<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsGlobalIntelClient -- HTTP client for hub communication.
 *
 * Handles registration, intel push/pull, lookups, and GDPR purge requests
 * against the central FPS Hub API. All methods are fail-open: hub errors
 * never block local WHMCS operations.
 *
 * Security: API keys stored as SHA-256 hashes on the hub. Transport is TLS-only.
 * Rate limited: 60 req/min per instance, 500 records per push batch.
 */
class FpsGlobalIntelClient
{
    private string $hubUrl;
    private string $apiKey;
    private string $instanceId;
    private bool $shareIps;
    private int $timeout;

    public function __construct()
    {
        $this->hubUrl     = rtrim($this->getConfig('hub_url', ''), '/');
        $this->apiKey     = $this->getConfig('instance_api_key', '');
        $this->instanceId = $this->getConfig('instance_id', '');
        $this->shareIps   = $this->getConfig('share_ip_addresses', '1') === '1';
        $this->timeout    = 5; // 5-second timeout for all hub requests
    }

    /**
     * Check if client is properly configured for hub communication.
     */
    public function isConfigured(): bool
    {
        return !empty($this->hubUrl) && !empty($this->apiKey);
    }

    /**
     * Check if global sharing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->getConfig('global_sharing_enabled', '0') === '1';
    }

    /**
     * Register this instance with the hub.
     * The hub returns an API key (shown once) and a verification token.
     */
    public function register(): array
    {
        if (empty($this->hubUrl)) {
            return ['error' => 'Hub URL not configured. Set it in Global Intel settings first.'];
        }

        // Get domain from WHMCS SystemURL
        $systemUrl = Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value') ?? '';
        $domain = parse_url($systemUrl, PHP_URL_HOST) ?: '';

        if (empty($domain)) {
            return ['error' => 'Cannot detect domain from WHMCS System URL'];
        }

        $response = $this->httpRequest('POST', '/api/v1/register', [
            'domain'       => $domain,
            'display_name' => 'FPS Instance - ' . $domain,
            'instance_id'  => $this->instanceId,
        ]);

        if (!empty($response['success']) && !empty($response['data']['api_key'])) {
            // Store the API key and domain
            $this->setConfig('instance_api_key', $response['data']['api_key']);
            $this->setConfig('instance_domain', $domain);
            if (!empty($response['data']['verification_token'])) {
                $this->setConfig('verification_token', $response['data']['verification_token']);
            }
            $this->apiKey = $response['data']['api_key'];

            return [
                'success' => true,
                'message' => 'Registered with hub. API key stored.',
                'domain'  => $domain,
                'verification_token' => $response['data']['verification_token'] ?? '',
            ];
        }

        return $response;
    }

    /**
     * Push unpushed local intel to the hub (batch, max 500).
     */
    public function pushIntel(): array
    {
        if (!$this->isConfigured() || !$this->isEnabled()) {
            return ['error' => 'Hub not configured or sharing disabled'];
        }

        // Get unpushed records
        $records = Capsule::table('mod_fps_global_intel')
            ->where('pushed_to_hub', 0)
            ->where('source', 'local')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->toArray();

        if (empty($records)) {
            return ['success' => true, 'pushed' => 0, 'message' => 'No records to push'];
        }

        // Prepare payload -- strip IPs if sharing is disabled
        $payload = [];
        foreach ($records as $record) {
            $entry = [
                'email_hash'     => $record->email_hash,
                'country'        => $record->country,
                'risk_score'     => (float)$record->risk_score,
                'risk_level'     => $record->risk_level,
                'evidence_flags' => json_decode($record->evidence_flags ?? '{}', true),
                'seen_count'     => (int)$record->seen_count,
            ];

            if ($this->shareIps && !empty($record->ip_address)) {
                $entry['ip_address'] = $record->ip_address;
            }

            $payload[] = $entry;
        }

        $response = $this->httpRequest('POST', '/api/v1/intel/push', [
            'records' => $payload,
        ]);

        if (!empty($response['success'])) {
            // Mark as pushed
            $ids = array_column((array)$records, 'id');
            Capsule::table('mod_fps_global_intel')
                ->whereIn('id', $ids)
                ->update([
                    'pushed_to_hub' => 1,
                    'pushed_at' => date('Y-m-d H:i:s'),
                ]);

            $this->setConfig('last_push_at', date('Y-m-d H:i:s'));

            return [
                'success' => true,
                'pushed'  => count($payload),
                'accepted' => $response['data']['accepted'] ?? count($payload),
            ];
        }

        return $response;
    }

    /**
     * Lookup an email hash in the hub.
     */
    public function lookupEmail(string $emailHash): ?array
    {
        if (!$this->isConfigured()) return null;

        $response = $this->httpRequest('GET', '/api/v1/intel/lookup?email_hash=' . urlencode($emailHash));
        return $response['success'] ?? false ? ($response['data'] ?? null) : null;
    }

    /**
     * Lookup an IP in the hub.
     */
    public function lookupIp(string $ip): ?array
    {
        if (!$this->isConfigured()) return null;

        $response = $this->httpRequest('GET', '/api/v1/intel/lookup?ip=' . urlencode($ip));
        return $response['success'] ?? false ? ($response['data'] ?? null) : null;
    }

    /**
     * Pull recent high-risk intel from hub.
     */
    public function pullFeed(string $since = ''): array
    {
        if (!$this->isConfigured() || !$this->isEnabled()) {
            return ['error' => 'Not configured or disabled'];
        }

        if (empty($since)) {
            $since = $this->getConfig('last_pull_at', date('Y-m-d H:i:s', strtotime('-24 hours')));
        }

        $response = $this->httpRequest('GET', '/api/v1/intel/feed?since=' . urlencode($since));

        if (!empty($response['success']) && !empty($response['data']['records'])) {
            $collector = new FpsGlobalIntelCollector();
            $imported = 0;

            foreach ($response['data']['records'] as $record) {
                try {
                    // Store with source='hub' using raw upsert
                    $collector->upsertIntel(
                        $record['email_hash'],
                        $record['ip_address'] ?? null,
                        $record['country'] ?? '',
                        (float)($record['max_risk_score'] ?? $record['risk_score'] ?? 0),
                        $record['risk_level'] ?? 'low',
                        $record['evidence_flags'] ?? []
                    );
                    // Mark as hub source
                    Capsule::table('mod_fps_global_intel')
                        ->where('email_hash', $record['email_hash'])
                        ->where('source', 'local')
                        ->update(['source' => 'hub']);
                    $imported++;
                } catch (\Throwable $e) {
                    // Skip individual record errors
                }
            }

            $this->setConfig('last_pull_at', date('Y-m-d H:i:s'));

            return ['success' => true, 'imported' => $imported];
        }

        return $response;
    }

    /**
     * Get hub-wide aggregate statistics.
     */
    public function getHubStats(): array
    {
        if (!$this->isConfigured()) return [];

        $response = $this->httpRequest('GET', '/api/v1/stats');
        return $response['success'] ?? false ? ($response['data'] ?? []) : [];
    }

    /**
     * GDPR: Remove all this instance's contributions from the hub.
     */
    public function purgeContributions(): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Not configured'];
        }

        return $this->httpRequest('DELETE', '/api/v1/intel/purge-mine');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Make an HTTP request to the hub API.
     */
    private function httpRequest(string $method, string $path, array $body = []): array
    {
        $url = $this->hubUrl . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-FPS-Hub-Key: ' . $this->apiKey,
                'X-FPS-Instance-ID: ' . $this->instanceId,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            logModuleCall('fraud_prevention_suite', 'hub_request', $url, "cURL error: {$error}");
            return ['error' => 'Hub connection failed: ' . $error, 'success' => false];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return ['error' => "Hub returned invalid response (HTTP {$httpCode})", 'success' => false];
        }

        return $decoded;
    }

    private function getConfig(string $key, string $default = ''): string
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_global_config')) return $default;
            $val = Capsule::table('mod_fps_global_config')
                ->where('setting_key', $key)
                ->value('setting_value');
            return $val !== null ? (string)$val : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function setConfig(string $key, string $value): void
    {
        try {
            Capsule::table('mod_fps_global_config')
                ->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => $value]
                );
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}

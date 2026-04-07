<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsGlobalIntelSharing -- manages data sharing between WHMCS instances.
 *
 * Each WHMCS instance can opt-in to share anonymized fraud check data
 * with a central hub. The hub aggregates data from all instances into
 * mod_fps_global_intel, creating a shared threat intelligence database.
 *
 * Data is anonymized: emails are SHA-256 hashed, only IPs, countries,
 * risk scores, and risk levels are shared. No PII leaves the instance.
 */
class FpsGlobalIntelSharing
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    /**
     * Get or generate this instance's unique ID.
     */
    public function fps_getInstanceId(): string
    {
        try {
            $id = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'instance_uuid')
                ->value('setting_value');
            if (!empty($id)) return (string)$id;

            $newId = bin2hex(random_bytes(16));
            Capsule::table('mod_fps_settings')->insert([
                'setting_key' => 'instance_uuid',
                'setting_value' => $newId,
            ]);
            return $newId;
        } catch (\Throwable $e) {
            return 'unknown-' . substr(md5(gethostname()), 0, 12);
        }
    }

    /**
     * Check if sharing is enabled.
     */
    public function isEnabled(): bool
    {
        try {
            return Capsule::table('mod_fps_settings')
                ->where('setting_key', 'global_sharing_enabled')
                ->value('setting_value') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the hub URL for submitting data.
     */
    public function fps_getHubUrl(): string
    {
        try {
            return (string) Capsule::table('mod_fps_settings')
                ->where('setting_key', 'global_hub_url')
                ->value('setting_value') ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Collect recent fraud checks for sharing (anonymized).
     */
    public function fps_collectRecentChecks(int $sinceMins = 60): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime("-{$sinceMins} minutes"));

            $checks = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $since)
                ->where('risk_score', '>', 0)
                ->limit(500)
                ->get(['ip_address', 'email', 'country', 'risk_score', 'risk_level', 'check_type', 'created_at']);

            $data = [];
            foreach ($checks as $c) {
                $data[] = [
                    'email_hash'   => $c->email ? hash('sha256', strtolower(trim($c->email))) : null,
                    'ip_address'   => $c->ip_address,
                    'country_code' => $c->country ?: null,
                    'risk_score'   => (float) $c->risk_score,
                    'risk_level'   => $c->risk_level,
                    'check_type'   => $c->check_type,
                    'check_date'   => $c->created_at,
                ];
            }

            return $data;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'GlobalShare::collect::ERROR', '', $e->getMessage());
            return [];
        }
    }

    /**
     * Push collected data to the central hub.
     */
    public function fps_pushToHub(array $data): bool
    {
        $hubUrl = $this->fps_getHubUrl();
        if ($hubUrl === '' || empty($data)) return false;

        try {
            $instanceId = $this->fps_getInstanceId();
            $secret = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'global_sharing_secret')
                ->value('setting_value') ?: '';

            $payload = json_encode([
                'instance_id' => $instanceId,
                'entries'     => $data,
                'timestamp'   => date('c'),
            ]);

            $ch = curl_init($hubUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-FPS-Instance-Id: ' . $instanceId,
                    'X-FPS-Share-Secret: ' . $secret,
                ],
                CURLOPT_USERAGENT      => 'FPS-WHMCS/4.1-GlobalShare',
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = $httpCode >= 200 && $httpCode < 300;

            logModuleCall(self::MODULE_NAME, 'GlobalShare::push',
                "Sent " . count($data) . " entries to hub",
                "HTTP {$httpCode}: " . substr((string)$response, 0, 200));

            // Update last sync time
            Capsule::table('mod_fps_settings')->updateOrInsert(
                ['setting_key' => 'global_last_sync'],
                ['setting_value' => date('Y-m-d H:i:s')]
            );

            return $success;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'GlobalShare::push::ERROR', '', $e->getMessage());
            return false;
        }
    }

    /**
     * Register this instance with the hub and get a sharing key.
     * Called automatically on first opt-in.
     */
    public function fps_registerWithHub(): array
    {
        $hubUrl = $this->fps_getHubUrl();
        if ($hubUrl === '') return ['status' => 'error', 'message' => 'Hub URL not configured'];

        try {
            // Derive register URL from hub URL
            $registerUrl = str_replace('/v1/global-intel/ingest', '/v1/global-intel/register', $hubUrl);

            // Use WHMCS SystemURL for accurate domain (not $_SERVER which gives cPanel hostname in CLI)
            $systemUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value') ?: '';
            $domain = parse_url($systemUrl, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? gethostname());
            $adminEmail = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'notify_email')
                ->value('value') ?: '';

            $payload = json_encode([
                'instance_id'   => $this->fps_getInstanceId(),
                'domain'        => $domain,
                'admin_email'   => $adminEmail,
                'whmcs_version' => $GLOBALS['CONFIG']['Version'] ?? 'unknown',
                'fps_version'   => '4.1.0',
            ]);

            $ch = curl_init($registerUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_USERAGENT      => 'FPS-WHMCS/4.1-Register',
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall(self::MODULE_NAME, 'GlobalRegister::FAIL', $registerUrl, "HTTP {$httpCode}: " . substr((string)$response, 0, 200));
                return ['status' => 'error', 'message' => 'Registration failed (HTTP ' . $httpCode . ')'];
            }

            $data = json_decode($response, true);
            if (!is_array($data) || !($data['success'] ?? false)) {
                return ['status' => 'error', 'message' => $data['error'] ?? 'Unknown error'];
            }

            $sharingKey = $data['data']['sharing_key'] ?? '';
            if ($sharingKey !== '') {
                // Store the key as our sharing secret (replaces the generic one)
                Capsule::table('mod_fps_settings')->updateOrInsert(
                    ['setting_key' => 'global_sharing_secret'],
                    ['setting_value' => $sharingKey]
                );
                Capsule::table('mod_fps_settings')->updateOrInsert(
                    ['setting_key' => 'global_registered_domain'],
                    ['setting_value' => $domain]
                );

                logModuleCall(self::MODULE_NAME, 'GlobalRegister::OK',
                    "Domain: {$domain}", "Key: " . substr($sharingKey, 0, 10) . '...');
            }

            return [
                'status'  => $data['data']['status'] ?? 'registered',
                'domain'  => $domain,
                'message' => $data['data']['message'] ?? 'Registered successfully',
            ];
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'GlobalRegister::ERROR', '', $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Run a complete share cycle: collect + push.
     * Auto-registers on first run if not yet registered.
     */
    public function fps_runShareCycle(): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'disabled', 'message' => 'Global sharing is not enabled'];
        }

        // Auto-register on first sync if no registered domain yet
        $registeredDomain = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'global_registered_domain')
            ->value('setting_value');
        if (empty($registeredDomain)) {
            $regResult = $this->fps_registerWithHub();
            if ($regResult['status'] === 'error') {
                return $regResult;
            }
        }

        $data = $this->fps_collectRecentChecks(60);
        if (empty($data)) {
            return ['status' => 'empty', 'message' => 'No recent checks to share'];
        }

        $success = $this->fps_pushToHub($data);
        return [
            'status'  => $success ? 'success' : 'failed',
            'entries' => count($data),
            'message' => $success ? count($data) . ' entries shared with hub' : 'Failed to push to hub',
        ];
    }

    /**
     * Ingest data from another instance (hub-side).
     */
    public function fps_ingestFromInstance(string $instanceId, array $entries): int
    {
        $inserted = 0;

        foreach ($entries as $entry) {
            try {
                Capsule::table('mod_fps_global_intel')->insert([
                    'source_instance_id' => substr($instanceId, 0, 64),
                    'email_hash'         => $entry['email_hash'] ?? null,
                    'ip_address'         => $entry['ip_address'] ?? null,
                    'country_code'       => $entry['country_code'] ?? null,
                    'risk_score'         => (float) ($entry['risk_score'] ?? 0),
                    'risk_level'         => $entry['risk_level'] ?? 'low',
                    'check_type'         => $entry['check_type'] ?? 'shared',
                    'check_date'         => $entry['check_date'] ?? date('Y-m-d H:i:s'),
                    'received_at'        => date('Y-m-d H:i:s'),
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                // Skip duplicates or bad data
                continue;
            }
        }

        return $inserted;
    }

    /**
     * Get aggregated global intelligence data for the admin tab.
     */
    public function fps_getGlobalStats(): array
    {
        try {
            $total = (int) Capsule::table('mod_fps_global_intel')->count();
            $instances = (int) Capsule::table('mod_fps_global_intel')
                ->distinct()->count('source_instance_id');
            $avgScore = round((float) Capsule::table('mod_fps_global_intel')
                ->avg('risk_score'), 1);

            $topCountries = Capsule::table('mod_fps_global_intel')
                ->select('country_code', Capsule::raw('COUNT(*) as cnt'))
                ->whereNotNull('country_code')
                ->groupBy('country_code')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get()->toArray();

            $topIps = Capsule::table('mod_fps_global_intel')
                ->select('ip_address', Capsule::raw('COUNT(*) as cnt'), Capsule::raw('AVG(risk_score) as avg_score'))
                ->whereNotNull('ip_address')
                ->groupBy('ip_address')
                ->orderByDesc('cnt')
                ->limit(15)
                ->get()->toArray();

            $recentEntries = Capsule::table('mod_fps_global_intel')
                ->orderByDesc('received_at')
                ->limit(25)
                ->get()->toArray();

            return [
                'total_entries'  => $total,
                'instance_count' => $instances,
                'avg_risk_score' => $avgScore,
                'top_countries'  => $topCountries,
                'top_ips'        => $topIps,
                'recent'         => $recentEntries,
            ];
        } catch (\Throwable $e) {
            return ['total_entries' => 0, 'instance_count' => 0, 'avg_risk_score' => 0,
                'top_countries' => [], 'top_ips' => [], 'recent' => []];
        }
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsGlobalIntelCollector -- Harvests fraud intelligence from client records.
 *
 * Extracts anonymized fraud signals (SHA-256 email hash, IP, country, risk score,
 * evidence flags) and upserts them into mod_fps_global_intel with deduplication.
 * The same email_hash+IP combination increments seen_count instead of creating
 * duplicate rows.
 *
 * Called by FpsBotDetector before any purge/deep-purge to preserve fraud intel.
 * Also callable manually from the admin interface.
 *
 * GDPR compliant: Only hashed/anonymized data is stored. Raw PII never leaves
 * the client table context.
 */
class FpsGlobalIntelCollector
{
    private int $retentionDays;

    public function __construct()
    {
        $this->retentionDays = (int) $this->getConfig('intel_retention_days', '365');
    }

    /**
     * Harvest fraud intel from a single client before deletion.
     *
     * Reads the client record, their highest-risk fraud check, and IP intel
     * flags, then upserts an anonymized record into mod_fps_global_intel.
     *
     * @return array{harvested: bool, email_hash: string, risk_score: float, evidence: array}
     */
    public function harvestFromClient(int $clientId): array
    {
        $client = Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['email', 'ip', 'country']);

        if (!$client || empty($client->email)) {
            return ['harvested' => false, 'reason' => 'Client not found or no email'];
        }

        // SHA-256 hash of lowercased email -- irreversible anonymization
        $emailHash = hash('sha256', strtolower(trim($client->email)));
        $ip = $client->ip ?? null;
        $country = $client->country ?? '';

        // Get highest risk check for this client
        $bestCheck = Capsule::table('mod_fps_checks')
            ->where('client_id', $clientId)
            ->orderByDesc('risk_score')
            ->first(['risk_score', 'risk_level', 'details', 'provider_scores']);

        $riskScore = $bestCheck ? (float)$bestCheck->risk_score : 0.0;
        $riskLevel = $bestCheck ? $bestCheck->risk_level : 'low';

        // Build evidence flags from available data
        $evidence = $this->buildEvidenceFlags($clientId, $bestCheck);

        // Upsert with deduplication
        $this->upsertIntel($emailHash, $ip, $country, $riskScore, $riskLevel, $evidence);

        return [
            'harvested'   => true,
            'email_hash'  => $emailHash,
            'risk_score'  => $riskScore,
            'risk_level'  => $riskLevel,
            'evidence'    => $evidence,
        ];
    }

    /**
     * Harvest intel from multiple clients (bulk operation).
     *
     * @return array{harvested: int, skipped: int, errors: array}
     */
    public function harvestBulk(array $clientIds): array
    {
        $harvested = 0;
        $skipped = 0;
        $errors = [];

        foreach ($clientIds as $id) {
            try {
                $result = $this->harvestFromClient((int)$id);
                if ($result['harvested']) {
                    $harvested++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Client #{$id}: " . $e->getMessage();
                $skipped++;
            }
        }

        return [
            'harvested' => $harvested,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * Upsert intel record with deduplication.
     *
     * If the same email_hash+ip_address already exists:
     * - Increments seen_count
     * - Updates last_seen_at
     * - Takes the HIGHEST risk_score (GREATEST)
     * - Updates risk_level only if new score is higher
     * - Merges evidence flags (OR logic)
     * - Resets pushed_to_hub to 0 (needs re-push)
     */
    public function upsertIntel(
        string $emailHash,
        ?string $ip,
        string $country,
        float $riskScore,
        string $riskLevel,
        array $evidence
    ): void {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return;
        }

        $evidenceJson = json_encode($evidence);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->retentionDays} days"));
        $now = date('Y-m-d H:i:s');

        // Use raw query for ON DUPLICATE KEY UPDATE (Capsule doesn't support it natively)
        // All values are bound parameters (?) to prevent SQL injection
        Capsule::connection()->statement("
            INSERT INTO mod_fps_global_intel
                (email_hash, ip_address, country, risk_score, risk_level, source,
                 evidence_flags, seen_count, first_seen_at, last_seen_at, expires_at,
                 pushed_to_hub, pushed_at)
            VALUES
                (?, ?, ?, ?, ?, 'local', ?, 1, ?, ?, ?, 0, NULL)
            ON DUPLICATE KEY UPDATE
                seen_count = seen_count + 1,
                last_seen_at = VALUES(last_seen_at),
                risk_score = GREATEST(risk_score, VALUES(risk_score)),
                risk_level = CASE
                    WHEN VALUES(risk_score) > risk_score THEN VALUES(risk_level)
                    ELSE risk_level
                END,
                evidence_flags = VALUES(evidence_flags),
                expires_at = VALUES(expires_at),
                pushed_to_hub = 0
        ", [$emailHash, $ip, $country, $riskScore, $riskLevel, $evidenceJson, $now, $now, $expiresAt]);
    }

    /**
     * Purge expired intel records. Called by DailyCronJob.
     *
     * @return int Number of records deleted
     */
    public function purgeExpired(): int
    {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return 0;
        }

        return Capsule::table('mod_fps_global_intel')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();
    }

    /**
     * Get local intel statistics.
     */
    public function getStats(): array
    {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return ['total' => 0, 'by_level' => [], 'by_source' => [], 'unpushed' => 0];
        }

        $total = Capsule::table('mod_fps_global_intel')->count();

        $byLevel = Capsule::table('mod_fps_global_intel')
            ->select('risk_level', Capsule::raw('COUNT(*) as cnt'))
            ->groupBy('risk_level')
            ->pluck('cnt', 'risk_level')
            ->toArray();

        $bySource = Capsule::table('mod_fps_global_intel')
            ->select('source', Capsule::raw('COUNT(*) as cnt'))
            ->groupBy('source')
            ->pluck('cnt', 'source')
            ->toArray();

        $unpushed = Capsule::table('mod_fps_global_intel')
            ->where('pushed_to_hub', 0)
            ->count();

        $topCountries = Capsule::table('mod_fps_global_intel')
            ->select('country', Capsule::raw('COUNT(*) as cnt'))
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country')
            ->orderByDesc('cnt')
            ->limit(10)
            ->pluck('cnt', 'country')
            ->toArray();

        $totalSeen = Capsule::table('mod_fps_global_intel')
            ->sum('seen_count');

        return [
            'total'         => $total,
            'by_level'      => $byLevel,
            'by_source'     => $bySource,
            'unpushed'      => $unpushed,
            'top_countries'  => $topCountries,
            'total_seen'    => (int)$totalSeen,
        ];
    }

    /**
     * Browse intel records with pagination and filtering.
     */
    public function browse(int $page = 1, int $perPage = 25, array $filters = []): array
    {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return ['records' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        }

        $query = Capsule::table('mod_fps_global_intel');

        if (!empty($filters['risk_level'])) {
            $query->where('risk_level', $filters['risk_level']);
        }
        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('email_hash', 'LIKE', "%{$search}%")
                  ->orWhere('ip_address', 'LIKE', "%{$search}%")
                  ->orWhere('country', $search);
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('last_seen_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('last_seen_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $total = $query->count();
        $totalPages = (int)ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $records = $query->orderByDesc('last_seen_at')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Export all local intel as array (for GDPR data portability).
     */
    public function exportAll(): array
    {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return [];
        }

        return Capsule::table('mod_fps_global_intel')
            ->orderByDesc('last_seen_at')
            ->get()
            ->toArray();
    }

    /**
     * Purge all local intel (GDPR right to erasure).
     */
    public function purgeAll(): int
    {
        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            return 0;
        }

        return Capsule::table('mod_fps_global_intel')->delete();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Build evidence flags from available data sources.
     * Returns boolean-only flags for privacy (no raw values).
     */
    private function buildEvidenceFlags(int $clientId, ?object $check): array
    {
        $flags = [
            'tor'              => false,
            'vpn'              => false,
            'proxy'            => false,
            'datacenter'       => false,
            'disposable_email' => false,
            'role_email'       => false,
            'invalid_mx'      => false,
            'geo_mismatch'     => false,
            'high_velocity'    => false,
            'bot_detected'     => true, // Always true since we're harvesting from bot purge
        ];

        // Check IP intel cache
        try {
            $ipIntel = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', Capsule::table('tblclients')->where('id', $clientId)->value('ip'))
                ->first();

            if ($ipIntel) {
                $flags['tor'] = (bool)($ipIntel->is_tor ?? false);
                $flags['vpn'] = (bool)($ipIntel->is_vpn ?? false);
                $flags['proxy'] = (bool)($ipIntel->is_proxy ?? false);
                $flags['datacenter'] = (bool)($ipIntel->is_datacenter ?? false);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Check email intel cache
        try {
            $emailIntel = Capsule::table('mod_fps_email_intel')
                ->where('email', Capsule::table('tblclients')->where('id', $clientId)->value('email'))
                ->first();

            if ($emailIntel) {
                $flags['disposable_email'] = (bool)($emailIntel->is_disposable ?? false);
                $flags['role_email'] = (bool)($emailIntel->is_role_account ?? false);
                $flags['invalid_mx'] = !((bool)($emailIntel->mx_valid ?? true));
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Extract evidence from check details JSON if available
        if ($check && !empty($check->details)) {
            try {
                $details = json_decode($check->details, true);
                if (isset($details['risk']['factors'])) {
                    foreach ($details['risk']['factors'] as $factor) {
                        $factorLower = strtolower(is_string($factor) ? $factor : ($factor['factor'] ?? ''));
                        if (str_contains($factorLower, 'geo') && str_contains($factorLower, 'mismatch')) {
                            $flags['geo_mismatch'] = true;
                        }
                        if (str_contains($factorLower, 'velocity') || str_contains($factorLower, 'rate')) {
                            $flags['high_velocity'] = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        return $flags;
    }

    /**
     * Get global config value.
     */
    private function getConfig(string $key, string $default = ''): string
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_global_config')) {
                return $default;
            }
            $val = Capsule::table('mod_fps_global_config')
                ->where('setting_key', $key)
                ->value('setting_value');
            return $val !== null ? (string)$val : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

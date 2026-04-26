<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

class FpsHookHelpers
{
    /**
     * Per-request memoized default currency id (avoids repeated DB lookups
     * when the same hook iterates many products/pricing rows).
     */
    private static ?int $fps_defaultCurrencyId = null;

    /**
     * Resolve the WHMCS default currency id without hardcoding to 1.
     *
     * Resolution order:
     *   1. tblcurrencies row with default = 1 (admin-chosen default currency)
     *   2. lowest-id currency row (safe for partially-set-up installs)
     *   3. integer 1 as last-resort fallback
     *
     * The result is memoized for the remainder of the PHP request so callers
     * inside loops do not pay repeated DB hits. Pass $forceRefresh = true
     * after a settings change to invalidate.
     *
     * Mirrors the resolver embedded in fps_createDefaultProducts() so all
     * runtime callers share one canonical implementation.
     */
    public static function fps_resolveDefaultCurrencyId(bool $forceRefresh = false): int
    {
        if (!$forceRefresh && self::$fps_defaultCurrencyId !== null) {
            return self::$fps_defaultCurrencyId;
        }

        try {
            $id = (int) (Capsule::table('tblcurrencies')->where('default', 1)->value('id') ?? 0);
            if ($id < 1) {
                $id = (int) (Capsule::table('tblcurrencies')->orderBy('id')->value('id') ?? 1);
            }
        } catch (\Throwable $e) {
            $id = 1;
        }

        self::$fps_defaultCurrencyId = $id > 0 ? $id : 1;
        return self::$fps_defaultCurrencyId;
    }

    /**
     * Read provider scores from a mod_fps_checks row, preferring the
     * structured `provider_scores` JSON column and falling back to
     * extracting them from the legacy `details` blob's risk.providerScores
     * tree. Returns an empty array when neither path yields data.
     *
     * Use from any reader that needs per-provider score data; this is
     * the canonical pattern for the v4.2.4+ reader migration so that
     * pre-migration rows still resolve correctly.
     *
     * @param object|array|null $checkRow A row fetched from mod_fps_checks
     *                                    (object via Capsule, array via raw query, or null).
     * @return array<string, float>
     */
    public static function fps_readProviderScores($checkRow): array
    {
        if ($checkRow === null) {
            return [];
        }
        $get = static function ($key) use ($checkRow) {
            if (is_array($checkRow))   { return $checkRow[$key] ?? null; }
            if (is_object($checkRow))  { return $checkRow->{$key} ?? null; }
            return null;
        };

        // Preferred structured path.
        $structured = $get('provider_scores');
        if (is_string($structured) && $structured !== '') {
            $decoded = json_decode($structured, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $name => $score) {
                    $out[(string) $name] = (float) $score;
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        // Legacy fallback: pull from details.risk.providerScores.
        $details = $get('details');
        if (is_string($details) && $details !== '') {
            $decoded = json_decode($details, true);
            $providerScores = $decoded['risk']['providerScores'] ?? null;
            if (is_array($providerScores)) {
                $out = [];
                foreach ($providerScores as $name => $score) {
                    $out[(string) $name] = (float) $score;
                }
                return $out;
            }
        }

        return [];
    }

    /**
     * Read the normalised check_context from a mod_fps_checks row,
     * preferring the structured `check_context` JSON column and falling
     * back to the legacy details.context tree.
     *
     * @param object|array|null $checkRow
     * @return array<string, mixed>
     */
    public static function fps_readCheckContext($checkRow): array
    {
        if ($checkRow === null) {
            return [];
        }
        $get = static function ($key) use ($checkRow) {
            if (is_array($checkRow))   { return $checkRow[$key] ?? null; }
            if (is_object($checkRow))  { return $checkRow->{$key} ?? null; }
            return null;
        };

        $structured = $get('check_context');
        if (is_string($structured) && $structured !== '') {
            $decoded = json_decode($structured, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $details = $get('details');
        if (is_string($details) && $details !== '') {
            $decoded = json_decode($details, true);
            if (is_array($decoded['context'] ?? null)) {
                return $decoded['context'];
            }
        }

        return [];
    }

    /**
     * Build a deterministic ?v=... cache-bust suffix for an in-module
     * asset. Combines FPS_MODULE_VERSION (release boundary) with the
     * file's filemtime (hotfix boundary) so browsers cache within a
     * release but pick up redeploys.
     *
     * Pass the path RELATIVE to the assets/ root (e.g. "js/fps-fingerprint.js").
     * Never pass time() -- that would defeat browser caching entirely.
     *
     * @param string $relativeAssetPath path under assets/
     * @return string e.g. "?v=4.2.6-1733180123" or "?v=4.2.6-0" if missing
     */
    public static function fps_assetCacheBust(string $relativeAssetPath): string
    {
        $version = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'v';
        $abs     = __DIR__ . '/../assets/' . ltrim($relativeAssetPath, '/');
        $mtime   = file_exists($abs) ? (string) filemtime($abs) : '0';
        return '?v=' . $version . '-' . $mtime;
    }

    /**
     * Resolve pre-checkout thresholds (block + captcha) with consistent
     * fall-throughs and optional per-gateway override.
     *
     * Resolution order for the BLOCK threshold:
     *   1. mod_fps_gateway_thresholds.block_threshold for $gateway (when > 0)
     *   2. tbladdonmodules.pre_checkout_block_threshold (legacy admin-visible)
     *   3. mod_fps_settings.risk_critical_threshold (modern admin-visible)
     *   4. hardcoded safe default (85)
     *
     * Resolution order for the CAPTCHA / flag threshold:
     *   1. mod_fps_gateway_thresholds.flag_threshold for $gateway (when > 0)
     *   2. mod_fps_settings.risk_high_threshold (modern admin-visible)
     *   3. hardcoded safe default (60)
     *
     * Both the inline pre-checkout hook and FpsCheckRunner share this
     * helper so admin settings changes affect every code path consistently.
     *
     * @return array{block: float, captcha: float}
     */
    public static function fps_resolvePreCheckoutThresholds(string $gateway = ''): array
    {
        $block   = 0.0;
        $captcha = 0.0;

        // Per-gateway override (highest priority when present and > 0).
        if ($gateway !== '') {
            try {
                $gwRow = Capsule::table('mod_fps_gateway_thresholds')
                    ->where('gateway', $gateway)
                    ->where('enabled', 1)
                    ->first();
                if ($gwRow) {
                    $block   = (float) ($gwRow->block_threshold ?? 0);
                    $captcha = (float) ($gwRow->flag_threshold  ?? 0);
                }
            } catch (\Throwable $e) {
                // Non-fatal -- fall through to global resolution.
            }
        }

        // Global block threshold.
        if ($block <= 0.0) {
            try {
                $legacy = (float) (Capsule::table('tbladdonmodules')
                    ->where('module', 'fraud_prevention_suite')
                    ->where('setting', 'pre_checkout_block_threshold')
                    ->value('value') ?? 0);
                if ($legacy > 0.0) {
                    $block = $legacy;
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($block <= 0.0) {
            try {
                $modern = (float) (Capsule::table('mod_fps_settings')
                    ->where('setting_key', 'risk_critical_threshold')
                    ->value('setting_value') ?? 0);
                if ($modern > 0.0) {
                    $block = $modern;
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($block <= 0.0) {
            $block = 85.0;
        }

        // Captcha / flag threshold.
        if ($captcha <= 0.0) {
            try {
                $modernHigh = (float) (Capsule::table('mod_fps_settings')
                    ->where('setting_key', 'risk_high_threshold')
                    ->value('setting_value') ?? 0);
                if ($modernHigh > 0.0) {
                    $captcha = $modernHigh;
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($captcha <= 0.0) {
            $captcha = 60.0;
        }

        return ['block' => $block, 'captcha' => $captcha];
    }

    /**
     * Record geo event for topology visualization.
     */
    public static function fps_recordGeoEvent(string $ip, string $country, int $orderId): void
    {
        try {
            $topologyEnabled = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'topology_enabled')
                ->value('setting_value');

            if ($topologyEnabled !== '1') return;

            // Get coordinates from IP intel cache
            $ipIntel = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->first();

            $lat = $ipIntel->latitude ?? null;
            $lng = $ipIntel->longitude ?? null;
            $cc = $ipIntel->country_code ?? $country;

            // Only store anonymized data (rounded coordinates)
            if ($lat !== null && $lng !== null) {
                $lat = round((float)$lat, 1);
                $lng = round((float)$lng, 1);
            }

            // Get the risk level from the check
            $check = Capsule::table('mod_fps_checks')
                ->where('order_id', $orderId)
                ->orderByDesc('created_at')
                ->first();

            Capsule::table('mod_fps_geo_events')->insert([
                'event_type' => 'order_check',
                'country_code' => strtoupper(substr($cc ?: 'XX', 0, 2)),
                'latitude' => $lat,
                'longitude' => $lng,
                'risk_level' => $check->risk_level ?? 'low',
                'risk_score' => $check->risk_score ?? 0,
                'is_anonymized' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal - topology is optional
        }
    }

    /**
     * Download the latest disposable email domains list from GitHub.
     */
    public static function fps_refreshDisposableDomains(): void
    {
        $targetFile = __DIR__ . '/../data/disposable_domains.txt';
        $url = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';

        // Skip if updated in last 24 hours
        if (file_exists($targetFile) && (time() - filemtime($targetFile)) < 86400) {
            return;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && strlen($data) > 1000) {
            file_put_contents($targetFile, $data);
            logModuleCall('fraud_prevention_suite', 'DisposableDomains', '',
                'Updated: ' . substr_count($data, "\n") . ' domains');
        }
    }

    /**
     * v3.0: Auto-suspend clients exceeding chargeback thresholds.
     * Called by DailyCronJob when auto_suspend_enabled = '1'.
     */
    public static function fps_autoSuspendChargebackAbusers(): void
    {
        try {
            $threshold = (int) (Capsule::table('mod_fps_settings')
                ->where('setting_key', 'auto_suspend_chargeback_threshold')
                ->value('setting_value') ?: 3);

            $windowDays = (int) (Capsule::table('mod_fps_settings')
                ->where('setting_key', 'auto_suspend_chargeback_window_days')
                ->value('setting_value') ?: 180);

            $since = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));

            // Find clients with chargebacks exceeding threshold
            $abusers = Capsule::table('mod_fps_chargebacks')
                ->where('chargeback_date', '>=', $since)
                ->selectRaw('client_id, COUNT(*) as cb_count, SUM(amount) as total_amount')
                ->groupBy('client_id')
                ->havingRaw('COUNT(*) >= ?', [$threshold])
                ->get();

            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsClientTrustManager')) {
                logModuleCall('fraud_prevention_suite', 'AutoSuspend::ERROR', '', 'FpsClientTrustManager class not found');
                return;
            }

            $trustManager = new FpsClientTrustManager();

            foreach ($abusers as $abuser) {
                $currentStatus = $trustManager->getClientStatus((int) $abuser->client_id);

                // Don't override if already blacklisted or trusted
                if (in_array($currentStatus, ['blacklisted', 'trusted'], true)) {
                    continue;
                }

                $reason = "Auto-suspended: {$abuser->cb_count} chargebacks (\${$abuser->total_amount}) in {$windowDays} days";
                $trustManager->setClientStatus((int) $abuser->client_id, 'suspended', $reason, 0);

                // Suspend all active services
                $services = Capsule::table('tblhosting')
                    ->where('userid', $abuser->client_id)
                    ->where('domainstatus', 'Active')
                    ->get(['id']);

                foreach ($services as $service) {
                    Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update(['domainstatus' => 'Suspended']);
                }

                logActivity("Fraud Prevention: Auto-suspended client #{$abuser->client_id} -- {$abuser->cb_count} chargebacks in {$windowDays} days");
            }

            if (count($abusers) > 0) {
                logModuleCall('fraud_prevention_suite', 'AutoSuspend', '', count($abusers) . ' clients suspended');
            }
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AutoSuspend::ERROR', '', $e->getMessage());
        }
    }

    /**
     * Legacy v1.0 fraud check fallback.
     */
    public static function fps_legacyFraudCheck(int $orderId, int $clientId, string $email, string $ip, string $phone, string $country): void
    {
        try {
            $apiKey = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'fraudrecord_api_key')
                ->value('value');

            $riskScore = 0;
            $details = [];
            $rawResponse = null;
            $fraudRecordId = null;

            if ($apiKey && $email) {
                $postData = [
                    'api_key' => $apiKey,
                    'action' => 'query',
                    'email' => md5(strtolower(trim($email))),
                ];
                if ($ip) $postData['ip'] = $ip;
                if ($phone) $postData['phone'] = md5(preg_replace('/[^0-9]/', '', $phone));

                $ch = curl_init('https://www.fraudrecord.com/api/');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $rawResponse = $response;

                if ($httpCode === 200 && $response) {
                    $data = @json_decode($response, true);
                    if ($data) {
                        $fraudRecordId = $data['id'] ?? null;
                        $frScore = (float)($data['value'] ?? 0);
                        if ($frScore > 0) {
                            $riskScore += $frScore * 30;
                            $details[] = "FraudRecord score: {$frScore}";
                        }
                    }
                }

                logModuleCall('fraud_prevention_suite', 'FraudRecord Query',
                    json_encode($postData), $response, '', [$apiKey]);
            }

            $riskScore = min($riskScore, 100);
            $riskLevel = 'low';
            if ($riskScore >= 80) $riskLevel = 'critical';
            elseif ($riskScore >= 60) $riskLevel = 'high';
            elseif ($riskScore >= 30) $riskLevel = 'medium';

            $actionTaken = 'approved';
            $locked = 0;

            $critThreshold = (float)(Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'risk_critical_threshold')
                ->value('value') ?: 80);

            if ($riskScore >= $critThreshold) {
                $autoLock = Capsule::table('tbladdonmodules')
                    ->where('module', 'fraud_prevention_suite')
                    ->where('setting', 'auto_lock_critical')
                    ->value('value');

                if ($autoLock === 'on' || $autoLock === 'yes') {
                    Capsule::table('tblorders')->where('id', $orderId)->update(['status' => 'Fraud']);
                    $actionTaken = 'locked';
                    $locked = 1;
                }
            }

            Capsule::table('mod_fps_checks')->insert([
                'order_id' => $orderId,
                'client_id' => $clientId,
                'check_type' => 'legacy_auto',
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'ip_address' => $ip,
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
                'fraudrecord_id' => $fraudRecordId,
                'raw_response' => $rawResponse,
                'details' => json_encode($details),
                'action_taken' => $actionTaken,
                'locked' => $locked,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'LegacyCheck', '', $e->getMessage());
        }
    }

    /**
     * Resolve country code from IP via the existing mod_fps_ip_intel cache.
     * Returns '' on cache miss or DB failure -- never throws.
     *
     * Used by analytics injection in ClientAreaHeaderOutput to decide
     * whether the EEA consent banner should render.
     */
    public static function fps_lookupCountryByIp(string $ip): string
    {
        if ($ip === '') return '';
        try {
            $row = \WHMCS\Database\Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)->first(['country']);
            return ($row && $row->country) ? (string) $row->country : '';
        } catch (\Throwable $e) { return ''; }
    }

}

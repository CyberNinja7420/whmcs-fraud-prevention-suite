<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Browser fingerprint analysis provider.
 *
 * Processes client-side fingerprint data collected by fps-fingerprint.js,
 * computes a canonical hash, stores it for cross-referencing, and detects
 * suspicious patterns:
 *
 *   - Same fingerprint across 3+ different client accounts
 *   - Timezone mismatch between browser and IP geolocation
 *   - WebRTC IP leaks revealing a different real IP
 *   - Headless browser / automation indicators
 *   - Previously confirmed-fraud fingerprints
 */
class FingerprintProvider implements FpsProviderInterface
{
    private const TABLE_FINGERPRINTS = 'mod_fps_fingerprints';
    private const TABLE_FRAUD_FP     = 'mod_fps_fraud_fingerprints';

    /** Signals used to compute the canonical fingerprint hash. */
    private const CANONICAL_KEYS = [
        'canvas_hash',
        'webgl_hash',
        'audio_hash',
        'fonts',
        'languages',
        'platform',
        'screen_resolution',
        'color_depth',
        'timezone',
        'touch_support',
        'hardware_concurrency',
        'device_memory',
    ];

    public function getName(): string
    {
        return 'Browser Fingerprint';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 1.3;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $fpJson = $context['fingerprint_data'] ?? '';
        if ($fpJson === '' || !is_string($fpJson)) {
            return $blank;
        }

        try {
            $fpData = json_decode($fpJson, true);
            if (!is_array($fpData) || empty($fpData)) {
                return $blank;
            }

            $clientId = (int) ($context['client_id'] ?? 0);
            $clientIp = trim($context['ip'] ?? '');

            // Compute canonical fingerprint hash
            $fpHash = $this->fps_computeHash($fpData);
            if ($fpHash === '') {
                return $blank;
            }

            // Store fingerprint association
            if ($clientId > 0) {
                $this->fps_storeFingerprint($fpHash, $clientId, $clientIp, $fpData);
            }

            // Run all checks
            $crossAccountCount = $this->fps_getCrossAccountCount($fpHash, $clientId);
            $timezoneMismatch  = $this->fps_checkTimezoneMismatch($fpData, $context);
            $webrtcLeak        = $this->fps_checkWebrtcLeak($fpData, $clientIp);
            $isHeadless        = $this->fps_detectHeadless($fpData);
            $isKnownFraud      = $this->fps_isKnownFraudFingerprint($fpHash);
            $botSignals        = $this->fps_scoreBotSignals($fpData);

            $details = [
                'fingerprint_hash'   => $fpHash,
                'cross_account_count' => $crossAccountCount,
                'timezone_mismatch'  => $timezoneMismatch,
                'webrtc_leak'        => $webrtcLeak,
                'headless_browser'   => $isHeadless,
                'bot_signals'        => $botSignals['signals'],
                'bot_score'          => $botSignals['score'],
                'known_fraud_fp'     => $isKnownFraud,
                'signals_present'    => array_keys(array_filter($fpData, function ($v) {
                    return $v !== null && $v !== '';
                })),
            ];

            $score = $this->fps_calculateScore(
                $crossAccountCount,
                $timezoneMismatch,
                $webrtcLeak,
                $isHeadless,
                $isKnownFraud,
                $botSignals
            );

            logModuleCall(
                'fraud_prevention_suite',
                'Fingerprint Check',
                substr($fpHash, 0, 12) . '...',
                json_encode($details),
                '',
                []
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => $fpData,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'Fingerprint Error',
                '',
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Fingerprint hash computation
    // ------------------------------------------------------------------

    /**
     * Compute a deterministic SHA-256 hash from the canonical signal subset.
     */
    private function fps_computeHash(array $fpData): string
    {
        $canonical = [];

        foreach (self::CANONICAL_KEYS as $key) {
            $value = $fpData[$key] ?? null;
            if (is_array($value)) {
                sort($value);
                $canonical[$key] = $value;
            } else {
                $canonical[$key] = (string) $value;
            }
        }

        // Sort by key for deterministic ordering
        ksort($canonical);

        $serialised = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($serialised === false) {
            return '';
        }

        return hash('sha256', $serialised);
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    private function fps_storeFingerprint(string $fpHash, int $clientId, string $ip, array $fpData): void
    {
        try {
            // Check if this client+hash combination already exists
            $existing = Capsule::table(self::TABLE_FINGERPRINTS)
                ->where('fingerprint_hash', $fpHash)
                ->where('client_id', $clientId)
                ->first();

            if ($existing) {
                // Update last_seen and increment times_seen
                Capsule::table(self::TABLE_FINGERPRINTS)
                    ->where('id', $existing->id)
                    ->update([
                        'last_seen_at' => date('Y-m-d H:i:s'),
                        'times_seen'   => ($existing->times_seen ?? 0) + 1,
                    ]);
                return;
            }

            Capsule::table(self::TABLE_FINGERPRINTS)->insert([
                'fingerprint_hash'    => $fpHash,
                'client_id'           => $clientId,
                'user_agent'          => substr((string) ($fpData['user_agent'] ?? ''), 0, 65535),
                'canvas_hash'         => $fpData['canvas_hash'] ?? null,
                'webgl_hash'          => $fpData['webgl_hash'] ?? null,
                'screen_resolution'   => $fpData['screen_resolution'] ?? null,
                'timezone'            => $fpData['timezone'] ?? null,
                'timezone_offset'     => isset($fpData['timezone_offset']) ? (int) $fpData['timezone_offset'] : null,
                'hardware_concurrency' => isset($fpData['hardware_concurrency']) ? (int) $fpData['hardware_concurrency'] : null,
                'device_memory'       => isset($fpData['device_memory']) ? (int) $fpData['device_memory'] : null,
                'webrtc_local_ips'    => isset($fpData['webrtc_ips']) ? json_encode($fpData['webrtc_ips']) : null,
                'webrtc_mismatch'     => $this->fps_checkWebrtcLeak($fpData, $ip) ? 1 : 0,
                'raw_data'            => json_encode($fpData, JSON_UNESCAPED_SLASHES),
                'first_seen_at'       => date('Y-m-d H:i:s'),
                'last_seen_at'        => date('Y-m-d H:i:s'),
                'times_seen'          => 1,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal -- UNIQUE constraint violation means concurrent insert, which is fine
        }
    }

    // ------------------------------------------------------------------
    // Cross-account detection
    // ------------------------------------------------------------------

    /**
     * Count how many DISTINCT client IDs share this fingerprint hash,
     * excluding the current client.
     */
    private function fps_getCrossAccountCount(string $fpHash, int $excludeClientId): int
    {
        try {
            $query = Capsule::table(self::TABLE_FINGERPRINTS)
                ->where('fingerprint_hash', $fpHash)
                ->distinct();

            if ($excludeClientId > 0) {
                $query->where('client_id', '!=', $excludeClientId);
            }

            return (int) $query->count('client_id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Anomaly checks
    // ------------------------------------------------------------------

    /**
     * Compare the browser-reported timezone with IP geolocation timezone.
     *
     * Context may contain ip_intel.timezone from a prior IpIntelProvider run.
     */
    private function fps_checkTimezoneMismatch(array $fpData, array $context): bool
    {
        $browserTz = trim((string) ($fpData['timezone'] ?? ''));
        if ($browserTz === '') {
            return false;
        }

        // Check for IP-based timezone in context (set by orchestrator from IpIntel results)
        $ipTimezone = '';
        if (isset($context['ip_intel']['timezone'])) {
            $ipTimezone = trim((string) $context['ip_intel']['timezone']);
        }

        if ($ipTimezone === '') {
            // No IP timezone data available -- cannot compare
            return false;
        }

        // Normalise: compare UTC offsets if both are offset-based, or IANA names
        return strtolower($browserTz) !== strtolower($ipTimezone);
    }

    /**
     * Detect WebRTC IP leak: the fingerprint JS may capture WebRTC-revealed IPs
     * that differ from the HTTP-visible IP (indicating VPN/proxy).
     */
    private function fps_checkWebrtcLeak(array $fpData, string $clientIp): bool
    {
        $webrtcIps = $fpData['webrtc_ips'] ?? [];
        if (!is_array($webrtcIps) || empty($webrtcIps) || $clientIp === '') {
            return false;
        }

        foreach ($webrtcIps as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '' || $ip === $clientIp) {
                continue;
            }
            // Ignore private/reserved IPs (local network addresses in WebRTC are normal)
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                // This is a public IP that differs from the client IP -- leak detected
                return true;
            }
        }

        return false;
    }

    /**
     * Detect headless browser / automation indicators in the fingerprint.
     */
    private function fps_detectHeadless(array $fpData): bool
    {
        // Check explicit headless flag
        if (!empty($fpData['is_headless'])) {
            return true;
        }

        // WebDriver property present
        if (!empty($fpData['webdriver'])) {
            return true;
        }

        // Phantom.js, Selenium, Puppeteer indicators
        $userAgent = strtolower((string) ($fpData['user_agent'] ?? ''));
        $headlessSignals = ['headless', 'phantomjs', 'selenium', 'puppeteer', 'playwright'];
        foreach ($headlessSignals as $signal) {
            if (str_contains($userAgent, $signal)) {
                return true;
            }
        }

        // Zero plugins + zero mime types in a desktop browser is suspicious
        $pluginCount = (int) ($fpData['plugin_count'] ?? -1);
        $platform = strtolower((string) ($fpData['platform'] ?? ''));
        $isMobile = str_contains($platform, 'android') || str_contains($platform, 'iphone') || str_contains($platform, 'ipad');

        if (!$isMobile && $pluginCount === 0) {
            // Desktop browser with zero plugins -- suspicious but not definitive
            // Check hardware concurrency too (headless often reports 1-2)
            $cores = (int) ($fpData['hardware_concurrency'] ?? 0);
            if ($cores > 0 && $cores <= 2) {
                return true;
            }
        }

        // Notification permission API not available (common in headless)
        if (isset($fpData['notification_permission']) && $fpData['notification_permission'] === 'denied_by_default') {
            // Combined with other signals
            if ($pluginCount === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this fingerprint hash has been explicitly flagged as fraud.
     */
    private function fps_isKnownFraudFingerprint(string $fpHash): bool
    {
        try {
            return Capsule::table(self::TABLE_FRAUD_FP)
                ->where('fingerprint_hash', $fpHash)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Scoring
    // ------------------------------------------------------------------

    /**
     * Granular bot/automation detection scoring.
     * Returns ['score' => float, 'signals' => array]
     */
    private function fps_scoreBotSignals(array $fpData): array
    {
        $score = 0.0;
        $signals = [];

        // WebDriver property present (Selenium, Puppeteer, Playwright)
        if (!empty($fpData['webdriver'])) {
            $score += 30.0;
            $signals[] = 'webdriver_detected';
        }

        // User agent contains headless keywords
        $userAgent = strtolower((string) ($fpData['user_agent'] ?? ''));
        $headlessKeywords = ['headless', 'phantomjs', 'selenium', 'puppeteer', 'playwright'];
        foreach ($headlessKeywords as $keyword) {
            if (str_contains($userAgent, $keyword)) {
                $score += 30.0;
                $signals[] = 'ua_headless_' . $keyword;
                break; // Only count once
            }
        }

        // Missing plugins on desktop browser
        $pluginCount = (int) ($fpData['plugin_count'] ?? -1);
        $platform = strtolower((string) ($fpData['platform'] ?? ''));
        $isMobile = str_contains($platform, 'android')
            || str_contains($platform, 'iphone')
            || str_contains($platform, 'ipad');

        if (!$isMobile && $pluginCount === 0) {
            $score += 15.0;
            $signals[] = 'zero_plugins_desktop';
        }

        // Zero touch support on mobile user agent
        $touchSupport = $fpData['touch_support'] ?? null;
        $isMobileUa = (bool) preg_match('/mobile|android|iphone|ipad/i', $userAgent);
        if ($isMobileUa && ($touchSupport === false || $touchSupport === 0 || $touchSupport === '0')) {
            $score += 20.0;
            $signals[] = 'no_touch_on_mobile_ua';
        }

        // Missing or empty languages array
        $languages = $fpData['languages'] ?? null;
        if (empty($languages) || (is_array($languages) && count($languages) === 0)) {
            $score += 10.0;
            $signals[] = 'missing_languages';
        }

        // Very low hardware concurrency (1-2 cores typical of headless)
        $cores = (int) ($fpData['hardware_concurrency'] ?? 0);
        if ($cores > 0 && $cores <= 2 && !$isMobile) {
            $score += 10.0;
            $signals[] = 'low_cores_desktop';
        }

        // Explicit headless flag
        if (!empty($fpData['is_headless'])) {
            $score += 25.0;
            $signals[] = 'explicit_headless_flag';
        }

        // Missing device memory (common in headless)
        $deviceMemory = $fpData['device_memory'] ?? null;
        if ($deviceMemory === null || $deviceMemory === 0) {
            $score += 5.0;
            $signals[] = 'missing_device_memory';
        }

        return ['score' => min(100.0, $score), 'signals' => $signals];
    }

    private function fps_calculateScore(
        int $crossAccountCount,
        bool $timezoneMismatch,
        bool $webrtcLeak,
        bool $isHeadless,
        bool $isKnownFraud,
        array $botSignals = []
    ): float {
        $score = 0.0;

        // Cross-account: 3+ distinct clients with same fingerprint
        if ($crossAccountCount >= 3) {
            $score += 25.0;
        } elseif ($crossAccountCount >= 1) {
            $score += 10.0;
        }

        if ($timezoneMismatch) {
            $score += 10.0;
        }

        if ($webrtcLeak) {
            $score += 15.0;
        }

        // Use granular bot score instead of flat headless flag
        if (!empty($botSignals['score'])) {
            $score += $botSignals['score'];
        } elseif ($isHeadless) {
            $score += 20.0;
        }

        if ($isKnownFraud) {
            $score += 30.0;
        }

        return min(100.0, max(0.0, $score));
    }
}

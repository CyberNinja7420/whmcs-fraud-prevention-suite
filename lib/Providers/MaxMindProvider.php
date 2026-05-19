<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * MaxMindProvider -- IP intelligence via MaxMind minFraud Score API
 * and GeoIP2 Insights endpoint.
 *
 * Requires a MaxMind account with a license key. Free tier available.
 * minFraud Score: POST https://minfraud.maxmind.com/minfraud/v2.0/score
 * GeoIP2 Insights: GET https://geoip.maxmind.com/geoip/v2.1/insights/{ip}
 *
 * Results are cached in mod_fps_ip_intel for 24 hours.
 *
 * @see https://dev.maxmind.com/minfraud/
 * @see https://dev.maxmind.com/geoip/docs/web-services
 */
class MaxMindProvider implements FpsProviderInterface
{
    private const CACHE_HOURS = 24;
    private const MINFRAUD_URL = 'https://minfraud.maxmind.com/minfraud/v2.0/score';
    private const GEOIP_URL = 'https://geoip.maxmind.com/geoip/v2.1/insights/';

    public function getName(): string
    {
        return 'MaxMind';
    }

    public function isEnabled(): bool
    {
        $config = FpsConfig::getInstance();
        return $config->isEnabled('maxmind_enabled')
            && trim((string) $config->getCustom('maxmind_license_key', '')) !== ''
            && trim((string) $config->getCustom('maxmind_account_id', '')) !== '';
    }

    public function isQuick(): bool
    {
        return false;
    }

    public function getWeight(): float
    {
        return 1.4;
    }

    public function check(array $context): array
    {
        $ip = trim($context['ip'] ?? '');
        $email = trim($context['email'] ?? '');

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        // Check cache first
        $cached = $this->fps_getCachedMaxMind($ip);
        if ($cached !== null) {
            return $cached;
        }

        $config = FpsConfig::getInstance();
        $accountId = trim((string) $config->getCustom('maxmind_account_id', ''));
        $licenseKey = trim((string) $config->getCustom('maxmind_license_key', ''));

        if ($accountId === '' || $licenseKey === '') {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        // Try minFraud Score API first (richer data with email)
        $result = $this->fps_callMinFraudScore($accountId, $licenseKey, $ip, $email);

        if ($result !== null) {
            return $result;
        }

        // Fallback to GeoIP2 Insights (IP-only)
        $result = $this->fps_callGeoIpInsights($accountId, $licenseKey, $ip);

        if ($result !== null) {
            return $result;
        }

        return ['score' => 0.0, 'details' => [], 'raw' => null];
    }

    // -----------------------------------------------------------------------
    // Public test method (called from AJAX test_maxmind)
    // -----------------------------------------------------------------------

    /**
     * Test the MaxMind API connection with a sample IP.
     *
     * @return array{success: bool, message: string, data: mixed}
     */
    public function fps_testConnection(string $accountId, string $licenseKey): array
    {
        try {
            $testIp = '128.101.101.101'; // University of Minnesota (safe test IP)

            $ch = curl_init(self::GEOIP_URL . urlencode($testIp));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($accountId . ':' . $licenseKey),
                ],
                CURLOPT_USERAGENT => 'FPS-WHMCS/' . (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : '5.0'),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return ['success' => false, 'message' => 'Connection error: ' . $curlError, 'data' => null];
            }

            if ($httpCode === 401) {
                return ['success' => false, 'message' => 'Authentication failed. Check your Account ID and License Key.', 'data' => null];
            }

            if ($httpCode !== 200) {
                $errData = json_decode($response, true);
                $errMsg = $errData['error'] ?? "HTTP {$httpCode}";
                return ['success' => false, 'message' => 'API error: ' . $errMsg, 'data' => null];
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return ['success' => false, 'message' => 'Invalid JSON response from MaxMind', 'data' => null];
            }

            $country = $data['country']['iso_code'] ?? 'Unknown';
            $city = $data['city']['names']['en'] ?? 'Unknown';
            $riskScore = $data['risk'] ?? 'N/A';

            return [
                'success' => true,
                'message' => "Connection successful. Test IP {$testIp} resolved to {$city}, {$country} (risk: {$riskScore})",
                'data' => [
                    'country' => $country,
                    'city' => $city,
                    'risk' => $riskScore,
                    'traits' => $data['traits'] ?? [],
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage(), 'data' => null];
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Call MaxMind minFraud Score API.
     */
    private function fps_callMinFraudScore(string $accountId, string $licenseKey, string $ip, string $email): ?array
    {
        try {
            $body = ['device' => ['ip_address' => $ip]];

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $body['email'] = ['address' => $email];
            }

            $ch = curl_init(self::MINFRAUD_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($accountId . ':' . $licenseKey),
                ],
                CURLOPT_USERAGENT => 'FPS-WHMCS/' . (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : '5.0'),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall('fraud_prevention_suite', 'MaxMind::minFraud', $ip, "HTTP {$httpCode}");
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return null;
            }

            // risk_score from minFraud is 0.01-99.0
            $riskScore = (float) ($data['risk_score'] ?? 0);
            $ipRisk = (float) ($data['ip_address']['risk'] ?? 0);

            // Map directly to our 0-100 scale (minFraud uses same scale)
            $score = max($riskScore, $ipRisk);

            $details = [];
            if ($riskScore > 0) {
                $details[] = "MaxMind minFraud risk: {$riskScore}/99";
            }
            if ($ipRisk > 0 && abs($ipRisk - $riskScore) > 1) {
                $details[] = "MaxMind IP risk: {$ipRisk}/99";
            }

            $ipCountry = $data['ip_address']['country']['iso_code'] ?? '';
            if ($ipCountry !== '') {
                $details[] = "MaxMind country: {$ipCountry}";
            }

            // Cache the result
            $this->fps_cacheMaxMindResult($ip, $riskScore, $ipCountry);

            logModuleCall('fraud_prevention_suite', 'MaxMind::minFraud', $ip,
                json_encode(['risk_score' => $riskScore, 'ip_risk' => $ipRisk, 'country' => $ipCountry])
            );

            return [
                'score'   => min($score, 100.0),
                'details' => $details,
                'raw'     => $data,
            ];
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'MaxMind::minFraud::ERROR', $ip, $e->getMessage());
            return null;
        }
    }

    /**
     * Call MaxMind GeoIP2 Insights API (IP-only fallback).
     */
    private function fps_callGeoIpInsights(string $accountId, string $licenseKey, string $ip): ?array
    {
        try {
            $ch = curl_init(self::GEOIP_URL . urlencode($ip));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($accountId . ':' . $licenseKey),
                ],
                CURLOPT_USERAGENT => 'FPS-WHMCS/' . (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : '5.0'),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall('fraud_prevention_suite', 'MaxMind::GeoIP2', $ip, "HTTP {$httpCode}");
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return null;
            }

            $score = 0.0;
            $details = [];

            // GeoIP2 Insights risk score (0-99)
            $ipRisk = (float) ($data['traits']['ip_risk'] ?? $data['risk'] ?? 0);
            if ($ipRisk > 0) {
                $score = $ipRisk;
                $details[] = "MaxMind GeoIP2 risk: {$ipRisk}/99";
            }

            // Anonymous IP flags
            $isAnonymous = (bool) ($data['traits']['is_anonymous'] ?? false);
            $isAnonymousVpn = (bool) ($data['traits']['is_anonymous_vpn'] ?? false);
            $isTorNode = (bool) ($data['traits']['is_tor_exit_node'] ?? false);
            $isHostingProvider = (bool) ($data['traits']['is_hosting_provider'] ?? false);

            $flags = [];
            if ($isAnonymous) $flags[] = 'anonymous';
            if ($isAnonymousVpn) $flags[] = 'VPN';
            if ($isTorNode) {
                $flags[] = 'Tor exit';
                $score += 20;
            }
            if ($isHostingProvider) {
                $flags[] = 'hosting/datacenter';
                $score += 10;
            }
            if (!empty($flags)) {
                $details[] = 'MaxMind flags: ' . implode(', ', $flags);
            }

            $country = $data['country']['iso_code'] ?? '';
            if ($country !== '') {
                $details[] = "MaxMind country: {$country}";
            }

            // Cache
            $this->fps_cacheMaxMindResult($ip, $score, $country);

            logModuleCall('fraud_prevention_suite', 'MaxMind::GeoIP2', $ip,
                json_encode(['risk' => $ipRisk, 'anonymous' => $isAnonymous, 'vpn' => $isAnonymousVpn, 'tor' => $isTorNode])
            );

            return [
                'score'   => min($score, 100.0),
                'details' => $details,
                'raw'     => $data,
            ];
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'MaxMind::GeoIP2::ERROR', $ip, $e->getMessage());
            return null;
        }
    }

    /**
     * Check IP intel cache for MaxMind data.
     */
    private function fps_getCachedMaxMind(string $ip): ?array
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                return null;
            }

            // Check for maxmind_risk column existence before querying
            if (!Capsule::schema()->hasColumn('mod_fps_ip_intel', 'maxmind_risk')) {
                return null;
            }

            $row = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->whereNotNull('maxmind_risk')
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_HOURS . ' hours')))
                ->first();

            if (!$row) {
                return null;
            }

            $risk = (float) $row->maxmind_risk;

            return [
                'score'   => min($risk, 100.0),
                'details' => $risk > 0 ? ["MaxMind: {$risk} (cached)"] : [],
                'raw'     => ['cached' => true, 'maxmind_risk' => $risk],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Cache MaxMind result in mod_fps_ip_intel.
     */
    private function fps_cacheMaxMindResult(string $ip, float $risk, string $country): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                return;
            }

            // Ensure maxmind_risk column exists (upgrade path)
            if (!Capsule::schema()->hasColumn('mod_fps_ip_intel', 'maxmind_risk')) {
                try {
                    Capsule::schema()->table('mod_fps_ip_intel', function ($table) {
                        $table->decimal('maxmind_risk', 5, 2)->nullable()->after('threat_score');
                    });
                } catch (\Throwable $e) {
                    // Column may already exist from another request
                    return;
                }
            }

            $exists = Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->exists();
            $data = [
                'maxmind_risk' => $risk,
                'cached_at'    => date('Y-m-d H:i:s'),
            ];

            if ($country !== '') {
                $data['country_code'] = strtoupper(substr($country, 0, 5));
            }

            if ($exists) {
                Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->update($data);
            } else {
                $data['ip_address'] = $ip;
                $data['expires_at'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
                Capsule::table('mod_fps_ip_intel')->insert($data);
            }
        } catch (\Throwable $e) {
            // Non-fatal caching failure
        }
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * AbuseIpdbProvider -- checks IPs against the AbuseIPDB crowd-sourced database.
 *
 * Free tier: 1,000 checks/day. Results cached 24h in mod_fps_ip_intel.
 * Also supports reporting confirmed fraud IPs back to the database.
 *
 * @see https://docs.abuseipdb.com/
 */
class AbuseIpdbProvider implements FpsProviderInterface
{
    private const API_BASE = 'https://api.abuseipdb.com/api/v2';
    private const CACHE_HOURS = 24;

    public function getName(): string
    {
        return 'abuseipdb';
    }

    public function isEnabled(): bool
    {
        $config = FpsConfig::getInstance();
        return $config->isEnabled('abuseipdb_enabled')
            && trim((string) $config->getCustom('abuseipdb_api_key', '')) !== '';
    }

    public function isQuick(): bool
    {
        return true; // Cached results are instant; API calls are fast
    }

    public function getWeight(): float
    {
        return 1.3;
    }

    public function check(array $context): array
    {
        $ip = trim($context['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        // Check cache first
        $cached = $this->fps_getCached($ip);
        if ($cached !== null) {
            return $cached;
        }

        // Query API
        $apiKey = trim((string) FpsConfig::getInstance()->getCustom('abuseipdb_api_key', ''));
        if ($apiKey === '') {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        try {
            $url = self::API_BASE . '/check?' . http_build_query([
                'ipAddress'    => $ip,
                'maxAgeInDays' => 90,
                'verbose'      => '',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Key: ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall('fraud_prevention_suite', 'AbuseIPDB::check', $ip, "HTTP {$httpCode}");
                return ['score' => 0.0, 'details' => [], 'raw' => null];
            }

            $data = json_decode($response, true);
            $report = $data['data'] ?? [];

            $abuseScore    = (int) ($report['abuseConfidenceScore'] ?? 0);
            $totalReports  = (int) ($report['totalReports'] ?? 0);
            $usageType     = (string) ($report['usageType'] ?? '');
            $isp           = (string) ($report['isp'] ?? '');
            $countryCode   = (string) ($report['countryCode'] ?? '');
            $isWhitelisted = (bool) ($report['isWhitelisted'] ?? false);

            // Score calculation
            $score = 0.0;
            if ($isWhitelisted) {
                $score = 0.0;
            } else {
                $score = $abuseScore / 2.0; // 0-50 base range
                if ($totalReports > 50) {
                    $score += 15;
                } elseif ($totalReports > 10) {
                    $score += 10;
                }
                if (stripos($usageType, 'Data Center') !== false || stripos($usageType, 'Hosting') !== false) {
                    $score += 5;
                }
                $score = min($score, 100.0);
            }

            $details = [];
            if ($abuseScore > 0) {
                $details[] = "AbuseIPDB confidence: {$abuseScore}%";
            }
            if ($totalReports > 0) {
                $details[] = "Reported {$totalReports} times in 90 days";
            }
            if ($usageType !== '') {
                $details[] = "Usage: {$usageType}";
            }

            // Cache the result
            $this->fps_cacheResult($ip, $abuseScore, $totalReports, $usageType);

            logModuleCall('fraud_prevention_suite', 'AbuseIPDB::check', $ip,
                json_encode(['score' => $abuseScore, 'reports' => $totalReports, 'usage' => $usageType]),
                '', ['Key']
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => $report,
            ];

        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AbuseIPDB::ERROR', $ip, $e->getMessage());
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }
    }

    /**
     * Report an IP to AbuseIPDB (for auto-reporting on deny/terminate).
     *
     * @param string $ip         IP to report
     * @param string $comment    Reason for reporting
     * @param array  $categories AbuseIPDB category IDs (e.g., [18] for brute force, [15] for fraud)
     * @return bool Success
     */
    public function fps_reportAbuse(string $ip, string $comment, array $categories = [15]): bool
    {
        $apiKey = trim((string) FpsConfig::getInstance()->getCustom('abuseipdb_api_key', ''));
        if ($apiKey === '') return false;

        try {
            $ch = curl_init(self::API_BASE . '/report');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'ip'         => $ip,
                    'categories' => implode(',', $categories),
                    'comment'    => substr($comment, 0, 1024),
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => [
                    'Key: ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logModuleCall('fraud_prevention_suite', 'AbuseIPDB::report', $ip,
                "HTTP {$httpCode}: " . substr((string)$response, 0, 200), '', ['Key']);

            return $httpCode === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------------

    private function fps_getCached(string $ip): ?array
    {
        try {
            $row = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->whereNotNull('abuseipdb_score')
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_HOURS . ' hours')))
                ->first();

            if (!$row) return null;

            $abuseScore = (int) $row->abuseipdb_score;
            $totalReports = (int) ($row->abuseipdb_reports ?? 0);
            $score = $abuseScore / 2.0;
            if ($totalReports > 50) $score += 15;
            elseif ($totalReports > 10) $score += 10;

            return [
                'score'   => min($score, 100.0),
                'details' => $abuseScore > 0 ? ["AbuseIPDB: {$abuseScore}% confidence, {$totalReports} reports (cached)"] : [],
                'raw'     => ['cached' => true, 'abuseipdb_score' => $abuseScore],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fps_cacheResult(string $ip, int $abuseScore, int $totalReports, string $usageType): void
    {
        try {
            $exists = Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->exists();
            $data = [
                'abuseipdb_score'      => $abuseScore,
                'abuseipdb_reports'    => $totalReports,
                'abuseipdb_usage_type' => substr($usageType, 0, 100),
                'cached_at'            => date('Y-m-d H:i:s'),
            ];

            if ($exists) {
                Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->update($data);
            } else {
                $data['ip_address'] = $ip;
                $data['expires_at'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
                Capsule::table('mod_fps_ip_intel')->insert($data);
            }
        } catch (\Throwable $e) {
            // Cache miss is non-fatal
        }
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * IpQualityScoreProvider -- comprehensive IP fraud scoring via IPQualityScore.
 *
 * Free tier: 5,000 lookups/month. Returns fraud score, proxy/VPN/Tor detection,
 * bot status, and abuse velocity. Results cached 24h in mod_fps_ip_intel.
 *
 * @see https://www.ipqualityscore.com/documentation/proxy-detection-api/overview
 */
class IpQualityScoreProvider implements FpsProviderInterface
{
    private const CACHE_HOURS = 24;

    public function getName(): string
    {
        return 'ipqualityscore';
    }

    public function isEnabled(): bool
    {
        $config = FpsConfig::getInstance();
        return $config->isEnabled('ipqs_enabled')
            && trim((string) $config->getCustom('ipqs_api_key', '')) !== '';
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 1.4;
    }

    public function check(array $context): array
    {
        $ip = trim($context['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        // Check cache
        $cached = $this->fps_getCached($ip);
        if ($cached !== null) {
            return $cached;
        }

        $apiKey = trim((string) FpsConfig::getInstance()->getCustom('ipqs_api_key', ''));
        if ($apiKey === '') {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        try {
            $url = 'https://ipqualityscore.com/api/json/ip/' . urlencode($apiKey) . '/' . urlencode($ip)
                . '?' . http_build_query(['strictness' => 1, 'allow_public_access_points' => 'true']);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'FPS-WHMCS/4.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall('fraud_prevention_suite', 'IPQS::check', $ip, "HTTP {$httpCode}");
                return ['score' => 0.0, 'details' => [], 'raw' => null];
            }

            $data = json_decode($response, true);
            if (!is_array($data) || !($data['success'] ?? false)) {
                return ['score' => 0.0, 'details' => [], 'raw' => $data];
            }

            $fraudScore     = (int) ($data['fraud_score'] ?? 0);
            $isProxy        = (bool) ($data['proxy'] ?? false);
            $isVpn          = (bool) ($data['vpn'] ?? false);
            $isTor          = (bool) ($data['tor'] ?? false);
            $botStatus      = (bool) ($data['bot_status'] ?? false);
            $abuseVelocity  = (string) ($data['abuse_velocity'] ?? 'none');
            $isMobile       = (bool) ($data['mobile'] ?? false);
            $recentAbuse    = (bool) ($data['recent_abuse'] ?? false);
            $isCrawler      = (bool) ($data['is_crawler'] ?? false);

            // Score calculation
            $score = $fraudScore * 0.6; // 0-60 base range
            if ($isProxy) $score += 15;
            if ($isVpn) $score += 20;
            if ($isTor) $score += 25;
            if ($botStatus) $score += 10;
            if ($abuseVelocity === 'high') $score += 10;
            elseif ($abuseVelocity === 'medium') $score += 5;
            if ($recentAbuse) $score += 10;
            $score = min($score, 100.0);

            $details = [];
            if ($fraudScore > 0) {
                $details[] = "IPQS fraud score: {$fraudScore}/100";
            }
            $flags = [];
            if ($isProxy) $flags[] = 'proxy';
            if ($isVpn) $flags[] = 'VPN';
            if ($isTor) $flags[] = 'Tor';
            if ($botStatus) $flags[] = 'bot';
            if ($recentAbuse) $flags[] = 'recent abuse';
            if ($isCrawler) $flags[] = 'crawler';
            if (!empty($flags)) {
                $details[] = 'Flags: ' . implode(', ', $flags);
            }
            if ($abuseVelocity !== 'none') {
                $details[] = "Abuse velocity: {$abuseVelocity}";
            }

            // Cache
            $this->fps_cacheResult($ip, $fraudScore, $botStatus);

            logModuleCall('fraud_prevention_suite', 'IPQS::check', $ip,
                json_encode(['fraud_score' => $fraudScore, 'proxy' => $isProxy, 'vpn' => $isVpn, 'tor' => $isTor])
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => $data,
            ];

        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'IPQS::ERROR', $ip, $e->getMessage());
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }
    }

    // -----------------------------------------------------------------------

    private function fps_getCached(string $ip): ?array
    {
        try {
            $row = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->whereNotNull('ipqs_fraud_score')
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_HOURS . ' hours')))
                ->first();

            if (!$row) return null;

            $fraudScore = (int) $row->ipqs_fraud_score;
            $score = $fraudScore * 0.6;

            return [
                'score'   => min($score, 100.0),
                'details' => $fraudScore > 0 ? ["IPQS: {$fraudScore}/100 (cached)"] : [],
                'raw'     => ['cached' => true, 'ipqs_fraud_score' => $fraudScore],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fps_cacheResult(string $ip, int $fraudScore, bool $botStatus): void
    {
        try {
            $exists = Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->exists();
            $data = [
                'ipqs_fraud_score' => $fraudScore,
                'ipqs_bot_status'  => $botStatus ? 1 : 0,
                'cached_at'        => date('Y-m-d H:i:s'),
            ];

            if ($exists) {
                Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->update($data);
            } else {
                $data['ip_address'] = $ip;
                $data['expires_at'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
                Capsule::table('mod_fps_ip_intel')->insert($data);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}

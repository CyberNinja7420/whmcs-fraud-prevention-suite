<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * AbuseSignalProvider -- aggregates FREE abuse signal sources.
 *
 * Sources (no API keys required for checking):
 *   1. StopForumSpam -- crowd-sourced database of forum/signup spam IPs and emails
 *   2. SpamHaus ZEN  -- DNS-based blocklist of known spam/botnet IPs
 *
 * Also supports reporting confirmed fraud to StopForumSpam (requires API key).
 */
class AbuseSignalProvider implements FpsProviderInterface
{
    private const SFS_API = 'https://api.stopforumspam.org/api';

    public function getName(): string
    {
        return 'abuse_signal';
    }

    public function isEnabled(): bool
    {
        return FpsConfig::getInstance()->isEnabled('abuse_signal_enabled');
    }

    public function isQuick(): bool
    {
        return true; // SFS is fast, SpamHaus is DNS-based (instant)
    }

    public function getWeight(): float
    {
        return 1.2;
    }

    public function check(array $context): array
    {
        $ip    = trim($context['ip'] ?? '');
        $email = trim($context['email'] ?? '');

        if ($ip === '' && $email === '') {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        $score = 0.0;
        $details = [];
        $raw = [];

        // 1. StopForumSpam check (IP + email in one call)
        $sfsResult = $this->fps_checkStopForumSpam($ip, $email);
        if ($sfsResult !== null) {
            $raw['stopforumspam'] = $sfsResult;

            $ipAppears = (bool) ($sfsResult['ip']['appears'] ?? false);
            $ipConf    = (float) ($sfsResult['ip']['confidence'] ?? 0);
            $emailAppears = (bool) ($sfsResult['email']['appears'] ?? false);
            $emailConf    = (float) ($sfsResult['email']['confidence'] ?? 0);

            if ($ipAppears && $ipConf > 50) {
                $bonus = min(30, $ipConf * 0.3);
                $score += $bonus;
                $details[] = "StopForumSpam: IP flagged ({$ipConf}% confidence)";
            }
            if ($emailAppears && $emailConf > 50) {
                $bonus = min(25, $emailConf * 0.25);
                $score += $bonus;
                $details[] = "StopForumSpam: Email flagged ({$emailConf}% confidence)";
            }
        }

        // 2. SpamHaus ZEN check (DNS-based, instant)
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $spamhausResult = $this->fps_checkSpamHaus($ip);
            $raw['spamhaus'] = $spamhausResult;
            if ($spamhausResult['listed']) {
                $score += 40;
                $details[] = 'SpamHaus ZEN: IP is listed (' . implode(', ', $spamhausResult['zones']) . ')';
            }
        }

        return [
            'score'   => min($score, 100.0),
            'details' => $details,
            'raw'     => $raw,
        ];
    }

    /**
     * Report an IP/email to StopForumSpam (requires SFS API key).
     */
    public function fps_reportToStopForumSpam(string $ip, string $email, string $evidence = ''): bool
    {
        $apiKey = trim((string) FpsConfig::getInstance()->getCustom('sfs_report_api_key', ''));
        if ($apiKey === '') return false;

        try {
            $postData = [
                'api_key'  => $apiKey,
                'ip_addr'  => $ip,
                'email'    => $email,
                'evidence' => substr($evidence, 0, 500),
            ];

            $ch = curl_init('https://www.stopforumspam.org/add');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            logModuleCall('fraud_prevention_suite', 'SFS::report', "{$ip} / {$email}",
                "HTTP {$httpCode}", '', ['api_key']);

            return $httpCode === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Query StopForumSpam API for IP + email.
     */
    private function fps_checkStopForumSpam(string $ip, string $email): ?array
    {
        try {
            $params = ['json' => ''];
            if ($ip !== '') $params['ip'] = $ip;
            if ($email !== '') $params['email'] = $email;

            $url = self::SFS_API . '?' . http_build_query($params);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) return null;

            $data = json_decode($response, true);
            if (!is_array($data) || !($data['success'] ?? false)) return null;

            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check IP against SpamHaus ZEN via DNS lookup.
     *
     * SpamHaus ZEN combines: SBL (spam), XBL (exploits), PBL (policy), CSS (snowshoe spam).
     * A DNS hit means the IP is on at least one SpamHaus list.
     */
    private function fps_checkSpamHaus(string $ip): array
    {
        try {
            // Reverse IP octets for DNS query
            $parts = array_reverse(explode('.', $ip));
            $query = implode('.', $parts) . '.zen.spamhaus.org';

            $result = @dns_get_record($query, DNS_A);
            if (empty($result)) {
                return ['listed' => false, 'zones' => []];
            }

            // Parse zone indicators from A record IPs
            $zones = [];
            foreach ($result as $record) {
                $addr = $record['ip'] ?? '';
                if (strpos($addr, '127.0.0.') === 0) {
                    $last = (int) substr($addr, strrpos($addr, '.') + 1);
                    if ($last >= 2 && $last <= 3) $zones[] = 'SBL';
                    elseif ($last >= 4 && $last <= 7) $zones[] = 'XBL';
                    elseif ($last >= 10 && $last <= 11) $zones[] = 'PBL';
                }
            }

            return ['listed' => true, 'zones' => array_unique($zones)];
        } catch (\Throwable $e) {
            return ['listed' => false, 'zones' => []];
        }
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * DomainReputationProvider -- checks email domain reputation and age.
 *
 * Scoring signals:
 *   1. Domain age via RDAP (new domains are higher risk)
 *   2. Suspicious TLD detection (.xyz, .top, .click, etc.)
 *   3. Google Safe Browsing API (optional, requires API key)
 *
 * Does NOT require an API key for basic domain age + TLD checks.
 * Google Safe Browsing key is optional for enhanced protection.
 */
class DomainReputationProvider implements FpsProviderInterface
{
    /** High-risk/abuse-prone TLDs commonly used in fraud */
    private const SUSPICIOUS_TLDS = [
        'xyz', 'top', 'work', 'click', 'loan', 'gq', 'cf', 'tk', 'ml', 'ga',
        'buzz', 'monster', 'rest', 'icu', 'casa', 'surf', 'bar', 'cyou',
        'cam', 'quest', 'sbs', 'cfd', 'boats', 'hair',
    ];

    private const SAFE_BROWSING_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    public function getName(): string
    {
        return 'domain_reputation';
    }

    public function isEnabled(): bool
    {
        return FpsConfig::getInstance()->isEnabled('domain_reputation_enabled');
    }

    public function isQuick(): bool
    {
        return false; // RDAP can be slow (1-3s)
    }

    public function getWeight(): float
    {
        return 1.1;
    }

    public function check(array $context): array
    {
        $domain = strtolower(trim($context['domain'] ?? ''));
        if ($domain === '') {
            // Try to extract from email
            $email = strtolower(trim($context['email'] ?? ''));
            if (strpos($email, '@') !== false) {
                $domain = substr($email, strpos($email, '@') + 1);
            }
        }
        if ($domain === '') {
            return ['score' => 0.0, 'details' => [], 'raw' => null];
        }

        $score = 0.0;
        $details = [];
        $raw = ['domain' => $domain];

        // 1. Suspicious TLD check (instant)
        $tld = $this->fps_extractTld($domain);
        if (in_array($tld, self::SUSPICIOUS_TLDS, true)) {
            $score += 15;
            $details[] = "Suspicious TLD: .{$tld}";
            $raw['suspicious_tld'] = true;
        }

        // 2. Domain age via RDAP
        $age = $this->fps_getDomainAge($domain);
        $raw['domain_age_days'] = $age;
        if ($age !== null) {
            if ($age < 7) {
                $score += 30;
                $details[] = "Domain registered {$age} day(s) ago (very new)";
            } elseif ($age < 30) {
                $score += 20;
                $details[] = "Domain registered {$age} days ago (new)";
            } elseif ($age < 90) {
                $score += 10;
                $details[] = "Domain registered {$age} days ago (recent)";
            }
        }

        // 3. Google Safe Browsing (if key configured)
        $safeBrowsingKey = trim((string) FpsConfig::getInstance()->getCustom('safe_browsing_api_key', ''));
        if ($safeBrowsingKey !== '') {
            $isThreat = $this->fps_checkSafeBrowsing($domain, $safeBrowsingKey);
            $raw['safe_browsing_threat'] = $isThreat;
            if ($isThreat) {
                $score += 60;
                $details[] = 'Google Safe Browsing: Domain flagged as threat';
            }
        }

        return [
            'score'   => min($score, 100.0),
            'details' => $details,
            'raw'     => $raw,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Extract TLD from domain (handles multi-level like co.uk).
     */
    private function fps_extractTld(string $domain): string
    {
        $parts = explode('.', $domain);
        if (count($parts) < 2) return '';
        return end($parts);
    }

    /**
     * Get domain age in days via RDAP.
     * Returns null if unable to determine.
     */
    private function fps_getDomainAge(string $domain): ?int
    {
        // Check email intel cache first
        try {
            $cached = Capsule::table('mod_fps_email_intel')
                ->where('domain', $domain)
                ->whereNotNull('domain_age_days')
                ->where('domain_age_days', '>', 0)
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->value('domain_age_days');
            if ($cached !== null) {
                return (int) $cached;
            }
        } catch (\Throwable $e) {
            // Continue to RDAP
        }

        try {
            // Query RDAP for domain registration date
            $rdapUrl = 'https://rdap.org/domain/' . urlencode($domain);
            $ch = curl_init($rdapUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_HTTPHEADER     => ['Accept: application/rdap+json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) return null;

            $data = json_decode($response, true);
            if (!is_array($data)) return null;

            // Find registration date from events array
            $regDate = null;
            foreach ($data['events'] ?? [] as $event) {
                $action = strtolower($event['eventAction'] ?? '');
                if ($action === 'registration') {
                    $regDate = $event['eventDate'] ?? null;
                    break;
                }
            }

            if ($regDate === null) return null;

            $regTimestamp = strtotime($regDate);
            if ($regTimestamp === false) return null;

            $ageDays = (int) floor((time() - $regTimestamp) / 86400);
            if ($ageDays < 0) $ageDays = 0;

            // Cache in email_intel
            try {
                Capsule::table('mod_fps_email_intel')
                    ->updateOrInsert(
                        ['domain' => $domain],
                        ['domain_age_days' => $ageDays, 'cached_at' => date('Y-m-d H:i:s')]
                    );
            } catch (\Throwable $e) {
                // Non-fatal
            }

            return $ageDays;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check domain against Google Safe Browsing API.
     */
    private function fps_checkSafeBrowsing(string $domain, string $apiKey): bool
    {
        try {
            $url = self::SAFE_BROWSING_URL . '?key=' . urlencode($apiKey);
            $payload = json_encode([
                'client' => [
                    'clientId'      => 'fps-whmcs',
                    'clientVersion' => '4.0.0',
                ],
                'threatInfo' => [
                    'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                    'platformTypes'    => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries'    => [
                        ['url' => 'http://' . $domain . '/'],
                        ['url' => 'https://' . $domain . '/'],
                    ],
                ],
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) return false;

            $data = json_decode($response, true);
            // Non-empty matches array means threat detected
            return !empty($data['matches']);

        } catch (\Throwable $e) {
            return false;
        }
    }
}

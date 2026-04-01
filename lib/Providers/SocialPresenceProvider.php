<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Social presence detection provider.
 *
 * Issues lightweight HTTP HEAD requests to major social platforms to detect
 * whether a profile exists for the username derived from the email address.
 *
 * Uses NEGATIVE scoring: confirmed social presence REDUCES the risk score,
 * while complete absence of any social footprint adds a small penalty.
 *
 * This provider is NOT quick because it makes multiple sequential HTTP requests
 * to external services that may be slow.
 */
class SocialPresenceProvider implements FpsProviderInterface
{
    private const TIMEOUT = 3;

    /**
     * Platforms to check, keyed by internal name.
     * Each entry has a URL template where {username} is replaced.
     */
    private const PLATFORMS = [
        'github'   => 'https://github.com/{username}',
        'linkedin' => 'https://www.linkedin.com/in/{username}',
        'twitter'  => 'https://twitter.com/{username}',
    ];

    public function getName(): string
    {
        return 'Social Presence';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isQuick(): bool
    {
        return false;
    }

    public function getWeight(): float
    {
        return 0.4;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $email = strtolower(trim($context['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $blank;
        }

        try {
            $username = $this->fps_extractUsername($email);
            if ($username === '' || strlen($username) < 2) {
                return $blank;
            }

            // Skip obviously non-personal usernames
            if ($this->fps_isGenericUsername($username)) {
                return $blank;
            }

            $results = [];
            $foundCount = 0;

            foreach (self::PLATFORMS as $platform => $urlTemplate) {
                $url = str_replace('{username}', urlencode($username), $urlTemplate);
                $found = $this->fps_checkProfileExists($url, $platform);
                $results[$platform] = $found;
                if ($found) {
                    $foundCount++;
                }
            }

            $score = $this->fps_calculateScore($results, $foundCount);

            $details = [
                'username'      => $username,
                'platforms'     => $results,
                'found_count'   => $foundCount,
                'total_checked' => count(self::PLATFORMS),
            ];

            logModuleCall(
                'fraud_prevention_suite',
                'SocialPresence Check',
                $username,
                json_encode($results),
                '',
                []
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => $results,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'SocialPresence Error',
                $email,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Profile detection
    // ------------------------------------------------------------------

    /**
     * Check if a profile exists at the given URL via HTTP HEAD/GET.
     *
     * A 200 response is treated as "profile exists".
     * 404, timeouts, and errors are treated as "not found".
     */
    private function fps_checkProfileExists(string $url, string $platform): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOBODY         => true, // HEAD request
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WHMCS-FraudPreventionSuite/2.0)',
        ]);

        // LinkedIn blocks HEAD requests -- use GET with minimal download
        if ($platform === 'linkedin') {
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_RANGE, '0-1024'); // Only download first 1KB
        }

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // 200 = profile exists
        // Some platforms redirect 404s to homepage or login
        if ($httpCode === 200) {
            // LinkedIn redirects missing profiles to login page
            if ($platform === 'linkedin' && str_contains($finalUrl, '/login')) {
                return false;
            }
            // Twitter/X may redirect to login for missing profiles
            if ($platform === 'twitter' && str_contains($finalUrl, '/login')) {
                return false;
            }
            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Scoring (NEGATIVE = reduces risk)
    // ------------------------------------------------------------------

    private function fps_calculateScore(array $results, int $foundCount): float
    {
        $score = 0.0;

        // Negative scoring: each confirmed platform reduces risk
        if (!empty($results['linkedin'])) {
            $score -= 10.0;
        }
        if (!empty($results['github'])) {
            $score -= 10.0;
        }

        // Any presence at all is a mild trust signal
        if ($foundCount > 0) {
            $score -= 5.0;
        }

        // No social presence at all is mildly suspicious
        if ($foundCount === 0) {
            $score += 10.0;
        }

        // Score can be negative (trust bonus), clamped to -25..100
        return max(-25.0, min(100.0, $score));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Extract the local part of an email as a potential username.
     */
    private function fps_extractUsername(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';

        // Strip common suffixes (digits, +tags)
        $local = preg_replace('/\+.*$/', '', $local);  // Gmail-style tags
        $local = preg_replace('/\d+$/', '', $local);   // Trailing numbers

        // Remove dots (GitHub/LinkedIn don't use dots in usernames the same way)
        $clean = str_replace('.', '', $local);

        return $clean !== '' ? $clean : $local;
    }

    /**
     * Skip obviously non-personal/generic usernames that would produce
     * false positives on social platforms.
     */
    private function fps_isGenericUsername(string $username): bool
    {
        $generic = [
            'info', 'admin', 'support', 'contact', 'sales', 'billing',
            'noreply', 'no-reply', 'webmaster', 'postmaster', 'help',
            'test', 'demo', 'user', 'mail', 'email', 'hello', 'hi',
            'office', 'team', 'service', 'account', 'accounts',
        ];
        return in_array(strtolower($username), $generic, true);
    }
}

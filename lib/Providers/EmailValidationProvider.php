<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Email validation provider -- fully built-in, no external API required.
 *
 * Checks performed:
 *   - MX record existence (dns_get_record / checkdnsrr)
 *   - Disposable domain detection (bundled list)
 *   - Free email provider detection (bundled list)
 *   - Role account detection (info@, admin@, etc.)
 *   - Domain age via RDAP (free, no key)
 *
 * Results cached in mod_fps_email_intel for 7 days.
 */
class EmailValidationProvider implements FpsProviderInterface
{
    private const CACHE_TABLE = 'mod_fps_email_intel';
    private const CACHE_TTL_DAYS = 7;
    private const TIMEOUT = 3;

    /** Common role-based local parts that are not real humans. */
    private const ROLE_ACCOUNTS = [
        'abuse', 'admin', 'administrator', 'billing', 'compliance',
        'devnull', 'dns', 'ftp', 'hostmaster', 'info', 'inoc',
        'ispfeedback', 'ispsupport', 'list', 'list-request', 'maildaemon',
        'marketing', 'noc', 'no-reply', 'noreply', 'null', 'office',
        'phish', 'phishing', 'postmaster', 'privacy', 'registrar',
        'root', 'sales', 'security', 'spam', 'support', 'sysadmin',
        'tech', 'undisclosed-recipients', 'unsubscribe', 'usenet',
        'uucp', 'webmaster', 'www',
    ];

    /** In-memory cache for disposable domains (loaded once per request). */
    private ?array $fps_disposableDomains = null;

    /** In-memory cache for free email providers (loaded once per request). */
    private ?array $fps_freeProviders = null;

    public function getName(): string
    {
        return 'Email Validation';
    }

    public function isEnabled(): bool
    {
        return true; // No external dependency
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $email = strtolower(trim($context['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $blank;
        }

        try {
            // Check cache
            $cached = $this->fps_getCached($email);
            if ($cached !== null) {
                return [
                    'score'   => $this->fps_calculateScore($cached),
                    'details' => $cached,
                    'raw'     => $cached,
                ];
            }

            $parts  = explode('@', $email, 2);
            $local  = $parts[0];
            $domain = $parts[1] ?? '';

            if ($domain === '') {
                return $blank;
            }

            $intel = [
                'has_mx'        => $this->fps_checkMx($domain),
                'is_disposable' => $this->fps_isDisposable($domain),
                'is_free'       => $this->fps_isFreeProvider($domain),
                'is_role'       => $this->fps_isRoleAccount($local),
                'domain_age_days' => $this->fps_getDomainAgeDays($domain),
                'domain'        => $domain,
                'local_part'    => $local,
            ];

            $this->fps_cacheResult($email, $intel);

            $score = $this->fps_calculateScore($intel);
            return [
                'score'   => $score,
                'details' => $intel,
                'raw'     => $intel,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'EmailValidation Error',
                $email,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Individual checks
    // ------------------------------------------------------------------

    private function fps_checkMx(string $domain): bool
    {
        try {
            if (checkdnsrr($domain, 'MX')) {
                return true;
            }
            // Some domains only have A records, still accept mail
            $records = @dns_get_record($domain, DNS_MX);
            return is_array($records) && count($records) > 0;
        } catch (\Throwable $e) {
            // DNS failure -- assume valid to avoid false positives
            return true;
        }
    }

    private function fps_isDisposable(string $domain): bool
    {
        $domains = $this->fps_loadDisposableDomains();
        return isset($domains[$domain]);
    }

    private function fps_isFreeProvider(string $domain): bool
    {
        $providers = $this->fps_loadFreeProviders();
        return isset($providers[$domain]);
    }

    private function fps_isRoleAccount(string $local): bool
    {
        return in_array($local, self::ROLE_ACCOUNTS, true);
    }

    /**
     * Determine domain age in days via RDAP (free, no key).
     *
     * Returns -1 if age cannot be determined.
     */
    private function fps_getDomainAgeDays(string $domain): int
    {
        try {
            // Extract registrable domain (strip subdomains for RDAP)
            $registrable = $this->fps_getRegistrableDomain($domain);
            $url = 'https://rdap.org/domain/' . urlencode($registrable);

            $body = $this->fps_httpGet($url);
            if ($body === null) {
                return -1;
            }

            logModuleCall('fraud_prevention_suite', 'EmailValidation RDAP', $registrable, substr($body, 0, 500), '', []);

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return -1;
            }

            // Look for registration event
            $events = $data['events'] ?? [];
            foreach ($events as $event) {
                $action = $event['eventAction'] ?? '';
                if ($action === 'registration') {
                    $date = $event['eventDate'] ?? '';
                    if ($date !== '') {
                        $ts = strtotime($date);
                        if ($ts !== false) {
                            $days = (int) floor((time() - $ts) / 86400);
                            return max(0, $days);
                        }
                    }
                }
            }

            return -1;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    // ------------------------------------------------------------------
    // Scoring
    // ------------------------------------------------------------------

    private function fps_calculateScore(array $intel): float
    {
        $score = 0.0;

        if (empty($intel['has_mx'])) {
            $score += 30.0;
        }
        if (!empty($intel['is_disposable'])) {
            $score += 40.0;
        }
        if (!empty($intel['is_role'])) {
            $score += 15.0;
        }

        $domainAge = (int) ($intel['domain_age_days'] ?? -1);
        if ($domainAge >= 0 && $domainAge < 30) {
            $score += 20.0;
        }

        if (!empty($intel['is_free'])) {
            $score += 5.0;
        }

        return min(100.0, max(0.0, $score));
    }

    // ------------------------------------------------------------------
    // Data file loaders
    // ------------------------------------------------------------------

    private function fps_loadDisposableDomains(): array
    {
        if ($this->fps_disposableDomains !== null) {
            return $this->fps_disposableDomains;
        }

        $this->fps_disposableDomains = $this->fps_loadDomainFile('disposable_domains.txt');
        return $this->fps_disposableDomains;
    }

    private function fps_loadFreeProviders(): array
    {
        if ($this->fps_freeProviders !== null) {
            return $this->fps_freeProviders;
        }

        $this->fps_freeProviders = $this->fps_loadDomainFile('free_email_providers.txt');
        return $this->fps_freeProviders;
    }

    /**
     * Load a newline-delimited domain list into a hash set (domain => true).
     */
    private function fps_loadDomainFile(string $filename): array
    {
        $path = __DIR__ . '/../../data/' . $filename;
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $set = [];
        foreach ($lines as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $set[$line] = true;
            }
        }

        return $set;
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    private function fps_getCached(string $email): ?array
    {
        try {
            $row = Capsule::table(self::CACHE_TABLE)
                ->where('email', $email)
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_TTL_DAYS . ' days')))
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'has_mx'          => (bool) ($row->mx_valid ?? false),
                'is_disposable'   => (bool) ($row->is_disposable ?? false),
                'is_free'         => (bool) ($row->is_free_provider ?? false),
                'is_role'         => (bool) ($row->is_role_account ?? false),
                'domain_age_days' => (int) ($row->domain_age_days ?? -1),
                'domain'          => $row->domain ?? '',
                'local_part'      => explode('@', $email, 2)[0],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fps_cacheResult(string $email, array $intel): void
    {
        try {
            $hash   = hash('sha256', $email);
            $domain = $intel['domain'] ?? '';

            Capsule::table(self::CACHE_TABLE)
                ->where('email', $email)
                ->delete();

            Capsule::table(self::CACHE_TABLE)->insert([
                'email'            => $email,
                'email_hash'       => $hash,
                'domain'           => $domain,
                'mx_valid'         => (int) ($intel['has_mx'] ?? 0),
                'is_disposable'    => (int) ($intel['is_disposable'] ?? 0),
                'is_role_account'  => (int) ($intel['is_role'] ?? 0),
                'is_free_provider' => (int) ($intel['is_free'] ?? 0),
                'domain_age_days'  => $intel['domain_age_days'] ?? null,
                'raw_data'         => json_encode($intel, JSON_UNESCAPED_SLASHES),
                'cached_at'        => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fps_httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WHMCS-FraudPreventionSuite/2.0',
        ]);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        return (string) $result;
    }

    /**
     * Extract the registrable domain from a potentially subdomain'd host.
     * Simple heuristic: take last two labels (or last three if TLD is a known ccSLD).
     */
    private function fps_getRegistrableDomain(string $domain): string
    {
        $domain = strtolower(trim($domain, '.'));
        $parts = explode('.', $domain);
        $count = count($parts);

        if ($count <= 2) {
            return $domain;
        }

        // Known two-part TLDs
        $ccSlds = ['co.uk', 'com.au', 'com.br', 'co.in', 'co.za', 'co.jp', 'co.kr', 'com.mx', 'com.ar', 'com.cn', 'org.uk', 'net.au'];
        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];

        if (in_array($lastTwo, $ccSlds, true) && $count >= 3) {
            return $parts[$count - 3] . '.' . $lastTwo;
        }

        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }
}

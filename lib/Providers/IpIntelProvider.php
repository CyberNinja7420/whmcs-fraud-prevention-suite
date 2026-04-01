<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * IP intelligence provider.
 *
 * Primary:  ip-api.com (free, no key, 45 req/min)
 * Fallback: ipinfo.io  (optional API key)
 *
 * Results cached in mod_fps_ip_intel for 24 hours.
 */
class IpIntelProvider implements FpsProviderInterface
{
    private const CACHE_TABLE = 'mod_fps_ip_intel';
    private const CACHE_TTL_HOURS = 24;
    private const TIMEOUT = 3;

    /** ISO 3166-1 alpha-2 codes considered high-risk for hosting fraud. */
    private const HIGH_RISK_COUNTRIES = [
        'NG', 'GH', 'CM', 'CI', 'SN', 'CD', 'KE',
        'PK', 'BD', 'VN', 'ID', 'PH',
        'RU', 'UA', 'BY', 'RO', 'BG',
        'VE', 'BR',
    ];

    public function getName(): string
    {
        return 'IP Intelligence';
    }

    public function isEnabled(): bool
    {
        // Always enabled -- ip-api.com requires no key
        return true;
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 1.2;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $ip = trim($context['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $blank;
        }

        try {
            // Check cache first
            $cached = $this->fps_getCached($ip);
            if ($cached !== null) {
                $score = $this->fps_calculateScore($cached, $context);
                return [
                    'score'   => $score,
                    'details' => $cached,
                    'raw'     => $cached,
                ];
            }

            // Query external API
            $intel = $this->fps_queryIpApi($ip);
            if ($intel === null) {
                $intel = $this->fps_queryIpInfo($ip);
            }

            if ($intel === null) {
                return $blank;
            }

            // Cache the result
            $this->fps_cacheResult($ip, $intel);

            $score = $this->fps_calculateScore($intel, $context);
            return [
                'score'   => $score,
                'details' => $intel,
                'raw'     => $intel,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'IpIntel Error',
                $ip,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // API query methods
    // ------------------------------------------------------------------

    /**
     * Query ip-api.com (free tier, 45 req/min, no key).
     */
    private function fps_queryIpApi(string $ip): ?array
    {
        $url = 'http://ip-api.com/json/' . urlencode($ip)
            . '?fields=status,message,country,countryCode,region,regionName,city,lat,lon,isp,org,as,asname,proxy,hosting,mobile,query';

        $body = $this->fps_httpGet($url);
        if ($body === null) {
            return null;
        }

        logModuleCall('fraud_prevention_suite', 'IpIntel ip-api.com', $ip, $body, '', []);

        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'asn'           => $this->fps_extractAsn($data['as'] ?? ''),
            'asn_org'       => $data['asname'] ?? ($data['org'] ?? ''),
            'isp'           => $data['isp'] ?? '',
            'country_code'  => strtoupper($data['countryCode'] ?? ''),
            'region'        => $data['regionName'] ?? ($data['region'] ?? ''),
            'city'          => $data['city'] ?? '',
            'lat'           => (float) ($data['lat'] ?? 0),
            'lng'           => (float) ($data['lon'] ?? 0),
            'is_proxy'      => (bool) ($data['proxy'] ?? false),
            'is_vpn'        => false, // ip-api free does not distinguish VPN from proxy
            'is_tor'        => false, // ip-api free does not flag Tor
            'is_datacenter' => (bool) ($data['hosting'] ?? false),
            'proxy_type'    => ($data['proxy'] ?? false) ? 'proxy' : '',
            'threat_score'  => 0,
            'source'        => 'ip-api.com',
        ];
    }

    /**
     * Fallback: query ipinfo.io (optional token).
     */
    private function fps_queryIpInfo(string $ip): ?array
    {
        $token = $this->fps_getIpInfoToken();
        $url = 'https://ipinfo.io/' . urlencode($ip) . '/json';
        if ($token !== '') {
            $url .= '?token=' . urlencode($token);
        }

        $body = $this->fps_httpGet($url);
        if ($body === null) {
            return null;
        }

        $masked = $token !== '' ? $this->fps_maskKey($token) : '';
        logModuleCall(
            'fraud_prevention_suite',
            'IpIntel ipinfo.io',
            $ip,
            $body,
            '',
            $token !== '' ? [$token] : []
        );

        $data = json_decode($body, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        $loc = explode(',', $data['loc'] ?? '0,0');

        return [
            'asn'           => $this->fps_extractAsn($data['org'] ?? ''),
            'asn_org'       => $data['org'] ?? '',
            'isp'           => $data['org'] ?? '',
            'country_code'  => strtoupper($data['country'] ?? ''),
            'region'        => $data['region'] ?? '',
            'city'          => $data['city'] ?? '',
            'lat'           => (float) ($loc[0] ?? 0),
            'lng'           => (float) ($loc[1] ?? 0),
            'is_proxy'      => false,
            'is_vpn'        => (bool) ($data['privacy']['vpn'] ?? false),
            'is_tor'        => (bool) ($data['privacy']['tor'] ?? false),
            'is_datacenter' => (bool) ($data['privacy']['hosting'] ?? false),
            'proxy_type'    => '',
            'threat_score'  => 0,
            'source'        => 'ipinfo.io',
        ];
    }

    // ------------------------------------------------------------------
    // Scoring
    // ------------------------------------------------------------------

    private function fps_calculateScore(array $intel, array $context): float
    {
        $score = 0.0;

        if (!empty($intel['is_proxy'])) {
            $score += 25.0;
        }
        if (!empty($intel['is_vpn'])) {
            $score += 20.0;
        }
        if (!empty($intel['is_tor'])) {
            $score += 30.0;
        }
        if (!empty($intel['is_datacenter'])) {
            $score += 15.0;
        }

        $ipCountry = strtoupper($intel['country_code'] ?? '');
        if ($ipCountry !== '' && in_array($ipCountry, self::HIGH_RISK_COUNTRIES, true)) {
            $score += 10.0;
        }

        // Country mismatch between IP and client profile
        $clientCountry = strtoupper($context['country'] ?? '');
        if ($clientCountry !== '' && $ipCountry !== '' && $clientCountry !== $ipCountry) {
            $score += 10.0;
        }

        return min(100.0, max(0.0, $score));
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    private function fps_getCached(string $ip): ?array
    {
        try {
            $row = Capsule::table(self::CACHE_TABLE)
                ->where('ip_address', $ip)
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_TTL_HOURS . ' hours')))
                ->first();

            if ($row === null) {
                return null;
            }

            // Rebuild intel array from table columns
            return [
                'asn'           => $row->asn ?? 0,
                'asn_org'       => $row->asn_org ?? '',
                'isp'           => $row->isp ?? '',
                'country_code'  => $row->country_code ?? '',
                'region'        => $row->region ?? '',
                'city'          => $row->city ?? '',
                'lat'           => (float) ($row->latitude ?? 0),
                'lng'           => (float) ($row->longitude ?? 0),
                'is_proxy'      => (bool) ($row->is_proxy ?? false),
                'is_vpn'        => (bool) ($row->is_vpn ?? false),
                'is_tor'        => (bool) ($row->is_tor ?? false),
                'is_datacenter' => (bool) ($row->is_datacenter ?? false),
                'proxy_type'    => $row->proxy_type ?? '',
                'threat_score'  => (float) ($row->threat_score ?? 0),
                'source'        => 'cache',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fps_cacheResult(string $ip, array $intel): void
    {
        try {
            // Delete stale entries for this IP
            Capsule::table(self::CACHE_TABLE)
                ->where('ip_address', $ip)
                ->delete();

            Capsule::table(self::CACHE_TABLE)->insert([
                'ip_address'    => $ip,
                'asn'           => (int) ($intel['asn'] ?? 0),
                'asn_org'       => $intel['asn_org'] ?? '',
                'isp'           => $intel['isp'] ?? '',
                'country_code'  => $intel['country_code'] ?? '',
                'region'        => $intel['region'] ?? '',
                'city'          => $intel['city'] ?? '',
                'latitude'      => $intel['lat'] ?? 0,
                'longitude'     => $intel['lng'] ?? 0,
                'is_proxy'      => (int) ($intel['is_proxy'] ?? 0),
                'is_vpn'        => (int) ($intel['is_vpn'] ?? 0),
                'is_tor'        => (int) ($intel['is_tor'] ?? 0),
                'is_datacenter' => (int) ($intel['is_datacenter'] ?? 0),
                'proxy_type'    => $intel['proxy_type'] ?? '',
                'threat_score'  => $intel['threat_score'] ?? 0,
                'raw_data'      => json_encode($intel, JSON_UNESCAPED_SLASHES),
                'cached_at'     => date('Y-m-d H:i:s'),
                'expires_at'    => date('Y-m-d H:i:s', strtotime('+' . self::CACHE_TTL_HOURS . ' hours')),
            ]);
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal
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
            CURLOPT_MAXREDIRS      => 2,
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

    private function fps_extractAsn(string $raw): string
    {
        // ip-api returns "AS12345 Organization", ipinfo returns "AS12345 Org"
        if (preg_match('/^(AS\d+)/', $raw, $m)) {
            return $m[1];
        }
        return '';
    }

    private function fps_getIpInfoToken(): string
    {
        try {
            $val = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'ipinfo_api_key')
                ->value('value');
            return is_string($val) ? trim($val) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function fps_maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return substr($key, 0, 3) . str_repeat('*', $len - 6) . substr($key, -3);
    }
}

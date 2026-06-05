<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * BIN (Bank Identification Number) / full card-intelligence provider.
 *
 * Resolves the first 6-8 digits of a payment card to the COMPLETE set of
 * card attributes for ALL card types -- including commercial/corporate and
 * datacenter/business cards -- and turns them into fraud signals tuned for a
 * hosting/VPS business (stolen-card VPS provisioning overwhelmingly uses
 * prepaid/virtual and foreign-issued cards).
 *
 * Captured fields: scheme/brand, type (credit/debit/charge/prepaid), level
 * (Classic..Business/Corporate/World), is_prepaid, is_commercial/is_corporate,
 * is_reloadable, issuer bank (name/url/phone), issuer country + currency, and
 * the BIN length actually used (6 or 8).
 *
 * Sources, tried in configurable priority with graceful fallback:
 *   - binlist.net      (free, no key; rate-limited ~5/hr -- spot use)
 *   - HandyAPI         (free key; better limits; scheme/type/tier/issuer)
 *   - Neutrino API     (paid; full is_commercial/is_prepaid/is_reloadable,
 *                       category incl. CORPORATE/BUSINESS, currency, 8-digit)
 *
 * PCI-DSS: only ever handles the BIN (first 6-8 digits). The full PAN is never
 * read, logged, or stored anywhere in this class.
 *
 * Runs only when context['card_first6'] is present (populated PCI-safely from
 * the checkout POST by FpsHookHelpers::fps_extractCardBin()).
 */
class BinLookupProvider implements FpsProviderInterface
{
    private const CACHE_TABLE    = 'mod_fps_bin_cache';
    private const CACHE_TTL_DAYS = 30;
    private const TIMEOUT        = 4;

    public function getName(): string
    {
        return 'BIN / Card Intelligence';
    }

    public function isEnabled(): bool
    {
        return $this->setting('bin_lookup_enabled', '1') === '1';
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 0.9;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $raw = preg_replace('/\D/', '', (string) ($context['card_first6'] ?? ''));
        if ($raw === '' || strlen($raw) < 6) {
            return $blank;
        }
        // Prefer an 8-digit key (modern network BINs); fall back to 6.
        $bin = substr($raw, 0, strlen($raw) >= 8 ? 8 : 6);

        try {
            $data = $this->fps_getCached($bin);
            if ($data === null) {
                $data = $this->fps_lookup($bin);
                if ($data !== null) {
                    $this->fps_cacheResult($bin, $data);
                }
            }
            if ($data === null) {
                return $blank;
            }

            return $this->fps_score($bin, $data, $context);
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'BinLookup Error', $bin, $e->getMessage(), '', []);
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Scoring + derived signals
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $d   Canonical card data
     * @param array<string,mixed> $ctx Check context
     * @return array{score: float, details: array<string,mixed>, raw: array<string,mixed>}
     */
    private function fps_score(string $bin, array $d, array $ctx): array
    {
        $billingCountry = strtoupper(trim((string) ($ctx['country'] ?? '')));
        $ipCountry      = strtoupper(trim((string) ($ctx['ip_country'] ?? '')));
        if ($ipCountry === '') {
            $ipCountry = $this->fps_ipCountry((string) ($ctx['ip'] ?? ''));
        }
        $cardCountry    = strtoupper((string) ($d['country_code'] ?? ''));

        $isPrepaid    = (bool) ($d['is_prepaid'] ?? false);
        $isReloadable = (bool) ($d['is_reloadable'] ?? false);
        $isCommercial = (bool) ($d['is_commercial'] ?? false) || (bool) ($d['is_corporate'] ?? false);

        $billingMismatch = $billingCountry !== '' && $cardCountry !== '' && $billingCountry !== $cardCountry;
        $ipMismatch      = $ipCountry !== '' && $cardCountry !== '' && $ipCountry !== $cardCountry;

        // BIN-testing velocity: distinct BINs seen for this client recently.
        $binVelocity = $this->fps_binVelocity((int) ($ctx['client_id'] ?? 0), $bin);

        $reasons = [];
        $score   = 0.0;

        $wPrepaid    = (float) $this->setting('bin_weight_prepaid', '12');
        $wReloadable = (float) $this->setting('bin_weight_reloadable', '8');
        $wBilling    = (float) $this->setting('bin_weight_country_mismatch', '22');
        $wIp         = (float) $this->setting('bin_weight_ip_mismatch', '12');
        $wVelocity   = (float) $this->setting('bin_weight_velocity', '20');
        $commTrust   = (float) $this->setting('bin_commercial_trust', '10'); // subtracted

        if ($isPrepaid)    { $score += $wPrepaid;    $reasons[] = 'prepaid card'; }
        if ($isReloadable) { $score += $wReloadable; $reasons[] = 'reloadable prepaid'; }
        if ($billingMismatch) { $score += $wBilling; $reasons[] = "issuer country {$cardCountry} != billing {$billingCountry}"; }
        if ($ipMismatch)      { $score += $wIp;      $reasons[] = "issuer country {$cardCountry} != IP {$ipCountry}"; }
        if ($binVelocity >= (int) $this->setting('bin_velocity_threshold', '3')) {
            $score += $wVelocity; $reasons[] = "card testing: {$binVelocity} distinct BINs from client";
        }
        // Commercial/corporate cards are a TRUST signal -- legit business buyers.
        if ($isCommercial && !$isPrepaid && !$billingMismatch) {
            $score -= $commTrust; $reasons[] = 'commercial/corporate card (trust)';
        }

        $details = [
            'bin'              => $bin,
            'bin_length'       => strlen($bin),
            'scheme'           => $d['scheme'] ?? '',
            'brand'            => $d['brand'] ?? '',
            'type'             => $d['type'] ?? '',
            'level'            => $d['level'] ?? '',
            'is_prepaid'       => $isPrepaid,
            'is_commercial'    => (bool) ($d['is_commercial'] ?? false),
            'is_corporate'     => (bool) ($d['is_corporate'] ?? false),
            'is_reloadable'    => $isReloadable,
            'bank_name'        => $d['bank_name'] ?? '',
            'bank_url'         => $d['bank_url'] ?? '',
            'bank_phone'       => $d['bank_phone'] ?? '',
            'card_country'     => $cardCountry,
            'card_currency'    => $d['currency'] ?? '',
            'billing_country'  => $billingCountry,
            'ip_country'       => $ipCountry,
            'country_mismatch' => $billingMismatch,
            'ip_mismatch'      => $ipMismatch,
            'bin_velocity'     => $binVelocity,
            'source'           => $d['source'] ?? '',
            'reasons'          => $reasons,
        ];

        return [
            'score'   => min(100.0, max(0.0, $score)),
            'details' => $details,
            'raw'     => $d,
        ];
    }

    /**
     * Count distinct BINs recorded for this client in the velocity window
     * (card-testing detection). Reads the BIN written into each check's
     * check_context JSON by the runner.
     */
    private function fps_binVelocity(int $clientId, string $currentBin): int
    {
        if ($clientId < 1) {
            return 0;
        }
        try {
            if (!Capsule::schema()->hasTable('mod_fps_checks')) {
                return 0;
            }
            $since = date('Y-m-d H:i:s', strtotime('-' . (int) $this->setting('bin_velocity_window_hours', '24') . ' hours'));
            $rows = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->where('created_at', '>=', $since)
                ->whereNotNull('check_context')
                ->limit(200)
                ->pluck('check_context');
            $bins = [$currentBin => true];
            foreach ($rows as $json) {
                $ctx = json_decode((string) $json, true);
                $b = is_array($ctx) ? (string) ($ctx['card_bin'] ?? '') : '';
                if ($b !== '') {
                    $bins[$b] = true;
                }
            }
            return count($bins);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Multi-source lookup
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null Canonical card data, or null.
     */
    private function fps_lookup(string $bin): ?array
    {
        $pref = strtolower(trim($this->setting('bin_source', 'auto')));
        $order = $pref === 'auto'
            ? ['neutrino', 'handyapi', 'binlist']
            : array_unique(array_merge([$pref], ['neutrino', 'handyapi', 'binlist']));

        foreach ($order as $src) {
            $data = null;
            if ($src === 'neutrino') {
                $data = $this->fps_queryNeutrino($bin);
            } elseif ($src === 'handyapi') {
                $data = $this->fps_queryHandyApi($bin);
            } elseif ($src === 'binlist') {
                $data = $this->fps_queryBinlist($bin);
            }
            if ($data !== null && ($data['scheme'] ?? '') !== '') {
                return $data;
            }
        }
        return null;
    }

    /** binlist.net -- free, no key (scheme/type/brand/prepaid/bank/country). */
    private function fps_queryBinlist(string $bin): ?array
    {
        [$code, $body] = $this->fps_http('GET', 'https://lookup.binlist.net/' . urlencode($bin), ['Accept-Version: 3']);
        if ($code < 200 || $code >= 400) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d)) {
            return null;
        }
        return [
            'scheme'        => (string) ($d['scheme'] ?? ''),
            'brand'         => (string) ($d['brand'] ?? ''),
            'type'          => (string) ($d['type'] ?? ''),
            'level'         => '',
            'is_prepaid'    => (bool) ($d['prepaid'] ?? false),
            'is_commercial' => false,
            'is_corporate'  => false,
            'is_reloadable' => false,
            'bank_name'     => (string) ($d['bank']['name'] ?? ''),
            'bank_url'      => (string) ($d['bank']['url'] ?? ''),
            'bank_phone'    => (string) ($d['bank']['phone'] ?? ''),
            'country_code'  => strtoupper((string) ($d['country']['alpha2'] ?? '')),
            'currency'      => (string) ($d['country']['currency'] ?? ''),
            'source'        => 'binlist.net',
        ];
    }

    /** HandyAPI -- free key (Scheme/Type/CardTier/Issuer/Country). */
    private function fps_queryHandyApi(string $bin): ?array
    {
        $key = trim($this->setting('handyapi_key', ''));
        if ($key === '') {
            return null;
        }
        [$code, $body] = $this->fps_http('GET', 'https://data.handyapi.com/bin/' . urlencode($bin), ['x-api-key: ' . $key]);
        if ($code < 200 || $code >= 400) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || (($d['Status'] ?? '') !== 'SUCCESS' && !isset($d['Scheme']))) {
            return null;
        }
        $tier = strtoupper((string) ($d['CardTier'] ?? ''));
        return [
            'scheme'        => strtolower((string) ($d['Scheme'] ?? '')),
            'brand'         => (string) ($d['CardTier'] ?? ''),
            'type'          => strtolower((string) ($d['Type'] ?? '')),
            'level'         => (string) ($d['CardTier'] ?? ''),
            'is_prepaid'    => stripos((string) ($d['Type'] ?? ''), 'prepaid') !== false,
            'is_commercial' => (strpos($tier, 'BUSINESS') !== false || strpos($tier, 'CORPORATE') !== false || strpos($tier, 'COMMERCIAL') !== false),
            'is_corporate'  => strpos($tier, 'CORPORATE') !== false,
            'is_reloadable' => false,
            'bank_name'     => (string) ($d['Issuer'] ?? ''),
            'bank_url'      => (string) ($d['IssuerUrl'] ?? $d['Bank']['Url'] ?? ''),
            'bank_phone'    => (string) ($d['Bank']['Phone'] ?? ''),
            'country_code'  => strtoupper((string) ($d['Country']['A2'] ?? $d['CountryCode'] ?? '')),
            'currency'      => (string) ($d['Country']['Currency'] ?? ''),
            'source'        => 'handyapi',
        ];
    }

    /** Neutrino API -- paid; richest commercial/prepaid/reloadable + 8-digit. */
    private function fps_queryNeutrino(string $bin): ?array
    {
        $uid = trim($this->setting('neutrino_user_id', ''));
        $key = trim($this->setting('neutrino_api_key', ''));
        if ($uid === '' || $key === '') {
            return null;
        }
        [$code, $body] = $this->fps_http(
            'POST',
            'https://neutrinoapi.net/bin-lookup',
            ['Content-Type: application/x-www-form-urlencoded', 'User-ID: ' . $uid, 'API-Key: ' . $key],
            http_build_query(['bin-number' => $bin])
        );
        if ($code < 200 || $code >= 400) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || !($d['valid'] ?? false)) {
            return null;
        }
        $cat = strtoupper((string) ($d['card-category'] ?? ''));
        return [
            'scheme'        => strtolower((string) ($d['card-brand'] ?? '')),
            'brand'         => (string) ($d['card-category'] ?? ''),
            'type'          => strtolower((string) ($d['card-type'] ?? '')),
            'level'         => (string) ($d['card-category'] ?? ''),
            'is_prepaid'    => (bool) ($d['is-prepaid'] ?? false),
            'is_commercial' => (bool) ($d['is-commercial'] ?? false) || strpos($cat, 'BUSINESS') !== false || strpos($cat, 'CORPORATE') !== false,
            'is_corporate'  => strpos($cat, 'CORPORATE') !== false,
            'is_reloadable' => (bool) ($d['is-reloadable'] ?? false),
            'bank_name'     => (string) ($d['issuer'] ?? ''),
            'bank_url'      => (string) ($d['issuer-website'] ?? ''),
            'bank_phone'    => (string) ($d['issuer-phone'] ?? ''),
            'country_code'  => strtoupper((string) ($d['country-code'] ?? '')),
            'currency'      => (string) ($d['currency-code'] ?? ''),
            'source'        => 'neutrino',
        ];
    }

    // ------------------------------------------------------------------
    // HTTP (curl when available, file_get_contents fallback)
    // ------------------------------------------------------------------

    /**
     * @param array<int,string> $headers
     * @return array{0:int,1:string} [httpCode, body]
     */
    private function fps_http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_USERAGENT      => 'WHMCS-FraudPreventionSuite/5.4',
                    CURLOPT_CUSTOMREQUEST  => $method,
                ];
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = $body;
                }
                curl_setopt_array($ch, $opts);
                $resp = call_user_func('curl_exec', $ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return [$code, is_string($resp) ? $resp : ''];
            }
        }
        // Fallback: stream wrapper (requires allow_url_fopen).
        $opt = ['http' => ['method' => $method, 'timeout' => self::TIMEOUT, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]];
        if ($body !== null) {
            $opt['http']['content'] = $body;
        }
        $resp = @file_get_contents($url, false, stream_context_create($opt));
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return [$code, is_string($resp) ? $resp : ''];
    }

    // ------------------------------------------------------------------
    // Cache + settings
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function fps_getCached(string $bin): ?array
    {
        try {
            $row = Capsule::table(self::CACHE_TABLE)
                ->where('bin', $bin)
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_TTL_DAYS . ' days')))
                ->first();
            if ($row === null) {
                return null;
            }
            $data = json_decode($row->bin_data, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<string,mixed> $data */
    private function fps_cacheResult(string $bin, array $data): void
    {
        try {
            Capsule::table(self::CACHE_TABLE)->where('bin', $bin)->delete();
            Capsule::table(self::CACHE_TABLE)->insert([
                'bin'        => $bin,
                'bin_data'   => json_encode($data, JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /** Best-effort ISO country for an IP from the cached IP-intel table. */
    private function fps_ipCountry(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        try {
            if (!Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                return '';
            }
            $c = Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)->value('country_code');
            return strtoupper((string) ($c ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function setting(string $key, string $default = ''): string
    {
        try {
            $v = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            return $v === null ? $default : (string) $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

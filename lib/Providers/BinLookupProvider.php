<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * BIN (Bank Identification Number) lookup provider.
 *
 * Uses the free binlist.net API to resolve the first 6 digits of a
 * payment card to issuing bank, card type, and country. Detects
 * country mismatches and prepaid cards.
 *
 * Only runs when context['card_first6'] is present.
 */
class BinLookupProvider implements FpsProviderInterface
{
    private const API_URL = 'https://lookup.binlist.net/';
    private const TIMEOUT = 3;
    private const CACHE_TABLE = 'mod_fps_bin_cache';
    private const CACHE_TTL_DAYS = 30; // BIN data changes infrequently

    public function getName(): string
    {
        return 'BIN Lookup';
    }

    public function isEnabled(): bool
    {
        return true; // Free API, no key needed
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

        $bin = trim($context['card_first6'] ?? '');
        if ($bin === '' || !preg_match('/^\d{6,8}$/', $bin)) {
            return $blank;
        }

        // Use only first 6 digits
        $bin = substr($bin, 0, 6);

        try {
            $clientCountry = strtoupper(trim($context['country'] ?? ''));

            // Check cache
            $cached = $this->fps_getCached($bin);
            $binData = $cached;

            if ($binData === null) {
                $binData = $this->fps_queryBinList($bin);
                if ($binData !== null) {
                    $this->fps_cacheResult($bin, $binData);
                }
            }

            if ($binData === null) {
                return $blank;
            }

            $cardCountry     = strtoupper($binData['country_code'] ?? '');
            $isPrepaid       = (bool) ($binData['is_prepaid'] ?? false);
            $countryMismatch = false;

            if ($clientCountry !== '' && $cardCountry !== '' && $clientCountry !== $cardCountry) {
                $countryMismatch = true;
            }

            $details = [
                'bin'              => $bin,
                'scheme'           => $binData['scheme'] ?? '',
                'type'             => $binData['type'] ?? '',
                'brand'            => $binData['brand'] ?? '',
                'bank_name'        => $binData['bank_name'] ?? '',
                'card_country'     => $cardCountry,
                'client_country'   => $clientCountry,
                'is_prepaid'       => $isPrepaid,
                'country_mismatch' => $countryMismatch,
            ];

            $score = 0.0;
            if ($countryMismatch) {
                $score += 25.0;
            }
            if ($isPrepaid) {
                $score += 10.0;
            }

            return [
                'score'   => min(100.0, max(0.0, $score)),
                'details' => $details,
                'raw'     => $binData,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'BinLookup Error',
                $bin,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // API query
    // ------------------------------------------------------------------

    private function fps_queryBinList(string $bin): ?array
    {
        $url = self::API_URL . urlencode($bin);

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
            CURLOPT_HTTPHEADER     => ['Accept-Version: 3'],
            CURLOPT_USERAGENT      => 'WHMCS-FraudPreventionSuite/2.0',
        ]);

        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        logModuleCall('fraud_prevention_suite', 'BinLookup Query', $bin, (string) $result, '', []);

        if ($result === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        $data = json_decode((string) $result, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'scheme'       => $data['scheme'] ?? '',
            'type'         => $data['type'] ?? '',
            'brand'        => $data['brand'] ?? '',
            'is_prepaid'   => (bool) ($data['prepaid'] ?? false),
            'country_code' => strtoupper($data['country']['alpha2'] ?? ''),
            'country_name' => $data['country']['name'] ?? '',
            'bank_name'    => $data['bank']['name'] ?? '',
            'bank_url'     => $data['bank']['url'] ?? '',
            'bank_phone'   => $data['bank']['phone'] ?? '',
        ];
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

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

    private function fps_cacheResult(string $bin, array $data): void
    {
        try {
            Capsule::table(self::CACHE_TABLE)
                ->where('bin', $bin)
                ->delete();

            Capsule::table(self::CACHE_TABLE)->insert([
                'bin'        => $bin,
                'bin_data'   => json_encode($data, JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}

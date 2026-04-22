<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsGeoImpossibilityEngine -- geographic impossibility detection engine.
 *
 * Cross-correlates up to four independent geographic signals --
 * IP-derived country, billing country from the WHMCS client profile,
 * phone-prefix country, and BIN-derived country -- and scores the
 * degree of geographic impossibility based on how many distinct
 * countries appear across those signals.
 *
 * Returns a provider-format result array compatible with FpsRiskEngine.
 */
class FpsGeoImpossibilityEngine
{
    /**
     * ISO 3166-1 alpha-2 codes treated as high-risk for fraud origination.
     *
     * @var list<string>
     */
    private const HIGH_RISK_COUNTRIES = [
        'NG', 'GH', 'CM', 'CI', 'SN', 'CD', 'KE',
        'PK', 'BD', 'VN', 'ID', 'PH',
        'RU', 'UA', 'BY', 'RO', 'BG',
        'VE',
    ];

    /**
     * Map of phone number prefix (without leading '+') to ISO country code.
     *
     * Longer prefixes must be checked before shorter ones to avoid
     * false matches (e.g. +380 before +38, +234 before +2).
     *
     * @var array<string, string>
     */
    private const PHONE_PREFIX_MAP = [
        // 3-digit prefixes first
        '380' => 'UA',
        '234' => 'NG',
        '358' => 'FI',
        '353' => 'IE',
        // 2-digit prefixes
        '44'  => 'GB',
        '49'  => 'DE',
        '33'  => 'FR',
        '91'  => 'IN',
        '55'  => 'BR',
        '86'  => 'CN',
        '81'  => 'JP',
        '61'  => 'AU',
        '27'  => 'ZA',
        '48'  => 'PL',
        '39'  => 'IT',
        '34'  => 'ES',
        '31'  => 'NL',
        '46'  => 'SE',
        '47'  => 'NO',
        '45'  => 'DK',
        // 1-digit prefixes last
        '1'   => 'US',
        '7'   => 'RU',
    ];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Analyse geographic consistency across all available signals.
     *
     * Required data: at least TWO non-empty geo signals from
     * {IP-derived country, billing country, phone-prefix country,
     *  BIN-derived country}. Most fresh installs only have billing +
     * possibly phone for the first few checks per client; IP-derived
     * country comes from the IP intel cache populated by lookup
     * providers, and BIN-derived country from BinLookupProvider.
     *
     * When fewer than 2 signals are available the engine returns
     * score=0 with details="Insufficient geographic data for analysis."
     * (success=true so callers know we ran cleanly without scoring).
     * This is by design -- penalising for missing data would punish
     * legitimate users.
     *
     * @param array{
     *     ip: string,
     *     country: string,
     *     phone: string,
     *     card_first6?: string,
     *     client_id: int|string
     * } $context
     *
     * @return array{
     *     provider: string,
     *     score: float,
     *     details: string,
     *     factors: array<int, string>,
     *     success: bool
     * }
     */
    public function analyze(array $context): array
    {
        $provider = 'geo_impossibility';
        $emptyResult = [
            'provider' => $provider,
            'score'    => 0.0,
            'details'  => 'Insufficient geographic data for analysis (need >=2 of: ip-country, billing, phone, BIN).',
            'factors'  => [],
            'success'  => true,
        ];

        // --- Admin-visible enable gate ---
        //
        // Operators on very-low-volume installs (where most clients have
        // <2 prior geo-located checks) can disable the engine entirely
        // by setting `geo_impossibility_requires_history = '0'` is the
        // permissive setting (engine always runs when signals available).
        // Default is '1' (require history): the engine still runs the
        // signal-count check below; this flag adds a SECOND gate that
        // requires the client to have at least one prior geo-located
        // check on file before the engine contributes a score.
        $requiresHistory = '1';
        try {
            $val = \WHMCS\Database\Capsule::table('mod_fps_settings')
                ->where('setting_key', 'geo_impossibility_requires_history')
                ->value('setting_value');
            if ($val !== null) {
                $requiresHistory = (string) $val;
            }
        } catch (\Throwable $e) {
            // Non-fatal -- fall through to default
        }

        // --- Collect all geographic signals ---
        $signals = $this->fps_collectSignals($context);

        // Filter out empty values, normalise to uppercase
        $available = array_filter(
            $signals,
            static fn(string $v): bool => $v !== ''
        );

        // Safe no-op when we don't have enough independent signals to
        // meaningfully cross-correlate. See class doc above.
        if (count($available) < 2) {
            return $emptyResult;
        }

        // Optional history gate: skip the engine when the client has
        // no prior geo-located checks. Avoids penalising the very first
        // check for a brand-new client on installs that opt in.
        if ($requiresHistory === '1') {
            $clientId = (int) ($context['client_id'] ?? 0);
            if ($clientId > 0) {
                try {
                    $priorGeoCount = (int) \WHMCS\Database\Capsule::table('mod_fps_checks')
                        ->where('client_id', $clientId)
                        ->whereNotNull('country')
                        ->where('country', '!=', '')
                        ->count();
                    if ($priorGeoCount < 1) {
                        return [
                            'provider' => $provider,
                            'score'    => 0.0,
                            'details'  => 'No prior geo-located checks for this client; engine gated by geo_impossibility_requires_history.',
                            'factors'  => [],
                            'success'  => true,
                        ];
                    }
                } catch (\Throwable $e) {
                    // Non-fatal -- fall through and let the engine run
                }
            }
        }

        // --- Base score from unique-country count ---
        $uniqueCountries = array_unique(array_values($available));
        $uniqueCount     = count($uniqueCountries);

        $score   = $this->fps_baseScoreFromUnique($uniqueCount, count($available));
        $factors = $this->fps_buildBaseFactors($signals, $uniqueCountries);

        // --- Modifier: IP in high-risk country, billing in low-risk ---
        $ipCountry      = $signals['ip_country'];
        $billingCountry = $signals['billing_country'];

        if (
            $ipCountry !== ''
            && $billingCountry !== ''
            && in_array($ipCountry, self::HIGH_RISK_COUNTRIES, true)
            && !in_array($billingCountry, self::HIGH_RISK_COUNTRIES, true)
        ) {
            $score    += 15.0;
            $factors[] = sprintf(
                'IP country %s is high-risk while billing country %s is low-risk (+15)',
                $ipCountry,
                $billingCountry
            );
        }

        // --- Modifier: phone country doesn't match any other signal ---
        $phoneCountry = $signals['phone_country'];
        if ($phoneCountry !== '') {
            $othersWithoutPhone = array_filter(
                $available,
                static fn(string $v): bool => $v !== $phoneCountry
            );
            // All other signals present and all differ from phone
            if (count($othersWithoutPhone) === count($available) - 1 && count($othersWithoutPhone) > 0) {
                $score    += 10.0;
                $factors[] = sprintf(
                    'Phone country %s does not match any other geographic signal (+10)',
                    $phoneCountry
                );
            }
        }

        $score = min(100.0, max(0.0, $score));

        $details = $this->fps_buildDetails($signals, $uniqueCountries, $score);

        return [
            'provider' => $provider,
            'score'    => $score,
            'details'  => $details,
            'factors'  => $factors,
            'success'  => true,
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Collect all available geographic signals from context and DB lookups.
     *
     * @param array<string, mixed> $context
     * @return array{
     *     ip_country: string,
     *     billing_country: string,
     *     phone_country: string,
     *     bin_country: string
     * }
     */
    private function fps_collectSignals(array $context): array
    {
        $ip          = trim((string) ($context['ip'] ?? ''));
        $billing     = strtoupper(trim((string) ($context['country'] ?? '')));
        $phone       = trim((string) ($context['phone'] ?? ''));
        $cardFirst6  = trim((string) ($context['card_first6'] ?? ''));

        $ipCountry   = $ip !== '' ? $this->fps_getIpCountry($ip) : '';
        $phoneCountry = $phone !== '' ? $this->fps_extractPhoneCountry($phone) : '';
        $binCountry  = $cardFirst6 !== '' ? $this->fps_getBinCountry($cardFirst6) : '';

        return [
            'ip_country'      => $ipCountry,
            'billing_country' => $billing,
            'phone_country'   => $phoneCountry,
            'bin_country'     => $binCountry,
        ];
    }

    /**
     * Calculate base score from the number of unique countries detected.
     *
     * When fewer than 3 signals are available the analysis is limited
     * and the maximum contribution is capped at 15 points.
     */
    private function fps_baseScoreFromUnique(int $uniqueCount, int $availableSignalCount): float
    {
        $limitedAnalysis = $availableSignalCount < 3;

        if ($uniqueCount <= 1) {
            return 0.0;
        }

        $raw = match ($uniqueCount) {
            2       => 20.0,
            3       => 45.0,
            default => 70.0,
        };

        if ($limitedAnalysis) {
            return min(15.0, $raw);
        }

        return $raw;
    }

    /**
     * Build the human-readable factor strings for the base mismatch count.
     *
     * @param array<string, string> $signals
     * @param list<string>          $uniqueCountries
     * @return list<string>
     */
    private function fps_buildBaseFactors(array $signals, array $uniqueCountries): array
    {
        $factors = [];
        $present = array_filter($signals, static fn(string $v): bool => $v !== '');

        if (count($present) === 0) {
            return $factors;
        }

        $labelMap = [
            'ip_country'      => 'IP',
            'billing_country' => 'Billing',
            'phone_country'   => 'Phone',
            'bin_country'     => 'BIN',
        ];

        $signalDescriptions = [];
        foreach ($signals as $key => $value) {
            if ($value !== '') {
                $label                = $labelMap[$key] ?? $key;
                $signalDescriptions[] = sprintf('%s=%s', $label, $value);
            }
        }

        $factors[] = sprintf(
            'Geographic signals detected: %s',
            implode(', ', $signalDescriptions)
        );

        $uniqueCount = count($uniqueCountries);

        if ($uniqueCount > 1) {
            $factors[] = sprintf(
                '%d unique countr%s found across %d signal%s: %s',
                $uniqueCount,
                $uniqueCount === 1 ? 'y' : 'ies',
                count($present),
                count($present) === 1 ? '' : 's',
                implode(', ', $uniqueCountries)
            );
        }

        return $factors;
    }

    /**
     * Compose a single summary string describing the analysis outcome.
     *
     * @param array<string, string> $signals
     * @param list<string>          $uniqueCountries
     */
    private function fps_buildDetails(array $signals, array $uniqueCountries, float $score): string
    {
        $present = array_filter($signals, static fn(string $v): bool => $v !== '');

        if (count($uniqueCountries) <= 1) {
            return sprintf(
                'All %d geographic signal(s) consistent (%s). Score: %.1f.',
                count($present),
                implode(', ', $uniqueCountries),
                $score
            );
        }

        return sprintf(
            '%d geographic signal(s) across %d distinct countr%s. Score: %.1f.',
            count($present),
            count($uniqueCountries),
            count($uniqueCountries) === 1 ? 'y' : 'ies',
            $score
        );
    }

    /**
     * Derive a 2-letter ISO country code from a phone number string.
     *
     * Accepts formats: +1234567890, 001234567890, 1234567890.
     * Longer prefixes are matched before shorter ones.
     */
    private function fps_extractPhoneCountry(string $phone): string
    {
        // Strip all non-digit characters except a leading +
        $normalised = preg_replace('/[^\d+]/', '', $phone);
        if ($normalised === null || $normalised === '') {
            return '';
        }

        // Normalise leading 00 to +
        if (str_starts_with($normalised, '00')) {
            $normalised = '+' . substr($normalised, 2);
        }

        // Only proceed if the number starts with +
        if (!str_starts_with($normalised, '+')) {
            return '';
        }

        $digits = substr($normalised, 1); // drop the leading '+'

        // Match longest prefix first (prefixes are ordered in the constant)
        foreach (self::PHONE_PREFIX_MAP as $prefix => $countryCode) {
            if (str_starts_with($digits, (string) $prefix)) {
                return $countryCode;
            }
        }

        return '';
    }

    /**
     * Look up a BIN in mod_fps_bin_cache and extract the country code
     * from the cached JSON payload.
     *
     * @param string $bin 6-digit card BIN
     */
    private function fps_getBinCountry(string $bin): string
    {
        if ($bin === '') {
            return '';
        }

        try {
            $row = Capsule::table('mod_fps_bin_cache')
                ->where('bin', $bin)
                ->first();

            if ($row === null) {
                return '';
            }

            $rawData = $row->bin_data ?? '';
            if (!is_string($rawData) || $rawData === '') {
                return '';
            }

            $decoded = json_decode($rawData, true);
            if (!is_array($decoded)) {
                return '';
            }

            $country = $decoded['country'] ?? '';

            return strtoupper(trim((string) $country));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Look up an IP address in mod_fps_ip_intel and return its country code.
     */
    private function fps_getIpCountry(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        try {
            $countryCode = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->value('country_code');

            if (!is_string($countryCode) || $countryCode === '') {
                return '';
            }

            return strtoupper(trim($countryCode));
        } catch (\Throwable $e) {
            return '';
        }
    }
}

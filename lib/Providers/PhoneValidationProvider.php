<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Phone number validation provider -- fully built-in, no external API.
 *
 * Validates international phone number format using regex patterns,
 * extracts the country calling code, and detects country mismatches.
 */
class PhoneValidationProvider implements FpsProviderInterface
{
    /**
     * Map of country calling codes to ISO 3166-1 alpha-2 country codes.
     * Only the most common / unambiguous mappings are included.
     * Shared codes (e.g. +1 = US/CA) map to the most populous country.
     */
    private const CALLING_CODE_TO_COUNTRY = [
        '1'   => 'US', // also CA -- ambiguous
        '7'   => 'RU', // also KZ
        '20'  => 'EG',
        '27'  => 'ZA',
        '30'  => 'GR',
        '31'  => 'NL',
        '32'  => 'BE',
        '33'  => 'FR',
        '34'  => 'ES',
        '36'  => 'HU',
        '39'  => 'IT',
        '40'  => 'RO',
        '41'  => 'CH',
        '43'  => 'AT',
        '44'  => 'GB',
        '45'  => 'DK',
        '46'  => 'SE',
        '47'  => 'NO',
        '48'  => 'PL',
        '49'  => 'DE',
        '51'  => 'PE',
        '52'  => 'MX',
        '53'  => 'CU',
        '54'  => 'AR',
        '55'  => 'BR',
        '56'  => 'CL',
        '57'  => 'CO',
        '58'  => 'VE',
        '60'  => 'MY',
        '61'  => 'AU',
        '62'  => 'ID',
        '63'  => 'PH',
        '64'  => 'NZ',
        '65'  => 'SG',
        '66'  => 'TH',
        '81'  => 'JP',
        '82'  => 'KR',
        '84'  => 'VN',
        '86'  => 'CN',
        '90'  => 'TR',
        '91'  => 'IN',
        '92'  => 'PK',
        '93'  => 'AF',
        '94'  => 'LK',
        '95'  => 'MM',
        '98'  => 'IR',
        '212' => 'MA',
        '213' => 'DZ',
        '216' => 'TN',
        '218' => 'LY',
        '220' => 'GM',
        '221' => 'SN',
        '222' => 'MR',
        '223' => 'ML',
        '224' => 'GN',
        '225' => 'CI',
        '226' => 'BF',
        '227' => 'NE',
        '228' => 'TG',
        '229' => 'BJ',
        '230' => 'MU',
        '231' => 'LR',
        '232' => 'SL',
        '233' => 'GH',
        '234' => 'NG',
        '237' => 'CM',
        '243' => 'CD',
        '244' => 'AO',
        '245' => 'GW',
        '248' => 'SC',
        '249' => 'SD',
        '250' => 'RW',
        '251' => 'ET',
        '252' => 'SO',
        '253' => 'DJ',
        '254' => 'KE',
        '255' => 'TZ',
        '256' => 'UG',
        '260' => 'ZM',
        '261' => 'MG',
        '263' => 'ZW',
        '264' => 'NA',
        '265' => 'MW',
        '266' => 'LS',
        '267' => 'BW',
        '268' => 'SZ',
        '269' => 'KM',
        '351' => 'PT',
        '352' => 'LU',
        '353' => 'IE',
        '354' => 'IS',
        '355' => 'AL',
        '356' => 'MT',
        '357' => 'CY',
        '358' => 'FI',
        '359' => 'BG',
        '370' => 'LT',
        '371' => 'LV',
        '372' => 'EE',
        '373' => 'MD',
        '374' => 'AM',
        '375' => 'BY',
        '376' => 'AD',
        '380' => 'UA',
        '381' => 'RS',
        '382' => 'ME',
        '385' => 'HR',
        '386' => 'SI',
        '387' => 'BA',
        '389' => 'MK',
        '420' => 'CZ',
        '421' => 'SK',
        '852' => 'HK',
        '853' => 'MO',
        '855' => 'KH',
        '856' => 'LA',
        '880' => 'BD',
        '886' => 'TW',
        '960' => 'MV',
        '961' => 'LB',
        '962' => 'JO',
        '963' => 'SY',
        '964' => 'IQ',
        '965' => 'KW',
        '966' => 'SA',
        '967' => 'YE',
        '968' => 'OM',
        '970' => 'PS',
        '971' => 'AE',
        '972' => 'IL',
        '973' => 'BH',
        '974' => 'QA',
        '975' => 'BT',
        '976' => 'MN',
        '977' => 'NP',
        '992' => 'TJ',
        '993' => 'TM',
        '994' => 'AZ',
        '995' => 'GE',
        '996' => 'KG',
        '998' => 'UZ',
    ];

    public function getName(): string
    {
        return 'Phone Validation';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 0.8;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $phone = trim($context['phone'] ?? '');
        if ($phone === '') {
            return $blank;
        }

        try {
            $clientCountry = strtoupper(trim($context['country'] ?? ''));

            // Normalise: strip everything except digits and leading +
            $normalised = $this->fps_normalise($phone);
            $isValid    = $this->fps_validateFormat($normalised);
            $callingCode = $this->fps_extractCallingCode($normalised);
            $phoneCountry = $this->fps_callingCodeToCountry($callingCode);

            $countryMismatch = false;
            if ($clientCountry !== '' && $phoneCountry !== '' && $clientCountry !== $phoneCountry) {
                // Allow US/CA ambiguity for calling code +1
                if (!($callingCode === '1' && in_array($clientCountry, ['US', 'CA'], true))) {
                    $countryMismatch = true;
                }
            }

            $details = [
                'original'         => $phone,
                'normalised'       => $normalised,
                'is_valid_format'  => $isValid,
                'calling_code'     => $callingCode,
                'phone_country'    => $phoneCountry,
                'client_country'   => $clientCountry,
                'country_mismatch' => $countryMismatch,
            ];

            $score = 0.0;
            if (!$isValid) {
                $score += 20.0;
            }
            if ($countryMismatch) {
                $score += 15.0;
            }

            return [
                'score'   => min(100.0, max(0.0, $score)),
                'details' => $details,
                'raw'     => $details,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'PhoneValidation Error',
                $phone,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Normalise phone string: keep leading +, strip all non-digit chars.
     */
    private function fps_normalise(string $phone): string
    {
        $hasPlus = (str_starts_with($phone, '+'));
        $digits  = preg_replace('/[^0-9]/', '', $phone);

        if ($hasPlus) {
            return '+' . $digits;
        }

        return $digits;
    }

    /**
     * Basic international phone number format validation.
     *
     * Accepts: +{1-3 digit country code}{6-14 digits} or domestic formats
     * with 7-15 total digits.
     */
    private function fps_validateFormat(string $normalised): bool
    {
        // International format: + followed by 7-15 digits
        if (str_starts_with($normalised, '+')) {
            $digits = substr($normalised, 1);
            $len = strlen($digits);
            return $len >= 7 && $len <= 15 && ctype_digit($digits);
        }

        // Domestic format: 7-15 digits
        $len = strlen($normalised);
        return $len >= 7 && $len <= 15 && ctype_digit($normalised);
    }

    /**
     * Extract the calling code from a normalised international number.
     * Returns empty string if not determinable.
     */
    private function fps_extractCallingCode(string $normalised): string
    {
        if (!str_starts_with($normalised, '+')) {
            return '';
        }

        $digits = substr($normalised, 1);

        // Try 1-digit, 2-digit, then 3-digit country codes
        for ($len = 1; $len <= 3; $len++) {
            if (strlen($digits) > $len) {
                $code = substr($digits, 0, $len);
                if (isset(self::CALLING_CODE_TO_COUNTRY[$code])) {
                    return $code;
                }
            }
        }

        return '';
    }

    private function fps_callingCodeToCountry(string $callingCode): string
    {
        if ($callingCode === '') {
            return '';
        }
        return self::CALLING_CODE_TO_COUNTRY[$callingCode] ?? '';
    }
}

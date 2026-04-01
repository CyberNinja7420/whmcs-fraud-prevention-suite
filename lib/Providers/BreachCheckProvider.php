<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Have I Been Pwned (HIBP) breach check provider.
 *
 * Uses the HIBP v3 breaches-by-email API when an API key is configured.
 * This is an OPTIONAL premium provider ($3.50/mo for HIBP API key).
 * When no key is configured, the provider gracefully returns score 0.
 *
 * The presence of an email in data breaches is an informational signal,
 * not a definitive fraud indicator -- hence the low weight.
 */
class BreachCheckProvider implements FpsProviderInterface
{
    private const API_URL = 'https://haveibeenpwned.com/api/v3/breachedaccount/';
    private const TIMEOUT = 3;

    public function getName(): string
    {
        return 'Breach Check (HIBP)';
    }

    public function isEnabled(): bool
    {
        return $this->fps_getApiKey() !== '';
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 0.3;
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $apiKey = $this->fps_getApiKey();
        if ($apiKey === '') {
            return $blank;
        }

        $email = strtolower(trim($context['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $blank;
        }

        try {
            $breaches = $this->fps_queryBreaches($email, $apiKey);

            // null means API error -- degrade gracefully
            if ($breaches === null) {
                return $blank;
            }

            $breachCount  = count($breaches);
            $breachNames  = [];
            $dataClasses  = [];
            $latestBreach = '';

            foreach ($breaches as $breach) {
                $breachNames[] = $breach['Name'] ?? 'Unknown';
                foreach (($breach['DataClasses'] ?? []) as $dc) {
                    $dataClasses[$dc] = true;
                }
                $bd = $breach['BreachDate'] ?? '';
                if ($bd > $latestBreach) {
                    $latestBreach = $bd;
                }
            }

            $hasPasswords    = isset($dataClasses['Passwords']);
            $hasCreditCards  = isset($dataClasses['Credit cards']);
            $hasPhoneNumbers = isset($dataClasses['Phone numbers']);

            $details = [
                'breach_count'        => $breachCount,
                'breach_names'        => array_slice($breachNames, 0, 10),
                'latest_breach_date'  => $latestBreach,
                'exposed_passwords'   => $hasPasswords,
                'exposed_credit_cards' => $hasCreditCards,
                'exposed_phones'      => $hasPhoneNumbers,
                'data_classes'        => array_keys($dataClasses),
            ];

            $score = $this->fps_calculateScore($breachCount);

            $maskedKey = $this->fps_maskKey($apiKey);
            logModuleCall(
                'fraud_prevention_suite',
                'BreachCheck Query',
                $email,
                json_encode(['breach_count' => $breachCount]),
                '',
                [$apiKey]
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => $breaches,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'BreachCheck Error',
                $email,
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

    private function fps_queryBreaches(string $email, string $apiKey): ?array
    {
        $url = self::API_URL . urlencode($email) . '?truncateResponse=false';

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'hibp-api-key: ' . $apiKey,
                'User-Agent: WHMCS-FraudPreventionSuite/2.0',
            ],
        ]);

        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 404 = email not found in any breach (good!)
        if ($httpCode === 404) {
            return [];
        }

        // 401 = bad API key, 429 = rate limited
        if ($result === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        $data = json_decode((string) $result, true);
        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------
    // Scoring
    // ------------------------------------------------------------------

    private function fps_calculateScore(int $breachCount): float
    {
        if ($breachCount <= 0) {
            return 0.0;
        }
        if ($breachCount <= 5) {
            return 5.0;
        }
        if ($breachCount <= 20) {
            return 10.0;
        }
        return 15.0;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fps_getApiKey(): string
    {
        try {
            $key = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'hibp_api_key')
                ->value('value');
            return is_string($key) ? trim($key) : '';
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

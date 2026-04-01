<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FraudRecord.com integration provider.
 *
 * Queries the FraudRecord API with MD5-hashed PII (email, IP, phone) and
 * maps the response to a normalised 0-100 risk score.
 */
class FraudRecordProvider implements FpsProviderInterface
{
    private const API_URL = 'https://www.fraudrecord.com/api/';
    private const TIMEOUT = 3;

    public function getName(): string
    {
        return 'FraudRecord';
    }

    public function isEnabled(): bool
    {
        return (bool) $this->fps_getApiKey();
    }

    public function isQuick(): bool
    {
        return false;
    }

    public function getWeight(): float
    {
        return 1.5;
    }

    /**
     * Query FraudRecord for reports matching the client's PII.
     */
    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $apiKey = $this->fps_getApiKey();
        if ($apiKey === '') {
            return $blank;
        }

        $email = $context['email'] ?? '';
        $ip    = $context['ip'] ?? '';
        $phone = $context['phone'] ?? '';

        if ($email === '' && $ip === '' && $phone === '') {
            return $blank;
        }

        try {
            $params = ['_action' => 'query', '_api' => $apiKey];

            if ($email !== '') {
                $params['email'] = md5(strtolower(trim($email)));
            }
            if ($ip !== '') {
                $params['ip'] = md5(trim($ip));
            }
            if ($phone !== '') {
                $params['phone'] = md5(preg_replace('/[^0-9]/', '', $phone));
            }

            $url = self::API_URL . '?' . http_build_query($params);
            $response = $this->fps_httpGet($url);

            if ($response === null) {
                return $blank;
            }

            $maskedKey = $this->fps_maskKey($apiKey);
            logModuleCall(
                'fraud_prevention_suite',
                'FraudRecord Query',
                str_replace($apiKey, $maskedKey, $url),
                $response,
                '',
                [$apiKey]
            );

            $parsed = $this->fps_parseResponse($response);
            $score  = $this->fps_mapScore($parsed);

            return [
                'score'   => $score,
                'details' => [
                    'report_count'   => $parsed['count'] ?? 0,
                    'reliability'    => $parsed['reliability'] ?? 'unknown',
                    'value'          => $parsed['value'] ?? 0,
                    'mapped_score'   => $score,
                ],
                'raw' => $parsed,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FraudRecord Error',
                $e->getMessage(),
                $e->getTraceAsString(),
                '',
                []
            );
            return $blank;
        }
    }

    /**
     * Submit a fraud report to FraudRecord.
     *
     * @param string $email   Client email
     * @param string $ip      Client IP
     * @param string $phone   Client phone
     * @param string $reason  Reason text
     * @param string $reporter Reporter email address
     * @return bool
     */
    public function fps_reportToFraudRecord(
        string $email,
        string $ip,
        string $phone,
        string $reason,
        string $reporter
    ): bool {
        $apiKey = $this->fps_getApiKey();
        if ($apiKey === '') {
            return false;
        }

        try {
            $params = [
                '_action'  => 'report',
                '_api'     => $apiKey,
                '_reporter' => $reporter,
                '_reason'  => $reason,
            ];

            if ($email !== '') {
                $params['email'] = md5(strtolower(trim($email)));
            }
            if ($ip !== '') {
                $params['ip'] = md5(trim($ip));
            }
            if ($phone !== '') {
                $params['phone'] = md5(preg_replace('/[^0-9]/', '', $phone));
            }

            $url = self::API_URL . '?' . http_build_query($params);
            $response = $this->fps_httpGet($url);

            $maskedKey = $this->fps_maskKey($apiKey);
            logModuleCall(
                'fraud_prevention_suite',
                'FraudRecord Report',
                str_replace($apiKey, $maskedKey, $url),
                (string) $response,
                '',
                [$apiKey]
            );

            return $response !== null;
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FraudRecord Report Error',
                $e->getMessage(),
                '',
                '',
                []
            );
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function fps_getApiKey(): string
    {
        try {
            $key = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'fraudrecord_api_key')
                ->value('value');
            return is_string($key) ? trim($key) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

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

    /**
     * Parse FraudRecord XML/text response.
     *
     * Response format: <report><value>X</value><count>Y</count><reliability>Z</reliability></report>
     */
    private function fps_parseResponse(string $body): array
    {
        $parsed = [
            'value'       => 0,
            'count'       => 0,
            'reliability' => 'unknown',
        ];

        if (preg_match('/<value>([^<]*)<\/value>/', $body, $m)) {
            $parsed['value'] = (int) $m[1];
        }
        if (preg_match('/<count>([^<]*)<\/count>/', $body, $m)) {
            $parsed['count'] = (int) $m[1];
        }
        if (preg_match('/<reliability>([^<]*)<\/reliability>/', $body, $m)) {
            $parsed['reliability'] = trim($m[1]);
        }

        return $parsed;
    }

    /**
     * Map FraudRecord's native score (0-10 scale) to 0-100.
     *
     * FraudRecord value meanings:
     *   0     = no reports
     *   1-3   = low risk
     *   4-6   = medium risk
     *   7-9   = high risk
     *   10    = confirmed fraud
     */
    private function fps_mapScore(array $parsed): float
    {
        $value = max(0, min(10, (int) ($parsed['value'] ?? 0)));
        $count = (int) ($parsed['count'] ?? 0);

        if ($value === 0 && $count === 0) {
            return 0.0;
        }

        // Base: linear map 0-10 -> 0-100
        $score = (float) ($value * 10);

        // Boost slightly for multiple independent reports (max +10)
        if ($count > 1) {
            $score += min(10.0, ($count - 1) * 2.0);
        }

        return min(100.0, max(0.0, $score));
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

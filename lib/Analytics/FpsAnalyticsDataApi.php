<?php
/**
 * FpsAnalyticsDataApi -- single-purpose GA4 Data API client for the
 * dashboard "yesterday's count" widget.
 *
 * Uses Service Account JWT (NOT OAuth dance) for unattended access:
 *   1. operator pastes SA JSON into the Settings tab
 *   2. operator enters their GA4 numeric property ID
 *   3. dashboard widget reads ONE number per 6-hour window
 *
 * Graceful degradation: every failure path returns null + logs;
 * the dashboard widget shows '--' when this returns null.
 *
 * Closes Task 17 of docs/plans/2026-04-22-analytics-integration.md.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/FpsAnalyticsConfig.php';

use WHMCS\Database\Capsule;

final class FpsAnalyticsDataApi
{
    private const CACHE_TTL    = 21600;  // 6 hours
    private const HTTP_TIMEOUT = 5;
    private const HTTP_CONNECT = 2;
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const SCOPE        = 'https://www.googleapis.com/auth/analytics.readonly';
    private const DATA_API_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    private function __construct() {}

    /**
     * Fetch yesterday's count of $eventName for the configured GA4 property.
     *
     * @return ?int Yesterday's event count, or null on any failure.
     */
    public static function getYesterdayCount(string $eventName): ?int
    {
        try {
            // 1. Read SA JSON
            $saJson = FpsAnalyticsConfig::get('ga4_service_account_json', '');
            if ($saJson === '') {
                return null;
            }

            // 2. Read property ID
            $propertyId = FpsAnalyticsConfig::get('ga4_property_id', '');
            if ($propertyId === '') {
                logModuleCall(
                    'fraud_prevention_suite',
                    'fps_analytics_property_id_missing',
                    $eventName,
                    'ga4_property_id setting is empty -- widget will display em-dash. Enter the numeric GA4 property ID in Settings -> Analytics & Tracking.'
                );
                return null;
            }

            // 3. Cache check
            $cached = self::fps_readCache($eventName);
            if ($cached !== null) {
                return $cached;
            }

            // 4. Parse SA JSON
            $decoded = json_decode($saJson, true);
            if (!is_array($decoded)
                || empty($decoded['client_email'])
                || empty($decoded['private_key'])
            ) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    $eventName,
                    'service account JSON missing client_email, private_key, or project_id'
                );
                return null;
            }

            $clientEmail = (string) $decoded['client_email'];
            $privateKey  = (string) $decoded['private_key'];

            // 5. Mint JWT
            $jwt = self::fps_mintJwt($clientEmail, $privateKey);
            if ($jwt === null) {
                return null;
            }

            // 6. Exchange JWT for access token
            $token = self::fps_exchangeJwt($jwt);
            if ($token === null) {
                return null;
            }

            // 7. Call Data API
            $count = self::fps_callDataApi($propertyId, $token, $eventName);
            if ($count === null) {
                return null;
            }

            // 8. Cache result
            self::fps_writeCache($eventName, $count);

            return $count;
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsAnalyticsDataApi::ERROR',
                $eventName,
                $e->getMessage()
            );
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Cache helpers
    // -----------------------------------------------------------------------

    private static function fps_readCache(string $eventName): ?int
    {
        try {
            $row = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'analytics_widget_cache_' . $eventName)
                ->value('setting_value');

            if (!is_string($row) || $row === '') {
                return null;
            }

            $d = json_decode($row, true);
            if (!is_array($d) || !isset($d['ts'], $d['count'])) {
                return null;
            }

            $ts    = (int) $d['ts'];
            $count = (int) $d['count'];

            if ((time() - $ts) > self::CACHE_TTL) {
                return null;
            }

            return $count;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function fps_writeCache(string $eventName, int $count): void
    {
        try {
            $payload = json_encode(['ts' => time(), 'count' => $count]);
            if ($payload === false) {
                return;
            }
            Capsule::table('mod_fps_settings')->updateOrInsert(
                ['setting_key'   => 'analytics_widget_cache_' . $eventName],
                ['setting_value' => $payload]
            );
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal -- next call will re-fetch.
        }
    }

    // -----------------------------------------------------------------------
    // JWT minting (RS256)
    // -----------------------------------------------------------------------

    private static function fps_mintJwt(string $clientEmail, string $privateKey): ?string
    {
        try {
            $now = time();
            $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
            $payload = [
                'iss'   => $clientEmail,
                'scope' => self::SCOPE,
                'aud'   => self::TOKEN_URL,
                'exp'   => $now + 3600,
                'iat'   => $now,
            ];

            $headerJson  = json_encode($header);
            $payloadJson = json_encode($payload);
            if ($headerJson === false || $payloadJson === false) {
                return null;
            }

            $segments = self::fps_base64url($headerJson) . '.' . self::fps_base64url($payloadJson);

            $key = @openssl_pkey_get_private($privateKey);
            if ($key === false) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'mintJwt',
                    'openssl_pkey_get_private failed -- malformed PEM in service account JSON'
                );
                return null;
            }

            $sig = '';
            $ok  = openssl_sign($segments, $sig, $key, OPENSSL_ALGO_SHA256);
            if (!$ok) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'mintJwt',
                    'openssl_sign failed'
                );
                return null;
            }

            return $segments . '.' . self::fps_base64url($sig);
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsAnalyticsDataApi::ERROR',
                'mintJwt',
                $e->getMessage()
            );
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // OAuth2 JWT-bearer token exchange
    // -----------------------------------------------------------------------

    private static function fps_exchangeJwt(string $jwt): ?string
    {
        try {
            $ch = curl_init(self::TOKEN_URL);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]),
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_TIMEOUT         => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT  => self::HTTP_CONNECT,
                CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ]);

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if (!is_string($body) || $err !== '' || $status >= 400) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'exchangeJwt',
                    'OAuth2 exchange failed: status=' . $status . ' err=' . $err . ' body=' . (is_string($body) ? substr($body, 0, 500) : '')
                );
                return null;
            }

            $d = json_decode($body, true);
            if (!is_array($d) || empty($d['access_token'])) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'exchangeJwt',
                    'access_token absent from OAuth2 response'
                );
                return null;
            }

            return (string) $d['access_token'];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsAnalyticsDataApi::ERROR',
                'exchangeJwt',
                $e->getMessage()
            );
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // GA4 Data API runReport call
    // -----------------------------------------------------------------------

    private static function fps_callDataApi(string $propertyId, string $token, string $eventName): ?int
    {
        try {
            $url = self::DATA_API_URL . rawurlencode($propertyId) . ':runReport';

            $body = json_encode([
                'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'eventName']],
                'metrics'    => [['name' => 'eventCount']],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName'    => 'eventName',
                        'stringFilter' => ['value' => $eventName, 'matchType' => 'EXACT'],
                    ],
                ],
            ]);
            if ($body === false) {
                return null;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => $body,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_TIMEOUT         => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT  => self::HTTP_CONNECT,
                CURLOPT_HTTPHEADER      => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ]);

            $resp   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if (!is_string($resp) || $err !== '' || $status >= 400) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'callDataApi',
                    'runReport failed: status=' . $status . ' err=' . $err . ' body=' . (is_string($resp) ? substr($resp, 0, 500) : '')
                );
                return null;
            }

            $d = json_decode($resp, true);
            if (!is_array($d)) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsAnalyticsDataApi::ERROR',
                    'callDataApi',
                    'runReport response not JSON'
                );
                return null;
            }

            // Empty rows = event simply did not fire yesterday. That's a
            // valid 0, NOT an error.
            if (!isset($d['rows']) || !is_array($d['rows']) || count($d['rows']) === 0) {
                return 0;
            }

            $row = $d['rows'][0];
            if (!is_array($row)
                || !isset($row['metricValues'])
                || !is_array($row['metricValues'])
                || !isset($row['metricValues'][0]['value'])
            ) {
                return 0;
            }

            return (int) $row['metricValues'][0]['value'];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsAnalyticsDataApi::ERROR',
                'callDataApi',
                $e->getMessage()
            );
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // base64url encoding helper (RFC 7515 section 2)
    // -----------------------------------------------------------------------

    private static function fps_base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

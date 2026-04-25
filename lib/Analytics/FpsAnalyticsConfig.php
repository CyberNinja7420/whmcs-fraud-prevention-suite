<?php
/**
 * FpsAnalyticsConfig -- memoized analytics settings reader + ID validators.
 *
 * Reads all 11 analytics settings from mod_fps_settings with a
 * per-request in-memory cache (static array).  Call clearCache()
 * in unit tests to reset between cases.
 *
 * Follows FPS helper conventions:
 *   - Global namespace (no PSR-4 conversion) so call sites need no
 *     import and existing autoload is unaffected.
 *   - Single final class with static state.
 *   - No if(!class_exists()) guard -- psalm flags conditional
 *     declarations as UndefinedClass at call sites (see commit 9a8e4a7).
 *   - WHMCS guard at file top.
 *
 * Part of the Analytics Integration (v4.2.5).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

final class FpsAnalyticsConfig
{
    // -----------------------------------------------------------------------
    // Setting key constants
    // -----------------------------------------------------------------------

    public const KEY_ENABLE_CLIENT_ANALYTICS    = 'enable_client_analytics';
    public const KEY_ENABLE_ADMIN_ANALYTICS     = 'enable_admin_analytics';
    public const KEY_ENABLE_SERVER_EVENTS       = 'enable_server_events';
    public const KEY_GA4_MEASUREMENT_ID_CLIENT  = 'ga4_measurement_id_client';
    public const KEY_GA4_MEASUREMENT_ID_ADMIN   = 'ga4_measurement_id_admin';
    public const KEY_GA4_API_SECRET             = 'ga4_api_secret';
    public const KEY_GA4_SERVICE_ACCOUNT_JSON   = 'ga4_service_account_json';
    public const KEY_CLARITY_PROJECT_ID_CLIENT  = 'clarity_project_id_client';
    public const KEY_CLARITY_PROJECT_ID_ADMIN   = 'clarity_project_id_admin';
    public const KEY_EEA_CONSENT_REQUIRED       = 'analytics_eea_consent_required';
    public const KEY_EVENT_SAMPLING_RATE        = 'analytics_event_sampling_rate';

    // -----------------------------------------------------------------------
    // Per-request memoization cache
    // -----------------------------------------------------------------------

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    // Private constructor -- static-only helper class.
    private function __construct() {}

    // -----------------------------------------------------------------------
    // Core reader
    // -----------------------------------------------------------------------

    /**
     * Get one analytics setting value from mod_fps_settings.
     *
     * Results are cached for the lifetime of the PHP request.
     *
     * @param string $key     One of the KEY_* constants (or any setting_key).
     * @param string $default Returned when the key is absent or DB throws.
     */
    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $rows = Capsule::table('mod_fps_settings')
                    ->whereIn('setting_key', [
                        self::KEY_ENABLE_CLIENT_ANALYTICS,
                        self::KEY_ENABLE_ADMIN_ANALYTICS,
                        self::KEY_ENABLE_SERVER_EVENTS,
                        self::KEY_GA4_MEASUREMENT_ID_CLIENT,
                        self::KEY_GA4_MEASUREMENT_ID_ADMIN,
                        self::KEY_GA4_API_SECRET,
                        self::KEY_GA4_SERVICE_ACCOUNT_JSON,
                        self::KEY_CLARITY_PROJECT_ID_CLIENT,
                        self::KEY_CLARITY_PROJECT_ID_ADMIN,
                        self::KEY_EEA_CONSENT_REQUIRED,
                        self::KEY_EVENT_SAMPLING_RATE,
                    ])
                    ->get(['setting_key', 'setting_value']);

                foreach ($rows as $row) {
                    self::$cache[(string) $row->setting_key] = (string) $row->setting_value;
                }
            } catch (\Throwable $e) {
                logModuleCall('fraud_prevention_suite', 'FpsAnalyticsConfig::get', $key, $e->getMessage());
            }
        }

        return self::$cache[$key] ?? $default;
    }

    // -----------------------------------------------------------------------
    // Boolean helpers
    // -----------------------------------------------------------------------

    /**
     * Whether client-side analytics scripts should be injected.
     */
    public static function isClientEnabled(): bool
    {
        return self::get(self::KEY_ENABLE_CLIENT_ANALYTICS, '0') === '1';
    }

    /**
     * Whether admin-side analytics scripts should be injected.
     */
    public static function isAdminEnabled(): bool
    {
        return self::get(self::KEY_ENABLE_ADMIN_ANALYTICS, '0') === '1';
    }

    /**
     * Whether server-side Measurement Protocol POSTs are active.
     */
    public static function isServerEnabled(): bool
    {
        return self::get(self::KEY_ENABLE_SERVER_EVENTS, '0') === '1';
    }

    // -----------------------------------------------------------------------
    // ID validators
    // -----------------------------------------------------------------------

    /**
     * Validate a GA4 Measurement ID (format: G-XXXXXXXXXX).
     *
     * Accepts the standard G- prefix followed by 1-20 uppercase
     * alphanumeric characters, matching all current GA4 property IDs.
     */
    public static function isValidGa4Id(string $id): bool
    {
        return (bool) preg_match('/^G-[A-Z0-9]{1,20}$/', $id);
    }

    /**
     * Validate a Microsoft Clarity project ID.
     *
     * Clarity IDs are lowercase alphanumeric strings of 6-12 characters.
     */
    public static function isValidClarityId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9]{6,12}$/', $id);
    }

    /**
     * Validate a GA4 Service Account JSON blob.
     *
     * Checks the minimum structural requirements: valid JSON containing
     * a "private_key" field, and that the private key material is a
     * valid PEM RSA private key (via openssl_pkey_get_private).
     *
     * Returns false on any structural or cryptographic failure.
     */
    public static function isValidServiceAccountJson(string $json): bool
    {
        if (trim($json) === '') {
            return false;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }

        if (!is_array($decoded) || empty($decoded['private_key'])) {
            return false;
        }

        $key = @openssl_pkey_get_private($decoded['private_key']);
        if ($key === false) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Test helper
    // -----------------------------------------------------------------------

    /**
     * Flush the memoization cache.
     *
     * Call this between test cases to ensure a clean read from the DB.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}

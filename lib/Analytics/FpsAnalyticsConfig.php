<?php
/**
 * FpsAnalyticsConfig -- memoized analytics settings reader + ID validators.
 *
 * Reads all 12 analytics settings from mod_fps_settings with a
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
    public const KEY_GA4_PROPERTY_ID            = 'ga4_property_id';
    public const KEY_CLARITY_PROJECT_ID_CLIENT  = 'clarity_project_id_client';
    public const KEY_CLARITY_PROJECT_ID_ADMIN   = 'clarity_project_id_admin';
    public const KEY_EEA_CONSENT_REQUIRED       = 'analytics_eea_consent_required';
    public const KEY_EVENT_SAMPLING_RATE        = 'analytics_event_sampling_rate';
    public const KEY_HIGH_RISK_SIGNUP_THRESHOLD = 'analytics_high_risk_signup_threshold';

    // -----------------------------------------------------------------------
    // All setting key strings as an ordered array (spec requirement)
    // -----------------------------------------------------------------------

    public const KEYS = [
        'enable_client_analytics', 'enable_admin_analytics', 'enable_server_events',
        'ga4_measurement_id_client', 'ga4_measurement_id_admin', 'ga4_api_secret',
        'ga4_service_account_json', 'ga4_property_id',
        'clarity_project_id_client', 'clarity_project_id_admin',
        'analytics_eea_consent_required', 'analytics_event_sampling_rate',
        'analytics_high_risk_signup_threshold',
    ];

    // -----------------------------------------------------------------------
    // Safe defaults -- eagerly loaded before the DB query so the cache
    // always contains all 12 keys regardless of which rows exist in the DB.
    // -----------------------------------------------------------------------

    private const DEFAULTS = [
        'enable_client_analytics'        => '0',
        'enable_admin_analytics'         => '0',
        'enable_server_events'           => '0',
        'ga4_measurement_id_client'      => '',
        'ga4_measurement_id_admin'       => '',
        'ga4_api_secret'                 => '',
        'ga4_service_account_json'       => '',
        'ga4_property_id'                => '',
        'clarity_project_id_client'      => '',
        'clarity_project_id_admin'       => '',
        'analytics_eea_consent_required' => '1',
        'analytics_event_sampling_rate'        => '100',
        'analytics_high_risk_signup_threshold' => '80',
    ];

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
     * Results are cached for the lifetime of the PHP request. The cache
     * is eagerly pre-populated with DEFAULTS so all 11 keys are always
     * present -- DB rows overwrite the defaults; missing rows keep the
     * safe default value.
     *
     * @param string $key     One of the KEY_* constants (or any setting_key).
     * @param string $default Returned when the key is absent or DB throws.
     */
    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;        // eager-init with safe defaults
            try {
                $rows = Capsule::table('mod_fps_settings')
                    ->whereIn('setting_key', self::KEYS)
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
     * Validate a GA4 Measurement ID. Format: G- prefix + 8-12 uppercase
     * alphanumeric chars (covers all current GA4 property IDs). Empty string
     * is accepted (= "not configured" -- treated as not-yet-set, not invalid).
     */
    public static function isValidGa4Id(string $id): bool
    {
        return $id === '' || (bool) preg_match('/^G-[A-Z0-9]{8,12}$/', $id);
    }

    /**
     * Validate a Microsoft Clarity project ID.
     *
     * Clarity IDs are lowercase alphanumeric strings of 8-12 characters.
     * Empty string is accepted (= "not configured").
     */
    public static function isValidClarityId(string $id): bool
    {
        return $id === '' || (bool) preg_match('/^[a-z0-9]{8,12}$/', $id);
    }

    /**
     * Validate a GA4 Service Account JSON blob.
     *
     * Checks the minimum structural requirements: valid JSON containing
     * a "private_key" field, and that the private key material is a
     * valid PEM RSA private key (via openssl_pkey_get_private).
     *
     * Returns false on any structural or cryptographic failure.
     * Empty string is accepted (= "not configured").
     */
    public static function isValidServiceAccountJson(string $json): bool
    {
        if ($json === "") {
            return true;
        }

        $d = json_decode($json, true);
        if (!is_array($d) || empty($d["private_key"]) || empty($d["client_email"])) {
            return false;
        }

        $key = @openssl_pkey_get_private($d["private_key"]);
        if ($key === false) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Test helper
    // -----------------------------------------------------------------------

    /**
     * Flush the memoization cache. Intended ONLY for test isolation;
     * never call mid-request -- a subsequent get() will re-bulk-load
     * but at the cost of an extra round-trip and lost amortisation.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * FpsConfig -- centralized settings reader with singleton access.
 *
 * Reads from two sources:
 *   1. tbladdonmodules (WHMCS native module config fields)
 *   2. mod_fps_settings (custom key-value settings added via admin UI)
 *
 * Both sources are cached after first read within a request lifecycle.
 * Write operations go to mod_fps_settings only (tbladdonmodules is managed by WHMCS core).
 */
class FpsConfig
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    private static ?self $instance = null;

    /** @var array<string, string>|null Cached tbladdonmodules rows */
    private ?array $moduleSettings = null;

    /** @var array<string, string>|null Cached mod_fps_settings rows */
    private ?array $customSettings = null;

    private function __construct()
    {
        // Singleton -- use getInstance()
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Read a WHMCS module config value from tbladdonmodules.
     *
     * These are the fields defined in fraud_prevention_suite_config()['fields'].
     * Examples: fraudrecord_api_key, auto_check_orders, risk_medium_threshold.
     *
     * @param string $key     The setting name (column: setting)
     * @param mixed  $default Fallback if key not found or on error
     * @return mixed
     */
    public function get(string $key, mixed $default = ''): mixed
    {
        try {
            $settings = $this->loadModuleSettings();
            return $settings[$key] ?? $default;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsConfig::get', $key, $e->getMessage());
            return $default;
        }
    }

    /**
     * Read a custom setting from mod_fps_settings.
     *
     * These are runtime/admin-configurable values stored outside the
     * WHMCS module config system (provider toggles, cache TTLs, etc.).
     *
     * @param string $key     The setting_key column value
     * @param mixed  $default Fallback if key not found or on error
     * @return mixed
     */
    public function getCustom(string $key, mixed $default = ''): mixed
    {
        try {
            $settings = $this->loadCustomSettings();
            return $settings[$key] ?? $default;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsConfig::getCustom', $key, $e->getMessage());
            return $default;
        }
    }

    /**
     * Write or update a custom setting in mod_fps_settings.
     *
     * Uses upsert (updateOrInsert) so both new and existing keys work.
     * Invalidates the custom settings cache after write.
     *
     * @param string $key   The setting_key
     * @param string $value The setting_value
     */
    public function set(string $key, string $value): void
    {
        try {
            Capsule::table('mod_fps_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value]
            );
            // Invalidate cache so next read reflects the new value
            $this->customSettings = null;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsConfig::set',
                json_encode(['key' => $key, 'value' => $value]),
                $e->getMessage()
            );
        }
    }

    /**
     * Return all settings merged (custom settings override module settings on conflict).
     *
     * @return array<string, string>
     */
    public function getAll(): array
    {
        try {
            $module = $this->loadModuleSettings();
            $custom = $this->loadCustomSettings();
            return array_merge($module, $custom);
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsConfig::getAll', '', $e->getMessage());
            return [];
        }
    }

    /**
     * Check whether a feature toggle is enabled.
     *
     * Checks custom settings first, then module settings.
     * Truthy values: "1", "yes", "on", "true" (case-insensitive).
     *
     * @param string $feature The feature key (e.g. "auto_check_orders", "provider_fraudrecord")
     */
    public function isEnabled(string $feature): bool
    {
        $value = $this->getCustom($feature, '');
        if ($value === '') {
            $value = $this->get($feature, '');
        }
        return in_array(strtolower((string) $value), ['1', 'yes', 'on', 'true'], true);
    }

    /**
     * Get a float config value with bounds clamping.
     *
     * @param string $key     Setting key
     * @param float  $default Default value
     * @param float  $min     Minimum allowed
     * @param float  $max     Maximum allowed
     */
    public function getFloat(string $key, float $default, float $min = 0.0, float $max = 100.0): float
    {
        $raw = $this->getCustom($key, '');
        if ($raw === '') {
            $raw = $this->get($key, '');
        }
        if ($raw === '') {
            return $default;
        }
        return max($min, min($max, (float) $raw));
    }

    /**
     * Get an integer config value.
     */
    public function getInt(string $key, int $default): int
    {
        $raw = $this->getCustom($key, '');
        if ($raw === '') {
            $raw = $this->get($key, '');
        }
        if ($raw === '') {
            return $default;
        }
        return (int) $raw;
    }

    /**
     * Flush all caches so next read hits the database.
     */
    public function flush(): void
    {
        $this->moduleSettings = null;
        $this->customSettings = null;
    }

    /**
     * Delete a custom setting.
     */
    public function delete(string $key): void
    {
        try {
            Capsule::table('mod_fps_settings')
                ->where('setting_key', $key)
                ->delete();
            $this->customSettings = null;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsConfig::delete', $key, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Private loaders
    // -----------------------------------------------------------------------

    /**
     * Load and cache all rows from tbladdonmodules for this module.
     *
     * @return array<string, string>
     */
    private function loadModuleSettings(): array
    {
        if ($this->moduleSettings !== null) {
            return $this->moduleSettings;
        }

        $rows = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE_NAME)
            ->get(['setting', 'value']);

        $this->moduleSettings = [];
        foreach ($rows as $row) {
            $this->moduleSettings[$row->setting] = $row->value ?? '';
        }
        return $this->moduleSettings;
    }

    /**
     * Load and cache all rows from mod_fps_settings.
     *
     * @return array<string, string>
     */
    private function loadCustomSettings(): array
    {
        if ($this->customSettings !== null) {
            return $this->customSettings;
        }

        if (!Capsule::schema()->hasTable('mod_fps_settings')) {
            $this->customSettings = [];
            return $this->customSettings;
        }

        $rows = Capsule::table('mod_fps_settings')->get(['setting_key', 'setting_value']);

        $this->customSettings = [];
        foreach ($rows as $row) {
            $this->customSettings[$row->setting_key] = $row->setting_value ?? '';
        }
        return $this->customSettings;
    }

    /**
     * Prevent cloning of singleton.
     */
    private function __clone()
    {
    }
}

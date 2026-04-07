<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\FpsConfig;

/**
 * TabSettings -- all module configuration with collapsible provider sections,
 * threshold sliders, CAPTCHA settings, public API toggles, maintenance actions,
 * and a sticky save bar.
 */
class TabSettings
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
        $config  = FpsConfig::getInstance();

        echo '<form id="fps-settings-form" onsubmit="return false;">';

        $this->fpsRenderDisplaySettings($config);
        $this->fpsRenderProviderSettings($config);
        $this->fpsRenderThresholdSettings($config);
        $this->fpsRenderCaptchaSettings($config);
        $this->fpsRenderPublicApiSettings($config);
        $this->fpsRenderMaintenanceSettings($config, $ajaxUrl);

        $this->fpsRenderGatewaySettings($config, $ajaxUrl);
        $this->fpsRenderOfacSettings($config);
        $this->fpsRenderRefundAbuseSettings($config);
        $this->fpsRenderBotCleanupSettings($config);

        echo '</form>';

        $this->fpsRenderSaveBar($ajaxUrl);
    }

    /**
     * Display settings: font size scaling.
     */
    private function fpsRenderDisplaySettings(FpsConfig $config): void
    {
        $rawScale = $config->getCustom('ui_font_scale', '1.0');
        $currentScale = max(0.85, min(1.4, (float)$rawScale));

        $scales = [
            '0.85' => 'Compact (85%)',
            '0.9'  => 'Small (90%)',
            '1.0'  => 'Default (100%)',
            '1.1'  => 'Large (110%)',
            '1.15' => 'Larger (115%)',
            '1.2'  => 'Extra Large (120%)',
            '1.3'  => 'Maximum (130%)',
        ];

        $options = '';
        foreach ($scales as $val => $label) {
            // Compare as floats to avoid "1" vs "1.0" string mismatch
            $selected = (abs($currentScale - (float)$val) < 0.001) ? ' selected' : '';
            $options .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
        }

        // Color defaults
        $colorDefaults = [
            'admin_primary_color'   => '#667eea',
            'admin_secondary_color' => '#764ba2',
            'admin_bg_color'        => '#f4f6fb',
            'admin_surface_color'   => '#ffffff',
            'admin_text_color'      => '#1a1d2e',
            'client_brand_color'    => '#2563eb',
            'client_bg_color'       => '#f8fafc',
            'client_text_color'     => '#334155',
            'client_hero_start'     => '#1e3a5f',
            'client_hero_end'       => '#2d1b4e',
        ];

        $colors = [];
        foreach ($colorDefaults as $key => $default) {
            $val = $config->getCustom($key, $default);
            $colors[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$val) ? $val : $default;
        }

        $darkMode = (int)$config->getCustom('admin_dark_mode', '0');
        $darkChecked = $darkMode ? ' checked' : '';

        // Build color picker HTML helper
        $cp = function($key, $label, $desc) use ($colors) {
            $v = htmlspecialchars($colors[$key]);
            return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">'
                . '<input type="color" name="' . $key . '" value="' . $v . '" '
                . 'style="width:44px;height:36px;border:1px solid var(--fps-border,#dde1ef);border-radius:6px;cursor:pointer;padding:2px;" '
                . 'oninput="this.nextElementSibling.value=this.value;FpsAdmin.previewColor(\'' . $key . '\',this.value)">'
                . '<input type="text" value="' . $v . '" maxlength="7" '
                . 'style="width:90px;font-family:monospace;font-size:13px;padding:6px 8px;border:1px solid var(--fps-border,#dde1ef);border-radius:6px;" '
                . 'oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){this.previousElementSibling.value=this.value;FpsAdmin.previewColor(\'' . $key . '\',this.value)}">'
                . '<div style="flex:1;"><strong style="font-size:13px;">' . $label . '</strong>'
                . '<div style="font-size:11px;color:var(--fps-text-muted,#9499b5);">' . $desc . '</div></div>'
                . '</div>';
        };

        $defaultsJson = htmlspecialchars(json_encode($colorDefaults), ENT_QUOTES);

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group" style="max-width:320px;">
    <label for="fps-setting-ui_font_scale"><i class="fas fa-text-height"></i> <strong>Interface Size</strong></label>
    <select id="fps-setting-ui_font_scale" name="ui_font_scale" class="fps-select"
      onchange="FpsAdmin.changeFontScale(this.value)">
      {$options}
    </select>
    <small style="margin-top:4px;display:block;color:#888;">Adjusts the entire FPS interface size. Takes effect immediately.</small>
  </div>
</div>

<hr style="border:none;border-top:1px solid var(--fps-border-light,#eaedf5);margin:20px 0;">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
  <h4 style="margin:0;font-size:15px;"><i class="fas fa-palette"></i> Admin Panel Colors</h4>
  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
    <input type="checkbox" name="admin_dark_mode" value="1"{$darkChecked}
      onchange="FpsAdmin.toggleDarkMode(this.checked)" style="width:16px;height:16px;">
    <i class="fas fa-moon"></i> Dark Mode
  </label>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 32px;">
  {$cp('admin_primary_color', 'Primary Color', 'Header gradients, buttons, active tabs')}
  {$cp('admin_secondary_color', 'Secondary Color', 'Gradient end color, secondary accents')}
  {$cp('admin_bg_color', 'Page Background', 'Main page background behind cards')}
  {$cp('admin_surface_color', 'Card Background', 'Card and panel surfaces')}
  {$cp('admin_text_color', 'Text Color', 'Primary body text')}
</div>

<hr style="border:none;border-top:1px solid var(--fps-border-light,#eaedf5);margin:20px 0;">

<h4 style="margin:0 0 16px;font-size:15px;"><i class="fas fa-globe"></i> Client Page Colors</h4>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 32px;">
  {$cp('client_brand_color', 'Brand / Accent Color', 'Links, buttons, CTA on public pages')}
  {$cp('client_bg_color', 'Page Background', 'Client area body background')}
  {$cp('client_text_color', 'Text Color', 'Client area body text')}
  {$cp('client_hero_start', 'Hero Gradient Start', 'Hero banner gradient start color')}
  {$cp('client_hero_end', 'Hero Gradient End', 'Hero banner gradient end color')}
</div>

<hr style="border:none;border-top:1px solid var(--fps-border-light,#eaedf5);margin:20px 0;">

<div style="display:flex;gap:12px;">
  <button type="button" class="fps-btn fps-btn-sm fps-btn-outline"
    onclick="FpsAdmin.resetThemeDefaults()" style="font-size:13px;">
    <i class="fas fa-rotate-left"></i> Reset All Colors to Defaults
  </button>
</div>
<input type="hidden" id="fps-color-defaults" value="{$defaultsJson}">
HTML;

        echo FpsAdminRenderer::renderCard('Display Settings', 'fa-display', $content);
    }

    /**
     * Provider settings with collapsible accordion sections.
     */
    private function fpsRenderProviderSettings(FpsConfig $config): void
    {
        $providers = [
            [
                'key'     => 'fraudrecord',
                'title'   => 'FraudRecord',
                'icon'    => 'fa-shield-halved',
                'fields'  => [
                    ['type' => 'text', 'name' => 'fraudrecord_api_key', 'label' => 'API Key', 'source' => 'module'],
                    ['type' => 'text', 'name' => 'reporter_email', 'label' => 'Reporter Email', 'source' => 'module'],
                    ['type' => 'toggle', 'name' => 'provider_fraudrecord', 'label' => 'Enable FraudRecord'],
                ],
            ],
            [
                'key'     => 'ip_intel',
                'title'   => 'IP Intelligence',
                'icon'    => 'fa-network-wired',
                'fields'  => [
                    ['type' => 'info', 'text' => 'ip-api.com is enabled by default and requires no API key.'],
                    ['type' => 'text', 'name' => 'ipinfo_api_key', 'label' => 'ipinfo.io API Key (optional)', 'source' => 'module'],
                    ['type' => 'toggle', 'name' => 'ip_intel_enabled', 'label' => 'Enable IP Intelligence'],
                ],
            ],
            [
                'key'     => 'email_validation',
                'title'   => 'Email Validation',
                'icon'    => 'fa-at',
                'fields'  => [
                    ['type' => 'toggle', 'name' => 'email_validation_enabled', 'label' => 'Enable Email Validation'],
                    ['type' => 'toggle', 'name' => 'disposable_list_auto_update', 'label' => 'Auto-Update Disposable Email List'],
                ],
            ],
            [
                'key'     => 'phone_validation',
                'title'   => 'Phone Validation',
                'icon'    => 'fa-phone',
                'fields'  => [
                    ['type' => 'toggle', 'name' => 'phone_validation_enabled', 'label' => 'Enable Phone Validation'],
                ],
            ],
            [
                'key'     => 'bin_lookup',
                'title'   => 'BIN Lookup',
                'icon'    => 'fa-credit-card',
                'fields'  => [
                    ['type' => 'toggle', 'name' => 'bin_lookup_enabled', 'label' => 'Enable BIN/IIN Lookup'],
                ],
            ],
            [
                'key'     => 'breach_check',
                'title'   => 'Breach Check (HIBP)',
                'icon'    => 'fa-unlock',
                'fields'  => [
                    ['type' => 'text', 'name' => 'hibp_api_key', 'label' => 'HIBP API Key (optional, $3.50/mo)', 'source' => 'module'],
                    ['type' => 'toggle', 'name' => 'breach_check_enabled', 'label' => 'Enable Breach Checking'],
                ],
            ],
            [
                'key'     => 'social_presence',
                'title'   => 'Social Presence',
                'icon'    => 'fa-share-nodes',
                'fields'  => [
                    ['type' => 'info', 'text' => 'Social presence checking is slow and disabled by default. Enable only if needed.'],
                    ['type' => 'toggle', 'name' => 'social_presence_enabled', 'label' => 'Enable Social Presence Check'],
                ],
            ],
            [
                'key'     => 'fingerprinting',
                'title'   => 'Device Fingerprinting',
                'icon'    => 'fa-fingerprint',
                'fields'  => [
                    ['type' => 'toggle', 'name' => 'fingerprint_enabled', 'label' => 'Enable Fingerprinting'],
                    ['type' => 'select', 'name' => 'fingerprint_scope', 'label' => 'Collection Scope',
                     'options' => ['checkout' => 'Checkout Only', 'all' => 'All Pages']],
                ],
            ],
        ];

        $content = '<div class="fps-accordion" id="fps-provider-accordion">';

        foreach ($providers as $provider) {
            $pKey   = htmlspecialchars($provider['key'], ENT_QUOTES, 'UTF-8');
            $pTitle = htmlspecialchars($provider['title'], ENT_QUOTES, 'UTF-8');

            $content .= '<div class="fps-accordion-item">';
            $content .= '<div class="fps-accordion-header" onclick="FpsAdmin.toggleAccordion(\'' . $pKey . '\')">';
            $content .= '  <h4><i class="fas ' . $provider['icon'] . '"></i> ' . $pTitle . '</h4>';
            $content .= '  <i class="fas fa-chevron-down fps-accordion-arrow" id="fps-arrow-' . $pKey . '"></i>';
            $content .= '</div>';
            $content .= '<div class="fps-accordion-body fps-collapsed" id="fps-accordion-' . $pKey . '">';

            foreach ($provider['fields'] as $field) {
                $content .= $this->fpsRenderSettingField($field, $config);
            }

            $content .= '</div>';
            $content .= '</div>';
        }

        $content .= '</div>';

        echo FpsAdminRenderer::renderCard('Provider Settings', 'fa-puzzle-piece', $content);
    }

    /**
     * Threshold settings with range sliders.
     */
    private function fpsRenderThresholdSettings(FpsConfig $config): void
    {
        $thresholds = [
            ['name' => 'pre_checkout_block_threshold', 'label' => 'Pre-Checkout Block Threshold', 'default' => 85, 'source' => 'module'],
            ['name' => 'risk_medium_threshold',        'label' => 'Medium Risk Threshold',        'default' => 30, 'source' => 'module'],
            ['name' => 'risk_high_threshold',          'label' => 'High Risk Threshold',          'default' => 60, 'source' => 'module'],
            ['name' => 'risk_critical_threshold',      'label' => 'Critical Risk Threshold',      'default' => 80, 'source' => 'module'],
        ];

        $content = '';

        foreach ($thresholds as $th) {
            $safeName  = htmlspecialchars($th['name'], ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($th['label'], ENT_QUOTES, 'UTF-8');

            if (($th['source'] ?? '') === 'module') {
                $value = (int)$config->get($th['name'], (string)$th['default']);
            } else {
                $value = (int)$config->getCustom($th['name'], (string)$th['default']);
            }
            $value = max(0, min(100, $value));

            $content .= '<div class="fps-form-group fps-slider-group">';
            $content .= '  <label for="fps-setting-' . $safeName . '">' . $safeLabel . '</label>';
            $content .= '  <div class="fps-slider-row">';
            $content .= '    <input type="range" id="fps-setting-' . $safeName . '" name="' . $safeName . '" '
                . 'class="fps-range" min="0" max="100" value="' . $value . '" '
                . 'oninput="FpsAdmin.updateSliderValue(\'' . $safeName . '\', this.value)">';
            $content .= '    <span class="fps-slider-value" id="fps-slider-val-' . $safeName . '">' . $value . '</span>';
            $content .= '  </div>';
            $content .= '</div>';
        }

        // Toggle options
        $autoLockChecked    = $config->isEnabled('auto_lock_critical');
        $preCheckoutChecked = $config->isEnabled('pre_checkout_blocking');

        $content .= '<div class="fps-form-row" style="margin-top:16px;">';
        $content .= '  <div class="fps-form-group">';
        $content .= '    <label>Auto-Lock Critical Orders</label>';
        $content .= '    ' . FpsAdminRenderer::renderToggle('auto_lock_critical', $autoLockChecked);
        $content .= '  </div>';
        $content .= '  <div class="fps-form-group">';
        $content .= '    <label>Pre-Checkout Blocking</label>';
        $content .= '    ' . FpsAdminRenderer::renderToggle('pre_checkout_blocking', $preCheckoutChecked);
        $content .= '  </div>';
        $content .= '</div>';

        echo FpsAdminRenderer::renderCard('Risk Thresholds', 'fa-sliders', $content);
    }

    /**
     * CAPTCHA settings card.
     */
    private function fpsRenderCaptchaSettings(FpsConfig $config): void
    {
        $captchaProvider = htmlspecialchars($config->getCustom('captcha_provider', 'hcaptcha'), ENT_QUOTES, 'UTF-8');
        $captchaEnabled  = $config->isEnabled('captcha_enabled');
        $siteKey         = htmlspecialchars($config->getCustom('captcha_site_key', ''), ENT_QUOTES, 'UTF-8');
        $secretKey       = htmlspecialchars($config->getCustom('captcha_secret_key', ''), ENT_QUOTES, 'UTF-8');

        $hSelected = $captchaProvider === 'hcaptcha' ? ' selected' : '';
        $rSelected = $captchaProvider === 'recaptcha_v3' ? ' selected' : '';

        $toggleHtml = FpsAdminRenderer::renderToggle('captcha_enabled', $captchaEnabled);

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group">
    <label for="fps-setting-captcha_provider"><i class="fas fa-robot"></i> CAPTCHA Provider</label>
    <select id="fps-setting-captcha_provider" name="captcha_provider" class="fps-select">
      <option value="hcaptcha"{$hSelected}>hCaptcha</option>
      <option value="recaptcha_v3"{$rSelected}>reCAPTCHA v3</option>
    </select>
  </div>
  <div class="fps-form-group">
    <label>Enable CAPTCHA</label>
    {$toggleHtml}
  </div>
</div>
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-setting-captcha_site_key"><i class="fas fa-globe"></i> Site Key</label>
    <input type="text" id="fps-setting-captcha_site_key" name="captcha_site_key" class="fps-input"
      value="{$siteKey}" placeholder="Enter site key">
  </div>
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-setting-captcha_secret_key"><i class="fas fa-lock"></i> Secret Key</label>
    <input type="password" id="fps-setting-captcha_secret_key" name="captcha_secret_key" class="fps-input"
      value="{$secretKey}" placeholder="Enter secret key">
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('CAPTCHA Settings', 'fa-robot', $content);
    }

    /**
     * Public API settings card.
     */
    private function fpsRenderPublicApiSettings(FpsConfig $config): void
    {
        $apiEnabled     = $config->isEnabled('public_api_enabled');
        $topoEnabled    = $config->isEnabled('topology_enabled');

        $apiToggle  = FpsAdminRenderer::renderToggle('public_api_enabled', $apiEnabled);
        $topoToggle = FpsAdminRenderer::renderToggle('topology_enabled', $topoEnabled);

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group">
    <label>Enable Public API</label>
    {$apiToggle}
  </div>
  <div class="fps-form-group">
    <label>Enable Topology Page</label>
    {$topoToggle}
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Public API Settings', 'fa-satellite-dish', $content);

        // Rate limit configuration per tier
        $tiers = [
            'anonymous' => ['label' => 'Anonymous (no API key)', 'def_min' => 5, 'def_day' => 100, 'icon' => 'fa-eye-slash'],
            'free'      => ['label' => 'Free Tier', 'def_min' => 30, 'def_day' => 5000, 'icon' => 'fa-tag'],
            'basic'     => ['label' => 'Basic Tier', 'def_min' => 120, 'def_day' => 50000, 'icon' => 'fa-bolt'],
            'premium'   => ['label' => 'Premium Tier', 'def_min' => 600, 'def_day' => 500000, 'icon' => 'fa-crown'],
        ];

        $rateContent = '<p class="fps-text-muted" style="margin:0 0 1rem;font-size:0.85rem;">'
            . '<i class="fas fa-info-circle"></i> Configure API rate limits per tier. Per-key overrides can be set in the API Keys tab. '
            . 'Changes take effect immediately for new requests.</p>';
        $rateContent .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">';

        foreach ($tiers as $tierKey => $t) {
            $minVal = htmlspecialchars($config->getCustom('rate_limit_' . $tierKey . '_minute', (string)$t['def_min']), ENT_QUOTES, 'UTF-8');
            $dayVal = htmlspecialchars($config->getCustom('rate_limit_' . $tierKey . '_day', (string)$t['def_day']), ENT_QUOTES, 'UTF-8');

            $rateContent .= '<div class="fps-card" style="padding:1rem;">';
            $rateContent .= '<h4 style="margin:0 0 0.75rem;font-size:0.9rem;"><i class="fas ' . $t['icon'] . '"></i> ' . $t['label'] . '</h4>';
            $rateContent .= '<div class="fps-form-group" style="margin-bottom:0.5rem;">';
            $rateContent .= '  <label style="font-size:0.8rem;">Requests / Minute</label>';
            $rateContent .= '  <input type="number" name="rate_limit_' . $tierKey . '_minute" class="fps-input" value="' . $minVal . '" min="1" max="10000">';
            $rateContent .= '</div>';
            $rateContent .= '<div class="fps-form-group">';
            $rateContent .= '  <label style="font-size:0.8rem;">Requests / Day</label>';
            $rateContent .= '  <input type="number" name="rate_limit_' . $tierKey . '_day" class="fps-input" value="' . $dayVal . '" min="1" max="10000000">';
            $rateContent .= '</div>';
            $rateContent .= '</div>';
        }
        $rateContent .= '</div>';

        echo FpsAdminRenderer::renderCard('API Rate Limits', 'fa-tachometer-alt', $rateContent);
    }

    /**
     * Maintenance settings card with cache TTLs, retention, and purge buttons.
     */
    private function fpsRenderMaintenanceSettings(FpsConfig $config, string $ajaxUrl): void
    {
        $cacheTtlIp       = htmlspecialchars($config->getCustom('cache_ttl_ip', '86400'), ENT_QUOTES, 'UTF-8');
        $cacheTtlEmail    = htmlspecialchars($config->getCustom('cache_ttl_email', '604800'), ENT_QUOTES, 'UTF-8');
        $geoRetention     = htmlspecialchars($config->getCustom('geo_events_retention_days', '90'), ENT_QUOTES, 'UTF-8');
        $apiLogsRetention = htmlspecialchars($config->getCustom('api_logs_retention_days', '30'), ENT_QUOTES, 'UTF-8');

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group">
    <label for="fps-setting-cache_ttl_ip"><i class="fas fa-clock"></i> IP Cache TTL (seconds)</label>
    <input type="number" id="fps-setting-cache_ttl_ip" name="cache_ttl_ip" class="fps-input"
      value="{$cacheTtlIp}" min="0" max="2592000">
  </div>
  <div class="fps-form-group">
    <label for="fps-setting-cache_ttl_email"><i class="fas fa-clock"></i> Email Cache TTL (seconds)</label>
    <input type="number" id="fps-setting-cache_ttl_email" name="cache_ttl_email" class="fps-input"
      value="{$cacheTtlEmail}" min="0" max="2592000">
  </div>
</div>
<div class="fps-form-row">
  <div class="fps-form-group">
    <label for="fps-setting-geo_events_retention_days"><i class="fas fa-database"></i> Geo Events Retention (days)</label>
    <input type="number" id="fps-setting-geo_events_retention_days" name="geo_events_retention_days" class="fps-input"
      value="{$geoRetention}" min="1" max="365">
  </div>
  <div class="fps-form-group">
    <label for="fps-setting-api_logs_retention_days"><i class="fas fa-database"></i> API Logs Retention (days)</label>
    <input type="number" id="fps-setting-api_logs_retention_days" name="api_logs_retention_days" class="fps-input"
      value="{$apiLogsRetention}" min="1" max="365">
  </div>
</div>
<div class="fps-form-row" style="margin-top:16px;">
  <button type="button" class="fps-btn fps-btn-sm fps-btn-warning"
    onclick="FpsAdmin.purgeAllCaches('{$ajaxUrl}')">
    <i class="fas fa-broom"></i> Purge All Caches Now
  </button>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-danger"
    onclick="FpsAdmin.resetStatistics('{$ajaxUrl}')">
    <i class="fas fa-trash-alt"></i> Reset Statistics
  </button>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Maintenance', 'fa-wrench', $content);
    }

    /**
     * Sticky save bar at the bottom of settings.
     */
    private function fpsRenderSaveBar(string $ajaxUrl): void
    {
        echo '<div class="fps-save-bar">';
        echo '  <div class="fps-save-bar-content">';
        echo '    <span class="fps-save-status" id="fps-save-status"></span>';
        echo '    <button type="button" class="fps-btn fps-btn-md fps-btn-success" '
            . 'onclick="FpsAdmin.saveSettings(\'' . $ajaxUrl . '\')">';
        echo '      <i class="fas fa-save"></i> Save All Settings';
        echo '    </button>';
        echo '  </div>';
        echo '</div>';
    }

    /**
     * Per-payment-gateway fraud control settings.
     * Only Lara Fraud Control offers this among competitors.
     */
    private function fpsRenderGatewaySettings(FpsConfig $config, string $ajaxUrl): void
    {
        // Get available payment gateways from WHMCS
        $gateways = [];
        try {
            $gateways = Capsule::table('tblpaymentgateways')
                ->where('setting', 'name')
                ->pluck('value', 'gateway')
                ->toArray();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Get existing thresholds
        $thresholds = [];
        try {
            $rows = Capsule::table('mod_fps_gateway_thresholds')->get()->toArray();
            foreach ($rows as $row) {
                $thresholds[$row->gateway] = $row;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        $content = '<p class="fps-text-muted" style="margin-bottom:16px;">';
        $content .= '<i class="fas fa-info-circle"></i> Set different fraud thresholds per payment gateway. ';
        $content .= 'Stricter thresholds for high-risk gateways (crypto), lenient for trusted gateways (PayPal).</p>';

        if (empty($gateways)) {
            $content .= '<p class="fps-text-muted">No payment gateways found in WHMCS.</p>';
        } else {
            $content .= '<table class="fps-table fps-table-striped" id="fps-gateway-table">';
            $content .= '<thead><tr>';
            $content .= '<th>Gateway</th><th>Block Threshold</th><th>Flag Threshold</th><th>Require CAPTCHA</th><th>Enabled</th>';
            $content .= '</tr></thead><tbody>';

            foreach ($gateways as $gwKey => $gwName) {
                $safeKey = htmlspecialchars($gwKey, ENT_QUOTES, 'UTF-8');
                $safeName = htmlspecialchars($gwName, ENT_QUOTES, 'UTF-8');
                $existing = $thresholds[$gwKey] ?? null;
                $blockVal = $existing ? (int)$existing->block_threshold : 85;
                $flagVal  = $existing ? (int)$existing->flag_threshold : 60;
                $captcha  = $existing ? (int)$existing->require_captcha : 0;
                $enabled  = $existing ? (int)$existing->enabled : 1;

                $content .= '<tr>';
                $content .= '<td><strong>' . $safeName . '</strong><br><small class="fps-text-muted">' . $safeKey . '</small></td>';
                $content .= '<td><input type="number" name="gw_block_' . $safeKey . '" class="fps-input fps-input-sm" value="' . $blockVal . '" min="0" max="100" style="width:80px;"></td>';
                $content .= '<td><input type="number" name="gw_flag_' . $safeKey . '" class="fps-input fps-input-sm" value="' . $flagVal . '" min="0" max="100" style="width:80px;"></td>';
                $content .= '<td>' . FpsAdminRenderer::renderToggle('gw_captcha_' . $safeKey, (bool)$captcha) . '</td>';
                $content .= '<td>' . FpsAdminRenderer::renderToggle('gw_enabled_' . $safeKey, (bool)$enabled) . '</td>';
                $content .= '</tr>';
            }

            $content .= '</tbody></table>';
        }

        echo FpsAdminRenderer::renderCard('Per-Gateway Fraud Control', 'fa-credit-card', $content);
    }

    /**
     * OFAC Sanctions screening settings.
     */
    private function fpsRenderOfacSettings(FpsConfig $config): void
    {
        $ofacEnabled = $config->isEnabled('ofac_screening_enabled');
        $sdnFile = __DIR__ . '/../../data/sdn.csv';
        $sdnStatus = file_exists($sdnFile)
            ? 'Last updated: ' . date('Y-m-d H:i', filemtime($sdnFile)) . ' (' . number_format(filesize($sdnFile)) . ' bytes)'
            : 'Not downloaded yet (will download on next cron run)';

        $toggleHtml = FpsAdminRenderer::renderToggle('ofac_screening_enabled', $ofacEnabled);

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group">
    <label>Enable OFAC SDN Screening</label>
    {$toggleHtml}
  </div>
  <div class="fps-form-group">
    <label><i class="fas fa-database"></i> SDN List Status</label>
    <p class="fps-text-muted">{$sdnStatus}</p>
  </div>
</div>
<div class="fps-form-info">
  <i class="fas fa-info-circle"></i> Checks client names against the US Treasury OFAC Specially Designated Nationals list. Auto-updates daily via cron.
</div>
HTML;

        echo FpsAdminRenderer::renderCard('OFAC Sanctions Screening', 'fa-landmark', $content);
    }

    /**
     * Refund abuse detection settings.
     */
    private function fpsRenderRefundAbuseSettings(FpsConfig $config): void
    {
        $threshold = htmlspecialchars($config->getCustom('refund_abuse_threshold', '3'), ENT_QUOTES, 'UTF-8');
        $windowDays = htmlspecialchars($config->getCustom('refund_abuse_window_days', '90'), ENT_QUOTES, 'UTF-8');
        $cbEnabled = $config->isEnabled('chargeback_tracking_enabled');
        $cbToggle = FpsAdminRenderer::renderToggle('chargeback_tracking_enabled', $cbEnabled);

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group">
    <label for="fps-setting-refund_abuse_threshold"><i class="fas fa-undo"></i> Refund Abuse Threshold</label>
    <input type="number" id="fps-setting-refund_abuse_threshold" name="refund_abuse_threshold" class="fps-input"
      value="{$threshold}" min="1" max="50">
    <small class="fps-text-muted">Flag clients with this many refunds in the window period</small>
  </div>
  <div class="fps-form-group">
    <label for="fps-setting-refund_abuse_window_days"><i class="fas fa-calendar"></i> Window Period (days)</label>
    <input type="number" id="fps-setting-refund_abuse_window_days" name="refund_abuse_window_days" class="fps-input"
      value="{$windowDays}" min="7" max="365">
  </div>
</div>
<div class="fps-form-row">
  <div class="fps-form-group">
    <label>Enable Chargeback Tracking</label>
    {$cbToggle}
    <small class="fps-text-muted">Track chargebacks and correlate with original fraud scores</small>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Refund Abuse & Chargeback Tracking', 'fa-money-bill-transfer', $content);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Render a single setting field based on type definition.
     */
    private function fpsRenderSettingField(array $field, FpsConfig $config): string
    {
        $html = '';

        switch ($field['type']) {
            case 'text':
                $name  = htmlspecialchars($field['name'], ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8');

                if (($field['source'] ?? '') === 'module') {
                    $value = $config->get($field['name'], '');
                } else {
                    $value = $config->getCustom($field['name'], '');
                }
                $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

                $html .= '<div class="fps-form-group">';
                $html .= '  <label for="fps-setting-' . $name . '">' . $label . '</label>';
                $html .= '  <input type="text" id="fps-setting-' . $name . '" name="' . $name . '" '
                    . 'class="fps-input" value="' . $safeValue . '">';
                $html .= '</div>';
                break;

            case 'toggle':
                $name    = $field['name'];
                $label   = htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8');
                $enabled = $config->isEnabled($name);

                $html .= '<div class="fps-form-group fps-form-toggle-row">';
                $html .= '  <label>' . $label . '</label>';
                $html .= '  ' . FpsAdminRenderer::renderToggle($name, $enabled);
                $html .= '</div>';
                break;

            case 'select':
                $name    = htmlspecialchars($field['name'], ENT_QUOTES, 'UTF-8');
                $label   = htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8');
                $current = $config->getCustom($field['name'], '');

                $html .= '<div class="fps-form-group">';
                $html .= '  <label for="fps-setting-' . $name . '">' . $label . '</label>';
                $html .= '  <select id="fps-setting-' . $name . '" name="' . $name . '" class="fps-select">';
                foreach ($field['options'] as $optVal => $optLabel) {
                    $sel = ((string)$current === (string)$optVal) ? ' selected' : '';
                    $html .= '    <option value="' . htmlspecialchars((string)$optVal, ENT_QUOTES, 'UTF-8') . '"'
                        . $sel . '>' . htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                $html .= '  </select>';
                $html .= '</div>';
                break;

            case 'info':
                $html .= '<div class="fps-form-info">';
                $html .= '  <i class="fas fa-info-circle"></i> ' . htmlspecialchars($field['text'], ENT_QUOTES, 'UTF-8');
                $html .= '</div>';
                break;
        }

        return $html;
    }

    /**
     * Bot cleanup and user purge settings.
     */
    private function fpsRenderBotCleanupSettings(FpsConfig $config): void
    {
        $userPurgeEnabled = $config->getCustom('user_purge_on_users_page', '1') === '1' ? 'checked' : '';

        $content = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:1;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
      <input type="checkbox" name="user_purge_on_users_page" value="1" {$userPurgeEnabled}>
      <strong>Show User Purge Controls on WHMCS Users Page</strong>
    </label>
    <p style="font-size:0.85rem;color:#888;margin:4px 0 0 28px;">
      When enabled, a "FPS Bot Detection" toolbar appears at the top of
      <strong>Admin > Users > Manage Users</strong> (<code>/admin/user/list</code>).
      Staff can scan for orphan/bot user accounts and purge them directly from the
      WHMCS interface without navigating to the FPS module.
    </p>
  </div>
</div>
<div class="fps-form-row" style="margin-top:12px;">
  <div class="fps-form-group" style="flex:1;">
    <div style="padding:12px;border-radius:8px;background:rgba(100,149,237,0.06);border:1px solid rgba(100,149,237,0.15);font-size:0.85rem;">
      <strong><i class="fas fa-info-circle"></i> How it works:</strong>
      Bot users are identified the same way as bot clients -- by checking for real financial activity.
      A user is considered a bot if <strong>none</strong> of their linked client accounts have paid invoices
      or active hosting services. Purging a user:
      <ul style="margin:8px 0 0;padding-left:20px;">
        <li>Harvests their email + IP into the Global Intel database (if enabled)</li>
        <li>Removes their login from <code>tblusers</code> so they cannot sign in again</li>
        <li>Cleans up <code>tbluserclients</code> link records</li>
        <li>Does NOT delete the associated client records (use Bot Cleanup tab for that)</li>
      </ul>
    </div>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Bot & User Cleanup', 'fa-user-slash', $content);
    }
}

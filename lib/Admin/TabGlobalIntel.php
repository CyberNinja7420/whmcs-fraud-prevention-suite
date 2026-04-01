<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * TabGlobalIntel -- Global Fraud Intelligence admin tab.
 *
 * Provides:
 * - Connection status and hub registration
 * - Sharing settings (opt-in, IP toggle, retention)
 * - Local intel browser with search/filter/pagination
 * - Statistics dashboard
 * - Privacy controls (export, purge, GDPR)
 */
class TabGlobalIntel
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->renderConnectionCard($ajaxUrl);
        $this->renderSettingsCard($ajaxUrl);
        $this->renderStatsCard($ajaxUrl);
        $this->renderBrowserCard($ajaxUrl);
        $this->renderPrivacyCard($ajaxUrl);
    }

    /**
     * Connection status and hub registration.
     */
    private function renderConnectionCard(string $ajaxUrl): void
    {
        $config = $this->getGlobalConfig();
        $enabled = ($config['global_sharing_enabled'] ?? '0') === '1';
        $hubUrl = $config['hub_url'] ?? '';
        $instanceId = $config['instance_id'] ?? 'Not set';
        $apiKey = $config['instance_api_key'] ?? '';
        $domain = $config['instance_domain'] ?? '';
        $lastPush = $config['last_push_at'] ?? 'Never';
        $lastPull = $config['last_pull_at'] ?? 'Never';
        $consentAccepted = ($config['data_consent_accepted'] ?? '0') === '1'
            ? '<span class="fps-badge fps-badge-low">Accepted</span>'
            : '<span class="fps-badge fps-badge-high">Not Accepted</span>';

        $statusLabel = $enabled ? 'Disable Sharing' : 'Enable Sharing';

        $statusBadge = $enabled
            ? '<span class="fps-badge fps-badge-low">Connected</span>'
            : '<span class="fps-badge fps-badge-medium">Disabled</span>';

        $registeredBadge = !empty($apiKey)
            ? '<span class="fps-badge fps-badge-low">Registered</span>'
            : '<span class="fps-badge fps-badge-high">Not Registered</span>';

        echo <<<HTML
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-satellite-dish"></i> Hub Connection</h3>
    {$statusBadge} {$registeredBadge}
  </div>
  <div class="fps-card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <div>
        <table class="fps-table" style="margin:0;">
          <tr><td style="opacity:0.7;">Instance ID</td><td><code style="font-size:0.85rem;">{$instanceId}</code></td></tr>
          <tr><td style="opacity:0.7;">Domain</td><td>{$domain}</td></tr>
          <tr><td style="opacity:0.7;">Hub URL</td><td>{$hubUrl}</td></tr>
          <tr><td style="opacity:0.7;">API Key</td><td>{$apiKey}</td></tr>
        </table>
      </div>
      <div>
        <table class="fps-table" style="margin:0;">
          <tr><td style="opacity:0.7;">Last Push</td><td>{$lastPush}</td></tr>
          <tr><td style="opacity:0.7;">Last Pull</td><td>{$lastPull}</td></tr>
          <tr><td style="opacity:0.7;">Sharing</td><td>{$statusBadge}</td></tr>
          <tr><td style="opacity:0.7;">Consent</td><td>{$consentAccepted}</td></tr>
        </table>
      </div>
    </div>
    <div style="margin-top:1rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
      <button class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsGlobal.register()" id="fps-global-register-btn">
        <i class="fas fa-plug"></i> Register with Hub
      </button>
      <button class="fps-btn fps-btn-sm" style="background:#38ef7d;color:#000;" onclick="FpsGlobal.toggle()" id="fps-global-toggle-btn">
        <i class="fas fa-power-off"></i> {$statusLabel}
      </button>
      <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsGlobal.pushNow()">
        <i class="fas fa-upload"></i> Push Now
      </button>
      <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsGlobal.refreshStats()">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>

    <!-- Consent notice -->
    <div style="margin-top:1rem;padding:0.75rem;border-radius:8px;background:rgba(100,149,237,0.08);border:1px solid rgba(100,149,237,0.2);font-size:0.85rem;">
      <strong><i class="fas fa-shield-halved"></i> Privacy Notice:</strong>
      When sharing is enabled, only <strong>SHA-256 hashed emails</strong>, IP addresses (optional), country codes,
      risk scores, and boolean evidence flags are shared. Raw emails, names, phone numbers, billing data,
      and WHMCS identifiers <strong>never leave your instance</strong>. You can disable sharing and purge all
      shared data at any time (GDPR Article 17).
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Sharing settings card.
     */
    private function renderSettingsCard(string $ajaxUrl): void
    {
        $config = $this->getGlobalConfig();
        $shareIp = ($config['share_ip_addresses'] ?? '1') === '1' ? 'checked' : '';
        $autoPush = ($config['auto_push_enabled'] ?? '1') === '1' ? 'checked' : '';
        $autoPull = ($config['auto_pull_on_signup'] ?? '1') === '1' ? 'checked' : '';
        $retention = $config['intel_retention_days'] ?? '365';
        $hubUrl = $config['hub_url'] ?? '';

        echo <<<HTML
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-sliders"></i> Sharing Settings</h3>
  </div>
  <div class="fps-card-body">
    <div class="fps-form-row" style="gap:1.5rem;">
      <div class="fps-form-group">
        <label for="fps-global-hub-url"><i class="fas fa-link"></i> Hub URL</label>
        <input type="url" id="fps-global-hub-url" class="fps-input" value="{$hubUrl}" placeholder="https://your-hub-server.com">
      </div>
      <div class="fps-form-group">
        <label for="fps-global-retention"><i class="fas fa-clock"></i> Retention (days)</label>
        <input type="number" id="fps-global-retention" class="fps-input" value="{$retention}" min="30" max="3650">
      </div>
    </div>
    <div class="fps-form-row" style="gap:1.5rem;margin-top:1rem;">
      <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
        <input type="checkbox" id="fps-global-share-ip" {$shareIp}> Share IP Addresses
      </label>
      <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
        <input type="checkbox" id="fps-global-auto-push" {$autoPush}> Auto-Push (daily cron)
      </label>
      <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
        <input type="checkbox" id="fps-global-auto-pull" {$autoPull}> Check Hub on New Signups
      </label>
    </div>
    <div style="margin-top:1rem;">
      <button class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsGlobal.saveSettings()">
        <i class="fas fa-save"></i> Save Settings
      </button>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Statistics card.
     */
    private function renderStatsCard(string $ajaxUrl): void
    {
        echo <<<'HTML'
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-chart-bar"></i> Intelligence Statistics</h3>
  </div>
  <div class="fps-card-body" id="fps-global-stats-body">
    <p style="opacity:0.6;"><i class="fas fa-spinner fa-spin"></i> Loading statistics...</p>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof FpsGlobal !== 'undefined') FpsGlobal.refreshStats();
});
</script>
HTML;
    }

    /**
     * Intel browser with search/filter/pagination.
     */
    private function renderBrowserCard(string $ajaxUrl): void
    {
        echo <<<'HTML'
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-database"></i> Local Intelligence Browser</h3>
  </div>
  <div class="fps-card-body">
    <div class="fps-form-row" style="gap:0.5rem;margin-bottom:1rem;align-items:flex-end;">
      <div class="fps-form-group" style="flex:2;">
        <label><i class="fas fa-search"></i> Search (hash, IP, country)</label>
        <input type="text" id="fps-global-search" class="fps-input" placeholder="Search...">
      </div>
      <div class="fps-form-group">
        <label><i class="fas fa-filter"></i> Risk Level</label>
        <select id="fps-global-level-filter" class="fps-select">
          <option value="">All</option>
          <option value="critical">Critical</option>
          <option value="high">High</option>
          <option value="medium">Medium</option>
          <option value="low">Low</option>
        </select>
      </div>
      <div class="fps-form-group">
        <label><i class="fas fa-database"></i> Source</label>
        <select id="fps-global-source-filter" class="fps-select">
          <option value="">All</option>
          <option value="local">Local</option>
          <option value="hub">Hub</option>
          <option value="manual">Manual</option>
        </select>
      </div>
      <div class="fps-form-group">
        <button class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsGlobal.browse(1)">
          <i class="fas fa-search"></i> Search
        </button>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="fps-table" id="fps-global-intel-table">
        <thead>
          <tr>
            <th>Email Hash</th>
            <th>IP</th>
            <th>Country</th>
            <th>Score</th>
            <th>Level</th>
            <th>Source</th>
            <th>Seen</th>
            <th>Last Seen</th>
            <th>Pushed</th>
          </tr>
        </thead>
        <tbody id="fps-global-intel-tbody">
          <tr><td colspan="9" style="text-align:center;opacity:0.5;">Click Search to load records</td></tr>
        </tbody>
      </table>
    </div>
    <div id="fps-global-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:0.5rem;">
      <span id="fps-global-page-info" style="font-size:0.85rem;opacity:0.7;"></span>
      <div style="display:flex;gap:0.25rem;">
        <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsGlobal.prevPage()" id="fps-global-prev" disabled>Prev</button>
        <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsGlobal.nextPage()" id="fps-global-next" disabled>Next</button>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Privacy controls card (GDPR).
     */
    private function renderPrivacyCard(string $ajaxUrl): void
    {
        echo <<<'HTML'
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-user-shield"></i> Privacy & Compliance</h3>
  </div>
  <div class="fps-card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
      <div style="padding:1rem;border-radius:8px;background:rgba(56,239,125,0.06);border:1px solid rgba(56,239,125,0.15);">
        <h4 style="margin:0 0 .5rem;font-size:0.95rem;"><i class="fas fa-download"></i> Export Data</h4>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;">Download all local intel as JSON (GDPR Art. 20).</p>
        <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsGlobal.exportAll()">
          <i class="fas fa-file-export"></i> Export JSON
        </button>
      </div>
      <div style="padding:1rem;border-radius:8px;background:rgba(245,87,108,0.06);border:1px solid rgba(245,87,108,0.15);">
        <h4 style="margin:0 0 .5rem;font-size:0.95rem;"><i class="fas fa-trash-can"></i> Purge Local Intel</h4>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;">Delete ALL local intelligence records (GDPR Art. 17).</p>
        <button class="fps-btn fps-btn-sm" style="background:#f5576c;" onclick="FpsGlobal.purgeLocal()">
          <i class="fas fa-trash"></i> Purge All Local
        </button>
      </div>
      <div style="padding:1rem;border-radius:8px;background:rgba(235,51,73,0.06);border:1px solid rgba(235,51,73,0.15);">
        <h4 style="margin:0 0 .5rem;font-size:0.95rem;"><i class="fas fa-eraser"></i> Purge Hub Contributions</h4>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;">Remove all data this instance pushed to the hub.</p>
        <button class="fps-btn fps-btn-sm" style="background:#eb3349;" onclick="FpsGlobal.purgeHub()">
          <i class="fas fa-cloud-arrow-down"></i> Purge Hub Data
        </button>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Get all global config values.
     */
    private function getGlobalConfig(): array
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_global_config')) {
                return [];
            }
            return Capsule::table('mod_fps_global_config')
                ->pluck('setting_value', 'setting_key')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}

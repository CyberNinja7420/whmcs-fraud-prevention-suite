<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * TabTrustManagement -- allowlist/blacklist management for client AND device trust status.
 *
 * Shows ALL clients with their trust status (Normal by default).
 * Admins can search, filter, and change trust levels per client.
 * Also shows device fingerprint trust management below the client section.
 */
class TabTrustManagement
{
    public function render(array $vars, string $modulelink): void
    {
        $this->fpsRenderStatsRow();
        $this->fpsRenderManageForm($modulelink);
        $this->fpsRenderTrustList($modulelink);

        // Device Trust section
        $this->fpsRenderDeviceTrust($modulelink);
    }

    /**
     * Render 4 stat cards: Trusted, Blacklisted, Suspended, Total Clients.
     */
    private function fpsRenderStatsRow(): void
    {
        $counts = [
            'trusted'  => 0,
            'blacklisted' => 0,
            'suspended' => 0,
        ];

        try {
            $rows = Capsule::table('mod_fps_client_trust')
                ->selectRaw('status, COUNT(*) as cnt')
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                if (isset($counts[$row->status])) {
                    $counts[$row->status] = (int)$row->cnt;
                }
            }
        } catch (\Throwable $e) {}

        $totalClients = 0;
        try {
            $totalClients = Capsule::table('tblclients')->count();
        } catch (\Throwable $e) {}

        echo '<div class="fps-stats-grid">';
        echo FpsAdminRenderer::renderStatCard('Trusted Clients',    $counts['trusted'],     'fa-shield-check',    'success');
        echo FpsAdminRenderer::renderStatCard('Blacklisted Clients', $counts['blacklisted'], 'fa-ban',             'danger');
        echo FpsAdminRenderer::renderStatCard('Suspended Clients',  $counts['suspended'],   'fa-user-lock',       'warning');
        echo FpsAdminRenderer::renderStatCard('Total Clients',      $totalClients,          'fa-users',           'info');
        echo '</div>';
    }

    /**
     * Render the Manage Client Trust Status form card.
     */
    private function fpsRenderManageForm(string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $formHtml = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-trust-client-id"><i class="fas fa-user"></i> Client ID</label>
    <input type="number" id="fps-trust-client-id" class="fps-input" placeholder="Enter Client ID" min="1">
  </div>
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-trust-status"><i class="fas fa-tag"></i> Status</label>
    <select id="fps-trust-status" class="fps-select">
      <option value="trusted">Trusted</option>
      <option value="normal" selected>Normal</option>
      <option value="blacklisted">Blacklisted</option>
      <option value="suspended">Suspended</option>
    </select>
  </div>
</div>
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-trust-reason"><i class="fas fa-comment-alt"></i> Reason</label>
    <textarea id="fps-trust-reason" class="fps-input" rows="2" placeholder="Reason for this trust status change..."></textarea>
  </div>
</div>
<div class="fps-form-row">
  <div class="fps-form-group fps-form-actions">
    <button type="button" class="fps-btn fps-btn-md fps-btn-primary" onclick="FpsAdmin.setClientTrust('{$ajaxUrl}')">
      <i class="fas fa-save"></i> Update Trust Status
    </button>
  </div>
</div>
<div id="fps-trust-result" style="display:none;"></div>
HTML;

        echo FpsAdminRenderer::renderCard('Manage Client Trust Status', 'fa-user-shield', $formHtml);
    }

    /**
     * Render the Client Trust List card with filter buttons, search, and AJAX-loaded table.
     */
    private function fpsRenderTrustList(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $tableHtml = <<<HTML
<div class="fps-form-row" style="align-items:flex-end;margin-bottom:16px;gap:8px;flex-wrap:wrap;">
  <div class="fps-filter-btn-group" style="display:flex;gap:4px;">
    <button type="button" class="fps-btn fps-btn-sm fps-btn-outline fps-filter-active" onclick="FpsAdmin.loadTrustList({$jsAjaxUrl}, '')">
      <i class="fas fa-list"></i> All Clients
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-success" onclick="FpsAdmin.loadTrustList({$jsAjaxUrl}, 'trusted')">
      <i class="fas fa-shield-check"></i> Trusted
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-danger" onclick="FpsAdmin.loadTrustList({$jsAjaxUrl}, 'blacklisted')">
      <i class="fas fa-ban"></i> Blacklisted
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-warning" onclick="FpsAdmin.loadTrustList({$jsAjaxUrl}, 'suspended')">
      <i class="fas fa-user-lock"></i> Suspended
    </button>
  </div>
  <div class="fps-form-group" style="flex:1;min-width:200px;margin:0;">
    <input type="text" id="fps-trust-search" class="fps-input" placeholder="Search by name, email, company, or ID..."
      onkeydown="if(event.key==='Enter'){FpsAdmin.searchTrustList({$jsAjaxUrl});}">
  </div>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsAdmin.searchTrustList({$jsAjaxUrl})">
    <i class="fas fa-search"></i> Search
  </button>
</div>
<div id="fps-trust-list-container">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:96%"></div>
    <div class="fps-skeleton-line" style="width:91%"></div>
    <div class="fps-skeleton-line" style="width:94%"></div>
    <div class="fps-skeleton-line" style="width:88%"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    FpsAdmin.loadTrustList({$jsAjaxUrl}, '');
});
</script>
HTML;

        echo FpsAdminRenderer::renderCard('Client Trust List', 'fa-list-check', $tableHtml);
    }

    // -----------------------------------------------------------------------
    // Device Trust section
    // -----------------------------------------------------------------------

    /**
     * Render the full Device Trust management section.
     *
     * Shows device trust stats, search/filter, table of devices with
     * trust status, and controls to trust/block/watch devices.
     */
    private function fpsRenderDeviceTrust(string $modulelink): void
    {
        // Section header
        echo '<div style="margin-top:2rem;margin-bottom:1rem;padding-bottom:0.5rem;border-bottom:2px solid rgba(102,126,234,0.2);">';
        echo '<h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--fps-text-primary,#1a1d2e);display:flex;align-items:center;gap:0.5rem;">';
        echo '<i class="fas fa-fingerprint" style="color:#667eea;"></i> Device Trust Management';
        echo '</h3>';
        echo '<p style="margin:0.35rem 0 0 0;font-size:0.82rem;color:var(--fps-text-secondary,#5a6176);">';
        echo 'Manage trust status for device fingerprints independently of client accounts. Block known-bad devices or trust verified workstations.';
        echo '</p>';
        echo '</div>';

        $this->fpsRenderDeviceStatsRow();
        $this->fpsRenderDeviceList($modulelink);
    }

    /**
     * Render device trust stat cards.
     */
    private function fpsRenderDeviceStatsRow(): void
    {
        $stats = [
            'trusted' => 0,
            'blocked' => 0,
            'watched' => 0,
            'total'   => 0,
        ];

        try {
            if (Capsule::schema()->hasTable('mod_fps_device_trust')) {
                $rows = Capsule::table('mod_fps_device_trust')
                    ->selectRaw('status, COUNT(*) as cnt')
                    ->groupBy('status')
                    ->get();

                foreach ($rows as $row) {
                    $s = $row->status ?? 'normal';
                    if (isset($stats[$s])) {
                        $stats[$s] = (int) $row->cnt;
                    }
                }
                $stats['total'] = (int) Capsule::table('mod_fps_device_trust')->count();
            }
        } catch (\Throwable $e) {}

        echo '<div class="fps-stats-grid">';
        echo FpsAdminRenderer::renderStatCard('Trusted Devices',  $stats['trusted'], 'fa-shield-check', 'success');
        echo FpsAdminRenderer::renderStatCard('Blocked Devices',  $stats['blocked'], 'fa-ban',          'danger');
        echo FpsAdminRenderer::renderStatCard('Watched Devices',  $stats['watched'], 'fa-eye',          'warning');
        echo FpsAdminRenderer::renderStatCard('Total Tracked',    $stats['total'],   'fa-fingerprint',  'info');
        echo '</div>';
    }

    /**
     * Render the device list with search, filter buttons, and AJAX-loaded table.
     */
    private function fpsRenderDeviceList(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $content = <<<HTML
<div class="fps-form-row" style="align-items:flex-end;margin-bottom:16px;gap:8px;flex-wrap:wrap;">
  <div class="fps-filter-btn-group" style="display:flex;gap:4px;">
    <button type="button" class="fps-btn fps-btn-sm fps-btn-outline fps-filter-active" onclick="FpsAdmin.loadDeviceList({$jsAjaxUrl}, '')">
      <i class="fas fa-list"></i> All Devices
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-success" onclick="FpsAdmin.loadDeviceList({$jsAjaxUrl}, 'trusted')">
      <i class="fas fa-shield-check"></i> Trusted
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-danger" onclick="FpsAdmin.loadDeviceList({$jsAjaxUrl}, 'blocked')">
      <i class="fas fa-ban"></i> Blocked
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-warning" onclick="FpsAdmin.loadDeviceList({$jsAjaxUrl}, 'watched')">
      <i class="fas fa-eye"></i> Watched
    </button>
  </div>
  <div class="fps-form-group" style="flex:1;min-width:200px;margin:0;">
    <input type="text" id="fps-device-search" class="fps-input" placeholder="Search by hash, label, or client email..."
      onkeydown="if(event.key==='Enter'){FpsAdmin.searchDeviceList({$jsAjaxUrl});}">
  </div>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsAdmin.searchDeviceList({$jsAjaxUrl})">
    <i class="fas fa-search"></i> Search
  </button>
</div>
<div id="fps-device-list-container">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:96%"></div>
    <div class="fps-skeleton-line" style="width:91%"></div>
    <div class="fps-skeleton-line" style="width:94%"></div>
    <div class="fps-skeleton-line" style="width:88%"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    FpsAdmin.loadDeviceList({$jsAjaxUrl}, '');
});
</script>
HTML;

        echo FpsAdminRenderer::renderCard('Device Trust List', 'fa-fingerprint', $content);
    }
}

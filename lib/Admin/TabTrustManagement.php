<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * TabTrustManagement -- allowlist/blacklist management for client trust status.
 *
 * Stats are queried directly from mod_fps_client_trust grouped by status.
 * The trust list table is loaded via AJAX. Trust status updates submit via AJAX.
 */
class TabTrustManagement
{
    public function render(array $vars, string $modulelink): void
    {
        $this->fpsRenderStatsRow();
        $this->fpsRenderManageForm($modulelink);
        $this->fpsRenderTrustList($modulelink);
    }

    /**
     * Render 4 stat cards: Trusted, Blacklisted, Suspended, Pending Review.
     */
    private function fpsRenderStatsRow(): void
    {
        $counts = [
            'trusted'  => 0,
            'blacklisted' => 0,
            'suspended' => 0,
            'normal'   => 0,
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
        } catch (\Throwable $e) {
            // Silently zero-fill; table may not exist yet
        }

        echo '<div class="fps-stats-grid">';
        echo FpsAdminRenderer::renderStatCard('Trusted Clients',    $counts['trusted'],     'fa-shield-check',    'success');
        echo FpsAdminRenderer::renderStatCard('Blacklisted Clients', $counts['blacklisted'], 'fa-ban',             'danger');
        echo FpsAdminRenderer::renderStatCard('Suspended Clients',  $counts['suspended'],   'fa-user-lock',       'warning');
        echo FpsAdminRenderer::renderStatCard('Pending Review',     $counts['normal'],      'fa-clock',           'info');
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
    <textarea id="fps-trust-reason" class="fps-input" rows="3" placeholder="Enter reason for this trust status change..."></textarea>
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
     * Render the Client Trust List card with filter buttons and AJAX-loaded table.
     */
    private function fpsRenderTrustList(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $tableHtml = <<<HTML
<div class="fps-filter-btn-group" style="margin-bottom:16px;">
  <button type="button" class="fps-btn fps-btn-sm fps-btn-outline fps-filter-active" onclick="FpsAdmin.loadTrustList({$jsAjaxUrl}, '')">
    <i class="fas fa-list"></i> All
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
}

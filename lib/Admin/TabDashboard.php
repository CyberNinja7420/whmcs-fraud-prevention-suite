<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabDashboard -- main dashboard with 8 animated stat cards, manual check form,
 * and recent checks table.
 *
 * Stats load via AJAX call to get_dashboard_stats on page load.
 * Recent checks load via AJAX call to get_recent_checks.
 */
class TabDashboard
{
    public function render(array $vars, string $modulelink): void
    {
        $this->fpsRenderStatsRow($modulelink);
        $this->fpsRenderManualCheckForm($modulelink);
        $this->fpsRenderRecentChecks($modulelink);
    }

    /**
     * Render 2 rows of 4 stat cards each (8 total) with skeleton loaders.
     */
    private function fpsRenderStatsRow(string $modulelink): void
    {
        $stats = [
            ['id' => 'checks_today',       'label' => 'Checks Today',        'icon' => 'fa-search',           'gradient' => 'primary'],
            ['id' => 'blocked_today',       'label' => 'Blocked Today',       'icon' => 'fa-ban',              'gradient' => 'danger'],
            ['id' => 'pre_checkout_blocks', 'label' => 'Pre-Checkout Blocks', 'icon' => 'fa-shield-halved',    'gradient' => 'warning'],
            ['id' => 'active_threats',      'label' => 'Active Threats',      'icon' => 'fa-skull-crossbones', 'gradient' => 'danger'],
            ['id' => 'review_queue',        'label' => 'Review Queue',        'icon' => 'fa-clipboard-check',  'gradient' => 'info'],
            ['id' => 'block_rate',          'label' => 'Block Rate %',        'icon' => 'fa-percentage',       'gradient' => 'warning'],
            ['id' => 'avg_risk_score',      'label' => 'Avg Risk Score',      'icon' => 'fa-gauge-high',       'gradient' => 'primary'],
            ['id' => 'api_requests',        'label' => 'API Requests',        'icon' => 'fa-satellite-dish',   'gradient' => 'success'],
        ];

        echo '<div class="fps-stats-grid" id="fps-dashboard-stats">';

        foreach ($stats as $stat) {
            $safeId    = htmlspecialchars($stat['id'], ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8');
            $sparkId   = 'fps-spark-' . $safeId;

            echo '<div class="fps-stat-card" id="fps-stat-' . $safeId . '">';
            echo '  <div class="fps-stat-icon fps-gradient-' . $stat['gradient'] . '">';
            echo '    <i class="fas ' . $stat['icon'] . '"></i>';
            echo '  </div>';
            echo '  <div class="fps-stat-content">';
            echo '    <div class="fps-stat-value" data-countup="0" id="fps-val-' . $safeId . '">--</div>';
            echo '    <div class="fps-stat-label">' . $safeLabel . '</div>';
            echo '    <canvas id="' . $sparkId . '" class="fps-sparkline" width="100" height="30"></canvas>';
            echo '  </div>';
            echo '</div>';
        }

        echo '</div>';

        // JavaScript to load stats via AJAX -- use json_encode for <script> context
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');
        echo <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    FpsAdmin.loadDashboardStats({$jsAjaxUrl});
});
</script>
HTML;
    }

    /**
     * Render the manual fraud check form.
     */
    private function fpsRenderManualCheckForm(string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $formHtml = <<<HTML
<div class="fps-manual-check-form">
  <div class="fps-form-row">
    <div class="fps-form-group" style="flex:1;">
      <label for="fps-manual-client-id"><i class="fas fa-user"></i> Client ID</label>
      <input type="number" id="fps-manual-client-id" class="fps-input" placeholder="Enter Client ID" min="1">
    </div>
    <div class="fps-form-group fps-form-actions" style="padding-top:24px;">
      <button type="button" class="fps-btn fps-btn-md fps-btn-primary" onclick="FpsAdmin.runManualCheck('{$ajaxUrl}')">
        <i class="fas fa-magnifying-glass"></i> Run Check
      </button>
    </div>
  </div>
  <div id="fps-manual-check-result" class="fps-manual-result" style="display:none;"></div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Manual Fraud Check', 'fa-user-secret', $formHtml);
    }

    /**
     * Render the recent checks table (populated via AJAX).
     */
    private function fpsRenderRecentChecks(string $modulelink): void
    {
        // json_encode for <script> context; no &amp; encoding issues
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        // We render the table shell and let JS populate it
        $tableHtml = <<<HTML
<div id="fps-recent-checks-container">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:95%"></div>
    <div class="fps-skeleton-line" style="width:90%"></div>
    <div class="fps-skeleton-line" style="width:88%"></div>
    <div class="fps-skeleton-line" style="width:92%"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    FpsAdmin.loadRecentChecks({$jsAjaxUrl});
});
</script>
HTML;

        echo FpsAdminRenderer::renderCard('Recent Fraud Checks (Last 20)', 'fa-clock-rotate-left', $tableHtml);
    }
}

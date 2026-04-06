<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabMassScan -- bulk client scanning with batch AJAX progress tracking.
 *
 * Scans run in batches of 25 via AJAX. JavaScript handles the batch loop,
 * progress bar updates, and ETA calculation. Results accumulate in a table
 * with bulk action support.
 */
class TabMassScan
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderConfigCard($ajaxUrl);
        $this->fpsRenderProgressCard();
        $this->fpsRenderResultsCard($ajaxUrl);
    }

    /**
     * Scan configuration form with filters and start button.
     */
    private function fpsRenderConfigCard(string $ajaxUrl): void
    {
        $clientCount = 0;
        try {
            $clientCount = Capsule::table('tblclients')->count();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // WHMCS CSRF token for AJAX requests
        $token = $_SESSION['token'] ?? '';

        $content = <<<HTML
<input type="hidden" name="token" value="{$token}">
<div id="fps-scan-filters" class="fps-form-row">
  <div class="fps-form-group">
    <label for="fps-scan-status"><i class="fas fa-filter"></i> Client Status</label>
    <select id="fps-scan-status" name="status" class="fps-select">
      <option value="Active">Active Only</option>
      <option value="Inactive">Inactive Only</option>
      <option value="">All Clients</option>
    </select>
  </div>
  <div class="fps-form-group">
    <label for="fps-scan-date-from"><i class="fas fa-calendar"></i> Registered After</label>
    <input type="date" id="fps-scan-date-from" name="date_from" class="fps-input">
  </div>
  <div class="fps-form-group">
    <label for="fps-scan-date-to"><i class="fas fa-calendar-check"></i> Registered Before</label>
    <input type="date" id="fps-scan-date-to" name="date_to" class="fps-input">
  </div>
  <div class="fps-form-group">
    <label for="fps-scan-skip-recent"><i class="fas fa-forward"></i> Skip Recently Checked</label>
    <select id="fps-scan-skip-recent" name="skip_recent" class="fps-select">
      <option value="7">Last 7 days</option>
      <option value="30" selected>Last 30 days</option>
      <option value="0">Don't skip</option>
    </select>
  </div>
</div>
<div class="fps-form-row fps-form-row-right">
  <p class="fps-text-muted" style="margin-right:auto;">
    <i class="fas fa-users"></i> Total clients in database: <strong>{$clientCount}</strong>
  </p>
  <button type="button" class="fps-btn fps-btn-md fps-btn-primary" id="fps-scan-start-btn"
    onclick="FpsAdmin.startMassScan('{$ajaxUrl}')">
    <i class="fas fa-radar"></i> Start Scan
  </button>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Mass Scan Configuration', 'fa-sliders', $content);
    }

    /**
     * Progress card (hidden until scan starts).
     */
    private function fpsRenderProgressCard(): void
    {
        $content = <<<HTML
<div class="fps-progress-container">
  <div class="fps-progress-bar-wrapper">
    <div class="fps-progress-bar" id="fps-scan-progress-bar" style="width:0%;">
      <span id="fps-scan-progress-pct">0%</span>
    </div>
  </div>
  <div class="fps-progress-info">
    <span id="fps-scan-status-text">
      <i class="fas fa-spinner fa-spin"></i> Preparing scan...
    </span>
    <span id="fps-scan-eta" class="fps-text-muted"></span>
  </div>
  <div class="fps-scan-batch-stats" id="fps-scan-batch-stats">
    <div class="fps-mini-stat">
      <span class="fps-mini-stat-value" id="fps-scan-scanned">0</span>
      <span class="fps-mini-stat-label">Scanned</span>
    </div>
    <div class="fps-mini-stat">
      <span class="fps-mini-stat-value fps-text-warning" id="fps-scan-flagged">0</span>
      <span class="fps-mini-stat-label">Flagged</span>
    </div>
    <div class="fps-mini-stat">
      <span class="fps-mini-stat-value fps-text-danger" id="fps-scan-blocked">0</span>
      <span class="fps-mini-stat-label">Blocked</span>
    </div>
  </div>
</div>
<div class="fps-form-row fps-form-row-right" style="margin-top:12px;">
  <button type="button" class="fps-btn fps-btn-sm fps-btn-danger" id="fps-scan-cancel-btn"
    onclick="FpsAdmin.cancelMassScan()" style="display:none;">
    <i class="fas fa-stop"></i> Cancel Scan
  </button>
</div>
HTML;

        echo '<div id="fps-scan-progress-card" style="display:none;">';
        echo FpsAdminRenderer::renderCard('Scan Progress', 'fa-hourglass-half', $content);
        echo '</div>';
    }

    /**
     * Results card with table and bulk actions (hidden until scan completes).
     */
    private function fpsRenderResultsCard(string $ajaxUrl): void
    {
        $content = <<<HTML
<div class="fps-scan-summary" id="fps-scan-summary">
  <div class="fps-mini-stat-row">
    <div class="fps-mini-stat fps-gradient-primary">
      <span class="fps-mini-stat-value" id="fps-scan-total-result">0</span>
      <span class="fps-mini-stat-label">Total Scanned</span>
    </div>
    <div class="fps-mini-stat fps-gradient-warning">
      <span class="fps-mini-stat-value" id="fps-scan-flagged-result">0</span>
      <span class="fps-mini-stat-label">Flagged</span>
    </div>
    <div class="fps-mini-stat fps-gradient-danger">
      <span class="fps-mini-stat-value" id="fps-scan-blocked-result">0</span>
      <span class="fps-mini-stat-label">Blocked</span>
    </div>
  </div>
</div>

<div class="fps-bulk-actions-bar" id="fps-scan-bulk-bar">
  <label class="fps-checkbox-label">
    <input type="checkbox" id="fps-scan-select-all" onclick="FpsAdmin.toggleSelectAll('fps-scan-row-check')">
    <span>Select All</span>
  </label>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-warning"
    onclick="FpsAdmin.scanBulkAction('flag', '{$ajaxUrl}')">
    <i class="fas fa-flag"></i> Flag Selected
  </button>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-danger"
    onclick="FpsAdmin.scanBulkAction('terminate', '{$ajaxUrl}')">
    <i class="fas fa-skull-crossbones"></i> Terminate Selected
  </button>
  <button type="button" class="fps-btn fps-btn-sm fps-btn-info"
    onclick="FpsAdmin.scanExportCsv('{$ajaxUrl}')">
    <i class="fas fa-file-csv"></i> Export CSV
  </button>
</div>

<div id="fps-scan-results-table">
  <p class="fps-text-muted"><i class="fas fa-info-circle"></i> Results will appear here after scanning.</p>
</div>
HTML;

        echo '<div id="fps-scan-results-card" style="display:none;">';
        echo FpsAdminRenderer::renderCard('Scan Results', 'fa-list-check', $content);
        echo '</div>';
    }
}

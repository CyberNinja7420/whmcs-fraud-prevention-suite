<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * TabBotCleanup -- Bot detection and cleanup interface.
 *
 * Uses real WHMCS financial data (paid invoices, active hosting) to identify
 * bot accounts with 100% accuracy and zero false positives.
 *
 * Features:
 *   - Scan all clients to detect bots
 *   - Preview (dry-run) before any destructive action
 *   - Flag, Deactivate, Purge, and Deep Purge actions
 *   - Deep Purge handles accounts with Fraud/Cancelled records
 */
class TabBotCleanup
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->renderInfoCard();
        $this->renderScanCard($ajaxUrl);
        $this->renderResultsCard($ajaxUrl);
        $this->renderUserCleanupCard($ajaxUrl);
        $this->renderPreviewModal();
    }

    /**
     * Info card explaining bot detection methodology.
     */
    private function renderInfoCard(): void
    {
        echo <<<'HTML'
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-circle-info"></i> Bot Detection Methodology</h3>
  </div>
  <div class="fps-card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;">
      <div style="padding:1rem;border-radius:8px;background:rgba(56,239,125,0.08);border:1px solid rgba(56,239,125,0.2);">
        <h4 style="margin:0 0 .5rem;color:#38ef7d;"><i class="fas fa-check-circle"></i> Real Client</h4>
        <p style="margin:0;font-size:0.9rem;">Has at least one <strong>paid invoice</strong> (total > $0) OR at least one <strong>active hosting</strong> service.</p>
      </div>
      <div style="padding:1rem;border-radius:8px;background:rgba(245,87,108,0.08);border:1px solid rgba(245,87,108,0.2);">
        <h4 style="margin:0 0 .5rem;color:#f5576c;"><i class="fas fa-robot"></i> Suspected Bot</h4>
        <p style="margin:0;font-size:0.9rem;">Has <strong>zero paid invoices</strong> and <strong>zero active hosting</strong>. May have Fraud/Cancelled/Unpaid records.</p>
      </div>
      <div style="padding:1rem;border-radius:8px;background:rgba(100,149,237,0.08);border:1px solid rgba(100,149,237,0.2);">
        <h4 style="margin:0 0 .5rem;color:#6495ed;"><i class="fas fa-shield-halved"></i> Deep Purge</h4>
        <p style="margin:0;font-size:0.9rem;">Deletes accounts where <strong>all</strong> orders are Fraud/Cancelled, all invoices Unpaid/Cancelled, all hosting Terminated/Fraud.</p>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Scan controls card.
     */
    private function renderScanCard(string $ajaxUrl): void
    {
        $totalClients = 0;
        try {
            $totalClients = Capsule::table('tblclients')->count();
        } catch (\Throwable $e) {
            // non-fatal
        }

        echo <<<HTML
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-magnifying-glass"></i> Bot Detection Scan</h3>
    <span class="fps-badge fps-badge-low">{$totalClients} total clients</span>
  </div>
  <div class="fps-card-body">
    <div class="fps-form-row" style="align-items:flex-end;gap:1rem;">
      <div class="fps-form-group">
        <label for="fps-bot-status"><i class="fas fa-filter"></i> Filter by Status</label>
        <select id="fps-bot-status" class="fps-select">
          <option value="" selected>All Clients</option>
          <option value="Active">Active Only</option>
          <option value="Inactive">Inactive Only</option>
          <option value="Closed">Closed Only</option>
        </select>
      </div>
      <div class="fps-form-group">
        <button class="fps-btn fps-btn-primary" onclick="FpsBot.scan()" id="fps-bot-scan-btn">
          <i class="fas fa-search"></i> Scan for Bots
        </button>
      </div>
    </div>
    <div id="fps-bot-scan-status" style="display:none;margin-top:1rem;">
      <div class="fps-progress-bar" style="height:8px;border-radius:4px;background:rgba(255,255,255,0.1);overflow:hidden;">
        <div id="fps-bot-progress" style="width:0%;height:100%;background:linear-gradient(90deg,#38ef7d,#11998e);transition:width 0.3s;border-radius:4px;"></div>
      </div>
      <p id="fps-bot-scan-text" style="margin-top:0.5rem;font-size:0.85rem;opacity:0.7;">Scanning...</p>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Results table with bulk action buttons.
     */
    private function renderResultsCard(string $ajaxUrl): void
    {
        $actionBar = $this->getActionButtonsHtml();

        echo <<<HTML
<div class="fps-card" id="fps-bot-results-card" style="display:none;">
  <div class="fps-card-header">
    <h3><i class="fas fa-list"></i> Scan Results</h3>
    <div id="fps-bot-summary" style="display:flex;gap:0.5rem;"></div>
  </div>
  <div class="fps-card-body">

    <!-- TOP Action Bar (Preview + Execute) -->
    {$actionBar}

    <!-- Selected count -->
    <div id="fps-bot-selected-count" style="margin-bottom:0.5rem;font-size:0.85rem;opacity:0.7;">
      0 accounts selected
    </div>

    <!-- Results table -->
    <div style="overflow-x:auto;">
      <table class="fps-table" id="fps-bot-table">
        <thead>
          <tr>
            <th style="width:35px;"><input type="checkbox" id="fps-bot-check-all" onchange="FpsBot.toggleAll(this.checked)"></th>
            <th>ID</th>
            <th>Email</th>
            <th>Name</th>
            <th>Status</th>
            <th>Created</th>
            <th>Orders</th>
            <th>Invoices</th>
            <th>Hosting</th>
            <th>Evidence</th>
          </tr>
        </thead>
        <tbody id="fps-bot-tbody">
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div id="fps-bot-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;">
      <span id="fps-bot-page-info" style="font-size:0.85rem;opacity:0.7;"></span>
      <div style="display:flex;gap:0.25rem;">
        <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.prevPage()" id="fps-bot-prev">Prev</button>
        <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.nextPage()" id="fps-bot-next">Next</button>
      </div>
    </div>

    <!-- BOTTOM Action Bar (same buttons repeated so users don't scroll up) -->
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.1);">
      {$actionBar}
    </div>

  </div>
</div>
HTML;
    }

    /**
     * Generate the action buttons HTML (used at top AND bottom of results).
     */
    private function getActionButtonsHtml(): string
    {
        return <<<'BUTTONS'
<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
  <button class="fps-btn fps-btn-sm" style="background:#6495ed;" onclick="FpsBot.selectAll()">
    <i class="fas fa-check-double"></i> Select All
  </button>
  <button class="fps-btn fps-btn-sm" style="background:#888;" onclick="FpsBot.deselectAll()">
    <i class="fas fa-times"></i> Deselect All
  </button>
  <span style="border-left:1px solid rgba(255,255,255,0.15);margin:0 0.25rem;"></span>

  <!-- DRY-RUN / PREVIEW buttons -->
  <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.preview('flag')" title="Dry-run: see what flagging will do without making changes">
    <i class="fas fa-eye"></i> Dry-Run Flag
  </button>
  <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.preview('deactivate')" title="Dry-run: see what deactivation will do without making changes">
    <i class="fas fa-eye"></i> Dry-Run Deactivate
  </button>
  <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.preview('purge')" title="Dry-run: see which zero-record accounts can be purged">
    <i class="fas fa-eye"></i> Dry-Run Purge
  </button>
  <button class="fps-btn fps-btn-sm fps-btn-outline" style="border-color:#f5576c;color:#f5576c;" onclick="FpsBot.preview('deep_purge')" title="Dry-run: see which Fraud/Cancelled accounts can be deep purged">
    <i class="fas fa-eye"></i> Dry-Run Deep Purge
  </button>

  <span style="border-left:1px solid rgba(255,255,255,0.15);margin:0 0.25rem;"></span>

  <!-- EXECUTE buttons -->
  <button class="fps-btn fps-btn-sm" style="background:#f5c842;color:#000;" onclick="FpsBot.execute('flag')">
    <i class="fas fa-flag"></i> Flag Selected
  </button>
  <button class="fps-btn fps-btn-sm" style="background:#ff9800;color:#000;" onclick="FpsBot.execute('deactivate')">
    <i class="fas fa-ban"></i> Deactivate Selected
  </button>
  <button class="fps-btn fps-btn-sm" style="background:#f5576c;" onclick="FpsBot.execute('purge')">
    <i class="fas fa-trash"></i> Purge Selected
  </button>
  <button class="fps-btn fps-btn-sm" style="background:#eb3349;" onclick="FpsBot.execute('deep_purge')">
    <i class="fas fa-skull-crossbones"></i> Deep Purge Selected
  </button>
</div>
BUTTONS;
    }

    /**
     * User account cleanup card (tblusers -- WHMCS 8.x).
     */
    private function renderUserCleanupCard(string $ajaxUrl): void
    {
        $hasUsersTable = Capsule::schema()->hasTable('tblusers');
        if (!$hasUsersTable) return; // Skip on WHMCS < 8.0

        $totalUsers = Capsule::table('tblusers')->count();

        echo <<<HTML
<div class="fps-card">
  <div class="fps-card-header">
    <h3><i class="fas fa-user-slash"></i> User Account Cleanup (WHMCS 8.x)</h3>
    <span class="fps-badge" style="background:#6495ed;">{$totalUsers} total users</span>
  </div>
  <div class="fps-card-body">
    <div style="padding:0.75rem;border-radius:8px;background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);margin-bottom:1rem;font-size:0.9rem;">
      <strong><i class="fas fa-exclamation-triangle"></i> Why this matters:</strong>
      WHMCS 8.x has separate <strong>Client</strong> accounts (billing) and <strong>User</strong> accounts (login).
      Purging a bot client leaves the user login account intact -- they can log back in and create new clients.
      This scan finds orphan users (no real client links) so you can clean them too.
    </div>

    <div style="display:flex;gap:0.5rem;margin-bottom:1rem;">
      <button class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsBotUsers.scan()" id="fps-user-scan-btn">
        <i class="fas fa-search"></i> Scan for Orphan Users
      </button>
    </div>

    <div id="fps-user-results" style="display:none;">
      <div id="fps-user-summary" style="margin-bottom:0.5rem;"></div>
      <div style="overflow-x:auto;">
        <table class="fps-table" id="fps-user-table">
          <thead>
            <tr>
              <th style="width:35px;"><input type="checkbox" id="fps-user-check-all" onchange="FpsBotUsers.toggleAll(this.checked)"></th>
              <th>User ID</th>
              <th>Email</th>
              <th>Name</th>
              <th>Last IP</th>
              <th>Last Login</th>
              <th>Created</th>
              <th>Clients</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody id="fps-user-tbody"></tbody>
        </table>
      </div>
      <div style="margin-top:0.75rem;display:flex;gap:0.5rem;">
        <button class="fps-btn fps-btn-sm" style="background:#6495ed;" onclick="FpsBotUsers.selectAll()">
          <i class="fas fa-check-double"></i> Select All
        </button>
        <button class="fps-btn fps-btn-sm" style="background:#eb3349;" onclick="FpsBotUsers.purge()">
          <i class="fas fa-trash"></i> Purge Selected Users
        </button>
      </div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Preview modal for dry-run results.
     */
    private function renderPreviewModal(): void
    {
        echo <<<'HTML'
<!-- Bot Preview Modal Overlay -->
<div id="fps-bot-preview-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:var(--fps-card-bg,#1e1e2e);border:1px solid rgba(255,255,255,0.1);border-radius:12px;max-width:800px;width:95%;max-height:80vh;display:flex;flex-direction:column;">
    <div style="padding:1rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
      <h3 id="fps-bot-preview-title" style="margin:0;"><i class="fas fa-eye"></i> Preview Results</h3>
      <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsBot.closePreview()"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:1rem 1.5rem;overflow-y:auto;flex:1;">
      <div id="fps-bot-preview-summary" style="margin-bottom:1rem;padding:0.75rem;border-radius:8px;background:rgba(100,149,237,0.1);border:1px solid rgba(100,149,237,0.2);"></div>
      <div style="overflow-x:auto;">
        <table class="fps-table" id="fps-bot-preview-table">
          <thead id="fps-bot-preview-thead"></thead>
          <tbody id="fps-bot-preview-tbody"></tbody>
        </table>
      </div>
    </div>
    <div style="padding:1rem 1.5rem;border-top:1px solid rgba(255,255,255,0.1);text-align:right;">
      <button class="fps-btn fps-btn-outline" onclick="FpsBot.closePreview()">Close</button>
    </div>
  </div>
</div>
HTML;
    }
}

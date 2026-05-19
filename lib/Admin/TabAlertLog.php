<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * TabAlertLog -- admin-visible log of all API alerts, errors, and provider issues.
 *
 * Shows module log entries, API failures, rate limit hits, and provider status
 * so admins can quickly diagnose and resolve issues.
 */
class TabAlertLog
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderCronHealth($modulelink);
        $this->fpsRenderProviderStatus();
        $this->fpsRenderRecentAlerts();
        $this->fpsRenderModuleLog();
        $this->fpsRenderClearActions($ajaxUrl);
    }

    /**
     * Provider health status cards.
     */
    private function fpsRenderProviderStatus(): void
    {
        $providers = [
            ['name' => 'Turnstile', 'key_setting' => 'turnstile_enabled', 'icon' => 'fa-shield-halved'],
            ['name' => 'AbuseIPDB', 'key_setting' => 'abuseipdb_enabled', 'icon' => 'fa-bug'],
            ['name' => 'IPQualityScore', 'key_setting' => 'ipqs_enabled', 'icon' => 'fa-magnifying-glass-chart'],
            ['name' => 'FraudRecord', 'key_setting' => 'provider_fraudrecord', 'icon' => 'fa-shield-alt'],
            ['name' => 'Abuse Signals', 'key_setting' => 'abuse_signal_enabled', 'icon' => 'fa-exclamation-triangle'],
            ['name' => 'Domain Rep.', 'key_setting' => 'domain_reputation_enabled', 'icon' => 'fa-globe'],
            ['name' => 'IP Intel', 'key_setting' => 'ip_intel_enabled', 'icon' => 'fa-network-wired'],
            ['name' => 'Bot Detector', 'key_setting' => 'bot_signup_blocking', 'icon' => 'fa-robot'],
        ];

        $cards = '<div class="fps-mini-stat-row" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">';
        foreach ($providers as $p) {
            $enabled = false;
            try {
                $val = Capsule::table('mod_fps_settings')
                    ->where('setting_key', $p['key_setting'])
                    ->value('setting_value');
                $enabled = ($val === '1' || $val === 'yes' || $val === 'on');
            } catch (\Throwable $e) {
                // Check tbladdonmodules as fallback
                try {
                    $val = Capsule::table('tbladdonmodules')
                        ->where('module', 'fraud_prevention_suite')
                        ->where('setting', $p['key_setting'])
                        ->value('value');
                    $enabled = ($val === '1' || $val === 'yes' || $val === 'on');
                } catch (\Throwable $e2) {}
            }

            // Check for recent errors
            $recentErrors = 0;
            try {
                $recentErrors = Capsule::table('tblmodulelog')
                    ->where('module', 'fraud_prevention_suite')
                    ->where('action', 'LIKE', '%' . str_replace(' ', '', $p['name']) . '%')
                    ->where('response', 'LIKE', '%rror%')
                    ->where('date', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->count();
            } catch (\Throwable $e) {}

            $statusClass = $enabled ? ($recentErrors > 0 ? 'fps-gradient-warning' : 'fps-gradient-success') : 'fps-gradient-dark';
            $statusLabel = $enabled ? ($recentErrors > 0 ? 'ERRORS' : 'ACTIVE') : 'OFF';

            $cards .= '<div class="fps-mini-stat ' . $statusClass . '">';
            $cards .= '  <span class="fps-mini-stat-value"><i class="fas ' . $p['icon'] . '"></i></span>';
            $cards .= '  <span class="fps-mini-stat-label">' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '<br><strong>' . $statusLabel . '</strong></span>';
            $cards .= '</div>';
        }
        $cards .= '</div>';

        echo FpsAdminRenderer::renderCard('Provider Status (last 24h)', 'fa-heartbeat', $cards);
    }

    /**
     * Recent alerts: API failures, rate limits, validation errors.
     */
    private function fpsRenderRecentAlerts(): void
    {
        try {
            $alerts = Capsule::table('tblmodulelog')
                ->where('module', 'fraud_prevention_suite')
                ->where(function ($q) {
                    $q->where('response', 'LIKE', '%rror%')
                      ->orWhere('response', 'LIKE', '%fail%')
                      ->orWhere('response', 'LIKE', '%429%')
                      ->orWhere('response', 'LIKE', '%401%')
                      ->orWhere('response', 'LIKE', '%timeout%')
                      ->orWhere('action', 'LIKE', '%ERROR%');
                })
                ->orderByDesc('id')
                ->limit(25)
                ->get(['id', 'action', 'request', 'response', 'date']);

            if ($alerts->isEmpty()) {
                echo FpsAdminRenderer::renderCard('Recent Alerts', 'fa-bell',
                    '<p class="fps-text-muted"><i class="fas fa-check-circle" style="color:var(--fps-risk-low);"></i> No alerts or errors in the log. All systems operating normally.</p>');
                return;
            }

            $rows = '';
            foreach ($alerts as $a) {
                $severity = 'fps-badge-medium';
                if (stripos($a->response ?? '', '401') !== false || stripos($a->response ?? '', '403') !== false) {
                    $severity = 'fps-badge-critical';
                } elseif (stripos($a->response ?? '', '429') !== false) {
                    $severity = 'fps-badge-high';
                } elseif (stripos($a->action ?? '', 'ERROR') !== false) {
                    $severity = 'fps-badge-critical';
                }

                $action = htmlspecialchars(substr($a->action ?? '', 0, 50), ENT_QUOTES, 'UTF-8');
                $response = htmlspecialchars(substr($a->response ?? '', 0, 150), ENT_QUOTES, 'UTF-8');
                $date = htmlspecialchars($a->date ?? '', ENT_QUOTES, 'UTF-8');

                $rows .= '<tr>'
                    . '<td>' . $date . '</td>'
                    . '<td><span class="fps-badge fps-badge-sm ' . $severity . '">!</span> ' . $action . '</td>'
                    . '<td style="font-size:0.9rem;color:var(--fps-text-secondary);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $response . '</td>'
                    . '</tr>';
            }

            $table = '<table class="fps-table"><thead><tr>'
                . '<th>Time</th><th>Action</th><th>Details</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>';

            echo FpsAdminRenderer::renderCard('Recent Alerts & Errors (' . count($alerts) . ')', 'fa-exclamation-circle', $table);

        } catch (\Throwable $e) {
            echo FpsAdminRenderer::renderCard('Recent Alerts', 'fa-bell',
                '<p class="fps-text-muted">Error loading alerts: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>');
        }
    }

    /**
     * Full module log (last 50 entries).
     */
    private function fpsRenderModuleLog(): void
    {
        try {
            $logs = Capsule::table('tblmodulelog')
                ->where('module', 'fraud_prevention_suite')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'action', 'request', 'response', 'date']);

            if ($logs->isEmpty()) {
                echo FpsAdminRenderer::renderCard('Module Log', 'fa-scroll',
                    '<p class="fps-text-muted">No log entries.</p>');
                return;
            }

            $rows = '';
            foreach ($logs as $l) {
                $isError = stripos($l->response ?? '', 'rror') !== false || stripos($l->action ?? '', 'ERROR') !== false;
                $rowClass = $isError ? ' style="background:rgba(245,87,108,0.04);"' : '';
                $action = htmlspecialchars(substr($l->action ?? '', 0, 60), ENT_QUOTES, 'UTF-8');
                $request = htmlspecialchars(substr($l->request ?? '', 0, 80), ENT_QUOTES, 'UTF-8');
                $response = htmlspecialchars(substr($l->response ?? '', 0, 120), ENT_QUOTES, 'UTF-8');

                $rows .= '<tr' . $rowClass . '>'
                    . '<td style="white-space:nowrap;">' . htmlspecialchars($l->date ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . $action . '</td>'
                    . '<td style="font-size:0.88rem;color:var(--fps-text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;">' . $request . '</td>'
                    . '<td style="font-size:0.88rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;">' . $response . '</td>'
                    . '</tr>';
            }

            $table = '<table class="fps-table"><thead><tr>'
                . '<th>Time</th><th>Action</th><th>Request</th><th>Response</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>';

            echo FpsAdminRenderer::renderCard('Module Activity Log (last 50)', 'fa-scroll', $table);

        } catch (\Throwable $e) {
            echo FpsAdminRenderer::renderCard('Module Log', 'fa-scroll',
                '<p class="fps-text-muted">Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>');
        }
    }

    /**
     * Log management actions: clear module logs, clear error logs.
     */
    private function fpsRenderClearActions(string $ajaxUrl): void
    {
        $content = <<<HTML
<div class="fps-form-row" style="gap:1rem;flex-wrap:wrap;">
  <button type="button" class="fps-btn fps-btn-md fps-btn-danger"
    onclick="FpsAdmin.clearFpsLogs('{$ajaxUrl}')">
    <i class="fas fa-trash-alt"></i> Clear All Module Logs
  </button>
  <button type="button" class="fps-btn fps-btn-md fps-btn-warning"
    onclick="FpsAdmin.clearAllChecks('{$ajaxUrl}')">
    <i class="fas fa-eraser"></i> Clear All Fraud Checks
  </button>
  <p class="fps-text-muted" style="margin:0;font-size:0.85rem;align-self:center;">
    <i class="fas fa-info-circle"></i> Clearing logs or checks is permanent and cannot be undone. Stats are preserved.
  </p>
</div>
HTML;
        echo FpsAdminRenderer::renderCard('Log Management', 'fa-broom', $content);
    }

    /**
     * Cron Health Dashboard -- shows last-run timestamps and health status
     * for every cron-dependent feature. Loads via AJAX to avoid slowing page.
     */
    private function fpsRenderCronHealth(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $bodyHtml = <<<'HTML'
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
  <span class="fps-text-muted" style="font-size:0.82rem;">
    Monitors when each cron-dependent feature last executed. Status is based on expected schedule.
  </span>
  <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" id="fps-cron-refresh-btn" onclick="fpsCronHealthLoad()">
    <i class="fas fa-sync"></i> Refresh
  </button>
</div>
<div id="fps-cron-health-container">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:95%"></div>
    <div class="fps-skeleton-line" style="width:90%"></div>
    <div class="fps-skeleton-line" style="width:85%"></div>
    <div class="fps-skeleton-line" style="width:92%"></div>
    <div class="fps-skeleton-line" style="width:88%"></div>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Cron Health Dashboard', 'fa-heartbeat', $bodyHtml);

        // Inline JS fetches cron health via AJAX and renders status grid.
        // All data comes from the authenticated admin AJAX endpoint (get_cron_health).
        // The rendered output uses escaped text via fpsSafeText() for all dynamic values.
        echo '<script>';
        echo '(function() {';
        echo 'var cronUrl = ' . $jsAjaxUrl . ';';
        echo <<<'JSBLOCK'

function fpsSafeText(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function fpsCronStatusBadge(ageHours, cycle) {
    var greenMax, yellowMax;
    if (cycle === 'monthly') {
        greenMax = 744; yellowMax = 1488;
    } else if (cycle === 'weekly') {
        greenMax = 192; yellowMax = 336;
    } else if (cycle === 'continuous') {
        greenMax = 24; yellowMax = 72;
    } else {
        greenMax = 26; yellowMax = 72;
    }
    if (ageHours < 0) {
        return '<span class="fps-badge fps-badge-sm" style="background:var(--fps-text-muted,#6a7195);color:#fff;">NEVER</span>';
    }
    if (ageHours <= greenMax) {
        return '<span class="fps-badge fps-badge-sm" style="background:#16a34a;color:#fff;">HEALTHY</span>';
    }
    if (ageHours <= yellowMax) {
        return '<span class="fps-badge fps-badge-sm" style="background:#f59e0b;color:#000;">STALE</span>';
    }
    return '<span class="fps-badge fps-badge-sm" style="background:#ef4444;color:#fff;">OVERDUE</span>';
}

function fpsFormatAge(hours) {
    if (hours < 0) return '--';
    if (hours < 1) return Math.round(hours * 60) + 'm ago';
    if (hours < 48) return Math.round(hours) + 'h ago';
    return Math.round(hours / 24) + 'd ago';
}

window.fpsCronHealthLoad = function() {
    var container = document.getElementById('fps-cron-health-container');
    if (!container) return;
    container.textContent = '';
    var skel = document.createElement('div');
    skel.className = 'fps-skeleton-container';
    for (var s = 0; s < 3; s++) {
        var line = document.createElement('div');
        line.className = 'fps-skeleton-line';
        line.style.width = (90 + s * 3) + '%';
        skel.appendChild(line);
    }
    container.appendChild(skel);

    fetch(cronUrl + '&a=get_cron_health', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            container.textContent = '';
            if (!j.success || !j.items) {
                var errP = document.createElement('p');
                errP.className = 'fps-text-muted';
                errP.textContent = j.error || 'Failed to load cron health';
                container.appendChild(errP);
                return;
            }
            var grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px;';

            j.items.forEach(function(item) {
                var badge = fpsCronStatusBadge(item.age_hours, item.cycle || 'daily');
                var age = fpsFormatAge(item.age_hours);
                var iconClass = item.icon || 'fa-clock';
                var lastRun = fpsSafeText(item.last_run || 'Never');
                var cycleLabel = (item.cycle || 'daily');
                cycleLabel = cycleLabel.charAt(0).toUpperCase() + cycleLabel.slice(1);

                var card = document.createElement('div');
                card.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;border:1px solid rgba(102,126,234,0.12);background:rgba(255,255,255,0.02);';

                var iconWrap = document.createElement('div');
                iconWrap.style.cssText = 'flex-shrink:0;width:32px;height:32px;border-radius:6px;background:rgba(102,126,234,0.1);display:flex;align-items:center;justify-content:center;color:#667eea;font-size:0.9rem;';
                var icon = document.createElement('i');
                icon.className = 'fas ' + iconClass;
                iconWrap.appendChild(icon);
                card.appendChild(iconWrap);

                var info = document.createElement('div');
                info.style.cssText = 'flex:1;min-width:0;';

                var titleRow = document.createElement('div');
                titleRow.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:2px;flex-wrap:wrap;';
                var nameSpan = document.createElement('span');
                nameSpan.style.cssText = 'font-weight:600;font-size:0.85rem;color:var(--fps-text-primary,#e0e6f0);';
                nameSpan.textContent = item.name;
                titleRow.appendChild(nameSpan);
                var badgeSpan = document.createElement('span');
                badgeSpan.innerHTML = badge;
                titleRow.appendChild(badgeSpan);
                info.appendChild(titleRow);

                var detailRow = document.createElement('div');
                detailRow.style.cssText = 'font-size:0.78rem;color:var(--fps-text-muted,#6a7195);';
                var lastSpan = document.createElement('span');
                lastSpan.title = 'Last run';
                lastSpan.textContent = item.last_run || 'Never';
                detailRow.appendChild(lastSpan);
                detailRow.appendChild(document.createTextNode(' | ' + age + ' | '));
                var cycleSpan = document.createElement('span');
                cycleSpan.style.cssText = 'text-transform:uppercase;font-size:0.7rem;letter-spacing:0.04em;';
                cycleSpan.textContent = cycleLabel;
                detailRow.appendChild(cycleSpan);
                info.appendChild(detailRow);

                card.appendChild(info);
                grid.appendChild(card);
            });
            container.appendChild(grid);
        })
        .catch(function() {
            container.textContent = '';
            var errP = document.createElement('p');
            errP.className = 'fps-text-muted';
            errP.textContent = 'Network error loading cron health';
            container.appendChild(errP);
        });
};

document.addEventListener('DOMContentLoaded', function() {
    fpsCronHealthLoad();
});
JSBLOCK;
        echo '})();';
        echo '</script>';
    }
}

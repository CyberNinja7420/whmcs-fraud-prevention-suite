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
}

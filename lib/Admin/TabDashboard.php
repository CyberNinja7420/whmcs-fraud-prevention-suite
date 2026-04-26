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
        $this->fpsRenderSetupWizard($modulelink);
        $this->fpsRenderStatsRow($modulelink);
        $this->fpsRenderLatencyWidget($modulelink);
        $this->fpsRenderAnalyticsStatus($modulelink);
        $this->fpsRenderManualCheckForm($modulelink);
        $this->fpsRenderRecentChecks($modulelink);
    }

    /**
     * Pre-checkout latency widget (P50/P95/P99 + sample count + max).
     *
     * Reads from the `pre_checkout_latency` shape returned by
     * fps_ajaxDashboardStats() (computed via fps_computePreCheckoutLatency
     * over the last 24 hours of `mod_fps_checks` rows with check_type =
     * pre_checkout and a non-null check_duration_ms).
     *
     * Operators use this widget to baseline P95 BEFORE flipping the
     * use_runner_fast_path setting on, then verify P95 stays under
     * the <2s checkout budget AFTER flipping it on. Closes part of
     * TODO-hardening.md item #1.
     */
    private function fpsRenderLatencyWidget(string $modulelink): void
    {
        $bodyHtml = <<<'HTML'
<div class="fps-latency-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:6px;">
  <div class="fps-latency-cell">
    <div class="fps-latency-label" style="font-size:.72rem;color:var(--fps-text-muted);text-transform:uppercase;letter-spacing:.06em;">Samples (24h)</div>
    <div id="fps-lat-samples" class="fps-latency-value" style="font-size:1.4rem;font-weight:700;">--</div>
  </div>
  <div class="fps-latency-cell">
    <div class="fps-latency-label" style="font-size:.72rem;color:var(--fps-text-muted);text-transform:uppercase;letter-spacing:.06em;">P50 ms</div>
    <div id="fps-lat-p50" class="fps-latency-value" style="font-size:1.4rem;font-weight:700;color:#16a34a;">--</div>
  </div>
  <div class="fps-latency-cell">
    <div class="fps-latency-label" style="font-size:.72rem;color:var(--fps-text-muted);text-transform:uppercase;letter-spacing:.06em;">P95 ms <span style="font-size:.65rem;font-weight:600;color:var(--fps-text-muted);">(target &lt;2000)</span></div>
    <div id="fps-lat-p95" class="fps-latency-value" style="font-size:1.4rem;font-weight:700;color:#f59e0b;">--</div>
  </div>
  <div class="fps-latency-cell">
    <div class="fps-latency-label" style="font-size:.72rem;color:var(--fps-text-muted);text-transform:uppercase;letter-spacing:.06em;">P99 ms</div>
    <div id="fps-lat-p99" class="fps-latency-value" style="font-size:1.4rem;font-weight:700;color:#ef4444;">--</div>
  </div>
  <div class="fps-latency-cell">
    <div class="fps-latency-label" style="font-size:.72rem;color:var(--fps-text-muted);text-transform:uppercase;letter-spacing:.06em;">Max ms</div>
    <div id="fps-lat-max" class="fps-latency-value" style="font-size:1.4rem;font-weight:700;color:var(--fps-text-secondary);">--</div>
  </div>
</div>
<div id="fps-lat-help" style="font-size:.78rem;color:var(--fps-text-muted);margin-top:6px;">
  Pre-checkout pipeline latency over the last 24 hours. Used to baseline
  before flipping <code>use_runner_fast_path</code> on, and to verify P95
  stays under the &lt;2s checkout budget afterwards.
</div>
HTML;

        echo FpsAdminRenderer::renderCard(
            'Pre-Checkout Latency (24h)',
            'fa-stopwatch',
            $bodyHtml
        );

        // The widget is fed by the same fps_ajaxDashboardStats AJAX call
        // as the stat cards. The dashboard stats response already includes
        // pre_checkout_latency. We attach a small refresh handler that
        // fires when the existing loadDashboardStats() resolves.
        echo <<<'HTML'
<script>
(function() {
    // Patch FpsAdmin.loadDashboardStats: after each refresh, populate the
    // latency widget. If FpsAdmin isn't loaded yet, wait for DOMContentLoaded.
    function applyLatency(data) {
        if (!data || !data.pre_checkout_latency) return;
        var L = data.pre_checkout_latency;
        var setEl = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
        setEl('fps-lat-samples', L.samples || 0);
        setEl('fps-lat-p50',     L.samples ? L.p50 : '--');
        setEl('fps-lat-p95',     L.samples ? L.p95 : '--');
        setEl('fps-lat-p99',     L.samples ? L.p99 : '--');
        setEl('fps-lat-max',     L.samples ? L.max : '--');
        // Color P95 by target: green <1500, amber 1500-2000, red >=2000.
        var p95el = document.getElementById('fps-lat-p95');
        if (p95el && L.samples) {
            var p = parseInt(L.p95, 10);
            p95el.style.color = (p < 1500) ? '#16a34a' : (p < 2000 ? '#f59e0b' : '#ef4444');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Hook into FpsAdmin.loadDashboardStats by polling the response cache.
        // Simpler approach: re-fetch ourselves once and then piggyback on the
        // existing 30s refresh interval if FpsAdmin sets one.
        var url = (window.fpsModuleLink || '') + '&ajax=1&a=get_dashboard_stats';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { applyLatency(j && j.data); })
            .catch(function () { /* silent */ });
    });
})();
</script>
HTML;
    }

    /**
     * Setup wizard banner -- shows when module needs configuration.
     * Disappears when all steps are complete or admin dismisses it.
     */
    private function fpsRenderSetupWizard(string $modulelink): void
    {
        // Check if dismissed
        try {
            $dismissed = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'wizard_dismissed')->value('setting_value');
            if ($dismissed === '1') return;
        } catch (\Throwable $e) {}

        // Check completion status
        $steps = [];

        // Step 1: API providers configured
        $providers = 0;
        try {
            $keys = ['turnstile_site_key', 'abuseipdb_api_key', 'ipqs_api_key'];
            foreach ($keys as $k) {
                $v = Capsule::table('mod_fps_settings')->where('setting_key', $k)->value('setting_value');
                if (!empty($v)) $providers++;
            }
        } catch (\Throwable $e) {}
        $steps[] = ['done' => $providers >= 1, 'label' => 'Configure Detection Providers',
            'desc' => $providers . '/3 API keys configured (Turnstile, AbuseIPDB, IPQS)',
            'action' => $modulelink . '&tab=settings', 'btn' => 'Open Settings', 'icon' => 'fa-key'];

        // Step 2: Products exist
        $productCount = 0;
        try { $productCount = Capsule::table('tblproducts')->where('servertype', 'fps_api')->count(); } catch (\Throwable $e) {}
        $steps[] = ['done' => $productCount >= 3, 'label' => 'API Products Created',
            'desc' => $productCount . '/3 products using fps_api server module',
            'action' => 'configproducts.php', 'btn' => 'View Products', 'icon' => 'fa-box'];

        // Step 3: Pre-checkout blocking
        $preCheckout = false;
        try {
            $v = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'pre_checkout_blocking')->value('value');
            $preCheckout = ($v === 'on' || $v === 'yes');
        } catch (\Throwable $e) {}
        $steps[] = ['done' => $preCheckout, 'label' => 'Pre-Checkout Blocking Enabled',
            'desc' => $preCheckout ? 'Fraud checks run before every checkout' : 'Enable to block fraudulent orders at checkout',
            'action' => $modulelink . '&tab=settings', 'btn' => 'Enable', 'icon' => 'fa-shield-halved'];

        // Step 4: Run first scan
        $hasChecks = false;
        try { $hasChecks = Capsule::table('mod_fps_checks')->exists(); } catch (\Throwable $e) {}
        $steps[] = ['done' => $hasChecks, 'label' => 'Run First Fraud Scan',
            'desc' => $hasChecks ? 'Fraud checks are running' : 'Run a mass scan or manual check to start collecting data',
            'action' => $modulelink . '&tab=mass_scan', 'btn' => 'Run Mass Scan', 'icon' => 'fa-radar'];

        $doneCount = count(array_filter($steps, fn($s) => $s['done']));
        $totalSteps = count($steps);

        // All done? Don't show wizard
        if ($doneCount >= $totalSteps) return;

        $pct = round(($doneCount / $totalSteps) * 100);
        $safeLink = htmlspecialchars($modulelink, ENT_QUOTES, 'UTF-8');

        echo '<div style="margin-bottom:1.5rem;border-radius:12px;overflow:hidden;border:1px solid rgba(102,126,234,0.2);background:linear-gradient(135deg,rgba(102,126,234,0.05),rgba(118,75,162,0.05));">';

        // Header
        echo '<div style="padding:16px 20px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:space-between;">';
        echo '<div style="display:flex;align-items:center;gap:10px;">';
        echo '<i class="fas fa-wand-magic-sparkles" style="font-size:1.2rem;"></i>';
        echo '<span style="font-size:1rem;font-weight:700;color:#fff;">Getting Started</span>';
        echo '<span style="background:rgba(255,255,255,0.2);padding:2px 10px;border-radius:12px;font-size:0.75rem;color:#fff;">' . $doneCount . '/' . $totalSteps . ' complete</span>';
        echo '</div>';
        echo '<button onclick="fetch(\'' . $safeLink . '&ajax=1&a=dismiss_wizard\').then(()=>this.closest(\'div[style]\').remove())" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);color:#fff;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:0.78rem;">Dismiss</button>';
        echo '</div>';

        // Progress bar
        echo '<div style="height:4px;background:rgba(102,126,234,0.1);"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#38ef7d,#667eea);transition:width 0.5s;border-radius:0 2px 2px 0;"></div></div>';

        // Steps
        echo '<div style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">';
        foreach ($steps as $i => $step) {
            $borderColor = $step['done'] ? 'rgba(56,239,125,0.3)' : 'rgba(102,126,234,0.15)';
            $iconColor = $step['done'] ? '#38ef7d' : '#667eea';
            $checkIcon = $step['done'] ? '<i class="fas fa-circle-check" style="color:#38ef7d;"></i>' : '<i class="far fa-circle" style="color:#4a5080;"></i>';

            echo '<div style="display:flex;gap:12px;padding:12px;border-radius:8px;border:1px solid ' . $borderColor . ';background:rgba(255,255,255,0.02);">';
            echo '<div style="flex-shrink:0;width:36px;height:36px;border-radius:8px;background:rgba(102,126,234,0.1);display:flex;align-items:center;justify-content:center;color:' . $iconColor . ';font-size:1rem;"><i class="fas ' . $step['icon'] . '"></i></div>';
            echo '<div style="flex:1;min-width:0;">';
            echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">' . $checkIcon . ' <span style="font-weight:600;font-size:0.88rem;color:#e0e6f0;">' . htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') . '</span></div>';
            echo '<div style="font-size:0.78rem;color:#6a7195;margin-bottom:6px;">' . htmlspecialchars($step['desc'], ENT_QUOTES, 'UTF-8') . '</div>';
            if (!$step['done']) {
                echo '<a href="' . htmlspecialchars($step['action'], ENT_QUOTES, 'UTF-8') . '" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:5px;background:rgba(102,126,234,0.15);color:#667eea;font-size:0.75rem;font-weight:600;text-decoration:none;border:1px solid rgba(102,126,234,0.25);"><i class="fas fa-arrow-right"></i> ' . htmlspecialchars($step['btn'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            echo '</div></div>';
        }
        echo '</div></div>';
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

        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $tableHtml = <<<HTML
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
  <span class="fps-text-muted" style="font-size:0.85rem;">Showing most recent fraud checks across all types</span>
  <div style="display:flex;gap:6px;">
    <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" onclick="FpsAdmin.refreshDashboard()">
      <i class="fas fa-sync"></i> Refresh
    </button>
    <button type="button" class="fps-btn fps-btn-xs fps-btn-danger" onclick="FpsAdmin.clearAllChecks('{$ajaxUrl}')">
      <i class="fas fa-trash-alt"></i> Clear All
    </button>
  </div>
</div>
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

    /**
     * Analytics Connection Status widget.
     *
     * Shows GA4 + Clarity connection health (last server-event timestamp,
     * 24h-count, status dot) when at least one of the three analytics
     * toggles is on. Hidden entirely when nothing is configured -- avoids
     * cluttering the dashboard for operators who haven't opted in.
     *
     * Closes Task 15 of docs/plans/2026-04-22-analytics-integration.md.
     */
    private function fpsRenderAnalyticsStatus(string $modulelink): void
    {
        if (!class_exists('\FpsAnalyticsConfig') || !class_exists('\FpsAnalyticsLog')) return;

        $clientOn = \FpsAnalyticsConfig::isClientEnabled();
        $adminOn  = \FpsAnalyticsConfig::isAdminEnabled();
        $serverOn = \FpsAnalyticsConfig::isServerEnabled();

        if (!$clientOn && !$adminOn && !$serverOn) return;

        $ga4Status     = \FpsAnalyticsLog::statusSnapshot(\FpsAnalyticsLog::DEST_GA4_SERVER);
        $clarityStatus = \FpsAnalyticsLog::statusSnapshot(\FpsAnalyticsLog::DEST_CLARITY);

        $dot = function (bool $configured, ?string $lastTs): string {
            if (!$configured) return '<span style="color:#888;">&#x26AA;</span>';
            if ($lastTs === null) return '<span style="color:#f59e0b;">&#x1F7E1;</span>';
            $age = time() - strtotime($lastTs);
            return $age < 3600 ? '<span style="color:#16a34a;">&#x1F7E2;</span>' : '<span style="color:#ef4444;">&#x1F534;</span>';
        };

        $body  = '<div class="fps-analytics-status-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">';
        $body .= '  <div><strong>' . $dot($serverOn, $ga4Status["ts"]) . ' Google Analytics 4</strong>';
        $body .=      '<div class="fps-text-muted" style="font-size:.8rem;">Last server event: ' . htmlspecialchars($ga4Status["ts"] ?? '&mdash;', ENT_QUOTES, 'UTF-8') . ' &middot; '
                    . (int) $ga4Status['count'] . ' in last 24h</div>';
        $body .=      '<a href="https://analytics.google.com/" target="_blank" rel="noopener">Open GA4 Realtime &#x2197;</a></div>';

        // Task 17: append yesterday's pre-checkout-block count when SA JSON +
        // property ID are both configured. Calls go through the cached Data
        // API client; widget shows '--' when null (graceful degradation).
        if (class_exists('\FpsAnalyticsDataApi')
            && \FpsAnalyticsConfig::get('ga4_service_account_json') !== ''
            && \FpsAnalyticsConfig::get('ga4_property_id') !== ''
        ) {
            $ydayCount = \FpsAnalyticsDataApi::getYesterdayCount('fps_pre_checkout_block');
            $ydayLabel = $ydayCount === null ? '&mdash;' : (string) $ydayCount;
            $body  = (string) preg_replace(
                '#(Open GA4 Realtime &\#x2197;</a>)</div>#',
                '$1<div class="fps-text-muted" style="font-size:.8rem;margin-top:4px;">Yesterday: ' . $ydayLabel . ' pre-checkout blocks</div></div>',
                $body,
                1
            );
        }
        $body .= '  <div><strong>' . $dot($clientOn, $clarityStatus["ts"]) . ' Microsoft Clarity</strong>';
        $body .=      '<div class="fps-text-muted" style="font-size:.8rem;">Sessions tagged via fps_* properties &middot; '
                    . (int) $clarityStatus['count'] . ' in last 24h</div>';
        $body .=      '<a href="https://clarity.microsoft.com/" target="_blank" rel="noopener">Open Clarity Dashboard &#x2197;</a></div>';
        $body .= '</div>';

        echo FpsAdminRenderer::renderCard('Analytics Connection Status', 'fa-chart-line', $body);
    }

}

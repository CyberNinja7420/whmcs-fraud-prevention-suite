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
        $this->fpsRenderPerformanceMetrics($modulelink);
        $this->fpsRenderSystemHealthWidget($modulelink);
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
     * Performance Metrics card -- latency percentiles, throughput, block rate,
     * and provider hit-rate table with period selector (1h/24h/7d/30d).
     * All data loaded via AJAX (get_performance_metrics).
     */
    private function fpsRenderPerformanceMetrics(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $bodyHtml = <<<'HTML'
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
  <span class="fps-text-muted" style="font-size:0.82rem;">
    Aggregated check latency, throughput, and provider effectiveness for the selected period.
  </span>
  <div style="display:flex;gap:4px;" id="fps-perf-period-btns">
    <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" data-period="1h" onclick="fpsPerfLoad('1h')">1h</button>
    <button type="button" class="fps-btn fps-btn-xs fps-btn-primary" data-period="24h" onclick="fpsPerfLoad('24h')">24h</button>
    <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" data-period="7d" onclick="fpsPerfLoad('7d')">7d</button>
    <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" data-period="30d" onclick="fpsPerfLoad('30d')">30d</button>
  </div>
</div>

<!-- Top-line stats grid -->
<div id="fps-perf-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
  <div class="fps-skeleton-container"><div class="fps-skeleton-line" style="width:100%"></div></div>
</div>

<!-- Latency percentile bar -->
<div id="fps-perf-latency-bar" style="margin-bottom:16px;">
  <div class="fps-skeleton-container"><div class="fps-skeleton-line" style="width:100%"></div></div>
</div>

<!-- Provider hit rate table -->
<div id="fps-perf-provider-table" style="margin-bottom:8px;">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:90%"></div>
    <div class="fps-skeleton-line" style="width:85%"></div>
  </div>
</div>

<!-- Risk distribution bar -->
<div id="fps-perf-risk-dist"></div>
HTML;

        echo FpsAdminRenderer::renderCard('Performance Metrics', 'fa-gauge-high', $bodyHtml);

        // Inline JS: fetches metrics, renders stats, latency bar, provider table, risk dist.
        // All rendering uses DOM methods (createElement/textContent) for safety.
        // The single innerHTML use for the latency visual bar is built from numeric data only.
        echo '<script>';
        echo '(function() {';
        echo 'var perfUrl = ' . $jsAjaxUrl . ';';
        echo <<<'JSBLOCK'

function fpsPerfSafe(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function fpsPerfMiniStat(label, value, color) {
    var card = document.createElement('div');
    card.style.cssText = 'padding:10px 12px;border-radius:8px;border:1px solid rgba(102,126,234,0.12);background:rgba(255,255,255,0.02);text-align:center;';
    var valEl = document.createElement('div');
    valEl.style.cssText = 'font-size:1.25rem;font-weight:700;color:' + (color || 'var(--fps-text-primary,#e0e6f0)') + ';';
    valEl.textContent = value;
    var lblEl = document.createElement('div');
    lblEl.style.cssText = 'font-size:0.72rem;color:var(--fps-text-muted,#6a7195);text-transform:uppercase;letter-spacing:0.05em;margin-top:2px;';
    lblEl.textContent = label;
    card.appendChild(valEl);
    card.appendChild(lblEl);
    return card;
}

window.fpsPerfLoad = function(period) {
    // Update button states
    var btns = document.querySelectorAll('#fps-perf-period-btns button');
    btns.forEach(function(b) {
        if (b.getAttribute('data-period') === period) {
            b.className = 'fps-btn fps-btn-xs fps-btn-primary';
        } else {
            b.className = 'fps-btn fps-btn-xs fps-btn-outline';
        }
    });

    var statsGrid = document.getElementById('fps-perf-stats-grid');
    var latencyBar = document.getElementById('fps-perf-latency-bar');
    var providerTable = document.getElementById('fps-perf-provider-table');
    var riskDist = document.getElementById('fps-perf-risk-dist');

    // Show loading
    [statsGrid, latencyBar, providerTable].forEach(function(el) {
        if (!el) return;
        el.textContent = '';
        var sk = document.createElement('div');
        sk.className = 'fps-skeleton-container';
        var ln = document.createElement('div');
        ln.className = 'fps-skeleton-line';
        ln.style.width = '100%';
        sk.appendChild(ln);
        el.appendChild(sk);
    });

    fetch(perfUrl + '&a=get_performance_metrics&period=' + encodeURIComponent(period), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.success || !j.metrics) {
                statsGrid.textContent = j.error || 'Failed to load metrics';
                return;
            }
            var m = j.metrics;

            // --- Stats grid ---
            statsGrid.textContent = '';
            var items = [
                ['Total Checks', m.total_checks, '#667eea'],
                ['Blocked', m.blocked_count, '#ef4444'],
                ['Block Rate', m.block_rate + '%', m.block_rate > 20 ? '#ef4444' : '#f59e0b'],
                ['Checks/Hour', m.checks_per_hour, '#16a34a'],
                ['Avg Latency', m.avg_latency_ms + 'ms', m.avg_latency_ms > 2000 ? '#ef4444' : '#667eea'],
                ['P50', m.p50_latency_ms + 'ms', '#16a34a'],
                ['P95', m.p95_latency_ms + 'ms', m.p95_latency_ms > 2000 ? '#ef4444' : '#f59e0b'],
                ['P99', m.p99_latency_ms + 'ms', m.p99_latency_ms > 3000 ? '#ef4444' : '#f59e0b'],
            ];
            items.forEach(function(it) {
                statsGrid.appendChild(fpsPerfMiniStat(it[0], String(it[1]), it[2]));
            });

            // --- Latency percentile visual bar ---
            latencyBar.textContent = '';
            if (m.latency_samples > 0 && m.max_latency_ms > 0) {
                var barLabel = document.createElement('div');
                barLabel.style.cssText = 'font-size:0.78rem;font-weight:600;color:var(--fps-text-secondary,#a0a8c8);margin-bottom:6px;';
                barLabel.textContent = 'Latency Distribution (' + m.latency_samples + ' samples, max ' + m.max_latency_ms + 'ms)';
                latencyBar.appendChild(barLabel);

                var barOuter = document.createElement('div');
                barOuter.style.cssText = 'height:24px;border-radius:6px;overflow:hidden;display:flex;background:rgba(102,126,234,0.08);';

                var maxMs = m.max_latency_ms || 1;
                var segments = [
                    { width: m.p50_latency_ms, color: '#16a34a', label: 'P50' },
                    { width: m.p95_latency_ms - m.p50_latency_ms, color: '#f59e0b', label: 'P95' },
                    { width: m.p99_latency_ms - m.p95_latency_ms, color: '#f97316', label: 'P99' },
                    { width: maxMs - m.p99_latency_ms, color: '#ef4444', label: 'Max' },
                ];
                segments.forEach(function(seg) {
                    if (seg.width <= 0) return;
                    var pct = Math.max(1, (seg.width / maxMs) * 100);
                    var s = document.createElement('div');
                    s.style.cssText = 'width:' + pct + '%;background:' + seg.color + ';display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:#fff;font-weight:600;min-width:20px;';
                    s.textContent = seg.label;
                    s.title = seg.label + ': ' + seg.width + 'ms';
                    barOuter.appendChild(s);
                });
                latencyBar.appendChild(barOuter);
            } else {
                var noData = document.createElement('p');
                noData.className = 'fps-text-muted';
                noData.style.fontSize = '0.82rem';
                noData.textContent = 'No latency data in this period.';
                latencyBar.appendChild(noData);
            }

            // --- Provider hit rate table ---
            providerTable.textContent = '';
            var providers = m.provider_hits || {};
            var providerNames = Object.keys(providers);
            if (providerNames.length > 0) {
                var tblLabel = document.createElement('div');
                tblLabel.style.cssText = 'font-size:0.78rem;font-weight:600;color:var(--fps-text-secondary,#a0a8c8);margin-bottom:6px;';
                tblLabel.textContent = 'Provider Hit Rates';
                providerTable.appendChild(tblLabel);

                var tbl = document.createElement('table');
                tbl.className = 'fps-table';
                tbl.style.fontSize = '0.85rem';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                ['Provider', 'Checks', 'Flags', 'Flag Rate', 'Avg Score', 'Effectiveness'].forEach(function(h) {
                    var th = document.createElement('th');
                    th.textContent = h;
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                tbl.appendChild(thead);

                var tbody = document.createElement('tbody');
                // Sort by checks descending
                providerNames.sort(function(a, b) { return providers[b].checks - providers[a].checks; });
                providerNames.forEach(function(name) {
                    var p = providers[name];
                    var tr = document.createElement('tr');

                    var tdName = document.createElement('td');
                    tdName.style.fontWeight = '600';
                    tdName.textContent = name;
                    tr.appendChild(tdName);

                    var tdChecks = document.createElement('td');
                    tdChecks.textContent = p.checks;
                    tr.appendChild(tdChecks);

                    var tdFlags = document.createElement('td');
                    tdFlags.textContent = p.flags;
                    tdFlags.style.color = p.flags > 0 ? '#f59e0b' : 'inherit';
                    tr.appendChild(tdFlags);

                    var tdRate = document.createElement('td');
                    tdRate.textContent = p.flag_rate + '%';
                    tdRate.style.color = p.flag_rate > 50 ? '#ef4444' : (p.flag_rate > 20 ? '#f59e0b' : '#16a34a');
                    tr.appendChild(tdRate);

                    var tdAvg = document.createElement('td');
                    tdAvg.textContent = p.avg_score;
                    tr.appendChild(tdAvg);

                    // Effectiveness bar
                    var tdBar = document.createElement('td');
                    tdBar.style.cssText = 'min-width:100px;';
                    var barWrap = document.createElement('div');
                    barWrap.style.cssText = 'height:8px;border-radius:4px;background:rgba(102,126,234,0.1);overflow:hidden;';
                    var barFill = document.createElement('div');
                    var rate = Math.min(100, p.flag_rate);
                    barFill.style.cssText = 'height:100%;width:' + rate + '%;border-radius:4px;background:' + (rate > 50 ? '#ef4444' : (rate > 20 ? '#f59e0b' : '#16a34a')) + ';';
                    barWrap.appendChild(barFill);
                    tdBar.appendChild(barWrap);
                    tr.appendChild(tdBar);

                    tbody.appendChild(tr);
                });
                tbl.appendChild(tbody);
                providerTable.appendChild(tbl);
            } else {
                var noProviders = document.createElement('p');
                noProviders.className = 'fps-text-muted';
                noProviders.style.fontSize = '0.82rem';
                noProviders.textContent = 'No provider score data in this period.';
                providerTable.appendChild(noProviders);
            }

            // --- Risk distribution mini-bars ---
            riskDist.textContent = '';
            var rd = m.risk_distribution || {};
            var rdKeys = Object.keys(rd);
            if (rdKeys.length > 0 && m.total_checks > 0) {
                var rdLabel = document.createElement('div');
                rdLabel.style.cssText = 'font-size:0.78rem;font-weight:600;color:var(--fps-text-secondary,#a0a8c8);margin-bottom:6px;margin-top:8px;';
                rdLabel.textContent = 'Risk Level Distribution';
                riskDist.appendChild(rdLabel);

                var rdBar = document.createElement('div');
                rdBar.style.cssText = 'height:20px;border-radius:6px;overflow:hidden;display:flex;';
                var colorMap = { low: '#16a34a', medium: '#f59e0b', high: '#f97316', critical: '#ef4444' };
                var order = ['low', 'medium', 'high', 'critical'];
                // Add any unknown levels
                rdKeys.forEach(function(k) { if (order.indexOf(k) === -1) order.push(k); });
                order.forEach(function(level) {
                    var cnt = rd[level] || 0;
                    if (cnt <= 0) return;
                    var pct = Math.max(1, (cnt / m.total_checks) * 100);
                    var seg = document.createElement('div');
                    seg.style.cssText = 'width:' + pct + '%;background:' + (colorMap[level] || '#6a7195') + ';display:flex;align-items:center;justify-content:center;font-size:0.62rem;color:#fff;font-weight:600;';
                    seg.textContent = level.toUpperCase() + ' ' + cnt;
                    seg.title = level + ': ' + cnt + ' (' + Math.round(pct) + '%)';
                    rdBar.appendChild(seg);
                });
                riskDist.appendChild(rdBar);
            }
        })
        .catch(function() {
            statsGrid.textContent = '';
            var errP = document.createElement('p');
            errP.className = 'fps-text-muted';
            errP.textContent = 'Network error loading performance metrics';
            statsGrid.appendChild(errP);
        });
};

document.addEventListener('DOMContentLoaded', function() {
    fpsPerfLoad('24h');
});
JSBLOCK;
        echo '})();';
        echo '</script>';
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
     * System Health widget -- condensed selftest status with "Run Full Selftest" button.
     *
     * Shows a compact grid of pass/warn/fail counts and a button that
     * triggers the run_selftest AJAX action. Results are displayed inline.
     */
    private function fpsRenderSystemHealthWidget(string $modulelink): void
    {
        $jsAjaxUrl = json_encode($modulelink . '&ajax=1');

        $bodyHtml = <<<HTML
<div id="fps-health-summary" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px;">
  <div style="text-align:center;padding:12px;border-radius:8px;background:rgba(56,239,125,0.08);border:1px solid rgba(56,239,125,0.2);">
    <div id="fps-health-pass" style="font-size:1.6rem;font-weight:700;color:#16a34a;">--</div>
    <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--fps-text-muted);">Pass</div>
  </div>
  <div style="text-align:center;padding:12px;border-radius:8px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);">
    <div id="fps-health-warn" style="font-size:1.6rem;font-weight:700;color:#f59e0b;">--</div>
    <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--fps-text-muted);">Warn</div>
  </div>
  <div style="text-align:center;padding:12px;border-radius:8px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);">
    <div id="fps-health-fail" style="font-size:1.6rem;font-weight:700;color:#ef4444;">--</div>
    <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--fps-text-muted);">Fail</div>
  </div>
</div>
<div id="fps-health-details" style="display:none;max-height:300px;overflow-y:auto;margin-bottom:12px;border:1px solid var(--fps-border,#dde1ef);border-radius:8px;"></div>
<div id="fps-health-timestamp" style="font-size:0.72rem;color:var(--fps-text-muted);margin-bottom:10px;"></div>
<button type="button" id="fps-health-run-btn"
  style="padding:8px 20px;border:none;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
  onclick="FpsAdmin.runSelftest()">
  <i class="fas fa-stethoscope"></i> Run Full Selftest
</button>
<a href="/modules/addons/fraud_prevention_suite/selftest.php" target="_blank"
  style="margin-left:10px;font-size:0.78rem;color:#667eea;text-decoration:underline;">
  Open selftest.php (JSON)
</a>
HTML;

        echo FpsAdminRenderer::renderCard(
            'System Health',
            'fa-heartbeat',
            $bodyHtml
        );

        // JavaScript to handle the selftest AJAX call and render results.
        // Note: The detail table is built from trusted server-side selftest
        // data (admin-only endpoint) -- all values originate from our own
        // PHP checks, not user input. The name/detail fields contain only
        // internal identifiers like table names and HTTP status codes.
        echo <<<HTML
<script>
(function() {
    if (typeof FpsAdmin === 'undefined') window.FpsAdmin = {};

    FpsAdmin.runSelftest = function() {
        var btn = document.getElementById('fps-health-run-btn');
        btn.disabled = true;
        btn.textContent = 'Running...';

        var url = {$jsAjaxUrl} + '&a=run_selftest';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(document.getElementById('fps-csrf-token') ? document.getElementById('fps-csrf-token').value : '')
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Run Full Selftest';

            if (!data.success && data.error) {
                document.getElementById('fps-health-fail').textContent = '!';
                document.getElementById('fps-health-timestamp').textContent = 'Error: ' + data.error;
                return;
            }

            var s = data.summary || {};
            document.getElementById('fps-health-pass').textContent = s.pass || 0;
            document.getElementById('fps-health-warn').textContent = s.warn || 0;
            document.getElementById('fps-health-fail').textContent = s.fail || 0;
            document.getElementById('fps-health-timestamp').textContent = 'Last run: ' + (data.timestamp || 'unknown') + ' | v' + (data.version || '?');

            // Render detail table using DOM methods (data is from trusted admin-only endpoint)
            var checks = data.checks || [];
            if (checks.length > 0) {
                var detailEl = document.getElementById('fps-health-details');
                detailEl.textContent = '';

                var table = document.createElement('table');
                table.style.cssText = 'width:100%;font-size:0.8rem;border-collapse:collapse;';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                headRow.style.background = 'var(--fps-surface-2,#f8f9fc)';
                ['Check', 'Status', 'Detail'].forEach(function(label, idx) {
                    var th = document.createElement('th');
                    th.textContent = label;
                    th.style.cssText = 'padding:6px 10px;text-align:' + (idx === 1 ? 'center' : 'left') + ';' + (idx === 1 ? 'width:60px;' : '');
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                for (var i = 0; i < checks.length; i++) {
                    var c = checks[i];
                    var color = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#f59e0b' : '#ef4444');
                    var iconClass = c.status === 'pass' ? 'fa-check-circle' : (c.status === 'warn' ? 'fa-exclamation-triangle' : 'fa-times-circle');

                    var row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--fps-border,#eee)';

                    var tdName = document.createElement('td');
                    tdName.style.padding = '5px 10px';
                    tdName.textContent = c.name || '';
                    row.appendChild(tdName);

                    var tdStatus = document.createElement('td');
                    tdStatus.style.cssText = 'padding:5px 10px;text-align:center;color:' + color + ';';
                    var icon = document.createElement('i');
                    icon.className = 'fas ' + iconClass;
                    tdStatus.appendChild(icon);
                    row.appendChild(tdStatus);

                    var tdDetail = document.createElement('td');
                    tdDetail.style.cssText = 'padding:5px 10px;color:var(--fps-text-secondary);';
                    tdDetail.textContent = c.detail || '';
                    row.appendChild(tdDetail);

                    tbody.appendChild(row);
                }
                table.appendChild(tbody);
                detailEl.appendChild(table);
                detailEl.style.display = 'block';
            }
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = 'Run Full Selftest';
            document.getElementById('fps-health-timestamp').textContent = 'Error: ' + e.message;
        });
    };
})();
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
}

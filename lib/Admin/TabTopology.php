<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabTopology -- admin globe visualization with live event feed.
 *
 * Renders a full-width WebGL globe (globe.gl) with fraud events,
 * a live events sidebar, and animated stat counters.
 * Three.js and globe.gl are loaded via CDN only on this tab.
 */
class TabTopology
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderControlsBar($ajaxUrl);
        $this->fpsRenderGlobeLayout($ajaxUrl);
        $this->fpsRenderCdnScripts($ajaxUrl);
    }

    /**
     * Controls bar with time range, auto-refresh toggle, and fullscreen button.
     */
    private function fpsRenderControlsBar(string $ajaxUrl): void
    {
        // Pulse animation for LIVE dot
        echo '<style>@keyframes fps-pulse{0%,100%{opacity:1;box-shadow:0 0 6px currentColor;}50%{opacity:0.5;box-shadow:0 0 12px currentColor;}}</style>';

        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;margin-bottom:12px;background:rgba(10,10,26,0.6);border:1px solid rgba(102,126,234,0.1);border-radius:10px;flex-wrap:wrap;gap:10px;">';

        // Time range buttons -- default active is 24h
        $currentRange = $_GET['topo_range'] ?? '24h';
        echo '<div class="fps-quick-range-btns">';
        $ranges = [
            '1h'  => '1 Hour',
            '6h'  => '6 Hours',
            '24h' => '24 Hours',
            '7d'  => '7 Days',
            '30d' => '30 Days',
            'all' => 'All Time',
        ];
        foreach ($ranges as $val => $label) {
            // Use fps-filter-active for the initially-selected range;
            // JS will toggle this same class on click (no duplicate class bug).
            $isActive  = ($val === $currentRange);
            $activeCls = $isActive ? ' fps-filter-active' : '';
            $safeVal   = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            echo '<button type="button" class="fps-btn fps-btn-sm fps-btn-outline fps-topo-range-btn' . $activeCls . '" '
                . 'data-range="' . $safeVal . '" '
                . 'onclick="FpsAdmin.setTopologyRange(\'' . $safeVal . '\', \'' . $ajaxUrl . '\', this)">'
                . $safeLabel . '</button>';
        }
        echo '</div>';

        // Right side controls
        echo '<div class="fps-topology-right-controls">';

        // Auto-refresh toggle
        echo '<label class="fps-toggle-label">';
        echo '  <span><i class="fas fa-sync-alt"></i> Auto-refresh</span>';
        echo FpsAdminRenderer::renderToggle('fps-topo-autorefresh', true, "FpsAdmin.toggleTopologyAutoRefresh(this.checked)");
        echo '</label>';

        // Fullscreen button
        echo '<button type="button" class="fps-btn fps-btn-sm fps-btn-outline" '
            . 'onclick="FpsAdmin.toggleTopologyFullscreen()" title="Toggle Fullscreen">'
            . '<i class="fas fa-expand"></i></button>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Globe container with live events sidebar and stats overlay.
     */
    private function fpsRenderGlobeLayout(string $ajaxUrl): void
    {
        // Fetch initial stats for the overlay
        $eventCount   = 0;
        $countryCount = 0;
        $threatCount  = 0;
        $blockRate    = 0;

        try {
            $eventCount   = Capsule::table('mod_fps_geo_events')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count();
            $countryCount = Capsule::table('mod_fps_geo_events')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->distinct()
                ->count('country_code');
            $threatCount  = Capsule::table('mod_fps_geo_events')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->whereIn('risk_level', ['high', 'critical'])
                ->count();

            $totalToday  = Capsule::table('mod_fps_stats')->where('date', date('Y-m-d'))->value('checks_total') ?? 0;
            $blockedToday = Capsule::table('mod_fps_stats')->where('date', date('Y-m-d'))->value('checks_blocked') ?? 0;
            $blockRate = $totalToday > 0 ? round(($blockedToday / $totalToday) * 100, 1) : 0;
        } catch (\Throwable $e) {
            // Non-fatal
        }

        echo '<div id="fps-topology-layout" style="display:flex;gap:0;min-height:600px;border-radius:12px;overflow:hidden;border:1px solid rgba(102,126,234,0.12);background:#080818;">';

        // Globe container
        echo '<div style="flex:1;position:relative;min-height:500px;background:radial-gradient(ellipse at 50% 60%,#0a0a2e 0%,#050515 100%);">';
        echo '  <div id="fps-admin-globe" style="width:100%;height:100%;min-height:500px;"></div>';

        // Stats overlay - bottom bar inside globe
        echo '  <div style="position:absolute;bottom:0;left:0;right:0;display:grid;grid-template-columns:repeat(4,1fr);background:rgba(10,10,26,0.92);backdrop-filter:blur(8px);border-top:1px solid rgba(102,126,234,0.1);">';

        $stats = [
            ['id' => 'fps-topo-events', 'value' => (int)$eventCount, 'label' => 'Events Tracked', 'color' => '#00d4ff'],
            ['id' => 'fps-topo-countries', 'value' => (int)$countryCount, 'label' => 'Active Countries', 'color' => '#38ef7d'],
            ['id' => 'fps-topo-threats', 'value' => (int)$threatCount, 'label' => 'Active Threats', 'color' => '#f5576c'],
            ['id' => 'fps-topo-blockrate', 'value' => $blockRate . '%', 'label' => 'Block Rate', 'color' => '#ffd700'],
        ];
        foreach ($stats as $i => $s) {
            $borderRight = $i < 3 ? 'border-right:1px solid rgba(102,126,234,0.08);' : '';
            echo '<div style="padding:12px 16px;text-align:center;' . $borderRight . '">';
            echo '  <div id="' . $s['id'] . '" style="font-size:1.4rem;font-weight:800;font-family:monospace;color:' . $s['color'] . ';letter-spacing:-0.02em;line-height:1;">' . $s['value'] . '</div>';
            echo '  <div style="font-size:0.62rem;color:#4a5080;text-transform:uppercase;letter-spacing:0.1em;margin-top:3px;">' . $s['label'] . '</div>';
            echo '</div>';
        }

        echo '  </div>'; // stats bar
        echo '</div>'; // globe wrapper

        // Live events sidebar
        echo '<div style="width:340px;background:rgba(10,10,26,0.95);border-left:1px solid rgba(102,126,234,0.15);display:flex;flex-direction:column;flex-shrink:0;">';

        // Sidebar header
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid rgba(102,126,234,0.12);background:rgba(102,126,234,0.04);">';
        echo '  <span style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#667eea;display:flex;align-items:center;gap:8px;">';
        echo '    <i class="fas fa-bolt" style="font-size:0.9rem;"></i> Live Threat Feed</span>';
        echo '  <span style="display:flex;align-items:center;gap:5px;font-size:0.68rem;font-weight:700;letter-spacing:0.08em;color:#38ef7d;">';
        echo '    <span style="width:7px;height:7px;border-radius:50%;background:#38ef7d;box-shadow:0 0 6px #38ef7d;animation:fps-pulse 1.5s ease-in-out infinite;display:inline-block;"></span> LIVE</span>';
        echo '</div>';

        echo '<div id="fps-event-feed" style="flex:1;overflow-y:auto;scroll-behavior:smooth;">';

        try {
            $recentEvents = Capsule::table('mod_fps_geo_events')
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
                ->toArray();

            foreach ($recentEvents as $event) {
                $this->fpsRenderEventItem($event);
            }

            if (empty($recentEvents)) {
                echo '<div style="padding:3rem 1.5rem;text-align:center;color:#4a5080;">';
                echo '  <i class="fas fa-satellite-dish" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:0.75rem;"></i>';
                echo '  <div style="font-size:0.82rem;">Monitoring active</div>';
                echo '  <div style="font-size:0.72rem;opacity:0.6;margin-top:4px;">Events will appear as they occur</div>';
                echo '</div>';
            }
        } catch (\Throwable $e) {
            echo '<div style="padding:2rem;text-align:center;color:#4a5080;font-size:0.82rem;"><i class="fas fa-info-circle"></i> Unable to load events.</div>';
        }

        echo '</div>';

        // Sidebar footer with event count
        $eventTotal = count($recentEvents ?? []);
        echo '<div style="padding:10px 18px;border-top:1px solid rgba(102,126,234,0.1);font-size:0.7rem;color:#4a5080;display:flex;justify-content:space-between;">';
        echo '  <span>' . $eventTotal . ' events loaded</span>';
        echo '  <span>Auto-refresh: 60s</span>';
        echo '</div>';

        echo '</div>';

        echo '</div>';
    }

    /**
     * Render a single event item in the sidebar feed.
     */
    private function fpsRenderEventItem(object $event): void
    {
        $level = $event->risk_level ?? 'low';
        $colors = [
            'critical' => ['border' => '#eb3349', 'bg' => 'rgba(235,51,73,0.08)', 'icon' => 'fa-skull-crossbones', 'label' => 'CRITICAL'],
            'high'     => ['border' => '#f5576c', 'bg' => 'rgba(245,87,108,0.06)', 'icon' => 'fa-circle-exclamation', 'label' => 'HIGH'],
            'medium'   => ['border' => '#f5c842', 'bg' => 'rgba(245,200,66,0.05)', 'icon' => 'fa-triangle-exclamation', 'label' => 'MEDIUM'],
            'low'      => ['border' => '#38ef7d', 'bg' => 'rgba(56,239,125,0.04)', 'icon' => 'fa-circle-check', 'label' => 'LOW'],
        ];
        $c = $colors[$level] ?? $colors['low'];

        $country = htmlspecialchars($event->country_code ?? '--', ENT_QUOTES, 'UTF-8');
        $type    = htmlspecialchars(str_replace('_', ' ', $event->event_type ?? 'check'), ENT_QUOTES, 'UTF-8');
        $score   = (int)($event->risk_score ?? 0);
        $ip      = htmlspecialchars($event->ip_address ?? '', ENT_QUOTES, 'UTF-8');

        // Format time nicely
        $rawTime = $event->created_at ?? '';
        $timeDisplay = $rawTime;
        if ($rawTime) {
            try { $timeDisplay = date('H:i:s', strtotime($rawTime)); } catch (\Throwable $e) {}
        }

        echo '<div style="display:flex;gap:10px;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.03);border-left:3px solid ' . $c['border'] . ';background:' . $c['bg'] . ';cursor:default;transition:background 0.15s;" onmouseover="this.style.background=\'rgba(102,126,234,0.06)\'" onmouseout="this.style.background=\'' . $c['bg'] . '\'">';

        // Left: icon
        echo '<div style="width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px;background:rgba(255,255,255,0.04);border:1px solid ' . $c['border'] . '33;color:' . $c['border'] . ';">';
        echo '<i class="fas ' . $c['icon'] . '"></i></div>';

        // Middle: content
        echo '<div style="flex:1;min-width:0;">';

        // Row 1: country + type
        echo '<div style="display:flex;align-items:center;gap:6px;font-size:0.82rem;">';
        echo '  <span style="font-weight:700;color:#e0e6f0;">' . $country . '</span>';
        echo '  <span style="color:#4a5080;">-</span>';
        echo '  <span style="color:#8892b0;text-transform:capitalize;">' . $type . '</span>';
        echo '</div>';

        // Row 2: score + IP
        echo '<div style="display:flex;align-items:center;gap:6px;margin-top:3px;">';
        echo '  <span style="font-size:0.7rem;font-weight:700;padding:1px 7px;border-radius:9999px;background:' . $c['border'] . '1a;color:' . $c['border'] . ';border:1px solid ' . $c['border'] . '33;">' . $c['label'] . ' ' . $score . '</span>';
        if ($ip) {
            echo '<span style="font-size:0.68rem;color:#4a5080;font-family:monospace;">' . $ip . '</span>';
        }
        echo '</div>';

        echo '</div>';

        // Right: time
        echo '<div style="font-size:0.68rem;color:#4a5080;font-family:monospace;white-space:nowrap;flex-shrink:0;margin-top:2px;">' . htmlspecialchars($timeDisplay, ENT_QUOTES, 'UTF-8') . '</div>';

        echo '</div>';
    }

    /**
     * CDN script tags for Three.js and globe.gl (loaded only on this tab).
     */
    private function fpsRenderCdnScripts(string $ajaxUrl): void
    {
        $assetsUrl = '../modules/addons/fraud_prevention_suite/assets';
        // Use unescaped URL for <script> context
        $rawUrl = str_replace('&amp;', '&', $ajaxUrl);
        $jsAjaxUrl = json_encode($rawUrl);

        echo <<<HTML
<script src="https://unpkg.com/three@0.160.0/build/three.min.js"></script>
<script src="https://unpkg.com/globe.gl@2.30.0/dist/globe.gl.min.js"></script>
<script src="{$assetsUrl}/js/fps-topology.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof FpsTopology !== 'undefined') {
        FpsTopology.initAdminGlobe('fps-admin-globe', {$jsAjaxUrl}, false);
    }
});
</script>
HTML;
    }
}

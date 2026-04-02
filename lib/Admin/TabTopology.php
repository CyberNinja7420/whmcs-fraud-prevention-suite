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
        echo '<div class="fps-topology-controls">';

        // Time range buttons
        echo '<div class="fps-quick-range-btns">';
        $ranges = [
            '1h'  => '1 Hour',
            '6h'  => '6 Hours',
            '24h' => '24 Hours',
            '7d'  => '7 Days',
            '30d' => '30 Days',
        ];
        foreach ($ranges as $val => $label) {
            $active = $val === '24h' ? ' fps-filter-active fps-topo-range-btn' : ' fps-topo-range-btn';
            $safeVal   = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            echo '<button type="button" class="fps-btn fps-btn-sm fps-btn-outline fps-topo-range-btn' . $active . '" '
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

        echo '<div class="fps-topology-layout" id="fps-topology-layout">';

        // Globe container
        echo '<div class="fps-globe-wrapper">';
        echo '  <div id="fps-admin-globe" class="fps-globe-container"></div>';

        // Stats overlay
        echo '  <div class="fps-topology-stats-overlay">';
        echo '    <div class="fps-topo-stat">';
        echo '      <span class="fps-topo-stat-value" id="fps-topo-events" data-countup="' . (int)$eventCount . '">' . (int)$eventCount . '</span>';
        echo '      <span class="fps-topo-stat-label">Total Events</span>';
        echo '    </div>';
        echo '    <div class="fps-topo-stat">';
        echo '      <span class="fps-topo-stat-value" id="fps-topo-countries" data-countup="' . (int)$countryCount . '">' . (int)$countryCount . '</span>';
        echo '      <span class="fps-topo-stat-label">Countries</span>';
        echo '    </div>';
        echo '    <div class="fps-topo-stat">';
        echo '      <span class="fps-topo-stat-value fps-text-danger" id="fps-topo-threats" data-countup="' . (int)$threatCount . '">' . (int)$threatCount . '</span>';
        echo '      <span class="fps-topo-stat-label">Active Threats</span>';
        echo '    </div>';
        echo '    <div class="fps-topo-stat">';
        echo '      <span class="fps-topo-stat-value" id="fps-topo-blockrate" data-countup="' . $blockRate . '">' . $blockRate . '%</span>';
        echo '      <span class="fps-topo-stat-label">Block Rate</span>';
        echo '    </div>';
        echo '  </div>';

        echo '</div>';

        // Live events sidebar
        echo '<div class="fps-topology-sidebar">';
        echo '  <div class="fps-sidebar-header">';
        echo '    <h4><i class="fas fa-bolt"></i> Live Events</h4>';
        echo '    <span class="fps-live-indicator"><span class="fps-live-dot"></span> LIVE</span>';
        echo '  </div>';
        echo '  <div class="fps-event-feed" id="fps-event-feed">';

        // Render recent events
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
                echo '<p class="fps-text-muted fps-text-center"><i class="fas fa-satellite-dish"></i> Waiting for events...</p>';
            }
        } catch (\Throwable $e) {
            echo '<p class="fps-text-muted"><i class="fas fa-info-circle"></i> Unable to load events.</p>';
        }

        echo '  </div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render a single event item in the sidebar feed.
     */
    private function fpsRenderEventItem(object $event): void
    {
        $levelClass = match ($event->risk_level ?? 'low') {
            'critical' => 'fps-event-critical',
            'high'     => 'fps-event-high',
            'medium'   => 'fps-event-medium',
            default    => 'fps-event-low',
        };

        $country = htmlspecialchars($event->country_code ?? '--', ENT_QUOTES, 'UTF-8');
        $type    = htmlspecialchars($event->event_type ?? 'check', ENT_QUOTES, 'UTF-8');
        $score   = number_format((float)($event->risk_score ?? 0), 1);
        $time    = htmlspecialchars($event->created_at ?? '', ENT_QUOTES, 'UTF-8');
        $badge   = FpsAdminRenderer::renderBadge($event->risk_level ?? 'low', (float)($event->risk_score ?? 0));

        echo '<div class="fps-event-item ' . $levelClass . '">';
        echo '  <div class="fps-event-header">';
        echo '    <span class="fps-event-country"><i class="fas fa-flag"></i> ' . $country . '</span>';
        echo '    ' . $badge;
        echo '  </div>';
        echo '  <div class="fps-event-meta">';
        echo '    <span><i class="fas fa-tag"></i> ' . $type . '</span>';
        echo '    <span class="fps-event-time"><i class="fas fa-clock"></i> ' . $time . '</span>';
        echo '  </div>';
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

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabStatistics -- full analytics with ApexCharts.
 *
 * Renders a date range selector, 6 chart containers (2x3 grid),
 * and a summary stats table. Charts initialize via FpsCharts.* JS
 * functions and data is loaded via AJAX get_chart_data.
 */
class TabStatistics
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderDateRangeSelector($ajaxUrl);
        $this->fpsRenderChartGrid($ajaxUrl);
        $this->fpsRenderSummaryTable();
    }

    /**
     * Date range selector with quick buttons and custom date picker.
     */
    private function fpsRenderDateRangeSelector(string $ajaxUrl): void
    {
        $content = <<<HTML
<div class="fps-date-range-bar">
  <div class="fps-quick-range-btns">
    <button type="button" class="fps-btn fps-btn-sm fps-btn-outline active" data-days="7"
      onclick="FpsAdmin.setChartRange(7, '{$ajaxUrl}', this)">
      <i class="fas fa-calendar-day"></i> 7 Days
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-outline" data-days="30"
      onclick="FpsAdmin.setChartRange(30, '{$ajaxUrl}', this)">
      <i class="fas fa-calendar-week"></i> 30 Days
    </button>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-outline" data-days="90"
      onclick="FpsAdmin.setChartRange(90, '{$ajaxUrl}', this)">
      <i class="fas fa-calendar"></i> 90 Days
    </button>
  </div>
  <div class="fps-custom-range">
    <div class="fps-form-group fps-form-inline">
      <label><i class="fas fa-calendar-minus"></i></label>
      <input type="date" id="fps-chart-date-from" class="fps-input fps-input-sm">
    </div>
    <span class="fps-text-muted">to</span>
    <div class="fps-form-group fps-form-inline">
      <label><i class="fas fa-calendar-plus"></i></label>
      <input type="date" id="fps-chart-date-to" class="fps-input fps-input-sm">
    </div>
    <button type="button" class="fps-btn fps-btn-sm fps-btn-primary"
      onclick="FpsAdmin.loadCustomChartRange('{$ajaxUrl}')">
      <i class="fas fa-chart-line"></i> Apply
    </button>
  </div>
</div>
HTML;

        echo '<div class="fps-card fps-card-compact">';
        echo '<div class="fps-card-body">' . $content . '</div>';
        echo '</div>';
    }

    /**
     * 2x3 chart grid with ApexCharts containers.
     */
    private function fpsRenderChartGrid(string $ajaxUrl): void
    {
        $charts = [
            [
                'id'    => 'fps-chart-daily-trends',
                'title' => 'Daily Trends',
                'icon'  => 'fa-chart-line',
                'desc'  => 'Checks, flagged, and blocked over time',
            ],
            [
                'id'    => 'fps-chart-risk-distribution',
                'title' => 'Risk Distribution',
                'icon'  => 'fa-chart-pie',
                'desc'  => 'Breakdown by risk level',
            ],
            [
                'id'    => 'fps-chart-provider-accuracy',
                'title' => 'Provider Accuracy',
                'icon'  => 'fa-chart-bar',
                'desc'  => 'Which providers flag the most',
            ],
            [
                'id'    => 'fps-chart-country-breakdown',
                'title' => 'Country Breakdown',
                'icon'  => 'fa-earth-americas',
                'desc'  => 'Fraud distribution by country',
            ],
            [
                'id'    => 'fps-chart-hourly-activity',
                'title' => 'Hourly Activity',
                'icon'  => 'fa-clock',
                'desc'  => 'Checks per hour of day',
            ],
            [
                'id'    => 'fps-chart-score-histogram',
                'title' => 'Risk Score Histogram',
                'icon'  => 'fa-chart-column',
                'desc'  => 'Score distribution 0-100',
            ],
        ];

        echo '<div class="fps-chart-grid">';

        foreach ($charts as $chart) {
            $safeId    = htmlspecialchars($chart['id'], ENT_QUOTES, 'UTF-8');
            $safeTitle = htmlspecialchars($chart['title'], ENT_QUOTES, 'UTF-8');
            $safeDesc  = htmlspecialchars($chart['desc'], ENT_QUOTES, 'UTF-8');

            echo '<div class="fps-card fps-chart-card">';
            echo '  <div class="fps-card-header fps-card-header-gradient">';
            echo '    <h3><i class="fas ' . $chart['icon'] . '"></i> ' . $safeTitle . '</h3>';
            echo '    <span class="fps-text-muted fps-chart-desc">' . $safeDesc . '</span>';
            echo '  </div>';
            echo '  <div class="fps-card-body">';
            echo '    <div id="' . $safeId . '" class="fps-apex-chart" style="min-height:280px;">';
            echo '      <div class="fps-skeleton-container">';
            echo '        <div class="fps-skeleton-line" style="width:100%;height:200px;"></div>';
            echo '      </div>';
            echo '    </div>';
            echo '  </div>';
            echo '</div>';
        }

        echo '</div>';

        // Initialize charts via JS -- use json_encode for <script> context
        $jsAjaxUrl = json_encode(html_entity_decode($ajaxUrl, ENT_QUOTES, 'UTF-8'));
        echo <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof FpsCharts !== 'undefined' && FpsCharts.initAll) {
        FpsCharts.initAll({$jsAjaxUrl}, 7);
    }
    // Wire up FpsAdmin.setChartRange to delegate to FpsCharts
    if (typeof FpsAdmin !== 'undefined') {
        FpsAdmin.setChartRange = function(days, ajaxUrl, btn) {
            FpsCharts.setChartRange(days, ajaxUrl, btn);
        };
        FpsAdmin.loadCustomChartRange = function(ajaxUrl) {
            var from = document.getElementById('fps-chart-date-from');
            var to = document.getElementById('fps-chart-date-to');
            if (from && to && from.value && to.value) {
                var diffMs = new Date(to.value) - new Date(from.value);
                var days = Math.max(1, Math.ceil(diffMs / 86400000));
                FpsCharts.destroyAll();
                FpsCharts.initAll(ajaxUrl, days);
            }
        };
    }
});
</script>
HTML;
    }

    /**
     * Summary stats table comparing periods.
     */
    private function fpsRenderSummaryTable(): void
    {
        $today    = date('Y-m-d');
        $weekAgo  = date('Y-m-d', strtotime('-7 days'));
        $monthAgo = date('Y-m-d', strtotime('-30 days'));

        $periods = [
            '7 Days'  => ['from' => $weekAgo,  'to' => $today],
            '30 Days' => ['from' => $monthAgo, 'to' => $today],
        ];

        $headers = ['Metric', '7 Days', '30 Days'];
        $rows    = [];

        $data = [];
        foreach ($periods as $label => $range) {
            try {
                $aggRow = Capsule::table('mod_fps_stats')
                    ->where('date', '>=', $range['from'])
                    ->where('date', '<=', $range['to'])
                    ->selectRaw('
                        COALESCE(SUM(checks_total), 0) as total_checks,
                        COALESCE(SUM(checks_flagged), 0) as total_flagged,
                        COALESCE(SUM(checks_blocked), 0) as total_blocked,
                        COALESCE(SUM(pre_checkout_blocks), 0) as total_pre_blocks,
                        COALESCE(SUM(api_requests), 0) as total_api,
                        COALESCE(AVG(avg_risk_score), 0) as period_avg_score
                    ')
                    ->first();
                $data[$label] = $aggRow;
            } catch (\Throwable $e) {
                $data[$label] = null;
            }
        }

        $metrics = [
            'total_checks'     => 'Total Checks',
            'total_flagged'    => 'Total Flagged',
            'total_blocked'    => 'Total Blocked',
            'total_pre_blocks' => 'Pre-Checkout Blocks',
            'total_api'        => 'API Requests',
            'period_avg_score' => 'Avg Risk Score',
        ];

        foreach ($metrics as $field => $label) {
            $row = [htmlspecialchars($label, ENT_QUOTES, 'UTF-8')];
            foreach (['7 Days', '30 Days'] as $period) {
                $val = $data[$period] ? (float)($data[$period]->$field ?? 0) : 0;
                if ($field === 'period_avg_score') {
                    $row[] = number_format($val, 1);
                } else {
                    $row[] = number_format($val, 0);
                }
            }
            $rows[] = $row;
        }

        // Block rate row
        $blockRateRow = ['Block Rate'];
        foreach (['7 Days', '30 Days'] as $period) {
            $total   = $data[$period] ? (int)($data[$period]->total_checks ?? 0) : 0;
            $blocked = $data[$period] ? (int)($data[$period]->total_blocked ?? 0) : 0;
            $rate    = $total > 0 ? round(($blocked / $total) * 100, 1) : 0;
            $blockRateRow[] = $rate . '%';
        }
        $rows[] = $blockRateRow;

        echo FpsAdminRenderer::renderCard(
            'Period Summary',
            'fa-table',
            FpsAdminRenderer::renderTable($headers, $rows, 'fps-summary-stats-table')
        );
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsPdfReport -- generates print-optimized HTML fraud reports and sends
 * them on schedule (weekly on Mondays, monthly on the 1st).
 *
 * Since WHMCS cannot require external PDF libraries (wkhtmltopdf, TCPDF)
 * without Composer, this class produces a self-contained HTML document
 * with inline CSS that is:
 *   - Directly printable via browser "Save as PDF"
 *   - Attachable as an .html file in scheduled emails
 *   - Renderable inline in the admin panel for preview
 *
 * All methods use the fps_ prefix per module convention.
 */
class FpsPdfReport
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    /**
     * Generate a full HTML report for the given period.
     *
     * @param string $period  Period shorthand: '7d', '30d', '90d', or 'custom'
     * @param string $startOverride  ISO date for custom start (only if $period === 'custom')
     * @param string $endOverride    ISO date for custom end (only if $period === 'custom')
     * @return string  Complete HTML document with inline CSS and @media print styles
     */
    public function fps_generateReport(string $period = '30d', string $startOverride = '', string $endOverride = ''): string
    {
        try {
            $endDate = date('Y-m-d H:i:s');

            switch ($period) {
                case '7d':
                    $startDate = date('Y-m-d H:i:s', strtotime('-7 days'));
                    $periodLabel = 'Last 7 Days';
                    break;
                case '90d':
                    $startDate = date('Y-m-d H:i:s', strtotime('-90 days'));
                    $periodLabel = 'Last 90 Days';
                    break;
                case 'custom':
                    $startDate = $startOverride !== '' ? $startOverride . ' 00:00:00' : date('Y-m-d H:i:s', strtotime('-30 days'));
                    $endDate = $endOverride !== '' ? $endOverride . ' 23:59:59' : date('Y-m-d H:i:s');
                    $periodLabel = date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate));
                    break;
                default: // 30d
                    $startDate = date('Y-m-d H:i:s', strtotime('-30 days'));
                    $periodLabel = 'Last 30 Days';
                    break;
            }

            $data = $this->fps_getReportData($startDate, $endDate);
            $recommendations = $this->fps_generateRecommendations($data);
            $dailyTrend = $this->fps_buildDailyTrend($startDate, $endDate);

            return $this->fps_renderHtml($data, $periodLabel, $recommendations, $dailyTrend);
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_generateReport', $period, $e->getMessage());
            return '<html><body><h1>Report Generation Failed</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        }
    }

    /**
     * Collect all statistics for the report period.
     *
     * @param string $startDate  ISO datetime start
     * @param string $endDate    ISO datetime end
     * @return array{
     *   total_checks: int,
     *   blocked_count: int,
     *   block_rate: float,
     *   avg_score: float,
     *   low_count: int,
     *   medium_count: int,
     *   high_count: int,
     *   critical_count: int,
     *   top_blocked_ips: array,
     *   top_blocked_emails: array,
     *   provider_breakdown: array,
     *   period_start: string,
     *   period_end: string,
     *   review_pending: int,
     *   auto_locked: int
     * }
     */
    public function fps_getReportData(string $startDate, string $endDate): array
    {
        $data = [
            'total_checks'      => 0,
            'blocked_count'     => 0,
            'block_rate'        => 0.0,
            'avg_score'         => 0.0,
            'low_count'         => 0,
            'medium_count'      => 0,
            'high_count'        => 0,
            'critical_count'    => 0,
            'top_blocked_ips'   => [],
            'top_blocked_emails' => [],
            'provider_breakdown' => [],
            'period_start'      => $startDate,
            'period_end'        => $endDate,
            'review_pending'    => 0,
            'auto_locked'       => 0,
        ];

        try {
            if (!Capsule::schema()->hasTable('mod_fps_checks')) {
                return $data;
            }

            $baseQuery = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate);

            $data['total_checks'] = (int) (clone $baseQuery)->count();

            if ($data['total_checks'] === 0) {
                return $data;
            }

            // Blocked = action_taken IN (block, blocked) OR risk_level = critical with locked
            $data['blocked_count'] = (int) (clone $baseQuery)->where(function ($q) {
                $q->whereIn('action_taken', FpsActionTaken::BLOCK)
                  ->orWhere(function ($q2) {
                      $q2->where('risk_level', 'critical')->where('locked', 1);
                  });
            })->count();

            $data['block_rate'] = $data['total_checks'] > 0
                ? round(($data['blocked_count'] / $data['total_checks']) * 100, 1)
                : 0.0;

            $data['avg_score'] = round((float) (clone $baseQuery)->avg('risk_score'), 1);

            // Risk distribution
            $data['low_count'] = (int) (clone $baseQuery)->where('risk_level', 'low')->count();
            $data['medium_count'] = (int) (clone $baseQuery)->where('risk_level', 'medium')->count();
            $data['high_count'] = (int) (clone $baseQuery)->where('risk_level', 'high')->count();
            $data['critical_count'] = (int) (clone $baseQuery)->where('risk_level', 'critical')->count();

            // Top 10 blocked IPs
            $data['top_blocked_ips'] = Capsule::table('mod_fps_checks')
                ->select(
                    'ip_address',
                    Capsule::raw('COUNT(*) as hit_count'),
                    Capsule::raw('ROUND(AVG(risk_score), 1) as avg_score'),
                    Capsule::raw('MAX(risk_score) as max_score'),
                    Capsule::raw('MAX(country) as country')
                )
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->where(function ($q) {
                    $q->whereIn('action_taken', FpsActionTaken::BLOCK)
                      ->orWhere('risk_level', 'critical');
                })
                ->whereNotNull('ip_address')
                ->where('ip_address', '!=', '')
                ->groupBy('ip_address')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(10)
                ->get()
                ->toArray();

            // Top 10 blocked emails
            $data['top_blocked_emails'] = Capsule::table('mod_fps_checks')
                ->select(
                    'email',
                    Capsule::raw('COUNT(*) as hit_count'),
                    Capsule::raw('ROUND(AVG(risk_score), 1) as avg_score'),
                    Capsule::raw('MAX(risk_score) as max_score')
                )
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->where(function ($q) {
                    $q->whereIn('action_taken', FpsActionTaken::BLOCK)
                      ->orWhere('risk_level', 'critical');
                })
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->groupBy('email')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(10)
                ->get()
                ->toArray();

            // Provider effectiveness
            $data['provider_breakdown'] = $this->fps_collectProviderStats($startDate, $endDate);

            // Review queue pending
            $data['review_pending'] = (int) (clone $baseQuery)
                ->whereNull('reviewed_by')
                ->whereIn('risk_level', ['medium', 'high', 'critical'])
                ->count();

            // Auto-locked orders
            $data['auto_locked'] = (int) (clone $baseQuery)
                ->where('locked', 1)
                ->count();
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_getReportData', '', $e->getMessage());
        }

        return $data;
    }

    /**
     * Called by DailyCronJob. Checks schedule and sends report if due.
     */
    public function fps_scheduleCheck(): void
    {
        try {
            $config = FpsConfig::getInstance();

            if (!$config->isEnabled('scheduled_reports_enabled')) {
                return;
            }

            $frequency = $config->getCustom('scheduled_reports_frequency', 'weekly');
            $recipients = trim((string) $config->getCustom('scheduled_reports_recipients', ''));

            if ($recipients === '') {
                // Fall back to first active admin email
                $recipients = $this->fps_getDefaultAdminEmail();
                if ($recipients === '') {
                    return;
                }
            }

            $shouldSend = false;
            $period = '30d';
            $periodLabel = '';

            if ($frequency === 'weekly') {
                // Send on Mondays
                if ((int) date('N') === 1) {
                    if ($this->fps_shouldSendReport('weekly')) {
                        $shouldSend = true;
                        $period = '7d';
                        $periodLabel = 'Weekly';
                    }
                }
            } elseif ($frequency === 'monthly') {
                // Send on the 1st of each month
                if ((int) date('j') === 1) {
                    if ($this->fps_shouldSendReport('monthly')) {
                        $shouldSend = true;
                        $period = '30d';
                        $periodLabel = 'Monthly';
                    }
                }
            }

            if (!$shouldSend) {
                return;
            }

            $html = $this->fps_generateReport($period);

            $subject = "[FPS] {$periodLabel} Fraud Report - " . date('M j, Y');

            $recipientList = array_filter(array_map('trim', explode(',', $recipients)));
            $sent = false;

            foreach ($recipientList as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if ($this->fps_sendReportEmail($email, $subject, $html)) {
                    $sent = true;
                }
            }

            if ($sent) {
                $this->fps_markReportSent($frequency);
                logActivity("Fraud Prevention: {$periodLabel} fraud report sent to " . implode(', ', $recipientList));
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_scheduleCheck', '', $e->getMessage());
        }
    }

    /**
     * Check whether we should send a report (prevent double-sends).
     */
    private function fps_shouldSendReport(string $frequency): bool
    {
        try {
            $settingKey = "last_report_{$frequency}";
            $lastSent = Capsule::table('mod_fps_settings')
                ->where('setting_key', $settingKey)
                ->value('setting_value');

            if ($lastSent === null || $lastSent === '') {
                return true;
            }

            $lastTs = strtotime((string) $lastSent);
            if ($lastTs === false) {
                return true;
            }

            $now = time();
            if ($frequency === 'weekly') {
                return ($now - $lastTs) > 518400; // 6 days
            }
            // monthly
            return ($now - $lastTs) > 2505600; // 29 days
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Record that a scheduled report was sent.
     */
    private function fps_markReportSent(string $frequency): void
    {
        try {
            Capsule::table('mod_fps_settings')->updateOrInsert(
                ['setting_key' => "last_report_{$frequency}"],
                ['setting_value' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_markReportSent', $frequency, $e->getMessage());
        }
    }

    /**
     * Collect provider effectiveness from provider_scores JSON column.
     */
    private function fps_collectProviderStats(string $start, string $end): array
    {
        $breakdown = [];

        try {
            $rows = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $start)
                ->where('created_at', '<=', $end)
                ->whereNotNull('provider_scores')
                ->limit(5000) // cap to avoid memory issues on large datasets
                ->pluck('provider_scores');

            foreach ($rows as $json) {
                $scores = @json_decode((string) $json, true);
                if (!is_array($scores)) {
                    continue;
                }
                foreach ($scores as $provider => $score) {
                    if (!isset($breakdown[$provider])) {
                        $breakdown[$provider] = [
                            'name'        => $provider,
                            'checks'      => 0,
                            'total_score' => 0.0,
                            'flagged'     => 0,
                        ];
                    }
                    $breakdown[$provider]['checks']++;
                    $scoreVal = is_array($score) ? (float) ($score['score'] ?? $score['weighted'] ?? 0) : (float) $score;
                    $breakdown[$provider]['total_score'] += $scoreVal;
                    if ($scoreVal > 0) {
                        $breakdown[$provider]['flagged']++;
                    }
                }
            }

            foreach ($breakdown as &$p) {
                $p['avg_score'] = $p['checks'] > 0 ? round($p['total_score'] / $p['checks'], 1) : 0;
                $p['flag_rate'] = $p['checks'] > 0 ? round(($p['flagged'] / $p['checks']) * 100, 1) : 0;
                unset($p['total_score']);
            }
            unset($p);

            usort($breakdown, function ($a, $b) {
                return $b['flagged'] <=> $a['flagged'];
            });
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_collectProviderStats', '', $e->getMessage());
        }

        return $breakdown;
    }

    /**
     * Build daily trend data for the period (date => checks count).
     *
     * @return array<string, array{checks: int, blocked: int}>
     */
    private function fps_buildDailyTrend(string $startDate, string $endDate): array
    {
        $trend = [];

        try {
            if (!Capsule::schema()->hasTable('mod_fps_stats')) {
                return $trend;
            }

            $rows = Capsule::table('mod_fps_stats')
                ->where('date', '>=', substr($startDate, 0, 10))
                ->where('date', '<=', substr($endDate, 0, 10))
                ->orderBy('date')
                ->get(['date', 'checks_total', 'checks_blocked']);

            foreach ($rows as $row) {
                $trend[$row->date] = [
                    'checks' => (int) $row->checks_total,
                    'blocked' => (int) $row->checks_blocked,
                ];
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_buildDailyTrend', '', $e->getMessage());
        }

        return $trend;
    }

    /**
     * Generate actionable recommendations based on the data.
     */
    private function fps_generateRecommendations(array $data): array
    {
        $recs = [];

        try {
            // High block rate warning
            if ($data['block_rate'] > 50) {
                $recs[] = [
                    'severity' => 'warning',
                    'text'     => 'Block rate is above 50% (' . $data['block_rate'] . '%). Consider reviewing your risk thresholds to ensure legitimate orders are not being blocked.',
                ];
            } elseif ($data['block_rate'] < 1 && $data['total_checks'] > 50) {
                $recs[] = [
                    'severity' => 'info',
                    'text'     => 'Block rate is very low (' . $data['block_rate'] . '%). Your thresholds may be too lenient. Review medium-risk checks for potential missed fraud.',
                ];
            }

            // Review queue backlog
            if ($data['review_pending'] > 20) {
                $recs[] = [
                    'severity' => 'warning',
                    'text'     => $data['review_pending'] . ' checks are pending review. Clear the review queue to prevent order processing delays.',
                ];
            }

            // Repeat offender IPs
            if (!empty($data['top_blocked_ips'])) {
                $topIp = is_array($data['top_blocked_ips'][0]) ? (object) $data['top_blocked_ips'][0] : $data['top_blocked_ips'][0];
                if ((int) $topIp->hit_count > 5) {
                    $recs[] = [
                        'severity' => 'danger',
                        'text'     => 'IP ' . ($topIp->ip_address ?? 'unknown') . ' has been blocked ' . $topIp->hit_count . ' times. Consider adding it to your firewall blocklist.',
                    ];
                }
            }

            // Critical risk volume
            if ($data['critical_count'] > 10) {
                $recs[] = [
                    'severity' => 'danger',
                    'text'     => $data['critical_count'] . ' critical-risk checks detected. Investigate for coordinated fraud attempts (card testing, bot attacks).',
                ];
            }

            // Average score trending high
            if ($data['avg_score'] > 45) {
                $recs[] = [
                    'severity' => 'warning',
                    'text'     => 'Average risk score is ' . $data['avg_score'] . '/100. This is elevated. Check if a specific provider is contributing disproportionately.',
                ];
            }

            // Low activity
            if ($data['total_checks'] < 5) {
                $recs[] = [
                    'severity' => 'info',
                    'text'     => 'Only ' . $data['total_checks'] . ' checks were performed this period. Ensure auto-check is enabled for new orders.',
                ];
            }

            // Provider with high flag rate
            foreach ($data['provider_breakdown'] as $p) {
                if ($p['checks'] >= 10 && $p['flag_rate'] > 80) {
                    $recs[] = [
                        'severity' => 'info',
                        'text'     => 'Provider "' . $p['name'] . '" flagged ' . $p['flag_rate'] . '% of checks. Verify it is not producing excessive false positives.',
                    ];
                    break; // Only report the most egregious provider
                }
            }

            if (empty($recs)) {
                $recs[] = [
                    'severity' => 'success',
                    'text'     => 'No actionable recommendations. Your fraud prevention configuration appears healthy.',
                ];
            }
        } catch (\Throwable $e) {
            $recs[] = [
                'severity' => 'info',
                'text'     => 'Unable to generate full recommendations due to a data error.',
            ];
        }

        return $recs;
    }

    /**
     * Render the complete HTML report document.
     */
    private function fps_renderHtml(array $data, string $periodLabel, array $recommendations, array $dailyTrend): string
    {
        $generatedAt = date('M j, Y \a\t g:i A T');
        $periodRange = date('M j, Y', strtotime($data['period_start']))
            . ' - ' . date('M j, Y', strtotime($data['period_end']));
        $version = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : '4.2.8';

        // Build table rows: Top blocked IPs
        $ipRows = $this->fps_renderTopTable($data['top_blocked_ips'], 'ip');
        $emailRows = $this->fps_renderTopTable($data['top_blocked_emails'], 'email');
        $providerRows = $this->fps_renderProviderTable($data['provider_breakdown']);
        $trendHtml = $this->fps_renderTrendChart($dailyTrend);
        $recsHtml = $this->fps_renderRecommendations($recommendations);

        // Risk distribution bar (CSS-only)
        $total = max(1, $data['low_count'] + $data['medium_count'] + $data['high_count'] + $data['critical_count']);
        $lowPct = round(($data['low_count'] / $total) * 100, 1);
        $medPct = round(($data['medium_count'] / $total) * 100, 1);
        $highPct = round(($data['high_count'] / $total) * 100, 1);
        $critPct = round(($data['critical_count'] / $total) * 100, 1);

        // Block rate color
        $brColor = '#38ef7d';
        if ($data['block_rate'] > 30) $brColor = '#ffb347';
        if ($data['block_rate'] > 60) $brColor = '#eb3349';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fraud Prevention Suite - {$periodLabel} Report</title>
<style>
/* Base styles */
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1a1d2e;background:#f4f6fb;line-height:1.6;font-size:14px;}
.report-wrapper{max-width:800px;margin:20px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);}

/* Header */
.report-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:36px 32px;color:#ffffff;}
.report-header h1{font-size:24px;font-weight:800;margin:0 0 6px;}
.report-header .subtitle{font-size:14px;opacity:0.9;margin:0 0 4px;}
.report-header .generated{font-size:12px;opacity:0.7;}

/* Section headers */
.section{padding:24px 32px;border-bottom:1px solid #f0f2f5;}
.section:last-child{border-bottom:none;}
.section h2{font-size:16px;font-weight:700;color:#1a1d2e;margin:0 0 16px;display:flex;align-items:center;gap:8px;}
.section h2 .icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;font-size:13px;font-weight:900;color:#fff;}
.section h2 .icon.blue{background:#667eea;}
.section h2 .icon.green{background:#38ef7d;color:#1a1d2e;}
.section h2 .icon.orange{background:#ffb347;color:#1a1d2e;}
.section h2 .icon.red{background:#eb3349;}
.section h2 .icon.purple{background:#764ba2;}

/* Summary stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:8px;}
.stat-box{text-align:center;padding:16px 8px;border-radius:10px;background:#f8f9fc;}
.stat-box .value{font-size:28px;font-weight:800;}
.stat-box .value.blocked{color:#eb3349;}
.stat-box .value.rate{color:{$brColor};}
.stat-box .value.score{color:#667eea;}
.stat-box .label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}

/* Risk distribution bar */
.risk-bar-container{margin:8px 0 4px;}
.risk-bar{display:flex;height:24px;border-radius:6px;overflow:hidden;font-size:11px;font-weight:700;color:#fff;}
.risk-bar .seg-low{background:#38ef7d;color:#1a1d2e;}
.risk-bar .seg-medium{background:#ffb347;color:#1a1d2e;}
.risk-bar .seg-high{background:#ff6b6b;}
.risk-bar .seg-critical{background:#eb3349;}
.risk-bar div{display:flex;align-items:center;justify-content:center;min-width:30px;transition:width 0.3s;}
.risk-legend{display:flex;gap:16px;margin-top:8px;font-size:12px;}
.risk-legend span{display:flex;align-items:center;gap:5px;}
.risk-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;}
.dot-low{background:#38ef7d;}
.dot-medium{background:#ffb347;}
.dot-high{background:#ff6b6b;}
.dot-critical{background:#eb3349;}

/* Tables */
table{width:100%;border-collapse:collapse;font-size:13px;}
table th{padding:10px 12px;text-align:left;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:0.3px;}
table td{padding:9px 12px;border-bottom:1px solid #f0f2f5;}
table tr:last-child td{border-bottom:none;}
table .num{text-align:center;}
.no-data{text-align:center;padding:16px;color:#aaa;font-style:italic;}

/* Trend chart (CSS bars) */
.trend-chart{display:flex;align-items:flex-end;gap:2px;height:80px;padding:8px 0;}
.trend-bar{flex:1;background:linear-gradient(to top,#667eea,#a78bfa);border-radius:3px 3px 0 0;min-width:4px;position:relative;transition:height 0.2s;}
.trend-bar.blocked{background:linear-gradient(to top,#eb3349,#ff6b6b);}
.trend-labels{display:flex;gap:2px;font-size:9px;color:#aaa;margin-top:4px;}
.trend-labels span{flex:1;text-align:center;overflow:hidden;white-space:nowrap;}

/* Recommendations */
.rec{padding:12px 16px;border-radius:8px;margin-bottom:8px;font-size:13px;line-height:1.5;}
.rec-danger{background:#fee2e2;border-left:4px solid #eb3349;color:#991b1b;}
.rec-warning{background:#fef3c7;border-left:4px solid #f59e0b;color:#92400e;}
.rec-info{background:#dbeafe;border-left:4px solid #3b82f6;color:#1e40af;}
.rec-success{background:#d1fae5;border-left:4px solid #10b981;color:#065f46;}

/* Footer */
.report-footer{padding:20px 32px;background:#f8f9fc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#888;}

/* Print styles */
@media print {
  body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .report-wrapper{box-shadow:none;margin:0;border-radius:0;max-width:100%;}
  .section{page-break-inside:avoid;}
  .report-header{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .risk-bar div{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .stat-box{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .no-print{display:none !important;}
}

/* Responsive */
@media (max-width:600px) {
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .section{padding:16px 20px;}
  .report-header{padding:24px 20px;}
}
</style>
</head>
<body>
<div class="report-wrapper">

  <!-- Header -->
  <div class="report-header">
    <h1>Fraud Prevention Suite</h1>
    <p class="subtitle">{$periodLabel} Report -- {$periodRange}</p>
    <p class="generated">Generated {$generatedAt} -- FPS v{$version}</p>
  </div>

  <!-- Executive Summary -->
  <div class="section">
    <h2><span class="icon blue">1</span> Executive Summary</h2>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="value">{$data['total_checks']}</div>
        <div class="label">Total Checks</div>
      </div>
      <div class="stat-box">
        <div class="value blocked">{$data['blocked_count']}</div>
        <div class="label">Blocked</div>
      </div>
      <div class="stat-box">
        <div class="value rate">{$data['block_rate']}%</div>
        <div class="label">Block Rate</div>
      </div>
      <div class="stat-box">
        <div class="value score">{$data['avg_score']}</div>
        <div class="label">Avg Score</div>
      </div>
    </div>
    <div style="display:flex;gap:16px;margin-top:12px;font-size:13px;">
      <span>Auto-Locked: <strong>{$data['auto_locked']}</strong></span>
      <span>Pending Review: <strong>{$data['review_pending']}</strong></span>
    </div>
  </div>

  <!-- Risk Distribution -->
  <div class="section">
    <h2><span class="icon green">2</span> Risk Distribution</h2>
    <div class="risk-bar-container">
      <div class="risk-bar">
        <div class="seg-low" style="width:{$lowPct}%">{$data['low_count']}</div>
        <div class="seg-medium" style="width:{$medPct}%">{$data['medium_count']}</div>
        <div class="seg-high" style="width:{$highPct}%">{$data['high_count']}</div>
        <div class="seg-critical" style="width:{$critPct}%">{$data['critical_count']}</div>
      </div>
      <div class="risk-legend">
        <span><span class="dot dot-low"></span> Low ({$lowPct}%)</span>
        <span><span class="dot dot-medium"></span> Medium ({$medPct}%)</span>
        <span><span class="dot dot-high"></span> High ({$highPct}%)</span>
        <span><span class="dot dot-critical"></span> Critical ({$critPct}%)</span>
      </div>
    </div>
  </div>

  <!-- Top 10 Blocked IPs -->
  <div class="section">
    <h2><span class="icon red">3</span> Top 10 Blocked IPs</h2>
    {$ipRows}
  </div>

  <!-- Top 10 Blocked Emails -->
  <div class="section">
    <h2><span class="icon orange">4</span> Top 10 Blocked Emails</h2>
    {$emailRows}
  </div>

  <!-- Provider Effectiveness -->
  <div class="section">
    <h2><span class="icon purple">5</span> Provider Effectiveness</h2>
    {$providerRows}
  </div>

  <!-- Daily Trend -->
  <div class="section">
    <h2><span class="icon blue">6</span> Daily Trend</h2>
    {$trendHtml}
  </div>

  <!-- Recommendations -->
  <div class="section">
    <h2><span class="icon green">7</span> Recommendations</h2>
    {$recsHtml}
  </div>

  <!-- Footer -->
  <div class="report-footer">
    Fraud Prevention Suite v{$version} -- EnterpriseVPS<br>
    This report was auto-generated. Configure scheduling in FPS Admin &gt; Settings &gt; Scheduled Reports.
  </div>

</div>
</body>
</html>
HTML;
    }

    /**
     * Render top IPs or emails table.
     */
    private function fps_renderTopTable(array $items, string $type): string
    {
        if (empty($items)) {
            return '<p class="no-data">No blocked ' . ($type === 'ip' ? 'IPs' : 'emails') . ' in this period.</p>';
        }

        $field = $type === 'ip' ? 'ip_address' : 'email';
        $fieldLabel = $type === 'ip' ? 'IP Address' : 'Email';
        $extraHeader = $type === 'ip' ? '<th>Country</th>' : '';

        $html = '<table><thead><tr>';
        $html .= '<th>#</th><th>' . $fieldLabel . '</th><th class="num">Hits</th><th class="num">Avg Score</th><th class="num">Max Score</th>';
        $html .= $extraHeader;
        $html .= '</tr></thead><tbody>';

        $rank = 0;
        foreach ($items as $item) {
            $rank++;
            $obj = is_array($item) ? (object) $item : $item;
            $val = htmlspecialchars((string) ($obj->$field ?? ''), ENT_QUOTES, 'UTF-8');
            $extraCell = '';
            if ($type === 'ip') {
                $country = htmlspecialchars((string) ($obj->country ?? '--'), ENT_QUOTES, 'UTF-8');
                $extraCell = '<td class="num">' . strtoupper($country) . '</td>';
            }
            $html .= '<tr>';
            $html .= '<td class="num">' . $rank . '</td>';
            $html .= '<td>' . $val . '</td>';
            $html .= '<td class="num">' . (int) ($obj->hit_count ?? 0) . '</td>';
            $html .= '<td class="num">' . (float) ($obj->avg_score ?? 0) . '</td>';
            $html .= '<td class="num">' . (float) ($obj->max_score ?? 0) . '</td>';
            $html .= $extraCell;
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render provider effectiveness table.
     */
    private function fps_renderProviderTable(array $providers): string
    {
        if (empty($providers)) {
            return '<p class="no-data">No provider data available for this period.</p>';
        }

        $html = '<table><thead><tr>';
        $html .= '<th>Provider</th><th class="num">Checks</th><th class="num">Flagged</th><th class="num">Flag Rate</th><th class="num">Avg Score</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($providers as $p) {
            $name = htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8');
            $flagRate = $p['flag_rate'] ?? ($p['checks'] > 0 ? round(($p['flagged'] / $p['checks']) * 100, 1) : 0);
            $html .= '<tr>';
            $html .= '<td>' . $name . '</td>';
            $html .= '<td class="num">' . (int) $p['checks'] . '</td>';
            $html .= '<td class="num">' . (int) $p['flagged'] . '</td>';
            $html .= '<td class="num">' . $flagRate . '%</td>';
            $html .= '<td class="num">' . (float) $p['avg_score'] . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render CSS-based bar chart for daily trend.
     */
    private function fps_renderTrendChart(array $dailyTrend): string
    {
        if (empty($dailyTrend)) {
            return '<p class="no-data">No daily trend data available.</p>';
        }

        $maxChecks = max(1, max(array_column($dailyTrend, 'checks')));
        $bars = '';
        $labels = '';
        $dayCount = count($dailyTrend);

        foreach ($dailyTrend as $date => $vals) {
            $heightPct = round(($vals['checks'] / $maxChecks) * 100);
            $heightPct = max(2, $heightPct); // minimum visible bar
            $title = htmlspecialchars($date . ': ' . $vals['checks'] . ' checks, ' . $vals['blocked'] . ' blocked', ENT_QUOTES, 'UTF-8');
            $bars .= '<div class="trend-bar" style="height:' . $heightPct . '%" title="' . $title . '"></div>';

            // Show date labels only for first, last, and every ~7th day
            $dayNum = (int) substr($date, 8, 2);
            if ($dayCount <= 14 || $dayNum === 1 || $dayNum === 15 || $date === array_key_first($dailyTrend) || $date === array_key_last($dailyTrend)) {
                $labels .= '<span>' . date('M j', strtotime($date)) . '</span>';
            } else {
                $labels .= '<span></span>';
            }
        }

        $html = '<div class="trend-chart">' . $bars . '</div>';
        $html .= '<div class="trend-labels">' . $labels . '</div>';
        $html .= '<p style="font-size:12px;color:#888;margin-top:8px;">Hover over bars for daily details. Peak: ' . $maxChecks . ' checks/day.</p>';

        return $html;
    }

    /**
     * Render recommendations list.
     */
    private function fps_renderRecommendations(array $recommendations): string
    {
        $html = '';
        foreach ($recommendations as $rec) {
            $cssClass = 'rec-' . ($rec['severity'] ?? 'info');
            $text = htmlspecialchars($rec['text'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="rec ' . $cssClass . '">' . $text . '</div>';
        }
        return $html;
    }

    /**
     * Send report email using WHMCS localAPI with mail() fallback.
     */
    private function fps_sendReportEmail(string $to, string $subject, string $html): bool
    {
        try {
            if (function_exists('localAPI')) {
                $result = localAPI('SendEmail', [
                    'id'             => 0,
                    'customtype'     => 'general',
                    'customsubject'  => $subject,
                    'custommessage'  => $html,
                    'type'           => 'general',
                    'email'          => $to,
                ]);

                if (isset($result['result']) && $result['result'] === 'success') {
                    return true;
                }

                logModuleCall(
                    self::MODULE_NAME,
                    'FpsPdfReport::localAPI_SendEmail',
                    $to,
                    json_encode($result)
                );
            }

            // Fallback: PHP mail()
            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Fraud Prevention Suite <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
                'X-Mailer: FPS-Report/' . (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : '4.2.8'),
            ]);

            return mail($to, $subject, $html, $headers);
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsPdfReport::fps_sendReportEmail', $to, $e->getMessage());
            return false;
        }
    }

    /**
     * Get the first admin email as default recipient.
     */
    private function fps_getDefaultAdminEmail(): string
    {
        try {
            $email = Capsule::table('tbladmins')
                ->where('disabled', 0)
                ->orderBy('id')
                ->value('email');
            return $email ? (string) $email : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

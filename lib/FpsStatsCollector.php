<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\Models\FpsCheckResult;

/**
 * FpsStatsCollector -- daily statistics recorder and query helper.
 *
 * Records per-check increments into mod_fps_stats (one row per date),
 * and provides query methods for dashboards and Chart.js visualizations.
 *
 * Table: mod_fps_stats
 * Columns: date (PK), checks_total, checks_flagged, checks_blocked,
 *          orders_locked, reports_submitted, false_positives
 */
class FpsStatsCollector
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    /**
     * Record a completed check into today's stats row.
     *
     * Increments the appropriate counters based on the check result.
     * Uses upsert logic: inserts a new row for today if none exists,
     * otherwise increments the existing row.
     *
     * @param FpsCheckResult $result The completed check result
     */
    public function recordCheck(FpsCheckResult $result): void
    {
        try {
            $today = date('Y-m-d');
            $level = $result->getLevel();

            $increments = [
                'checks_total'  => 1,
                'checks_flagged' => in_array($level, ['medium', 'high', 'critical'], true) ? 1 : 0,
                'checks_blocked' => $level === 'critical' ? 1 : 0,
                'orders_locked'  => $result->locked ? 1 : 0,
            ];

            $this->fps_upsertDayStats($today, $increments);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::recordCheck',
                json_encode(['check_id' => $result->checkId, 'level' => $result->getLevel()]),
                $e->getMessage()
            );
        }
    }

    /**
     * Record a fraud event by name - single entry point for ALL stats updates.
     *
     * Replaces the ad-hoc inserts/increments that were scattered across hooks.php
     * (turnstile block, pre-checkout block) and FpsApiRouter.php (api_request).
     * Every path that wants to bump a daily counter should call this method.
     *
     * Event types and what they increment:
     *   - 'turnstile_block'     : checks_total + checks_flagged + checks_blocked + pre_checkout_blocks
     *   - 'pre_checkout_block'  : checks_total + checks_flagged + checks_blocked + pre_checkout_blocks
     *   - 'pre_checkout_allow'  : checks_total only
     *   - 'api_request'         : api_requests only
     *   - 'manual_check'        : checks_total only (risk level determines extras via recordCheck)
     *
     * Any event type not matched here still safely upserts a row with zeros,
     * which is better than silently dropping the event.
     *
     * @param string $event One of the documented event types.
     * @param array<string,int> $extras Optional additional increments to merge in.
     */
    public function recordEvent(string $event, array $extras = []): void
    {
        try {
            $today = date('Y-m-d');

            $map = [
                'turnstile_block'    => ['checks_total' => 1, 'checks_flagged' => 1, 'checks_blocked' => 1, 'pre_checkout_blocks' => 1],
                'pre_checkout_block' => ['checks_total' => 1, 'checks_flagged' => 1, 'checks_blocked' => 1, 'pre_checkout_blocks' => 1],
                'pre_checkout_allow' => ['checks_total' => 1],
                'api_request'        => ['api_requests' => 1],
                'manual_check'       => ['checks_total' => 1],
            ];

            $increments = $map[$event] ?? [];
            foreach ($extras as $col => $amount) {
                $increments[$col] = ($increments[$col] ?? 0) + (int) $amount;
            }

            if (empty($increments)) {
                return;
            }

            $this->fps_upsertDayStats($today, $increments);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::recordEvent',
                json_encode(['event' => $event, 'extras' => $extras]),
                $e->getMessage()
            );
        }
    }

    /**
     * Get today's statistics.
     *
     * @return array{checks_total: int, checks_flagged: int, checks_blocked: int, orders_locked: int, reports_submitted: int, false_positives: int}
     */
    public function getToday(): array
    {
        return $this->fps_getStatsForDate(date('Y-m-d'));
    }

    /**
     * Get aggregated statistics for a date range.
     *
     * @param string $from Start date (Y-m-d)
     * @param string $to   End date (Y-m-d)
     * @return array{checks_total: int, checks_flagged: int, checks_blocked: int, orders_locked: int, reports_submitted: int, false_positives: int, days: int}
     */
    public function getRange(string $from, string $to): array
    {
        $defaults = $this->fps_emptyStats();

        try {
            $rows = Capsule::table('mod_fps_stats')
                ->where('date', '>=', $from)
                ->where('date', '<=', $to)
                ->get();

            $agg = $defaults;
            $agg['days'] = 0;

            foreach ($rows as $row) {
                $agg['checks_total']     += (int) $row->checks_total;
                $agg['checks_flagged']   += (int) $row->checks_flagged;
                $agg['checks_blocked']   += (int) $row->checks_blocked;
                $agg['orders_locked']    += (int) $row->orders_locked;
                $agg['reports_submitted'] += (int) $row->reports_submitted;
                $agg['false_positives']  += (int) $row->false_positives;
                $agg['days']++;
            }

            return $agg;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::getRange',
                json_encode(['from' => $from, 'to' => $to]),
                $e->getMessage()
            );
            $defaults['days'] = 0;
            return $defaults;
        }
    }

    /**
     * Get chart-ready data for the last N days.
     *
     * Returns an array with 'labels' (date strings) and per-metric
     * arrays suitable for Chart.js datasets.
     *
     * @param int $days Number of days to look back (default 30)
     * @return array{labels: array<string>, checks_total: array<int>, checks_flagged: array<int>, checks_blocked: array<int>, orders_locked: array<int>}
     */
    public function getChartData(int $days = 30): array
    {
        $labels         = [];
        $checksTotal    = [];
        $checksFlagged  = [];
        $checksBlocked  = [];
        $ordersLocked   = [];

        try {
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            $endDate   = date('Y-m-d');

            // Fetch all stat rows in range
            $rows = Capsule::table('mod_fps_stats')
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->orderBy('date', 'asc')
                ->get();

            // Index by date for fast lookup
            $indexed = [];
            foreach ($rows as $row) {
                $indexed[$row->date] = $row;
            }

            // Fill in every date (including days with zero checks)
            $current = new \DateTime($startDate);
            $end     = new \DateTime($endDate);
            $end->modify('+1 day');

            while ($current < $end) {
                $dateStr = $current->format('Y-m-d');
                $labels[] = $current->format('M j');

                if (isset($indexed[$dateStr])) {
                    $row = $indexed[$dateStr];
                    $checksTotal[]   = (int) $row->checks_total;
                    $checksFlagged[] = (int) $row->checks_flagged;
                    $checksBlocked[] = (int) $row->checks_blocked;
                    $ordersLocked[]  = (int) $row->orders_locked;
                } else {
                    $checksTotal[]   = 0;
                    $checksFlagged[] = 0;
                    $checksBlocked[] = 0;
                    $ordersLocked[]  = 0;
                }

                $current->modify('+1 day');
            }
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::getChartData',
                json_encode(['days' => $days]),
                $e->getMessage()
            );
        }

        return [
            'labels'          => $labels,
            'checks_total'    => $checksTotal,
            'checks_flagged'  => $checksFlagged,
            'checks_blocked'  => $checksBlocked,
            'orders_locked'   => $ordersLocked,
        ];
    }

    /**
     * Purge stats older than N days.
     *
     * @param int $days Retention period (default 365)
     * @return int Number of rows deleted
     */
    public function purgeOld(int $days = 365): int
    {
        try {
            $cutoff = date('Y-m-d', strtotime("-{$days} days"));

            $deleted = Capsule::table('mod_fps_stats')
                ->where('date', '<', $cutoff)
                ->delete();

            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::purgeOld',
                json_encode(['days' => $days, 'cutoff' => $cutoff]),
                "Deleted {$deleted} rows"
            );

            return (int) $deleted;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsStatsCollector::purgeOld::ERROR',
                json_encode(['days' => $days]),
                $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Increment the reports_submitted counter for today.
     */
    public function recordReport(): void
    {
        try {
            $this->fps_upsertDayStats(date('Y-m-d'), ['reports_submitted' => 1]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Increment the false_positives counter for today.
     */
    public function recordFalsePositive(): void
    {
        try {
            $this->fps_upsertDayStats(date('Y-m-d'), ['false_positives' => 1]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Get summary statistics for the dashboard (today + 7d + 30d).
     *
     * @return array{today: array, week: array, month: array, all_time: array}
     */
    public function getDashboardSummary(): array
    {
        $today     = date('Y-m-d');
        $weekAgo   = date('Y-m-d', strtotime('-7 days'));
        $monthAgo  = date('Y-m-d', strtotime('-30 days'));

        return [
            'today'    => $this->fps_getStatsForDate($today),
            'week'     => $this->getRange($weekAgo, $today),
            'month'    => $this->getRange($monthAgo, $today),
            'all_time' => $this->fps_getAllTimeStats(),
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Upsert a day's stats row: insert if missing, increment if existing.
     *
     * @param string $date       Y-m-d date string
     * @param array  $increments Column => increment value
     */
    private function fps_upsertDayStats(string $date, array $increments): void
    {
        $row = Capsule::table('mod_fps_stats')
            ->where('date', $date)
            ->first();

        // Only touch columns that actually exist in the current schema; an install
        // that hasn't run a recent upgrade might lack pre_checkout_blocks, api_requests,
        // etc. Silently dropping unknown columns is better than an SQLException breaking
        // the checkout/API request path.
        $existingCols = [];
        try {
            foreach (array_keys($increments) as $col) {
                if (Capsule::schema()->hasColumn('mod_fps_stats', $col)) {
                    $existingCols[$col] = $increments[$col];
                }
            }
        } catch (\Throwable $e) {
            $existingCols = $increments; // fallback: trust caller
        }

        if ($row === null) {
            $insert = $this->fps_emptyStats();
            $insert['date'] = $date;
            foreach ($existingCols as $col => $val) {
                if (isset($insert[$col])) {
                    $insert[$col] = $val;
                }
            }
            Capsule::table('mod_fps_stats')->insert($insert);
        } else {
            foreach ($existingCols as $col => $val) {
                if ($val > 0) {
                    Capsule::table('mod_fps_stats')
                        ->where('date', $date)
                        ->increment($col, $val);
                }
            }
        }
    }

    /**
     * Get stats for a single date.
     *
     * @return array{checks_total: int, checks_flagged: int, checks_blocked: int, orders_locked: int, reports_submitted: int, false_positives: int}
     */
    private function fps_getStatsForDate(string $date): array
    {
        try {
            $row = Capsule::table('mod_fps_stats')
                ->where('date', $date)
                ->first();

            if ($row === null) {
                return $this->fps_emptyStats();
            }

            return [
                'checks_total'     => (int) $row->checks_total,
                'checks_flagged'   => (int) $row->checks_flagged,
                'checks_blocked'   => (int) $row->checks_blocked,
                'orders_locked'    => (int) $row->orders_locked,
                'reports_submitted' => (int) $row->reports_submitted,
                'false_positives'  => (int) $row->false_positives,
            ];
        } catch (\Throwable $e) {
            return $this->fps_emptyStats();
        }
    }

    /**
     * Get all-time aggregated stats.
     */
    private function fps_getAllTimeStats(): array
    {
        try {
            return [
                'checks_total'     => (int) Capsule::table('mod_fps_stats')->sum('checks_total'),
                'checks_flagged'   => (int) Capsule::table('mod_fps_stats')->sum('checks_flagged'),
                'checks_blocked'   => (int) Capsule::table('mod_fps_stats')->sum('checks_blocked'),
                'orders_locked'    => (int) Capsule::table('mod_fps_stats')->sum('orders_locked'),
                'reports_submitted' => (int) Capsule::table('mod_fps_stats')->sum('reports_submitted'),
                'false_positives'  => (int) Capsule::table('mod_fps_stats')->sum('false_positives'),
                'first_check'      => Capsule::table('mod_fps_stats')->min('date') ?? 'N/A',
                'last_check'       => Capsule::table('mod_fps_stats')->max('date') ?? 'N/A',
            ];
        } catch (\Throwable $e) {
            $stats = $this->fps_emptyStats();
            $stats['first_check'] = 'N/A';
            $stats['last_check']  = 'N/A';
            return $stats;
        }
    }

    /**
     * Return a zeroed-out stats array.
     */
    private function fps_emptyStats(): array
    {
        return [
            'checks_total'       => 0,
            'checks_flagged'     => 0,
            'checks_blocked'     => 0,
            'orders_locked'      => 0,
            'reports_submitted'  => 0,
            'false_positives'    => 0,
            // Extended columns added by upgrade path; included here so the
            // insert branch of fps_upsertDayStats can seed new rows for
            // events like turnstile_block / api_request that touch them.
            'pre_checkout_blocks' => 0,
            'api_requests'        => 0,
            'unique_ips'          => 0,
        ];
    }
}

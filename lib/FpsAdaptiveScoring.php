<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsAdaptiveScoring -- monthly chargeback-driven weight tuning for fraud providers.
 *
 * Analyses the last 90 days of chargebacks, correlates them against per-provider
 * scores stored in mod_fps_checks, and adjusts the weight of each provider in
 * mod_fps_settings. All weight changes are recorded in mod_fps_weight_history
 * for full audit trail.
 *
 * Designed to be called from the WHMCS DailyCronJob hook; it self-gates on a
 * 30-day cooldown via the last_adaptive_run setting.
 */
class FpsAdaptiveScoring
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    /** Score threshold above which a provider is considered to have "flagged" an order. */
    private const FLAG_THRESHOLD = 30;

    /** Minimum and maximum weight bounds. */
    private const WEIGHT_MIN = 0.3;
    private const WEIGHT_MAX = 2.5;

    /** Number of chargebacks needed for full confidence (confidence = 1.0). */
    private const CONFIDENCE_SAMPLE_SIZE = 50;

    /** Cooldown in days between adaptive runs. */
    private const COOLDOWN_DAYS = 30;

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Entry point for the monthly adaptive weight tuning process.
     *
     * Gated by shouldRun(); safe to call from DailyCronJob on every run.
     * All DB failures are caught and logged; this method never throws.
     */
    public function runMonthlyAnalysis(): void
    {
        if (!$this->shouldRun()) {
            return;
        }

        try {
            $period = date('Y-m');
            $chargebacks = $this->fps_fetchRecentChargebacks();

            if (empty($chargebacks)) {
                $this->fps_stampLastRun();
                return;
            }

            $chargebackOrderIds = array_column($chargebacks, 'order_id');

            // Fetch fraud checks for chargeback orders (true positives source).
            $cbChecks = $this->fps_fetchChecksByOrderIds($chargebackOrderIds);

            // Fetch all check order IDs for the same 90-day window, then subtract
            // chargeback orders to get clean (true negative) orders.
            $allRecentOrderIds = $this->fps_fetchAllRecentCheckOrderIds();
            $cleanOrderIds = array_values(array_diff($allRecentOrderIds, $chargebackOrderIds));
            $cleanChecks = $this->fps_fetchChecksByOrderIds($cleanOrderIds);

            // Discover provider names from the combined check set.
            $providers = $this->fps_discoverProviders(array_merge($cbChecks, $cleanChecks));

            if (empty($providers)) {
                $this->fps_stampLastRun();
                return;
            }

            foreach ($providers as $provider) {
                $this->fps_tuneProviderWeight($provider, $cbChecks, $cleanChecks, $period);
            }

            $this->fps_stampLastRun();

        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAdaptiveScoring::runMonthlyAnalysis',
                [],
                $e->getMessage(),
                $e->getTraceAsString()
            );
        }
    }

    /**
     * Returns weight change history grouped by period.
     *
     * @param int $months Number of calendar months to look back (default 6).
     * @return array<int, array{period: string, provider: string, old_weight: float, new_weight: float, precision: float, sample_size: int}>
     */
    public function getWeightHistory(int $months = 6): array
    {
        try {
            $since = date('Y-m', strtotime("-{$months} months"));

            $rows = Capsule::table('mod_fps_weight_history')
                ->where('period', '>=', $since)
                ->orderBy('period', 'desc')
                ->orderBy('provider', 'asc')
                ->get([
                    'period',
                    'provider',
                    'old_weight',
                    'new_weight',
                    'precision_score',
                    'sample_size',
                ]);

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'period'      => $row->period,
                    'provider'    => $row->provider,
                    'old_weight'  => (float) $row->old_weight,
                    'new_weight'  => (float) $row->new_weight,
                    'precision'   => (float) $row->precision_score,
                    'sample_size' => (int) $row->sample_size,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAdaptiveScoring::getWeightHistory',
                ['months' => $months],
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Returns each provider's current weight alongside a confidence score.
     *
     * Confidence is clamped to [0.0, 1.0] and is proportional to the sample
     * size used in the most recent tuning run (50+ chargebacks = full confidence).
     *
     * @return array<string, array{weight: float, confidence: float, last_updated: string}>
     */
    public function getCurrentWeightsWithConfidence(): array
    {
        try {
            // Pull the latest history row per provider.
            $latestRows = Capsule::table('mod_fps_weight_history')
                ->select(['provider', Capsule::raw('MAX(created_at) as latest_at')])
                ->groupBy('provider')
                ->get();

            if ($latestRows->isEmpty()) {
                return [];
            }

            $result = [];

            foreach ($latestRows as $latest) {
                $row = Capsule::table('mod_fps_weight_history')
                    ->where('provider', $latest->provider)
                    ->where('created_at', $latest->latest_at)
                    ->first(['provider', 'new_weight', 'sample_size', 'created_at']);

                if ($row === null) {
                    continue;
                }

                $sampleSize = (int) $row->sample_size;
                $confidence = min(1.0, $sampleSize / self::CONFIDENCE_SAMPLE_SIZE);

                $result[$row->provider] = [
                    'weight'       => (float) $row->new_weight,
                    'confidence'   => round($confidence, 4),
                    'last_updated' => $row->created_at,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAdaptiveScoring::getCurrentWeightsWithConfidence',
                [],
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Returns true when the adaptive analysis is due to run.
     *
     * Runs if the last_adaptive_run setting is absent or older than COOLDOWN_DAYS.
     */
    public function shouldRun(): bool
    {
        $lastRun = $this->config->getCustom('last_adaptive_run', '');

        if ($lastRun === '') {
            return true;
        }

        $lastRunTime = strtotime($lastRun);
        if ($lastRunTime === false) {
            return true;
        }

        $daysSince = (int) floor((time() - $lastRunTime) / 86400);
        return $daysSince >= self::COOLDOWN_DAYS;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Fetch all chargebacks recorded in the last 90 days.
     *
     * @return array<int, object>
     */
    private function fps_fetchRecentChargebacks(): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-90 days'));

        return Capsule::table('mod_fps_chargebacks')
            ->where('created_at', '>=', $since)
            ->get(['id', 'order_id'])
            ->all();
    }

    /**
     * Fetch fraud check rows for a given list of order IDs.
     *
     * Returns an empty array when the list is empty (avoids an invalid query).
     *
     * @param int[] $orderIds
     * @return array<int, object>
     */
    private function fps_fetchChecksByOrderIds(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        return Capsule::table('mod_fps_checks')
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'provider_scores'])
            ->all();
    }

    /**
     * Fetch all order IDs that have a fraud check in the last 90 days.
     *
     * @return int[]
     */
    private function fps_fetchAllRecentCheckOrderIds(): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-90 days'));

        $rows = Capsule::table('mod_fps_checks')
            ->where('created_at', '>=', $since)
            ->get(['order_id']);

        return array_map(static fn(object $r): int => (int) $r->order_id, $rows->all());
    }

    /**
     * Extract every unique provider name found across a set of check rows.
     *
     * @param array<int, object> $checks
     * @return string[]
     */
    private function fps_discoverProviders(array $checks): array
    {
        $providers = [];

        foreach ($checks as $check) {
            $scores = $this->fps_parseProviderScores($check->provider_scores ?? '{}');
            foreach (array_keys($scores) as $name) {
                $providers[$name] = true;
            }
        }

        return array_keys($providers);
    }

    /**
     * Parse the provider_scores JSON column into an associative array.
     *
     * Silently returns an empty array on malformed JSON.
     *
     * @return array<string, float>
     */
    private function fps_parseProviderScores(string $json): array
    {
        if ($json === '' || $json === 'null') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $provider => $score) {
            $result[(string) $provider] = (float) $score;
        }
        return $result;
    }

    /**
     * Calculate true-positive and false-positive counts for one provider,
     * derive precision, compute the new weight, then persist both the new
     * weight setting and the history record.
     *
     * @param array<int, object> $cbChecks    Checks for chargeback orders.
     * @param array<int, object> $cleanChecks Checks for non-chargeback orders.
     */
    private function fps_tuneProviderWeight(
        string $provider,
        array $cbChecks,
        array $cleanChecks,
        string $period
    ): void {
        // True positives: chargeback orders where provider score > FLAG_THRESHOLD
        $truePositives = 0;
        foreach ($cbChecks as $check) {
            $scores = $this->fps_parseProviderScores($check->provider_scores ?? '{}');
            if (isset($scores[$provider]) && $scores[$provider] > self::FLAG_THRESHOLD) {
                $truePositives++;
            }
        }

        // False positives: clean orders where provider score > FLAG_THRESHOLD
        $falsePositives = 0;
        foreach ($cleanChecks as $check) {
            $scores = $this->fps_parseProviderScores($check->provider_scores ?? '{}');
            if (isset($scores[$provider]) && $scores[$provider] > self::FLAG_THRESHOLD) {
                $falsePositives++;
            }
        }

        $denominator = $truePositives + $falsePositives;
        $precision   = $denominator > 0 ? $truePositives / $denominator : 0.0;

        // Read the current stored weight (defaults to 1.0 if never set).
        $currentWeight = (float) $this->config->getCustom(
            "provider_weight_{$provider}",
            '1.0'
        );
        if ($currentWeight <= 0.0) {
            $currentWeight = 1.0;
        }

        // New weight formula: current * (0.7 + 0.6 * precision)
        // Gives range 0.7x (precision=0) to 1.3x (precision=1) of current weight.
        $newWeight = $currentWeight * (0.7 + 0.6 * $precision);
        $newWeight = max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $newWeight));

        $sampleSize = count($cbChecks);

        // Persist the new weight to mod_fps_settings.
        $this->config->set(
            "provider_weight_{$provider}",
            (string) round($newWeight, 4)
        );

        // Record the tuning event in the audit history table.
        $this->fps_insertWeightHistory(
            provider: $provider,
            oldWeight: $currentWeight,
            newWeight: $newWeight,
            precision: $precision,
            truePositives: $truePositives,
            falsePositives: $falsePositives,
            sampleSize: $sampleSize,
            period: $period
        );
    }

    /**
     * Insert one row into mod_fps_weight_history.
     *
     * All numeric values are rounded to their column precision before insert.
     */
    private function fps_insertWeightHistory(
        string $provider,
        float $oldWeight,
        float $newWeight,
        float $precision,
        int $truePositives,
        int $falsePositives,
        int $sampleSize,
        string $period
    ): void {
        Capsule::table('mod_fps_weight_history')->insert([
            'provider'            => $provider,
            'old_weight'          => round($oldWeight, 2),
            'new_weight'          => round($newWeight, 2),
            'precision_score'     => round($precision, 4),
            'true_positive_count' => $truePositives,
            'false_positive_count' => $falsePositives,
            'sample_size'         => $sampleSize,
            'period'              => $period,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Write the current UTC timestamp to last_adaptive_run in mod_fps_settings.
     */
    private function fps_stampLastRun(): void
    {
        $this->config->set('last_adaptive_run', date('Y-m-d H:i:s'));
    }
}

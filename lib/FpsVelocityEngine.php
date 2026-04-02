<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsVelocityEngine -- velocity-based fraud signal tracker and scorer.
 *
 * Records discrete fraud-relevant events (orders, registrations, payment
 * failures, checkout attempts, card BIN usage) and scores them against
 * configurable time-window limits.  Each exceeded limit contributes a
 * point penalty scaled by how far over the limit the actual count is.
 * The final score is capped at 100 and returned in the standard
 * provider-result format used by FpsRiskEngine::aggregate().
 *
 * Velocity limits are read via FpsConfig keys:
 *   velocity_orders_per_ip_hour       (default 5)
 *   velocity_regs_per_ip_day          (default 3)
 *   velocity_fails_per_client_day     (default 5)
 *   velocity_checkouts_per_ip_hour    (default 10)
 *   velocity_bin_reuse_day            (default 3)
 *
 * Event records are stored in mod_fps_velocity_events and pruned via
 * purgeOldEvents(), which is intended to be called from DailyCronJob.
 */
class FpsVelocityEngine
{
    private const MODULE_NAME = 'fraud_prevention_suite';
    private const TABLE       = 'mod_fps_velocity_events';

    /** Valid event type identifiers */
    private const VALID_EVENT_TYPES = [
        'order',
        'registration',
        'failed_payment',
        'checkout_attempt',
        'card_used',
    ];

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Record a velocity event.
     *
     * Inserts a row into mod_fps_velocity_events.  Invalid event types are
     * silently dropped so callers do not need to guard against typos causing
     * fatal errors in production.
     *
     * @param string $eventType  One of the VALID_EVENT_TYPES constants.
     * @param string $identifier The keyed value (IP address, email, BIN, etc.).
     * @param int    $clientId   WHMCS client ID; 0 for guests/pre-auth events.
     */
    public function recordEvent(string $eventType, string $identifier, int $clientId = 0): void
    {
        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::recordEvent',
                json_encode(['event_type' => $eventType, 'identifier' => $identifier]),
                'Invalid event type -- skipped'
            );
            return;
        }

        try {
            Capsule::table(self::TABLE)->insert([
                'event_type' => $eventType,
                'identifier' => $identifier,
                'client_id'  => $clientId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::recordEvent',
                json_encode([
                    'event_type' => $eventType,
                    'identifier' => $identifier,
                    'client_id'  => $clientId,
                ]),
                $e->getMessage()
            );
        }
    }

    /**
     * Evaluate velocity signals against all configured limits.
     *
     * Checks five independent velocity windows and aggregates the result
     * into a 0-100 score.  Each exceeded limit adds a points penalty
     * calculated by fps_scoreExceedance().  The score is capped at 100.
     *
     * @param array{
     *     ip: string,
     *     email: string,
     *     client_id: int,
     *     card_first6?: string
     * } $context
     *
     * @return array{
     *     provider: string,
     *     score: float,
     *     details: string,
     *     factors: array<int, array{factor: string, score: float, provider: string}>,
     *     success: bool
     * }
     */
    public function checkVelocity(array $context): array
    {
        $factors    = [];
        $totalScore = 0.0;

        try {
            // ------------------------------------------------------------------
            // (a) Orders per IP per hour
            // ------------------------------------------------------------------
            $ordersPerIpHourLimit = $this->config->getInt('velocity_orders_per_ip_hour', 5);
            $ordersPerIpHour      = $this->fps_countEvents('order', $context['ip'], 3600);
            if ($ordersPerIpHour > $ordersPerIpHourLimit) {
                $points = $this->fps_scoreExceedance($ordersPerIpHour, $ordersPerIpHourLimit);
                $totalScore += $points;
                $factors[]   = [
                    'factor'   => 'orders_per_ip_hour',
                    'score'    => $points,
                    'provider' => 'velocity',
                ];
            }

            // ------------------------------------------------------------------
            // (b) Registrations per IP per day
            // ------------------------------------------------------------------
            $regsPerIpDayLimit = $this->config->getInt('velocity_regs_per_ip_day', 3);
            $regsPerIpDay      = $this->fps_countEvents('registration', $context['ip'], 86400);
            if ($regsPerIpDay > $regsPerIpDayLimit) {
                $points = $this->fps_scoreExceedance($regsPerIpDay, $regsPerIpDayLimit);
                $totalScore += $points;
                $factors[]   = [
                    'factor'   => 'regs_per_ip_day',
                    'score'    => $points,
                    'provider' => 'velocity',
                ];
            }

            // ------------------------------------------------------------------
            // (c) Failed payments per client per day
            // ------------------------------------------------------------------
            if ($context['client_id'] > 0) {
                $failsPerClientDayLimit = $this->config->getInt('velocity_fails_per_client_day', 5);
                $failsPerClientDay      = $this->fps_countEvents(
                    'failed_payment',
                    (string) $context['client_id'],
                    86400
                );
                if ($failsPerClientDay > $failsPerClientDayLimit) {
                    $points = $this->fps_scoreExceedance($failsPerClientDay, $failsPerClientDayLimit);
                    $totalScore += $points;
                    $factors[]   = [
                        'factor'   => 'failed_payments_per_client_day',
                        'score'    => $points,
                        'provider' => 'velocity',
                    ];
                }
            }

            // ------------------------------------------------------------------
            // (d) Checkout attempts per IP per hour
            // ------------------------------------------------------------------
            $checkoutsPerIpHourLimit = $this->config->getInt('velocity_checkouts_per_ip_hour', 10);
            $checkoutsPerIpHour      = $this->fps_countEvents('checkout_attempt', $context['ip'], 3600);
            if ($checkoutsPerIpHour > $checkoutsPerIpHourLimit) {
                $points = $this->fps_scoreExceedance($checkoutsPerIpHour, $checkoutsPerIpHourLimit);
                $totalScore += $points;
                $factors[]   = [
                    'factor'   => 'checkouts_per_ip_hour',
                    'score'    => $points,
                    'provider' => 'velocity',
                ];
            }

            // ------------------------------------------------------------------
            // (e) Same card BIN used by distinct clients in 24 hours
            // ------------------------------------------------------------------
            $cardFirst6 = trim($context['card_first6'] ?? '');
            if ($cardFirst6 !== '') {
                $binReuseDayLimit = $this->config->getInt('velocity_bin_reuse_day', 3);
                $binReuseDay      = $this->fps_countDistinctClients('card_used', $cardFirst6, 86400);
                if ($binReuseDay > $binReuseDayLimit) {
                    $points = $this->fps_scoreExceedance($binReuseDay, $binReuseDayLimit);
                    $totalScore += $points;
                    $factors[]   = [
                        'factor'   => 'bin_reuse_distinct_clients_day',
                        'score'    => $points,
                        'provider' => 'velocity',
                    ];
                }
            }
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::checkVelocity',
                json_encode($context),
                $e->getMessage()
            );

            return [
                'provider' => 'velocity',
                'score'    => 0.0,
                'details'  => 'Velocity check failed: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }

        $finalScore  = min(100.0, $totalScore);
        $detailParts = array_map(
            static fn(array $f): string => sprintf('%s (+%.0f)', $f['factor'], $f['score']),
            $factors
        );
        $details = $finalScore > 0.0
            ? 'Velocity signals: ' . implode(', ', $detailParts)
            : 'No velocity limits exceeded';

        return [
            'provider' => 'velocity',
            'score'    => round($finalScore, 2),
            'details'  => $details,
            'factors'  => $factors,
            'success'  => true,
        ];
    }

    /**
     * Delete velocity event records older than the retention window.
     *
     * Intended to be called from the WHMCS DailyCronJob hook to keep the
     * events table from growing unbounded.
     *
     * @param int $retentionDays Number of days of history to keep (default 7).
     * @return int Number of rows deleted; -1 on error.
     */
    public function purgeOldEvents(int $retentionDays = 7): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

            $deleted = Capsule::table(self::TABLE)
                ->where('created_at', '<', $cutoff)
                ->delete();

            return (int) $deleted;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::purgeOldEvents',
                json_encode(['retention_days' => $retentionDays]),
                $e->getMessage()
            );
            return -1;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Count events of a given type for a given identifier within a time window.
     *
     * Used for IP-keyed and client-ID-keyed lookups where we count all rows
     * belonging to one identifier regardless of how many distinct clients or
     * IPs are behind it.
     *
     * @param string $eventType      Event type to filter on.
     * @param string $identifier     The indexed key value to match.
     * @param int    $windowSeconds  Look-back window in seconds from now.
     * @return int Count of matching rows; 0 on error.
     */
    private function fps_countEvents(string $eventType, string $identifier, int $windowSeconds): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);

            return (int) Capsule::table(self::TABLE)
                ->where('event_type', $eventType)
                ->where('identifier', $identifier)
                ->where('created_at', '>=', $since)
                ->count();
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::fps_countEvents',
                json_encode([
                    'event_type'     => $eventType,
                    'identifier'     => $identifier,
                    'window_seconds' => $windowSeconds,
                ]),
                $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Count the number of distinct client IDs that have generated a given
     * event type for a given identifier within a time window.
     *
     * Used exclusively for BIN-reuse detection: the identifier is the card
     * BIN (first 6 digits) and we want to know how many different clients
     * have used that BIN recently, which indicates carding activity.
     *
     * @param string $eventType      Event type to filter on.
     * @param string $identifier     The indexed key value to match (BIN, etc.).
     * @param int    $windowSeconds  Look-back window in seconds from now.
     * @return int Distinct client count; 0 on error.
     */
    private function fps_countDistinctClients(string $eventType, string $identifier, int $windowSeconds): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);

            return (int) Capsule::table(self::TABLE)
                ->where('event_type', $eventType)
                ->where('identifier', $identifier)
                ->where('created_at', '>=', $since)
                ->where('client_id', '>', 0)
                ->distinct()
                ->count('client_id');
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsVelocityEngine::fps_countDistinctClients',
                json_encode([
                    'event_type'     => $eventType,
                    'identifier'     => $identifier,
                    'window_seconds' => $windowSeconds,
                ]),
                $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Calculate a score contribution based on how far a count exceeds its limit.
     *
     * Bands:
     *   1x to <2x the limit : +15 points (marginal exceedance, could be legitimate)
     *   2x to <3x the limit : +25 points (clearly elevated, warrants review)
     *   3x+      the limit  : +40 points (likely automated or coordinated attack)
     *
     * @param int $count The observed event count.
     * @param int $limit The configured threshold for this signal.
     * @return float Score contribution for this single exceeded signal.
     */
    private function fps_scoreExceedance(int $count, int $limit): float
    {
        if ($limit <= 0) {
            // Degenerate config -- treat any count as max severity
            return 40.0;
        }

        $ratio = $count / $limit;

        if ($ratio >= 3.0) {
            return 40.0;
        }

        if ($ratio >= 2.0) {
            return 25.0;
        }

        // ratio is between 1.0 (exclusive) and 2.0 (exclusive)
        return 15.0;
    }
}

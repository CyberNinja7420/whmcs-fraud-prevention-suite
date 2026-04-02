<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsBehavioralScoringEngine -- client-side behavioral signal analyzer.
 *
 * Accepts a decoded behavioral fingerprint collected by the checkout page
 * JavaScript payload and converts it into a 0-100 fraud risk score.
 * Signals analyzed:
 *
 *   - Form fill speed        : impossibly fast fills indicate bots
 *   - Mouse behavior         : zero movement or low-entropy paths indicate automation
 *   - Paste detection        : pasting card/CVV numbers is a strong carding signal
 *   - Keypress cadence       : robotic uniformity (low CV) indicates scripted input
 *   - Tab switching          : excessive switching suggests scripted info retrieval
 *   - Time on page           : very short dwell time indicates automation
 *
 * Events are persisted to mod_fps_behavioral_events for historical trend
 * analysis and client trust scoring.
 *
 * The returned array is in the standard provider-result format used by
 * FpsRiskEngine::aggregate():
 *   ['provider' => 'behavioral', 'score' => float, 'details' => string,
 *    'factors' => array, 'success' => bool]
 */
class FpsBehavioralScoringEngine
{
    private const MODULE_NAME = 'fraud_prevention_suite';
    private const TABLE       = 'mod_fps_behavioral_events';

    /** Field name substrings that indicate credit card number inputs. */
    private const CARD_FIELD_PATTERNS = ['card', 'cc_num', 'cardnumber', 'card_number', 'ccnum'];

    /** Field name substrings that indicate CVV / security code inputs. */
    private const CVV_FIELD_PATTERNS  = ['cvv', 'cvc', 'cvv2', 'security_code', 'card_code'];

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Analyze a behavioral fingerprint and return a scored provider result.
     *
     * @param array{
     *     form_fill_time_ms?: int,
     *     field_timings?: array<int, array{field: string, duration_ms: int, focus_count: int}>,
     *     mouse_movements?: int,
     *     mouse_distance_px?: int,
     *     mouse_entropy?: float,
     *     paste_events?: array<int, array{field: string, length: int}>,
     *     keypress_intervals_ms?: array<int, int>,
     *     tab_switches?: int,
     *     time_on_page_ms?: int,
     *     scroll_events?: int,
     *     touch_events?: int
     * } $behavioralData Decoded JSON from the client-side behavioral fingerprint.
     *
     * @return array{
     *     provider: string,
     *     score: float,
     *     details: string,
     *     factors: array<int, array{factor: string, score: float, provider: string}>,
     *     success: bool
     * }
     */
    public function analyze(array $behavioralData): array
    {
        $factors    = [];
        $totalScore = 0.0;

        try {
            // ------------------------------------------------------------------
            // (a) Form fill speed
            // ------------------------------------------------------------------
            $formFillMs = (int) ($behavioralData['form_fill_time_ms'] ?? 0);
            $speedScore = $this->fps_scoreFormFillSpeed($formFillMs);
            if ($speedScore > 0.0) {
                $totalScore += $speedScore;
                $factors[]   = [
                    'factor'   => 'form_fill_speed',
                    'score'    => $speedScore,
                    'provider' => 'behavioral',
                ];
            }

            // ------------------------------------------------------------------
            // (b) Mouse behavior
            // ------------------------------------------------------------------
            $mouseMovements = (int) ($behavioralData['mouse_movements'] ?? 0);
            $mouseEntropy   = (float) ($behavioralData['mouse_entropy']  ?? 0.0);
            $touchEvents    = (int) ($behavioralData['touch_events']     ?? 0);
            $mouseScore     = $this->fps_scoreMouseBehavior($mouseMovements, $mouseEntropy, $touchEvents);
            if ($mouseScore > 0.0) {
                $totalScore += $mouseScore;
                $factors[]   = [
                    'factor'   => 'mouse_behavior',
                    'score'    => $mouseScore,
                    'provider' => 'behavioral',
                ];
            }

            // ------------------------------------------------------------------
            // (c) Paste detection
            // ------------------------------------------------------------------
            $pasteEvents = (array) ($behavioralData['paste_events'] ?? []);
            $pasteScore  = $this->fps_scorePasteEvents($pasteEvents);
            if ($pasteScore > 0.0) {
                $totalScore += $pasteScore;
                $factors[]   = [
                    'factor'   => 'paste_detection',
                    'score'    => $pasteScore,
                    'provider' => 'behavioral',
                ];
            }

            // ------------------------------------------------------------------
            // (d) Keypress cadence
            // ------------------------------------------------------------------
            $keypressIntervals = (array) ($behavioralData['keypress_intervals_ms'] ?? []);
            $cadenceScore      = $this->fps_scoreKeypressCadence($keypressIntervals);
            if ($cadenceScore > 0.0) {
                $totalScore += $cadenceScore;
                $factors[]   = [
                    'factor'   => 'keypress_cadence',
                    'score'    => $cadenceScore,
                    'provider' => 'behavioral',
                ];
            }

            // ------------------------------------------------------------------
            // (e) Tab switching
            // ------------------------------------------------------------------
            $tabSwitches = (int) ($behavioralData['tab_switches'] ?? 0);
            if ($tabSwitches > 5) {
                $totalScore += 5.0;
                $factors[]   = [
                    'factor'   => 'tab_switching',
                    'score'    => 5.0,
                    'provider' => 'behavioral',
                ];
            }

            // ------------------------------------------------------------------
            // (f) Time on page
            // ------------------------------------------------------------------
            $timeOnPageMs    = (int) ($behavioralData['time_on_page_ms'] ?? 0);
            $timeOnPageScore = $this->fps_scoreTimeOnPage($timeOnPageMs);
            if ($timeOnPageScore > 0.0) {
                $totalScore += $timeOnPageScore;
                $factors[]   = [
                    'factor'   => 'time_on_page',
                    'score'    => $timeOnPageScore,
                    'provider' => 'behavioral',
                ];
            }
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsBehavioralScoringEngine::analyze',
                json_encode($behavioralData),
                $e->getMessage()
            );

            return [
                'provider' => 'behavioral',
                'score'    => 0.0,
                'details'  => 'Behavioral analysis failed: ' . $e->getMessage(),
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
            ? 'Behavioral signals: ' . implode(', ', $detailParts)
            : 'No behavioral anomalies detected';

        return [
            'provider' => 'behavioral',
            'score'    => round($finalScore, 2),
            'details'  => $details,
            'factors'  => $factors,
            'success'  => true,
        ];
    }

    /**
     * Persist a behavioral event record for historical analysis.
     *
     * A best-effort write: failures are logged but not re-thrown so that a
     * database hiccup never blocks the checkout flow.
     *
     * @param int    $clientId      WHMCS client ID; 0 for guest/pre-auth sessions.
     * @param string $sessionId     Session identifier from the client-side payload.
     * @param array  $behavioralData The raw decoded behavioral fingerprint.
     * @param float  $score         Final score produced by analyze().
     */
    public function recordBehavioralEvent(
        int    $clientId,
        string $sessionId,
        array  $behavioralData,
        float  $score
    ): void {
        try {
            $pasteEvents    = (array) ($behavioralData['paste_events'] ?? []);
            $pasteDetected  = !empty($pasteEvents) ? 1 : 0;

            Capsule::table(self::TABLE)->insert([
                'client_id'         => $clientId,
                'session_id'        => substr($sessionId, 0, 64),
                'form_fill_time_ms' => isset($behavioralData['form_fill_time_ms'])
                                           ? (int) $behavioralData['form_fill_time_ms']
                                           : null,
                'mouse_entropy'     => isset($behavioralData['mouse_entropy'])
                                           ? round((float) $behavioralData['mouse_entropy'], 3)
                                           : null,
                'paste_detected'    => $pasteDetected,
                'behavioral_score'  => round($score, 2),
                'raw_data'          => json_encode($behavioralData, JSON_UNESCAPED_SLASHES),
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsBehavioralScoringEngine::recordBehavioralEvent',
                json_encode([
                    'client_id'  => $clientId,
                    'session_id' => $sessionId,
                    'score'      => $score,
                ]),
                $e->getMessage()
            );
        }
    }

    /**
     * Retrieve recent behavioral events for a client.
     *
     * Rows are ordered newest-first.  An empty array is returned on error so
     * callers always receive a safe iterable.
     *
     * @param int $clientId WHMCS client ID.
     * @param int $limit    Maximum number of rows to return (default 10).
     * @return array<int, object> Eloquent row objects from mod_fps_behavioral_events.
     */
    public function getClientBehavioralHistory(int $clientId, int $limit = 10): array
    {
        try {
            return Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->orderBy('created_at', 'desc')
                ->limit(max(1, $limit))
                ->get()
                ->all();
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsBehavioralScoringEngine::getClientBehavioralHistory',
                json_encode(['client_id' => $clientId, 'limit' => $limit]),
                $e->getMessage()
            );
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Score based on how quickly the form was filled.
     *
     * Thresholds:
     *   < 3 000 ms  : +40  (impossibly fast -- headless bot)
     *   < 8 000 ms  : +25  (suspiciously fast)
     *   < 15 000 ms : +10  (fast but plausible)
     *   >= 15 000 ms:   0  (normal human speed)
     *
     * Zero milliseconds is treated as missing data and scores 0 to avoid
     * false positives when the client payload is incomplete.
     *
     * @param int $ms Total form fill time in milliseconds.
     * @return float Score contribution (0, 10, 25, or 40).
     */
    private function fps_scoreFormFillSpeed(int $ms): float
    {
        if ($ms <= 0) {
            return 0.0;
        }

        if ($ms < 3_000) {
            return 40.0;
        }

        if ($ms < 8_000) {
            return 25.0;
        }

        if ($ms < 15_000) {
            return 10.0;
        }

        return 0.0;
    }

    /**
     * Score mouse-related behavioral signals.
     *
     * Signal checks:
     *   - Zero mouse movements AND zero touch events : +30 (headless browser)
     *   - Mouse entropy < 0.5 (very linear path)     : +15
     *   - Mouse entropy < 1.0 (low variance path)    : +5
     *
     * Touch events are treated as a legitimate substitute for mouse input
     * (mobile devices).  When touch events are present alongside zero mouse
     * movements the zero-movement penalty is suppressed.
     *
     * @param int   $movements  Total mouse move events recorded during form fill.
     * @param float $entropy    Shannon entropy of mouse movement angles (0-4.0).
     * @param int   $touchEvents Number of touch events (mobile indicator).
     * @return float Score contribution.
     */
    private function fps_scoreMouseBehavior(int $movements, float $entropy, int $touchEvents): float
    {
        $score = 0.0;

        if ($movements === 0 && $touchEvents === 0) {
            $score += 30.0;
        }

        // Entropy checks only apply when there is actual mouse movement to
        // measure; linear paths on headless browsers still get the penalty above
        // but we avoid double-penalizing zero-movement cases here.
        if ($movements > 0) {
            if ($entropy < 0.5) {
                $score += 15.0;
            } elseif ($entropy < 1.0) {
                $score += 5.0;
            }
        }

        return $score;
    }

    /**
     * Score paste events on sensitive form fields.
     *
     * Penalties:
     *   - Paste on credit card field : +20
     *   - Paste on CVV field         : +25
     *   - Paste on multiple fields   : +15 (stacked on top of per-field scores)
     *
     * The multi-field bonus is applied once when more than one paste is detected
     * regardless of which fields were targeted.
     *
     * @param array<int, array{field: string, length: int}> $pasteEvents
     * @return float Score contribution.
     */
    private function fps_scorePasteEvents(array $pasteEvents): float
    {
        if (empty($pasteEvents)) {
            return 0.0;
        }

        $score           = 0.0;
        $cardPasteFound  = false;
        $cvvPasteFound   = false;

        foreach ($pasteEvents as $event) {
            $fieldName = strtolower((string) ($event['field'] ?? ''));

            if (!$cardPasteFound && $this->fps_fieldMatchesPatterns($fieldName, self::CARD_FIELD_PATTERNS)) {
                $score          += 20.0;
                $cardPasteFound  = true;
            }

            if (!$cvvPasteFound && $this->fps_fieldMatchesPatterns($fieldName, self::CVV_FIELD_PATTERNS)) {
                $score         += 25.0;
                $cvvPasteFound  = true;
            }
        }

        // Multi-field paste bonus: triggers when more than one paste event was
        // detected, irrespective of whether those events hit sensitive fields.
        if (count($pasteEvents) > 1) {
            $score += 15.0;
        }

        return $score;
    }

    /**
     * Score keypress cadence using the coefficient of variation of inter-key
     * intervals.
     *
     * CV = stddev / mean.  A very low CV indicates robotic, uniform typing.
     *
     * Thresholds:
     *   CV < 0.1 (robotic uniformity) : +25
     *   CV < 0.2 (very consistent)    : +10
     *   CV >= 0.5 (human-like)        : 0
     *
     * Fewer than two intervals are silently skipped (not enough data).
     *
     * @param array<int, int> $intervals Inter-keypress intervals in milliseconds.
     * @return float Score contribution (0, 10, or 25).
     */
    private function fps_scoreKeypressCadence(array $intervals): float
    {
        // Need at least two intervals to compute a meaningful variance.
        $filtered = array_values(
            array_filter($intervals, static fn(mixed $v): bool => is_numeric($v) && (int) $v > 0)
        );

        if (count($filtered) < 2) {
            return 0.0;
        }

        $cv = $this->fps_calculateCoefficientOfVariation($filtered);

        if ($cv < 0.1) {
            return 25.0;
        }

        if ($cv < 0.2) {
            return 10.0;
        }

        return 0.0;
    }

    /**
     * Calculate the coefficient of variation (stddev / mean) for a set of values.
     *
     * Returns 0.0 when the mean is zero to avoid division-by-zero.  The caller
     * is responsible for ensuring the array has at least two elements before
     * calling this method.
     *
     * @param array<int, int|float> $values Non-empty array of numeric values.
     * @return float Coefficient of variation; 0.0 when undetermined.
     */
    private function fps_calculateCoefficientOfVariation(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;

        if ($mean <= 0.0) {
            return 0.0;
        }

        $sumSquaredDeviations = array_sum(
            array_map(static fn(float $v): float => ($v - $mean) ** 2, $values)
        );

        // Population standard deviation (n denominator) is appropriate here
        // because we are treating the recorded intervals as the full population
        // of interest, not a sample from a larger distribution.
        $stddev = sqrt($sumSquaredDeviations / $count);

        return $stddev / $mean;
    }

    /**
     * Score total time spent on the checkout page.
     *
     * Very short dwell times indicate the page was loaded and submitted
     * programmatically without any meaningful human interaction.
     *
     * Thresholds:
     *   < 5 000 ms  : +20 (automated)
     *   < 15 000 ms : +10 (unusually quick)
     *   >= 15 000 ms:   0 (normal)
     *
     * Zero is treated as missing data and scores 0.
     *
     * @param int $ms Total time on checkout page in milliseconds.
     * @return float Score contribution (0, 10, or 20).
     */
    private function fps_scoreTimeOnPage(int $ms): float
    {
        if ($ms <= 0) {
            return 0.0;
        }

        if ($ms < 5_000) {
            return 20.0;
        }

        if ($ms < 15_000) {
            return 10.0;
        }

        return 0.0;
    }

    /**
     * Check whether a field name contains any of the given pattern substrings.
     *
     * Comparison is case-insensitive (caller should already have lowercased
     * $fieldName before calling this method).
     *
     * @param string        $fieldName Field name to test (lowercased).
     * @param array<string> $patterns  Substrings to look for.
     * @return bool True if any pattern matches.
     */
    private function fps_fieldMatchesPatterns(string $fieldName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($fieldName, $pattern)) {
                return true;
            }
        }
        return false;
    }
}

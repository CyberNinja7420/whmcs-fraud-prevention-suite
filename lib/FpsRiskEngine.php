<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use FraudPreventionSuite\Lib\Models\FpsRiskResult;

/**
 * FpsRiskEngine -- score aggregation engine.
 *
 * Takes raw results from multiple fraud-check providers, applies
 * configurable weight multipliers, aggregates into a single 0-100
 * score, determines the risk level, and collects per-provider
 * breakdowns and individual risk factors.
 *
 * Weight multipliers are stored in mod_fps_settings as
 * "provider_weight_{name}" with a default of 1.0 each.
 *
 * Risk level thresholds are read from the WHMCS module config:
 *   risk_medium_threshold   (default 30)
 *   risk_high_threshold     (default 60)
 *   risk_critical_threshold (default 80)
 */
class FpsRiskEngine
{
    private FpsConfig $config;

    /** @var array<string, float> Default weight per known provider */
    private const DEFAULT_WEIGHTS = [
        'fraudrecord'        => 1.0,
        'ip_intel'           => 1.0,
        'email_verify'       => 1.0,
        'fingerprint'        => 1.0,
        'geo_mismatch'       => 1.0,
        'custom_rules'       => 1.0,
        'velocity'           => 1.0,
        'domain_age'         => 1.0,
        'tor_datacenter'     => 1.3,
        'smtp_verify'        => 0.8,
        'geo_impossibility'  => 1.1,
        'behavioral'         => 0.9,
        'abuseipdb'          => 1.2,
        'ipqualityscore'     => 1.3,
        'abuse_signals'      => 1.1,
        'domain_reputation'  => 1.0,
        'bot_detection'      => 2.0,
        'global_intel'       => 1.5,
    ];

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    /**
     * Aggregate results from all providers into a single FpsRiskResult.
     *
     * Each element in $providerResults should have:
     *   - 'provider'  => string  (provider name, e.g. 'fraudrecord')
     *   - 'score'     => float   (raw score 0-100 from this provider)
     *   - 'details'   => string  (human-readable detail line)
     *   - 'factors'   => array   (optional, individual risk factors)
     *   - 'success'   => bool    (whether the provider call succeeded)
     *
     * Failed providers (success=false) are logged but contribute score 0.
     *
     * @param array<array{provider: string, score: float, details: string, factors?: array, success?: bool}> $providerResults
     * @return FpsRiskResult
     */
    public function aggregate(array $providerResults): FpsRiskResult
    {
        $weights        = $this->getWeights();
        $weightedSum    = 0.0;
        $totalWeight    = 0.0;
        $providerScores = [];
        $details        = [];
        $factors        = [];

        foreach ($providerResults as $result) {
            $provider = $result['provider'] ?? 'unknown';
            $rawScore = (float) ($result['score'] ?? 0.0);
            $success  = (bool) ($result['success'] ?? true);
            $detail   = $result['details'] ?? '';
            $pFactors = $result['factors'] ?? [];

            // Get the weight multiplier -- provider-specified weight overrides default
            $weight = isset($result['weight']) ? (float)$result['weight'] : ($weights[$provider] ?? 1.0);

            if (!$success) {
                // Provider failed -- record but do not penalize the order
                $providerScores[$provider] = 0.0;
                if ($detail !== '') {
                    $details[] = "[{$provider}] SKIPPED: {$detail}";
                }
                continue;
            }

            // Clamp raw score to 0-100 before weighting
            $clampedScore = max(0.0, min(100.0, $rawScore));
            $weightedScore = $clampedScore * $weight;

            $providerScores[$provider] = round($clampedScore, 2);
            $weightedSum += $weightedScore;
            $totalWeight += $weight;

            if ($detail !== '') {
                $details[] = "[{$provider}] {$detail} (score: {$clampedScore}, weight: {$weight})";
            }

            // Collect individual risk factors from this provider
            foreach ($pFactors as $factor) {
                $factors[] = [
                    'factor'   => $factor['factor'] ?? $provider,
                    'score'    => (float) ($factor['score'] ?? $clampedScore),
                    'provider' => $provider,
                ];
            }
        }

        // Calculate final weighted average, capped at 100
        $finalScore = 0.0;
        if ($totalWeight > 0.0) {
            $finalScore = min(100.0, $weightedSum / $totalWeight);
        }

        // Score floor overrides: if specific high-signal providers fire, enforce minimum score
        // This prevents score dilution from many zero-scoring providers
        $botScore = $providerScores['bot_detection'] ?? 0;
        $torDcScore = $providerScores['tor_datacenter'] ?? 0;
        $globalScore = $providerScores['global_intel'] ?? 0;

        $scoreFloor = 0.0;
        if ($botScore >= 30) $scoreFloor = max($scoreFloor, 40.0);   // Bot pattern -> at least MEDIUM
        if ($botScore >= 50) $scoreFloor = max($scoreFloor, 60.0);   // Strong bot -> at least HIGH
        if ($torDcScore >= 20) $scoreFloor = max($scoreFloor, 25.0); // Datacenter IP -> nudge up
        if ($globalScore >= 15) $scoreFloor = max($scoreFloor, 35.0); // Known in global DB -> at least MEDIUM

        if ($scoreFloor > $finalScore) {
            $finalScore = $scoreFloor;
            $details[] = "[score_floor] Score raised from " . round($finalScore - $scoreFloor + $scoreFloor, 1) . " to {$scoreFloor} due to high-confidence signal";
        }

        // Determine risk level from thresholds
        $level = $this->calculateLevel($finalScore);

        return new FpsRiskResult(
            score:          $finalScore,
            level:          $level,
            providerScores: $providerScores,
            details:        $details,
            factors:        $factors,
        );
    }

    /**
     * Calculate risk level from a numeric score using configured thresholds.
     *
     * @param float $score Score 0-100
     * @return string One of: low, medium, high, critical
     */
    public function calculateLevel(float $score): string
    {
        $critical = $this->config->getFloat('risk_critical_threshold', 80.0, 0.0, 100.0);
        $high     = $this->config->getFloat('risk_high_threshold', 60.0, 0.0, 100.0);
        $medium   = $this->config->getFloat('risk_medium_threshold', 30.0, 0.0, 100.0);

        if ($score >= $critical) {
            return 'critical';
        }
        if ($score >= $high) {
            return 'high';
        }
        if ($score >= $medium) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Get provider weight multipliers from config, merged with defaults.
     *
     * Stored in mod_fps_settings as "provider_weight_{name}" = "1.5"
     * Missing providers default to 1.0.
     *
     * @return array<string, float>
     */
    public function getWeights(): array
    {
        $weights = self::DEFAULT_WEIGHTS;

        try {
            $allSettings = $this->config->getAll();
            foreach ($allSettings as $key => $value) {
                if (str_starts_with($key, 'provider_weight_')) {
                    $provider = substr($key, strlen('provider_weight_'));
                    $weights[$provider] = max(0.0, min(5.0, (float) $value));
                }
            }
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsRiskEngine::getWeights',
                '',
                $e->getMessage()
            );
        }

        return $weights;
    }

    /**
     * Merge a RuleResult score into provider results for aggregation.
     *
     * Convenience method: converts a rule engine score into the same
     * format used by external providers so it can be fed into aggregate().
     *
     * @param float  $ruleScore   Score from the rule engine
     * @param array  $ruleDetails Detail lines from matched rules
     * @param array  $ruleFactors Individual rule factors
     * @return array Provider-result-shaped array
     */
    public function fps_ruleScoreToProviderFormat(
        float $ruleScore,
        array $ruleDetails = [],
        array $ruleFactors = []
    ): array {
        return [
            'provider' => 'custom_rules',
            'score'    => $ruleScore,
            'details'  => implode('; ', $ruleDetails),
            'factors'  => $ruleFactors,
            'success'  => true,
        ];
    }
}

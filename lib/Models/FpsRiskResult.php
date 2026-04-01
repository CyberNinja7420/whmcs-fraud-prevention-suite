<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Models;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


/**
 * FpsRiskResult -- output of the RiskEngine aggregation step.
 *
 * Contains the final weighted score, the computed risk level,
 * per-provider breakdowns, human-readable detail lines, and
 * the individual risk factors that contributed to the score.
 */
class FpsRiskResult
{
    /** @var float Final aggregated score (0-100) */
    public readonly float $score;

    /** @var string Risk level: low|medium|high|critical */
    public readonly string $level;

    /** @var array<string, float> Per-provider raw scores keyed by provider name */
    public readonly array $providerScores;

    /** @var array<string> Human-readable detail lines */
    public readonly array $details;

    /** @var array<array{factor: string, score: float, provider: string}> Individual risk factors */
    public readonly array $factors;

    public function __construct(
        float $score,
        string $level,
        array $providerScores = [],
        array $details = [],
        array $factors = []
    ) {
        $this->score          = round(min(max($score, 0.0), 100.0), 2);
        $this->level          = $level;
        $this->providerScores = $providerScores;
        $this->details        = $details;
        $this->factors        = $factors;
    }

    /**
     * Whether the result should trigger an admin notification.
     */
    public function isAlertWorthy(): bool
    {
        return in_array($this->level, ['high', 'critical'], true);
    }

    /**
     * Whether the result should trigger an automatic order lock.
     */
    public function isCritical(): bool
    {
        return $this->level === 'critical';
    }

    /**
     * Serialize to array for JSON storage.
     */
    public function toArray(): array
    {
        return [
            'score'           => $this->score,
            'level'           => $this->level,
            'provider_scores' => $this->providerScores,
            'details'         => $this->details,
            'factors'         => $this->factors,
        ];
    }

    /**
     * Create a zero-risk result (clean).
     */
    public static function clean(): self
    {
        return new self(
            score: 0.0,
            level: 'low',
            providerScores: [],
            details: ['No risk factors detected'],
            factors: [],
        );
    }

    /**
     * Create a result from an error condition (provider failure, etc.).
     * Returns low risk so a provider outage does not block orders.
     */
    public static function fromError(string $errorMessage): self
    {
        return new self(
            score: 0.0,
            level: 'low',
            providerScores: [],
            details: ['Error during risk assessment: ' . $errorMessage],
            factors: [['factor' => 'engine_error', 'score' => 0.0, 'provider' => 'system']],
        );
    }
}

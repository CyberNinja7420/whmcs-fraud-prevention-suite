<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Models;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


/**
 * FpsRuleResult -- output of the FpsRuleEngine evaluator.
 *
 * Captures which rules matched, the cumulative score adjustment,
 * the most severe action recommended, and per-rule detail lines.
 */
class FpsRuleResult
{
    /** @var string Recommended action: none|flag|hold|block */
    public readonly string $action;

    /** @var float Cumulative weighted score from matched rules */
    public readonly float $ruleScore;

    /** @var array<array{rule_id: int, rule_name: string, rule_type: string, action: string, score: float}> */
    public readonly array $matchedRules;

    /** @var array<string> Human-readable detail lines */
    public readonly array $details;

    /** @var int Total number of rules evaluated */
    public readonly int $rulesEvaluated;

    public function __construct(
        string $action = 'none',
        float $ruleScore = 0.0,
        array $matchedRules = [],
        array $details = [],
        int $rulesEvaluated = 0
    ) {
        $this->action         = $action;
        $this->ruleScore      = round($ruleScore, 2);
        $this->matchedRules   = $matchedRules;
        $this->details        = $details;
        $this->rulesEvaluated = $rulesEvaluated;
    }

    /**
     * Whether any rules matched.
     */
    public function hasMatches(): bool
    {
        return count($this->matchedRules) > 0;
    }

    /**
     * Whether the rule result recommends blocking.
     */
    public function isBlocking(): bool
    {
        return $this->action === 'block';
    }

    /**
     * Serialize to array for JSON storage.
     */
    public function toArray(): array
    {
        return [
            'action'          => $this->action,
            'rule_score'      => $this->ruleScore,
            'matched_rules'   => $this->matchedRules,
            'details'         => $this->details,
            'rules_evaluated' => $this->rulesEvaluated,
        ];
    }

    /**
     * Create a result representing no matches.
     */
    public static function noMatch(int $rulesEvaluated = 0): self
    {
        return new self(
            action: 'none',
            ruleScore: 0.0,
            matchedRules: [],
            details: [],
            rulesEvaluated: $rulesEvaluated,
        );
    }
}

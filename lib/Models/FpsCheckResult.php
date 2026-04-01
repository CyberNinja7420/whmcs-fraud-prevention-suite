<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Models;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


/**
 * FpsCheckResult -- final output of a complete fraud check cycle.
 *
 * Wraps the RiskResult, RuleResult, and orchestration metadata
 * (check ID, action taken, lock status, timing) into a single object
 * returned by FpsCheckRunner methods.
 */
class FpsCheckResult
{
    /** @var int|null Database ID from mod_fps_checks (null if not yet persisted) */
    public readonly ?int $checkId;

    /** @var FpsRiskResult Aggregated risk assessment */
    public readonly FpsRiskResult $risk;

    /** @var FpsRuleResult Custom rule evaluation result */
    public readonly FpsRuleResult $rules;

    /** @var string Final action: approved|held|cancelled|error */
    public readonly string $actionTaken;

    /** @var bool Whether the order was locked (set to Fraud status) */
    public readonly bool $locked;

    /** @var FpsCheckContext The context used for this check */
    public readonly FpsCheckContext $context;

    /** @var float Execution time in milliseconds */
    public readonly float $executionMs;

    /** @var string ISO-8601 timestamp when the check was performed */
    public readonly string $checkedAt;

    public function __construct(
        ?int $checkId,
        FpsRiskResult $risk,
        FpsRuleResult $rules,
        string $actionTaken,
        bool $locked,
        FpsCheckContext $context,
        float $executionMs = 0.0,
        string $checkedAt = ''
    ) {
        $this->checkId     = $checkId;
        $this->risk        = $risk;
        $this->rules       = $rules;
        $this->actionTaken = $actionTaken;
        $this->locked      = $locked;
        $this->context     = $context;
        $this->executionMs = round($executionMs, 2);
        $this->checkedAt   = $checkedAt ?: date('Y-m-d H:i:s');
    }

    /**
     * Convenience: final risk score.
     */
    public function getScore(): float
    {
        return $this->risk->score;
    }

    /**
     * Convenience: final risk level string.
     */
    public function getLevel(): string
    {
        return $this->risk->level;
    }

    /**
     * Whether the check resulted in any intervention (not approved).
     */
    public function wasIntervened(): bool
    {
        return $this->actionTaken !== 'approved';
    }

    /**
     * Serialize to array for JSON/API responses.
     */
    public function toArray(): array
    {
        return [
            'check_id'     => $this->checkId,
            'score'        => $this->risk->score,
            'level'        => $this->risk->level,
            'action_taken' => $this->actionTaken,
            'locked'       => $this->locked,
            'execution_ms' => $this->executionMs,
            'checked_at'   => $this->checkedAt,
            'risk'         => $this->risk->toArray(),
            'rules'        => $this->rules->toArray(),
            'context'      => $this->context->toArray(),
        ];
    }

    /**
     * Create an error result when the check pipeline fails entirely.
     */
    public static function fromError(
        FpsCheckContext $context,
        string $errorMessage,
        float $executionMs = 0.0
    ): self {
        return new self(
            checkId:     null,
            risk:        FpsRiskResult::fromError($errorMessage),
            rules:       FpsRuleResult::noMatch(),
            actionTaken: 'error',
            locked:      false,
            context:     $context,
            executionMs: $executionMs,
        );
    }
}

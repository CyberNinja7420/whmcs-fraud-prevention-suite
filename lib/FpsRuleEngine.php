<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use FraudPreventionSuite\Lib\Models\FpsRuleResult;

/**
 * FpsRuleEngine -- custom rules evaluator (v2.0).
 *
 * Migrated from v1.0 fps_evaluateRules() with enhancements:
 *   - Priority ordering (lower number = evaluated first)
 *   - Score weight per rule (multiplied into cumulative score)
 *   - Rule expiration via expires_at column
 *   - Complex multi-condition rules via conditions JSON column
 *   - Additional rule types: domain_age, fingerprint_match
 *   - Proper data class I/O (FpsCheckContext in, FpsRuleResult out)
 *
 * Database table: mod_fps_rules
 * Required columns beyond v1.0 schema:
 *   priority     INT DEFAULT 100
 *   score_weight DECIMAL(4,2) DEFAULT 1.0
 *   conditions   TEXT NULL (JSON)
 *   expires_at   DATETIME NULL
 */
class FpsRuleEngine
{
    private FpsConfig $config;

    /** @var array<string, int> Default base score per rule type */
    private const BASE_SCORES = [
        'ip_block'          => 40,
        'email_pattern'     => 35,
        'country_block'     => 25,
        'velocity'          => 20,
        'amount'            => 15,
        'domain_age'        => 20,
        'fingerprint_match' => 30,
    ];

    /** @var array<string> Action severity order (higher index = more severe) */
    private const ACTION_SEVERITY = ['none', 'flag', 'hold', 'block'];

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    /**
     * Evaluate all enabled, non-expired rules against the given context.
     *
     * Rules are processed in priority order (lower = first).
     * Each matched rule contributes its base score * score_weight
     * to the cumulative rule score. The most severe action among
     * all matched rules becomes the recommended action.
     *
     * @param FpsCheckContext $context Order/client data to evaluate
     * @return FpsRuleResult
     */
    public function evaluate(FpsCheckContext $context): FpsRuleResult
    {
        try {
            $rules = $this->fps_loadEnabledRules();
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'FpsRuleEngine::evaluate',
                $context->toArray(),
                'Failed to load rules: ' . $e->getMessage()
            );
            return FpsRuleResult::noMatch();
        }

        $matchedRules    = [];
        $details         = [];
        $cumulativeScore = 0.0;
        $worstAction     = 'none';
        $rulesEvaluated  = 0;

        foreach ($rules as $rule) {
            $rulesEvaluated++;

            try {
                $matched = $this->fps_evaluateSingleRule($rule, $context);
            } catch (\Throwable $e) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'FpsRuleEngine::evaluateSingle',
                    json_encode(['rule_id' => $rule->id, 'type' => $rule->rule_type]),
                    $e->getMessage()
                );
                continue;
            }

            if (!$matched) {
                continue;
            }

            // Calculate this rule's score contribution
            $baseScore   = self::BASE_SCORES[$rule->rule_type] ?? 10;
            $scoreWeight = (float) ($rule->score_weight ?? 1.0);
            $ruleScore   = $baseScore * $scoreWeight;

            $cumulativeScore += $ruleScore;

            // Track the most severe action
            $ruleAction  = $rule->action ?? 'flag';
            $worstAction = $this->fps_moreServerAction($worstAction, $ruleAction);

            $matchedRules[] = [
                'rule_id'   => (int) $rule->id,
                'rule_name' => $rule->rule_name,
                'rule_type' => $rule->rule_type,
                'action'    => $ruleAction,
                'score'     => round($ruleScore, 2),
            ];

            $details[] = "Rule [{$rule->rule_name}] ({$rule->rule_type}): "
                . "+{$ruleScore} points, action={$ruleAction}";

            // Increment hit counter
            $this->fps_incrementHits((int) $rule->id);
        }

        // Cap cumulative score at 100
        $cumulativeScore = min(100.0, $cumulativeScore);

        return new FpsRuleResult(
            action:         $worstAction,
            ruleScore:      $cumulativeScore,
            matchedRules:   $matchedRules,
            details:        $details,
            rulesEvaluated: $rulesEvaluated,
        );
    }

    // -----------------------------------------------------------------------
    // Rule type evaluators
    // -----------------------------------------------------------------------

    /**
     * Evaluate a single rule against the context.
     *
     * @param object          $rule    Row from mod_fps_rules
     * @param FpsCheckContext $context Check context
     * @return bool Whether the rule matched
     */
    private function fps_evaluateSingleRule(object $rule, FpsCheckContext $context): bool
    {
        // Check multi-condition rules first (if conditions JSON is set)
        if (!empty($rule->conditions)) {
            return $this->fps_evaluateConditions($rule->conditions, $context);
        }

        // Simple single-type evaluation
        return match ($rule->rule_type) {
            'ip_block'          => $this->fps_matchIpBlock($rule->rule_value, $context->ip),
            'email_pattern'     => $this->fps_matchEmailPattern($rule->rule_value, $context->email),
            'country_block'     => $this->fps_matchCountryBlock($rule->rule_value, $context->country),
            'velocity'          => $this->fps_matchVelocity($rule->rule_value, $context),
            'amount'            => $this->fps_matchAmount($rule->rule_value, $context->amount),
            'domain_age'        => $this->fps_matchDomainAge($rule->rule_value, $context->domain),
            'fingerprint_match' => $this->fps_matchFingerprint($rule->rule_value, $context->fingerprintHash),
            default             => false,
        };
    }

    /**
     * IP block: exact match or wildcard prefix match.
     * Supports: exact IP, CIDR notation (basic), wildcard prefix (192.168.*)
     */
    private function fps_matchIpBlock(string $ruleValue, string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $patterns = array_map('trim', explode(',', $ruleValue));
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // Exact match
            if ($ip === $pattern) {
                return true;
            }
            // Wildcard match (e.g. "192.168.*")
            if (str_contains($pattern, '*') && fnmatch($pattern, $ip)) {
                return true;
            }
            // CIDR match (e.g. "192.168.1.0/24")
            if (str_contains($pattern, '/') && $this->fps_ipInCidr($ip, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Email pattern: regex match against the full email address.
     * The rule_value is treated as a regex pattern (without delimiters).
     */
    private function fps_matchEmailPattern(string $ruleValue, string $email): bool
    {
        if ($email === '' || $ruleValue === '') {
            return false;
        }
        // Suppress regex errors from bad patterns
        $result = @preg_match('/' . $ruleValue . '/i', $email);
        return $result === 1;
    }

    /**
     * Country block: comma-separated list of ISO-3166-1 alpha-2 country codes.
     */
    private function fps_matchCountryBlock(string $ruleValue, string $country): bool
    {
        if ($country === '') {
            return false;
        }
        $blocked = array_map('trim', explode(',', strtoupper($ruleValue)));
        return in_array(strtoupper($country), $blocked, true);
    }

    /**
     * Velocity: check how many checks from same IP (or client) within a time window.
     * Rule value format: "maxOrders:minutes" (e.g. "3:60" = max 3 in 60 min)
     * Alternative format: "maxOrders:minutes:field" where field is ip|client|email
     */
    private function fps_matchVelocity(string $ruleValue, FpsCheckContext $context): bool
    {
        $parts     = explode(':', $ruleValue);
        $maxOrders = (int) ($parts[0] ?? 3);
        $minutes   = (int) ($parts[1] ?? 60);
        $field     = trim($parts[2] ?? 'ip');
        $since     = date('Y-m-d H:i:s', time() - ($minutes * 60));

        $query = Capsule::table('mod_fps_checks')
            ->where('created_at', '>=', $since);

        switch ($field) {
            case 'client':
                if ($context->clientId <= 0) {
                    return false;
                }
                $query->where('client_id', $context->clientId);
                break;
            case 'email':
                if ($context->email === '') {
                    return false;
                }
                $query->where('email', $context->email);
                break;
            case 'ip':
            default:
                if ($context->ip === '') {
                    return false;
                }
                $query->where('ip_address', $context->ip);
                break;
        }

        $count = $query->count();
        return $count >= $maxOrders;
    }

    /**
     * Amount: triggers if the order amount exceeds the threshold.
     * Rule value: numeric threshold (e.g. "500" or "1000.00")
     */
    private function fps_matchAmount(string $ruleValue, float $amount): bool
    {
        $threshold = (float) $ruleValue;
        if ($threshold <= 0.0) {
            return false;
        }
        return $amount > $threshold;
    }

    /**
     * Domain age: flags if the email domain was registered recently.
     *
     * This is a heuristic check using WHOIS data. In practice this would
     * call an external domain-age API or cached lookup. For now, we check
     * against a list of known disposable/new domain patterns stored in
     * mod_fps_settings as "disposable_domains" (comma-separated).
     *
     * Rule value: minimum age in days (e.g. "30")
     */
    private function fps_matchDomainAge(string $ruleValue, string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        // Check against known disposable domain list
        $disposableList = $this->config->getCustom('disposable_domains', '');
        if ($disposableList !== '') {
            $disposable = array_map('trim', explode(',', strtolower($disposableList)));
            if (in_array(strtolower($domain), $disposable, true)) {
                return true;
            }
        }

        // Check cached domain age from mod_fps_settings
        $cachedAge = $this->config->getCustom('domain_age_' . md5($domain), '');
        if ($cachedAge !== '') {
            $minAgeDays = (int) $ruleValue;
            $ageDays    = (int) $cachedAge;
            return $ageDays < $minAgeDays;
        }

        return false;
    }

    /**
     * Fingerprint match: compares the browser fingerprint hash against known bad hashes.
     * Rule value: comma-separated list of known bad fingerprint hashes.
     */
    private function fps_matchFingerprint(string $ruleValue, string $fingerprintHash): bool
    {
        if ($fingerprintHash === '' || $ruleValue === '') {
            return false;
        }
        $badHashes = array_map('trim', explode(',', $ruleValue));
        return in_array($fingerprintHash, $badHashes, true);
    }

    // -----------------------------------------------------------------------
    // Multi-condition evaluation
    // -----------------------------------------------------------------------

    /**
     * Evaluate a JSON conditions block.
     *
     * Format:
     * {
     *   "operator": "AND",  // or "OR"
     *   "conditions": [
     *     {"type": "country_block", "value": "NG,GH"},
     *     {"type": "amount", "value": "500"}
     *   ]
     * }
     *
     * @param string          $conditionsJson JSON string
     * @param FpsCheckContext $context
     * @return bool
     */
    private function fps_evaluateConditions(string $conditionsJson, FpsCheckContext $context): bool
    {
        $parsed = json_decode($conditionsJson, true);
        if (!is_array($parsed) || empty($parsed['conditions'])) {
            return false;
        }

        $operator   = strtoupper($parsed['operator'] ?? 'AND');
        $conditions = $parsed['conditions'];

        foreach ($conditions as $cond) {
            $type  = $cond['type'] ?? '';
            $value = $cond['value'] ?? '';

            $matched = match ($type) {
                'ip_block'          => $this->fps_matchIpBlock($value, $context->ip),
                'email_pattern'     => $this->fps_matchEmailPattern($value, $context->email),
                'country_block'     => $this->fps_matchCountryBlock($value, $context->country),
                'amount'            => $this->fps_matchAmount($value, $context->amount),
                'domain_age'        => $this->fps_matchDomainAge($value, $context->domain),
                'fingerprint_match' => $this->fps_matchFingerprint($value, $context->fingerprintHash),
                default             => false,
            };

            if ($operator === 'OR' && $matched) {
                return true;
            }
            if ($operator === 'AND' && !$matched) {
                return false;
            }
        }

        // AND: all passed; OR: none matched
        return $operator === 'AND';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Load all enabled, non-expired rules ordered by priority.
     *
     * @return \Illuminate\Support\Collection
     */
    private function fps_loadEnabledRules(): mixed
    {
        $query = Capsule::table('mod_fps_rules')
            ->where('enabled', 1);

        // Only filter by expires_at if the column exists
        try {
            $query->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            });
        } catch (\Throwable $e) {
            // Column may not exist in v1.0 schema -- proceed without expiry filter
        }

        // Order by priority if column exists, otherwise by id
        try {
            $query->orderBy('priority', 'asc');
        } catch (\Throwable $e) {
            $query->orderBy('id', 'asc');
        }

        return $query->get();
    }

    /**
     * Increment the hit counter for a matched rule.
     */
    private function fps_incrementHits(int $ruleId): void
    {
        try {
            Capsule::table('mod_fps_rules')
                ->where('id', $ruleId)
                ->increment('hits');
        } catch (\Throwable $e) {
            // Non-fatal: hit counter failure should not break the check
        }
    }

    /**
     * Return the more severe of two actions.
     */
    private function fps_moreServerAction(string $current, string $candidate): string
    {
        $currentIdx   = array_search($current, self::ACTION_SEVERITY, true);
        $candidateIdx = array_search($candidate, self::ACTION_SEVERITY, true);

        if ($currentIdx === false) {
            $currentIdx = 0;
        }
        if ($candidateIdx === false) {
            $candidateIdx = 0;
        }

        return $candidateIdx > $currentIdx ? $candidate : $current;
    }

    /**
     * Check if an IP falls within a CIDR range.
     */
    private function fps_ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $subnet = $parts[0];
        $mask   = (int) $parts[1];

        if ($mask < 0 || $mask > 32) {
            return false;
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}

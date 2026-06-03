<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * FpsActionTaken -- single source of truth for the mod_fps_checks.action_taken
 * value vocabulary.
 *
 * action_taken is written by many code paths with historically inconsistent
 * strings: the Turnstile pre-checkout path writes 'block', the inline pipeline
 * and API path write 'blocked', FpsCheckRunner writes 'cancelled'/'held'/
 * 'approved', the admin actions write 'denied'/'archived'/'flagged', and the
 * bot-cleanup path writes 'purged'. Any query that filtered on a single exact
 * value (e.g. action_taken = 'blocked') silently under-counted, which is why
 * the Performance Metrics / Home Widget / digest "blocked" figures read far
 * lower than reality (they missed the Turnstile 'block' rows that make up
 * almost all blocks).
 *
 * Every read query that filters or groups on action_taken MUST use these sets
 * via whereIn(...), so "blocked" means the same thing everywhere.
 */
final class FpsActionTaken
{
    /** Outcomes that stopped/declined the transaction. */
    public const BLOCK = ['blocked', 'block', 'cancelled', 'denied', 'locked'];

    /** Outcomes that flagged for manual review (not auto-blocked). */
    public const FLAG = ['flagged', 'held', 'review'];

    /** Outcomes that let the transaction proceed. */
    public const ALLOW = ['approved', 'allowed', 'allow'];

    /**
     * @return list<string> the canonical block set
     */
    public static function blockSet(): array
    {
        return self::BLOCK;
    }

    /**
     * @return list<string> the canonical flag set
     */
    public static function flagSet(): array
    {
        return self::FLAG;
    }

    /**
     * @return list<string> the canonical allow set
     */
    public static function allowSet(): array
    {
        return self::ALLOW;
    }
}

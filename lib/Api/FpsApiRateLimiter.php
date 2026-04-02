<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * Token bucket rate limiter backed by mod_fps_rate_limits table.
 *
 * Limits are resolved in this priority order:
 * 1. Per-key overrides (mod_fps_api_keys.rate_limit_per_minute/per_day)
 * 2. Admin-configured tier defaults (mod_fps_settings: rate_limit_{tier}_minute/day)
 * 3. Hardcoded fallback defaults
 */
class FpsApiRateLimiter
{
    /** Hardcoded fallback defaults (used only if DB settings are missing). */
    private const DEFAULTS = [
        'anonymous' => ['per_minute' => 5, 'per_day' => 100],
        'free'      => ['per_minute' => 30, 'per_day' => 5000],
        'basic'     => ['per_minute' => 120, 'per_day' => 50000],
        'premium'   => ['per_minute' => 600, 'per_day' => 500000],
    ];

    /**
     * Get rate limits for a tier, reading admin-configured values from DB.
     *
     * @return array{per_minute: int, per_day: int}
     */
    public function getLimit(string $tier): array
    {
        $defaults = self::DEFAULTS[$tier] ?? self::DEFAULTS['anonymous'];

        try {
            // Read admin-configured limits from mod_fps_settings
            $minuteKey = 'rate_limit_' . $tier . '_minute';
            $dayKey    = 'rate_limit_' . $tier . '_day';

            $minuteVal = Capsule::table('mod_fps_settings')
                ->where('setting_key', $minuteKey)->value('setting_value');
            $dayVal = Capsule::table('mod_fps_settings')
                ->where('setting_key', $dayKey)->value('setting_value');

            return [
                'per_minute' => $minuteVal !== null ? (int)$minuteVal : $defaults['per_minute'],
                'per_day'    => $dayVal !== null ? (int)$dayVal : $defaults['per_day'],
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Get per-key rate limit overrides. If the key has custom limits set,
     * those take priority over tier defaults.
     *
     * @return array{per_minute: int, per_day: int}|null Null if no overrides set
     */
    public function getKeyOverride(int $keyId): ?array
    {
        try {
            $key = Capsule::table('mod_fps_api_keys')
                ->where('id', $keyId)
                ->first(['rate_limit_per_minute', 'rate_limit_per_day']);

            if (!$key) return null;

            // Only return overrides if they've been explicitly set (non-default values)
            $minute = (int)$key->rate_limit_per_minute;
            $day    = (int)$key->rate_limit_per_day;

            // Values of 0 or less mean "use tier default"
            if ($minute <= 0 && $day <= 0) return null;

            return [
                'per_minute' => $minute > 0 ? $minute : 0,
                'per_day'    => $day > 0 ? $day : 0,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the effective rate limit: per-key override > tier setting > hardcoded default.
     *
     * @return array{per_minute: int, per_day: int}
     */
    public function resolveLimit(string $tier, int $keyId = 0): array
    {
        $tierLimit = $this->getLimit($tier);

        if ($keyId > 0) {
            $override = $this->getKeyOverride($keyId);
            if ($override) {
                return [
                    'per_minute' => $override['per_minute'] > 0 ? $override['per_minute'] : $tierLimit['per_minute'],
                    'per_day'    => $override['per_day'] > 0 ? $override['per_day'] : $tierLimit['per_day'],
                ];
            }
        }

        return $tierLimit;
    }

    /**
     * Consume one token. Returns true if allowed, false if rate limited.
     */
    public function consume(string $identifier, array $limit): bool
    {
        try {
            $now = time();
            $bucket = Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->first();

            if (!$bucket) {
                // Create new bucket
                Capsule::table('mod_fps_rate_limits')->insert([
                    'identifier' => $identifier,
                    'tokens' => max(0, $limit['per_minute'] - 1),
                    'last_refill_at' => date('Y-m-d H:i:s', $now),
                    'window_requests' => 1,
                    'window_start_at' => date('Y-m-d H:i:s', $now),
                ]);
                return true;
            }

            // Refill tokens based on elapsed time
            $lastRefill = strtotime($bucket->last_refill_at);
            $elapsed = $now - $lastRefill;
            $refillRate = $limit['per_minute'] / 60.0; // tokens per second
            $newTokens = min($limit['per_minute'], $bucket->tokens + ($elapsed * $refillRate));

            // Check daily window
            $windowStart = strtotime($bucket->window_start_at);
            $windowRequests = (int)$bucket->window_requests;

            if ($now - $windowStart >= 86400) {
                // Reset daily window
                $windowStart = $now;
                $windowRequests = 0;
            }

            // Check limits
            if ($newTokens < 1) {
                // Per-minute limit exceeded
                Capsule::table('mod_fps_rate_limits')
                    ->where('identifier', $identifier)
                    ->update([
                        'tokens' => $newTokens,
                        'last_refill_at' => date('Y-m-d H:i:s', $now),
                    ]);
                return false;
            }

            if ($windowRequests >= $limit['per_day']) {
                // Daily limit exceeded
                return false;
            }

            // Consume one token
            Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->update([
                    'tokens' => $newTokens - 1,
                    'last_refill_at' => date('Y-m-d H:i:s', $now),
                    'window_requests' => $windowRequests + 1,
                    'window_start_at' => date('Y-m-d H:i:s', $windowStart),
                ]);

            return true;

        } catch (\Throwable $e) {
            // On error, allow the request (fail open for rate limiting)
            return true;
        }
    }

    /**
     * Get remaining tokens for an identifier.
     */
    public function getRemaining(string $identifier, array $limit): int
    {
        try {
            $bucket = Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->first();

            if (!$bucket) {
                return $limit['per_minute'];
            }

            $elapsed = time() - strtotime($bucket->last_refill_at);
            $refillRate = $limit['per_minute'] / 60.0;
            $tokens = min($limit['per_minute'], $bucket->tokens + ($elapsed * $refillRate));

            return max(0, (int)floor($tokens));
        } catch (\Throwable $e) {
            return $limit['per_minute'];
        }
    }

    /**
     * Get seconds until next token is available.
     */
    public function getRetryAfter(string $identifier, array $limit): int
    {
        try {
            $bucket = Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->first();

            if (!$bucket) return 0;

            $refillRate = $limit['per_minute'] / 60.0;
            if ($refillRate <= 0) return 60;

            $tokensNeeded = 1 - $bucket->tokens;
            if ($tokensNeeded <= 0) return 0;

            return (int)ceil($tokensNeeded / $refillRate);
        } catch (\Throwable $e) {
            return 60;
        }
    }
}

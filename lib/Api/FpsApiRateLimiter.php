<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * Token bucket rate limiter backed by mod_fps_rate_limits table.
 */
class FpsApiRateLimiter
{
    /**
     * Rate limits per tier.
     *
     * @return array{per_minute: int, per_day: int}
     */
    public function getLimit(string $tier): array
    {
        $limits = [
            'anonymous' => ['per_minute' => 5, 'per_day' => 100],
            'free'      => ['per_minute' => 30, 'per_day' => 5000],
            'basic'     => ['per_minute' => 120, 'per_day' => 50000],
            'premium'   => ['per_minute' => 600, 'per_day' => 500000],
        ];

        return $limits[$tier] ?? $limits['anonymous'];
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

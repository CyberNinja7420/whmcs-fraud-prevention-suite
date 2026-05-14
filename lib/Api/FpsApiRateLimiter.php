<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * Multi-tier token bucket rate limiter for the FPS public API.
 *
 * Limits are resolved in this priority order:
 * 1. Per-key overrides (mod_fps_api_keys.rate_limit_per_minute/per_day)
 * 2. Admin-configured tier defaults (mod_fps_settings: rate_limit_{tier}_minute/day)
 * 3. Hardcoded fallback defaults (TIER_LIMITS)
 *
 * Per-minute enforcement uses a sliding-window counter in mod_fps_api_rate_limits.
 * Per-day enforcement uses a rolling 24h counter on the same table.
 * Both windows auto-reset: minute windows every 60 s, day windows every 86400 s.
 *
 * Overage alerts fire once per day when a key crosses the 80% daily threshold.
 */
class FpsApiRateLimiter
{
    /**
     * Hardcoded tier rate limits.
     * Used as fallback when DB settings (mod_fps_settings) are absent.
     */
    private const TIER_LIMITS = [
        'anonymous' => ['per_minute' => 30,  'per_day' => 1000],
        'free'      => ['per_minute' => 30,  'per_day' => 5000],
        'basic'     => ['per_minute' => 120, 'per_day' => 50000],
        'premium'   => ['per_minute' => 600, 'per_day' => 500000],
    ];

    /** Percentage of daily limit that triggers an overage warning email. */
    private const OVERAGE_THRESHOLD = 0.80;

    // ------------------------------------------------------------------
    // Table bootstrapping
    // ------------------------------------------------------------------

    /**
     * Ensure the mod_fps_api_rate_limits sliding-window table exists.
     * Called lazily on first rate-limit check so the module works even
     * if the migration in _activate hasn't run yet (upgrade path).
     */
    public function fps_ensureRateLimitTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_api_rate_limits')) {
                Capsule::schema()->create('mod_fps_api_rate_limits', function ($table) {
                    $table->increments('id');
                    $table->integer('api_key_id')->index();
                    $table->dateTime('window_start');
                    $table->integer('request_count')->default(1);
                    $table->index(['api_key_id', 'window_start']);
                });
            }
        } catch (\Throwable $e) {
            // Non-fatal -- consume() will fall back to the legacy mod_fps_rate_limits table
        }
    }

    /**
     * Ensure mod_fps_api_keys has the columns needed for tier-aware tracking:
     *   requests_today, requests_month, last_overage_alert, requests_today_date
     */
    public function fps_ensureApiKeyColumns(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_api_keys')) {
                return;
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_today')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->integer('requests_today')->default(0)->after('total_requests');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_today_date')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->date('requests_today_date')->nullable()->after('requests_today');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_month')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->integer('requests_month')->default(0)->after('requests_today_date');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_month_start')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->date('requests_month_start')->nullable()->after('requests_month');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'last_overage_alert')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->dateTime('last_overage_alert')->nullable()->after('requests_month_start');
                });
            }
        } catch (\Throwable $e) {
            // Non-fatal -- counters remain unavailable; limit checks still work via log queries
        }
    }

    // ------------------------------------------------------------------
    // Limit resolution (unchanged priority chain)
    // ------------------------------------------------------------------

    /**
     * Get rate limits for a tier, reading admin-configured values from DB.
     *
     * @return array{per_minute: int, per_day: int}
     */
    public function getLimit(string $tier): array
    {
        $defaults = self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['anonymous'];

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

    // ------------------------------------------------------------------
    // Legacy token-bucket consumer (mod_fps_rate_limits)
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Multi-tier enforcement (new sliding-window layer)
    // ------------------------------------------------------------------

    /**
     * Check whether a request from an API key is within its tier limits.
     *
     * Uses the sliding-window counter table (mod_fps_api_rate_limits) for
     * per-minute checks and the requests_today column on mod_fps_api_keys
     * for per-day checks. Falls back gracefully to mod_fps_api_logs queries
     * when the new columns/tables are not yet present (upgrade path).
     *
     * @param string $apiKey  The raw API key string (used only for identifier hashing)
     * @param string $tier    Tier slug: free, basic, premium, anonymous
     * @param int    $keyId   The mod_fps_api_keys.id (0 for anonymous)
     * @return array{allowed: bool, remaining_minute: int, remaining_day: int, reset_at: string, limit_minute: int, limit_day: int, reason: string}
     */
    public function fps_checkTierLimits(string $apiKey, string $tier, int $keyId = 0): array
    {
        $limit = $this->resolveLimit($tier, $keyId);
        $now   = time();

        $result = [
            'allowed'          => true,
            'remaining_minute' => $limit['per_minute'],
            'remaining_day'    => $limit['per_day'],
            'reset_at'         => date('Y-m-d\TH:i:s\Z', $now + 60),
            'limit_minute'     => $limit['per_minute'],
            'limit_day'        => $limit['per_day'],
            'reason'           => '',
        ];

        try {
            // -- Per-minute check (sliding window in mod_fps_api_rate_limits) --
            $minuteCount = $this->fps_getMinuteWindowCount($keyId, $now);
            $result['remaining_minute'] = max(0, $limit['per_minute'] - $minuteCount);

            if ($minuteCount >= $limit['per_minute']) {
                $result['allowed'] = false;
                $result['reason']  = 'per_minute';
                // Reset at = current window start + 60s
                $windowStart = $this->fps_currentMinuteWindowStart($now);
                $result['reset_at'] = date('Y-m-d\TH:i:s\Z', $windowStart + 60);
                return $result;
            }

            // -- Per-day check (fast path via requests_today column, fallback to log count) --
            $dayCount = $this->fps_getDayRequestCount($keyId, $now);
            $result['remaining_day'] = max(0, $limit['per_day'] - $dayCount);

            if ($dayCount >= $limit['per_day']) {
                $result['allowed'] = false;
                $result['reason']  = 'per_day';
                // Reset at midnight UTC
                $result['reset_at'] = date('Y-m-d\TH:i:s\Z', strtotime('tomorrow midnight'));
                return $result;
            }
        } catch (\Throwable $e) {
            // Fail open -- if we can't check, allow the request
            $result['allowed'] = true;
        }

        return $result;
    }

    /**
     * Record a successful request against a key's rate limit windows.
     *
     * Increments:
     *  - mod_fps_api_rate_limits (per-minute sliding window row)
     *  - mod_fps_api_keys.requests_today / requests_month (daily/monthly counters)
     *  - mod_fps_api_keys.total_requests (lifetime counter)
     *
     * @param string $apiKey  Raw API key (not used directly, kept for interface symmetry)
     * @param int    $keyId   mod_fps_api_keys.id (0 = anonymous / IP-based)
     */
    public function fps_recordRequest(string $apiKey, int $keyId): void
    {
        $now = time();

        try {
            // 1. Upsert the per-minute sliding window row
            $this->fps_incrementMinuteWindow($keyId, $now);

            // 2. Update per-key daily/monthly/lifetime counters
            if ($keyId > 0) {
                $this->fps_incrementKeyCounters($keyId, $now);
            }
        } catch (\Throwable $e) {
            // Non-fatal -- never break the API response for counter bookkeeping
        }
    }

    /**
     * Build standard rate-limit response headers.
     *
     * Returns an associative array of header-name => header-value that the
     * router should emit via header() calls. Conforms to the IETF draft
     * RateLimit header fields specification.
     *
     * @return array<string, string>
     */
    public function fps_getRateLimitHeaders(string $apiKey, string $tier, int $keyId = 0): array
    {
        $limit = $this->resolveLimit($tier, $keyId);
        $now   = time();

        try {
            $minuteCount  = $this->fps_getMinuteWindowCount($keyId, $now);
            $dayCount     = $this->fps_getDayRequestCount($keyId, $now);

            $remainMinute = max(0, $limit['per_minute'] - $minuteCount);
            $remainDay    = max(0, $limit['per_day'] - $dayCount);

            // Use the more restrictive of the two windows for the primary header
            $primaryRemaining = min($remainMinute, $remainDay);
            $primaryLimit     = $limit['per_minute']; // per-minute is the burst limit

            // Reset: next minute boundary
            $windowStart = $this->fps_currentMinuteWindowStart($now);
            $resetSeconds = max(0, ($windowStart + 60) - $now);
        } catch (\Throwable $e) {
            $primaryRemaining = $limit['per_minute'];
            $primaryLimit     = $limit['per_minute'];
            $remainMinute     = $limit['per_minute'];
            $remainDay        = $limit['per_day'];
            $resetSeconds     = 60;
        }

        return [
            'X-RateLimit-Limit'          => (string)$primaryLimit,
            'X-RateLimit-Remaining'      => (string)$primaryRemaining,
            'X-RateLimit-Reset'          => (string)$resetSeconds,
            'X-RateLimit-Limit-Minute'   => (string)$limit['per_minute'],
            'X-RateLimit-Remaining-Minute' => (string)$remainMinute,
            'X-RateLimit-Limit-Day'      => (string)$limit['per_day'],
            'X-RateLimit-Remaining-Day'  => (string)$remainDay,
        ];
    }

    /**
     * Send an overage warning email when a key exceeds 80% of its daily limit.
     *
     * Gated by mod_fps_api_keys.last_overage_alert so each key receives
     * at most one alert per calendar day. If the column doesn't exist yet
     * (upgrade path), the alert is skipped silently.
     */
    public function fps_sendOverageAlert(string $apiKey, string $tier, string $keyName, int $keyId): void
    {
        try {
            if ($keyId <= 0) {
                return; // anonymous requests don't get alerts
            }

            $key = Capsule::table('mod_fps_api_keys')
                ->where('id', $keyId)
                ->first();

            if (!$key) {
                return;
            }

            // Check if column exists before reading it
            $hasColumn = Capsule::schema()->hasColumn('mod_fps_api_keys', 'last_overage_alert');
            if ($hasColumn && !empty($key->last_overage_alert)) {
                $lastAlert = strtotime($key->last_overage_alert);
                // Only send once per calendar day
                if (date('Y-m-d', $lastAlert) === date('Y-m-d')) {
                    return;
                }
            }

            // Determine effective daily limit
            $limit = $this->resolveLimit($tier, $keyId);
            $dailyLimit = $limit['per_day'];

            // Get current daily usage
            $dayCount = $this->fps_getDayRequestCount($keyId, time());

            // Only alert at >= 80% threshold
            if ($dayCount < (int)($dailyLimit * self::OVERAGE_THRESHOLD)) {
                return;
            }

            $pctUsed = $dailyLimit > 0 ? round(($dayCount / $dailyLimit) * 100, 1) : 0;

            // Determine recipient: key owner_email, falling back to first admin
            $email = '';
            if (!empty($key->owner_email) && filter_var($key->owner_email, FILTER_VALIDATE_EMAIL)) {
                $email = $key->owner_email;
            } else {
                $email = $this->fps_getFirstAdminEmail();
            }

            if ($email === '') {
                return;
            }

            $subject = "[FPS] API Key '{$keyName}' approaching daily limit ({$pctUsed}%)";
            $tierLabel = ucfirst($tier);
            $remainDay = max(0, $dailyLimit - $dayCount);

            $html = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:600px;margin:0 auto;">
  <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:24px;border-radius:8px 8px 0 0;">
    <h2 style="color:#fff;margin:0;">API Rate Limit Warning</h2>
  </div>
  <div style="background:#1a1f36;padding:24px;color:#e0e0e0;border-radius:0 0 8px 8px;">
    <p>Your API key <strong>{$keyName}</strong> has used <strong>{$pctUsed}%</strong> of its daily request limit.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
      <tr><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);color:#999;">Tier</td><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);font-weight:600;">{$tierLabel}</td></tr>
      <tr><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);color:#999;">Requests Today</td><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);font-weight:600;">{$dayCount}</td></tr>
      <tr><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);color:#999;">Daily Limit</td><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);font-weight:600;">{$dailyLimit}</td></tr>
      <tr><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);color:#999;">Remaining</td><td style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.1);font-weight:600;color:#f6993f;">{$remainDay}</td></tr>
    </table>
    <p style="font-size:0.9em;color:#999;">Once the daily limit is reached, requests will receive HTTP 429 responses until the window resets at midnight UTC. Consider upgrading your API tier for higher limits.</p>
  </div>
</div>
HTML;

            $sent = false;
            if (function_exists('localAPI')) {
                $result = localAPI('SendEmail', [
                    'id'             => 0,
                    'customtype'     => 'general',
                    'customsubject'  => $subject,
                    'custommessage'  => $html,
                    'type'           => 'general',
                    'email'          => $email,
                ]);
                $sent = isset($result['result']) && $result['result'] === 'success';
            }

            if (!$sent) {
                // Fallback: PHP mail()
                $headers = implode("\r\n", [
                    'MIME-Version: 1.0',
                    'Content-type: text/html; charset=UTF-8',
                    'From: FPS Rate Limiter <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
                ]);
                $sent = @mail($email, $subject, $html, $headers);
            }

            // Mark alert sent regardless of delivery success to prevent spam
            if ($hasColumn) {
                Capsule::table('mod_fps_api_keys')
                    ->where('id', $keyId)
                    ->update(['last_overage_alert' => date('Y-m-d H:i:s')]);
            }

            if ($sent) {
                logActivity("FPS: Overage alert sent to {$email} for API key '{$keyName}' ({$pctUsed}% of {$dailyLimit}/day)");
            }
        } catch (\Throwable $e) {
            // Non-fatal -- overage alerts are best-effort
            logModuleCall('fraud_prevention_suite', 'fps_sendOverageAlert', $keyName, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Internal sliding-window helpers
    // ------------------------------------------------------------------

    /**
     * Get the start of the current 60-second window (floored to minute boundary).
     */
    private function fps_currentMinuteWindowStart(int $now): int
    {
        return $now - ($now % 60);
    }

    /**
     * Count requests in the current per-minute window for a key.
     * Uses mod_fps_api_rate_limits (new table). Falls back to 0 if table missing.
     */
    private function fps_getMinuteWindowCount(int $keyId, int $now): int
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_api_rate_limits')) {
                // Fallback: use the legacy token bucket remaining count
                return $this->fps_getMinuteCountFromLegacy($keyId);
            }

            $windowStart = date('Y-m-d H:i:s', $this->fps_currentMinuteWindowStart($now));

            $row = Capsule::table('mod_fps_api_rate_limits')
                ->where('api_key_id', $keyId)
                ->where('window_start', $windowStart)
                ->first(['request_count']);

            return $row ? (int)$row->request_count : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Fallback minute count from the legacy mod_fps_rate_limits token bucket.
     * Calculates approximate consumed tokens from remaining token count.
     */
    private function fps_getMinuteCountFromLegacy(int $keyId): int
    {
        try {
            $identifier = $keyId > 0 ? "key:{$keyId}" : "ip:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $bucket = Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->first();

            if (!$bucket) {
                return 0;
            }

            // Estimate consumed = capacity - current tokens (after refill)
            $elapsed = time() - strtotime($bucket->last_refill_at);
            $tier = self::TIER_LIMITS['free']; // rough estimate
            $refillRate = $tier['per_minute'] / 60.0;
            $currentTokens = min($tier['per_minute'], $bucket->tokens + ($elapsed * $refillRate));

            return max(0, $tier['per_minute'] - (int)floor($currentTokens));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get today's request count for a key.
     * Fast path: reads mod_fps_api_keys.requests_today if the column exists
     * and the date matches. Slow fallback: counts from mod_fps_api_logs.
     */
    private function fps_getDayRequestCount(int $keyId, int $now): int
    {
        if ($keyId <= 0) {
            // Anonymous: count from rate_limits window
            return $this->fps_getAnonymousDayCount($now);
        }

        try {
            // Fast path: use the denormalized counter column
            if (Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_today')) {
                $key = Capsule::table('mod_fps_api_keys')
                    ->where('id', $keyId)
                    ->first(['requests_today', 'requests_today_date']);

                if ($key && $key->requests_today_date === date('Y-m-d', $now)) {
                    return (int)$key->requests_today;
                }
                // Date mismatch -- the counter hasn't been reset yet; fall through to log count
            }

            // Slow fallback: count from mod_fps_api_logs
            if (Capsule::schema()->hasTable('mod_fps_api_logs')) {
                $todayStart = date('Y-m-d 00:00:00', $now);
                return (int)Capsule::table('mod_fps_api_logs')
                    ->where('api_key_id', $keyId)
                    ->where('created_at', '>=', $todayStart)
                    ->count();
            }
        } catch (\Throwable $e) {
            // Fail open
        }

        return 0;
    }

    /**
     * Approximate daily count for anonymous (IP-based) requests.
     * Uses the legacy mod_fps_rate_limits.window_requests counter.
     */
    private function fps_getAnonymousDayCount(int $now): int
    {
        try {
            $identifier = "ip:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $bucket = Capsule::table('mod_fps_rate_limits')
                ->where('identifier', $identifier)
                ->first(['window_requests', 'window_start_at']);

            if (!$bucket) {
                return 0;
            }

            $windowStart = strtotime($bucket->window_start_at);
            if ($now - $windowStart >= 86400) {
                return 0; // window expired, will be reset on next consume()
            }

            return (int)$bucket->window_requests;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Increment the per-minute sliding window row for a key.
     * Upserts into mod_fps_api_rate_limits.
     */
    private function fps_incrementMinuteWindow(int $keyId, int $now): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_api_rate_limits')) {
                return;
            }

            $windowStart = date('Y-m-d H:i:s', $this->fps_currentMinuteWindowStart($now));

            $affected = Capsule::table('mod_fps_api_rate_limits')
                ->where('api_key_id', $keyId)
                ->where('window_start', $windowStart)
                ->increment('request_count');

            if ($affected === 0) {
                // No existing row for this window -- insert one
                Capsule::table('mod_fps_api_rate_limits')->insert([
                    'api_key_id'    => $keyId,
                    'window_start'  => $windowStart,
                    'request_count' => 1,
                ]);
            }

            // Prune old window rows (keep last 10 minutes max)
            $cutoff = date('Y-m-d H:i:s', $now - 600);
            Capsule::table('mod_fps_api_rate_limits')
                ->where('window_start', '<', $cutoff)
                ->delete();
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Increment the per-key daily and monthly counters on mod_fps_api_keys.
     * Resets the counter if the date has rolled over.
     */
    private function fps_incrementKeyCounters(int $keyId, int $now): void
    {
        try {
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'requests_today')) {
                return; // columns not yet migrated
            }

            $today     = date('Y-m-d', $now);
            $monthStart = date('Y-m-01', $now);

            $key = Capsule::table('mod_fps_api_keys')
                ->where('id', $keyId)
                ->first(['requests_today', 'requests_today_date', 'requests_month', 'requests_month_start']);

            if (!$key) {
                return;
            }

            $updates = [
                'total_requests' => Capsule::raw('total_requests + 1'),
                'last_used_at'   => date('Y-m-d H:i:s', $now),
            ];

            // Daily counter: reset if date changed
            if ($key->requests_today_date !== $today) {
                $updates['requests_today']      = 1;
                $updates['requests_today_date'] = $today;
            } else {
                $updates['requests_today'] = Capsule::raw('requests_today + 1');
            }

            // Monthly counter: reset if month changed
            if (!$key->requests_month_start || substr($key->requests_month_start, 0, 7) !== substr($monthStart, 0, 7)) {
                $updates['requests_month']       = 1;
                $updates['requests_month_start'] = $monthStart;
            } else {
                $updates['requests_month'] = Capsule::raw('requests_month + 1');
            }

            Capsule::table('mod_fps_api_keys')
                ->where('id', $keyId)
                ->update($updates);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Get the first admin email from WHMCS tbladmins.
     */
    private function fps_getFirstAdminEmail(): string
    {
        try {
            $admin = Capsule::table('tbladmins')
                ->where('disabled', 0)
                ->orderBy('id')
                ->value('email');
            return $admin ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

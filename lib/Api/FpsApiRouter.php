<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * Public REST API router for the Fraud Prevention Suite.
 * Routes requests to FpsApiController methods with authentication
 * and multi-tier rate limiting.
 */
class FpsApiRouter
{
    private FpsApiAuth $auth;
    private FpsApiRateLimiter $rateLimiter;
    private FpsApiController $controller;

    public function __construct()
    {
        $this->auth = new FpsApiAuth();
        $this->rateLimiter = new FpsApiRateLimiter();
        $this->controller = new FpsApiController();
    }

    public function handle(): void
    {
        header('Content-Type: application/json');

        // CORS policy.
        //
        // Intent (documented): the public read-only endpoints (/v1/stats/global,
        // /v1/topology/*) may be called from browsers on any origin. Authenticated
        // endpoints (/v1/lookup/*, /v1/reports/*) require an X-FPS-API-Key header
        // and should NOT be CORS-enabled for credentialed requests from unknown
        // origins.
        //
        // Browser requests are distinguishable by the Origin header. If origin is
        // in our allowlist, echo it back (enables credentialed requests from our
        // own pages). Otherwise:
        //   - For OPTIONS (preflight) and GET to public endpoints: serve * so
        //     third-party dashboards can embed threat data.
        //   - For requests carrying an API key: do NOT emit * (browsers reject
        //     credentialed requests with Access-Control-Allow-Origin: *, and we
        //     want them to go server-to-server, not browser-to-FPS).
        $origin            = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedPatterns   = ['enterprisevpssolutions.com', 'localhost'];
        $originAllowed     = false;
        foreach ($allowedPatterns as $pattern) {
            if ($origin !== '' && str_contains($origin, $pattern)) { $originAllowed = true; break; }
        }

        $hasApiKey = !empty($_SERVER['HTTP_X_FPS_API_KEY']) || !empty($_GET['api_key']);

        if ($originAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        } elseif (!$hasApiKey) {
            // Public/anonymous request from an unknown origin - safe to serve *
            // because no credentials are involved.
            header('Access-Control-Allow-Origin: *');
        }
        // else: API-key request from an unknown origin - emit NO CORS header.
        // A browser client attempting credentialed access from an unknown origin
        // will be blocked by the browser's CORS enforcement, which is what we want.

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-FPS-API-Key, Content-Type');
        header('X-FPS-Version: ' . (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown'));

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $startTime = microtime(true);

        try {
            // Check if API is enabled
            $enabled = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'public_api_enabled')
                ->value('setting_value');

            if ($enabled !== '1') {
                $this->respondError(503, 'API is currently disabled');
                return;
            }

            // Parse endpoint
            $endpoint = trim($_GET['endpoint'] ?? $_GET['e'] ?? '/v1/stats/global');
            $endpoint = '/' . ltrim($endpoint, '/');
            $method = $_SERVER['REQUEST_METHOD'];

            // Authenticate
            $authResult = $this->auth->authenticate();
            $tier = $authResult['tier'];
            $keyId = $authResult['key_id'];

            // Extract raw API key for rate limiter (used as identifier seed)
            $rawApiKey = $this->fps_extractRawApiKey();

            // ----------------------------------------------------------
            // Multi-tier rate limiting
            //
            // Layer 1: Tier-aware sliding-window check (new)
            //   Uses mod_fps_api_rate_limits for per-minute windows and
            //   mod_fps_api_keys.requests_today for per-day checks.
            //
            // Layer 2: Legacy token-bucket (mod_fps_rate_limits)
            //   Kept as a secondary backstop. Both layers must pass.
            // ----------------------------------------------------------

            // Ensure the new rate limit table exists (lazy migration)
            $this->rateLimiter->fps_ensureRateLimitTable();
            $this->rateLimiter->fps_ensureApiKeyColumns();

            // Layer 1: tier-aware check
            $tierCheck = $this->rateLimiter->fps_checkTierLimits(
                $rawApiKey,
                $tier,
                (int)($keyId ?? 0)
            );

            if (!$tierCheck['allowed']) {
                // Emit full rate limit headers before the 429
                $rateLimitHeaders = $this->rateLimiter->fps_getRateLimitHeaders(
                    $rawApiKey, $tier, (int)($keyId ?? 0)
                );
                foreach ($rateLimitHeaders as $headerName => $headerValue) {
                    header("{$headerName}: {$headerValue}");
                }

                $retryAfter = $tierCheck['reason'] === 'per_day'
                    ? max(1, (int)(strtotime('tomorrow midnight') - time()))
                    : max(1, 60 - (time() % 60));
                header('Retry-After: ' . $retryAfter);

                $limitType = $tierCheck['reason'] === 'per_day' ? 'daily' : 'per-minute';
                $this->respondError(429, "Rate limit exceeded ({$limitType} limit for {$tier} tier)", [
                    'retry_after'       => $retryAfter,
                    'limit_minute'      => $tierCheck['limit_minute'],
                    'limit_day'         => $tierCheck['limit_day'],
                    'remaining_minute'  => $tierCheck['remaining_minute'],
                    'remaining_day'     => $tierCheck['remaining_day'],
                    'reset_at'          => $tierCheck['reset_at'],
                ]);
                $this->fps_logRequest($keyId, $endpoint, $method, 429, $startTime);
                return;
            }

            // Layer 2: legacy token-bucket backstop
            $identifier = $keyId ? "key:{$keyId}" : "ip:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $limit = $this->rateLimiter->resolveLimit($tier, (int)($keyId ?? 0));

            if (!$this->rateLimiter->consume($identifier, $limit)) {
                $rateLimitHeaders = $this->rateLimiter->fps_getRateLimitHeaders(
                    $rawApiKey, $tier, (int)($keyId ?? 0)
                );
                foreach ($rateLimitHeaders as $headerName => $headerValue) {
                    header("{$headerName}: {$headerValue}");
                }

                $retryAfter = $this->rateLimiter->getRetryAfter($identifier, $limit);
                header('Retry-After: ' . $retryAfter);
                $this->respondError(429, 'Rate limit exceeded', [
                    'retry_after'       => $retryAfter,
                    'limit_minute'      => $limit['per_minute'],
                    'limit_day'         => $limit['per_day'],
                ]);
                $this->fps_logRequest($keyId, $endpoint, $method, 429, $startTime);
                return;
            }

            // Check endpoint access
            if (!$this->auth->canAccess($tier, $endpoint)) {
                $this->respondError(403, 'Endpoint not available on your tier. Upgrade at the API Keys page.');
                $this->fps_logRequest($keyId, $endpoint, $method, 403, $startTime);
                return;
            }

            // Route to controller
            $response = $this->route($endpoint, $method);
            $responseCode = $response['code'] ?? 200;

            // Record the successful request in both the sliding window and key counters.
            // This is done AFTER routing so only successful dispatches consume quota.
            $this->rateLimiter->fps_recordRequest($rawApiKey, (int)($keyId ?? 0));

            // Emit full rate limit headers on the successful response
            $rateLimitHeaders = $this->rateLimiter->fps_getRateLimitHeaders(
                $rawApiKey, $tier, (int)($keyId ?? 0)
            );
            foreach ($rateLimitHeaders as $headerName => $headerValue) {
                header("{$headerName}: {$headerValue}");
            }

            http_response_code($responseCode);
            echo json_encode([
                'success' => $responseCode === 200,
                'data' => $response['data'] ?? null,
                'error' => $response['error'] ?? null,
                'meta' => [
                    'request_id' => 'fps_' . bin2hex(random_bytes(8)),
                    'tier' => $tier,
                    'rate_limit' => [
                        'limit_minute'      => (int)($rateLimitHeaders['X-RateLimit-Limit-Minute'] ?? $limit['per_minute']),
                        'remaining_minute'  => (int)($rateLimitHeaders['X-RateLimit-Remaining-Minute'] ?? 0),
                        'limit_day'         => (int)($rateLimitHeaders['X-RateLimit-Limit-Day'] ?? $limit['per_day']),
                        'remaining_day'     => (int)($rateLimitHeaders['X-RateLimit-Remaining-Day'] ?? 0),
                        'reset'             => (int)($rateLimitHeaders['X-RateLimit-Reset'] ?? 60),
                    ],
                    'response_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                ],
            ], JSON_UNESCAPED_UNICODE);

            $this->fps_logRequest($keyId, $endpoint, $method, $responseCode, $startTime);

            // Check for overage alert (async-safe: non-blocking, best-effort)
            if ($keyId) {
                try {
                    $keyRow = Capsule::table('mod_fps_api_keys')
                        ->where('id', $keyId)
                        ->first(['name']);
                    $keyName = $keyRow->name ?? ('Key #' . $keyId);
                    $this->rateLimiter->fps_sendOverageAlert($rawApiKey, $tier, $keyName, (int)$keyId);
                } catch (\Throwable $e) {
                    // Non-fatal
                }
            }

        } catch (\Throwable $e) {
            $this->respondError(500, 'Internal server error');
            logModuleCall('fraud_prevention_suite', 'API:Error', $_GET, $e->getMessage());
        }
    }

    private function route(string $endpoint, string $method): array
    {
        // Normalize endpoint
        $endpoint = rtrim($endpoint, '/');

        $routes = [
            'GET /v1/stats/global'       => 'statsGlobal',
            'GET /v1/topology/hotspots'   => 'topologyHotspots',
            'GET /v1/topology/events'     => 'topologyEvents',
            'GET /v1/lookup/ip-basic'     => 'lookupIpBasic',
            'GET /v1/lookup/ip-full'      => 'lookupIpFull',
            'GET /v1/lookup/email-basic'  => 'lookupEmailBasic',
            'GET /v1/lookup/email-full'   => 'lookupEmailFull',
            'POST /v1/lookup/bulk'        => 'lookupBulk',
            'GET /v1/reports/country'     => 'reportsCountry',
        ];

        $routeKey = $method . ' ' . $endpoint;

        // Check for parameterized routes
        if (preg_match('#^GET /v1/reports/country/([A-Z]{2})$#', $routeKey, $m)) {
            return $this->controller->reportsCountry($m[1]);
        }

        if (isset($routes[$routeKey])) {
            $handler = $routes[$routeKey];
            return $this->controller->$handler();
        }

        return ['code' => 404, 'error' => 'Endpoint not found: ' . $endpoint];
    }

    private function respondError(int $code, string $message, array $extra = []): void
    {
        http_response_code($code);
        echo json_encode(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extract the raw API key from request headers/params.
     * Returns empty string for anonymous requests.
     */
    private function fps_extractRawApiKey(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-fps-api-key') {
                return trim($value);
            }
        }

        $serverKey = $_SERVER['HTTP_X_FPS_API_KEY'] ?? '';
        if ($serverKey !== '') {
            return trim($serverKey);
        }

        return trim($_GET['api_key'] ?? '');
    }

    /**
     * Log an API request to mod_fps_api_logs and update stats.
     *
     * Note: per-key usage counters (total_requests, requests_today, etc.) are
     * now handled by FpsApiRateLimiter::fps_recordRequest() which is called
     * separately. This method only handles the log table insert and the
     * shared stats collector.
     */
    private function fps_logRequest(?int $keyId, string $endpoint, string $method, int $code, float $startTime): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            // Classify request source:
            //   api_key   = authenticated external consumer with a valid API key
            //   internal  = our own WHMCS pages (topology/overview JS calling the API)
            //   hub       = Global Intel Hub server
            //   anonymous = unauthenticated external request
            $source = 'anonymous';
            if ($keyId) {
                $source = 'api_key';
            } else {
                // Detect internal requests: referrer from our own domain or
                // known internal IPs (WAN IP of the WHMCS server itself)
                $referer = $_SERVER['HTTP_REFERER'] ?? '';
                $systemUrl = Capsule::table('tblconfiguration')
                    ->where('setting', 'SystemURL')
                    ->value('value') ?? '';
                $systemHost = parse_url($systemUrl, PHP_URL_HOST) ?: '';

                if ($systemHost !== '' && str_contains($referer, $systemHost)) {
                    $source = 'internal';
                }
                // Hub server requests carry X-FPS-Hub-Key or X-FPS-Instance-ID
                if (!empty($_SERVER['HTTP_X_FPS_HUB_KEY']) || !empty($_SERVER['HTTP_X_FPS_INSTANCE_ID'])) {
                    $source = 'hub';
                }
            }

            Capsule::table('mod_fps_api_logs')->insert([
                'api_key_id'      => $keyId,
                'endpoint'        => substr($endpoint, 0, 100),
                'method'          => $method,
                'ip_address'      => $ip,
                'user_agent'      => $ua,
                'source'          => $source,
                'request_params'  => json_encode(array_diff_key($_GET, ['api_key' => 1])),
                'response_code'   => $code,
                'response_time_ms'=> (int)((microtime(true) - $startTime) * 1000),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            // Record via shared stats collector - handles the day-row upsert
            // consistently with the hook-based stats paths.
            (new \FraudPreventionSuite\Lib\FpsStatsCollector())->recordEvent('api_request');

        } catch (\Throwable $e) {
            // Non-fatal -- never let logging errors break API responses
        }
    }
}

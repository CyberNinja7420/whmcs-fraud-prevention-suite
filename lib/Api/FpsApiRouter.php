<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * Public REST API router for the Fraud Prevention Suite.
 * Routes requests to FpsApiController methods with authentication and rate limiting.
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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-FPS-API-Key, Content-Type');
        header('X-FPS-Version: 2.0.0');

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

            // Rate limiting (per-key overrides > tier settings > hardcoded defaults)
            $identifier = $keyId ? "key:{$keyId}" : "ip:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $limit = $this->rateLimiter->resolveLimit($tier, (int)($keyId ?? 0));

            if (!$this->rateLimiter->consume($identifier, $limit)) {
                $retryAfter = $this->rateLimiter->getRetryAfter($identifier, $limit);
                header('Retry-After: ' . $retryAfter);
                $this->respondError(429, 'Rate limit exceeded', ['retry_after' => $retryAfter]);
                $this->logRequest($keyId, $endpoint, $method, 429, $startTime);
                return;
            }

            // Check endpoint access
            if (!$this->auth->canAccess($tier, $endpoint)) {
                $this->respondError(403, 'Endpoint not available on your tier. Upgrade at the API Keys page.');
                $this->logRequest($keyId, $endpoint, $method, 403, $startTime);
                return;
            }

            // Route to controller
            $response = $this->route($endpoint, $method);
            $responseCode = $response['code'] ?? 200;

            // Add rate limit headers
            $remaining = $this->rateLimiter->getRemaining($identifier, $limit);
            header('X-RateLimit-Limit: ' . $limit['per_minute']);
            header('X-RateLimit-Remaining: ' . $remaining);

            http_response_code($responseCode);
            echo json_encode([
                'success' => $responseCode === 200,
                'data' => $response['data'] ?? null,
                'error' => $response['error'] ?? null,
                'meta' => [
                    'request_id' => 'fps_' . bin2hex(random_bytes(8)),
                    'tier' => $tier,
                    'rate_limit' => [
                        'remaining' => $remaining,
                        'limit' => $limit['per_minute'],
                    ],
                    'response_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                ],
            ], JSON_UNESCAPED_UNICODE);

            $this->logRequest($keyId, $endpoint, $method, $responseCode, $startTime);

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

    private function logRequest(?int $keyId, string $endpoint, string $method, int $code, float $startTime): void
    {
        try {
            Capsule::table('mod_fps_api_logs')->insert([
                'api_key_id' => $keyId,
                'endpoint' => substr($endpoint, 0, 100),
                'method' => $method,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'request_params' => json_encode(array_diff_key($_GET, ['api_key' => 1])),
                'response_code' => $code,
                'response_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Update daily stats
            $today = date('Y-m-d');
            if (Capsule::table('mod_fps_stats')->where('date', $today)->exists()) {
                Capsule::table('mod_fps_stats')->where('date', $today)->increment('api_requests');
            }

            // Update key usage
            if ($keyId) {
                Capsule::table('mod_fps_api_keys')
                    ->where('id', $keyId)
                    ->update([
                        'last_used_at' => date('Y-m-d H:i:s'),
                        'total_requests' => Capsule::raw('total_requests + 1'),
                    ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}

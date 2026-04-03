<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * API authentication and authorization.
 * Validates API keys and determines access tier.
 */
class FpsApiAuth
{
    /**
     * Endpoint access by tier.
     */
    private const TIER_ACCESS = [
        'anonymous' => [
            '/v1/stats/global',
            '/v1/topology/hotspots',
            '/v1/topology/events',
        ],
        'free' => [
            '/v1/stats/global',
            '/v1/topology/hotspots',
            '/v1/topology/events',
            '/v1/lookup/ip-basic',
        ],
        'basic' => [
            '/v1/stats/global',
            '/v1/topology/hotspots',
            '/v1/topology/events',
            '/v1/lookup/ip-basic',
            '/v1/lookup/ip-full',
            '/v1/lookup/email-basic',
        ],
        'premium' => [
            '/v1/stats/global',
            '/v1/topology/hotspots',
            '/v1/topology/events',
            '/v1/lookup/ip-basic',
            '/v1/lookup/ip-full',
            '/v1/lookup/email-basic',
            '/v1/lookup/email-full',
            '/v1/lookup/bulk',
            '/v1/reports/country',
        ],
    ];

    /**
     * Authenticate the request and return tier info.
     *
     * @return array{tier: string, key_id: int|null}
     */
    public function authenticate(): array
    {
        $apiKey = $this->extractKey();

        if (empty($apiKey)) {
            return ['tier' => 'anonymous', 'key_id' => null];
        }

        $keyHash = hash('sha256', $apiKey);

        $key = Capsule::table('mod_fps_api_keys')
            ->where('key_hash', $keyHash)
            ->where('is_active', 1)
            ->first();

        if (!$key) {
            return ['tier' => 'anonymous', 'key_id' => null];
        }

        // Check expiration
        if ($key->expires_at && strtotime($key->expires_at) < time()) {
            return ['tier' => 'anonymous', 'key_id' => null];
        }

        // Check IP whitelist
        if ($key->ip_whitelist) {
            $whitelist = json_decode($key->ip_whitelist, true);
            if (is_array($whitelist) && !empty($whitelist)) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                if (!in_array($clientIp, $whitelist)) {
                    return ['tier' => 'anonymous', 'key_id' => null];
                }
            }
        }

        return [
            'tier' => $key->tier ?: 'free',
            'key_id' => (int)$key->id,
        ];
    }

    /**
     * Check if a tier can access an endpoint.
     */
    public function canAccess(string $tier, string $endpoint): bool
    {
        // Normalize: strip trailing slash and country code from parameterized routes
        $normalized = rtrim($endpoint, '/');
        if (preg_match('#^/v1/reports/country/[A-Z]{2}$#', $normalized)) {
            $normalized = '/v1/reports/country';
        }

        $allowed = self::TIER_ACCESS[$tier] ?? self::TIER_ACCESS['anonymous'];
        return in_array($normalized, $allowed);
    }

    /**
     * Extract API key from request headers or query params.
     */
    private function extractKey(): string
    {
        // Header: X-FPS-API-Key
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-fps-api-key') {
                return trim($value);
            }
        }

        // Also check standard HTTP_* server vars
        $serverKey = $_SERVER['HTTP_X_FPS_API_KEY'] ?? '';
        if ($serverKey) {
            return trim($serverKey);
        }

        // Query parameter fallback
        return trim($_GET['api_key'] ?? '');
    }
}

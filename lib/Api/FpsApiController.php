<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Api;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * API endpoint handlers for the public Fraud Prevention Suite API.
 * All methods return arrays with 'data' and optionally 'code' and 'error'.
 */
class FpsApiController
{
    /**
     * GET /v1/stats/global -- Public anonymized statistics
     */
    public function statsGlobal(): array
    {
        $since = date('Y-m-d', strtotime('-30 days'));

        $stats = Capsule::table('mod_fps_stats')
            ->where('date', '>=', $since)
            ->selectRaw('SUM(checks_total) as total_checks, SUM(checks_blocked) as total_blocks, SUM(pre_checkout_blocks) as pre_blocks, AVG(avg_risk_score) as avg_score')
            ->first();

        $topCountries = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since . ' 00:00:00')
            ->selectRaw('country_code, COUNT(*) as event_count')
            ->groupBy('country_code')
            ->orderByDesc('event_count')
            ->limit(10)
            ->get()
            ->toArray();

        $riskDistribution = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since . ' 00:00:00')
            ->selectRaw('risk_level, COUNT(*) as count')
            ->groupBy('risk_level')
            ->pluck('count', 'risk_level')
            ->toArray();

        return [
            'data' => [
                'period' => '30d',
                'total_checks' => (int)($stats->total_checks ?? 0),
                'total_blocks' => (int)($stats->total_blocks ?? 0),
                'pre_checkout_blocks' => (int)($stats->pre_blocks ?? 0),
                'avg_risk_score' => round((float)($stats->avg_score ?? 0), 1),
                'top_countries' => $topCountries,
                'risk_distribution' => $riskDistribution,
                'active_countries' => count($topCountries),
            ],
        ];
    }

    /**
     * GET /v1/topology/hotspots -- Fraud hotspot data for globe visualization
     */
    public function topologyHotspots(): array
    {
        // Allow up to 8760 hours (1 year). 0 = all time.
        $hours = (int)($_GET['hours'] ?? 24);
        if ($hours > 0) {
            $hours = min($hours, 8760);
            $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        } else {
            $since = '2000-01-01 00:00:00'; // all time
        }

        $query = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        $hotspots = (clone $query)
            ->selectRaw('ROUND(latitude, 1) as lat, ROUND(longitude, 1) as lng, country_code, COUNT(*) as intensity, AVG(risk_score) as avg_risk, CASE MAX(CASE risk_level WHEN \'critical\' THEN 4 WHEN \'high\' THEN 3 WHEN \'medium\' THEN 2 ELSE 1 END) WHEN 4 THEN \'critical\' WHEN 3 THEN \'high\' WHEN 2 THEN \'medium\' ELSE \'low\' END as max_level')
            ->groupBy(Capsule::raw('ROUND(latitude, 1)'), Capsule::raw('ROUND(longitude, 1)'), 'country_code')
            ->orderByDesc('intensity')
            ->limit(200)
            ->get()
            ->toArray();

        $totalEvents = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)->count();

        $activeCountries = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('country_code')->where('country_code', '!=', '')
            ->distinct()->count('country_code');

        $totalBlocks = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)
            ->whereIn('risk_level', ['high', 'critical'])->count();

        $blockRate = $totalEvents > 0 ? round(($totalBlocks / $totalEvents) * 100) : 0;

        return [
            'data' => [
                'period_hours' => $hours,
                'hotspots' => $hotspots,
                'total_events' => $totalEvents,
                'active_countries' => $activeCountries,
                'total_blocks' => $totalBlocks,
                'block_rate' => $blockRate,
            ],
        ];
    }

    /**
     * GET /v1/topology/events -- Anonymized event feed
     * Accepts: hours (int), since (datetime), limit (int)
     */
    public function topologyEvents(): array
    {
        // Support both 'hours' and 'since' parameters
        $hours = (int)($_GET['hours'] ?? 0);
        if ($hours > 0) {
            $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        } else {
            $since = $_GET['since'] ?? date('Y-m-d H:i:s', time() - 86400);
        }
        $limit = min((int)($_GET['limit'] ?? 100), 500);

        $events = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)
            ->where('is_anonymized', 1)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->select('event_type', 'country_code', 'latitude', 'longitude', 'risk_level', 'risk_score', 'created_at')
            ->get()
            ->toArray();

        return ['data' => ['events' => $events, 'count' => count($events)]];
    }

    /**
     * GET /v1/lookup/ip-basic -- Basic IP intelligence
     */
    public function lookupIpBasic(): array
    {
        $ip = trim($_GET['ip'] ?? '');
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['code' => 400, 'error' => 'Valid IP address required'];
        }

        $intel = Capsule::table('mod_fps_ip_intel')
            ->where('ip_address', $ip)
            ->first();

        if (!$intel) {
            // Try to fetch fresh data
            if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\IpIntelProvider')) {
                $provider = new \FraudPreventionSuite\Lib\Providers\IpIntelProvider();
                $result = $provider->check(['ip' => $ip]);
                $intel = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $ip)
                    ->first();
            }
        }

        if (!$intel) {
            return ['data' => ['ip' => $ip, 'status' => 'not_found']];
        }

        return [
            'data' => [
                'ip' => $ip,
                'country' => $intel->country_code,
                'is_proxy' => (bool)$intel->is_proxy,
                'is_vpn' => (bool)$intel->is_vpn,
                'is_tor' => (bool)$intel->is_tor,
                'is_datacenter' => (bool)$intel->is_datacenter,
                'threat_score' => round((float)$intel->threat_score, 1),
            ],
        ];
    }

    /**
     * GET /v1/lookup/ip-full -- Full IP intelligence
     */
    public function lookupIpFull(): array
    {
        $basic = $this->lookupIpBasic();
        if (isset($basic['error'])) return $basic;

        $ip = trim($_GET['ip'] ?? '');
        $intel = Capsule::table('mod_fps_ip_intel')
            ->where('ip_address', $ip)
            ->first();

        if (!$intel) {
            return $basic;
        }

        return [
            'data' => [
                'ip' => $ip,
                'asn' => $intel->asn,
                'asn_org' => $intel->asn_org,
                'isp' => $intel->isp,
                'country' => $intel->country_code,
                'region' => $intel->region,
                'city' => $intel->city,
                'latitude' => (float)$intel->latitude,
                'longitude' => (float)$intel->longitude,
                'is_proxy' => (bool)$intel->is_proxy,
                'is_vpn' => (bool)$intel->is_vpn,
                'is_tor' => (bool)$intel->is_tor,
                'is_datacenter' => (bool)$intel->is_datacenter,
                'proxy_type' => $intel->proxy_type,
                'threat_score' => round((float)$intel->threat_score, 1),
                'cached_at' => $intel->cached_at,
            ],
        ];
    }

    /**
     * GET /v1/lookup/email-basic -- Basic email intelligence
     */
    public function lookupEmailBasic(): array
    {
        $email = strtolower(trim($_GET['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['code' => 400, 'error' => 'Valid email address required'];
        }

        $intel = Capsule::table('mod_fps_email_intel')
            ->where('email', $email)
            ->first();

        if (!$intel) {
            // Try to fetch fresh data
            if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\EmailValidationProvider')) {
                $provider = new \FraudPreventionSuite\Lib\Providers\EmailValidationProvider();
                $provider->check(['email' => $email, 'domain' => substr($email, strpos($email, '@') + 1)]);
                $intel = Capsule::table('mod_fps_email_intel')
                    ->where('email', $email)
                    ->first();
            }
        }

        if (!$intel) {
            return ['data' => ['email_hash' => hash('sha256', $email), 'status' => 'not_found']];
        }

        return [
            'data' => [
                'email_hash' => $intel->email_hash,
                'domain' => $intel->domain,
                'is_disposable' => (bool)$intel->is_disposable,
                'is_free_provider' => (bool)$intel->is_free_provider,
                'is_role_account' => (bool)$intel->is_role_account,
                'mx_valid' => (bool)$intel->mx_valid,
                'domain_age_days' => $intel->domain_age_days,
            ],
        ];
    }

    /**
     * GET /v1/lookup/email-full -- Full email intelligence
     */
    public function lookupEmailFull(): array
    {
        $basic = $this->lookupEmailBasic();
        if (isset($basic['error'])) return $basic;

        $email = strtolower(trim($_GET['email'] ?? ''));
        $intel = Capsule::table('mod_fps_email_intel')
            ->where('email', $email)
            ->first();

        if (!$intel) return $basic;

        $data = $basic['data'];
        $data['breach_count'] = (int)$intel->breach_count;
        $data['has_social_presence'] = (bool)$intel->has_social_presence;
        $data['deliverability_score'] = round((float)$intel->deliverability_score, 1);
        $data['cached_at'] = $intel->cached_at;

        if ($intel->social_signals) {
            $signals = json_decode($intel->social_signals, true);
            if (is_array($signals)) {
                $data['social_profiles'] = $signals;
            }
        }

        return ['data' => $data];
    }

    /**
     * POST /v1/lookup/bulk -- Bulk lookup (max 100 items)
     */
    public function lookupBulk(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['items'])) {
            return ['code' => 400, 'error' => 'Request body must contain "items" array'];
        }

        $items = array_slice($input['items'], 0, 100);
        $results = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $value = $item['value'] ?? '';

            if ($type === 'ip' && filter_var($value, FILTER_VALIDATE_IP)) {
                $_GET['ip'] = $value;
                $results[] = array_merge(['type' => 'ip', 'value' => $value], $this->lookupIpBasic()['data'] ?? []);
            } elseif ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $_GET['email'] = $value;
                $results[] = array_merge(['type' => 'email', 'value' => $value], $this->lookupEmailBasic()['data'] ?? []);
            } else {
                $results[] = ['type' => $type, 'value' => $value, 'error' => 'Invalid type or value'];
            }
        }

        return ['data' => ['results' => $results, 'count' => count($results)]];
    }

    /**
     * GET /v1/reports/country/<CC> -- Country-level fraud statistics
     */
    public function reportsCountry(string $countryCode = ''): array
    {
        if (empty($countryCode)) {
            $countryCode = strtoupper(trim($_GET['cc'] ?? ''));
        }

        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return ['code' => 400, 'error' => 'Valid 2-letter country code required'];
        }

        $since = date('Y-m-d', strtotime('-30 days'));

        $stats = Capsule::table('mod_fps_geo_events')
            ->where('country_code', $countryCode)
            ->where('created_at', '>=', $since . ' 00:00:00')
            ->selectRaw('COUNT(*) as total_events, AVG(risk_score) as avg_risk, SUM(CASE WHEN risk_level = "critical" THEN 1 ELSE 0 END) as critical_count, SUM(CASE WHEN risk_level = "high" THEN 1 ELSE 0 END) as high_count')
            ->first();

        $daily = Capsule::table('mod_fps_geo_events')
            ->where('country_code', $countryCode)
            ->where('created_at', '>=', $since . ' 00:00:00')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as events, AVG(risk_score) as avg_risk')
            ->groupBy(Capsule::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'data' => [
                'country' => $countryCode,
                'period' => '30d',
                'total_events' => (int)($stats->total_events ?? 0),
                'avg_risk_score' => round((float)($stats->avg_risk ?? 0), 1),
                'critical_events' => (int)($stats->critical_count ?? 0),
                'high_events' => (int)($stats->high_count ?? 0),
                'daily_breakdown' => $daily,
            ],
        ];
    }
}

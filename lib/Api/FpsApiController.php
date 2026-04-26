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

        // Include global intel data for broader timelines (30D+) and ALL
        $globalIntelCount = 0;
        $globalCountries = 0;
        $globalBlocks = 0;
        if ($hours === 0 || $hours >= 720) {
            try {
                // Country centroids for mapping global intel records to globe coordinates
                $centroids = [
                    'US'=>[39.8,-98.5],'GB'=>[54.0,-2.0],'DE'=>[51.2,10.4],'FR'=>[46.6,2.2],
                    'CA'=>[56.1,-106.3],'AU'=>[-25.3,133.8],'NL'=>[52.1,5.3],'BR'=>[-14.2,-51.9],
                    'IN'=>[20.6,78.9],'JP'=>[36.2,138.3],'RU'=>[61.5,105.3],'CN'=>[35.9,104.2],
                    'KR'=>[35.9,127.8],'IT'=>[41.9,12.6],'ES'=>[40.5,-3.7],'SE'=>[60.1,18.6],
                    'PL'=>[51.9,19.1],'UA'=>[48.4,31.2],'RO'=>[45.9,24.9],'ZA'=>[-30.6,22.9],
                    'MX'=>[23.6,-102.5],'AR'=>[-38.4,-63.6],'CO'=>[4.6,-74.3],'TR'=>[38.9,35.2],
                    'SG'=>[1.35,103.8],'HK'=>[22.3,114.2],'ID'=>[-0.8,113.9],'TH'=>[15.9,100.9],
                    'PH'=>[12.9,121.8],'VN'=>[14.1,108.3],'NG'=>[9.1,8.7],'EG'=>[26.8,30.8],
                    'KE'=>[-0.02,37.9],'CL'=>[-35.7,-71.5],'PE'=>[-9.2,-75.0],
                ];

                $globalByCountry = Capsule::table('mod_fps_global_intel')
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->selectRaw('country, COUNT(*) as cnt, AVG(risk_score) as avg_risk, MAX(risk_level) as max_level')
                    ->groupBy('country')
                    ->get();

                foreach ($globalByCountry as $row) {
                    $cc = strtoupper($row->country);
                    if (isset($centroids[$cc])) {
                        $hotspots[] = (object)[
                            'lat' => $centroids[$cc][0],
                            'lng' => $centroids[$cc][1],
                            'country_code' => $cc,
                            'intensity' => (int)$row->cnt,
                            'avg_risk' => round((float)$row->avg_risk, 1),
                            'max_level' => $row->max_level ?: 'low',
                        ];
                    }
                }

                $globalIntelCount = Capsule::table('mod_fps_global_intel')->count();
                $globalCountries = Capsule::table('mod_fps_global_intel')
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->distinct()->count('country');
                $globalBlocks = Capsule::table('mod_fps_global_intel')
                    ->whereIn('risk_level', ['high', 'critical'])->count();
            } catch (\Throwable $e) {
                // Global intel optional - fail silently
            }
        }

        $combinedEvents = $totalEvents + $globalIntelCount;
        // Deduplicate countries: union local + global country codes
        $localCountryCodes = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('country_code')->where('country_code', '!=', '')
            ->distinct()->pluck('country_code')->toArray();
        $globalCountryCodes = [];
        if ($hours === 0 || $hours >= 720) {
            try {
                $globalCountryCodes = Capsule::table('mod_fps_global_intel')
                    ->whereNotNull('country')->where('country', '!=', '')
                    ->distinct()->pluck('country')->toArray();
            } catch (\Throwable $e) {}
        }
        $combinedCountries = count(array_unique(array_merge($localCountryCodes, $globalCountryCodes)));
        $combinedBlocks = $totalBlocks + $globalBlocks;
        $blockRate = $combinedEvents > 0 ? round(($combinedBlocks / $combinedEvents) * 100) : 0;

        return [
            'data' => [
                'period_hours' => $hours,
                'hotspots' => $hotspots,
                'total_events' => $combinedEvents,
                'active_countries' => $combinedCountries,
                'total_blocks' => $combinedBlocks,
                'block_rate' => $blockRate,
                'local_events' => $totalEvents,
                'global_intel_count' => $globalIntelCount,
            ],
        ];
    }

    /**
     * GET /v1/topology/events -- Anonymized event feed
     * Accepts: hours (int), since (datetime), limit (int)
     */
    /**
     * Clamp a user-supplied numeric value into [$min, $max]. Pure
     * helper so callers can pass the value explicitly rather than
     * re-reading $_GET inside business code.
     */
    private static function fps_clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        $value = (int) $raw;
        return max($min, min($max, $value));
    }

    public function topologyEvents(): array
    {
        // Support: hours (int), since (datetime). hours=0 means all time.
        $hours = (int)($_GET['hours'] ?? -1);
        if ($hours > 0) {
            $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        } elseif ($hours === 0) {
            $since = '2000-01-01 00:00:00'; // all time
        } else {
            $since = $_GET['since'] ?? date('Y-m-d H:i:s', time() - 86400);
        }
        $limit = self::fps_clampInt($_GET['limit'] ?? null, 1, 500, 100);

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

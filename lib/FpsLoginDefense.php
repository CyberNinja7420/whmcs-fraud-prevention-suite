<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\Providers\IpIntelProvider;

/**
 * FpsLoginDefense -- Account-Takeover (ATO) detection on client login.
 *
 * WHMCS protects the signup/checkout front door; this closes the returning-
 * customer gap that Kount / IPQS / SEON / Stripe Radar all emphasize. On each
 * successful client login it captures the IP, geo, device fingerprint and
 * user-agent, compares them to the client's own login history, and flags:
 *   - new device (fingerprint never seen for this client)
 *   - new country
 *   - impossible travel (implied speed between consecutive logins > 900 km/h)
 *   - login velocity (too many logins from too many IPs in a short window)
 *
 * It records every login as a check_type='login' row (so it flows into the
 * existing stats / topology / reason-code surfaces), stores the event in
 * mod_fps_login_events for future comparison, and -- per settings -- emails the
 * client on a new-device sign-in and alerts admins on an impossible-travel /
 * high-risk login. All data is real; nothing is simulated.
 *
 * Note: WHMCS fires UserLogin/ClientLogin AFTER authentication, so this layer
 * detects + alerts + records (the industry-standard ATO email-alert model)
 * rather than blocking the in-flight login.
 */
final class FpsLoginDefense
{
    private const TABLE = 'mod_fps_login_events';
    private const MODULE = 'fraud_prevention_suite';

    /** Max plausible travel speed (km/h) between two logins before "impossible". */
    private const MAX_KMH = 900.0;

    /** Login-velocity window + threshold (distinct IPs). */
    private const VELOCITY_WINDOW_MIN = 60;
    private const VELOCITY_MAX_IPS = 4;

    public function isEnabled(): bool
    {
        try {
            $v = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'login_defense_enabled')
                ->value('setting_value');
            // Default ON once the feature ships.
            return $v === null ? true : ((string) $v === '1');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Handle a successful client login. Safe to call from a hook -- never throws.
     *
     * @param int $clientId tblclients.id of the user who logged in.
     */
    public function handleLogin(int $clientId): void
    {
        if ($clientId < 1 || !$this->isEnabled()) {
            return;
        }

        try {
            $ip = $this->clientIp();
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $deviceHash = $this->deviceHash();

            // Resolve geo from the IP-intel cache (populate it if missing).
            [$country, $lat, $lng] = $this->geoForIp($ip);

            $now = date('Y-m-d H:i:s');

            // --- Compare against this client's login history ---
            $history = Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            $isFirstLogin    = (count($history) === 0);
            $knownDevices    = [];
            $knownCountries  = [];
            $last            = null;
            foreach ($history as $h) {
                if (!empty($h->device_hash)) {
                    $knownDevices[$h->device_hash] = true;
                }
                if (!empty($h->country_code)) {
                    $knownCountries[$h->country_code] = true;
                }
                if ($last === null) {
                    $last = $h;
                }
            }

            $isNewDevice  = !$isFirstLogin && $deviceHash !== '' && !isset($knownDevices[$deviceHash]);
            $isNewCountry = !$isFirstLogin && $country !== '' && !isset($knownCountries[$country]);

            // Impossible travel vs the most recent prior login.
            $isImpossible = false;
            $impliedKmh   = 0.0;
            if ($last !== null && $lat !== null && $lng !== null
                && $last->latitude !== null && $last->longitude !== null
                && (float) $last->latitude !== 0.0 && (float) $last->longitude !== 0.0) {
                $km = $this->haversineKm((float) $last->latitude, (float) $last->longitude, $lat, $lng);
                $hours = max(0.0167, (strtotime($now) - strtotime((string) $last->created_at)) / 3600.0);
                $impliedKmh = $km / $hours;
                $isImpossible = $impliedKmh > self::MAX_KMH && $km > 500.0;
            }

            // Login velocity: distinct IPs for this client in the window.
            $distinctIps = Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::VELOCITY_WINDOW_MIN . ' minutes')))
                ->distinct()->count('ip_address');
            $isVelocity = ($distinctIps + 1) > self::VELOCITY_MAX_IPS;

            // --- Risk score from the login signals (weighted, additive, capped) ---
            $providerScores = [];
            if ($isNewDevice)  { $providerScores['login_new_device']  = 25.0; }
            if ($isNewCountry) { $providerScores['login_new_country'] = 20.0; }
            if ($isImpossible) { $providerScores['geo_impossibility'] = 40.0; }
            if ($isVelocity)   { $providerScores['velocity']          = 30.0; }
            $score = min(100.0, array_sum($providerScores));
            $level = $score >= 50 ? 'critical' : ($score >= 25 ? 'high' : ($score >= 10 ? 'medium' : 'low'));

            // --- Persist the login event (history for next time) ---
            Capsule::table(self::TABLE)->insert([
                'client_id'           => $clientId,
                'ip_address'          => $ip ?: null,
                'country_code'        => $country ?: null,
                'latitude'            => $lat,
                'longitude'           => $lng,
                'device_hash'         => $deviceHash ?: null,
                'user_agent'          => $ua ?: null,
                'is_new_device'       => $isNewDevice ? 1 : 0,
                'is_new_country'      => $isNewCountry ? 1 : 0,
                'is_impossible_travel' => $isImpossible ? 1 : 0,
                'is_velocity'         => $isVelocity ? 1 : 0,
                'risk_score'          => $score,
                'created_at'          => $now,
            ]);

            // --- Record as a check so it flows into stats/topology/reason codes ---
            $action = $score >= 50 ? 'flagged' : 'approved';
            try {
                Capsule::table('mod_fps_checks')->insert([
                    'order_id'        => 0,
                    'client_id'       => $clientId,
                    'email'           => $this->clientEmail($clientId),
                    'ip_address'      => $ip ?: null,
                    'country'         => $country ?: null,
                    'check_type'      => 'login',
                    'risk_score'      => $score,
                    'risk_level'      => $level,
                    'action_taken'    => $action,
                    'reviewed_by'     => 0,
                    'provider_scores' => json_encode($providerScores ?: ['login' => 0]),
                    'check_context'   => json_encode([
                        'event'        => 'login',
                        'new_device'   => $isNewDevice,
                        'new_country'  => $isNewCountry,
                        'impossible'   => $isImpossible,
                        'implied_kmh'  => round($impliedKmh),
                        'velocity_ips' => $distinctIps + 1,
                        'user_agent'   => $ua,
                    ]),
                    'is_pre_checkout' => 0,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                if (class_exists('\\FraudPreventionSuite\\Lib\\FpsStatsCollector')) {
                    $ev = $score >= 50 ? 'login_flagged' : 'login_ok';
                    (new FpsStatsCollector())->recordEvent($ev, ['checks_total' => 1]);
                }
            } catch (\Throwable $e) {
                logModuleCall(self::MODULE, 'LoginDefense::recordCheck', (string) $clientId, $e->getMessage());
            }

            // --- Alerts (real emails, gated by settings) ---
            if ($isNewDevice && $this->setting('login_alert_new_device', '1') === '1') {
                $this->notifyClientNewDevice($clientId, $ip, $country, $ua, $now);
            }
            if (($isImpossible || $score >= 50) && $this->setting('login_alert_admin', '1') === '1') {
                $this->notifyAdminSuspiciousLogin($clientId, $ip, $country, $score, $isImpossible, $impliedKmh);
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'LoginDefense::handleLogin', (string) $clientId, $e->getMessage());
        }
    }

    /** Idempotent schema creation -- called from module _activate/_upgrade. */
    public static function ensureSchema(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
            return;
        }
        Capsule::schema()->create(self::TABLE, function ($t) {
            $t->increments('id');
            $t->integer('client_id')->index();
            $t->string('ip_address', 45)->nullable();
            $t->string('country_code', 5)->nullable();
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->string('device_hash', 191)->nullable()->index();
            $t->string('user_agent', 255)->nullable();
            $t->tinyInteger('is_new_device')->default(0);
            $t->tinyInteger('is_new_country')->default(0);
            $t->tinyInteger('is_impossible_travel')->default(0);
            $t->tinyInteger('is_velocity')->default(0);
            $t->double('risk_score')->default(0);
            $t->dateTime('created_at')->nullable()->index();
        });
    }

    // ----------------------------------------------------------------- helpers

    private function setting(string $key, string $default): string
    {
        try {
            $v = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            return $v === null ? $default : (string) $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') {
                $ip = trim(explode(',', $v)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    private function deviceHash(): string
    {
        // The fingerprint JS sets a long-lived fps_device_id cookie.
        $c = $_COOKIE['fps_device_id'] ?? ($_POST['fps_fingerprint_hash'] ?? '');
        return substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $c), 0, 191);
    }

    private function clientEmail(int $clientId): ?string
    {
        try {
            return Capsule::table('tblclients')->where('id', $clientId)->value('email') ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0:string,1:?float,2:?float} [countryCode, lat, lng]
     */
    private function geoForIp(string $ip): array
    {
        if ($ip === '') {
            return ['', null, null];
        }
        try {
            // Run the provider (it caches into mod_fps_ip_intel); ignore score.
            if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\IpIntelProvider')) {
                (new IpIntelProvider())->check(['ip' => $ip]);
            }
            $row = Capsule::table('mod_fps_ip_intel')->where('ip_address', $ip)
                ->first(['country_code', 'latitude', 'longitude']);
            if ($row) {
                return [
                    strtoupper((string) ($row->country_code ?? '')),
                    $row->latitude !== null ? (float) $row->latitude : null,
                    $row->longitude !== null ? (float) $row->longitude : null,
                ];
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
        return ['', null, null];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function notifyClientNewDevice(int $clientId, string $ip, string $country, string $ua, string $when): void
    {
        try {
            $subject = 'Security alert: new sign-in to your account';
            $loc = $country !== '' ? " from {$country}" : '';
            $body = '<p>We noticed a new sign-in to your account' . htmlspecialchars($loc) . '.</p>'
                . '<table style="font-family:sans-serif;font-size:14px;">'
                . '<tr><td><strong>Time:</strong></td><td>' . htmlspecialchars($when) . ' UTC</td></tr>'
                . '<tr><td><strong>IP:</strong></td><td>' . htmlspecialchars($ip) . '</td></tr>'
                . '<tr><td><strong>Location:</strong></td><td>' . htmlspecialchars($country ?: 'Unknown') . '</td></tr>'
                . '<tr><td><strong>Device:</strong></td><td>' . htmlspecialchars(substr($ua, 0, 120)) . '</td></tr>'
                . '</table>'
                . '<p>If this was you, no action is needed. If you don\'t recognize this activity, '
                . 'change your password immediately and contact support.</p>';
            // Send to the specific client via WHMCS SendEmail (valid client id).
            if (function_exists('localAPI')) {
                localAPI('SendEmail', [
                    'customtype'    => 'general',
                    'customsubject' => $subject,
                    'custommessage' => $body,
                    'id'            => $clientId,
                ], 'admin');
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'LoginDefense::notifyClient', (string) $clientId, $e->getMessage());
        }
    }

    private function notifyAdminSuspiciousLogin(int $clientId, string $ip, string $country, float $score, bool $impossible, float $kmh): void
    {
        try {
            $reason = $impossible
                ? 'Impossible travel (' . round($kmh) . ' km/h implied)'
                : 'High login risk score';
            $body = '<p><strong>Suspicious client login detected.</strong></p>'
                . '<ul>'
                . '<li>Client ID: ' . (int) $clientId . '</li>'
                . '<li>Reason: ' . htmlspecialchars($reason) . '</li>'
                . '<li>Risk score: ' . round($score) . '/100</li>'
                . '<li>IP: ' . htmlspecialchars($ip) . ' (' . htmlspecialchars($country ?: '?') . ')</li>'
                . '</ul>';
            if (function_exists('localAPI')) {
                localAPI('SendAdminEmail', [
                    'customsubject'  => '[FPS] Suspicious login - client #' . (int) $clientId,
                    'custommessage'  => $body,
                    'type'           => 'system',
                    'deliverymethod' => 'email',
                ], 'admin');
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'LoginDefense::notifyAdmin', (string) $clientId, $e->getMessage());
        }
    }
}

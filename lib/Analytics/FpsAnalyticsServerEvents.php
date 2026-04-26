<?php
/**
 * FpsAnalyticsServerEvents -- queues + flushes GA4 Measurement Protocol
 * events. Fire-and-log (never throws); honours the enable_server_events
 * master toggle and the analytics_event_sampling_rate setting.
 *
 * 12 known event names live in self::EVENTS as constants for lint-time
 * typo protection.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';
require_once __DIR__ . '/FpsAnalyticsLog.php';

final class FpsAnalyticsServerEvents
{
    public const EVENTS = [
        'pre_checkout_block', 'pre_checkout_allow',
        'turnstile_fail', 'turnstile_pass',
        'high_risk_signup', 'global_intel_hit',
        'geo_impossibility_score', 'velocity_block',
        'admin_review_action', 'api_request',
        'bot_purge', 'module_health',
    ];

    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /** @var array<int, array{name:string, params:array<string,mixed>, client_id:string}> */
    private static array $queue = [];

    /**
     * Queue a server-side event. Call site does NOT need to await -- the
     * queue auto-flushes on shutdown via register_shutdown_function.
     *
     * @param string               $name   One of self::EVENTS, will be prefixed fps_
     * @param array<string, mixed> $params Custom event parameters (max 25)
     * @param string               $cid    Pseudo-client-id (random per request OR FPS check_id)
     */
    public static function send(string $name, array $params = [], string $cid = ''): void
    {
        if (!FpsAnalyticsConfig::isServerEnabled()) return;

        // Sampling
        $rate = (int) FpsAnalyticsConfig::get('analytics_event_sampling_rate', '100');
        if ($rate < 100 && random_int(1, 100) > $rate) return;

        $params['module_version'] = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
        $params['instance_id']    = self::instanceId();

        self::$queue[] = [
            'name'      => 'fps_' . $name,
            'params'    => $params,
            'client_id' => $cid !== '' ? $cid : self::generateCid(),
        ];

        FpsAnalyticsLog::record('fps_' . $name, $params, FpsAnalyticsLog::DEST_GA4_SERVER, 'queued');

        // Auto-flush at shutdown (registered once)
        static $registered = false;
        if (!$registered) { $registered = true; register_shutdown_function([self::class, 'flush']); }
    }

    /** Flush the queue (called automatically; can be called manually for tests). */
    public static function flush(): void
    {
        if (self::$queue === []) return;

        $measurementId = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        $apiSecret     = FpsAnalyticsConfig::get('ga4_api_secret', '');
        if ($measurementId === '' || $apiSecret === '') {
            foreach (self::$queue as $e) {
                FpsAnalyticsLog::record(
                    $e['name'],
                    $e['params'],
                    FpsAnalyticsLog::DEST_GA4_SERVER,
                    'failed',
                    'GA4 credentials not configured (measurement_id or api_secret empty)'
                );
            }
            self::$queue = [];
            return;
        }

        // Group by client_id (Measurement Protocol allows multiple events per
        // POST when they share the same client_id). Each unique CID becomes
        // a separate request -- this is the intentional session-correlation
        // strategy: events tagged with the same FPS check_id end up in the
        // same GA4 session for funnel analysis.
        $byCid = [];
        foreach (self::$queue as $e) { $byCid[$e['client_id']][] = ['name' => $e['name'], 'params' => $e['params']]; }
        self::$queue = [];

        foreach ($byCid as $cid => $events) {
            // Cap at 25 events per request (GA4 limit)
            foreach (array_chunk($events, 25) as $chunk) {
                $payload = json_encode([
                    'client_id' => $cid,
                    'events'    => $chunk,
                ]);
                $url = self::ENDPOINT . '?measurement_id=' . urlencode($measurementId)
                    . '&api_secret=' . urlencode($apiSecret);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                $status = ($code >= 200 && $code < 300) ? 'sent' : 'failed';
                foreach ($chunk as $event) {
                    FpsAnalyticsLog::record(
                        $event['name'],
                        $event['params'],
                        FpsAnalyticsLog::DEST_GA4_SERVER,
                        $status,
                        $status === 'failed' ? "HTTP $code: " . substr((string) ($err ?: $resp), 0, 200) : null
                    );
                }
            }
        }
    }

    private static function generateCid(): string
    {
        return bin2hex(random_bytes(8)) . '.' . time();
    }

    private static function instanceId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? gethostname();
        return substr(sha1((string) $host), 0, 12);
    }
}

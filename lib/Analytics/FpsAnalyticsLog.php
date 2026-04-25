<?php
/**
 * FpsAnalyticsLog -- 30-day rolling event log for the analytics
 * status widget + anomaly detector. Inserts are best-effort
 * (logging failures must never break a real request); reads are
 * cheap (indexed on event_name + created_at).
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

final class FpsAnalyticsLog
{
    public const DEST_GA4_CLIENT = 'ga4_client';
    public const DEST_GA4_SERVER = 'ga4_server';
    public const DEST_CLARITY    = 'clarity';

    public static function record(string $eventName, array $payload, string $destination, string $status, ?string $error = null): void
    {
        try {
            Capsule::table('mod_fps_analytics_log')->insert([
                'event_name'   => substr($eventName, 0, 50),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'destination'  => $destination,
                'status'       => $status,
                'error'        => $error !== null ? substr($error, 0, 65535) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Count events with given name in the last 24h (status=sent). */
    public static function countEventsToday(string $eventName): int
    {
        try {
            return (int) Capsule::table('mod_fps_analytics_log')
                ->where('event_name', $eventName)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->count();
        } catch (\Throwable $e) { return 0; }
    }

    /** Median per-day count over the last $days days, excluding today (status=sent). */
    public static function medianDailyCount(string $eventName, int $days = 14): int
    {
        try {
            $rows = Capsule::table('mod_fps_analytics_log')
                ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                ->where('event_name', $eventName)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d 00:00:00', time() - ($days + 1) * 86400))
                ->where('created_at', '<', date('Y-m-d 00:00:00'))
                ->groupBy('d')
                ->pluck('c')
                ->toArray();
            if ($rows === []) return 0;
            sort($rows);
            // Returns upper median for even-length arrays (e.g. [1,2,3,4] -> 3).
            // Suitable for anomaly detection baselines (slightly conservative).
            return (int) $rows[(int) (count($rows) / 2)];
        } catch (\Throwable $e) { return 0; }
    }

    /** Last successful POST timestamp + 24h-count, by destination. Returns ['ts'=>?string,'count'=>int]. */
    public static function statusSnapshot(string $destination): array
    {
        $out = ['ts' => null, 'count' => 0];
        try {
            $row = Capsule::table('mod_fps_analytics_log')
                ->where('destination', $destination)
                ->where('status', 'sent')
                ->orderByDesc('created_at')
                ->first(['created_at']);
            if ($row) $out['ts'] = $row->created_at;
            $out['count'] = (int) Capsule::table('mod_fps_analytics_log')
                ->where('destination', $destination)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->count();
        } catch (\Throwable $e) { /* keep defaults */ }
        return $out;
    }

    /** Rolling TTL purge -- called from the daily cron. Returns deleted row count. */
    public static function purgeOlderThan(int $days = 30): int
    {
        try {
            return (int) Capsule::table('mod_fps_analytics_log')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
                ->delete();
        } catch (\Throwable $e) { return 0; }
    }
}

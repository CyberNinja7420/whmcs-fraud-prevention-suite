<?php
/**
 * FpsAnalyticsAnomalyDetector -- daily spike-detection for FPS event
 * counts. If today's count of an event exceeds 3x the 14-day median
 * AND is at least 50, record an anomaly + email the admin.
 *
 * Reuses FpsAnalyticsLog for the count queries and fps_sendMail (the
 * existing module-safe mail wrapper) for notifications.
 *
 * Closes Task 18 of docs/plans/2026-04-22-analytics-integration.md.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsLog.php';

final class FpsAnalyticsAnomalyDetector
{
    /** Run the daily check. Returns count of anomalies detected. */
    public static function runDaily(): int
    {
        $events = ['fps_pre_checkout_block', 'fps_turnstile_fail', 'fps_high_risk_signup'];
        $detected = 0;
        foreach ($events as $event) {
            $today  = FpsAnalyticsLog::countEventsToday($event);
            $median = FpsAnalyticsLog::medianDailyCount($event, 14);
            if ($today > $median * 3 && $today > 50) {
                self::record($event, $median, $today);
                self::notify($event, $median, $today);
                $detected++;
            }
        }
        return $detected;
    }

    private static function record(string $event, int $baseline, int $observed): void
    {
        try {
            \WHMCS\Database\Capsule::table('mod_fps_analytics_anomalies')->insert([
                'event_name'     => $event,
                'baseline_count' => $baseline,
                'observed_count' => $observed,
                'detected_at'    => date('Y-m-d H:i:s'),
                'notified_at'    => null,
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    private static function notify(string $event, int $baseline, int $observed): void
    {
        try {
            $admin = \WHMCS\Database\Capsule::table('mod_fps_settings')
                ->where('setting_key', 'notification_email')->value('setting_value');
            if (!$admin) return;
            $subject = "[FPS] $event spike detected ($observed vs median $baseline)";
            $body = "Today: $observed events.\n14-day median: $baseline events.\nThreshold: 3x median + min 50.\n\nLog into FPS admin -> Dashboard -> Analytics Connection Status to investigate.\n";
            if (function_exists('fps_sendMail')) {
                fps_sendMail((string) $admin, $subject, $body);
            }
            \WHMCS\Database\Capsule::table('mod_fps_analytics_anomalies')
                ->where('event_name', $event)
                ->whereNull('notified_at')
                ->orderByDesc('id')
                ->limit(1)
                ->update(['notified_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

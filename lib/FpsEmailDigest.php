<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsEmailDigest -- sends daily/weekly fraud activity summary emails.
 *
 * Collects statistics from mod_fps_checks for the configured period,
 * formats an HTML report, and delivers it via WHMCS localAPI('SendEmail')
 * with a mail() fallback.
 *
 * Self-gates via mod_fps_settings timestamps to prevent double-sends
 * within the same period.
 */
class FpsEmailDigest
{
    private const MODULE_NAME = 'fraud_prevention_suite';

    /**
     * Send a digest email for the given frequency.
     *
     * @param string $frequency 'daily' or 'weekly'
     * @return bool True if sent, false if skipped or failed
     */
    public function sendDigest(string $frequency): bool
    {
        try {
            $config = FpsConfig::getInstance();

            // Check if digest is enabled
            if ($config->getCustom('email_digest_enabled', '1') !== '1') {
                return false;
            }

            // Check if this frequency matches the configured one
            $configuredFreq = $config->getCustom('email_digest_frequency', 'daily');
            if ($frequency === 'daily' && $configuredFreq === 'weekly') {
                // Daily cron fires but user only wants weekly
                return false;
            }
            if ($frequency === 'weekly' && $configuredFreq === 'daily') {
                // Weekly trigger but user only wants daily -- skip
                // (daily already covers it)
                return false;
            }

            // Self-gate: check if we already sent for this period
            if (!$this->fps_shouldSend($frequency)) {
                return false;
            }

            // Get recipients
            $recipients = trim((string) $config->getCustom('email_digest_recipients', ''));
            if ($recipients === '') {
                // Fall back to first admin email
                $recipients = $this->fps_getDefaultAdminEmail();
                if ($recipients === '') {
                    return false;
                }
            }

            // Collect stats for the period
            $stats = $this->fps_collectStats($frequency);

            // Skip sending if there were zero checks (nothing to report)
            if ($stats['total_checks'] === 0) {
                // Still mark as sent so we don't retry every cron tick
                $this->fps_markSent($frequency);
                return false;
            }

            // Format HTML email
            $html = $this->fps_formatHtml($stats, $frequency);
            $subject = $this->fps_buildSubject($stats, $frequency);

            // Send to each recipient
            $recipientList = array_filter(array_map('trim', explode(',', $recipients)));
            $sent = false;

            foreach ($recipientList as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if ($this->fps_sendEmail($email, $subject, $html)) {
                    $sent = true;
                }
            }

            if ($sent) {
                $this->fps_markSent($frequency);
                logActivity("Fraud Prevention: {$frequency} digest sent to " . implode(', ', $recipientList));
            }

            return $sent;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::sendDigest', $frequency, $e->getMessage());
            return false;
        }
    }

    /**
     * Check whether a digest should be sent now (not already sent this period).
     */
    private function fps_shouldSend(string $frequency): bool
    {
        try {
            $settingKey = "last_digest_{$frequency}";
            $lastSent = Capsule::table('mod_fps_settings')
                ->where('setting_key', $settingKey)
                ->value('setting_value');

            if ($lastSent === null || $lastSent === '') {
                return true;
            }

            $lastTs = strtotime($lastSent);
            if ($lastTs === false) {
                return true;
            }

            $now = time();
            if ($frequency === 'daily') {
                // Allow sending if last send was more than 20 hours ago
                return ($now - $lastTs) > 72000;
            }

            // Weekly: allow if last send was more than 6 days ago
            return ($now - $lastTs) > 518400;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_shouldSend', $frequency, $e->getMessage());
            return true; // On error, allow sending
        }
    }

    /**
     * Record that a digest was sent for the given frequency.
     */
    private function fps_markSent(string $frequency): void
    {
        try {
            $settingKey = "last_digest_{$frequency}";
            Capsule::table('mod_fps_settings')->updateOrInsert(
                ['setting_key' => $settingKey],
                ['setting_value' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_markSent', $frequency, $e->getMessage());
        }
    }

    /**
     * Collect fraud activity statistics for the given period.
     *
     * @return array{
     *   total_checks: int,
     *   blocked_count: int,
     *   allowed_count: int,
     *   block_rate: float,
     *   avg_score: float,
     *   top_risk_ips: array,
     *   top_risk_emails: array,
     *   review_queue_count: int,
     *   provider_breakdown: array,
     *   period_start: string,
     *   period_end: string,
     *   high_risk_count: int,
     *   medium_risk_count: int,
     *   low_risk_count: int
     * }
     */
    private function fps_collectStats(string $frequency): array
    {
        $periodStart = $frequency === 'daily'
            ? date('Y-m-d H:i:s', strtotime('-24 hours'))
            : date('Y-m-d H:i:s', strtotime('-7 days'));
        $periodEnd = date('Y-m-d H:i:s');

        $stats = [
            'total_checks'       => 0,
            'blocked_count'      => 0,
            'allowed_count'      => 0,
            'block_rate'         => 0.0,
            'avg_score'          => 0.0,
            'top_risk_ips'       => [],
            'top_risk_emails'    => [],
            'review_queue_count' => 0,
            'provider_breakdown' => [],
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'high_risk_count'    => 0,
            'medium_risk_count'  => 0,
            'low_risk_count'     => 0,
        ];

        try {
            if (!Capsule::schema()->hasTable('mod_fps_checks')) {
                return $stats;
            }

            // Total checks in period
            $stats['total_checks'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->count();

            if ($stats['total_checks'] === 0) {
                return $stats;
            }

            // Blocked / allowed counts
            $stats['blocked_count'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->where(function ($q) {
                    $q->where('action_taken', 'block')
                      ->orWhere('action_taken', 'blocked');
                })
                ->count();

            $stats['allowed_count'] = $stats['total_checks'] - $stats['blocked_count'];

            // Block rate
            $stats['block_rate'] = $stats['total_checks'] > 0
                ? round(($stats['blocked_count'] / $stats['total_checks']) * 100, 1)
                : 0.0;

            // Average risk score
            $stats['avg_score'] = round((float) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->avg('risk_score'), 1);

            // Risk level distribution
            $stats['high_risk_count'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->where('risk_level', 'high')
                ->count();

            $stats['medium_risk_count'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->where('risk_level', 'medium')
                ->count();

            $stats['low_risk_count'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->where('risk_level', 'low')
                ->count();

            // Top 5 risk IPs (by average score, minimum 1 check)
            $stats['top_risk_ips'] = Capsule::table('mod_fps_checks')
                ->select(
                    'ip_address',
                    Capsule::raw('COUNT(*) as check_count'),
                    Capsule::raw('ROUND(AVG(risk_score), 1) as avg_score'),
                    Capsule::raw('MAX(risk_score) as max_score')
                )
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->whereNotNull('ip_address')
                ->where('ip_address', '!=', '')
                ->groupBy('ip_address')
                ->orderByRaw('AVG(risk_score) DESC')
                ->limit(5)
                ->get()
                ->toArray();

            // Top 5 risk emails (by average score)
            $stats['top_risk_emails'] = Capsule::table('mod_fps_checks')
                ->select(
                    'email',
                    Capsule::raw('COUNT(*) as check_count'),
                    Capsule::raw('ROUND(AVG(risk_score), 1) as avg_score'),
                    Capsule::raw('MAX(risk_score) as max_score')
                )
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->groupBy('email')
                ->orderByRaw('AVG(risk_score) DESC')
                ->limit(5)
                ->get()
                ->toArray();

            // Review queue: unreviewed items in period
            $stats['review_queue_count'] = (int) Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->whereNull('reviewed_by')
                ->where('risk_level', '!=', 'low')
                ->count();

            // Provider breakdown from provider_scores JSON column
            $stats['provider_breakdown'] = $this->fps_collectProviderBreakdown($periodStart, $periodEnd);

        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_collectStats', $frequency, $e->getMessage());
        }

        return $stats;
    }

    /**
     * Parse provider_scores JSON from recent checks to build a provider hit summary.
     */
    private function fps_collectProviderBreakdown(string $start, string $end): array
    {
        $breakdown = [];

        try {
            $rows = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $start)
                ->where('created_at', '<=', $end)
                ->whereNotNull('provider_scores')
                ->pluck('provider_scores');

            foreach ($rows as $json) {
                $scores = @json_decode((string) $json, true);
                if (!is_array($scores)) {
                    continue;
                }
                foreach ($scores as $provider => $score) {
                    if (!isset($breakdown[$provider])) {
                        $breakdown[$provider] = [
                            'name'       => $provider,
                            'checks'     => 0,
                            'total_score' => 0.0,
                            'flagged'    => 0,
                        ];
                    }
                    $breakdown[$provider]['checks']++;
                    $scoreVal = is_array($score) ? (float)($score['score'] ?? $score['weighted'] ?? 0) : (float)$score;
                    $breakdown[$provider]['total_score'] += $scoreVal;
                    if ($scoreVal > 0) {
                        $breakdown[$provider]['flagged']++;
                    }
                }
            }

            // Calculate averages and sort by flagged count descending
            foreach ($breakdown as &$p) {
                $p['avg_score'] = $p['checks'] > 0 ? round($p['total_score'] / $p['checks'], 1) : 0;
                unset($p['total_score']);
            }
            unset($p);

            usort($breakdown, function ($a, $b) {
                return $b['flagged'] <=> $a['flagged'];
            });
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_collectProviderBreakdown', '', $e->getMessage());
        }

        return $breakdown;
    }

    /**
     * Format the statistics into an HTML email body.
     */
    private function fps_formatHtml(array $stats, string $frequency): string
    {
        $periodLabel = $frequency === 'daily' ? 'Daily' : 'Weekly';
        $periodRange = date('M j, Y H:i', strtotime($stats['period_start']))
            . ' - '
            . date('M j, Y H:i', strtotime($stats['period_end']));

        // Determine severity color for block rate
        $blockRateColor = '#38ef7d';
        if ($stats['block_rate'] > 30) {
            $blockRateColor = '#ffb347';
        }
        if ($stats['block_rate'] > 60) {
            $blockRateColor = '#eb3349';
        }

        // Build top IPs table rows
        $ipRows = '';
        if (!empty($stats['top_risk_ips'])) {
            foreach ($stats['top_risk_ips'] as $ip) {
                $ipObj = is_array($ip) ? (object) $ip : $ip;
                $safeIp = htmlspecialchars((string)$ipObj->ip_address, ENT_QUOTES, 'UTF-8');
                $ipRows .= "<tr><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">{$safeIp}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$ipObj->check_count}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$ipObj->avg_score}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$ipObj->max_score}</td></tr>";
            }
        } else {
            $ipRows = '<tr><td colspan="4" style="padding:12px;text-align:center;color:#888;">No IP data for this period</td></tr>';
        }

        // Build top emails table rows
        $emailRows = '';
        if (!empty($stats['top_risk_emails'])) {
            foreach ($stats['top_risk_emails'] as $em) {
                $emObj = is_array($em) ? (object) $em : $em;
                $safeEmail = htmlspecialchars((string)$emObj->email, ENT_QUOTES, 'UTF-8');
                $emailRows .= "<tr><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">{$safeEmail}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$emObj->check_count}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$emObj->avg_score}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$emObj->max_score}</td></tr>";
            }
        } else {
            $emailRows = '<tr><td colspan="4" style="padding:12px;text-align:center;color:#888;">No email data for this period</td></tr>';
        }

        // Provider breakdown rows
        $providerRows = '';
        if (!empty($stats['provider_breakdown'])) {
            foreach ($stats['provider_breakdown'] as $p) {
                $safeName = htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8');
                $flagPct = $p['checks'] > 0 ? round(($p['flagged'] / $p['checks']) * 100, 1) : 0;
                $providerRows .= "<tr><td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">{$safeName}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$p['checks']}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$p['flagged']}</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$flagPct}%</td>"
                    . "<td style=\"padding:8px 12px;border-bottom:1px solid #eee;text-align:center;\">{$p['avg_score']}</td></tr>";
            }
        } else {
            $providerRows = '<tr><td colspan="5" style="padding:12px;text-align:center;color:#888;">No provider data for this period</td></tr>';
        }

        $reviewBadge = $stats['review_queue_count'] > 0
            ? "<span style=\"display:inline-block;padding:4px 12px;border-radius:12px;background:#eb3349;color:#fff;font-weight:700;\">{$stats['review_queue_count']} pending review</span>"
            : "<span style=\"display:inline-block;padding:4px 12px;border-radius:12px;background:#38ef7d;color:#1a1d2e;font-weight:700;\">Queue clear</span>";

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<div style="max-width:680px;margin:20px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:32px 28px;color:#ffffff;">
    <h1 style="margin:0;font-size:22px;font-weight:700;">Fraud Prevention Suite</h1>
    <p style="margin:6px 0 0;font-size:14px;opacity:0.9;">{$periodLabel} Digest - {$periodRange}</p>
  </div>

  <!-- Summary Stats -->
  <div style="padding:24px 28px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
      <tr>
        <td style="padding:12px;text-align:center;width:25%;">
          <div style="font-size:28px;font-weight:700;color:#1a1d2e;">{$stats['total_checks']}</div>
          <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Total Checks</div>
        </td>
        <td style="padding:12px;text-align:center;width:25%;">
          <div style="font-size:28px;font-weight:700;color:#eb3349;">{$stats['blocked_count']}</div>
          <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Blocked</div>
        </td>
        <td style="padding:12px;text-align:center;width:25%;">
          <div style="font-size:28px;font-weight:700;color:{$blockRateColor};">{$stats['block_rate']}%</div>
          <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Block Rate</div>
        </td>
        <td style="padding:12px;text-align:center;width:25%;">
          <div style="font-size:28px;font-weight:700;color:#667eea;">{$stats['avg_score']}</div>
          <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">Avg Score</div>
        </td>
      </tr>
    </table>
  </div>

  <!-- Risk Distribution -->
  <div style="padding:0 28px 20px;">
    <h3 style="margin:0 0 12px;font-size:15px;color:#1a1d2e;">Risk Distribution</h3>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
      <tr>
        <td style="padding:8px 16px;background:#fee2e2;border-radius:6px 0 0 6px;text-align:center;">
          <strong style="color:#dc2626;">{$stats['high_risk_count']}</strong>
          <div style="font-size:11px;color:#dc2626;">High</div>
        </td>
        <td style="padding:8px 16px;background:#fef3c7;text-align:center;">
          <strong style="color:#d97706;">{$stats['medium_risk_count']}</strong>
          <div style="font-size:11px;color:#d97706;">Medium</div>
        </td>
        <td style="padding:8px 16px;background:#d1fae5;border-radius:0 6px 6px 0;text-align:center;">
          <strong style="color:#059669;">{$stats['low_risk_count']}</strong>
          <div style="font-size:11px;color:#059669;">Low</div>
        </td>
      </tr>
    </table>
  </div>

  <!-- Review Queue -->
  <div style="padding:0 28px 24px;">
    <h3 style="margin:0 0 8px;font-size:15px;color:#1a1d2e;">Review Queue</h3>
    {$reviewBadge}
  </div>

  <!-- Top Risk IPs -->
  <div style="padding:0 28px 24px;">
    <h3 style="margin:0 0 12px;font-size:15px;color:#1a1d2e;">Top Risk IPs</h3>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
      <tr style="background:#f8fafc;">
        <th style="padding:10px 12px;text-align:left;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">IP Address</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Checks</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Avg Score</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Max Score</th>
      </tr>
      {$ipRows}
    </table>
  </div>

  <!-- Top Risk Emails -->
  <div style="padding:0 28px 24px;">
    <h3 style="margin:0 0 12px;font-size:15px;color:#1a1d2e;">Top Risk Emails</h3>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
      <tr style="background:#f8fafc;">
        <th style="padding:10px 12px;text-align:left;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Email</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Checks</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Avg Score</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Max Score</th>
      </tr>
      {$emailRows}
    </table>
  </div>

  <!-- Provider Breakdown -->
  <div style="padding:0 28px 24px;">
    <h3 style="margin:0 0 12px;font-size:15px;color:#1a1d2e;">Provider Breakdown</h3>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
      <tr style="background:#f8fafc;">
        <th style="padding:10px 12px;text-align:left;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Provider</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Checks</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Flagged</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Flag Rate</th>
        <th style="padding:10px 12px;text-align:center;font-weight:600;color:#667eea;border-bottom:2px solid #e2e8f0;">Avg Score</th>
      </tr>
      {$providerRows}
    </table>
  </div>

  <!-- Footer -->
  <div style="padding:20px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
    <p style="margin:0;font-size:12px;color:#888;">
      Fraud Prevention Suite v4.2.8 -- This is an automated {$periodLabel} digest.
      <br>Configure or disable this in FPS Admin > Settings > Email Digest.
    </p>
  </div>

</div>
</body>
</html>
HTML;
    }

    /**
     * Build the email subject line.
     */
    private function fps_buildSubject(array $stats, string $frequency): string
    {
        $periodLabel = $frequency === 'daily' ? 'Daily' : 'Weekly';
        $blockedTag = $stats['blocked_count'] > 0
            ? " -- {$stats['blocked_count']} blocked"
            : '';

        return "[FPS] {$periodLabel} Fraud Digest: {$stats['total_checks']} checks{$blockedTag}";
    }

    /**
     * Send an HTML email via WHMCS localAPI, with mail() fallback.
     */
    private function fps_sendEmail(string $to, string $subject, string $html): bool
    {
        try {
            // Try WHMCS localAPI SendEmail (custom type, no template required)
            if (function_exists('localAPI')) {
                $result = localAPI('SendEmail', [
                    'id'           => 0,
                    'customtype'   => 'general',
                    'customsubject' => $subject,
                    'custommessage' => $html,
                    'type'         => 'general',
                    'email'        => $to,
                ]);

                if (isset($result['result']) && $result['result'] === 'success') {
                    return true;
                }

                // localAPI failed -- log and try mail() fallback
                logModuleCall(
                    self::MODULE_NAME,
                    'FpsEmailDigest::localAPI_SendEmail',
                    $to,
                    json_encode($result)
                );
            }

            // Fallback: PHP mail()
            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Fraud Prevention Suite <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
                'X-Mailer: FPS-Digest/4.2.8',
            ]);

            return @mail($to, $subject, $html, $headers);
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_sendEmail', $to, $e->getMessage());
            return false;
        }
    }

    /**
     * Get the first admin email address as a fallback recipient.
     */
    private function fps_getDefaultAdminEmail(): string
    {
        try {
            $email = Capsule::table('tbladmins')
                ->where('disabled', 0)
                ->orderBy('id')
                ->value('email');

            return $email ? (string) $email : '';
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'FpsEmailDigest::fps_getDefaultAdminEmail', '', $e->getMessage());
            return '';
        }
    }
}

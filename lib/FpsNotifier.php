<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * FpsNotifier -- notification handler for fraud alerts.
 *
 * Sends admin email notifications and logs WHMCS activity entries
 * when fraud checks exceed configured thresholds.
 *
 * Notification targets:
 *   1. Email (via mail() or WHMCS internal mail if available)
 *   2. WHMCS admin activity log (always)
 *   3. Module call log (always, for audit trail)
 */
class FpsNotifier
{
    private FpsConfig $config;

    private const MODULE_NAME = 'fraud_prevention_suite';

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    /**
     * Send an admin notification for a fraud detection event.
     *
     * Sends email (if configured) and always logs to WHMCS activity log
     * and module call log for audit purposes.
     *
     * @param string $level    Risk level (medium, high, critical)
     * @param int    $orderId  The WHMCS order ID (0 for client-only checks)
     * @param int    $clientId The WHMCS client ID
     * @param float  $score    Final risk score (0-100)
     * @param array  $details  Array of human-readable detail strings
     */
    public function notifyAdmin(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): void {
        // Always log to WHMCS activity log
        $this->fps_logActivity($level, $orderId, $clientId, $score);

        // Always log to module call log for audit trail
        $this->fps_logModuleCall($level, $orderId, $clientId, $score, $details);

        // Send email if configured
        $notifyEmail = $this->config->get('notify_email', '');
        if ($notifyEmail === '') {
            $notifyEmail = $this->config->getCustom('notify_email', '');
        }

        if ($notifyEmail !== '' && in_array($level, ['high', 'critical'], true)) {
            $this->fps_sendEmailNotification($notifyEmail, $level, $orderId, $clientId, $score, $details);
        }
    }

    /**
     * Send a fraud notification email (migrated from v1.0 fps_sendNotification).
     *
     * @param string $to       Recipient email address
     * @param string $level    Risk level
     * @param int    $orderId  Order ID
     * @param int    $clientId Client ID
     * @param float  $score    Risk score
     * @param array  $details  Detail lines
     */
    public function fps_sendNotification(
        string $to,
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): void {
        $this->fps_sendEmailNotification($to, $level, $orderId, $clientId, $score, $details);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Log to WHMCS activity log.
     */
    private function fps_logActivity(string $level, int $orderId, int $clientId, float $score): void
    {
        try {
            $orderStr = $orderId > 0 ? "Order #{$orderId}, " : '';
            $message  = "Fraud Prevention: {$orderStr}Client #{$clientId} flagged as "
                . strtoupper($level) . " risk (score: {$score})";

            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable $e) {
            // Activity logging failure is non-fatal
        }
    }

    /**
     * Log to WHMCS module call log for detailed audit trail.
     */
    private function fps_logModuleCall(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): void {
        try {
            if (function_exists('logModuleCall')) {
                logModuleCall(
                    self::MODULE_NAME,
                    'FraudAlert:' . strtoupper($level),
                    json_encode([
                        'order_id'  => $orderId,
                        'client_id' => $clientId,
                        'score'     => $score,
                    ]),
                    json_encode($details)
                );
            }
        } catch (\Throwable $e) {
            // Module call logging failure is non-fatal
        }
    }

    /**
     * Send the actual email notification.
     */
    private function fps_sendEmailNotification(
        string $to,
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): void {
        try {
            $levelUpper = strtoupper($level);
            $orderStr   = $orderId > 0 ? " - Order #{$orderId}" : '';
            $subject    = "[FPS Alert] {$levelUpper} Risk{$orderStr}";

            // Fetch client info for the email body
            $clientInfo = $this->fps_getClientInfo($clientId);

            $body = "Fraud Prevention Suite - Risk Alert\n"
                . str_repeat('=', 50) . "\n\n"
                . "Risk Level:  {$levelUpper}\n"
                . "Risk Score:  {$score}/100\n"
                . "Time:        " . date('Y-m-d H:i:s T') . "\n\n";

            if ($orderId > 0) {
                $body .= "Order ID:    #{$orderId}\n";
            }

            $body .= "Client ID:   #{$clientId}\n";

            if ($clientInfo !== null) {
                $body .= "Email:       {$clientInfo['email']}\n"
                    . "Name:        {$clientInfo['name']}\n"
                    . "Country:     {$clientInfo['country']}\n";
            }

            if (!empty($details)) {
                $body .= "\nRisk Factors:\n"
                    . str_repeat('-', 40) . "\n";
                foreach ($details as $detail) {
                    $body .= "  - {$detail}\n";
                }
            }

            $body .= "\n" . str_repeat('-', 40) . "\n"
                . "Review this alert in the WHMCS admin panel:\n"
                . "Addons > Fraud Prevention Suite > Checks tab\n";

            // Use WHMCS SystemURL for domain (SERVER_NAME returns cPanel hostname in CLI/cron)
            $hostname = 'localhost';
            try {
                $systemUrl = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', 'SystemURL')->value('value');
                if ($systemUrl) {
                    $hostname = parse_url($systemUrl, PHP_URL_HOST) ?: $hostname;
                }
            } catch (\Throwable $e) {
                $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
            }
            $headers  = "From: noreply@{$hostname}\r\n"
                . "X-Mailer: WHMCS-FPS/4.1\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            $sent = @mail($to, $subject, $body, $headers);

            logModuleCall(
                self::MODULE_NAME,
                'fps_sendEmailNotification',
                json_encode(['to' => $to, 'level' => $level, 'score' => $score]),
                $sent ? 'Sent successfully' : 'mail() returned false'
            );
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'fps_sendEmailNotification::ERROR',
                json_encode(['to' => $to, 'level' => $level]),
                $e->getMessage()
            );
        }
    }

    /**
     * Fetch basic client info for email notifications.
     *
     * @return array{email: string, name: string, country: string}|null
     */
    private function fps_getClientInfo(int $clientId): ?array
    {
        try {
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first(['email', 'firstname', 'lastname', 'country']);

            if ($client === null) {
                return null;
            }

            return [
                'email'   => $client->email ?? '',
                'name'    => trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? '')),
                'country' => $client->country ?? '',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}

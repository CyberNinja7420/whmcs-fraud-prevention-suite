<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsPostPurchaseMonitor -- Detects abuse signals AFTER order placement.
 *
 * Monitors for:
 * - Email sending spikes (tblactivitylog entries)
 * - Rapid resource provisioning (multiple tblhosting created quickly)
 * - Support ticket velocity (many tickets in short periods)
 * - Login from new IPs (different from order IP)
 *
 * Called daily via DailyCronJob hook for recent clients (last 7 days).
 */
class FpsPostPurchaseMonitor
{
    /** @var int Hours lookback for email abuse check */
    private const EMAIL_WINDOW_HOURS = 24;

    /** @var int Max email-related log entries before flagging */
    private const EMAIL_THRESHOLD = 50;

    /** @var int Minutes window for rapid provisioning detection */
    private const PROVISION_WINDOW_MINUTES = 30;

    /** @var int Max services created in window before flagging */
    private const PROVISION_THRESHOLD = 5;

    /** @var int Hours lookback for ticket velocity */
    private const TICKET_WINDOW_HOURS = 24;

    /** @var int Max tickets before flagging */
    private const TICKET_THRESHOLD = 10;

    /** @var int Days lookback for suspicious login check */
    private const LOGIN_WINDOW_DAYS = 7;

    /** @var int Max unique new IPs before flagging */
    private const NEW_IP_THRESHOLD = 5;

    /**
     * Run all post-purchase abuse monitors for a client.
     *
     * @param int $clientId
     * @return array{flagged: bool, score: float, details: string, signals: array}
     */
    public function fps_monitorClient(int $clientId): array
    {
        $signals = [];
        $totalScore = 0.0;

        try {
            $emailResult = $this->fps_checkEmailAbuse($clientId);
            if ($emailResult['flagged']) {
                $signals[] = $emailResult;
                $totalScore += $emailResult['score'];
            }

            $provisionResult = $this->fps_checkProvisioningAbuse($clientId);
            if ($provisionResult['flagged']) {
                $signals[] = $provisionResult;
                $totalScore += $provisionResult['score'];
            }

            $ticketResult = $this->fps_checkTicketVelocity($clientId);
            if ($ticketResult['flagged']) {
                $signals[] = $ticketResult;
                $totalScore += $ticketResult['score'];
            }

            $loginResult = $this->fps_checkSuspiciousLogins($clientId);
            if ($loginResult['flagged']) {
                $signals[] = $loginResult;
                $totalScore += $loginResult['score'];
            }
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'PostPurchaseMonitor::Error',
                "client_id={$clientId}",
                $e->getMessage()
            );
        }

        $flagged = !empty($signals);
        $detailParts = array_map(fn(array $s) => $s['details'], $signals);
        $detailStr = $flagged
            ? implode('; ', $detailParts)
            : 'No abuse signals detected';

        return [
            'flagged' => $flagged,
            'score'   => min(100.0, $totalScore),
            'details' => $detailStr,
            'signals' => $signals,
        ];
    }

    /**
     * Check for abnormally high email-related activity.
     *
     * Scans tblactivitylog for entries mentioning "email" associated with
     * this client in the last EMAIL_WINDOW_HOURS hours.
     *
     * @param int $clientId
     * @return array{flagged: bool, score: float, details: string}
     */
    public function fps_checkEmailAbuse(int $clientId): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime('-' . self::EMAIL_WINDOW_HOURS . ' hours'));

            $count = (int) Capsule::table('tblactivitylog')
                ->where('userid', $clientId)
                ->where('date', '>=', $since)
                ->where(function ($q) {
                    $q->where('description', 'LIKE', '%email%')
                      ->orWhere('description', 'LIKE', '%mail%')
                      ->orWhere('description', 'LIKE', '%smtp%');
                })
                ->count();

            if ($count >= self::EMAIL_THRESHOLD) {
                $severity = min(40.0, ($count / self::EMAIL_THRESHOLD) * 20.0);
                return [
                    'flagged' => true,
                    'score'   => round($severity, 1),
                    'details' => "Email spike: {$count} email-related events in last " . self::EMAIL_WINDOW_HOURS . "h (threshold: " . self::EMAIL_THRESHOLD . ")",
                    'type'    => 'email_abuse',
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal -- table may not exist or be inaccessible
        }

        return ['flagged' => false, 'score' => 0.0, 'details' => 'Email activity normal'];
    }

    /**
     * Check for rapid resource provisioning.
     *
     * Looks for multiple tblhosting records created within a short window
     * (indicates automated bulk ordering, reseller abuse, or stolen card).
     *
     * @param int $clientId
     * @return array{flagged: bool, score: float, details: string}
     */
    public function fps_checkProvisioningAbuse(int $clientId): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime('-' . self::PROVISION_WINDOW_MINUTES . ' minutes'));

            // Count services created recently
            $recentServices = Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->where('regdate', '>=', date('Y-m-d', strtotime('-1 day')))
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get(['id', 'regdate']);

            if ($recentServices->count() < self::PROVISION_THRESHOLD) {
                return ['flagged' => false, 'score' => 0.0, 'details' => 'Provisioning rate normal'];
            }

            // Check if they were created in rapid succession
            // Group by date and check if many fall within the same day
            $dateGroups = [];
            foreach ($recentServices as $svc) {
                $day = $svc->regdate ?? '';
                if ($day !== '') {
                    $dateGroups[$day] = ($dateGroups[$day] ?? 0) + 1;
                }
            }

            $maxInDay = !empty($dateGroups) ? max($dateGroups) : 0;
            if ($maxInDay >= self::PROVISION_THRESHOLD) {
                $severity = min(35.0, ($maxInDay / self::PROVISION_THRESHOLD) * 15.0);
                return [
                    'flagged' => true,
                    'score'   => round($severity, 1),
                    'details' => "Rapid provisioning: {$maxInDay} services created in a single day (threshold: " . self::PROVISION_THRESHOLD . ")",
                    'type'    => 'provisioning_abuse',
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return ['flagged' => false, 'score' => 0.0, 'details' => 'Provisioning rate normal'];
    }

    /**
     * Check for unusually high support ticket velocity.
     *
     * A new client submitting many tickets quickly can indicate testing
     * stolen credentials, social engineering, or automated abuse.
     *
     * @param int $clientId
     * @return array{flagged: bool, score: float, details: string}
     */
    public function fps_checkTicketVelocity(int $clientId): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime('-' . self::TICKET_WINDOW_HOURS . ' hours'));

            $ticketCount = (int) Capsule::table('tbltickets')
                ->where('userid', $clientId)
                ->where('date', '>=', $since)
                ->count();

            if ($ticketCount >= self::TICKET_THRESHOLD) {
                $severity = min(25.0, ($ticketCount / self::TICKET_THRESHOLD) * 12.0);
                return [
                    'flagged' => true,
                    'score'   => round($severity, 1),
                    'details' => "Ticket flood: {$ticketCount} tickets in last " . self::TICKET_WINDOW_HOURS . "h (threshold: " . self::TICKET_THRESHOLD . ")",
                    'type'    => 'ticket_velocity',
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return ['flagged' => false, 'score' => 0.0, 'details' => 'Ticket velocity normal'];
    }

    /**
     * Check for logins from IPs that differ from the order IP.
     *
     * A client signing up from one IP then immediately logging in from
     * multiple different IPs could indicate credential sharing, account
     * takeover, or a compromised account.
     *
     * @param int $clientId
     * @return array{flagged: bool, score: float, details: string}
     */
    public function fps_checkSuspiciousLogins(int $clientId): array
    {
        try {
            // Get the client's original registration IP
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first(['ip']);

            $orderIp = $client->ip ?? '';

            $since = date('Y-m-d H:i:s', strtotime('-' . self::LOGIN_WINDOW_DAYS . ' days'));

            // Collect unique login IPs from activity log
            $loginIps = Capsule::table('tblactivitylog')
                ->where('userid', $clientId)
                ->where('date', '>=', $since)
                ->where(function ($q) {
                    $q->where('description', 'LIKE', '%logged in%')
                      ->orWhere('description', 'LIKE', '%login%')
                      ->orWhere('description', 'LIKE', '%Client Login%');
                })
                ->whereNotNull('ipaddress')
                ->where('ipaddress', '!=', '')
                ->distinct()
                ->pluck('ipaddress')
                ->toArray();

            // Remove the original order IP from the list -- that one is expected
            if ($orderIp !== '') {
                $loginIps = array_values(array_diff($loginIps, [$orderIp]));
            }

            $newIpCount = count($loginIps);

            if ($newIpCount >= self::NEW_IP_THRESHOLD) {
                $severity = min(30.0, ($newIpCount / self::NEW_IP_THRESHOLD) * 15.0);
                return [
                    'flagged' => true,
                    'score'   => round($severity, 1),
                    'details' => "Suspicious logins: {$newIpCount} new IPs in last " . self::LOGIN_WINDOW_DAYS . " days (threshold: " . self::NEW_IP_THRESHOLD . ", original IP: " . ($orderIp ?: 'unknown') . ")",
                    'type'    => 'suspicious_logins',
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return ['flagged' => false, 'score' => 0.0, 'details' => 'Login pattern normal'];
    }
}

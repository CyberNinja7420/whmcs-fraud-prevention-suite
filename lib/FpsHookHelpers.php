<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

class FpsHookHelpers
{
    /**
     * Record geo event for topology visualization.
     */
    public static function fps_recordGeoEvent(string $ip, string $country, int $orderId): void
    {
        try {
            $topologyEnabled = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'topology_enabled')
                ->value('setting_value');

            if ($topologyEnabled !== '1') return;

            // Get coordinates from IP intel cache
            $ipIntel = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $ip)
                ->first();

            $lat = $ipIntel->latitude ?? null;
            $lng = $ipIntel->longitude ?? null;
            $cc = $ipIntel->country_code ?? $country;

            // Only store anonymized data (rounded coordinates)
            if ($lat !== null && $lng !== null) {
                $lat = round((float)$lat, 1);
                $lng = round((float)$lng, 1);
            }

            // Get the risk level from the check
            $check = Capsule::table('mod_fps_checks')
                ->where('order_id', $orderId)
                ->orderByDesc('created_at')
                ->first();

            Capsule::table('mod_fps_geo_events')->insert([
                'event_type' => 'order_check',
                'country_code' => strtoupper(substr($cc ?: 'XX', 0, 2)),
                'latitude' => $lat,
                'longitude' => $lng,
                'risk_level' => $check->risk_level ?? 'low',
                'risk_score' => $check->risk_score ?? 0,
                'is_anonymized' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal - topology is optional
        }
    }

    /**
     * Download the latest disposable email domains list from GitHub.
     */
    public static function fps_refreshDisposableDomains(): void
    {
        $targetFile = __DIR__ . '/../data/disposable_domains.txt';
        $url = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';

        // Skip if updated in last 24 hours
        if (file_exists($targetFile) && (time() - filemtime($targetFile)) < 86400) {
            return;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && strlen($data) > 1000) {
            file_put_contents($targetFile, $data);
            logModuleCall('fraud_prevention_suite', 'DisposableDomains', '',
                'Updated: ' . substr_count($data, "\n") . ' domains');
        }
    }

    /**
     * v3.0: Auto-suspend clients exceeding chargeback thresholds.
     * Called by DailyCronJob when auto_suspend_enabled = '1'.
     */
    public static function fps_autoSuspendChargebackAbusers(): void
    {
        try {
            $threshold = (int) (Capsule::table('mod_fps_settings')
                ->where('setting_key', 'auto_suspend_chargeback_threshold')
                ->value('setting_value') ?: 3);

            $windowDays = (int) (Capsule::table('mod_fps_settings')
                ->where('setting_key', 'auto_suspend_chargeback_window_days')
                ->value('setting_value') ?: 180);

            $since = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));

            // Find clients with chargebacks exceeding threshold
            $abusers = Capsule::table('mod_fps_chargebacks')
                ->where('chargeback_date', '>=', $since)
                ->selectRaw('client_id, COUNT(*) as cb_count, SUM(amount) as total_amount')
                ->groupBy('client_id')
                ->havingRaw('COUNT(*) >= ?', [$threshold])
                ->get();

            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsClientTrustManager')) {
                logModuleCall('fraud_prevention_suite', 'AutoSuspend::ERROR', '', 'FpsClientTrustManager class not found');
                return;
            }

            $trustManager = new FpsClientTrustManager();

            foreach ($abusers as $abuser) {
                $currentStatus = $trustManager->getClientStatus((int) $abuser->client_id);

                // Don't override if already blacklisted or trusted
                if (in_array($currentStatus, ['blacklisted', 'trusted'], true)) {
                    continue;
                }

                $reason = "Auto-suspended: {$abuser->cb_count} chargebacks (\${$abuser->total_amount}) in {$windowDays} days";
                $trustManager->setClientStatus((int) $abuser->client_id, 'suspended', $reason, 0);

                // Suspend all active services
                $services = Capsule::table('tblhosting')
                    ->where('userid', $abuser->client_id)
                    ->where('domainstatus', 'Active')
                    ->get(['id']);

                foreach ($services as $service) {
                    Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update(['domainstatus' => 'Suspended']);
                }

                logActivity("Fraud Prevention: Auto-suspended client #{$abuser->client_id} -- {$abuser->cb_count} chargebacks in {$windowDays} days");
            }

            if (count($abusers) > 0) {
                logModuleCall('fraud_prevention_suite', 'AutoSuspend', '', count($abusers) . ' clients suspended');
            }
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AutoSuspend::ERROR', '', $e->getMessage());
        }
    }

    /**
     * Legacy v1.0 fraud check fallback.
     */
    public static function fps_legacyFraudCheck(int $orderId, int $clientId, string $email, string $ip, string $phone, string $country): void
    {
        try {
            $apiKey = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'fraudrecord_api_key')
                ->value('value');

            $riskScore = 0;
            $details = [];
            $rawResponse = null;
            $fraudRecordId = null;

            if ($apiKey && $email) {
                $postData = [
                    'api_key' => $apiKey,
                    'action' => 'query',
                    'email' => md5(strtolower(trim($email))),
                ];
                if ($ip) $postData['ip'] = $ip;
                if ($phone) $postData['phone'] = md5(preg_replace('/[^0-9]/', '', $phone));

                $ch = curl_init('https://www.fraudrecord.com/api/');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $rawResponse = $response;

                if ($httpCode === 200 && $response) {
                    $data = @json_decode($response, true);
                    if ($data) {
                        $fraudRecordId = $data['id'] ?? null;
                        $frScore = (float)($data['value'] ?? 0);
                        if ($frScore > 0) {
                            $riskScore += $frScore * 30;
                            $details[] = "FraudRecord score: {$frScore}";
                        }
                    }
                }

                logModuleCall('fraud_prevention_suite', 'FraudRecord Query',
                    json_encode($postData), $response, '', [$apiKey]);
            }

            $riskScore = min($riskScore, 100);
            $riskLevel = 'low';
            if ($riskScore >= 80) $riskLevel = 'critical';
            elseif ($riskScore >= 60) $riskLevel = 'high';
            elseif ($riskScore >= 30) $riskLevel = 'medium';

            $actionTaken = 'approved';
            $locked = 0;

            $critThreshold = (float)(Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', 'risk_critical_threshold')
                ->value('value') ?: 80);

            if ($riskScore >= $critThreshold) {
                $autoLock = Capsule::table('tbladdonmodules')
                    ->where('module', 'fraud_prevention_suite')
                    ->where('setting', 'auto_lock_critical')
                    ->value('value');

                if ($autoLock === 'on' || $autoLock === 'yes') {
                    Capsule::table('tblorders')->where('id', $orderId)->update(['status' => 'Fraud']);
                    $actionTaken = 'locked';
                    $locked = 1;
                }
            }

            Capsule::table('mod_fps_checks')->insert([
                'order_id' => $orderId,
                'client_id' => $clientId,
                'check_type' => 'legacy_auto',
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'ip_address' => $ip,
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
                'fraudrecord_id' => $fraudRecordId,
                'raw_response' => $rawResponse,
                'details' => json_encode($details),
                'action_taken' => $actionTaken,
                'locked' => $locked,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'LegacyCheck', '', $e->getMessage());
        }
    }
}

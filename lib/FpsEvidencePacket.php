<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsEvidencePacket -- auto-compiles a chargeback / dispute evidence packet
 * from data the suite already stores, in the spirit of Stripe Smart Disputes /
 * Visa Compelling Evidence 3.0.
 *
 * Given a mod_fps_chargebacks row it assembles: cardholder identity, the
 * disputed transaction (order/invoice), the device & network evidence (IP,
 * geolocation, device fingerprint), the full account-activity timeline (fraud
 * checks + logins with timestamps), and the fraud assessment at order time.
 * Everything is real, sourced from WHMCS core tables + mod_fps_* -- nothing is
 * fabricated. The output is a self-contained printable HTML packet the merchant
 * can attach to a dispute response.
 */
final class FpsEvidencePacket
{
    /**
     * Compile structured evidence for a chargeback.
     *
     * @return array{found:bool,error?:string,data?:array}
     */
    public static function compile(int $chargebackId): array
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_chargebacks')) {
                return ['found' => false, 'error' => 'Chargeback table not present'];
            }
            $cb = Capsule::table('mod_fps_chargebacks')->where('id', $chargebackId)->first();
            if (!$cb) {
                return ['found' => false, 'error' => 'Chargeback not found'];
            }

            $clientId = (int) $cb->client_id;
            $client = Capsule::table('tblclients')->where('id', $clientId)
                ->first(['id', 'firstname', 'lastname', 'companyname', 'email', 'address1',
                         'city', 'state', 'postcode', 'country', 'phonenumber', 'datecreated',
                         'ip', 'status']);

            // Disputed invoice/order.
            $invoice = null;
            if (!empty($cb->invoice_id)) {
                $invoice = Capsule::table('tblinvoices')->where('id', (int) $cb->invoice_id)
                    ->first(['id', 'date', 'datepaid', 'total', 'status', 'paymentmethod']);
            }
            $order = null;
            if (!empty($cb->order_id)) {
                $order = Capsule::table('tblorders')->where('id', (int) $cb->order_id)
                    ->first(['id', 'ordernum', 'date', 'amount', 'status', 'ipaddress', 'paymentmethod']);
            }

            // Fraud-check history for this client (most recent first).
            $checks = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['check_type', 'risk_score', 'risk_level', 'action_taken', 'ip_address',
                       'country', 'provider_scores', 'created_at']);

            // Login history (ATO module) -- proves account access pattern.
            $logins = [];
            if (Capsule::schema()->hasTable('mod_fps_login_events')) {
                $logins = Capsule::table('mod_fps_login_events')
                    ->where('client_id', $clientId)
                    ->orderByDesc('created_at')
                    ->limit(25)
                    ->get(['ip_address', 'country_code', 'device_hash', 'is_new_device',
                           'is_impossible_travel', 'risk_score', 'created_at'])->toArray();
            }

            // Network + device evidence from the order/most-recent-check IP.
            $orderIp = $order->ipaddress ?? ($client->ip ?? '');
            $ipIntel = null;
            if ($orderIp !== '') {
                $ipIntel = Capsule::table('mod_fps_ip_intel')->where('ip_address', $orderIp)
                    ->first(['country_code', 'region', 'city', 'isp', 'asn_org',
                             'is_vpn', 'is_tor', 'is_proxy', 'is_datacenter', 'latitude', 'longitude']);
            }
            $devices = [];
            if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
                $devices = Capsule::table('mod_fps_fingerprints')
                    ->where('client_id', $clientId)
                    ->orderByDesc('last_seen_at')
                    ->limit(10)
                    ->get(['fingerprint_hash', 'first_seen_at', 'last_seen_at'])->toArray();
            }

            return [
                'found' => true,
                'data' => [
                    'chargeback' => $cb,
                    'client'     => $client,
                    'invoice'    => $invoice,
                    'order'      => $order,
                    'checks'     => $checks->toArray(),
                    'logins'     => $logins,
                    'ip_intel'   => $ipIntel,
                    'order_ip'   => $orderIp,
                    'devices'    => $devices,
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
            ];
        } catch (\Throwable $e) {
            return ['found' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Render the compiled evidence as a self-contained printable HTML packet.
     */
    public static function renderHtml(int $chargebackId): string
    {
        $res = self::compile($chargebackId);
        if (empty($res['found'])) {
            return '<div class="fps-card"><div class="fps-card-body"><p class="text-danger">'
                . 'Could not compile evidence: ' . htmlspecialchars($res['error'] ?? 'unknown') . '</p></div></div>';
        }
        $d = $res['data'];
        $e = static fn($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $cb = $d['chargeback'];
        $c  = $d['client'];
        $ip = $d['ip_intel'];

        $rows = static function (array $pairs) use ($e): string {
            $h = '<table class="fps-table" style="width:100%;margin-bottom:14px;">';
            foreach ($pairs as $k => $v) {
                $h .= '<tr><td style="width:38%;font-weight:600;">' . $e($k) . '</td><td>' . $e($v) . '</td></tr>';
            }
            return $h . '</table>';
        };

        $clientName = trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? ''));
        $anomalies = [];
        if ($ip) {
            if ((int) $ip->is_vpn) { $anomalies[] = 'VPN'; }
            if ((int) $ip->is_tor) { $anomalies[] = 'Tor'; }
            if ((int) $ip->is_proxy) { $anomalies[] = 'Proxy'; }
            if ((int) $ip->is_datacenter) { $anomalies[] = 'Datacenter'; }
        }

        $html = '<div class="fps-card"><div class="fps-card-header"><i class="fas fa-file-shield"></i> '
              . 'Chargeback Evidence Packet &mdash; CB #' . (int) $cb->id . '</div><div class="fps-card-body">';
        $html .= '<p class="fps-text-muted" style="font-size:0.82rem;">Auto-compiled from order, network, device and account-activity '
              . 'records (Visa Compelling Evidence 3.0 style). Generated ' . $e($d['generated_at']) . ' UTC.</p>';

        $html .= '<h4>1. Cardholder / Account</h4>' . $rows([
            'Name'          => $clientName,
            'Company'       => $c->companyname ?? '',
            'Email'         => $c->email ?? '',
            'Phone'         => $c->phonenumber ?? '',
            'Billing'       => trim(($c->address1 ?? '') . ', ' . ($c->city ?? '') . ' ' . ($c->state ?? '') . ' ' . ($c->postcode ?? '') . ', ' . ($c->country ?? '')),
            'Account created' => $c->datecreated ?? '',
            'Account status'  => $c->status ?? '',
            'Signup IP'       => $c->ip ?? '',
        ]);

        $html .= '<h4>2. Disputed Transaction</h4>' . $rows([
            'Order #'       => $d['order']->ordernum ?? ($cb->order_id ?? ''),
            'Order date'    => $d['order']->date ?? '',
            'Invoice #'     => $cb->invoice_id ?? '',
            'Invoice paid'  => $d['invoice']->datepaid ?? '',
            'Amount'        => $cb->amount ?? ($d['order']->amount ?? ''),
            'Gateway'       => $cb->gateway ?? ($d['order']->paymentmethod ?? ''),
            'Order IP'      => $d['order_ip'],
            'Chargeback reason' => $cb->reason ?? '',
            'Chargeback date'   => $cb->chargeback_date ?? '',
        ]);

        $html .= '<h4>3. Network &amp; Device Evidence</h4>' . $rows([
            'Order IP'      => $d['order_ip'],
            'IP geolocation' => $ip ? trim(($ip->city ?? '') . ', ' . ($ip->region ?? '') . ' ' . ($ip->country_code ?? '')) : 'n/a',
            'ISP / ASN'     => $ip ? trim(($ip->isp ?? '') . ' / ' . ($ip->asn_org ?? '')) : 'n/a',
            'IP anomalies'  => $anomalies ? implode(', ', $anomalies) : 'None (clean residential IP)',
            'Known devices' => count($d['devices']) . ' fingerprint(s) on file',
        ]);

        // Fraud assessment at order time.
        $html .= '<h4>4. Fraud Assessment at Order</h4>' . $rows([
            'Risk score at order' => $cb->fraud_score_at_order ?? 'n/a',
            'Risk level at order' => $cb->risk_level_at_order ?? 'n/a',
        ]);
        if (!empty($cb->provider_scores_at_order)) {
            $html .= '<p style="font-size:0.8rem;"><strong>Signal reasons at order:</strong> '
                  . $e(FpsReasonCodes::summary($cb->provider_scores_at_order, [], 5)) . '</p>';
        }

        // Activity timeline (logins + checks) -- proves consistent legitimate access.
        $html .= '<h4>5. Account Activity Timeline</h4>';
        $html .= '<table class="fps-table" style="width:100%;"><thead><tr><th>When</th><th>Event</th><th>IP</th><th>Country</th><th>Risk</th></tr></thead><tbody>';
        $timeline = [];
        foreach ($d['logins'] as $l) {
            $timeline[] = ['t' => $l->created_at, 'event' => 'Login' . ((int) $l->is_new_device ? ' (new device)' : '') . ((int) $l->is_impossible_travel ? ' (impossible travel)' : ''), 'ip' => $l->ip_address, 'country' => $l->country_code, 'risk' => $l->risk_score];
        }
        foreach ($d['checks'] as $ck) {
            $timeline[] = ['t' => $ck->created_at, 'event' => 'Check: ' . $ck->check_type . ' (' . $ck->action_taken . ')', 'ip' => $ck->ip_address, 'country' => $ck->country, 'risk' => $ck->risk_score];
        }
        usort($timeline, static fn($a, $b): int => strcmp((string) $b['t'], (string) $a['t']));
        foreach (array_slice($timeline, 0, 40) as $t) {
            $html .= '<tr><td>' . $e($t['t']) . '</td><td>' . $e($t['event']) . '</td><td>' . $e($t['ip'])
                  . '</td><td>' . $e($t['country']) . '</td><td>' . $e($t['risk']) . '</td></tr>';
        }
        if (empty($timeline)) {
            $html .= '<tr><td colspan="5" class="fps-text-muted">No recorded activity for this account.</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div style="margin-top:14px;"><button class="fps-btn fps-btn-primary" onclick="window.print()">'
              . '<i class="fas fa-print"></i> Print / Save as PDF</button></div>';
        $html .= '</div></div>';
        return $html;
    }
}

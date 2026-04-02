<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabClientProfile -- deep client fraud investigation page.
 *
 * Renders a search form then loads full profile data via AJAX:
 * risk gauge, IP/email/device intelligence, associated accounts,
 * check history timeline, orders table, and action buttons.
 */
class TabClientProfile
{
    public function render(array $vars, string $modulelink): void
    {
        $clientId = (int)($_GET['client_id'] ?? 0);
        $ajaxUrl  = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderSearchForm($modulelink, $clientId);

        if ($clientId > 0) {
            $this->fpsRenderProfileContent($clientId, $modulelink, $ajaxUrl);
        }
    }

    /**
     * Client search input and load button.
     */
    private function fpsRenderSearchForm(string $modulelink, int $currentId): void
    {
        $safeLink = htmlspecialchars($modulelink, ENT_QUOTES, 'UTF-8');
        $safeId   = $currentId > 0 ? (string)$currentId : '';

        $formHtml = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-profile-client-input"><i class="fas fa-user"></i> Client ID or Email</label>
    <input type="text" id="fps-profile-client-input" class="fps-input" placeholder="Enter Client ID or email address" value="{$safeId}">
  </div>
  <div class="fps-form-group fps-form-actions" style="padding-top:24px;">
    <button type="button" class="fps-btn fps-btn-md fps-btn-primary" onclick="FpsAdmin.loadClientProfile('{$safeLink}')">
      <i class="fas fa-search"></i> Load Profile
    </button>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Client Search', 'fa-magnifying-glass', $formHtml);
    }

    /**
     * Render the full client profile with all intelligence panels.
     */
    private function fpsRenderProfileContent(int $clientId, string $modulelink, string $ajaxUrl): void
    {
        try {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$client) {
                echo FpsAdminRenderer::renderEmpty('Client #' . $clientId . ' not found.');
                return;
            }

            // Profile header
            $this->fpsRenderProfileHeader($client);

            // Risk gauge + intel panels in a grid
            echo '<div class="fps-profile-grid">';

            // Risk gauge
            $this->fpsRenderRiskGauge($clientId);

            // IP intelligence
            $this->fpsRenderIpIntelPanel($clientId);

            // Email intelligence
            $this->fpsRenderEmailIntelPanel($client->email);

            // Device fingerprints
            $this->fpsRenderFingerprintPanel($clientId);

            echo '</div>'; // .fps-profile-grid

            // Duplicate account detection (expanded: fingerprint + IP + phone + payment)
            $this->fpsRenderDuplicateAccounts($clientId, $client, $modulelink);

            // Order velocity visualization
            $this->fpsRenderOrderVelocity($clientId);

            // Refund abuse tracking
            $this->fpsRenderRefundHistory($clientId);

            // Check history timeline
            $this->fpsRenderCheckTimeline($clientId);

            // Orders table
            $this->fpsRenderOrdersTable($clientId);

            // Action buttons
            $this->fpsRenderActionButtons($clientId, $ajaxUrl);

        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }
    }

    /**
     * Profile header card with client details.
     */
    private function fpsRenderProfileHeader(object $client): void
    {
        $name    = htmlspecialchars(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''), ENT_QUOTES, 'UTF-8');
        $email   = htmlspecialchars($client->email ?? '', ENT_QUOTES, 'UTF-8');
        $status  = htmlspecialchars($client->status ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $created = htmlspecialchars($client->datecreated ?? '', ENT_QUOTES, 'UTF-8');

        $totalSpend = 0.00;
        try {
            $totalSpend = (float)Capsule::table('tblinvoices')
                ->where('userid', $client->id)
                ->where('status', 'Paid')
                ->sum('total');
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $statusClass = match (strtolower($client->status ?? '')) {
            'active'  => 'fps-badge-low',
            'closed'  => 'fps-badge-critical',
            default   => 'fps-badge-medium',
        };

        $content = <<<HTML
<div class="fps-profile-header-content">
  <div class="fps-profile-avatar">
    <i class="fas fa-user-circle"></i>
  </div>
  <div class="fps-profile-meta">
    <h2>{$name}</h2>
    <p class="fps-text-muted"><i class="fas fa-envelope"></i> {$email}</p>
    <div class="fps-profile-badges">
      <span class="fps-badge {$statusClass}">{$status}</span>
      <span class="fps-badge fps-badge-info"><i class="fas fa-calendar"></i> Joined: {$created}</span>
      <span class="fps-badge fps-badge-success"><i class="fas fa-dollar-sign"></i> Total Spend: \${$totalSpend}</span>
    </div>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Client Profile: ' . $name, 'fa-user-shield', $content);
    }

    /**
     * CSS-only circular risk gauge.
     */
    private function fpsRenderRiskGauge(int $clientId): void
    {
        $avgScore = 0.0;
        try {
            $avgScore = (float)Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->avg('risk_score');
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $score      = round($avgScore, 1);
        $percentage = min(100, max(0, $score));
        $degrees    = (int)($percentage * 3.6);

        if ($percentage >= 80) {
            $gaugeColor = '#eb3349';
        } elseif ($percentage >= 60) {
            $gaugeColor = '#f5576c';
        } elseif ($percentage >= 30) {
            $gaugeColor = '#f5c842';
        } else {
            $gaugeColor = '#38ef7d';
        }

        $remaining = 100 - $percentage;
        $levelLabel = match (true) {
            $percentage >= 80 => 'Critical',
            $percentage >= 60 => 'High',
            $percentage >= 30 => 'Medium',
            default => 'Low',
        };

        $content = <<<HTML
<div style="display:flex;flex-direction:column;align-items:center;padding:1.5rem 1rem;">
  <div style="position:relative;width:160px;height:160px;">
    <svg viewBox="0 0 160 160" style="transform:rotate(-90deg);">
      <circle cx="80" cy="80" r="70" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="14"/>
      <circle cx="80" cy="80" r="70" fill="none" stroke="{$gaugeColor}" stroke-width="14"
        stroke-dasharray="{$percentage} {$remaining}"
        stroke-dashoffset="0" stroke-linecap="round"
        pathLength="100" style="transition:stroke-dasharray 1s ease;"/>
    </svg>
    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
      <span style="font-size:2.2rem;font-weight:800;color:{$gaugeColor};line-height:1;">{$score}</span>
      <span style="font-size:0.75rem;color:#b0b8d0;text-transform:uppercase;letter-spacing:0.08em;margin-top:4px;">Risk Score</span>
    </div>
  </div>
  <div style="margin-top:0.75rem;text-align:center;">
    <span style="display:inline-block;padding:4px 16px;border-radius:20px;font-size:0.8rem;font-weight:700;color:#fff;background:{$gaugeColor};">{$levelLabel} Risk</span>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Overall Risk', 'fa-gauge-high', $content);
    }

    /**
     * IP intelligence panel.
     */
    private function fpsRenderIpIntelPanel(int $clientId): void
    {
        $ipIntel = null;
        $ipAddr  = '--';

        try {
            $latestCheck = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->whereNotNull('ip_address')
                ->orderByDesc('created_at')
                ->first(['ip_address']);

            if ($latestCheck && $latestCheck->ip_address) {
                $ipAddr  = $latestCheck->ip_address;
                $ipIntel = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $ipAddr)
                    ->first();
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $safeIp = htmlspecialchars($ipAddr, ENT_QUOTES, 'UTF-8');

        if (!$ipIntel) {
            $content = '<p class="fps-text-muted"><i class="fas fa-info-circle"></i> No IP intelligence data available for ' . $safeIp . '.</p>';
        } else {
            $asn     = htmlspecialchars((string)($ipIntel->asn ?? '--'), ENT_QUOTES, 'UTF-8');
            $asnOrg  = htmlspecialchars($ipIntel->asn_org ?? '--', ENT_QUOTES, 'UTF-8');
            $isp     = htmlspecialchars($ipIntel->isp ?? '--', ENT_QUOTES, 'UTF-8');
            $country = htmlspecialchars($ipIntel->country_code ?? '--', ENT_QUOTES, 'UTF-8');
            $city    = htmlspecialchars($ipIntel->city ?? '--', ENT_QUOTES, 'UTF-8');
            $lat     = htmlspecialchars((string)($ipIntel->latitude ?? '--'), ENT_QUOTES, 'UTF-8');
            $lng     = htmlspecialchars((string)($ipIntel->longitude ?? '--'), ENT_QUOTES, 'UTF-8');

            $proxyBadge = $ipIntel->is_proxy ? '<span class="fps-badge fps-badge-critical">PROXY</span>' : '<span class="fps-badge fps-badge-low">Clean</span>';
            $vpnBadge   = $ipIntel->is_vpn ? '<span class="fps-badge fps-badge-high">VPN</span>' : '';
            $torBadge   = $ipIntel->is_tor ? '<span class="fps-badge fps-badge-critical">TOR</span>' : '';
            $dcBadge    = $ipIntel->is_datacenter ? '<span class="fps-badge fps-badge-warning">Datacenter</span>' : '';

            $content = <<<HTML
<table class="fps-detail-table">
  <tr><td><i class="fas fa-globe"></i> IP Address</td><td><strong>{$safeIp}</strong></td></tr>
  <tr><td><i class="fas fa-network-wired"></i> ASN</td><td>AS{$asn} ({$asnOrg})</td></tr>
  <tr><td><i class="fas fa-building"></i> ISP</td><td>{$isp}</td></tr>
  <tr><td><i class="fas fa-flag"></i> Country</td><td>{$country}</td></tr>
  <tr><td><i class="fas fa-city"></i> City</td><td>{$city}</td></tr>
  <tr><td><i class="fas fa-location-dot"></i> Coordinates</td><td>{$lat}, {$lng}</td></tr>
  <tr><td><i class="fas fa-mask"></i> Indicators</td><td>{$proxyBadge} {$vpnBadge} {$torBadge} {$dcBadge}</td></tr>
</table>
HTML;
        }

        echo FpsAdminRenderer::renderCard('IP Intelligence', 'fa-network-wired', $content);
    }

    /**
     * Email intelligence panel.
     */
    private function fpsRenderEmailIntelPanel(string $email): void
    {
        $emailIntel = null;
        try {
            $emailIntel = Capsule::table('mod_fps_email_intel')
                ->where('email', $email)
                ->first();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        if (!$emailIntel) {
            $content = '<p class="fps-text-muted"><i class="fas fa-info-circle"></i> No email intelligence for ' . $safeEmail . '.</p>';
        } else {
            $mxBadge         = $emailIntel->mx_valid ? '<span class="fps-badge fps-badge-low">Valid</span>' : '<span class="fps-badge fps-badge-critical">Invalid</span>';
            $disposableBadge = $emailIntel->is_disposable ? '<span class="fps-badge fps-badge-critical">YES</span>' : '<span class="fps-badge fps-badge-low">No</span>';
            $freeBadge       = $emailIntel->is_free_provider ? '<span class="fps-badge fps-badge-warning">Yes</span>' : '<span class="fps-badge fps-badge-low">No</span>';
            $domainAge       = $emailIntel->domain_age_days !== null ? htmlspecialchars((string)$emailIntel->domain_age_days, ENT_QUOTES, 'UTF-8') . ' days' : '--';
            $breachCount     = (int)$emailIntel->breach_count;
            $breachBadge     = $breachCount > 0
                ? '<span class="fps-badge fps-badge-high">' . $breachCount . ' breaches</span>'
                : '<span class="fps-badge fps-badge-low">None</span>';

            $content = <<<HTML
<table class="fps-detail-table">
  <tr><td><i class="fas fa-envelope"></i> Email</td><td><strong>{$safeEmail}</strong></td></tr>
  <tr><td><i class="fas fa-server"></i> MX Valid</td><td>{$mxBadge}</td></tr>
  <tr><td><i class="fas fa-trash-can"></i> Disposable</td><td>{$disposableBadge}</td></tr>
  <tr><td><i class="fas fa-gift"></i> Free Provider</td><td>{$freeBadge}</td></tr>
  <tr><td><i class="fas fa-hourglass-half"></i> Domain Age</td><td>{$domainAge}</td></tr>
  <tr><td><i class="fas fa-unlock"></i> Breach Count</td><td>{$breachBadge}</td></tr>
</table>
HTML;
        }

        echo FpsAdminRenderer::renderCard('Email Intelligence', 'fa-at', $content);
    }

    /**
     * Device fingerprints panel.
     */
    private function fpsRenderFingerprintPanel(int $clientId): void
    {
        $fingerprints = [];
        try {
            $fingerprints = Capsule::table('mod_fps_fingerprints')
                ->where('client_id', $clientId)
                ->orderByDesc('last_seen_at')
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (empty($fingerprints)) {
            $content = '<p class="fps-text-muted"><i class="fas fa-info-circle"></i> No device fingerprints recorded.</p>';
        } else {
            $headers = ['Hash', 'Times Seen', 'Screen', 'Browser', 'Canvas', 'Cross-Acct', 'Last Seen'];
            $rows = [];

            foreach ($fingerprints as $fp) {
                $hash   = htmlspecialchars(substr($fp->fingerprint_hash, 0, 12) . '...', ENT_QUOTES, 'UTF-8');
                $times  = (int)$fp->times_seen;
                $screen = htmlspecialchars($fp->screen_resolution ?? '--', ENT_QUOTES, 'UTF-8');

                // Extract browser from user agent
                $ua = $fp->user_agent ?? '';
                $browser = '--';
                if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera)\/[\d.]+/', $ua, $m)) {
                    $browser = htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                }

                $canvas = htmlspecialchars(substr($fp->canvas_hash ?? '--', 0, 8), ENT_QUOTES, 'UTF-8');

                // Check cross-account matches
                $crossCount = 0;
                try {
                    $crossCount = Capsule::table('mod_fps_fingerprints')
                        ->where('fingerprint_hash', $fp->fingerprint_hash)
                        ->where('client_id', '!=', $clientId)
                        ->count();
                } catch (\Throwable $e) {
                    // Non-fatal
                }
                $crossBadge = $crossCount > 0
                    ? '<span class="fps-badge fps-badge-high">' . $crossCount . ' match(es)</span>'
                    : '<span class="fps-badge fps-badge-low">None</span>';

                $lastSeen = htmlspecialchars($fp->last_seen_at ?? '--', ENT_QUOTES, 'UTF-8');

                $rows[] = [$hash, (string)$times, $screen, $browser, $canvas, $crossBadge, $lastSeen];
            }

            $content = FpsAdminRenderer::renderTable($headers, $rows, 'fps-fingerprint-table');
        }

        echo FpsAdminRenderer::renderCard('Device Fingerprints', 'fa-fingerprint', $content);
    }

    /**
     * Associated accounts table (shared fingerprints/IPs).
     */
    private function fpsRenderAssociatedAccounts(int $clientId, string $modulelink): void
    {
        $associated = [];
        try {
            $fpHashes = Capsule::table('mod_fps_fingerprints')
                ->where('client_id', $clientId)
                ->pluck('fingerprint_hash')
                ->toArray();

            if (!empty($fpHashes)) {
                $associated = Capsule::table('mod_fps_fingerprints')
                    ->whereIn('fingerprint_hash', $fpHashes)
                    ->where('client_id', '!=', $clientId)
                    ->join('tblclients', 'tblclients.id', '=', 'mod_fps_fingerprints.client_id')
                    ->select(
                        'tblclients.id',
                        'tblclients.firstname',
                        'tblclients.lastname',
                        'tblclients.email',
                        'tblclients.status',
                        'mod_fps_fingerprints.fingerprint_hash',
                        'mod_fps_fingerprints.times_seen'
                    )
                    ->distinct()
                    ->limit(50)
                    ->get()
                    ->toArray();
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (empty($associated)) {
            $content = '<p class="fps-text-muted"><i class="fas fa-check-circle"></i> No associated accounts found via fingerprint matching.</p>';
        } else {
            $headers = ['Client ID', 'Name', 'Email', 'Status', 'Shared Fingerprint', 'Actions'];
            $rows = [];

            foreach ($associated as $acct) {
                $name   = htmlspecialchars(($acct->firstname ?? '') . ' ' . ($acct->lastname ?? ''), ENT_QUOTES, 'UTF-8');
                $email  = htmlspecialchars($acct->email ?? '', ENT_QUOTES, 'UTF-8');
                $status = htmlspecialchars($acct->status ?? '', ENT_QUOTES, 'UTF-8');
                $fpHash = htmlspecialchars(substr($acct->fingerprint_hash ?? '', 0, 12) . '...', ENT_QUOTES, 'UTF-8');

                $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . (int)$acct->id, ENT_QUOTES, 'UTF-8');
                $action = '<a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-info">'
                    . '<i class="fas fa-user-shield"></i> View</a>';

                $rows[] = ['#' . (int)$acct->id, $name, $email, $status, $fpHash, $action];
            }

            $content = FpsAdminRenderer::renderTable($headers, $rows, 'fps-associated-table');
        }

        echo FpsAdminRenderer::renderCard('Associated Accounts', 'fa-users', $content);
    }

    /**
     * Check history vertical timeline.
     */
    private function fpsRenderCheckTimeline(int $clientId): void
    {
        $checks = [];
        try {
            $checks = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (empty($checks)) {
            $content = FpsAdminRenderer::renderEmpty('No fraud checks recorded for this client.');
        } else {
            $content = '<div class="fps-timeline">';

            foreach ($checks as $check) {
                $levelClass = match ($check->risk_level) {
                    'critical' => 'fps-timeline-critical',
                    'high'     => 'fps-timeline-high',
                    'medium'   => 'fps-timeline-medium',
                    default    => 'fps-timeline-low',
                };

                $badge   = FpsAdminRenderer::renderBadge($check->risk_level, (float)$check->risk_score);
                $time    = htmlspecialchars($check->created_at ?? '', ENT_QUOTES, 'UTF-8');
                $type    = htmlspecialchars($check->check_type ?? 'auto', ENT_QUOTES, 'UTF-8');
                $orderId = (int)$check->order_id;
                $ip      = htmlspecialchars($check->ip_address ?? '--', ENT_QUOTES, 'UTF-8');
                $action  = htmlspecialchars($check->action_taken ?? 'none', ENT_QUOTES, 'UTF-8');

                $content .= '<div class="fps-timeline-item ' . $levelClass . '">';
                $content .= '  <div class="fps-timeline-dot"></div>';
                $content .= '  <div class="fps-timeline-content">';
                $content .= '    <div class="fps-timeline-header">';
                $content .= '      <span class="fps-timeline-time">' . $time . '</span> ' . $badge;
                $content .= '    </div>';
                $content .= '    <p>Type: <strong>' . $type . '</strong> | Order: #' . $orderId . ' | IP: ' . $ip . ' | Action: ' . $action . '</p>';
                $content .= '  </div>';
                $content .= '</div>';
            }

            $content .= '</div>';
        }

        echo FpsAdminRenderer::renderCard('Check History', 'fa-clock-rotate-left', $content);
    }

    /**
     * Orders table for this client.
     */
    private function fpsRenderOrdersTable(int $clientId): void
    {
        $orders = [];
        try {
            $orders = Capsule::table('tblorders')
                ->where('userid', $clientId)
                ->orderByDesc('date')
                ->limit(20)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $headers = ['Order #', 'Date', 'Amount', 'Status', 'Fraud Check'];
        $rows = [];

        foreach ($orders as $order) {
            $orderId = (int)$order->id;
            $date    = htmlspecialchars($order->date ?? '', ENT_QUOTES, 'UTF-8');
            $amount  = '$' . number_format((float)($order->amount ?? 0), 2);
            $status  = htmlspecialchars($order->status ?? '', ENT_QUOTES, 'UTF-8');

            // Find associated fraud check
            $check = null;
            try {
                $check = Capsule::table('mod_fps_checks')
                    ->where('order_id', $orderId)
                    ->orderByDesc('created_at')
                    ->first(['risk_level', 'risk_score']);
            } catch (\Throwable $e) {
                // Non-fatal
            }

            $fraudCol = $check
                ? FpsAdminRenderer::renderBadge($check->risk_level, (float)$check->risk_score)
                : '<span class="fps-text-muted">N/A</span>';

            $rows[] = ['#' . $orderId, $date, $amount, $status, $fraudCol];
        }

        echo FpsAdminRenderer::renderCard(
            'Orders',
            'fa-shopping-cart',
            FpsAdminRenderer::renderTable($headers, $rows, 'fps-orders-table')
        );
    }

    /**
     * Comprehensive duplicate account detection:
     * Matches by fingerprint hash, IP address, phone number, and payment method.
     */
    private function fpsRenderDuplicateAccounts(int $clientId, object $client, string $modulelink): void
    {
        $duplicates = [];
        $matchReasons = [];

        try {
            // 1. Fingerprint matches (existing logic)
            $fpHashes = Capsule::table('mod_fps_fingerprints')
                ->where('client_id', $clientId)
                ->pluck('fingerprint_hash')
                ->toArray();

            if (!empty($fpHashes)) {
                $fpMatches = Capsule::table('mod_fps_fingerprints')
                    ->whereIn('fingerprint_hash', $fpHashes)
                    ->where('client_id', '!=', $clientId)
                    ->distinct()
                    ->pluck('client_id')
                    ->toArray();
                foreach ($fpMatches as $cid) {
                    $duplicates[$cid][] = 'Fingerprint';
                }
            }

            // 2. IP address matches
            $clientIps = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->whereNotNull('ip_address')
                ->distinct()
                ->pluck('ip_address')
                ->toArray();

            if (!empty($clientIps)) {
                $ipMatches = Capsule::table('mod_fps_checks')
                    ->whereIn('ip_address', $clientIps)
                    ->where('client_id', '!=', $clientId)
                    ->distinct()
                    ->pluck('client_id')
                    ->toArray();
                foreach ($ipMatches as $cid) {
                    $duplicates[$cid][] = 'IP Address';
                }
            }

            // 3. Phone number matches (non-empty)
            $phone = trim($client->phonenumber ?? '');
            if ($phone !== '') {
                $phoneMatches = Capsule::table('tblclients')
                    ->where('phonenumber', $phone)
                    ->where('id', '!=', $clientId)
                    ->pluck('id')
                    ->toArray();
                foreach ($phoneMatches as $cid) {
                    $duplicates[$cid][] = 'Phone Number';
                }
            }

            // 4. Payment method / billing address matches
            $billingAddr = trim(($client->address1 ?? '') . ' ' . ($client->postcode ?? ''));
            if (strlen($billingAddr) > 5) {
                $addrMatches = Capsule::table('tblclients')
                    ->where('id', '!=', $clientId)
                    ->whereRaw("CONCAT(address1, ' ', postcode) = ?", [$billingAddr])
                    ->pluck('id')
                    ->toArray();
                foreach ($addrMatches as $cid) {
                    $duplicates[$cid][] = 'Billing Address';
                }
            }

        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (empty($duplicates)) {
            $content = '<p class="fps-text-muted"><i class="fas fa-check-circle"></i> No duplicate accounts detected across fingerprint, IP, phone, or billing address.</p>';
        } else {
            $headers = ['Client ID', 'Name', 'Email', 'Status', 'Match Reasons', 'Actions'];
            $rows = [];

            foreach ($duplicates as $dupId => $reasons) {
                try {
                    $dupClient = Capsule::table('tblclients')->where('id', $dupId)->first();
                    if (!$dupClient) continue;

                    $name   = htmlspecialchars(($dupClient->firstname ?? '') . ' ' . ($dupClient->lastname ?? ''), ENT_QUOTES, 'UTF-8');
                    $email  = htmlspecialchars($dupClient->email ?? '', ENT_QUOTES, 'UTF-8');
                    $status = htmlspecialchars($dupClient->status ?? '', ENT_QUOTES, 'UTF-8');

                    $reasonBadges = '';
                    foreach (array_unique($reasons) as $reason) {
                        $badgeClass = match ($reason) {
                            'Fingerprint' => 'fps-badge-critical',
                            'IP Address'  => 'fps-badge-high',
                            'Phone Number' => 'fps-badge-warning',
                            default        => 'fps-badge-medium',
                        };
                        $reasonBadges .= '<span class="fps-badge ' . $badgeClass . '">' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</span> ';
                    }

                    $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . (int)$dupId, ENT_QUOTES, 'UTF-8');
                    $action = '<a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-info"><i class="fas fa-user-shield"></i> View</a>';

                    $rows[] = ['#' . (int)$dupId, $name, $email, $status, $reasonBadges, $action];
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $content = '<p class="fps-text-muted" style="margin-bottom:12px;">'
                . '<i class="fas fa-exclamation-triangle"></i> Found <strong>' . count($duplicates) . '</strong> potential duplicate account(s).</p>';
            $content .= FpsAdminRenderer::renderTable($headers, $rows, 'fps-duplicate-table');
        }

        echo FpsAdminRenderer::renderCard(
            'Duplicate Account Detection <span class="fps-badge fps-badge-info" style="font-size:0.7rem;">' . count($duplicates) . ' found</span>',
            'fa-users-between-lines',
            $content
        );
    }

    /**
     * Order velocity visualization: sparkline + heatmap of order frequency.
     */
    private function fpsRenderOrderVelocity(int $clientId): void
    {
        $velocityData = [];
        try {
            // Get orders per day for the last 90 days
            $orders = Capsule::table('tblorders')
                ->where('userid', $clientId)
                ->where('date', '>=', date('Y-m-d', strtotime('-90 days')))
                ->selectRaw('DATE(date) as order_date, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('order_date')
                ->orderBy('order_date')
                ->get()
                ->toArray();

            foreach ($orders as $o) {
                $velocityData[] = [
                    'date'   => $o->order_date,
                    'count'  => (int)$o->count,
                    'amount' => (float)$o->total_amount,
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Build sparkline data array (90 days, 0 for no orders)
        $sparkValues = [];
        for ($i = 89; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $found = false;
            foreach ($velocityData as $v) {
                if ($v['date'] === $date) {
                    $sparkValues[] = $v['count'];
                    $found = true;
                    break;
                }
            }
            if (!$found) $sparkValues[] = 0;
        }

        $totalOrders = array_sum($sparkValues);
        $maxDay = max($sparkValues ?: [0]);
        $jsonData = json_encode($sparkValues);

        $containerId = 'fps-velocity-spark-' . $clientId;

        // Summary stats with explicit colors for visibility
        $content = '<div class="fps-velocity-summary" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">';
        $content .= '<div style="text-align:center;padding:0.75rem 1.25rem;background:rgba(102,126,234,0.1);border-radius:8px;min-width:90px;">';
        $content .= '  <div style="font-size:1.6rem;font-weight:800;color:#667eea;">' . $totalOrders . '</div>';
        $content .= '  <div style="font-size:0.7rem;color:#8892b0;text-transform:uppercase;letter-spacing:0.05em;">Orders (90d)</div>';
        $content .= '</div>';
        $content .= '<div style="text-align:center;padding:0.75rem 1.25rem;background:rgba(102,126,234,0.1);border-radius:8px;min-width:90px;">';
        $content .= '  <div style="font-size:1.6rem;font-weight:800;color:#667eea;">' . $maxDay . '</div>';
        $content .= '  <div style="font-size:0.7rem;color:#8892b0;text-transform:uppercase;letter-spacing:0.05em;">Max/Day</div>';
        $content .= '</div>';
        $content .= '<div style="text-align:center;padding:0.75rem 1.25rem;background:rgba(102,126,234,0.1);border-radius:8px;min-width:90px;">';
        $content .= '  <div style="font-size:1.6rem;font-weight:800;color:#667eea;">' . number_format($totalOrders / 90, 1) . '</div>';
        $content .= '  <div style="font-size:0.7rem;color:#8892b0;text-transform:uppercase;letter-spacing:0.05em;">Avg/Day</div>';
        $content .= '</div>';
        $content .= '</div>';

        // Only show heatmap if there are actual orders (don't show 90 blank tiles)
        if ($totalOrders > 0) {
            $content .= '<div class="fps-velocity-heatmap" style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;padding:0.5rem 0;">';
            for ($i = 0; $i < count($sparkValues); $i++) {
                $val = $sparkValues[$i];
                if ($val === 0) {
                    $color = 'rgba(255,255,255,0.04)';
                } elseif ($val === 1) {
                    $color = 'rgba(56,239,125,0.35)';
                } elseif ($val <= 3) {
                    $color = 'rgba(245,200,66,0.5)';
                } else {
                    $color = 'rgba(245,87,108,0.7)';
                }
                $date = date('M j', strtotime('-' . (89 - $i) . ' days'));
                $content .= '<div title="' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . ': ' . $val . ' orders" style="aspect-ratio:1;border-radius:3px;min-width:12px;min-height:12px;background:' . $color . ';cursor:default;transition:transform 0.15s;" onmouseover="this.style.transform=\'scale(1.3)\'" onmouseout="this.style.transform=\'scale(1)\'"></div>';
            }
            $content .= '</div>';
            $content .= '<div style="display:flex;gap:0.75rem;margin-top:0.5rem;font-size:0.7rem;color:#8892b0;">';
            $content .= '  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(255,255,255,0.04);vertical-align:middle;"></span> No orders</span>';
            $content .= '  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(56,239,125,0.35);vertical-align:middle;"></span> 1 order</span>';
            $content .= '  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(245,200,66,0.5);vertical-align:middle;"></span> 2-3 orders</span>';
            $content .= '  <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(245,87,108,0.7);vertical-align:middle;"></span> 4+ orders</span>';
            $content .= '</div>';
        } else {
            $content .= '<div style="text-align:center;padding:1.5rem;color:#8892b0;font-size:0.9rem;">';
            $content .= '  <i class="fas fa-chart-area" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:0.5rem;"></i>';
            $content .= '  No orders in the last 90 days';
            $content .= '</div>';
        }

        echo FpsAdminRenderer::renderCard('Order Velocity (90 Days)', 'fa-bolt', $content);
    }

    /**
     * Refund history and abuse indicators.
     */
    private function fpsRenderRefundHistory(int $clientId): void
    {
        $refunds = [];
        $chargebacks = [];
        try {
            $refunds = Capsule::table('mod_fps_refund_tracking')
                ->where('client_id', $clientId)
                ->orderByDesc('refund_date')
                ->limit(20)
                ->get()
                ->toArray();

            $chargebacks = Capsule::table('mod_fps_chargebacks')
                ->where('client_id', $clientId)
                ->orderByDesc('chargeback_date')
                ->limit(20)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            // Tables may not exist yet -- non-fatal
        }

        $refundCount = count($refunds);
        $cbCount = count($chargebacks);

        if ($refundCount === 0 && $cbCount === 0) {
            $content = '<p class="fps-text-muted"><i class="fas fa-check-circle"></i> No refunds or chargebacks recorded.</p>';
        } else {
            $content = '<div class="fps-form-row" style="margin-bottom:16px;">';
            $content .= '<div class="fps-velocity-stat"><strong>' . $refundCount . '</strong><br><small class="fps-text-muted">Refunds</small></div>';
            $content .= '<div class="fps-velocity-stat"><strong>' . $cbCount . '</strong><br><small class="fps-text-muted">Chargebacks</small></div>';

            $totalRefundAmt = array_sum(array_map(function ($r) { return (float)$r->amount; }, $refunds));
            $totalCbAmt = array_sum(array_map(function ($c) { return (float)$c->amount; }, $chargebacks));
            $content .= '<div class="fps-velocity-stat"><strong>$' . number_format($totalRefundAmt + $totalCbAmt, 2) . '</strong><br><small class="fps-text-muted">Total Lost</small></div>';
            $content .= '</div>';

            if ($refundCount > 0) {
                $headers = ['Date', 'Invoice', 'Amount', 'Reason'];
                $rows = [];
                foreach (array_slice($refunds, 0, 10) as $r) {
                    $rows[] = [
                        htmlspecialchars($r->refund_date ?? '', ENT_QUOTES, 'UTF-8'),
                        '#' . (int)($r->invoice_id ?? 0),
                        '$' . number_format((float)$r->amount, 2),
                        htmlspecialchars($r->reason ?? '--', ENT_QUOTES, 'UTF-8'),
                    ];
                }
                $content .= '<h5 style="margin:12px 0 8px;">Refunds</h5>';
                $content .= FpsAdminRenderer::renderTable($headers, $rows, 'fps-refund-table');
            }

            if ($cbCount > 0) {
                $headers = ['Date', 'Invoice', 'Amount', 'Gateway', 'Score at Order'];
                $rows = [];
                foreach (array_slice($chargebacks, 0, 10) as $cb) {
                    $scoreCell = $cb->fraud_score_at_order !== null
                        ? FpsAdminRenderer::renderBadge($cb->risk_level_at_order ?? 'low', (float)$cb->fraud_score_at_order)
                        : '<span class="fps-text-muted">N/A</span>';
                    $rows[] = [
                        htmlspecialchars($cb->chargeback_date ?? '', ENT_QUOTES, 'UTF-8'),
                        '#' . (int)($cb->invoice_id ?? 0),
                        '$' . number_format((float)$cb->amount, 2),
                        htmlspecialchars($cb->gateway ?? '--', ENT_QUOTES, 'UTF-8'),
                        $scoreCell,
                    ];
                }
                $content .= '<h5 style="margin:12px 0 8px;">Chargebacks</h5>';
                $content .= FpsAdminRenderer::renderTable($headers, $rows, 'fps-chargeback-table');
            }
        }

        // Abuse flag
        $abuseFlag = '';
        if ($refundCount >= 3 || $cbCount >= 1) {
            $abuseFlag = ' <span class="fps-badge fps-badge-critical"><i class="fas fa-exclamation-triangle"></i> Abuse Risk</span>';
        }

        echo FpsAdminRenderer::renderCard('Refunds & Chargebacks' . $abuseFlag, 'fa-money-bill-transfer', $content);
    }

    /**
     * Action buttons at the bottom of the profile.
     */
    private function fpsRenderActionButtons(int $clientId, string $ajaxUrl): void
    {
        echo '<div class="fps-action-bar">';
        echo FpsAdminRenderer::renderButton(
            'Run New Check', 'fa-play', "FpsAdmin.runManualCheck('{$ajaxUrl}', {$clientId})", 'primary', 'md'
        );
        echo FpsAdminRenderer::renderButton(
            'Report to FraudRecord', 'fa-flag', "FpsAdmin.reportClientToFraudRecord({$clientId}, '{$ajaxUrl}')", 'warning', 'md'
        );
        echo FpsAdminRenderer::renderButton(
            'Terminate Account', 'fa-skull-crossbones', "FpsAdmin.terminateClient({$clientId}, '{$ajaxUrl}')", 'danger', 'md'
        );
        echo FpsAdminRenderer::renderButton(
            'Export Profile', 'fa-file-export', "FpsAdmin.exportClientProfile({$clientId}, '{$ajaxUrl}')", 'info', 'md'
        );
        echo '</div>';
    }
}

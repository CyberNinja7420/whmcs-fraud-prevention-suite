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
        $input = trim((string) ($_GET['client_id'] ?? ''));
        $clientId = 0;

        if ($input !== '') {
            if (is_numeric($input)) {
                $clientId = (int)$input;
            } else {
                // Treat as email lookup
                try {
                    $client = Capsule::table('tblclients')
                        ->where('email', $input)
                        ->first(['id']);
                    if ($client) {
                        $clientId = (int)$client->id;
                    }
                } catch (\Throwable $e) {
                    logModuleCall('fraud_prevention_suite', 'TabClientProfile::emailLookup', $input, $e->getMessage());
                }

                if ($clientId === 0) {
                    // Try partial name match
                    try {
                        $client = Capsule::table('tblclients')
                            ->where(function ($q) use ($input) {
                                $q->where('email', 'LIKE', '%' . $input . '%')
                                  ->orWhereRaw("CONCAT(firstname, ' ', lastname) LIKE ?", ['%' . $input . '%'])
                                  ->orWhere('companyname', 'LIKE', '%' . $input . '%');
                            })
                            ->first(['id']);
                        if ($client) {
                            $clientId = (int)$client->id;
                        }
                    } catch (\Throwable $e) {
                        logModuleCall('fraud_prevention_suite', 'TabClientProfile::partialNameLookup', $input, $e->getMessage());
                    }
                }
            }
        }

        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderSearchForm($modulelink, $clientId, $input);

        if ($clientId > 0) {
            $this->fpsRenderProfileContent($clientId, $modulelink, $ajaxUrl);
        } elseif ($input !== '' && $clientId === 0) {
            echo '<div style="padding:1.5rem;text-align:center;color:#f5576c;font-size:0.95rem;">'
                . '<i class="fas fa-exclamation-triangle"></i> No client found matching "<strong>'
                . htmlspecialchars($input, ENT_QUOTES, 'UTF-8') . '</strong>". Try a client ID, email, or name.</div>';
        }
    }

    /**
     * Client search input and load button.
     */
    private function fpsRenderSearchForm(string $modulelink, int $currentId, string $originalInput = ''): void
    {
        $safeLink = htmlspecialchars($modulelink, ENT_QUOTES, 'UTF-8');
        $safeId   = $originalInput !== '' ? htmlspecialchars($originalInput, ENT_QUOTES, 'UTF-8') : ($currentId > 0 ? (string)$currentId : '');

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
            $this->fpsRenderIpIntelPanel($clientId, $ajaxUrl);

            // Email intelligence
            $this->fpsRenderEmailIntelPanel($client->email, $clientId, $ajaxUrl);

            // Device fingerprints
            $this->fpsRenderFingerprintPanel($clientId, $ajaxUrl);

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

            // Risk score timeline chart
            $this->fpsRenderRiskTimeline($clientId, $modulelink);

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
     * Profile header hero banner with avatar, stats cards, and status badge.
     */
    private function fpsRenderProfileHeader(object $client): void
    {
        $clientId  = (int)($client->id ?? 0);
        $firstName = $client->firstname ?? '';
        $lastName  = $client->lastname ?? '';
        $fullName  = trim($firstName . ' ' . $lastName);
        $name      = htmlspecialchars($fullName ?: 'Unknown Client', ENT_QUOTES, 'UTF-8');
        $email     = htmlspecialchars($client->email ?? '', ENT_QUOTES, 'UTF-8');
        $status    = htmlspecialchars($client->status ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $created   = htmlspecialchars($client->datecreated ?? '', ENT_QUOTES, 'UTF-8');
        $country   = htmlspecialchars(strtoupper($client->country ?? ''), ENT_QUOTES, 'UTF-8');

        // Initials for avatar
        $initials = '';
        if ($firstName !== '') $initials .= strtoupper(substr($firstName, 0, 1));
        if ($lastName !== '')  $initials .= strtoupper(substr($lastName, 0, 1));
        if ($initials === '')  $initials = '?';

        // Country flag emoji (regional indicator letters A=0x1F1E6)
        $flagEmoji = '';
        if (strlen($country) === 2 && ctype_alpha($country)) {
            $a = 0x1F1E6 + (ord($country[0]) - ord('A'));
            $b = 0x1F1E6 + (ord($country[1]) - ord('A'));
            $flagEmoji = mb_convert_encoding('&#' . $a . ';&#' . $b . ';', 'UTF-8', 'HTML-ENTITIES');
        }

        // Stats
        $totalSpend  = 0.00;
        $totalOrders = 0;
        $totalServices = 0;
        try {
            $totalSpend = (float)Capsule::table('tblinvoices')
                ->where('userid', $client->id)
                ->where('status', 'Paid')
                ->sum('total');
            $totalOrders = (int)Capsule::table('tblorders')
                ->where('userid', $client->id)
                ->count();
            $totalServices = (int)Capsule::table('tblhosting')
                ->where('userid', $client->id)
                ->count();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // Account age
        $accountAgeDays = 0;
        $accountAgeLabel = '--';
        if ($created && $created !== '') {
            try {
                $createdTs = strtotime($created);
                if ($createdTs !== false) {
                    $accountAgeDays = (int)floor((time() - $createdTs) / 86400);
                    if ($accountAgeDays >= 365) {
                        $accountAgeLabel = round($accountAgeDays / 365, 1) . 'y';
                    } elseif ($accountAgeDays >= 30) {
                        $accountAgeLabel = round($accountAgeDays / 30) . 'mo';
                    } else {
                        $accountAgeLabel = $accountAgeDays . 'd';
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        // Status colors and glow
        [$statusColor, $statusGlow, $statusBg] = match (strtolower($client->status ?? '')) {
            'active'   => ['#38ef7d', 'rgba(56,239,125,0.4)', 'rgba(56,239,125,0.12)'],
            'closed'   => ['#eb3349', 'rgba(235,51,73,0.4)',  'rgba(235,51,73,0.12)'],
            'banned'   => ['#eb3349', 'rgba(235,51,73,0.4)',  'rgba(235,51,73,0.12)'],
            'inactive' => ['#f5c842', 'rgba(245,200,66,0.4)', 'rgba(245,200,66,0.12)'],
            default    => ['#667eea', 'rgba(102,126,234,0.4)','rgba(102,126,234,0.12)'],
        };

        $spendFmt  = '$' . number_format($totalSpend, 2);
        $countryDisplay = ($flagEmoji !== '' ? $flagEmoji . ' ' : '') . ($country !== '' ? $country : '--');

        $content = <<<HTML
<div style="
    background: linear-gradient(135deg, #0f1628 0%, #1a1f3a 40%, #2d1b5e 100%);
    border-radius: 16px;
    padding: 2rem 2rem 1.5rem 2rem;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(102,126,234,0.2);
    box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.06);
">
  <!-- Background grid pattern -->
  <div style="
      position:absolute;inset:0;
      background-image:linear-gradient(rgba(102,126,234,0.05) 1px,transparent 1px),linear-gradient(90deg,rgba(102,126,234,0.05) 1px,transparent 1px);
      background-size:32px 32px;
      pointer-events:none;
  "></div>

  <!-- Top row: avatar + identity + status -->
  <div style="display:flex;align-items:center;gap:1.75rem;position:relative;flex-wrap:wrap;">

    <!-- Avatar circle with initials -->
    <div style="
        width:80px;height:80px;border-radius:50%;
        background:linear-gradient(135deg,#667eea,#764ba2);
        display:flex;align-items:center;justify-content:center;
        font-size:1.8rem;font-weight:900;color:#fff;
        box-shadow:0 0 0 3px rgba(102,126,234,0.3),0 0 24px rgba(102,126,234,0.25);
        flex-shrink:0;letter-spacing:-1px;
        text-shadow:0 2px 4px rgba(0,0,0,0.3);
    ">{$initials}</div>

    <!-- Name + email + status badge -->
    <div style="flex:1;min-width:200px;">
      <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.35rem;">
        <h2 style="margin:0;font-size:1.7rem;font-weight:800;color:#fff;letter-spacing:-0.02em;text-shadow:0 2px 8px rgba(0,0,0,0.3);">{$name}</h2>
        <span style="
            display:inline-flex;align-items:center;gap:5px;
            padding:3px 12px;border-radius:20px;
            font-size:0.72rem;font-weight:700;letter-spacing:0.08em;
            color:{$statusColor};
            background:{$statusBg};
            border:1px solid {$statusColor};
            box-shadow:0 0 10px {$statusGlow};
            text-transform:uppercase;
        ">
          <span style="width:6px;height:6px;border-radius:50%;background:{$statusColor};box-shadow:0 0 6px {$statusColor};display:inline-block;animation:fps-pulse 2s infinite;"></span>
          {$status}
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <span style="font-size:0.9rem;color:#a0aec0;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-envelope" style="color:#667eea;font-size:0.8rem;"></i>
          {$email}
        </span>
        <span style="font-size:0.9rem;color:#a0aec0;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-calendar-alt" style="color:#667eea;font-size:0.8rem;"></i>
          Joined {$created}
        </span>
        <span style="font-size:0.9rem;color:#a0aec0;">
          {$countryDisplay}
        </span>
      </div>
    </div>

    <!-- Client ID badge -->
    <div style="
        padding:0.5rem 1rem;border-radius:10px;
        background:rgba(102,126,234,0.1);border:1px solid rgba(102,126,234,0.2);
        text-align:center;flex-shrink:0;
    ">
      <div style="font-size:0.65rem;color:#667eea;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Client ID</div>
      <div style="font-size:1.3rem;font-weight:800;color:#fff;">#{$clientId}</div>
    </div>
  </div>

  <!-- Stats row: glass-morphism cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem;margin-top:1.5rem;position:relative;">

    <!-- Total Spend -->
    <div style="
        background:rgba(255,255,255,0.04);
        backdrop-filter:blur(10px);
        border:1px solid rgba(255,255,255,0.08);
        border-radius:12px;padding:1rem;
        text-align:center;
        transition:transform 0.2s,border-color 0.2s;
        box-shadow:0 4px 16px rgba(0,0,0,0.2);
    " onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(102,126,234,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.borderColor='rgba(255,255,255,0.08)'">
      <div style="font-size:0.65rem;color:#667eea;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.4rem;">
        <i class="fas fa-dollar-sign" style="margin-right:4px;"></i>Total Spend
      </div>
      <div style="font-size:1.4rem;font-weight:800;color:#fff;">{$spendFmt}</div>
    </div>

    <!-- Account Age -->
    <div style="
        background:rgba(255,255,255,0.04);
        backdrop-filter:blur(10px);
        border:1px solid rgba(255,255,255,0.08);
        border-radius:12px;padding:1rem;
        text-align:center;
        transition:transform 0.2s,border-color 0.2s;
        box-shadow:0 4px 16px rgba(0,0,0,0.2);
    " onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(102,126,234,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.borderColor='rgba(255,255,255,0.08)'">
      <div style="font-size:0.65rem;color:#667eea;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.4rem;">
        <i class="fas fa-hourglass-half" style="margin-right:4px;"></i>Account Age
      </div>
      <div style="font-size:1.4rem;font-weight:800;color:#fff;">{$accountAgeLabel}</div>
    </div>

    <!-- Total Orders -->
    <div style="
        background:rgba(255,255,255,0.04);
        backdrop-filter:blur(10px);
        border:1px solid rgba(255,255,255,0.08);
        border-radius:12px;padding:1rem;
        text-align:center;
        transition:transform 0.2s,border-color 0.2s;
        box-shadow:0 4px 16px rgba(0,0,0,0.2);
    " onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(102,126,234,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.borderColor='rgba(255,255,255,0.08)'">
      <div style="font-size:0.65rem;color:#667eea;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.4rem;">
        <i class="fas fa-shopping-cart" style="margin-right:4px;"></i>Total Orders
      </div>
      <div style="font-size:1.4rem;font-weight:800;color:#fff;">{$totalOrders}</div>
    </div>

    <!-- Total Services -->
    <div style="
        background:rgba(255,255,255,0.04);
        backdrop-filter:blur(10px);
        border:1px solid rgba(255,255,255,0.08);
        border-radius:12px;padding:1rem;
        text-align:center;
        transition:transform 0.2s,border-color 0.2s;
        box-shadow:0 4px 16px rgba(0,0,0,0.2);
    " onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(102,126,234,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.borderColor='rgba(255,255,255,0.08)'">
      <div style="font-size:0.65rem;color:#667eea;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.4rem;">
        <i class="fas fa-server" style="margin-right:4px;"></i>Total Services
      </div>
      <div style="font-size:1.4rem;font-weight:800;color:#fff;">{$totalServices}</div>
    </div>

  </div>
</div>
<style>
@keyframes fps-pulse {
  0%,100%{opacity:1;transform:scale(1);}
  50%{opacity:0.6;transform:scale(1.3);}
}
</style>
HTML;

        echo $content;
    }

    /**
     * Threat assessment command panel with semicircular speedometer gauge.
     */
    private function fpsRenderRiskGauge(int $clientId): void
    {
        $avgScore  = 0.0;
        $checkCount = 0;
        $lastCheck  = null;
        $providerBreakdown = [];

        try {
            $avgScore   = (float)Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)->avg('risk_score');
            $checkCount = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)->count();
            $lastCheck  = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)->orderByDesc('created_at')
                ->first(['risk_score', 'risk_level', 'provider_scores', 'created_at', 'action_taken']);

            if ($lastCheck && $lastCheck->provider_scores) {
                $providerBreakdown = json_decode($lastCheck->provider_scores, true) ?: [];
            }
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'TabClientProfile::riskScoreQuery', (string)$clientId, $e->getMessage());
        }

        $score = round($avgScore, 1);
        $pct   = min(100, max(0, $score));

        // Color theme based on score
        if ($pct >= 80) {
            $c1 = '#eb3349'; $c2 = '#f45c43'; $glow = 'rgba(235,51,73,0.5)'; $glowLight = 'rgba(235,51,73,0.15)'; $borderGlow = 'rgba(235,51,73,0.3)';
        } elseif ($pct >= 60) {
            $c1 = '#f5576c'; $c2 = '#ff8a5c'; $glow = 'rgba(245,87,108,0.45)'; $glowLight = 'rgba(245,87,108,0.12)'; $borderGlow = 'rgba(245,87,108,0.25)';
        } elseif ($pct >= 30) {
            $c1 = '#f5c842'; $c2 = '#ff8008'; $glow = 'rgba(245,200,66,0.4)'; $glowLight = 'rgba(245,200,66,0.1)'; $borderGlow = 'rgba(245,200,66,0.2)';
        } else {
            $c1 = '#38ef7d'; $c2 = '#11998e'; $glow = 'rgba(56,239,125,0.4)'; $glowLight = 'rgba(56,239,125,0.1)'; $borderGlow = 'rgba(56,239,125,0.2)';
        }

        $level     = match (true) { $pct >= 80 => 'CRITICAL', $pct >= 60 => 'HIGH', $pct >= 30 => 'MEDIUM', default => 'LOW' };
        $lastTime  = $lastCheck ? htmlspecialchars($lastCheck->created_at, ENT_QUOTES, 'UTF-8') : 'Never';
        $lastAction = $lastCheck ? htmlspecialchars($lastCheck->action_taken ?? 'none', ENT_QUOTES, 'UTF-8') : '--';

        // Semicircular speedometer SVG math
        // The arc spans 180 degrees (semicircle), from left to right across the bottom
        // Center: 120,120  Radius: 90
        // Start angle: 180deg (left), End angle: 0deg (right), sweep: 180deg = pi*r = pi*90 = 282.74
        $cx = 120; $cy = 110; $r = 85;
        $totalArcLen = M_PI * $r; // half circumference = 266.9

        // Track arc: from 180deg to 0deg (going clockwise = positive direction in SVG)
        // In SVG, angles go clockwise from 3 o'clock.
        // We want the arc to start at the 9 o'clock position (left) and go to 3 o'clock (right).
        // Start point: (cx - r, cy) = (35, 110)
        // End point:   (cx + r, cy) = (205, 110)
        $trackStartX = $cx - $r; // 35
        $trackStartY = $cy;      // 110
        $trackEndX   = $cx + $r; // 205
        $trackEndY   = $cy;      // 110

        // Filled arc length based on score
        $filledLen = $totalArcLen * ($pct / 100);
        // The track is a half-circle with total length ~266.9
        // We use stroke-dasharray trick: set total stroke length to totalArcLen*2 to avoid issues,
        // then dashoffset to hide the bottom half.
        $fullCirc    = 2 * M_PI * $r; // full circumference
        $dashFilled  = round($filledLen, 2);
        $dashGap     = round($fullCirc - $filledLen, 2);

        // Needle angle: maps 0->100 to 180->0 degrees (left to right across the top)
        $needleAngleDeg = 180 - ($pct * 1.8); // 0=>180, 100=>0
        $needleAngleRad = deg2rad($needleAngleDeg);
        $needleLen  = 70;
        $needleTipX = round($cx + $needleLen * cos($needleAngleRad), 2);
        $needleTipY = round($cy - $needleLen * sin($needleAngleRad), 2); // SVG Y is inverted
        // Wait: in standard math, angle 180 is left, but in SVG coordinate system
        // y increases downward. Let's recalculate properly.
        // SVG: (cx + r*cos(theta), cy - r*sin(theta)) where theta is measured CCW from right.
        // We want: score=0 -> needle points left (theta=180), score=100 -> needle points right (theta=0)
        // theta = 180 - score*1.8 (degrees)
        // But since SVG Y is flipped: tipY = cy - r*sin(theta_rad) actually works for the bottom arc display
        // because sin(180deg)=0, sin(90deg)=1, needle at top for mid-score.
        // Let's verify: score=50 -> theta=90 -> tip=(cx, cy-needleLen) = (120, 40) = pointing up. Correct.
        // score=0 -> theta=180 -> tip=(cx-needleLen, cy) = (50, 110) = pointing left. Correct.
        // score=100 -> theta=0 -> tip=(cx+needleLen, cy) = (190, 110) = pointing right. Correct.

        // Re-derive with correct SVG formula
        $thetaRad   = deg2rad(180 - ($pct * 1.8));
        $needleTipX = round($cx + $needleLen * cos($thetaRad), 2);
        $needleTipY = round($cy - $needleLen * sin($thetaRad), 2);

        // Track arc: uses stroke-dasharray on a full circle, rotated so only top half is visible
        // Easier approach: draw the arc with path element
        // Track path: large-arc-flag=1 (since it's exactly a semicircle), sweep=1 (clockwise)
        $trackPath  = "M {$trackStartX},{$trackStartY} A {$r},{$r} 0 0 1 {$trackEndX},{$trackEndY}";

        // Filled arc path (partial, from left to the score position)
        // Angle at score: theta from above
        $filledEndX = round($cx + $r * cos($thetaRad), 2);
        $filledEndY = round($cy - $r * sin($thetaRad), 2);
        // large-arc-flag: 1 if arc > 180deg (score > ~100 which won't happen), else 0
        // For score < 50: arc < 90deg, flag=0
        // For score > 50: arc > 90deg, flag=0 still (arc < 180deg until score=100)
        $largeArc = ($pct >= 100) ? 1 : 0;
        // sweep-flag=1 means clockwise; we go from left (180deg) clockwise to the score point
        // Actually for our coordinate system where theta decreases as score increases,
        // going from left to score point is COUNTER-clockwise in standard math,
        // but in SVG (Y-flipped), going from (35,110) counter-clockwise means sweep=0.
        // Let's think in SVG coords: leftmost point (35,110), rightmost (205,110).
        // Going from left to right along the TOP is: sweep-flag=0 (counter-clockwise in SVG = arc goes through top)
        // sweep-flag=1 would go through the bottom.
        // So: from left point to score point, going through top arc = sweep=0, large-arc depends.
        // For score 0-50: arc < 90deg -> large-arc=0, sweep=0
        // For score 50-100: arc 90-180deg -> large-arc=0, sweep=0 (still < 180, so large-arc=0)
        // For score exactly 100: arc=180deg, we'd reach the right point. large-arc can be 0 or 1 ambiguous.
        $fillLargeArc = 0;
        // Edge case: if score == 0, don't draw a filled arc
        $filledArcPath = ($pct > 0)
            ? "M {$trackStartX},{$trackStartY} A {$r},{$r} 0 {$fillLargeArc} 0 {$filledEndX},{$filledEndY}"
            : '';

        // Tick marks (11 ticks: 0,10,20...100)
        $ticksHtml = '';
        for ($t = 0; $t <= 10; $t++) {
            $tPct  = $t * 10;
            $tRad  = deg2rad(180 - ($tPct * 1.8));
            $innerR = ($t % 5 === 0) ? $r - 14 : $r - 9;
            $outerR = $r - 2;
            $tx1 = round($cx + $innerR * cos($tRad), 2);
            $ty1 = round($cy - $innerR * sin($tRad), 2);
            $tx2 = round($cx + $outerR * cos($tRad), 2);
            $ty2 = round($cy - $outerR * sin($tRad), 2);
            $tickColor = ($tPct >= 80) ? '#eb3349' : (($tPct >= 60) ? '#f5576c' : (($tPct >= 30) ? '#f5c842' : '#38ef7d'));
            $tickW = ($t % 5 === 0) ? '2' : '1';
            $ticksHtml .= "<line x1='{$tx1}' y1='{$ty1}' x2='{$tx2}' y2='{$ty2}' stroke='{$tickColor}' stroke-width='{$tickW}' opacity='0.7'/>";
        }

        // Score labels
        $labelsHtml = '';
        foreach ([0, 25, 50, 75, 100] as $lv) {
            $lRad  = deg2rad(180 - ($lv * 1.8));
            $labelR = $r - 24;
            $lx = round($cx + $labelR * cos($lRad), 2);
            $ly = round($cy - $labelR * sin($lRad), 2);
            $labelsHtml .= "<text x='{$lx}' y='{$ly}' text-anchor='middle' dominant-baseline='middle' fill='rgba(255,255,255,0.35)' font-size='8' font-family='monospace'>{$lv}</text>";
        }

        $gradId     = 'fpsGaugeGrad_' . $clientId;
        $glowId     = 'fpsGaugeGlow_' . $clientId;
        $trackColor = 'rgba(255,255,255,0.06)';

        // Provider breakdown bars
        $barsHtml = '';
        if (!empty($providerBreakdown)) {
            $barsHtml .= '<div style="margin-top:1.25rem;border-top:1px solid rgba(255,255,255,0.06);padding-top:1rem;">';
            $barsHtml .= '<div style="font-size:0.68rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.6rem;"><i class="fas fa-chart-bar" style="margin-right:5px;"></i>Provider Breakdown (Latest Check)</div>';
            arsort($providerBreakdown);
            foreach (array_slice($providerBreakdown, 0, 8, true) as $prov => $pScore) {
                $pScore   = round((float)$pScore, 1);
                $barW     = min(100, max(2, $pScore));
                $barColor = $pScore >= 60 ? '#eb3349' : ($pScore >= 30 ? '#f5c842' : '#38ef7d');
                $barGlow  = $pScore >= 60 ? 'rgba(235,51,73,0.4)' : ($pScore >= 30 ? 'rgba(245,200,66,0.4)' : 'rgba(56,239,125,0.4)');
                $provName = htmlspecialchars(str_replace('_', ' ', $prov), ENT_QUOTES, 'UTF-8');
                $barsHtml .= '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
                $barsHtml .= '  <span style="width:90px;font-size:0.7rem;color:#8892b0;text-align:right;flex-shrink:0;">' . ucwords($provName) . '</span>';
                $barsHtml .= '  <div style="flex:1;height:7px;background:rgba(255,255,255,0.04);border-radius:4px;overflow:hidden;">';
                $barsHtml .= '    <div style="width:' . $barW . '%;height:100%;background:linear-gradient(90deg,' . $barColor . ',' . $barColor . 'aa);border-radius:4px;box-shadow:0 0 6px ' . $barGlow . ';transition:width 1s cubic-bezier(0.4,0,0.2,1);"></div>';
                $barsHtml .= '  </div>';
                $barsHtml .= '  <span style="width:34px;font-size:0.72rem;color:#b0b8d0;font-weight:700;text-align:right;">' . $pScore . '</span>';
                $barsHtml .= '</div>';
            }
            $barsHtml .= '</div>';
        }

        $content = <<<HTML
<div style="
    background:linear-gradient(160deg,#0d1226 0%,#141830 60%,#1a1040 100%);
    border:1px solid {$borderGlow};
    border-radius:16px;
    padding:1.5rem;
    box-shadow:0 8px 32px rgba(0,0,0,0.35),0 0 0 1px rgba(255,255,255,0.03),inset 0 1px 0 rgba(255,255,255,0.05);
">
  <div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

    <!-- Speedometer gauge -->
    <div style="display:flex;flex-direction:column;align-items:center;min-width:220px;flex:0 0 auto;">
      <div style="position:relative;filter:drop-shadow(0 0 20px {$glow});">
        <svg viewBox="0 0 240 130" width="240" height="130" style="overflow:visible;">
          <defs>
            <linearGradient id="{$gradId}" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#38ef7d"/>
              <stop offset="40%" stop-color="#f5c842"/>
              <stop offset="70%" stop-color="#f5576c"/>
              <stop offset="100%" stop-color="#eb3349"/>
            </linearGradient>
            <filter id="{$glowId}" x="-20%" y="-20%" width="140%" height="140%">
              <feGaussianBlur stdDeviation="3" result="blur"/>
              <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
          </defs>

          <!-- Track arc (background) -->
          <path d="{$trackPath}" fill="none" stroke="{$trackColor}" stroke-width="10" stroke-linecap="round"/>

          <!-- Colored track gradient (full, dimmed) -->
          <path d="{$trackPath}" fill="none" stroke="url(#{$gradId})" stroke-width="10" stroke-linecap="round" opacity="0.15"/>

          <!-- Filled arc (active score portion) -->
HTML;

        if ($filledArcPath !== '') {
            $content .= <<<HTML
          <path d="{$filledArcPath}" fill="none" stroke="url(#{$gradId})" stroke-width="10" stroke-linecap="round" filter="url(#{$glowId})" opacity="0.9"/>
HTML;
        }

        $content .= <<<HTML
          <!-- Tick marks -->
          {$ticksHtml}

          <!-- Score labels -->
          {$labelsHtml}

          <!-- Needle -->
          <line x1="{$cx}" y1="{$cy}" x2="{$needleTipX}" y2="{$needleTipY}"
            stroke="rgba(255,255,255,0.9)" stroke-width="2.5" stroke-linecap="round"
            style="filter:drop-shadow(0 0 4px rgba(255,255,255,0.5));"/>
          <circle cx="{$cx}" cy="{$cy}" r="6" fill="{$c1}" style="filter:drop-shadow(0 0 8px {$glow});"/>
          <circle cx="{$cx}" cy="{$cy}" r="3" fill="#fff" opacity="0.9"/>
        </svg>
      </div>

      <!-- Score display -->
      <div style="margin-top:-0.25rem;text-align:center;">
        <div style="font-size:3.2rem;font-weight:900;color:#fff;line-height:1;text-shadow:0 0 30px {$glow},0 2px 8px rgba(0,0,0,0.5);letter-spacing:-2px;">{$score}</div>
        <div style="font-size:0.65rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.12em;margin-top:1px;">Risk Score / 100</div>
      </div>

      <!-- Level badge -->
      <div style="margin-top:0.6rem;">
        <span style="
            display:inline-block;
            padding:4px 18px;border-radius:20px;
            font-size:0.8rem;font-weight:800;letter-spacing:0.1em;
            color:#fff;
            background:linear-gradient(135deg,{$c1},{$c2});
            box-shadow:0 2px 12px {$glow},0 0 0 1px rgba(255,255,255,0.1);
            text-transform:uppercase;
        ">{$level}</span>
      </div>
    </div>

    <!-- Stats + Breakdown -->
    <div style="flex:1;min-width:200px;">

      <!-- 3-stat grid -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:0.5rem;">
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-size:1.5rem;font-weight:800;color:#667eea;line-height:1;">{$checkCount}</div>
          <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-top:3px;">Total Checks</div>
        </div>
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:12px;text-align:center;overflow:hidden;">
          <div style="font-size:0.9rem;font-weight:700;color:#b0b8d0;line-height:1.2;word-break:break-all;">{$lastAction}</div>
          <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-top:3px;">Last Action</div>
        </div>
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:12px;text-align:center;overflow:hidden;">
          <div style="font-size:0.7rem;font-weight:600;color:#b0b8d0;line-height:1.3;word-break:break-all;">{$lastTime}</div>
          <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-top:3px;">Last Scanned</div>
        </div>
      </div>

      {$barsHtml}
    </div>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Threat Assessment Command Panel', 'fa-shield-halved', $content);
    }

    /**
     * IP intelligence panel with pill badges and geo layout.
     */
    private function fpsRenderIpIntelPanel(int $clientId, string $ajaxUrl = ''): void
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
            $content = <<<HTML
<div style="text-align:center;padding:2rem 1rem;">
  <div style="font-size:2.5rem;color:#667eea;opacity:0.4;margin-bottom:1rem;">
    <i class="fas fa-globe"></i>
  </div>
  <div style="font-family:monospace;font-size:1.1rem;color:#667eea;margin-bottom:0.5rem;letter-spacing:0.05em;">{$safeIp}</div>
  <div style="color:#6a7195;font-size:0.85rem;margin-bottom:1.25rem;">No IP intelligence data cached for this address.</div>
  <button type="button" onclick="FpsAdmin.runManualCheck('{$ajaxUrl}', {$clientId})" style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);border:none;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(102,126,234,0.3);transition:transform 0.15s,box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 12px rgba(102,126,234,0.4)'" onmouseout="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(102,126,234,0.3)'">
    <i class="fas fa-play"></i> Scan Now
  </button>
</div>
HTML;
        } else {
            $asn     = htmlspecialchars((string)($ipIntel->asn ?? '--'), ENT_QUOTES, 'UTF-8');
            $asnOrg  = htmlspecialchars($ipIntel->asn_org ?? '--', ENT_QUOTES, 'UTF-8');
            $isp     = htmlspecialchars($ipIntel->isp ?? '--', ENT_QUOTES, 'UTF-8');
            $country = htmlspecialchars($ipIntel->country_code ?? '--', ENT_QUOTES, 'UTF-8');
            $city    = htmlspecialchars($ipIntel->city ?? '--', ENT_QUOTES, 'UTF-8');
            $lat     = htmlspecialchars((string)($ipIntel->latitude ?? '--'), ENT_QUOTES, 'UTF-8');
            $lng     = htmlspecialchars((string)($ipIntel->longitude ?? '--'), ENT_QUOTES, 'UTF-8');

            // Threat indicator pills
            $pillsHtml = '';

            if ($ipIntel->is_proxy) {
                $pillsHtml .= '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;letter-spacing:0.06em;color:#fff;background:rgba(235,51,73,0.2);border:1px solid rgba(235,51,73,0.5);box-shadow:0 0 8px rgba(235,51,73,0.2);margin:2px;"><i class="fas fa-mask"></i> PROXY</span>';
            }
            if (!empty($ipIntel->is_vpn) && $ipIntel->is_vpn) {
                $pillsHtml .= '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;letter-spacing:0.06em;color:#fff;background:rgba(245,87,108,0.2);border:1px solid rgba(245,87,108,0.5);box-shadow:0 0 8px rgba(245,87,108,0.2);margin:2px;"><i class="fas fa-user-secret"></i> VPN</span>';
            }
            if (!empty($ipIntel->is_tor) && $ipIntel->is_tor) {
                $pillsHtml .= '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;letter-spacing:0.06em;color:#fff;background:rgba(235,51,73,0.25);border:1px solid rgba(235,51,73,0.6);box-shadow:0 0 12px rgba(235,51,73,0.3);margin:2px;"><i class="fas fa-circle-nodes"></i> TOR</span>';
            }
            if (!empty($ipIntel->is_datacenter) && $ipIntel->is_datacenter) {
                $pillsHtml .= '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;letter-spacing:0.06em;color:#fff;background:rgba(245,200,66,0.15);border:1px solid rgba(245,200,66,0.4);box-shadow:0 0 8px rgba(245,200,66,0.15);margin:2px;"><i class="fas fa-server"></i> DATACENTER</span>';
            }
            if ($pillsHtml === '') {
                $pillsHtml = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;color:#38ef7d;background:rgba(56,239,125,0.1);border:1px solid rgba(56,239,125,0.3);"><i class="fas fa-shield-check"></i> CLEAN</span>';
            }

            $content = <<<HTML
<!-- IP address display -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;padding:0.85rem 1rem;background:rgba(102,126,234,0.07);border:1px solid rgba(102,126,234,0.15);border-radius:10px;">
  <i class="fas fa-globe" style="font-size:1.4rem;color:#667eea;opacity:0.8;flex-shrink:0;"></i>
  <div>
    <div style="font-family:monospace;font-size:1.3rem;font-weight:700;color:#fff;letter-spacing:0.08em;">{$safeIp}</div>
    <div style="font-size:0.7rem;color:#6a7195;margin-top:1px;">Active IP Address</div>
  </div>
</div>

<!-- Threat indicators -->
<div style="margin-bottom:1.25rem;">
  <div style="font-size:0.65rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Threat Indicators</div>
  <div style="display:flex;flex-wrap:wrap;gap:4px;">{$pillsHtml}</div>
</div>

<!-- Geo + network info in 2-column grid -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:3px;"><i class="fas fa-flag" style="margin-right:3px;"></i>Country</div>
    <div style="font-size:0.9rem;color:#c8d0e0;font-weight:600;">{$country}</div>
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:3px;"><i class="fas fa-city" style="margin-right:3px;"></i>City</div>
    <div style="font-size:0.9rem;color:#c8d0e0;font-weight:600;">{$city}</div>
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:3px;"><i class="fas fa-building" style="margin-right:3px;"></i>ISP</div>
    <div style="font-size:0.85rem;color:#c8d0e0;font-weight:500;word-break:break-all;">{$isp}</div>
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:3px;"><i class="fas fa-network-wired" style="margin-right:3px;"></i>ASN</div>
    <div style="font-size:0.85rem;color:#c8d0e0;font-weight:500;">AS{$asn}</div>
    <div style="font-size:0.72rem;color:#8892b0;">{$asnOrg}</div>
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;grid-column:1/-1;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:3px;"><i class="fas fa-location-dot" style="margin-right:3px;"></i>Coordinates</div>
    <div style="font-size:0.85rem;color:#c8d0e0;font-family:monospace;">{$lat}, {$lng}</div>
  </div>
</div>
HTML;
        }

        echo FpsAdminRenderer::renderCard('IP Intelligence', 'fa-network-wired', $content);
    }

    /**
     * Email intelligence panel with domain highlight and traffic-light indicators.
     */
    private function fpsRenderEmailIntelPanel(string $email, int $clientId = 0, string $ajaxUrl = ''): void
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

        // Split email into user and domain parts for styled display
        $emailParts = explode('@', $email, 2);
        $emailUser   = htmlspecialchars($emailParts[0] ?? $email, ENT_QUOTES, 'UTF-8');
        $emailDomain = htmlspecialchars($emailParts[1] ?? '', ENT_QUOTES, 'UTF-8');

        if (!$emailIntel) {
            $content = <<<HTML
<div style="text-align:center;padding:2rem 1rem;">
  <div style="font-size:2rem;color:#667eea;opacity:0.4;margin-bottom:1rem;"><i class="fas fa-at"></i></div>
  <div style="font-size:1.05rem;color:#c8d0e0;margin-bottom:0.5rem;font-family:monospace;">
    <span style="color:#a0aec0;">{$emailUser}</span><span style="color:#667eea;">@</span><span style="color:#7f8fcc;">{$emailDomain}</span>
  </div>
  <div style="color:#6a7195;font-size:0.85rem;margin-bottom:1rem;">No email intelligence data cached.</div>
  <button type="button" onclick="FpsAdmin.runManualCheck('{$ajaxUrl}', {$clientId})" style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);border:none;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(102,126,234,0.3);">
    <i class="fas fa-play"></i> Scan Now
  </button>
</div>
HTML;
        } else {
            $domainAgeDays = (int)($emailIntel->domain_age_days ?? 0);
            $domainAgeLabel = $domainAgeDays > 0
                ? htmlspecialchars((string)$domainAgeDays, ENT_QUOTES, 'UTF-8') . ' days'
                : '--';
            $breachCount = (int)($emailIntel->breach_count ?? 0);

            // Traffic light helper: returns inline SVG dot + label
            $trafficLight = static function (bool $isTrue, string $trueLabel, string $falseLabel, bool $redIsTrue = true): string {
                if ($isTrue) {
                    $color = $redIsTrue ? '#eb3349' : '#38ef7d';
                    $glow  = $redIsTrue ? 'rgba(235,51,73,0.6)' : 'rgba(56,239,125,0.6)';
                    $label = $trueLabel;
                } else {
                    $color = $redIsTrue ? '#38ef7d' : '#eb3349';
                    $glow  = $redIsTrue ? 'rgba(56,239,125,0.6)' : 'rgba(235,51,73,0.6)';
                    $label = $falseLabel;
                }
                return '<span style="display:inline-flex;align-items:center;gap:6px;">'
                    . '<span style="width:10px;height:10px;border-radius:50%;background:' . $color . ';box-shadow:0 0 8px ' . $glow . ';flex-shrink:0;display:inline-block;"></span>'
                    . '<span style="font-size:0.88rem;color:#c8d0e0;font-weight:600;">' . $label . '</span>'
                    . '</span>';
            };

            $mxLight         = $trafficLight((bool)$emailIntel->mx_valid, 'Valid', 'Invalid', false);
            $disposableLight = $trafficLight((bool)$emailIntel->is_disposable, 'YES', 'No', true);
            $freeLight       = $trafficLight((bool)$emailIntel->is_free_provider, 'Yes', 'No', true);

            // Breach danger counter
            if ($breachCount > 0) {
                $breachDisplay = <<<HTML
<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(235,51,73,0.08);border:1px solid rgba(235,51,73,0.3);border-radius:10px;margin-top:1rem;box-shadow:0 0 12px rgba(235,51,73,0.1);">
  <i class="fas fa-triangle-exclamation" style="font-size:1.4rem;color:#eb3349;"></i>
  <div>
    <div style="font-size:1.5rem;font-weight:900;color:#eb3349;line-height:1;">{$breachCount}</div>
    <div style="font-size:0.7rem;color:#8892b0;text-transform:uppercase;letter-spacing:0.08em;">Data Breach(es) Found</div>
  </div>
</div>
HTML;
            } else {
                $breachDisplay = <<<HTML
<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(56,239,125,0.07);border:1px solid rgba(56,239,125,0.2);border-radius:10px;margin-top:1rem;">
  <i class="fas fa-shield-check" style="color:#38ef7d;"></i>
  <span style="font-size:0.85rem;color:#38ef7d;font-weight:600;">No breach data found</span>
</div>
HTML;
            }

            $content = <<<HTML
<!-- Email address display with domain highlighted -->
<div style="padding:0.85rem 1rem;background:rgba(102,126,234,0.07);border:1px solid rgba(102,126,234,0.15);border-radius:10px;margin-bottom:1.25rem;">
  <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:4px;"><i class="fas fa-at" style="margin-right:4px;"></i>Email Address</div>
  <div style="font-family:monospace;font-size:1.1rem;line-height:1.4;">
    <span style="color:#fff;font-weight:600;">{$emailUser}</span><span style="color:#667eea;font-weight:700;">@</span><span style="color:#a78bfa;font-weight:600;">{$emailDomain}</span>
  </div>
</div>

<!-- Traffic light indicators -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:0.25rem;">
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:5px;"><i class="fas fa-server" style="margin-right:3px;"></i>MX Record</div>
    {$mxLight}
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:5px;"><i class="fas fa-trash-can" style="margin-right:3px;"></i>Disposable</div>
    {$disposableLight}
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:5px;"><i class="fas fa-gift" style="margin-right:3px;"></i>Free Provider</div>
    {$freeLight}
  </div>
  <div style="padding:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
    <div style="font-size:0.62rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:5px;"><i class="fas fa-hourglass-half" style="margin-right:3px;"></i>Domain Age</div>
    <span style="font-size:0.88rem;color:#c8d0e0;font-weight:600;">{$domainAgeLabel}</span>
  </div>
</div>

{$breachDisplay}
HTML;
        }

        echo FpsAdminRenderer::renderCard('Email Intelligence', 'fa-at', $content);
    }

    /**
     * Device fingerprints panel.
     */
    private function fpsRenderFingerprintPanel(int $clientId, string $ajaxUrl = ''): void
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

        // Load device trust manager for status lookups
        $deviceTrustMgr = null;
        try {
            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsDeviceTrustManager')) {
                $deviceTrustMgr = new \FraudPreventionSuite\Lib\FpsDeviceTrustManager();
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (empty($fingerprints)) {
            $content = '<div style="text-align:center;padding:1.5rem;">'
                . '<div style="font-size:2rem;color:#667eea;opacity:0.3;margin-bottom:0.75rem;"><i class="fas fa-fingerprint"></i></div>'
                . '<div style="color:#6a7195;font-size:0.85rem;margin-bottom:1rem;">No device fingerprints recorded yet.</div>'
                . '<button type="button" onclick="FpsAdmin.runManualCheck(\'' . $ajaxUrl . '\', ' . $clientId . ')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);border:none;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(102,126,234,0.3);">'
                . '<i class="fas fa-play"></i> Scan Now</button>'
                . '<div style="color:#4a5080;font-size:0.75rem;margin-top:0.75rem;">Device fingerprinting captures browser, screen, canvas, and WebGL data when clients visit your site with the Turnstile widget enabled.</div>'
                . '</div>';
        } else {
            $headers = ['Hash', 'Trust', 'Label', 'Times Seen', 'Screen', 'Browser', 'Cross-Acct', 'Last Seen', 'Actions'];
            $rows = [];

            foreach ($fingerprints as $fp) {
                $hash     = htmlspecialchars(substr($fp->fingerprint_hash, 0, 12) . '...', ENT_QUOTES, 'UTF-8');
                $fullHash = htmlspecialchars($fp->fingerprint_hash, ENT_QUOTES, 'UTF-8');
                $times    = (int)$fp->times_seen;
                $screen   = htmlspecialchars($fp->screen_resolution ?? '--', ENT_QUOTES, 'UTF-8');

                // Extract browser from user agent
                $ua = $fp->user_agent ?? '';
                $browser = '--';
                if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera)\/[\d.]+/', $ua, $m)) {
                    $browser = htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                }

                // Device trust status
                $trustStatus = 'normal';
                $trustLabel  = '';
                if ($deviceTrustMgr !== null) {
                    try {
                        $trustStatus = $deviceTrustMgr->fps_getDeviceStatus($fp->fingerprint_hash);
                        $history = $deviceTrustMgr->fps_getDeviceHistory($fp->fingerprint_hash);
                        $trustLabel = htmlspecialchars($history['label'] ?? '', ENT_QUOTES, 'UTF-8');
                    } catch (\Throwable $e) {
                        // Non-fatal
                    }
                }

                $trustBadge = match ($trustStatus) {
                    'trusted' => '<span class="fps-badge fps-badge-low">Trusted</span>',
                    'blocked' => '<span class="fps-badge fps-badge-critical">Blocked</span>',
                    'watched' => '<span class="fps-badge fps-badge-high">Watched</span>',
                    default   => '<span class="fps-badge fps-badge-medium">Normal</span>',
                };

                // Check cross-account matches
                $crossCount = 0;
                $crossClients = [];
                try {
                    $crossRows = Capsule::table('mod_fps_fingerprints')
                        ->where('fingerprint_hash', $fp->fingerprint_hash)
                        ->where('client_id', '!=', $clientId)
                        ->join('tblclients', 'tblclients.id', '=', 'mod_fps_fingerprints.client_id')
                        ->select('tblclients.id', 'tblclients.email')
                        ->distinct()
                        ->limit(5)
                        ->get()
                        ->toArray();
                    $crossCount = count($crossRows);
                    foreach ($crossRows as $cr) {
                        $crossClients[] = '#' . $cr->id . ' ' . htmlspecialchars($cr->email, ENT_QUOTES, 'UTF-8');
                    }
                } catch (\Throwable $e) {
                    // Non-fatal
                }

                $crossBadge = $crossCount > 0
                    ? '<span class="fps-badge fps-badge-high" title="' . htmlspecialchars(implode(', ', $crossClients), ENT_QUOTES, 'UTF-8') . '">' . $crossCount . ' match(es)</span>'
                    : '<span class="fps-badge fps-badge-low">None</span>';

                $lastSeen = htmlspecialchars($fp->last_seen_at ?? '--', ENT_QUOTES, 'UTF-8');

                // Action buttons
                $actions = '<div style="display:flex;gap:3px;flex-wrap:nowrap;">'
                    . '<button class="fps-btn fps-btn-xs fps-btn-success" onclick="FpsAdmin.setDeviceTrust(\'' . $fullHash . '\', \'trusted\')" title="Trust"><i class="fas fa-shield-check"></i></button>'
                    . '<button class="fps-btn fps-btn-xs fps-btn-danger" onclick="FpsAdmin.setDeviceTrust(\'' . $fullHash . '\', \'blocked\')" title="Block"><i class="fas fa-ban"></i></button>'
                    . '<button class="fps-btn fps-btn-xs fps-btn-warning" onclick="FpsAdmin.setDeviceTrust(\'' . $fullHash . '\', \'watched\')" title="Watch"><i class="fas fa-eye"></i></button>'
                    . '<button class="fps-btn fps-btn-xs fps-btn-outline" onclick="FpsAdmin.showDeviceDetail(\'' . $fullHash . '\')" title="Details"><i class="fas fa-info-circle"></i></button>'
                    . '</div>';

                $rows[] = [$hash, $trustBadge, $trustLabel ?: '--', (string)$times, $screen, $browser, $crossBadge, $lastSeen, $actions];
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
     * Check history vertical timeline with color-coded dots.
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
            $content = <<<HTML
<div style="text-align:center;padding:2rem 1rem;">
  <div style="font-size:2rem;color:#667eea;opacity:0.3;margin-bottom:0.75rem;"><i class="fas fa-clock-rotate-left"></i></div>
  <div style="color:#6a7195;font-size:0.9rem;">No fraud checks recorded for this client.</div>
</div>
HTML;
        } else {
            $content = '<div style="position:relative;padding-left:2rem;">';
            // Vertical line
            $content .= '<div style="position:absolute;left:9px;top:6px;bottom:6px;width:2px;background:linear-gradient(180deg,rgba(102,126,234,0.4),rgba(102,126,234,0.05));border-radius:1px;"></div>';

            foreach ($checks as $i => $check) {
                $level = strtolower($check->risk_level ?? 'low');
                [$dotColor, $dotGlow, $levelColor] = match ($level) {
                    'critical' => ['#eb3349', 'rgba(235,51,73,0.7)', '#eb3349'],
                    'high'     => ['#f5576c', 'rgba(245,87,108,0.6)', '#f5576c'],
                    'medium'   => ['#f5c842', 'rgba(245,200,66,0.5)', '#f5c842'],
                    default    => ['#38ef7d', 'rgba(56,239,125,0.5)', '#38ef7d'],
                };

                $score    = round((float)($check->risk_score ?? 0), 1);
                $time     = htmlspecialchars($check->created_at ?? '', ENT_QUOTES, 'UTF-8');
                $type     = htmlspecialchars($check->check_type ?? 'auto', ENT_QUOTES, 'UTF-8');
                $orderId  = (int)($check->order_id ?? 0);
                $ip       = htmlspecialchars($check->ip_address ?? '--', ENT_QUOTES, 'UTF-8');
                $action   = htmlspecialchars($check->action_taken ?? 'none', ENT_QUOTES, 'UTF-8');
                $levelUc  = strtoupper($level);

                // Alternating subtle background for readability
                $rowBg = ($i % 2 === 0) ? 'rgba(255,255,255,0.015)' : 'transparent';

                // Build timeline row using string concatenation so all variables interpolate correctly
                $content .= '<div style="position:relative;margin-bottom:0;padding:0.75rem 0.75rem 0.75rem 1.25rem;border-radius:8px;background:' . $rowBg . ';transition:background 0.15s;" onmouseover="this.style.background=\'rgba(102,126,234,0.06)\'" onmouseout="this.style.background=\'' . $rowBg . '\'">';
                $content .= '<div style="position:absolute;left:-1.75rem;top:50%;transform:translateY(-50%);width:14px;height:14px;border-radius:50%;background:' . $dotColor . ';box-shadow:0 0 0 3px rgba(102,126,234,0.15),0 0 10px ' . $dotGlow . ';border:2px solid rgba(0,0,0,0.3);z-index:1;"></div>';
                $content .= '<div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">';
                // Score badge
                $content .= '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:42px;padding:3px 8px;border-radius:6px;font-size:0.85rem;font-weight:800;color:#fff;background:linear-gradient(135deg,' . $dotColor . 'cc,' . $dotColor . '88);border:1px solid ' . $dotColor . '66;box-shadow:0 0 8px ' . $dotGlow . ';flex-shrink:0;">' . $score . '</span>';
                // Level badge
                $content .= '<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:0.65rem;font-weight:700;letter-spacing:0.08em;color:' . $levelColor . ';background:' . $dotColor . '1a;border:1px solid ' . $dotColor . '44;text-transform:uppercase;flex-shrink:0;">' . $levelUc . '</span>';
                // Type chip
                $content .= '<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:#a0aec0;flex-shrink:0;"><i class="fas fa-tag" style="color:#667eea;font-size:0.65rem;"></i>' . $type . '</span>';
                // IP chip
                $content .= '<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:#8892b0;font-family:monospace;flex-shrink:0;"><i class="fas fa-globe" style="font-size:0.65rem;"></i>' . $ip . '</span>';
                // Order ID chip
                $content .= '<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:#8892b0;flex-shrink:0;"><i class="fas fa-receipt" style="font-size:0.65rem;"></i>#' . $orderId . '</span>';
                // Action (push to right)
                $content .= '<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:#6a7195;margin-left:auto;flex-shrink:0;"><i class="fas fa-bolt" style="font-size:0.65rem;color:#f5c842;"></i>' . $action . '</span>';
                // Timestamp
                $content .= '<span style="font-size:0.7rem;color:#4a5568;flex-shrink:0;">' . $time . '</span>';
                $content .= '</div></div>';
            }

            $content .= '</div>';
        }

        echo FpsAdminRenderer::renderCard('Check History Timeline', 'fa-clock-rotate-left', $content);
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
                    ->where('client_id', '>', 0) // Exclude pre-checkout (client_id=0)
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
                            'Fingerprint'     => 'fps-badge-critical',
                            'IP Address'      => 'fps-badge-high',
                            'Phone Number'    => 'fps-badge-warning',
                            default           => 'fps-badge-medium',
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

        $dupCount = count($duplicates);
        $content = '<div style="margin-bottom:0.75rem;"><span class="fps-badge ' . ($dupCount > 0 ? 'fps-badge-warning' : 'fps-badge-success') . '">' . $dupCount . ' found</span></div>' . $content;
        echo FpsAdminRenderer::renderCard('Duplicate Account Detection', 'fa-users-between-lines', $content);
    }

    /**
     * Order velocity: summary stats + inline SVG bar chart for the last 90 days.
     */
    private function fpsRenderOrderVelocity(int $clientId): void
    {
        $velocityData = [];
        try {
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

        // Build daily values array for the last 90 days
        $sparkValues = [];
        for ($i = 89; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $found = false;
            foreach ($velocityData as $v) {
                if ($v['date'] === $date) {
                    $sparkValues[] = ['date' => $date, 'count' => $v['count'], 'amount' => $v['amount']];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $sparkValues[] = ['date' => $date, 'count' => 0, 'amount' => 0.0];
            }
        }

        $counts      = array_column($sparkValues, 'count');
        $totalOrders = array_sum($counts);
        $maxDay      = max($counts ?: [0]);
        $avgDay      = $totalOrders > 0 ? round($totalOrders / 90, 2) : 0;

        // Summary stats glass cards
        $content  = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:1rem;margin-bottom:1.5rem;">';
        $content .= $this->fpsVelocityStatCard((string)$totalOrders, 'Orders (90d)', 'fa-shopping-cart', '#667eea', 'rgba(102,126,234,0.1)', 'rgba(102,126,234,0.2)');
        $content .= $this->fpsVelocityStatCard((string)$maxDay, 'Max / Day', 'fa-arrow-trend-up', '#f5576c', 'rgba(245,87,108,0.1)', 'rgba(245,87,108,0.2)');
        $content .= $this->fpsVelocityStatCard(number_format($avgDay, 2), 'Avg / Day', 'fa-chart-line', '#38ef7d', 'rgba(56,239,125,0.1)', 'rgba(56,239,125,0.2)');
        $content .= '</div>';

        if ($totalOrders === 0) {
            $content .= <<<HTML
<div style="text-align:center;padding:2.5rem 1rem;border:1px dashed rgba(255,255,255,0.08);border-radius:12px;">
  <div style="font-size:2.5rem;color:#667eea;opacity:0.25;margin-bottom:0.75rem;"><i class="fas fa-chart-column"></i></div>
  <div style="color:#6a7195;font-size:0.9rem;">No orders placed in the last 90 days.</div>
</div>
HTML;
        } else {
            // SVG bar chart
            $chartW     = 560;
            $chartH     = 100;
            $barCount   = count($sparkValues); // 90
            $barGap     = 2;
            $barW       = max(2, floor(($chartW - ($barCount - 1) * $barGap) / $barCount));
            $maxHeight  = $chartH - 4;
            $safeMax    = max($maxDay, 1);

            $svgBars    = '';
            $xTicksHtml = '';
            foreach ($sparkValues as $idx => $day) {
                $barH  = (int)round(($day['count'] / $safeMax) * $maxHeight);
                $x     = $idx * ($barW + $barGap);
                $y     = $chartH - $barH;

                // Color by count
                if ($day['count'] === 0) {
                    $barFill = 'rgba(255,255,255,0.04)';
                } elseif ($day['count'] === 1) {
                    $barFill = 'rgba(56,239,125,0.6)';
                } elseif ($day['count'] <= 3) {
                    $barFill = 'rgba(245,200,66,0.75)';
                } else {
                    $barFill = 'rgba(245,87,108,0.85)';
                }

                $dateLabel  = htmlspecialchars($day['date'], ENT_QUOTES, 'UTF-8');
                $titleText  = htmlspecialchars($day['date'] . ': ' . $day['count'] . ' order(s)', ENT_QUOTES, 'UTF-8');
                $barHeight  = max($barH, ($day['count'] > 0 ? 2 : 0));
                $svgBars   .= "<rect x='{$x}' y='" . ($chartH - $barHeight) . "' width='{$barW}' height='{$barHeight}' fill='{$barFill}' rx='1'><title>{$titleText}</title></rect>";

                // X-axis tick labels for every 15 days
                if ($idx % 15 === 0) {
                    $tickX      = $x + $barW / 2;
                    $tickLabel  = htmlspecialchars(date('M j', strtotime($day['date'])), ENT_QUOTES, 'UTF-8');
                    $xTicksHtml .= "<text x='{$tickX}' y='12' text-anchor='middle' fill='rgba(255,255,255,0.3)' font-size='9' font-family='sans-serif'>{$tickLabel}</text>";
                }
            }

            // Y-axis reference lines
            $yLines = '';
            for ($yv = 1; $yv <= $safeMax; $yv++) {
                $lineY = $chartH - (int)round(($yv / $safeMax) * $maxHeight);
                $yLines .= "<line x1='0' y1='{$lineY}' x2='{$chartW}' y2='{$lineY}' stroke='rgba(255,255,255,0.04)' stroke-width='1'/>";
                $yLines .= "<text x='-4' y='{$lineY}' text-anchor='end' dominant-baseline='middle' fill='rgba(255,255,255,0.25)' font-size='8' font-family='monospace'>{$yv}</text>";
                if ($yv >= 6) break; // cap y labels at 6 to avoid clutter
            }

            $fullSvgW = $chartW + 20;
            $fullSvgH = $chartH + 20;

            $content .= <<<HTML
<div style="overflow-x:auto;">
  <svg viewBox="-20 -16 {$fullSvgW} {$fullSvgH}" width="100%" height="120" preserveAspectRatio="none" style="display:block;min-width:300px;">
    <!-- Y-axis reference lines -->
    {$yLines}
    <!-- Bars -->
    {$svgBars}
    <!-- X-axis labels -->
    <g transform="translate(0,{$chartH})">
      {$xTicksHtml}
    </g>
    <!-- Baseline -->
    <line x1="0" y1="{$chartH}" x2="{$chartW}" y2="{$chartH}" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>
  </svg>
</div>
HTML;

            // Legend
            $content .= <<<HTML
<div style="display:flex;gap:1.25rem;margin-top:0.75rem;flex-wrap:wrap;">
  <span style="display:flex;align-items:center;gap:5px;font-size:0.72rem;color:#8892b0;">
    <span style="width:12px;height:12px;border-radius:2px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);display:inline-block;"></span> No orders
  </span>
  <span style="display:flex;align-items:center;gap:5px;font-size:0.72rem;color:#8892b0;">
    <span style="width:12px;height:12px;border-radius:2px;background:rgba(56,239,125,0.6);display:inline-block;"></span> 1 order
  </span>
  <span style="display:flex;align-items:center;gap:5px;font-size:0.72rem;color:#8892b0;">
    <span style="width:12px;height:12px;border-radius:2px;background:rgba(245,200,66,0.75);display:inline-block;"></span> 2-3 orders
  </span>
  <span style="display:flex;align-items:center;gap:5px;font-size:0.72rem;color:#8892b0;">
    <span style="width:12px;height:12px;border-radius:2px;background:rgba(245,87,108,0.85);display:inline-block;"></span> 4+ orders
  </span>
</div>
HTML;
        }

        echo FpsAdminRenderer::renderCard('Order Velocity (90 Days)', 'fa-bolt', $content);
    }

    /**
     * Helper: render a single stat card for the velocity summary row.
     */
    private function fpsVelocityStatCard(
        string $value,
        string $label,
        string $icon,
        string $valueColor,
        string $bgColor,
        string $borderColor
    ): string {
        return <<<HTML
<div style="
    text-align:center;padding:1rem;
    background:{$bgColor};
    border:1px solid {$borderColor};
    border-radius:12px;
    transition:transform 0.2s;
" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
  <div style="font-size:0.65rem;color:{$valueColor};text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.4rem;opacity:0.8;">
    <i class="fas {$icon}" style="margin-right:4px;"></i>{$label}
  </div>
  <div style="font-size:1.6rem;font-weight:900;color:#fff;letter-spacing:-0.02em;text-shadow:0 0 20px {$valueColor}44;">{$value}</div>
</div>
HTML;
    }

    /**
     * Refund history and abuse indicators.
     */
    private function fpsRenderRefundHistory(int $clientId): void
    {
        $refunds    = [];
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
        $cbCount     = count($chargebacks);

        if ($refundCount === 0 && $cbCount === 0) {
            $content = '<p class="fps-text-muted"><i class="fas fa-check-circle"></i> No refunds or chargebacks recorded.</p>';
        } else {
            $content = '<div class="fps-form-row" style="margin-bottom:16px;">';
            $content .= '<div class="fps-velocity-stat"><strong>' . $refundCount . '</strong><br><small class="fps-text-muted">Refunds</small></div>';
            $content .= '<div class="fps-velocity-stat"><strong>' . $cbCount . '</strong><br><small class="fps-text-muted">Chargebacks</small></div>';

            $totalRefundAmt = array_sum(array_map(function ($r) { return (float)$r->amount; }, $refunds));
            $totalCbAmt     = array_sum(array_map(function ($c) { return (float)$c->amount; }, $chargebacks));
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
     * Risk score timeline -- ApexCharts area chart of historical check scores.
     */
    private function fpsRenderRiskTimeline(int $clientId, string $modulelink): void
    {
        $safeModulelink = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $content = <<<HTML
<div id="fps-risk-timeline-chart" style="height:300px;"></div>
<div id="fps-risk-timeline-empty" style="display:none;text-align:center;padding:2rem;color:#6a7195;">
    <i class="fas fa-chart-area" style="font-size:2rem;margin-bottom:0.5rem;display:block;opacity:0.4;"></i>
    No risk check history found for this client.
</div>
<script>
(function(){
    var chartEl = document.getElementById('fps-risk-timeline-chart');
    var emptyEl = document.getElementById('fps-risk-timeline-empty');
    if (!chartEl) return;

    var url = '{$safeModulelink}&ajax_action=get_client_risk_timeline&client_id={$clientId}';

    fetch(url, {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(resp){
            if (!resp.success || !resp.data || resp.data.length === 0) {
                chartEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return;
            }

            var categories = [];
            var scores     = [];

            resp.data.forEach(function(pt){
                categories.push(pt.x);
                scores.push(pt.y);
            });

            var opts = {
                chart: {
                    type: 'area',
                    height: 300,
                    background: 'transparent',
                    toolbar: { show: true, tools: { download: true, zoom: true, pan: true, reset: true } },
                    animations: { enabled: true, easing: 'easeinout', speed: 800 },
                    fontFamily: 'Inter, system-ui, sans-serif'
                },
                series: [{
                    name: 'Risk Score',
                    data: scores
                }],
                xaxis: {
                    categories: categories,
                    type: 'datetime',
                    labels: {
                        style: { colors: '#8892b0', fontSize: '11px' },
                        datetimeFormatter: { year: 'yyyy', month: "MMM 'yy", day: 'dd MMM', hour: 'HH:mm' }
                    },
                    axisBorder: { show: false },
                    axisTicks: { show: false }
                },
                yaxis: {
                    min: 0,
                    max: 100,
                    title: { text: 'Risk Score', style: { color: '#8892b0', fontSize: '12px' } },
                    labels: { style: { colors: '#8892b0', fontSize: '11px' } }
                },
                annotations: {
                    yaxis: [
                        { y: 0,  y2: 30,  fillColor: '#38ef7d', opacity: 0.06, label: { text: 'Low',      style: { color: '#38ef7d', background: 'transparent', fontSize: '10px' }, position: 'front' } },
                        { y: 30, y2: 60,  fillColor: '#f5c842', opacity: 0.06, label: { text: 'Medium',   style: { color: '#f5c842', background: 'transparent', fontSize: '10px' }, position: 'front' } },
                        { y: 60, y2: 80,  fillColor: '#f5576c', opacity: 0.06, label: { text: 'High',     style: { color: '#f5576c', background: 'transparent', fontSize: '10px' }, position: 'front' } },
                        { y: 80, y2: 100, fillColor: '#eb3349', opacity: 0.06, label: { text: 'Critical', style: { color: '#eb3349', background: 'transparent', fontSize: '10px' }, position: 'front' } }
                    ]
                },
                stroke: {
                    curve: 'smooth',
                    width: 2.5
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.35,
                        opacityTo: 0.05,
                        stops: [0, 100],
                        colorStops: [
                            { offset: 0,   color: '#667eea', opacity: 0.35 },
                            { offset: 100, color: '#764ba2', opacity: 0.05 }
                        ]
                    }
                },
                colors: ['#667eea'],
                markers: {
                    size: 4,
                    strokeWidth: 0,
                    hover: { sizeOffset: 3 }
                },
                tooltip: {
                    theme: 'dark',
                    custom: function(opts2) {
                        var idx  = opts2.dataPointIndex;
                        var pt   = resp.data[idx];
                        var score = pt.y;
                        var level = pt.level || '--';
                        var type  = pt.type  || '--';
                        var action = pt.action || 'none';
                        var date  = pt.x;

                        var levelColor = '#38ef7d';
                        if (score >= 80) levelColor = '#eb3349';
                        else if (score >= 60) levelColor = '#f5576c';
                        else if (score >= 30) levelColor = '#f5c842';

                        var el = document.createElement('div');
                        el.style.cssText = 'padding:10px 14px;background:#1a1f3a;border:1px solid rgba(102,126,234,0.3);border-radius:8px;font-size:12px;color:#ccd6f6;min-width:160px;';

                        var rows = [
                            {l:'Date',   v:date,                    c:'#fff',    bold:true},
                            {l:'Score',  v:String(score),           c:levelColor,bold:true},
                            {l:'Level',  v:level.toUpperCase(),     c:levelColor,bold:false},
                            {l:'Type',   v:type,                    c:'#ccd6f6', bold:false},
                            {l:'Action', v:action,                  c:'#ccd6f6', bold:false}
                        ];
                        rows.forEach(function(r, i){
                            var row = document.createElement('div');
                            row.style.cssText = 'display:flex;justify-content:space-between;' + (i === 0 ? 'margin-bottom:6px;' : 'margin-bottom:3px;');
                            var lbl = document.createElement('span');
                            lbl.textContent = r.l;
                            var val = document.createElement('span');
                            val.textContent = r.v;
                            val.style.color = r.c;
                            if (r.bold) val.style.fontWeight = '700';
                            row.appendChild(lbl);
                            row.appendChild(val);
                            el.appendChild(row);
                        });

                        return el.outerHTML;
                    }
                },
                grid: {
                    borderColor: 'rgba(255,255,255,0.05)',
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { top: 0, right: 10, bottom: 0, left: 10 }
                },
                theme: { mode: 'dark' }
            };

            var chart = new ApexCharts(chartEl, opts);
            chart.render();
        })
        .catch(function(err){
            var errDiv = document.createElement('div');
            errDiv.style.cssText = 'text-align:center;padding:2rem;color:#f5576c;';
            var icon = document.createElement('i');
            icon.className = 'fas fa-exclamation-triangle';
            errDiv.appendChild(icon);
            errDiv.appendChild(document.createTextNode(' Failed to load timeline: ' + err.message));
            chartEl.parentNode.replaceChild(errDiv, chartEl);
        });
})();
</script>
HTML;

        echo FpsAdminRenderer::renderCard(
            'Risk Score Timeline',
            'fa-chart-area',
            $content
        );
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

        // GDPR actions panel
        $this->fpsRenderGdprActions($clientId, $ajaxUrl);
    }

    /**
     * GDPR action buttons: Export Data (Article 15/20) and Erase Data (Article 17).
     */
    private function fpsRenderGdprActions(int $clientId, string $ajaxUrl): void
    {
        // Build the export URL (GET request triggers file download)
        $exportUrl = str_replace('&amp;', '&', $ajaxUrl) . '&a=gdpr_export_client&client_id=' . $clientId;
        $safeExportUrl = htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8');

        $content = <<<HTML
<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
  <div style="flex:1;min-width:200px;">
    <p style="margin:0 0 0.5rem 0;font-size:0.85rem;color:#a0aec0;">
      <i class="fas fa-info-circle" style="color:#667eea;margin-right:4px;"></i>
      <strong>GDPR Article 15/20:</strong> Export all FPS data held for this client as a portable JSON file.
      <strong>Article 17:</strong> Permanently erase all FPS data for this client (fraud check records are anonymised, not deleted, under Art 17(3)(e)).
    </p>
  </div>
  <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
    <a href="{$safeExportUrl}" class="fps-btn fps-btn-md fps-btn-info" style="text-decoration:none;">
      <i class="fas fa-download"></i> Export GDPR Data
    </a>
    <button type="button" class="fps-btn fps-btn-md fps-btn-danger" onclick="FpsGdpr.confirmErase({$clientId}, '{$ajaxUrl}')">
      <i class="fas fa-eraser"></i> Erase All Data
    </button>
  </div>
</div>

<!-- Erasure confirmation modal -->
<div id="fps-gdpr-erase-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);justify-content:center;align-items:center;">
  <div style="background:#1a1f3a;border:1px solid rgba(235,51,73,0.4);border-radius:16px;padding:2rem;max-width:520px;width:90%;box-shadow:0 16px 64px rgba(0,0,0,0.6);">
    <h3 style="margin:0 0 1rem 0;color:#eb3349;font-size:1.2rem;">
      <i class="fas fa-exclamation-triangle"></i> Confirm GDPR Data Erasure
    </h3>
    <p style="color:#e2e8f0;font-size:0.9rem;margin:0 0 1rem 0;">
      This will <strong>permanently delete</strong> all FPS data associated with Client #{$clientId}.
      Fraud check records will be anonymised (PII removed) rather than deleted.
      This action <strong>cannot be undone</strong>.
    </p>
    <div style="margin-bottom:1rem;">
      <label style="color:#a0aec0;font-size:0.8rem;display:block;margin-bottom:4px;">
        Type <code style="color:#eb3349;background:rgba(235,51,73,0.15);padding:2px 6px;border-radius:4px;">CONFIRM-ERASE-{$clientId}</code> to confirm:
      </label>
      <input type="text" id="fps-gdpr-erase-confirm-input" class="fps-input" placeholder="Type confirmation token" style="width:100%;">
    </div>
    <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
      <button type="button" class="fps-btn fps-btn-md fps-btn-outline" onclick="FpsGdpr.closeEraseModal()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button type="button" class="fps-btn fps-btn-md fps-btn-danger" id="fps-gdpr-erase-execute-btn" onclick="FpsGdpr.executeErase({$clientId}, '{$ajaxUrl}')">
        <i class="fas fa-skull-crossbones"></i> Erase Data
      </button>
    </div>
  </div>
</div>

<script>
var FpsGdpr = FpsGdpr || {};
FpsGdpr.confirmErase = function(clientId, ajaxUrl) {
    var modal = document.getElementById('fps-gdpr-erase-modal');
    if (modal) {
        modal.style.display = 'flex';
        var input = document.getElementById('fps-gdpr-erase-confirm-input');
        if (input) { input.value = ''; input.focus(); }
    }
};
FpsGdpr.closeEraseModal = function() {
    var modal = document.getElementById('fps-gdpr-erase-modal');
    if (modal) modal.style.display = 'none';
};
FpsGdpr.executeErase = function(clientId, ajaxUrl) {
    var input = document.getElementById('fps-gdpr-erase-confirm-input');
    var confirmToken = input ? input.value.trim() : '';
    var expected = 'CONFIRM-ERASE-' + clientId;
    if (confirmToken !== expected) {
        if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) {
            FpsAdmin.showToast('Confirmation token does not match. Please type: ' + expected, 'error');
        } else {
            alert('Confirmation token does not match. Please type: ' + expected);
        }
        return;
    }
    var btn = document.getElementById('fps-gdpr-erase-execute-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Erasing...'; }

    var formData = new FormData();
    formData.append('client_id', clientId);
    formData.append('confirm_token', confirmToken);
    var tokenEl = document.getElementById('fps-csrf-token')
        || document.querySelector('input[name="token"]')
        || document.querySelector('#frmFraudPreventionSuite input[name="token"]');
    if (tokenEl && tokenEl.value) { formData.append('token', tokenEl.value); }

    var url = ajaxUrl.replace(/&amp;/g, '&') + '&a=gdpr_erase_client';
    fetch(url, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            FpsGdpr.closeEraseModal();
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-skull-crossbones"></i> Erase Data'; }
            if (data.success) {
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) {
                    FpsAdmin.showToast(data.message, 'success');
                } else {
                    alert(data.message);
                }
            } else {
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) {
                    FpsAdmin.showToast(data.error || 'Erasure failed', 'error');
                } else {
                    alert(data.error || 'Erasure failed');
                }
            }
        })
        .catch(function(err) {
            FpsGdpr.closeEraseModal();
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-skull-crossbones"></i> Erase Data'; }
            if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) {
                FpsAdmin.showToast('Network error: ' + err.message, 'error');
            } else {
                alert('Network error: ' + err.message);
            }
        });
};
</script>
HTML;

        echo FpsAdminRenderer::renderCard('GDPR Data Management', 'fa-shield-halved', $content);
    }
}

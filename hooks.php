<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Load autoloader for lib/ classes
$autoloaderPath = __DIR__ . '/lib/Autoloader.php';
if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
}

// ---------------------------------------------------------------------------
// 1. AdminAreaPage -- Dashboard widget + threat counter
// ---------------------------------------------------------------------------
add_hook('AdminAreaPage', 1, function ($vars) {
    try {
        return [];
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 2. AdminAreaHeaderOutput -- Inject admin CSS/JS on module pages
// ---------------------------------------------------------------------------
add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    try {
        $page = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($page, 'configaddonmods') !== false
            && isset($_GET['module'])
            && $_GET['module'] === 'fraud_prevention_suite') {
            return '<link rel="stylesheet" href="../modules/addons/fraud_prevention_suite/assets/css/fps-1000x.css">';
        }
        return '';
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 2b. AdminAreaFooterOutput -- Inject user purge controls on WHMCS Users page
// ---------------------------------------------------------------------------
add_hook('AdminAreaFooterOutput', 2, function ($vars) {
    try {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Only inject on the WHMCS Users list page (/admin/user/list or /dj28hds/user/list)
        if (strpos($uri, '/user/list') === false && strpos($uri, 'user') === false) {
            return '';
        }
        // Check page more precisely -- the Users page has 'user' in the filename context
        $filename = $vars['filename'] ?? '';
        if ($filename !== '' && strpos($filename, 'user') === false && strpos($uri, '/user/') === false) {
            return '';
        }

        // Check if the feature is enabled in settings
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'user_purge_on_users_page')
            ->value('setting_value');
        if ($enabled !== '1') return '';

        // Get the module link for AJAX calls
        $moduleLink = 'addonmodules.php?module=fraud_prevention_suite';

        // Inject the toolbar and JavaScript
        return <<<FPSHTML
<style>
.fps-user-toolbar{background:linear-gradient(135deg,#1a1a2e,#302b63);color:#fff;padding:16px 20px;border-radius:10px;margin:15px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-family:-apple-system,sans-serif;}
.fps-user-toolbar h4{margin:0;font-size:1rem;font-weight:600;}
.fps-user-toolbar .fps-ubtn{padding:8px 16px;border:none;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;}
.fps-user-toolbar .fps-ubtn-scan{background:#667eea;color:#fff;}
.fps-user-toolbar .fps-ubtn-scan:hover{background:#5a6fd6;}
.fps-user-toolbar .fps-ubtn-purge{background:#eb3349;color:#fff;}
.fps-user-toolbar .fps-ubtn-purge:hover{background:#d42a3f;}
.fps-user-toolbar .fps-ubtn-select{background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.2);}
.fps-user-toolbar .fps-ubtn-select:hover{background:rgba(255,255,255,0.25);}
.fps-user-toolbar .fps-ustatus{font-size:0.85rem;opacity:0.8;margin-left:auto;}
.fps-user-toolbar .fps-ubadge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:0.8rem;font-weight:700;}
.fps-user-toolbar .fps-ubadge-red{background:rgba(235,51,73,0.2);color:#ff6b7a;}
.fps-user-toolbar .fps-ubadge-green{background:rgba(56,239,125,0.2);color:#38ef7d;}
tr.fps-bot-user{background:rgba(235,51,73,0.06) !important;}
tr.fps-bot-user td:first-child::before{content:'\\f544';font-family:'Font Awesome 6 Free';font-weight:900;color:#eb3349;margin-right:6px;font-size:0.8rem;}
</style>

<div class="fps-user-toolbar" id="fps-user-toolbar">
  <h4><i class="fas fa-shield-halved"></i> FPS Bot Detection</h4>
  <button class="fps-ubtn fps-ubtn-scan" onclick="fpsUserPurge.scan()"><i class="fas fa-search"></i> Scan for Bot Users</button>
  <button class="fps-ubtn fps-ubtn-select" onclick="fpsUserPurge.selectBots()" style="display:none;" id="fps-uselect-btn"><i class="fas fa-check-double"></i> Select All Bots</button>
  <button class="fps-ubtn fps-ubtn-purge" onclick="fpsUserPurge.purge()" style="display:none;" id="fps-upurge-btn"><i class="fas fa-trash"></i> Purge Selected</button>
  <span class="fps-ustatus" id="fps-ustatus"></span>
</div>

<script>
(function(){
  var moduleLink = '{$moduleLink}';
  var botUserIds = [];
  var selectedIds = new Set();

  window.fpsUserPurge = {
    scan: function() {
      var status = document.getElementById('fps-ustatus');
      if (status) status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';

      var url = moduleLink + '&ajax=1&a=detect_orphan_users';
      fetch(url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(data){
          if (data.error) { if (status) status.textContent = 'Error: ' + data.error; return; }

          botUserIds = (data.users || []).map(function(u){return u.id;});
          var botEmails = {};
          (data.users || []).forEach(function(u){ botEmails[u.email.toLowerCase()] = u; });

          // Highlight bot users in the existing table
          var rows = document.querySelectorAll('table tbody tr, .dataTables_wrapper tbody tr');
          var marked = 0;
          rows.forEach(function(row){
            var cells = row.querySelectorAll('td');
            // Try to find the email cell (usually 2nd or 3rd column)
            var found = false;
            cells.forEach(function(cell){
              var text = (cell.textContent || '').trim().toLowerCase();
              if (botEmails[text]) {
                found = true;
                row.classList.add('fps-bot-user');
                // Add checkbox if not already present
                if (!row.querySelector('.fps-ucheck')) {
                  var cb = document.createElement('input');
                  cb.type = 'checkbox';
                  cb.className = 'fps-ucheck';
                  cb.dataset.userId = botEmails[text].id;
                  cb.onchange = function(){ fpsUserPurge._toggle(parseInt(this.dataset.userId), this.checked); };
                  cells[0].prepend(cb);
                  cb.insertAdjacentHTML('afterend', ' ');
                }
                marked++;
              }
            });
          });

          if (status) {
            status.innerHTML = '<span class="fps-ubadge fps-ubadge-red">' + data.total + ' bot users</span> ' +
              '<span class="fps-ubadge fps-ubadge-green">' + (data.total_users - data.total) + ' real</span> ' +
              '(' + marked + ' highlighted in table)';
          }

          if (data.total > 0) {
            var selectBtn = document.getElementById('fps-uselect-btn');
            var purgeBtn = document.getElementById('fps-upurge-btn');
            if (selectBtn) selectBtn.style.display = '';
            if (purgeBtn) purgeBtn.style.display = '';
          }
        })
        .catch(function(err){ if (status) status.textContent = 'Scan failed: ' + err.message; });
    },

    _toggle: function(id, checked) {
      if (checked) selectedIds.add(id); else selectedIds.delete(id);
    },

    selectBots: function() {
      selectedIds = new Set(botUserIds);
      document.querySelectorAll('.fps-ucheck').forEach(function(cb){ cb.checked = true; });
    },

    purge: function() {
      var ids = Array.from(selectedIds);
      if (ids.length === 0) { alert('No users selected'); return; }
      if (!confirm('PERMANENTLY DELETE ' + ids.length + ' user login accounts?\\n\\nThey will not be able to log in again.\\nTheir fraud data will be saved to global intel.\\n\\nThis cannot be undone.')) return;

      var status = document.getElementById('fps-ustatus');
      if (status) status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Purging...';

      var url = moduleLink + '&ajax=1&a=purge_orphan_users';
      var body = 'ids=' + ids.join(',');
      fetch(url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin', body:body})
        .then(function(r){return r.json();})
        .then(function(data){
          if (data.error) { if (status) status.textContent = 'Error: ' + data.error; return; }
          if (status) status.innerHTML = '<span class="fps-ubadge fps-ubadge-green">' + data.purged + ' users purged</span> Reloading...';
          setTimeout(function(){ location.reload(); }, 1500);
        })
        .catch(function(err){ if (status) status.textContent = 'Purge failed: ' + err.message; });
    }
  };
})();
</script>
FPSHTML;
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 3. ShoppingCartValidateCheckout -- PRE-CHECKOUT BLOCKING (< 2 seconds)
// ---------------------------------------------------------------------------
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    try {
        // Check if pre-checkout blocking is enabled
        $enabled = Capsule::table('tbladdonmodules')
            ->where('module', 'fraud_prevention_suite')
            ->where('setting', 'pre_checkout_blocking')
            ->value('value');

        if ($enabled !== 'on' && $enabled !== 'yes') {
            return [];
        }

        $clientId = (int)($_SESSION['uid'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $email = '';
        $phone = '';
        $country = '';

        if ($clientId > 0) {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($client) {
                $email = $client->email ?? '';
                $phone = $client->phonenumber ?? '';
                $country = $client->country ?? '';
            }
        }

        // Process fingerprint data if submitted
        $fingerprintData = $_POST['fps_fingerprint'] ?? '';

        $startTime = microtime(true);

        // Build check context
        $context = [
            'email' => $email,
            'ip' => $ip,
            'phone' => $phone,
            'country' => $country,
            'client_id' => $clientId,
            'order_id' => 0,
            'amount' => 0,
            'domain' => '',
            'fingerprint_data' => $fingerprintData,
            'is_pre_checkout' => true,
        ];

        // Extract email domain
        if ($email && strpos($email, '@') !== false) {
            $context['domain'] = strtolower(substr($email, strpos($email, '@') + 1));
        }

        // Trust check FIRST -- before any scoring to avoid wasting resources
        if ($clientId > 0 && class_exists('\\FraudPreventionSuite\\Lib\\FpsClientTrustManager')) {
            $trustMgr = new \FraudPreventionSuite\Lib\FpsClientTrustManager();
            if ($trustMgr->shouldSkipCheck($clientId)) {
                return []; // Trusted client, skip all checks
            }
            if ($trustMgr->shouldAutoBlock($clientId)) {
                logActivity("Fraud Prevention: Pre-checkout BLOCKED -- blacklisted client #{$clientId}");
                return ['We were unable to process your order at this time. Please contact support if you believe this is an error. Reference: FPS-' . date('ymdHi')];
            }
        }

        // Run quick checks only (providers where isQuick() = true)
        $score = 0;
        $details = [];

        // 1. IP Intel (cached = instant)
        if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\IpIntelProvider')) {
            $provider = new \FraudPreventionSuite\Lib\Providers\IpIntelProvider();
            if ($provider->isEnabled()) {
                $result = $provider->check($context);
                $score += ($result['score'] ?? 0) * $provider->getWeight();
                if (!empty($result['details'])) {
                    $details = array_merge($details, $result['details']);
                }
            }
        }

        // 2. Email validation (local checks)
        if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\EmailValidationProvider')) {
            $provider = new \FraudPreventionSuite\Lib\Providers\EmailValidationProvider();
            if ($provider->isEnabled()) {
                $result = $provider->check($context);
                $score += ($result['score'] ?? 0) * $provider->getWeight();
                if (!empty($result['details'])) {
                    $details = array_merge($details, $result['details']);
                }
            }
        }

        // 3. Fingerprint matching (local DB lookup)
        if ($fingerprintData && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\FingerprintProvider')) {
            $provider = new \FraudPreventionSuite\Lib\Providers\FingerprintProvider();
            if ($provider->isEnabled()) {
                $result = $provider->check($context);
                $score += ($result['score'] ?? 0) * $provider->getWeight();
                if (!empty($result['details'])) {
                    $details = array_merge($details, $result['details']);
                }
            }
        }

        // 4. Bot pattern detection (instant -- no API calls)
        if ($email !== '') {
            $botScore = 0;
            $localPart = substr($email, 0, strpos($email, '@') ?: strlen($email));
            $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));

            // Plus-tag detection
            if (strpos($email, '+') !== false) {
                $botScore += 50;
                $details[] = 'Plus-tag email blocked';
            }

            // SMS gateway domains
            $smsGateways = ['vtext.com','tmomail.net','txt.att.net','messaging.sprintpcs.com',
                'pm.sprint.com','text.republicwireless.com','msg.fi.google.com','mymetropcs.com',
                'sms.mycricket.com','mmst5.tracfone.com'];
            if (in_array($emailDomain, $smsGateways, true)) {
                $botScore += 80;
                $details[] = 'SMS gateway email blocked';
            }

            // Numeric-only local part (e.g., 2013903653@vtext.com)
            if (preg_match('/^\d{7,}$/', $localPart)) {
                $botScore += 40;
                $details[] = 'Numeric email local part';
            }

            // Random local part (high digit density)
            $digitCount = (int)preg_match_all('/[0-9]/', $localPart);
            if (strlen($localPart) > 6 && $digitCount > 4 && $digitCount / strlen($localPart) > 0.4) {
                $botScore += 20;
                $details[] = 'Random-looking email';
            }

            $score += $botScore * 2.0; // Weight 2.0 for bot detection
        }

        // 5. Global Intel cross-reference (instant -- local DB only)
        try {
            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelChecker')
                && ($email !== '' || $ip !== '')) {
                $globalChecker = new \FraudPreventionSuite\Lib\FpsGlobalIntelChecker();
                $globalResult = $globalChecker->check($email, $ip);
                if ($globalResult['found']) {
                    $adj = $globalChecker->getScoreAdjustment($globalResult);
                    $score += $adj;
                    $details[] = "Known in global fraud DB (seen {$globalResult['seen_count']}x)";
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // 6. Custom rules
        if (class_exists('\\FraudPreventionSuite\\Lib\\FpsRuleEngine')
            && class_exists('\\FraudPreventionSuite\\Lib\\Models\\FpsCheckContext')) {
            $ruleEngine = new \FraudPreventionSuite\Lib\FpsRuleEngine();
            $ruleContext = new \FraudPreventionSuite\Lib\Models\FpsCheckContext(
                email: $context['email'] ?? '',
                ip: $context['ip'] ?? '',
                phone: $context['phone'] ?? '',
                country: $context['country'] ?? '',
                clientId: (int) ($context['client_id'] ?? 0),
                orderId: 0,
                amount: 0.0,
                domain: $context['domain'] ?? '',
                fingerprintHash: $context['fingerprint_data'] ?? '',
                checkType: 'pre_checkout',
            );
            $ruleResult = $ruleEngine->evaluate($ruleContext);
            if ($ruleResult->action === 'block') {
                $score = max($score, 95);
            } elseif ($ruleResult->action === 'flag') {
                $score += 15;
            }
            if (count($ruleResult->matchedRules) > 0) {
                $names = array_column($ruleResult->matchedRules, 'rule_name');
                $details[] = 'Rules matched: ' . implode(', ', $names);
            }
        }

        $score = min($score, 100);

        // Get default thresholds
        $blockThreshold = (float)(Capsule::table('tbladdonmodules')
            ->where('module', 'fraud_prevention_suite')
            ->where('setting', 'pre_checkout_block_threshold')
            ->value('value') ?: 85);

        $captchaThreshold = 60;

        // Per-gateway threshold override
        $selectedGateway = $_SESSION['paymentmethod'] ?? '';
        if ($selectedGateway !== '') {
            $gwRow = Capsule::table('mod_fps_gateway_thresholds')
                ->where('gateway', $selectedGateway)
                ->where('enabled', 1)
                ->first();
            if ($gwRow) {
                if ((float)$gwRow->block_threshold > 0) {
                    $blockThreshold = (float)$gwRow->block_threshold;
                }
                if ((float)$gwRow->flag_threshold > 0) {
                    $captchaThreshold = (float)$gwRow->flag_threshold;
                }
            }
        }

        // (Trust check moved to top of hook, before scoring pipeline)

        // v3.0: Velocity check (BEFORE persistence so score is complete)
        if ($ip !== '' && class_exists('\\FraudPreventionSuite\\Lib\\FpsVelocityEngine')) {
            $velEngine = new \FraudPreventionSuite\Lib\FpsVelocityEngine();
            $velEngine->recordEvent('checkout_attempt', $ip, $clientId);
            $velResult = $velEngine->checkVelocity($context);
            $velScore = (float) ($velResult['score'] ?? 0);
            if ($velScore > 0) {
                $score = min(100, $score + $velScore);
                $details[] = 'Velocity: ' . ($velResult['details'] ?? '');
            }
        }

        // v3.0: Tor exit node / datacenter check (BEFORE persistence)
        if ($ip !== '' && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\TorDatacenterProvider')) {
            $torProvider = new \FraudPreventionSuite\Lib\Providers\TorDatacenterProvider();
            $torResult = $torProvider->check($context);
            $torScore = (float) ($torResult['score'] ?? 0);
            if ($torScore > 0) {
                $score = min(100, $score + $torScore);
                $details[] = 'Tor/DC: ' . json_encode($torResult['details'] ?? []);
            }
        }

        // Measure duration AFTER all checks complete (accurate timing)
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        // NOW persist -- score includes ALL checks (providers + velocity + Tor)
        $riskLevel = 'low';
        if ($score >= 80) $riskLevel = 'critical';
        elseif ($score >= 60) $riskLevel = 'high';
        elseif ($score >= 30) $riskLevel = 'medium';

        Capsule::table('mod_fps_checks')->insert([
            'order_id' => 0,
            'client_id' => $clientId,
            'check_type' => 'pre_checkout',
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'ip_address' => $ip,
            'email' => $email,
            'phone' => $phone,
            'country' => $country,
            'details' => json_encode($details),
            'action_taken' => $score >= $blockThreshold ? 'blocked' : 'allowed',
            'is_pre_checkout' => 1,
            'check_duration_ms' => $durationMs,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Update stats
        $today = date('Y-m-d');
        if (Capsule::table('mod_fps_stats')->where('date', $today)->exists()) {
            Capsule::table('mod_fps_stats')->where('date', $today)->increment('checks_total');
            if ($score >= $blockThreshold) {
                Capsule::table('mod_fps_stats')->where('date', $today)->increment('pre_checkout_blocks');
            }
        }

        // Block if score exceeds threshold
        if ($score >= $blockThreshold) {
            logActivity("Fraud Prevention: Pre-checkout BLOCKED for client #{$clientId} (IP: {$ip}, score: {$score})");
            return ['We were unable to process your order at this time. Please contact support if you believe this is an error. Reference: FPS-' . date('ymdHi')];
        }

        return [];

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'PreCheckout', '', $e->getMessage());
        return []; // Never block checkout on error
    }
});

// ---------------------------------------------------------------------------
// 4. AfterShoppingCartCheckout -- Full fraud check with ALL providers
// ---------------------------------------------------------------------------
add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    try {
        $autoCheck = Capsule::table('tbladdonmodules')
            ->where('module', 'fraud_prevention_suite')
            ->where('setting', 'auto_check_orders')
            ->value('value');

        if ($autoCheck !== 'on' && $autoCheck !== 'yes') {
            return;
        }

        $orderId = (int)($vars['OrderID'] ?? 0);
        if (!$orderId) return;

        $order = Capsule::table('tblorders')->where('id', $orderId)->first();
        if (!$order) return;

        $clientId = (int)($order->userid ?? $_SESSION['uid'] ?? 0);
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) return;

        $ip = $order->ipaddress ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $email = $client->email ?? '';
        $phone = $client->phonenumber ?? '';
        $country = $client->country ?? '';
        $fingerprintData = $_POST['fps_fingerprint'] ?? '';

        // Build typed context for CheckRunner
        if (class_exists('\\FraudPreventionSuite\\Lib\\Models\\FpsCheckContext')
            && class_exists('\\FraudPreventionSuite\\Lib\\FpsCheckRunner')) {
            $context = \FraudPreventionSuite\Lib\Models\FpsCheckContext::fromOrderAndClient(
                $order,
                $client,
                'auto',
                $fingerprintData
            );
            $runner = new \FraudPreventionSuite\Lib\FpsCheckRunner();
            $runner->runFullCheck($context);
        } else {
            // Fallback: basic FraudRecord check (v1.0 compat)
            \FraudPreventionSuite\Lib\FpsHookHelpers::fps_legacyFraudCheck($orderId, $clientId, $email, $ip, $phone, $country);
        }

        // Store geo event for topology
        \FraudPreventionSuite\Lib\FpsHookHelpers::fps_recordGeoEvent($ip, $country, $orderId);

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'AfterShoppingCartCheckout', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// 5. AcceptOrder -- Verify fraud status before accepting
// ---------------------------------------------------------------------------
add_hook('AcceptOrder', 1, function ($vars) {
    try {
        $orderId = (int)($vars['orderid'] ?? 0);
        if (!$orderId) return;

        $check = Capsule::table('mod_fps_checks')
            ->where('order_id', $orderId)
            ->where('locked', 1)
            ->first();

        if ($check && !$check->reviewed_by) {
            logActivity("Fraud Prevention: WARNING - Accepting locked order #{$orderId} (risk: {$check->risk_score}). Not yet reviewed.");
        }
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'AcceptOrder', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// 6. ClientAreaPageCart -- Inject fingerprint collector JS
// ---------------------------------------------------------------------------
add_hook('ClientAreaPageCart', 1, function ($vars) {
    try {
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'fingerprint_enabled')
            ->value('setting_value');

        if ($enabled !== '1') return [];

        // Inject fingerprint.js on checkout pages
        return [
            'fps_fingerprint_js' => '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-fingerprint.js" defer></script>',
        ];
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 6b. ClientAreaHeaderOutput -- Inject Turnstile API script
// ---------------------------------------------------------------------------
add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    try {
        $turnstileEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'turnstile_enabled')
            ->value('setting_value');

        if ($turnstileEnabled !== '1') return '';

        $siteKey = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'turnstile_site_key')
            ->value('setting_value');

        if (empty($siteKey)) return '';

        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 7. ClientAreaFooterOutput -- Inject fingerprint JS tag
// ---------------------------------------------------------------------------
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        // Only inject on cart/checkout pages
        $filename = $vars['filename'] ?? '';
        if (!in_array($filename, ['cart', 'clientarea'])) {
            return '';
        }

        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'fingerprint_enabled')
            ->value('setting_value');

        if ($enabled !== '1') return '';

        return '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-fingerprint.js" defer></script>';
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 8. ClientAdd -- Check new client registrations
// ---------------------------------------------------------------------------
add_hook('ClientAdd', 1, function ($vars) {
    try {
        $clientId = (int)($vars['userid'] ?? 0);
        if (!$clientId) return;

        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) return;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $email = $client->email ?? '';
        $country = $client->country ?? '';

        // Run full check instead of pre-checkout (catches bot patterns, all providers)
        if (class_exists('\\FraudPreventionSuite\\Lib\\Models\\FpsCheckContext')
            && class_exists('\\FraudPreventionSuite\\Lib\\FpsCheckRunner')) {
            $context = \FraudPreventionSuite\Lib\Models\FpsCheckContext::fromClientId($clientId, 'registration');
            $runner = new \FraudPreventionSuite\Lib\FpsCheckRunner();
            $result = $runner->runFullCheck($context);

            if ($result->risk->score >= 60) {
                logActivity("Fraud Prevention: High-risk registration for client #{$clientId} (email: {$email}, IP: {$ip}, score: {$result->risk->score})");
                // Set client to Inactive if score is very high
                if ($result->risk->score >= 80) {
                    Capsule::table('tblclients')->where('id', $clientId)->update(['status' => 'Inactive']);
                    logActivity("Fraud Prevention: Auto-deactivated client #{$clientId} (score: {$result->risk->score})");
                }
            }
        }

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'ClientAdd', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// 9. DailyCronJob -- Cache purge, stats aggregation, maintenance
// ---------------------------------------------------------------------------
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        // Purge expired IP intel cache
        $ipTtl = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'cache_ttl_ip')
            ->value('setting_value') ?: 86400;

        Capsule::table('mod_fps_ip_intel')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();

        // Purge expired email intel cache
        Capsule::table('mod_fps_email_intel')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();

        // Purge old geo events (default 90 days)
        $geoRetention = (int)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'geo_events_retention_days')
            ->value('setting_value') ?: 90);

        $geoDeleted = Capsule::table('mod_fps_geo_events')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-{$geoRetention} days")))
            ->delete();

        // Purge old API logs (default 30 days)
        $apiRetention = (int)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'api_logs_retention_days')
            ->value('setting_value') ?: 30);

        $apiDeleted = Capsule::table('mod_fps_api_logs')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-{$apiRetention} days")))
            ->delete();

        // Purge expired rate limit entries
        Capsule::table('mod_fps_rate_limits')
            ->where('last_refill_at', '<', date('Y-m-d H:i:s', strtotime('-1 day')))
            ->delete();

        // Aggregate yesterday's stats
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stats = Capsule::table('mod_fps_stats')->where('date', $yesterday)->first();
        if ($stats) {
            // Calculate unique IPs
            $uniqueIps = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $yesterday . ' 00:00:00')
                ->where('created_at', '<', date('Y-m-d') . ' 00:00:00')
                ->distinct('ip_address')
                ->count('ip_address');

            // Top countries
            $topCountries = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $yesterday . ' 00:00:00')
                ->where('created_at', '<', date('Y-m-d') . ' 00:00:00')
                ->whereNotNull('country')
                ->selectRaw('country, COUNT(*) as count')
                ->groupBy('country')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'country')
                ->toArray();

            // Average risk score
            $avgScore = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $yesterday . ' 00:00:00')
                ->where('created_at', '<', date('Y-m-d') . ' 00:00:00')
                ->avg('risk_score') ?? 0;

            Capsule::table('mod_fps_stats')
                ->where('date', $yesterday)
                ->update([
                    'unique_ips' => $uniqueIps,
                    'top_countries' => json_encode($topCountries),
                    'avg_risk_score' => round($avgScore, 2),
                ]);
        }

        // Refresh OFAC SDN list
        if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\OfacScreeningProvider')) {
            \FraudPreventionSuite\Lib\Providers\OfacScreeningProvider::refreshSdnList();
        }

        // Auto-update disposable email domain list
        $autoUpdate = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'disposable_list_auto_update')
            ->value('setting_value');
        if ($autoUpdate === '1') {
            \FraudPreventionSuite\Lib\FpsHookHelpers::fps_refreshDisposableDomains();
        }

        // v3.0: Refresh Tor exit node list
        $torEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'tor_detection_enabled')
            ->value('setting_value');
        if ($torEnabled === '1') {
            if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\TorDatacenterProvider')) {
                \FraudPreventionSuite\Lib\Providers\TorDatacenterProvider::refreshTorNodeList();
                \FraudPreventionSuite\Lib\Providers\TorDatacenterProvider::refreshDatacenterAsnList();
            }
        }

        // v3.0: Purge old velocity events (default 7 days)
        if (class_exists('\\FraudPreventionSuite\\Lib\\FpsVelocityEngine')) {
            $velocityEngine = new \FraudPreventionSuite\Lib\FpsVelocityEngine();
            $velocityPurged = $velocityEngine->purgeOldEvents(7);
        }

        // v3.0: Purge old behavioral events (default 30 days)
        if (Capsule::schema()->hasTable('mod_fps_behavioral_events')) {
            $behavioralPurged = Capsule::table('mod_fps_behavioral_events')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->delete();
        }

        // v3.0: Purge old webhook logs (default 30 days)
        if (Capsule::schema()->hasTable('mod_fps_webhook_log')) {
            Capsule::table('mod_fps_webhook_log')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->delete();
        }

        // v3.0: Run adaptive scoring analysis (monthly)
        $adaptiveEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'adaptive_scoring_enabled')
            ->value('setting_value');
        if ($adaptiveEnabled === '1' && class_exists('\\FraudPreventionSuite\\Lib\\FpsAdaptiveScoring')) {
            $adaptive = new \FraudPreventionSuite\Lib\FpsAdaptiveScoring();
            if ($adaptive->shouldRun()) {
                $adaptive->runMonthlyAnalysis();
            }
        }

        // v3.0: Auto-suspend clients with excessive chargebacks
        $autoSuspendEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'auto_suspend_enabled')
            ->value('setting_value');
        if ($autoSuspendEnabled === '1') {
            \FraudPreventionSuite\Lib\FpsHookHelpers::fps_autoSuspendChargebackAbusers();
        }

        // v4.0: Purge expired global intel records
        try {
            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelCollector')) {
                $collector = new \FraudPreventionSuite\Lib\FpsGlobalIntelCollector();
                $intelPurged = $collector->purgeExpired();
            }
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'DailyCron:GlobalIntelPurge', '', $e->getMessage());
        }

        // v4.0: Auto-push global intel to hub (if enabled)
        try {
            if (Capsule::schema()->hasTable('mod_fps_global_config')) {
                $autoPush = Capsule::table('mod_fps_global_config')
                    ->where('setting_key', 'auto_push_enabled')
                    ->value('setting_value');
                $sharingEnabled = Capsule::table('mod_fps_global_config')
                    ->where('setting_key', 'global_sharing_enabled')
                    ->value('setting_value');
                if ($autoPush === '1' && $sharingEnabled === '1'
                    && class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelClient')) {
                    $hubClient = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
                    if ($hubClient->isConfigured()) {
                        $hubClient->pushIntel();
                    }
                }
            }
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'DailyCron:GlobalIntelPush', '', $e->getMessage());
        }

        logModuleCall('fraud_prevention_suite', 'DailyCron',
            "Purged: {$geoDeleted} geo events, {$apiDeleted} API logs",
            'Cron completed successfully');

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'DailyCron', '', $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// 10. ClientAreaPage -- Serve public API and topology
// ---------------------------------------------------------------------------
add_hook('ClientAreaPage', 1, function ($vars) {
    try {
        if (!isset($_GET['m']) || $_GET['m'] !== 'fraud_prevention_suite') {
            return [];
        }

        // API routing
        if (isset($_GET['api'])) {
            if (class_exists('\\FraudPreventionSuite\\Lib\\Api\\FpsApiRouter')) {
                $router = new \FraudPreventionSuite\Lib\Api\FpsApiRouter();
                $router->handle();
                exit;
            }
        }

        return [];
    } catch (\Throwable $e) {
        return [];
    }
});

// ---------------------------------------------------------------------------
// 11. InvoiceRefunded -- Track refunds for abuse detection
// ---------------------------------------------------------------------------
add_hook('InvoiceRefunded', 1, function ($vars) {
    try {
        $invoiceId = (int)($vars['invoiceid'] ?? 0);
        if (!$invoiceId) return;

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;

        $clientId = (int)($invoice->userid ?? 0);

        Capsule::table('mod_fps_refund_tracking')->insert([
            'client_id'   => $clientId,
            'invoice_id'  => $invoiceId,
            'amount'      => (float)($invoice->total ?? 0),
            'reason'      => 'refund',
            'refund_date' => date('Y-m-d H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Check for refund abuse pattern
        $windowDays = (int)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'refund_abuse_window_days')
            ->value('setting_value') ?: 90);
        $threshold = (int)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'refund_abuse_threshold')
            ->value('setting_value') ?: 3);

        $refundCount = Capsule::table('mod_fps_refund_tracking')
            ->where('client_id', $clientId)
            ->where('refund_date', '>=', date('Y-m-d H:i:s', strtotime("-{$windowDays} days")))
            ->count();

        if ($refundCount >= $threshold) {
            logActivity("Fraud Prevention: Refund abuse pattern detected for client #{$clientId} ({$refundCount} refunds in {$windowDays} days)");
        }
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'InvoiceRefunded', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// 12. InvoiceUnpaid -- Track chargebacks/disputes
// ---------------------------------------------------------------------------
add_hook('InvoiceUnpaid', 1, function ($vars) {
    try {
        $invoiceId = (int)($vars['invoiceid'] ?? 0);
        if (!$invoiceId) return;

        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'chargeback_tracking_enabled')
            ->value('setting_value');
        if ($enabled !== '1') return;

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;

        $clientId = (int)($invoice->userid ?? 0);

        // Find associated order
        $orderItem = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->first();
        $orderId = 0;
        if ($orderItem && $orderItem->relid) {
            $hosting = Capsule::table('tblhosting')->where('id', $orderItem->relid)->first();
            if ($hosting) $orderId = (int)($hosting->orderid ?? 0);
        }

        // Get the fraud score that was recorded at order time
        $originalCheck = null;
        if ($orderId > 0) {
            $originalCheck = Capsule::table('mod_fps_checks')
                ->where('order_id', $orderId)
                ->orderByDesc('created_at')
                ->first();
        }

        Capsule::table('mod_fps_chargebacks')->insert([
            'client_id'              => $clientId,
            'order_id'               => $orderId,
            'invoice_id'             => $invoiceId,
            'amount'                 => (float)($invoice->total ?? 0),
            'gateway'                => $invoice->paymentmethod ?? '',
            'fraud_score_at_order'   => $originalCheck->risk_score ?? null,
            'risk_level_at_order'    => $originalCheck->risk_level ?? null,
            'provider_scores_at_order' => $originalCheck->provider_scores ?? null,
            'chargeback_date'        => date('Y-m-d H:i:s'),
            'created_at'             => date('Y-m-d H:i:s'),
        ]);

        logActivity("Fraud Prevention: Chargeback recorded for client #{$clientId}, invoice #{$invoiceId}, amount \${$invoice->total}");

        // v3.0: Send webhook notification for chargeback event
        if (class_exists('\\FraudPreventionSuite\\Lib\\FpsWebhookNotifier')) {
            try {
                $webhookNotifier = new \FraudPreventionSuite\Lib\FpsWebhookNotifier();
                $webhookNotifier->sendFraudAlert(
                    'high',
                    $orderId,
                    $clientId,
                    $originalCheck->risk_score ?? 0,
                    ["Chargeback detected: Invoice #{$invoiceId}, Amount \${$invoice->total}", "Original risk score: " . ($originalCheck->risk_score ?? 'N/A')]
                );
            } catch (\Throwable $e) {
                // Webhook failure is non-fatal
            }
        }

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'InvoiceUnpaid', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// NOTE: Helper functions (fps_recordGeoEvent, fps_refreshDisposableDomains,
// fps_autoSuspendChargebackAbusers, fps_legacyFraudCheck) are in
// lib/FpsHookHelpers.php as static methods. Called via:
//   \FraudPreventionSuite\Lib\FpsHookHelpers::methodName()
// ---------------------------------------------------------------------------

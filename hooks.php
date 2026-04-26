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
// 0. Load AI Assistant hooks (if module is installed but WHMCS isn't loading them)
// ---------------------------------------------------------------------------
try {
    $aiAssistantHooks = dirname(__DIR__) . '/ai_assistant/hooks.php';
    if (file_exists($aiAssistantHooks)) {
        $aiModuleActive = Capsule::table('tbladdonmodules')
            ->where('module', 'ai_assistant')
            ->where('setting', 'version')
            ->exists();
        if ($aiModuleActive) {
            require_once $aiAssistantHooks;
        }
    }
} catch (\Throwable $e) {
    // Silently fail - AI assistant is optional
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
            $bust = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_assetCacheBust('css/fps-1000x.css');
            return '<link rel="stylesheet" href="../modules/addons/fraud_prevention_suite/assets/css/fps-1000x.css' . $bust . '">';
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
        // Strict URL match: only the WHMCS Users list page (custom admin dirs
        // supported). Previous broad strpos(uri, 'user') also matched unrelated
        // admin pages like clientsummary?userid= or filenames containing 'user'.
        // We require /<admindir>/user/list as a path segment, with optional
        // query-string suffix.
        if (!preg_match('#/[^/]+/user/list(\?|$)#', $uri)) {
            return '';
        }

        // Check if the feature is enabled in settings
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'user_purge_on_users_page')
            ->value('setting_value');
        if ($enabled !== '1') return '';

        // Build absolute AJAX URL using the admin path from configuration.php.
        // $customadminpath is a PHP variable (NOT in tblconfiguration), so we
        // extract the admin directory from the current REQUEST_URI which is
        // guaranteed to contain the correct admin path (e.g. /whmcsadmin/user/list).
        $adminDir = 'admin';
        if (preg_match('#^/([^/]+)/user/#', $uri, $m)) {
            $adminDir = $m[1]; // e.g. "whmcsadmin"
        }
        $moduleLink = '/' . $adminDir . '/addonmodules.php?module=fraud_prevention_suite';

        // Get CSRF token for AJAX POST requests
        $csrfToken = function_exists('generate_token') ? generate_token('plain') : ($_SESSION['token'] ?? '');

        // Inject the toolbar and JavaScript
        return <<<FPSHTML
<style>
.fps-user-toolbar{background:linear-gradient(135deg,#1a1a2e,#302b63);color:#fff;padding:16px 20px;border-radius:10px;margin:15px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-family:-apple-system,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.15);}
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

<div class="fps-user-toolbar" id="fps-user-toolbar" style="display:none;">
  <h4><i class="fas fa-shield-halved"></i> FPS Bot Detection</h4>
  <button class="fps-ubtn fps-ubtn-scan" onclick="fpsUserPurge.scan()"><i class="fas fa-search"></i> Scan for Bot Users</button>
  <button class="fps-ubtn fps-ubtn-select" onclick="fpsUserPurge.selectBots()" style="display:none;" id="fps-uselect-btn"><i class="fas fa-check-double"></i> Select All Bots</button>
  <button class="fps-ubtn fps-ubtn-purge" onclick="fpsUserPurge.purge()" style="display:none;" id="fps-upurge-btn"><i class="fas fa-trash"></i> Purge Selected</button>
  <span class="fps-ustatus" id="fps-ustatus"></span>
</div>

<script>
(function(){
  // Absolute AJAX URL so it works from /admin/user/list (different path depth)
  var moduleLink = '{$moduleLink}';
  var csrfToken  = '{$csrfToken}';
  var botUserIds = [];
  var selectedIds = new Set();

  // Reposition toolbar: move from footer to above the users table
  var toolbar = document.getElementById('fps-user-toolbar');
  if (toolbar) {
    // Find the main content area -- WHMCS uses .content-wrapper > section.content
    var table = document.querySelector('.content-wrapper table, section.content table, .tab-content table');
    if (table) {
      table.parentNode.insertBefore(toolbar, table);
    } else {
      // Fallback: insert after the page heading
      var heading = document.querySelector('.content-header, h1, .main-title');
      if (heading) heading.parentNode.insertBefore(toolbar, heading.nextSibling);
    }
    toolbar.style.display = '';
  }

  function ajaxPost(action, extraBody, callback) {
    var url = moduleLink + '&ajax=1&a=' + action;
    var body = 'token=' + encodeURIComponent(csrfToken);
    if (extraBody) body += '&' + extraBody;
    fetch(url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin', body:body})
      .then(function(r){return r.json();})
      .then(function(data){ callback(null, data); })
      .catch(function(err){ callback(err, null); });
  }

  window.fpsUserPurge = {
    scan: function() {
      var status = document.getElementById('fps-ustatus');
      if (status) status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';

      ajaxPost('detect_orphan_users', '', function(err, data) {
        if (err || (data && data.error)) {
          if (status) status.textContent = 'Error: ' + (data ? data.error : err.message);
          return;
        }

        botUserIds = (data.users || []).map(function(u){return u.id;});
        var botEmails = {};
        (data.users || []).forEach(function(u){ botEmails[u.email.toLowerCase()] = u; });

        // Highlight bot users in the existing table.
        // WHMCS email cells contain extra text like "Email Verified" badges,
        // so we use includes() instead of exact match.
        var rows = document.querySelectorAll('table tbody tr, .dataTables_wrapper tbody tr');
        var marked = 0;
        var emailKeys = Object.keys(botEmails);
        rows.forEach(function(row){
          var cells = row.querySelectorAll('td');
          var rowText = '';
          cells.forEach(function(cell){ rowText += ' ' + (cell.textContent || ''); });
          rowText = rowText.toLowerCase();
          for (var i = 0; i < emailKeys.length; i++) {
            if (rowText.indexOf(emailKeys[i]) !== -1) {
              row.classList.add('fps-bot-user');
              if (!row.querySelector('.fps-ucheck')) {
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'fps-ucheck';
                cb.dataset.userId = botEmails[emailKeys[i]].id;
                cb.onchange = function(){ fpsUserPurge._toggle(parseInt(this.dataset.userId), this.checked); };
                cells[0].prepend(cb);
                cb.insertAdjacentHTML('afterend', ' ');
              }
              marked++;
              break;
            }
          }
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
      });
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

      ajaxPost('purge_orphan_users', 'ids=' + ids.join(','), function(err, data) {
        if (err || (data && data.error)) {
          if (status) status.textContent = 'Error: ' + (data ? data.error : err.message);
          return;
        }
        if (status) status.innerHTML = '<span class="fps-ubadge fps-ubadge-green">' + data.purged + ' users purged</span> Reloading...';
        setTimeout(function(){ location.reload(); }, 1500);
      });
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

        // For guest checkout or when client data is incomplete, capture from POST/session
        if (empty($email)) {
            $email = trim($_POST['email'] ?? $_POST['loginemail'] ?? $_SESSION['cart']['user']['email'] ?? '');
        }
        if (empty($phone)) {
            $phone = trim($_POST['phonenumber'] ?? '');
        }
        if (empty($country)) {
            $country = trim($_POST['country'] ?? '');
        }

        // Turnstile validation (if enabled for checkout)
        try {
            $libDir = __DIR__ . '/lib/';
            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsConfig')) {
                if (file_exists($libDir . 'FpsConfig.php')) require_once $libDir . 'FpsConfig.php';
            }
            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsTurnstileValidator')) {
                if (file_exists($libDir . 'FpsTurnstileValidator.php')) require_once $libDir . 'FpsTurnstileValidator.php';
            }
            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsTurnstileValidator')) {
                $tsValidator = new \FraudPreventionSuite\Lib\FpsTurnstileValidator();
                if ($tsValidator->isEnabled() && $tsValidator->isFormProtected('checkout')) {
                    $tsToken = $_POST['cf-turnstile-response'] ?? '';
                    $tsResult = $tsValidator->validate($tsToken, $ip);
                    if (!$tsResult['success'] && !in_array('network-error-failopen', $tsResult['error_codes'] ?? [])) {
                        $tsErrors = implode(', ', $tsResult['error_codes'] ?? []);
                        logActivity("Fraud Prevention: Turnstile FAILED at checkout -- IP: {$ip}, errors: {$tsErrors}");

                        // Record the block in mod_fps_checks so it appears in
                        // dashboard stats, reports, topology, and global intel.
                        try {
                            $tsNow = date('Y-m-d H:i:s');
                            Capsule::table('mod_fps_checks')->insert([
                                'order_id'          => 0,
                                'client_id'         => $clientId,
                                'email'             => $email ?: null,
                                'ip_address'        => $ip,
                                'country'           => $country ?: null,
                                'check_type'        => 'turnstile_block',
                                'risk_score'        => 100.0,
                                'risk_level'        => 'critical',
                                'action_taken'      => 'block',
                                'locked'            => 1,
                                'reviewed_by'       => 0,
                                'reviewed_at'       => $tsNow,
                                // Structured columns (parity with the inline pre-checkout
                                // and FpsCheckRunner write paths).
                                'provider_scores'   => json_encode(['turnstile' => 100.0]),
                                'check_context'     => json_encode([
                                    'turnstile_errors' => $tsResult['error_codes'] ?? [],
                                    'blocked_at'       => 'checkout',
                                    'user_agent'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                                ]),
                                'is_pre_checkout'   => 1,
                                'check_duration_ms' => null,
                                'created_at'        => $tsNow,
                                'updated_at'        => $tsNow,
                            ]);
                            // Record daily counters via the shared recorder so this path
                            // stays consistent with the normal-check and API-request paths.
                            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsStatsCollector')) {
                                (new \FraudPreventionSuite\Lib\FpsStatsCollector())->recordEvent('turnstile_block');
                            }
                        } catch (\Throwable $e) {
                            // Non-fatal -- don't let logging failure unblock the bot
                        }

                        if (class_exists('FpsAnalyticsServerEvents')) {
                            try {
                                FpsAnalyticsServerEvents::send('turnstile_fail', [
                                    'country'     => $country,
                                    'error_codes' => implode(',', $tsResult['error_codes'] ?? []),
                                    'ip_country'  => $country,
                                ]);
                            } catch (\Throwable $analyticsEx) {
                                logModuleCall('fraud_prevention_suite', 'analytics::turnstile_fail', [], $analyticsEx->getMessage());
                            }
                        }

                        return ['Bot protection verification failed. Please refresh the page and try again. Reference: FPS-TS-' . date('ymdHi')];
                    } else {
                        // Turnstile passed
                        if (class_exists('FpsAnalyticsServerEvents')) {
                            try {
                                FpsAnalyticsServerEvents::send('turnstile_pass', ['country' => $country]);
                            } catch (\Throwable $analyticsEx) {
                                logModuleCall('fraud_prevention_suite', 'analytics::turnstile_pass', [], $analyticsEx->getMessage());
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail open on Turnstile errors to not block real users
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

        // Feature flag: route through FpsCheckRunner::runPreCheckoutFast()
        // instead of the inline pipeline below. **Default '1' as of v4.2.4 PM**
        // -- the runner is now the canonical path; the inline pipeline is kept
        // ONLY as automatic fallback (when the runner throws). To roll back
        // to the historical inline-first behaviour an operator can flip this
        // to '0' from the Settings tab "Pipeline Internals" card.
        $useRunnerFastPath = '1';
        try {
            $val = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'use_runner_fast_path')
                ->value('setting_value');
            if ($val !== null) {
                $useRunnerFastPath = (string) $val;
            }
        } catch (\Throwable $e) { /* non-fatal -- default to runner */ }

        if ($useRunnerFastPath === '1'
            && class_exists('\\FraudPreventionSuite\\Lib\\FpsCheckRunner')
            && class_exists('\\FraudPreventionSuite\\Lib\\Models\\FpsCheckContext')) {
            try {
                $selectedGateway = (string) ($_SESSION['paymentmethod'] ?? '');
                $thresholds      = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_resolvePreCheckoutThresholds($selectedGateway);

                $runnerCtx = new \FraudPreventionSuite\Lib\Models\FpsCheckContext(
                    email:           $email,
                    ip:              $ip,
                    phone:           $phone,
                    country:         $country,
                    clientId:        $clientId,
                    orderId:         0,
                    amount:          0.0,
                    domain:          $context['domain'] ?? '',
                    fingerprintHash: $fingerprintData,
                    checkType:       'pre_checkout',
                );
                $runnerResult = (new \FraudPreventionSuite\Lib\FpsCheckRunner())->runPreCheckoutFast($runnerCtx);

                // Mirror the inline path's stats recording so the dashboard
                // counters stay consistent regardless of which path ran.
                if (class_exists('\\FraudPreventionSuite\\Lib\\FpsStatsCollector')) {
                    $statsEvent = ($runnerResult->risk->score >= $thresholds['block']) ? 'pre_checkout_block' : 'pre_checkout_allow';
                    (new \FraudPreventionSuite\Lib\FpsStatsCollector())->recordEvent($statsEvent);
                }

                if ($runnerResult->risk->score >= $thresholds['block']) {
                    logActivity("Fraud Prevention: Pre-checkout (fast-path) BLOCKED for client #{$clientId} (IP: {$ip}, score: {$runnerResult->risk->score})");
                    return ['We were unable to process your order at this time. Please contact support if you believe this is an error. Reference: FPS-' . date('ymdHi')];
                }
                return [];
            } catch (\Throwable $e) {
                // Fast-path runner failed -- fall through to inline pipeline below.
                logModuleCall('fraud_prevention_suite', 'PreCheckout::FastPathFallback', '', $e->getMessage());
            }
        }

        // Run quick checks only (providers where isQuick() = true)
        $score = 0;
        $details = [];
        // providerScores: per-provider weighted contribution to the final score.
        // Persisted to mod_fps_checks.provider_scores so newer readers can rank
        // signals without parsing the legacy details JSON blob.
        $providerScores = [];

        // 1. IP Intel (cached = instant)
        if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\IpIntelProvider')) {
            $provider = new \FraudPreventionSuite\Lib\Providers\IpIntelProvider();
            if ($provider->isEnabled()) {
                $result = $provider->check($context);
                $contribution = ($result['score'] ?? 0) * $provider->getWeight();
                $score += $contribution;
                $providerScores['ip_intel'] = (float) $contribution;
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
                $contribution = ($result['score'] ?? 0) * $provider->getWeight();
                $score += $contribution;
                $providerScores['email_validation'] = (float) $contribution;
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
                $contribution = ($result['score'] ?? 0) * $provider->getWeight();
                $score += $contribution;
                $providerScores['fingerprint'] = (float) $contribution;
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

            $botContribution = $botScore * 2.0; // Weight 2.0 for bot detection
            $score += $botContribution;
            if ($botContribution > 0) {
                $providerScores['bot_pattern'] = (float) $botContribution;
            }
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
                    $providerScores['global_intel'] = (float) $adj;
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
                $providerScores['rules'] = 95.0;
            } elseif ($ruleResult->action === 'flag') {
                $score += 15;
                $providerScores['rules'] = ($providerScores['rules'] ?? 0.0) + 15.0;
            }
            if (count($ruleResult->matchedRules) > 0) {
                $names = array_column($ruleResult->matchedRules, 'rule_name');
                $details[] = 'Rules matched: ' . implode(', ', $names);
            }
        }

        $score = min($score, 100);

        // Resolve pre-checkout thresholds via the shared helper so the
        // inline path and FpsCheckRunner-style paths agree on values when
        // admin settings change. See FpsHookHelpers::fps_resolvePreCheckoutThresholds()
        // for the resolution order (per-gateway -> legacy admin -> modern admin -> default).
        //
        // Design note on duplicate engines (audit issue #5): the inline
        // provider orchestration above is intentionally kept here for the
        // <2s checkout budget. Threshold resolution, persistence (structured
        // columns) and stats are now centralised so the only intentional
        // gap between this path and FpsCheckRunner::runPreCheckout() is the
        // provider list itself. See TODO-hardening.md for the remaining
        // unification work.
        $selectedGateway  = (string) ($_SESSION['paymentmethod'] ?? '');
        $thresholds       = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_resolvePreCheckoutThresholds($selectedGateway);
        $blockThreshold   = $thresholds['block'];
        $captchaThreshold = $thresholds['captcha'];

        // (Trust check moved to top of hook, before scoring pipeline)

        // v3.0: Velocity check (BEFORE persistence so score is complete)
        if ($ip !== '' && class_exists('\\FraudPreventionSuite\\Lib\\FpsVelocityEngine')) {
            $velEngine = new \FraudPreventionSuite\Lib\FpsVelocityEngine();
            $velEngine->recordEvent('checkout_attempt', $ip, $clientId);
            $velResult = $velEngine->checkVelocity($context);
            $velScore = (float) ($velResult['score'] ?? 0);
            if ($velScore > 0) {
                $score = min(100, $score + $velScore);
                $providerScores['velocity'] = (float) $velScore;
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
                $providerScores['tor_datacenter'] = (float) $torScore;
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

        // Write BOTH legacy details JSON (existing readers) AND the structured
        // columns (provider_scores, check_context, is_pre_checkout,
        // check_duration_ms, updated_at) so this inline pre-checkout path
        // matches what FpsCheckRunner::fps_persistCheck() writes.
        $now = date('Y-m-d H:i:s');
        $checkContextJson = json_encode([
            'check_type'  => 'pre_checkout',
            'order_id'    => 0,
            'client_id'   => $clientId,
            'has_email'   => $email !== '',
            'has_phone'   => $phone !== '',
            'has_country' => $country !== '',
            'has_fp'      => $fingerprintData !== '',
            'amount'      => 0.0,
            'domain'      => $context['domain'] ?? '',
            'gateway'     => $selectedGateway,
            'thresholds'  => [
                'block'   => $blockThreshold,
                'captcha' => $captchaThreshold,
            ],
        ]);

        // Legacy details writes are gated by write_legacy_details_column
        // setting (see TODO-hardening.md item #2 + FpsCheckRunner::fps_persistCheck).
        $writeLegacyHere = '1';
        try {
            $val = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'write_legacy_details_column')
                ->value('setting_value');
            if ($val !== null) {
                $writeLegacyHere = (string) $val;
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        $insertRow = [
            'order_id'          => 0,
            'client_id'         => $clientId,
            'check_type'        => 'pre_checkout',
            'risk_score'        => $score,
            'risk_level'        => $riskLevel,
            'ip_address'        => $ip,
            'email'             => $email,
            'phone'             => $phone,
            'country'           => $country,
            'action_taken'      => $score >= $blockThreshold ? 'blocked' : 'allowed',
            'provider_scores'   => json_encode($providerScores),
            'check_context'     => $checkContextJson,
            'is_pre_checkout'   => 1,
            'check_duration_ms' => $durationMs,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        if ($writeLegacyHere !== '0') {
            $insertRow['details'] = json_encode($details);
        }
        Capsule::table('mod_fps_checks')->insert($insertRow);

        // Record via shared stats collector (same path as turnstile_block and api_request).
        // Distinguishes blocks from allows so dashboard counters stay accurate.
        if (class_exists('\\FraudPreventionSuite\\Lib\\FpsStatsCollector')) {
            $stats = new \FraudPreventionSuite\Lib\FpsStatsCollector();
            $stats->recordEvent($score >= $blockThreshold ? 'pre_checkout_block' : 'pre_checkout_allow');
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

        // Only inject here when scope is 'checkout'.
        // When scope is 'all', ClientAreaFooterOutput (hook 7) covers every page
        // including cart -- loading here too would double-inject the script.
        $scope = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'fingerprint_scope')
            ->value('setting_value') ?? 'all';

        if ($scope !== 'checkout') return [];

        $bust = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_assetCacheBust('js/fps-fingerprint.js');
        return [
            'fps_fingerprint_js' => '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-fingerprint.js' . $bust . '" defer></script>',
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
    $output = '';

    // Turnstile CAPTCHA script (only when enabled + key configured)
    try {
        $turnstileEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'turnstile_enabled')
            ->value('setting_value');

        if ($turnstileEnabled === '1') {
            $siteKey = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'turnstile_site_key')
                ->value('setting_value');

            if (!empty($siteKey)) {
                $output .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            }
        }
    } catch (\Throwable $e) {
        // Turnstile load failed silently - CSS still loads below
    }

    // Inject site-wide CSS from static file (audit issue #13: fps-site-theme.css
    // contains site-wide branding/merchandising overrides that are NOT core to
    // fraud prevention - gated behind admin flag, default on for existing installs).
    $themeEnabled = '1';
    try {
        $themeEnabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'enable_site_theme_overrides')
            ->value('setting_value') ?? '1';
    } catch (\Throwable $e) {}

    if ($themeEnabled === '1') {
        $cssPath = __DIR__ . '/assets/css/fps-site-theme.css';
        $cssVer  = (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'v') . '-' . (file_exists($cssPath) ? (string) filemtime($cssPath) : '0');
        $output .= '<link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-site-theme.css?v=' . $cssVer . '">';
    }

    // Inject custom client theme color overrides from admin settings
    try {
        $clientColorKeys = [
            'client_brand_color', 'client_bg_color', 'client_text_color',
            'client_hero_start', 'client_hero_end',
        ];
        $clientColors = Capsule::table('mod_fps_settings')
            ->whereIn('setting_key', $clientColorKeys)
            ->pluck('setting_value', 'setting_key')
            ->toArray();

        $clientDefaults = [
            'client_brand_color' => '#2563eb',
            'client_bg_color'    => '#f8fafc',
            'client_text_color'  => '#334155',
            'client_hero_start'  => '#1e3a5f',
            'client_hero_end'    => '#2d1b4e',
        ];

        $hasCustom = false;
        foreach ($clientColors as $k => $v) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v) && strtolower($v) !== strtolower($clientDefaults[$k] ?? '')) {
                $hasCustom = true;
                break;
            }
        }

        if ($hasCustom) {
            $brand = $clientColors['client_brand_color'] ?? $clientDefaults['client_brand_color'];
            $bg    = $clientColors['client_bg_color'] ?? $clientDefaults['client_bg_color'];
            $text  = $clientColors['client_text_color'] ?? $clientDefaults['client_text_color'];
            $heroS = $clientColors['client_hero_start'] ?? $clientDefaults['client_hero_start'];
            $heroE = $clientColors['client_hero_end'] ?? $clientDefaults['client_hero_end'];

            $output .= '<style>'
                . ':root{'
                . '--body-bg:' . $bg . '!important;'
                . '--body-color:' . $text . '!important;'
                . '--text-color:' . $text . '!important;'
                . '--link-color:' . $brand . '!important;'
                . '--btn-primary-bg:' . $brand . '!important;'
                . '--text-heading-color:' . $text . '!important;'
                . '}'
                . '.fps-pub-hero{background:linear-gradient(135deg,' . $heroS . ' 0%,' . $heroE . ' 100%)!important;}'
                . '.fps-pub-nav a.active,.fps-pub-nav a:hover{color:' . $brand . '!important;border-bottom-color:' . $brand . '!important;}'
                . '.fps-cta-section{background:linear-gradient(135deg,' . $heroS . ' 0%,' . $heroE . ' 100%)!important;}'
                . 'a:not(.btn):not([class*="btn"]){color:' . $brand . ';}'
                . '</style>';
        }
    } catch (\Throwable $e) {
        // Non-fatal -- use default theme colors
    }

    /* === LEGACY INLINE CSS MOVED TO fps-site-theme.css ===
     * The following 280+ CSS rules were moved to a static file for:
     * - Browser caching (saves ~11KB per page load after first visit)
     * - Easier maintenance (proper CSS syntax highlighting/linting)
     * - Better performance (HTTP/2 multiplexing + gzip compression)
     *
     * To regenerate the static file from this hook, uncomment the block below
     * and visit any page, then copy the <style> output to fps-site-theme.css
     */

    // NOTE: The following inline CSS blocks are now served from fps-site-theme.css
    // They are kept here commented as the source of truth for regeneration.
    // To regenerate: uncomment, load any page, copy rendered <style> to the CSS file.
    if (false) { // START OF COMMENTED-OUT INLINE CSS
    $output .= '<style>'
        . ':root{'
        . '--body-bg:#f8fafc!important;'
        . '--body-color:#334155!important;'
        . '--text-color:#334155!important;'
        . '--heading-color:#0f172a!important;'
        . '--text-heading-color:#0f172a!important;'
        . '--text-body-color:#334155!important;'
        . '--text-muted-color:#64748b!important;'
        . '--text-link-color:#2563eb!important;'
        . '--label-color:#1e293b!important;'
        . '--form-label-color:#1e293b!important;'
        . '--input-color:#1e293b!important;'
        . '--input-bg:#fff!important;'
        . '--input-border-color:#cbd5e1!important;'
        . '--input-placeholder-color:#94a3b8!important;'
        . '--price-color:#0f172a!important;'
        . '--subtitle-color:#475569!important;'
        . '--description-color:#475569!important;'
        . '--muted-color:#64748b!important;'
        . '--light-color:#94a3b8!important;'
        . '--link-color:#2563eb!important;'
        . '--nav-link-color:#1e293b!important;'
        . '--nav-link-hover-color:#16a34a!important;'
        . '--card-bg:#fff!important;'
        . '--card-text-color:#334155!important;'
        . '--card-heading-color:#0f172a!important;'
        . '--card-border-color:#e2e8f0!important;'
        . '--tile-bg:#fff!important;'
        . '--border-color:#e2e8f0!important;'
        . '--sidebar-text-color:#334155!important;'
        . '--sidebar-link-color:#334155!important;'
        . '--sidebar-bg:#fff!important;'
        . '--panel-heading-color:#0f172a!important;'
        . '--panel-text-color:#334155!important;'
        . '--list-item-color:#334155!important;'
        . '--svg-icon-color-1:#16a34a!important;'
        . '--svg-icon-color-2:#15803d!important;'
        . '--svg-icon-color-3:#0f172a!important;'
        . '--svg-icon-color-4:#64748b!important;'
        . '--svg-icon-color-5:#cbd5e1!important;'
        . '--footer-bg:#0f172a!important;'
        . '--footer-color:#94a3b8!important;'
        . '--footer-text-color:#64748b!important;'
        . '--footer-link-color:#475569!important;'
        . '--main-footer-link-color:#475569!important;'
        . '--main-footer-text-color:#64748b!important;'
        . '--primary:#16a34a!important;'
        . '--secondary:#2563eb!important;'
        . '}'
        // === GLOBAL TEXT ===
        . 'body{color:#334155!important;background:#f8fafc!important}'
        . '.main-content,.main-body,.main-content p,.main-content li,.main-content td,.main-content span,.main-content div{color:#334155!important}'
        . 'h1,h2,h3,h4,h5,h6,.main-content h1,.main-content h2,.main-content h3,.main-content h4,.main-content h5{color:#0f172a!important;font-weight:700!important}'
        // === LINKS - vibrant blue ===
        . '.main-content a:not(.btn):not([class*="btn"]){color:#2563eb!important;transition:color .2s!important}'
        . '.main-content a:not(.btn):not([class*="btn"]):hover{color:#1d4ed8!important}'
        // === MUTED TEXT - warm not gray ===
        . '.text-muted,small,.main-content .text-muted,.main-content small{color:#64748b!important}'
        // === CARDS & PANELS - crisp white with colored shadows ===
        . '.package,.card,.panel,.well{color:#334155!important;background:#fff!important;border:1px solid #e2e8f0!important;box-shadow:0 1px 3px rgba(15,23,42,0.06),0 1px 2px rgba(15,23,42,0.04)!important;border-radius:12px!important}'
        . '.card:hover,.panel:hover,.package:hover{box-shadow:0 4px 12px rgba(37,99,235,0.08),0 2px 4px rgba(15,23,42,0.04)!important}'
        // === ALERTS - keep functional but crisp ===
        . '.alert{color:#334155!important;border-radius:10px!important}'
        // === NEWS / TIMELINE ===
        . '.news-title,.news-title a,.timeline-title,.timeline-title a,.timeline-heading h4,.timeline-heading h5,.ann-title,.ann-title a{color:#0f172a!important;font-weight:700!important}'
        . '.timeline-body,.timeline-body p,.ann-body,.ann-body p,.ann-desc{color:#475569!important}'
        . '.timeline-date,.date-badge,.ann-date{color:#16a34a!important;font-weight:600!important}'
        . '.timeline-panel,.ann-card,.news-item{background:#fff!important;border:1px solid #e2e8f0!important;border-radius:12px!important;box-shadow:0 1px 3px rgba(15,23,42,0.06)!important}'
        // === FOOTER ===
        . '.footer,.footer-content{color:#94a3b8!important}'
        . '.footer a{color:#cbd5e1!important}'
        . '.footer a:hover{color:#16a34a!important}'
        // === NAVIGATION - catch ALL nav links including classless ones ===
        . '.main-menu a,.nav-link,.navbar-nav a,.item-text,.main-menu .item-text,.menu-primary a,.menu a{color:#1e293b!important;font-weight:500!important}'
        . '.main-menu a:hover,.nav-link:hover,.main-menu a:hover .item-text,.menu-primary a:hover,.menu a:hover{color:#16a34a!important}'
        . '.main-menu .active .item-text,.nav-link.active{color:#16a34a!important}'
        // === TOP UTILITY BAR ===
        . '.header-top .item-text,.header-top a,.utility-nav a,.utility-nav .item-text{color:#64748b!important}'
        . '.header-top a:hover,.utility-nav a:hover{color:#16a34a!important}'
        // === PAGE HEADER BANNER - rich gradient ===
        . '.page-head{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#16a34a 150%)!important;padding:28px 0 22px!important}'
        . '.page-head h1{color:#fff!important;font-weight:800!important;font-size:1.4rem!important}'
        . '.page-head .breadcrumbs,.page-head .breadcrumbs a,.page-head .breadcrumb li,.page-head .breadcrumb a{color:rgba(255,255,255,0.8)!important}'
        // === SIDEBAR + LIST GROUPS (store categories, support sidebar) ===
        . '.sidebar a,.sidebar .list-group-item,.sidebar-content,.sidebar-categories a,.sidebar-category a{color:#334155!important}'
        . '.list-group-item,.list-group-item a{color:#334155!important;background:#fff!important;border-color:#e2e8f0!important}'
        . '.list-group-item.active,.list-group-item.active a{background:#f0fdf4!important;border-left:3px solid #16a34a!important;color:#16a34a!important}'
        . '.list-group-item:hover,.list-group-item:hover a{background:#f0fdf4!important;color:#16a34a!important}'
        // === SECTION TITLES ===
        . '.section-title,.section-heading,.widget-title,.panel-heading,.panel-title{color:#0f172a!important;font-weight:700!important}'
        // === PACKAGE / PRODUCT CARDS ===
        . '.package-name{color:#0f172a!important;font-weight:700!important}'
        . '.package-price{color:#16a34a!important;font-weight:800!important}'
        . '.package-desc{color:#475569!important}'
        . '.package .list-group-item{color:#475569!important;background:#fff!important;border-color:#f1f5f9!important}'
        // === MODALS ===
        . '.modal-title{color:#0f172a!important;font-weight:700!important}'
        . '.modal-body,.modal-body p{color:#334155!important}'
        . '.modal-content{border-radius:16px!important;border:none!important;box-shadow:0 20px 60px rgba(15,23,42,0.15)!important}'
        // === FORMS - clean with focus states ===
        . 'label,.control-label,.form-label{color:#1e293b!important;font-weight:600!important}'
        . '.form-control{color:#1e293b!important;background:#fff!important;border:1.5px solid #cbd5e1!important;border-radius:8px!important;transition:border-color .2s,box-shadow .2s!important}'
        . '.form-control:focus{border-color:#2563eb!important;box-shadow:0 0 0 3px rgba(37,99,235,0.1)!important}'
        // === DROPDOWNS ===
        . '.dropdown-menu{background:#fff!important;border:1px solid #e2e8f0!important;border-radius:10px!important;box-shadow:0 8px 24px rgba(15,23,42,0.1)!important}'
        . '.dropdown-menu a,.dropdown-item{color:#334155!important}'
        . '.dropdown-menu a:hover,.dropdown-item:hover{background:#f0fdf4!important;color:#16a34a!important}'
        // === TABLES ===
        . 'table td,table th,.table td,.table th{color:#334155!important}'
        . '.table th{color:#0f172a!important;font-weight:700!important;background:#f8fafc!important}'
        . '.table-striped tbody tr:nth-of-type(odd){background:#f8fafc!important}'
        . '.table-hover tbody tr:hover{background:#f0fdf4!important}'
        // === BUTTONS - vibrant green primary ===
        . '.btn-primary,.btn-success{background:linear-gradient(135deg,#16a34a,#15803d)!important;border:none!important;color:#fff!important;border-radius:8px!important;font-weight:600!important;box-shadow:0 2px 8px rgba(22,163,74,0.25)!important}'
        . '.btn-primary:hover,.btn-success:hover{background:linear-gradient(135deg,#15803d,#166534)!important;box-shadow:0 4px 12px rgba(22,163,74,0.35)!important;transform:translateY(-1px)!important}'
        . '.btn-info{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;border:none!important;color:#fff!important;border-radius:8px!important}'
        . '.btn-default,.btn-secondary{background:#fff!important;color:#334155!important;border:1.5px solid #cbd5e1!important;border-radius:8px!important}'
        . '.btn-default:hover,.btn-secondary:hover{background:#f8fafc!important;border-color:#2563eb!important;color:#2563eb!important}'
        // === BADGES ===
        . '.badge-success,.label-success{background:#16a34a!important}'
        . '.badge-info,.label-info{background:#2563eb!important}'
        . '.badge-warning,.label-warning{background:#f59e0b!important}'
        . '.badge-danger,.label-danger{background:#ef4444!important}'
        // === ORDER SUMMARY / PRICES (white-on-white fix) ===
        . '.order-summary,.summary-box,.cart-sidebar,.sidebar-summary{color:#334155!important}'
        . '.price,.price-amount,.price-total,.price-amount-total{color:#0f172a!important}'
        . '.list-item,.list-item.faded,.order-item{color:#334155!important}'
        . '.list-item .price,.order-item .price{color:#0f172a!important}'
        . '.price-total .price-amount-total{color:#0f172a!important;font-weight:800!important}'
        . '.order-summary h3,.summary-box h3,.cart-sidebar h3{color:#0f172a!important}'
        . '.billing-cycle-label,.price-cycle{color:#475569!important}'
        . '.promo-code,.coupon{color:#334155!important}'
        . '.terms-of-service a,.terms a{color:#2563eb!important}'
        // === HSLA/RGBA OVERRIDES (Futuristic theme uses light HSLA text) ===
        . '[class*="text-heading"]{color:#0f172a!important}'
        . '[class*="text-body"]{color:#334155!important}'
        . '[class*="text-light"]{color:#64748b!important}'
        . '[class*="faded"]:not(.btn-primary-faded){color:#475569!important}'
        // === SELECT/OPTION ELEMENTS ===
        . 'select,select option,select.form-control{color:#1e293b!important;background:#fff!important}'
        . 'option{color:#1e293b!important;background:#fff!important}'
        . '.select2-selection,.select2-selection__rendered{color:#1e293b!important}'
        // === LANGUAGE SELECTOR ===
        . '.lang-selector,.language-selector,#languageChooser,.lang-dropdown,.choose-language{color:#334155!important}'
        . '.lang-selector a,.language-selector a{color:#334155!important}'
        . '.lang-selector .active,.language-selector .active{color:#0f172a!important}'
        // === FORM ELEMENTS (catch-all for ticket form, contact form) ===
        . '.form-group label,.field-label,.form-text{color:#1e293b!important}'
        . 'input,textarea,select{color:#1e293b!important}'
        . '::placeholder{color:#94a3b8!important;opacity:1!important}'
        . 'input::placeholder,textarea::placeholder{color:#94a3b8!important;opacity:1!important}'
        . '.help-block,.form-text{color:#64748b!important}'
        // === PRICING ON STORE PAGES ===
        . '.product-pricing,.pricing-amount,.amount{color:#0f172a!important}'
        . '.billing-cycle,.cycle-option,.billing-option{color:#334155!important}'
        . '.recurring,.one-time{color:#475569!important}'
        // === BREADCRUMBS ===
        . '.breadcrumb li,.breadcrumb a,.breadcrumb-item{color:rgba(255,255,255,0.8)!important}'
        // === RSTHEMES TILE CARDS (How can we help) ===
        . '.tile,.tile-home{background:#fff!important;background-image:none!important;border:1px solid #e2e8f0!important;border-radius:14px!important;box-shadow:0 2px 8px rgba(15,23,42,0.06)!important;color:#334155!important}'
        . '.tile:hover,.tile-home:hover{box-shadow:0 6px 20px rgba(22,163,74,0.1)!important;border-color:#16a34a!important;transform:translateY(-2px)!important}'
        . '.tile-title{color:#0f172a!important;font-weight:700!important}'
        . '.tile .lm,.tile i,.tile svg{color:#16a34a!important}'
        // SVG icons now fixed via --svg-icon-color CSS variables above
        // === RSTHEMES ANNOUNCEMENTS / NEWS LIST ===
        . '.announcements-list,.announcements-list.list-group{background:#fff!important;background-image:none!important;border:1px solid #e2e8f0!important;border-radius:14px!important;box-shadow:0 2px 8px rgba(15,23,42,0.06)!important;overflow:hidden!important}'
        . '.list-group-item-heading{color:#0f172a!important;font-weight:700!important}'
        . '.list-group-item-text,.list-group-item-text p{color:#475569!important}'
        . '.list-group-item-link{background:transparent!important;border-bottom:1px solid #f1f5f9!important}'
        . '.list-group-item-link:hover{background:#f8fafc!important}'
        // === RSTHEMES NAV - dropdown toggles still white ===
        . '.dropdown-toggle,.dropdown-toggle .item-text{color:#1e293b!important}'
        . '.dropdown-toggle:hover,.dropdown-toggle:hover .item-text{color:#16a34a!important}'
        . '.nav-item-cart a,.nav-item-cart .item-text{color:#64748b!important}'
        // === RSTHEMES DATE BADGES in news ===
        . '.list-group-item .date,.ann-date-badge{color:#16a34a!important;font-weight:600!important;font-size:0.8rem!important}'
        // === FOOTER - all text visible on light bg ===
        . 'footer,footer *,.site-footer,.site-footer *,.footer-content,.footer-content *{color:#64748b!important}'
        . 'footer a,.site-footer a,.footer a,.footer-content a{color:#475569!important}'
        . 'footer a:hover,.site-footer a:hover,.footer a:hover{color:#16a34a!important}'
        // Language selector in footer (uses --main-footer-link-color which is near-white)
        . '.footer .dropdown a,.footer .dropup a,.footer [data-toggle="dropdown"],.footer .lang-item a{color:#475569!important}'
        . '.footer .dropdown a:hover,.footer .dropup a:hover{color:#16a34a!important}'
        // === READ MORE BUTTON in news ===
        . '.btn-read-more,.list-group-item .btn{color:#2563eb!important;border-color:#2563eb!important}'
        . '</style>';
    } // END first CSS block

    // Inject FPS-specific CSS on FPS module pages (deterministic cache bust).
    if (isset($_GET['m']) && $_GET['m'] === 'fraud_prevention_suite') {
        $lagomPath = __DIR__ . '/assets/css/fps-lagom2.css';
        $lagomVer  = (defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'v') . '-' . (file_exists($lagomPath) ? (string) filemtime($lagomPath) : '0');
        $output .= '<link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-lagom2.css?v=' . $lagomVer . '">';
    }

    if (false) { // START colorblind CSS block (now in fps-site-theme.css)
    // =========================================================================
    // Accessibility: Comprehensive colorblind-friendly mode
    // Transforms ALL green (#16a34a family) to blue (#2563eb family) site-wide
    // Also: higher contrast text, underlined links, thicker focus rings
    // =========================================================================
    $output .= '<style>'
        // === CSS VARIABLE OVERRIDES ===
        . 'body.cb-mode{'
        . '--primary:#2563eb!important;'
        . '--svg-icon-color-1:#2563eb!important;'
        . '--svg-icon-color-2:#1d4ed8!important;'
        . '}'
        // === HEADER / BANNER / PAGE-HEAD (green gradients -> blue) ===
        . 'body.cb-mode .main-banner,body.cb-mode .banner,body.cb-mode .site-banner,body.cb-mode .banner-home{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%)!important}'
        . 'body.cb-mode .main-header,body.cb-mode header,body.cb-mode .header-bg{background:linear-gradient(90deg,#1e40af,#2563eb)!important}'
        . 'body.cb-mode .page-head{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#2563eb 150%)!important}'
        . 'body.cb-mode .search-box-primary,body.cb-mode .domain-search-section{background:linear-gradient(135deg,#1e40af,#2563eb)!important}'
        // === BUTTONS ===
        . 'body.cb-mode .btn-primary,body.cb-mode .btn-success{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;border-color:#2563eb!important;box-shadow:0 2px 8px rgba(37,99,235,0.25)!important}'
        . 'body.cb-mode .btn-primary:hover,body.cb-mode .btn-success:hover{background:linear-gradient(135deg,#1d4ed8,#1e40af)!important;box-shadow:0 4px 12px rgba(37,99,235,0.35)!important}'
        // === NAV / MENU (all hover + active states) ===
        . 'body.cb-mode .main-menu a:hover,body.cb-mode .nav-link:hover,body.cb-mode .main-menu a:hover .item-text,body.cb-mode .menu-primary a:hover,body.cb-mode .menu a:hover,body.cb-mode .dropdown-toggle:hover,body.cb-mode .dropdown-toggle:hover .item-text{color:#2563eb!important}'
        . 'body.cb-mode .main-menu .active .item-text,body.cb-mode .nav-link.active{color:#2563eb!important}'
        . 'body.cb-mode .header-top a:hover,body.cb-mode .utility-nav a:hover{color:#2563eb!important}'
        // === CART BADGE ===
        . 'body.cb-mode .badge-primary-faded,.cb-mode .nav-badge{color:#2563eb!important;background:rgba(37,99,235,0.1)!important;border-color:#2563eb!important}'
        // === SIDEBAR / LIST-GROUP (active + hover) ===
        . 'body.cb-mode .list-group-item.active,body.cb-mode .list-group-item.active a{border-left-color:#2563eb!important;color:#2563eb!important;background:#eff6ff!important}'
        . 'body.cb-mode .list-group-item:hover,body.cb-mode .list-group-item:hover a{background:#eff6ff!important;color:#2563eb!important}'
        . 'body.cb-mode .sidebar .list-group-item.active{border-left-color:#2563eb!important;color:#2563eb!important;background:#eff6ff!important}'
        . 'body.cb-mode .sidebar .list-group-item:hover{background:#eff6ff!important;color:#2563eb!important}'
        // === TILES (How can we help) ===
        . 'body.cb-mode .tile:hover,body.cb-mode .tile-home:hover{border-color:#2563eb!important;box-shadow:0 6px 20px rgba(37,99,235,0.1)!important}'
        . 'body.cb-mode .tile .lm,body.cb-mode .tile i,body.cb-mode .tile svg{color:#2563eb!important}'
        // === PRICES ===
        . 'body.cb-mode .package-price{color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-tier .price,body.cb-mode .fps-pub-tier .price.free{color:#2563eb!important}'
        . 'body.cb-mode .fps-card .cp{color:#2563eb!important}'
        // === DATES / TIMESTAMPS ===
        . 'body.cb-mode .timeline-date,body.cb-mode .date-badge,body.cb-mode .ann-date,body.cb-mode .list-group-item .date{color:#2563eb!important}'
        // === DROPDOWNS ===
        . 'body.cb-mode .dropdown-menu a:hover,body.cb-mode .dropdown-item:hover{background:#eff6ff!important;color:#2563eb!important}'
        // === TABLES ===
        . 'body.cb-mode .table-hover tbody tr:hover{background:#eff6ff!important}'
        // === BADGES (all states) ===
        . 'body.cb-mode .badge-success,body.cb-mode .label-success{background:#2563eb!important}'
        . 'body.cb-mode .badge-warning,body.cb-mode .label-warning{background:#f59e0b!important;color:#000!important}'
        . 'body.cb-mode .badge-danger,body.cb-mode .label-danger{background:#dc2626!important}'
        . 'body.cb-mode .badge-info,body.cb-mode .label-info{background:#6366f1!important}'
        // === TEXT CONTRAST (higher than normal mode) ===
        . 'body.cb-mode,body.cb-mode .main-content,body.cb-mode .main-content p,body.cb-mode .main-content div{color:#1e293b!important}'
        . 'body.cb-mode h1,body.cb-mode h2,body.cb-mode h3,body.cb-mode h4,body.cb-mode h5,body.cb-mode h6{color:#0a0f1e!important}'
        // CB mode: order summary + prices
        . 'body.cb-mode .price,body.cb-mode .price-amount,body.cb-mode .price-total,body.cb-mode .price-amount-total{color:#0a0f1e!important}'
        . 'body.cb-mode .list-item,body.cb-mode .list-item.faded,body.cb-mode .order-item{color:#1e293b!important}'
        . 'body.cb-mode label,body.cb-mode .form-label,body.cb-mode .control-label{color:#0a0f1e!important}'
        . 'body.cb-mode select,body.cb-mode option{color:#0a0f1e!important}'
        . 'body.cb-mode [class*="text-heading"]{color:#0a0f1e!important}'
        . 'body.cb-mode [class*="faded"]:not(.btn-primary-faded){color:#334155!important}'
        // === FORMS (focus rings) ===
        . 'body.cb-mode .form-control:focus{border-color:#2563eb!important;box-shadow:0 0 0 4px rgba(37,99,235,0.2)!important}'
        . 'body.cb-mode a:focus-visible{outline:3px solid #2563eb!important;outline-offset:2px!important}'
        // === LINKS (underlined for non-color indicator) ===
        . 'body.cb-mode .main-content a:not(.btn):not([class*="btn"]){text-decoration:underline!important;text-underline-offset:2px!important}'
        // === FPS MODULE PAGES ===
        . 'body.cb-mode .fps-pub-nav a.active{background:#2563eb!important;border-color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-nav a:hover{border-color:#2563eb!important;color:#2563eb!important;background:#eff6ff!important}'
        . 'body.cb-mode .fps-pub-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#2563eb 150%)!important}'
        . 'body.cb-mode .fps-pub-cta{background:linear-gradient(135deg,#0f172a,#1e3a5f)!important}'
        . 'body.cb-mode .fps-pub-stat-value.success{color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-feature:hover{border-color:#2563eb!important;box-shadow:0 6px 20px rgba(37,99,235,0.08)!important}'
        . 'body.cb-mode .fps-pub-feature h3 i,body.cb-mode .fps-pub-section h2 i{color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-tier.featured{border-color:#2563eb!important;box-shadow:0 4px 16px rgba(37,99,235,0.1)!important}'
        . 'body.cb-mode .fps-pub-tier .tier-btn.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 14px rgba(37,99,235,0.3)!important}'
        . 'body.cb-mode .fps-pub-tier .tier-btn.outline:hover{border-color:#2563eb!important;color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-provider-badge{background:#eff6ff!important;border-color:#bfdbfe!important;color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-endpoint .method{background:#2563eb!important}'
        . 'body.cb-mode .fps-pub-endpoint .tier-badge{background:#eff6ff!important;color:#2563eb!important;border-color:#bfdbfe!important}'
        . 'body.cb-mode .fps-pub-endpoint:hover{background:#eff6ff!important}'
        . 'body.cb-mode .fps-pub-cta a{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 14px rgba(37,99,235,0.3)!important}'
        // === FPS STORE/LANDING PAGE ===
        . 'body.cb-mode .fps-card.pop{border-color:#2563eb!important;box-shadow:0 0 30px rgba(37,99,235,0.1)!important}'
        . 'body.cb-mode .fps-free .cf a{color:#2563eb!important;border-color:#2563eb!important;background:#eff6ff!important}'
        . 'body.cb-mode .fps-basic .cf a{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 16px rgba(37,99,235,0.3)!important}'
        . 'body.cb-mode .fps-hero .cta-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 20px rgba(37,99,235,0.3)!important}'
        . 'body.cb-mode .fps-stat-bar .sv{background:linear-gradient(135deg,#2563eb,#60a5fa)!important;-webkit-background-clip:text!important}'
        . 'body.cb-mode .fps-fi:hover{border-color:#2563eb!important}'
        . 'body.cb-mode .fps-bottom{background:linear-gradient(135deg,#0f172a,#1e3a5f)!important}'
        . 'body.cb-mode .fps-card .cb ul li::before{color:#2563eb!important}'
        // === TOPOLOGY PAGE (neon green -> blue) ===
        . 'body.cb-mode .topo-live-dot{background:#2563eb!important;box-shadow:0 0 8px rgba(37,99,235,0.6)!important}'
        . 'body.cb-mode .topo-stat-bar-value--green{color:#2563eb!important}'
        . 'body.cb-mode .topo-event-icon--approved{color:#2563eb!important}'
        . 'body.cb-mode .topo-event-tag--low{color:#2563eb!important;border-color:#2563eb!important}'
        . 'body.cb-mode .topo-event-tag--medium{color:#f59e0b!important;border-color:#f59e0b!important}'
        . 'body.cb-mode .topo-event-tag--high,body.cb-mode .topo-event-tag--critical{color:#dc2626!important;border-color:#dc2626!important}'
        . 'body.cb-mode .topo-stat-bar-value--amber{color:#f59e0b!important}'
        . 'body.cb-mode .topo-stat-bar-value--red{color:#dc2626!important}'
        . 'body.cb-mode [class*="topo"] .fa-circle-check{color:#2563eb!important}'
        // Catch-all: any inline green gradient
        . 'body.cb-mode [style*="linear-gradient"][style*="#16a34a"],body.cb-mode [style*="linear-gradient"][style*="#15803d"],body.cb-mode [style*="linear-gradient"][style*="#25a75b"]{filter:hue-rotate(210deg) saturate(1.2)!important}'
        // === HOMEPAGE "Free" PRICE + PRODUCT CARD ICONS ===
        . 'body.cb-mode [style*="color:#16a34a"],body.cb-mode [style*="color: #16a34a"]{color:#2563eb!important}'
        . 'body.cb-mode [style*="color:#15803d"],body.cb-mode [style*="color: #15803d"]{color:#1d4ed8!important}'
        // === FPS OVERVIEW: green check icons -> blue ===
        . 'body.cb-mode .fps-pub i.check,body.cb-mode .fps-pub .fa-check,body.cb-mode .fps-pub i.fa-check{color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-tier ul li i.check{color:#2563eb!important}'
        . 'body.cb-mode .fps-pub-stat-label{color:#64748b!important}'
        // === CHECKOUT: validate code btn, radio, password strength ===
        . 'body.cb-mode .btn-primary-faded{color:#2563eb!important;border-color:#2563eb!important;background:rgba(37,99,235,0.08)!important}'
        . 'body.cb-mode .btn-primary-faded:hover{background:rgba(37,99,235,0.15)!important}'
        . 'body.cb-mode .radio-styled.checked,body.cb-mode .radio-styled:checked{border-color:#2563eb!important;box-shadow:0 0 0 3px rgba(37,99,235,0.15)!important}'
        . 'body.cb-mode .radio-styled.checked::after,body.cb-mode .radio-styled:checked::after{background:#2563eb!important}'
        . 'body.cb-mode input[type="radio"]:checked+label,body.cb-mode input[type="radio"]:checked~label{color:#2563eb!important}'
        . 'body.cb-mode .progress-bar-success,body.cb-mode .progress-bar.bg-success{background:#2563eb!important}'
        . 'body.cb-mode .password-strength-bar .progress-bar,body.cb-mode .pw-strength .bar{background:#2563eb!important}'
        // Check/tick icons everywhere
        . 'body.cb-mode .fa-check-circle,body.cb-mode .fa-circle-check{color:#2563eb!important}'
        . 'body.cb-mode i[style*="color:#16a34a"],body.cb-mode i[style*="color: #16a34a"],body.cb-mode i[style*="color:#25a75b"],body.cb-mode i[style*="color: rgb(37, 167, 91)"]{color:#2563eb!important}'
        // === FOOTER ===
        . 'body.cb-mode footer a:hover,body.cb-mode .site-footer a:hover{color:#2563eb!important}'
        // === TOGGLE BUTTON STYLING ===
        . '.fps-cb-toggle{position:fixed;bottom:20px;left:20px;z-index:9999;width:48px;height:48px;border-radius:50%;border:2px solid #cbd5e1;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.1);transition:all 0.3s;font-size:1.2rem;}'
        . '.fps-cb-toggle:hover{box-shadow:0 6px 20px rgba(0,0,0,0.15);transform:scale(1.05);}'
        . '.fps-cb-toggle[aria-pressed="true"]{background:#2563eb;border-color:#2563eb;color:#fff;}'
        . '.fps-cb-toggle .fps-cb-tooltip{display:none;position:absolute;bottom:56px;left:0;background:#0f172a;color:#fff;padding:6px 12px;border-radius:8px;font-size:0.75rem;white-space:nowrap;pointer-events:none;}'
        . '.fps-cb-toggle:hover .fps-cb-tooltip{display:block;}'
        . '</style>';
    } // END OF COMMENTED-OUT INLINE CSS

    // Accessibility toggle button + JS (applies to cb-mode class on body)
    $output .= '<button class="fps-cb-toggle" id="fps-cb-btn" aria-pressed="false" aria-label="Toggle colorblind-friendly mode" title="Colorblind-friendly mode">'
        . '<span style="font-size:1.1rem;">&#128065;</span>'
        . '<span class="fps-cb-tooltip">Colorblind-Friendly Mode</span>'
        . '</button>'
        . '<script>'
        . '(function(){'
        . 'var btn=document.getElementById("fps-cb-btn");'
        . 'if(!btn)return;'
        . 'var active=localStorage.getItem("fps-cb-mode")==="1";'
        . 'if(active){document.body.classList.add("cb-mode");btn.setAttribute("aria-pressed","true");}'
        . 'btn.addEventListener("click",function(){'
        . 'active=!active;'
        . 'document.body.classList.toggle("cb-mode",active);'
        . 'btn.setAttribute("aria-pressed",active?"true":"false");'
        . 'localStorage.setItem("fps-cb-mode",active?"1":"0");'
        . '});'
        . '})();'
        . '</script>';

    // Optional site-wide UI tweaks (audit issue #13: these are unrelated to the
    // core fraud-prevention mission and are gated behind admin-visible feature
    // flags so operators can opt out without code changes).
    //
    // Defaults: currently 'on' to preserve behaviour of the live install that
    // already relies on these. Operators can toggle them under Settings ->
    // Site Theme Extras once Batch 8 of the TabSettings work is rolled out.
    try {
        $flags = Capsule::table('mod_fps_settings')
            ->whereIn('setting_key', ['hide_invoice_extensions', 'redirect_chat_now'])
            ->pluck('setting_value', 'setting_key')
            ->toArray();
        $hideInvExt    = ($flags['hide_invoice_extensions'] ?? '1') === '1';
        $redirectChat  = ($flags['redirect_chat_now']       ?? '1') === '1';
    } catch (\Throwable $e) {
        $hideInvExt = $redirectChat = true; // safe defaults preserve behaviour
    }

    if ($hideInvExt) {
        $output .= '<style>'
            . 'a[href*="invoiceextension"]{display:none!important}'
            . 'li:has(>a[href*="invoiceextension"]){display:none!important}'
            . '.nav-item-addon-invoiceextension{display:none!important}'
            . '</style>';
    }

    if ($hideInvExt || $redirectChat) {
        $js = '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'document.querySelectorAll("a").forEach(function(a){';
        if ($hideInvExt) {
            $js .= 'if((a.textContent||"").trim()==="Invoice Extensions"){'
                .   'var p=a.closest("li")||a.parentElement;if(p)p.style.display="none";}';
        }
        if ($redirectChat) {
            $js .= 'if((a.textContent||"").trim()==="Chat Now"){'
                .   'a.href="/submitticket.php";a.removeAttribute("onclick");}';
        }
        $js .= '});});</script>';
        $output .= $js;
    }


    // ---------- FPS Analytics (client) ----------
    if (class_exists('FpsAnalyticsInjector') && FpsAnalyticsConfig::isClientEnabled()) {
        try {
            $visitorCountry = '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($ip !== '' && class_exists('\\FraudPreventionSuite\\Lib\\FpsHookHelpers')) {
                $visitorCountry = (string) (\FraudPreventionSuite\Lib\FpsHookHelpers::fps_lookupCountryByIp($ip) ?? '');
            }
            $context = [
                'fps_country'             => $visitorCountry,
                'fps_module_version'      => defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown',
                'fps_is_returning_client' => isset($_SESSION['uid']) ? 1 : 0,
            ];
            $modVer      = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
            $bannerJsUrl = '/modules/addons/fraud_prevention_suite/assets/js/fps-consent-banner.js?v=' . urlencode($modVer);
            $debugJsTag  = (isset($_GET['fps_analytics_debug']) && $_GET['fps_analytics_debug'] === '1')
                ? '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-analytics-debug.js"></script>'
                : '';
            $output .= FpsAnalyticsInjector::client($visitorCountry, $context)
                . "<script defer src=\"{$bannerJsUrl}\"></script>\n"
                . $debugJsTag;
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AnalyticsInject::ClientErr', '', $e->getMessage());
        }
    }

    return $output;
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 7. ClientAreaFooterOutput -- Inject fingerprint JS (scope-aware)
// ---------------------------------------------------------------------------
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'fingerprint_enabled')
            ->value('setting_value');

        if ($enabled !== '1') return '';

        // Only inject when scope is 'all' (every client-area page).
        // When scope is 'checkout', ClientAreaPageCart (hook 6) handles it
        // so this hook stays silent to avoid double-injecting on cart pages.
        $scope = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'fingerprint_scope')
            ->value('setting_value') ?? 'all';

        if ($scope !== 'all') return '';

        $bust = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_assetCacheBust('js/fps-fingerprint.js');
        return '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-fingerprint.js' . $bust . '" defer></script>';
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 7b. ClientAreaFooterOutput -- Inject Turnstile widgets into protected forms
// ---------------------------------------------------------------------------
add_hook('ClientAreaFooterOutput', 2, function ($vars) {
    try {
        $libDir = __DIR__ . '/lib/';
        // Require classes if not yet loaded
        if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsConfig')) {
            if (file_exists($libDir . 'FpsConfig.php')) {
                require_once $libDir . 'FpsConfig.php';
            } else {
                return '';
            }
        }
        if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsTurnstileValidator')) {
            if (file_exists($libDir . 'FpsTurnstileValidator.php')) {
                require_once $libDir . 'FpsTurnstileValidator.php';
            } else {
                return '';
            }
        }

        $validator = new \FraudPreventionSuite\Lib\FpsTurnstileValidator();
        return $validator->getInjectionScript();
    } catch (\Throwable $e) {
        return '';
    }
});

// ---------------------------------------------------------------------------
// 7c. ClientAreaFooterOutput -- Inject Featured Products on Homepage
// ---------------------------------------------------------------------------
add_hook('ClientAreaFooterOutput', 3, function ($vars) {
    try {
        // Feature flag (audit issue #13): this is site-merchandising content,
        // not fraud prevention. Gated behind an admin-visible toggle so operators
        // can disable without code changes. Default 'on' to preserve behaviour
        // of the existing live install.
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'enable_featured_products')
            ->value('setting_value') ?? '1';
        if ($enabled !== '1') {
            return '';
        }

        // Only inject on homepage
        // WHMCS passes 'filename' without extension, Lagom2 homepage = 'homepage'
        $filename = $vars['filename'] ?? '';
        $tpl = $vars['templatefile'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Homepage detection: check filename, templatefile, or URI
        $isHomepage = ($filename === 'homepage' || $tpl === 'homepage'
            || $uri === '/' || $uri === '/index.php' || $uri === '');
        if (!$isHomepage) {
            return '';
        }

        // Fetch visible product groups with products
        $groups = Capsule::table('tblproductgroups')
            ->where('hidden', 0)
            ->orderBy('order')
            ->get();

        if ($groups->isEmpty()) {
            return '';
        }

        // Resolve the WHMCS default currency once for all pricing lookups
        // below (audit issue: previously hardcoded to currency=1 which broke
        // installs whose default currency is not id 1).
        $defaultCurrency = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_resolveDefaultCurrencyId();

        $html = '<script>(function(){';
        // Find the News section (2nd .section on homepage) or fallback to last .section
        $html .= 'var sections = document.querySelectorAll(".main-body .section");';
        $html .= 'var target = null;';
        $html .= 'for(var i=0;i<sections.length;i++){';
        $html .= '  if(sections[i].querySelector(".announcements-list,.list-group")){target=sections[i];break;}';
        $html .= '}';
        $html .= 'if(!target && sections.length > 0) target = sections[sections.length-1];';
        $html .= 'if(!target) return;';

        // Build the products HTML
        $cardsHtml = '<div class="section" style="margin-bottom:32px;">'
            . '<div class="section-header"><h2 class="section-title" style="color:#0f172a;font-weight:800;">'
            . '<i class="fas fa-boxes-stacked" style="color:#16a34a;margin-right:8px;"></i>Our Products & Services</h2></div>'
            . '<div class="section-body">'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">';

        foreach ($groups as $group) {
            $products = Capsule::table('tblproducts')
                ->where('gid', $group->id)
                ->where('retired', 0)
                ->where('hidden', 0)
                ->orderBy('order')
                ->limit(3)
                ->get();

            if ($products->isEmpty()) continue;

            $startingPrice = '';
            foreach ($products as $p) {
                $price = Capsule::table('tblpricing')
                    ->where('type', 'product')
                    ->where('relid', $p->id)
                    ->where('currency', $defaultCurrency)
                    ->first();
                if ($price && $price->monthly > 0) {
                    $startingPrice = '$' . number_format((float)$price->monthly, 2) . '/mo';
                    break;
                } elseif ($price && $price->monthly == 0) {
                    $startingPrice = 'Free';
                    break;
                }
            }

            $groupSlug = Capsule::table('tblproducts_slugs')
                ->where('group_id', $group->id)
                ->value('group_slug');
            $storeUrl = $groupSlug ? "store/{$groupSlug}" : "cart.php";
            $productCount = $products->count();
            $escapedName = htmlspecialchars($group->name, ENT_QUOTES);
            $escapedTagline = htmlspecialchars($group->tagline ?? '', ENT_QUOTES);
            $icon = 'fa-server';
            if (stripos($group->name, 'hosting') !== false) $icon = 'fa-hard-drive';
            if (stripos($group->name, 'vps') !== false) $icon = 'fa-server';
            if (stripos($group->name, 'fraud') !== false || stripos($group->name, 'api') !== false) $icon = 'fa-shield-halved';
            if (stripos($group->name, 'ssl') !== false) $icon = 'fa-lock';
            if (stripos($group->name, 'domain') !== false) $icon = 'fa-globe';

            $cardsHtml .= '<a href="' . $storeUrl . '" style="'
                . 'display:block;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px 24px;'
                . 'text-decoration:none;transition:all 0.25s;box-shadow:0 2px 8px rgba(15,23,42,0.04);'
                . '" onmouseover="this.style.borderColor=\'#16a34a\';this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 8px 24px rgba(22,163,74,0.1)\'"'
                . ' onmouseout="this.style.borderColor=\'#e2e8f0\';this.style.transform=\'none\';this.style.boxShadow=\'0 2px 8px rgba(15,23,42,0.04)\'">'
                . '<div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">'
                . '<div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#16a34a,#15803d);display:flex;align-items:center;justify-content:center;">'
                . '<i class="fas ' . $icon . '" style="color:#fff;font-size:1.1rem;"></i></div>'
                . '<div><div style="font-size:1.1rem;font-weight:800;color:#0f172a;">' . $escapedName . '</div>'
                . '<div style="font-size:0.78rem;color:#64748b;">' . $productCount . ' plan' . ($productCount > 1 ? 's' : '') . ' available</div></div></div>';

            if ($escapedTagline) {
                $cardsHtml .= '<p style="font-size:0.88rem;color:#475569;margin:0 0 14px;line-height:1.5;">' . $escapedTagline . '</p>';
            }

            if ($startingPrice) {
                $cardsHtml .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">'
                    . '<span style="font-size:0.82rem;color:#64748b;">Starting at</span>'
                    . '<span style="font-size:1.1rem;font-weight:800;color:#16a34a;">' . $startingPrice . '</span></div>';
            }

            // Add "Learn More" link for FPS product (links to module overview page)
            if (stripos($group->name, 'fraud') !== false || stripos($group->name, 'intelligence') !== false) {
                $cardsHtml .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;text-align:center;">'
                    . '<span onclick="event.preventDefault();event.stopPropagation();window.location.href=\'index.php?m=fraud_prevention_suite\';" style="font-size:0.82rem;font-weight:700;color:#2563eb;cursor:pointer;">'
                    . '<i class="fas fa-chart-line" style="margin-right:4px;"></i>Live Stats & Detection Engines</span></div>';
            }

            $cardsHtml .= '</a>';
        }

        $cardsHtml .= '</div></div></div>';

        // Escape for JS insertion
        $jsHtml = str_replace(["'", "\n", "\r"], ["\\'", '', ''], $cardsHtml);

        $html .= "var div = document.createElement('div');";
        $html .= "div.innerHTML = '" . $jsHtml . "';";
        $html .= "target.parentNode.insertBefore(div.firstChild, target);";
        $html .= '})();</script>';

        return $html;
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

            $highRiskThreshold = (float) FpsAnalyticsConfig::get('analytics_high_risk_signup_threshold', '80');
            if ($result->risk->score >= $highRiskThreshold && class_exists('FpsAnalyticsServerEvents')) {
                try {
                    FpsAnalyticsServerEvents::send('high_risk_signup', [
                        'risk_score'   => $result->risk->score,
                        'country'      => $vars['country'] ?? '',
                        'email_domain' => substr(strrchr((string) ($vars['email'] ?? ''), '@') ?: '', 1),
                    ]);
                } catch (\Throwable $analyticsEx) {
                    logModuleCall('fraud_prevention_suite', 'analytics::high_risk_signup', $vars, $analyticsEx->getMessage());
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
// Hook: ClientDelete
// Fires when a WHMCS admin deletes a client directly via the admin panel
// (i.e. NOT through the FPS module purge path). Marks all associated
// mod_fps_checks rows as reviewed/archived so they don't remain in the queue.
// ---------------------------------------------------------------------------
add_hook('ClientDelete', 1, function (array $vars) {
    try {
        $clientId = (int)($vars['userid'] ?? 0);
        if ($clientId < 1) {
            return;
        }

        $pending = \WHMCS\Database\Capsule::table('mod_fps_checks')
            ->where('client_id', $clientId)
            ->whereNull('reviewed_by')
            ->count();

        if ($pending < 1) {
            return; // Nothing to clean up
        }

        // Snapshot client data -- WHMCS still has the row at hook-fire time
        $snapshot = 'Deleted Client #' . $clientId;
        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first(['firstname', 'lastname', 'email']);
            if ($client) {
                $name = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
                $snapshot = ($name !== '' ? $name : 'Client #' . $clientId)
                    . ' <' . ($client->email ?? '') . '>';
            }
        } catch (\Throwable $e) {
            // Snapshot is best-effort
        }

        \WHMCS\Database\Capsule::table('mod_fps_checks')
            ->where('client_id', $clientId)
            ->whereNull('reviewed_by')
            ->update([
                'action_taken'  => \WHMCS\Database\Capsule::raw(
                    "CONCAT(COALESCE(action_taken,''), ' [client_deleted]')"
                ),
                'reviewed_by'   => 0,   // 0 = system auto-review
                'reviewed_at'   => date('Y-m-d H:i:s'),
                'check_context' => json_encode([
                    'deleted_at'      => date('Y-m-d H:i:s'),
                    'deleted_via'     => 'whmcs_admin_panel',
                    'original_client' => $snapshot,
                ]),
            ]);

        logActivity(
            "Fraud Prevention: Auto-archived {$pending} check(s) for deleted client "
            . "#{$clientId} ({$snapshot})"
        );
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'ClientDelete_hook', $vars, $e->getMessage());
    }
});

// ---------------------------------------------------------------------------
// NOTE: Helper functions (fps_recordGeoEvent, fps_refreshDisposableDomains,
// fps_autoSuspendChargebackAbusers, fps_legacyFraudCheck) are in
// lib/FpsHookHelpers.php as static methods. Called via:
//   \FraudPreventionSuite\Lib\FpsHookHelpers::methodName()
// ---------------------------------------------------------------------------

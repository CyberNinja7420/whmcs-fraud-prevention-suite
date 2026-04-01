# WHMCS Hook Reference

## Overview

FPS integrates with WHMCS via 12 hooks, each firing at critical points in the order/registration lifecycle. All hooks are registered with Priority 1 (highest).

## Hook Reference

### 1. AdminAreaPage

**Priority**: 1
**Fires**: Admin dashboard loads
**Purpose**: Reserved for future dashboard widget injection

**Handler**:
```php
add_hook('AdminAreaPage', 1, function ($vars) {
    // Currently returns empty array
    return [];
});
```

**When It's Called**:
- Admin navigates to any admin page
- Used to inject admin sidebar widgets (future use)

**What You Can Do**:
- Add admin dashboard KPI cards
- Inject alert notifications
- Add quick-action buttons

---

### 2. AdminAreaHeaderOutput

**Priority**: 1
**Fires**: Admin page header renders
**Purpose**: Inject CSS on FPS module pages

**Handler**:
```php
add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    // Inject fps-1000x.css only on FPS pages
    if (strpos($_SERVER['SCRIPT_NAME'], 'configaddonmods') !== false
        && $_GET['module'] === 'fraud_prevention_suite') {
        return '<link rel="stylesheet" href="...fps-1000x.css">';
    }
    return '';
});
```

**When It's Called**:
- Admin navigates to module configuration page
- Admin views addon modules list

**What Gets Injected**:
- `fps-1000x.css` - Design system (dark/light mode, typography)

**Return Value**: HTML string (empty if not applicable)

---

### 3. AdminAreaFooterOutput

**Priority**: 2
**Fires**: Admin page footer renders
**Purpose**: Inject bot user purge toolbar on WHMCS Users page

**Handler**:
```php
add_hook('AdminAreaFooterOutput', 2, function ($vars) {
    // Check if page is /admin/user/list
    if (strpos($_SERVER['REQUEST_URI'], '/user/list') === false) {
        return '';
    }

    // Check if feature enabled
    $enabled = Capsule::table('mod_fps_settings')
        ->where('setting_key', 'user_purge_on_users_page')
        ->value('setting_value');
    if ($enabled !== '1') return '';

    // Inject toolbar HTML + JavaScript
    return $toolbar_html;
});
```

**When It's Called**:
- Admin visits WHMCS Users page (`/admin/user/list`)
- Footer section renders

**What Gets Injected**:
- FPS User toolbar (scan + purge buttons)
- JavaScript for bot user detection and purge

**Data Received in $vars**:
- `filename`: Current admin page name
- `pagetitle`: Page title

---

### 4. ShoppingCartValidateCheckout

**Priority**: 1
**Fires**: Checkout button clicked (pre-payment)
**Purpose**: Pre-checkout fraud blocking (< 2 seconds)

**Handler**:
```php
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    // Turnstile validation
    // IP Intel + Email Validation + Fingerprint checks
    // Custom rules
    // Return error array to block checkout
    return [];  // Empty = proceed, array = error messages
});
```

**When It's Called**:
- Client clicks "Proceed to Checkout" button
- Before payment gateway loads

**What Data is Available**:
- `$_SESSION['uid']` - Logged-in client ID
- `$_SESSION['paymentmethod']` - Selected gateway
- `$_POST['fps_fingerprint']` - Device fingerprint (if enabled)
- `$_SERVER['REMOTE_ADDR']` - Client IP

**Checks Performed**:
1. Turnstile validation (if enabled)
2. IP Intel lookup (cached)
3. Email validation (local checks)
4. Fingerprint matching (local DB)
5. Custom rules evaluation
6. Velocity check
7. Tor/Datacenter detection

**Return Value**:
- `[]` (empty array) = Allow checkout to proceed
- `['Error message', ...]` = Block checkout and show error

**Return Example** (block checkout):
```php
return ['We were unable to process your order. Please contact support. Reference: FPS-260401'];
```

**Score Behavior**:
- Pre-checkout threshold (default 85) triggers block
- Lower threshold (60) triggers Turnstile challenge if pre-checkout flag enabled
- All checks logged to `mod_fps_checks` with `is_pre_checkout = 1`

---

### 5. AfterShoppingCartCheckout

**Priority**: 1
**Fires**: Order created (post-payment)
**Purpose**: Full fraud check with all 16 providers

**Handler**:
```php
add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    // Run full FpsCheckRunner::runFullCheck()
    // Check all 16 providers
    // Evaluate Global Intel, Velocity, Geo-Impossibility, Behavioral
    // Auto-lock if critical
    // Send webhooks/emails
});
```

**When It's Called**:
- Payment processed successfully
- Order created in WHMCS

**Data Received in $vars**:
- `OrderID` - New WHMCS order ID
- May contain invoice and service data

**Checks Performed**:
1. All 16 fraud providers
2. Global Intel hub lookup
3. Velocity engine (5 dimensions)
4. Geo-impossibility cross-correlation
5. Behavioral biometrics
6. Custom rules
7. Risk aggregation

**Record Created**:
- Single row in `mod_fps_checks` with:
  - `order_id` = OrderID
  - `check_type` = 'auto'
  - `provider_scores` = JSON breakdown
  - `action_taken` = auto-locked or allowed

**Side Effects**:
- Order locked to "Fraud" status if critical
- Email alert sent if configured
- Webhook notifications dispatched
- Geo event recorded for topology
- Provider scores logged
- Statistics updated

---

### 6. AcceptOrder

**Priority**: 1
**Fires**: Admin accepts/unlocks fraud order
**Purpose**: Verify fraud status before acceptance

**Handler**:
```php
add_hook('AcceptOrder', 1, function ($vars) {
    $orderId = (int)($vars['orderid'] ?? 0);

    // Check if order was locked for fraud
    $check = Capsule::table('mod_fps_checks')
        ->where('order_id', $orderId)
        ->where('locked', 1)
        ->first();

    // Log warning if accepting unreviewed locked order
    if ($check && !$check->reviewed_by) {
        logActivity("FPS: WARNING - Accepting locked order (not yet reviewed)");
    }
});
```

**When It's Called**:
- Admin clicks "Accept" button on order details
- Admin manually reviews and approves locked order

**Data Received in $vars**:
- `orderid` - Order being accepted

**Purpose**:
- Warn admin if accepting order locked by FPS (not manually reviewed)
- Track manual overrides

**No Blocking**: This hook cannot block order acceptance; it only logs warnings.

---

### 7. ClientAreaPageCart

**Priority**: 1
**Fires**: Cart page loads
**Purpose**: Inject fingerprint collector JavaScript

**Handler**:
```php
add_hook('ClientAreaPageCart', 1, function ($vars) {
    $enabled = Capsule::table('mod_fps_settings')
        ->where('setting_key', 'fingerprint_enabled')
        ->value('setting_value');

    if ($enabled !== '1') return [];

    return [
        'fps_fingerprint_js' => '<script src=".../fps-fingerprint.js" defer></script>',
    ];
});
```

**When It's Called**:
- Client views shopping cart
- Checkout page loads

**What Gets Injected**:
- `fps-fingerprint.js` - Collects canvas, WebGL, fonts, screen data
- Data stored in hidden form field
- Submitted with checkout

**Return Value**: Array of template variables

---

### 8. ClientAreaHeaderOutput

**Priority**: 1
**Fires**: Client area header renders
**Purpose**: Inject Turnstile API script

**Handler**:
```php
add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    // Check if Turnstile enabled
    $turnstileEnabled = Capsule::table('mod_fps_settings')
        ->where('setting_key', 'turnstile_enabled')
        ->value('setting_value');

    if ($turnstileEnabled !== '1') return '';

    // Return Cloudflare Turnstile API script
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>';
});
```

**When It's Called**:
- Client area page renders (including checkout)

**What Gets Injected**:
- Cloudflare Turnstile API library (`/v0/api.js`)
- Loaded asynchronously

**Return Value**: HTML string

---

### 9. ClientAreaFooterOutput

**Priority**: 1
**Fires**: Client area footer renders
**Purpose**: Inject fingerprint JS and Turnstile widget

**Handler**:
```php
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    // Only on cart/checkout pages
    $filename = $vars['filename'] ?? '';
    if (!in_array($filename, ['cart', 'clientarea'])) {
        return '';
    }

    // Inject fingerprint.js
    return '<script src=".../fps-fingerprint.js" defer></script>';
});
```

**When It's Called**:
- Client area footer renders
- Checkout page footer loads

**What Gets Injected**:
- `fps-fingerprint.js` - Final device fingerprinting code
- Fingerprint data submission
- Turnstile widget initialization

---

### 10. ClientAdd

**Priority**: 1
**Fires**: New client registration
**Purpose**: Fraud check on new signups

**Handler**:
```php
add_hook('ClientAdd', 1, function ($vars) {
    $clientId = (int)($vars['userid'] ?? 0);

    // Build check context from new client
    $context = FpsCheckContext::fromClientId($clientId, 'registration');

    // Run pre-checkout checks (quick)
    $runner = new FpsCheckRunner();
    $result = $runner->runPreCheckout($context);

    // Log if high risk
    if ($result->risk->score >= 80) {
        logActivity("FPS: High-risk signup for client #{$clientId}");
    }
});
```

**When It's Called**:
- New client account created
- Client self-registers via WHMCS portal

**Data Received in $vars**:
- `userid` - New client ID
- May contain other client data

**Checks Performed**:
- Pre-checkout checks (fast providers only)

**Side Effects**:
- Check record created in `mod_fps_checks` (if pre-checkout enabled)
- Activity log entry if high risk
- No blocking (registration already complete)

---

### 11. ClientAreaPage

**Priority**: 1
**Fires**: Client area page renders
**Purpose**: Route API requests to REST API handler

**Handler**:
```php
add_hook('ClientAreaPage', 1, function ($vars) {
    // Check if module is FPS and API is requested
    if ($_GET['m'] === 'fraud_prevention_suite' && isset($_GET['api'])) {
        // Route to FpsApiRouter
        $router = new FpsApiRouter();
        $router->handle();
        exit;  // Exit after handling
    }
    return [];
});
```

**When It's Called**:
- Public API request received
- URL includes `?m=fraud_prevention_suite&api=...`

**Purpose**:
- Route public REST API calls to FPS API handler
- Bypass normal WHMCS output

**Exit Behavior**: Hook exits after handling API request (doesn't return to normal page flow)

---

### 12. DailyCronJob

**Priority**: 1
**Fires**: WHMCS daily cron task (once per day)
**Purpose**: Maintenance, cache cleanup, statistics aggregation, list refreshes

**Handler**:
```php
add_hook('DailyCronJob', 1, function ($vars) {
    // 1. Purge expired caches
    //    - Expired IP intel (24hr TTL)
    //    - Expired email intel (24hr TTL)
    //    - Old geo events (90 days)
    //    - Old API logs (30 days)

    // 2. Aggregate stats
    //    - Calculate unique IPs yesterday
    //    - Top countries
    //    - Average risk score

    // 3. Refresh lists
    //    - Tor exit nodes (via list provider)
    //    - Datacenter ASNs
    //    - Disposable domains (if auto-update enabled)

    // 4. Maintenance
    //    - Purge old velocity events (7 days)
    //    - Purge old behavioral events (30 days)
    //    - Purge old webhook logs (30 days)

    // 5. Adaptive scoring
    //    - Monthly: Reweight providers based on false positive rate

    // 6. Auto-suspend
    //    - Suspend clients with excessive chargebacks

    // 7. Global Intel
    //    - Auto-push intel to hub (if enabled)
    //    - Purge expired global intel
});
```

**When It's Called**:
- Daily cron job runs (timing controlled by WHMCS)
- Typically once per day at configured time

**Operations Performed**:

| Operation | Table | Action |
|-----------|-------|--------|
| Cache purge | mod_fps_ip_intel | DELETE WHERE expires_at < NOW() |
| Cache purge | mod_fps_email_intel | DELETE WHERE expires_at < NOW() |
| Cache purge | mod_fps_geo_events | DELETE WHERE created_at < 90 days ago |
| Cache purge | mod_fps_api_logs | DELETE WHERE created_at < 30 days ago |
| Cache purge | mod_fps_rate_limits | DELETE WHERE last_refill_at < 1 day ago |
| Cache purge | mod_fps_velocity_events | DELETE WHERE created_at < 7 days ago |
| Cache purge | mod_fps_behavioral_events | DELETE WHERE created_at < 30 days ago |
| Cache purge | mod_fps_webhook_log | DELETE WHERE created_at < 30 days ago |
| Stats | mod_fps_stats | UPDATE with yesterday's aggregations |
| List refresh | mod_fps_tor_nodes | Fetch latest Tor exit list |
| List refresh | mod_fps_datacenter_asns | Fetch latest datacenter ASN list |
| Disposable | data/disposable_domains.txt | Auto-update if enabled |
| ML | mod_fps_weight_history | Monthly: Analyze precision, reweight providers |
| Webhooks | SENT | Auto-suspend chargebacks |
| Global Intel | mod_fps_global_intel | DELETE WHERE expires_at < NOW() |
| Global Intel | HUB | POST intel/push if auto-push enabled |

**No Return Value**: Cron hooks don't return values

---

### 13. InvoiceRefunded

**Priority**: 1
**Fires**: Invoice marked refunded
**Purpose**: Track refund abuse patterns

**Handler**:
```php
add_hook('InvoiceRefunded', 1, function ($vars) {
    $invoiceId = (int)($vars['invoiceid'] ?? 0);

    // Insert refund tracking record
    Capsule::table('mod_fps_refund_tracking')->insert([
        'client_id' => $clientId,
        'invoice_id' => $invoiceId,
        'amount' => $amount,
        'refund_date' => date('Y-m-d H:i:s'),
    ]);

    // Check for abuse pattern
    // Count refunds in window (default 90 days, threshold 3)
    $refundCount = Capsule::table('mod_fps_refund_tracking')
        ->where('client_id', $clientId)
        ->where('refund_date', '>=', date('Y-m-d', strtotime('-90 days')))
        ->count();

    if ($refundCount >= 3) {
        logActivity("FPS: Refund abuse detected: {$refundCount} refunds");
    }
});
```

**When It's Called**:
- Admin marks invoice as refunded
- Refund processed (depending on WHMCS version)

**Data Received in $vars**:
- `invoiceid` - Invoice being refunded

**Records Created**:
- Row in `mod_fps_refund_tracking` with amount and date

**Abuse Detection**:
- Counts refunds in configurable window (default 90 days)
- If count >= threshold (default 3), logs warning
- Threshold and window configured in Settings

---

### 14. InvoiceUnpaid

**Priority**: 1
**Fires**: Invoice marked unpaid/disputed (chargeback)
**Purpose**: Track chargebacks and correlate with fraud scores

**Handler**:
```php
add_hook('InvoiceUnpaid', 1, function ($vars) {
    // Check if chargeback tracking enabled
    $enabled = Capsule::table('mod_fps_settings')
        ->where('setting_key', 'chargeback_tracking_enabled')
        ->value('setting_value');

    // Get original fraud check from order time
    $originalCheck = Capsule::table('mod_fps_checks')
        ->where('order_id', $orderId)
        ->orderByDesc('created_at')
        ->first();

    // Insert chargeback record WITH original fraud score
    Capsule::table('mod_fps_chargebacks')->insert([
        'client_id' => $clientId,
        'invoice_id' => $invoiceId,
        'amount' => $amount,
        'fraud_score_at_order' => $originalCheck->risk_score,
        'risk_level_at_order' => $originalCheck->risk_level,
        'provider_scores_at_order' => $originalCheck->provider_scores,
        'chargeback_date' => date('Y-m-d H:i:s'),
    ]);

    // Send webhook alert
    $webhookNotifier = new FpsWebhookNotifier();
    $webhookNotifier->sendFraudAlert(
        'high',
        $orderId,
        $clientId,
        $originalCheck->risk_score ?? 0,
        ['Chargeback detected']
    );
});
```

**When It's Called**:
- Invoice status changed to unpaid/disputed
- Chargeback/dispute reported

**Data Received in $vars**:
- `invoiceid` - Invoice being disputed

**Records Created**:
- Row in `mod_fps_chargebacks` with:
  - Original fraud score from order time
  - Original risk level
  - Original provider breakdown

**Side Effects**:
- Webhook alert sent if configured (high severity)
- Activity log entry
- Chargeback recorded with historic fraud data

**Analysis Use Case**: Review `mod_fps_chargebacks` table to see if FPS correctly identified chargebacks earlier. High fraud score at order time validates FPS detection.

---

## Hook Execution Timeline

```
SIGNUP / REGISTRATION
    |
    v
ClientAdd (Priority 1)
    |-> Pre-checkout checks
    |-> Log if high-risk

    v
CLIENT BROWSES CATALOG
    |
    v
(No hooks)

    v
CLIENT ADDS TO CART
    |
    v
ClientAreaPageCart (Priority 1)
    |-> Inject fingerprint.js

    v
CLIENT PROCEEDS TO CHECKOUT
    |
    v
ShoppingCartValidateCheckout (Priority 1)
    |-> Turnstile challenge
    |-> Fast provider checks
    |-> Block if high-risk

    v
PAYMENT PROCESSED
    |
    v
AfterShoppingCartCheckout (Priority 1)
    |-> All 16 providers
    |-> Velocity, Geo, Behavioral
    |-> Auto-lock if critical
    |-> Email + webhooks

    v
ADMIN REVIEWS ORDER
    |
    v
AcceptOrder (Priority 1)
    |-> Log warning if locked but not reviewed

    v
CHARGEBACK / REFUND EVENTS
    |
    +--> InvoiceRefunded
    |    |-> Track refund pattern
    |
    +--> InvoiceUnpaid
         |-> Track chargeback + original FPS score

    v
DAILY MAINTENANCE
    |
    v
DailyCronJob (Priority 1)
    |-> Purge caches
    |-> Refresh lists
    |-> Aggregate stats
    |-> Push Global Intel
```

## Hook Error Handling

All hooks wrap code in try/catch blocks to prevent WHMCS breakage:

```php
add_hook('HookName', 1, function ($vars) {
    try {
        // Hook logic
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'HookName', $vars, $e->getMessage());
        return [];  // Return safe default
    }
});
```

- Errors logged to `logModuleCall()` (WHMCS module log)
- Hooks never block critical WHMCS functionality
- Graceful degradation if FPS database missing or corrupt

# Architecture Overview

## System Architecture

The Fraud Prevention Suite is built on a modular, provider-based architecture with a layered detection pipeline and immutable check context objects.

```
Client Signup / Order Checkout
         |
         v
   [Turnstile Bot Challenge]  (invisible, pre-checkout)
         |
         v
  [Context Builder]
  (FpsCheckContext)
  - email, ip, phone, country, domain
  - client_id, order_id, amount
  - fingerprint data, check type
         |
         v
 [Provider Pipeline]
 (16 parallel/sequential checks)
  - IP Intel (geolocation, proxy, Tor)
  - Email Validation (disposable, MX, domain age)
  - AbuseIPDB, IPQualityScore, FraudRecord
  - Device Fingerprint matching
  - Custom Rules Engine
         |
         v
  [Global Intel Checker]
  (Cross-instance hub lookup)
         |
         v
  [Velocity Engine]
  (Rate limiting: 5 dimensions)
         |
         v
  [Geo-Impossibility Engine]
  (Cross-correlation validation)
         |
         v
  [Behavioral Biometrics]
  (Mouse entropy, keystroke cadence)
         |
         v
  [Risk Engine]
  (Weighted aggregation: 0-100)
         |
         v
  [Action Dispatcher]
  (Approve / Hold / Block / Cancel)
```

## Core Components

### 1. FpsCheckContext (Immutable)

Located: `lib/Models/FpsCheckContext.php`

Immutable data class holding all check parameters:

```php
$context = new FpsCheckContext(
    email: 'user@example.com',
    ip: '1.2.3.4',
    phone: '+1234567890',
    country: 'US',
    clientId: 123,
    orderId: 456,
    amount: 99.99,
    domain: 'example.com',
    fingerprintHash: 'abc123...',
    checkType: 'auto' // 'auto', 'pre_checkout', 'registration', 'manual'
);
```

**Factory methods**:
- `fromOrderAndClient()`: Construct from WHMCS order + client
- `fromClientId()`: Construct from client ID only (registration)
- `fromArray()`: Construct from associative array

### 2. FpsCheckRunner (Orchestrator)

Located: `lib/FpsCheckRunner.php`

Coordinates all detection engines and aggregates results. Entry points:

```php
$runner = new FpsCheckRunner();

// Pre-checkout (fast checks only)
$result = $runner->runPreCheckout($context);

// Full check (all 16 providers)
$result = $runner->runFullCheck($context);

// Manual rescanning
$result = $runner->rescanClient($clientId);

// Batch mass scan
$runner->startMassScan(callable $progressCallback);
```

**Returned**: `FpsCheckResult` object with risk assessment and provider scores

### 3. FpsRiskEngine (Weighted Aggregation)

Located: `lib/FpsRiskEngine.php`

Combines provider scores using configurable weights (stored in `mod_fps_settings`):

```
Final Score = (Provider1_Score * Provider1_Weight +
               Provider2_Score * Provider2_Weight +
               ...)
            / Sum(Weights)

Result: 0-100 normalized score
```

**Risk Levels**:
- 0-29: Low (approve)
- 30-59: Medium (approve + flag for review)
- 60-79: High (hold for manual review)
- 80-100: Critical (auto-cancel)

Thresholds are configurable in Settings.

### 4. Provider System

Located: `lib/Providers/`

All providers implement `FpsProviderInterface`:

```php
interface FpsProviderInterface
{
    public function check(FpsCheckContext $context): array;
    public function isEnabled(): bool;
    public function getWeight(): float;
    public function getScore(): float;
}
```

**16 Providers**:
1. **Turnstile** - Cloudflare bot challenge
2. **IpIntel** - Geolocation, proxy/VPN/Tor detection
3. **EmailValidation** - MX checks, disposable, role accounts
4. **AbuseIPDB** - Crowd-sourced IP abuse reports
5. **IPQualityScore** - Advanced proxy/VPN/bot detection
6. **AbuseSignals** - StopForumSpam + SpamHaus ZEN
7. **DomainReputation** - Domain age, suspicious TLD, Safe Browsing
8. **FraudRecord** - Hosting-industry shared database
9. **Fingerprint** - Canvas, WebGL, fonts, screen hashing
10. **BehavioralScoring** - Mouse entropy, keystroke analysis
11. **TorDatacenter** - Tor exit node and datacenter ASN detection
12. **OFAC** - US Treasury sanctions list screening
13. **BinLookup** - Credit card BIN analysis
14. **BreachCheck** - HIBP breach history
15. **SocialPresence** - LinkedIn, Twitter verification
16. **SmtpVerification** - SMTP mailbox validation

Each provider returns:

```php
[
    'score' => 0.0 to 1.0,      // Raw score before weight
    'details' => ['...', '...'], // Human-readable findings
    'metadata' => [...]          // Optional extra data
]
```

### 5. FpsRuleEngine (Custom Rules)

Located: `lib/FpsRuleEngine.php`

Evaluates admin-defined rules (IP blocks, email patterns, country blocks, velocity):

```php
$ruleEngine = new FpsRuleEngine();
$result = $ruleEngine->evaluate($context);

// $result->action: 'allow', 'flag', 'block'
// $result->matchedRules: [...rule objects...]
// $result->score_delta: points to add/subtract
```

**Rule Types**:
- `ip_block`: Exact IP match
- `email_pattern`: Regex or domain match
- `country_block`: 2-letter code
- `velocity`: Rate limit on 5 dimensions

### 6. FpsVelocityEngine (Rate Limiting)

Located: `lib/FpsVelocityEngine.php`

5-dimension rate limiting:

```
1. Orders per IP per hour
2. Orders per email per day
3. Signups per IP per hour
4. Signups per email per day
5. Refunds per email per month
```

Stores events in `mod_fps_velocity_events` (TTL: 30 days):

```php
$velEngine = new FpsVelocityEngine();
$velEngine->recordEvent('checkout_attempt', $ip, $clientId);
$result = $velEngine->checkVelocity($context);
// Returns: ['score' => 0-100, 'details' => '...']
```

### 7. FpsGeoImpossibilityEngine

Located: `lib/FpsGeoImpossibilityEngine.php`

Cross-correlates 4 geographic signals:

```
IP Location vs Billing Country vs Phone Country vs BIN Country
```

If mismatches detected: adds points based on severity.

### 8. FpsBehavioralScoringEngine

Located: `lib/FpsBehavioralScoringEngine.php`

Analyzes user interaction patterns:

```
- Mouse entropy (movement randomness)
- Keystroke timing and cadence
- Form fill time (humans vs scripts)
- Paste detection (copy-paste password)
```

Scores stored in `mod_fps_behavioral_events`.

### 9. FpsGlobalIntelChecker

Located: `lib/FpsGlobalIntelChecker.php`

Queries hub for cross-instance matches:

```php
$intelChecker = new FpsGlobalIntelChecker();
$matches = $intelChecker->check($context);
// Returns: ['score_delta' => 5-30, 'matches' => [...]]
```

Adds 5-30 points based on hit count and cross-instance confirmations.

## Database Schema (36 Tables)

### Core Check Data
- **mod_fps_checks** - All fraud assessments (order_id, client_id, risk_score, provider_scores)
- **mod_fps_stats** - Daily aggregations (checks_total, avg_risk_score, top_countries)
- **mod_fps_rules** - Custom rule definitions

### Intelligence Caches
- **mod_fps_ip_intel** - IP geolocation + proxy/VPN/Tor flags (expires_at TTL)
- **mod_fps_email_intel** - Email domain analysis, MX validity, breach count (expires_at TTL)
- **mod_fps_fingerprints** - Device fingerprint hashes (canvas, WebGL, fonts)
- **mod_fps_bin_cache** - Credit card BIN lookup results
- **mod_fps_fraud_fingerprints** - Flagged fraud fingerprints (blocklist)

### Event Tracking
- **mod_fps_velocity_events** - Rate limit tracking (orders, signups, refunds)
- **mod_fps_behavioral_events** - Mouse/keystroke biometrics
- **mod_fps_geo_events** - Geographic event stream (for topology visualization)

### Lists and Allowlists
- **mod_fps_tor_nodes** - Tor exit IP list (refreshed daily via cron)
- **mod_fps_datacenter_asns** - Datacenter ASN registry
- **mod_fps_client_trust** - Allowlist/blacklist per client

### Financial Tracking
- **mod_fps_chargebacks** - Chargeback history with original fraud score
- **mod_fps_refund_tracking** - Refund event log for abuse pattern detection
- **mod_fps_gateway_thresholds** - Per-gateway risk thresholds (optional overrides)

### API System
- **mod_fps_api_keys** - Public REST API key records (tier, rate limits)
- **mod_fps_api_logs** - API request audit trail (endpoint, response time, IP)
- **mod_fps_rate_limits** - Token bucket state per key

### Webhooks
- **mod_fps_webhook_configs** - Slack/Discord/Teams webhook URLs
- **mod_fps_webhook_log** - Webhook delivery log

### Global Intel
- **mod_fps_global_intel** - Harvested fraud signals (email_hash, ip, country, risk_score)
- **mod_fps_global_config** - Hub registration and sync settings

### Settings & Metadata
- **mod_fps_settings** - Configuration (enable/disable, thresholds, API keys)
- **mod_fps_weight_history** - Adaptive scoring ML weight changes (monthly)
- **mod_fps_gdpr_requests** - Data removal requests (GDPR compliance)

## WHMCS Hook Execution Order

Fraud checks are triggered at 3 key points:

### 1. Pre-Checkout (< 2 seconds)
```
ShoppingCartValidateCheckout Hook (Priority 1)
  |-> IP Intel + Email Validation + Fingerprint + Rules (fast only)
  |-> Score threshold check
  |-> Block if score >= pre_checkout_block_threshold
```

### 2. Order Submission
```
AfterShoppingCartCheckout Hook (Priority 1)
  |-> FpsCheckRunner::runFullCheck()
  |-> All 16 providers (parallel where possible)
  |-> Global Intel lookup
  |-> Velocity, Geo-Impossibility, Behavioral checks
  |-> Risk aggregation
  |-> Auto-lock if critical
```

### 3. New Registration
```
ClientAdd Hook (Priority 1)
  |-> FpsCheckRunner::runPreCheckout()
  |-> New account flagged if high risk
```

### Maintenance
```
DailyCronJob Hook (Priority 1)
  |-> Purge expired caches (IP intel, email intel)
  |-> Aggregate yesterday's statistics
  |-> Refresh Tor/datacenter lists
  |-> Run adaptive scoring (monthly)
  |-> Auto-push Global Intel to hub
```

## Data Flow Diagram

```
[Client fills checkout]
        |
        v
 [Turnstile challenge]
        |
        v
 [Pre-checkout checks]  <-- ShoppingCartValidateCheckout
   (2 providers)
        |
  [Block?] --> Yes: Return error, stop
        |
        No
        v
 [Proceed to payment]
        |
        v
 [Payment processed]
        |
        v
 [Full fraud check]  <-- AfterShoppingCartCheckout
   (16 providers +
    velocity +
    geo-impossibility +
    behavioral +
    custom rules)
        |
        v
  [Risk aggregation]
   (RiskEngine)
        |
        v
  [Decision]
   Low (0-29): Approve
   Medium (30-59): Approve + flag review
   High (60-79): Hold for manual review
   Critical (80-100): Auto-lock
        |
        v
  [Persist check]
   (mod_fps_checks row)
        |
        v
  [Log activity]
   (WHMCS Activity Log)
        |
        v
  [Notifications]
   (Email alerts, webhooks)
```

## Dashboard Enhancements (v4.2.0+)

### Recent Fraud Checks
- Enriched with client name and company name
- Action buttons per row: Approve, Deny, View Profile
- Refresh and Clear All buttons
- Guest checkout rows show IP address instead of blank

### Review Queue Source Filter
- Default view: "New Signups & Orders" (filters out manual re-scans)
- Toggle to "All Checks" to include manual re-scans
- Configurable via the `source` parameter on `get_review_queue`

### Trust List Improvements
- "All Clients" view uses LEFT JOIN to show every client (not just manually-assigned)
- Search by name, email, company, or client ID
- WHMCS Status column (Active/Inactive/Closed) per row
- Quick-edit button for inline trust status changes

### Log Management
- "Clear All Module Logs" button on Alert Log tab
- "Clear All Fraud Checks" button on Alert Log tab and Dashboard
- Both require confirmation dialog before execution

## Performance Optimizations

1. **Caching**: IP/email intel cached for 24 hours; reduces external API calls
2. **Lazy providers**: Domain reputation and SMTP verification only run on high-risk checks
3. **Parallel checks**: IP and email validation run simultaneously
4. **Local rules**: Custom rules evaluated before external API calls
5. **Rate limiting**: Clients whitelisted skip all checks entirely
6. **Fingerprint matching**: Local database lookup, no external calls

## Extensibility

### Adding a New Provider

1. Create class in `lib/Providers/MyProviderName.php`
2. Implement `FpsProviderInterface`
3. Register in `FpsCheckRunner::$providers` array
4. Add weight slider in Settings tab
5. Add API key field to module config

### Adding a Custom Detection Engine

1. Create class in `lib/MyEngine.php`
2. Implement detection logic returning score (0-100)
3. Integrate into `FpsCheckRunner::runFullCheck()`
4. Add result to `FpsCheckResult`
5. Add weight to `FpsRiskEngine`

### Adding a New Hook

Follow pattern in `hooks.php`:
- Implement handler function
- Register with `add_hook()`
- Load required classes via autoloader
- Log errors to module call log

## Server Module: fps_api (v4.2.0+)

### Component Overview

Located: `modules/servers/fps_api/fps_api.php`

The fps_api server provisioning module enables selling FPS API access as WHMCS products. It implements the standard WHMCS server module interface.

### Functions

| Function | Purpose |
|----------|---------|
| `CreateAccount` | Auto-provisions API key when client purchases product |
| `SuspendAccount` | Deactivates API key (sets `is_active = 0`) |
| `UnsuspendAccount` | Reactivates API key |
| `TerminateAccount` | Permanently revokes and deletes API key |
| `ChangePackage` | Handles tier upgrades/downgrades |
| `ClientArea` | Renders client-facing panel with key, usage, docs link |
| `RegenerateKey` | Allows client to regenerate their API key |
| `TestConnection` | Verifies FPS addon module is active and DB is accessible |

### Provisioning Flow

```
Client Purchases Product
        |
        v
  [WHMCS Order Accepted]
        |
        v
  [fps_api::CreateAccount()]
    - Generate API key (fps_ + 32 chars)
    - Hash key (SHA-256)
    - Insert into mod_fps_api_keys
    - Set tier from product config
    - Link client_id + service_id
    - Return key to client
        |
        v
  [Client Area]
    - Shows API key (masked, reveal on click)
    - Usage stats (requests 24h/7d)
    - Tier and rate limits
    - Link to API documentation
    - Regenerate key button
```

### Product Configuration

Three WHMCS products map to API tiers:

| Product | Tier | Monthly Price | Default Rate Limits |
|---------|------|---------------|---------------------|
| FPS API Free | free | $0 | 30/min, 5,000/day |
| FPS API Basic | basic | $19 | 120/min, 50,000/day |
| FPS API Premium | premium | $99 | 600/min, 500,000/day |

### Rate Limit Resolution Chain

When a request arrives, the rate limit is determined by:

```
1. Per-key override (mod_fps_api_keys.rate_limit_per_minute/per_day)
   |-- If non-zero, use this value
   |
2. Tier setting (mod_fps_settings: rate_limit_{tier}_per_minute/per_day)
   |-- If configured in admin Settings tab, use this value
   |
3. Hardcoded fallback (built into the code)
   |-- Default values from the table above
```

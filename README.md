# Fraud Prevention Suite v4.1.2

Enterprise-grade fraud detection and prevention platform for WHMCS. Protects hosting companies from automated bot signups, carding attacks, and fraudulent orders using 16+ detection engines, real-time behavioral analysis, global threat intelligence sharing, and a complete admin toolkit.

## Quick Start

1. Upload `fraud_prevention_suite/` to `modules/addons/` on your WHMCS server
2. Activate in **WHMCS Admin > Setup > Addon Modules**
3. Open the FPS Dashboard -- the Setup Wizard banner guides you through API key configuration
4. Run a **Mass Scan** to baseline your existing client database
5. Optionally enable **Global Intel** sharing to contribute to and benefit from the cross-instance fraud network

## Requirements

- WHMCS 8.x or later
- PHP 8.2+
- MySQL 5.7+ or MariaDB 10.3+
- cURL extension enabled
- DNS functions enabled (for SpamHaus lookups)

---

## Features Overview

### Detection Engines (16)

| Engine | Type | API Key Required | Free Tier | Speed |
|--------|------|-----------------|-----------|-------|
| **Cloudflare Turnstile** | Invisible bot challenge | Yes (free) | Unlimited | Instant |
| **Bot Pattern Detector** | Plus-addressing, SMS gateways, disposable emails | No | -- | Instant |
| **IP Intelligence** | Geolocation, ISP, proxy/VPN/Tor detection | No | 45 req/min | Fast |
| **AbuseIPDB** | Crowd-sourced IP abuse reports | Yes | 1,000/day | Fast |
| **IPQualityScore** | Advanced proxy/VPN/bot/abuse scoring | Yes | 5,000/month | Fast |
| **Abuse Signals** | StopForumSpam + SpamHaus ZEN (DNS) | No | -- | Fast |
| **Domain Reputation** | Domain age (RDAP), suspicious TLD, Safe Browsing | No | -- | Medium |
| **Email Validation** | MX records, disposable detection, role accounts | No | -- | Fast |
| **Phone Validation** | Format, calling code, carrier type | No | -- | Instant |
| **FraudRecord** | Hosting-industry shared fraud database (v2 API) | Yes | Free | Medium |
| **Device Fingerprinting** | Canvas, WebGL, fonts, screen, audio, WebRTC | No | -- | Instant |
| **Behavioral Biometrics** | Mouse entropy, keystroke cadence, form fill speed | No | -- | Instant |
| **Velocity Engine** | Order/registration/checkout rate limiting (5 dimensions) | No | -- | Instant |
| **Geo-Impossibility** | IP vs billing vs phone vs BIN country cross-correlation | No | -- | Instant |
| **OFAC Screening** | US Treasury sanctions list (SDN) | No | -- | Instant |
| **Global Intel** | Cross-instance shared fraud intelligence hub | No | -- | Fast |

### Admin Dashboard (14 Tabs)

| Tab | Purpose |
|-----|---------|
| **Dashboard** | KPI cards, recent checks, risk trend sparklines, setup wizard |
| **Review Queue** | Flagged orders awaiting admin approve/deny/hold decision |
| **Trust Management** | Allowlist/blacklist per client with bulk actions |
| **Client Profile** | Deep investigation: risk gauge, 15-dimension duplicate detection, timeline |
| **Mass Scan** | Batch scan all clients with progress bar and ETA |
| **Bot Cleanup** | Detect bots using real financial data, preview/purge/deep-purge |
| **Rules** | Custom fraud rules (IP blocks, email patterns, country blocks, velocity) |
| **Reports** | Fraud reports and FraudRecord submissions |
| **Statistics** | Charts and trends (ApexCharts) |
| **Topology** | Interactive 3D globe of fraud origins (Three.js) |
| **Global Intel** | Hub connection, sharing settings, intel browser, privacy controls |
| **Alert Log** | Provider health status, module error log, pagination |
| **API Keys** | Manage API credentials for external integrations |
| **Settings** | Provider toggles, threshold sliders, Turnstile config, display options |

---

## Installation

### Step 1: Upload

Upload the entire `fraud_prevention_suite/` directory to your WHMCS installation:

```
/path/to/whmcs/modules/addons/fraud_prevention_suite/
```

### Step 2: Activate

1. Go to **WHMCS Admin > Setup > Addon Modules**
2. Find "Fraud Prevention Suite" and click **Activate**
3. Configure access control (which admin roles can access FPS)

The module automatically creates 36+ database tables on activation. All table creation is idempotent (safe to re-activate).

### Step 3: Configure API Keys

Open the FPS module and follow the Setup Wizard banner:

1. **Cloudflare Turnstile** (Critical) -- Get free keys at [dash.cloudflare.com](https://dash.cloudflare.com) > Turnstile
2. **AbuseIPDB** (Recommended) -- Get free API key at [abuseipdb.com](https://www.abuseipdb.com/account/api)
3. **IPQualityScore** (Recommended) -- Get free key at [ipqualityscore.com](https://www.ipqualityscore.com/create-account)
4. **FraudRecord** (Optional) -- Get free key at [fraudrecord.com](https://www.fraudrecord.com/signup/)

The wizard validates each key by making a real test API call and shows a green checkmark or specific error message.

### Step 4: Run Initial Scan

Go to **Mass Scan** tab and click "Start Scan" to baseline all existing clients. This identifies existing fraud that predates FPS installation.

---

## Fraud Scoring Pipeline

```
Signup/Order Submitted
        |
        v
[Turnstile Bot Challenge] ---> Block bots instantly (invisible)
        |
        v
[Bot Pattern Detection] -----> Score: plus-addressing, SMS gateways, disposable email
        |
        v
[16 Provider Checks] -------> IP intel, email validation, abuse DBs, domain age
        |
        v
[Global Intel Check] -------> Cross-reference against shared fraud database
        |
        v
[Velocity Engine] ----------> Rate limit violations (5 dimensions)
        |
        v
[Geo-Impossibility] --------> Country mismatches (IP vs billing vs phone vs BIN)
        |
        v
[Behavioral Biometrics] ----> Mouse/keyboard patterns (human vs script)
        |
        v
[Custom Rules Engine] ------> Admin-defined rules (IP blocks, patterns, etc.)
        |
        v
[Risk Engine Aggregation] --> Weighted score 0-100 with configurable weights
        |
        v
[Action: Approve / Hold / Block / Cancel]
```

### Risk Levels

| Level | Score Range | Default Action |
|-------|------------|----------------|
| Low | 0-29 | Approve |
| Medium | 30-59 | Approve (flagged for review) |
| High | 60-79 | Hold order for admin review |
| Critical | 80-100 | Auto-cancel order |

All thresholds are configurable in Settings.

---

## Bot Cleanup System

The Bot Cleanup tab uses **real WHMCS financial data** to identify bot accounts with zero false positives:

- **Real client**: Has at least one paid invoice (total > $0) OR at least one active hosting service
- **Suspected bot**: Everything else (zero paid invoices AND zero active hosting)

### Available Actions

| Action | Preview | Description |
|--------|---------|-------------|
| **Flag** | Yes | Adds `[FPS-BOT]` note to client record |
| **Deactivate** | Yes | Sets client status to Inactive |
| **Purge** | Yes | Deletes clients with zero records (no orders, invoices, hosting) |
| **Deep Purge** | Yes | Deletes clients where ALL records are Fraud/Cancelled/Unpaid |

All actions have a **Preview (dry-run)** button that shows exactly what will happen without making any changes. Deep purge is the most powerful -- it handles accounts that standard purge can't touch because they have Fraud orders and Cancelled invoices.

### Harvest Before Delete

When any purge or deep purge runs, FPS automatically **harvests fraud intelligence** (SHA-256 email hash, IP, country, risk score, evidence flags) into the Global Intel database before deleting the account. This ensures fraud data is never lost and can be shared with other WHMCS instances.

### User Account Cleanup (WHMCS 8.x)

WHMCS 8.x has separate **Client** accounts (billing) and **User** accounts (login). Purging a bot client leaves the user login intact -- they can log back in and create new accounts. FPS handles this:

**Automatic cleanup**: When a bot client is purged, FPS also removes the associated user login from `tblusers` if it has no other real client associations.

**WHMCS Users page integration**: FPS injects a "Bot Detection" toolbar directly into the WHMCS admin Users page (`/admin/user/list`). Staff can scan for orphan users and purge them without leaving their normal workflow. This feature is controlled by a toggle in **FPS Settings > Bot & User Cleanup**.

**Standalone scan**: The Bot Cleanup tab also includes a "User Account Cleanup" section for scanning and purging orphan users from within the FPS module.

**What makes a user a "bot"**: A user is a bot if NONE of their linked client accounts have paid invoices or active hosting. Users with zero client links (orphans) are also flagged.

---

## Global Threat Intelligence

FPS includes a hub-and-spoke fraud intelligence sharing system. When enabled, your WHMCS instance contributes anonymized fraud data to a central hub and benefits from fraud data detected by other WHMCS instances running FPS.

### How It Works

1. **Local**: FPS harvests fraud signals into `mod_fps_global_intel` (SHA-256 email hashes, IPs, evidence flags)
2. **Push**: Daily cron (or manual) pushes unpushed records to the central hub
3. **Query**: New signups/orders are cross-referenced against the global database
4. **Score**: Matches add 5-30 points to the fraud score (more hits = higher adjustment)

### Privacy & GDPR Compliance

| What is shared | What is NEVER shared |
|---------------|---------------------|
| SHA-256 email hash (irreversible) | Raw email addresses |
| IP address (optional, admin toggle) | Names, phone numbers |
| 2-letter country code | Billing addresses |
| Risk score (0-100) | Client IDs, order IDs |
| Boolean evidence flags | Invoice data, payment details |
| Risk level (low/medium/high/critical) | Fingerprint data |

Admins can:
- **Disable sharing** at any time (master toggle)
- **Disable IP sharing** separately
- **Export all data** as JSON (GDPR Article 20 -- data portability)
- **Purge all local intel** (GDPR Article 17 -- right to erasure)
- **Purge hub contributions** (removes everything this instance sent to the hub)

### Hub Setup

The hub is a standalone service that receives and aggregates fraud intel from all opt-in instances. It can run on any server with Docker:

```bash
cd fps-hub/
docker compose up -d
```

The hub listens on port 8400. Configure a reverse proxy (Caddy, Nginx) with TLS for production use.

---

## Public REST API

FPS exposes a REST API for external integrations. Authentication via `X-FPS-API-Key` header.

### Endpoints

| Method | Endpoint | Min Tier | Description |
|--------|----------|----------|-------------|
| GET | `/v1/stats/global` | Anonymous | Platform-wide 30-day statistics |
| GET | `/v1/topology/hotspots` | Anonymous | Geographic threat heatmap data |
| GET | `/v1/topology/events` | Free | Anonymized real-time event feed |
| GET | `/v1/lookup/ip-basic` | Free | Country, proxy, VPN, Tor, datacenter, threat score |
| GET | `/v1/lookup/ip-full` | Basic | + ASN, ISP, region, city, lat/lng, proxy type |
| GET | `/v1/lookup/email-basic` | Basic | Disposable, free provider, role account, MX, domain age |
| GET | `/v1/lookup/email-full` | Premium | + Breach count, social presence, deliverability score |
| POST | `/v1/lookup/bulk` | Premium | Batch IP/email lookups (up to 100 items) |
| GET | `/v1/reports/country/{CC}` | Premium | Per-country threat stats with daily breakdown |

### Rate Limits

| Tier | Per Minute | Per Day | Price |
|------|-----------|---------|-------|
| Anonymous | 5 | 100 | Free |
| Free | 30 | 5,000 | Free (with key) |
| Basic | 120 | 50,000 | $0.005/query |
| Premium | 600 | 500,000 | Custom |

### Authentication

```bash
# Anonymous (no key -- limited to stats/hotspots)
curl "https://your-whmcs.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"

# With API key
curl -H "X-FPS-API-Key: YOUR_KEY" \
  "https://your-whmcs.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4"
```

### Response Format

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "request_id": "fps_a1b2c3d4e5f6",
    "tier": "basic",
    "rate_limit": {
      "remaining": 118,
      "limit": 120
    },
    "response_time_ms": 42
  }
}
```

Rate limit headers are included in all responses: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` (on 429).

---

## 15-Dimension Duplicate Detection

The Client Profile tab runs deep linkage analysis to find accounts connected to the investigated client:

1. Exact IP match (from fraud check history)
2. Registration IP match (from `tblclients.ip`)
3. IP /24 subnet (same datacenter block)
4. SMS gateway email domain siblings
5. Plus-tag email base matching (e.g., user+tag@gmail.com)
6. Phone number (normalized, stripped formatting)
7. Billing address + postcode
8. City + zip code proximity
9. Same-day signup clustering
10. Device fingerprint hash
11. Exact name match
12. Non-major email domain sharing
13. Country mismatch pattern
14. Shared historical IP (cross-reference)
15. Global intel cross-instance matches

---

## WHMCS Hooks

| Hook | Priority | Purpose |
|------|----------|---------|
| `AdminAreaHeaderOutput` | 1 | Inject 1000X CSS into admin panel |
| `ClientAreaHeaderOutput` | 1 | Inject Turnstile API script |
| `ClientAreaFooterOutput` | 1 | Inject Turnstile widget + fingerprint JS |
| `ShoppingCartValidateCheckout` | 1 | Turnstile validation + bot blocking + pre-checkout scoring |
| `AfterShoppingCartCheckout` | 1 | Full fraud check with all providers |
| `AcceptOrder` | 1 | Verify locked order fraud status |
| `ClientAdd` | 1 | Bot detection + fraud check on new registration |
| `ClientAreaPageCart` | 1 | Fingerprint JS injection on cart page |
| `ClientAreaPage` | 1 | Public API and page routing |
| `DailyCronJob` | 1 | Cache cleanup, list refresh, stats, adaptive scoring, global intel push |
| `InvoiceRefunded` | 1 | Refund abuse tracking |
| `InvoiceUnpaid` | 1 | Chargeback tracking + webhook alerts |

---

## File Structure

```
fraud_prevention_suite/
  fraud_prevention_suite.php      # Main module (config, activate, output, 58 AJAX handlers)
  hooks.php                       # 12 WHMCS hooks
  version.json                    # Version metadata
  lib/
    Autoloader.php                # PSR-4 autoloader
    FpsBotDetector.php            # Bot detection (real financial data) + purge + deep purge
    FpsCheckRunner.php            # Fraud check orchestrator (4 entry points)
    FpsRiskEngine.php             # Weighted score aggregation (0-100)
    FpsRuleEngine.php             # Custom rule evaluation
    FpsConfig.php                 # Settings reader (singleton)
    FpsTurnstileValidator.php     # Cloudflare Turnstile integration
    FpsVelocityEngine.php         # 5-dimension rate limiting
    FpsGeoImpossibilityEngine.php # Geographic cross-correlation
    FpsBehavioralScoringEngine.php# Mouse/keyboard biometrics
    FpsAdaptiveScoring.php        # Monthly ML weight optimization
    FpsStatsCollector.php         # Daily statistics
    FpsClientTrustManager.php     # Allowlist/blacklist
    FpsNotifier.php               # Email notifications
    FpsWebhookNotifier.php        # Slack/Discord/Teams webhooks
    FpsHookHelpers.php            # Hook utility functions
    FpsGlobalIntelCollector.php   # Harvest fraud intel + upsert with dedup
    FpsGlobalIntelClient.php      # Hub HTTP client (register, push, lookup, GDPR purge)
    FpsGlobalIntelChecker.php     # Fast cross-reference for signups (<200ms)
    Models/
      FpsCheckContext.php          # Immutable check context
      FpsCheckResult.php           # Check result wrapper
      FpsRiskResult.php            # Risk assessment output
      FpsRuleResult.php            # Rule evaluation output
    Providers/                     # 16 provider classes (FpsProviderInterface + 15 implementations)
    Admin/                         # 14 tab renderers (TabDashboard, TabBotCleanup, etc.)
    Api/
      FpsApiRouter.php             # REST API routing (10 endpoints)
      FpsApiAuth.php               # 4-tier API key authentication
      FpsApiController.php         # Endpoint handler methods
      FpsApiRateLimiter.php        # Token bucket rate limiting
  assets/
    css/fps-1000x.css              # 1000X Design System (dark/light mode)
    css/fps-topology.css           # Globe visualization styles
    js/fps-admin.js                # Admin UI controller (FpsAdmin, FpsBot, FpsGlobal)
    js/fps-charts.js               # ApexCharts dashboard integration
    js/fps-fingerprint.js          # Device fingerprint collector (10+ signals)
    js/fps-topology.js             # Three.js globe visualization
  templates/
    overview.tpl                   # Public API docs + live stats page
    topology.tpl                   # Standalone threat map
  public/
    api.php                        # REST API entry point (bootstraps WHMCS + routes)
  data/
    disposable_domains.txt         # 500+ known disposable email domains
    free_email_providers.txt       # Major free email providers (Gmail, Yahoo, etc.)
```

---

## Database Tables (36)

All tables use the `mod_fps_` prefix with idempotent creation (`hasTable` guards).

| Category | Tables |
|----------|--------|
| **Core** | `checks`, `stats`, `settings`, `rules`, `reports` |
| **Cache** | `ip_intel`, `email_intel`, `bin_cache`, `fingerprints`, `fr_cache` |
| **Events** | `velocity_events`, `behavioral_events`, `geo_events` |
| **Lists** | `tor_nodes`, `datacenter_asns`, `fraud_fingerprints`, `blacklist_*`, `whitelist` |
| **Tracking** | `chargebacks`, `refund_tracking`, `client_trust`, `country_risk` |
| **API** | `api_keys`, `api_logs`, `rate_limits` |
| **Webhooks** | `webhook_configs`, `webhook_log` |
| **ML** | `weight_history` |
| **Global Intel** | `global_intel`, `global_config` |

---

## Troubleshooting

### Module won't activate
- Check PHP version: `php -v` (must be 8.2+)
- Check WHMCS error log: `Admin > Utilities > Logs > Module Log`
- Verify file permissions: all `.php` files should be 644, directories 755

### API returns 503
- API is disabled. Enable it in **Settings > API Access > Enable Public API**

### Turnstile not blocking bots
- Verify both Site Key and Secret Key are set (use the Setup Wizard to validate)
- Check that the `ClientAreaHeaderOutput` hook is active

### Bot scan shows 0 suspects
- All clients have paid invoices or active hosting (they're real customers!)
- Check the status filter dropdown -- "Inactive Only" filters out active clients

### Provider check returns empty
- Enable the provider in Settings (toggle switch)
- Validate the API key using the Setup Wizard
- Check the Alert Log tab for API errors

### Global Intel push fails
- Verify hub URL is configured in Global Intel settings
- Check hub is reachable: `curl https://your-hub/health`
- Register with the hub first (click Register button)

---

## Changelog

### v4.1.2 (2026-03-31)
- Global Threat Intelligence hub-and-spoke system (cross-instance sharing)
- Harvest-before-purge (fraud intel preserved before bot account deletion)
- Deep purge (handles accounts with Fraud/Cancelled/Unpaid records)
- Preview/dry-run system for all destructive operations (flag, deactivate, purge, deep purge)
- Global Intel admin tab (connection status, intel browser, privacy controls, GDPR compliance)
- FpsGlobalIntelCollector with dedup upsert (seen_count, GREATEST risk_score)
- FpsGlobalIntelClient (hub registration, push, lookup, feed, GDPR purge)
- FpsGlobalIntelChecker (fast cross-reference, <200ms local, <500ms with hub)
- Score adjustment from global intel (5-30 points based on hit count + cross-instance confirmation)
- Hub API (FastAPI + SQLite Docker container) with 8 endpoints
- Portability audit: all paths use __DIR__, domain from SystemURL, zero hardcoded IPs/URLs
- 62 PHP files, 16 providers, 14 admin tabs, 58 AJAX actions, 36 database tables

### v4.0.0 (2026-03-30)
- Cloudflare Turnstile integration (invisible bot protection)
- AbuseIPDB, IPQualityScore, Abuse Signals, Domain Reputation providers
- Bot detection with real WHMCS financial data (100% accuracy, zero false positives)
- 15-dimension duplicate account detection
- Setup wizard with real-time API key validation
- Client risk timeline (chronological forensic view)
- Cross-account scan (shared IPs, fingerprints, phone, address, email domain)
- FraudRecord v2 API migration (SHA1, JSON POST, camelCase)
- Public API docs page with live stats and tier pricing
- Font size scaling (85%-130%)
- Alert Log tab (provider health, error tracking)

### v3.0.0 (2026-03-29)
- Full provider pipeline (11 provider classes with FpsProviderInterface)
- Client trust management (allowlist/blacklist)
- Webhook notifications (Slack, Discord, Teams, Generic)
- Velocity engine (5 rate limit dimensions)
- Geo-impossibility engine (4-signal cross-correlation)
- Behavioral scoring engine
- Mass scan with batch AJAX progress
- REST API with 4-tier access
- 1000X Design System (dark/light mode)

### v2.0.0
- IP/email intel caching, device fingerprinting, BIN lookup
- Gateway-specific thresholds, chargeback tracking

### v1.0.0
- Initial release: FraudRecord integration, basic IP/email checks

---

## License

Proprietary -- EnterpriseVPS Solutions LLC. All rights reserved.

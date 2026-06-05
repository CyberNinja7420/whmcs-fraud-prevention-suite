# Fraud Prevention Suite for WHMCS

**Enterprise-grade fraud detection and intelligence platform for WHMCS with 18+ detection engines, 22-signal device fingerprinting, real-time bot blocking, proof-of-work challenges, behavioral biometrics, device trust management, VPN/Tor detection, and shared global threat intelligence.**

[![WHMCS 8.x | 9.x](https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-0052CC?style=for-the-badge)](https://whmcs.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge)](LICENSE)
![Version](https://img.shields.io/badge/Version-5.7.0-2563eb?style=for-the-badge)

[Live Demo](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite) | [API Plans](https://enterprisevpssolutions.com/store/fraud-intelligence-api) | [Threat Map](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite&page=topology) | [Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home)

---

## What is FPS?

Fraud Prevention Suite (FPS) is the most comprehensive fraud detection addon module for WHMCS. It protects hosting providers from fraudulent signups, bot attacks, and payment fraud through 18+ layered detection engines, a public REST API for fraud intelligence lookups, a real-time 3D threat visualization globe, and automated response actions.

**Key differentiators:**

- **5-layer bot defense** -- Turnstile CAPTCHA, honeypot fields, proof-of-work challenge, device fingerprinting, and behavioral biometrics all run at checkout
- **Automated incident response** -- Auto-suspend, flag, or blacklist clients after configurable critical fraud thresholds
- **Real-time Slack/Discord alerts** -- Instant webhook notifications on every blocked checkout
- **GDPR-compliant by design** -- One-click data export (Article 20 portability) and erasure (Article 17) with anonymization of fraud evidence
- **SaaS API product** -- Three-tier monetizable API (Free/Basic/Premium) with per-key rate limiting and usage dashboards
- **WHMCS-native** -- Built specifically for WHMCS using Capsule ORM, Smarty templates, and the hook system

## Features

### Detection Engines (18+)

| Engine | Description | Data Source |
|--------|-------------|-------------|
| Cloudflare Turnstile | Invisible bot challenge on login, registration, checkout, tickets | Cloudflare (free) |
| Proof-of-Work Challenge | SHA-256 crypto puzzle solved by real browsers in ~200ms | Browser SubtleCrypto |
| Honeypot Fields | Invisible form fields bots fill, humans skip (+30 score) | Built-in |
| Device Fingerprinting | Canvas, WebGL, font, screen, timezone, audio fingerprinting | Browser-side JS |
| Behavioral Biometrics | Mouse entropy, keystroke cadence CV, form fill speed, paste detection | Browser-side JS |
| User-Agent Analysis | Detects headless browsers, curl, python-requests, selenium, puppeteer | Built-in |
| IP Intelligence | Proxy, VPN, Tor, datacenter detection with abuse scoring | ip-api.com, AbuseIPDB, IPQS |
| AbuseSignalProvider | StopForumSpam + SpamHaus ZEN cross-reference (no API key needed) | StopForumSpam, SpamHaus |
| Email Validation | Disposable detection (auto-updated daily), SMTP verification, breach checks | Built-in + HIBP |
| Bot Pattern Detection | Plus-addressing, SMS gateways, signup velocity, numeric emails | Built-in heuristics |
| Geographic Analysis | IP/billing/phone/BIN country cross-correlation, impossible travel | Multi-source correlation |
| Velocity Engine | Rate limiting on orders, registrations, failed payments, BIN reuse | Built-in |
| Tor Exit Node Detection | 1,300+ Tor exit nodes updated daily via cron | Tor Project |
| OFAC SDN Screening | US Treasury sanctions compliance screening | US Treasury |
| Global Threat Intel | Cross-instance fraud data sharing (GDPR compliant, SHA-256 hashed) | FPS Network |
| Domain Reputation | Domain age, WHOIS analysis, blacklist lookup | Built-in |
| FraudRecord | Community fraud database lookups and reporting (V1 + V2 API) | FraudRecord.com |
| Adaptive Scoring | Monthly chargeback-driven weight auto-tuning per provider | Built-in ML |

### Admin Dashboard (14 Tabs)

| Tab | Description |
|-----|-------------|
| Dashboard | 8 stat cards, latency widget, manual check form, setup wizard |
| Review Queue | Filterable fraud check queue with bulk approve/deny |
| Trust Management | Client whitelist/blacklist/suspend with status management |
| Client Profile | Per-client fraud history, risk timeline chart, GDPR actions |
| Mass Scan | Batch fraud scan across all clients with filters |
| Rules | Custom fraud rules with geo-blocking country manager |
| Reports | Fraud reports, scheduled PDF reports, audit trail CSV export |
| Statistics | ApexCharts daily trends, risk distribution, provider accuracy |
| Topology | Live 3D globe (Three.js/Globe.gl) with real-time threat feed |
| Global Intel | Hub connection, sharing settings, GDPR privacy controls |
| Bot Cleanup | Financial evidence-based bot detection with purge/deactivate |
| Alert Log | Provider health status, recent errors, module log viewer |
| API Keys | Tiered API key management with usage tracking |
| Settings | Display, providers, thresholds, gateway overrides, auto-response, email digest, scheduled reports |

**Tab visibility is settings-driven** -- disabled features automatically hide their tabs.

### Operational Features

- **Auto-Response Actions** -- Automatically suspend, flag, or blacklist clients after N critical fraud checks in X days
- **Email Digest** -- Daily or weekly HTML fraud activity summary emailed to admins
- **Scheduled PDF Reports** -- Weekly/monthly print-optimized HTML reports with auto-generated recommendations
- **Slack/Discord/Teams Alerts** -- Real-time webhook notifications on every pre-checkout block
- **Admin Home Widget** -- Fraud stats widget on the WHMCS admin dashboard
- **Rules Export/Import** -- JSON backup and restore of custom fraud rules
- **Disposable Domain Auto-Update** -- DailyCronJob fetches latest blocklist from GitHub (3,000+ domains)
- **Audit Trail Export** -- CSV download of all fraud check history with date range filter
- **IP Whitelist** -- Admin/monitoring/CI IPs bypass all fraud checks
- **GDPR Automation** -- One-click client data export and erasure with Article 17(3)(e) evidence preservation

### API & SaaS

- **REST API** with 3 tiers: Free ($0/mo) / Basic ($19/mo) / Premium ($99/mo)
- **Multi-tier rate limiting** -- Per-minute sliding window + daily caps with `X-RateLimit-*` headers
- **80% overage alerts** -- Email notification when API keys approach daily limits
- **Client portal usage dashboard** -- Per-key stats, 30-day usage charts, tier badges
- **429 Too Many Requests** with `Retry-After` header on rate limit violations

## Quick Start

### 1. Upload

```bash
unzip fraud_prevention_suite.zip -d /path/to/whmcs/modules/addons/
chmod -R 755 /path/to/whmcs/modules/addons/fraud_prevention_suite/
find /path/to/whmcs/modules/addons/fraud_prevention_suite/ -name "*.php" -exec chmod 644 {} \;
```

### 2. Activate

1. Login to WHMCS Admin
2. Navigate to **System Settings > Addon Modules**
3. Find **Fraud Prevention Suite** and click **Activate**
4. Grant admin role access permissions
5. Three API products are auto-created (Free Tier, Basic, Premium)

### 3. Configure

1. Go to **Addons > Fraud Prevention Suite > Settings**
2. **Required:** Add [Cloudflare Turnstile](https://dash.cloudflare.com) keys (free)
3. **Recommended:** Add [AbuseIPDB](https://www.abuseipdb.com) API key (free)
4. **Recommended:** Add [IPQualityScore](https://www.ipqualityscore.com) API key (free tier)
5. Enable desired detection engines
6. Set pre-checkout blocking threshold (default: 65)
7. Add admin IPs to the whitelist
8. Configure email digest and webhook notifications

## Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| WHMCS | 8.0 | 8.10+ / 9.x |
| PHP | 8.2 | 8.3 |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |
| cURL extension | Required | -- |
| JSON extension | Required | -- |
| OpenSSL extension | Required | -- |
| Cron | Required | Every 5 minutes |

## API Tiers

| Feature | Free | Basic ($19/mo) | Premium ($99/mo) |
|---------|------|----------------|-------------------|
| Rate limit | 30/min, 5K/day | 120/min, 50K/day | 600/min, 500K/day |
| IP lookup | Basic | Full (abuse history) | Full + bulk |
| Email lookup | -- | Basic | Full (breaches, SMTP) |
| Topology & Stats | Yes | Yes | Yes |
| Country reports | -- | -- | Yes |
| Webhook alerts | -- | -- | Yes |
| Support | Community | Email (24h) | Priority (4h) |

## Bot Defense Stack

FPS uses 5 independent layers to catch bots. A typical automated script must defeat ALL layers to pass:

| Layer | What It Catches | Score Impact |
|-------|----------------|--------------|
| 1. Turnstile | JS-incapable bots, known bad IPs | Block (100) |
| 2. Honeypot | DOM-parsing bots that fill hidden fields | +30 |
| 3. Proof-of-Work | Bots that skip JS execution | +10-25 |
| 4. Fingerprint | Headless browsers (webdriver, zero plugins) | +20-60 |
| 5. Behavioral | Robotic mouse/keyboard patterns, instant form fills | +10-50 |

Combined with User-Agent detection (+35-50), AbuseSignal (+variable), and IP intelligence, most bots score 65+ against a threshold of 65 and are blocked at pre-checkout.

## Module Structure

```
fraud_prevention_suite/
  fraud_prevention_suite.php    # Main module file (~4,500 lines)
  hooks.php                     # 21 WHMCS hooks
  version.json                  # Build manifest
  lib/
    Admin/                      # 14 admin tab controllers + home widget
    Api/                        # REST API (router, auth, controller, rate limiter)
    Models/                     # Typed data models
    Providers/                  # 16 detection engine providers
    Gdpr/                       # GDPR export/erasure helpers
    Ajax/                       # AJAX handler classes
    Install/                    # Installation helpers
    FpsCheckRunner.php          # Pipeline orchestrator (full + pre-checkout)
    FpsRiskEngine.php           # Score aggregation with floor overrides
    FpsAutoResponder.php        # Automated suspend/flag/blacklist
    FpsEmailDigest.php          # Daily/weekly email summaries
    FpsPdfReport.php            # Scheduled HTML/PDF reports
    FpsBehavioralScoringEngine.php  # Behavioral biometrics analyzer
    FpsAdaptiveScoring.php      # Chargeback-driven weight tuning
    FpsWebhookNotifier.php      # Slack/Teams/Discord/generic webhooks
    FpsClientTrustManager.php   # Client trust/blacklist system
    FpsVelocityEngine.php       # Rate-limited event scoring
    FpsBotDetector.php          # Financial evidence-based bot detection
    FpsGeoImpossibilityEngine.php  # Geographic correlation
    FpsGlobalIntelCollector.php # Hub push/pull collector
  templates/                    # Smarty templates
    client/usage.tpl            # Client API usage dashboard
    overview.tpl, topology.tpl, apidocs.tpl, gdpr.tpl, etc.
  assets/
    css/fps-1000x.css           # 1000X design system
    js/fps-admin.js             # Admin panel JS
    js/fps-fingerprint.js       # Device fingerprint collector
    js/fps-pow.js               # Proof-of-work challenge solver
    js/fps-behavioral.js        # Behavioral biometrics collector
    js/fps-charts.js            # ApexCharts dashboard integration
    vendor/                     # Pinned frontend libs (no CDN dependency)
  public/api.php                # REST API endpoint
  data/                         # Disposable domains, Tor nodes (auto-updated)
  docs/                         # Wiki pages, audit reports
```

## Security

- 5-layer bot defense (Turnstile + honeypot + PoW + fingerprint + behavioral)
- API keys stored as SHA-256 hashes (plaintext shown once on creation)
- Global intel data anonymized with SHA-256 before sharing
- CSRF protection on all admin AJAX endpoints (WHMCS token validation)
- Parameterized queries via Capsule ORM (no raw SQL on core tables)
- Multi-tier API rate limiting with sliding window enforcement
- GDPR Article 17 erasure with Article 17(3)(e) evidence preservation
- Webhook HMAC signatures for outbound notification verification
- IP whitelist bypass for admin/CI infrastructure

To report a security vulnerability, email security@enterprisevpssolutions.com

## Documentation

Full documentation is available in the [Wiki](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home):

- [Installation Guide](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Installation-Guide)
- [Provider Configuration](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Provider-Configuration)
- [API Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/API-Documentation)
- [Architecture Overview](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Architecture-Overview)
- [Database Schema](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Database-Schema)
- [Hook Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Hook-Reference)
- [AJAX Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/AJAX-Reference)
- [Bot Cleanup Guide](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Bot-Cleanup-Guide)
- [Troubleshooting](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Troubleshooting)

## License

MIT License - see [LICENSE](LICENSE) for details.

Copyright (c) 2025-2026 [Enterprise VPS Solutions (EVDPS)](https://enterprisevpssolutions.com)

## Support

- **Documentation:** [Wiki](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home)
- **Issues:** [GitHub Issues](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/issues)
- **Email:** support@enterprisevpssolutions.com
- **Live Demo:** [enterprisevpssolutions.com](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite)

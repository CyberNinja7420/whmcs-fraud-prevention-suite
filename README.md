# Fraud Prevention Suite for WHMCS

**Enterprise-grade fraud detection platform for WHMCS with 16+ detection engines, real-time bot blocking, device fingerprinting, and shared global threat intelligence.**

[![WHMCS 8.x | 9.x](https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-0052CC?style=for-the-badge)](https://whmcs.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge)](LICENSE)
![Version](https://img.shields.io/badge/Version-4.2.5-2563eb?style=for-the-badge)

[Live Demo](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite) | [API Plans](https://enterprisevpssolutions.com/store/fraud-intelligence-api) | [Threat Map](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite&page=topology) | [Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home)

---

## What is FPS?

Fraud Prevention Suite (FPS) is a comprehensive fraud detection addon module for WHMCS that protects hosting providers from fraudulent signups, bot attacks, and payment fraud. It integrates 16+ detection engines, provides a REST API for fraud intelligence lookups, and features a real-time 3D threat visualization globe.

**Key differentiators:**

- **Not just a fraud check** -- FPS is a complete fraud intelligence platform with API, threat topology, and global data sharing
- **Open source module, SaaS API** -- The module is free and MIT-licensed; revenue comes from optional paid API tier subscriptions
- **WHMCS-native** -- Built specifically for WHMCS using Capsule ORM, Smarty templates, and the hook system
- **Zero runtime CDN dependency** -- ApexCharts, three.js and globe.gl are all vendored under `assets/vendor/` (refreshed quarterly via `scripts/refresh-vendor-assets.sh`)
- **Accessibility-first** -- Built-in colorblind mode toggle and high-contrast support

### What's new in v4.2.5 (2026-04-25)

- **Google Analytics 4 + Microsoft Clarity integration** with three independently-toggleable scopes (client storefront, admin tabs, server-side custom events)
- **12 server-side custom events** sent via the GA4 Measurement Protocol covering the full FPS lifecycle: pre-checkout blocks/allows, Turnstile pass/fail, high-risk signups, geo-impossibility hits, velocity blocks, admin review actions, API requests, bot purges, daily heartbeat
- **EEA Consent Mode v2** -- default-deny, banner shown only to EEA visitors (27-country list), Accept/Decline updates `gtag('consent')` + `clarity('consent')` and persists in localStorage + cookie
- **Dashboard "Analytics Connection Status" widget** with health dots, deep-links to GA4 Realtime + Clarity, optional yesterday-block-count via Service Account JWT
- **Daily anomaly detection** (3x-median spike check on 3 fraud events) with admin email alert
- **GDPR Article 17 erasure extended** to call Microsoft Clarity DSR API + GA4 manual-deletion instructions
- **MCP server installer** (`scripts/install-mcp-servers.sh`) wires the official Google Analytics + Microsoft Clarity MCP servers into Claude Code for natural-language analytics queries
- See [Analytics & MCP Setup](docs/wiki/Analytics-MCP-Setup.md) for the operator guide

### What's new in v4.2.4 (2026-04-22)

- Vendored ApexCharts + three.js + globe.gl; admin and topology pages no longer pull from public CDNs at runtime
- Default WHMCS currency is now resolved (was hardcoded to id=1) so featured-products pricing renders correctly on installs whose default currency is not USD
- `mod_fps_checks` rows now consistently populate the structured columns `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` on every write path; legacy `details` JSON is still written for backward compatibility
- Pre-checkout block + captcha thresholds resolved through one shared helper (`FpsHookHelpers::fps_resolvePreCheckoutThresholds()`) so admin settings drive every code path
- New canonical readers `FpsHookHelpers::fps_readProviderScores()` / `fps_readCheckContext()` prefer the structured columns and fall back to the legacy JSON for pre-v4.2.4 rows
- Quarterly vendor-asset refresh is automated via `/etc/cron.d/fps-vendor-refresh`

See [CHANGELOG.md](CHANGELOG.md) for full change history.

## Features

### Detection Engines (16)

| Engine | Description | Data Source |
|--------|-------------|-------------|
| Cloudflare Turnstile | Invisible bot challenge on login, registration, checkout, tickets | Cloudflare (free) |
| Device Fingerprinting | Canvas, WebGL, font, screen, timezone, audio fingerprinting | Browser-side JS |
| IP Intelligence | Proxy, VPN, Tor, datacenter detection with abuse scoring | ip-api.com, AbuseIPDB, IPQS |
| Email Validation | Disposable detection, SMTP verification, breach checks | Built-in + HaveIBeenPwned |
| Bot Pattern Detection | Plus-addressing, SMS gateways, signup velocity, 15-pattern engine | Built-in heuristics |
| Geographic Analysis | IP/billing/phone/BIN country cross-correlation, impossible travel | Multi-source correlation |
| Behavioral Biometrics | Mouse entropy, keystroke cadence, form fill speed, paste detection | Browser-side JS |
| Velocity Engine | Rate limiting on orders, registrations, failed payments, BIN reuse | Built-in |
| Tor Exit Node Detection | 1,300+ Tor exit nodes updated daily | Tor Project |
| OFAC SDN Screening | Sanctions compliance screening | US Treasury |
| Global Threat Intel | Cross-instance fraud data sharing (GDPR compliant, SHA-256 hashed) | FPS Network |
| Domain Reputation | Domain age, WHOIS analysis, blacklist lookup | Built-in |
| BIN Lookup | Card issuer country validation against billing address | Built-in |
| Phone Validation | Format validation, country prefix matching | Built-in |
| FraudRecord | Community fraud database lookups | FraudRecord.com |
| Duplicate Detection | 15-dimension client linking (IP, email, phone, fingerprint, etc.) | Built-in |

### Platform Features

- **REST API** with 4 tiers: Free / Community / Basic ($19/mo) / Premium ($99/mo)
- **Pre-checkout Blocking** -- Block fraudulent orders before payment processing
- **Live 3D Threat Map** -- Globe.gl visualization with real-time fraud events
- **Admin Dashboard** -- 10 tabs: Dashboard, Review Queue, Client Profile, Rules, API Keys, Settings, and more
- **Client Area Pages** -- Public overview, API docs, topology, GDPR data removal
- **Colorblind Accessibility** -- Toggle button swaps green to blue palette site-wide
- **Auto-provisioning** -- API products created automatically on module activation
- **Cloudflare Turnstile** -- Invisible CAPTCHA on login, registration, checkout, and ticket forms
- **Google Analytics 4 + Microsoft Clarity Integration** -- Server-side fraud tracking, Consent Mode v2 (GDPR), anomaly detection, MCP servers for AI analytics queries. See [Analytics & MCP Integration](docs/wiki/Analytics-MCP-Setup.md).

## Quick Start

### 1. Upload

Download the latest release from the [Releases](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/releases) page, then extract to your WHMCS installation:

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
6. Configure pre-checkout blocking thresholds

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
| Rate limit | 30/day | 120/min, 50K/day | 600/min, 500K/day |
| IP lookup | Basic | Full (abuse history) | Full + bulk |
| Email lookup | -- | Basic | Full (breaches, SMTP) |
| Topology & Stats | Yes | Yes | Yes |
| Country reports | -- | -- | Yes |
| Webhook alerts | -- | -- | Yes |
| Support | Community | Email (24h) | Priority (4h) |

## Module Structure

```
fraud_prevention_suite/
  fraud_prevention_suite.php    # Main module file
  hooks.php                     # WHMCS hooks (17 add_hook calls across 14 sections)
  LICENSE                       # MIT License
  README.md                     # This file
  CHANGELOG.md                  # Version history
  CONTRIBUTING.md               # Contributor guide and code standards
  TODO-hardening.md             # Open hardening follow-ups (post v4.2.x)
  version.json                  # Build manifest (read by public/api.php bootstrap)
  lib/                          # PHP classes (PSR-4)
    Admin/                      # 14 admin tab controllers (Dashboard, Review Queue,
                                #  Client Profile, Rules, API Keys, Settings, Stats,
                                #  Topology, Reports, Alert Log, Bot Cleanup,
                                #  Mass Scan, Trust Management, Global Intel)
    Api/                        # REST API (router, auth, controller, rate limiter)
    Models/                     # Typed data models (FpsCheckContext, FpsRiskResult, ...)
    Providers/                  # 16 detection engine providers + 1 interface
    FpsCheckRunner.php          # Orchestrates the full + pre-checkout pipelines
    FpsConfig.php               # Configuration manager
    FpsHookHelpers.php          # Shared helpers: currency resolver, threshold
                                #  resolver, asset cache-bust, structured-column readers
    FpsStatsCollector.php       # Single event recorder for stats updates
    FpsTurnstileValidator.php   # Cloudflare Turnstile integration
    FpsClientTrustManager.php   # Client trust/blacklist system
    FpsGeoImpossibilityEngine.php  # Multi-signal geographic correlation
    FpsVelocityEngine.php       # Rate-limited event scoring
    FpsWebhookNotifier.php      # Outbound webhook dispatch (SSRF-hardened)
    FpsGlobalIntelCollector.php # Hub push/pull collector
  templates/                    # Smarty + raw HTML templates
    overview.tpl                # Public overview page
    topology.tpl                # 3D threat map (Three.js + Globe.gl, vendored)
    store/landing.tpl           # Product landing page
    apidocs.tpl                 # API documentation
    gdpr.tpl                    # GDPR data removal form
    global.tpl                  # Global threat intelligence
    api-keys.tpl                # Client-area API key management
  assets/                       # CSS, JS, images
    css/                        # Site theme, Lagom2 compat, topology dark theme
    js/                         # Topology globe, admin panel, fingerprint collector
    img/                        # SVG logos
    vendor/                     # Pinned frontend libs (no runtime CDN dependency)
      apexcharts.min.js         #   ApexCharts 3.54.1 (admin Statistics charts)
      three.min.js              #   three.js 0.160.0 (topology 3D globe)
      globe.gl.min.js           #   globe.gl 2.31.0 (topology globe helper)
  public/api.php                # Standalone REST API endpoint
  install/                      # Server module auto-installer (fps_api)
  data/                         # Static data files (disposable domains, Tor nodes, etc.)
  scripts/                      # Operations tooling
    refresh-vendor-assets.sh    #   Quarterly pinned refresh of assets/vendor/*
    fps-vendor-refresh.cron     #   /etc/cron.d entry for the above (Jan/Apr/Jul/Oct)
  docs/                         # Documentation
    audits/                     # Production hardening audit + remediation reports
    wiki/                       # 14 GitHub wiki pages
```

## Documentation

Full documentation is available in the [Wiki](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home):

- [Installation Guide](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Installation-Guide) -- Step-by-step setup
- [Provider Configuration](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Provider-Configuration) -- API keys and free tiers
- [API Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/API-Documentation) -- REST endpoints and examples
- [Architecture Overview](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Architecture-Overview) -- Module structure and data flow
- [Database Schema](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Database-Schema) -- 28 module tables
- [Hook Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Hook-Reference) -- WHMCS hook integrations
- [AJAX Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/AJAX-Reference) -- 70+ admin AJAX actions
- [Adding Providers](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Adding-Providers) -- Custom detection engine guide
- [Troubleshooting](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Troubleshooting) -- Common issues

## Accessibility

FPS includes a **colorblind-friendly mode** toggle (eye icon, bottom-left corner):

- Swaps all green accents to blue (safe for all types of color blindness)
- Adds underlines to links (WCAG 2.1 compliance)
- Increases text contrast ratios
- Thickens focus rings for keyboard navigation
- Persists across pages via localStorage
- Works on all pages including the 3D topology globe

## Security

- Turnstile tokens validated server-side via Cloudflare siteverify API
- API keys stored as SHA-256 hashes (plaintext never persisted)
- Global intel data anonymized with SHA-256 before sharing
- CSRF protection on all admin AJAX endpoints
- Input sanitization via parameterized queries
- Rate limiting on all API endpoints (per-key and per-IP)
- GDPR compliant with user data removal support

To report a security vulnerability, email security@enterprisevpssolutions.com

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Run PHP syntax checks on all files
4. Commit your changes with a descriptive message
5. Push to the branch and create a Pull Request

## License

MIT License - see [LICENSE](LICENSE) for details.

Copyright (c) 2025-2026 [Enterprise VPS Solutions (EVDPS)](https://enterprisevpssolutions.com)

## Support

- **Documentation:** [Wiki](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home)
- **Issues:** [GitHub Issues](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/issues)
- **Email:** support@enterprisevpssolutions.com
- **Live Demo:** [enterprisevpssolutions.com](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite)

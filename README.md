# Fraud Prevention Suite for WHMCS

**Enterprise-grade fraud detection platform for WHMCS with 16+ detection engines, real-time bot blocking, device fingerprinting, and shared global threat intelligence.**

[![WHMCS 8.x | 9.x](https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-0052CC?style=for-the-badge)](https://whmcs.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge)](LICENSE)
![Version](https://img.shields.io/badge/Version-4.2.3-2563eb?style=for-the-badge)

[Live Demo](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite) | [API Plans](https://enterprisevpssolutions.com/store/fraud-intelligence-api) | [Threat Map](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite&page=topology) | [Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home)

---

## What is FPS?

Fraud Prevention Suite (FPS) is a comprehensive fraud detection addon module for WHMCS that protects hosting providers from fraudulent signups, bot attacks, and payment fraud. It integrates 16+ detection engines, provides a REST API for fraud intelligence lookups, and features a real-time 3D threat visualization globe.

**Key differentiators:**

- **Not just a fraud check** -- FPS is a complete fraud intelligence platform with API, threat topology, and global data sharing
- **Open source module, SaaS API** -- The module is free and MIT-licensed; revenue comes from optional paid API tier subscriptions
- **WHMCS-native** -- Built specifically for WHMCS using Capsule ORM, Smarty templates, and the hook system
- **Accessibility-first** -- Built-in colorblind mode toggle and high-contrast support

## Features

### Detection Engines (16+)

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
  hooks.php                     # WHMCS hooks (13 hooks)
  LICENSE                       # MIT License
  README.md                     # This file
  CHANGELOG.md                  # Version history
  .gitlab-ci.yml                # CI pipeline
  lib/                          # PHP classes
    Admin/                      # Admin tab controllers (10 tabs)
    Api/                        # REST API (router, auth, controller, rate limiter)
    Models/                     # Data models
    Providers/                  # 16+ detection engine providers
    FpsConfig.php               # Configuration manager
    FpsTurnstileValidator.php   # Cloudflare Turnstile integration
    FpsClientTrustManager.php   # Client trust/blacklist system
  templates/                    # Smarty templates
    overview.tpl                # Public overview page
    topology.tpl                # 3D threat map (Three.js + Globe.gl)
    store/landing.tpl           # Product landing page
    apidocs.tpl                 # API documentation
    gdpr.tpl                    # GDPR data removal form
    global.tpl                  # Global threat intelligence
  assets/                       # CSS, JS, images
    css/                        # Site theme, Lagom2 compat, topology dark theme
    js/                         # Topology globe, admin panel, fingerprint collector
    img/                        # SVG logos
  public/api.php                # Standalone REST API endpoint
  install/                      # Server module auto-installer
  data/                         # Static data files
  docs/wiki/                    # 14 documentation pages
```

## Documentation

Full documentation is available in the [Wiki](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Home):

- [Installation Guide](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Installation-Guide) -- Step-by-step setup
- [Provider Configuration](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Provider-Configuration) -- API keys and free tiers
- [API Documentation](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/API-Documentation) -- REST endpoints and examples
- [Architecture Overview](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Architecture-Overview) -- Module structure and data flow
- [Database Schema](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Database-Schema) -- All 29 tables
- [Hook Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/Hook-Reference) -- 13 WHMCS hooks
- [AJAX Reference](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite/wiki/AJAX-Reference) -- 62 admin AJAX actions
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

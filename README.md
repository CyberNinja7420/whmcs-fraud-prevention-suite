# Fraud Prevention Suite for WHMCS

**Enterprise-grade fraud detection platform for WHMCS with 16+ detection engines, real-time bot blocking, and shared global threat intelligence.**

[![WHMCS 8.x](https://img.shields.io/badge/WHMCS-8.x-blue)](https://whmcs.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-purple)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-4.2.0-brightgreen)]()

## Live Demo

- **Overview:** [enterprisevpssolutions.com/index.php?m=fraud_prevention_suite](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite)
- **API Plans:** [enterprisevpssolutions.com/store/fraud-intelligence-api](https://enterprisevpssolutions.com/store/fraud-intelligence-api)
- **Threat Map:** [enterprisevpssolutions.com/index.php?m=fraud_prevention_suite&page=topology](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite&page=topology)

## Features

### Detection Engines (16+)
- **Cloudflare Turnstile** - Invisible bot challenge on login, registration, checkout, tickets
- **Device Fingerprinting** - Canvas, WebGL, font, screen, timezone, audio fingerprinting
- **IP Intelligence** - Multi-source: ip-api.com, AbuseIPDB, IPQualityScore
- **Email Validation** - Disposable detection, SMTP verification, breach checks
- **Bot Pattern Detection** - Plus-addressing, SMS gateways, velocity analysis
- **Geographic Analysis** - IP/billing/phone/BIN country cross-correlation
- **Behavioral Biometrics** - Mouse entropy, keystroke cadence, paste detection
- **Velocity Engine** - Rate limiting on orders, registrations, failed payments
- **Tor/VPN/Proxy Detection** - 1,300+ Tor exit nodes, datacenter ASN detection
- **OFAC Screening** - SDN list screening for sanctions compliance
- **Global Threat Intel** - Cross-instance fraud sharing (GDPR compliant, SHA-256 hashed)
- **Domain Reputation** - Age check, WHOIS analysis, blacklist lookup
- **BIN Lookup** - Card issuer country validation
- **Phone Validation** - Format validation, country prefix matching
- **FraudRecord Integration** - Community fraud database lookups

### Platform Features
- **REST API** - 4 tiers (Free/Community/Basic $19/mo/Premium $99/mo) with rate limiting
- **Pre-checkout Blocking** - Block fraudulent orders before payment processing
- **Live Threat Map** - 3D globe with real-time geographic fraud visualization
- **Admin Dashboard** - 10 tabs: Dashboard, Review Queue, Client Profile, Rules, Settings, etc.
- **Client Area Pages** - Public overview, API docs, topology, GDPR data removal
- **Accessibility** - Colorblind-friendly mode toggle, high-contrast support
- **Auto-provisioning** - Products created automatically on activation

## Quick Start

### 1. Install
```bash
# Upload to WHMCS
cp -r fraud_prevention_suite/ /path/to/whmcs/modules/addons/

# Set permissions
chmod -R 755 modules/addons/fraud_prevention_suite/
find modules/addons/fraud_prevention_suite/ -name "*.php" -exec chmod 644 {} \;
```

### 2. Activate
1. WHMCS Admin > System Settings > Addon Modules
2. Find "Fraud Prevention Suite" > Activate
3. Grant admin access permissions

### 3. Configure
1. Go to Addons > Fraud Prevention Suite > Settings tab
2. Add API keys for providers (AbuseIPDB, IPQualityScore - both have free tiers)
3. Configure Cloudflare Turnstile (free at dash.cloudflare.com)
4. Enable desired detection engines
5. Products are auto-created on activation (Free Tier, Basic, Premium)

## Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| WHMCS | 8.0 | 8.10+ |
| PHP | 8.2 | 8.3 |
| MySQL/MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |
| cURL | Required | - |
| Cron | Required | Every 5 minutes |

## API Tiers

| Feature | Free | Basic ($19/mo) | Premium ($99/mo) |
|---------|------|----------------|-------------------|
| Fraud checks | 30/day | 120/min | 600/min |
| IP lookup | Basic | Full (abuse history) | Full + bulk |
| Email lookup | - | Basic | Full (breaches, SMTP) |
| Topology/Stats | Yes | Yes | Yes |
| Country reports | - | - | Yes |
| Webhook alerts | - | - | Yes |
| Support | Community | Email (24h) | Priority (4h) |

## File Structure

```
fraud_prevention_suite/
  fraud_prevention_suite.php   # Main module (config, activate, output, clientarea)
  hooks.php                    # 13 WHMCS hooks (Turnstile, fingerprint, checks, CSS)
  lib/                         # PHP classes
    Admin/                     # Admin tab controllers (10 tabs)
    Api/                       # REST API (router, auth, controller, rate limiter)
    Models/                    # Data models (FpsCheckContext, FpsRuleResult)
    Providers/                 # 16+ detection engine providers
    FpsConfig.php              # Configuration manager
    FpsTurnstileValidator.php  # Cloudflare Turnstile integration
    FpsClientTrustManager.php  # Client trust/blacklist system
  templates/                   # Smarty templates
    overview.tpl               # Public overview page
    topology.tpl               # 3D threat map (Three.js + Globe.gl)
    store/landing.tpl          # Product landing page
    apidocs.tpl                # API documentation
    gdpr.tpl                   # GDPR data removal request
    global.tpl                 # Global threat intelligence
  assets/
    css/fps-lagom2.css         # Lagom2 theme compatibility
    js/fps-topology.js         # Topology globe controller
    js/fps-admin.js            # Admin panel JavaScript
    js/fps-fingerprint.js      # Device fingerprint collector
    img/                       # SVG logos
  public/api.php               # Standalone API endpoint
  install/                     # Server module auto-installer
  data/                        # Static data files (disposable domains, etc.)
  docs/wiki/                   # 14 documentation pages
  LICENSE                      # MIT License
```

## Documentation

Full documentation available in the [Wiki](../../wikis/Home):

- [Installation Guide](../../wikis/Installation-Guide)
- [Provider Configuration](../../wikis/Provider-Configuration)
- [API Documentation](../../wikis/API-Documentation)
- [Architecture Overview](../../wikis/Architecture-Overview)
- [Database Schema](../../wikis/Database-Schema)
- [Hook Reference](../../wikis/Hook-Reference)
- [Troubleshooting](../../wikis/Troubleshooting)

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -am 'feat: add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Create a Merge Request

## License

MIT License - see [LICENSE](LICENSE) for details.

## Support

- **Documentation:** [Wiki](../../wikis/Home)
- **Issues:** [GitLab Issues](../../issues)
- **Email:** support@enterprisevpssolutions.com
- **Live Demo:** [enterprisevpssolutions.com](https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite)

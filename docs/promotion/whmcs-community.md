# WHMCS Community Post
# Post to: https://whmcs.community/forum/41-developer-corner/

## Title
[Free/Open Source] Fraud Prevention Suite v4.2.2 - 16 Detection Engines, 3D Threat Map, REST API

## Body

Hi everyone,

We're releasing our Fraud Prevention Suite for WHMCS as open source (MIT license). No encryption, no encoding -- fully readable PHP code.

**GitHub:** https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite

### What it does

FPS runs 16 detection engines on checkout to catch fraudulent signups and orders:

- FraudRecord, AbuseIPDB, IPQualityScore, MaxMind GeoIP, StopForumSpam, SpamHaus
- Cloudflare Turnstile (replaces CAPTCHA)
- Device fingerprinting (canvas, WebGL, audio, fonts)
- BIN/IIN card validation
- Behavioral biometrics (mouse entropy, keystroke patterns)
- Domain age via RDAP
- Google Safe Browsing
- Velocity engine (rate limits per IP)
- 15-dimension duplicate account detection
- Global threat intel sharing hub

### Admin Panel (14 tabs)
- Dashboard with live stats and manual check
- Review queue with bulk approve/deny
- Client risk profiles with gauge, IP intel, email intel, device fingerprints
- Mass scan (batch check all clients)
- Custom rules (IP block, email pattern, country, velocity, amount, domain age, fingerprint match)
- Statistics with 6 interactive charts
- 3D threat topology (Three.js globe)
- Global intel hub with cross-instance sharing
- Bot cleanup with financial-data-based detection
- Alert log with provider health monitoring
- API key management with tier pricing
- Full settings panel

### REST API
9 endpoints across 4 tiers (Anonymous, Free, Basic $19/mo, Premium $99/mo). The module is free -- the API is the monetization path.

### Technical Details
- WHMCS 7.x and 8.x compatible (tested on 8.13.1)
- PHP 8.2+ with Capsule ORM
- GDPR Art. 17 compliant with self-service data removal
- Colorblind accessibility toggle on all pages
- SSRF-hardened webhook notifications

### Live Demo
https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite

Feedback, bug reports, and pull requests welcome!

Best,
Enterprise VPS Solutions

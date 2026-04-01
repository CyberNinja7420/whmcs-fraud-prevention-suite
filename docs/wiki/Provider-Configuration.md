# Provider Configuration

FPS includes 16 fraud detection providers. Most are free and require no API key. This page details each provider's configuration.

## Providers Requiring API Keys

### Cloudflare Turnstile
- **Type:** Invisible bot challenge (CAPTCHA alternative)
- **Cost:** Free (unlimited)
- **Setup:** [dash.cloudflare.com](https://dash.cloudflare.com) > Turnstile > Add Site
- **Fields:** Site Key, Secret Key
- **What it does:** Injects an invisible challenge on checkout and registration forms. Blocks automated scripts without showing CAPTCHAs to real users.
- **Setting keys:** `turnstile_site_key`, `turnstile_secret_key`, `turnstile_enabled`

### AbuseIPDB
- **Type:** Crowd-sourced IP abuse database
- **Cost:** Free (1,000 checks/day), paid plans available
- **Setup:** [abuseipdb.com/account/api](https://www.abuseipdb.com/account/api)
- **Fields:** API Key (v2)
- **What it does:** Checks if an IP has been reported for abuse (spam, hacking, fraud). Returns abuse confidence score 0-100.
- **Setting keys:** `abuseipdb_api_key`, `abuseipdb_enabled`

### IPQualityScore
- **Type:** Advanced IP/proxy/bot detection
- **Cost:** Free (5,000/month), paid plans available
- **Setup:** [ipqualityscore.com/create-account](https://www.ipqualityscore.com/create-account)
- **Fields:** API Key
- **What it does:** Detects proxies, VPNs, Tor exits, bots, and datacenter IPs. Returns fraud score, bot probability, and recent abuse flag.
- **Setting keys:** `ipqs_api_key`, `ipqs_enabled`
- **Note:** Requires `CURLOPT_ENCODING=''` for gzip responses

### FraudRecord
- **Type:** Hosting-industry shared fraud database
- **Cost:** Free
- **Setup:** [fraudrecord.com/signup](https://www.fraudrecord.com/signup/)
- **Fields:** API Key
- **What it does:** Queries the FraudRecord database for known fraud reports on email/IP/phone. Uses v2 API (SHA1 hashes, JSON POST, camelCase apiKey).
- **Setting keys:** `fraudrecord_api_key` (stored in tbladdonmodules)

## Providers Requiring No API Key

### IP Intelligence (ip-api.com)
- **Rate limit:** 45 requests/minute (free)
- **What it does:** Geolocation, ISP, ASN, proxy/VPN detection
- **Setting key:** `ip_intel_enabled`

### Abuse Signals (StopForumSpam + SpamHaus)
- **What it does:** Checks StopForumSpam HTTP API for email/IP spam reports. Checks SpamHaus ZEN DNS blocklist for IP reputation.
- **Setting key:** `abuse_signal_enabled`

### Domain Reputation
- **What it does:** RDAP domain age lookup, suspicious TLD detection (50+ risky TLDs), Google Safe Browsing (optional key).
- **Setting key:** `domain_reputation_enabled`

### Email Validation
- **What it does:** MX record validation, disposable email detection (500+ domains), free provider detection, role account detection (admin@, info@, etc.)
- **Setting key:** `email_validation_enabled`

### Phone Validation
- **What it does:** Format validation, international calling code verification, carrier type detection
- **Setting key:** `phone_validation_enabled`

### Device Fingerprinting
- **What it does:** Collects Canvas, WebGL, font, screen resolution, timezone, audio context, and WebRTC data. Creates a unique fingerprint hash to link accounts across sessions.
- **Setting key:** `fingerprint_enabled`

### Behavioral Biometrics
- **What it does:** Analyzes mouse movement entropy, keystroke cadence, form fill speed, and paste detection. Distinguishes human users from automated scripts.
- **Setting key:** (always active when fingerprint data is available)

### Velocity Engine
- **What it does:** Rate limiting across 5 dimensions: orders per IP per hour, registrations per IP per day, failed payments per client per day, checkout attempts per IP per hour, BIN reuse per day.
- **Setting keys:** `velocity_orders_per_ip_hour`, `velocity_regs_per_ip_day`, etc.

### Geo-Impossibility Engine
- **What it does:** Cross-correlates IP country, billing country, phone country prefix, and BIN/IIN country. Flags mismatches that indicate fraud.
- **Setting key:** (always active)

### OFAC Screening
- **What it does:** Checks client names against the US Treasury OFAC SDN (Specially Designated Nationals) sanctions list.
- **Setting key:** `ofac_screening_enabled`

### Tor/Datacenter Detection
- **What it does:** Checks IP against known Tor exit nodes (auto-refreshed daily) and datacenter ASN list (200+ hosting providers).
- **Setting key:** (always active when IP is available)

### BIN/IIN Lookup
- **What it does:** Looks up credit card BIN (first 6-8 digits) to determine issuing country, card type, and bank. Flags mismatches with billing country.
- **Setting key:** `bin_lookup_enabled`

### SMTP Verification
- **What it does:** Connects to the recipient mail server and verifies the email address exists (without sending). Slow (2-5 seconds) -- only runs on full checks, not pre-checkout.
- **Setting key:** (runs in full check mode only)

## Provider Weight Configuration

Each provider has a configurable weight that affects how much its score contributes to the final risk score. Weights are stored in `mod_fps_settings` as `provider_weight_{name}`.

Default weights:
| Provider | Weight | Reason |
|----------|--------|--------|
| fraudrecord | 1.0 | Industry standard |
| ip_intel | 1.0 | Good signal |
| email_verify | 1.0 | Good signal |
| fingerprint | 1.0 | Strong signal |
| geo_mismatch | 1.0 | Strong signal |
| custom_rules | 1.0 | Admin-defined |
| velocity | 1.0 | Good signal |
| domain_age | 1.0 | Moderate signal |
| tor_datacenter | 1.3 | Strong fraud indicator |
| smtp_verify | 0.8 | Can have false positives |
| geo_impossibility | 1.1 | Strong signal |
| behavioral | 0.9 | Newer, calibrating |
| global_intel | 1.5 | Cross-instance confirmed |

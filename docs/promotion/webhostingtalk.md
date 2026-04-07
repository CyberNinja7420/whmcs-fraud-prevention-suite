# WebHostingTalk (WHT) Post
# Post to: https://www.webhostingtalk.com/forumdisplay.php?f=73 (Software & Scripts Offers)

## Title
[Free/Open Source] WHMCS Fraud Prevention Suite - 16 Engines, 3D Globe, REST API (MIT License)

## Body

Hello WHT,

We've open-sourced our fraud prevention module for WHMCS. It's been running in production on our hosting company for months and we decided to share it with the community.

**GitHub:** https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite
**License:** MIT (no encryption, no ionCube, fully readable code)
**Price:** Free. Zero cost. Open source.
**Live demo:** https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite

**What makes this different from MaxMind/FraudRecord alone:**

1. It doesn't just check one provider -- it runs 16 detection engines in parallel and produces a composite risk score
2. Device fingerprinting catches multi-accounters even when they change IP/email
3. Behavioral biometrics detect bots by mouse movement and typing patterns
4. The velocity engine catches burst-signup attacks in real time
5. A 3D globe shows you where fraud is coming from (yes, it's cool looking)
6. Built-in bot cleanup tool uses actual WHMCS financial data (invoices, payments) to identify bot accounts with 100% accuracy
7. REST API lets you monetize the fraud intelligence data with tiered subscriptions

**Detection engines included:**
FraudRecord | AbuseIPDB | IPQualityScore | MaxMind GeoIP | StopForumSpam | SpamHaus | Cloudflare Turnstile | BIN/IIN Lookup | Domain RDAP | Device Fingerprinting | Behavioral Biometrics | Velocity Engine | Geographic Analysis | Duplicate Detection | Google Safe Browsing | Global Threat Intel

**Requirements:**
- WHMCS 7.x or 8.x (tested on 8.13.1)
- PHP 8.2+
- API keys for providers you want to use (most have free tiers)

Installation is standard -- upload to modules/addons/, activate in admin. Setup wizard walks you through provider API keys.

Happy to answer questions. Pull requests welcome on GitHub.

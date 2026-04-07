# Reddit Post: r/webhosting

## Title
Open-sourced our WHMCS fraud detection module -- 16 engines, 3D threat map, REST API (MIT license, no encryption)

## Body

Hey r/webhosting,

We've been running a hosting company for 10+ years and fraud/bots have always been a massive pain. After building an internal fraud detection system for our WHMCS installation, we decided to open-source the whole thing.

**What it is:** A WHMCS addon module that runs 16 detection engines on every signup/order to catch fraud before it costs you money.

**What it checks:**
- IP reputation (MaxMind, AbuseIPDB, IPQualityScore, StopForumSpam, SpamHaus)
- Email validation (disposable detection, breach databases)
- Device fingerprinting (canvas, WebGL, audio, fonts)
- Cloudflare Turnstile bot protection
- BIN/IIN card validation
- Behavioral biometrics (mouse entropy, keystroke patterns)
- Velocity limits (orders/IP, failed payments)
- 15-dimension duplicate account detection
- Geographic impossibility (IP country vs billing vs phone vs BIN)

**Cool features:**
- 3D globe (Three.js) showing fraud events in real-time
- REST API with 4 tiers so you can monetize the fraud intelligence data
- Global intel hub -- share threat data across multiple WHMCS instances
- Bot cleanup tool that identifies fake accounts using actual financial data (not heuristics)
- Full GDPR compliance with self-service data removal

**The code:** https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite

MIT licensed. No ionCube, no encryption, no obfuscation. You can read every line, fork it, modify it. The module itself is free -- we make revenue from optional paid API tier subscriptions.

**Live demo** running on our production site: https://enterprisevpssolutions.com/index.php?m=fraud_prevention_suite

Would love feedback from other hosting providers. What fraud patterns are you seeing in 2026? What would you want from a tool like this?

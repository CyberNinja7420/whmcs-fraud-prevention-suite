---
title: "Building a 16-Engine Fraud Detection System for Web Hosting (Open Source)"
published: true
tags: security, php, webdev, opensource
cover_image: https://raw.githubusercontent.com/CyberNinja7420/whmcs-fraud-prevention-suite/main/docs/demo/09-admin-topology.png
---

# Building a 16-Engine Fraud Detection System for Web Hosting (Open Source)

Web hosting companies lose thousands of dollars every month to fraudulent signups. Bots create fake accounts, stolen credit cards get used for VPS purchases, and chargebacks eat into margins. After dealing with this for 10 years, we built a comprehensive fraud detection system and open-sourced it.

**GitHub:** [CyberNinja7420/whmcs-fraud-prevention-suite](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite)

## The Problem

A typical hosting company sees:
- 30-60% of new signups are bots or fraudulent
- Each chargeback costs $15-25 in fees alone (plus the hosting resources consumed)
- Single-provider fraud checks miss sophisticated attackers who rotate IPs and emails

## Our Approach: 16 Engines in Parallel

Instead of relying on one fraud database, we run 16 detection engines simultaneously and produce a composite risk score (0-100):

| Engine | What it catches |
|--------|----------------|
| IP Reputation (4 providers) | Known proxy/VPN/Tor, datacenter IPs, previously reported IPs |
| Email Validation | Disposable emails, undeliverable addresses, breach databases |
| Device Fingerprinting | Canvas, WebGL, audio, fonts -- identifies users across accounts |
| Cloudflare Turnstile | Automated bot submissions |
| BIN/IIN Lookup | Stolen card numbers, prepaid cards |
| Behavioral Biometrics | Mouse entropy, keystroke timing, form fill speed |
| Velocity Engine | Burst signups from same IP, rapid-fire orders |
| Geographic Analysis | IP in Russia but billing address in Texas |
| Duplicate Detection | 15-dimension cross-matching across all accounts |
| Domain Age | Brand-new domains used for registration |

## Architecture

The module is built for WHMCS (PHP/MySQL) using:
- **Capsule ORM** for all database operations
- **Smarty templates** for admin UI
- **Three.js + Globe.gl** for the 3D threat visualization
- **Chart.js** for analytics dashboards
- **Token bucket algorithm** for API rate limiting

The scoring pipeline runs in ~200ms for cached lookups, ~2-3 seconds for full checks with all external API calls.

## The 3D Threat Globe

One of the more visually impressive features -- a real-time 3D globe showing fraud events as arcs between attacker origin and target. Built with Three.js and Globe.gl, it shows:
- Country hotspots (sized by event count)
- Live event feed
- Time-range filtering (1H to all-time)

## REST API for Monetization

The module itself is free, but it includes a built-in REST API with tiered access. This lets hosting companies monetize their fraud intelligence data:

- **Anonymous:** Stats and topology (free, no key needed)
- **Free tier:** Basic IP lookups (5,000/day)
- **Basic ($19/mo):** Full IP + email intelligence (50,000/day)
- **Premium ($99/mo):** Everything + bulk lookups + country reports (500,000/day)

## Open Source Philosophy

The entire module is MIT-licensed with zero encryption or obfuscation. We believe security tools should be transparent and auditable. You can read every line of code, fork it, modify it.

If you run a WHMCS-based hosting company, check it out: [GitHub Repository](https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite)

Stars, issues, and PRs welcome.

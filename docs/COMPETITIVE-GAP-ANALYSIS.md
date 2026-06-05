# FPS v5.4.0 — Competitive Gap Analysis

**Date:** 2026-06-04
**Subject:** WHMCS Fraud Prevention Suite (FPS) v5.4.0 vs. leading online fraud-prevention platforms
**Audience:** FPS product/engineering, VPS/hosting business owner
**Method:** Web research against primary vendor docs/pricing pages (cited inline). Listicles avoided where a primary source existed.

---

## 0. Executive summary

FPS v5.4.0 is already broad — arguably broader at the *hosting-workflow* layer (cross-install Global Intel, FraudRecord, OFAC, chargeback evidence compiler, link/identity-graph analysis, GDPR tooling, rules engine, 3D topology) than any single off-the-shelf WHMCS addon. Where the commercial leaders pull ahead is in four areas:

1. **Behavioral biometrics** (typing cadence, mouse/touch dynamics) — none of our signals are behavioral.
2. **Consortium/network scale ML scoring** — Stripe, Sift, Kount, MaxMind score against billions of cross-merchant events; our ML surface is thinner.
3. **Card/BIN intelligence depth** — we do BIN/IIN lookup but the spec below shows we should be extracting *every* card attribute (level, commercial/corporate flags, prepaid, issuer bank, country) for *all* card types, and using datacenter/commercial-card mismatch as a hosting-specific signal.
4. **3DS2 / SCA orchestration** and **chargeback-guarantee economics** — leaders shift liability; we compile evidence but don't trigger step-up auth or guarantee.

Full ranked gap list and the BIN deep-dive are below.

---

## 1. Comparison matrix

Legend: ✓ = full, ◑ = partial/limited, ✗ = absent. "Us" = FPS v5.4.0.

| Capability | **Us (FPS 5.4)** | SEON | Stripe Radar | Sift | Kount (Equifax) | IPQS | MaxMind minFraud | Fingerprint | Riskified | Signifyd | FraudLabs Pro | Arkose |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Real-time risk score (ML) | ◑ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ◑ |
| Rules engine (custom) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ◑ | ✓ | ◑ | ✓ | ◑ |
| Explainable reason codes | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ◑ | ◑ |
| Device fingerprinting | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ |
| **Behavioral biometrics** | ✗ | ◑ | ✗ | ◑ | ◑ | ✗ | ✗ | ◑ | ✓ | ◑ | ✗ | ✓ |
| Email/phone/IP enrichment | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ✓ |
| Digital footprint (social/breach) | ✓ | ✓ | ✗ | ◑ | ◑ | ◑ | ◑ | ✗ | ◑ | ◑ | ◑ | ✗ |
| VPN/proxy/Tor/datacenter ASN | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Residential-proxy detection** | ◑ | ✓ | ◑ | ✓ | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ◑ | ✓ |
| BIN/card lookup | ◑ | ✓ | ✓ | ◑ | ✓ | ✓ | ◑ | ✗ | ✓ | ✓ | ✓ | ✗ |
| **Full card-level/commercial flags** | ◑ | ◑ | ✓ | ◑ | ✓ | ✓ | ◑ | ✗ | ✓ | ✓ | ✓ | ✗ |
| Velocity checks | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ◑ |
| Account-takeover / login defense | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ◑ | ✓ | ◑ | ◑ | ✗ | ✓ |
| Geo-impossibility / impossible travel | ✓ | ✓ | ◑ | ✓ | ✓ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ |
| Bot/automation detection | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ | ◑ | ◑ | ◑ | ✓ |
| **AI-agent / headless-bot detection** | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ✗ | ✓ | ✗ | ✗ | ✗ | ✓ |
| CAPTCHA / challenge (Turnstile/hCaptcha) | ✓ | ◑ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ◑ | ✓ |
| **3DS2 / SCA step-up orchestration** | ✗ | ◑ | ✓ | ◑ | ✓ | ◑ | ✗ | ✗ | ✓ | ✓ | ◑ | ◑ |
| OFAC/sanctions screening | ✓ | ✓ | ◑ | ◑ | ✓ | ◑ | ✗ | ✗ | ◑ | ◑ | ◑ | ✗ |
| Chargeback evidence compiler | ✓ | ◑ | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| **Chargeback financial guarantee** | ✗ | ✗ | ◑ | ✗ | ◑ | ✗ | ✗ | ✗ | ✓ | ✓ | ✗ | ✗ |
| Refund/return/policy-abuse detection | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ◑ | ◑ | ✓ | ✓ | ◑ | ✗ |
| Cross-merchant **consortium network** | ◑ (Global Intel) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ |
| Hosting-industry shared db (FraudRecord) | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Link/identity-graph (fraud-ring) | ✓ | ✓ | ◑ | ✓ | ✓ | ◑ | ◑ | ◑ | ✓ | ✓ | ◑ | ✓ |
| Whitebox rule recommendations | ✓ | ◑ | ✓ (AI rules) | ✓ | ◑ | ✗ | ✗ | ✗ | ◑ | ◑ | ✗ | ✗ |
| Public REST API + keys + rate limit | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Webhooks | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ◑ | ✓ | ✓ | ✓ | ✓ | ✓ |
| GDPR export/erase tooling | ✓ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ | ◑ |
| Analytics (GA4/Clarity) + consent | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Native WHMCS integration | ✓ | ◑ | ◑ | ✗ | ✗ | ◑ | ✓ | ✗ | ✗ | ✗ | ✓ | ✗ |

Matrix sourcing: SEON [products](https://seon.io/products/fraud-prevention/) and [900+ signals](https://seon.io/resources/news/seon-900-first-party-risk-signals/); Stripe Radar [product](https://stripe.com/radar) and [rules docs](https://docs.stripe.com/radar/rules); Sift [platform](https://sift.com/platform/); Kount [Identity Trust Global Network](https://investor.equifax.com/news-events/press-releases/detail/1235/kount-an-equifax-company-expands-international-presence) and [Kount 360](https://investor.equifax.com/news-events/press-releases/detail/1368/equifax-launches-identity-proofing-solution-in); IPQS [transaction scoring](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring); MaxMind [risk score reasons](https://support.maxmind.com/hc/en-us/articles/28488469041947-Risk-Score-Reasons); Fingerprint [Smart Signals](https://fingerprint.com/products/smart-signals/); Riskified vs Signifyd [comparison](https://www.riskified.com/lp/signifyd-vs-riskified/); Signifyd [guaranteed protection](https://www.signifyd.com/guaranteed-fraud-protection/); FraudLabs Pro [WHMCS tutorial](https://www.fraudlabspro.com/resources/tutorials/how-to-use-the-fraudlabs-pro-module-on-whmcs-8/); Arkose [Titan platform](https://www.businesswire.com/news/home/20260129990839/en/Arkose-Labs-Unleashes-Arkose-Titan-First-Unified-Platform-to-Stop-Malicious-Bots-AI-Agents-and-Human-Fraud-Networks).

---

## 2. GAPS WE HAVE — ranked by value for a hosting/VPS business

> Ranking weights: how directly the gap stops hosting-specific fraud (stolen-card VPS provisioning, abuse-account farming, chargeback losses, bot signups for spam/mining) vs. effort to add.

### Tier 1 — high value, build first

1. **Full card-level + commercial/corporate/prepaid/datacenter-card extraction.** We do BIN lookup but should capture *every* attribute and turn prepaid/commercial/corporate/issuer-country mismatch into hard signals — stolen-card VPS buyers overwhelmingly use prepaid/virtual and foreign-issued cards. Leaders expose `is_prepaid`, `is_commercial`, card level (Classic/Gold/Platinum/Business/Corporate), issuer bank, issuer country. *Have it:* IPQS, MaxMind, FraudLabs Pro, Stripe, Riskified, Signifyd, Neutrino, bindb. See deep dive §3. Cite: [Neutrino BIN fields](https://www.neutrinoapi.com/api/bin-lookup/), [IPQS transaction scoring](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring).

2. **Residential-proxy detection (not just datacenter/VPN/Tor).** Residential and ISP-grade proxies now defeat plain ASN/datacenter checks; abusers buy residential exits to look "clean." We have datacenter ASN — we need residential/ISP/mobile-proxy classification. *Have it:* IPQS, SEON, Fingerprint, Sift, Kount, Signifyd, Riskified. Cite: [IPQS proxy detection](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring), [SEON device intelligence](https://docs.seon.io/getting-started/device-intelligence).

3. **3DS2 / SCA step-up orchestration.** Instead of binary block/allow, leaders trigger 3D Secure on medium-risk transactions to shift chargeback liability to the issuer — directly cuts hosting chargeback losses. We have CAPTCHA challenges but no payment-auth step-up. *Have it:* Stripe Radar (request 3DS rule), Kount, Signifyd, Riskified. Cite: [Stripe Radar rules](https://docs.stripe.com/radar/rules), [Stripe AI dynamic rules](https://stripe.com/blog/using-ai-dynamic-radar-rules).

4. **Consortium/network ML scoring at scale.** Our Global Intel is cross-install but small; leaders score against billions of events (Stripe $1.9T/yr; Sift ~1T events/700+ brands; Kount 32B interactions; MaxMind minFraud Network). For a single hosting shop this is the single biggest accuracy multiplier. *Have it:* Stripe, Sift, Kount, MaxMind, SEON, Signifyd. Cite: [Stripe Radar](https://stripe.com/radar), [Sift platform](https://sift.com/platform/), [Kount network](https://investor.equifax.com/news-events/press-releases/detail/1235/kount-an-equifax-company-expands-international-presence).

5. **AI-agent / advanced headless-bot detection.** GenAI agents and headless browsers now drive signup abuse and credential stuffing. Fingerprint and Arkose explicitly classify headless browsers, automation tools, AI agents, and VMs. Our bot-signup detection is heuristic; upgrade to browser-leak/inconsistency detection. *Have it:* Fingerprint, Arkose. Cite: [Fingerprint bot detection](https://fingerprint.com/blog/bot-detection/), [Arkose Titan](https://www.helpnetsecurity.com/2026/01/30/arkose-labs-titan/).

### Tier 2 — meaningful value

6. **Behavioral biometrics.** Typing cadence, mouse/touch dynamics, session navigation speed to build per-user baselines — strongest for account-takeover and bonus/trial abuse. *Have it:* Riskified, Arkose, BioCatch-class, partially Sift/Kount/Fingerprint. Cite: [Security Boulevard 2026 guide](https://securityboulevard.com/2026/03/ai-powered-adaptive-authentication-and-behavioral-biometrics-the-enterprise-guide-2026/).

7. **Chargeback financial guarantee model (optional/strategic).** Signifyd/Riskified take liability and reimburse fraud chargebacks within 48h. Probably out of scope to *underwrite*, but worth a "guarantee-style confidence tier" that auto-approves and tracks realized loss. *Have it:* Signifyd, Riskified. Cite: [Signifyd guaranteed protection](https://www.signifyd.com/guaranteed-fraud-protection/), [Riskified vs Signifyd](https://www.riskified.com/lp/signifyd-vs-riskified/).

8. **AI-assisted rule authoring (natural-language → rule).** Stripe's rule editor has a built-in LLM that constructs rules from a plain-English prompt, and AI-suggested adaptive rules. We do whitebox rule *recommendations* from blocks; add NL-to-rule authoring on top. *Have it:* Stripe Radar. Cite: [Stripe AI dynamic rules](https://stripe.com/blog/using-ai-dynamic-radar-rules).

9. **Identity-proofing / document + biometric verification (high-risk step-up).** Kount 360 added doc verification + facial-recognition biometric checks for high-risk onboarding; useful as an optional escalation for flagged VPS orders rather than outright decline. *Have it:* Kount, SEON (KYC), Sumsub-class. Cite: [Equifax/Kount identity proofing](https://investor.equifax.com/news-events/press-releases/detail/1368/equifax-launches-identity-proofing-solution-in).

10. **Email/phone "first-party signal" depth (account-age, linked-accounts, breach recency).** SEON surfaces 900+ first-party signals incl. registration-date of email/social, data-breach linkage, phone-carrier/line-type. We have breach + social presence; deepen the email/phone *age and linkage* signals which strongly separate real customers from throwaway fraud identities. *Have it:* SEON, Sift, Kount. Cite: [SEON 900+ signals](https://seon.io/resources/news/seon-900-first-party-risk-signals/), [SEON digital footprint](https://seon.io/products/digital-footprint-solution/).

### Tier 3 — nice-to-have / parity polish

11. **SMS/OTP step-up verification** for first-time or device-flagged buyers (FraudLabs Pro ships this as an add-on). Cite: [FraudLabs Pro WHMCS](https://www.fraudlabspro.com/resources/tutorials/how-to-use-the-fraudlabs-pro-module-on-whmcs-8/).
12. **Phishing / AiTM-aware login protection** (Arkose adds reverse-proxy/AITM detection). Cite: [Arkose phishing protection](https://www.prnewswire.com/news-releases/arkose-labs-adds-advanced-phishing-protection-to-its-industry-leading-bot-management-platform-arkose-bot-manager-301802563.html).
13. **Proximity / device-farm detection** (Fingerprint Proximity Detection to catch device farms). Cite: [Fingerprint Smart Signals press](https://thepaypers.com/fraud-and-fincrime/news/fingerprint-launches-new-smart-signals-and-platform-upgrades).

---

## 3. BIN / CARD INTELLIGENCE DEEP DIVE

### 3.1 Why this matters specifically for hosting/VPS
Stolen-card VPS provisioning is the dominant card-fraud vector in hosting. The single highest-signal card attributes for this business are: **prepaid/virtual flag**, **commercial/corporate flag**, **issuer country vs. IP/billing country mismatch**, and **card level**. Privacy.com / Green Dot / Vanilla-type prepaid cards and foreign-issued cards correlate heavily with abuse. IPQS explicitly markets stolen-credit-card and prepaid/virtual-card detection as chargeback-prevention signals ([IPQS transaction scoring](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring)).

### 3.2 What full card-detail extraction looks like at the leaders
The richest BIN payloads (IPQS, MaxMind, Neutrino, bindb, FraudLabs Pro, Stripe `payment_method_details.card`) return *all* of:

| Field | Example values | Hosting use |
|---|---|---|
| `brand` / `scheme` | Visa, Mastercard, Amex, Discover, JCB, UnionPay | base routing |
| `type` | credit, debit, charge, **prepaid** | prepaid = elevated risk |
| `level` / `category` | Classic, Gold, Platinum, **Business, Corporate**, Signature, World | corporate VPS buyer vs. throwaway |
| `is_prepaid` | true/false | hard risk flag |
| `is_commercial` / `is_corporate` / `is_business` | true/false | distinguishes legit business buyers |
| `is_reloadable` | true/false | reloadable prepaid = higher risk |
| `issuer` / `bank` | bank name, website, phone | manual review + issuer-country |
| `country` / `country_code` | ISO-2 / ISO-3 | mismatch vs. IP & billing |
| `currency` | ISO-4217 | mismatch signal |
| `bin_length` | 6 / 8 / 9–11 digit | modern 8-digit BIN accuracy |

Source for the field set: [Neutrino BIN Lookup](https://www.neutrinoapi.com/api/bin-lookup/) (returns brand, type DEBIT/CREDIT/CHARGE, category CLASSIC/BUSINESS/CORPORATE/PLATINUM/PREPAID, `is_commercial`, `is_prepaid`, `is_reloadable`, issuer name/website/phone, country codes, currency; ~2.5M BIN records, supports 6/8/9–11-digit BINs); [bindb](https://www.bindb.com/bin-database); [FraudLabs Pro card validation](https://www.fraudlabspro.com/resources/tutorials/how-to-use-the-fraudlabs-pro-module-on-whmcs-8/) (BIN lookup, prepaid/virtual/gift detection, issuing-country vs billing-country check).

**Note on 8-digit BINs:** Card networks migrated to 8-digit BINs; lookups keyed on only the first 6 digits increasingly misclassify level/type. Use a source that supports 8-digit (and 9–11-digit) BIN keys — Neutrino and the paid providers do.

### 3.3 Concrete derived signals to implement (beyond raw fields)
- **Prepaid/virtual card → +risk** (configurable weight; auto-review for VPS).
- **Issuer country ≠ IP country ≠ billing country** → geo-card-mismatch score (FraudLabs Pro and MaxMind both do issuing-country vs billing-country).
- **Commercial/corporate card + matching business email domain + aged email** → trust *decrease* (legit business buyer) — use the card-level data to *reduce* friction for good customers, not only to block.
- **BIN velocity:** many distinct BINs from one client/IP, or one BIN across many clients (card testing) — leaders treat multi-card attempts as a top velocity signal.
- **BIN-to-IP country mismatch** as a standalone reason code (Neutrino returns IP-to-BIN country matching natively).

### 3.4 Best BIN data sources (free + paid)

**Free / freemium:**
- **binlist.net** — free, scheme/type/brand/prepaid/bank/country; *throttled to ~5 req/hour* (HTTP 429) — fine for spot checks, not production volume. [binlist.net](https://binlist.net/)
- **HandyAPI BIN/IIN List** — free full BIN/IIN lookup, issuer/type/country/metadata; better rate limits than binlist.net for a free tier. [Handy API BIN list](https://www.handyapi.com/bin-list)
- **binlist.io** — open-source DB, 343k BINs / 13k banks, scheme/level/type/bank/country; self-hostable so no rate limit. [binlist.io](https://binlist.io/)
- **bincheck.io / bincodes / api-ninjas** — free tiers for validation/level/type. [bincheck.io API](https://bincheck.io/api)

**Paid (production-grade, recommended primary):**
- **IPQualityScore** — BIN data *inside* the transaction-scoring API (prepaid, stolen-card, virtual, commercial flags) alongside proxy/email/device — best single integration for hosting since it co-locates card + IP + email risk. [IPQS transaction scoring](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring)
- **Neutrino API BIN Lookup** — ~2.5M BINs, full commercial/prepaid/reloadable flags, IP-to-BIN matching, 6/8/9–11-digit support. [Neutrino BIN](https://www.neutrinoapi.com/api/bin-lookup/)
- **bindb** — large licensed BIN/IIN database, issuer + country, bulk/file licensing for self-hosting. [bindb](https://www.bindb.com/bin-database)
- **MaxMind minFraud** — card metadata feeds the risk score + risk-score-reasons (already a provider you integrate). [MaxMind risk score reasons](https://support.maxmind.com/hc/en-us/articles/28488469041947-Risk-Score-Reasons)

**Recommendation:** keep IPQS/MaxMind as the *scoring* path (they already weight card+IP+email together), and add **Neutrino or self-hosted binlist.io** as a dedicated full-field BIN enrichment source so the Client Profile / Review Queue can display *every* card attribute (level, commercial/corporate, prepaid, reloadable, issuer bank, issuer country) for every card type — including commercial/corporate cards — for analyst decisioning. Avoid binlist.net for production due to the 5/hour cap.

---

## 4. 2025–2026 TRENDS

1. **GenAI-enabled fraud is now mainstream.** Sift's 2025 Digital Trust Index reports GenAI-enabled scams up ~456% (May 2024→Apr 2025) and breached data up 186%; Experian's 2026 forecast names agentic AI and deepfake candidates top threats; some reports cite GenAI fraud up >1,200% in 2025. Cite: [Sift Q2 2025 AI fraud](https://sift.com/index-reports-ai-fraud-q2-2025/), [Experian 2026 forecast](https://www.experianplc.com/newsroom/press-releases/2026/experian-s-new-fraud-forecast-warns-agentic-ai--deepfake-job-can).

2. **AI-agent / bot arms race.** Vendors now explicitly detect headless browsers, automation frameworks, VMs, and *authorized vs malicious AI agents*. Arkose Titan and Fingerprint both ship AI-agent classification; Arkose frames it as making automated attacks "economically unviable." Cite: [Fingerprint bot detection](https://fingerprint.com/blog/bot-detection/), [Arkose Titan](https://www.businesswire.com/news/home/20260129990839/en/Arkose-Labs-Unleashes-Arkose-Titan-First-Unified-Platform-to-Stop-Malicious-Bots-AI-Agents-and-Human-Fraud-Networks).

3. **Device intelligence at planetary scale.** Fingerprint surpassed 1B device identifications/month (Feb 2026) with 65% ARR growth — device intelligence is the fastest-growing fraud layer. Cite: [Fingerprint 1B/month](https://www.businesswire.com/news/home/20260224743088/en/Fingerprint-Reports-65-ARR-Growth-Surpasses-1-Billion-Device-Identifications-Per-Month-as-Enterprises-Adopt-Device-Intelligence-to-Combat-AI-Driven-Fraud).

4. **Behavioral biometrics going mainstream / into Zero Trust.** Gartner (via 2026 enterprise guide) projects 60% of Zero Trust tools will embed AI incl. behavioral biometrics by 2028. Cite: [Security Boulevard 2026 guide](https://securityboulevard.com/2026/03/ai-powered-adaptive-authentication-and-behavioral-biometrics-the-enterprise-guide-2026/).

5. **Identity graphs / cross-dimensional identity trust.** Sift's "Identity Trust XD" and Kount's "Identity Trust Global Network" both stitch fragmented signals into a holistic, time-aware identity view — our Link Analysis tab is the right direction; deepen it. Cite: [Sift platform](https://sift.com/platform/), [Kount network](https://investor.equifax.com/news-events/press-releases/detail/1235/kount-an-equifax-company-expands-international-presence).

6. **Adaptive / AI-authored rules + risk-based step-up.** Stripe's adaptive rules combine ML with live issuer CVC/postal response and natural-language rule authoring; the trend is dynamic rules + 3DS step-up rather than static block-lists. Cite: [Stripe AI dynamic rules](https://stripe.com/blog/using-ai-dynamic-radar-rules).

7. **Residential-proxy & device-spoofing detection** as table stakes — datacenter/VPN/Tor lists no longer suffice; leaders classify residential/mobile proxies and detect device/location spoofing. Cite: [IPQS proxy detection](https://www.ipqualityscore.com/documentation/proxy-detection-api/transaction-scoring), [Arkose detection](https://www.arkoselabs.com/platform/).

8. **First-party signal expansion.** SEON's move to 900+ first-party signals (email/phone/IP/device, breach linkage, social/registration age) shows enrichment depth — not just third-party blacklists — is the differentiator. Cite: [SEON 900+ signals](https://seon.io/resources/news/seon-900-first-party-risk-signals/).

---

## 5. Hosting-community context (WHMCS / WHT / LowEndTalk)

- **FraudRecord** remains the hosting-specific consortium standard (cross-host shared abuser DB, 1–10 score, query+report). We already integrate it — this is a genuine edge over the general-purpose leaders, none of whom carry hosting-industry abuse history. Cite: [FraudRecord](https://fraudrecord.com/), [SkynetHosting guide](https://skynethosting.net/blog/how-to-configure-whmcs-fraud-protection/).
- **MaxMind + FraudRecord combo** is the de-facto community baseline (MaxMind for real-time IP/VPN risk, FraudRecord for repeat-offender history). FPS already covers both plus far more. Cite: [SkynetHosting guide](https://skynethosting.net/blog/how-to-configure-whmcs-fraud-protection/), [LowEndTalk MaxMind](https://lowendtalk.com/discussion/151541/why-hosting-providers-like-to-use-maxmind-to-judge-fraud).
- **Community-recommended manual controls** we should keep surfacing: require longer billing terms / ID for high-risk-country orders, disable mail ports by default on VPS, resource-limit triggers, zero-tolerance no-refund removal. These are policy levers FPS can encode as rules/recommendations. Cite: [WHT prevent abuses](https://www.webhostingtalk.com/showthread.php?t=1838334), [Spamhaus hosting sign-ups](https://www.spamhaus.org/resource-hub/service-providers/how-hosting-providers-can-battle-fraudulent-sign-ups/).

---

## 6. What we already do better than most

For balance: cross-install **Global Intel**, **FraudRecord** integration, **OFAC sanctions screening**, **chargeback evidence auto-compiler (CE 3.0 style)**, **link/identity-graph analysis**, **3D topology**, **whitebox rule recommendations**, **GA4/Clarity analytics with EEA consent + anomaly detection**, **GDPR export/erase**, and **native WHMCS integration with 15 admin tabs** — collectively this is a wider *operational* surface than any single WHMCS fraud addon (FraudLabs Pro / MaxMind module) and competitive at the workflow layer with the enterprise platforms. The gaps above are about *signal depth* (behavioral biometrics, residential proxy, full card intelligence, network-scale ML) and *liability-shifting actions* (3DS2, guarantee), not breadth of tooling.

---

*Every external claim above links to a primary vendor doc, pricing page, or named industry report. Listicles were used only where no primary source carried the specific data point.*

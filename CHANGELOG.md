# Changelog

All notable changes to the Fraud Prevention Suite are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

---

## [4.2.3] - 2026-04-08

### Added
- **Mass scan rich results table** -- 9-column table with client name/ID/email, IP, country flag (Unicode regional indicator), score bar with gradient fill, risk level badge, action taken badge, scan time, and provider tooltip; locked rows highlighted with red tint
- **Review queue Type column** -- new column showing check type badge (Pre-checkout, New Order, Registration, Bot Block, Manual, Test) with distinct colors and icons
- **Review queue Archive Guest Checks button** -- admin tool to bulk-archive all unreviewed pre-checkout guest entries via single AJAX call
- **Topology All Time button** -- added "All Time" range option (hours=0 sentinel); backend skips date filter entirely for full-history view
- **ClientDelete hook** -- new WHMCS hook in hooks.php auto-archives unreviewed fraud checks when a client is deleted directly through WHMCS admin (outside FPS purge), preventing orphaned records from appearing in the review queue

### Fixed
- **Mass scan returning 0 clients** -- four bugs: empty status string passed as filter, FpsCheckResult object not serialized (toArray()), date/skip filters silently ignored, total count using hardcoded "Active" status
- **Review queue Client #0 display** -- pre-checkout guest checks (client_id=0) now show "Guest" badge with "Pre-checkout visitor" note instead of "Client #0"
- **Review queue orphaned client rows** -- checks from deleted clients now show "Deleted #N" muted style with email search button instead of broken profile link
- **Review queue N+1 query** -- per-row client lookup replaced with single batch whereIn() query using clientMap
- **Review queue order column** -- order_id=0 now shows dash instead of broken link to "orders.php?id=0"
- **Review queue count label** -- changed from misleading "orders pending review" to accurate "checks pending review"
- **Unscanned users false count** -- tblusers query now uses whereExists() against tblusers_clients with tblclients JOIN; users linked only to deleted clients or with no client link are excluded from "unscanned" count
- **FraudRecord report missing argument** -- fps_ajaxReportFraudRecord() and fps_ajaxReportClientFraudRecord() were passing 4 arguments to fps_reportToFraudRecord() which expects 5; added $reporter parameter from fraudrecord_email setting
- **Topology active class mismatch** -- PHP emitted fps-filter-active but JS toggled plain "active" class; now both use fps-filter-active consistently
- **Topology duplicate CSS class** -- active button HTML had fps-topo-range-btn listed twice; removed from $activeCls since it's already in the base class
- **deleteClientRelatedData() missing reviewed_by** -- FpsBotDetector purge function marked checks with [purged] suffix but didn't set reviewed_by/reviewed_at, leaving them in the review queue; now sets both fields
- **Dark mode table header unreadable** -- added .fps-theme-dark .fps-table thead th color override to use --fps-text-primary

### Changed
- **Review queue profile links** -- known clients link to FPS client_profile tab; guest/deleted clients show email search button linking to clients.php?search=email
- **Topology setRange map** -- added "all": 0 entry; max hours cap raised from 720 to 8760 (1 year)
- **fps_ajaxTopologyData** -- hours=0 now treated as all-time query (no date filter) instead of defaulting to 24h
- **Module version** -- bumped from 4.2.2 to 4.2.3

---

## [4.2.2] - 2026-04-06

### Security
- **Webhook SSRF hardened** -- `fps_sendWebhook()` now validates URLs before dispatch: enforces HTTPS-only scheme, resolves hostname and blocks RFC 1918/loopback/link-local/cloud-metadata IPs, disabled `CURLOPT_FOLLOWLOCATION` to prevent redirect-based SSRF
- **API key query string deprecated** -- `$_GET['api_key']` fallback now logs a deprecation warning via `logModuleCall()`; prefer `X-FPS-API-Key` header
- **CORS tightened** -- `Access-Control-Allow-Origin` now checks against known origin patterns instead of blanket `*` for authenticated endpoints
- **GDPR endpoint non-enumerable** -- submit function returns identical generic message regardless of whether email exists, is already pending, or is new; prevents email hash enumeration; internal request IDs no longer leaked

### Fixed
- **Topology country double-count** -- `combinedCountries` was `max(a, a+b)` (always summed, never deduped); now unions distinct country codes from both local and global sources
- **Trust list N+1 queries** -- filtered trust list loaded client data one-by-one per row; now batch-loads all clients in a single `whereIn()` query
- **README broken links** -- 14 relative links using GitLab-style `../../wikis/...` replaced with full GitHub URLs; issues and releases links also fixed

---

## [4.2.1] - 2026-04-06

### Added
- **Colorblind-friendly accessibility toggle** -- floating eye icon button (bottom-left) swaps all green accents to blue across all pages; persists via localStorage; includes underlined links, higher contrast text, and thicker focus rings (WCAG 2.1 compliance)
- **Featured products on homepage** -- "Our Products & Services" section injected via hook showing all visible product groups with icons, plan counts, taglines, and starting prices pulled live from the database
- **Page navigation bar on FPS overview** -- links to Overview, API Plans, API Docs, Threat Map, Global Intel, and Data Removal pages
- **AI Assistant chat widget integration** -- loads ai_assistant hooks via FPS bridge; floating chat bubble with Ollama-powered AI support
- **90D and ALL time buttons on topology** -- previously only 1H/24H/7D/30D; ALL now includes global intel data (661+ records with country centroids)
- **Global intel data in topology** -- ALL timeline merges local geo_events with mod_fps_global_intel records, mapping country codes to globe coordinates via centroids table
- **whmcs.json marketplace metadata** -- WHMCS Apps & Integrations page metadata with features, support links, and author info
- **DNS hostnames for API and Hub** -- `api.enterprisevpssolutions.com` (Ollama via Traefik) and `hub.enterprisevpssolutions.com` (Global Intel Hub via Caddy), both behind Cloudflare proxy with SSL

### Fixed
- **CSRF token generation for WHMCS 8.x** -- WHMCS 8.x uses `generate_token('plain')` instead of `$_SESSION['token']`; all admin AJAX calls (mass scan, manual check, settings save, rule management, API key creation) were silently failing due to empty CSRF tokens
- **Mass scan UI not showing progress** -- progress card, batch loop, results table, and cancel button were all non-functional; complete JS rewrite with real-time progress bar, scanned/flagged/blocked counters, and clickable results table
- **Scan Now buttons on Client Profile page** -- `runManualCheck()` only accepted one argument but Client Profile passed two; second argument (clientId) was silently ignored; now accepts optional clientId parameter
- **Topology showing 0 events on load** -- three bugs: wrong API base URL (WHMCS handler vs public API), `loadData()` overwriting server-injected stats with empty 24H data, and `parseInt("0") || 24` treating ALL button as 24H
- **Turnstile widget injection missing** -- `getInjectionScript()` method existed but was never called from any hook; added ClientAreaFooterOutput hook to inject widgets on login, registration, checkout, contact, and ticket forms
- **Turnstile hook architecture** -- CSS injection was inside the Turnstile-enabled check; if Turnstile was disabled, site-wide CSS stopped loading; restructured so CSS always injects regardless of Turnstile state
- **Device fingerprint JS only loaded on cart pages** -- removed page filter; now loads on ALL client-area pages for passive fingerprint collection from every visitor
- **Duplicate detection false positives** -- IP matching included `client_id=0` (pre-checkout bot blocks), creating phantom duplicates; added `client_id > 0` filter
- **Hero/CTA text unreadable on dark gradients** -- blanket `.main-content` dark-text rules overrode white text in hero banners, CTA sections, and code blocks; added 20+ high-specificity override rules using `:not(.x)` specificity hack
- **API docs code blocks invisible** -- JSON syntax highlighting (`.key`, `.str`, `.num`) and curl commands were dark-on-dark due to CSS specificity cascade with colorblind mode
- **Base URL text invisible in API docs hero** -- required `:not(.x)^4` specificity (0,6,1) to beat blanket rule (0,5,1)
- **Active nav tabs invisible** -- `.fps-pub-nav a.active` lost to `.main-content a:not(.btn):not([class*="btn"])` specificity; fixed with `.main-content` prefix
- **Store page buttons invisible** -- "Get Started", "Get Free Key", "Go Premium" buttons had white/transparent text in colorblind mode
- **Invoice Extensions nav item hidden** -- removed via CSS `display:none` + JS fallback
- **Chat Now redirected to support tickets** -- removed Chatstack livehelp hook (404 errors), redirected Chat Now link to submitticket.php
- **Chatstack livehelp 404 errors removed** -- disabled `/includes/hooks/livehelp.php` that was injecting a missing script on every page
- **Footer language selector invisible** -- `--main-footer-link-color` was near-white HSLA; overridden to `#475569`
- **50+ RSThemes CSS variables overridden** -- `--text-heading-color`, `--label-color`, `--input-color`, `--price-color`, and 46 more variables changed from white/HSLA (Futuristic dark theme) to dark colors for light theme
- **Order Summary prices invisible** -- `.price`, `.price-total`, `.list-item.faded` all had white/transparent text on white cards
- **Form labels on ticket page invisible** -- `--label-color: #fff` overridden to `#1e293b`
- **Select/option dropdown text invisible** -- inherited white text from RSThemes variables
- **Dark mode forced on FPS templates** -- removed `document.body.classList.add('fps-dark-mode')` from overview.tpl, apidocs.tpl, gdpr.tpl, global.tpl, and landing.tpl; updated CSS variables to EVPS light palette
- **CSS comment leaking as visible text** -- `/* Dark mode removed */` outside `<style>` tag rendered as HTML text; changed to `<!-- -->` HTML comment
- **Trust list blocking mass scan** -- 17 clients marked as "trusted" from bulk assignment were correctly skipped by mass scan; reset to "normal" so all clients get scanned

### Changed
- **Site-wide CSS moved to static file** -- 11KB inline `<style>` injection replaced with cacheable `fps-site-theme.css`; browser caches after first page load
- **Anonymous API rate limit raised** -- from 5/min (caused topology page to 429 itself) to 30/min, 1000/day
- **Topology events endpoint accepts hours=0** -- returns all-time data instead of defaulting to 24H
- **Hotspots API returns full stats** -- `active_countries`, `total_blocks`, `block_rate` now included per-timeline
- **Purple accents (#667eea) replaced with blue (#2563eb)** -- across API docs, GDPR, Global Intel, and store templates
- **Hero subtitle text brightened** -- from `rgba(255,255,255,0.75)` to `#e2e8f0` across all hero sections
- **Hub URL uses DNS hostname** -- `hub.enterprisevpssolutions.com` instead of internal IP `130.12.69.6:8400`

### Security
- **Internal IPs sanitized** -- all references to `130.12.69.x`, `47.207.89.153`, `192.168.1.210` removed from PHP, JS, and template files before public release
- **Internal development plans removed** -- `docs/plans/` directory deleted
- **CONTRIBUTING.md sanitized** -- internal hostnames replaced with placeholders

### Infrastructure
- **Public GitHub repo** -- `CyberNinja7420/whmcs-fraud-prevention-suite` with 107 files, v4.2.0 tagged release, 14 wiki pages
- **GitLab CI pipeline** -- 4 stages: php-lint, hooks-audit, conflict-check, secret-scan
- **Traefik route** on GPU host for `api.enterprisevpssolutions.com` -> Ollama :11435
- **Caddy route** on CI/CD server for `hub.enterprisevpssolutions.com` -> Hub :8400
- **Advanced AI chatbot archived** -- GitLab project 246 archived; replaced by ai_assistant v3.1.0
- **96 orphaned database tables dropped** -- 94 churn prediction + 2 domain namespinner tables cleaned from dev server

---

## [4.2.0] - 2026-04-02

### Added
- **Server module (fps_api)** for WHMCS product provisioning -- clients can purchase FPS API access as a product; keys are auto-created on provisioning and suspended/terminated with the service
- **Configurable rate limits** -- per-tier rate limits (per-minute and per-day) are now stored in the database and editable from the admin Settings UI; changes take effect immediately
- **API usage and abuse tracking dashboard** -- new admin tab showing per-key request volumes, quota consumption, and flagged abuse patterns
- **Trust list shows all clients** -- trust list page now displays every client with search/filter, not just those with existing trust entries
- **Dashboard recent checks enriched** -- recent checks table now includes client name and quick-action buttons (view profile, add to trust list)
- **Log management controls** -- "Clear Logs" and "Clear Checks" buttons in the admin Alert Log tab for housekeeping old data
- **Review queue source filter** -- dropdown filter on the review queue to narrow results by fraud check source (checkout, registration, mass scan, API)
- **Bot purge data preservation** -- fraud intelligence is harvested and stored in `mod_fps_global_intel` before bot accounts are deleted
- **Pre-checkout guest data capture** -- guest checkout sessions now capture IP, email, and device fingerprint before the order is placed, enabling earlier fraud blocking

### Fixed
- 7 JS-PHP contract bugs: mismatched action names, missing response fields, and incorrect parameter keys between frontend AJAX calls and backend handlers
- Topology page CSS class name mismatches causing broken layout and missing globe container height
- GDPR export table (`mod_fps_gdpr_exports`) now auto-created on module activation if missing

---

## [4.1.3] - 2026-04-01

### Added
- **User Account Cleanup (WHMCS 8.x)** -- Detects and purges orphan user accounts from `tblusers`
  - `detectOrphanUsers()` -- Scans for users with no real client associations
  - `purgeOrphanUsers()` -- Harvests intel then deletes user login accounts
  - `cleanOrphanUserForClient()` -- Automatic user cleanup when bot clients are purged
  - Supports both `tbluserclients` (WHMCS 8.6+) and email-matching fallback (older 8.x)
- **WHMCS Users Page Integration** -- FPS Bot Detection toolbar injected into `/admin/user/list`
  - Admin can scan for bot users, select them, and purge directly from the WHMCS Users page
  - Controlled by toggle in Settings > Bot & User Cleanup
  - Highlights bot users in the existing table with visual indicators
- Settings toggle: `user_purge_on_users_page` (enabled by default)
- `FpsBotUsers` JavaScript namespace for user cleanup UI

---

## [4.1.2] - 2026-03-31

### Added
- **Global Threat Intelligence Hub** -- Cross-instance fraud data sharing via hub-and-spoke architecture
  - `FpsGlobalIntelCollector` -- Harvests fraud signals, upserts with dedup (seen_count, GREATEST risk_score)
  - `FpsGlobalIntelClient` -- Hub HTTP client (register, push, lookup, feed, GDPR purge)
  - `FpsGlobalIntelChecker` -- Fast cross-reference for signups (<200ms local, <500ms with hub)
  - Hub API (FastAPI + SQLite Docker container) with 8 endpoints on CI/CD server
  - Admin tab: Global Intel (connection status, settings, intel browser, privacy controls)
- **Harvest-before-purge** -- Fraud intel preserved in `mod_fps_global_intel` before bot account deletion
- **Deep purge** -- Deletes accounts where ALL records are Fraud/Cancelled/Unpaid (not just zero-record accounts)
- **Preview/dry-run system** -- Preview buttons for all destructive operations (flag, deactivate, purge, deep purge)
- **Bot Cleanup tab** -- Full admin interface for bot detection, preview, and cleanup actions
- **Alert Log tab** -- Provider health monitoring, paginated module log, clear button
- Score adjustment from global intel (5-30 points based on hit count + cross-instance confirmation)
- `ClientAreaHeaderOutput` hook for Turnstile script injection
- DailyCronJob integration: auto-push to hub, auto-purge expired intel
- Global intel wired into `FpsCheckRunner` as a provider (weight 1.5)
- GDPR compliance: export all data (Art. 20), purge local intel (Art. 17), purge hub contributions
- 475-line README.md with full documentation
- LICENSE, CHANGELOG.md, CONTRIBUTING.md, docs/wiki/

### Fixed
- **CRITICAL: Score ordering bug in ShoppingCartValidateCheckout** -- Velocity and Tor checks now run BEFORE database persistence, so stored scores match blocking decisions
- **CRITICAL: SQL injection in upsertIntel** -- Replaced `addslashes()` with PDO bound parameters
- **CRITICAL: Undefined `$statusLabel`** in TabGlobalIntel causing blank button label
- **CRITICAL: Missing `total` field** in flag preview response causing "undefined" in JS
- Standalone functions removed from hooks.php (moved to FpsHookHelpers static methods)
- Toast type `'danger'` changed to `'error'` to match toast system's icon/title map
- Boolean consent rendering now shows proper badges instead of `1`/blank
- Flag preview now includes `status` field for complete display
- `$_SERVER['SERVER_NAME']` replaced with `SystemURL` in FpsNotifier and FpsWebhookNotifier (CLI/cron portability)
- Version strings unified to 4.1.2 across all 13 files (config, version.json, admin header, activate message, 4 JS, 2 CSS, 2 TPL, README)
- Placeholder `fps-hub.evdps.com` changed to generic `your-hub-server.com`

### Changed
- `mod_fps_global_intel` table uses UNIQUE(email_hash, ip_address) for dedup
- `mod_fps_global_config` table stores all hub/sharing settings
- `FpsBotDetector::purgeBotAccounts()` and `deepPurgeBotAccounts()` now call `FpsGlobalIntelCollector::harvestFromClient()` before deletion
- hooks.php helper functions migrated to `FpsHookHelpers` static methods
- Trust check moved to top of ShoppingCartValidateCheckout (before providers)

---

## [4.0.0] - 2026-03-30

### Added
- **Cloudflare Turnstile** integration (invisible bot protection on all forms)
- **AbuseIPDB** provider (crowd-sourced IP abuse database, 1K free/day)
- **IPQualityScore** provider (advanced proxy/VPN/bot detection, 5K free/month)
- **Abuse Signal** provider (StopForumSpam + SpamHaus ZEN DNS -- free, no key)
- **Domain Reputation** provider (RDAP domain age, suspicious TLD, Safe Browsing)
- **Bot detection** using real WHMCS financial data (100% accuracy, zero false positives)
- **15-dimension duplicate detection** in Client Profile tab
- **Setup wizard** with real-time API key validation (Turnstile, AbuseIPDB, IPQS, FraudRecord)
- **Client risk timeline** (chronological forensic event view)
- **Cross-account scan** (shared IPs, fingerprints, phone, address, email domain)
- **FraudRecord v2 API** migration (SHA1 hashes, JSON POST, camelCase apiKey)
- **Public API docs page** with live stats, tier pricing, endpoint documentation
- **Font size scaling** (85%-130%) via Settings > Display
- Auto fraud reporting to FraudRecord/AbuseIPDB/StopForumSpam on deny/terminate
- Bot scoring integrated into risk engine with override floors (weight 5.0)
- Enhanced email intel (SMS gateway detection, plus-addressing, numeric email flags)

---

## [3.0.0] - 2026-03-29

### Added
- Full provider pipeline rewrite (16 provider classes with FpsProviderInterface)
- Client trust management (allowlist/blacklist with admin controls)
- Webhook notifications (Slack, Discord, Teams, Generic)
- Velocity engine (5 rate limit dimensions: orders/IP, registrations/IP, failed payments, checkout attempts, BIN reuse)
- Geo-impossibility engine (IP vs billing vs phone vs BIN country cross-correlation)
- Behavioral scoring engine (mouse entropy, keystroke cadence, form fill speed, paste detection)
- Bot detector with signup blocking hooks
- Mass scan optimization (fast providers only, 8-330ms/client)
- Enriched daily statistics with sparkline data
- Adaptive scoring (monthly ML weight optimization via FpsAdaptiveScoring)
- REST API with 4-tier access (anonymous, free, basic, premium)
- Token bucket rate limiter (per API key and per IP)
- 1000X Design System CSS (dark/light mode, gradient headers, stat cards)
- 14 admin tabs with full UI

### Changed
- AJAX routing uses `$_POST['action'] ?? $_GET['a']` pattern
- All hooks wrapped in try/catch(Throwable)
- Database operations use Capsule ORM exclusively

---

## [2.0.0] - 2026-03-28

### Added
- IP/email intel caching tables (`mod_fps_ip_intel`, `mod_fps_email_intel`)
- Device fingerprinting (Canvas, WebGL, fonts, screen, audio, WebRTC)
- BIN/IIN card lookup provider
- Gateway-specific fraud thresholds
- Chargeback tracking and refund abuse detection
- Three.js globe topology visualization

---

## [1.0.0] - 2026-03-27

### Added
- Initial release
- FraudRecord API integration (v1)
- Basic IP and email checks
- Admin dashboard with risk scoring
- WHMCS hook integration (checkout, order, registration)
- Database schema (mod_fps_checks, mod_fps_settings, mod_fps_rules, mod_fps_stats)

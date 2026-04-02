# Changelog

All notable changes to the Fraud Prevention Suite are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

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

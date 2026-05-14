# FPS Analytics Integration — Design Document

**Date:** 2026-04-22
**Module version target:** 4.2.5
**Status:** Approved
**Authors:** Tom (admin@evdps.com), Claude (brainstorming session)

---

## Goal

Integrate Google Analytics 4 (client + admin + server-side custom events) and Microsoft Clarity (client + admin) into the WHMCS Fraud Prevention Suite, with rich FPS-specific dimensions, EEA-compliant consent, anomaly detection, and MCP server registration for analyst-facing AI workflows.

The integration must:
- Track meaningful FPS lifecycle events as first-class GA4 events with full payload (risk_score, providers, country, gateway, etc.) — not just page views
- Tag every Clarity session with FPS state so analysts can filter recordings by `fps_risk_level`, `fps_blocked`, etc.
- Respect Consent Mode v2 default-deny + show banner only to EEA visitors (uses FPS's existing IP→country resolver)
- Stay maintenance-light: no in-module recreation of GA4's UI, no OAuth dance — link out to vendor consoles for full analysis, expose MCP servers for natural-language AI queries
- Default OFF on activation; operator opts in per-side (client / admin / server events) via three independent toggles

## Non-goals

- Recreating GA4 reports inside WHMCS (deliberate maintenance avoidance)
- OAuth-based Data API integration (chose Service Account JWT instead — single read, single endpoint)
- Real-time client-side dashboard streams (events flow to GA4 Realtime; admins use that UI)
- Replacing the existing FPS dashboard tiles (analytics is additive)

---

## Architecture (3 separated concerns)

### A. Client-side tracking — gated by `enable_client_analytics`

`ClientAreaHeaderOutput` hook injects in this exact order:

1. **Consent Mode v2 default-deny stub** — `gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',...})` — must be first, before any tag loads
2. **gtag.js + clarity.js loaders** — `async/defer`, with `anonymize_ip:true` baked in
3. **Session-context tags** — both libraries get FPS context:
   - `gtag('set','user_properties',{fps_country, fps_is_returning_client, fps_trust_score, fps_module_version})`
   - `clarity('set','fps_country',...)`, `clarity('set','fps_risk_level',...)`, `clarity('identify','client_'+id)` (links Clarity session ↔ WHMCS client)
4. **EEA-only consent banner** — only renders when visitor IP country ∈ EEA list; "Accept" calls `gtag('consent','update',...)` + `clarity('consent')`; "Decline" leaves default-deny in place
5. **Debug toggle** — `?fps_analytics_debug=1` query param dumps every event to `console.table` and a hidden `<div id="fps-debug-events">` for QA verification

### B. Admin-side tracking — gated by `enable_admin_analytics`

`AdminAreaHeaderOutput` hook injects gtag + clarity with admin IDs.

- No consent banner (internal tool, documented in onboarding)
- Auto-fires `fps_admin_tab_view` on every FPS tab switch (so we can see which tabs admins actually use)
- Sets `gtag('set','user_properties',{fps_admin_id, fps_admin_role, fps_module_version})`

### C. Server-side rich event pipeline — gated by `enable_server_events`

`lib/Analytics/FpsAnalyticsServerEvents.php` sends events via the GA4 Measurement Protocol (`POST https://www.google-analytics.com/mp/collect`).

**12 events covering the FPS lifecycle:**

| Event | When | Key payload |
|-------|------|-------------|
| `fps_pre_checkout_block` | hooks.php blocks checkout | risk_score, risk_level, providers, country, gateway, amount |
| `fps_pre_checkout_allow` | hooks.php allows checkout | risk_score, country, gateway, duration_ms |
| `fps_turnstile_fail` | Turnstile validation fails | country, error_codes, ip_country |
| `fps_turnstile_pass` | Turnstile validation passes | country, duration_ms |
| `fps_high_risk_signup` | ClientAdd → score≥80 | risk_score, providers, country, email_domain |
| `fps_global_intel_hit` | Email/IP matched in hub | seen_count, signal_count, country |
| `fps_geo_impossibility_score` | Geo engine fires non-zero | score, signals, unique_countries |
| `fps_velocity_block` | Velocity engine blocks | engine_type, count, window_minutes |
| `fps_admin_review_action` | Admin approves/denies/locks | admin_id, action, original_risk_score, new_status |
| `fps_api_request` | Public API endpoint hit | endpoint, tier, response_code, duration_ms |
| `fps_bot_purge` | Bot user purge runs | mode, detected_count, purged_count |
| `fps_module_health` | Daily cron heartbeat | module_version, total_checks_24h, error_count_24h |

Common dimensions on every event: `module_version`, `instance_id` (so multi-install operators can compare their FPS rollouts).

**Batching:** max 25 events per HTTP POST, 1-second debounce window — stays inside GA4 Measurement Protocol limits (currently 25 events/request, 500K events/day per property).

---

## Settings card — "Analytics & Tracking"

New card in TabSettings (provider accordion), 11 fields:

| Field | Type | Purpose |
|-------|------|---------|
| `enable_client_analytics` | toggle | Master switch for client-side scripts |
| `enable_admin_analytics` | toggle | Master switch for admin-side scripts |
| `enable_server_events` | toggle | Master switch for server-side Measurement Protocol POSTs |
| `ga4_measurement_id_client` | text (G-XXXX) | Client property |
| `ga4_measurement_id_admin` | text (G-XXXX, optional) | Admin property; falls back to client value if blank |
| `ga4_api_secret` | concealed | Measurement Protocol secret |
| `ga4_service_account_json` | textarea (optional) | For dashboard data-pull (Service Account, NOT OAuth) |
| `clarity_project_id_client` | text | Client Clarity project ID |
| `clarity_project_id_admin` | text (optional) | Admin Clarity project ID |
| `analytics_eea_consent_required` | toggle (default ON) | If off, banner shows for everyone (broader compliance) |
| `analytics_event_sampling_rate` | int 1-100 (default 100) | High-traffic operators can sample down |

**"Test connection" button** per platform — fires synthetic `fps_test_ping` event; tells admin "look in GA4 Realtime in 30 seconds" + auto-refreshes the status widget once.

---

## Dashboard widget — "Analytics Connection Status"

New tile on TabDashboard (between latency widget and manual check form). For each platform shows:

- 🟢 / 🔴 / ⚪ status dot (configured + last successful POST < 1h / configured but failing / not configured)
- Last server-side event timestamp + count of events sent in last 24h (queried from `mod_fps_analytics_log`)
- "Open GA4 Realtime ↗" / "Open Clarity Dashboard ↗" deep links
- **Optional sub-widget** — only renders if Service Account JSON configured: yesterday's `fps_pre_checkout_block` count pulled from GA4 Data API with 6-hour cache. Single read, single endpoint, graceful degradation to "—" on failure.

---

## Anomaly detection (daily cron extension)

Existing `DailyCronJob` hook extended:

```php
$today  = $analyticsLog->countEventsToday('fps_pre_checkout_block');
$median = $analyticsLog->medianDailyCount('fps_pre_checkout_block', 14); // 14-day baseline
if ($today > $median * 3 && $today > 50) {
    fps_sendMail($adminEmail, '[FPS] pre-checkout block spike detected',
        "Today: $today, median: $median over 14 days");
    Capsule::table('mod_fps_analytics_anomalies')->insert([...]);
}
```

Reuses existing `FpsNotifier` + `fps_sendMail` (no new mail path). Prevents silent fraud-pattern drift.

---

## MCP server registration (separate, optional)

Operators can wire the official Google Analytics MCP server + Microsoft Clarity MCP server into their own Claude Code/Desktop:

- New `scripts/install-mcp-servers.sh` — detects user's `~/.claude/settings.json`, backs it up (`.bak.YYYYMMDD-HHMMSS`), merges in both MCP server entries, prints "now run `claude mcp list` to verify"
- New `docs/wiki/Analytics-MCP-Setup.md` — manual instructions + example natural-language queries

The MCP servers are NOT auto-installed by the WHMCS module — they live in the analyst's local Claude environment. The WHMCS install only ships the docs + helper script.

---

## Privacy / GDPR

| Concern | Handling |
|---------|----------|
| EEA cookie consent | Consent Mode v2 default-deny + banner only EEA visitors see. Falls back to "show banner for everyone" when `analytics_eea_consent_required = '0'`. |
| Article 17 erasure | `fps_gdprPurgeByEmail()` extended to call Microsoft's Clarity Data Subject Request API + log instructions for the operator to manually file a GA4 user-deletion request (no GA4 API for this exists). |
| DPA signing | Settings card warning: "You must sign Google's GA4 DPA + Microsoft's Clarity DPA separately." Links to both DPA pages. |
| IP anonymisation | `gtag('config','G-XXX',{anonymize_ip:true})` always set; Clarity has built-in IP truncation. |
| Logged-in admin data | Admin tracking documented as "internal staff usage analytics — not for client-side personalisation". |
| Consent state in server events | Server-side events pass the visitor's consent state (from `fps_consent` cookie) into the Measurement Protocol payload (`consent.ad_user_data`, `consent.ad_personalization`). |
| Anonymous mode toggle | When on, drops `client_id` and `email_domain` from server events; sends only aggregate dimensions. |

---

## Failure modes

| Failure | Behaviour |
|---------|-----------|
| Operator hasn't entered IDs | Hooks emit nothing; status widget shows "Not configured" |
| Invalid ID format on save | `fps_ajaxSaveSettings` rejects with field-level error |
| Google CDN unreachable | `async/defer` on script tags = page render unaffected; events lost (acceptable for analytics) |
| Server-side Measurement Protocol POST fails (4xx/5xx) | Logged once per hour with truncated payload; status widget turns red |
| Clarity script blocked by adblocker | Page unaffected; no events recorded; status widget unaware (correct — client-side block) |
| `mod_fps_analytics_log` table grows | Rolling 30-day TTL via daily cron `DELETE WHERE created_at < NOW() - INTERVAL 30 DAY` |
| Service Account JSON malformed | Settings save validates with `openssl_pkey_get_private` on the `private_key` field; rejects with field error |
| Consent banner fails to render | Default-deny stays; analytics inactive; not a blocker |

---

## New components (11 files, ~1500 lines)

```
lib/Analytics/
  FpsAnalyticsConfig.php          (memoized settings reader + ID validators)
  FpsAnalyticsInjector.php        (script tag builders for client / admin / banner)
  FpsAnalyticsServerEvents.php    (Measurement Protocol POST batcher)
  FpsAnalyticsDataApi.php         (Service Account JWT → GA4 Data API single call)
  FpsAnalyticsAnomalyDetector.php (cron-side spike check)
  FpsAnalyticsLog.php             (30-day rolling event log; queries for status widget)
  FpsAnalyticsConsentManager.php  (EEA detection + Consent Mode v2 state mgmt)

assets/js/
  fps-consent-banner.js            (~80 lines, vanilla, no deps)
  fps-analytics-debug.js           (only loads when ?fps_analytics_debug=1)

scripts/
  install-mcp-servers.sh           (MCP auto-install for ~/.claude/settings.json)

docs/wiki/
  Analytics-MCP-Setup.md           (operator-facing docs)
```

Plus extensions to:
- `lib/Admin/TabSettings.php` (new "Analytics & Tracking" provider card)
- `lib/Admin/TabDashboard.php` (new `fpsRenderAnalyticsStatus()` widget)
- `hooks.php` (two new injection blocks inside existing `ClientAreaHeaderOutput` and `AdminAreaHeaderOutput`)
- `fraud_prevention_suite.php` (require_once the new lib/Analytics files; seed 11 new settings; extend booleanFlagKeys; activate the 2 new tables; `DailyCronJob` extension)
- `phpstan-stubs/fps-globals.php` (stubs for any new cross-file helpers)

---

## Schema additions (idempotent)

```sql
mod_fps_analytics_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(50) INDEX,
  payload_json TEXT,
  destination ENUM('ga4_client','ga4_server','clarity'),
  status ENUM('queued','sent','failed'),
  error TEXT,
  created_at TIMESTAMP INDEX
)

mod_fps_analytics_anomalies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(50) INDEX,
  baseline_count INT,
  observed_count INT,
  detected_at TIMESTAMP,
  notified_at TIMESTAMP NULL
)
```

Both created via `if (!hasTable())` guard. Both excluded from `drop_legacy_details_columns` flow (separate concern).

---

## Battle-test plan

| # | Phase | Side | Test | Pass criteria |
|---|-------|------|------|---------------|
| 1 | Static | n/a | `php -l` all changed files; phpstan level 3; psalm level 6 | exit=0 on each |
| 2 | Dev admin | admin | Settings → enter test IDs → save → reload | Network tab shows `gtag/js?id=G-...` HTTP 200; `clarity.ms/tag/{id}` HTTP 200; no console errors; cookies `_ga`, `_clck` set |
| 3 | Dev admin | admin | Click 5 different FPS tabs | GA4 Realtime shows 5 `fps_admin_tab_view` events with `tab_name` user property |
| 4 | Dev admin | admin | "Test connection" button | Synthetic event arrives in GA4 Realtime within 30s; status widget green dot |
| 5 | Dev admin | admin | Dashboard widget renders | Status row visible; if Service Account JSON entered, yesterday's count pulled |
| 6 | Dev client | client (incognito, NON-EEA) | Visit storefront | gtag.js + clarity.js load; NO banner; gtag set with FPS user properties |
| 7 | Dev client | client (incognito, EEA simulated) | Visit storefront | Banner renders; default-deny in effect; accept → consent update fires; events resume |
| 8 | Dev client | client | Synthetic high-risk checkout via FpsCheckRunner | `fps_pre_checkout_block` arrives in GA4 with full payload; Clarity session has `fps_risk_level=critical` tag |
| 9 | Dev client | client | Adblocker active (uBlock Origin) | Page renders normally; checkout works; events silently dropped |
| 10 | Dev cron | server | Manually trigger DailyCronJob | If anomaly detected: email sent + row in `mod_fps_analytics_anomalies` |
| 11 | Dev GDPR | server | Synthetic `fps_gdprPurgeByEmail` call | Clarity DSR API called; log entry created with manual GA4 instructions |
| 12 | Dev MCP | analyst | Run `scripts/install-mcp-servers.sh` against a copy of `~/.claude/settings.json` | Backup created; entries merged; `claude mcp list` shows GA4 + Clarity |
| 13 | Live deploy | both | Same payload to freeit.us + enterprisevpssolutions.com | Lint clean both |
| 14 | Live admin | admin | Repeat tests 2–5 on each install | Same pass criteria |
| 15 | Live client | client | Repeat tests 6–9 on each install | Same pass criteria |
| 16 | Live observation | n/a | Wait 24h, check GA4 Realtime + Clarity dashboard | Real traffic events flowing; no spike in PHP error log |
| 17 | Commit + push | n/a | Single thematic feat commit; CI runs phpstan + psalm | All 3 jobs green |

---

## Subagent dispatch plan

| Subagent | Task | Phase |
|----------|------|-------|
| `php-pro` | Build `lib/Analytics/Fps*.php` (5 of 7 files: Config, Injector, ServerEvents, Log, AnomalyDetector) | 1 |
| `php-pro` (second worktree) | Build `lib/Analytics/FpsAnalyticsDataApi.php` (Service Account JWT → GA4 Data API) | 1, parallel |
| `frontend-design` skill | Build `fps-consent-banner.js` + `fps-analytics-debug.js` + dashboard status widget HTML/CSS | 1, parallel |
| `security-auditor` | Review Consent Mode v2 flow + GDPR Article 17 integration + Service Account JSON validation | 2 |
| `test-automator` | Generate the 17-step battle-test harness + reusable Bash validators | 2, parallel |
| `documentation-expert` | Write `docs/wiki/Analytics-MCP-Setup.md`, extend CHANGELOG, extend README | 3 |
| `code-reviewer-pro` | Final review before commit | 4 |
| (My direct work) | Wiring (require_once, settings card, dashboard tile integration, schema activation, cron extension) + battle-test execution + deployment + commit | 1–5 |

---

## Constraints carried over from existing module

- All FPS conventions hold: `if (!defined("WHMCS")) die(...)` guards, `\Throwable` (not `\Exception`) in catch blocks, no `addslashes` for SQL, all tables behind `if (!hasTable())`
- New helpers stay in **global namespace** (consistent with FpsMailHelper, FpsGdprHelper, FpsInstallHelper from earlier extractions)
- Cross-file global functions get a stub entry in `phpstan-stubs/fps-globals.php` (psalm requires this)
- All asset injections use `FpsHookHelpers::fps_assetCacheBust()` for deterministic versioning
- All settings use the existing `mod_fps_settings` kv store (not `tbladdonmodules`); `FpsConfig::getCustom()` reads them
- Toggle flags added to `$booleanFlagKeys` so unchecked-checkbox saves persist '0'
- All work follows the existing `lib/Analytics/` PSR-4-ish convention (one helper per file, no class wrapper for the global functions)

---

## Out of scope (explicit non-decisions)

- Server-Side GTM (sGTM) container — would require Google Cloud Run/App Engine deploy, far beyond module scope
- Custom Clarity heatmaps surfaced inside WHMCS — link out to vendor console
- A/B testing framework on top of GA4 — not what FPS is
- Replacing Cloudflare Turnstile telemetry with GA4 events — Turnstile already has its own dashboard; we just MIRROR Turnstile pass/fail as GA4 events for funnel analysis

---

## Sources

- [Google Analytics MCP Server (official) — developers.google.com](https://developers.google.com/analytics/devguides/MCP)
- [Microsoft Clarity MCP Server (official) — github.com/microsoft/clarity-mcp-server](https://github.com/microsoft/clarity-mcp-server)
- [GA4 Measurement Protocol — support.google.com](https://support.google.com/analytics/answer/9900444)
- [Server-Side Consent Mode for GA4 — secureprivacy.ai](https://secureprivacy.ai/blog/server-side-consent-mode-for-ga4-how-to-track-analytics-while-respecting-privacy)
- [Microsoft Clarity Consent API — cookieyes.com](https://www.cookieyes.com/blog/microsoft-clarity-consent-api-explained/)
- [Microsoft Clarity & GDPR — pandectes.io](https://pandectes.io/blog/microsoft-clarity-and-gdpr-what-you-need-to-know-in-2025/)
- [br33f/php-GA4-Measurement-Protocol — GitHub](https://github.com/br33f/php-GA4-Measurement-Protocol)

---

**Approval:** Tom (admin@evdps.com), 2026-04-22.
**Next step:** invoke `superpowers:writing-plans` skill to produce the implementation plan.

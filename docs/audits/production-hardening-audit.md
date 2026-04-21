# FPS Production Hardening Audit

**Date:** 2026-04-09
**Module version at audit time:** 4.2.3
**Target environment:** WHMCS 8.13.1+ / PHP 8.3.x
**Methodology:** Repo-wide static review of `fraud_prevention_suite/` with grep-based drift detection, schema-vs-runtime cross-check, and dead-path verification.

## Severity legend

| Severity | Meaning |
|----------|---------|
| **P0 - Critical** | Breaks fresh install OR silently drops data OR security issue |
| **P1 - High** | User-facing bug OR config that doesn't do what UI says |
| **P2 - Medium** | Maintenance burden, technical debt, drift markers |
| **P3 - Low** | Cosmetic, deprecation warnings, documentation drift |

## Issue matrix

| # | Issue | Files | Severity | Fresh install impact | Upgrade impact | Runtime impact | Intended fix |
|---|-------|-------|----------|----------------------|----------------|----------------|--------------|
| 1 | **Version drift** | `fraud_prevention_suite.php:23` (config `4.2.3`), `:612` (seed `3.0.0`), `:750` banner, `:1420` UI header, `:2997` event payload; `lib/Api/FpsApiRouter.php:43` (`X-FPS-Version: 2.0.0`); `lib/FpsWebhookNotifier.php:31` (`MODULE_VERSION = '3.0'`); `templates/topology.tpl:47,115` hardcoded `4.2.2`; `hooks.php:747` `$cssVer='4.2.10'`; `templates/topology.tpl:124` JS `?v=4.2.10` | **P1** | New installs seed `module_version=3.0.0` while config says `4.2.3` → stats/upgrade path confusion | Upgrades leave webhook payloads advertising `3.0`, API header advertising `2.0.0` | Webhooks misreport source version; API consumers see wrong version | Introduce `const FPS_MODULE_VERSION = '4.2.3'` constant; all version strings reference it |
| 2 | **Fresh install vs upgrade schema drift (api_keys)** | `fraud_prevention_suite.php:291-310` (create), `:1035-1045` (upgrade adds `client_id`,`service_id`) | **P0** | Fresh installs get `mod_fps_api_keys` WITHOUT `client_id`/`service_id`; auto-provision writes fail with "Unknown column" | Existing installs get the columns via upgrade path | Provision hook breaks on fresh install; API key audit broken | Move `client_id` + `service_id` into the create() definition; keep upgrade path for existing installs |
| 3 | **mod_fps_reports missing reviewed_by/reviewed_at** | `fraud_prevention_suite.php:158-169` (create - no reviewed fields), `:3704` writes `reviewed_by`/`reviewed_at` | **P0** | Report review on fresh install throws "Unknown column" | Existing installs MAY have the columns if an earlier upgrade migration added them (needs inspection) | `fps_ajaxUpdateReportStatus` breaks | Add `reviewed_by`/`reviewed_at` to create() AND add idempotent upgrade path |
| 4 | **Threshold config naming drift** | `risk_*_threshold` declared in `_config()` and used in `FpsRiskEngine`; `block_threshold`/`flag_threshold` used in `FpsCheckRunner:1028-1029` and `mod_fps_gateway_thresholds` table | **P1** | Admin sliders update `risk_*_threshold` but the action decider reads `block_threshold`/`flag_threshold` → **UI changes do not drive runtime behavior** at the global level | Same | Admin thinks they set the block threshold to 80 via UI; runner uses hardcoded default 80 because global `block_threshold` key is never written | `FpsCheckRunner` reads `risk_critical_threshold` for block and `risk_high_threshold` for flag at the global level; per-gateway `mod_fps_gateway_thresholds` keeps its own block/flag columns (valid use) |
| 5 | **Duplicate pre-checkout engines** | `hooks.php:253-680` inline scoring with own provider calls; `lib/FpsCheckRunner.php:261` `runPreCheckout()` canonical engine | **P1** | Two code paths that can drift: one adds Turnstile tracking and stats, the other does full provider scoring | Same | Maintenance risk; stats paths differ between the two | Unify: hook calls shared helper `FpsCheckRunner::runPreCheckout()` after Turnstile short-circuit; extract the pre-Turnstile fast-gate into a helper so fast-path is preserved |
| 6 | **Stats inconsistency** | `hooks.php:336-347` (turnstile block: increments checks_total+blocked+pre_checkout_blocks, creates row if missing); `hooks.php:595-603` (pre-checkout: increments checks_total+pre_checkout_blocks only); `FpsApiRouter.php:214-225` (api_requests: upsert); `FpsStatsCollector::recordCheck()` (normal path: upsert via `fps_upsertDayStats`) | **P1** | Four different upsert code paths; slight behavioral drift; pre-checkout doesn't increment `checks_blocked` column | Same | `checks_blocked` undercount on pre-checkout blocks; race conditions in upsert logic slightly differ | Single shared recorder method `FpsStatsCollector::recordEvent($type)` that all four paths call |
| 7 | **Structured columns underused** | `mod_fps_checks` has `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` columns; writer in `FpsCheckRunner` populates them but many legacy paths (e.g. purge logic) dump everything into `details` JSON | **P2** | No install impact; data just less structured | Same | Query overhead; client profile tab has to decode JSON for common fields | Ensure all writer paths populate structured columns consistently; keep raw JSON for truly variable metadata |
| 8 | **Cached IP intel naming** | `lib/Providers/AbuseIpdbProvider.php:56` calls `fps_getCached($ip)`; `FpsCheckRunner:434` queries `mod_fps_ip_intel` directly with different schema assumptions | **P2** | Works on fresh install | Potentially fragile on upgrades | Cache lookup may miss data that exists; TTL inconsistency | Document cache layer; no code change required if behavior is correct - needs inspection |
| 9 | **Geo-impossibility dead paths** | `FpsCheckRunner:881-905` geo_mismatch logic; `FpsGeoImpossibilityEngine` engine exists | **P2** | Works if geo data populated; returns 0 score if missing | Same | Feature advertised but may never score non-zero for many installs | Either document that it requires `geoip_location_country` data on previous checks, OR gate the engine behind a "enabled AND has_history" check |
| 10 | **FraudRecord integration consistency** | `FraudRecordProvider::fps_reportToFraudRecord()` takes 5 args, `fraudrecord_api_key` key stored in both `tbladdonmodules` and `mod_fps_settings`; `fraudrecord_email` used as reporter | **P2** | Already verified working (last session) | Same | None known | No-op; monitoring only |
| 11 | **GDPR scope narrow** | `fps_ajaxGlobalIntelPurge` targets only `mod_fps_global_intel`; no purge of `mod_fps_checks`, `mod_fps_ip_intel`, `mod_fps_email_intel`, `mod_fps_bin_cache`, `mod_fps_fingerprints` | **P1** | No install impact | Same | GDPR Article 17 requests don't actually remove requester's data from other caches | Add `fps_gdprPurgeByEmail($email, $ip)` that deletes matching rows from ALL FPS tables with email/ip columns, logs what was deleted, returns audit trail |
| 12 | **Raw @mail() usage** | `fraud_prevention_suite.php:4472`; `lib/FpsNotifier.php:228` | **P1** | No install impact | Same | Silently drops errors; bypasses WHMCS mail config; emails may not send via configured mailer (SMTP, SES) | Replace with WHMCS's `\WHMCS\Mail::class` or `localAPI('SendEmail')`; if not feasible, wrap in a single helper `fps_sendMail()` that logs errors without `@` |
| 13 | **Site-wide UI hacks in hooks.php** | `hooks.php:1162-1194` Chat Now + Invoice Extensions hide; `hooks.php:1239+` Featured Products injection; `assets/css/fps-site-theme.css` 50+ variable overrides | **P2** | No install impact, but activates aggressive site theme mods | Unexpected appearance changes on upgrade if theme was already customized | Fraud module shouldn't own site branding | Move behind feature flags `enable_site_theme_overrides`, `enable_homepage_featured_products`, `hide_invoice_extensions`, `redirect_chat_now` (default: current value preserved, but admin-visible on Settings tab) |
| 14 | **Hardcoded currency=1** | `fraud_prevention_suite.php:941` in product auto-provision | **P1** | Breaks auto-provision on installs without currency ID 1 (e.g. re-imports) | Same | Currency mismatch in invoices | Use `tblcurrencies` default (`default=1`) or WHMCS `$CONFIG['Currency']` |
| 15 | **API version header drift** | `lib/Api/FpsApiRouter.php:43` `X-FPS-Version: 2.0.0` | **P2** | Consumers see wrong version | Same | API docs say v1 but header says 2.0.0 and module is 4.2.3 | Use shared `FPS_MODULE_VERSION` constant |
| 16 | **CORS comment vs behavior** | `lib/Api/FpsApiRouter.php:33-40`: comment says "restrict to known origins", code falls back to `*` for unknown origins | **P1** | No install impact | Same | Misleading security posture; "*" is wrong for credentialed endpoints | If origin not in allowlist, either (a) omit Access-Control-Allow-Origin entirely for non-browser clients, or (b) serve `*` only for read-only endpoints and explicitly reject credentialed requests from unknown origins |
| 17 | **time()-based cache bust** | `fraud_prevention_suite.php:1194` `$cacheBust = '?v=' . time()`; `hooks.php:1008` | **P2** | No install impact | No browser cache = more bandwidth | Breaks browser caching entirely; every page load pulls fresh assets | Use `filemtime($assetPath)` or `FPS_MODULE_VERSION` constant |
| 18 | **Large file maintainability** | `fraud_prevention_suite.php`: 4640 lines; `hooks.php`: 1798 lines | **P3** | No direct impact | Harder upgrades | Cognitive load; merge conflict risk | Extract cleanly-bounded groups to `lib/` helpers (not vanity refactor; only where measurable risk reduction) |
| 19 | **Admin/client route detection brittle** | `hooks.php:67-74` matches `strpos($uri, '/user/list')` but also matches `strpos($uri, 'user')` (overly broad); `hooks.php:48-49` uses SCRIPT_NAME | **P2** | Works on most installs | Can fire on wrong pages if admin has "user" in directory name or URL | Hooks inject toolbars on wrong pages | Tighten to `preg_match('#/user/list(\?|$)#', $uri)` etc. |
| 20 | **Dead code / drift markers** | Various TODO/FIXME, unused vars in renderers | **P3** | No impact | None | None | Remove or document |

## Cross-cutting concerns

**Not issues per se, but worth noting:**

- **Hook load order**: `hooks.php` registers many hooks with priority 1-3; ensure FPS hooks don't conflict with other modules using same priority
- **Opcache invalidation**: Not enabled on live server per earlier session; deployments take effect immediately but no safety net for syntax errors
- **PHP 8.3 compatibility**: No issues found in static scan; `Capsule` (Laravel Illuminate DB) is PHP 8.3-safe; `htmlspecialchars` / `json_encode` usage safe

## Fix batching

Grouped into 9 reviewable batches in [implementation plan](./remediation-implementation-plan.md).

---

**Audit complete.** Implementation phase begins next.

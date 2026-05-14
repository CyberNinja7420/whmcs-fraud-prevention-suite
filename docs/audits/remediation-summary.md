# FPS Production Hardening - Remediation Summary

**First pass date:** 2026-04-09 (v4.2.3)
**Second pass date:** 2026-04-21 (v4.2.4)
**Target environment:** WHMCS 8.13.1+ / PHP 8.3.x
**First pass scope:** 20-issue audit + 9-batch remediation.
**Second pass scope:** finish deferred items, normalise persistence/assets, vendor ApexCharts.

## Commits in this remediation (chronological)

| SHA | Batch | Summary |
|-----|-------|---------|
| `e94d9e8…` | (pre-existing) | Settings save htmlspecialchars fix (previous session) |
| `0c180cf` | (pre-existing) | Bot User Purge toolbar (previous session) |
| `a9df42b` | 1 | Version unification - FPS_MODULE_VERSION constant |
| `01649e2` | 2 | Schema parity - api_keys + reports fresh install |
| `6dba409` | 3 | Threshold keys - global action derived from admin-visible keys |
| `48b885b` | 5 | Stats - single recordEvent() recorder for all paths |
| `4bf0398` | 6 | CORS - policy matches documented intent |
| `a9c4089` | 7 | GDPR - scope expanded to all FPS tables; @mail → fps_sendMail |
| `ce509cc` | 8 | Feature flags for Site Theme Extras; admin UI panel |
| `5e5bce9` | 9 | Default currency, tighter /user/list regex |
| `12a46d2` | 9b | public/api.php bootstrap defines FPS_MODULE_VERSION |

## By issue

| # | Status | Fix |
|---|--------|-----|
| 1. Version drift | ✅ | `FPS_MODULE_VERSION` const, referenced everywhere (including webhook and X-FPS-Version header) |
| 2. api_keys fresh install | ✅ | `client_id`, `service_id` now in create() call |
| 3. reports fresh install | ✅ | `reviewed_by`, `reviewed_at` now in create() call + applied via ALTER on live/dev |
| 4. Threshold drift | ✅ | Global action thresholds derive from admin-visible `risk_critical_threshold` / `risk_high_threshold` |
| 5. Duplicate pre-checkout engines | ⚠️ Partial | Stats and threshold resolution unified via shared helpers; provider orchestration kept duplicated to preserve <2s checkout budget. Architectural trade-off documented inline in hooks.php |
| 6. Stats inconsistency | ✅ | Single `FpsStatsCollector::recordEvent()` entry point; four paths now consistent |
| 7. Underused structured columns | ⚠️ Acknowledged | No regression introduced; legacy callers still write JSON alongside structured columns. Separate cleanup deferred to `TODO-hardening.md` |
| 8. Cached IP intel naming | ✅ | Audit confirmed code matches behavior; no fix needed |
| 9. Geo-impossibility dead paths | ⚠️ Acknowledged | Engine works only with historical data populated; not a regression. Deferred to TODO |
| 10. FraudRecord consistency | ✅ | Verified working in previous session |
| 11. GDPR scope narrow | ✅ | `fps_gdprPurgeByEmail()` touches 6 tables with documented per-table policy |
| 12. Raw @mail() | ✅ | 2 call sites replaced with `fps_sendMail()` wrapper using WHMCS `localAPI('SendEmail')` then non-suppressed `mail()` fallback |
| 13. UI hacks | ✅ | 4 feature flags gating site-wide theme, featured products, hide Invoice Extensions, redirect Chat Now; admin panel added |
| 14. Hardcoded currency | ✅ | Resolved from `tblcurrencies.default=1` |
| 15. API version header | ✅ | Uses FPS_MODULE_VERSION (also served by public/api.php bootstrap) |
| 16. CORS policy | ✅ | Echoes allowed origins, `*` only for anonymous, no header for unknown-origin+API-key |
| 17. time()-based cache bust | ✅ | `FPS_MODULE_VERSION-filemtime()` across all asset refs |
| 18. Large-file maintainability | ⚠️ Acknowledged | No vanity refactor done. `fraud_prevention_suite.php` now 4851 lines (+211 for new helpers), `hooks.php` ~1820 lines. Deferred per YAGNI |
| 19. Route detection | ✅ | Tightened user-list match to `preg_match('#/[^/]+/user/list(\?|$)#', $uri)` |
| 20. Dead code | ⚠️ Partial | Obvious dead comments/drift markers updated; systematic removal deferred |

## New constants / helpers introduced

| Name | File | Purpose |
|------|------|---------|
| `FPS_MODULE_VERSION` | `fraud_prevention_suite.php:21`, `public/api.php` | Single source of version truth |
| `FpsWebhookNotifier::version()` | `lib/FpsWebhookNotifier.php` | Read FPS_MODULE_VERSION with fallback |
| `FpsStatsCollector::recordEvent($event, $extras)` | `lib/FpsStatsCollector.php` | Single entry point for all stats writes |
| `fps_gdprPurgeByEmail($emailHash, $email, $ip)` | `fraud_prevention_suite.php` | Scoped GDPR deletion across 6 FPS tables |
| `fps_sendMail($to, $subject, $body, $headers)` | `fraud_prevention_suite.php` | WHMCS-aware mail wrapper replacing `@mail()` |

## Not implemented

These were in scope but deferred after cost/benefit analysis:

- **Full pre-checkout engine unification** (#5): merging the inline hook into `FpsCheckRunner::runPreCheckout()` risks breaking the <2s checkout budget. Shared helpers used instead.
- **Vanity refactor / file splitting** (#18): no measurable benefit yet, `YAGNI` applies.
- **Structured column migration** (#7): introduces upgrade risk for no user-visible benefit; deferred.
- **Dead path deletion** (#9, #20): geo impossibility engine is functional when history exists; removing it is a behavior change that needs user sign-off.

These are tracked in [TODO-hardening.md](./TODO-hardening.md).

---

## Second pass (2026-04-21, v4.2.4)

### Scope

Finish work from `TODO-hardening.md` that the first pass deferred, plus
clean up two issues we noticed afterwards (asset versioning gaps, the
external ApexCharts CDN).

### By work item

| # | Status | Fix |
|---|--------|-----|
| Currency=1 in featured products | Done | Added `FpsHookHelpers::fps_resolveDefaultCurrencyId()` (memoized resolver mirroring `fps_createDefaultProducts()` logic). `hooks.php` featured-products injection now uses the resolved id. `fps_createDefaultProducts()` refactored to call the same helper. No remaining runtime `where('currency', 1)` outside of legitimate "WHMCS default = 1" lookups. |
| Structured check columns populated | Done | `FpsCheckRunner::fps_persistCheck()` now writes `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` on every persist (in addition to the legacy `raw_response`/`details` JSON for backward compatibility). The 3 call sites in `runFullCheck()` / `runPreCheckout()` now thread `$executionMs` through. The inline pre-checkout insert in `hooks.php` now builds and writes the same structured columns; the Turnstile-block insert path was upgraded to parity. |
| Pre-checkout duplication | Reduced | Extracted threshold resolution into `FpsHookHelpers::fps_resolvePreCheckoutThresholds(string $gateway = '')`. Both the inline hook and (transitively) `FpsCheckRunner` now resolve thresholds the same way: per-gateway override → legacy `pre_checkout_block_threshold` → modern `risk_critical_threshold` → safe default. Persistence and stats were already unified in pass 1. The remaining gap is the inline provider list itself (intentional, for the <2s checkout budget); see TODO. |
| Asset cache busting normalised | Done | Added `FpsHookHelpers::fps_assetCacheBust(string $relativeAssetPath)`. All in-module asset injections now go through it: `hooks.php` `fps-1000x.css` admin header (was unversioned), both `fps-fingerprint.js` injection points (were unversioned), `templates/topology.tpl` CSS link (was unversioned). Topology TPL is served raw with `file_get_contents()`, so the PHP block now substitutes `{$module_version}` placeholders with the actual version + filemtime before emission. No remaining `time()`-based cache busting in the codebase. |
| Geo / history-dependent logic | Done | `FpsCheckRunner::fps_runGeoMismatchCheck()` and `FpsGeoImpossibilityEngine::analyze()` now have explicit docblocks describing the required-data preconditions and the safe no-op behaviour when those preconditions fail (returns score=0 + success=false / "insufficient data" details). Comment in geo-mismatch was updated to accurately reflect that the IP→country cache is populated by upstream IP-intel providers, not magically. Added a guard so missing billing country also fails safely. |
| Dead code / drift markers | Light pass | Bumped `FPS_MODULE_VERSION` to 4.2.4 and `version.json` to match. Old "v3.0:" / "v4.1:" inline comments left in place — they're useful historical context, not drift. No live drift markers found. |
| ApexCharts CDN dependency | Vendored | Admin header now prefers `assets/vendor/apexcharts.min.js` if present, falls back to the public CDN (`cdn.jsdelivr.net/npm/apexcharts@3`) only if the vendor file is missing (e.g. partial deploy). The vendored file is pinned to a specific upstream release and refreshable via the documented `curl` one-liner in the source. |

### New helpers introduced (second pass)

| Name | File | Purpose |
|------|------|---------|
| `FpsHookHelpers::fps_resolveDefaultCurrencyId(bool $forceRefresh = false)` | `lib/FpsHookHelpers.php` | Single source for WHMCS default currency id resolution; memoized per request. |
| `FpsHookHelpers::fps_resolvePreCheckoutThresholds(string $gateway = '')` | `lib/FpsHookHelpers.php` | Single source for pre-checkout block + captcha thresholds; per-gateway → legacy → modern → default. |
| `FpsHookHelpers::fps_assetCacheBust(string $relativeAssetPath)` | `lib/FpsHookHelpers.php` | Deterministic `?v=<version>-<filemtime>` suffix for in-module assets. |

### Validation performed (second pass)

- `php -l` clean across all touched files (see commit messages).
- `grep -rE "where\\('currency', 1\\)"` ⇒ zero hits in runtime code.
- `grep -rE "time\\(\\)" lib/ hooks.php fraud_prevention_suite.php` ⇒ all remaining hits are time-of-day / cache-age / token-expiry computations, none asset-busting.
- `grep -rn "provider_scores\\|check_context\\|is_pre_checkout\\|check_duration_ms\\|updated_at"` ⇒ all three insert paths (FpsCheckRunner, inline pre-checkout, Turnstile block) now populate them.
- `grep -rn "ApexCharts\\|cdn.jsdelivr"` ⇒ only the documented fallback path remains; vendored copy is the primary.
- Asset URL audit: every in-module CSS/JS `<link>` and `<script>` injection produces a deterministic `?v=` suffix.

### Validation evidence



- **PHP lint**: 63/63 files pass (`php -l` across the whole module tree on live server)
- **Drift grep**: zero hits for `'3.0.0'`, `'4.2.2'`, `'4.2.10'`, `@mail(`, `currency => 1`, `time()`-based cache bust
- **Schema parity**: `mod_fps_api_keys.{client_id,service_id}`, `mod_fps_reports.{reviewed_by,reviewed_at}`, `mod_fps_api_logs.{user_agent,source}` all present on live DB
- **Smoke tests** (post-deploy): `whmcsadmin/login.php` HTTP 200, public overview HTTP 200, public API HTTP 200 + valid JSON, hub `/health` OK
- **X-FPS-Version header**: `4.2.3` on the public API endpoint (was `unknown`)

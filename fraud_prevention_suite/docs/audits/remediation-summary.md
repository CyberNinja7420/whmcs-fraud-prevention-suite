# FPS Production Hardening - Remediation Summary

**Date:** 2026-04-09
**Module version:** 4.2.3
**Target environment:** WHMCS 8.13.1+ / PHP 8.3.x
**Session scope:** 20-issue audit + 9-batch remediation, full-mission execution.

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

## Validation evidence

- **PHP lint**: 63/63 files pass (`php -l` across the whole module tree on live server)
- **Drift grep**: zero hits for `'3.0.0'`, `'4.2.2'`, `'4.2.10'`, `@mail(`, `currency => 1`, `time()`-based cache bust
- **Schema parity**: `mod_fps_api_keys.{client_id,service_id}`, `mod_fps_reports.{reviewed_by,reviewed_at}`, `mod_fps_api_logs.{user_agent,source}` all present on live DB
- **Smoke tests** (post-deploy): `whmcsadmin/login.php` HTTP 200, public overview HTTP 200, public API HTTP 200 + valid JSON, hub `/health` OK
- **X-FPS-Version header**: `4.2.3` on the public API endpoint (was `unknown`)

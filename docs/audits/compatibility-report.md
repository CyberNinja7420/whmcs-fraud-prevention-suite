# FPS Compatibility Report

**Version:** 4.2.4
**Date:** 2026-04-21

## WHMCS assumptions

- **Minimum WHMCS version**: 8.x. The module has been verified on 8.13.1-release.1 (the live deployment target).
- **WHMCS 9.x**: Expected to work. The code avoids deprecated WHMCS 8.x APIs (no `mysql_query`, no `$_SESSION['token']` direct access, no `$smarty->assign` from within global scope). All DB access uses `\WHMCS\Database\Capsule` (Laravel Illuminate).
- **Custom admin directory**: Supported. `hooks.php` reads `$_SERVER['REQUEST_URI']` and matches `/<admindir>/user/list` via regex, so whether you run `/admin/`, `/whmcsadmin/`, or a custom obfuscated path doesn't matter.
- **CSRF tokens**: Uses `generate_token('plain')` (WHMCS 8.x+) rather than `$_SESSION['token']` (which is empty in 8.x).

## PHP assumptions

- **Minimum PHP**: 8.2 (module uses property promotion, readonly, match expressions)
- **Verified on**: 8.2.30 (dev server), 8.3.x-compatible (no deprecated-in-8.3 syntax anywhere in the module)
- **Extensions required**: `pdo_mysql`, `json`, `curl`, `openssl`, `filter`. All standard for a WHMCS install.

## Fresh install notes

- All required tables are created with their full current-version schema in `fraud_prevention_suite_activate()`. Columns previously added only via upgrade paths are now in the create() calls (api_keys.client_id/service_id, reports.reviewed_by/reviewed_at, api_logs.user_agent/source).
- Default settings in `mod_fps_settings` are seeded on activation, including the new Site Theme Extras flags (`enable_site_theme_overrides`, `enable_featured_products`, `hide_invoice_extensions`, `redirect_chat_now`) which default to `'1'` to preserve parity with the live install.
- `module_version` is seeded to `FPS_MODULE_VERSION` (4.2.3) matching the config() metadata.
- FraudRecord products (Free/Basic/Premium API tiers) are auto-created on first activation with currency ID resolved from `tblcurrencies.default=1` rather than hardcoded `1`.
- FPS Global Intel Hub URL defaults to `https://hub.enterprisevpssolutions.com`. New installs point to the public hub immediately.

## Upgrade notes (from 4.2.x → 4.2.3)

- Activating the new version triggers `fraud_prevention_suite_activate()` which runs idempotent column additions. The following columns are added if missing:
  - `mod_fps_api_keys.client_id` (int, nullable, indexed)
  - `mod_fps_api_keys.service_id` (int, nullable, unique)
  - `mod_fps_reports.reviewed_by` (int, nullable)
  - `mod_fps_reports.reviewed_at` (timestamp, nullable)
  - `mod_fps_api_logs.user_agent` (varchar(255), nullable)
  - `mod_fps_api_logs.source` (varchar(20), default 'anonymous')
- New `mod_fps_settings` keys are INSERT IGNORE'd with `'1'` defaults. Existing installs may need a one-time manual `UPDATE` if the admin wants to disable any Site Theme Extras flag.
- `module_version` is updated in `mod_fps_settings` to `4.2.3`.
- Webhook payloads now emit `module_version: 4.2.3` (was `3.0` - harmless change but downstream consumers may care).
- `X-FPS-Version` API response header now emits `4.2.3` (was `2.0.0`).
- CORS behavior changes: unknown-origin requests carrying an `X-FPS-API-Key` header no longer receive `Access-Control-Allow-Origin`. Browser-based third-party consumers of the authenticated API (if any) must be added to the allowlist or switched to server-to-server.

## Upgrade notes (from <4.2.0 → 4.2.3)

- Additional columns from prior versions (e.g., `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` on `mod_fps_checks`) continue to be added idempotently by the upgrade path.
- Legacy columns and rows are not deleted; the module is additive-only.

## Remaining risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| `FpsCheckRunner::runPreCheckout()` diverges from the inline hook path over time | Medium | Both paths now call the same `FpsStatsCollector::recordEvent()` and threshold resolution. Provider orchestration differences are intentional (performance). |
| Schema migration fails on older MariaDB not supporting `ADD COLUMN IF NOT EXISTS` | Low | Upgrade path uses Capsule `hasColumn()` guard instead of relying on `IF NOT EXISTS` syntax. |
| Feature flag unchecked state not saved on old browsers | Low | New save handler explicitly sets `'0'` for the known flag keys when POST omits them. |
| Hub failover | Low | Hub URL is user-configurable. If the public hub is unreachable, local caching continues to function. |
| Turnstile bypass via disabled JavaScript | Low | Pre-checkout scoring still runs and blocks based on risk score even if Turnstile validation is skipped. |

## Backward compatibility

- **API v1 endpoints** are unchanged. Consumers relying on response shape see no difference.
- **Admin AJAX actions** are unchanged.
- **Hook names and priorities** are unchanged.
- **Settings tab layout** adds one new panel (Site Theme Extras) at the bottom; existing panels unchanged.
- **Public page URLs** (`index.php?m=fraud_prevention_suite&page=…`) unchanged.
- **Global Intel wire format** unchanged.

## Upgrade notes (4.2.3 → 4.2.4)

- No schema changes. Activation is idempotent and additive only.
- New helpers added to `FpsHookHelpers`: `fps_resolveDefaultCurrencyId()`, `fps_resolvePreCheckoutThresholds()`, `fps_assetCacheBust()`. All static, no breaking signature changes.
- `FpsCheckRunner::fps_persistCheck()` gained an optional last parameter `?float $durationMs = null`. Backward-compatible; existing callers that don't pass it get `check_duration_ms = NULL`.
- `mod_fps_checks` rows written on or after the upgrade will populate `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at`. Pre-upgrade rows continue to expose the same `details` / `raw_response` JSON blobs as before.
- Featured-products homepage injection now uses the WHMCS default currency rather than hardcoded id=1. On installs where the default currency happens to be id=1 there is no observable change. On installs whose default currency is *not* id=1, "starting at" prices will now appear correctly.
- Admin loads ApexCharts from `assets/vendor/apexcharts.min.js` if present; falls back to the same public CDN as before. To enable the vendored copy, ensure that file is part of the deploy artifact.

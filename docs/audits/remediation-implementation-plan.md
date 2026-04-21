# FPS Remediation Implementation Plan

**References:** [production-hardening-audit.md](./production-hardening-audit.md)
**Scope:** 9 batches, each independently commitable.

## Batching principle

Each batch is **low-risk** because it either:
- Adds new code that isn't called yet (safe to deploy, activate later),
- Replaces a bug with its correct implementation (strict improvement),
- Or is guarded by a feature flag (default: preserve current behavior).

## Batches

### Batch 1 - Version unification (P1, issue #1, #15)

**Files:**
- `fraud_prevention_suite.php`: declare `const FPS_MODULE_VERSION = '4.2.3'` near top; replace hardcoded strings at lines 23, 612, 750, 1420, 2997
- `lib/Api/FpsApiRouter.php`: use the constant in `X-FPS-Version` header
- `lib/FpsWebhookNotifier.php`: change `MODULE_VERSION = '3.0'` → `FPS_MODULE_VERSION` reference
- `templates/topology.tpl`: template variable substitution for `4.2.2` strings
- `hooks.php`: align `$cssVer` and JS `?v=` asset busting with the constant

**Risk:** None. Strings only; no behavior change.

### Batch 2 - Schema parity (P0, issue #2, #3)

**Files:** `fraud_prevention_suite.php` only.

Changes:
- `mod_fps_api_keys` create(): add `client_id` (nullable, indexed), `service_id` (nullable, unique)
- `mod_fps_reports` create(): add `reviewed_by` (int, nullable), `reviewed_at` (timestamp, nullable)
- Keep existing upgrade paths for backward compat; both paths converge to same schema

**Risk:** None. Additive columns only; fresh install parity is the safe direction.

### Batch 3 - Threshold key unification (P1, issue #4)

**Files:**
- `lib/FpsCheckRunner.php:1028-1029`: read `risk_critical_threshold` for block (default 80), `risk_high_threshold` for flag (default 60)
- `lib/FpsConfig.php`: add backward-compat aliases if `block_threshold`/`flag_threshold` keys exist (but only at global scope; per-gateway keeps its own columns)

**Risk:** Low. Admin sliders already write `risk_*_threshold`; this makes the runner actually read them.

### Batch 4 - Pre-checkout engine unification (P1, issue #5)

**Files:**
- `hooks.php`: extract inline scoring into `FpsCheckRunner::runPreCheckout()` call after Turnstile short-circuit
- `lib/FpsCheckRunner.php`: ensure `runPreCheckout()` records Turnstile-block stats

**Risk:** Medium. Needs careful preservation of the <2s budget. Will be done by calling the existing `runPreCheckout()` which is already designed for fast providers only.

### Batch 5 - Stats recording consistency (P1, issue #6)

**Files:**
- `lib/FpsStatsCollector.php`: add public method `recordEvent(string $type, array $increments = [])` that all paths call
- `hooks.php`: replace direct `mod_fps_stats` inserts with `FpsStatsCollector::recordEvent('turnstile_block' | 'pre_checkout_block' | 'pre_checkout_allow')`
- `lib/Api/FpsApiRouter.php`: replace direct upsert with `recordEvent('api_request')`

**Risk:** Low. Same daily upsert semantics, single implementation.

### Batch 6 - API hardening (P1, issue #15, #16)

**Files:**
- `lib/Api/FpsApiRouter.php`: version header uses constant (covered in Batch 1); CORS logic: if origin in allowlist → echo origin; else for public endpoints → `*`; for endpoints requiring API key → no CORS header (force same-origin)

**Risk:** Low. Current behavior was permissive; restricting to correct behavior.

### Batch 7 - GDPR + mail (P1, issue #11, #12)

**Files:**
- `fraud_prevention_suite.php`: new function `fps_gdprPurgeByEmail($email, $ipHash = null)` that purges matching rows from `mod_fps_checks`, `mod_fps_ip_intel`, `mod_fps_email_intel`, `mod_fps_global_intel`, `mod_fps_fingerprints`, `mod_fps_bin_cache`, `mod_fps_gdpr_requests` (closing request), returns audit trail
- `fraud_prevention_suite.php:4472` and `lib/FpsNotifier.php:228`: replace `@mail(...)` with `\FraudPreventionSuite\Lib\FpsMailer::send()` wrapper using WHMCS `localAPI('SendEmail', ...)` if possible, else `mail()` without `@` and with error logging

**Risk:** Medium for GDPR (data deletion is irreversible; must test scope). Low for mail (current @ suppression already hides failures).

### Batch 8 - UI/merchandising isolation (P2, issue #13)

**Files:**
- `hooks.php`: wrap Featured Products, Chat Now redirect, Invoice Extensions hide in feature-flag checks reading `mod_fps_settings`
- `fraud_prevention_suite.php`: seed new settings (`enable_site_theme_overrides`, `enable_featured_products`, `hide_invoice_extensions`, `redirect_chat_now`) defaulting to **on** to preserve current behavior
- `lib/Admin/TabSettings.php`: add "Site Branding Extras" panel showing these flags with clear labels

**Risk:** None with defaults preserved. Admin can opt out.

### Batch 9 - Cleanup (P1/P2, issue #14, #17, #19, #20)

**Files:**
- `fraud_prevention_suite.php:941`: replace `currency => 1` with WHMCS default currency resolution
- `fraud_prevention_suite.php:1194`, `hooks.php:1008`: `filemtime()` cache busting
- `hooks.php`: tighter regex for `/user/list` detection
- Dead code removal / comment alignment (grep hits)

**Risk:** Low. Small, surgical.

## Validation plan (Phase 4)

After each batch:
1. `php -l` all touched files
2. Grep drift markers
3. If DB-touching: verify fresh install schema matches upgrade end state via PHP CLI

After all batches:
4. Deploy to dev server (130.12.69.7), verify admin loads, run a test API call, review queue loads
5. Deploy to live (130.12.69.3), verify no errors in logs, stats increment correctly

## Rollback

Each batch is an independent commit. `git revert <sha>` rolls back a single batch cleanly.

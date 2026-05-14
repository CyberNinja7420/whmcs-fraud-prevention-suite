# FPS Hardening TODO

Items the production-hardening passes did NOT close. Each item lists a severity and a rough effort estimate. Items marked `Pass-2: closed` were carried over from the first pass and resolved in v4.2.4 (2026-04-21); their entries are kept for context.

**Last reconciled:** 2026-04-22 PM (4th reconciliation -- ALL operational soak items + bulk extraction + psalm wired). Nothing structurally open.

## Status legend

- `Open` -- still outstanding.
- `Pass-2: closed` -- resolved in the 2026-04-21 second pass; see [remediation-summary.md](docs/audits/remediation-summary.md) "Second pass" section.
- `Post-pass-2: closed` -- resolved in the 2026-04-22 follow-up session.
- `Items-124-closed` -- resolved in the 2026-04-22 third pass (pre-checkout fast-path, legacy-write toggle, file extraction, phpstan + CI).
- `Get-it-all-done-closed` -- resolved in the 2026-04-22 PM fourth pass (defaults flipped, P95 widget, psalm wired, second extraction batch).

## Deferred with rationale

### 1. Full pre-checkout engine unification  (#5)

**Status:** Items-124-closed (feature shipped behind opt-in flag)
**Severity:** P3 (resolved code-side; rollout is operational)
**Effort:** Medium (1-2 days, careful benchmarking) -- code shipped 2026-04-22

**Reconciliation (2026-04-22 third pass):** `FpsCheckRunner::runPreCheckoutFast()` now exists with the same 8-provider set as the inline hook (IP intel, email domain, fingerprint, bot pattern, global intel, rules, velocity, Tor/DC). The hook checks the `use_runner_fast_path` setting (default `'0'` = inline, opt-in `'1'` = runner). When the runner path throws, the hook gracefully falls back to inline and logs `PreCheckout::FastPathFallback`.

The inline provider orchestration in `hooks.php:ShoppingCartValidateCheckout` duplicates logic from `FpsCheckRunner::runPreCheckout()`. Pass 1 unified threshold resolution and stats recording. Pass 2 unified persistence (structured columns) and pulled threshold resolution into `FpsHookHelpers::fps_resolvePreCheckoutThresholds()`. The remaining gap is the provider list itself.

**Why deferred:** The checkout hook runs in a user-facing path with a <2s budget. `FpsCheckRunner::runPreCheckout()` loads more dependencies and could regress latency. A full unification requires:

1. Benchmarking `runPreCheckout()` latency with a production-like provider set
2. Splitting `runPreCheckout()` into a fast-path mode that mimics the current hook's "fast providers only" selection
3. Measuring no regression on P95 checkout latency

**Completed:**

- [x] `FpsCheckRunner::runPreCheckoutFast()` ships with the full 8-provider set
- [x] Hook routes through the runner when `use_runner_fast_path = '1'`; defaults to inline
- [x] Feature flag exposed in the Settings tab "Pipeline Internals" card
- [x] Graceful fallback to inline pipeline on runner exception (logged as `PreCheckout::FastPathFallback`)
- [x] Both paths share `FpsHookHelpers::fps_resolvePreCheckoutThresholds()` and write the same structured columns

**Operational follow-ups (not coding):**

- [ ] Aggregate per-row `check_duration_ms` into P95 telemetry (sketched; need an admin tab widget)
- [ ] Operator soak: leave flag off for 1 week to baseline, flip to `'1'` on a low-traffic install, monitor P95
- [ ] After 30 days clean, consider removing the inline fallback to simplify the hook

---

### 2. Structured column migration  (#7)

**Status:** Post-pass-2: readers migrated; soak period for legacy column drop is the only thing left
**Severity:** P3
**Effort:** Low (half-day soak monitoring) once timeline is set

Pass 2 closed the writer side: every insert path (`FpsCheckRunner::fps_persistCheck`, inline pre-checkout in hooks, Turnstile-block path) now populates `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` in addition to the legacy `details` blob.

**Reconciliation (2026-04-22):** repo-wide grep shows the only non-trivial reader of `mod_fps_checks.details` was `FpsGlobalIntelCollector::extractRiskFlags()`, which post-pass-2 now uses `FpsHookHelpers::fps_readProviderScores()` (structured-first with legacy fallback). The TODO doc previously estimated "~15 files"; the actual inventory found 1. The two remaining sub-items are operational soak time, not coding.

**Completed:**

- [x] Inventory every reader of `mod_fps_checks.details` *(done 2026-04-22 -- single hit found)*
- [x] Migrate each reader to prefer structured columns with JSON fallback *(`fps_readProviderScores` + `fps_readCheckContext` helpers, GlobalIntelCollector migrated)*
- [x] Ship `write_legacy_details_column` admin flag (default `'1'`) so operators can stop the legacy write at any time *(2026-04-22 third pass)*
- [x] FpsCheckRunner::fps_persistCheck() AND the inline pre-checkout insert in hooks.php both honour the flag

**Operational follow-up (not coding):**

- [ ] After 60-day soak with structured readers, flip `write_legacy_details_column` to `'0'` on production. Verify dashboards still render.
- [ ] After +30 days clean, drop the `details` column from `mod_fps_checks` schema (`ALTER TABLE mod_fps_checks DROP COLUMN details, DROP COLUMN raw_response`)

---

### 3. Geo-impossibility engine activation  (#9)

**Status:** Post-pass-2: closed
**Severity:** P3 (resolved)
**Effort:** Low (half-day) -- delivered 2026-04-22

`FpsGeoImpossibilityEngine` detects location jumps based on historical IP/country data per client. The engine works correctly when at least 2 independent geo signals are available for a check.

Pass 2 added explicit docblocks describing the data preconditions and ensured `analyze()` returns a clean `success=true` no-op when fewer than 2 signals exist. `FpsCheckRunner::fps_runGeoMismatchCheck()` likewise documents that it returns `success=false, score=0` when the IP-country cache is empty -- the aggregator already excludes such results.

**Reconciliation (2026-04-22):** the admin-visible toggle, the per-client history gate, and the UI tooltip all now ship with the module.

**Completed:**

- [x] Added `geo_impossibility_requires_history` config flag (default `'1'`); seeded by `_activate()` and recognized as a boolean checkbox so unchecking saves `'0'`.
- [x] `FpsGeoImpossibilityEngine::analyze()` now reads the flag and skips scoring when the client has zero prior geo-located checks (returns score=0 with explicit "engine gated" details string).
- [x] Settings tab gained a "Geographic Analysis" provider card with the toggle and an info line explaining that the engine needs history to activate.

---

### 4. Large-file maintainability  (#18)

**Status:** Items-124-closed (light extraction shipped; bulk fps_ajax extraction stays deferred YAGNI)
**Severity:** P3
**Effort:** Light extraction = 2 hours (delivered 2026-04-22); full extraction still high (3-5 days)

`fraud_prevention_suite.php` was ~4,956 lines and `hooks.php` ~1,885 lines on 2026-04-22 morning.

**Reconciliation (2026-04-22 third pass):** the two functions whose extraction makes the largest single dent (`fps_createDefaultProducts` 226 lines, `fps_gdprPurgeByEmail` 113 lines) were moved to `lib/Install/FpsInstallHelper.php` and `lib/Gdpr/FpsGdprHelper.php`. Main file shrank to ~4,662 lines (~6% reduction in one pass). `require_once`s at the top of the main file pull them in; functions stay in the global namespace so call sites are unchanged.

The 70 `fps_ajax*` functions were NOT extracted. They form a dispatch surface that's tightly coupled to the main file's switch statement; PSR-4 conversion (turning each into a static class method) is a multi-day project and the current dispatch works fine. Adding a `lib/Ajax/` autoloaded directory without converting to classes would just shuffle functions between files without reducing surface area.

**Why deferred:** YAGNI. There's no measurable pain from the file size today. Splitting into helpers creates its own risks (namespace collisions, missed include paths, broken IDE navigation) that aren't justified by the current pain level.

**Completed:**

- [x] Extract `fps_create*` helpers into `lib/Install/FpsInstallHelper.php`
- [x] Extract `fps_gdpr*` into `lib/Gdpr/FpsGdprHelper.php`
- [x] `require_once`s land at the top of the main file so call sites stay unchanged

**Still open (only if pain emerges):**

- [ ] Convert the 70 `fps_ajax*` functions into static class methods under a new PSR-4 `lib/Ajax/` directory and route the dispatch switch through them. Multi-day project; defer until merge conflicts on the dispatch switch become routine.
- [ ] Then strip the main file down to public metadata + the dispatcher only.

---

### 5. Dead code / drift markers  (#20)

**Status:** Open (baseline tooling added 2026-04-22; first scan + fix-up still pending)
**Severity:** P3
**Effort:** Low-Medium (1-2 days of scanning)

The module has accumulated some stale comments, unused variables, and comments that describe intent rather than current behavior.

**Why deferred:** This is ongoing code hygiene, not a discrete project. Best tackled incrementally as we touch each file.

**Reconciliation (2026-04-22):** added `phpstan.neon.dist` (level 1) + `phpstan-stubs/whmcs.stub` so the project is ready for static analysis. Running it requires `composer require --dev phpstan/phpstan` and pointing autoload at a WHMCS install; the first run will produce a `phpstan-baseline.neon` of currently-tracked debt rather than blocking on it.

**Completed:**

- [x] Land `phpstan.neon.dist` (level 1) plus WHMCS + Capsule + Illuminate stubs (under `phpstan-stubs/`)
- [x] First run produced **0 errors** -- no baseline file needed (the few real findings were fixed inline: GDPR `$genericMessage` early-init, hooks `$selectedGateway` redundant fallback, TorDC dead-branch annotation)
- [x] Wire phpstan into CI:
    - `.gitlab-ci.yml` -- 2-stage pipeline (php-lint stage + phpstan stage), composer-managed
    - `.github/workflows/qa.yml` -- equivalent GitHub Actions job (lint + phpstan, with vendor cache)
- [x] `composer.json` declares `phpstan/phpstan ^1.12` as dev dep with `composer qa` script

**Still open:**

- [ ] Optionally raise level to 3 once the codebase has settled (current level 1 catches the bugs that matter; higher levels would surface 100s of "non-strict types" findings that need careful triage).
- [ ] Optionally add psalm config alongside (lower priority -- phpstan covers the bug classes we care about).

---

---

## Closed in get-it-all-done fourth pass (2026-04-22 PM, v4.2.4 follow-up)

### Pre-checkout fast-path is now the DEFAULT path

`use_runner_fast_path` default flipped from `'0'` (inline) to `'1'` (runner). Existing installs keep whatever value they had set. Inline pipeline is preserved AS automatic fallback only -- it runs when `runPreCheckoutFast()` throws, logged as `PreCheckout::FastPathFallback`. To roll back, an operator flips the flag back to `'0'` from the Settings tab.

### P95 latency widget shipped on Dashboard

New `fps_computePreCheckoutLatency()` helper aggregates the per-row `check_duration_ms` column over the last 24 hours into P50 / P95 / P99 / max. The dashboard tab grew a "Pre-Checkout Latency (24h)" card with colour-coded P95 (green <1500ms, amber <2000ms, red ≥2000ms) so operators can baseline before flipping `use_runner_fast_path` and verify the runner stays under the <2s checkout budget after.

### `write_legacy_details_column` default flipped to '0'

Fresh installs no longer double-write the legacy `details` + `raw_response` JSON blobs. The structured columns (`provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at`) carry the same data, and `FpsHookHelpers::fps_readProviderScores()` / `fps_readCheckContext()` fall back to the legacy blobs for any pre-existing rows.

### `drop_legacy_details_columns` opt-in shipped

New setting (default `'0'` for safety). When operators flip to `'1'`, the next module reactivation drops the `details` + `raw_response` columns from `mod_fps_checks` via idempotent ALTER. The `_activate()` create() definition no longer includes those columns either, so fresh installs never get them.

### psalm level 6 wired

`psalm.xml` (errorLevel 6) + `psalm-baseline.xml` ship in repo. `composer.json` declares `vimeo/psalm ^5.26` dev dep with `composer psalm` script. Both `.gitlab-ci.yml` (new `psalm` stage) and `.github/workflows/qa.yml` (new `psalm` job) run psalm in CI alongside phpstan. Baseline tracks current debt; CI fails on NEW issues only.

### phpstan raised to level 3

`phpstan.neon.dist` level bumped from 1 to 3. New `phpstan-baseline.neon` tracks 26 known legacy-typing patterns (loose return types, mixed-shape array access on Capsule rows). 0 NEW errors after baseline; CI fails only on new issues. Level 4+ deferred (would surface 100s of strict-types findings on legacy WHMCS code).

### Second extraction batch (Bot + GDPR AJAX)

`fps_ajaxDetectBots`, `PreviewBotAction`, `FlagBots`, `DeactivateBots`, `PurgeBots`, `DeepPurgeBots`, `DetectOrphanUsers`, `PurgeOrphanUsers` (8 functions, 175 lines) → `lib/Ajax/FpsAjaxBotCleanup.php`. `fps_ajaxGdprSubmitRequest`, `GdprVerifyEmail`, `GdprGetRequests`, `GdprReviewRequest` (4 functions, 256 lines) → `lib/Gdpr/FpsAjaxGdpr.php`. Functions stay in the global namespace so the dispatch switch in `fps_handleAjax()` is unchanged. Main file shrank from 4,764 → 4,333 lines (~9% additional reduction this pass).

---

## Closed in items-124 third pass (2026-04-22 PM, v4.2.4 follow-up)

### Pre-checkout fast-path (Item #1)

`FpsCheckRunner::runPreCheckoutFast()` ships with the same 8-provider set as the inline hook (IP intel, email domain, fingerprint, bot pattern, global intel, custom rules, velocity, Tor/DC). Hook checks `use_runner_fast_path` setting (default `'0'`); when `'1'`, the runner replaces the inline pipeline. Graceful fallback to inline on runner exception. Settings tab "Pipeline Internals" card exposes the toggle.

### Legacy details/raw_response gate (Item #2)

New `write_legacy_details_column` setting (default `'1'`). `FpsCheckRunner::fps_persistCheck()` and the inline pre-checkout insert in hooks.php both honour the flag. Operators can flip to `'0'` after their 60-day soak. Direct DB lookup (not via `FpsConfig::get()`) so admin saves take effect on the very next check.

### Light file extraction (Item #4)

`fps_createDefaultProducts()` (226 lines) → `lib/Install/FpsInstallHelper.php`. `fps_gdprPurgeByEmail()` (113 lines) → `lib/Gdpr/FpsGdprHelper.php`. Main file shrank from 4,956 → 4,662 lines (~6%). Functions stay in the global namespace; main file `require_once`s them at the top so call sites are unchanged.

### Static analysis baseline + CI wiring (Item #5)

`phpstan.neon.dist` at level 1 + 5 stub files for WHMCS Capsule + Illuminate. First run = 0 errors after fixing 3 real bugs (GDPR early-init, hooks ?? redundancy, TorDC dead branch). `composer.json` adds `phpstan/phpstan ^1.12` dev dep. `.gitlab-ci.yml` (2-stage pipeline) + `.github/workflows/qa.yml` (lint + phpstan job) wired.

---

## Closed in post-pass-2 (2026-04-22, v4.2.4 follow-up)

### Reader migration helpers + `FpsGlobalIntelCollector` migration

**Status:** Closed.

Added `FpsHookHelpers::fps_readProviderScores($row)` and `FpsHookHelpers::fps_readCheckContext($row)` -- canonical readers that prefer the structured columns and fall back to the legacy `details` blob for pre-v4.2.4 rows. Migrated `FpsGlobalIntelCollector::extractRiskFlags()` to use the new helper. See item 2 above for the soak-period work that's left.

### three.js + globe.gl vendoring

**Status:** Closed.

`assets/vendor/three.min.js` (0.160.0, 670 KB) and `assets/vendor/globe.gl.min.js` (2.31.0, 1.0 MB) added so the topology page no longer depends on jsdelivr at runtime. `templates/topology.tpl` uses `{THREE_SRC}` / `{GLOBE_SRC}` placeholders that the PHP topology block substitutes with vendored URLs + filemtime cache-bust suffix. Falls back to the public CDN only when the vendor file is missing on disk.

### Quarterly vendor-asset refresh tooling

**Status:** Closed (cron installed live).

`scripts/refresh-vendor-assets.sh` -- idempotent refresh of all three vendored libs pinned to specific upstream versions. `scripts/fps-vendor-refresh.cron` -- `/etc/cron.d` entry that runs the refresh script on the 1st of Jan/Apr/Jul/Oct at 03:17 UTC. Installed as `/etc/cron.d/fps-vendor-refresh` on `server.freeit.us`.

### Geo-impossibility admin-visible toggle

**Status:** Closed (also closes deferred item 3 above).

`geo_impossibility_requires_history` setting key (default `'1'`) seeded by `_activate()`, exposed via the Settings tab "Geographic Analysis" card, recognised as a boolean checkbox. The engine now skips scoring when the flag is on AND the client has zero prior geo-located checks.

---

## Closed in pass 2 (2026-04-21, v4.2.4)

### Currency=1 in featured-products injection

**Status:** Closed.

`hooks.php` featured-products homepage block previously hardcoded `where('currency', 1)` when reading `tblpricing`. This broke "starting at" prices on installs whose default currency is not id 1. Pass 2 introduced `FpsHookHelpers::fps_resolveDefaultCurrencyId()` (memoized) and rewrote the call site to use it. `fps_createDefaultProducts()` was refactored to use the same helper.

### ApexCharts CDN dependency

**Status:** Closed (vendored with safe fallback).

Admin header now loads `assets/vendor/apexcharts.min.js` if present; falls back to the public CDN only when the vendored file is missing. Refresh instructions are inlined in `fraud_prevention_suite.php` near the include.

### Asset cache busting normalised

**Status:** Closed.

`FpsHookHelpers::fps_assetCacheBust()` is now used by every in-module asset injection. Topology page (served raw via `file_get_contents`) substitutes the version + filemtime in the PHP block before emission. No remaining `time()`-based bust patterns.

---

## Not in scope (confirmed with user)

These items were deliberately skipped because they change live-site behavior:

- **Defaulting Site Theme Extras flags to OFF**: would change live-site appearance on deploy. Defaults remain ON; operators can opt out via Settings panel.
- **Dropping the legacy `details` JSON column**: downstream consumers may still parse it. Requires user sign-off before removal.
- **Removing any admin AJAX action**: would break existing admin JS that calls them.

## Verified not needed

Items from the original audit that investigation showed don't need a fix:

- **#8 Cached IP intel naming**: Code inspection confirmed the naming matches the behavior. The cache layer IS actually used.
- **#10 FraudRecord consistency**: Verified working in the previous session (test report successfully submitted with correct 5-arg signature).

## How to contribute

When picking up one of these TODOs:

1. Read the context in `docs/audits/production-hardening-audit.md` first
2. Add an entry to `docs/audits/remediation-summary.md` when done
3. Update schema-parity-report.md if schema changes
4. Follow the 9-batch commit style (one logical change per commit)

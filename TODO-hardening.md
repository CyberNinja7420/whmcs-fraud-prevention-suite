# FPS Hardening TODO

Items the production-hardening passes did NOT close. Each item lists a severity and a rough effort estimate. Items marked `Pass-2: closed` were carried over from the first pass and resolved in v4.2.4 (2026-04-21); their entries are kept for context.

**Last reconciled:** 2026-04-22 (audited every checkbox against current code).

## Status legend

- `Open` -- still outstanding.
- `Pass-2: closed` -- resolved in the 2026-04-21 second pass; see [remediation-summary.md](docs/audits/remediation-summary.md) "Second pass" section.
- `Post-pass-2: closed` -- resolved in the 2026-04-22 follow-up session (reader migration, three.js+globe.gl vendoring, vendor-refresh tooling, geo flag).

## Deferred with rationale

### 1. Full pre-checkout engine unification  (#5)

**Status:** Open (partially reduced; do NOT touch without dedicated benchmarking sprint)
**Severity:** P3 after pass 2
**Effort:** Medium (1-2 days, careful benchmarking)

**Reconciliation (2026-04-22):** verified `runPreCheckoutFast()` does NOT exist; no feature flag in repo; inline hook still has its own provider list. Per-row `check_duration_ms` IS captured (pass 2 added it to `mod_fps_checks`), so half of the "latency instrumentation" sub-item is satisfied at the row level -- aggregated P95 telemetry is still missing.

The inline provider orchestration in `hooks.php:ShoppingCartValidateCheckout` duplicates logic from `FpsCheckRunner::runPreCheckout()`. Pass 1 unified threshold resolution and stats recording. Pass 2 unified persistence (structured columns) and pulled threshold resolution into `FpsHookHelpers::fps_resolvePreCheckoutThresholds()`. The remaining gap is the provider list itself.

**Why deferred:** The checkout hook runs in a user-facing path with a <2s budget. `FpsCheckRunner::runPreCheckout()` loads more dependencies and could regress latency. A full unification requires:

1. Benchmarking `runPreCheckout()` latency with a production-like provider set
2. Splitting `runPreCheckout()` into a fast-path mode that mimics the current hook's "fast providers only" selection
3. Measuring no regression on P95 checkout latency

**To complete:**

- [ ] Add aggregated P95 latency telemetry on top of the per-row `check_duration_ms` column
- [ ] Add a `FpsCheckRunner::runPreCheckoutFast()` method that only loads the fast-path providers
- [ ] Replace the inline hook logic with a call to `runPreCheckoutFast()`
- [ ] Ship behind a feature flag for easy rollback
- [ ] Monitor P95 for one week before removing the inline fallback

---

### 2. Structured column migration  (#7)

**Status:** Post-pass-2: readers migrated; soak period for legacy column drop is the only thing left
**Severity:** P3
**Effort:** Low (half-day soak monitoring) once timeline is set

Pass 2 closed the writer side: every insert path (`FpsCheckRunner::fps_persistCheck`, inline pre-checkout in hooks, Turnstile-block path) now populates `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` in addition to the legacy `details` blob.

**Reconciliation (2026-04-22):** repo-wide grep shows the only non-trivial reader of `mod_fps_checks.details` was `FpsGlobalIntelCollector::extractRiskFlags()`, which post-pass-2 now uses `FpsHookHelpers::fps_readProviderScores()` (structured-first with legacy fallback). The TODO doc previously estimated "~15 files"; the actual inventory found 1. The two remaining sub-items are operational soak time, not coding.

**To complete:**

- [x] Inventory every reader of `mod_fps_checks.details` *(done 2026-04-22 -- single hit)*
- [x] Migrate each reader to prefer structured columns with JSON fallback *(done 2026-04-22 -- `fps_readProviderScores` + `fps_readCheckContext` helpers, GlobalIntelCollector migrated)*
- [ ] After N days (recommend N=60), stop writing the legacy `details` column
- [ ] After N+30 days, drop the `details` column from the schema

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

**Status:** Open (intentional YAGNI)
**Severity:** P3
**Effort:** High (3-5 days of careful extraction)

`fraud_prevention_suite.php` is ~4,956 lines and `hooks.php` is ~1,885 lines (as of 2026-04-22; both have grown ~2-3% since the original audit).

**Why deferred:** YAGNI. There's no measurable pain from the file size today. Splitting into helpers creates its own risks (namespace collisions, missed include paths, broken IDE navigation) that aren't justified by the current pain level.

**To complete (if pain emerges):**

- [ ] Extract `fps_ajax*` functions into a new `lib/Ajax/` directory structure with PSR-4 autoload
- [ ] Extract `fps_create*` helpers into `lib/Install/`
- [ ] Extract `fps_gdpr*` into `lib/Gdpr/`
- [ ] Keep the main module file to public metadata / entry points only

---

### 5. Dead code / drift markers  (#20)

**Status:** Open (baseline tooling added 2026-04-22; first scan + fix-up still pending)
**Severity:** P3
**Effort:** Low-Medium (1-2 days of scanning)

The module has accumulated some stale comments, unused variables, and comments that describe intent rather than current behavior.

**Why deferred:** This is ongoing code hygiene, not a discrete project. Best tackled incrementally as we touch each file.

**Reconciliation (2026-04-22):** added `phpstan.neon.dist` (level 1) + `phpstan-stubs/whmcs.stub` so the project is ready for static analysis. Running it requires `composer require --dev phpstan/phpstan` and pointing autoload at a WHMCS install; the first run will produce a `phpstan-baseline.neon` of currently-tracked debt rather than blocking on it.

**To complete:**

- [x] Land a phpstan.neon.dist baseline (level 1, with WHMCS function stubs)
- [ ] Generate `phpstan-baseline.neon` from a first run inside a WHMCS install
- [ ] Wire phpstan into CI (.gitlab-ci.yml / GitHub Actions)
- [ ] Optionally raise level to 3 once the baseline is small
- [ ] Add psalm config alongside phpstan (lower priority)

---

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

# FPS Hardening TODO

Items the production-hardening passes did NOT close. Each item lists a severity and a rough effort estimate. Items marked `Pass-2: closed` were carried over from the first pass and resolved in v4.2.4 (2026-04-21); their entries are kept for context.

## Status legend

- `Open` -- still outstanding.
- `Pass-2: closed` -- resolved in 2026-04-21 second pass; see remediation-summary.md "Second pass" section.

## Deferred with rationale

### 1. Full pre-checkout engine unification  (#5)

**Status:** Open (partially reduced)
**Severity:** P3 after pass 2
**Effort:** Medium (1-2 days, careful benchmarking)

The inline provider orchestration in `hooks.php:ShoppingCartValidateCheckout` duplicates logic from `FpsCheckRunner::runPreCheckout()`. Pass 1 unified threshold resolution and stats recording. Pass 2 unified persistence (structured columns) and pulled threshold resolution into `FpsHookHelpers::fps_resolvePreCheckoutThresholds()`. The remaining gap is the provider list itself.

**Why deferred:** The checkout hook runs in a user-facing path with a <2s budget. `FpsCheckRunner::runPreCheckout()` loads more dependencies and could regress latency. A full unification requires:

1. Benchmarking `runPreCheckout()` latency with a production-like provider set
2. Splitting `runPreCheckout()` into a fast-path mode that mimics the current hook's "fast providers only" selection
3. Measuring no regression on P95 checkout latency

**To complete:**

- [ ] Add latency instrumentation to `FpsCheckRunner::runPreCheckout()`
- [ ] Add a `FpsCheckRunner::runPreCheckoutFast()` method that only loads the fast-path providers
- [ ] Replace the inline hook logic with a call to `runPreCheckoutFast()`
- [ ] Ship behind a feature flag for easy rollback
- [ ] Monitor P95 for one week before removing the inline fallback

---

### 2. Structured column migration  (#7)

**Status:** Pass-2: writers closed; readers still open
**Severity:** P3
**Effort:** Low (half-day) but high upgrade risk

Pass 2 closed the writer side: every insert path (`FpsCheckRunner::fps_persistCheck`, inline pre-checkout in hooks, Turnstile-block path) now populates `provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at` in addition to the legacy `details` blob.

**Still open:** reader migration. The Client Profile tab, Statistics tab, webhook notifier, and export still parse the legacy `details` JSON. Migrating them to read structured columns first (with JSON fallback) is mechanical but spans ~15 files.

**To complete:**

- [ ] Inventory every reader of `mod_fps_checks.details`
- [ ] Migrate each reader to prefer structured columns with JSON fallback
- [ ] After N days, stop writing the legacy `details` column
- [ ] After N+30 days, drop the `details` column

---

### 3. Geo-impossibility engine activation  (#9)

**Status:** Pass-2: comments + guards added; flag still open
**Severity:** P3
**Effort:** Low (half-day)

`FpsGeoImpossibilityEngine` detects location jumps based on historical IP/country data per client. The engine works correctly when at least 2 independent geo signals are available for a check.

Pass 2 added explicit docblocks describing the data preconditions and ensured `analyze()` returns a clean `success=true` no-op when fewer than 2 signals exist. `FpsCheckRunner::fps_runGeoMismatchCheck()` likewise documents that it returns `success=false, score=0` when the IP-country cache is empty -- the aggregator already excludes such results.

**Still open:** an admin-visible "geo_impossibility_requires_history" toggle so operators can disable the engine entirely on installs that won't accumulate the necessary history (e.g. very-low-volume installs).

**To complete:**

- [ ] Add a `geo_impossibility_requires_history` config flag (default true)
- [ ] Skip the engine when flag=true and the client has <2 prior geo-located checks
- [ ] Document in the UI tooltip that this engine needs history to activate

---

### 4. Large-file maintainability  (#18)

**Severity:** P3
**Effort:** High (3-5 days of careful extraction)

`fraud_prevention_suite.php` is ~4,851 lines and `hooks.php` is ~1,820 lines.

**Why deferred:** YAGNI. There's no measurable pain from the file size today. Splitting into helpers creates its own risks (namespace collisions, missed include paths, broken IDE navigation) that aren't justified by the current pain level.

**To complete (if pain emerges):**

- [ ] Extract `fps_ajax*` functions into a new `lib/Ajax/` directory structure with PSR-4 autoload
- [ ] Extract `fps_create*` helpers into `lib/Install/`
- [ ] Extract `fps_gdpr*` into `lib/Gdpr/`
- [ ] Keep the main module file to public metadata / entry points only

---

### 5. Dead code / drift markers  (#20)

**Severity:** P3
**Effort:** Low-Medium (1-2 days of scanning)

The module has accumulated some stale comments, unused variables, and comments that describe intent rather than current behavior.

**Why deferred:** This is ongoing code hygiene, not a discrete project. Best tackled incrementally as we touch each file.

**To complete:**

- [ ] Run `phpstan --level=5` on the module and fix findings
- [ ] Run `psalm --no-progress` and fix findings
- [ ] Diff comments against code monthly and keep them aligned

---

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

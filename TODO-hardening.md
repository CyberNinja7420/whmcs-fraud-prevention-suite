# FPS Hardening TODO

Items from the production-hardening audit that were **not** addressed in the v4.2.3 remediation session, with explanations. Each has a severity and a rough effort estimate.

## Deferred with rationale

### 1. Full pre-checkout engine unification  (#5)

**Severity:** P1 → P2 after Batch 4/5 partial unification
**Effort:** Medium (1-2 days, careful benchmarking)

The inline provider orchestration in `hooks.php:ShoppingCartValidateCheckout` duplicates logic from `FpsCheckRunner::runPreCheckout()`. Batches 4 and 5 unified threshold resolution and stats recording so the two paths stay consistent on those dimensions, but the provider loop itself (velocity check, Tor/DC, rule engine, etc.) is still duplicated.

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

**Severity:** P2
**Effort:** Low (half-day) but high upgrade risk

`mod_fps_checks` has structured columns (`provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`) that are populated in some writer paths but not others. Legacy code writes everything into `details` JSON.

**Why deferred:** Migrating all writers is trivial. Migrating all readers (Client Profile tab, Statistics tab, webhook notifier, export, etc.) is a systematic sweep across ~15 files and introduces regression risk disproportionate to the benefit.

**To complete:**

- [ ] Inventory every reader of `mod_fps_checks.details`
- [ ] Migrate each reader to prefer structured columns with JSON fallback
- [ ] After N days, stop writing the legacy `details` column
- [ ] After N+30 days, drop the `details` column

---

### 3. Geo-impossibility engine activation  (#9)

**Severity:** P2
**Effort:** Low (half-day)

`FpsGeoImpossibilityEngine` detects location jumps based on historical IP/country data per client. The engine works correctly when `mod_fps_checks` has at least 2 prior rows for a given client with geolocation populated - but most installs won't have that data for most clients.

**Why deferred:** Not a regression; the engine just scores 0 when history is absent. Removing it would be a feature removal. Better to document the preconditions and gate it behind a "requires_history" config check.

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

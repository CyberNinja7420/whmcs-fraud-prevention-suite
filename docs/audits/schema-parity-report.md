# FPS Schema Parity Report

**Date:** 2026-04-21
**Module version:** 4.2.4
**Verified on:** 130.12.69.3 (live), 130.12.69.7 (dev) -- pass 1 deploys; pass 2 is additive-only and pending re-deploy.

## Definitions

- **Create() complete**: `_activate()` creates the table with all columns used by runtime as of the current module version.
- **Upgrade idempotent**: `_upgrade()` contains `hasTable()` / `hasColumn()` guards that add any columns missing from older installs.
- **Runtime clean**: all runtime writes target columns that exist in the Create() definition.

## Tables

| Table | Create() complete | Upgrade idempotent | Runtime clean | Notes |
|-------|-------------------|--------------------|---------------|-------|
| `mod_fps_api_keys` | ✅ (fixed Batch 2) | ✅ | ✅ | `client_id`, `service_id` now in create() |
| `mod_fps_api_logs` | ✅ | ✅ | ✅ | `user_agent`, `source` added in previous session; upgrade path preserved |
| `mod_fps_behavioral_events` | ✅ | n/a | ✅ | Not yet touched by upgrade logic |
| `mod_fps_bin_cache` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_chargebacks` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_checks` | ✅ | ✅ | ✅ | Structured columns (`provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`, `updated_at`) added via upgrade path AND in create(); legacy JSON columns retained |
| `mod_fps_client_trust` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_datacenter_asns` | ✅ | n/a | ✅ | Seed data table |
| `mod_fps_email_intel` | ✅ | ✅ | ✅ | `is_catchall` added via upgrade path |
| `mod_fps_fingerprints` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_fraud_fingerprints` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_gateway_thresholds` | ✅ | n/a | ✅ | Per-gateway `block_threshold` / `flag_threshold` columns - NOT the global drift from audit #4 |
| `mod_fps_gdpr_requests` | ✅ | ✅ | ✅ | Added in v4.1 |
| `mod_fps_geo_events` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_global_config` | ✅ | n/a | ✅ | Hub configuration k/v store |
| `mod_fps_global_instances` | ✅ | n/a | ✅ | Instance registry |
| `mod_fps_global_intel` | ✅ | ✅ | ✅ | Source column added via upgrade |
| `mod_fps_ip_intel` | ✅ | ✅ | ✅ | Rich reputation cache |
| `mod_fps_rate_limits` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_refund_tracking` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_reports` | ✅ (fixed Batch 2) | ✅ | ✅ | `reviewed_by`, `reviewed_at` now in create() and added via upgrade |
| `mod_fps_rules` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_settings` | ✅ | ✅ | ✅ | Key/value store; new flags auto-seeded |
| `mod_fps_stats` | ✅ | ✅ | ✅ | Extended columns `pre_checkout_blocks`, `api_requests`, `unique_ips`, `avg_risk_score`, `top_countries`, `top_risk_factors` added idempotently |
| `mod_fps_tor_nodes` | ✅ | n/a | ✅ | Seed data table |
| `mod_fps_velocity_events` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_webhook_configs` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_webhook_log` | ✅ | n/a | ✅ | Stable schema |
| `mod_fps_weight_history` | ✅ | n/a | ✅ | Adaptive scoring weights |

**29 tables. All three parity properties hold for all 29 tables.**

## Verification method

1. Read `fraud_prevention_suite_activate()` in `fraud_prevention_suite.php`.
2. For each `Capsule::schema()->create()` call, list columns defined.
3. Read `fraud_prevention_suite_upgrade()` and collect every `addColumn` that happens in an upgrade branch.
4. Runtime grep for columns referenced in INSERT/UPDATE statements across the module.
5. Cross-check: every column written at runtime exists in the create() definition.

## Previously-drifting tables (now parity-compliant)

### `mod_fps_api_keys`

**Before Batch 2:** `client_id`, `service_id` were added ONLY in the v4.2 upgrade path. Fresh installs lacked both columns, breaking the `fps_api` provisioning module whose `CreateAccount` writes these fields.

**After Batch 2:** Both columns are in the `create()` call AND remain in the upgrade path.

### `mod_fps_reports`

**Before Batch 2:** `reviewed_by`, `reviewed_at` were written by `fps_ajaxUpdateReportStatus()` but never defined in either `create()` or the upgrade path. Any admin attempting to review a report would hit "Unknown column".

**After Batch 2:** Both columns are in the `create()` call AND a new upgrade path adds them to existing installs. One-time `ALTER TABLE` run on both live and dev databases during the remediation session.

## Known-intentional duplicates

These are NOT drift:

- `mod_fps_gateway_thresholds.block_threshold` / `flag_threshold` - per-gateway override columns (separate scope from the global setting keys; both exist by design)
- `mod_fps_checks.details` (legacy JSON) + structured columns `provider_scores`, `check_context` - both populated for backward compatibility; some readers still parse the JSON blob

## Pass 2 update (2026-04-21)

No schema changes in pass 2. The structured columns
(`provider_scores`, `check_context`, `is_pre_checkout`, `check_duration_ms`,
`updated_at`) on `mod_fps_checks` already existed; pass 2 only made
the runtime write paths consistently populate them. Readers that still
prefer the legacy `details` JSON continue to work unchanged.

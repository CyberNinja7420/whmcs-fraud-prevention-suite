# Database Schema Reference

## Overview

FPS creates 36 database tables with the `mod_fps_` prefix. All tables are created idempotently during module activation.

## Core Check Data (4 tables)

### mod_fps_checks

Main fraud check record. Created for every order, registration, or manual scan.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| order_id | INT INDEX | WHMCS order ID (0 for registrations) |
| client_id | INT INDEX | WHMCS client ID |
| check_type | VARCHAR(50) | auto, pre_checkout, registration, manual |
| risk_score | DECIMAL(5,2) | 0-100 normalized score |
| risk_level | VARCHAR(20) | low, medium, high, critical |
| ip_address | VARCHAR(45) | IPv4 or IPv6 |
| email | VARCHAR(255) | Email address |
| phone | VARCHAR(50) | Phone number (formatted) |
| country | VARCHAR(5) | 2-letter country code |
| fraudrecord_id | VARCHAR(100) | FraudRecord external ID |
| raw_response | TEXT | Raw API responses (JSON) |
| details | TEXT | Detailed findings (JSON array) |
| action_taken | VARCHAR(50) | blocked, allowed, locked |
| locked | TINYINT | 1 = held for review, 0 = not locked |
| reported | TINYINT | 1 = reported to FraudRecord |
| reviewed_by | INT | Admin user ID who reviewed |
| reviewed_at | TIMESTAMP | When admin reviewed |
| created_at | TIMESTAMP | Check creation time (indexed) |
| fingerprint_id | INT | Link to mod_fps_fingerprints |
| ip_intel_id | INT | Link to mod_fps_ip_intel |
| email_intel_id | INT | Link to mod_fps_email_intel |
| provider_scores | TEXT | JSON: {provider_name: score, ...} |
| check_context | TEXT | Archived FpsCheckContext (JSON) |
| is_pre_checkout | TINYINT | 1 = pre-checkout check |
| check_duration_ms | INT | Execution time in milliseconds |
| updated_at | TIMESTAMP | Last update timestamp |

### mod_fps_reports

Fraud reports submitted to external services (FraudRecord, etc.).

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| check_id | INT INDEX | Link to mod_fps_checks |
| client_id | INT INDEX | WHMCS client |
| order_id | INT | WHMCS order (nullable) |
| report_type | VARCHAR(50) | internal, fraudrecord, manual |
| report_data | TEXT | JSON submission data |
| submitted_at | TIMESTAMP | Report creation time |
| response | TEXT | Service response (JSON) |
| status | VARCHAR(30) | pending, accepted, rejected, closed |

### mod_fps_stats

Daily aggregated statistics for dashboard.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| date | DATE UNIQUE | Day being recorded |
| checks_total | INT | Total checks run |
| checks_flagged | INT | Medium risk+ |
| checks_blocked | INT | Critical risk |
| orders_locked | INT | Orders set to Fraud status |
| reports_submitted | INT | Reports to FraudRecord |
| false_positives | INT | Admin-marked errors |
| pre_checkout_blocks | INT | Pre-checkout rejections |
| api_requests | INT | Public API calls |
| unique_ips | INT | Distinct IP addresses |
| avg_risk_score | DECIMAL(5,2) | Average risk score |
| top_countries | TEXT | JSON {CC: count, ...} |
| top_risk_factors | TEXT | JSON [factor, ...] |

### mod_fps_rules

Custom admin-defined fraud rules.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| rule_name | VARCHAR(255) | User-friendly name |
| rule_type | VARCHAR(50) | ip_block, email_pattern, country_block, velocity |
| rule_value | TEXT | Regex, IP range, country code, or threshold |
| action | VARCHAR(50) | flag, block, hold, allow |
| enabled | TINYINT | 1 = active, 0 = disabled |
| hits | INT | Times rule matched |
| created_at | TIMESTAMP | Rule creation |
| priority | INT | Lower = checked first (default 50) |
| score_weight | DECIMAL(5,2) | Multiplier if matched (default 1.0) |
| description | TEXT | Rule documentation |
| conditions | TEXT | Additional conditions (JSON) |
| expires_at | TIMESTAMP | Rule auto-disable date (nullable) |
| created_by | INT | Admin user ID |
| updated_at | TIMESTAMP | Last modification |

## Intelligence Caches (6 tables)

### mod_fps_ip_intel

Cached IP geolocation and threat data. TTL: 24 hours (configurable).

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| ip_address | VARCHAR(45) UNIQUE | IPv4 or IPv6 |
| asn | INT | Autonomous System Number |
| asn_org | VARCHAR(255) | ASN organization name |
| isp | VARCHAR(255) | Internet Service Provider |
| country_code | VARCHAR(5) INDEX | 2-letter code |
| region | VARCHAR(255) | State/province |
| city | VARCHAR(255) | City name |
| latitude | DECIMAL(10,7) | Latitude coordinate |
| longitude | DECIMAL(10,7) | Longitude coordinate |
| is_proxy | TINYINT | 1 = proxy detected |
| is_vpn | TINYINT | 1 = VPN detected |
| is_tor | TINYINT | 1 = Tor exit node |
| is_datacenter | TINYINT | 1 = datacenter/hosting |
| proxy_type | VARCHAR(50) | residential, commercial, edu, gov, etc. |
| threat_score | DECIMAL(5,2) | 0-100 threat rating |
| raw_data | TEXT | Full API response (JSON) |
| cached_at | TIMESTAMP | Cache insertion time |
| expires_at | TIMESTAMP | Cache expiration (nullable) |

### mod_fps_email_intel

Cached email validation and domain data. TTL: 24 hours.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| email | VARCHAR(255) UNIQUE | Full email address |
| email_hash | VARCHAR(64) INDEX | SHA-256 hash |
| domain | VARCHAR(255) INDEX | Domain portion |
| mx_valid | TINYINT | 1 = MX records exist |
| smtp_valid | TINYINT | 1 = SMTP response OK (nullable) |
| is_catchall | TINYINT | 1 = domain accepts all emails |
| is_disposable | TINYINT | 1 = temporary/disposable |
| is_role_account | TINYINT | 1 = info@, admin@, etc. |
| is_free_provider | TINYINT | 1 = Gmail, Yahoo, Hotmail |
| domain_age_days | INT | Days since domain registration |
| breach_count | INT | HIBP breach appearances |
| has_social_presence | TINYINT | 1 = LinkedIn/Twitter found |
| social_signals | TEXT | JSON [platform, ...] |
| deliverability_score | DECIMAL(5,2) | 0-100 deliverability |
| raw_data | TEXT | Full API response (JSON) |
| cached_at | TIMESTAMP | Cache insertion |
| expires_at | TIMESTAMP | Cache expiration |

### mod_fps_fingerprints

Device fingerprint records for browser/device identification.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| client_id | INT INDEX | WHMCS client (nullable) |
| fingerprint_hash | VARCHAR(64) UNIQUE | SHA-256 combined hash |
| user_agent | TEXT | Browser user agent string |
| canvas_hash | VARCHAR(64) | Canvas fingerprinting hash |
| webgl_hash | VARCHAR(64) | WebGL renderer hash |
| screen_resolution | VARCHAR(30) | Format: 1920x1080x24 |
| timezone | VARCHAR(100) | Timezone string (e.g., America/New_York) |
| timezone_offset | INT | UTC offset in minutes |
| hardware_concurrency | INT | CPU core count |
| device_memory | INT | RAM in GB |
| webrtc_local_ips | TEXT | JSON [IP, ...] (private IPs) |
| webrtc_mismatch | TINYINT | 1 = IP mismatch detected |
| raw_data | TEXT | Full fingerprint data (JSON) |
| first_seen_at | TIMESTAMP | First appearance |
| last_seen_at | TIMESTAMP | Most recent use |
| times_seen | INT | Total uses |

### mod_fps_bin_cache

Credit card BIN (Bank Identification Number) lookup cache.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| bin | VARCHAR(8) UNIQUE | First 8 digits of card |
| bin_data | TEXT | Issuer info (JSON) |
| created_at | TIMESTAMP | Cache insertion |

### mod_fps_fraud_fingerprints

Flagged fingerprints (known fraud).

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| fingerprint_hash | VARCHAR(64) UNIQUE | Device fingerprint |
| flagged_by | INT | Admin user ID |
| reason | TEXT | Why flagged (e.g., "Bot account") |
| created_at | TIMESTAMP | Flag creation |

## Event Tracking (3 tables)

### mod_fps_velocity_events

Rate-limiting event log. TTL: 7 days (configurable).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| event_type | VARCHAR(30) INDEX | checkout_attempt, order_placed, signup, refund |
| identifier | VARCHAR(255) INDEX | IP, email, or client_id |
| client_id | INT INDEX DEFAULT 0 | WHMCS client (0 if not logged in) |
| ip_address | VARCHAR(45) | IP address |
| meta | TEXT | JSON metadata (gateway, amount, etc.) |
| created_at | TIMESTAMP INDEX | Event creation (used for TTL) |

### mod_fps_behavioral_events

Mouse/keyboard biometric data. TTL: 30 days.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| client_id | INT INDEX | WHMCS client |
| session_id | VARCHAR(64) INDEX | Session identifier |
| form_fill_time_ms | INT | Milliseconds to complete form |
| mouse_entropy | DECIMAL(5,3) | Randomness of mouse movement (0-1) |
| paste_detected | TINYINT | 1 = copy-paste used |
| behavioral_score | DECIMAL(5,2) | 0-100 suspicion score |
| raw_data | TEXT | Full biometric data (JSON) |
| created_at | TIMESTAMP | Event timestamp |

### mod_fps_geo_events

Geographic event stream for topology visualization.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| event_type | VARCHAR(30) INDEX | order_placed, high_risk, blocked, etc. |
| country_code | VARCHAR(2) INDEX | 2-letter country code |
| latitude | DECIMAL(10,7) | Anonymized latitude (null if unavailable) |
| longitude | DECIMAL(10,7) | Anonymized longitude (null if unavailable) |
| risk_level | VARCHAR(20) | low, medium, high, critical |
| risk_score | DECIMAL(5,2) | Associated score |
| is_anonymized | TINYINT | 1 = IP removed (default 1) |
| created_at | TIMESTAMP | Event timestamp |

## Lists (2 tables)

### mod_fps_tor_nodes

Tor exit node IP list. Updated daily via cron.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| ip_address | VARCHAR(45) UNIQUE | Tor exit IP |
| last_seen_at | TIMESTAMP | Last confirmation |

### mod_fps_datacenter_asns

Known datacenter and hosting provider ASNs.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| asn | INT UNIQUE | Autonomous System Number |
| description | VARCHAR(255) | Provider name (e.g., "AWS EC2") |
| category | VARCHAR(50) | aws, azure, gcp, linode, vultr, etc. |
| last_updated_at | TIMESTAMP | List refresh date |

## Trust Management (1 table)

### mod_fps_client_trust

Per-client allowlist/blacklist status.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| client_id | INT UNIQUE | WHMCS client |
| status | VARCHAR(20) | normal, whitelist, blacklist |
| reason | TEXT | Admin notes (e.g., "VIP customer") |
| set_by_admin_id | INT | Admin who set status |
| created_at | TIMESTAMP | Status creation |
| updated_at | TIMESTAMP | Last modification |

## Financial Tracking (2 tables)

### mod_fps_chargebacks

Chargeback/dispute history with original fraud scores.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| client_id | INT INDEX | WHMCS client |
| order_id | INT INDEX | WHMCS order |
| invoice_id | INT | WHMCS invoice (nullable) |
| amount | DECIMAL(10,2) | Chargeback amount |
| gateway | VARCHAR(100) | Payment gateway (Stripe, PayPal, etc.) |
| fraud_score_at_order | DECIMAL(5,2) | Original FPS score |
| risk_level_at_order | VARCHAR(20) | Original risk level |
| provider_scores_at_order | TEXT | Original provider breakdown (JSON) |
| reason | VARCHAR(255) | Chargeback reason (nullable) |
| chargeback_date | TIMESTAMP | When reported |
| created_at | TIMESTAMP | Record creation |

### mod_fps_refund_tracking

Refund history for abuse pattern detection.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| client_id | INT INDEX | WHMCS client |
| invoice_id | INT | WHMCS invoice (nullable) |
| amount | DECIMAL(10,2) | Refund amount |
| reason | VARCHAR(255) | Refund reason |
| refund_date | TIMESTAMP | When refunded |
| created_at | TIMESTAMP | Record creation |

### mod_fps_gateway_thresholds

Per-payment-gateway risk thresholds (optional overrides).

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| gateway | VARCHAR(100) UNIQUE | WHMCS gateway name |
| block_threshold | INT | Override block score (0 = use default) |
| flag_threshold | INT | Override flag score (0 = use default) |
| require_captcha | TINYINT | Force Turnstile on this gateway |
| enabled | TINYINT | 1 = apply overrides |
| updated_at | TIMESTAMP | Last edit |

## API System (3 tables)

### mod_fps_api_keys

Public REST API keys with tier and rate limits.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| key_hash | VARCHAR(64) UNIQUE | SHA-256 hash of key |
| key_prefix | VARCHAR(8) | First 8 chars of key (display) |
| name | VARCHAR(255) | Human name (e.g., "Integration A") |
| tier | VARCHAR(20) | free, basic, premium |
| owner_email | VARCHAR(255) | Key owner email (nullable) |
| rate_limit_per_minute | INT | Requests per minute |
| rate_limit_per_day | INT | Requests per day |
| allowed_endpoints | TEXT | JSON [endpoint, ...] or null (all) |
| ip_whitelist | TEXT | JSON [IP, ...] or null (any) |
| is_active | TINYINT | 1 = valid, 0 = revoked |
| created_at | TIMESTAMP | Key creation |
| expires_at | TIMESTAMP | Auto-expiration date (nullable) |
| last_used_at | TIMESTAMP | Most recent request |
| total_requests | BIGINT | Lifetime API calls |

### mod_fps_api_logs

API request audit trail. TTL: 30 days.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| api_key_id | INT INDEX | Link to mod_fps_api_keys (nullable for anonymous) |
| endpoint | VARCHAR(100) INDEX | /v1/lookup/ip-basic, etc. |
| method | VARCHAR(10) | GET, POST |
| ip_address | VARCHAR(45) | Requester IP |
| request_params | TEXT | Query parameters (JSON) |
| response_code | INT | HTTP 200, 429, 503, etc. |
| response_time_ms | INT | Execution time |
| created_at | TIMESTAMP | Request timestamp |

### mod_fps_rate_limits

Token bucket state per API key.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| identifier | VARCHAR(128) UNIQUE | key:X or ip:1.2.3.4 |
| tokens | DECIMAL(10,2) | Available tokens (bucket) |
| last_refill_at | TIMESTAMP | When bucket was refilled |
| window_requests | INT | Requests in current minute |
| window_start_at | TIMESTAMP | Minute window start |

## Webhooks (2 tables)

### mod_fps_webhook_configs

Slack/Discord/Teams webhook destinations.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| name | VARCHAR(100) | Display name |
| type | VARCHAR(20) | slack, discord, teams, generic |
| url | TEXT | Webhook URL |
| secret | VARCHAR(255) | Signing secret (nullable) |
| events | TEXT | JSON [event_type, ...] to trigger on |
| enabled | TINYINT | 1 = active |
| created_at | TIMESTAMP | Creation |
| updated_at | TIMESTAMP | Last edit |

### mod_fps_webhook_log

Webhook delivery history. TTL: 30 days.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| webhook_id | INT INDEX | Link to mod_fps_webhook_configs |
| event_type | VARCHAR(50) | high_risk_order, critical_alert, etc. |
| success | TINYINT | 1 = delivered, 0 = failed |
| http_code | INT | HTTP response status |
| response_body | TEXT | Webhook server response |
| created_at | TIMESTAMP | Delivery attempt |

## ML & Optimization (1 table)

### mod_fps_weight_history

Adaptive scoring weight adjustments (monthly analysis).

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| provider | VARCHAR(50) | Provider name (ip_intel, email_validation, etc.) |
| old_weight | DECIMAL(5,2) | Previous weight |
| new_weight | DECIMAL(5,2) | Optimized weight |
| precision_score | DECIMAL(5,4) | F1 score (0-1) |
| true_positive_count | INT | Correctly identified fraud |
| false_positive_count | INT | Incorrectly flagged |
| sample_size | INT | Orders analyzed |
| period | VARCHAR(7) | YYYY-MM period |
| created_at | TIMESTAMP | Analysis date |

## Global Intelligence (2 tables)

### mod_fps_global_intel

Harvested fraud signals (email_hash, IP, country, score). Cross-instance shared.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| email_hash | VARCHAR(64) INDEX | SHA-256 (irreversible) |
| ip_address | VARCHAR(45) INDEX | IPv4/IPv6 (nullable, may be stripped for privacy) |
| country | CHAR(2) | 2-letter country code |
| risk_score | DECIMAL(5,2) | 0-100 score |
| risk_level | VARCHAR(20) INDEX | low, medium, high, critical |
| source | VARCHAR(20) | local, hub, manual |
| evidence_flags | TEXT | JSON {disposable: true, proxy: false, ...} |
| seen_count | INT UNSIGNED | Times encountered |
| first_seen_at | TIMESTAMP | First appearance |
| last_seen_at | TIMESTAMP | Most recent |
| expires_at | TIMESTAMP INDEX | Auto-purge date |
| pushed_to_hub | TINYINT INDEX | 1 = sent to central hub |
| pushed_at | TIMESTAMP | Push timestamp |
| UNIQUE (email_hash, ip_address) | | Composite key |

### mod_fps_global_config

Hub registration and sync settings.

| Column | Type | Notes |
|--------|------|-------|
| setting_key | VARCHAR(100) PRIMARY KEY | Config key |
| setting_value | TEXT | Config value |

**Keys**:
- `global_sharing_enabled`: 0/1
- `hub_url`: Hub endpoint
- `instance_id`: UUID
- `instance_api_key`: Auth token
- `instance_domain`: WHMCS domain
- `share_ip_addresses`: 0/1
- `auto_push_enabled`: 0/1
- `auto_pull_on_signup`: 0/1
- `intel_retention_days`: TTL
- `last_push_at`: Timestamp
- `last_pull_at`: Timestamp
- `data_consent_accepted`: 0/1

## Settings (1 table)

### mod_fps_settings

All module configuration (API keys, toggles, thresholds).

| Column | Type | Notes |
|--------|------|-------|
| setting_key | VARCHAR(100) PRIMARY KEY | Config key (no auto-increment) |
| setting_value | TEXT | Value (stored as string) |

**Keys** (examples; full list in `fraud_prevention_suite.php::activate()`):
- `module_version`: Current version
- `api_keys/*`: API credentials
- `*_enabled`: Feature toggles
- `*_threshold`: Risk thresholds
- `*_weight`: Provider weights
- `cache_ttl_*`: Cache expiration (seconds)
- `notification_email`: Alert recipient
- etc.

## GDPR Compliance (1 table)

### mod_fps_gdpr_requests

Data removal/portability requests.

| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT | Primary key |
| email | VARCHAR(255) | Subject email |
| email_hash | VARCHAR(64) INDEX | SHA-256 for matching |
| name | VARCHAR(255) | Subject name (nullable) |
| reason | TEXT | Request reason |
| verification_token | VARCHAR(64) | Email verification link |
| email_verified | TINYINT | 1 = verified |
| status | VARCHAR(20) | pending, verified, approved, denied, completed |
| reviewed_by | INT | Admin who approved |
| reviewed_at | TIMESTAMP | Review date |
| admin_notes | TEXT | Admin comments |
| records_purged | INT | Count of deleted records |
| ip_address | VARCHAR(45) | Requester IP |
| created_at | TIMESTAMP | Request submission |
| updated_at | TIMESTAMP | Last change |

## Index Summary

Critical indexes for performance:

- `mod_fps_checks` (order_id, client_id, created_at)
- `mod_fps_ip_intel` (ip_address)
- `mod_fps_email_intel` (email, email_hash, domain)
- `mod_fps_fingerprints` (fingerprint_hash)
- `mod_fps_velocity_events` (event_type, identifier, created_at)
- `mod_fps_global_intel` (email_hash, ip_address, pushed_to_hub)
- `mod_fps_api_logs` (api_key_id, endpoint, created_at)

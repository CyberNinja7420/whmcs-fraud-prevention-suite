# AJAX Reference

## Overview

FPS admin dashboard uses 70+ AJAX actions to load data, save settings, execute bulk operations, and stream progress. All actions are POST requests to `addonmodules.php?module=fraud_prevention_suite&ajax=1&a=action_name`.

## Request Format

```bash
POST /admin/addonmodules.php?module=fraud_prevention_suite&ajax=1&a=action_name
Content-Type: application/x-www-form-urlencoded

param1=value1&param2=value2
```

Returns: JSON response

## Response Format

All AJAX endpoints return JSON (success or error):

```json
{
  "success": true,
  "data": { ... },
  "error": null,
  "message": "Operation completed"
}
```

On error:
```json
{
  "success": false,
  "error": "Error message",
  "data": null
}
```

---

## Dashboard Tab Actions

### 1. get_dashboard_stats

**GET/POST**: POST
**Parameters**: None
**Returns**: Daily stats, KPI cards, recent checks

```json
{
  "success": true,
  "data": {
    "total_checks": 1234,
    "blocked_today": 15,
    "avg_risk_score": 28.5,
    "high_risk_count": 8,
    "critical_count": 2,
    "unique_ips": 456,
    "top_countries": ["RU", "CN", "US"]
  }
}
```

### 2. get_recent_checks

**Parameters**: None
**Returns**: Last 10 checks (for dashboard list)

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "order_id": 100,
      "client_id": 50,
      "risk_score": 85.5,
      "risk_level": "critical",
      "email": "test@example.com",
      "created_at": "2026-04-01 10:30:00"
    }
  ]
}
```

### 3. get_chart_data

**Parameters**: `period` (7, 30, 90)
**Returns**: Time-series data for ApexCharts

```json
{
  "success": true,
  "data": {
    "dates": ["2026-03-01", "2026-03-02", ...],
    "checks": [10, 15, 8, ...],
    "blocked": [1, 2, 0, ...],
    "avg_score": [25.3, 28.1, 22.5, ...]
  }
}
```

---

## Review Queue Actions

### 4. get_review_queue

**Parameters**: `page` (default 1), `per_page` (default 20)
**Returns**: Locked orders awaiting admin review

```json
{
  "success": true,
  "data": {
    "total": 42,
    "page": 1,
    "items": [
      {
        "id": 1,
        "order_id": 100,
        "client_id": 50,
        "risk_score": 92,
        "risk_level": "critical",
        "provider_scores": { "ip_intel": 0.8, ... },
        "locked_at": "2026-04-01 10:15:00"
      }
    ]
  }
}
```

### 5. approve_check

**Parameters**: `check_id`
**Returns**: Success/error

Unlocks order, sets `reviewed_by`, `reviewed_at`.

### 6. deny_check

**Parameters**: `check_id`
**Returns**: Success/error

Locks order permanently, sets manual denial flag.

---

## Client Profile Actions

### 7. get_client_profile

**Parameters**: `client_id`
**Returns**: Deep client analysis (15-dimension duplicate detection)

```json
{
  "success": true,
  "data": {
    "client": {
      "id": 50,
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe"
    },
    "financials": {
      "total_paid": 1500.00,
      "unpaid": 200.00,
      "refunded": 0.00,
      "chargebacks": 0
    },
    "risk": {
      "avg_score": 25.3,
      "highest_score": 85,
      "check_count": 5
    },
    "duplicates": {
      "exact_ip_match": [client_ids],
      "email_domain_siblings": [client_ids],
      "phone_match": [client_ids],
      "address_proximity": [client_ids],
      ...15 total dimensions
    },
    "timeline": [
      {
        "date": "2026-03-15",
        "event": "Order placed",
        "risk_score": 25,
        "ip": "1.2.3.4"
      }
    ]
  }
}
```

### 8. get_client_timeline

**Parameters**: `client_id`
**Returns**: Chronological fraud check timeline

Includes: orders, signups, chargebacks, refunds, risk scores.

---

## Manual Checks

### 9. run_manual_check

**Parameters**: `client_id` (optional), `order_id` (optional), `email` (optional), `ip` (optional)
**Returns**: Fraud check result (full pipeline)

```json
{
  "success": true,
  "data": {
    "risk_score": 42.5,
    "risk_level": "medium",
    "provider_scores": {
      "ip_intel": 0.3,
      "email_validation": 0.2,
      "abuseipdb": 0.5,
      ...
    },
    "details": [
      "Email from free provider",
      "IP in US (matching country)",
      "No proxy detected"
    ],
    "duration_ms": 245
  }
}
```

### 10. rescan_client

**Parameters**: `client_id`
**Returns**: Rescans all orders for client; returns aggregated result

---

## Mass Scan Actions

### 11. start_mass_scan

**Parameters**: `status_filter` (optional: all, active, inactive, closed), `limit` (default 100)
**Returns**: Scan job ID and initial count

```json
{
  "success": true,
  "data": {
    "scan_id": "fps_abc123",
    "total_clients": 450,
    "estimated_time_seconds": 120
  }
}
```

**Client should then poll**:
- `get_mass_scan_progress?scan_id=fps_abc123` (every 2 seconds)

### 12. get_mass_scan_progress

**Parameters**: `scan_id`
**Returns**: Live progress

```json
{
  "success": true,
  "data": {
    "completed": 120,
    "total": 450,
    "percent": 26.7,
    "time_elapsed_seconds": 45,
    "time_remaining_seconds": 125,
    "current_client": "user@example.com"
  }
}
```

---

## Bot Cleanup Actions

### 13. detect_bots

**Parameters**: `status_filter` (optional)
**Returns**: List of suspected bot accounts

```json
{
  "success": true,
  "data": {
    "total": 12,
    "bots": [
      {
        "id": 50,
        "email": "bot@tempmail.com",
        "paid_invoices": 0,
        "active_services": 0,
        "created_at": "2026-03-01"
      }
    ]
  }
}
```

### 14. preview_bot_action

**Parameters**: `action` (flag, deactivate, purge, deep_purge), `status_filter` (optional)
**Returns**: Dry-run preview (no changes)

```json
{
  "success": true,
  "data": {
    "action": "purge",
    "affected_count": 5,
    "summary": [
      "Will delete client 50 (3 orders, 5 invoices)",
      "Will delete client 51 (0 orders, 2 unpaid invoices)"
    ]
  }
}
```

### 15. flag_bots

**Parameters**: `status_filter` (optional)
**Returns**: Adds [FPS-BOT] notes

Success: `{affected: 5}`

### 16. deactivate_bots

**Parameters**: `status_filter` (optional)
**Returns**: Sets client status to Inactive

Success: `{affected: 5}`

### 17. purge_bots

**Parameters**: `status_filter` (optional), `dry_run` (optional: 1/0)
**Returns**: Deletes bot clients (one-way)

```json
{
  "success": true,
  "data": {
    "purged": 5,
    "intel_harvested": 5
  }
}
```

**Dry run mode**: Returns what would happen without changes

### 18. deep_purge_bots

**Parameters**: `status_filter` (optional), `dry_run` (optional)
**Returns**: Deletes accounts with all fraud/cancelled/unpaid records

---

## User Cleanup Actions

### 19. detect_orphan_users

**Parameters**: None
**Returns**: Orphan login accounts (no real client associations)

```json
{
  "success": true,
  "data": {
    "total_users": 450,
    "total": 12,
    "users": [
      {
        "id": 100,
        "email": "orphan@example.com",
        "client_count": 0
      }
    ]
  }
}
```

### 20. purge_orphan_users

**Parameters**: `ids` (CSV: 100,101,102)
**Returns**: Deletes orphan user accounts

```json
{
  "success": true,
  "data": {
    "purged": 3
  }
}
```

---

## Rules Management

### 21. save_rule

**Parameters**: `rule_id` (0 = new), `rule_name`, `rule_type` (ip_block, email_pattern, country_block, velocity), `rule_value`, `action`, `priority`, `score_weight`, `description`, `enabled` (0/1)
**Returns**: Created/updated rule

```json
{
  "success": true,
  "data": {
    "id": 42,
    "rule_name": "Block RU IPs",
    "rule_type": "ip_block",
    "hits": 0
  }
}
```

### 22. delete_rule

**Parameters**: `rule_id`
**Returns**: Success/error

### 23. toggle_rule

**Parameters**: `rule_id`, `enabled` (0/1)
**Returns**: Toggled rule status

---

## Trust Management

### 24. set_client_trust

**Parameters**: `client_id`, `status` (normal, whitelist, blacklist), `reason`
**Returns**: Trust status set

```json
{
  "success": true,
  "data": {
    "client_id": 50,
    "status": "whitelist",
    "reason": "VIP customer verified"
  }
}
```

### 25. get_trust_list

**Parameters**: `status` (optional: whitelist, blacklist)
**Returns**: Trust list paginated

```json
{
  "success": true,
  "data": {
    "total": 25,
    "items": [
      {
        "client_id": 50,
        "email": "vip@example.com",
        "status": "whitelist",
        "reason": "..."
      }
    ]
  }
}
```

---

## Settings & Configuration

### 26. save_settings

**Parameters**: Form fields (api_keys, thresholds, toggles, etc.)
**Returns**: Success with count updated

```json
{
  "success": true,
  "message": "Settings saved (18 values updated)"
}
```

### 27. validate_api_key

**Parameters**: `provider` (turnstile, abuseipdb, ipqualityscore, fraudrecord), `api_key` (and site_key for Turnstile)
**Returns**: Validation result (real API test call)

```json
{
  "success": true,
  "data": {
    "valid": true,
    "message": "Key authenticated",
    "quota": "4950/5000 remaining"
  }
}
```

### 28. get_setup_status

**Parameters**: None
**Returns**: Setup wizard status (which keys configured)

```json
{
  "success": true,
  "data": {
    "turnstile_configured": true,
    "abuseipdb_configured": false,
    "ipqualityscore_configured": true,
    "next_step": "Configure AbuseIPDB (optional)"
  }
}
```

---

## Webhooks

### 29. save_webhook

**Parameters**: `webhook_id` (0 = new), `name`, `type` (slack, discord, teams, generic), `url`, `secret`, `events` (JSON array), `enabled` (0/1)
**Returns**: Webhook saved

### 30. delete_webhook

**Parameters**: `webhook_id`
**Returns**: Success/error

### 31. test_webhook

**Parameters**: `webhook_id`
**Returns**: Test result (makes real HTTP request)

```json
{
  "success": true,
  "data": {
    "http_code": 200,
    "response_body": "Event received",
    "time_ms": 145
  }
}
```

### 32. get_webhooks

**Parameters**: None
**Returns**: All configured webhooks with last delivery status

---

## Check Management

### 33. delete_check

**Parameters**: `check_id`
**Returns**: Success/error (one check)

### 34. delete_checks_bulk

**Parameters**: `check_ids` (CSV: 1,2,3), `client_id` (alternative: delete all for client)
**Returns**: Count deleted

### 35. clear_all_checks

**Parameters**: None (DANGEROUS)
**Returns**: Confirmation, then count deleted

---

## Report Management

### 36. update_report_status

**Parameters**: `report_id`, `status` (accepted, rejected, closed)
**Returns**: Status updated

### 37. delete_report

**Parameters**: `report_id`
**Returns**: Success/error

### 38. clear_all_reports

**Parameters**: None
**Returns**: Count deleted

### 39. report_fraudrecord

**Parameters**: `check_id`
**Returns**: Submits fraud check to FraudRecord API

### 40. report_client_fraudrecord

**Parameters**: `client_id`
**Returns**: Submits entire client profile to FraudRecord

---

## Data Export

### 41. export_csv

**Parameters**: `type` (checks, stats, bots, etc.), `date_start` (YYYY-MM-DD), `date_end`
**Returns**: CSV file download (browser triggers download)

### 42. export_client_profile

**Parameters**: `client_id`
**Returns**: Client profile as CSV/JSON (GDPR data portability)

---

## Statistics & Reporting

### 43. get_topology_data

**Parameters**: None
**Returns**: Geo event data for 3D globe visualization

```json
{
  "success": true,
  "data": {
    "events": [
      {
        "latitude": 55.75,
        "longitude": 37.62,
        "country": "RU",
        "count": 150,
        "risk_level": "high"
      }
    ],
    "intensity_map": { ... }
  }
}
```

---

## Utilities

### 44. purge_caches

**Parameters**: `type` (all, ip_intel, email_intel, rate_limits)
**Returns**: Count purged

```json
{
  "success": true,
  "data": {
    "ip_intel": 450,
    "email_intel": 320,
    "total": 770
  }
}
```

### 45. reset_statistics

**Parameters**: None (DANGEROUS)
**Returns**: Confirmation, then success

### 46. clear_fps_logs

**Parameters**: None
**Returns**: Count deleted

### 47. get_module_log

**Parameters**: `limit` (default 100)
**Returns**: Recent module errors/warnings

```json
{
  "success": true,
  "data": [
    {
      "timestamp": "2026-04-01 10:15:00",
      "type": "error",
      "message": "AbuseIPDB API timeout"
    }
  ]
}
```

---

## Bulk Actions

### 48. bulk_approve

**Parameters**: `check_ids` (CSV)
**Returns**: Count approved

### 49. bulk_deny

**Parameters**: `check_ids` (CSV)
**Returns**: Count denied

### 50. bulk_terminate

**Parameters**: `client_ids` (CSV)
**Returns**: Count terminated (cancels all orders)

---

## Global Intel Actions

### 51. global_intel_toggle

**Parameters**: `enabled` (0/1)
**Returns**: Global sharing enabled/disabled

### 52. global_intel_register

**Parameters**: None
**Returns**: Registers with hub; returns instance_id and api_key

```json
{
  "success": true,
  "data": {
    "instance_id": "abc123...",
    "instance_api_key": "fps_...",
    "hub_url": "https://hub.example.com"
  }
}
```

### 53. global_intel_push_now

**Parameters**: None
**Returns**: Manual push to hub; returns records pushed

```json
{
  "success": true,
  "data": {
    "pushed": 45,
    "inserted": 30,
    "updated": 15
  }
}
```

### 54. global_intel_stats

**Parameters**: None
**Returns**: Local vs hub statistics

```json
{
  "success": true,
  "data": {
    "local_records": 1234,
    "hub_records": 45678,
    "last_push": "2026-04-01 02:30:00",
    "last_pull": "2026-04-01 09:15:00"
  }
}
```

### 55. global_intel_browse

**Parameters**: `offset` (default 0), `limit` (default 20)
**Returns**: Paginated global intel records

```json
{
  "success": true,
  "data": {
    "total": 1234,
    "items": [
      {
        "email_hash": "sha256...",
        "country": "RU",
        "risk_score": 85,
        "seen_count": 5
      }
    ]
  }
}
```

### 56. global_intel_save_settings

**Parameters**: `hub_url`, `share_ip_addresses` (0/1), `auto_push_enabled` (0/1), etc.
**Returns**: Settings saved

### 57. global_intel_purge

**Parameters**: `target` (local, hub, all)
**Returns**: Count purged; GDPR erasure

```json
{
  "success": true,
  "data": {
    "purged": 1234,
    "target": "local"
  }
}
```

### 58. global_intel_export

**Parameters**: None
**Returns**: JSON file (GDPR data portability)

Triggers browser download of all global intel records in JSON format.

---

## API Key Management

### 59. create_api_key

**Parameters**: `name`, `tier` (free, basic, premium)
**Returns**: New key (shown once, then hashed)

```json
{
  "success": true,
  "data": {
    "key": "fps_abcdef1234567890...",
    "tier": "basic",
    "message": "Copy this key immediately (not shown again)"
  }
}
```

### 60. get_api_key_detail

**Parameters**: `key_id`
**Returns**: Key metadata and usage

```json
{
  "success": true,
  "data": {
    "id": 5,
    "name": "Integration A",
    "tier": "basic",
    "total_requests": 45000,
    "last_used_at": "2026-04-01 10:15:00",
    "rate_limit_per_minute": 120
  }
}
```

### 61. revoke_api_key

**Parameters**: `key_id`
**Returns**: Success/error; key immediately invalid

---

## Client Termination

### 62. terminate_client

**Parameters**: `client_id`, `reason` (optional)
**Returns**: Client terminated (all orders cancelled)

---

## Cross-Account Scanning

### 63. cross_account_scan

**Parameters**: `dimension` (ip, email, phone, fingerprint, address)
**Returns**: Linked account analysis

```json
{
  "success": true,
  "data": {
    "dimension": "email_domain",
    "matched_clients": [50, 51, 52],
    "match_count": 3
  }
}
```

---

## Error Responses

### Common AJAX Error Codes

| Error | Cause | Solution |
|-------|-------|----------|
| "Invalid parameters" | Missing required param | Check AJAX request body |
| "Unauthorized" | Not admin or wrong role | Verify FPS access in role settings |
| "Database error" | Query failed | Check Module Log |
| "External API error" | Provider API timeout | Retry or check provider status |
| "Validation failed" | Invalid input (e.g., bad email) | Fix input and retry |

---

## Performance Notes

- Bulk operations (mass scan, bulk purge) may take several minutes
- Client should poll progress endpoint every 2 seconds
- Long-running operations may timeout on shared hosting (increase PHP timeout)
- AJAX requests timeout after 300 seconds by default
- All database modifications are logged to WHMCS Activity Log

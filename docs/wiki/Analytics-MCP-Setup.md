# Analytics & MCP Integration

Fraud Prevention Suite (FPS) v4.2.5 integrates Google Analytics 4 and Microsoft Clarity for comprehensive fraud tracking and analysis. This page covers setup, vendor integration, consent compliance, and optional AI-powered analytics via MCP servers.

---

## Overview

The analytics integration provides three complementary tracking paths:

### 1. Client-side tracking (website visitors)
Lightweight JavaScript injection on your client portal and checkout pages. Tracks visitor behavior, fraud signals, and page interactions in GA4 + Clarity session replay.

### 2. Admin-side tracking (WHMCS staff)
Monitors which FPS tabs your team uses, how often admins review checks, and admin actions (approve, deny, lock).

### 3. Server-side custom events (12 FPS-specific events)
Rich fraud lifecycle events sent directly to GA4 via the Measurement Protocol.

Why use it:
- Detect fraud trends
- Compliance audit trail
- Optimize thresholds
- Identify patterns
- Automate alerts

---

## Quick Start

### Step 1: Get vendor credentials

1. Google Analytics 4 — Create a GA4 property and copy your Measurement ID
2. Microsoft Clarity — Sign up and copy the Project ID (10 characters)

### Step 2: Enter credentials in WHMCS

1. Navigate to Addons > Fraud Prevention Suite > Settings
2. Scroll to Analytics & Tracking card
3. Paste your GA4 Measurement ID
4. Paste your Clarity Project ID
5. Click Test Connection
6. Toggle Enable Client-Side Analytics to ON

### Step 3 (optional): Enable server-side events

1. Generate a GA4 API Secret
2. Paste into GA4 API Secret field
3. Toggle Enable Server Events to ON

---

## Required Vendor Accounts

| Service | Sign-up | DPA Required? | Free Tier |
|---------|---------|---------------|-----------|
| Google Analytics 4 | analytics.google.com | Yes | Unlimited |
| Microsoft Clarity | clarity.microsoft.com | Yes | 100K sessions/month |

IMPORTANT: You must sign DPA separately.

---

## Settings Reference

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Enable Client-Side Analytics | Toggle | OFF | Inject GA4 + Clarity |
| Enable Admin-Side Analytics | Toggle | OFF | Track admin activity |
| Enable Server Events | Toggle | OFF | Send FPS events to GA4 |
| GA4 Measurement ID (Client) | Text | (blank) | GA4 property ID |
| GA4 Measurement ID (Admin) | Text | (blank) | Optional separate |
| GA4 API Secret | Concealed | (blank) | Measurement Protocol secret |
| GA4 Service Account JSON | Textarea | (blank) | Optional for Data API |
| GA4 Property ID | Text | (blank) | GA4 property numeric ID |
| Clarity Project ID (Client) | Text | (blank) | Clarity project ID |
| Clarity Project ID (Admin) | Text | (blank) | Optional separate |
| EEA Consent Required | Toggle | ON | EEA-only consent banner |
| Analytics Event Sampling Rate | Integer | 100 | Event send percentage |
| High-Risk Signup Threshold | Integer | 80 | Risk score threshold |

---

## The 12 Server-Side Events

| Event | When it fires |
|-------|---------------|
| fps_pre_checkout_block | Checkout blocked |
| fps_pre_checkout_allow | Checkout allowed |
| fps_turnstile_fail | Turnstile fails |
| fps_turnstile_pass | Turnstile passes |
| fps_high_risk_signup | High-risk signup |
| fps_global_intel_hit | Threat hub match |
| fps_geo_impossibility_score | Geo mismatch |
| fps_velocity_block | Rate limit hit |
| fps_admin_review_action | Admin decision |
| fps_api_request | API call |
| fps_bot_purge | Bot purge runs |
| fps_module_health | Daily heartbeat |

---

## EEA Consent Mode v2

FPS implements Consent Mode v2, GDPR-compliant analytics consent.

### How it works

1. Default: Deny all — No data collected
2. Banner (EEA only) — If from EEA, banner appears
3. Banner choices:
   - Accept — Full analytics
   - Decline — Stays denied
4. Non-EEA — No banner, default-deny

### Turning off EEA-only mode

Toggle EEA Consent Required to OFF to show banner to all visitors.

---

## Dashboard Widget

Analytics Connection Status shows:
- Green — Configured and working
- Red — Configured but failing
- Grey — Not configured

Also displays:
- GA4 Realtime link
- Clarity link
- Events in last 24h
- Last event timestamp

---

## MCP Server Setup

Wire Claude for natural-language analytics queries.

### Automatic setup

```bash
bash scripts/install-mcp-servers.sh
```

This script backs up, adds GA4 + Clarity MCP servers.

### Manual setup

1. Open ~/.claude/settings.json
2. Add MCP server entries
3. Restart Claude Desktop

---

## Anomaly Detection

Daily spike detection:
1. Counts today events
2. Calculates 14-day median
3. Flags if > 3x median and > 50
4. Sends admin alert
5. Logs to mod_fps_analytics_anomalies

---

## GDPR & Privacy

### Erasure

fps_gdprPurgeByEmail() extends to call Clarity DSR API and log GA4 instructions.

### Data Processing Addendum

GA4 and Clarity require signed DPA.

### IP anonymization

- GA4: Sets anonymize_ip:true
- Clarity: Truncates IPs internally

---

## Troubleshooting

### Status shows red

Check browser console (F12) for errors. Common issues:
- Invalid Measurement ID format
- Invalid Clarity Project ID format
- Missing API secret
- Firewall blocking endpoints

### Debug toggle

Add ?fps_analytics_debug=1 to any URL and open console.

### Check log table

```sql
SELECT * FROM mod_fps_analytics_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;
```

---

## Schema Reference

### mod_fps_analytics_log

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Auto-increment |
| event_name | VARCHAR(50) | Event name |
| payload_json | LONGTEXT | Event payload |
| destination | VARCHAR(20) | ga4 or clarity |
| status | VARCHAR(10) | queued/sent/failed |
| error | LONGTEXT | Error if failed |
| created_at | TIMESTAMP | When recorded |

Rows auto-purged after 30 days.

### mod_fps_analytics_anomalies

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Auto-increment |
| event_name | VARCHAR(50) | Event name |
| baseline_count | INT | 14-day median |
| today_count | INT | Today count |
| spike_ratio | DECIMAL(5,2) | Ratio |
| flagged_at | TIMESTAMP | When detected |

---

## Next Steps

1. Enter GA4 ID + Clarity ID in Settings
2. Toggle Enable Client-Side Analytics
3. Generate API Secret and toggle Server Events
4. Run install-mcp-servers.sh (optional)
5. Sign DPAs
6. Monitor Dashboard widget
7. Ask Claude analytics questions

For more, see Architecture Overview and API Documentation.

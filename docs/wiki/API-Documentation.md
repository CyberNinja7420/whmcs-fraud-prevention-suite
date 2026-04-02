# REST API Documentation

## Overview

FPS exposes a public REST API for fraud intelligence lookups, statistics, and geographic threat visualization. Authentication via `X-FPS-API-Key` header; rate limiting enforced per tier.

## Access Tiers

| Tier | Per Minute (Default) | Per Day (Default) | Authentication | WHMCS Product |
|------|---------------------|-------------------|----------------|---------------|
| Anonymous | 5 | 100 | None | -- |
| Free | 30 | 5,000 | API Key | $0/mo |
| Basic | 120 | 50,000 | API Key | $19/mo |
| Premium | 600 | 500,000 | API Key | $99/mo |

### Configurable Rate Limits (v4.2.0+)

Rate limits are no longer hardcoded. The resolution chain is:

1. **Per-key override**: `mod_fps_api_keys.rate_limit_per_minute` / `rate_limit_per_day` (if non-zero)
2. **Tier DB setting**: Configured by admin in **Settings > API Access > Per-Tier Limits**
3. **Hardcoded fallback**: The defaults shown in the table above

Admins can configure per-tier limits in the Settings tab. Per-key overrides allow granting specific clients higher or lower limits than their tier default.

### Auto-Provisioning via Server Module

API keys can be auto-provisioned when clients purchase WHMCS products. See [Server-Module-Guide.md](Server-Module-Guide.md) for setup. The fps_api server module handles the full lifecycle: CreateAccount, SuspendAccount, UnsuspendAccount, TerminateAccount, and ChangePackage (tier upgrades/downgrades).

## Authentication

### Anonymous (No Key)

Limited endpoints; lower rate limits:

```bash
curl "https://your-whmcs.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"
```

### With API Key

Navigate to **FPS Admin > API Keys > Create Key**:

1. Enter key name and select tier
2. Copy the key (shown once)
3. Include in requests:

```bash
curl -H "X-FPS-API-Key: fps_1234567890abcdef" \
  "https://your-whmcs.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4"
```

Key format: `fps_` + 32 random characters (stored as SHA-256 hash in database)

## Response Format

All endpoints return JSON with consistent envelope:

```json
{
  "success": true,
  "data": { ... },
  "error": null,
  "meta": {
    "request_id": "fps_a1b2c3d4e5f6",
    "tier": "basic",
    "rate_limit": {
      "remaining": 118,
      "limit": 120
    },
    "response_time_ms": 42
  }
}
```

**Headers**:
- `X-RateLimit-Limit`: Requests per minute
- `X-RateLimit-Remaining`: Requests left in window
- `Retry-After`: Seconds to wait (on HTTP 429)

## Endpoint Reference

### 1. Global Statistics

**GET** `/v1/stats/global`

Platform-wide 30-day statistics. Unauthenticated access allowed.

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "period_days": 30,
    "total_checks": 245678,
    "total_flagged": 3421,
    "total_blocked": 892,
    "avg_risk_score": 28.5,
    "unique_ips": 45123,
    "top_countries": ["RU", "CN", "US"],
    "providers_active": 16
  }
}
```

---

### 2. Topology Hotspots

**GET** `/v1/topology/hotspots`

Geographic threat heatmap. Unauthenticated access allowed.

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "hotspots": [
      {
        "country": "RU",
        "country_name": "Russia",
        "latitude": 61.52,
        "longitude": 105.32,
        "count": 1234,
        "avg_risk_score": 65.3,
        "risk_level": "high"
      }
    ],
    "generated_at": "2026-04-01T10:30:00Z"
  }
}
```

---

### 3. Topology Events (Requires Free Tier)

**GET** `/v1/topology/events`

Real-time anonymized event stream (last 100 events).

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "events": [
      {
        "timestamp": "2026-04-01T10:25:15Z",
        "event_type": "high_risk_order",
        "country_code": "CN",
        "risk_score": 92,
        "risk_level": "critical"
      }
    ]
  }
}
```

---

### 4. IP Lookup - Basic (Requires Free Tier)

**GET** `/v1/lookup/ip-basic`

Country, proxy/VPN/Tor, threat score.

**Parameters**:
- `ip` (required): IPv4 or IPv6 address

**Example**:
```bash
curl -H "X-FPS-API-Key: fps_..." \
  "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/ip-basic&ip=8.8.8.8"
```

**Response**:
```json
{
  "success": true,
  "data": {
    "ip": "8.8.8.8",
    "country": "US",
    "is_proxy": false,
    "is_vpn": false,
    "is_tor": false,
    "is_datacenter": true,
    "threat_score": 15.2
  }
}
```

---

### 5. IP Lookup - Full (Requires Basic Tier)

**GET** `/v1/lookup/ip-full`

Adds ASN, ISP, region, city, coordinates.

**Parameters**:
- `ip` (required): IPv4 or IPv6 address

**Response**:
```json
{
  "success": true,
  "data": {
    "ip": "1.2.3.4",
    "country": "RU",
    "region": "Moscow",
    "city": "Moscow",
    "latitude": 55.75,
    "longitude": 37.62,
    "asn": 12345,
    "isp": "MegaFon",
    "is_proxy": true,
    "proxy_type": "residential",
    "threat_score": 72.5
  }
}
```

---

### 6. Email Lookup - Basic (Requires Basic Tier)

**GET** `/v1/lookup/email-basic`

Domain age, disposable status, role account detection.

**Parameters**:
- `email` (required): Email address

**Example**:
```bash
curl -H "X-FPS-API-Key: fps_..." \
  "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/email-basic&email=test@example.com"
```

**Response**:
```json
{
  "success": true,
  "data": {
    "email": "test@example.com",
    "domain": "example.com",
    "is_disposable": false,
    "is_role_account": false,
    "is_free_provider": false,
    "mx_valid": true,
    "domain_age_days": 5847
  }
}
```

---

### 7. Email Lookup - Full (Requires Premium Tier)

**GET** `/v1/lookup/email-full`

Adds breach history, social presence, deliverability score.

**Parameters**:
- `email` (required): Email address

**Response**:
```json
{
  "success": true,
  "data": {
    "email": "test@example.com",
    "domain": "example.com",
    "is_disposable": false,
    "is_role_account": false,
    "mx_valid": true,
    "domain_age_days": 5847,
    "breach_count": 2,
    "has_social_presence": true,
    "social_signals": ["linkedin", "twitter"],
    "deliverability_score": 94.5
  }
}
```

---

### 8. Bulk Lookup (Requires Premium Tier)

**POST** `/v1/lookup/bulk`

Batch IP/email lookups (up to 100 items per request).

**Parameters** (JSON body):
```json
{
  "items": [
    {"type": "ip", "value": "1.2.3.4"},
    {"type": "email", "value": "test@example.com"}
  ]
}
```

**Example**:
```bash
curl -X POST -H "X-FPS-API-Key: fps_..." \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
      {"type": "ip", "value": "8.8.8.8"},
      {"type": "email", "value": "admin@google.com"}
    ]
  }' \
  "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/bulk"
```

**Response**:
```json
{
  "success": true,
  "data": {
    "results": [
      {"type": "ip", "value": "8.8.8.8", "threat_score": 15.2, ...},
      {"type": "email", "value": "admin@google.com", "mx_valid": true, ...}
    ]
  }
}
```

---

### 9. Country Reports (Requires Premium Tier)

**GET** `/v1/reports/country/{CC}`

Per-country threat statistics with daily breakdown.

**Parameters**:
- `{CC}` (required, path): 2-letter country code (e.g., "RU", "CN")

**Example**:
```bash
curl -H "X-FPS-API-Key: fps_..." \
  "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/reports/country/RU"
```

**Response**:
```json
{
  "success": true,
  "data": {
    "country": "RU",
    "country_name": "Russia",
    "period_days": 30,
    "total_checks": 12345,
    "avg_risk_score": 65.3,
    "daily_breakdown": [
      {
        "date": "2026-03-02",
        "checks": 410,
        "avg_risk_score": 62.1
      }
    ]
  }
}
```

## Error Responses

### 400 Bad Request

Missing required parameter:

```json
{
  "success": false,
  "error": "Missing required parameter: ip"
}
```

### 401 Unauthorized

Invalid or missing API key:

```json
{
  "success": false,
  "error": "Invalid API key"
}
```

### 403 Forbidden

Endpoint not available on your tier:

```json
{
  "success": false,
  "error": "Endpoint not available on your tier. Upgrade at the API Keys page."
}
```

### 429 Too Many Requests

Rate limit exceeded:

```json
{
  "success": false,
  "error": "Rate limit exceeded",
  "retry_after": 45
}
```

Header: `Retry-After: 45` (seconds)

### 503 Service Unavailable

API is disabled:

```json
{
  "success": false,
  "error": "API is currently disabled"
}
```

Enable in **Settings > API Access > Enable Public API**

## Implementation Examples

### Python

```python
import requests

api_key = "fps_your_key_here"
headers = {"X-FPS-API-Key": api_key}
base_url = "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php"

# IP lookup
response = requests.get(f"{base_url}?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4", headers=headers)
data = response.json()
print(data['data']['threat_score'])
```

### JavaScript

```javascript
const apiKey = "fps_your_key_here";
const baseUrl = "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php";

fetch(`${baseUrl}?endpoint=/v1/lookup/email-basic&email=test@example.com`, {
  headers: { "X-FPS-API-Key": apiKey }
})
  .then(r => r.json())
  .then(data => console.log(data.data.is_disposable));
```

### cURL

```bash
curl -H "X-FPS-API-Key: fps_key" \
  "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"
```

## Rate Limit Behavior

Requests are rate-limited using a token bucket algorithm per tier. When limit is exceeded:

1. Request is rejected with HTTP 429
2. Response includes `Retry-After` header (seconds to wait)
3. Next request allowed after window refills
4. Daily limit is harder cap; cannot exceed by queuing

Example retry logic:

```python
import time
while True:
    r = requests.get(url, headers=headers)
    if r.status_code == 429:
        wait = int(r.headers.get("Retry-After", 60))
        print(f"Rate limited. Waiting {wait}s...")
        time.sleep(wait)
    else:
        break
```

## API Key Management

### Create Key

**FPS Admin > API Keys > Generate New Key**:
1. Enter key name (e.g., "Integration Partner A")
2. Select tier (Free, Basic, Premium)
3. Click "Generate"
4. Copy key immediately (not shown again)
5. Store securely

### Rotate Key

1. Generate new key with same tier
2. Update application to use new key
3. Click "Revoke" on old key
4. Confirm revocation

Old key stops working immediately; revocation is irreversible.

### Monitor Usage

**API Keys tab shows**:
- Key prefix (first 8 chars)
- Tier and name
- Last used timestamp
- Total requests (lifetime)
- Status (active/revoked/expired)

Click key to view detailed request logs and rate limit status.

### Usage & Abuse Tracking (v4.2.0+)

The API Keys tab now shows per-key usage analytics:

- **Requests 24h / 7d**: Request counts for the last 24 hours and 7 days
- **Rate Limit Hits**: Number of times the key has been rate-limited
- **Unauthorized Attempts**: Failed authentication attempts using this key prefix
- **Top 10 IPs**: Most active source IPs, with automatic ABUSE badge flagging (>5 rate limit hits = ABUSE)
- **Top Endpoints**: Most-used endpoints with average response time
- **Per-Key Breakdown**: Detailed usage metrics for individual keys

This data helps identify abusive API consumers and optimize rate limits.

## Troubleshooting

**Q: API returns 503 "API is currently disabled"**
- Go to Settings > API Access > Enable Public API
- Toggle the switch and save

**Q: Getting "Invalid API key"**
- Verify key format: must start with `fps_`
- Check key is not revoked or expired
- Regenerate key if needed

**Q: Hitting rate limits too quickly**
- Upgrade to higher tier for more requests
- Implement request queuing with exponential backoff
- Use bulk endpoint for multiple lookups (cheaper)

**Q: Response time is slow (>500ms)**
- Check network latency to WHMCS server
- Verify WHMCS database performance
- Some lookups require external API calls; this is expected

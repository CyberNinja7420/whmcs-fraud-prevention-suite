# Server Module Guide (fps_api)

> WHMCS server provisioning module for selling FPS API access as products.

## Overview

The `fps_api` server module enables WHMCS to automatically provision, suspend, and terminate API keys when clients purchase FPS API products. Keys are tied to client accounts and hosting services for full lifecycle management.

**Location**: `modules/servers/fps_api/fps_api.php`

**Module Functions**: CreateAccount, SuspendAccount, UnsuspendAccount, TerminateAccount, ChangePackage, ClientArea, RegenerateKey, TestConnection

## Installation

### Upload

Upload the server module file:

```
modules/servers/fps_api/fps_api.php
```

Set permissions:
```bash
chown webuser:webuser modules/servers/fps_api/fps_api.php
chmod 644 modules/servers/fps_api/fps_api.php
```

### Create Server Entry

1. Go to **Setup > Products/Services > Servers**
2. Click **Add New Server**
3. Set Name: "FPS API Server"
4. Set Type: "FPS API (fps_api)"
5. Set Hostname to your WHMCS domain
6. Click **Save Changes**
7. Click **Test Connection** -- verifies FPS addon is active and `mod_fps_api_keys` table exists

### Create Products

Create products under **Setup > Products/Services > Products/Services** using module `fps_api`.

## Product Setup

Three products are pre-configured:

| Product | Monthly Price | Tier | Rate Limits |
|---------|--------------|------|-------------|
| FPS API - Free | $0.00 | free | 30 req/min, 5,000 req/day |
| FPS API - Basic | $19.00 | basic | 120 req/min, 50,000 req/day |
| FPS API - Premium | $99.00 | premium | 600 req/min, 500,000 req/day |

### Config Options

| Option | Type | Description |
|--------|------|-------------|
| Tier | Dropdown (free/basic/premium) | Determines rate limits and endpoint access |
| Custom Rate Minute | Text (number) | Override req/minute (0 = use tier default) |
| Custom Rate Day | Text (number) | Override req/day (0 = use tier default) |

## Provisioning Lifecycle

### CreateAccount
Triggered when a new order is accepted or manually provisioned.

- Generates a unique 52-character API key (`fps_` prefix + 48 hex chars)
- Stores SHA-256 hash in `mod_fps_api_keys` (raw key never stored)
- Sets `client_id` and `service_id` for tracking
- Applies tier-based rate limits (or custom overrides from config options)
- Stores key in `tblhosting.dedicatedip` for client area display
- Logs creation to WHMCS module log

### SuspendAccount
Triggered on non-payment or admin action.

- Sets `is_active = 0` on the API key
- API requests with this key return HTTP 403

### UnsuspendAccount
Triggered when payment is received or admin action.

- Sets `is_active = 1` on the API key
- API requests work again immediately

### TerminateAccount
Triggered on service cancellation.

- Sets `is_active = 0` and `expires_at = NOW()`
- Clears stored credentials from `tblhosting.dedicatedip`
- Key is permanently revoked

### ChangePackage
Triggered on product upgrade/downgrade.

- Updates `tier`, `rate_limit_per_minute`, `rate_limit_per_day`
- Takes effect immediately for new API requests

### RegenerateKey
Triggered by client or admin action.

- Generates a new API key
- Invalidates the old key immediately (old key returns HTTP 401)
- New key hash stored; old hash overwritten
- Updates `tblhosting.dedicatedip` with new key
- Logs regeneration to WHMCS module log

### TestConnection
Triggered when admin clicks "Test Connection" on the server.

- Verifies the FPS addon module is activated
- Checks that `mod_fps_api_keys` table exists and is accessible
- Returns success if both checks pass

## Rate Limit Resolution

When an API request arrives, the rate limit is determined using this chain:

1. **Per-key override**: `mod_fps_api_keys.rate_limit_per_minute` / `rate_limit_per_day` (if non-zero)
2. **Tier DB setting**: Admin-configured per-tier limits in Settings > API Access
3. **Hardcoded fallback**: Built-in defaults (30/120/600 per minute by tier)

This allows administrators to set global tier defaults while still granting specific clients custom limits.

## Client Area

When a client views their FPS API service in the client area, they see:

- **API Key** -- full key with copy button (masked by default, reveal on click)
- **Usage Stats**:
  - Requests in last 24 hours
  - Requests in last 7 days
  - Rate limit hits (how often they were throttled)
  - Unauthorized attempts
- **Tier** -- current tier name (free, basic, or premium)
- **Rate Limits** -- current requests/minute and requests/day
- **Status** -- Active, Suspended, or Terminated
- **API Documentation** link -- directs to the public API docs
- **Regenerate Key** button -- allows client to rotate their key

## API Usage & Abuse Tracking (Admin View)

The API Keys tab in the FPS admin panel shows usage analytics for all provisioned keys:

- **Per-Key Usage**: Requests in 24h/7d, rate limit hits, unauthorized attempts
- **Top 10 IPs**: Most active source IPs per key, with automatic ABUSE badge flagging (>5 rate limit hits triggers ABUSE badge)
- **Top Endpoints**: Most-used API endpoints with average response time
- **Per-Key Breakdown**: Detailed metrics for individual keys

This data helps identify abusive API consumers and supports decisions about rate limit adjustments or key revocation.

## Admin Actions

Custom admin buttons available on each service:

| Button | Action |
|--------|--------|
| Regenerate Key | Creates new key, invalidates old one |
| View Usage | Links to API Keys admin tab |

## Database

The `mod_fps_api_keys` table includes:

| Column | Type | Purpose |
|--------|------|---------|
| client_id | INT NULL | Links to `tblclients.id` |
| service_id | INT NULL UNIQUE | Links to `tblhosting.id` (1:1) |

These columns are added by the v4.2.0 migration in the addon module's `upgrade()` function.

## Testing

Run the provisioning test script:
```bash
php fps_provision_test.php /path/to/whmcs
```

This tests: CreateAccount, SuspendAccount, UnsuspendAccount, TerminateAccount, ChangePackage, and ClientArea rendering.

## Troubleshooting

| Issue | Cause | Fix |
|-------|-------|-----|
| "No API key found for this service" | Key not created | Run Module Command > Create from admin |
| Key shows suspended after payment | WHMCS didn't trigger unsuspend | Run Module Command > Unsuspend from admin |
| Rate limits not changing on upgrade | configoption1 not set on product | Check product Module Settings > Tier dropdown |
| Client area blank | tblhosting.dedicatedip cleared | Regenerate key via admin button |

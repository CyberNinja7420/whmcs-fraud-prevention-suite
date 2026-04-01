# Global Threat Intelligence Setup

## Overview

Global Intel is a cross-instance fraud intelligence sharing system. Your WHMCS instance contributes anonymized fraud data to a central hub and benefits from fraud signals detected by other instances running FPS.

## Hub Architecture

The hub operates as a stand-alone service that aggregates fraud intelligence from opt-in WHMCS instances:

```
WHMCS Instance 1 --> Hub (SQLite) --> WHMCS Instance 2
WHMCS Instance 3 -->      ^      --> WHMCS Instance 4
                          |
                    [Deduplication]
                    [Aggregation]
                    [Query API]
```

The hub can run anywhere (cloud, on-prem, Docker) with HTTPS and a DNS record.

## Hub Deployment

### Docker (Recommended)

```bash
cd fps-hub/
docker compose up -d
```

Exposes port 8400. Set up reverse proxy (Caddy/Nginx) for HTTPS:

```
https://hub.example.com {
  reverse_proxy localhost:8400
}
```

### Configuration

Create `.env` in fps-hub/:

```
HUB_DEBUG=false
HUB_STORAGE_PATH=/data/fps-hub.db
HUB_MAX_RECORDS=1000000
HUB_RETENTION_DAYS=365
HUB_API_PORT=8400
```

## Instance Registration

### Step 1: Enable Global Intel

Go to **FPS Admin > Global Intel > Settings**:

1. Toggle "Enable Global Threat Intelligence"
2. Enter Hub URL (e.g., `https://hub.example.com`)
3. Save settings

### Step 2: Register with Hub

Click **"Register Instance"** button:

- Generates unique `instance_id` and `instance_api_key`
- Sends registration POST to hub: `POST /v1/instances/register`
- Hub responds with registration confirmation
- Your instance is now listed in hub's instance registry

**Registration data sent**:
- `instance_id` (UUID)
- `instance_domain` (from WHMCS SystemURL)
- `instance_api_key` (authentication token)
- Current timestamp

## Data Flow

### Push (Local -> Hub)

**Trigger**: Daily cron job or manual "Push Now" button

**Process**:
1. Query `mod_fps_global_intel` WHERE `pushed_to_hub = 0` (unpushed records)
2. For each record:
   - Extract: email_hash, ip_address, country, risk_score, evidence_flags
   - Strip: actual email, names, billing data (GDPR safe)
3. Send POST to `hub/v1/intel/push`:
   ```json
   {
     "instance_id": "...",
     "api_key": "...",
     "records": [
       {
         "email_hash": "sha256...",
         "ip_address": "1.2.3.4",
         "country": "US",
         "risk_score": 85,
         "evidence_flags": {...}
       }
     ]
   }
   ```
4. Hub deduplicates: if email_hash + ip exist, increments `seen_count`, updates `risk_score` to MAX
5. Mark sent records: `updated_at` timestamp, `pushed_at` timestamp, `pushed_to_hub = 1`

**Auto-push**: Configured in Global Intel Settings; runs during DailyCronJob

### Query (Hub -> Local)

**Trigger**: New signup, new order (during fraud check)

**Process**:
1. During FPS check, call `FpsGlobalIntelChecker::check()`
2. Query hub: `GET /v1/intel/lookup`
   ```
   ?email_hash=sha256...&ip=1.2.3.4&instance_id=...&api_key=...
   ```
3. Hub returns matching records:
   ```json
   {
     "matches": [
       {
         "email_hash": "...",
         "ip_address": "...",
         "country": "US",
         "risk_score": 92,
         "seen_count": 15,
         "cross_instance_sources": 3
       }
     ],
     "total_matches": 1
   }
   ```
4. Add points to fraud score based on hit count:
   - 1 match: +5 points
   - 2-3 matches: +10 points
   - 4+ matches: +30 points

**Local cache**: Results cached for 24 hours in `mod_fps_ip_intel` and `mod_fps_email_intel`

## Privacy Controls

### Share IP Addresses

Toggle in **Global Intel > Settings**:

- **On**: IP addresses sent to hub (faster correlation, better detection)
- **Off**: Only email hashes sent (stronger privacy)

When disabled, hub cannot detect shared IPs across instances but can still match emails.

### Data Consent

Before first push, admins must accept data sharing consent:

```
"I understand my instance will share:
- SHA-256 email hashes (irreversible)
- [Optional] IP addresses
- 2-letter country codes
- Risk scores (0-100)
- Fraud evidence flags

And never share:
- Names, phone numbers, addresses
- Invoice/payment data
- Client IDs or order IDs
- Fingerprint raw data
```

Consent is stored in `mod_fps_global_config` > `data_consent_accepted = 1`

## GDPR Compliance

### Article 17 (Right to Erasure)

Click **"Purge All Local Intel"** in Global Intel Settings:

```
DELETE FROM mod_fps_global_intel WHERE source = 'local'
```

All locally-sourced records deleted. Hub-received data unaffected (other instances' contributions).

### Article 20 (Data Portability)

Click **"Export All Data"**:

```json
{
  "export_date": "2026-04-01T10:30:00Z",
  "instance_id": "...",
  "records": [
    {
      "email_hash": "...",
      "ip_address": "...",
      "country": "US",
      "risk_score": 75,
      "seen_count": 5,
      "first_seen": "2026-03-15T08:00:00Z",
      "pushed_to_hub": true
    }
  ]
}
```

JSON file downloaded; suitable for data subject portability requests.

### Hub Data Removal (Article 17 Extended)

Click **"Purge Hub Contributions"** in Global Intel Settings:

1. Query hub for all records from your instance
2. Send DELETE to `hub/v1/intel/purge`
3. Hub removes records where `source = 'instance_abc123'`
4. Confirmation returned: "Removed 12,345 records"

This removes your contributions from the shared hub; other instances' data unaffected.

## Configuration Reference

Settings stored in `mod_fps_global_config`:

| Key | Default | Description |
|-----|---------|-------------|
| `global_sharing_enabled` | 0 | Master toggle for all Global Intel |
| `hub_url` | 130.12.69.6:8400 | Hub HTTP endpoint |
| `instance_id` | auto-generated | Unique UUID for this instance |
| `instance_api_key` | empty | Auth token for hub requests |
| `instance_domain` | from SystemURL | Registered domain |
| `share_ip_addresses` | 1 | Include IPs in hub pushes |
| `auto_push_enabled` | 1 | Push intel during DailyCronJob |
| `auto_pull_on_signup` | 1 | Query hub during signups |
| `intel_retention_days` | 365 | Local intel TTL |
| `last_push_at` | empty | Last push timestamp |
| `last_pull_at` | empty | Last hub query |
| `data_consent_accepted` | 0 | GDPR consent flag |

## Hub API Endpoints

### Register Instance
```
POST /v1/instances/register
{
  "instance_id": "...",
  "instance_domain": "whmcs.example.com",
  "instance_api_key": "..."
}
```
Returns: `{ "registered": true }`

### Push Intel
```
POST /v1/intel/push
{
  "instance_id": "...",
  "api_key": "...",
  "records": [...]
}
```
Returns: `{ "inserted": 123, "updated": 45 }`

### Query Intel
```
GET /v1/intel/lookup?email_hash=...&ip=...&instance_id=...&api_key=...
```
Returns: `{ "matches": [...], "total_matches": 1 }`

### Purge Instance Data
```
DELETE /v1/intel/purge
{
  "instance_id": "...",
  "api_key": "..."
}
```
Returns: `{ "records_deleted": 12345 }`

## Troubleshooting

**Q: Hub registration fails with "Connection refused"**
- Verify hub URL is reachable: `curl https://hub.example.com/v1/health`
- Check HTTPS certificate is valid
- Verify firewall allows outbound HTTPS from WHMCS server

**Q: Push returns "Auth failed"**
- Verify `instance_api_key` is set in database
- Re-register instance to regenerate keys
- Check hub is running: `docker logs fps-hub`

**Q: Global Intel scores not affecting fraud checks**
- Verify `auto_pull_on_signup` is enabled in settings
- Check Module Log for hub query errors
- Ensure hub has data: `docker exec fps-hub sqlite3 /data/fps-hub.db "SELECT COUNT(*) FROM global_intel"`

**Q: Hub running out of storage**
- Configure `HUB_RETENTION_DAYS` in hub `.env` (default 365)
- Run: `docker exec fps-hub python purge_expired.py`
- Monitor SQLite size: `docker exec fps-hub du -sh /data/fps-hub.db`

## Best Practices

1. **Start with IP sharing OFF**: Enable after vetting hub security
2. **Test before production**: Register test instance first
3. **Monitor hub health**: Check "Hub Status" indicator in Global Intel tab
4. **Review GDPR annually**: Ensure consent documentation is updated
5. **Schedule pushes**: Use auto-push during off-peak hours (e.g., 2 AM)
6. **Backup hub data**: If self-hosted, include fps-hub.db in backups

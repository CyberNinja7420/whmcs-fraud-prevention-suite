# Installation Guide

## Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| WHMCS | 8.0 | 8.10+ |
| PHP | 8.2 | 8.3 |
| MySQL | 5.7 | 8.0 |
| MariaDB | 10.3 | 10.11+ |
| cURL | Required | -- |
| DNS functions | Required | -- |
| Disk space | 5 MB | 10 MB |

## Step 1: Upload

Upload the `fraud_prevention_suite/` directory to your WHMCS modules directory:

```
/path/to/whmcs/modules/addons/fraud_prevention_suite/
```

Ensure the directory structure is:
```
modules/addons/fraud_prevention_suite/
  fraud_prevention_suite.php
  hooks.php
  version.json
  lib/
  assets/
  templates/
  public/
  data/
  docs/
```

## Step 2: Set Permissions

```bash
chown -R webuser:webuser modules/addons/fraud_prevention_suite/
chmod -R 644 modules/addons/fraud_prevention_suite/
find modules/addons/fraud_prevention_suite/ -type d -exec chmod 755 {} \;
```

## Step 3: Activate

1. Login to WHMCS Admin Panel
2. Navigate to **Setup > Addon Modules**
3. Find "Fraud Prevention Suite" in the list
4. Click **Activate**
5. Configure access control (select which admin roles can access FPS)
6. Click **Save Changes**

The module automatically creates 36+ database tables. All table creation is idempotent -- re-activating is safe and won't destroy data.

## Step 4: Configure API Keys

Open the FPS module (**Addons > Fraud Prevention Suite**). The Dashboard shows a Setup Wizard banner.

### Priority 1: Cloudflare Turnstile (Critical -- Free)
1. Go to [dash.cloudflare.com](https://dash.cloudflare.com)
2. Click **Turnstile** in the left sidebar
3. Click **Add Site**, enter your WHMCS domain
4. Choose **Managed** widget mode
5. Copy the **Site Key** and **Secret Key**
6. Enter both in the FPS Setup Wizard
7. Click **Validate** -- green checkmark confirms it works

### Priority 2: AbuseIPDB (Recommended -- Free)
1. Create account at [abuseipdb.com](https://www.abuseipdb.com/register)
2. Go to **Account > API**
3. Copy your API Key (v2)
4. Enter in FPS Setup Wizard, click Validate
5. Free tier: 1,000 checks/day

### Priority 3: IPQualityScore (Recommended -- Free)
1. Create account at [ipqualityscore.com](https://www.ipqualityscore.com/create-account)
2. Copy API key from dashboard
3. Enter in FPS Setup Wizard, click Validate
4. Free tier: 5,000 checks/month

### Priority 4: FraudRecord (Optional -- Free)
1. Sign up at [fraudrecord.com](https://www.fraudrecord.com/signup/)
2. Go to **API Access**
3. Copy your API key
4. Enter in FPS Setup Wizard, click Validate

## Step 5: Run Initial Scan

1. Go to the **Mass Scan** tab
2. Set filter to **All Clients**
3. Click **Start Scan**
4. Wait for the scan to complete (processes ~100 clients/minute)
5. Review results in the **Review Queue** tab

## Step 6: Enable Global Intelligence (Optional)

1. Go to the **Global Intel** tab
2. Enter the Hub URL (provided by your administrator)
3. Click **Save Settings**
4. Click **Register with Hub**
5. Click **Enable Sharing**

See [Global Intel Setup](Global-Intel-Setup.md) for details.

## Verification

After installation, verify:

- [ ] Dashboard loads without errors
- [ ] All 14 tabs are visible and clickable
- [ ] Setup Wizard shows provider status
- [ ] Mass Scan completes without errors
- [ ] No PHP errors in WHMCS Module Log (Utilities > Logs > Module Log)

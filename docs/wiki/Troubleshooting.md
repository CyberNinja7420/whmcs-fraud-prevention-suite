# Troubleshooting Guide

## Module Won't Activate or Load

### Error: "Class not found" or "Cannot load module"

**Cause**: PHP version < 8.2 or autoloader failure

**Fix**:
1. Check PHP version: `php -v` (must be 8.2+)
2. Verify file structure:
   ```
   fraud_prevention_suite/
     lib/Autoloader.php
     lib/FpsCheckRunner.php
     lib/Providers/
   ```
3. Check file permissions: `chmod 644 lib/*.php && chmod 755 lib/*/`
4. Clear WHMCS cache: `Admin > Utilities > System > Clear All Caches`

### Error: "Table creation failed" during activation

**Cause**: MySQL permissions or compatibility issue

**Fix**:
1. Check WHMCS database user has CREATE TABLE privilege
2. Verify MySQL version: 5.7+ or MariaDB 10.3+
3. Check disk space: `SHOW VARIABLES LIKE 'datadir'`
4. Look at WHMCS error log: `Admin > Utilities > Logs > Module Log`
5. Manually verify tables exist: `SHOW TABLES LIKE 'mod_fps%'`

## Blank Admin Page

### Dashboard shows no content

**Cause**: JavaScript error, CSS not loading, or AJAX request failing

**Fix**:
1. Open browser DevTools (F12) > Console tab
2. Look for red errors
3. Check Network tab for failed requests
4. Verify CSS loads: Check `<head>` for `fps-1000x.css`
5. Clear browser cache: Ctrl+F5

### Specific tab shows blank (e.g., Bot Cleanup)

**Cause**: Tab renderer throws exception

**Fix**:
1. Check Module Log: `Admin > Utilities > Logs > Module Log`
2. Search for tab class name (e.g., `TabBotCleanup`)
3. Look for SQL errors or file not found
4. Re-upload `lib/Admin/TabBotCleanup.php` from distribution
5. Verify database tables exist for that tab

## API Returns 503 "Service Unavailable"

### Public REST API is disabled

**Cause**: Feature not enabled in settings

**Fix**:
1. Go to **FPS Admin > Settings > API Access**
2. Toggle "Enable Public API" to ON
3. Save settings
4. Wait 5 seconds then retry API request

### API returns 503 on test

```bash
curl "https://whmcs.example.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"
```

**Fix**:
1. Verify endpoint is correct (starts with `/v1/`)
2. Check query parameters: Use `?endpoint=/v1/stats/global` (no double slashes)
3. Verify DNS resolves to WHMCS server
4. Check HTTPS certificate is valid
5. Try with API key: Add `-H "X-FPS-API-Key: fps_..."` header

## Bot Scan Shows 0 Suspects

### Detect Bots button finds no bots

**Cause**: All clients have paid invoices or active hosting

**Fix**:
1. Verify status filter: Check dropdown (may be set to "Inactive Only")
2. Check specific client in **Client Profile** tab
3. Look at "Financial Data" section: paid invoices and active services
4. If all clients are legitimate, bot detection is working correctly

### Bot Scan shows wrong count

**Cause**: Filter doesn't match actual data

**Fix**:
1. Switch status filter to "All Statuses"
2. Click "Detect Bots" again
3. Verify invoice and service counts in sample clients
4. Check database directly:
   ```sql
   SELECT c.id, c.email, COUNT(DISTINCT i.id) as inv_count, COUNT(DISTINCT h.id) as svc_count
   FROM tblclients c
   LEFT JOIN tblinvoices i ON c.id = i.userid AND i.total > 0
   LEFT JOIN tblhosting h ON c.id = h.userid AND h.domainstatus = 'Active'
   GROUP BY c.id
   HAVING inv_count = 0 AND svc_count = 0
   LIMIT 5;
   ```

## Provider Check Returns Empty

### Score shows 0 or result is incomplete

**Cause**: Provider is disabled, API key missing, or provider not returning data

**Fix**:
1. Go to **Settings > Fraud Providers**
2. Verify the provider's toggle is ON
3. Check if API key is required:
   - **Cloudflare Turnstile**: Site Key + Secret Key required
   - **AbuseIPDB**: API Key required
   - **IPQualityScore**: API Key required
   - **FraudRecord**: API Key required
4. Test API key using Setup Wizard
5. Check Alert Log for error messages

### Provider keeps returning errors

**Cause**: Invalid API credentials or provider API is down

**Fix**:
1. Regenerate API key at provider's website
2. Re-enter in FPS Settings
3. Click "Test Key" button (in Setup Wizard)
4. If test fails, check provider status page
5. Review Alert Log for specific error (quota exceeded, timeout, etc.)

### Specific provider has quota warning

**Cause**: Provider's free tier has request limits

**Fix**:
1. Check Alert Log for "Quota exceeded" messages
2. Review provider's current usage in their dashboard
3. Options:
   - Disable the provider in FPS until quota resets
   - Upgrade provider plan
   - Reduce check frequency via custom rules

## Font Too Small or Display Issues

### Text is unreadable on dashboard

**Cause**: Font scaling not applied or browser zoom reset

**Fix**:
1. Go to **Settings > Display Options**
2. Adjust "Font Size Scaling" slider (85% to 130%)
3. Save settings
4. Reload page (Ctrl+F5)
5. If still small, check browser zoom: Ctrl+0 (reset to 100%)

### Charts don't display

**Cause**: ApexCharts JavaScript library not loading

**Fix**:
1. DevTools > Network > Look for failed .js files
2. Verify `fps-charts.js` exists: `assets/js/fps-charts.js`
3. Check browser console for JavaScript errors
4. Try disabling browser extensions (ad blockers, etc.)
5. Clear browser cache and reload

### Dark mode not working

**Cause**: CSS variables not applied

**Fix**:
1. Settings > Display Options > Theme toggle
2. Check that browser supports CSS custom properties (all modern browsers do)
3. Clear browser cache
4. Reload page with Ctrl+Shift+Delete (hard refresh)

## Settings Not Saving

### Settings form submitted but values not updated

**Cause**: Database permissions, AJAX error, or validation failure

**Fix**:
1. Check browser Console (F12) for AJAX error messages
2. Verify database user can UPDATE `mod_fps_settings` table
3. Check for validation errors: Look for red error messages in form
4. Verify API key fields are not empty if required
5. Try saving a single setting at a time (not bulk)

### Settings intermittently revert

**Cause**: Race condition in cache invalidation

**Fix**:
1. Go to **Settings** and resave all values
2. Wait 30 seconds before changing other settings
3. Clear WHMCS cache: `Admin > Utilities > System > Clear All Caches`
4. Reload module: Go to another tab then back to Settings

## Turnstile Not Blocking Bots

### Turnstile widget doesn't appear on checkout

**Cause**: Site Key not set or hook not active

**Fix**:
1. Verify `ClientAreaHeaderOutput` hook is active (should be auto-injected)
2. Check **Settings > Turnstile Configuration**:
   - Site Key is set (get from dash.cloudflare.com > Turnstile)
   - Secret Key is set
3. Click "Test Key" button in Setup Wizard
4. If widget still missing, check browser console for errors
5. Verify Cloudflare JS loads: Look for `challenges.cloudflare.com` in Network tab

### Bots still bypass Turnstile

**Cause**: Bot is solving challenge correctly or validation disabled

**Fix**:
1. Go to **Settings > Pre-Checkout Blocking**
2. Toggle "Pre-Checkout Bot Blocking" to ON
3. Verify threshold is reasonable (default 85)
4. Check Score Distribution in Dashboard (should show blocked attempts)
5. Enable Debug Mode in Settings to log all block attempts

## Order Locks Not Taking Effect

### Critical orders not auto-locked

**Cause**: Auto-lock is disabled or threshold is too high

**Fix**:
1. Go to **Settings > Risk Thresholds**
2. Check "Auto-Lock Critical Orders" toggle is ON
3. Verify "Critical Risk Threshold" is reasonable (default 80)
4. Go to **Review Queue** and manually lock test order
5. Check Activity Log: `Admin > Utilities > Logs > Activity Log` for lock messages

### Orders locked but should have been approved

**Cause**: Threshold too aggressive

**Fix**:
1. Review locked order in **Review Queue**
2. Check risk score and provider breakdown
3. Adjust threshold upward in Settings if too many false positives
4. Consider adding client to Whitelist (Trust Management tab)
5. Review custom rules: may be causing high scores

## Module Freezes or Timeouts

### Mass Scan hangs

**Cause**: Too many clients (>10,000) or slow database

**Fix**:
1. Scan in smaller batches: Use filters (status, date range)
2. Check WHMCS database size: `SELECT COUNT(*) FROM tblclients`
3. Increase PHP timeout in php.ini: `max_execution_time = 300`
4. Run during off-peak hours
5. Monitor server load: `top` (Linux) or Task Manager (Windows)

### Bot Cleanup purge times out

**Cause**: Large batch size on shared hosting

**Fix**:
1. Limit bulk action to 50-100 clients at a time
2. Use preview first: Click action button, wait for dry-run
3. Request timeout increase from hosting provider
4. Manually purge smaller batches
5. Contact support for large-scale cleanup

## Email Notifications Not Sending

### Alert emails not received

**Cause**: Email address not configured or mail() failing

**Fix**:
1. Go to **Settings > Notifications**
2. Verify "Notification Email" is set (not blank)
3. Send test email: Admin > Utilities > Email > Test Email
4. Check WHMCS mail log: `Admin > Utilities > Logs > Mail Log`
5. Verify notification is enabled: "Send High-Risk Alerts" toggle = ON

### Webhook notifications failing

**Cause**: Webhook URL unreachable or malformed

**Fix**:
1. Go to **Settings > Webhooks**
2. Click webhook, then "Test Webhook"
3. Check response status (should be 200)
4. Verify URL is HTTPS with valid certificate
5. Check webhook server logs for failed requests
6. Look at Module Log for timeout errors

## Database Corruption

### Getting SQL errors in Module Log

**Cause**: Table schema mismatch or missing columns

**Fix**:
1. Re-run activation: Go to Addon Modules, click Deactivate, then Activate
2. Activation is idempotent (safe to re-run)
3. Check all 36 tables exist: `SHOW TABLES LIKE 'mod_fps%'`
4. If specific table missing, re-activate module
5. As last resort, restore database backup and activate again

### "Column not found" errors

**Cause**: Version mismatch or incomplete upgrade

**Fix**:
1. Verify module version: Check `version.json`
2. Ensure all files from distribution are uploaded
3. Re-activate module (triggers upgrade migrations)
4. Check for upgrade errors in Module Log
5. Manually verify column exists:
   ```sql
   SHOW COLUMNS FROM mod_fps_checks WHERE Field = 'provider_scores';
   ```

## Performance Issues

### Dashboard loads slowly

**Cause**: Large dataset in charts or slow queries

**Fix**:
1. Go to **Settings > Data Retention**
2. Reduce retention period: Delete old checks (>90 days)
3. Run **Utilities > Purge Caches** to clear expired intel
4. Check database size: Large mod_fps_checks table slows queries
5. Enable database query caching if available
6. Monitor server resources: CPU, RAM, disk I/O

### API responses slow (>1 second)

**Cause**: External provider API calls blocking

**Fix**:
1. Check Alert Log for provider timeouts
2. Consider disabling slow providers (Domain Reputation, SMTP)
3. Increase cache TTL: Settings > Cache TTL > increase 24 hours to 48+
4. Use bulk API endpoint instead of individual queries
5. Whitelist known-good clients/IPs to skip checks

## GDPRand Data Privacy

### Client data not fully deleted

**Cause**: Data retained in Global Intel or cache

**Fix**:
1. Go to **Global Intel > Settings > Purge All Local Intel**
2. Also purge hub contributions: **Global Intel > Purge Hub Contributions**
3. Verify `mod_fps_checks` entries deleted for that client
4. Confirm email no longer in `mod_fps_email_intel`
5. Export GDPR data first: **Global Intel > Export All Data** (if needed)

### User login account not deleted after client purge

**Cause**: User has multiple client associations

**Fix**:
1. Go to **Bot Cleanup > User Account Cleanup**
2. Click "Detect Orphan Users"
3. Check if user appears in list
4. If not listed, user is linked to real (non-bot) clients
5. To delete, must first purge all associated bot clients

## Getting Help

Check these resources before contacting support:

1. **Module Log**: `Admin > Utilities > Logs > Module Log` (most errors here)
2. **Alert Log**: FPS Dashboard > Alert Log tab (provider health)
3. **Activity Log**: `Admin > Utilities > Logs > Activity Log` (user actions)
4. **Browser Console**: DevTools > Console (JavaScript errors)
5. **This Guide**: Search for your error message

Include in support request:
- WHMCS version
- PHP version
- Module version (Settings tab)
- Error message (exact text)
- Recent Module Log entries
- Steps to reproduce

# Analytics Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add Google Analytics 4 (client + admin + 12 server-side custom events) and Microsoft Clarity (client + admin) to the WHMCS Fraud Prevention Suite, with EEA-compliant Consent Mode v2, anomaly detection, MCP server registration, and full battle-test coverage from both admin and client sides.

**Architecture:** Three separated concerns — client-side injection (`ClientAreaHeaderOutput` hook), admin-side injection (`AdminAreaHeaderOutput` hook), and server-side Measurement Protocol POSTs (called from `FpsCheckRunner` + hooks + cron). All gated by 3 independent toggles, all default OFF. New `lib/Analytics/` directory holds 7 helper files in the global namespace (matching existing `lib/Gdpr/`, `lib/Install/`, `lib/FpsMailHelper.php` pattern). Dashboard tile shows connection status (no in-WHMCS data fetch except an optional Service Account JWT pull for one number). Two new tables (`mod_fps_analytics_log`, `mod_fps_analytics_anomalies`).

**Tech Stack:** PHP 8.2, WHMCS Capsule ORM, GA4 Measurement Protocol (HTTP POST), GA4 Data API (Service Account JWT), Microsoft Clarity JS API (`window.clarity`), Consent Mode v2 (`gtag('consent','default'/'update',...)`), vanilla JS for the consent banner.

**Design doc:** `docs/plans/2026-04-22-analytics-integration-design.md` — read this first; all defaults, event names, payload shapes, schema columns, and battle-test pass criteria are specified there.

**Quality gates:** `php -l` per file, `phpstan analyse` (level 3 with baseline), `vendor/bin/psalm` (level 6 with baseline). All three must exit 0 before each commit. Synthetic functional checks via PHP CLI scripts; HTTP + browser Network tab inspection for the client-side parts.

---

## Phase 1 — Foundation (config + schema)

### Task 1: Create FpsAnalyticsConfig

**Files:**
- Create: `lib/Analytics/FpsAnalyticsConfig.php`

**Step 1: Write the failing static-analysis check**

Create the empty stub first so we can lint it:

```php
<?php
/**
 * FpsAnalyticsConfig -- memoized settings reader + ID validators
 * for GA4 + Clarity. Reads from mod_fps_settings (kv store), NOT
 * tbladdonmodules. Direct Capsule lookups for per-row operational
 * flags so admin saves take effect on the very next request.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

final class FpsAnalyticsConfig
{
    private static array $cache = [];

    public const KEYS = [
        'enable_client_analytics', 'enable_admin_analytics', 'enable_server_events',
        'ga4_measurement_id_client', 'ga4_measurement_id_admin', 'ga4_api_secret',
        'ga4_service_account_json',
        'clarity_project_id_client', 'clarity_project_id_admin',
        'analytics_eea_consent_required', 'analytics_event_sampling_rate',
    ];

    public static function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        try {
            $val = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            self::$cache[$key] = $val !== null ? (string) $val : $default;
        } catch (\Throwable $e) {
            self::$cache[$key] = $default;
        }
        return self::$cache[$key];
    }

    public static function isClientEnabled(): bool { return self::get('enable_client_analytics', '0') === '1'; }
    public static function isAdminEnabled(): bool  { return self::get('enable_admin_analytics',  '0') === '1'; }
    public static function isServerEnabled(): bool { return self::get('enable_server_events',    '0') === '1'; }

    /** GA4 measurement ID format: G-XXXXXXXXXX (10+ alnum after G-) */
    public static function isValidGa4Id(string $id): bool
    {
        return $id === '' || (bool) preg_match('/^G-[A-Z0-9]{8,12}$/', $id);
    }

    /** Clarity project ID format: 10 char alnum lowercase */
    public static function isValidClarityId(string $id): bool
    {
        return $id === '' || (bool) preg_match('/^[a-z0-9]{8,12}$/', $id);
    }

    /** Service Account JSON: validate parseable + has private_key + private_key parses as RSA. */
    public static function isValidServiceAccountJson(string $json): bool
    {
        if ($json === '') return true;
        $d = json_decode($json, true);
        if (!is_array($d) || empty($d['private_key']) || empty($d['client_email'])) return false;
        $key = @openssl_pkey_get_private($d['private_key']);
        if ($key === false) return false;
        return true;
    }

    public static function clearCache(): void { self::$cache = []; }
}
```

**Step 2: Lint + phpstan + psalm**

Run: `php -l lib/Analytics/FpsAnalyticsConfig.php && vendor/bin/phpstan analyse --no-progress --memory-limit=512M lib/Analytics/FpsAnalyticsConfig.php && vendor/bin/psalm --no-cache --no-progress lib/Analytics/FpsAnalyticsConfig.php`

Expected: exit 0 from each.

**Step 3: Commit**

```bash
git add lib/Analytics/FpsAnalyticsConfig.php
git commit -m 'feat(analytics): FpsAnalyticsConfig (memoized settings reader + ID validators)'
```

---

### Task 2: Seed the 11 settings + register boolean toggles + require_once

**Files:**
- Modify: `fraud_prevention_suite.php` near the existing `'use_runner_fast_path' => '1'` block (~line 666 area) — add 11 new seeded settings
- Modify: `fraud_prevention_suite.php` `$booleanFlagKeys` array — add 3 new toggles
- Modify: `fraud_prevention_suite.php` near the existing `require_once __DIR__ . '/lib/FpsMailHelper.php';` — add `require_once __DIR__ . '/lib/Analytics/FpsAnalyticsConfig.php';`

**Step 1: Add the require_once**

```php
require_once __DIR__ . '/lib/Analytics/FpsAnalyticsConfig.php';
```

**Step 2: Add seeded defaults to the settings array** (insert immediately after `'use_runner_fast_path' => '1',`)

```php
// Analytics integration defaults (TODO-hardening item: analytics design doc).
// All three master toggles default OFF -- operator opts in per side after
// entering credentials. See docs/plans/2026-04-22-analytics-integration-design.md.
'enable_client_analytics'         => '0',
'enable_admin_analytics'          => '0',
'enable_server_events'            => '0',
'ga4_measurement_id_client'       => '',
'ga4_measurement_id_admin'        => '',
'ga4_api_secret'                  => '',
'ga4_service_account_json'        => '',
'clarity_project_id_client'       => '',
'clarity_project_id_admin'        => '',
'analytics_eea_consent_required'  => '1',
'analytics_event_sampling_rate'   => '100',
```

**Step 3: Extend `$booleanFlagKeys`**

```php
$booleanFlagKeys = [
    'enable_site_theme_overrides', 'enable_featured_products',
    'hide_invoice_extensions', 'redirect_chat_now',
    'geo_impossibility_requires_history',
    'use_runner_fast_path',
    'write_legacy_details_column',
    'drop_legacy_details_columns',
    'enable_client_analytics', 'enable_admin_analytics',
    'enable_server_events', 'analytics_eea_consent_required',
];
```

**Step 4: Lint**

Run: `php -l fraud_prevention_suite.php`

Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add fraud_prevention_suite.php
git commit -m 'feat(analytics): seed 11 settings + 4 toggles + require_once FpsAnalyticsConfig'
```

---

### Task 3: Add the two analytics tables to _activate

**Files:**
- Modify: `fraud_prevention_suite.php` `fraud_prevention_suite_activate()` — add two `if (!hasTable())` blocks at the end of the create() section

**Step 1: Add the table creation**

```php
// mod_fps_analytics_log -- 30-day rolling event log (queries for status widget)
if (!Capsule::schema()->hasTable('mod_fps_analytics_log')) {
    Capsule::schema()->create('mod_fps_analytics_log', function ($table) {
        $table->increments('id');
        $table->string('event_name', 50)->index();
        $table->text('payload_json')->nullable();
        $table->string('destination', 20)->default('ga4_server'); // ga4_client | ga4_server | clarity
        $table->string('status', 10)->default('queued');           // queued | sent | failed
        $table->text('error')->nullable();
        $table->timestamp('created_at')->useCurrent()->index();
    });
}

// mod_fps_analytics_anomalies -- spike-detection records
if (!Capsule::schema()->hasTable('mod_fps_analytics_anomalies')) {
    Capsule::schema()->create('mod_fps_analytics_anomalies', function ($table) {
        $table->increments('id');
        $table->string('event_name', 50)->index();
        $table->integer('baseline_count')->default(0);
        $table->integer('observed_count')->default(0);
        $table->timestamp('detected_at')->useCurrent();
        $table->timestamp('notified_at')->nullable();
    });
}
```

**Step 2: Lint**

Run: `php -l fraud_prevention_suite.php`

Expected: clean.

**Step 3: Commit**

```bash
git add fraud_prevention_suite.php
git commit -m 'feat(analytics): add mod_fps_analytics_log + mod_fps_analytics_anomalies tables'
```

---

### Task 4: Sync Phase 1 to dev WHMCS + verify activate idempotent

**Files:** none (deployment + verification)

**Step 1: Build deploy tarball**

```bash
cd "D:/Claude workfolder/fraud_prevention_suite"
tar -cf /tmp/fps-analytics-p1.tar fraud_prevention_suite.php lib/Analytics/
scp -i ~/.ssh/id_ed25519_gpu_ci /tmp/fps-analytics-p1.tar root@130.12.69.6:/tmp/
```

**Step 2: Apply to dev**

```bash
ssh -i ~/.ssh/id_ed25519_gpu_ci root@130.12.69.6 "scp -o StrictHostKeyChecking=no -i /root/.ssh/evdps_server_ed25519 /tmp/fps-analytics-p1.tar root@130.12.69.7:/tmp/
ssh -o StrictHostKeyChecking=no -i /root/.ssh/evdps_server_ed25519 root@130.12.69.7 'set -e
cd /home/freeit/public_html/modules/addons/fraud_prevention_suite
mkdir -p lib/Analytics
tar -xf /tmp/fps-analytics-p1.tar
chown -R freeit:freeit fraud_prevention_suite.php lib/Analytics/
echo === lint ===; /usr/local/bin/php -l fraud_prevention_suite.php
echo === phpstan ===; /usr/local/bin/php vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3
echo === psalm ===; /usr/local/bin/php vendor/bin/psalm --no-cache --no-progress --show-info=false 2>&1 | tail -3'"
```

Expected: all 3 quality gates exit 0.

**Step 3: Trigger activate + verify**

Create `/tmp/fps_p1_check.php`:

```php
<?php
chdir("/home/freeit/public_html");
define("WHMCS", true);
require "/home/freeit/public_html/init.php";
require_once "/home/freeit/public_html/modules/addons/fraud_prevention_suite/fraud_prevention_suite.php";
use WHMCS\Database\Capsule;
$result = fraud_prevention_suite_activate();
echo "activate: " . ($result['status'] ?? '?') . "\n";
foreach (['enable_client_analytics','enable_admin_analytics','enable_server_events',
          'ga4_measurement_id_client','clarity_project_id_client',
          'analytics_eea_consent_required','analytics_event_sampling_rate'] as $k) {
    $v = Capsule::table('mod_fps_settings')->where('setting_key', $k)->value('setting_value');
    echo str_pad($k, 36) . " = " . var_export($v, true) . "\n";
}
foreach (['mod_fps_analytics_log','mod_fps_analytics_anomalies'] as $t) {
    echo str_pad("table $t exists", 36) . " = " . (Capsule::schema()->hasTable($t) ? 'YES' : 'NO') . "\n";
}
```

Sync + run: `scp ... && ssh ... '/usr/local/bin/php /tmp/fps_p1_check.php'`

Expected output: `activate: success`, all 7 keys with their default values, both tables = YES.

**Step 4: Commit (no code change, just deployment evidence)**

Skip — Phase 1 commits are already separate. Move to Phase 2.

---

## Phase 2 — Server-side events

### Task 5: Create FpsAnalyticsLog (event log + queries)

**Files:**
- Create: `lib/Analytics/FpsAnalyticsLog.php`

**Step 1: Implement**

```php
<?php
/**
 * FpsAnalyticsLog -- 30-day rolling event log for the analytics
 * status widget + anomaly detector. Inserts are best-effort
 * (logging failures must never break a real request); reads are
 * cheap (indexed on event_name + created_at).
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

final class FpsAnalyticsLog
{
    public const DEST_GA4_CLIENT = 'ga4_client';
    public const DEST_GA4_SERVER = 'ga4_server';
    public const DEST_CLARITY    = 'clarity';

    public static function record(string $eventName, array $payload, string $destination, string $status, ?string $error = null): void
    {
        try {
            Capsule::table('mod_fps_analytics_log')->insert([
                'event_name'   => substr($eventName, 0, 50),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'destination'  => $destination,
                'status'       => $status,
                'error'        => $error !== null ? substr($error, 0, 65535) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Count events with given name in the last 24h. */
    public static function countEventsToday(string $eventName): int
    {
        try {
            return (int) Capsule::table('mod_fps_analytics_log')
                ->where('event_name', $eventName)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->count();
        } catch (\Throwable $e) { return 0; }
    }

    /** Median per-day count over the last $days days, excluding today. */
    public static function medianDailyCount(string $eventName, int $days = 14): int
    {
        try {
            $rows = Capsule::table('mod_fps_analytics_log')
                ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                ->where('event_name', $eventName)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d 00:00:00', time() - ($days + 1) * 86400))
                ->where('created_at', '<', date('Y-m-d 00:00:00'))
                ->groupBy('d')
                ->pluck('c')
                ->toArray();
            if ($rows === []) return 0;
            sort($rows);
            return (int) $rows[(int) (count($rows) / 2)];
        } catch (\Throwable $e) { return 0; }
    }

    /** Last successful POST timestamp + 24h-count, by destination. Returns ['ts'=>?,'count'=>int]. */
    public static function statusSnapshot(string $destination): array
    {
        $out = ['ts' => null, 'count' => 0];
        try {
            $row = Capsule::table('mod_fps_analytics_log')
                ->where('destination', $destination)
                ->where('status', 'sent')
                ->orderByDesc('created_at')
                ->first(['created_at']);
            if ($row) $out['ts'] = $row->created_at;
            $out['count'] = (int) Capsule::table('mod_fps_analytics_log')
                ->where('destination', $destination)
                ->where('status', 'sent')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->count();
        } catch (\Throwable $e) { /* keep defaults */ }
        return $out;
    }

    /** Rolling TTL purge -- called from the daily cron. */
    public static function purgeOlderThan(int $days = 30): int
    {
        try {
            return (int) Capsule::table('mod_fps_analytics_log')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
                ->delete();
        } catch (\Throwable $e) { return 0; }
    }
}
```

**Step 2: Lint + phpstan + psalm**

Run: `php -l lib/Analytics/FpsAnalyticsLog.php && vendor/bin/phpstan analyse --no-progress lib/Analytics/FpsAnalyticsLog.php && vendor/bin/psalm --no-cache --no-progress lib/Analytics/FpsAnalyticsLog.php`

Expected: 0 0 0.

**Step 3: Commit**

```bash
git add lib/Analytics/FpsAnalyticsLog.php
git commit -m 'feat(analytics): FpsAnalyticsLog (30-day rolling event log + queries)'
```

---

### Task 6: Create FpsAnalyticsServerEvents (Measurement Protocol batcher)

**Files:**
- Create: `lib/Analytics/FpsAnalyticsServerEvents.php`

**Step 1: Implement**

```php
<?php
/**
 * FpsAnalyticsServerEvents -- queues + flushes GA4 Measurement Protocol
 * events. Fire-and-log (never throws); honours the enable_server_events
 * master toggle and the analytics_event_sampling_rate setting.
 *
 * 12 known event names live in self::EVENTS as constants for lint-time
 * typo protection.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';
require_once __DIR__ . '/FpsAnalyticsLog.php';

final class FpsAnalyticsServerEvents
{
    public const EVENTS = [
        'pre_checkout_block', 'pre_checkout_allow',
        'turnstile_fail', 'turnstile_pass',
        'high_risk_signup', 'global_intel_hit',
        'geo_impossibility_score', 'velocity_block',
        'admin_review_action', 'api_request',
        'bot_purge', 'module_health',
    ];

    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /** @var array<int, array{name:string, params:array<string,mixed>, client_id:string}> */
    private static array $queue = [];

    /**
     * Queue a server-side event. Call site does NOT need to await -- the
     * queue auto-flushes on shutdown via register_shutdown_function.
     *
     * @param string               $name   One of self::EVENTS, will be prefixed fps_
     * @param array<string, mixed> $params Custom event parameters (max 25)
     * @param string               $cid    Pseudo-client-id (random per request OR FPS check_id)
     */
    public static function send(string $name, array $params = [], string $cid = ''): void
    {
        if (!FpsAnalyticsConfig::isServerEnabled()) return;

        // Sampling
        $rate = (int) FpsAnalyticsConfig::get('analytics_event_sampling_rate', '100');
        if ($rate < 100 && random_int(1, 100) > $rate) return;

        $params['module_version'] = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
        $params['instance_id']    = self::instanceId();

        self::$queue[] = [
            'name'      => 'fps_' . $name,
            'params'    => $params,
            'client_id' => $cid !== '' ? $cid : self::generateCid(),
        ];

        FpsAnalyticsLog::record('fps_' . $name, $params, FpsAnalyticsLog::DEST_GA4_SERVER, 'queued');

        // Auto-flush at shutdown (registered once)
        static $registered = false;
        if (!$registered) { $registered = true; register_shutdown_function([self::class, 'flush']); }
    }

    /** Flush the queue (called automatically; can be called manually for tests). */
    public static function flush(): void
    {
        if (self::$queue === []) return;

        $measurementId = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        $apiSecret     = FpsAnalyticsConfig::get('ga4_api_secret', '');
        if ($measurementId === '' || $apiSecret === '') {
            self::$queue = [];
            return;
        }

        // Group by client_id (Measurement Protocol allows multiple events per
        // POST but they share the same client_id).
        $byCid = [];
        foreach (self::$queue as $e) { $byCid[$e['client_id']][] = ['name' => $e['name'], 'params' => $e['params']]; }
        self::$queue = [];

        foreach ($byCid as $cid => $events) {
            // Cap at 25 events per request (GA4 limit)
            foreach (array_chunk($events, 25) as $chunk) {
                $payload = json_encode([
                    'client_id' => $cid,
                    'events'    => $chunk,
                ]);
                $url = self::ENDPOINT . '?measurement_id=' . urlencode($measurementId)
                    . '&api_secret=' . urlencode($apiSecret);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 2,
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                $status = ($code >= 200 && $code < 300) ? 'sent' : 'failed';
                foreach ($chunk as $event) {
                    FpsAnalyticsLog::record($event['name'], $event['params'], FpsAnalyticsLog::DEST_GA4_SERVER, $status,
                        $status === 'failed' ? "HTTP $code: " . substr((string) ($err ?: $resp), 0, 200) : null);
                }
            }
        }
    }

    private static function generateCid(): string
    {
        return bin2hex(random_bytes(8)) . '.' . time();
    }

    private static function instanceId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? gethostname();
        return substr(sha1((string) $host), 0, 12);
    }
}
```

**Step 2: Lint + static analysis**

Run: `php -l lib/Analytics/FpsAnalyticsServerEvents.php && vendor/bin/phpstan analyse --no-progress lib/Analytics/FpsAnalyticsServerEvents.php && vendor/bin/psalm --no-cache --no-progress lib/Analytics/FpsAnalyticsServerEvents.php`

Expected: clean.

**Step 3: Add to require_once chain in main file**

Modify `fraud_prevention_suite.php` near the existing `require_once __DIR__ . '/lib/Analytics/FpsAnalyticsConfig.php';`:

```php
require_once __DIR__ . '/lib/Analytics/FpsAnalyticsConfig.php';
require_once __DIR__ . '/lib/Analytics/FpsAnalyticsLog.php';
require_once __DIR__ . '/lib/Analytics/FpsAnalyticsServerEvents.php';
```

**Step 4: Add stub for psalm (cross-file static method calls)**

Modify `phpstan-stubs/fps-globals.php` — append at the end:

```php
/**
 * @phpstan-ignore-next-line  -- only purpose is to make the cross-file
 * call site readable to psalm; runtime impl in lib/Analytics/.
 */
class FpsAnalyticsConfig {
    public static function get(string $key, string $default = ''): string { return $default; }
    public static function isClientEnabled(): bool { return false; }
    public static function isAdminEnabled(): bool { return false; }
    public static function isServerEnabled(): bool { return false; }
    public static function isValidGa4Id(string $id): bool { return true; }
    public static function isValidClarityId(string $id): bool { return true; }
    public static function isValidServiceAccountJson(string $json): bool { return true; }
    public static function clearCache(): void {}
    public const KEYS = [];
}
class FpsAnalyticsLog {
    public const DEST_GA4_CLIENT = 'ga4_client';
    public const DEST_GA4_SERVER = 'ga4_server';
    public const DEST_CLARITY    = 'clarity';
    public static function record(string $eventName, array $payload, string $destination, string $status, ?string $error = null): void {}
    public static function countEventsToday(string $eventName): int { return 0; }
    public static function medianDailyCount(string $eventName, int $days = 14): int { return 0; }
    public static function statusSnapshot(string $destination): array { return ['ts' => null, 'count' => 0]; }
    public static function purgeOlderThan(int $days = 30): int { return 0; }
}
class FpsAnalyticsServerEvents {
    public const EVENTS = [];
    public static function send(string $name, array $params = [], string $cid = ''): void {}
    public static function flush(): void {}
}
```

**Step 5: Lint + commit**

Run: `php -l fraud_prevention_suite.php phpstan-stubs/fps-globals.php`

```bash
git add lib/Analytics/FpsAnalyticsServerEvents.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php
git commit -m 'feat(analytics): FpsAnalyticsServerEvents (Measurement Protocol batcher) + stubs'
```

---

### Task 7: Wire 4 highest-value events into call sites

**Files:**
- Modify: `lib/FpsCheckRunner.php` — in `runPreCheckoutFast()` after `$this->stats->recordCheck($result)` line, fire `pre_checkout_block` or `pre_checkout_allow`
- Modify: `hooks.php` — in the Turnstile validation path (currently logs `Turnstile FAILED at checkout`), fire `turnstile_fail`; in the success branch fire `turnstile_pass`
- Modify: `hooks.php` — in `ClientAdd` hook handler, after the FPS scoring runs, fire `high_risk_signup` if score >= 80

**Step 1: Wire pre_checkout_* in FpsCheckRunner**

Locate the line `$this->stats->recordCheck($result);` inside `runPreCheckoutFast()` and append immediately after:

```php
// Server-side analytics event (TODO-hardening analytics design).
// Fire-and-forget: gated by enable_server_events; auto-flushes at shutdown.
if (class_exists('FpsAnalyticsServerEvents')) {
    $eventName = ($result->risk->score >= ((float) FpsHookHelpers::fps_resolvePreCheckoutThresholds()['block'])) ? 'pre_checkout_block' : 'pre_checkout_allow';
    FpsAnalyticsServerEvents::send($eventName, [
        'risk_score'  => round((float) $result->risk->score, 2),
        'risk_level'  => $result->risk->level,
        'country'     => $context->country,
        'gateway'     => '',
        'duration_ms' => (int) round($result->executionMs),
        'providers'   => implode(',', array_keys((array) $result->risk->providerScores)),
    ], 'check_' . $result->checkId);
}
```

**Step 2: Wire turnstile_* in hooks.php**

Find the Turnstile validation block (`logActivity("Fraud Prevention: Turnstile FAILED at checkout"`). Below the `logActivity` call, before the `return [...]`:

```php
if (class_exists('FpsAnalyticsServerEvents')) {
    FpsAnalyticsServerEvents::send('turnstile_fail', [
        'country'      => $country,
        'error_codes'  => implode(',', $tsResult['error_codes'] ?? []),
        'ip_country'   => $country,
    ]);
}
```

Find the matching success branch (no early return, `$tsResult['success']` is true) and add:

```php
if (class_exists('FpsAnalyticsServerEvents')) {
    FpsAnalyticsServerEvents::send('turnstile_pass', ['country' => $country]);
}
```

**Step 3: Wire high_risk_signup in ClientAdd hook**

Find the `add_hook('ClientAdd', ...)` block. After the FPS check runs and we have a $riskScore, add:

```php
if (($riskScore ?? 0) >= 80 && class_exists('FpsAnalyticsServerEvents')) {
    FpsAnalyticsServerEvents::send('high_risk_signup', [
        'risk_score'   => $riskScore,
        'country'      => $vars['country'] ?? '',
        'email_domain' => substr(strrchr((string) ($vars['email'] ?? ''), '@') ?: '', 1),
    ]);
}
```

**Step 4: Lint + phpstan + psalm + commit**

Run:
```bash
for f in lib/FpsCheckRunner.php hooks.php; do php -l $f; done
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-cache --no-progress --show-info=false
```

Expected: all clean.

```bash
git add lib/FpsCheckRunner.php hooks.php
git commit -m 'feat(analytics): wire pre_checkout_block/allow + turnstile_pass/fail + high_risk_signup events'
```

---

### Task 8: Synthetic functional check on dev — server events fire + log

**Files:** Create temporary `/tmp/fps_p2_check.php`

**Step 1: Sync Phase 2 changes to dev**

```bash
cd "D:/Claude workfolder/fraud_prevention_suite"
tar -cf /tmp/fps-analytics-p2.tar lib/Analytics/ lib/FpsCheckRunner.php hooks.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php
scp -i ~/.ssh/id_ed25519_gpu_ci /tmp/fps-analytics-p2.tar root@130.12.69.6:/tmp/
ssh -i ~/.ssh/id_ed25519_gpu_ci root@130.12.69.6 "scp -o StrictHostKeyChecking=no -i /root/.ssh/evdps_server_ed25519 /tmp/fps-analytics-p2.tar root@130.12.69.7:/tmp/
ssh -o StrictHostKeyChecking=no -i /root/.ssh/evdps_server_ed25519 root@130.12.69.7 'cd /home/freeit/public_html/modules/addons/fraud_prevention_suite && tar -xf /tmp/fps-analytics-p2.tar && chown -R freeit:freeit lib/ hooks.php fraud_prevention_suite.php phpstan-stubs/'"
```

**Step 2: Run synthetic check** (without real GA4 — just verify queue/log + that disabled flag respected)

Create script:
```php
<?php
chdir("/home/freeit/public_html");
define("WHMCS", true);
require "/home/freeit/public_html/init.php";
require_once "/home/freeit/public_html/modules/addons/fraud_prevention_suite/fraud_prevention_suite.php";
use FraudPreventionSuite\Lib\FpsCheckRunner;
use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use WHMCS\Database\Capsule;

// 1. With server events DISABLED (default), no log row should be created
Capsule::table('mod_fps_settings')->where('setting_key','enable_server_events')->update(['setting_value' => '0']);
$preCount = (int) Capsule::table('mod_fps_analytics_log')->count();
FpsAnalyticsServerEvents::send('pre_checkout_block', ['risk_score' => 90, 'country' => 'US']);
FpsAnalyticsServerEvents::flush();
$postCountDisabled = (int) Capsule::table('mod_fps_analytics_log')->count();
echo "DISABLED: log unchanged = " . ($postCountDisabled === $preCount ? "PASS" : "FAIL ($preCount->$postCountDisabled)") . "\n";

// 2. With server events ENABLED, log row created (status will be 'failed' because no real GA4 secret, but row exists)
Capsule::table('mod_fps_settings')->where('setting_key','enable_server_events')->update(['setting_value' => '1']);
Capsule::table('mod_fps_settings')->where('setting_key','ga4_measurement_id_client')->update(['setting_value' => 'G-TESTONLY']);
Capsule::table('mod_fps_settings')->where('setting_key','ga4_api_secret')->update(['setting_value' => 'fake-secret']);
FpsAnalyticsConfig::clearCache();
$preCount = (int) Capsule::table('mod_fps_analytics_log')->count();
FpsAnalyticsServerEvents::send('pre_checkout_block', ['risk_score' => 90, 'country' => 'US']);
FpsAnalyticsServerEvents::flush();
$postCountEnabled = (int) Capsule::table('mod_fps_analytics_log')->count();
echo "ENABLED: log row added = " . ($postCountEnabled > $preCount ? "PASS" : "FAIL ($preCount->$postCountEnabled)") . "\n";

// 3. Restore: disable server events; clear test settings
Capsule::table('mod_fps_settings')->where('setting_key','enable_server_events')->update(['setting_value' => '0']);
Capsule::table('mod_fps_settings')->where('setting_key','ga4_measurement_id_client')->update(['setting_value' => '']);
Capsule::table('mod_fps_settings')->where('setting_key','ga4_api_secret')->update(['setting_value' => '']);
echo "Restored.\n";
```

Run: `scp ... && ssh ... '/usr/local/bin/php /tmp/fps_p2_check.php'`

Expected: `DISABLED: PASS`, `ENABLED: PASS`, `Restored.`

**Step 3: No commit (verification only — no code change since Task 7's commit)**

---

## Phase 3 — Client-side injection

### Task 9: Create FpsAnalyticsConsentManager (EEA detection)

**Files:**
- Create: `lib/Analytics/FpsAnalyticsConsentManager.php`

**Step 1: Implement**

```php
<?php
/**
 * FpsAnalyticsConsentManager -- decides whether to show the EEA cookie
 * banner and tracks the resulting consent state.
 *
 * EEA list cached as a const here; if the visitor's IP-derived country
 * is in the list AND analytics_eea_consent_required = '1', the banner
 * is rendered and Consent Mode v2 default-deny is set.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';

final class FpsAnalyticsConsentManager
{
    /** EU + EEA + UK + Switzerland (Microsoft made consent mandatory for these in 2025). */
    private const EEA_COUNTRIES = [
        'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE',
        'IS','IT','LI','LT','LU','LV','MT','NL','NO','PL','PT','RO','SE','SI','SK',
        'GB','CH',
    ];

    public static function isEeaVisitor(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::EEA_COUNTRIES, true);
    }

    public static function shouldShowBanner(string $country): bool
    {
        if (FpsAnalyticsConfig::get('analytics_eea_consent_required', '1') !== '1') {
            return true; // operator chose: show banner for everyone
        }
        return self::isEeaVisitor($country);
    }

    /** Read the user's previously-stored consent decision from the cookie. */
    public static function readConsent(): ?bool
    {
        if (!isset($_COOKIE['fps_consent'])) return null;
        return $_COOKIE['fps_consent'] === '1';
    }
}
```

**Step 2: Lint + static analysis**

Run: `php -l lib/Analytics/FpsAnalyticsConsentManager.php && vendor/bin/phpstan analyse --no-progress lib/Analytics/FpsAnalyticsConsentManager.php && vendor/bin/psalm --no-cache --no-progress lib/Analytics/FpsAnalyticsConsentManager.php`

Expected: clean.

**Step 3: Add stub + require_once + commit**

Append to `phpstan-stubs/fps-globals.php`:

```php
class FpsAnalyticsConsentManager {
    public static function isEeaVisitor(string $country): bool { return false; }
    public static function shouldShowBanner(string $country): bool { return false; }
    public static function readConsent(): ?bool { return null; }
}
```

Add `require_once __DIR__ . '/lib/Analytics/FpsAnalyticsConsentManager.php';` to main file.

```bash
git add lib/Analytics/FpsAnalyticsConsentManager.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php
git commit -m 'feat(analytics): FpsAnalyticsConsentManager (EEA detection)'
```

---

### Task 10: Create FpsAnalyticsInjector (script tag builders)

**Files:**
- Create: `lib/Analytics/FpsAnalyticsInjector.php`

**Step 1: Implement**

This is a builder of pure HTML strings — no side effects. The injector returns nothing if the relevant toggle is off OR the relevant ID is empty.

```php
<?php
/**
 * FpsAnalyticsInjector -- builds the <script> blocks for client-side and
 * admin-side analytics. Pure string output, no side effects. Caller
 * (hooks.php) decides where to echo the result.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';
require_once __DIR__ . '/FpsAnalyticsConsentManager.php';

final class FpsAnalyticsInjector
{
    /**
     * Client-side script block. Returns '' when disabled or no IDs configured.
     *
     * @param array<string, mixed> $context Optional FPS user_properties to attach
     *                                      (e.g. ['fps_country' => 'US', 'fps_trust_score' => 0.9])
     */
    public static function client(string $visitorCountry, array $context = []): string
    {
        if (!FpsAnalyticsConfig::isClientEnabled()) return '';

        $ga4 = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        $clarity = FpsAnalyticsConfig::get('clarity_project_id_client', '');
        if ($ga4 === '' && $clarity === '') return '';

        $showBanner = FpsAnalyticsConsentManager::shouldShowBanner($visitorCountry);
        $userProps  = self::userPropertiesScript($context);
        $bannerHtml = $showBanner ? self::bannerHtml() : '';

        $out = "<!-- FPS analytics (client) -->\n";
        // Consent Mode v2 default-deny stub MUST come first
        $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
        $out .= "gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});\n";
        $out .= "</script>\n";

        if ($ga4 !== '' && self::idValid($ga4, 'ga4')) {
            $idEsc = htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8');
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$idEsc}\"></script>\n";
            $out .= "<script>gtag('js', new Date());\n";
            $out .= "gtag('config','{$idEsc}',{anonymize_ip:true,send_page_view:true});\n";
            $out .= $userProps;
            $out .= "</script>\n";
        }

        if ($clarity !== '' && self::idValid($clarity, 'clarity')) {
            $idEsc = htmlspecialchars($clarity, ENT_QUOTES, 'UTF-8');
            $out .= "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,\"clarity\",\"script\",\"{$idEsc}\");\n";
            // Attach FPS context as Clarity custom tags
            foreach ($context as $k => $v) {
                if (!is_scalar($v)) continue;
                $kEsc = htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8');
                $vEsc = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $out .= "clarity('set','{$kEsc}','{$vEsc}');\n";
            }
            $out .= "</script>\n";
        }

        $out .= $bannerHtml;
        return $out;
    }

    public static function admin(string $adminId = '', string $adminRole = ''): string
    {
        if (!FpsAnalyticsConfig::isAdminEnabled()) return '';

        $ga4 = FpsAnalyticsConfig::get('ga4_measurement_id_admin', '');
        if ($ga4 === '') $ga4 = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        $clarity = FpsAnalyticsConfig::get('clarity_project_id_admin', '');
        if ($clarity === '') $clarity = FpsAnalyticsConfig::get('clarity_project_id_client', '');
        if ($ga4 === '' && $clarity === '') return '';

        $modVer = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
        $userProps = self::userPropertiesScript([
            'fps_admin_id'       => $adminId,
            'fps_admin_role'     => $adminRole,
            'fps_module_version' => $modVer,
        ]);

        $out = "<!-- FPS analytics (admin) -->\n";
        if ($ga4 !== '' && self::idValid($ga4, 'ga4')) {
            $idEsc = htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8');
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$idEsc}\"></script>\n";
            $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
            $out .= "gtag('js',new Date());gtag('config','{$idEsc}',{anonymize_ip:true,send_page_view:true});\n";
            $out .= $userProps;
            $out .= "</script>\n";
        }

        if ($clarity !== '' && self::idValid($clarity, 'clarity')) {
            $idEsc = htmlspecialchars($clarity, ENT_QUOTES, 'UTF-8');
            $out .= "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,\"clarity\",\"script\",\"{$idEsc}\");\n";
            $out .= "clarity('identify','admin_" . htmlspecialchars($adminId, ENT_QUOTES, 'UTF-8') . "');\n";
            $out .= "</script>\n";
        }

        return $out;
    }

    private static function userPropertiesScript(array $context): string
    {
        if ($context === []) return '';
        $sanitized = [];
        foreach ($context as $k => $v) {
            if (!is_scalar($v)) continue;
            $sanitized[(string) $k] = is_bool($v) ? ($v ? 1 : 0) : $v;
        }
        return "gtag('set','user_properties'," . json_encode($sanitized, JSON_UNESCAPED_SLASHES) . ");\n";
    }

    private static function bannerHtml(): string
    {
        // Banner JS lives in assets/js/fps-consent-banner.js and is loaded by the
        // existing fps-fingerprint.js loader path. We only need to inject the
        // container <div> here -- the JS finds it on DOMContentLoaded.
        return "<div id=\"fps-consent-banner\" data-active=\"1\" hidden></div>\n";
    }

    private static function idValid(string $id, string $kind): bool
    {
        return $kind === 'ga4'
            ? FpsAnalyticsConfig::isValidGa4Id($id)
            : FpsAnalyticsConfig::isValidClarityId($id);
    }
}
```

**Step 2: Lint + static analysis**

Run: `php -l lib/Analytics/FpsAnalyticsInjector.php && vendor/bin/phpstan analyse --no-progress lib/Analytics/FpsAnalyticsInjector.php && vendor/bin/psalm --no-cache --no-progress lib/Analytics/FpsAnalyticsInjector.php`

Expected: clean.

**Step 3: Add stub + require_once + commit**

Append to `phpstan-stubs/fps-globals.php`:

```php
class FpsAnalyticsInjector {
    public static function client(string $visitorCountry, array $context = []): string { return ''; }
    public static function admin(string $adminId = '', string $adminRole = ''): string { return ''; }
}
```

Add `require_once` for new file.

```bash
git add lib/Analytics/FpsAnalyticsInjector.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php
git commit -m 'feat(analytics): FpsAnalyticsInjector (gtag + clarity script tag builders)'
```

---

### Task 11: Build the consent banner (vanilla JS) — **DISPATCH `frontend-design` SUBAGENT**

**Files:**
- Create: `assets/js/fps-consent-banner.js` (~80 lines)
- Create: `assets/js/fps-analytics-debug.js` (~30 lines)

**Step 1: Dispatch the frontend-design subagent**

Prompt to give the subagent:

> Task: build a Consent Mode v2 cookie consent banner for an existing WHMCS module (Fraud Prevention Suite v4.2.5). Vanilla JS only — no React, no jQuery dependency, no build step.
>
> Behaviour:
> - On `DOMContentLoaded`, check if `<div id="fps-consent-banner" data-active="1">` exists. If not, exit silently.
> - If `localStorage.fps_consent === '1'` or the cookie `fps_consent=1` is set, exit silently (user previously accepted).
> - Otherwise: render a fixed-bottom banner with "We use Google Analytics & Microsoft Clarity to improve our service. [Accept] [Decline] [Privacy policy ↗]". Style: matches the existing FPS site theme (read CSS variables `--fps-bg-card`, `--fps-text-primary`, `--fps-accent` if present; otherwise dark `rgb(20,22,26)` bg + white text + accent `#3a82f7`).
> - On Accept: `gtag('consent','update',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted'})`; if `window.clarity` exists call `clarity('consent')`; set `localStorage.fps_consent='1'` + `document.cookie='fps_consent=1;Max-Age=31536000;path=/;SameSite=Lax'`; remove banner.
> - On Decline: keep default-deny (no gtag/clarity calls); set `localStorage.fps_consent='0'` + `fps_consent=0` cookie; remove banner.
> - Privacy policy link: read `data-privacy-url` attribute on the container; default to `/privacypolicy.php`.
>
> Quality bar:
> - One file, no imports, ≤ 100 lines.
> - Accessible: button focus rings, `role="dialog"`, `aria-label`, Esc key to dismiss (counts as Decline).
> - No external font loads.
> - Works on IE11? No — modern browsers only (matches WHMCS 8.x baseline).
>
> Also produce a tiny `fps-analytics-debug.js` (~30 lines) that:
> - Only runs when `window.location.search.includes('fps_analytics_debug=1')`.
> - Wraps `gtag` and `clarity` to also log every call to `console.table` and to a hidden `<div id="fps-debug-events"></div>` it appends to `<body>`.
>
> Return both files as plain content. Do not commit — caller will commit.

**Step 2: Save the returned files to disk**

Place the subagent's output at `assets/js/fps-consent-banner.js` and `assets/js/fps-analytics-debug.js`.

**Step 3: Smoke test the JS**

Run: `node --check assets/js/fps-consent-banner.js && node --check assets/js/fps-analytics-debug.js`

Expected: no syntax errors.

**Step 4: Commit**

```bash
git add assets/js/fps-consent-banner.js assets/js/fps-analytics-debug.js
git commit -m 'feat(analytics): consent banner + analytics debug overlay (vanilla JS)'
```

---

### Task 12: Wire client-side injection into ClientAreaHeaderOutput

**Files:**
- Modify: `hooks.php` — extend the existing `ClientAreaHeaderOutput` block

**Step 1: Locate the existing block**

`grep -n "ClientAreaHeaderOutput" hooks.php` — find the existing `add_hook('ClientAreaHeaderOutput', 1, function ($vars) {` block.

**Step 2: Append the analytics injection inside it (just before the closing `});`)**

```php
    // ---------- FPS Analytics (client) ----------
    if (class_exists('FpsAnalyticsInjector') && FpsAnalyticsConfig::isClientEnabled()) {
        try {
            // Resolve visitor country (FPS already does this via IP intel).
            $visitorCountry = '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($ip !== '' && class_exists('\\FraudPreventionSuite\\Lib\\FpsHookHelpers')) {
                $visitorCountry = (string) (\FraudPreventionSuite\Lib\FpsHookHelpers::fps_lookupCountryByIp($ip) ?? '');
            }
            // Build FPS context for user_properties + Clarity custom tags.
            $context = [
                'fps_country'               => $visitorCountry,
                'fps_module_version'        => defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown',
                'fps_is_returning_client'   => isset($_SESSION['uid']) ? 1 : 0,
            ];
            $modVer = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
            $bannerJsUrl = '/modules/addons/fraud_prevention_suite/assets/js/fps-consent-banner.js?v='
                . urlencode($modVer);
            $debugJsTag  = (isset($_GET['fps_analytics_debug']) && $_GET['fps_analytics_debug'] === '1')
                ? '<script src="/modules/addons/fraud_prevention_suite/assets/js/fps-analytics-debug.js"></script>'
                : '';
            return FpsAnalyticsInjector::client($visitorCountry, $context)
                . "<script defer src=\"{$bannerJsUrl}\"></script>\n"
                . $debugJsTag;
        } catch (\Throwable $e) {
            // Never let analytics break the page
            logModuleCall('fraud_prevention_suite', 'AnalyticsInject::ClientErr', '', $e->getMessage());
        }
    }
```

**Step 3: Add `fps_lookupCountryByIp` helper to FpsHookHelpers if it doesn't exist**

`grep -n "fps_lookupCountryByIp" lib/FpsHookHelpers.php` — if missing, add this method:

```php
/** Resolve country code from IP via the existing mod_fps_ip_intel cache; '' on miss. */
public static function fps_lookupCountryByIp(string $ip): string
{
    if ($ip === '') return '';
    try {
        $row = \WHMCS\Database\Capsule::table('mod_fps_ip_intel')
            ->where('ip_address', $ip)->first(['country']);
        return $row && $row->country ? (string) $row->country : '';
    } catch (\Throwable $e) { return ''; }
}
```

**Step 4: Lint + commit**

```bash
git add hooks.php lib/FpsHookHelpers.php
git commit -m 'feat(analytics): wire client-side injection into ClientAreaHeaderOutput'
```

---

### Task 13: Wire admin-side injection into AdminAreaHeaderOutput

**Files:**
- Modify: `hooks.php` — extend the existing `AdminAreaHeaderOutput` block

**Step 1: Append injection inside the existing hook block**

```php
    // ---------- FPS Analytics (admin) ----------
    if (class_exists('FpsAnalyticsInjector') && FpsAnalyticsConfig::isAdminEnabled()) {
        try {
            $adminId   = (string) ($_SESSION['adminid'] ?? '');
            $adminRole = (string) ($_SESSION['adminrole'] ?? '');
            return FpsAnalyticsInjector::admin($adminId, $adminRole);
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AnalyticsInject::AdminErr', '', $e->getMessage());
        }
    }
```

**Step 2: Lint + commit**

```bash
php -l hooks.php
git add hooks.php
git commit -m 'feat(analytics): wire admin-side injection into AdminAreaHeaderOutput'
```

---

## Phase 4 — Settings card + Dashboard widget

### Task 14: Add "Analytics & Tracking" provider card to TabSettings

**Files:**
- Modify: `lib/Admin/TabSettings.php` — add a new provider entry to the `$providers` array

**Step 1: Append to providers array**

Locate the `$providers` array in `fpsRenderProviderSettings()`. Add after the existing "Pipeline Internals" card:

```php
[
    'key'     => 'analytics_tracking',
    'title'   => 'Analytics & Tracking',
    'icon'    => 'fa-chart-line',
    'fields'  => [
        ['type' => 'info', 'text' => 'Enable Google Analytics 4 + Microsoft Clarity tracking. You must sign Google\'s GA4 DPA + Microsoft\'s Clarity DPA separately. Both default OFF; enable per side after entering credentials.'],
        ['type' => 'toggle', 'name' => 'enable_client_analytics', 'label' => 'Enable client-side tracking (storefront + cart)'],
        ['type' => 'toggle', 'name' => 'enable_admin_analytics',  'label' => 'Enable admin-side tracking (FPS admin tabs)'],
        ['type' => 'toggle', 'name' => 'enable_server_events',    'label' => 'Enable server-side custom events (Measurement Protocol)'],
        ['type' => 'text',   'name' => 'ga4_measurement_id_client', 'label' => 'GA4 measurement ID (client)', 'placeholder' => 'G-XXXXXXXXXX'],
        ['type' => 'text',   'name' => 'ga4_measurement_id_admin',  'label' => 'GA4 measurement ID (admin, optional)', 'placeholder' => 'falls back to client ID if blank'],
        ['type' => 'text',   'name' => 'ga4_api_secret', 'label' => 'GA4 Measurement Protocol secret', 'placeholder' => '(required for server-side events)'],
        ['type' => 'textarea','name' => 'ga4_service_account_json', 'label' => 'GA4 Data API service account JSON (optional)', 'placeholder' => 'Paste service-account JSON; enables yesterday-count widget'],
        ['type' => 'text',   'name' => 'clarity_project_id_client', 'label' => 'Clarity project ID (client)', 'placeholder' => '10-char alphanumeric'],
        ['type' => 'text',   'name' => 'clarity_project_id_admin',  'label' => 'Clarity project ID (admin, optional)'],
        ['type' => 'toggle', 'name' => 'analytics_eea_consent_required', 'label' => 'Show consent banner only to EEA visitors (recommended); off = banner for everyone'],
        ['type' => 'text',   'name' => 'analytics_event_sampling_rate', 'label' => 'Server event sampling rate (1-100, default 100)', 'placeholder' => '100'],
    ],
],
```

**Step 2: Add textarea support to the existing fpsRenderSettingField if it doesn't exist**

`grep -n "case 'textarea'" lib/Admin/TabSettings.php` — if missing, add to the switch in fpsRenderSettingField:

```php
case 'textarea':
    $val = htmlspecialchars($config->getCustom($field['name'], ''), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($field['name'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8');
    $placeholder = htmlspecialchars($field['placeholder'] ?? '', ENT_QUOTES, 'UTF-8');
    return "<div class=\"fps-form-group\"><label>{$label}</label><textarea name=\"{$name}\" rows=\"6\" class=\"fps-input fps-textarea\" placeholder=\"{$placeholder}\">{$val}</textarea></div>";
```

**Step 3: Lint + commit**

```bash
php -l lib/Admin/TabSettings.php
git add lib/Admin/TabSettings.php
git commit -m 'feat(analytics): Analytics & Tracking provider card in TabSettings'
```

---

### Task 15: Add fpsRenderAnalyticsStatus() to TabDashboard

**Files:**
- Modify: `lib/Admin/TabDashboard.php` — add a new render method + register in `render()`

**Step 1: Register**

In the `render()` method, between `fpsRenderLatencyWidget` and `fpsRenderManualCheckForm`:

```php
$this->fpsRenderAnalyticsStatus($modulelink);
```

**Step 2: Implement**

Add method:

```php
private function fpsRenderAnalyticsStatus(string $modulelink): void
{
    if (!class_exists('FpsAnalyticsConfig') || !class_exists('FpsAnalyticsLog')) return;

    $clientOn = FpsAnalyticsConfig::isClientEnabled();
    $adminOn  = FpsAnalyticsConfig::isAdminEnabled();
    $serverOn = FpsAnalyticsConfig::isServerEnabled();

    if (!$clientOn && !$adminOn && !$serverOn) return; // nothing configured -- omit widget

    $ga4Status     = FpsAnalyticsLog::statusSnapshot(FpsAnalyticsLog::DEST_GA4_SERVER);
    $clarityStatus = FpsAnalyticsLog::statusSnapshot(FpsAnalyticsLog::DEST_CLARITY);

    $dot = function (bool $configured, ?string $lastTs): string {
        if (!$configured) return '<span style="color:#888;">⚪</span>';
        if ($lastTs === null) return '<span style="color:#f59e0b;">🟡</span>';
        $age = time() - strtotime($lastTs);
        return $age < 3600 ? '<span style="color:#16a34a;">🟢</span>' : '<span style="color:#ef4444;">🔴</span>';
    };

    $body  = '<div class="fps-analytics-status-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">';
    $body .= '  <div><strong>' . $dot($serverOn, $ga4Status['ts']) . ' Google Analytics 4</strong>';
    $body .=      '<div class="fps-text-muted" style="font-size:.8rem;">Last server event: ' . htmlspecialchars($ga4Status['ts'] ?? '—', ENT_QUOTES, 'UTF-8') . ' &middot; '
                . (int) $ga4Status['count'] . ' in last 24h</div>';
    $body .=      '<a href="https://analytics.google.com/" target="_blank" rel="noopener">Open GA4 Realtime ↗</a></div>';
    $body .= '  <div><strong>' . $dot($clientOn, $clarityStatus['ts']) . ' Microsoft Clarity</strong>';
    $body .=      '<div class="fps-text-muted" style="font-size:.8rem;">Sessions tagged via fps_* properties &middot; '
                . (int) $clarityStatus['count'] . ' in last 24h</div>';
    $body .=      '<a href="https://clarity.microsoft.com/" target="_blank" rel="noopener">Open Clarity Dashboard ↗</a></div>';
    $body .= '</div>';

    echo FpsAdminRenderer::renderCard('Analytics Connection Status', 'fa-chart-line', $body);
}
```

**Step 3: Lint + commit**

```bash
php -l lib/Admin/TabDashboard.php
git add lib/Admin/TabDashboard.php
git commit -m 'feat(analytics): Analytics Connection Status widget on Dashboard'
```

---

### Task 16: Sync Phase 3+4 to dev + render check

```bash
cd "D:/Claude workfolder/fraud_prevention_suite"
tar -cf /tmp/fps-analytics-p34.tar lib/Analytics/ lib/Admin/TabSettings.php lib/Admin/TabDashboard.php lib/FpsHookHelpers.php hooks.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php assets/js/fps-consent-banner.js assets/js/fps-analytics-debug.js
scp -i ~/.ssh/id_ed25519_gpu_ci /tmp/fps-analytics-p34.tar root@130.12.69.6:/tmp/
ssh -i ~/.ssh/id_ed25519_gpu_ci root@130.12.69.6 "scp ... && ssh ... 'cd /home/freeit/.../fraud_prevention_suite && tar -xf /tmp/fps-analytics-p34.tar && chown -R freeit:freeit . && /usr/local/bin/php -l hooks.php fraud_prevention_suite.php lib/Admin/TabSettings.php lib/Admin/TabDashboard.php && /usr/local/bin/php vendor/bin/phpstan analyse --no-progress 2>&1 | tail -3 && /usr/local/bin/php vendor/bin/psalm --no-cache --no-progress --show-info=false 2>&1 | tail -3'"
```

Expected: lint clean, phpstan 0, psalm 0.

Reuse `/tmp/fps_tabs_check.php` from prior session and verify "Analytics" appears in the Settings + Dashboard tab outputs.

---

## Phase 5 — Service Account JWT (optional widget data)

### Task 17: Create FpsAnalyticsDataApi

**Files:**
- Create: `lib/Analytics/FpsAnalyticsDataApi.php`

**Step 1: Implement** — single function `getYesterdayCount(string $eventName): ?int`. Service account JWT signing with `openssl_sign`, exchange for OAuth bearer, single GA4 Data API call, 6-hour cache via a transient row in `mod_fps_settings` (`analytics_widget_cache_<event>`).

(Implementation ~120 lines; standard JWT-bearer flow. Defer detail to subagent task.)

**Step 2: Dispatch `php-pro` subagent for the implementation**

Prompt:

> Build `lib/Analytics/FpsAnalyticsDataApi.php` for an existing WHMCS module. Single public method: `static function getYesterdayCount(string $eventName): ?int`. Reads service account JSON from `FpsAnalyticsConfig::get('ga4_service_account_json')` and the GA4 property ID derived from the measurement ID (use the GA4 Admin API call once + cache the property ID separately, OR require operators to enter the property ID directly — pick the simpler path). Mints a JWT signed with the SA's `private_key` using `openssl_sign` + RS256, exchanges at `https://oauth2.googleapis.com/token`, calls `https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport` with a 1-day window and an `eventName` filter. Caches the result for 6 hours in `mod_fps_settings` under key `analytics_widget_cache_<event>`. Returns null on any failure (graceful degradation). All errors logged via logModuleCall, never thrown.
>
> Quality bar: phpstan level 3 + psalm level 6 clean against the existing baselines. Stays in global namespace (no PSR-4 class wrapper). Uses the existing `Capsule` import pattern. Add a stub entry for the public method to `phpstan-stubs/fps-globals.php`.

**Step 3: Wire into dashboard widget**

In `fpsRenderAnalyticsStatus()`, if `FpsAnalyticsConfig::get('ga4_service_account_json') !== ''`, call `FpsAnalyticsDataApi::getYesterdayCount('fps_pre_checkout_block')` and append a "Yesterday: N pre-checkout blocks" line to the GA4 column.

**Step 4: Lint + commit**

```bash
git add lib/Analytics/FpsAnalyticsDataApi.php phpstan-stubs/fps-globals.php lib/Admin/TabDashboard.php fraud_prevention_suite.php
git commit -m 'feat(analytics): GA4 Data API single-event yesterday-count via Service Account JWT'
```

---

## Phase 6 — Anomaly detection + GDPR + cron

### Task 18: Create FpsAnalyticsAnomalyDetector

**Files:**
- Create: `lib/Analytics/FpsAnalyticsAnomalyDetector.php`

**Step 1: Implement**

```php
<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsLog.php';

final class FpsAnalyticsAnomalyDetector
{
    /** Run the daily check; returns count of anomalies detected. */
    public static function runDaily(): int
    {
        $events = ['fps_pre_checkout_block', 'fps_turnstile_fail', 'fps_high_risk_signup'];
        $detected = 0;
        foreach ($events as $event) {
            $today    = FpsAnalyticsLog::countEventsToday($event);
            $median   = FpsAnalyticsLog::medianDailyCount($event, 14);
            if ($today > $median * 3 && $today > 50) {
                self::record($event, $median, $today);
                self::notify($event, $median, $today);
                $detected++;
            }
        }
        return $detected;
    }

    private static function record(string $event, int $baseline, int $observed): void
    {
        try {
            \WHMCS\Database\Capsule::table('mod_fps_analytics_anomalies')->insert([
                'event_name'     => $event,
                'baseline_count' => $baseline,
                'observed_count' => $observed,
                'detected_at'    => date('Y-m-d H:i:s'),
                'notified_at'    => null,
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    private static function notify(string $event, int $baseline, int $observed): void
    {
        $admin = \WHMCS\Database\Capsule::table('mod_fps_settings')
            ->where('setting_key', 'notification_email')->value('setting_value');
        if (!$admin) return;
        $subject = "[FPS] $event spike detected ($observed vs median $baseline)";
        $body = "Today: $observed events.\n14-day median: $baseline events.\nThreshold: 3x median + min 50.\n\nLog into FPS admin → Dashboard → Analytics Connection Status to investigate.\n";
        if (function_exists('fps_sendMail')) {
            fps_sendMail((string) $admin, $subject, $body);
        }
    }
}
```

**Step 2: Wire into DailyCronJob hook**

In `hooks.php` find `add_hook('DailyCronJob', ...)`. Inside the handler, after the existing logic:

```php
    if (class_exists('FpsAnalyticsAnomalyDetector')) {
        try {
            FpsAnalyticsAnomalyDetector::runDaily();
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'AnalyticsAnomaly::ERROR', '', $e->getMessage());
        }
    }

    // 30-day rolling TTL for analytics log
    if (class_exists('FpsAnalyticsLog')) {
        try { FpsAnalyticsLog::purgeOlderThan(30); } catch (\Throwable $e) {}
    }

    // Daily heartbeat event so we can see in GA4 Realtime that the cron ran
    if (class_exists('FpsAnalyticsServerEvents')) {
        try {
            FpsAnalyticsServerEvents::send('module_health', [
                'module_version'      => defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown',
                'total_checks_24h'    => (int) \WHMCS\Database\Capsule::table('mod_fps_checks')
                                              ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
            ]);
        } catch (\Throwable $e) {}
    }
```

**Step 3: Add stub + require_once + commit**

```bash
git add lib/Analytics/FpsAnalyticsAnomalyDetector.php hooks.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php
git commit -m 'feat(analytics): anomaly detector + DailyCronJob extension (TTL + heartbeat)'
```

---

### Task 19: Extend fps_gdprPurgeByEmail to call Clarity DSR API

**Files:**
- Modify: `lib/Gdpr/FpsGdprHelper.php`

**Step 1: Append a new "vendor" section at the end of fps_gdprPurgeByEmail**

```php
    // 7. Microsoft Clarity DSR -- send delete request via Clarity API.
    //    Note: Clarity's DSR API requires a project access token; we read
    //    the same project IDs the injector uses. Logged result is appended
    //    to the report.tables row.
    try {
        $clarityIds = array_filter([
            \WHMCS\Database\Capsule::table('mod_fps_settings')
                ->where('setting_key','clarity_project_id_client')->value('setting_value'),
            \WHMCS\Database\Capsule::table('mod_fps_settings')
                ->where('setting_key','clarity_project_id_admin')->value('setting_value'),
        ]);
        $clarityToken = \WHMCS\Database\Capsule::table('mod_fps_settings')
            ->where('setting_key','clarity_dsr_token')->value('setting_value');
        $sent = 0;
        foreach (array_unique($clarityIds) as $pid) {
            if (empty($pid) || empty($clarityToken)) continue;
            $ch = curl_init('https://www.clarity.ms/export-data/api/v1/data-subject-requests');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $clarityToken],
                CURLOPT_POSTFIELDS     => json_encode([
                    'projectId' => $pid, 'requestType' => 'delete',
                    'identifier' => $emailHash,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) $sent++;
        }
        $record('clarity_dsr_api', $sent, 0);
    } catch (\Throwable $e) {
        $record('clarity_dsr_api', 0, 0);
    }

    // 8. GA4 -- no API for user deletion exists.
    //    Add a manual-instructions field to the report so the operator
    //    knows to file a request via Google's form.
    $report['manual_followup'] = [
        'ga4_user_deletion' => 'No GA4 API for user deletion exists. File a request at https://support.google.com/analytics/contact/data-deletion (requires the GA4 user ID — see your service account audit log).',
    ];
```

**Step 2: Add new setting `clarity_dsr_token` (concealed) to TabSettings analytics card**

(One-line addition.)

**Step 3: Seed the new setting in fraud_prevention_suite_activate**

Add `'clarity_dsr_token' => '',` to the analytics block in the seeded settings.

**Step 4: Lint + commit**

```bash
php -l lib/Gdpr/FpsGdprHelper.php fraud_prevention_suite.php lib/Admin/TabSettings.php
git add lib/Gdpr/FpsGdprHelper.php fraud_prevention_suite.php lib/Admin/TabSettings.php
git commit -m 'feat(analytics,gdpr): Clarity DSR API call + GA4 manual-instructions in fps_gdprPurgeByEmail'
```

---

## Phase 7 — MCP setup helper + docs

### Task 20: Create scripts/install-mcp-servers.sh

**Files:**
- Create: `scripts/install-mcp-servers.sh`

**Step 1: Implement**

```bash
#!/usr/bin/env bash
set -euo pipefail
# install-mcp-servers.sh -- merges Google Analytics + Microsoft Clarity MCP
# server entries into ~/.claude/settings.json. Backs up the original first.

SETTINGS="${CLAUDE_SETTINGS:-$HOME/.claude/settings.json}"
TS=$(date +%Y%m%d-%H%M%S)
BACKUP="${SETTINGS}.bak.${TS}"

if [[ ! -f "$SETTINGS" ]]; then
    echo "{\"mcpServers\":{}}" > "$SETTINGS"
fi

cp "$SETTINGS" "$BACKUP"
echo "Backed up to $BACKUP"

python3 - <<'PY'
import json, os, sys
path = os.environ.get("CLAUDE_SETTINGS", os.path.expanduser("~/.claude/settings.json"))
with open(path) as f: data = json.load(f)
data.setdefault("mcpServers", {})
data["mcpServers"]["google-analytics"] = {"command": "npx", "args": ["-y", "@google/analytics-mcp-server"]}
data["mcpServers"]["microsoft-clarity"] = {"command": "uvx", "args": ["--from", "git+https://github.com/microsoft/clarity-mcp-server", "clarity-mcp"]}
with open(path, "w") as f: json.dump(data, f, indent=2)
print("Wrote 2 MCP servers to", path)
PY

echo "Now run: claude mcp list"
```

**Step 2: Make executable + commit**

```bash
chmod +x scripts/install-mcp-servers.sh
git add scripts/install-mcp-servers.sh
git commit -m 'feat(analytics): scripts/install-mcp-servers.sh (GA4 + Clarity MCP merge helper)'
```

---

### Task 21: Write Analytics-MCP-Setup.md — **DISPATCH `documentation-expert` SUBAGENT**

**Files:**
- Create: `docs/wiki/Analytics-MCP-Setup.md`
- Modify: `README.md` — add a "What's new in v4.2.5" subsection
- Modify: `CHANGELOG.md` — add the v4.2.5 entry

Prompt the `documentation-expert` subagent with the design doc + this plan as input. Constraints: matches the voice/structure of existing `docs/wiki/*.md` (reference `Provider-Configuration.md` for tone). Cover: what it does, how to enable per-side, where to find your GA4/Clarity IDs, how to wire MCP servers (link to `scripts/install-mcp-servers.sh`), example natural-language queries, GDPR posture.

---

## Phase 8 — Battle test on dev (admin + client)

### Task 22: Static gates

```bash
ssh ... 'cd /home/freeit/.../fraud_prevention_suite
echo === lint ===; find . -name "*.php" -not -path "./vendor/*" -not -path "./phpstan-stubs/*" -not -path "./docs/*" -print0 | xargs -0 -n1 /usr/local/bin/php -l 2>&1 | grep -v "No syntax errors" || echo all_clean
echo === phpstan ===; /usr/local/bin/php vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3
echo === psalm ===; /usr/local/bin/php vendor/bin/psalm --no-cache --no-progress --show-info=false 2>&1 | tail -3'
```

Expected: lint clean, phpstan exit 0, psalm exit 0.

### Task 23: Battle test admin side

Create a real GA4 property + Clarity project for the dev install (or use existing test property), enter IDs in Settings, save. Then verify:

| # | Test | Expected |
|---|------|----------|
| 1 | Reload `/admin/addonmodules.php?module=fraud_prevention_suite` with browser DevTools open | Network tab shows `gtag/js?id=G-...` 200; `clarity.ms/tag/{id}` 200; no console errors |
| 2 | DevTools → Application → Cookies | `_ga`, `_gid`, `_clck` cookies set |
| 3 | Click 5 different FPS tabs (Dashboard, Settings, Bot Cleanup, Review Queue, Topology) | GA4 Realtime shows 5 page_view events; user_properties has fps_admin_id |
| 4 | Trigger "Test connection" button | Synthetic `fps_test_ping` event arrives in GA4 Realtime within 30s |
| 5 | Inspect Dashboard | "Analytics Connection Status" widget visible with green dots |

### Task 24: Battle test client side

Open storefront in incognito with DevTools:

| # | Test | Expected |
|---|------|----------|
| 6 | Visit `/cart.php` (NON-EEA — your default IP) | `gtag/js` + `clarity/tag` load 200; NO consent banner; gtag user_properties includes fps_country, fps_module_version |
| 7 | Simulate EEA visitor: `curl -H "X-Forwarded-For: 91.197.83.1"` (BG IP) → check that the rendered page contains `<div id="fps-consent-banner" data-active="1"` AND that gtag default-deny is set | Banner div present; consent default-deny in place |
| 8 | Click Accept in the banner | gtag('consent','update',...) fires; clarity('consent') fires; cookie `fps_consent=1` set; banner removed |
| 9 | Trigger a synthetic high-risk pre-checkout via the FpsCheckRunner CLI script | `fps_pre_checkout_block` arrives in GA4 with full payload; Clarity session has `fps_risk_level=critical` tag (verify in Clarity dashboard within 10 min) |
| 10 | Enable uBlock Origin in the incognito window | Page renders normally; checkout works; no console errors; events silently dropped |

### Task 25: Cron + GDPR + MCP tests

| # | Test | Expected |
|---|------|----------|
| 11 | `/usr/local/bin/php /tmp/fps_trigger_cron.php` (calls the DailyCronJob hook) | Heartbeat event sent; if anomaly thresholds met: row in `mod_fps_analytics_anomalies` + email |
| 12 | Synthetic `fps_gdprPurgeByEmail()` call with a test email | `report['tables']['clarity_dsr_api']['deleted'] >= 1` (if `clarity_dsr_token` set); `report['manual_followup']['ga4_user_deletion']` populated |
| 13 | Run `bash scripts/install-mcp-servers.sh` against a copy of `~/.claude/settings.json` | `.bak.YYYYMMDD-HHMMSS` created; new `mcpServers.google-analytics` + `mcpServers.microsoft-clarity` entries; `claude mcp list` shows both |

### Task 26: code-reviewer-pro pass — **DISPATCH SUBAGENT**

Prompt `code-reviewer-pro` with the diff (`git diff main..HEAD`) and the design doc + this plan. Address findings before committing to live.

---

## Phase 9 — Live deploy + battle test

### Task 27: Deploy to BOTH installs

```bash
cd "D:/Claude workfolder/fraud_prevention_suite"
tar -cf /tmp/fps-analytics-final.tar lib/Analytics/ lib/Admin/TabSettings.php lib/Admin/TabDashboard.php lib/Gdpr/FpsGdprHelper.php lib/FpsHookHelpers.php lib/FpsCheckRunner.php hooks.php fraud_prevention_suite.php phpstan-stubs/fps-globals.php assets/js/fps-consent-banner.js assets/js/fps-analytics-debug.js scripts/install-mcp-servers.sh docs/wiki/Analytics-MCP-Setup.md README.md CHANGELOG.md
scp -i ~/.ssh/id_ed25519_gpu_ci /tmp/fps-analytics-final.tar root@130.12.69.6:/tmp/

ssh -i ~/.ssh/id_ed25519_gpu_ci root@130.12.69.6 "scp ... && ssh ... 'set -e
for ROOT in /home/freeit /home/enterpri; do
  ADDON=\$ROOT/public_html/modules/addons/fraud_prevention_suite
  TS=\$(date +%Y%m%d-%H%M%S)
  cp -a \$ADDON \${ADDON}.bak.pre-analytics.\$TS
  cd \$ADDON
  mkdir -p lib/Analytics scripts docs/wiki
  tar -xf /tmp/fps-analytics-final.tar
  USER=\$(stat -c %U \$ADDON)
  chown -R \${USER}:\${USER} \$ADDON
done
echo === lint both installs ===
for ROOT in /home/freeit /home/enterpri; do
  ADDON=\$ROOT/public_html/modules/addons/fraud_prevention_suite
  cd \$ADDON
  ERRS=\$(find . -name \"*.php\" -not -path \"./vendor/*\" -not -path \"./phpstan-stubs/*\" -not -path \"./docs/*\" -print0 | xargs -0 -n1 /usr/local/bin/php -l 2>&1 | grep -v \"No syntax errors\" || true)
  if [ -n \"\$ERRS\" ]; then echo \"\$ROOT FAIL\"; echo \"\$ERRS\"; else echo \"\$ROOT clean\"; fi
done'"
```

Expected: both installs clean.

### Task 28: Live activate

`/usr/local/bin/php` invocation of `fraud_prevention_suite_activate()` for each install (via short PHP script). Verify `mod_fps_analytics_log` and `mod_fps_analytics_anomalies` tables exist on each install's DB.

### Task 29: Live admin battle test

Repeat Task 23 against `https://freeit.us/admin/addonmodules.php?module=fraud_prevention_suite` and `https://enterprisevpssolutions.com/admin/...`. Same 5 pass criteria.

### Task 30: Live client battle test

Repeat Task 24 against `https://freeit.us/cart.php` and the enterprisevpssolutions.com equivalent. Same 5 pass criteria.

### Task 31: 24-hour live observation

Wait 24h. Then check:
- GA4 Realtime → real fps_* events flowing
- Clarity dashboard → real sessions with FPS tags
- PHP error log → no spike

---

## Phase 10 — Final commit + push + CI

### Task 32: Single thematic feat commit (squashed if many small commits)

```bash
ssh ... 'cd /tmp/fps-design/repo && git log --oneline origin/main..HEAD'
```

If many commits, optionally squash via `git reset --soft origin/main && git commit -m '...'`. Otherwise just push.

### Task 33: Push + verify CI green

```bash
git push --force "https://x-access-token:${NEW_PAT}@github.com/CyberNinja7420/whmcs-fraud-prevention-suite.git" HEAD:main
# Wait + poll
sleep 60
curl ... /actions/runs?head_sha=HEAD&per_page=1
```

Expected: 3/3 jobs green (PHP syntax lint ✅, PHPStan ✅, Psalm ✅).

### Task 34: TODO-hardening.md update

Add the analytics integration to the "Closed in v4.2.5" section.

```bash
git add TODO-hardening.md
git commit -m 'docs(hardening): record analytics integration in v4.2.5 closed section'
git push ...
```

---

## Total commits (estimated)

~18-22 commits across the 10 phases. CI runs after each push (recommend pushing per-phase, not per-task, to keep CI runs proportional).

## Total LOC delta (estimated)

| Component | LOC |
|-----------|-----|
| `lib/Analytics/*.php` (7 files) | ~900 |
| `phpstan-stubs/fps-globals.php` extensions | ~80 |
| `assets/js/*.js` (2 files) | ~120 |
| `lib/Admin/TabSettings.php` extensions | ~50 |
| `lib/Admin/TabDashboard.php` extensions | ~60 |
| `hooks.php` extensions (3 hook bodies) | ~80 |
| `lib/FpsCheckRunner.php` extensions | ~20 |
| `lib/Gdpr/FpsGdprHelper.php` extensions | ~50 |
| `fraud_prevention_suite.php` extensions | ~60 |
| `scripts/install-mcp-servers.sh` | ~30 |
| `docs/wiki/Analytics-MCP-Setup.md` | ~250 |
| `README.md` + `CHANGELOG.md` updates | ~80 |
| **Total** | **~1780** |

## Subagent dispatch summary

| Subagent | Task | When |
|----------|------|------|
| `frontend-design` | Task 11 (consent banner + debug overlay JS) | Phase 3 |
| `php-pro` | Task 17 (FpsAnalyticsDataApi — JWT + GA4 Data API) | Phase 5 |
| `documentation-expert` | Task 21 (Analytics-MCP-Setup.md + README/CHANGELOG) | Phase 7 |
| `code-reviewer-pro` | Task 26 (final review pre-live-deploy) | Phase 8 |
| **My direct work** | All other tasks; battle-test execution; deployment; commit | Phases 1–10 |

---

**Plan complete and saved.** See execution choice below.

<?php
/**
 * FPS Selftest / Health Check Endpoint
 *
 * Verifies all providers, tables, connections, and critical settings.
 * Accessible only to authenticated WHMCS admins.
 *
 * Usage: https://yourdomain.com/modules/addons/fraud_prevention_suite/selftest.php
 * Returns: JSON with pass/fail/warn status for each check.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/init.php';

// Only allow admin access
if (!isset($_SESSION['adminid']) || (int) $_SESSION['adminid'] < 1) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Admin authentication required']);
    exit;
}

header('Content-Type: application/json');

use WHMCS\Database\Capsule;

$checks = [];
$pass = 0;
$fail = 0;
$warn = 0;

// ---------------------------------------------------------------------------
// 1. Module loads
// ---------------------------------------------------------------------------
try {
    require_once __DIR__ . '/fraud_prevention_suite.php';
    $cfg = fraud_prevention_suite_config();
    $checks[] = ['name' => 'Module loads', 'status' => 'pass', 'detail' => 'v' . ($cfg['version'] ?? 'unknown')];
    $pass++;
} catch (\Throwable $e) {
    $checks[] = ['name' => 'Module loads', 'status' => 'fail', 'detail' => $e->getMessage()];
    $fail++;
}

// ---------------------------------------------------------------------------
// 2. Database tables exist
// ---------------------------------------------------------------------------
$tables = [
    'mod_fps_checks',
    'mod_fps_settings',
    'mod_fps_rules',
    'mod_fps_stats',
    'mod_fps_reports',
    'mod_fps_fingerprints',
    'mod_fps_ip_intel',
    'mod_fps_email_intel',
    'mod_fps_api_keys',
    'mod_fps_otp_verifications',
];

foreach ($tables as $t) {
    try {
        $exists = Capsule::schema()->hasTable($t);
        $checks[] = ['name' => 'Table: ' . $t, 'status' => $exists ? 'pass' : 'fail', 'detail' => $exists ? 'exists' : 'missing'];
        $exists ? $pass++ : $fail++;
    } catch (\Throwable $e) {
        $checks[] = ['name' => 'Table: ' . $t, 'status' => 'fail', 'detail' => 'Query error: ' . $e->getMessage()];
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// 3. Provider connectivity (external APIs)
// ---------------------------------------------------------------------------
$providers = [
    'ip-api.com'     => 'http://ip-api.com/json/8.8.8.8?fields=status',
    'StopForumSpam'  => 'https://api.stopforumspam.org/api?json&ip=8.8.8.8',
    'FraudRecord'    => 'https://www.fraudrecord.com/api/',
];

foreach ($providers as $name => $url) {
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'FPS-Selftest/' . (FPS_MODULE_VERSION ?? '5.0'),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            $checks[] = ['name' => 'API: ' . $name, 'status' => 'warn', 'detail' => 'cURL: ' . $err];
            $warn++;
        } elseif ($code >= 200 && $code < 400) {
            $checks[] = ['name' => 'API: ' . $name, 'status' => 'pass', 'detail' => 'HTTP ' . $code];
            $pass++;
        } else {
            $checks[] = ['name' => 'API: ' . $name, 'status' => 'warn', 'detail' => 'HTTP ' . $code];
            $warn++;
        }
    } catch (\Throwable $e) {
        $checks[] = ['name' => 'API: ' . $name, 'status' => 'fail', 'detail' => $e->getMessage()];
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// 3b. Twilio connectivity (if configured)
// ---------------------------------------------------------------------------
try {
    if (class_exists('\\FraudPreventionSuite\\Lib\\FpsSmsVerifier')) {
        $smsVerifier = new \FraudPreventionSuite\Lib\FpsSmsVerifier();
        $smsEnabled = $smsVerifier->fps_isEnabled();
        $checks[] = [
            'name'   => 'SMS/OTP: Twilio',
            'status' => $smsEnabled ? 'pass' : 'warn',
            'detail' => $smsEnabled ? 'Configured and enabled' : 'Not configured or disabled',
        ];
        $smsEnabled ? $pass++ : $warn++;
    }
} catch (\Throwable $e) {
    $checks[] = ['name' => 'SMS/OTP: Twilio', 'status' => 'warn', 'detail' => $e->getMessage()];
    $warn++;
}

// ---------------------------------------------------------------------------
// 4. Critical settings
// ---------------------------------------------------------------------------
$critical = ['pre_checkout_blocking', 'auto_check_orders', 'auto_lock_critical'];
foreach ($critical as $key) {
    try {
        $val = Capsule::table('tbladdonmodules')
            ->where('module', 'fraud_prevention_suite')
            ->where('setting', $key)
            ->value('value');
        $on = ($val === 'on' || $val === 'yes');
        $checks[] = [
            'name'   => 'Setting: ' . $key,
            'status' => $on ? 'pass' : 'warn',
            'detail' => $val ?: 'not set',
        ];
        $on ? $pass++ : $warn++;
    } catch (\Throwable $e) {
        $checks[] = ['name' => 'Setting: ' . $key, 'status' => 'fail', 'detail' => $e->getMessage()];
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// 5. Hooks registered
// ---------------------------------------------------------------------------
try {
    $hooksFile = __DIR__ . '/hooks.php';
    if (file_exists($hooksFile)) {
        $hooksContent = file_get_contents($hooksFile);
        $hookCount = substr_count($hooksContent, 'add_hook(');
        $checks[] = [
            'name'   => 'Hooks registered',
            'status' => $hookCount >= 15 ? 'pass' : 'warn',
            'detail' => $hookCount . ' hooks found',
        ];
        $hookCount >= 15 ? $pass++ : $warn++;
    } else {
        $checks[] = ['name' => 'Hooks registered', 'status' => 'fail', 'detail' => 'hooks.php not found'];
        $fail++;
    }
} catch (\Throwable $e) {
    $checks[] = ['name' => 'Hooks registered', 'status' => 'fail', 'detail' => $e->getMessage()];
    $fail++;
}

// ---------------------------------------------------------------------------
// 6. Cron health (data freshness)
// ---------------------------------------------------------------------------
// Disposable-domain intelligence is file-based (data/disposable_domains.txt),
// refreshed by the daily cron hook.
$dataFiles = [
    'data/disposable_domains.txt' => 7,
];

foreach ($dataFiles as $file => $maxDays) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $age = (time() - filemtime($path)) / 86400;
        $ok = $age <= $maxDays;
        $checks[] = [
            'name'   => 'Data: ' . $file,
            'status' => $ok ? 'pass' : 'warn',
            'detail' => round($age, 1) . ' days old (max ' . $maxDays . ')',
        ];
        $ok ? $pass++ : $warn++;
    } else {
        $checks[] = ['name' => 'Data: ' . $file, 'status' => 'warn', 'detail' => 'File not found'];
        $warn++;
    }
}

// Tor exit-node intelligence is DB-based: TorDatacenterProvider::refreshTorNodeList()
// truncates and repopulates mod_fps_tor_nodes (with a last_seen_at timestamp) from
// the Tor Project bulk exit list on the daily cron. There is no flat file -- check
// the table's row count and freshness, which is what the detection path actually uses.
$torMaxDays = 3;
try {
    if (!Capsule::schema()->hasTable('mod_fps_tor_nodes')) {
        $checks[] = ['name' => 'Data: Tor exit nodes', 'status' => 'warn', 'detail' => 'mod_fps_tor_nodes table missing'];
        $warn++;
    } else {
        $torCount  = (int) Capsule::table('mod_fps_tor_nodes')->count();
        $torLatest = Capsule::table('mod_fps_tor_nodes')->max('last_seen_at');
        if ($torCount < 1) {
            $checks[] = ['name' => 'Data: Tor exit nodes', 'status' => 'warn', 'detail' => 'Table empty -- run the Tor refresh cron'];
            $warn++;
        } else {
            $torAge = $torLatest ? (time() - strtotime((string) $torLatest)) / 86400 : 999;
            $ok = $torAge <= $torMaxDays;
            $checks[] = [
                'name'   => 'Data: Tor exit nodes',
                'status' => $ok ? 'pass' : 'warn',
                'detail' => number_format($torCount) . ' nodes, ' . round($torAge, 1) . ' days old (max ' . $torMaxDays . ')',
            ];
            $ok ? $pass++ : $warn++;
        }
    }
} catch (\Throwable $e) {
    $checks[] = ['name' => 'Data: Tor exit nodes', 'status' => 'warn', 'detail' => 'Check failed: ' . $e->getMessage()];
    $warn++;
}

// ---------------------------------------------------------------------------
// 7. PHP extensions required
// ---------------------------------------------------------------------------
$requiredExtensions = ['curl', 'json', 'openssl', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = [
        'name'   => 'PHP ext: ' . $ext,
        'status' => $loaded ? 'pass' : 'fail',
        'detail' => $loaded ? 'loaded' : 'missing',
    ];
    $loaded ? $pass++ : $fail++;
}

// ---------------------------------------------------------------------------
// 8. Database connectivity / row counts
// ---------------------------------------------------------------------------
try {
    $checksCount = Capsule::schema()->hasTable('mod_fps_checks')
        ? (int) Capsule::table('mod_fps_checks')->count()
        : 0;
    $checksToday = Capsule::schema()->hasTable('mod_fps_checks')
        ? (int) Capsule::table('mod_fps_checks')
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count()
        : 0;
    $checks[] = [
        'name'   => 'DB: mod_fps_checks rows',
        'status' => 'pass',
        'detail' => $checksCount . ' total, ' . $checksToday . ' today',
    ];
    $pass++;
} catch (\Throwable $e) {
    $checks[] = ['name' => 'DB: mod_fps_checks rows', 'status' => 'fail', 'detail' => $e->getMessage()];
    $fail++;
}

// ---------------------------------------------------------------------------
// 9. Autoloader functional
// ---------------------------------------------------------------------------
$autoloadClasses = [
    '\\FraudPreventionSuite\\Lib\\FpsConfig',
    '\\FraudPreventionSuite\\Lib\\FpsCheckRunner',
    '\\FraudPreventionSuite\\Lib\\FpsSmsVerifier',
    '\\FraudPreventionSuite\\Lib\\Admin\\TabDashboard',
];
foreach ($autoloadClasses as $cls) {
    $exists = class_exists($cls);
    $shortName = substr($cls, strrpos($cls, '\\') + 1);
    $checks[] = [
        'name'   => 'Class: ' . $shortName,
        'status' => $exists ? 'pass' : 'warn',
        'detail' => $exists ? 'loadable' : 'not found',
    ];
    $exists ? $pass++ : $warn++;
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
echo json_encode([
    'module'    => 'fraud_prevention_suite',
    'version'   => FPS_MODULE_VERSION ?? 'unknown',
    'php'       => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'summary'   => [
        'pass'  => $pass,
        'warn'  => $warn,
        'fail'  => $fail,
        'total' => $pass + $warn + $fail,
    ],
    'checks' => $checks,
], JSON_PRETTY_PRINT);

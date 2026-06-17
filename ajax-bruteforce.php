<?php
/**
 * Standalone brute-force lockout verification endpoint (FEATURE 2).
 *
 * Called by the Turnstile gate injected into login.php when a visitor is
 * locked out. Verifies the cf-turnstile-response token server-side via the
 * existing FpsTurnstileValidator; on success, clears the lockout for the
 * caller's IP so the human can retry their login.
 *
 * This is a PUBLIC endpoint (a locked-out visitor is by definition NOT logged
 * in), so it is intentionally NOT session-gated. Its only mutation -- clearing
 * a lockout -- is hard-gated behind a successful Cloudflare Turnstile check,
 * which is the human-proof. It is rate-limited implicitly by Turnstile and by
 * the fact that it can only ever DELETE the caller's own lockout rows.
 *
 * Returns clean JSON only (no page chrome) and exit;.
 */

// --- Locate and load WHMCS init.php by walking up parent directories. ---
$dir = __DIR__;
$initPath = null;
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . '/init.php';
    if (file_exists($candidate)) {
        $initPath = $candidate;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

header('Content-Type: application/json');

if ($initPath === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'init not found']);
    exit;
}

require_once $initPath;
if (!headers_sent()) { header('Content-Type: application/json'); }

if (!defined('WHMCS')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'bootstrap failed']);
    exit;
}

// Load the module autoloader so the lib/ classes resolve.
$autoload = dirname(__DIR__) . '/lib/Autoloader.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    // Resolve the caller IP (same precedence the defense class uses).
    $ip = '';
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = (string) ($_SERVER[$k] ?? '');
        if ($v !== '') {
            $cand = trim(explode(',', $v)[0]);
            if (filter_var($cand, FILTER_VALIDATE_IP)) {
                $ip = $cand;
                break;
            }
        }
    }

    // Turnstile token from the gate.
    $token = (string) ($_POST['token'] ?? $_POST['cf-turnstile-response'] ?? '');
    $token = trim($token);
    if ($token === '') {
        echo json_encode(['success' => false, 'message' => 'Missing verification token.']);
        exit;
    }

    if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsTurnstileValidator')
        || !class_exists('\\FraudPreventionSuite\\Lib\\FpsBruteForceDefense')) {
        echo json_encode(['success' => false, 'message' => 'Verification unavailable.']);
        exit;
    }

    $turnstile = new \FraudPreventionSuite\Lib\FpsTurnstileValidator();

    // If Turnstile is not configured, we cannot prove the human -- fail closed
    // here (the gate only renders the verify button when Turnstile IS enabled,
    // so reaching this without it configured is anomalous).
    if (!$turnstile->isEnabled()) {
        echo json_encode(['success' => false, 'message' => 'Captcha not configured.']);
        exit;
    }

    $result = $turnstile->validate($token, $ip);
    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'message' => 'Verification failed. Please try again.']);
        exit;
    }

    // Human proven -- clear the IP lockout so they can retry the login.
    $defense = new \FraudPreventionSuite\Lib\FpsBruteForceDefense();
    $cleared = $defense->clearLockout($ip, '');

    echo json_encode([
        'success' => true,
        'message' => 'Verified. You may sign in now.',
        'cleared' => $cleared,
    ]);
    exit;
} catch (\Throwable $e) {
    if (function_exists('logModuleCall')) {
        logModuleCall('fraud_prevention_suite', 'ajax-bruteforce::ERROR', '', $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
}

<?php
/**
 * FpsAjaxGdpr -- GDPR Article 17 / 12 AJAX handlers (request, verify,
 * admin review). Extracted from fraud_prevention_suite.php as part of
 * the TODO-hardening.md item #4 bulk extraction.
 *
 * Functions stay in the global namespace so the dispatch switch in
 * fps_handleAjax() continues to call them by name.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// v4.1: GDPR DATA REMOVAL REQUEST SYSTEM
// ---------------------------------------------------------------------------

/**
 * PUBLIC: Submit a GDPR data removal request (no admin auth).
 */
function fps_ajaxGdprSubmitRequest(): array
{
    // Define the generic anti-enumeration response BEFORE the try{} so the
    // catch block at the bottom can reference it even when an exception is
    // thrown early (caught by phpstan: variable might not be defined).
    $genericMessage = 'If data associated with this email exists in our system, a verification link has been sent to your email address. Please check your inbox.';

    try {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'A valid email address is required'];
        }

        $emailHash = hash('sha256', $email);

        // Check if this email exists in our fraud intel
        $exists = Capsule::table('mod_fps_global_intel')
            ->where('email_hash', $emailHash)
            ->exists();

        if (!$exists) {
            // No data found -- still return the same generic message
            return ['success' => true, 'message' => $genericMessage];
        }

        // Check for existing pending request
        $existingRequest = Capsule::table('mod_fps_gdpr_requests')
            ->where('email_hash', $emailHash)
            ->whereIn('status', ['pending', 'verified'])
            ->first();

        if ($existingRequest) {
            // Already pending -- return same generic message (don't leak request ID)
            return ['success' => true, 'message' => $genericMessage];
        }

        // Create verification token
        $token = bin2hex(random_bytes(32));

        // Insert request
        $requestId = Capsule::table('mod_fps_gdpr_requests')->insertGetId([
            'email'              => $email,
            'email_hash'         => $emailHash,
            'name'               => $name,
            'reason'             => $reason,
            'verification_token' => $token,
            'email_verified'     => 0,
            'status'             => 'pending',
            'ip_address'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Send verification email via WHMCS
        try {
            $systemUrl = Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')->value('value') ?? '';
            $verifyUrl = rtrim($systemUrl, '/') . '/index.php?m=fraud_prevention_suite&page=gdpr-verify&ajax=1&token=' . urlencode($token);

            $hostname = parse_url($systemUrl, PHP_URL_HOST) ?: 'localhost';
            $subject = 'Verify Your Data Removal Request - Reference #' . $requestId;
            $body = "You (or someone using your email) submitted a data removal request.\n\n"
                . "To verify this is you, please visit this link:\n"
                . $verifyUrl . "\n\n"
                . "If you did not make this request, please ignore this email.\n\n"
                . "Reference: #" . $requestId . "\n"
                . "This link expires in 72 hours.\n";

            // Route through fps_sendMail so failures are logged instead of @suppressed.
            fps_sendMail($email, $subject, $body, [
                'From'         => 'noreply@' . $hostname,
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } catch (\Throwable $e) {
            // Email send failure is non-fatal -- admin can verify manually
        }

        // Return same generic message regardless of outcome (GDPR Art. 12(4) non-enumerable)
        return ['success' => true, 'message' => $genericMessage];
    } catch (\Throwable $e) {
        // Return generic message even on error to prevent enumeration
        return ['success' => true, 'message' => $genericMessage];
    }
}

/**
 * PUBLIC: Verify email ownership for a GDPR request (clicked from email link).
 */
function fps_ajaxGdprVerifyEmail(): array
{
    try {
        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        if (empty($token) || strlen($token) !== 64) {
            return ['error' => 'Invalid verification token'];
        }

        $request = Capsule::table('mod_fps_gdpr_requests')
            ->where('verification_token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$request) {
            return ['error' => 'Token not found or already used. The request may have expired or been processed.'];
        }

        // Check 72-hour expiry
        $created = strtotime($request->created_at);
        if ((time() - $created) > 259200) { // 72 hours
            Capsule::table('mod_fps_gdpr_requests')
                ->where('id', $request->id)
                ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
            return ['error' => 'This verification link has expired. Please submit a new request.'];
        }

        // Mark as verified
        Capsule::table('mod_fps_gdpr_requests')
            ->where('id', $request->id)
            ->update([
                'email_verified' => 1,
                'status' => 'verified',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'success' => true,
            'message' => 'Email verified. Your request (Reference #' . $request->id . ') is now pending admin review. You will be notified when it is processed.',
        ];
    } catch (\Throwable $e) {
        return ['error' => 'Verification failed'];
    }
}

/**
 * ADMIN: Get all GDPR removal requests with pagination.
 */
function fps_ajaxGdprGetRequests(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_fps_gdpr_requests')) {
            return ['success' => true, 'requests' => [], 'total' => 0];
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $status = $_GET['status'] ?? '';

        $query = Capsule::table('mod_fps_gdpr_requests')->orderByDesc('created_at');
        if ($status !== '' && in_array($status, ['pending', 'verified', 'approved', 'denied', 'completed'], true)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->toArray();

        // Enrich with intel record count
        foreach ($requests as &$r) {
            $r = (array)$r;
            $r['intel_records'] = Capsule::table('mod_fps_global_intel')
                ->where('email_hash', $r['email_hash'])
                ->count();
        }

        return [
            'success' => true,
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * ADMIN: Approve or deny a GDPR removal request.
 * Approve = delete all matching intel records from local DB + request hub purge.
 */
function fps_ajaxGdprReviewRequest(): array
{
    try {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = $_POST['review_action'] ?? ''; // approve or deny
        $notes = trim($_POST['admin_notes'] ?? '');

        if ($requestId < 1) return ['error' => 'Invalid request ID'];
        if (!in_array($action, ['approve', 'deny'], true)) return ['error' => 'Action must be approve or deny'];

        $request = Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->first();
        if (!$request) return ['error' => 'Request not found'];

        if ($action === 'deny') {
            Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->update([
                'status' => 'denied',
                'reviewed_by' => (int)$_SESSION['adminid'],
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['success' => true, 'message' => 'Request #' . $requestId . ' denied.'];
        }

        // APPROVE: comprehensive purge across ALL FPS tables (see fps_gdprPurgeByEmail).
        // Previously only mod_fps_global_intel was touched; this left caches, fingerprints,
        // and check records referencing the requester untouched, which didn't meet GDPR
        // Article 17 scope expectations.
        $purgeReport = fps_gdprPurgeByEmail(
            $request->email_hash,
            $request->email ?? null,
            $request->ip_address ?? null
        );
        $deleted = (int) array_sum(array_column($purgeReport['tables'], 'deleted'));

        // Also try to purge this instance's contributions from the hub
        $hubPurged = false;
        try {
            $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
            if ($client->isConfigured()) {
                $hubResult = $client->purgeContributions();
                $hubPurged = $hubResult['success'] ?? false;
            }
        } catch (\Throwable $e) {
            // Hub purge failure is non-fatal
        }

        Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->update([
            'status' => 'completed',
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'admin_notes' => $notes . "\n\n--- Purge report ---\n" . json_encode($purgeReport, JSON_PRETTY_PRINT),
            'records_purged' => $deleted,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        logActivity("FPS GDPR: Request #{$requestId} approved by admin #{$_SESSION['adminid']}. Tables touched: " . implode(',', array_keys($purgeReport['tables'])) . " ({$deleted} rows affected). Hub purge: " . ($hubPurged ? 'yes' : 'no'));

        return [
            'success' => true,
            'message' => "Request #{$requestId} approved. {$deleted} records purged across " . count($purgeReport['tables']) . " tables." . ($hubPurged ? ' Hub data also purged.' : ''),
            'records_purged' => $deleted,
            'hub_purged' => $hubPurged,
            'purge_report' => $purgeReport,
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

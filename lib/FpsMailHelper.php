<?php
/**
 * FpsMailHelper -- module-safe mail sender wrapper.
 *
 * Extracted from fraud_prevention_suite.php so static analysers
 * (psalm, phpstan) can resolve the function declaration without
 * scanning the 4,000+ line main file. Functions stay in the global
 * namespace so existing call sites continue to work without
 * modification.
 *
 * Closes the second psalm CI failure (UndefinedFunction fps_sendMail).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Module-safe mail sender wrapper.
 *
 * Replaces scattered raw @mail() calls. Tries WHMCS's localAPI SendEmail
 * (so admin mail config, SMTP, templates apply) and falls back to a
 * non-suppressed mail() that actually logs failures.
 *
 * @return bool true on apparent success, false otherwise (and logs why).
 */
function fps_sendMail(string $to, string $subject, string $body, array $headers = []): bool
{
    // Try WHMCS's SendEmail API first (respects admin mail config).
    // WHMCS SendEmail requires a valid related ID -- id=0 throws
    // "A related ID is required". So we only use it when the recipient
    // resolves to a real client; admin/arbitrary addresses fall to mail().
    try {
        if (function_exists('localAPI')) {
            $clientId = 0;
            try {
                $clientId = (int) Capsule::table('tblclients')
                    ->where('email', $to)
                    ->value('id');
            } catch (\Throwable $e) {
                $clientId = 0;
            }

            if ($clientId > 0) {
                $result = localAPI('SendEmail', [
                    'customtype'    => 'general',
                    'customsubject' => $subject,
                    'custommessage' => $body,
                    'id'            => $clientId,
                ], 'admin');
                if (($result['result'] ?? '') === 'success') {
                    return true;
                }
            }
        }
    } catch (\Throwable $e) {
        // Fall through to mail() fallback
    }

    // Fallback: PHP mail() WITHOUT @ suppression so errors are visible.
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }
    $sent = mail($to, $subject, $body, implode("\r\n", $headerLines));
    if (!$sent) {
        $err = error_get_last();
        logModuleCall(
            'fraud_prevention_suite',
            'fps_sendMail::fail',
            json_encode(['to' => $to, 'subject' => $subject]),
            $err['message'] ?? 'mail() returned false'
        );
    }
    return $sent;
}

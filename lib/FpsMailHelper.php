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
    try {
        if (function_exists('localAPI')) {
            $result = localAPI('SendEmail', [
                'customtype'    => 'product',
                'customsubject' => $subject,
                'custommessage' => $body,
                'id'            => 0,
                'customvars'    => base64_encode(serialize(['to' => $to])),
            ], 'admin');
            if (($result['result'] ?? '') === 'success') {
                return true;
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

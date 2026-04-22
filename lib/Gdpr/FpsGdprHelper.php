<?php
/**
 * FpsGdprHelper -- GDPR Article 17 erasure helpers extracted from
 * fraud_prevention_suite.php to keep the main file under control.
 *
 * Functions stay in the global namespace (no class wrapper, no PSR-4
 * conversion) so existing call sites continue to work without
 * modification. The main module file require_once's this file at
 * load time.
 *
 * Closes part of TODO-hardening.md item #4.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * GDPR Article 17 (Right to Erasure) helper.
 *
 * Purges or anonymises rows for a requester across the FPS-owned
 * tables that may hold PII. Audit-friendly: returns a per-table
 * report describing how many rows were deleted and how many were
 * anonymised (mod_fps_checks rows are kept for fraud-defence
 * evidence with PII redacted under Article 17(3)(e)).
 *
 * The require_once at the top of fraud_prevention_suite.php guarantees
 * this file is included exactly once per request, so a `function_exists()`
 * guard is unnecessary -- and would also hide the declaration from
 * static analysers (psalm flags conditionally-defined functions as
 * UndefinedFunction at call sites).
 *
 * @param string      $emailHash SHA-256 hash of the requester's email (canonical).
 * @param string|null $email     Raw email if available (used for fingerprints lookup).
 * @param string|null $ip        IP address if the request is also tied to one.
 * @return array{subject: array, tables: array<string, array{deleted:int, anonymised:int}>}
 */
function fps_gdprPurgeByEmail(string $emailHash, ?string $email = null, ?string $ip = null): array
{
    $report = [
        'subject' => [
            'email_hash' => $emailHash,
            'email'      => $email,
            'ip'         => $ip,
            'purged_at'  => date('Y-m-d H:i:s'),
        ],
        'tables' => [],
    ];

    $record = function (string $table, int $deleted, int $anonymised) use (&$report) {
        $report['tables'][$table] = ['deleted' => $deleted, 'anonymised' => $anonymised];
    };

    // 1. mod_fps_global_intel - full delete by email_hash (strongest identifier)
    try {
        $count = Capsule::table('mod_fps_global_intel')
            ->where('email_hash', $emailHash)->delete();
        $record('mod_fps_global_intel', $count, 0);
    } catch (\Throwable $e) {
        $record('mod_fps_global_intel', 0, 0);
    }

    // 2. mod_fps_email_intel - cache of email reputation lookups
    try {
        if (Capsule::schema()->hasTable('mod_fps_email_intel')) {
            $count = Capsule::table('mod_fps_email_intel')
                ->where('email_hash', $emailHash)->delete();
            $record('mod_fps_email_intel', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_email_intel', 0, 0);
    }

    // 3. mod_fps_ip_intel - IP reputation cache (only if IP provided)
    if ($ip !== null && $ip !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                $count = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $ip)->delete();
                $record('mod_fps_ip_intel', $count, 0);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_ip_intel', 0, 0);
        }
    }

    // 4. mod_fps_fingerprints - delete matching rows (schema varies by install)
    try {
        if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
            $q = Capsule::table('mod_fps_fingerprints');
            if ($email !== null && $email !== '' && Capsule::schema()->hasColumn('mod_fps_fingerprints', 'email')) {
                $count = $q->where('email', $email)->delete();
                $record('mod_fps_fingerprints', $count, 0);
            } elseif ($ip !== null && $ip !== '' && Capsule::schema()->hasColumn('mod_fps_fingerprints', 'ip_address')) {
                $count = $q->where('ip_address', $ip)->delete();
                $record('mod_fps_fingerprints', $count, 0);
            }
        }
    } catch (\Throwable $e) {
        $record('mod_fps_fingerprints', 0, 0);
    }

    // 5. mod_fps_checks - ANONYMISE rather than delete. Lawful basis for retention
    //    of fraud-defence evidence under GDPR Article 17(3)(e); but PII must be
    //    redacted on request.
    try {
        $q = Capsule::table('mod_fps_checks');
        if ($email !== null && $email !== '') {
            $emailRows = (clone $q)->where('email', $email)->update([
                'email'         => null,
                'phone'         => null,
                'ip_address'    => null,
                'details'       => null,
                'check_context' => json_encode(['anonymised' => true, 'at' => date('c')]),
            ]);
        } else {
            $emailRows = 0;
        }
        if ($ip !== null && $ip !== '') {
            $ipRows = (clone $q)->where('ip_address', $ip)->update([
                'email'         => null,
                'phone'         => null,
                'ip_address'    => null,
                'details'       => null,
                'check_context' => json_encode(['anonymised' => true, 'at' => date('c')]),
            ]);
        } else {
            $ipRows = 0;
        }
        $record('mod_fps_checks', 0, $emailRows + $ipRows);
    } catch (\Throwable $e) {
        $record('mod_fps_checks', 0, 0);
    }

    // 6. mod_fps_api_logs - only relevant if IP was tied to API calls
    if ($ip !== null && $ip !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_api_logs')) {
                $count = Capsule::table('mod_fps_api_logs')
                    ->where('ip_address', $ip)
                    ->update(['ip_address' => null]);
                $record('mod_fps_api_logs', 0, $count);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_api_logs', 0, 0);
        }
    }

    return $report;
}

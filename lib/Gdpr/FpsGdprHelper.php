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

/**
 * Export ALL FPS data associated with a WHMCS client ID.
 *
 * Collects records from every mod_fps_* table that references the client
 * (by client_id, email, or IP address). Used for GDPR Article 15
 * (Right of Access / data portability).
 *
 * @param int $clientId  The WHMCS client ID
 * @return array Structured array of all FPS data for the client
 */
function fps_exportClientData(int $clientId): array
{
    $export = [
        'client_id'  => $clientId,
        'exported_at' => date('Y-m-d H:i:s'),
        'module'     => 'fraud_prevention_suite',
        'sections'   => [],
    ];

    // Resolve email and IP from tblclients for cross-referencing
    $clientEmail = '';
    $clientIp = '';
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first(['email', 'ip']);
        if ($client) {
            $clientEmail = $client->email ?? '';
            $clientIp = $client->ip ?? '';
        }
    } catch (\Throwable $e) {
        // Non-fatal
    }

    // 1. Fraud checks
    try {
        if (Capsule::schema()->hasTable('mod_fps_checks')) {
            $rows = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['fraud_checks'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['fraud_checks'] = ['error' => $e->getMessage()];
    }

    // 2. Fingerprints
    try {
        if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
            $rows = Capsule::table('mod_fps_fingerprints')
                ->where('client_id', $clientId)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['fingerprints'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['fingerprints'] = ['error' => $e->getMessage()];
    }

    // 3. IP intelligence
    try {
        if (Capsule::schema()->hasTable('mod_fps_ip_intel') && $clientIp !== '') {
            $rows = Capsule::table('mod_fps_ip_intel')
                ->where('ip_address', $clientIp)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['ip_intel'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['ip_intel'] = ['error' => $e->getMessage()];
    }

    // 4. Email intelligence
    try {
        if (Capsule::schema()->hasTable('mod_fps_email_intel') && $clientEmail !== '') {
            $emailHash = hash('sha256', strtolower(trim($clientEmail)));
            $rows = Capsule::table('mod_fps_email_intel')
                ->where('email_hash', $emailHash)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['email_intel'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['email_intel'] = ['error' => $e->getMessage()];
    }

    // 5. Behavioral events
    try {
        if (Capsule::schema()->hasTable('mod_fps_behavioral_events')) {
            $rows = Capsule::table('mod_fps_behavioral_events')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['behavioral_events'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['behavioral_events'] = ['error' => $e->getMessage()];
    }

    // 6. Trust status
    try {
        if (Capsule::schema()->hasTable('mod_fps_trust_list')) {
            $rows = Capsule::table('mod_fps_trust_list')
                ->where('client_id', $clientId)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['trust_status'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['trust_status'] = ['error' => $e->getMessage()];
    }

    // 7. Reports
    try {
        if (Capsule::schema()->hasTable('mod_fps_reports')) {
            $rows = Capsule::table('mod_fps_reports')
                ->where('client_id', $clientId)
                ->orderByDesc('submitted_at')
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['reports'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['reports'] = ['error' => $e->getMessage()];
    }

    // 8. API keys
    try {
        if (Capsule::schema()->hasTable('mod_fps_api_keys')) {
            $rows = Capsule::table('mod_fps_api_keys')
                ->where('client_id', $clientId)
                ->get(['id', 'key_prefix', 'name', 'tier', 'is_active', 'total_requests', 'created_at', 'last_used_at'])
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['api_keys'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['api_keys'] = ['error' => $e->getMessage()];
    }

    // 9. Global intel contributions for this email
    try {
        if (Capsule::schema()->hasTable('mod_fps_global_intel') && $clientEmail !== '') {
            $emailHash = hash('sha256', strtolower(trim($clientEmail)));
            $rows = Capsule::table('mod_fps_global_intel')
                ->where('email_hash', $emailHash)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['global_intel'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['global_intel'] = ['error' => $e->getMessage()];
    }

    // 10. Velocity events
    try {
        if (Capsule::schema()->hasTable('mod_fps_velocity_events')) {
            $rows = Capsule::table('mod_fps_velocity_events')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['velocity_events'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['velocity_events'] = ['error' => $e->getMessage()];
    }

    // 11. GDPR requests for this email
    try {
        if (Capsule::schema()->hasTable('mod_fps_gdpr_requests') && $clientEmail !== '') {
            $emailHash = hash('sha256', strtolower(trim($clientEmail)));
            $rows = Capsule::table('mod_fps_gdpr_requests')
                ->where('email_hash', $emailHash)
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['gdpr_requests'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['gdpr_requests'] = ['error' => $e->getMessage()];
    }

    // 12. Geo-impossibility events
    try {
        if (Capsule::schema()->hasTable('mod_fps_geo_events')) {
            $rows = Capsule::table('mod_fps_geo_events')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($r) { return (array) $r; })
                ->toArray();
            $export['sections']['geo_events'] = $rows;
        }
    } catch (\Throwable $e) {
        $export['sections']['geo_events'] = ['error' => $e->getMessage()];
    }

    return $export;
}

/**
 * Wrap fps_exportClientData() in JSON with GDPR Article 20 metadata.
 *
 * @param int $clientId  The WHMCS client ID
 * @return string  JSON string with export data and metadata envelope
 */
function fps_exportClientDataJson(int $clientId): string
{
    $data = fps_exportClientData($clientId);

    $envelope = [
        'schema'     => 'fps-gdpr-export-v1',
        'format'     => 'JSON',
        'generated'  => date('c'),
        'module'     => 'fraud_prevention_suite',
        'module_version' => defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown',
        'client_id'  => $clientId,
        'record_count' => 0,
        'data'       => $data,
    ];

    // Count total records across all sections
    $totalRecords = 0;
    foreach ($data['sections'] as $section) {
        if (is_array($section) && !isset($section['error'])) {
            $totalRecords += count($section);
        }
    }
    $envelope['record_count'] = $totalRecords;

    return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * GDPR Article 17 erasure: delete ALL FPS data for a given WHMCS client ID.
 *
 * Unlike fps_gdprPurgeByEmail() which works by email hash (for public-facing
 * GDPR requests), this function works by client_id (for admin-initiated
 * erasure from the Client Profile tab). It covers every mod_fps_* table
 * that holds PII or client-linked data.
 *
 * mod_fps_checks rows are ANONYMISED (PII columns nulled) rather than
 * deleted, preserving fraud-defence evidence under GDPR Art 17(3)(e).
 *
 * @param int $clientId  The WHMCS client ID
 * @param int $adminId   The admin performing the erasure (audit trail)
 * @return array Summary of what was deleted/anonymised per table
 */
function fps_eraseClientData(int $clientId, int $adminId): array
{
    $summary = [
        'client_id'  => $clientId,
        'admin_id'   => $adminId,
        'erased_at'  => date('Y-m-d H:i:s'),
        'tables'     => [],
    ];

    $record = function (string $table, int $deleted, int $anonymised) use (&$summary) {
        $summary['tables'][$table] = ['deleted' => $deleted, 'anonymised' => $anonymised];
    };

    // Resolve email/IP for cross-table cleanup
    $clientEmail = '';
    $clientIp = '';
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first(['email', 'ip']);
        if ($client) {
            $clientEmail = $client->email ?? '';
            $clientIp = $client->ip ?? '';
        }
    } catch (\Throwable $e) {
        // Non-fatal
    }
    $emailHash = $clientEmail !== '' ? hash('sha256', strtolower(trim($clientEmail))) : '';

    // 1. mod_fps_fingerprints -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
            $count = Capsule::table('mod_fps_fingerprints')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_fingerprints', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_fingerprints', 0, 0);
    }

    // 2. mod_fps_behavioral_events -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_behavioral_events')) {
            $count = Capsule::table('mod_fps_behavioral_events')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_behavioral_events', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_behavioral_events', 0, 0);
    }

    // 3. mod_fps_velocity_events -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_velocity_events')) {
            $count = Capsule::table('mod_fps_velocity_events')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_velocity_events', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_velocity_events', 0, 0);
    }

    // 4. mod_fps_geo_events -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_geo_events')) {
            $count = Capsule::table('mod_fps_geo_events')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_geo_events', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_geo_events', 0, 0);
    }

    // 5. mod_fps_reports -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_reports')) {
            $count = Capsule::table('mod_fps_reports')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_reports', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_reports', 0, 0);
    }

    // 6. mod_fps_trust_list -- full delete
    try {
        if (Capsule::schema()->hasTable('mod_fps_trust_list')) {
            $count = Capsule::table('mod_fps_trust_list')
                ->where('client_id', $clientId)->delete();
            $record('mod_fps_trust_list', $count, 0);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_trust_list', 0, 0);
    }

    // 7. mod_fps_global_intel -- delete by email hash
    if ($emailHash !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_global_intel')) {
                $count = Capsule::table('mod_fps_global_intel')
                    ->where('email_hash', $emailHash)->delete();
                $record('mod_fps_global_intel', $count, 0);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_global_intel', 0, 0);
        }
    }

    // 8. mod_fps_email_intel -- delete by email hash
    if ($emailHash !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_email_intel')) {
                $count = Capsule::table('mod_fps_email_intel')
                    ->where('email_hash', $emailHash)->delete();
                $record('mod_fps_email_intel', $count, 0);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_email_intel', 0, 0);
        }
    }

    // 9. mod_fps_ip_intel -- delete by IP
    if ($clientIp !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                $count = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $clientIp)->delete();
                $record('mod_fps_ip_intel', $count, 0);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_ip_intel', 0, 0);
        }
    }

    // 10. mod_fps_api_keys -- revoke (soft-delete: deactivate, don't expose key material)
    try {
        if (Capsule::schema()->hasTable('mod_fps_api_keys')) {
            $count = Capsule::table('mod_fps_api_keys')
                ->where('client_id', $clientId)
                ->update(['is_active' => 0, 'name' => '[GDPR erased]']);
            $record('mod_fps_api_keys', 0, $count);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_api_keys', 0, 0);
    }

    // 11. mod_fps_api_logs -- anonymise IP
    if ($clientIp !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_api_logs')) {
                $count = Capsule::table('mod_fps_api_logs')
                    ->where('ip_address', $clientIp)
                    ->update(['ip_address' => null]);
                $record('mod_fps_api_logs', 0, $count);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_api_logs', 0, 0);
        }
    }

    // 12. mod_fps_fraud_fingerprints -- delete by email
    if ($clientEmail !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_fraud_fingerprints')) {
                $q = Capsule::table('mod_fps_fraud_fingerprints');
                $count = 0;
                if (Capsule::schema()->hasColumn('mod_fps_fraud_fingerprints', 'email')) {
                    $count = $q->where('email', $clientEmail)->delete();
                }
                $record('mod_fps_fraud_fingerprints', $count, 0);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_fraud_fingerprints', 0, 0);
        }
    }

    // 13. mod_fps_checks -- ANONYMISE (Article 17(3)(e): fraud-defence evidence retention)
    try {
        if (Capsule::schema()->hasTable('mod_fps_checks')) {
            $updateData = [
                'email'      => null,
                'phone'      => null,
                'ip_address' => null,
                'check_context' => json_encode([
                    'anonymised' => true,
                    'gdpr_erasure' => true,
                    'admin_id' => $adminId,
                    'at' => date('c'),
                ]),
            ];
            // Null details column if it exists (legacy installs)
            if (Capsule::schema()->hasColumn('mod_fps_checks', 'details')) {
                $updateData['details'] = null;
            }
            $count = Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->update($updateData);
            $record('mod_fps_checks', 0, $count);
        }
    } catch (\Throwable $e) {
        $record('mod_fps_checks', 0, 0);
    }

    // 14. mod_fps_gdpr_requests -- keep for audit trail but mark completed
    if ($emailHash !== '') {
        try {
            if (Capsule::schema()->hasTable('mod_fps_gdpr_requests')) {
                $count = Capsule::table('mod_fps_gdpr_requests')
                    ->where('email_hash', $emailHash)
                    ->whereNotIn('status', ['completed', 'denied'])
                    ->update([
                        'status' => 'completed',
                        'reviewed_by' => $adminId,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'admin_notes' => 'Auto-completed via admin GDPR erasure',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $record('mod_fps_gdpr_requests', 0, $count);
            }
        } catch (\Throwable $e) {
            $record('mod_fps_gdpr_requests', 0, 0);
        }
    }

    // Compute totals
    $totalDeleted = 0;
    $totalAnonymised = 0;
    foreach ($summary['tables'] as $tbl) {
        $totalDeleted += $tbl['deleted'];
        $totalAnonymised += $tbl['anonymised'];
    }
    $summary['total_deleted'] = $totalDeleted;
    $summary['total_anonymised'] = $totalAnonymised;

    // Log to WHMCS activity log
    logActivity(sprintf(
        'FPS GDPR Erasure: Admin #%d erased data for Client #%d. %d records deleted, %d anonymised across %d tables.',
        $adminId,
        $clientId,
        $totalDeleted,
        $totalAnonymised,
        count($summary['tables'])
    ));

    return $summary;
}

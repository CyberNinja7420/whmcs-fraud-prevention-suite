<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsDeviceTrustManager -- device-level trust/block management.
 *
 * Manages trust status for device fingerprints independently of client
 * accounts. A single device hash (SHA-256 from FingerprintProvider) can
 * be shared across multiple client accounts. This class lets admins:
 *
 *   - 'trusted'  -- always allow (skip all fraud checks)
 *   - 'normal'   -- default; subject to regular fraud checks
 *   - 'blocked'  -- always deny (block checkout)
 *   - 'watched'  -- flag for monitoring without auto-block
 *
 * All reads/writes target mod_fps_device_trust.
 * The table is created on first use with a hasTable guard.
 */
class FpsDeviceTrustManager
{
    private const MODULE_NAME = 'fraud_prevention_suite';
    private const TABLE       = 'mod_fps_device_trust';

    /** @var array<string> Allowed status values */
    private const VALID_STATUSES = ['trusted', 'normal', 'blocked', 'watched'];

    // ------------------------------------------------------------------
    // Schema bootstrap (idempotent)
    // ------------------------------------------------------------------

    /**
     * Ensure the device trust table exists. Called lazily on first query.
     */
    private function fps_ensureTable(): void
    {
        try {
            if (Capsule::schema()->hasTable(self::TABLE)) {
                return;
            }

            Capsule::schema()->create(self::TABLE, function ($table) {
                $table->increments('id');
                $table->string('fingerprint_hash', 64)->unique();
                $table->string('status', 20)->default('normal');
                $table->string('label', 255)->nullable();
                $table->text('client_ids')->nullable();
                $table->dateTime('first_seen_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->integer('total_sessions')->default(1);
                $table->text('reason')->nullable();
                $table->integer('set_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_ensureTable',
                '',
                $e->getMessage()
            );
        }
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Return the trust status for a device fingerprint hash.
     *
     * Falls back to 'normal' when no record exists or on any DB error.
     *
     * @param string $fingerprintHash SHA-256 device fingerprint hash
     * @return string 'trusted'|'normal'|'blocked'|'watched'
     */
    public function fps_getDeviceStatus(string $fingerprintHash): string
    {
        if ($fingerprintHash === '') {
            return 'normal';
        }

        try {
            $this->fps_ensureTable();

            $row = Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $fingerprintHash)
                ->first(['status']);

            if ($row === null) {
                return 'normal';
            }

            $status = (string) ($row->status ?? 'normal');
            return in_array($status, self::VALID_STATUSES, true) ? $status : 'normal';
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getDeviceStatus',
                json_encode(['hash' => substr($fingerprintHash, 0, 12)]),
                $e->getMessage()
            );
            return 'normal';
        }
    }

    /**
     * Set the trust status for a device fingerprint.
     *
     * Creates or updates the device trust record and logs the change.
     *
     * @param string $hash    SHA-256 fingerprint hash
     * @param string $status  One of: trusted, normal, blocked, watched
     * @param string $reason  Human-readable reason for the status change
     * @param int    $adminId Admin user ID performing the action
     * @param string $label   Optional human-readable device label
     * @throws \InvalidArgumentException If status is not valid
     */
    public function fps_setDeviceStatus(
        string $hash,
        string $status,
        string $reason,
        int $adminId,
        string $label = ''
    ): void {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid device trust status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES)
            );
        }

        try {
            $this->fps_ensureTable();
            $now = date('Y-m-d H:i:s');

            $existing = Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $hash)
                ->first();

            $updateData = [
                'status'     => $status,
                'reason'     => $reason ?: null,
                'set_by'     => $adminId > 0 ? $adminId : null,
                'updated_at' => $now,
            ];

            if ($label !== '') {
                $updateData['label'] = $label;
            }

            if ($existing) {
                Capsule::table(self::TABLE)
                    ->where('id', $existing->id)
                    ->update($updateData);
            } else {
                $updateData['fingerprint_hash'] = $hash;
                $updateData['first_seen_at']    = $now;
                $updateData['last_seen_at']     = $now;
                $updateData['total_sessions']   = 0;
                $updateData['client_ids']       = json_encode([]);
                if ($label === '' && !isset($updateData['label'])) {
                    $updateData['label'] = null;
                }
                Capsule::table(self::TABLE)->insert($updateData);
            }

            $logMessage = sprintf(
                'FPS: Device %s trust status set to "%s" by admin #%d. Reason: %s',
                substr($hash, 0, 12) . '...',
                $status,
                $adminId,
                $reason ?: 'none'
            );

            logActivity($logMessage);

            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_setDeviceStatus',
                json_encode(['hash' => substr($hash, 0, 12), 'status' => $status, 'admin_id' => $adminId]),
                'OK'
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_setDeviceStatus',
                json_encode(['hash' => substr($hash, 0, 12), 'status' => $status]),
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Record that a device fingerprint was seen with a given client.
     *
     * Upserts the device record: creates it if new, or updates last_seen_at,
     * increments total_sessions, and appends the client ID to the JSON array.
     *
     * @param string $hash     SHA-256 fingerprint hash
     * @param int    $clientId WHMCS client ID
     */
    public function fps_recordDeviceSeen(string $hash, int $clientId): void
    {
        if ($hash === '' || $clientId <= 0) {
            return;
        }

        try {
            $this->fps_ensureTable();
            $now = date('Y-m-d H:i:s');

            $existing = Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $hash)
                ->first();

            if ($existing) {
                // Parse existing client_ids array and append if new
                $clientIds = [];
                if (!empty($existing->client_ids)) {
                    $decoded = json_decode($existing->client_ids, true);
                    if (is_array($decoded)) {
                        $clientIds = $decoded;
                    }
                }

                if (!in_array($clientId, $clientIds, true)) {
                    $clientIds[] = $clientId;
                }

                Capsule::table(self::TABLE)
                    ->where('id', $existing->id)
                    ->update([
                        'last_seen_at'   => $now,
                        'total_sessions' => ($existing->total_sessions ?? 0) + 1,
                        'client_ids'     => json_encode(array_values(array_unique($clientIds))),
                        'updated_at'     => $now,
                    ]);
            } else {
                Capsule::table(self::TABLE)->insert([
                    'fingerprint_hash' => $hash,
                    'status'           => 'normal',
                    'label'            => null,
                    'client_ids'       => json_encode([$clientId]),
                    'first_seen_at'    => $now,
                    'last_seen_at'     => $now,
                    'total_sessions'   => 1,
                    'reason'           => null,
                    'set_by'           => null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal -- don't block checkout if device tracking fails
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_recordDeviceSeen',
                json_encode(['hash' => substr($hash, 0, 12), 'client_id' => $clientId]),
                $e->getMessage()
            );
        }
    }

    /**
     * Return the full device trust record for a fingerprint hash.
     *
     * @param string $hash SHA-256 fingerprint hash
     * @return array|null Full record or null if not found
     */
    public function fps_getDeviceHistory(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        try {
            $this->fps_ensureTable();

            $row = Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $hash)
                ->first();

            if ($row === null) {
                return null;
            }

            $record = (array) $row;

            // Decode client_ids JSON for convenience
            if (!empty($record['client_ids'])) {
                $decoded = json_decode($record['client_ids'], true);
                $record['client_ids_array'] = is_array($decoded) ? $decoded : [];
            } else {
                $record['client_ids_array'] = [];
            }

            // Enrich with client details
            if (!empty($record['client_ids_array'])) {
                try {
                    $clients = Capsule::table('tblclients')
                        ->whereIn('id', $record['client_ids_array'])
                        ->get(['id', 'firstname', 'lastname', 'email', 'status', 'companyname'])
                        ->toArray();

                    $record['clients'] = array_map(function ($c) {
                        return (array) $c;
                    }, $clients);
                } catch (\Throwable $e) {
                    $record['clients'] = [];
                }
            } else {
                $record['clients'] = [];
            }

            return $record;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getDeviceHistory',
                json_encode(['hash' => substr($hash, 0, 12)]),
                $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Return all devices that have been seen with a given client.
     *
     * Queries both mod_fps_device_trust (for trust status/labels) and
     * mod_fps_fingerprints (for the raw fingerprint data).
     *
     * @param int $clientId WHMCS client ID
     * @return array List of device records with trust status merged
     */
    public function fps_getDevicesByClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }

        try {
            $this->fps_ensureTable();

            // Get fingerprint hashes from the fingerprints table
            $fpHashes = [];
            try {
                if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
                    $fpHashes = Capsule::table('mod_fps_fingerprints')
                        ->where('client_id', $clientId)
                        ->pluck('fingerprint_hash')
                        ->toArray();
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            // Also check device_trust table for client_ids JSON containing this client
            try {
                $trustDevices = Capsule::table(self::TABLE)
                    ->whereRaw("JSON_CONTAINS(client_ids, ?)", [json_encode($clientId)])
                    ->pluck('fingerprint_hash')
                    ->toArray();

                $fpHashes = array_unique(array_merge($fpHashes, $trustDevices));
            } catch (\Throwable $e) {
                // JSON_CONTAINS may not be available on older MySQL -- fallback to LIKE
                try {
                    $trustDevices = Capsule::table(self::TABLE)
                        ->where('client_ids', 'LIKE', '%' . $clientId . '%')
                        ->pluck('fingerprint_hash')
                        ->toArray();

                    // Filter false positives from LIKE (e.g. client 1 matching client 10)
                    foreach ($trustDevices as $tdHash) {
                        $row = Capsule::table(self::TABLE)
                            ->where('fingerprint_hash', $tdHash)
                            ->first(['client_ids']);
                        if ($row && !empty($row->client_ids)) {
                            $ids = json_decode($row->client_ids, true);
                            if (is_array($ids) && in_array($clientId, $ids, true)) {
                                $fpHashes[] = $tdHash;
                            }
                        }
                    }
                    $fpHashes = array_unique($fpHashes);
                } catch (\Throwable $e2) {
                    // Non-fatal
                }
            }

            if (empty($fpHashes)) {
                return [];
            }

            // Get fingerprint records
            $devices = [];
            try {
                if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
                    $fpRows = Capsule::table('mod_fps_fingerprints')
                        ->where('client_id', $clientId)
                        ->whereIn('fingerprint_hash', $fpHashes)
                        ->orderByDesc('last_seen_at')
                        ->get()
                        ->toArray();

                    foreach ($fpRows as $fp) {
                        $devices[$fp->fingerprint_hash] = (array) $fp;
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            // Merge trust status from device_trust table
            try {
                $trustRows = Capsule::table(self::TABLE)
                    ->whereIn('fingerprint_hash', $fpHashes)
                    ->get()
                    ->toArray();

                foreach ($trustRows as $tr) {
                    $hash = $tr->fingerprint_hash;
                    if (!isset($devices[$hash])) {
                        $devices[$hash] = ['fingerprint_hash' => $hash];
                    }
                    $devices[$hash]['device_trust_status'] = $tr->status ?? 'normal';
                    $devices[$hash]['device_trust_label']  = $tr->label ?? '';
                    $devices[$hash]['device_trust_reason'] = $tr->reason ?? '';
                    $devices[$hash]['device_trust_set_by'] = $tr->set_by ?? null;
                    $devices[$hash]['device_first_seen']   = $tr->first_seen_at ?? null;
                    $devices[$hash]['device_last_seen']    = $tr->last_seen_at ?? null;
                    $devices[$hash]['device_sessions']     = $tr->total_sessions ?? 0;
                    $devices[$hash]['device_client_ids']   = $tr->client_ids ?? '[]';
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            // Ensure every device has a trust status (default to normal)
            foreach ($devices as &$dev) {
                if (!isset($dev['device_trust_status'])) {
                    $dev['device_trust_status'] = 'normal';
                    $dev['device_trust_label']  = '';
                }
            }
            unset($dev);

            return array_values($devices);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getDevicesByClient',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Return all clients who have used a given device fingerprint.
     *
     * @param string $hash SHA-256 fingerprint hash
     * @return array List of client records
     */
    public function fps_getClientsByDevice(string $hash): array
    {
        if ($hash === '') {
            return [];
        }

        try {
            $this->fps_ensureTable();

            $clientIds = [];

            // From device_trust table
            $trustRow = Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $hash)
                ->first(['client_ids']);

            if ($trustRow && !empty($trustRow->client_ids)) {
                $decoded = json_decode($trustRow->client_ids, true);
                if (is_array($decoded)) {
                    $clientIds = $decoded;
                }
            }

            // Also from fingerprints table
            try {
                if (Capsule::schema()->hasTable('mod_fps_fingerprints')) {
                    $fpClientIds = Capsule::table('mod_fps_fingerprints')
                        ->where('fingerprint_hash', $hash)
                        ->pluck('client_id')
                        ->toArray();

                    $clientIds = array_unique(array_merge($clientIds, $fpClientIds));
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            if (empty($clientIds)) {
                return [];
            }

            $clients = Capsule::table('tblclients')
                ->whereIn('id', $clientIds)
                ->get(['id', 'firstname', 'lastname', 'email', 'status', 'companyname', 'datecreated'])
                ->toArray();

            return array_map(function ($c) {
                return (array) $c;
            }, $clients);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getClientsByDevice',
                json_encode(['hash' => substr($hash, 0, 12)]),
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Search devices by hash prefix, label, or associated client email.
     *
     * @param string $query  Search string
     * @param int    $limit  Max results (default 50)
     * @return array List of device records matching the search
     */
    public function fps_searchDevices(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $this->fps_ensureTable();

            $results = Capsule::table(self::TABLE)
                ->where(function ($q) use ($query) {
                    $q->where('fingerprint_hash', 'LIKE', $query . '%')
                      ->orWhere('label', 'LIKE', '%' . $query . '%');
                })
                ->orderByDesc('last_seen_at')
                ->limit($limit)
                ->get()
                ->toArray();

            // Also search by client email
            try {
                $clientMatches = Capsule::table('tblclients')
                    ->where('email', 'LIKE', '%' . $query . '%')
                    ->pluck('id')
                    ->toArray();

                if (!empty($clientMatches)) {
                    // Find devices associated with these clients
                    $existingHashes = array_map(function ($r) {
                        return $r->fingerprint_hash;
                    }, $results);

                    foreach ($clientMatches as $cid) {
                        $cidStr = (string) $cid;
                        $deviceRows = Capsule::table(self::TABLE)
                            ->where('client_ids', 'LIKE', '%' . $cidStr . '%')
                            ->whereNotIn('fingerprint_hash', $existingHashes)
                            ->limit($limit)
                            ->get()
                            ->toArray();

                        foreach ($deviceRows as $dr) {
                            // Verify the client ID is actually in the JSON array
                            $ids = json_decode($dr->client_ids ?? '[]', true);
                            if (is_array($ids) && in_array($cid, $ids, true)) {
                                $results[] = $dr;
                                $existingHashes[] = $dr->fingerprint_hash;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            return array_map(function ($r) {
                return (array) $r;
            }, array_slice($results, 0, $limit));
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_searchDevices',
                json_encode(['query' => $query]),
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Return all blocked devices.
     *
     * @param int $limit Max results (default 100)
     * @return array List of blocked device records
     */
    public function fps_getBlockedDevices(int $limit = 100): array
    {
        return $this->fps_getDevicesByStatus('blocked', $limit);
    }

    /**
     * Return all trusted devices.
     *
     * @param int $limit Max results (default 100)
     * @return array List of trusted device records
     */
    public function fps_getTrustedDevices(int $limit = 100): array
    {
        return $this->fps_getDevicesByStatus('trusted', $limit);
    }

    /**
     * Return all watched devices.
     *
     * @param int $limit Max results (default 100)
     * @return array List of watched device records
     */
    public function fps_getWatchedDevices(int $limit = 100): array
    {
        return $this->fps_getDevicesByStatus('watched', $limit);
    }

    /**
     * Return paginated list of devices, optionally filtered by status and search.
     *
     * @param string $status  Filter by status (empty string = all)
     * @param string $search  Search query (hash prefix, label, or email)
     * @param int    $page    1-based page number
     * @param int    $perPage Records per page
     * @return array{rows: array, total: int, pages: int}
     */
    public function fps_getDeviceList(
        string $status = '',
        string $search = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        try {
            $this->fps_ensureTable();

            $perPage = max(1, min(200, $perPage));
            $page    = max(1, $page);
            $offset  = ($page - 1) * $perPage;

            $query = Capsule::table(self::TABLE);

            if ($status !== '' && in_array($status, self::VALID_STATUSES, true)) {
                $query->where('status', $status);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('fingerprint_hash', 'LIKE', $search . '%')
                      ->orWhere('label', 'LIKE', '%' . $search . '%');
                });
            }

            $total = (int) (clone $query)->count();
            $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

            $rows = $query
                ->orderByDesc('last_seen_at')
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->toArray();

            // Enrich each row with client email summary
            $enriched = [];
            foreach ($rows as $row) {
                $record = (array) $row;
                $record['client_emails'] = [];

                if (!empty($record['client_ids'])) {
                    $clientIdArr = json_decode($record['client_ids'], true);
                    if (is_array($clientIdArr) && !empty($clientIdArr)) {
                        try {
                            $clients = Capsule::table('tblclients')
                                ->whereIn('id', array_slice($clientIdArr, 0, 10))
                                ->get(['id', 'firstname', 'lastname', 'email'])
                                ->toArray();

                            $record['client_emails'] = array_map(function ($c) {
                                return [
                                    'id'    => (int) $c->id,
                                    'name'  => trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')),
                                    'email' => $c->email ?? '',
                                ];
                            }, $clients);
                        } catch (\Throwable $e) {
                            // Non-fatal
                        }
                    }
                }

                $enriched[] = $record;
            }

            return [
                'rows'  => $enriched,
                'total' => $total,
                'pages' => $pages,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getDeviceList',
                json_encode(['status' => $status, 'search' => $search, 'page' => $page]),
                $e->getMessage()
            );
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    /**
     * Update just the label of a device (without changing its status).
     *
     * @param string $hash  SHA-256 fingerprint hash
     * @param string $label Human-readable label
     */
    public function fps_setDeviceLabel(string $hash, string $label): void
    {
        if ($hash === '') {
            return;
        }

        try {
            $this->fps_ensureTable();

            Capsule::table(self::TABLE)
                ->where('fingerprint_hash', $hash)
                ->update([
                    'label'      => $label ?: null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_setDeviceLabel',
                json_encode(['hash' => substr($hash, 0, 12)]),
                $e->getMessage()
            );
        }
    }

    /**
     * Get device trust statistics for the dashboard.
     *
     * @return array{trusted: int, blocked: int, watched: int, normal: int, total: int}
     */
    public function fps_getDeviceStats(): array
    {
        $stats = [
            'trusted' => 0,
            'blocked' => 0,
            'watched' => 0,
            'normal'  => 0,
            'total'   => 0,
        ];

        try {
            $this->fps_ensureTable();

            $rows = Capsule::table(self::TABLE)
                ->selectRaw('status, COUNT(*) as cnt')
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $s = (string) ($row->status ?? 'normal');
                $cnt = (int) ($row->cnt ?? 0);
                switch ($s) {
                    case 'trusted': $stats['trusted'] = $cnt; break;
                    case 'blocked': $stats['blocked'] = $cnt; break;
                    case 'watched': $stats['watched'] = $cnt; break;
                    case 'normal':  $stats['normal']  = $cnt; break;
                }
            }

            $stats['total'] = $stats['trusted'] + $stats['blocked'] + $stats['watched'] + $stats['normal'];
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Return devices filtered by status.
     */
    private function fps_getDevicesByStatus(string $status, int $limit): array
    {
        try {
            $this->fps_ensureTable();

            $rows = Capsule::table(self::TABLE)
                ->where('status', $status)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->toArray();

            return array_map(function ($r) {
                return (array) $r;
            }, $rows);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsDeviceTrustManager::fps_getDevicesByStatus',
                json_encode(['status' => $status]),
                $e->getMessage()
            );
            return [];
        }
    }
}

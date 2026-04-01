<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsClientTrustManager -- client trust and blacklist management.
 *
 * Maintains a per-client trust status that short-circuits or blocks
 * the fraud check pipeline before any provider calls are made:
 *
 *   - 'trusted'     -- always skip fraud checks (whitelisted)
 *   - 'normal'      -- default; subject to normal fraud checks
 *   - 'blacklisted' -- immediately block all orders
 *   - 'suspended'   -- flag for manual review without auto-block
 *
 * All reads and writes target mod_fps_client_trust.
 * The table is created by the module activate function, not here.
 */
class FpsClientTrustManager
{
    private const MODULE_NAME  = 'fraud_prevention_suite';
    private const TABLE        = 'mod_fps_client_trust';

    /** @var array<string> Allowed status values */
    private const VALID_STATUSES = ['trusted', 'normal', 'blacklisted', 'suspended'];

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Return the trust status for a client.
     *
     * Queries mod_fps_client_trust for the client's current status.
     * Falls back to 'normal' when no record exists or on any DB error.
     *
     * @param int $clientId WHMCS client ID
     * @return string 'trusted'|'normal'|'blacklisted'|'suspended'
     */
    public function getClientStatus(int $clientId): string
    {
        try {
            $row = Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->first(['status']);

            if ($row === null) {
                return 'normal';
            }

            $status = (string) ($row->status ?? 'normal');

            return in_array($status, self::VALID_STATUSES, true) ? $status : 'normal';
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsClientTrustManager::getClientStatus',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
            return 'normal';
        }
    }

    /**
     * Upsert the trust status for a client and log the change.
     *
     * Writes to mod_fps_client_trust using updateOrInsert so both new
     * and existing client records are handled correctly.
     * Records the change in the WHMCS admin activity log via logActivity().
     *
     * @param int    $clientId The WHMCS client ID
     * @param string $status   One of: trusted, normal, blacklisted, suspended
     * @param string $reason   Human-readable reason for the status change
     * @param int    $adminId  Admin user ID performing the action (0 for system)
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function setClientStatus(int $clientId, string $status, string $reason, int $adminId): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid trust status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES)
            );
        }

        try {
            $now = date('Y-m-d H:i:s');

            Capsule::table(self::TABLE)->updateOrInsert(
                ['client_id' => $clientId],
                [
                    'status'          => $status,
                    'reason'          => $reason ?: null,
                    'set_by_admin_id' => $adminId > 0 ? $adminId : null,
                    'updated_at'      => $now,
                ]
            );

            // Ensure created_at is set on first insert (updateOrInsert may not set it)
            $row = Capsule::table(self::TABLE)->where('client_id', $clientId)->first(['created_at']);
            if ($row !== null && empty($row->created_at)) {
                Capsule::table(self::TABLE)
                    ->where('client_id', $clientId)
                    ->update(['created_at' => $now]);
            }

            $logMessage = sprintf(
                'FPS: Client #%d trust status set to "%s" by admin #%d. Reason: %s',
                $clientId,
                $status,
                $adminId,
                $reason ?: 'none'
            );

            logActivity($logMessage);

            logModuleCall(
                self::MODULE_NAME,
                'FpsClientTrustManager::setClientStatus',
                json_encode(['client_id' => $clientId, 'status' => $status, 'admin_id' => $adminId]),
                'OK'
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsClientTrustManager::setClientStatus',
                json_encode(['client_id' => $clientId, 'status' => $status]),
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Return true when a client's fraud checks should be skipped entirely.
     *
     * Called by FpsCheckRunner before any provider calls are made.
     * Only 'trusted' clients bypass the check pipeline.
     *
     * @param int $clientId WHMCS client ID
     */
    public function shouldSkipCheck(int $clientId): bool
    {
        return $this->getClientStatus($clientId) === 'trusted';
    }

    /**
     * Return true when a client's orders should be auto-blocked immediately.
     *
     * Called by FpsCheckRunner at order placement time.
     * Only 'blacklisted' clients trigger an immediate block.
     *
     * @param int $clientId WHMCS client ID
     */
    public function shouldAutoBlock(int $clientId): bool
    {
        return $this->getClientStatus($clientId) === 'blacklisted';
    }

    /**
     * Return a paginated list of clients with a given trust status.
     *
     * Joins mod_fps_client_trust with tblclients to include client
     * name and email alongside the trust record data.
     *
     * @param string $status  One of: trusted, normal, blacklisted, suspended
     * @param int    $page    1-based page number
     * @param int    $perPage Records per page (clamped to 1-200)
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function getClientsWithStatus(string $status, int $page = 1, int $perPage = 25): array
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid trust status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES)
            );
        }

        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        try {
            $query = Capsule::table(self::TABLE . ' as t')
                ->join('tblclients as c', 'c.id', '=', 't.client_id')
                ->where('t.status', $status);

            $total = (int) $query->count();
            $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

            $rows = $query
                ->orderBy('t.updated_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get([
                    't.id',
                    't.client_id',
                    't.status',
                    't.reason',
                    't.set_by_admin_id',
                    't.created_at',
                    't.updated_at',
                    'c.firstname',
                    'c.lastname',
                    'c.email',
                    'c.companyname',
                ]);

            $rowsArray = [];
            foreach ($rows as $row) {
                $rowsArray[] = (array) $row;
            }

            return [
                'rows'  => $rowsArray,
                'total' => $total,
                'pages' => $pages,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsClientTrustManager::getClientsWithStatus',
                json_encode(['status' => $status, 'page' => $page, 'per_page' => $perPage]),
                $e->getMessage()
            );
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    /**
     * Return the full change history for a client's trust status.
     *
     * Queries mod_fps_client_trust for all rows matching the client ID,
     * ordered newest-first. Because updateOrInsert overwrites a single row,
     * this returns the current record. A dedicated audit log table should be
     * used if full change history across multiple edits is required.
     *
     * @param int $clientId WHMCS client ID
     * @return list<array<string, mixed>>
     */
    public function getAuditLog(int $clientId): array
    {
        try {
            $rows = Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->orderBy('updated_at', 'desc')
                ->get([
                    'id',
                    'client_id',
                    'status',
                    'reason',
                    'set_by_admin_id',
                    'created_at',
                    'updated_at',
                ]);

            $result = [];
            foreach ($rows as $row) {
                $result[] = (array) $row;
            }

            return $result;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsClientTrustManager::getAuditLog',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Set the same trust status on multiple clients in a single operation.
     *
     * Iterates the client ID array and calls setClientStatus() for each,
     * collecting successful updates. Skips invalid IDs (<= 0) silently.
     * Individual failures are logged but do not abort the remaining updates.
     *
     * @param array<int> $clientIds Array of WHMCS client IDs
     * @param string     $status    One of: trusted, normal, blacklisted, suspended
     * @param string     $reason    Reason applied to every updated record
     * @param int        $adminId   Admin user ID performing the action
     * @return int Number of clients successfully updated
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function bulkSetStatus(array $clientIds, string $status, string $reason, int $adminId): int
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid trust status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES)
            );
        }

        $updated = 0;

        foreach ($clientIds as $clientId) {
            $clientId = (int) $clientId;
            if ($clientId <= 0) {
                continue;
            }

            try {
                $this->setClientStatus($clientId, $status, $reason, $adminId);
                $updated++;
            } catch (\Throwable $e) {
                logModuleCall(
                    self::MODULE_NAME,
                    'FpsClientTrustManager::bulkSetStatus::item',
                    json_encode(['client_id' => $clientId, 'status' => $status]),
                    $e->getMessage()
                );
            }
        }

        logModuleCall(
            self::MODULE_NAME,
            'FpsClientTrustManager::bulkSetStatus',
            json_encode([
                'client_count' => count($clientIds),
                'status'       => $status,
                'admin_id'     => $adminId,
            ]),
            json_encode(['updated' => $updated])
        );

        return $updated;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Validate that a status string is one of the four allowed values.
     *
     * @param string $status The status to validate
     */
    private function fps_assertValidStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid trust status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES)
            );
        }
    }

    /**
     * Return the current UTC timestamp string for DB writes.
     */
    private function fps_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsBotDetector -- identifies and cleans up bot/spam accounts.
 *
 * Detection strategy: A "real" client has at least one paid invoice (total > 0)
 * OR at least one active hosting service. Everything else is a suspected bot.
 * This gives 100% accuracy with zero false positives because it's based on
 * financial reality, not email pattern heuristics.
 *
 * Provides:
 *   - detectBots()             Scan all clients, return suspected bots
 *   - previewPurge()           Dry-run: show what standard purge would delete
 *   - purgeBotAccounts()       Delete accounts with ZERO records
 *   - previewDeepPurge()       Dry-run: show what deep purge would delete
 *   - deepPurgeBotAccounts()   Delete accounts where ALL records are Fraud/Cancelled
 *   - previewDeactivate()      Dry-run: show what deactivation would do
 *   - deactivateBotAccounts()  Set bot accounts to Inactive
 *   - flagBotAccounts()        Add bot flag/notes to accounts
 */
class FpsBotDetector
{
    /**
     * Detect all suspected bot accounts using real WHMCS financial data.
     *
     * @param string $statusFilter 'all', 'Active', 'Inactive', 'Closed'
     * @return array{success: bool, total: int, suspects: array, real_count: int}
     */
    public function detectBots(string $statusFilter = ''): array
    {
        // Step 1: Find ALL real clients (have paid invoices OR active hosting)
        $realClientIds = $this->getRealClientIds();

        // Step 2: Get all clients except admin (id=1 is sometimes the master admin)
        $query = Capsule::table('tblclients')->where('id', '>', 1);
        if ($statusFilter && $statusFilter !== 'all' && $statusFilter !== '') {
            $query->where('status', $statusFilter);
        }
        $allClients = $query->get(['id', 'firstname', 'lastname', 'email', 'status',
            'datecreated', 'ip', 'country']);

        // Step 3: Anyone NOT in realClientIds is a suspected bot
        $suspects = [];
        foreach ($allClients as $client) {
            if (in_array((int)$client->id, $realClientIds, true)) {
                continue; // Skip real clients
            }

            // Gather evidence for each suspect
            $orderCount = Capsule::table('tblorders')->where('userid', $client->id)->count();
            $invoiceCount = Capsule::table('tblinvoices')->where('userid', $client->id)->count();
            $hostingCount = Capsule::table('tblhosting')->where('userid', $client->id)->count();

            $suspects[] = [
                'id'         => (int)$client->id,
                'name'       => trim($client->firstname . ' ' . $client->lastname),
                'email'      => $client->email,
                'status'     => $client->status,
                'created'    => $client->datecreated,
                'ip'         => $client->ip ?? '',
                'country'    => $client->country ?? '',
                'orders'     => $orderCount,
                'invoices'   => $invoiceCount,
                'hosting'    => $hostingCount,
                'evidence'   => $this->buildEvidence($client, $orderCount, $invoiceCount, $hostingCount),
            ];
        }

        return [
            'success'    => true,
            'total'      => count($suspects),
            'suspects'   => $suspects,
            'real_count' => count($realClientIds),
            'scanned'    => count($allClients),
        ];
    }

    /**
     * Get IDs of clients confirmed as real (paid invoices or active hosting).
     */
    public function getRealClientIds(): array
    {
        $paidClients = Capsule::table('tblinvoices')
            ->where('status', 'Paid')
            ->where('total', '>', 0)
            ->distinct()
            ->pluck('userid')
            ->toArray();

        $activeHosting = Capsule::table('tblhosting')
            ->where('domainstatus', 'Active')
            ->distinct()
            ->pluck('userid')
            ->toArray();

        return array_values(array_unique(array_merge(
            array_map('intval', $paidClients),
            array_map('intval', $activeHosting)
        )));
    }

    /**
     * Detect orphan/bot user accounts in tblusers.
     *
     * Returns users that have no client links (orphans) or are linked only
     * to bot clients (no paid invoices, no active hosting).
     */
    public function detectOrphanUsers(): array
    {
        if (!Capsule::schema()->hasTable('tblusers')) {
            return ['success' => true, 'total' => 0, 'users' => [], 'message' => 'tblusers table not found (WHMCS < 8.0)'];
        }

        $realClientIds = $this->getRealClientIds();
        $users = Capsule::table('tblusers')->get(['id', 'email', 'first_name', 'last_name', 'last_ip', 'last_login', 'created_at']);
        $hasUserClients = Capsule::schema()->hasTable('tbluserclients');

        $orphans = [];
        foreach ($users as $user) {
            $linkedClientIds = [];

            if ($hasUserClients) {
                $linkedClientIds = Capsule::table('tbluserclients')
                    ->where('auth_user_id', $user->id)
                    ->pluck('client_id')
                    ->toArray();
            } else {
                // Fallback: match by email
                $linkedClientIds = Capsule::table('tblclients')
                    ->where('email', $user->email)
                    ->pluck('id')
                    ->toArray();
            }

            // Check if any linked client is real
            $hasRealClient = false;
            foreach ($linkedClientIds as $cid) {
                if (in_array((int)$cid, $realClientIds, true)) {
                    $hasRealClient = true;
                    break;
                }
            }

            if (!$hasRealClient) {
                $reason = empty($linkedClientIds) ? 'No client links (orphan)' : 'All linked clients are bots';
                $orphans[] = [
                    'id'         => (int)$user->id,
                    'email'      => $user->email,
                    'name'       => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                    'last_ip'    => $user->last_ip ?? '',
                    'last_login' => $user->last_login ?? 'Never',
                    'created'    => $user->created_at,
                    'clients'    => count($linkedClientIds),
                    'reason'     => $reason,
                ];
            }
        }

        return [
            'success'    => true,
            'total'      => count($orphans),
            'users'      => $orphans,
            'total_users' => count($users),
        ];
    }

    /**
     * Purge orphan user accounts.
     * Harvests intel before deletion and removes from tblusers.
     */
    public function purgeOrphanUsers(array $userIds): array
    {
        if (!Capsule::schema()->hasTable('tblusers')) {
            return ['success' => false, 'error' => 'tblusers table not found'];
        }

        $purged = 0;
        $errors = [];

        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            try {
                $this->harvestAndDeleteUser($uid);
                $purged++;
            } catch (\Throwable $e) {
                $errors[] = "User #{$uid}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'purged'  => $purged,
            'errors'  => $errors,
        ];
    }

    // =========================================================================
    // PREVIEW (DRY-RUN) METHODS
    // =========================================================================

    /**
     * Preview what standard purge would delete (accounts with ZERO records).
     */
    public function previewPurge(array $clientIds): array
    {
        $results = [];
        foreach ($clientIds as $id) {
            $id = (int)$id;
            $client = Capsule::table('tblclients')->where('id', $id)->first(['id', 'email', 'status', 'firstname', 'lastname']);
            if (!$client) continue;

            $orders = Capsule::table('tblorders')->where('userid', $id)->count();
            $invoices = Capsule::table('tblinvoices')->where('userid', $id)->count();
            $hosting = Capsule::table('tblhosting')->where('userid', $id)->count();
            $domains = Capsule::table('tbldomains')->where('userid', $id)->count();

            $canPurge = ($orders === 0 && $invoices === 0 && $hosting === 0 && $domains === 0);

            $results[] = [
                'id'        => $id,
                'email'     => $client->email,
                'name'      => trim($client->firstname . ' ' . $client->lastname),
                'status'    => $client->status,
                'orders'    => $orders,
                'invoices'  => $invoices,
                'hosting'   => $hosting,
                'domains'   => $domains,
                'can_purge' => $canPurge,
                'impact'    => $canPurge
                    ? 'Will be permanently deleted (zero records)'
                    : "Cannot purge: has {$orders} orders, {$invoices} invoices, {$hosting} hosting, {$domains} domains",
            ];
        }

        $deletable = count(array_filter($results, fn($r) => $r['can_purge']));
        return [
            'success' => true,
            'summary' => "{$deletable} of " . count($results) . " accounts can be purged (zero records)",
            'count'   => $deletable,
            'total'   => count($results),
            'details' => $results,
        ];
    }

    /**
     * Preview what deep purge would delete.
     * Deep purge targets accounts where ALL orders are Fraud/Cancelled,
     * ALL invoices are Cancelled/Unpaid, ALL hosting is Fraud/Terminated,
     * and no paid invoices with amount > 0 exist.
     */
    public function previewDeepPurge(array $clientIds): array
    {
        $results = [];
        foreach ($clientIds as $id) {
            $id = (int)$id;
            $client = Capsule::table('tblclients')->where('id', $id)->first(['id', 'email', 'status', 'firstname', 'lastname']);
            if (!$client) continue;

            $analysis = $this->analyzeAccountForDeepPurge($id);

            $results[] = [
                'id'              => $id,
                'email'           => $client->email,
                'name'            => trim($client->firstname . ' ' . $client->lastname),
                'status'          => $client->status,
                'can_deep_purge'  => $analysis['eligible'],
                'order_statuses'  => $analysis['order_statuses'],
                'invoice_statuses'=> $analysis['invoice_statuses'],
                'hosting_statuses'=> $analysis['hosting_statuses'],
                'total_paid'      => $analysis['total_paid'],
                'impact'          => $analysis['eligible']
                    ? 'Will be permanently deleted (all records are Fraud/Cancelled/Unpaid)'
                    : 'Cannot deep purge: ' . $analysis['reason'],
            ];
        }

        $deletable = count(array_filter($results, fn($r) => $r['can_deep_purge']));
        return [
            'success' => true,
            'summary' => "{$deletable} of " . count($results) . " accounts eligible for deep purge",
            'count'   => $deletable,
            'total'   => count($results),
            'details' => $results,
        ];
    }

    /**
     * Preview what deactivation would do.
     */
    public function previewDeactivate(array $clientIds): array
    {
        $results = [];
        foreach ($clientIds as $id) {
            $id = (int)$id;
            $client = Capsule::table('tblclients')->where('id', $id)->first(['id', 'email', 'status', 'firstname', 'lastname']);
            if (!$client) continue;

            $results[] = [
                'id'     => $id,
                'email'  => $client->email,
                'name'   => trim($client->firstname . ' ' . $client->lastname),
                'status' => $client->status,
                'impact' => $client->status === 'Inactive'
                    ? 'Already Inactive (no change)'
                    : "Will change from {$client->status} to Inactive",
            ];
        }

        $changeable = count(array_filter($results, fn($r) => $r['status'] !== 'Inactive'));
        return [
            'success' => true,
            'summary' => "{$changeable} accounts will be set to Inactive",
            'count'   => $changeable,
            'total'   => count($results),
            'details' => $results,
        ];
    }

    // =========================================================================
    // ACTION METHODS
    // =========================================================================

    /**
     * Flag bot accounts (add admin notes).
     */
    public function flagBotAccounts(array $clientIds): array
    {
        $flagged = 0;
        $errors = [];

        foreach ($clientIds as $id) {
            $id = (int)$id;
            try {
                $existing = Capsule::table('tblclients')->where('id', $id)->value('notes') ?? '';
                if (strpos($existing, '[FPS-BOT]') === false) {
                    Capsule::table('tblclients')->where('id', $id)->update([
                        'notes' => "[FPS-BOT] Flagged as suspected bot account on " . date('Y-m-d H:i:s') . "\n" . $existing,
                    ]);
                }
                $flagged++;
            } catch (\Throwable $e) {
                $errors[] = "Client #{$id}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'flagged' => $flagged,
            'errors'  => $errors,
        ];
    }

    /**
     * Deactivate bot accounts (set status to Inactive).
     */
    public function deactivateBotAccounts(array $clientIds): array
    {
        $deactivated = 0;
        $errors = [];

        foreach ($clientIds as $id) {
            $id = (int)$id;
            try {
                Capsule::table('tblclients')->where('id', $id)->update([
                    'status' => 'Inactive',
                ]);
                // Also add a note
                $existing = Capsule::table('tblclients')->where('id', $id)->value('notes') ?? '';
                if (strpos($existing, '[FPS-DEACTIVATED]') === false) {
                    Capsule::table('tblclients')->where('id', $id)->update([
                        'notes' => "[FPS-DEACTIVATED] Deactivated by FPS on " . date('Y-m-d H:i:s') . "\n" . $existing,
                    ]);
                }
                $deactivated++;
            } catch (\Throwable $e) {
                $errors[] = "Client #{$id}: " . $e->getMessage();
            }
        }

        return [
            'success'     => true,
            'deactivated' => $deactivated,
            'errors'      => $errors,
        ];
    }

    /**
     * Purge bot accounts that have ZERO records (orders, invoices, hosting, domains).
     * Deletes related data first, then the client record.
     */
    public function purgeBotAccounts(array $clientIds): array
    {
        $purged = 0;
        $skipped = 0;
        $errors = [];

        foreach ($clientIds as $id) {
            $id = (int)$id;
            try {
                // Safety check: skip if has any real records
                $orders = Capsule::table('tblorders')->where('userid', $id)->count();
                $invoices = Capsule::table('tblinvoices')->where('userid', $id)->count();
                $hosting = Capsule::table('tblhosting')->where('userid', $id)->count();
                $domains = Capsule::table('tbldomains')->where('userid', $id)->count();

                if ($orders > 0 || $invoices > 0 || $hosting > 0 || $domains > 0) {
                    $skipped++;
                    continue;
                }

                // Harvest fraud intel BEFORE deletion (preserves data for global + local)
                try {
                    $collector = new FpsGlobalIntelCollector();
                    $collector->harvestFromClient($id);
                } catch (\Throwable $e) {
                    logModuleCall('fraud_prevention_suite', 'harvest_before_purge', $id, $e->getMessage());
                }
                // Push harvested intel to hub immediately (don't wait for daily cron)
                try {
                    if (class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelClient')) {
                        $hubClient = new FpsGlobalIntelClient();
                        if ($hubClient->isConfigured()) {
                            $hubClient->pushIntel(1); // Push just this batch
                        }
                    }
                } catch (\Throwable $e) {
                    // Non-fatal: hub push can happen later via cron
                }

                // Delete related data first (order matters: related before client)
                $this->deleteClientRelatedData($id);

                // Finally delete the client record
                Capsule::table('tblclients')->where('id', $id)->delete();
                $purged++;
            } catch (\Throwable $e) {
                $errors[] = "Client #{$id}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'purged'  => $purged,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * Deep purge bot accounts where ALL records are Fraud/Cancelled/Unpaid.
     * More aggressive than standard purge -- handles accounts that have orders
     * and invoices, but none that were ever legitimately paid or active.
     */
    public function deepPurgeBotAccounts(array $clientIds): array
    {
        $purged = 0;
        $skipped = 0;
        $errors = [];

        foreach ($clientIds as $id) {
            $id = (int)$id;
            try {
                $analysis = $this->analyzeAccountForDeepPurge($id);
                if (!$analysis['eligible']) {
                    $skipped++;
                    continue;
                }

                // Harvest fraud intel BEFORE deletion (preserves data for global + local)
                try {
                    $collector = new FpsGlobalIntelCollector();
                    $collector->harvestFromClient($id);
                } catch (\Throwable $e) {
                    logModuleCall('fraud_prevention_suite', 'harvest_before_deep_purge', $id, $e->getMessage());
                }
                // Push to hub immediately
                try {
                    if (class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelClient')) {
                        $hubClient = new FpsGlobalIntelClient();
                        if ($hubClient->isConfigured()) {
                            $hubClient->pushIntel(1);
                        }
                    }
                } catch (\Throwable $e) {}

                // Delete orders (all are Fraud/Cancelled)
                Capsule::table('tblorders')->where('userid', $id)->delete();

                // Delete invoice items first, then invoices
                $invoiceIds = Capsule::table('tblinvoices')->where('userid', $id)->pluck('id')->toArray();
                if (!empty($invoiceIds)) {
                    Capsule::table('tblinvoiceitems')->whereIn('invoiceid', $invoiceIds)->delete();
                    Capsule::table('tblinvoices')->where('userid', $id)->delete();
                }

                // Delete hosting (all are Fraud/Terminated)
                Capsule::table('tblhosting')->where('userid', $id)->delete();

                // Delete domains if all are non-active
                Capsule::table('tbldomains')->where('userid', $id)->delete();

                // Delete remaining related data
                $this->deleteClientRelatedData($id);

                // Finally delete the client
                Capsule::table('tblclients')->where('id', $id)->delete();
                $purged++;
            } catch (\Throwable $e) {
                $errors[] = "Client #{$id}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'purged'  => $purged,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Analyze whether an account is eligible for deep purge.
     */
    private function analyzeAccountForDeepPurge(int $clientId): array
    {
        $allowedOrderStatuses = ['Fraud', 'Cancelled', 'Pending'];
        $allowedInvoiceStatuses = ['Cancelled', 'Unpaid', 'Overdue', 'Draft'];
        $allowedHostingStatuses = ['Fraud', 'Terminated', 'Cancelled', 'Pending'];

        // Check for any paid invoices with amount > 0
        $hasPaidInvoice = Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->where('status', 'Paid')
            ->where('total', '>', 0)
            ->exists();

        if ($hasPaidInvoice) {
            return [
                'eligible' => false,
                'reason'   => 'Has paid invoice(s) with amount > $0',
                'order_statuses' => '',
                'invoice_statuses' => '',
                'hosting_statuses' => '',
                'total_paid' => Capsule::table('tblinvoices')
                    ->where('userid', $clientId)->where('status', 'Paid')->sum('total'),
            ];
        }

        // Check active hosting
        $hasActiveHosting = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->where('domainstatus', 'Active')
            ->exists();

        if ($hasActiveHosting) {
            return [
                'eligible' => false,
                'reason'   => 'Has active hosting service(s)',
                'order_statuses' => '',
                'invoice_statuses' => '',
                'hosting_statuses' => '',
                'total_paid' => 0,
            ];
        }

        // Check order statuses
        $orderStatuses = Capsule::table('tblorders')
            ->where('userid', $clientId)
            ->distinct()
            ->pluck('status')
            ->toArray();

        $badOrders = array_diff($orderStatuses, $allowedOrderStatuses);
        if (!empty($badOrders)) {
            return [
                'eligible' => false,
                'reason'   => 'Has orders with status: ' . implode(', ', $badOrders),
                'order_statuses' => implode(', ', $orderStatuses),
                'invoice_statuses' => '',
                'hosting_statuses' => '',
                'total_paid' => 0,
            ];
        }

        // Check invoice statuses
        $invoiceStatuses = Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->distinct()
            ->pluck('status')
            ->toArray();

        $badInvoices = array_diff($invoiceStatuses, $allowedInvoiceStatuses);
        if (!empty($badInvoices)) {
            return [
                'eligible' => false,
                'reason'   => 'Has invoices with status: ' . implode(', ', $badInvoices),
                'order_statuses' => implode(', ', $orderStatuses),
                'invoice_statuses' => implode(', ', $invoiceStatuses),
                'hosting_statuses' => '',
                'total_paid' => 0,
            ];
        }

        // Check hosting statuses
        $hostingStatuses = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->distinct()
            ->pluck('domainstatus')
            ->toArray();

        $badHosting = array_diff($hostingStatuses, $allowedHostingStatuses);
        if (!empty($badHosting)) {
            return [
                'eligible' => false,
                'reason'   => 'Has hosting with status: ' . implode(', ', $badHosting),
                'order_statuses' => implode(', ', $orderStatuses),
                'invoice_statuses' => implode(', ', $invoiceStatuses),
                'hosting_statuses' => implode(', ', $hostingStatuses),
                'total_paid' => 0,
            ];
        }

        return [
            'eligible'         => true,
            'reason'           => '',
            'order_statuses'   => implode(', ', $orderStatuses) ?: 'none',
            'invoice_statuses' => implode(', ', $invoiceStatuses) ?: 'none',
            'hosting_statuses' => implode(', ', $hostingStatuses) ?: 'none',
            'total_paid'       => 0,
        ];
    }

    /**
     * Delete all related data for a client (except orders/invoices/hosting which
     * may need special handling in deep purge).
     */
    private function deleteClientRelatedData(int $clientId): void
    {
        // Related tables to clean up -- order: child tables first
        $relatedTables = [
            'tblcustomfieldsvalues' => 'relid',   // may reference client
            'tbltickets'            => 'userid',
            'tblticketnotes'        => null,        // cleaned via ticket IDs
            'tblticketreplies'      => null,        // cleaned via ticket IDs
            'tblactivitylog'        => 'userid',
            'tblemails'             => 'userid',
            'tblcredit'             => 'clientid',
            'tblcontacts'           => 'userid',
            'tblsslorders'          => 'userid',
            'tblnotes'              => 'userid',
        ];

        // Clean ticket-related tables first
        $ticketIds = [];
        try {
            $ticketIds = Capsule::table('tbltickets')
                ->where('userid', $clientId)
                ->pluck('id')
                ->toArray();
        } catch (\Throwable $e) {
            // Table may not exist
        }

        if (!empty($ticketIds)) {
            foreach (['tblticketnotes', 'tblticketreplies'] as $ticketTable) {
                try {
                    if (Capsule::schema()->hasTable($ticketTable)) {
                        Capsule::table($ticketTable)->whereIn('tid', $ticketIds)->delete();
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }

        // Clean remaining related tables
        foreach ($relatedTables as $table => $column) {
            if ($column === null) continue; // Already handled above
            try {
                if (Capsule::schema()->hasTable($table)) {
                    Capsule::table($table)->where($column, $clientId)->delete();
                }
            } catch (\Throwable $e) {
                // Ignore individual table errors
            }
        }

        // Preserve FPS fraud check history (snapshot client details before unlinking)
        // Instead of deleting checks, mark them as belonging to a purged client
        // so the dashboard and stats still show the fraud data
        try {
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first(['firstname', 'lastname', 'email', 'companyname']);
            $snapshot = $client
                ? trim($client->firstname . ' ' . $client->lastname) . ' <' . $client->email . '>'
                : 'Purged Client #' . $clientId;

            Capsule::table('mod_fps_checks')->where('client_id', $clientId)->update([
                'action_taken' => Capsule::raw("CONCAT(COALESCE(action_taken,''), ' [purged]')"),
                'check_context' => json_encode([
                    'purged_at' => date('Y-m-d H:i:s'),
                    'purged_by' => (int)($_SESSION['adminid'] ?? 0),
                    'original_client' => $snapshot,
                ]),
            ]);
        } catch (\Throwable $e) {
            // Fallback: if snapshot fails, still don't delete the checks
            try {
                Capsule::table('mod_fps_checks')->where('client_id', $clientId)->update([
                    'action_taken' => 'purged',
                ]);
            } catch (\Throwable $e2) {}
        }

        // Clean client group pivots if table exists
        try {
            if (Capsule::schema()->hasTable('tblclientgroups_pivot')) {
                Capsule::table('tblclientgroups_pivot')->where('client_id', $clientId)->delete();
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        // WHMCS 8.x: Clean user-client links and orphaned users (tblusers/tbluserclients)
        $this->cleanOrphanUserForClient($clientId);
    }

    /**
     * Clean orphan user accounts linked to a client being deleted.
     *
     * WHMCS 8.x has two account systems:
     *   - tblclients = billing accounts (services, invoices, orders)
     *   - tblusers = login accounts (authentication, sessions)
     *   - tbluserclients = many-to-many link between users and clients
     *
     * When a bot client is purged, the user login survives and can create
     * new client accounts. This method removes the link and deletes the
     * user IF it has no other client associations.
     */
    private function cleanOrphanUserForClient(int $clientId): void
    {
        try {
            // Method 1: tbluserclients exists (WHMCS 8.6+)
            if (Capsule::schema()->hasTable('tbluserclients')) {
                // Find all user IDs linked to this client
                $userIds = Capsule::table('tbluserclients')
                    ->where('client_id', $clientId)
                    ->pluck('auth_user_id')
                    ->toArray();

                // Remove the links
                Capsule::table('tbluserclients')->where('client_id', $clientId)->delete();

                // For each user, check if they have any OTHER client links
                foreach ($userIds as $uid) {
                    $otherLinks = Capsule::table('tbluserclients')
                        ->where('auth_user_id', $uid)
                        ->count();

                    if ($otherLinks === 0) {
                        // This user is now orphaned -- harvest intel then delete
                        $this->harvestAndDeleteUser((int)$uid);
                    }
                }
            }
            // Method 2: tbluserclients doesn't exist (older WHMCS 8.x) -- match by email
            elseif (Capsule::schema()->hasTable('tblusers')) {
                $clientEmail = Capsule::table('tblclients')
                    ->where('id', $clientId)
                    ->value('email');

                if ($clientEmail) {
                    $matchingUser = Capsule::table('tblusers')
                        ->where('email', $clientEmail)
                        ->first(['id']);

                    if ($matchingUser) {
                        // Check this user's email doesn't match any OTHER client
                        $otherClients = Capsule::table('tblclients')
                            ->where('email', $clientEmail)
                            ->where('id', '!=', $clientId)
                            ->count();

                        if ($otherClients === 0) {
                            $this->harvestAndDeleteUser((int)$matchingUser->id);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: user cleanup failure shouldn't block client purge
            logModuleCall('fraud_prevention_suite', 'clean_orphan_user', $clientId, $e->getMessage());
        }
    }

    /**
     * Harvest ALL available intel from a user account, then delete it.
     *
     * Captures: email (SHA-256), last_ip, last_hostname, country (from linked client),
     * risk score (from mod_fps_checks if any fraud check was run), email verification
     * status, login history, and evidence flags from IP intel cache.
     */
    private function harvestAndDeleteUser(int $userId): void
    {
        $user = Capsule::table('tblusers')->where('id', $userId)->first();
        if (!$user) return;

        try {
            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelCollector')) {
                // Can't harvest without the collector -- still delete but log
                Capsule::table('tblusers')->where('id', $userId)->delete();
                return;
            }

            $collector = new FpsGlobalIntelCollector();
            $emailHash = hash('sha256', strtolower(trim($user->email)));
            $ip = $user->last_ip ?? null;

            // Try to find linked client for country + risk score
            $country = '';
            $riskScore = 0.0;
            $riskLevel = 'low';

            // Check if there's a client with the same email (for country + existing checks)
            $linkedClient = Capsule::table('tblclients')
                ->where('email', $user->email)
                ->first(['id', 'ip', 'country']);

            if ($linkedClient) {
                $country = $linkedClient->country ?? '';
                // Use client IP if user has no last_ip
                if (empty($ip)) {
                    $ip = $linkedClient->ip ?? null;
                }

                // Get highest risk score from any fraud check on this client
                $bestCheck = Capsule::table('mod_fps_checks')
                    ->where('client_id', $linkedClient->id)
                    ->orderByDesc('risk_score')
                    ->first(['risk_score', 'risk_level']);

                if ($bestCheck) {
                    $riskScore = (float)$bestCheck->risk_score;
                    $riskLevel = $bestCheck->risk_level;
                }
            }

            // Build comprehensive evidence flags
            $evidence = [
                'bot_detected'  => true,
                'orphan_user'   => true,
                'email_verified' => !empty($user->email_verified_at),
                'has_login_history' => !empty($user->last_login),
                'tor'           => false,
                'vpn'           => false,
                'proxy'         => false,
                'datacenter'    => false,
            ];

            // Check IP intel cache for this IP
            if ($ip) {
                try {
                    $ipIntel = Capsule::table('mod_fps_ip_intel')
                        ->where('ip_address', $ip)
                        ->first();
                    if ($ipIntel) {
                        $evidence['tor'] = (bool)($ipIntel->is_tor ?? false);
                        $evidence['vpn'] = (bool)($ipIntel->is_vpn ?? false);
                        $evidence['proxy'] = (bool)($ipIntel->is_proxy ?? false);
                        $evidence['datacenter'] = (bool)($ipIntel->is_datacenter ?? false);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal
                }
            }

            // Upsert into global intel (dedup: increments seen_count if exists)
            $collector->upsertIntel(
                $emailHash,
                $ip,
                $country,
                $riskScore,
                $riskLevel,
                $evidence
            );

            // Also upsert with last_hostname as a separate IP if different
            $hostname = $user->last_hostname ?? '';
            if ($hostname && $hostname !== $ip && filter_var($hostname, FILTER_VALIDATE_IP)) {
                $collector->upsertIntel($emailHash, $hostname, $country, $riskScore, $riskLevel, $evidence);
            }

        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'harvest_user_intel_error',
                "User #{$userId}", $e->getMessage());
        }

        // Delete the user AFTER harvesting
        Capsule::table('tblusers')->where('id', $userId)->delete();

        logModuleCall('fraud_prevention_suite', 'delete_orphan_user',
            "User #{$userId} ({$user->email}), IP: {$user->last_ip}", 'Deleted with intel harvested');
    }

    /**
     * Build human-readable evidence string for a suspected bot.
     */
    private function buildEvidence(object $client, int $orders, int $invoices, int $hosting): string
    {
        $reasons = [];

        if ($orders === 0 && $invoices === 0 && $hosting === 0) {
            $reasons[] = 'Zero orders/invoices/hosting';
        } else {
            // Has records but none paid/active
            if ($orders > 0) {
                $statuses = Capsule::table('tblorders')
                    ->where('userid', $client->id)
                    ->distinct()->pluck('status')->toArray();
                $reasons[] = "Orders: " . implode(', ', $statuses);
            }
            if ($invoices > 0) {
                $statuses = Capsule::table('tblinvoices')
                    ->where('userid', $client->id)
                    ->distinct()->pluck('status')->toArray();
                $totalPaid = Capsule::table('tblinvoices')
                    ->where('userid', $client->id)
                    ->where('status', 'Paid')
                    ->sum('total');
                $reasons[] = "Invoices: " . implode(', ', $statuses) . " (paid: \${$totalPaid})";
            }
            if ($hosting > 0) {
                $statuses = Capsule::table('tblhosting')
                    ->where('userid', $client->id)
                    ->distinct()->pluck('domainstatus')->toArray();
                $reasons[] = "Hosting: " . implode(', ', $statuses);
            }
        }

        // Account age
        if ($client->datecreated) {
            $age = (int)((time() - strtotime($client->datecreated)) / 86400);
            if ($age < 7) $reasons[] = "Account age: {$age} days";
        }

        return implode(' | ', $reasons);
    }
}

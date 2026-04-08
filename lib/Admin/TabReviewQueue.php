<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabReviewQueue -- high/critical risk orders awaiting admin review.
 *
 * Displays filterable table with approve/deny action buttons per row,
 * bulk action bar, and pagination. All actions execute via AJAX.
 */
class TabReviewQueue
{
    public function render(array $vars, string $modulelink): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        // Filters
        $filterLevel  = $_GET['risk_level'] ?? '';
        $filterSearch = $_GET['search'] ?? '';
        $filterFrom   = $_GET['date_from'] ?? '';
        $filterTo     = $_GET['date_to'] ?? '';

        $this->fpsRenderFilterBar($modulelink, $filterLevel, $filterSearch, $filterFrom, $filterTo);

        try {
            // Show unreviewed checks from automated sources (new signups/orders)
            // Exclude manual re-scans and validation tests - those go to client profile
            $filterType = $_GET['check_type'] ?? 'auto_only';
            $query = Capsule::table('mod_fps_checks')
                ->whereNull('reviewed_by');

            if ($filterType === 'auto_only') {
                // Default: only show automated checks (new signups, new orders, bot blocks)
                $query->whereIn('check_type', ['pre_checkout', 'auto', 'bot_signup_block', 'bot_detection']);
            }
            // 'all' shows everything including manual re-scans

            if ($filterLevel !== '' && in_array($filterLevel, ['low', 'medium', 'high', 'critical'], true)) {
                $query->where('risk_level', $filterLevel);
            }
            if ($filterSearch !== '') {
                $query->where(function ($q) use ($filterSearch) {
                    $q->where('email', 'LIKE', '%' . $filterSearch . '%')
                      ->orWhere('ip_address', 'LIKE', '%' . $filterSearch . '%')
                      ->orWhere('order_id', '=', (int)$filterSearch);
                });
            }
            if ($filterFrom !== '') {
                $query->where('created_at', '>=', $filterFrom . ' 00:00:00');
            }
            if ($filterTo !== '') {
                $query->where('created_at', '<=', $filterTo . ' 23:59:59');
            }

            $total      = $query->count();
            $totalPages = max(1, (int)ceil($total / $perPage));

            $checks = $query->orderByDesc('risk_score')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // ---- Batch-fetch client names (single query, avoids N+1) ----
            $clientIds = $checks->pluck('client_id')
                ->filter(fn($id) => (int)$id > 0)
                ->unique()
                ->values()
                ->toArray();

            $clientMap = [];
            if (!empty($clientIds)) {
                Capsule::table('tblclients')
                    ->whereIn('id', $clientIds)
                    ->get(['id', 'firstname', 'lastname', 'email'])
                    ->each(function ($c) use (&$clientMap) {
                        $clientMap[(int)$c->id] = $c;
                    });
            }

            // ---- Queue count badge ----
            $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

            echo '<div class="fps-queue-badge-bar fps-queue-header-row">';
            echo '  <span class="fps-badge fps-badge-high"><i class="fas fa-clock"></i> '
                . htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8')
                . ' checks pending review</span>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-warning" style="margin-left:auto;"'
                . ' onclick="FpsAdmin.bulkAction(\'archive_guest\', \'' . $ajaxUrl . '\')"'
                . ' title="Archive all guest pre-checkout entries that have no associated client account">'
                . '<i class="fas fa-archive"></i> Archive Guest Checks</button>';
            echo '</div>';

            // ---- Bulk actions bar ----
            echo '<div class="fps-bulk-actions-bar">';
            echo '  <label class="fps-checkbox-label">';
            echo '    <input type="checkbox" id="fps-select-all-queue" onclick="FpsAdmin.toggleSelectAll(\'fps-queue-check\')">';
            echo '    <span>Select All</span>';
            echo '  </label>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-success" onclick="FpsAdmin.bulkAction(\'approve\', \'' . $ajaxUrl . '\')">';
            echo '    <i class="fas fa-check"></i> Bulk Approve';
            echo '  </button>';
            echo '  <button type="button" class="fps-btn fps-btn-sm fps-btn-danger" onclick="FpsAdmin.bulkAction(\'deny\', \'' . $ajaxUrl . '\')">';
            echo '    <i class="fas fa-times"></i> Bulk Deny';
            echo '  </button>';
            echo '</div>';

            // ---- Build table rows ----
            $headers = ['', 'Client', 'Email', 'Type', 'Order', 'Risk Score', 'IP', 'Country', 'Time', 'Actions'];
            $rows = [];

            foreach ($checks as $check) {
                $checkIdSafe  = (int)$check->id;
                $clientIdSafe = (int)$check->client_id;
                $orderIdSafe  = (int)$check->order_id;
                $checkType    = $check->check_type ?? 'auto';
                $isGuest      = ($clientIdSafe === 0);
                $client       = $clientMap[$clientIdSafe] ?? null;
                $clientExists = ($client !== null);

                // ---- Client cell ----
                $checkEmail = htmlspecialchars($check->email ?? '', ENT_QUOTES, 'UTF-8');
                if ($isGuest) {
                    // No client yet — pre-checkout visitor
                    $clientName = '<span class="fps-queue-guest-cell">'
                        . '<span class="fps-badge fps-badge-pending" style="font-size:0.68rem;">'
                        . '<i class="fas fa-user-clock"></i> Guest</span>'
                        . '<span class="fps-queue-guest-note">Pre-checkout visitor</span>'
                        . '</span>';
                } elseif (!$clientExists) {
                    // Client was deleted after the check was recorded
                    $clientName = '<span class="fps-queue-deleted-cell">'
                        . '<i class="fas fa-user-slash fps-text-muted"></i>'
                        . ' <span class="fps-text-muted fps-mono" style="font-size:0.8rem;">Deleted #' . $clientIdSafe . '</span>'
                        . '</span>';
                } else {
                    $name = trim($client->firstname . ' ' . $client->lastname);
                    $displayName = $name !== '' ? $name : 'Client #' . $clientIdSafe;
                    $profileUrl  = htmlspecialchars(
                        $modulelink . '&tab=client_profile&client_id=' . $clientIdSafe,
                        ENT_QUOTES, 'UTF-8'
                    );
                    $clientName  = '<div class="fps-queue-client-cell">'
                        . '<a href="' . $profileUrl . '" class="fps-queue-client-name">'
                        . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</a>'
                        . '<span class="fps-queue-client-id fps-mono">#' . $clientIdSafe . '</span>'
                        . '</div>';
                }

                // Use client's confirmed email if available, otherwise the check email
                $displayEmail = ($clientExists && !empty($client->email))
                    ? htmlspecialchars($client->email, ENT_QUOTES, 'UTF-8')
                    : $checkEmail;

                // ---- Check type badge ----
                $typeLabels = [
                    'pre_checkout'     => ['fps-badge-pending',  'fa-cart-shopping', 'Pre-checkout'],
                    'auto'             => ['fps-badge-info',     'fa-bolt',          'New Order'],
                    'registration'     => ['fps-badge-info',     'fa-user-plus',     'Registration'],
                    'bot_signup_block' => ['fps-badge-blocked',  'fa-robot',         'Bot Block'],
                    'bot_detection'    => ['fps-badge-blocked',  'fa-robot',         'Bot'],
                    'manual'           => ['fps-scan-badge-none','fa-hand',          'Manual'],
                    'engine_validation'=> ['fps-scan-badge-none','fa-flask',         'Test'],
                ];
                [$typeBadgeCls, $typeIcon, $typeLabel] = $typeLabels[$checkType]
                    ?? ['fps-scan-badge-none', 'fa-question-circle', ucfirst($checkType)];
                $typeBadge = '<span class="fps-badge ' . $typeBadgeCls . '" style="font-size:0.68rem;">'
                    . '<i class="fas ' . $typeIcon . '"></i> ' . $typeLabel . '</span>';

                // ---- Order cell ----
                $orderCell = ($orderIdSafe > 0)
                    ? '<a href="orders.php?action=view&id=' . $orderIdSafe . '" class="fps-mono fps-text-muted" style="font-size:0.85rem;">#' . $orderIdSafe . '</a>'
                    : '<span class="fps-text-muted">—</span>';

                // ---- Risk badge ----
                $badge = FpsAdminRenderer::renderBadge($check->risk_level, (float)$check->risk_score);

                // ---- IP / Country ----
                $ip      = htmlspecialchars($check->ip_address ?? '—', ENT_QUOTES, 'UTF-8');
                $country = htmlspecialchars($check->country ?? '—', ENT_QUOTES, 'UTF-8');
                $time    = htmlspecialchars($check->created_at ?? '', ENT_QUOTES, 'UTF-8');

                // ---- Actions ----
                $actions = '<div class="fps-action-group">';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-success"'
                    . ' onclick="FpsAdmin.approveCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Approve — mark reviewed, allow">'
                    . '<i class="fas fa-check"></i></button>';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger"'
                    . ' onclick="FpsAdmin.denyCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Deny — mark reviewed, block">'
                    . '<i class="fas fa-times"></i></button>';

                if (!$isGuest && $clientExists) {
                    // Real client — show profile link
                    $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . $clientIdSafe, ENT_QUOTES, 'UTF-8');
                    $actions .= '<a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-info" title="View FPS Client Profile">'
                        . '<i class="fas fa-user-shield"></i></a>';
                } else {
                    // Guest or deleted — search by email instead
                    $emailSearch = urlencode($check->email ?? '');
                    $actions .= '<a href="clients.php?search=' . $emailSearch . '" class="fps-btn fps-btn-xs fps-btn-secondary" title="Search WHMCS for this email">'
                        . '<i class="fas fa-search"></i></a>';
                }

                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-warning"'
                    . ' onclick="FpsAdmin.reportToFraudRecord(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Report IP/email to FraudRecord">'
                    . '<i class="fas fa-flag"></i></button>';
                $actions .= '</div>';

                $rows[] = [
                    '<input type="checkbox" class="fps-queue-check" value="' . $checkIdSafe . '">',
                    $clientName,
                    $displayEmail,
                    $typeBadge,
                    $orderCell,
                    $badge,
                    $ip,
                    $country,
                    $time,
                    $actions,
                ];
            }

            echo FpsAdminRenderer::renderTable($headers, $rows, 'fps-review-queue-table');

            // Pagination
            $paginationBase = $modulelink . '&tab=review_queue';
            if ($filterLevel !== '') {
                $paginationBase .= '&risk_level=' . urlencode($filterLevel);
            }
            if ($filterSearch !== '') {
                $paginationBase .= '&search=' . urlencode($filterSearch);
            }
            echo FpsAdminRenderer::renderPagination($page, $totalPages, $paginationBase);

        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error loading review queue: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }

        // Unscanned clients/users section
        $this->fpsRenderUnscannedSection($modulelink);
    }

    /**
     * Render the filter bar with risk level, date range, and search inputs.
     */
    private function fpsRenderFilterBar(
        string $modulelink,
        string $filterLevel,
        string $filterSearch,
        string $filterFrom,
        string $filterTo
    ): void {
        $actionUrl = htmlspecialchars($modulelink . '&tab=review_queue', ENT_QUOTES, 'UTF-8');

        echo '<div class="fps-filter-bar">';
        echo '<form method="GET" action="" class="fps-filter-form">';

        // Preserve modulelink params
        echo '<input type="hidden" name="module" value="fraud_prevention_suite">';
        echo '<input type="hidden" name="tab" value="review_queue">';

        // Source filter (auto vs all)
        $filterType = $_GET['check_type'] ?? 'auto_only';
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-filter"></i> Source</label>';
        echo '  <select name="check_type" class="fps-select">';
        echo '    <option value="auto_only"' . ($filterType === 'auto_only' ? ' selected' : '') . '>New Signups & Orders</option>';
        echo '    <option value="all"' . ($filterType === 'all' ? ' selected' : '') . '>All Checks (incl. re-scans)</option>';
        echo '  </select>';
        echo '</div>';

        // Risk level
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-shield-halved"></i> Risk Level</label>';
        echo '  <select name="risk_level" class="fps-select">';
        echo '    <option value="">All Levels</option>';
        echo '    <option value="critical"' . ($filterLevel === 'critical' ? ' selected' : '') . '>Critical</option>';
        echo '    <option value="high"' . ($filterLevel === 'high' ? ' selected' : '') . '>High</option>';
        echo '    <option value="medium"' . ($filterLevel === 'medium' ? ' selected' : '') . '>Medium</option>';
        echo '    <option value="low"' . ($filterLevel === 'low' ? ' selected' : '') . '>Low</option>';
        echo '  </select>';
        echo '</div>';

        // Date from
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-calendar"></i> From</label>';
        echo '  <input type="date" name="date_from" class="fps-input" value="' . htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Date to
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-calendar-check"></i> To</label>';
        echo '  <input type="date" name="date_to" class="fps-input" value="' . htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Search
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-search"></i> Search</label>';
        echo '  <input type="text" name="search" class="fps-input" placeholder="Email, IP, or Order #" value="' . htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') . '">';
        echo '</div>';

        // Submit
        echo '<div class="fps-form-group" style="padding-top:24px;">';
        echo '  <button type="submit" class="fps-btn fps-btn-sm fps-btn-primary"><i class="fas fa-filter"></i> Filter</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Show clients and users that have NEVER been scanned.
     */
    private function fpsRenderUnscannedSection(string $modulelink): void
    {
        try {
            // Find clients with zero fraud checks
            $scannedIds = Capsule::table('mod_fps_checks')
                ->distinct()->pluck('client_id')->toArray();

            $unscannedClients = Capsule::table('tblclients')
                ->whereNotIn('id', $scannedIds ?: [0])
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'firstname', 'lastname', 'email', 'status', 'datecreated', 'ip']);

            // Count unscanned users (tblusers)
            // A tblusers account is only "unscanned" when:
            //   1. Its email hasn't appeared in any fraud check, AND
            //   2. It is linked (via tblusers_clients) to at least one EXISTING client
            //      that has NOT been scanned yet.
            // This filters out:
            //   - Users linked only to deleted clients (orphaned tblusers_clients rows)
            //   - Users with no client link at all (admin/system accounts)
            //   - Users whose linked clients have ALL been scanned already
            $unscannedUserCount = 0;
            if (Capsule::schema()->hasTable('tblusers')) {
                $checkedEmails = Capsule::table('mod_fps_checks')
                    ->whereNotNull('email')
                    ->distinct()->pluck('email')->toArray();

                $userQuery = Capsule::table('tblusers')
                    ->whereNotIn('email', $checkedEmails ?: ['']);

                // WHMCS 8.x uses tblusers_clients (auth_user_id -> client_id).
                // Some installations may use tblclients_users (users_id -> clients_id).
                // The JOIN on tblclients ensures we ignore links to deleted clients
                // (e.g. user linked only to a purged client_id stays filtered out).
                if (Capsule::schema()->hasTable('tblusers_clients')) {
                    $userQuery->whereExists(function ($sub) use ($scannedIds) {
                        $sub->selectRaw('1')
                            ->from('tblusers_clients')
                            ->join('tblclients', 'tblclients.id', '=', 'tblusers_clients.client_id')
                            ->whereRaw('tblusers_clients.auth_user_id = tblusers.id')
                            ->whereNotIn('tblusers_clients.client_id', $scannedIds ?: [0]);
                    });
                } elseif (Capsule::schema()->hasTable('tblclients_users')) {
                    $userQuery->whereExists(function ($sub) use ($scannedIds) {
                        $sub->selectRaw('1')
                            ->from('tblclients_users')
                            ->join('tblclients', 'tblclients.id', '=', 'tblclients_users.clients_id')
                            ->whereRaw('tblclients_users.users_id = tblusers.id')
                            ->whereNotIn('tblclients_users.clients_id', $scannedIds ?: [0]);
                    });
                }

                $unscannedUserCount = $userQuery->count();
            }

            $clientCount = count($unscannedClients);
            $totalUnscanned = Capsule::table('tblclients')
                ->whereNotIn('id', $scannedIds ?: [0])->count();

            if ($totalUnscanned === 0 && $unscannedUserCount === 0) {
                return; // All accounts have been scanned
            }

            $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
            $scanLink = htmlspecialchars($modulelink . '&tab=mass_scan', ENT_QUOTES, 'UTF-8');

            echo '<div class="fps-card" style="margin-top:1.5rem;">';
            echo '<div class="fps-card-header" style="background:linear-gradient(135deg,#f5a623,#f7c948);">';
            echo '<h3 style="color:#1a1a2e;"><i class="fas fa-exclamation-triangle"></i> Unscanned Accounts</h3>';
            echo '<span class="fps-badge" style="background:rgba(0,0,0,0.15);color:#1a1a2e;">' . $totalUnscanned . ' clients + ' . $unscannedUserCount . ' users</span>';
            echo '</div>';
            echo '<div class="fps-card-body">';

            echo '<p style="margin:0 0 1rem;font-size:0.9rem;">These accounts have never been scanned by the fraud detection system. '
                . '<a href="' . $scanLink . '" style="color:#667eea;font-weight:600;">Run a Mass Scan</a> to check them all, or click individual scan buttons below.</p>';

            if ($clientCount > 0) {
                echo '<div style="overflow-x:auto;">';
                echo '<table class="fps-table"><thead><tr>';
                echo '<th>Client ID</th><th>Name</th><th>Email</th><th>Status</th><th>Registered</th><th>IP</th><th>Action</th>';
                echo '</tr></thead><tbody>';

                foreach ($unscannedClients as $c) {
                    $name = htmlspecialchars(trim($c->firstname . ' ' . $c->lastname), ENT_QUOTES, 'UTF-8');
                    $email = htmlspecialchars($c->email, ENT_QUOTES, 'UTF-8');
                    $statusClass = $c->status === 'Active' ? 'fps-badge-low' : ($c->status === 'Inactive' ? 'fps-badge-medium' : '');
                    $profileUrl = htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . (int)$c->id, ENT_QUOTES, 'UTF-8');

                    echo '<tr>';
                    echo '<td>#' . (int)$c->id . '</td>';
                    echo '<td>' . $name . '</td>';
                    echo '<td style="font-size:0.85rem;">' . $email . '</td>';
                    echo '<td><span class="fps-badge ' . $statusClass . '">' . htmlspecialchars($c->status, ENT_QUOTES, 'UTF-8') . '</span></td>';
                    echo '<td style="font-size:0.85rem;">' . htmlspecialchars($c->datecreated ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td style="font-size:0.85rem;">' . htmlspecialchars($c->ip ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td><a href="' . $profileUrl . '" class="fps-btn fps-btn-xs fps-btn-primary"><i class="fas fa-search"></i> Scan</a></td>';
                    echo '</tr>';
                }

                echo '</tbody></table></div>';

                if ($totalUnscanned > $clientCount) {
                    echo '<p style="margin-top:0.5rem;font-size:0.85rem;opacity:0.7;">Showing ' . $clientCount . ' of ' . $totalUnscanned . ' unscanned clients. <a href="' . $scanLink . '">Run Mass Scan</a> to check all.</p>';
                }
            }

            if ($unscannedUserCount > 0) {
                echo '<div style="margin-top:1rem;padding:0.75rem;border-radius:8px;background:rgba(245,166,35,0.06);border:1px solid rgba(245,166,35,0.15);">';
                echo '<strong><i class="fas fa-user-slash"></i> ' . $unscannedUserCount . ' user accounts</strong> have never been scanned. ';
                echo 'Go to <a href="' . htmlspecialchars($modulelink . '&tab=bot_cleanup', ENT_QUOTES, 'UTF-8') . '" style="color:#667eea;font-weight:600;">Bot Cleanup</a> to scan and clean up user accounts.';
                echo '</div>';
            }

            echo '</div></div>';
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}

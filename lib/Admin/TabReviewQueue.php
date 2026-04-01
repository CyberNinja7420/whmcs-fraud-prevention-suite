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
            $query = Capsule::table('mod_fps_checks')
                ->whereIn('risk_level', ['high', 'critical'])
                ->whereNull('reviewed_by');

            if ($filterLevel !== '' && in_array($filterLevel, ['high', 'critical'], true)) {
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

            // Queue count badge
            echo '<div class="fps-queue-badge-bar">';
            echo '  <span class="fps-badge fps-badge-high"><i class="fas fa-exclamation-triangle"></i> ';
            echo htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') . ' orders pending review</span>';
            echo '</div>';

            // Bulk actions bar
            $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
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

            // Build table rows
            $headers = ['', 'Client', 'Email', 'Order #', 'Risk Score', 'IP', 'Country', 'Time', 'Actions'];
            $rows = [];

            foreach ($checks as $check) {
                $client = Capsule::table('tblclients')
                    ->where('id', $check->client_id)
                    ->first(['id', 'firstname', 'lastname', 'email']);

                $clientName = $client
                    ? htmlspecialchars($client->firstname . ' ' . $client->lastname, ENT_QUOTES, 'UTF-8')
                    : 'Client #' . (int)$check->client_id;
                $clientEmail = $client
                    ? htmlspecialchars($client->email, ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars($check->email ?? '', ENT_QUOTES, 'UTF-8');

                $checkIdSafe = (int)$check->id;
                $clientIdSafe = (int)$check->client_id;
                $orderIdSafe = (int)$check->order_id;

                $checkbox = '<input type="checkbox" class="fps-queue-check" value="' . $checkIdSafe . '">';

                $badge = FpsAdminRenderer::renderBadge($check->risk_level, (float)$check->risk_score);

                $ip      = htmlspecialchars($check->ip_address ?? '--', ENT_QUOTES, 'UTF-8');
                $country = htmlspecialchars($check->country ?? '--', ENT_QUOTES, 'UTF-8');
                $time    = htmlspecialchars($check->created_at ?? '', ENT_QUOTES, 'UTF-8');

                $actions  = '<div class="fps-action-group">';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-success" '
                    . 'onclick="FpsAdmin.approveCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Approve">'
                    . '<i class="fas fa-check"></i></button>';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger" '
                    . 'onclick="FpsAdmin.denyCheck(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Deny">'
                    . '<i class="fas fa-times"></i></button>';
                $actions .= '<a href="' . htmlspecialchars($modulelink . '&tab=client_profile&client_id=' . $clientIdSafe, ENT_QUOTES, 'UTF-8')
                    . '" class="fps-btn fps-btn-xs fps-btn-info" title="View Profile">'
                    . '<i class="fas fa-user-shield"></i></a>';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-warning" '
                    . 'onclick="FpsAdmin.reportToFraudRecord(' . $checkIdSafe . ', \'' . $ajaxUrl . '\')" title="Report to FraudRecord">'
                    . '<i class="fas fa-flag"></i></button>';
                $actions .= '</div>';

                $rows[] = [$checkbox, $clientName, $clientEmail, '#' . $orderIdSafe, $badge, $ip, $country, $time, $actions];
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

        // Risk level
        echo '<div class="fps-form-group">';
        echo '  <label><i class="fas fa-shield-halved"></i> Risk Level</label>';
        echo '  <select name="risk_level" class="fps-select">';
        echo '    <option value="">All</option>';
        echo '    <option value="high"' . ($filterLevel === 'high' ? ' selected' : '') . '>High</option>';
        echo '    <option value="critical"' . ($filterLevel === 'critical' ? ' selected' : '') . '>Critical</option>';
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
}

<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabReports -- FraudRecord reports and internal reporting.
 *
 * Shows report statistics, reports table with detail modals,
 * and a form to submit new reports.
 */
class TabReports
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderReportStats();
        $this->fpsRenderReportsTable($modulelink, $ajaxUrl);
        $this->fpsRenderSubmitForm($ajaxUrl);
        $this->fpsRenderDetailModal($ajaxUrl);
    }

    /**
     * Report statistics cards row.
     */
    private function fpsRenderReportStats(): void
    {
        $stats = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'false_positive' => 0];

        try {
            $stats['total']          = Capsule::table('mod_fps_reports')->count();
            $stats['pending']        = Capsule::table('mod_fps_reports')->where('status', 'pending')->count();
            $stats['confirmed']      = Capsule::table('mod_fps_reports')->where('status', 'confirmed')->count();
            $stats['false_positive'] = Capsule::table('mod_fps_reports')->where('status', 'false_positive')->count();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        echo '<div class="fps-stats-grid fps-stats-grid-4">';
        echo FpsAdminRenderer::renderStatCard('Total Reports', $stats['total'], 'fa-flag', 'primary');
        echo FpsAdminRenderer::renderStatCard('Pending', $stats['pending'], 'fa-clock', 'warning');
        echo FpsAdminRenderer::renderStatCard('Confirmed', $stats['confirmed'], 'fa-check-circle', 'success');
        echo FpsAdminRenderer::renderStatCard('False Positives', $stats['false_positive'], 'fa-times-circle', 'danger');
        echo '</div>';
    }

    /**
     * Reports table with all submitted reports.
     */
    private function fpsRenderReportsTable(string $modulelink, string $ajaxUrl): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        try {
            $total      = Capsule::table('mod_fps_reports')->count();
            $totalPages = max(1, (int)ceil($total / $perPage));

            $reports = Capsule::table('mod_fps_reports')
                ->orderByDesc('submitted_at')
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->toArray();

            $headers = ['Check ID', 'Client', 'Email', 'IP', 'Type', 'Status', 'Submitted', 'Actions'];
            $rows = [];

            foreach ($reports as $report) {
                $reportId = (int)$report->id;
                $checkId  = (int)$report->check_id;
                $clientId = (int)$report->client_id;

                $client = null;
                try {
                    $client = Capsule::table('tblclients')
                        ->where('id', $clientId)
                        ->first(['firstname', 'lastname', 'email']);
                } catch (\Throwable $e) {
                    // Non-fatal
                }

                $clientName = $client
                    ? htmlspecialchars($client->firstname . ' ' . $client->lastname, ENT_QUOTES, 'UTF-8')
                    : 'Client #' . $clientId;
                $email = $client
                    ? htmlspecialchars($client->email, ENT_QUOTES, 'UTF-8')
                    : '--';

                $check = null;
                try {
                    $check = Capsule::table('mod_fps_checks')
                        ->where('id', $checkId)
                        ->first(['ip_address']);
                } catch (\Throwable $e) {
                    // Non-fatal
                }
                $ip = $check ? htmlspecialchars($check->ip_address ?? '--', ENT_QUOTES, 'UTF-8') : '--';

                $typeBadge = match ($report->report_type) {
                    'fraudrecord' => '<span class="fps-badge fps-badge-warning">FraudRecord</span>',
                    'internal'    => '<span class="fps-badge fps-badge-info">Internal</span>',
                    default       => '<span class="fps-badge fps-badge-medium">' . htmlspecialchars($report->report_type, ENT_QUOTES, 'UTF-8') . '</span>',
                };

                $statusBadge = match ($report->status) {
                    'pending'        => '<span class="fps-badge fps-badge-warning">Pending</span>',
                    'confirmed'      => '<span class="fps-badge fps-badge-low">Confirmed</span>',
                    'false_positive' => '<span class="fps-badge fps-badge-danger">False Positive</span>',
                    'submitted'      => '<span class="fps-badge fps-badge-info">Submitted</span>',
                    default          => '<span class="fps-badge fps-badge-medium">' . htmlspecialchars($report->status, ENT_QUOTES, 'UTF-8') . '</span>',
                };

                $submitted = htmlspecialchars($report->submitted_at ?? '', ENT_QUOTES, 'UTF-8');

                $actions  = '<div class="fps-action-group">';
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-info" '
                    . 'onclick="FpsAdmin.viewReportDetail(' . $reportId . ', \'' . $ajaxUrl . '\')" title="View Details">'
                    . '<i class="fas fa-eye"></i></button>';
                if ($report->status === 'pending') {
                    $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-success" '
                        . 'onclick="FpsAdmin.updateReportStatus(' . $reportId . ', \'confirmed\', \'' . $ajaxUrl . '\')" title="Confirm">'
                        . '<i class="fas fa-check"></i></button>';
                    $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger" '
                        . 'onclick="FpsAdmin.updateReportStatus(' . $reportId . ', \'false_positive\', \'' . $ajaxUrl . '\')" title="False Positive">'
                        . '<i class="fas fa-times"></i></button>';
                }
                $actions .= '</div>';

                $rows[] = ['#' . $checkId, $clientName, $email, $ip, $typeBadge, $statusBadge, $submitted, $actions];
            }

            echo FpsAdminRenderer::renderCard(
                'Fraud Reports (' . $total . ')',
                'fa-flag',
                FpsAdminRenderer::renderTable($headers, $rows, 'fps-reports-table')
            );

            echo FpsAdminRenderer::renderPagination($page, $totalPages, $modulelink . '&tab=reports');

        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error loading reports: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
        }
    }

    /**
     * Submit report form.
     */
    private function fpsRenderSubmitForm(string $ajaxUrl): void
    {
        $options = '<option value="">-- Select a fraud check --</option>';
        try {
            $checks = Capsule::table('mod_fps_checks')
                ->where('reported', 0)
                ->whereIn('risk_level', ['medium', 'high', 'critical'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'client_id', 'email', 'risk_level', 'risk_score', 'created_at']);

            foreach ($checks as $c) {
                $cId    = (int)$c->id;
                $cEmail = htmlspecialchars($c->email ?? 'Client #' . $c->client_id, ENT_QUOTES, 'UTF-8');
                $cLevel = strtoupper($c->risk_level ?? 'unknown');
                $cScore = number_format((float)$c->risk_score, 1);
                $cDate  = htmlspecialchars(substr($c->created_at ?? '', 0, 10), ENT_QUOTES, 'UTF-8');
                $options .= '<option value="' . $cId . '">Check #' . $cId . ' - ' . $cEmail
                    . ' [' . $cLevel . ' ' . $cScore . '] ' . $cDate . '</option>';
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $formContent = <<<HTML
<form id="fps-report-form">
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:2;">
    <label for="fps-report-check"><i class="fas fa-search"></i> Select Fraud Check</label>
    <select id="fps-report-check" name="check_id" class="fps-select">
      {$options}
    </select>
  </div>
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-report-reason"><i class="fas fa-list"></i> Reason</label>
    <select id="fps-report-reason" name="reason" class="fps-select">
      <option value="fraudulent_order">Fraudulent Order</option>
      <option value="stolen_payment">Stolen Payment Method</option>
      <option value="identity_theft">Identity Theft</option>
      <option value="abuse">Service Abuse</option>
      <option value="chargeback">Chargeback</option>
      <option value="other">Other</option>
    </select>
  </div>
</div>
<div class="fps-form-group">
  <label for="fps-report-notes"><i class="fas fa-pencil"></i> Notes</label>
  <textarea id="fps-report-notes" name="notes" class="fps-input fps-textarea" rows="3"
    placeholder="Additional details about this fraud report..."></textarea>
</div>
<div class="fps-form-row fps-form-row-right">
  <button type="button" class="fps-btn fps-btn-md fps-btn-warning"
    onclick="FpsAdmin.submitReport('{$ajaxUrl}')">
    <i class="fas fa-paper-plane"></i> Submit Report
  </button>
</div>
</form>
HTML;

        echo FpsAdminRenderer::renderCard('Submit New Report', 'fa-paper-plane', $formContent);
    }

    /**
     * Report detail modal (populated via JS on click).
     */
    private function fpsRenderDetailModal(string $ajaxUrl): void
    {
        $content = <<<HTML
<div id="fps-report-detail-content">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:80%"></div>
    <div class="fps-skeleton-line" style="width:90%"></div>
  </div>
</div>
HTML;

        $footer = FpsAdminRenderer::renderButton(
            'Confirm Report', 'fa-check', "FpsAdmin.confirmReport('{$ajaxUrl}')", 'success', 'md',
            'id="fps-report-confirm-btn"'
        );
        $footer .= ' ';
        $footer .= FpsAdminRenderer::renderButton(
            'Dismiss', 'fa-times', "FpsAdmin.closeModal('fps-report-detail-modal')", 'outline', 'md'
        );

        echo FpsAdminRenderer::renderModal('fps-report-detail-modal', 'Report Details', $content, $footer);
    }
}

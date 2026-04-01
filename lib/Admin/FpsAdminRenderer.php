<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


/**
 * FpsAdminRenderer -- shared rendering helpers for all admin tab classes.
 *
 * Every method is static so tab classes can call them without instantiation.
 * All output uses the fps- CSS class prefix and Font Awesome 6 icons.
 */
class FpsAdminRenderer
{
    /**
     * Wrap content inside a 1000X-styled card.
     *
     * @param string $title   Card header title text
     * @param string $icon    Font Awesome 6 class (e.g. "fa-chart-line")
     * @param string $content Inner HTML body
     * @param string $extra   Extra CSS classes for the card div
     * @return string
     */
    public static function renderCard(string $title, string $icon, string $content, string $extra = ''): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $extraAttr = $extra !== '' ? ' ' . $extra : '';

        return <<<HTML
<div class="fps-card{$extraAttr}">
  <div class="fps-card-header fps-card-header-gradient">
    <h3><i class="fas {$icon}"></i> {$safeTitle}</h3>
  </div>
  <div class="fps-card-body">
    {$content}
  </div>
</div>
HTML;
    }

    /**
     * Render a single animated stat card with optional sparkline canvas.
     *
     * @param string  $label     Stat label (e.g. "Checks Today")
     * @param mixed   $value     Numeric value to display
     * @param string  $icon      Font Awesome 6 class
     * @param string  $gradient  CSS gradient class (primary/success/danger/warning/info)
     * @param array   $sparkline Optional array of 7 numeric values for mini chart
     * @return string
     */
    public static function renderStatCard(string $label, mixed $value, string $icon, string $gradient, array $sparkline = []): string
    {
        $safeLabel    = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeValue    = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $sparkId      = 'fps-spark-' . md5($label . microtime());
        $sparkData    = htmlspecialchars(json_encode($sparkline), ENT_QUOTES, 'UTF-8');
        $sparklineHtml = '';

        if (!empty($sparkline)) {
            $sparklineHtml = '<canvas id="' . $sparkId . '" class="fps-sparkline" data-values="' . $sparkData . '" width="100" height="30"></canvas>';
        }

        return <<<HTML
<div class="fps-stat-card">
  <div class="fps-stat-icon fps-gradient-{$gradient}">
    <i class="fas {$icon}"></i>
  </div>
  <div class="fps-stat-content">
    <div class="fps-stat-value" data-countup="{$safeValue}">{$safeValue}</div>
    <div class="fps-stat-label">{$safeLabel}</div>
    {$sparklineHtml}
  </div>
</div>
HTML;
    }

    /**
     * Render a responsive data table with header and rows.
     *
     * @param array  $headers Column header labels
     * @param array  $rows    Array of row arrays (each row is an array of cell HTML)
     * @param string $tableId Optional HTML id attribute
     * @return string
     */
    public static function renderTable(array $headers, array $rows, string $tableId = ''): string
    {
        $idAttr = $tableId !== '' ? ' id="' . htmlspecialchars($tableId, ENT_QUOTES, 'UTF-8') . '"' : '';

        $html = '<div class="fps-table-responsive"><table class="fps-table"' . $idAttr . '>';
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $colspan = count($headers);
            $html .= '<tr><td colspan="' . $colspan . '" class="fps-table-empty">';
            $html .= '<i class="fas fa-inbox"></i> No data available';
            $html .= '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    // Cells may contain raw HTML (badges, buttons), so no escaping here
                    $html .= '<td>' . $cell . '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    /**
     * Render pagination controls.
     *
     * @param int    $page       Current page (1-based)
     * @param int    $totalPages Total number of pages
     * @param string $baseUrl    URL pattern (page number appended as &page=N)
     * @return string
     */
    public static function renderPagination(int $page, int $totalPages, string $baseUrl): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<div class="fps-pagination">';

        // Previous
        if ($page > 1) {
            $prevUrl = htmlspecialchars($baseUrl . '&page=' . ($page - 1), ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $prevUrl . '" class="fps-page-btn"><i class="fas fa-chevron-left"></i></a>';
        } else {
            $html .= '<span class="fps-page-btn disabled"><i class="fas fa-chevron-left"></i></span>';
        }

        // Page numbers (show max 7 pages with ellipsis)
        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);

        if ($start > 1) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . '&page=1', ENT_QUOTES, 'UTF-8') . '" class="fps-page-btn">1</a>';
            if ($start > 2) {
                $html .= '<span class="fps-page-ellipsis">...</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $active = ($i === $page) ? ' active' : '';
            $url = htmlspecialchars($baseUrl . '&page=' . $i, ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $url . '" class="fps-page-btn' . $active . '">' . $i . '</a>';
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<span class="fps-page-ellipsis">...</span>';
            }
            $html .= '<a href="' . htmlspecialchars($baseUrl . '&page=' . $totalPages, ENT_QUOTES, 'UTF-8') . '" class="fps-page-btn">' . $totalPages . '</a>';
        }

        // Next
        if ($page < $totalPages) {
            $nextUrl = htmlspecialchars($baseUrl . '&page=' . ($page + 1), ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $nextUrl . '" class="fps-page-btn"><i class="fas fa-chevron-right"></i></a>';
        } else {
            $html .= '<span class="fps-page-btn disabled"><i class="fas fa-chevron-right"></i></span>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render an empty-state message with icon.
     *
     * @param string $message Display message
     * @return string
     */
    public static function renderEmpty(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="fps-empty-state">
  <i class="fas fa-folder-open"></i>
  <p>{$safe}</p>
</div>
HTML;
    }

    /**
     * Render a risk-level badge with optional numeric score.
     *
     * @param string     $level Risk level (low/medium/high/critical)
     * @param float|null $score Optional numeric score to show in parentheses
     * @return string
     */
    public static function renderBadge(string $level, ?float $score = null): string
    {
        $safeLevel = htmlspecialchars($level, ENT_QUOTES, 'UTF-8');
        $text = strtoupper($safeLevel);
        if ($score !== null) {
            $text .= ' (' . number_format($score, 1) . ')';
        }
        return '<span class="fps-badge fps-badge-' . $safeLevel . '">' . $text . '</span>';
    }

    /**
     * Render an action button.
     *
     * @param string $label   Button text
     * @param string $icon    Font Awesome icon class
     * @param string $onclick JavaScript onclick handler (FpsAdmin.* method)
     * @param string $variant Button variant (primary/success/danger/warning/info/outline)
     * @param string $size    Button size (sm/md/lg)
     * @param string $extra   Extra HTML attributes
     * @return string
     */
    public static function renderButton(
        string $label,
        string $icon,
        string $onclick,
        string $variant = 'primary',
        string $size = 'sm',
        string $extra = ''
    ): string {
        $safeLabel   = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeOnclick = htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8');
        $extraAttr   = $extra !== '' ? ' ' . $extra : '';

        return '<button type="button" class="fps-btn fps-btn-' . $size . ' fps-btn-' . $variant
            . '" onclick="' . $safeOnclick . '"' . $extraAttr . '>'
            . '<i class="fas ' . $icon . '"></i> ' . $safeLabel . '</button>';
    }

    /**
     * Render a skeleton loading placeholder.
     *
     * @param int    $lines Number of skeleton lines
     * @param string $id    Optional container id
     * @return string
     */
    public static function renderSkeleton(int $lines = 3, string $id = ''): string
    {
        $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '';
        $html = '<div class="fps-skeleton-container"' . $idAttr . '>';
        for ($i = 0; $i < $lines; $i++) {
            $width = rand(60, 100);
            $html .= '<div class="fps-skeleton-line" style="width:' . $width . '%"></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render a toggle switch.
     *
     * @param string $name    Input name
     * @param bool   $checked Current state
     * @param string $onchange JS handler
     * @return string
     */
    public static function renderToggle(string $name, bool $checked, string $onchange = ''): string
    {
        $safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $checkedAt = $checked ? ' checked' : '';
        $changeAt  = $onchange !== '' ? ' onchange="' . htmlspecialchars($onchange, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<label class="fps-toggle">'
            . '<input type="checkbox" name="' . $safeName . '" value="1"' . $checkedAt . $changeAt . '>'
            . '<span class="fps-toggle-slider"></span>'
            . '</label>';
    }

    /**
     * Render a modal dialog shell.
     *
     * @param string $id      Modal HTML id
     * @param string $title   Modal title
     * @param string $content Modal body HTML
     * @param string $footer  Modal footer HTML (buttons)
     * @return string
     */
    public static function renderModal(string $id, string $title, string $content, string $footer = ''): string
    {
        $safeId    = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $footerHtml = $footer !== '' ? '<div class="fps-modal-footer">' . $footer . '</div>' : '';

        return <<<HTML
<div id="{$safeId}" class="fps-modal" style="display:none;">
  <div class="fps-modal-backdrop" onclick="FpsAdmin.closeModal('{$safeId}')"></div>
  <div class="fps-modal-dialog">
    <div class="fps-modal-header fps-card-header-gradient">
      <h3>{$safeTitle}</h3>
      <button type="button" class="fps-modal-close" onclick="FpsAdmin.closeModal('{$safeId}')">&times;</button>
    </div>
    <div class="fps-modal-body">
      {$content}
    </div>
    {$footerHtml}
  </div>
</div>
HTML;
    }
}

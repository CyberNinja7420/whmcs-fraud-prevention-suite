<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabRules -- custom fraud rule management with CRUD modal, type legend,
 * and enable/disable toggles.
 */
class TabRules
{
    private const RULE_TYPES = [
        'ip_block'          => ['label' => 'IP Block',          'icon' => 'fa-ban',            'desc' => 'Block or flag specific IP addresses or CIDR ranges'],
        'email_pattern'     => ['label' => 'Email Pattern',     'icon' => 'fa-at',             'desc' => 'Match email patterns (e.g. *@tempmail.com)'],
        'country_block'     => ['label' => 'Country Block',     'icon' => 'fa-globe',          'desc' => 'Block orders from specific country codes'],
        'velocity'          => ['label' => 'Velocity',          'icon' => 'fa-gauge-high',     'desc' => 'Limit orders per time window per client/IP'],
        'amount'            => ['label' => 'Amount',            'icon' => 'fa-dollar-sign',    'desc' => 'Flag orders above/below a dollar threshold'],
        'domain_age'        => ['label' => 'Domain Age',        'icon' => 'fa-hourglass-half', 'desc' => 'Flag emails from domains younger than N days'],
        'fingerprint_match' => ['label' => 'Fingerprint Match', 'icon' => 'fa-fingerprint',    'desc' => 'Flag when device fingerprint matches known bad actor'],
    ];

    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderAddButton($ajaxUrl);
        $this->fpsRenderRulesTable($ajaxUrl);
        $this->fpsRenderRuleTypeLegend();
        $this->fpsRenderRuleModal($ajaxUrl);
    }

    /**
     * Add Rule button at the top.
     */
    private function fpsRenderAddButton(string $ajaxUrl): void
    {
        echo '<div class="fps-action-bar">';
        echo FpsAdminRenderer::renderButton(
            'Add New Rule', 'fa-plus', "FpsAdmin.openRuleModal(null, '{$ajaxUrl}')", 'primary', 'md'
        );
        echo '</div>';
    }

    /**
     * Rules table with all existing rules.
     */
    private function fpsRenderRulesTable(string $ajaxUrl): void
    {
        $rules = [];
        try {
            $rules = Capsule::table('mod_fps_rules')
                ->orderBy('priority', 'asc')
                ->orderByDesc('created_at')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error loading rules: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
            return;
        }

        $headers = ['Name', 'Type', 'Value', 'Action', 'Priority', 'Weight', 'Hits', 'Status', 'Created', 'Actions'];
        $rows = [];

        foreach ($rules as $rule) {
            $ruleId  = (int)$rule->id;
            $name    = htmlspecialchars($rule->rule_name ?? '', ENT_QUOTES, 'UTF-8');

            $typeKey   = $rule->rule_type ?? 'unknown';
            $typeInfo  = self::RULE_TYPES[$typeKey] ?? ['label' => ucfirst($typeKey), 'icon' => 'fa-question'];
            $typeBadge = '<span class="fps-badge fps-badge-info"><i class="fas ' . $typeInfo['icon'] . '"></i> '
                . htmlspecialchars($typeInfo['label'], ENT_QUOTES, 'UTF-8') . '</span>';

            $value    = htmlspecialchars(mb_strimwidth($rule->rule_value ?? '', 0, 40, '...'), ENT_QUOTES, 'UTF-8');
            $action   = $rule->action === 'block'
                ? '<span class="fps-badge fps-badge-critical">BLOCK</span>'
                : '<span class="fps-badge fps-badge-warning">FLAG</span>';
            $priority = (int)($rule->priority ?? 50);
            $weight   = number_format((float)($rule->score_weight ?? 1.0), 1);
            $hits     = (int)($rule->hits ?? 0);
            $enabled  = (int)($rule->enabled ?? 0);
            $created  = htmlspecialchars(substr($rule->created_at ?? '', 0, 10), ENT_QUOTES, 'UTF-8');

            $toggle = FpsAdminRenderer::renderToggle(
                'rule_enabled_' . $ruleId,
                (bool)$enabled,
                "FpsAdmin.toggleRule({$ruleId}, this.checked, '{$ajaxUrl}')"
            );

            $ruleJson = htmlspecialchars(json_encode($rule), ENT_QUOTES, 'UTF-8');
            $actions  = '<div class="fps-action-group">';
            $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-info" '
                . 'onclick="FpsAdmin.openRuleModal(' . $ruleJson . ', \'' . $ajaxUrl . '\')" title="Edit">'
                . '<i class="fas fa-pencil"></i></button>';
            $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger" '
                . 'onclick="FpsAdmin.deleteRule(' . $ruleId . ', \'' . $ajaxUrl . '\')" title="Delete">'
                . '<i class="fas fa-trash"></i></button>';
            $actions .= '</div>';

            $rows[] = [$name, $typeBadge, $value, $action, (string)$priority, $weight, (string)$hits, $toggle, $created, $actions];
        }

        $tableHtml = FpsAdminRenderer::renderTable($headers, $rows, 'fps-rules-table');
        echo FpsAdminRenderer::renderCard('Custom Fraud Rules (' . count($rules) . ')', 'fa-gavel', $tableHtml);
    }

    /**
     * Rule type legend info card.
     */
    private function fpsRenderRuleTypeLegend(): void
    {
        $content = '<div class="fps-legend-grid">';

        foreach (self::RULE_TYPES as $key => $info) {
            $safeKey  = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeDesc = htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8');
            $safeLbl  = htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8');

            $content .= '<div class="fps-legend-item">';
            $content .= '  <div class="fps-legend-icon"><i class="fas ' . $info['icon'] . '"></i></div>';
            $content .= '  <div class="fps-legend-text">';
            $content .= '    <strong>' . $safeLbl . '</strong> <code>' . $safeKey . '</code>';
            $content .= '    <p>' . $safeDesc . '</p>';
            $content .= '  </div>';
            $content .= '</div>';
        }

        $content .= '</div>';

        echo FpsAdminRenderer::renderCard('Rule Type Reference', 'fa-circle-info', $content);
    }

    /**
     * Rule create/edit modal form.
     */
    private function fpsRenderRuleModal(string $ajaxUrl): void
    {
        // Build type options
        $typeOptions = '<option value="">-- Select Type --</option>';
        foreach (self::RULE_TYPES as $key => $info) {
            $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeLbl = htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8');
            $typeOptions .= '<option value="' . $safeKey . '">' . $safeLbl . '</option>';
        }

        $formContent = <<<HTML
<form id="fps-rule-form" onsubmit="return false;">
  <input type="hidden" id="fps-rule-id" name="rule_id" value="">

  <div class="fps-form-row">
    <div class="fps-form-group" style="flex:2;">
      <label for="fps-rule-name"><i class="fas fa-tag"></i> Rule Name</label>
      <input type="text" id="fps-rule-name" name="rule_name" class="fps-input" required placeholder="e.g. Block Russian IPs">
    </div>
    <div class="fps-form-group" style="flex:1;">
      <label for="fps-rule-type"><i class="fas fa-layer-group"></i> Type</label>
      <select id="fps-rule-type" name="rule_type" class="fps-select" required>
        {$typeOptions}
      </select>
    </div>
  </div>

  <div class="fps-form-group">
    <label for="fps-rule-value"><i class="fas fa-code"></i> Value</label>
    <input type="text" id="fps-rule-value" name="rule_value" class="fps-input" required
      placeholder="e.g. 192.168.1.0/24, *@tempmail.com, RU, etc.">
  </div>

  <div class="fps-form-row">
    <div class="fps-form-group">
      <label for="fps-rule-action"><i class="fas fa-bolt"></i> Action</label>
      <select id="fps-rule-action" name="action" class="fps-select">
        <option value="flag">Flag (add to score)</option>
        <option value="block">Block (instant reject)</option>
      </select>
    </div>
    <div class="fps-form-group">
      <label for="fps-rule-priority"><i class="fas fa-sort-numeric-up"></i> Priority</label>
      <input type="number" id="fps-rule-priority" name="priority" class="fps-input" value="50" min="1" max="100">
    </div>
    <div class="fps-form-group">
      <label for="fps-rule-weight"><i class="fas fa-weight-hanging"></i> Score Weight</label>
      <input type="number" id="fps-rule-weight" name="score_weight" class="fps-input" value="1.0" min="0.1" max="10.0" step="0.1">
    </div>
  </div>

  <div class="fps-form-group">
    <label for="fps-rule-description"><i class="fas fa-align-left"></i> Description</label>
    <textarea id="fps-rule-description" name="description" class="fps-input fps-textarea" rows="2"
      placeholder="Optional: explain what this rule does"></textarea>
  </div>

  <div class="fps-form-group">
    <label for="fps-rule-expires"><i class="fas fa-calendar-xmark"></i> Expiration Date (optional)</label>
    <input type="date" id="fps-rule-expires" name="expires_at" class="fps-input">
  </div>
</form>
HTML;

        $footerHtml = FpsAdminRenderer::renderButton(
            'Save Rule', 'fa-check', "FpsAdmin.saveRule('{$ajaxUrl}')", 'success', 'md'
        );
        $footerHtml .= ' ';
        $footerHtml .= FpsAdminRenderer::renderButton(
            'Cancel', 'fa-times', "FpsAdmin.closeModal('fps-rule-modal')", 'outline', 'md'
        );

        echo FpsAdminRenderer::renderModal('fps-rule-modal', 'Add / Edit Fraud Rule', $formContent, $footerHtml);
    }
}

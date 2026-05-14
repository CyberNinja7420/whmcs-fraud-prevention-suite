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
        $this->fpsRenderGeoBlockingUI($ajaxUrl);
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
        echo ' ';
        echo '<button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsAdmin.exportRules()">'
            . '<i class="fas fa-download"></i> Export Rules</button>';
        echo ' ';
        echo '<button class="fps-btn fps-btn-sm fps-btn-outline" onclick="document.getElementById(\'fps-import-file\').click()">'
            . '<i class="fas fa-upload"></i> Import Rules</button>';
        echo '<input type="file" id="fps-import-file" accept=".json" style="display:none" '
            . 'onchange="FpsAdmin.importRules(this)">';
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
     * Country-based geo-blocking rule management UI.
     */
    private function fpsRenderGeoBlockingUI(string $ajaxUrl): void
    {
        // Top 50 countries most associated with online fraud (ISO 3166-1 alpha-2)
        $countries = [
            'AF' => 'Afghanistan',       'AL' => 'Albania',           'DZ' => 'Algeria',
            'AO' => 'Angola',            'BD' => 'Bangladesh',        'BY' => 'Belarus',
            'BJ' => 'Benin',             'BA' => 'Bosnia & Herzegovina', 'BR' => 'Brazil',
            'BG' => 'Bulgaria',          'BF' => 'Burkina Faso',      'KH' => 'Cambodia',
            'CM' => 'Cameroon',          'CF' => 'Central African Rep.', 'TD' => 'Chad',
            'CN' => 'China',             'CI' => "Cote d'Ivoire",     'CU' => 'Cuba',
            'CD' => 'DR Congo',          'EG' => 'Egypt',             'GH' => 'Ghana',
            'HT' => 'Haiti',             'IN' => 'India',             'ID' => 'Indonesia',
            'IR' => 'Iran',              'IQ' => 'Iraq',              'KE' => 'Kenya',
            'KP' => 'North Korea',       'LB' => 'Lebanon',           'LY' => 'Libya',
            'MG' => 'Madagascar',        'MY' => 'Malaysia',          'ML' => 'Mali',
            'MX' => 'Mexico',            'MM' => 'Myanmar',           'NE' => 'Niger',
            'NG' => 'Nigeria',           'PK' => 'Pakistan',          'PH' => 'Philippines',
            'RO' => 'Romania',           'RU' => 'Russia',            'SN' => 'Senegal',
            'SO' => 'Somalia',           'ZA' => 'South Africa',      'SD' => 'Sudan',
            'SY' => 'Syria',             'TZ' => 'Tanzania',          'UA' => 'Ukraine',
            'VE' => 'Venezuela',         'VN' => 'Vietnam',           'YE' => 'Yemen',
        ];

        // Build country options JSON for JS (avoids inline PHP loops in JS)
        $countriesJson = htmlspecialchars(json_encode($countries), ENT_QUOTES, 'UTF-8');

        $content = <<<HTML
<div id="fps-geo-block-container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

        <!-- Blocked Countries (left) -->
        <div>
            <h4 style="color:#f5576c;font-size:0.85rem;font-weight:700;margin:0 0 0.75rem 0;text-transform:uppercase;letter-spacing:0.08em;">
                <i class="fas fa-ban" style="margin-right:6px;"></i>Blocked Countries
            </h4>
            <div id="fps-geo-blocked-list" style="
                background:rgba(235,51,73,0.05);
                border:1px solid rgba(235,51,73,0.15);
                border-radius:10px;
                padding:1rem;
                min-height:200px;
                max-height:400px;
                overflow-y:auto;
            ">
                <div class="fps-geo-loading" style="text-align:center;padding:2rem;color:#6a7195;">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
        </div>

        <!-- Available Countries (right) -->
        <div>
            <h4 style="color:#38ef7d;font-size:0.85rem;font-weight:700;margin:0 0 0.75rem 0;text-transform:uppercase;letter-spacing:0.08em;">
                <i class="fas fa-globe" style="margin-right:6px;"></i>Available Countries
            </h4>
            <div style="margin-bottom:0.5rem;">
                <input type="text" id="fps-geo-search" class="fps-input" placeholder="Search countries..." style="font-size:0.85rem;">
            </div>
            <div id="fps-geo-available-list" style="
                background:rgba(56,239,125,0.04);
                border:1px solid rgba(56,239,125,0.12);
                border-radius:10px;
                padding:1rem;
                min-height:200px;
                max-height:355px;
                overflow-y:auto;
            ">
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var ajaxUrl     = '{$ajaxUrl}';
    var allCountries = JSON.parse(document.getElementById('fps-geo-countries-data').getAttribute('data-fps-countries'));
    var blockedSet   = {};

    function fpsGeoLoad() {
        fetch(ajaxUrl + '&ajax_action=get_country_blocks', {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(resp){
                blockedSet = {};
                if (resp.success && resp.blocked) {
                    resp.blocked.forEach(function(c){ blockedSet[c] = true; });
                }
                fpsGeoRender();
            })
            .catch(function(err){
                var el = document.getElementById('fps-geo-blocked-list');
                if (el) el.textContent = 'Error loading: ' + err.message;
            });
    }

    function fpsGeoRender() {
        var blockedEl   = document.getElementById('fps-geo-blocked-list');
        var availableEl = document.getElementById('fps-geo-available-list');
        var searchVal   = (document.getElementById('fps-geo-search') || {}).value || '';
        searchVal = searchVal.toLowerCase();

        // Render blocked list
        while (blockedEl.firstChild) blockedEl.removeChild(blockedEl.firstChild);
        var blockedCodes = Object.keys(blockedSet).sort();
        if (blockedCodes.length === 0) {
            var emptyMsg = document.createElement('div');
            emptyMsg.style.cssText = 'text-align:center;padding:2rem;color:#6a7195;font-size:0.85rem;';
            emptyMsg.textContent = 'No countries blocked yet. Add countries from the right panel.';
            blockedEl.appendChild(emptyMsg);
        } else {
            blockedCodes.forEach(function(code){
                var name = allCountries[code] || code;
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:6px 10px;margin-bottom:4px;background:rgba(235,51,73,0.08);border:1px solid rgba(235,51,73,0.15);border-radius:6px;';

                var label = document.createElement('span');
                label.style.cssText = 'font-size:0.82rem;color:#ccd6f6;';
                label.textContent = code + ' - ' + name;

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'fps-btn fps-btn-xs fps-btn-danger';
                btn.title = 'Remove block';
                btn.setAttribute('data-code', code);
                btn.addEventListener('click', function(){ fpsGeoRemove(this.getAttribute('data-code')); });
                var icon = document.createElement('i');
                icon.className = 'fas fa-times';
                btn.appendChild(icon);

                row.appendChild(label);
                row.appendChild(btn);
                blockedEl.appendChild(row);
            });
        }

        // Render available list
        while (availableEl.firstChild) availableEl.removeChild(availableEl.firstChild);
        var sortedKeys = Object.keys(allCountries).sort(function(a,b){
            return allCountries[a].localeCompare(allCountries[b]);
        });
        var shown = 0;
        sortedKeys.forEach(function(code){
            if (blockedSet[code]) return;
            var name = allCountries[code];
            if (searchVal && name.toLowerCase().indexOf(searchVal) === -1 && code.toLowerCase().indexOf(searchVal) === -1) return;
            shown++;

            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:6px 10px;margin-bottom:4px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:6px;transition:background 0.2s;';

            var label = document.createElement('span');
            label.style.cssText = 'font-size:0.82rem;color:#ccd6f6;';
            label.textContent = code + ' - ' + name;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fps-btn fps-btn-xs fps-btn-warning';
            btn.title = 'Block this country';
            btn.setAttribute('data-code', code);
            btn.addEventListener('click', function(){ fpsGeoAdd(this.getAttribute('data-code')); });
            var icon = document.createElement('i');
            icon.className = 'fas fa-ban';
            btn.appendChild(icon);

            row.appendChild(label);
            row.appendChild(btn);
            availableEl.appendChild(row);
        });

        if (shown === 0) {
            var noMatch = document.createElement('div');
            noMatch.style.cssText = 'text-align:center;padding:1.5rem;color:#6a7195;font-size:0.85rem;';
            noMatch.textContent = searchVal ? 'No matching countries found.' : 'All countries are blocked.';
            availableEl.appendChild(noMatch);
        }
    }

    function fpsGeoAdd(code) {
        var fd = new FormData();
        fd.append('ajax_action', 'add_country_block');
        fd.append('country_code', code);
        fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(resp){
                if (resp.error) {
                    if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast(resp.error, 'error');
                    return;
                }
                blockedSet[code] = true;
                fpsGeoRender();
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast(code + ' blocked', 'success');
            })
            .catch(function(err){
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast('Error: ' + err.message, 'error');
            });
    }

    function fpsGeoRemove(code) {
        var fd = new FormData();
        fd.append('ajax_action', 'remove_country_block');
        fd.append('country_code', code);
        fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(resp){
                if (resp.error) {
                    if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast(resp.error, 'error');
                    return;
                }
                delete blockedSet[code];
                fpsGeoRender();
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast(code + ' unblocked', 'success');
            })
            .catch(function(err){
                if (typeof FpsAdmin !== 'undefined' && FpsAdmin.showToast) FpsAdmin.showToast('Error: ' + err.message, 'error');
            });
    }

    // Search filter
    var searchInput = document.getElementById('fps-geo-search');
    if (searchInput) {
        searchInput.addEventListener('input', function(){ fpsGeoRender(); });
    }

    fpsGeoLoad();
})();
</script>
<span id="fps-geo-countries-data" data-fps-countries="{$countriesJson}" style="display:none;"></span>
HTML;

        echo FpsAdminRenderer::renderCard('Country-Based Rules', 'fa-earth-americas', $content);
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

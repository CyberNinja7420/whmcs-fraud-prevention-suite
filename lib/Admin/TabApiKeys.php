<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;

/**
 * TabApiKeys -- public API key management.
 *
 * Provides key creation, listing with usage stats, detail modals,
 * and tier pricing information cards.
 */
class TabApiKeys
{
    private const TIERS = [
        'free' => [
            'label'       => 'Free',
            'icon'        => 'fa-gift',
            'gradient'    => 'success',
            'rate_minute' => 10,
            'rate_day'    => 1000,
            'price'       => 'Free',
            'features'    => ['Anonymous lookup', 'Basic risk score', '1,000 requests/day', 'Community support'],
        ],
        'basic' => [
            'label'       => 'Basic',
            'icon'        => 'fa-bolt',
            'gradient'    => 'primary',
            'rate_minute' => 30,
            'rate_day'    => 10000,
            'price'       => '$19/mo',
            'features'    => ['Full IP intelligence', 'Email validation', '10,000 requests/day', 'Email support'],
        ],
        'premium' => [
            'label'       => 'Premium',
            'icon'        => 'fa-crown',
            'gradient'    => 'warning',
            'rate_minute' => 100,
            'rate_day'    => 100000,
            'price'       => '$99/mo',
            'features'    => ['All providers', 'Batch lookups', '100,000 requests/day', 'Priority support', 'Webhooks'],
        ],
    ];

    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');

        $this->fpsRenderCreateKeyCard($ajaxUrl);
        $this->fpsRenderKeysTable($ajaxUrl);
        $this->fpsRenderTierPricing();
        $this->fpsRenderKeyDetailModal();
    }

    /**
     * Create key card with name, tier, and owner email inputs.
     */
    private function fpsRenderCreateKeyCard(string $ajaxUrl): void
    {
        $tierOptions = '';
        foreach (self::TIERS as $key => $tier) {
            $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeLbl = htmlspecialchars($tier['label'] . ' - ' . $tier['price'], ENT_QUOTES, 'UTF-8');
            $tierOptions .= '<option value="' . $safeKey . '">' . $safeLbl . '</option>';
        }

        $formContent = <<<HTML
<div class="fps-form-row">
  <div class="fps-form-group" style="flex:2;">
    <label for="fps-apikey-name"><i class="fas fa-tag"></i> Key Name</label>
    <input type="text" id="fps-apikey-name" class="fps-input" placeholder="e.g. Production App, Partner Integration" required>
  </div>
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-apikey-tier"><i class="fas fa-layer-group"></i> Tier</label>
    <select id="fps-apikey-tier" class="fps-select">
      {$tierOptions}
    </select>
  </div>
  <div class="fps-form-group" style="flex:1;">
    <label for="fps-apikey-email"><i class="fas fa-envelope"></i> Owner Email</label>
    <input type="email" id="fps-apikey-email" class="fps-input" placeholder="owner@example.com">
  </div>
</div>
<div class="fps-form-row fps-form-row-right">
  <button type="button" class="fps-btn fps-btn-md fps-btn-success"
    onclick="FpsAdmin.createApiKey('{$ajaxUrl}')">
    <i class="fas fa-key"></i> Generate Key
  </button>
</div>
<div id="fps-apikey-generated" class="fps-generated-key" style="display:none;">
  <div class="fps-alert fps-alert-success">
    <i class="fas fa-check-circle"></i> API key generated. Copy it now -- it will not be shown again.
    <div class="fps-key-display">
      <code id="fps-apikey-value"></code>
      <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" onclick="FpsAdmin.copyApiKey()">
        <i class="fas fa-copy"></i> Copy
      </button>
    </div>
  </div>
</div>
HTML;

        echo FpsAdminRenderer::renderCard('Create API Key', 'fa-plus-circle', $formContent);
    }

    /**
     * API keys table with all issued keys.
     */
    private function fpsRenderKeysTable(string $ajaxUrl): void
    {
        $keys = [];
        try {
            $keys = Capsule::table('mod_fps_api_keys')
                ->orderByDesc('created_at')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            echo '<div class="fps-alert fps-alert-danger">';
            echo '<i class="fas fa-exclamation-circle"></i> Error loading API keys: ';
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '</div>';
            return;
        }

        $headers = ['Prefix', 'Name', 'Tier', 'Rate Limit', 'Total Requests', 'Last Used', 'Status', 'Actions'];
        $rows = [];

        foreach ($keys as $key) {
            $keyId    = (int)$key->id;
            $prefix   = htmlspecialchars($key->key_prefix ?? '', ENT_QUOTES, 'UTF-8');
            $name     = htmlspecialchars($key->name ?? '', ENT_QUOTES, 'UTF-8');
            $tier     = $key->tier ?? 'free';
            $tierInfo = self::TIERS[$tier] ?? self::TIERS['free'];

            $tierBadge = '<span class="fps-badge fps-badge-' . $tierInfo['gradient'] . '">'
                . '<i class="fas ' . $tierInfo['icon'] . '"></i> '
                . htmlspecialchars($tierInfo['label'], ENT_QUOTES, 'UTF-8') . '</span>';

            $rateLimit  = number_format((int)($key->rate_limit_per_minute ?? 10)) . '/min, '
                . number_format((int)($key->rate_limit_per_day ?? 1000)) . '/day';
            $totalReqs  = number_format((int)($key->total_requests ?? 0));
            $lastUsed   = $key->last_used_at
                ? htmlspecialchars($key->last_used_at, ENT_QUOTES, 'UTF-8')
                : '<span class="fps-text-muted">Never</span>';

            $isActive = (int)($key->is_active ?? 0);
            $statusBadge = $isActive
                ? '<span class="fps-badge fps-badge-low">Active</span>'
                : '<span class="fps-badge fps-badge-critical">Revoked</span>';

            $actions  = '<div class="fps-action-group">';
            $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-info" '
                . 'onclick="FpsAdmin.viewKeyDetail(' . $keyId . ', \'' . $ajaxUrl . '\')" title="View Details">'
                . '<i class="fas fa-chart-bar"></i></button>';
            if ($isActive) {
                $actions .= '<button type="button" class="fps-btn fps-btn-xs fps-btn-danger" '
                    . 'onclick="FpsAdmin.revokeApiKey(' . $keyId . ', \'' . $ajaxUrl . '\')" title="Revoke Key">'
                    . '<i class="fas fa-ban"></i></button>';
            }
            $actions .= '</div>';

            $rows[] = [
                '<code>' . $prefix . '...</code>',
                $name,
                $tierBadge,
                $rateLimit,
                $totalReqs,
                $lastUsed,
                $statusBadge,
                $actions,
            ];
        }

        echo FpsAdminRenderer::renderCard(
            'API Keys (' . count($keys) . ')',
            'fa-key',
            FpsAdminRenderer::renderTable($headers, $rows, 'fps-api-keys-table')
        );
    }

    /**
     * Tier pricing info cards.
     */
    private function fpsRenderTierPricing(): void
    {
        $content = '<div class="fps-tier-grid">';

        foreach (self::TIERS as $key => $tier) {
            $safeLabel = htmlspecialchars($tier['label'], ENT_QUOTES, 'UTF-8');
            $safePrice = htmlspecialchars($tier['price'], ENT_QUOTES, 'UTF-8');

            $content .= '<div class="fps-tier-card fps-gradient-' . $tier['gradient'] . '-subtle">';
            $content .= '  <div class="fps-tier-icon"><i class="fas ' . $tier['icon'] . ' fa-2x"></i></div>';
            $content .= '  <h4>' . $safeLabel . '</h4>';
            $content .= '  <div class="fps-tier-price">' . $safePrice . '</div>';
            $content .= '  <ul class="fps-tier-features">';
            foreach ($tier['features'] as $feature) {
                $content .= '    <li><i class="fas fa-check"></i> ' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $content .= '  </ul>';
            $content .= '</div>';
        }

        $content .= '</div>';

        echo FpsAdminRenderer::renderCard('API Tier Pricing', 'fa-tags', $content);
    }

    /**
     * Key detail modal (populated via JS).
     */
    private function fpsRenderKeyDetailModal(): void
    {
        $content = <<<HTML
<div id="fps-key-detail-content">
  <div class="fps-skeleton-container">
    <div class="fps-skeleton-line" style="width:100%"></div>
    <div class="fps-skeleton-line" style="width:80%"></div>
    <div class="fps-skeleton-line" style="width:90%"></div>
  </div>
</div>
<div id="fps-key-usage-chart" style="min-height:200px;margin-top:16px;"></div>
HTML;

        $footer = FpsAdminRenderer::renderButton(
            'Close', 'fa-times', "FpsAdmin.closeModal('fps-key-detail-modal')", 'outline', 'md'
        );

        echo FpsAdminRenderer::renderModal('fps-key-detail-modal', 'API Key Details', $content, $footer);
    }
}

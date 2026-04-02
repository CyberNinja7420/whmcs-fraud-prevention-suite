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

        $this->fpsRenderApiUsageStats();
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

            // Show effective rate limits (per-key override > tier default > hardcoded)
            $rl = (new \FraudPreventionSuite\Lib\Api\FpsApiRateLimiter())->resolveLimit($tier, (int)$key->id);
            $rateLimit  = number_format($rl['per_minute']) . '/min, '
                . number_format($rl['per_day']) . '/day';
            $hasOverride = ((int)($key->rate_limit_per_minute ?? 0) > 0 || (int)($key->rate_limit_per_day ?? 0) > 0);
            if ($hasOverride) $rateLimit .= ' <i class="fas fa-pen" title="Custom override" style="opacity:0.5;font-size:0.7rem;"></i>';
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
        // Read actual configured limits to display accurate numbers
        $rateLimiter = new \FraudPreventionSuite\Lib\Api\FpsApiRateLimiter();

        $content = '<div class="fps-tier-grid">';

        foreach (self::TIERS as $key => $tier) {
            $safeLabel = htmlspecialchars($tier['label'], ENT_QUOTES, 'UTF-8');
            $safePrice = htmlspecialchars($tier['price'], ENT_QUOTES, 'UTF-8');
            $actualLimits = $rateLimiter->getLimit($key);

            $content .= '<div class="fps-tier-card fps-gradient-' . $tier['gradient'] . '-subtle">';
            $content .= '  <div class="fps-tier-icon"><i class="fas ' . $tier['icon'] . ' fa-2x"></i></div>';
            $content .= '  <h4>' . $safeLabel . '</h4>';
            $content .= '  <div class="fps-tier-price">' . $safePrice . '</div>';
            $content .= '  <div style="font-size:0.8rem;margin:0.5rem 0;opacity:0.8;">'
                . number_format($actualLimits['per_minute']) . ' req/min - '
                . number_format($actualLimits['per_day']) . ' req/day</div>';
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

    /**
     * API usage stats: request origins, top IPs, abuse detection, recent 429s.
     */
    private function fpsRenderApiUsageStats(): void
    {
        try {
            $oneDayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

            // Total requests last 24h and 7d
            $last24h = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $oneDayAgo)->count();
            $last7d = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $sevenDaysAgo)->count();

            // Rate limit hits (429s) last 24h
            $rateLimitHits24h = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $oneDayAgo)
                ->where('response_code', 429)->count();

            // Unauthorized attempts (403s) last 24h
            $unauthorized24h = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $oneDayAgo)
                ->where('response_code', 403)->count();

            // Top requesting IPs (last 24h)
            $topIps = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $oneDayAgo)
                ->whereNotNull('ip_address')
                ->select('ip_address', Capsule::raw('COUNT(*) as cnt'), Capsule::raw('SUM(CASE WHEN response_code = 429 THEN 1 ELSE 0 END) as rate_limited'))
                ->groupBy('ip_address')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get();

            // Top endpoints (last 24h)
            $topEndpoints = Capsule::table('mod_fps_api_logs')
                ->where('created_at', '>=', $oneDayAgo)
                ->select('endpoint', Capsule::raw('COUNT(*) as cnt'), Capsule::raw('AVG(response_time_ms) as avg_ms'))
                ->groupBy('endpoint')
                ->orderByDesc('cnt')
                ->limit(8)
                ->get();

            // Per-key usage (last 24h)
            $keyUsage = Capsule::table('mod_fps_api_logs')
                ->where('mod_fps_api_logs.created_at', '>=', $oneDayAgo)
                ->whereNotNull('api_key_id')
                ->where('api_key_id', '>', 0)
                ->leftJoin('mod_fps_api_keys', 'mod_fps_api_logs.api_key_id', '=', 'mod_fps_api_keys.id')
                ->select(
                    'mod_fps_api_keys.name', 'mod_fps_api_keys.key_prefix', 'mod_fps_api_keys.tier',
                    Capsule::raw('COUNT(*) as cnt'),
                    Capsule::raw('SUM(CASE WHEN mod_fps_api_logs.response_code = 429 THEN 1 ELSE 0 END) as rate_limited')
                )
                ->groupBy('mod_fps_api_logs.api_key_id', 'mod_fps_api_keys.name', 'mod_fps_api_keys.key_prefix', 'mod_fps_api_keys.tier')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get();

            // Build stats cards
            $statsHtml = '<div class="fps-mini-stat-row">';
            $statsHtml .= '<div class="fps-mini-stat fps-gradient-primary"><span class="fps-mini-stat-value">' . number_format($last24h) . '</span><span class="fps-mini-stat-label">Requests (24h)</span></div>';
            $statsHtml .= '<div class="fps-mini-stat fps-gradient-info"><span class="fps-mini-stat-value">' . number_format($last7d) . '</span><span class="fps-mini-stat-label">Requests (7d)</span></div>';
            $statsHtml .= '<div class="fps-mini-stat ' . ($rateLimitHits24h > 0 ? 'fps-gradient-warning' : 'fps-gradient-success') . '"><span class="fps-mini-stat-value">' . number_format($rateLimitHits24h) . '</span><span class="fps-mini-stat-label">Rate Limited (24h)</span></div>';
            $statsHtml .= '<div class="fps-mini-stat ' . ($unauthorized24h > 0 ? 'fps-gradient-danger' : 'fps-gradient-success') . '"><span class="fps-mini-stat-value">' . number_format($unauthorized24h) . '</span><span class="fps-mini-stat-label">Unauthorized (24h)</span></div>';
            $statsHtml .= '</div>';

            // Top IPs table
            if (count($topIps) > 0) {
                $statsHtml .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;">';
                $statsHtml .= '<div>';
                $statsHtml .= '<h4 style="font-size:0.85rem;margin:0 0 0.5rem;"><i class="fas fa-network-wired"></i> Top IPs (24h)</h4>';
                $statsHtml .= '<table class="fps-table"><thead><tr><th>IP Address</th><th>Requests</th><th>Rate Limited</th></tr></thead><tbody>';
                foreach ($topIps as $ip) {
                    $isAbuse = $ip->rate_limited > 5;
                    $rowStyle = $isAbuse ? ' style="background:rgba(245,87,108,0.06);"' : '';
                    $statsHtml .= '<tr' . $rowStyle . '>'
                        . '<td style="font-family:monospace;font-size:0.85rem;">' . htmlspecialchars($ip->ip_address, ENT_QUOTES, 'UTF-8')
                        . ($isAbuse ? ' <span class="fps-badge fps-badge-critical fps-badge-sm">ABUSE</span>' : '') . '</td>'
                        . '<td>' . number_format($ip->cnt) . '</td>'
                        . '<td>' . ($ip->rate_limited > 0 ? '<span style="color:var(--fps-risk-high);">' . number_format($ip->rate_limited) . '</span>' : '0') . '</td>'
                        . '</tr>';
                }
                $statsHtml .= '</tbody></table></div>';

                // Top endpoints table
                $statsHtml .= '<div>';
                $statsHtml .= '<h4 style="font-size:0.85rem;margin:0 0 0.5rem;"><i class="fas fa-route"></i> Top Endpoints (24h)</h4>';
                $statsHtml .= '<table class="fps-table"><thead><tr><th>Endpoint</th><th>Requests</th><th>Avg ms</th></tr></thead><tbody>';
                foreach ($topEndpoints as $ep) {
                    $statsHtml .= '<tr>'
                        . '<td style="font-family:monospace;font-size:0.85rem;">' . htmlspecialchars($ep->endpoint ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td>' . number_format($ep->cnt) . '</td>'
                        . '<td>' . round($ep->avg_ms ?? 0) . 'ms</td>'
                        . '</tr>';
                }
                $statsHtml .= '</tbody></table></div>';
                $statsHtml .= '</div>';
            }

            // Per-key usage
            if (count($keyUsage) > 0) {
                $statsHtml .= '<h4 style="font-size:0.85rem;margin:1rem 0 0.5rem;"><i class="fas fa-key"></i> Per-Key Usage (24h)</h4>';
                $statsHtml .= '<table class="fps-table"><thead><tr><th>Key</th><th>Tier</th><th>Requests</th><th>Rate Limited</th></tr></thead><tbody>';
                foreach ($keyUsage as $ku) {
                    $statsHtml .= '<tr>'
                        . '<td style="font-size:0.85rem;">' . htmlspecialchars($ku->name ?? $ku->key_prefix ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td><span class="fps-badge fps-badge-sm">' . htmlspecialchars($ku->tier ?? '', ENT_QUOTES, 'UTF-8') . '</span></td>'
                        . '<td>' . number_format($ku->cnt) . '</td>'
                        . '<td>' . ($ku->rate_limited > 0 ? '<span style="color:var(--fps-risk-high);">' . number_format($ku->rate_limited) . '</span>' : '0') . '</td>'
                        . '</tr>';
                }
                $statsHtml .= '</tbody></table>';
            }

            echo FpsAdminRenderer::renderCard('API Usage & Abuse Tracking (last 24h)', 'fa-chart-line', $statsHtml);

        } catch (\Throwable $e) {
            echo FpsAdminRenderer::renderCard('API Usage', 'fa-chart-line',
                '<p class="fps-text-muted">No API usage data available yet.</p>');
        }
    }
}

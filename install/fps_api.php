<?php
/**
 * Fraud Prevention Suite - WHMCS Server Provisioning Module
 *
 * Automatically provisions API keys when clients purchase FPS API products.
 * Handles full lifecycle: create, suspend, unsuspend, terminate, upgrade/downgrade.
 *
 * @version 1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// Module metadata
// ---------------------------------------------------------------------------

function fps_api_MetaData(): array
{
    return [
        'DisplayName' => 'Fraud Prevention Suite API',
        'APIVersion'  => '1.1',
        'RequiresServer' => false,
    ];
}

// ---------------------------------------------------------------------------
// Config options (shown in product setup)
// ---------------------------------------------------------------------------

function fps_api_ConfigOptions(): array
{
    return [
        'Tier' => [
            'Type'        => 'dropdown',
            'Options'     => 'free,basic,premium',
            'Description' => 'API tier determines rate limits and endpoint access',
            'Default'     => 'free',
        ],
        'Custom Rate Minute' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Description' => 'Override req/minute (0 = use tier default)',
            'Default'     => '0',
        ],
        'Custom Rate Day' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Description' => 'Override req/day (0 = use tier default)',
            'Default'     => '0',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Tier defaults
// ---------------------------------------------------------------------------

function fps_api_getTierLimits(string $tier): array
{
    $tiers = [
        'free'    => ['per_minute' => 30,  'per_day' => 5000],
        'basic'   => ['per_minute' => 120, 'per_day' => 50000],
        'premium' => ['per_minute' => 600, 'per_day' => 500000],
    ];
    return $tiers[$tier] ?? $tiers['free'];
}

// ---------------------------------------------------------------------------
// CreateAccount - generates API key on purchase
// ---------------------------------------------------------------------------

function fps_api_CreateAccount(array $params): string
{
    try {
        $clientId  = (int)$params['clientsdetails']['userid'];
        $serviceId = (int)$params['serviceid'];
        $tier      = strtolower(trim($params['configoption1'] ?? 'free'));
        $customMin = (int)($params['configoption2'] ?? 0);
        $customDay = (int)($params['configoption3'] ?? 0);

        if (!in_array($tier, ['free', 'basic', 'premium'], true)) {
            $tier = 'free';
        }

        // Check if a key already exists for this service
        $existing = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->first();

        if ($existing) {
            // Reactivate existing key
            Capsule::table('mod_fps_api_keys')
                ->where('id', $existing->id)
                ->update(['is_active' => 1]);

            // Store key prefix in service custom fields for client area
            Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['username' => $existing->key_prefix . '...']);

            return 'success';
        }

        // Generate new API key
        $rawKey    = 'fps_' . bin2hex(random_bytes(24));
        $keyHash   = hash('sha256', $rawKey);
        $keyPrefix = substr($rawKey, 0, 12);

        $limits = fps_api_getTierLimits($tier);

        Capsule::table('mod_fps_api_keys')->insert([
            'key_hash'             => $keyHash,
            'key_prefix'           => $keyPrefix,
            'name'                 => 'Auto: ' . $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'] . ' - ' . ucfirst($tier),
            'tier'                 => $tier,
            'owner_email'          => $params['clientsdetails']['email'],
            'client_id'            => $clientId,
            'service_id'           => $serviceId,
            'rate_limit_per_minute' => $customMin > 0 ? $customMin : $limits['per_minute'],
            'rate_limit_per_day'   => $customDay > 0 ? $customDay : $limits['per_day'],
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'total_requests'       => 0,
        ]);

        // Store key in service dedicated IP field (WHMCS convention for server-generated creds)
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'username'    => $keyPrefix . '...',
                'dedicatedip' => $rawKey, // Full key stored encrypted by WHMCS
            ]);

        logModuleCall(
            'fraud_prevention_suite',
            'CreateAccount',
            json_encode(['client' => $clientId, 'service' => $serviceId, 'tier' => $tier]),
            'API key created: ' . $keyPrefix . '...'
        );

        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'CreateAccount', json_encode($params), $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// SuspendAccount - disable API key (non-payment, admin action)
// ---------------------------------------------------------------------------

function fps_api_SuspendAccount(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];

        $updated = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->update(['is_active' => 0]);

        if ($updated === 0) {
            return 'No API key found for this service';
        }

        logModuleCall('fraud_prevention_suite', 'SuspendAccount', $serviceId, 'Key suspended');
        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'SuspendAccount', json_encode($params), $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// UnsuspendAccount - re-enable API key
// ---------------------------------------------------------------------------

function fps_api_UnsuspendAccount(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];

        $updated = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->update(['is_active' => 1]);

        if ($updated === 0) {
            return 'No API key found for this service';
        }

        logModuleCall('fraud_prevention_suite', 'UnsuspendAccount', $serviceId, 'Key unsuspended');
        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'UnsuspendAccount', json_encode($params), $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// TerminateAccount - permanently revoke API key
// ---------------------------------------------------------------------------

function fps_api_TerminateAccount(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];

        $updated = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->update([
                'is_active'  => 0,
                'expires_at' => date('Y-m-d H:i:s'), // Mark as expired
            ]);

        if ($updated === 0) {
            return 'No API key found for this service';
        }

        // Clear stored credentials
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['dedicatedip' => '']);

        logModuleCall('fraud_prevention_suite', 'TerminateAccount', $serviceId, 'Key terminated');
        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'TerminateAccount', json_encode($params), $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// ChangePackage - upgrade/downgrade tier
// ---------------------------------------------------------------------------

function fps_api_ChangePackage(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $newTier   = strtolower(trim($params['configoption1'] ?? 'free'));
        $customMin = (int)($params['configoption2'] ?? 0);
        $customDay = (int)($params['configoption3'] ?? 0);

        if (!in_array($newTier, ['free', 'basic', 'premium'], true)) {
            $newTier = 'free';
        }

        $limits = fps_api_getTierLimits($newTier);

        $updated = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->update([
                'tier'                 => $newTier,
                'rate_limit_per_minute' => $customMin > 0 ? $customMin : $limits['per_minute'],
                'rate_limit_per_day'   => $customDay > 0 ? $customDay : $limits['per_day'],
            ]);

        if ($updated === 0) {
            return 'No API key found for this service';
        }

        logModuleCall('fraud_prevention_suite', 'ChangePackage', $serviceId, "Tier changed to $newTier");
        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'ChangePackage', json_encode($params), $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// TestConnection - verify addon module is active
// ---------------------------------------------------------------------------

function fps_api_TestConnection(array $params): array
{
    try {
        $active = Capsule::table('tbladdonmodules')
            ->where('module', 'fraud_prevention_suite')
            ->where('setting', 'version')
            ->value('value');

        if ($active) {
            return ['success' => true, 'error' => ''];
        }
        return ['success' => false, 'error' => 'FPS addon module is not activated'];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Admin custom buttons
// ---------------------------------------------------------------------------

function fps_api_AdminCustomButtonArray(): array
{
    return [
        'Regenerate Key' => 'RegenerateKey',
        'View Usage'     => 'ViewUsage',
    ];
}

function fps_api_RegenerateKey(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $clientId  = (int)$params['clientsdetails']['userid'];

        $key = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->first();

        if (!$key) {
            return 'No API key found for this service';
        }

        // Generate new key, keep same tier/limits
        $rawKey    = 'fps_' . bin2hex(random_bytes(24));
        $keyHash   = hash('sha256', $rawKey);
        $keyPrefix = substr($rawKey, 0, 12);

        Capsule::table('mod_fps_api_keys')
            ->where('id', $key->id)
            ->update([
                'key_hash'   => $keyHash,
                'key_prefix' => $keyPrefix,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'username'    => $keyPrefix . '...',
                'dedicatedip' => $rawKey,
            ]);

        logModuleCall('fraud_prevention_suite', 'RegenerateKey', $serviceId, 'New key: ' . $keyPrefix . '...');
        return 'success';

    } catch (\Throwable $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function fps_api_ViewUsage(array $params): string
{
    // Redirect to addon module API keys tab
    return 'success';
}

// ---------------------------------------------------------------------------
// ClientArea - shows key, usage stats, docs link
// ---------------------------------------------------------------------------

function fps_api_ClientArea(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];

        $key = Capsule::table('mod_fps_api_keys')
            ->where('service_id', $serviceId)
            ->first();

        if (!$key) {
            return '<div style="padding:20px;text-align:center;color:#999;">
                <p>Your API key is being provisioned. Please refresh in a moment.</p>
            </div>';
        }

        // Get the raw key from tblhosting dedicatedip (stored at provisioning)
        $hosting = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->first(['dedicatedip']);
        $rawKey = $hosting->dedicatedip ?? '';
        $maskedKey = $key->key_prefix . str_repeat('*', 40);

        // Usage stats
        $today = date('Y-m-d');
        $todayCount = 0;
        $monthCount = 0;
        try {
            $todayCount = Capsule::table('mod_fps_api_logs')
                ->where('api_key_id', $key->id)
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->count();
            $monthCount = Capsule::table('mod_fps_api_logs')
                ->where('api_key_id', $key->id)
                ->where('created_at', '>=', date('Y-m-01') . ' 00:00:00')
                ->count();
        } catch (\Throwable $e) {}

        $tierLabel = ucfirst($key->tier);
        $rateMin = number_format((int)$key->rate_limit_per_minute);
        $rateDay = number_format((int)$key->rate_limit_per_day);
        $totalReqs = number_format((int)$key->total_requests);
        $status = $key->is_active ? '<span style="color:#38c172;">Active</span>' : '<span style="color:#e3342f;">Suspended</span>';
        $docsUrl = 'index.php?m=fraud_prevention_suite&page=api-docs';

        $html = <<<HTML
<style>
.fps-ca { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.fps-ca-card { background:#1a1f36; border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:20px; margin:10px 0; color:#e0e0e0; }
.fps-ca-key { background:#0d1117; border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:12px 16px; font-family:monospace; font-size:0.85rem; word-break:break-all; color:#58a6ff; position:relative; }
.fps-ca-copy { position:absolute; right:8px; top:8px; background:#21262d; border:1px solid rgba(255,255,255,0.1); color:#58a6ff; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:0.8rem; }
.fps-ca-copy:hover { background:#30363d; }
.fps-ca-stats { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; margin:16px 0; }
.fps-ca-stat { background:rgba(255,255,255,0.04); border-radius:8px; padding:12px; text-align:center; }
.fps-ca-stat-val { font-size:1.4rem; font-weight:700; color:#58a6ff; }
.fps-ca-stat-label { font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.6; margin-top:4px; }
.fps-ca-info { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; font-size:0.85rem; opacity:0.8; }
</style>
<div class="fps-ca">
  <div class="fps-ca-card">
    <h3 style="margin:0 0 12px;font-size:1.1rem;">Your API Key</h3>
    <div class="fps-ca-key" id="fps-ca-key-display">
HTML;

        if (!empty($rawKey)) {
            $html .= htmlspecialchars($rawKey, ENT_QUOTES, 'UTF-8');
            $html .= '<button class="fps-ca-copy" onclick="navigator.clipboard.writeText(document.getElementById(\'fps-ca-key-display\').textContent.trim().replace(\'Copy\',\'\'));this.textContent=\'Copied!\';">Copy</button>';
        } else {
            $html .= htmlspecialchars($maskedKey, ENT_QUOTES, 'UTF-8');
            $html .= '<br><small style="opacity:0.5;">Full key shown once at creation. Contact support to regenerate.</small>';
        }

        $html .= <<<HTML
    </div>
    <div class="fps-ca-stats">
      <div class="fps-ca-stat"><div class="fps-ca-stat-val">{$todayCount}</div><div class="fps-ca-stat-label">Requests Today</div></div>
      <div class="fps-ca-stat"><div class="fps-ca-stat-val">{$monthCount}</div><div class="fps-ca-stat-label">This Month</div></div>
      <div class="fps-ca-stat"><div class="fps-ca-stat-val">{$totalReqs}</div><div class="fps-ca-stat-label">Lifetime</div></div>
      <div class="fps-ca-stat"><div class="fps-ca-stat-val">{$tierLabel}</div><div class="fps-ca-stat-label">Tier</div></div>
    </div>
    <div class="fps-ca-info">
      <span>Status: {$status}</span>
      <span>Rate Limit: {$rateMin}/min, {$rateDay}/day</span>
      <span><a href="{$docsUrl}" style="color:#58a6ff;text-decoration:none;">API Documentation &rarr;</a></span>
    </div>
  </div>
</div>
HTML;

        return $html;

    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'ClientArea', json_encode($params), $e->getMessage());
        return '<p>Error loading API key information.</p>';
    }
}

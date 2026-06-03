<?php
if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

class FpsHomeWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Fraud Prevention Suite';
    protected $description = 'Real-time fraud detection overview';
    protected $weight = 50;
    protected $columns = 1;
    protected $cache = true;
    protected $cacheExpiry = 300; // 5 minutes
    protected $requiredPermission = '';

    public function getData()
    {
        try {
            // Canonical action_taken sets (single source of truth). Required
            // explicitly: WHMCS loads dashboard widgets outside the module's
            // _output path, so the PSR-4 autoloader may not be registered here.
            if (!class_exists('\\FraudPreventionSuite\\Lib\\FpsActionTaken')) {
                require_once __DIR__ . '/../FpsActionTaken.php';
            }

            $today = date('Y-m-d');
            $checksToday = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $today . ' 00:00:00')->count();
            // 'blocked' alone missed the Turnstile 'block' rows (almost all
            // blocks) -- count the full canonical block set.
            $blockedToday = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->whereIn('action_taken', \FraudPreventionSuite\Lib\FpsActionTaken::BLOCK)->count();
            $reviewQueue = Capsule::table('mod_fps_checks')
                ->whereNull('reviewed_by')
                ->whereIn('risk_level', ['high', 'critical'])->count();
            $criticalToday = Capsule::table('mod_fps_checks')
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->where('risk_level', 'critical')->count();

            return [
                'checks_today' => $checksToday,
                'blocked_today' => $blockedToday,
                'review_queue' => $reviewQueue,
                'critical_today' => $criticalToday,
                'block_rate' => $checksToday > 0 ? round(($blockedToday / $checksToday) * 100, 1) : 0,
            ];
        } catch (\Throwable $e) {
            return [
                'checks_today' => 0, 'blocked_today' => 0,
                'review_queue' => 0, 'critical_today' => 0, 'block_rate' => 0,
            ];
        }
    }

    public function generateOutput($data)
    {
        $moduleLink = 'addonmodules.php?module=fraud_prevention_suite';
        $reviewBadge = $data['review_queue'] > 0
            ? '<span style="background:#eb3349;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:700;">' . $data['review_queue'] . '</span>'
            : '<span style="background:#38ef7d;color:#1a1a2e;padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:700;">0</span>';

        return <<<HTML
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px;">
  <div style="text-align:center;padding:12px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:8px;color:#fff;">
    <div style="font-size:1.5rem;font-weight:700;">{$data['checks_today']}</div>
    <div style="font-size:0.75rem;opacity:0.85;">Checks Today</div>
  </div>
  <div style="text-align:center;padding:12px;background:linear-gradient(135deg,#eb3349,#f45c43);border-radius:8px;color:#fff;">
    <div style="font-size:1.5rem;font-weight:700;">{$data['blocked_today']}</div>
    <div style="font-size:0.75rem;opacity:0.85;">Blocked Today</div>
  </div>
  <div style="text-align:center;padding:12px;background:linear-gradient(135deg,#11998e,#38ef7d);border-radius:8px;color:#fff;">
    <div style="font-size:1.5rem;font-weight:700;">{$data['block_rate']}%</div>
    <div style="font-size:0.75rem;opacity:0.85;">Block Rate</div>
  </div>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;">
  <div>Review Queue: {$reviewBadge}</div>
  <div>Critical: <strong style="color:#eb3349;">{$data['critical_today']}</strong></div>
  <a href="{$moduleLink}" class="btn btn-sm btn-primary">Open FPS</a>
</div>
HTML;
    }
}

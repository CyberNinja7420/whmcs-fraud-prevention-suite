<?php
/**
 * FpsAjaxBotCleanup -- AJAX handlers for the Bot Cleanup admin tab.
 *
 * Extracted from fraud_prevention_suite.php (TODO-hardening.md item #4
 * bulk extraction). Functions stay in the global namespace so the
 * dispatch switch in fps_handleAjax() continues to call them by name
 * with no modification.
 *
 * The required `use WHMCS\Database\Capsule;` import is repeated here
 * because PHP `use` statements are file-scoped.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function fps_ajaxDetectBots(): array
{
    try {
        $status = $_GET['status'] ?? $_POST['status'] ?? '';
        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->detectBots($status);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Preview a bot action (dry-run) before executing it.
 * Supports: flag, deactivate, purge, deep_purge
 */
function fps_ajaxPreviewBotAction(): array
{
    try {
        $action = $_GET['preview_action'] ?? $_POST['preview_action'] ?? '';
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));

        if (empty($clientIds)) {
            return ['error' => 'No client IDs provided'];
        }

        $validActions = ['flag', 'deactivate', 'purge', 'deep_purge'];
        if (!in_array($action, $validActions, true)) {
            return ['error' => 'Invalid preview action: ' . $action];
        }

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();

        switch ($action) {
            case 'flag':
                $results = [];
                foreach ($clientIds as $id) {
                    $client = Capsule::table('tblclients')->where('id', $id)
                        ->first(['id', 'email', 'firstname', 'lastname', 'notes', 'status']);
                    if (!$client) continue;
                    $hasFlag = strpos($client->notes ?? '', '[FPS-BOT]') !== false;
                    $results[] = [
                        'id'     => (int)$client->id,
                        'email'  => $client->email,
                        'name'   => trim($client->firstname . ' ' . $client->lastname),
                        'status' => $client->status ?? '',
                        'impact' => $hasFlag ? 'Already flagged (no change)' : 'Will add [FPS-BOT] flag to notes',
                    ];
                }
                $newFlags = count(array_filter($results, fn($r) => strpos($r['impact'], 'Already') === false));
                return [
                    'success' => true,
                    'summary' => "{$newFlags} accounts will be flagged",
                    'count'   => $newFlags,
                    'total'   => count($results),
                    'details' => $results,
                ];

            case 'deactivate':
                return $detector->previewDeactivate($clientIds);

            case 'purge':
                return $detector->previewPurge($clientIds);

            case 'deep_purge':
                return $detector->previewDeepPurge($clientIds);
        }

        return ['error' => 'Unhandled action'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Flag selected bot accounts.
 */
function fps_ajaxFlagBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->flagBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Deactivate selected bot accounts.
 */
function fps_ajaxDeactivateBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->deactivateBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Purge selected bot accounts (zero-record accounts only).
 */
function fps_ajaxPurgeBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->purgeBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Deep purge selected bot accounts (accounts with only Fraud/Cancelled records).
 */
function fps_ajaxDeepPurgeBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->deepPurgeBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Detect orphan user accounts (tblusers with no real clients).
 */
function fps_ajaxDetectOrphanUsers(): array
{
    try {
        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->detectOrphanUsers();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Purge selected orphan user accounts.
 */
function fps_ajaxPurgeOrphanUsers(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $userIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($userIds)) return ['error' => 'No user IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->purgeOrphanUsers($userIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Get paginated module log entries.
 */

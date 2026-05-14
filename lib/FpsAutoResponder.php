<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsAutoResponder -- automatic response actions triggered by repeated critical
 * fraud checks within a configurable time window.
 *
 * When a client accumulates N critical-level fraud checks in X days, the
 * auto-responder executes a configured action (suspend, flag, or blacklist).
 *
 * All actions are logged to mod_fps_auto_actions for auditing.
 * Settings are read from FpsConfig (mod_fps_settings).
 *
 * Table: mod_fps_auto_actions
 *   id             INT AUTO_INCREMENT PRIMARY KEY
 *   client_id      INT NOT NULL
 *   action         VARCHAR(50) NOT NULL
 *   reason         TEXT
 *   check_count    INT DEFAULT 0
 *   created_at     DATETIME NOT NULL
 */
class FpsAutoResponder
{
    private const MODULE_NAME = 'fraud_prevention_suite';
    private const TABLE       = 'mod_fps_auto_actions';

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
        $this->fps_ensureTable();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Evaluate whether a client has triggered the auto-response threshold.
     *
     * Counts critical-level fraud checks within the configured window and
     * compares against the threshold. Returns an associative array indicating
     * whether action should be taken.
     *
     * @param int $clientId WHMCS client ID
     * @return array{should_act: bool, action?: string, count?: int, reason?: string}
     */
    public function fps_evaluateClient(int $clientId): array
    {
        try {
            $enabled = $this->config->getCustom('auto_respond_enabled', '0');
            if ($enabled !== '1') {
                return ['should_act' => false];
            }

            $threshold  = max(1, (int) $this->config->getCustom('auto_respond_threshold', '3'));
            $windowDays = max(1, (int) $this->config->getCustom('auto_respond_window_days', '7'));
            $action     = (string) $this->config->getCustom('auto_respond_action', 'suspend');

            // Validate action
            if (!in_array($action, ['suspend', 'flag', 'blacklist'], true)) {
                $action = 'suspend';
            }

            // Count critical checks in the window
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));
            $count  = (int) Capsule::table('mod_fps_checks')
                ->where('client_id', $clientId)
                ->where('risk_level', 'critical')
                ->where('created_at', '>=', $cutoff)
                ->count();

            if ($count >= $threshold) {
                // Check if we already acted on this client recently (within the
                // same window) to avoid repeat actions.
                $alreadyActed = Capsule::table(self::TABLE)
                    ->where('client_id', $clientId)
                    ->where('created_at', '>=', $cutoff)
                    ->exists();

                if ($alreadyActed) {
                    return ['should_act' => false];
                }

                $reason = sprintf(
                    'Auto-response: %d critical fraud checks in %d days (threshold: %d)',
                    $count,
                    $windowDays,
                    $threshold
                );

                return [
                    'should_act' => true,
                    'action'     => $action,
                    'count'      => $count,
                    'reason'     => $reason,
                ];
            }

            return ['should_act' => false];
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_evaluateClient',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
            return ['should_act' => false];
        }
    }

    /**
     * Execute an auto-response action on a client.
     *
     * Actions:
     *   - 'suspend': Set tblclients.status = 'Inactive', add admin note
     *   - 'flag':    Add admin note only (no status change)
     *   - 'blacklist': Set trust status via FpsClientTrustManager
     *
     * All actions are logged to mod_fps_auto_actions.
     *
     * @param int    $clientId WHMCS client ID
     * @param string $action   One of: suspend, flag, blacklist
     * @param string $reason   Human-readable reason
     * @return bool True on success
     */
    public function fps_executeAction(int $clientId, string $action, string $reason): bool
    {
        if (!in_array($action, ['suspend', 'flag', 'blacklist'], true)) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_executeAction',
                json_encode(['client_id' => $clientId, 'action' => $action]),
                'Invalid action'
            );
            return false;
        }

        try {
            switch ($action) {
                case 'suspend':
                    Capsule::table('tblclients')
                        ->where('id', $clientId)
                        ->update(['status' => 'Inactive']);

                    $this->fps_addClientNote(
                        $clientId,
                        "[FPS-AUTO] Suspended: {$reason}"
                    );

                    logActivity(
                        "FPS Auto-Responder: Client #{$clientId} SUSPENDED. {$reason}"
                    );
                    break;

                case 'flag':
                    $this->fps_addClientNote(
                        $clientId,
                        "[FPS-AUTO] Flagged: {$reason}"
                    );

                    logActivity(
                        "FPS Auto-Responder: Client #{$clientId} FLAGGED. {$reason}"
                    );
                    break;

                case 'blacklist':
                    if (class_exists('\\FraudPreventionSuite\\Lib\\FpsClientTrustManager')) {
                        $trustManager = new FpsClientTrustManager();
                        $trustManager->setClientStatus(
                            $clientId,
                            'blacklisted',
                            $reason,
                            0 // system action (no admin user)
                        );
                    } else {
                        // Fallback: just add a note
                        $this->fps_addClientNote(
                            $clientId,
                            "[FPS-AUTO] Blacklisted (trust manager unavailable): {$reason}"
                        );
                    }

                    logActivity(
                        "FPS Auto-Responder: Client #{$clientId} BLACKLISTED. {$reason}"
                    );
                    break;
            }

            // Log to mod_fps_auto_actions
            $count = 0;
            try {
                $windowDays = max(1, (int) $this->config->getCustom('auto_respond_window_days', '7'));
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));
                $count = (int) Capsule::table('mod_fps_checks')
                    ->where('client_id', $clientId)
                    ->where('risk_level', 'critical')
                    ->where('created_at', '>=', $cutoff)
                    ->count();
            } catch (\Throwable $e) {
                // Non-fatal -- count is informational only
            }

            Capsule::table(self::TABLE)->insert([
                'client_id'   => $clientId,
                'action'      => $action,
                'reason'      => $reason,
                'check_count' => $count,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            // Send webhook notification for auto-response action
            try {
                if (class_exists('\\FraudPreventionSuite\\Lib\\FpsWebhookNotifier')) {
                    $webhookNotifier = new FpsWebhookNotifier();
                    $webhookNotifier->sendFraudAlert(
                        'critical',
                        0,
                        $clientId,
                        100.0,
                        [
                            "Auto-response action: {$action}",
                            $reason,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                // Non-fatal -- webhook failure should not block the action
            }

            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_executeAction',
                json_encode(['client_id' => $clientId, 'action' => $action]),
                'OK'
            );

            return true;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_executeAction',
                json_encode(['client_id' => $clientId, 'action' => $action]),
                $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Return the auto-action history for a specific client.
     *
     * @param int $clientId WHMCS client ID
     * @return list<array{id: int, client_id: int, action: string, reason: string, check_count: int, created_at: string}>
     */
    public function fps_getActionHistory(int $clientId): array
    {
        try {
            $rows = Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get([
                    'id',
                    'client_id',
                    'action',
                    'reason',
                    'check_count',
                    'created_at',
                ]);

            $result = [];
            foreach ($rows as $row) {
                $result[] = (array) $row;
            }
            return $result;
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_getActionHistory',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
            return [];
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Ensure the mod_fps_auto_actions table exists.
     *
     * Uses hasTable guard so this is idempotent.
     */
    private function fps_ensureTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE)) {
                Capsule::schema()->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('client_id')->index();
                    $table->string('action', 50);
                    $table->text('reason')->nullable();
                    $table->unsignedInteger('check_count')->default(0);
                    $table->dateTime('created_at');
                });
            }
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_ensureTable',
                '',
                $e->getMessage()
            );
        }
    }

    /**
     * Add a note to the client's admin notes field.
     *
     * Prepends the note to the existing notes with a timestamp.
     *
     * @param int    $clientId WHMCS client ID
     * @param string $note     Note text to prepend
     */
    private function fps_addClientNote(int $clientId, string $note): void
    {
        try {
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first(['notes']);

            $existing = ($client !== null) ? (string) ($client->notes ?? '') : '';
            $timestamp = date('Y-m-d H:i:s');
            $newNotes = "[{$timestamp}] {$note}\n" . $existing;

            Capsule::table('tblclients')
                ->where('id', $clientId)
                ->update(['notes' => $newNotes]);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsAutoResponder::fps_addClientNote',
                json_encode(['client_id' => $clientId]),
                $e->getMessage()
            );
        }
    }
}

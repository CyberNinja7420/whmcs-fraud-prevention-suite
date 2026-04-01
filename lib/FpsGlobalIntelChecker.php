<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsGlobalIntelChecker -- Fast cross-reference against global fraud intelligence.
 *
 * Called during signup and order hooks to check if a new client's email/IP
 * has been seen before in the global intelligence database. Must be fast:
 * <200ms for local lookups, <500ms including hub queries.
 *
 * Returns a score adjustment that can be added to the fraud check pipeline.
 */
class FpsGlobalIntelChecker
{
    /**
     * Check an email and IP against local + hub intelligence.
     *
     * @return array{found: bool, local_hits: int, hub_hits: int, max_risk_score: float, risk_level: string, evidence: array, seen_count: int, instance_count: int}
     */
    public function check(string $email, string $ip): array
    {
        $emailHash = hash('sha256', strtolower(trim($email)));
        $result = [
            'found'          => false,
            'local_hits'     => 0,
            'hub_hits'       => 0,
            'max_risk_score' => 0.0,
            'risk_level'     => 'low',
            'evidence'       => [],
            'seen_count'     => 0,
            'instance_count' => 0,
        ];

        // Step 1: Query local mod_fps_global_intel
        try {
            if (Capsule::schema()->hasTable('mod_fps_global_intel')) {
                $localMatches = Capsule::table('mod_fps_global_intel')
                    ->where(function ($q) use ($emailHash, $ip) {
                        $q->where('email_hash', $emailHash);
                        if (!empty($ip)) {
                            $q->orWhere('ip_address', $ip);
                        }
                    })
                    ->get();

                foreach ($localMatches as $match) {
                    $result['local_hits']++;
                    $result['found'] = true;
                    $result['seen_count'] += (int)$match->seen_count;

                    if ((float)$match->risk_score > $result['max_risk_score']) {
                        $result['max_risk_score'] = (float)$match->risk_score;
                        $result['risk_level'] = $match->risk_level;
                    }

                    // Merge evidence flags
                    $flags = json_decode($match->evidence_flags ?? '{}', true);
                    if (is_array($flags)) {
                        foreach ($flags as $key => $val) {
                            if ($val === true) {
                                $result['evidence'][$key] = true;
                            }
                        }
                    }

                    if ($match->source === 'hub') {
                        $result['hub_hits']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: local DB error shouldn't block operations
        }

        // Step 2: Optionally query hub (only if enabled and configured)
        try {
            $client = new FpsGlobalIntelClient();
            if ($client->isConfigured() && $client->isEnabled()
                && $this->getConfig('auto_pull_on_signup', '1') === '1')
            {
                $hubResult = $client->lookupEmail($emailHash);
                if ($hubResult && !empty($hubResult['records'])) {
                    foreach ($hubResult['records'] as $hubMatch) {
                        $result['hub_hits']++;
                        $result['found'] = true;
                        $result['seen_count'] += (int)($hubMatch['seen_count'] ?? 1);
                        $result['instance_count'] = max(
                            $result['instance_count'],
                            (int)($hubMatch['instance_count'] ?? 1)
                        );

                        $score = (float)($hubMatch['max_risk_score'] ?? $hubMatch['risk_score'] ?? 0);
                        if ($score > $result['max_risk_score']) {
                            $result['max_risk_score'] = $score;
                            $result['risk_level'] = $hubMatch['risk_level'] ?? 'low';
                        }
                    }
                }

                // Also check IP if available
                if (!empty($ip)) {
                    $ipResult = $client->lookupIp($ip);
                    if ($ipResult && !empty($ipResult['records'])) {
                        foreach ($ipResult['records'] as $ipMatch) {
                            $result['hub_hits']++;
                            $result['found'] = true;
                            $result['seen_count'] += (int)($ipMatch['seen_count'] ?? 1);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Hub errors are non-fatal: fail open
        }

        return $result;
    }

    /**
     * Calculate score adjustment based on global intel results.
     *
     * Scoring:
     *   0 hits     = +0 points
     *   1-2 hits   = +5 points
     *   3-5 hits   = +10 points
     *   5-10 hits  = +15 points
     *   10+ hits   = +25 points
     *   Cross-instance confirmed (instance_count > 1) = +5 extra
     *
     * @return float Score adjustment (0-30)
     */
    public function getScoreAdjustment(array $result): float
    {
        if (!$result['found']) {
            return 0.0;
        }

        $totalHits = $result['local_hits'] + $result['hub_hits'];
        $adjustment = 0.0;

        if ($totalHits >= 10) {
            $adjustment = 25.0;
        } elseif ($totalHits >= 5) {
            $adjustment = 15.0;
        } elseif ($totalHits >= 3) {
            $adjustment = 10.0;
        } elseif ($totalHits >= 1) {
            $adjustment = 5.0;
        }

        // Cross-instance confirmation bonus
        if ($result['instance_count'] > 1) {
            $adjustment += 5.0;
        }

        return min(30.0, $adjustment);
    }

    private function getConfig(string $key, string $default = ''): string
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_global_config')) return $default;
            $val = Capsule::table('mod_fps_global_config')
                ->where('setting_key', $key)
                ->value('setting_value');
            return $val !== null ? (string)$val : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

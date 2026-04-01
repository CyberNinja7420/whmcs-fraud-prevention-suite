<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * OFAC SDN (Specially Designated Nationals) sanctions screening provider.
 *
 * Downloads and caches the US Treasury SDN list, then checks client names
 * against it using normalized fuzzy matching. Only Sensfrx has this feature
 * among our competitors.
 *
 * SDN CSV source: https://www.treasury.gov/ofac/downloads/sdn.csv
 * Updated daily via DailyCronJob hook.
 */
class OfacScreeningProvider implements FpsProviderInterface
{
    private const SDN_FILE = __DIR__ . '/../../data/sdn.csv';
    private const SDN_URL  = 'https://www.treasury.gov/ofac/downloads/sdn.csv';
    private const CACHE_TTL_HOURS = 24;

    public function getName(): string
    {
        return 'OFAC Sanctions';
    }

    public function isEnabled(): bool
    {
        try {
            $val = Capsule::table('mod_fps_settings')
                ->where('setting_key', 'ofac_screening_enabled')
                ->value('setting_value');
            return $val === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isQuick(): bool
    {
        // Local file lookup is fast
        return true;
    }

    public function getWeight(): float
    {
        return 2.0; // OFAC match is very serious
    }

    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $firstName = trim($context['first_name'] ?? '');
        $lastName  = trim($context['last_name'] ?? '');
        $company   = trim($context['company'] ?? '');

        // Also try to get from client record if not in context
        if (($firstName === '' || $lastName === '') && !empty($context['client_id'])) {
            try {
                $client = Capsule::table('tblclients')
                    ->where('id', (int)$context['client_id'])
                    ->first(['firstname', 'lastname', 'companyname']);
                if ($client) {
                    if ($firstName === '') $firstName = trim($client->firstname ?? '');
                    if ($lastName === '') $lastName = trim($client->lastname ?? '');
                    if ($company === '') $company = trim($client->companyname ?? '');
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '' && $company === '') {
            return $blank;
        }

        try {
            $sdnEntries = $this->fps_loadSdnList();
            if (empty($sdnEntries)) {
                return $blank;
            }

            $matches = [];

            // Check full name
            if ($fullName !== '') {
                $nameMatches = $this->fps_searchSdn($sdnEntries, $fullName);
                $matches = array_merge($matches, $nameMatches);
            }

            // Check company name
            if ($company !== '') {
                $companyMatches = $this->fps_searchSdn($sdnEntries, $company);
                $matches = array_merge($matches, $companyMatches);
            }

            // Check reversed name (LastName, FirstName format in SDN)
            if ($firstName !== '' && $lastName !== '') {
                $reversed = $lastName . ' ' . $firstName;
                $revMatches = $this->fps_searchSdn($sdnEntries, $reversed);
                $matches = array_merge($matches, $revMatches);
            }

            // Deduplicate matches by SDN entry number
            $unique = [];
            foreach ($matches as $m) {
                $key = $m['entry_id'] ?? $m['name'];
                $unique[$key] = $m;
            }
            $matches = array_values($unique);

            $score = 0.0;
            $details = [
                'checked_name' => $fullName,
                'checked_company' => $company,
                'match_count' => count($matches),
                'matches' => array_slice($matches, 0, 5), // Top 5 matches
            ];

            if (count($matches) > 0) {
                // Any SDN match is extremely serious
                $bestScore = max(array_column($matches, 'similarity'));
                if ($bestScore >= 95) {
                    $score = 50.0; // Near-exact match
                } elseif ($bestScore >= 85) {
                    $score = 35.0; // Strong match
                } elseif ($bestScore >= 75) {
                    $score = 20.0; // Partial match
                }
                $details['best_similarity'] = $bestScore;
            }

            logModuleCall(
                'fraud_prevention_suite',
                'OFAC Screening',
                $fullName . ' / ' . $company,
                json_encode($details),
                '',
                []
            );

            return [
                'score'   => $score,
                'details' => $details,
                'raw'     => null,
            ];
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'OFAC Error', '', $e->getMessage());
            return $blank;
        }
    }

    /**
     * Load and parse the cached SDN list.
     * Returns array of ['entry_id' => ..., 'name' => ..., 'type' => ..., 'programs' => ...]
     */
    private function fps_loadSdnList(): array
    {
        if (!file_exists(self::SDN_FILE) || filesize(self::SDN_FILE) < 100) {
            return [];
        }

        $entries = [];
        $handle = fopen(self::SDN_FILE, 'r');
        if (!$handle) {
            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;

            // SDN CSV format: entry_id, name, type, programs, ...
            $entryId = trim($row[0] ?? '');
            $name    = trim($row[1] ?? '');
            $type    = trim($row[2] ?? '');

            if ($name === '' || !is_numeric($entryId)) continue;

            $entries[] = [
                'entry_id' => $entryId,
                'name'     => $name,
                'type'     => $type,
                'programs' => trim($row[3] ?? ''),
            ];
        }

        fclose($handle);
        return $entries;
    }

    /**
     * Search SDN entries for names similar to the query.
     * Uses normalized comparison + similar_text for fuzzy matching.
     */
    private function fps_searchSdn(array $entries, string $query): array
    {
        $matches = [];
        $normalized = $this->fps_normalize($query);

        if (strlen($normalized) < 3) {
            return [];
        }

        foreach ($entries as $entry) {
            $entryNorm = $this->fps_normalize($entry['name']);

            // Exact match
            if ($entryNorm === $normalized) {
                $matches[] = array_merge($entry, ['similarity' => 100]);
                continue;
            }

            // Contains match
            if (str_contains($entryNorm, $normalized) || str_contains($normalized, $entryNorm)) {
                similar_text($entryNorm, $normalized, $pct);
                $matches[] = array_merge($entry, ['similarity' => (int)max($pct, 80)]);
                continue;
            }

            // Fuzzy match (only for names of similar length to avoid false positives)
            $lenRatio = strlen($normalized) > 0
                ? min(strlen($entryNorm), strlen($normalized)) / max(strlen($entryNorm), strlen($normalized))
                : 0;

            if ($lenRatio > 0.6) {
                similar_text($entryNorm, $normalized, $pct);
                if ($pct >= 75) {
                    $matches[] = array_merge($entry, ['similarity' => (int)$pct]);
                }
            }
        }

        // Sort by similarity descending
        usort($matches, function ($a, $b) {
            return ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0);
        });

        return array_slice($matches, 0, 10);
    }

    /**
     * Normalize a name for comparison: lowercase, strip punctuation, collapse whitespace.
     */
    private function fps_normalize(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name;
    }

    /**
     * Download/refresh the SDN list from treasury.gov.
     * Called by the DailyCronJob hook.
     */
    public static function refreshSdnList(): bool
    {
        $dataDir = dirname(self::SDN_FILE);
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }

        // Skip if file was updated recently
        if (file_exists(self::SDN_FILE)) {
            $age = time() - filemtime(self::SDN_FILE);
            if ($age < self::CACHE_TTL_HOURS * 3600) {
                return true; // Still fresh
            }
        }

        $ch = curl_init(self::SDN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'FraudPreventionSuite/2.0 OFAC-SDN-Checker',
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($data)) {
            logModuleCall('fraud_prevention_suite', 'OFAC SDN Download',
                'HTTP ' . $httpCode, 'Failed to download SDN list');
            return false;
        }

        // Validate it looks like a CSV (first line should have numeric entry ID)
        $firstLine = strtok($data, "\n");
        if (!preg_match('/^\d+,/', $firstLine)) {
            logModuleCall('fraud_prevention_suite', 'OFAC SDN Download',
                '', 'Downloaded file does not appear to be valid SDN CSV');
            return false;
        }

        $written = file_put_contents(self::SDN_FILE, $data);
        if ($written === false) {
            return false;
        }

        logModuleCall('fraud_prevention_suite', 'OFAC SDN Refresh',
            '', 'SDN list updated: ' . strlen($data) . ' bytes');
        return true;
    }
}

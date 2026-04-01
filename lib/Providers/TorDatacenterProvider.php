<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Tor exit node and datacenter/hosting IP detection provider.
 *
 * Operates entirely on local DB tables populated by cron refresh methods.
 * No external API key required; latency is sub-millisecond (index lookup).
 *
 * Tables used:
 *   mod_fps_tor_nodes        - current Tor exit node IP list
 *   mod_fps_ip_intel         - IP intelligence cache (is_datacenter flag)
 *   mod_fps_datacenter_asns  - known hosting/datacenter ASNs
 */
class TorDatacenterProvider implements FpsProviderInterface
{
    private const TOR_TABLE       = 'mod_fps_tor_nodes';
    private const INTEL_TABLE     = 'mod_fps_ip_intel';
    private const ASN_TABLE       = 'mod_fps_datacenter_asns';
    private const TOR_BULK_URL    = 'https://check.torproject.org/torbulkexitlist';
    private const BAD_ASN_CSV_URL = 'https://raw.githubusercontent.com/brianhama/bad-asn-list/master/bad-asn-list.csv';
    private const HTTP_TIMEOUT    = 10;

    /**
     * Hardcoded ASNs for major cloud/hosting providers commonly used in fraud.
     * Format: asn_number (int) => description (string)
     */
    private const KNOWN_HOSTING_ASNS = [
        16276 => 'OVH SAS',
        24940 => 'Hetzner Online GmbH',
        14061 => 'DigitalOcean LLC',
        63949 => 'Linode LLC / Akamai Technologies',
        20473 => 'Vultr Holdings LLC / Choopa',
        16509 => 'Amazon Web Services (AS16509)',
        14618 => 'Amazon Web Services (AS14618)',
        15169 => 'Google Cloud (GOOGLE)',
        8075  => 'Microsoft Azure (MICROSOFT-CORP)',
    ];

    // ------------------------------------------------------------------
    // FpsProviderInterface
    // ------------------------------------------------------------------

    public function getName(): string
    {
        return 'Tor & Datacenter Detection';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isQuick(): bool
    {
        return true;
    }

    public function getWeight(): float
    {
        return 1.3;
    }

    /**
     * Check the supplied IP against Tor exit node and datacenter ASN tables.
     *
     * @param array $context Must contain key 'ip' (string).
     * @return array{score: float, details: array, raw: mixed}
     */
    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $ip = trim($context['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $blank;
        }

        try {
            $score   = 0.0;
            $details = [
                'is_tor'             => false,
                'is_datacenter'      => false,
                'is_bad_asn'         => false,
                'matched_asn'        => null,
                'matched_asn_desc'   => null,
            ];

            // Tor exit node check (+35)
            if ($this->fps_isTorExitNode($ip)) {
                $details['is_tor'] = true;
                $score += 35.0;
            }

            // Datacenter flag from IP intel cache (+20)
            $isDatacenter = $this->fps_isDatacenterByIntelFlag($ip);
            if ($isDatacenter) {
                $details['is_datacenter'] = true;
                $score += 20.0;
            }

            // Bad/hosting ASN check (+15)
            $asnMatch = $this->fps_isDatacenterAsn($ip);
            if ($asnMatch !== null) {
                $details['is_bad_asn']      = true;
                $details['matched_asn']     = $asnMatch['asn'];
                $details['matched_asn_desc'] = $asnMatch['description'];
                $score += 15.0;
            }

            return [
                'score'   => min(100.0, max(0.0, $score)),
                'details' => $details,
                'raw'     => $details,
            ];
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter Error',
                $ip,
                $e->getMessage(),
                '',
                []
            );
            return $blank;
        }
    }

    // ------------------------------------------------------------------
    // Static cron refresh methods
    // ------------------------------------------------------------------

    /**
     * Download the Tor Project bulk exit list and repopulate mod_fps_tor_nodes.
     *
     * Should be called from the module's cron hook (e.g. daily).
     */
    public static function refreshTorNodeList(): void
    {
        $instance = new self();

        try {
            $body = $instance->fps_httpGet(self::TOR_BULK_URL);
            if ($body === null || trim($body) === '') {
                logModuleCall(
                    'fraud_prevention_suite',
                    'TorDatacenter refreshTorNodeList',
                    self::TOR_BULK_URL,
                    'Empty or failed response from Tor Project',
                    '',
                    []
                );
                return;
            }

            $ips = self::fps_parseTorList($body);
            if (empty($ips)) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'TorDatacenter refreshTorNodeList',
                    self::TOR_BULK_URL,
                    'No IPs parsed from response',
                    '',
                    []
                );
                return;
            }

            $now = date('Y-m-d H:i:s');

            try {
                Capsule::table(self::TOR_TABLE)->truncate();

                // Insert in batches of 500 to avoid oversized queries
                foreach (array_chunk($ips, 500) as $batch) {
                    $rows = array_map(
                        static fn(string $ip): array => [
                            'ip_address'   => $ip,
                            'last_seen_at' => $now,
                        ],
                        $batch
                    );
                    Capsule::table(self::TOR_TABLE)->insert($rows);
                }
            } catch (\Throwable $e) {
                logModuleCall(
                    'fraud_prevention_suite',
                    'TorDatacenter refreshTorNodeList DB Error',
                    self::TOR_BULK_URL,
                    $e->getMessage(),
                    '',
                    []
                );
                return;
            }

            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter refreshTorNodeList',
                self::TOR_BULK_URL,
                sprintf('Refreshed %d Tor exit nodes at %s', count($ips), $now),
                '',
                []
            );
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter refreshTorNodeList Fatal',
                self::TOR_BULK_URL,
                $e->getMessage(),
                '',
                []
            );
        }
    }

    /**
     * Download bad-asn-list CSV and merge with hardcoded hosting ASNs,
     * then repopulate mod_fps_datacenter_asns.
     *
     * Should be called from the module's cron hook (e.g. weekly).
     */
    public static function refreshDatacenterAsnList(): void
    {
        $instance = new self();
        $now      = date('Y-m-d H:i:s');

        // Start with hardcoded known hosting ASNs
        $asnMap = [];
        foreach (self::KNOWN_HOSTING_ASNS as $asn => $description) {
            $asnMap[$asn] = [
                'asn'             => $asn,
                'description'     => $description,
                'category'        => 'hosting',
                'last_updated_at' => $now,
            ];
        }

        // Attempt to pull the community bad-ASN CSV and merge
        try {
            $csv = $instance->fps_httpGet(self::BAD_ASN_CSV_URL);
            if ($csv !== null && trim($csv) !== '') {
                $parsed = self::fps_parseBadAsnCsv($csv);
                foreach ($parsed as $row) {
                    $asn = $row['asn'];
                    // Only add if not already in hardcoded list (preserve our descriptions)
                    if (!isset($asnMap[$asn])) {
                        $asnMap[$asn] = [
                            'asn'             => $asn,
                            'description'     => $row['description'],
                            'category'        => 'bad_asn',
                            'last_updated_at' => $now,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // CSV download failure is non-fatal; proceed with hardcoded list
            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter refreshDatacenterAsnList CSV Error',
                self::BAD_ASN_CSV_URL,
                $e->getMessage(),
                '',
                []
            );
        }

        if (empty($asnMap)) {
            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter refreshDatacenterAsnList',
                self::BAD_ASN_CSV_URL,
                'No ASN entries to insert',
                '',
                []
            );
            return;
        }

        try {
            Capsule::table(self::ASN_TABLE)->truncate();

            foreach (array_chunk(array_values($asnMap), 500) as $batch) {
                Capsule::table(self::ASN_TABLE)->insert($batch);
            }
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'TorDatacenter refreshDatacenterAsnList DB Error',
                self::BAD_ASN_CSV_URL,
                $e->getMessage(),
                '',
                []
            );
            return;
        }

        logModuleCall(
            'fraud_prevention_suite',
            'TorDatacenter refreshDatacenterAsnList',
            self::BAD_ASN_CSV_URL,
            sprintf('Refreshed %d datacenter ASN entries at %s', count($asnMap), $now),
            '',
            []
        );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Return true if the IP appears in the local Tor exit node table.
     */
    private function fps_isTorExitNode(string $ip): bool
    {
        try {
            return Capsule::table(self::TOR_TABLE)
                ->where('ip_address', $ip)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return true if mod_fps_ip_intel has a cached entry with is_datacenter = 1.
     */
    private function fps_isDatacenterByIntelFlag(string $ip): bool
    {
        try {
            $row = Capsule::table(self::INTEL_TABLE)
                ->where('ip_address', $ip)
                ->select('is_datacenter')
                ->first();

            return $row !== null && (bool) $row->is_datacenter;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Look up the ASN for $ip from mod_fps_ip_intel, then check
     * mod_fps_datacenter_asns. Returns matching row data or null.
     *
     * @return array{asn: int, description: string}|null
     */
    private function fps_isDatacenterAsn(string $ip): ?array
    {
        try {
            $intelRow = Capsule::table(self::INTEL_TABLE)
                ->where('ip_address', $ip)
                ->select('asn')
                ->first();

            if ($intelRow === null) {
                return null;
            }

            $rawAsn = $intelRow->asn ?? '';
            // asn may be stored as "AS12345" or plain integer
            $asnInt = self::fps_parseAsnInt((string) $rawAsn);
            if ($asnInt === null) {
                return null;
            }

            $asnRow = Capsule::table(self::ASN_TABLE)
                ->where('asn', $asnInt)
                ->select(['asn', 'description'])
                ->first();

            if ($asnRow === null) {
                return null;
            }

            return [
                'asn'         => (int) $asnRow->asn,
                'description' => (string) ($asnRow->description ?? ''),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Perform a simple cURL GET and return the response body, or null on failure.
     */
    private function fps_httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WHMCS-FraudPreventionSuite/2.0',
        ]);

        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        return (string) $result;
    }

    // ------------------------------------------------------------------
    // Private static parsing helpers
    // ------------------------------------------------------------------

    /**
     * Parse the Tor Project bulk exit list text.
     * Lines starting with '#' or empty lines are skipped.
     *
     * @return list<string>
     */
    private static function fps_parseTorList(string $body): array
    {
        $ips  = [];
        $seen = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!filter_var($line, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $ips[]       = $line;
        }

        return $ips;
    }

    /**
     * Parse the brianhama bad-asn-list CSV.
     * Expected columns: ASN number, description (header row is skipped).
     *
     * @return list<array{asn: int, description: string}>
     */
    private static function fps_parseBadAsnCsv(string $csv): array
    {
        $rows   = [];
        $lines  = explode("\n", $csv);
        $isFirst = true;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $cols = str_getcsv($line);

            // Skip header row
            if ($isFirst) {
                $isFirst = false;
                // If the first column looks like a header string (not a number), skip it
                if (!is_numeric($cols[0] ?? '')) {
                    continue;
                }
            }

            $rawAsn = trim($cols[0] ?? '');
            $desc   = trim($cols[1] ?? '');

            $asnInt = self::fps_parseAsnInt($rawAsn);
            if ($asnInt === null) {
                continue;
            }

            $rows[] = [
                'asn'         => $asnInt,
                'description' => substr($desc !== '' ? $desc : 'Unknown', 0, 255),
            ];
        }

        return $rows;
    }

    /**
     * Convert an ASN string in either "AS12345" or "12345" format to an integer.
     * Returns null when the value cannot be resolved to a positive integer.
     */
    private static function fps_parseAsnInt(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Strip "AS" prefix (case-insensitive)
        if (stripos($raw, 'AS') === 0) {
            $raw = substr($raw, 2);
        }

        if (!ctype_digit($raw)) {
            return null;
        }

        $int = (int) $raw;
        return $int > 0 ? $int : null;
    }
}

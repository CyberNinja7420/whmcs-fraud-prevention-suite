<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * FpsReasonCodes -- turns the raw provider_scores map (and optional check
 * context) of a fraud check into a ranked, human-readable "why" explanation,
 * the way SEON's AI-insights score or Stripe Radar's risk insights surface
 * the top contributing signals.
 *
 * Pure transform over data the module ALREADY stores (mod_fps_checks.
 * provider_scores + check_context), so it works retroactively on historical
 * checks with no schema change.
 */
final class FpsReasonCodes
{
    /**
     * Map a provider_scores key to a human-readable reason phrase.
     * Keys cover every provider/engine that writes into provider_scores.
     *
     * @var array<string,string>
     */
    private const LABELS = [
        'turnstile'         => 'Failed Cloudflare Turnstile bot challenge',
        'ip_intel'          => 'High-risk IP (VPN / proxy / datacenter)',
        'tor_datacenter'    => 'Tor exit node or datacenter IP',
        'email_validation'  => 'Disposable, role, or malformed email',
        'email_verify'      => 'Email failed verification (MX/SMTP/disposable)',
        'breach_check'      => 'Email found in known data breaches',
        'fingerprint'       => 'Device fingerprint matches prior fraud',
        'abuse_signal'      => 'Listed on abuse blocklists (SFS/Spamhaus)',
        'abuse_signals'     => 'Listed on abuse blocklists (SFS/Spamhaus)',
        'abuseipdb'         => 'IP reported for abuse (AbuseIPDB)',
        'ipqualityscore'    => 'High IPQualityScore fraud score',
        'fraudrecord'       => 'Negative reports on FraudRecord',
        'geo_mismatch'      => 'Billing country differs from IP country',
        'geo_impossibility' => 'Impossible travel between recent logins/orders',
        'velocity'          => 'High velocity (rapid repeated attempts)',
        'ofac_screening'    => 'Possible OFAC sanctions-list match',
        'bin_lookup'        => 'Card BIN country/issuer risk',
        'phone_validation'  => 'Invalid or high-risk phone number',
        'social_presence'   => 'No social/web footprint for identity',
        'domain_reputation' => 'Low-reputation or newly-registered email domain',
        'bot_detection'     => 'Automated/bot behavior detected',
        'behavioral'        => 'Suspicious behavioral biometrics',
        'global_intel'      => 'Seen in the shared global threat network',
        'honeypot'          => 'Hidden honeypot field was filled (bot tell)',
        'pow'               => 'Failed proof-of-work browser challenge',
        'rules'             => 'Matched a custom admin fraud rule',
    ];

    /**
     * Build ranked reason codes from a provider_scores map.
     *
     * @param array<string,mixed>|string $providerScores Decoded map or JSON string.
     * @param array<string,mixed>        $context        Optional check_context (for extra detail).
     * @return list<array{key:string,reason:string,points:float,severity:string}>
     *         Sorted by points descending; only contributing (>0) signals.
     */
    public static function explain($providerScores, array $context = []): array
    {
        if (is_string($providerScores)) {
            $decoded = json_decode($providerScores, true);
            $providerScores = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($providerScores)) {
            return [];
        }

        $reasons = [];
        foreach ($providerScores as $key => $score) {
            $points = is_numeric($score) ? (float) $score : 0.0;
            if ($points <= 0) {
                continue; // only signals that actually contributed risk
            }
            $k = (string) $key;
            $reasons[] = [
                'key'      => $k,
                'reason'   => self::LABELS[$k] ?? self::humanizeKey($k),
                'points'   => round($points, 1),
                'severity' => self::severityFor($points),
            ];
        }

        // Context-derived reasons that aren't always in provider_scores.
        if (!empty($context['honeypot_filled']) && !isset($providerScores['honeypot'])) {
            $reasons[] = ['key' => 'honeypot', 'reason' => self::LABELS['honeypot'], 'points' => 100.0, 'severity' => 'critical'];
        }
        if (!empty($context['turnstile_errors']) && !isset($providerScores['turnstile'])) {
            $reasons[] = ['key' => 'turnstile', 'reason' => self::LABELS['turnstile'], 'points' => 100.0, 'severity' => 'critical'];
        }

        usort($reasons, static fn(array $a, array $b): int => $b['points'] <=> $a['points']);
        return $reasons;
    }

    /**
     * Top-N reasons as a single human sentence, e.g.
     * "Failed Turnstile (+100), Tor exit node (+20), disposable email (+15)".
     *
     * @param array<string,mixed>|string $providerScores
     * @param array<string,mixed>        $context
     */
    public static function summary($providerScores, array $context = [], int $limit = 3): string
    {
        $reasons = self::explain($providerScores, $context);
        if (empty($reasons)) {
            return 'No risk signals contributed (clean check).';
        }
        $parts = [];
        foreach (array_slice($reasons, 0, $limit) as $r) {
            // Whole numbers as ints (100, not 100.0 or a zero-stripped "1").
            $p = (float) $r['points'];
            $pInt = (int) $p;
            $disp = ($p === (float) $pInt) ? (string) $pInt : (string) $p;
            $parts[] = $r['reason'] . ' (+' . $disp . ')';
        }
        $extra = count($reasons) - $limit;
        $sentence = implode('; ', $parts);
        if ($extra > 0) {
            $sentence .= '; +' . $extra . ' more';
        }
        return $sentence;
    }

    private static function severityFor(float $points): string
    {
        if ($points >= 50) {
            return 'critical';
        }
        if ($points >= 25) {
            return 'high';
        }
        if ($points >= 10) {
            return 'medium';
        }
        return 'low';
    }

    private static function humanizeKey(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}

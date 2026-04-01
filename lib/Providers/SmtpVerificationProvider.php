<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * SMTP Verification provider -- live mailbox reachability check.
 *
 * Performs a real SMTP handshake up to RCPT TO to determine whether the
 * target mailbox exists, without ever sending an actual message. Also
 * detects catch-all domains by probing a random address after a positive
 * RCPT TO response.
 *
 * Results are cached in mod_fps_email_intel (smtp_valid, is_catchall columns).
 *
 * Timing: network I/O, typically 2-5 seconds. Not suitable for inline
 * pre-checkout use -- see isQuick().
 */
class SmtpVerificationProvider implements FpsProviderInterface
{
    private const CACHE_TABLE   = 'mod_fps_email_intel';
    private const CACHE_TTL_DAYS = 7;
    private const SMTP_PORT     = 25;
    private const SMTP_TIMEOUT  = 5;
    private const HELO_DOMAIN   = 'fps-check.local';
    private const MAIL_FROM     = 'verify@fps-check.local';
    private const SETTINGS_TABLE = 'mod_fps_settings';

    public function getName(): string
    {
        return 'SMTP Verification';
    }

    public function isEnabled(): bool
    {
        try {
            $row = Capsule::table(self::SETTINGS_TABLE)
                ->where('setting', 'smtp_verification_enabled')
                ->first();

            return ($row !== null && $row->value === '1');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isQuick(): bool
    {
        return false;
    }

    public function getWeight(): float
    {
        return 0.8;
    }

    /**
     * Run SMTP verification for the supplied email address.
     *
     * @param array $context Must contain key 'email' (string).
     * @return array{score: float, details: array, raw: mixed}
     */
    public function check(array $context): array
    {
        $blank = ['score' => 0.0, 'details' => [], 'raw' => null];

        $email = strtolower(trim($context['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $blank;
        }

        $atPos  = strrpos($email, '@');
        $domain = (string) substr($email, $atPos + 1);
        if ($domain === '') {
            return $blank;
        }

        // ------------------------------------------------------------------
        // Check database cache first
        // ------------------------------------------------------------------
        $cached = $this->fps_getCachedSmtpResult($email);
        if ($cached !== null) {
            return [
                'score'   => $this->fps_calculateScore($cached),
                'details' => $cached,
                'raw'     => $cached,
            ];
        }

        // ------------------------------------------------------------------
        // Live SMTP verification
        // ------------------------------------------------------------------
        $result = $this->fps_verifySmtp($email, $domain);

        $details = [
            'smtp_valid'   => $result['valid'],      // true | false | null
            'is_catchall'  => $result['catch_all'],  // bool
            'smtp_response'=> $result['response'],   // last SMTP response line
            'domain'       => $domain,
        ];

        // Persist to cache (fire-and-forget, non-fatal)
        $this->fps_updateEmailIntelCache(
            $email,
            $result['valid'] ?? false,
            $result['catch_all']
        );

        $score = $this->fps_calculateScore($details);

        return [
            'score'   => $score,
            'details' => $details,
            'raw'     => $result,
        ];
    }

    // ------------------------------------------------------------------
    // Private: SMTP handshake
    // ------------------------------------------------------------------

    /**
     * Open a direct SMTP connection to the domain's MX and probe the mailbox.
     *
     * Flow: EHLO -> MAIL FROM -> RCPT TO (real) -> [RCPT TO random] -> QUIT
     * The socket is always closed in a finally block.
     *
     * @return array{valid: bool|null, catch_all: bool, response: string}
     *   valid: true = mailbox exists, false = mailbox rejected, null = inconclusive
     */
    private function fps_verifySmtp(string $email, string $domain): array
    {
        $inconclusiveResult = ['valid' => null, 'catch_all' => false, 'response' => 'no_mx'];

        $mxHosts = $this->fps_getMxServers($domain);
        if (empty($mxHosts)) {
            return $inconclusiveResult;
        }

        $socket = null;

        foreach ($mxHosts as $mxHost) {
            try {
                // Suppress warning -- error handled by checking return value
                $socket = @fsockopen($mxHost, self::SMTP_PORT, $errno, $errstr, self::SMTP_TIMEOUT);

                if ($socket === false) {
                    $socket = null;
                    continue; // Try next MX
                }

                stream_set_timeout($socket, self::SMTP_TIMEOUT);

                // Read greeting banner (220)
                $banner = $this->fps_smtpCommand($socket, '');
                if (!str_starts_with(trim($banner), '2')) {
                    // Server not ready -- try next MX
                    fclose($socket);
                    $socket = null;
                    continue;
                }

                // EHLO
                $ehloResp = $this->fps_smtpCommand($socket, 'EHLO ' . self::HELO_DOMAIN);
                if (!str_starts_with(trim($ehloResp), '2')) {
                    // Fall back to HELO
                    $heloResp = $this->fps_smtpCommand($socket, 'HELO ' . self::HELO_DOMAIN);
                    if (!str_starts_with(trim($heloResp), '2')) {
                        fclose($socket);
                        $socket = null;
                        continue;
                    }
                }

                // MAIL FROM
                $fromResp = $this->fps_smtpCommand($socket, 'MAIL FROM:<' . self::MAIL_FROM . '>');
                if (!str_starts_with(trim($fromResp), '2')) {
                    $this->fps_smtpCommand($socket, 'QUIT');
                    fclose($socket);
                    $socket = null;
                    continue;
                }

                // RCPT TO: target address
                $rcptResp = $this->fps_smtpCommand($socket, 'RCPT TO:<' . $email . '>');
                $rcptCode = (int) substr(trim($rcptResp), 0, 3);

                $valid    = null;
                $catchAll = false;

                if ($rcptCode === 250) {
                    // Mailbox accepted -- now test catch-all with a random address
                    $valid = true;

                    $randomLocal   = bin2hex(random_bytes(10));
                    $randomAddress = $randomLocal . '@' . $domain;
                    $catchAllResp  = $this->fps_smtpCommand($socket, 'RCPT TO:<' . $randomAddress . '>');
                    $catchAllCode  = (int) substr(trim($catchAllResp), 0, 3);

                    $catchAll = ($catchAllCode === 250);

                } elseif (in_array($rcptCode, [550, 551, 553], true)) {
                    // Hard reject -- mailbox definitively does not exist
                    $valid = false;
                } elseif ($rcptCode === 252) {
                    // Cannot verify -- server will accept and attempt delivery
                    $valid = null;
                }
                // Any other code (e.g. 421, 450, 451) remains null (inconclusive)

                $this->fps_smtpCommand($socket, 'QUIT');
                fclose($socket);
                $socket = null;

                return [
                    'valid'     => $valid,
                    'catch_all' => $catchAll,
                    'response'  => $rcptResp,
                ];

            } catch (\Throwable $e) {
                // Swallow per-host errors and try next MX
                logModuleCall(
                    'fraud_prevention_suite',
                    'SmtpVerification Error',
                    $mxHost,
                    $e->getMessage(),
                    '',
                    []
                );
            } finally {
                if ($socket !== null && is_resource($socket)) {
                    fclose($socket);
                    $socket = null;
                }
            }
        }

        // All MX hosts failed to connect
        return ['valid' => null, 'catch_all' => false, 'response' => 'connection_failed'];
    }

    /**
     * Retrieve sorted (lowest priority first) list of MX hostnames for a domain.
     *
     * @return list<string>
     */
    private function fps_getMxServers(string $domain): array
    {
        try {
            $records = @dns_get_record($domain, DNS_MX);
            if (!is_array($records) || count($records) === 0) {
                return [];
            }

            usort($records, static fn(array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

            $hosts = [];
            foreach ($records as $record) {
                $target = $record['target'] ?? '';
                if ($target !== '') {
                    $hosts[] = $target;
                }
            }

            return $hosts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Send a single SMTP command (or nothing, for reading the greeting) and
     * return the full server response. Multi-line responses are concatenated.
     *
     * Passing an empty string skips writing and only reads (used for the
     * initial greeting banner).
     *
     * @param resource $socket
     */
    private function fps_smtpCommand($socket, string $command): string
    {
        if ($command !== '') {
            fwrite($socket, $command . "\r\n");
        }

        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;

            // SMTP continuation lines: "NNN-text", final line: "NNN text" or "NNN\r\n"
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4) {
                break;
            }

            // Check for stream timeout
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                break;
            }
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Private: scoring
    // ------------------------------------------------------------------

    /**
     * Convert SMTP verification details into a fraud risk score (0-100).
     *
     * @param array $details Keys: smtp_valid (bool|null), is_catchall (bool), response (string)
     */
    private function fps_calculateScore(array $details): float
    {
        $score = 0.0;

        $valid    = $details['smtp_valid'] ?? null;
        $catchAll = (bool) ($details['is_catchall'] ?? false);
        $response = $details['smtp_response'] ?? $details['response'] ?? '';

        if ($valid === false) {
            // Hard SMTP rejection (550/551/553) -- strong fraud signal
            $score += 35.0;
        } elseif ($valid === null && str_contains($response, 'connection_failed')) {
            // Could not reach any MX -- mild signal, uncertain
            $score += 5.0;
        }
        // $valid === true and $valid === null (252) both add 0 base points

        if ($catchAll) {
            // Catch-all domains are trivially easy to spoof
            $score += 10.0;
        }

        return min(100.0, max(0.0, $score));
    }

    // ------------------------------------------------------------------
    // Private: cache
    // ------------------------------------------------------------------

    /**
     * Attempt to read a cached SMTP result for this email address.
     *
     * Returns null when no fresh cache row exists, or when the smtp_valid
     * column is absent (i.e. the row was written by EmailValidationProvider
     * before SmtpVerificationProvider had populated the field).
     *
     * @return array{smtp_valid: bool|null, is_catchall: bool, smtp_response: string, domain: string}|null
     */
    private function fps_getCachedSmtpResult(string $email): ?array
    {
        try {
            $row = Capsule::table(self::CACHE_TABLE)
                ->where('email', $email)
                ->where('cached_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::CACHE_TTL_DAYS . ' days')))
                ->first();

            if ($row === null) {
                return null;
            }

            // smtp_valid column: 1 = valid, 0 = invalid, NULL = inconclusive/not yet checked
            // If column doesn't exist on this row we skip the cache
            if (!property_exists($row, 'smtp_valid')) {
                return null;
            }

            $smtpValid = $row->smtp_valid;
            $valid     = ($smtpValid === null) ? null : (bool) $smtpValid;

            return [
                'smtp_valid'    => $valid,
                'is_catchall'   => (bool) ($row->is_catchall ?? false),
                'smtp_response' => 'cached',
                'domain'        => $row->domain ?? (string) substr($email, strrpos($email, '@') + 1),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Upsert smtp_valid and is_catchall into the email intel cache row.
     * A row is created with minimal fields if none already exists.
     */
    private function fps_updateEmailIntelCache(string $email, bool $smtpValid, bool $isCatchAll): void
    {
        try {
            $atPos  = strrpos($email, '@');
            $domain = (string) substr($email, $atPos + 1);
            $now    = date('Y-m-d H:i:s');

            $exists = Capsule::table(self::CACHE_TABLE)
                ->where('email', $email)
                ->exists();

            if ($exists) {
                Capsule::table(self::CACHE_TABLE)
                    ->where('email', $email)
                    ->update([
                        'smtp_valid'  => (int) $smtpValid,
                        'is_catchall' => (int) $isCatchAll,
                        'cached_at'   => $now,
                    ]);
            } else {
                Capsule::table(self::CACHE_TABLE)->insert([
                    'email'       => $email,
                    'email_hash'  => hash('sha256', $email),
                    'domain'      => $domain,
                    'smtp_valid'  => (int) $smtpValid,
                    'is_catchall' => (int) $isCatchAll,
                    'cached_at'   => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal -- cache write failure must never break the check
        }
    }
}

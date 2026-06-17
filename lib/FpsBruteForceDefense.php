<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsBruteForceDefense -- pre-auth failed-login brute-force detection and
 * automatic account/IP lockout (FEATURE 2).
 *
 * FpsLoginDefense only fires AFTER a SUCCESSFUL login (the ATO model). This
 * class closes the pre-auth gap: a LogActivity hook feeds every 'Failed Login'
 * (client) and 'Failed Admin Login Attempt' (admin) entry into recordFailure(),
 * which tallies attempts per (ip,email) inside a sliding window. When the count
 * crosses the configured threshold it writes a lockout row. A high-priority
 * ClientAreaPage hook on login.php then calls isLockedOut() and, when locked,
 * injects a hard block + a Cloudflare Turnstile captcha gate (reusing
 * FpsTurnstileValidator) so a human can prove themselves while bots are stopped.
 *
 * All operations are defensive: missing tables/columns are guarded, every path
 * is wrapped in try/catch(\Throwable), and lockouts auto-expire. Nothing throws
 * into a hook. Tunables (threshold, window minutes, lockout minutes) are
 * settings read from mod_fps_settings.
 */
final class FpsBruteForceDefense
{
    private const ATTEMPTS_TABLE = 'mod_fps_login_attempts';
    private const LOCKOUT_TABLE  = 'mod_fps_login_lockouts';
    private const MODULE = 'fraud_prevention_suite';

    // Defaults (overridable via settings).
    private const DEFAULT_THRESHOLD   = 5;   // failures in window before lockout
    private const DEFAULT_WINDOW_MIN  = 15;  // sliding window (minutes)
    private const DEFAULT_LOCKOUT_MIN = 30;  // lockout duration (minutes)

    /** Master on/off. Default ON once shipped (matches sibling FpsLoginDefense). */
    public function isEnabled(): bool
    {
        $v = $this->setting('login_bruteforce_enabled', '');
        // Empty string = not yet configured -> default ON.
        if ($v === '') {
            return true;
        }
        return in_array(strtolower($v), ['1', 'yes', 'on', 'true'], true);
    }

    public function threshold(): int
    {
        $n = (int) $this->setting('login_bruteforce_threshold', (string) self::DEFAULT_THRESHOLD);
        return max(2, min(100, $n));
    }

    public function windowMinutes(): int
    {
        $n = (int) $this->setting('login_bruteforce_window_min', (string) self::DEFAULT_WINDOW_MIN);
        return max(1, min(1440, $n));
    }

    public function lockoutMinutes(): int
    {
        $n = (int) $this->setting('login_bruteforce_lockout_min', (string) self::DEFAULT_LOCKOUT_MIN);
        return max(1, min(10080, $n));
    }

    /**
     * Record one failed-login attempt and lock out if the threshold is crossed.
     * Safe to call from a hook -- never throws.
     *
     * @param string $ip    Source IP (may be empty if unknown).
     * @param string $email Username/email tried (may be empty).
     * @param string $scope 'client' or 'admin' (informational).
     * @return bool true if this attempt triggered/extended a lockout.
     */
    public function recordFailure(string $ip, string $email, string $scope = 'client'): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            self::ensureSchema();

            $ip    = $this->normalizeIp($ip);
            $email = strtolower(trim($email));
            $email = substr($email, 0, 190);
            $scope = in_array($scope, ['client', 'admin'], true) ? $scope : 'client';

            // Nothing actionable without at least an IP or an email key.
            if ($ip === '' && $email === '') {
                return false;
            }

            $now = date('Y-m-d H:i:s');
            Capsule::table(self::ATTEMPTS_TABLE)->insert([
                'ip_address' => $ip ?: null,
                'email'      => $email ?: null,
                'scope'      => $scope,
                'created_at' => $now,
            ]);

            $windowStart = date('Y-m-d H:i:s', strtotime('-' . $this->windowMinutes() . ' minutes'));

            // Count recent failures matching EITHER the same IP or the same email
            // (so an attacker rotating one axis is still caught on the other).
            $q = Capsule::table(self::ATTEMPTS_TABLE)->where('created_at', '>=', $windowStart);
            $q->where(function ($w) use ($ip, $email) {
                $has = false;
                if ($ip !== '')    { $w->orWhere('ip_address', $ip); $has = true; }
                if ($email !== '') { $w->orWhere('email', $email); $has = true; }
                if (!$has) { $w->whereRaw('1=0'); }
            });
            $count = (int) $q->count();

            if ($count >= $this->threshold()) {
                $this->lock($ip, $email, $scope, $count);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::recordFailure', $scope, $e->getMessage());
            return false;
        }
    }

    /**
     * Write (or refresh) a lockout row for the IP and/or email.
     */
    private function lock(string $ip, string $email, string $scope, int $count): void
    {
        try {
            $expires = date('Y-m-d H:i:s', strtotime('+' . $this->lockoutMinutes() . ' minutes'));
            $now     = date('Y-m-d H:i:s');

            foreach ([['ip', $ip], ['email', $email]] as [$type, $val]) {
                if ($val === '') {
                    continue;
                }
                $existing = Capsule::table(self::LOCKOUT_TABLE)
                    ->where('lock_type', $type)
                    ->where('lock_value', $val)
                    ->first();
                if ($existing) {
                    Capsule::table(self::LOCKOUT_TABLE)->where('id', $existing->id)->update([
                        'expires_at'    => $expires,
                        'attempt_count' => $count,
                        'scope'         => $scope,
                        'updated_at'    => $now,
                    ]);
                } else {
                    Capsule::table(self::LOCKOUT_TABLE)->insert([
                        'lock_type'     => $type,
                        'lock_value'    => $val,
                        'scope'         => $scope,
                        'attempt_count' => $count,
                        'expires_at'    => $expires,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    // Audit only on a NEW lockout (avoid log spam on refresh).
                    logActivity('Fraud Prevention: Brute-force lockout (' . $type . '=' . ($type === 'email' ? $this->maskEmail($val) : $val) . ') after ' . $count . ' failed ' . $scope . ' logins in ' . $this->windowMinutes() . ' min.');
                }
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::lock', $scope, $e->getMessage());
        }
    }

    /**
     * Is the current request (by IP and/or email) actively locked out?
     * Auto-expires stale rows. Safe -- never throws; returns false on error.
     */
    public function isLockedOut(string $ip, string $email = ''): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            if (!Capsule::schema()->hasTable(self::LOCKOUT_TABLE)) {
                return false;
            }
            $ip    = $this->normalizeIp($ip);
            $email = strtolower(trim($email));

            $now = date('Y-m-d H:i:s');

            // Opportunistic cleanup of expired rows (cheap, bounded).
            try {
                Capsule::table(self::LOCKOUT_TABLE)->where('expires_at', '<', $now)->delete();
            } catch (\Throwable $e) { /* non-fatal */ }

            if ($ip === '' && $email === '') {
                return false;
            }

            $query = Capsule::table(self::LOCKOUT_TABLE)->where('expires_at', '>=', $now);
            $query->where(function ($w) use ($ip, $email) {
                if ($ip !== '')    { $w->orWhere(function ($x) use ($ip) { $x->where('lock_type', 'ip')->where('lock_value', $ip); }); }
                if ($email !== '') { $w->orWhere(function ($x) use ($email) { $x->where('lock_type', 'email')->where('lock_value', $email); }); }
            });
            return $query->exists();
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::isLockedOut', '', $e->getMessage());
            return false;
        }
    }

    /**
     * HTML hard-block + Turnstile captcha gate, injected into login.php when
     * the visitor is locked out. The block hides the login form and disables
     * its submit until the Turnstile token is present; a standalone endpoint
     * (ajax-bruteforce.php) verifies the token and clears the lockout.
     *
     * @param string $ip
     * @param string $email
     * @param string $ajaxUrl  Standalone clear/verify endpoint URL.
     * @return string HTML (safe to append to ClientAreaPage output).
     */
    public function getBlockHtml(string $ip, string $email, string $ajaxUrl): string
    {
        try {
            $turnstile = new FpsTurnstileValidator();
            $hasTurnstile = $turnstile->isEnabled();
            $siteKey = $hasTurnstile ? htmlspecialchars($turnstile->getSiteKey(), ENT_QUOTES, 'UTF-8') : '';
            $mins = $this->lockoutMinutes();
            $ajaxUrlJs = htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8');

            $captchaBlock = '';
            $tsScript = '';
            if ($hasTurnstile) {
                $captchaBlock = '<div id="fps-bf-turnstile" class="cf-turnstile" data-sitekey="' . $siteKey . '" data-theme="light"></div>'
                    . '<button type="button" id="fps-bf-verify" class="btn btn-primary" style="margin-top:12px;" disabled>Verify and continue</button>'
                    . '<div id="fps-bf-verify-msg" style="margin-top:8px;font-size:13px;"></div>';
                $tsScript = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            }

            $html = $tsScript
                . '<style>'
                . '#fps-bf-overlay{position:fixed;inset:0;z-index:2147483600;background:rgba(15,23,42,.96);display:flex;align-items:center;justify-content:center;padding:20px;}'
                . '#fps-bf-card{max-width:440px;width:100%;background:#fff;border-radius:14px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.4);font-family:-apple-system,Segoe UI,Roboto,sans-serif;text-align:center;}'
                . '#fps-bf-card h2{margin:0 0 8px;font-size:22px;color:#0f172a;}'
                . '#fps-bf-card p{color:#475569;font-size:14px;line-height:1.5;margin:0 0 18px;}'
                . '#fps-bf-card .fps-bf-icon{font-size:42px;margin-bottom:10px;}'
                . '.cf-turnstile{display:flex;justify-content:center;margin:0 auto;}'
                . '</style>'
                . '<div id="fps-bf-overlay" role="alertdialog" aria-modal="true" aria-label="Account temporarily locked">'
                . '  <div id="fps-bf-card">'
                . '    <div class="fps-bf-icon">&#128274;</div>'
                . '    <h2>Too many failed sign-in attempts</h2>'
                . '    <p>For your security, sign-in from this location has been temporarily paused for about ' . (int) $mins . ' minutes. '
                . ($hasTurnstile ? 'Complete the check below to continue immediately.' : 'Please wait and try again later, or contact support if you need help.') . '</p>'
                . $captchaBlock
                . '  </div>'
                . '</div>'
                . '<script>(function(){'
                . 'function lock(){var f=document.querySelectorAll("form input[type=submit],form button[type=submit]");for(var i=0;i<f.length;i++){f[i].disabled=true;}}'
                . 'lock();setTimeout(lock,500);setTimeout(lock,1500);'
                . ($hasTurnstile ? (
                    'var vbtn=document.getElementById("fps-bf-verify");'
                    . 'window.fpsBfTsCb=function(){if(vbtn)vbtn.disabled=false;};'
                    . 'try{if(window.turnstile){window.turnstile.render("#fps-bf-turnstile",{sitekey:"' . $siteKey . '",theme:"light",callback:window.fpsBfTsCb});}}catch(e){}'
                    . 'if(vbtn){vbtn.addEventListener("click",function(){'
                    . 'var msg=document.getElementById("fps-bf-verify-msg");var tok="";try{tok=window.turnstile?window.turnstile.getResponse("#fps-bf-turnstile"):"";}catch(e){}'
                    . 'if(!tok){var el=document.querySelector("input[name=cf-turnstile-response]");tok=el?el.value:"";}'
                    . 'if(!tok){if(msg)msg.textContent="Please complete the check first.";return;}'
                    . 'vbtn.disabled=true;if(msg)msg.textContent="Verifying...";'
                    . 'fetch("' . $ajaxUrlJs . '",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"token="+encodeURIComponent(tok)})'
                    . '.then(function(r){return r.json();}).then(function(d){'
                    . 'if(d&&d.success){var ov=document.getElementById("fps-bf-overlay");if(ov)ov.parentNode.removeChild(ov);'
                    . 'var f=document.querySelectorAll("form input[type=submit],form button[type=submit]");for(var i=0;i<f.length;i++){f[i].disabled=false;}}'
                    . 'else{if(msg)msg.textContent=(d&&d.message)?d.message:"Verification failed. Please try again.";vbtn.disabled=false;}'
                    . '}).catch(function(){if(msg)msg.textContent="Network error. Please try again.";vbtn.disabled=false;});'
                    . '});}'
                ) : '')
                . '})();</script>';

            return $html;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::getBlockHtml', '', $e->getMessage());
            return '';
        }
    }

    /**
     * Clear all active lockouts for an IP and/or email (called after a
     * successful Turnstile verification, or by an admin).
     */
    public function clearLockout(string $ip, string $email = ''): int
    {
        try {
            if (!Capsule::schema()->hasTable(self::LOCKOUT_TABLE)) {
                return 0;
            }
            $ip    = $this->normalizeIp($ip);
            $email = strtolower(trim($email));
            $deleted = 0;
            if ($ip !== '') {
                $deleted += Capsule::table(self::LOCKOUT_TABLE)->where('lock_type', 'ip')->where('lock_value', $ip)->delete();
            }
            if ($email !== '') {
                $deleted += Capsule::table(self::LOCKOUT_TABLE)->where('lock_type', 'email')->where('lock_value', $email)->delete();
            }
            return (int) $deleted;
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::clearLockout', '', $e->getMessage());
            return 0;
        }
    }

    /**
     * Idempotent schema creation. Called from recordFailure() and from the
     * module _activate/_upgrade path.
     */
    public static function ensureSchema(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::ATTEMPTS_TABLE)) {
                Capsule::schema()->create(self::ATTEMPTS_TABLE, function ($t) {
                    $t->increments('id');
                    $t->string('ip_address', 45)->nullable()->index();
                    $t->string('email', 190)->nullable()->index();
                    $t->string('scope', 16)->default('client');
                    $t->dateTime('created_at')->nullable()->index();
                });
            }
            if (!Capsule::schema()->hasTable(self::LOCKOUT_TABLE)) {
                Capsule::schema()->create(self::LOCKOUT_TABLE, function ($t) {
                    $t->increments('id');
                    $t->string('lock_type', 8)->index();   // 'ip' | 'email'
                    $t->string('lock_value', 190)->index();
                    $t->string('scope', 16)->default('client');
                    $t->integer('attempt_count')->default(0);
                    $t->dateTime('expires_at')->index();
                    $t->dateTime('created_at')->nullable();
                    $t->dateTime('updated_at')->nullable();
                });
            }
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'BruteForce::ensureSchema', '', $e->getMessage());
        }
    }

    /**
     * Purge attempt rows older than 24h (called from DailyCronJob).
     */
    public function purgeOldAttempts(): int
    {
        try {
            if (!Capsule::schema()->hasTable(self::ATTEMPTS_TABLE)) {
                return 0;
            }
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
            return (int) Capsule::table(self::ATTEMPTS_TABLE)->where('created_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ----------------------------------------------------------------- helpers

    private function setting(string $key, string $default): string
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_settings')) {
                return $default;
            }
            $v = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            return $v === null ? $default : (string) $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip !== '' && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}

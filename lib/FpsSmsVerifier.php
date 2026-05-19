<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsSmsVerifier -- SMS/OTP verification at checkout via Twilio.
 *
 * Generates 6-digit OTP codes, stores SHA-256 hashes in
 * mod_fps_otp_verifications, sends via Twilio SMS API, and
 * verifies with max-attempt + expiry enforcement.
 */
class FpsSmsVerifier
{
    /** OTP code length */
    private const OTP_LENGTH = 6;

    /** OTP expiry in seconds (5 minutes) */
    private const OTP_EXPIRY_SECONDS = 300;

    /** Maximum verification attempts before lockout */
    private const MAX_ATTEMPTS = 3;

    /** Module name for settings lookups */
    private const MODULE_NAME = 'fraud_prevention_suite';

    /**
     * Send a new OTP to the given phone number.
     *
     * Generates a 6-digit code, stores its SHA-256 hash in
     * mod_fps_otp_verifications, and dispatches the SMS via Twilio.
     *
     * @param string $phone       Raw phone number input
     * @param string $countryCode Optional country code hint (e.g. "US", "GB")
     * @return array{success: bool, message: string, otp_id: string}
     */
    public function fps_sendOtp(string $phone, string $countryCode = ''): array
    {
        try {
            if (!$this->fps_isEnabled()) {
                return ['success' => false, 'message' => 'SMS verification is not configured', 'otp_id' => ''];
            }

            $formattedPhone = $this->fps_formatPhone($phone, $countryCode);
            if ($formattedPhone === '') {
                return ['success' => false, 'message' => 'Invalid phone number format', 'otp_id' => ''];
            }

            // Rate limit: max 3 OTPs per phone per 15 minutes
            $phoneHash = hash('sha256', $formattedPhone);
            $recentCount = 0;
            try {
                if (Capsule::schema()->hasTable('mod_fps_otp_verifications')) {
                    $recentCount = (int) Capsule::table('mod_fps_otp_verifications')
                        ->where('phone_hash', $phoneHash)
                        ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 900))
                        ->count();
                }
            } catch (\Throwable $e) {
                // Non-fatal -- skip rate limit check
            }
            if ($recentCount >= 3) {
                return ['success' => false, 'message' => 'Too many verification attempts. Please wait 15 minutes.', 'otp_id' => ''];
            }

            // Generate OTP
            $otp = $this->fps_generateOtp();
            $otpHash = hash('sha256', $otp);
            $otpId = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', time() + self::OTP_EXPIRY_SECONDS);

            // Store in database
            if (!Capsule::schema()->hasTable('mod_fps_otp_verifications')) {
                return ['success' => false, 'message' => 'OTP verification table not found. Please reactivate the module.', 'otp_id' => ''];
            }

            Capsule::table('mod_fps_otp_verifications')->insert([
                'otp_id'     => $otpId,
                'phone_hash' => $phoneHash,
                'otp_hash'   => $otpHash,
                'attempts'   => 0,
                'expires_at' => $expiresAt,
                'verified'   => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Send via Twilio
            $sendResult = $this->fps_sendViaTwilio($formattedPhone, $otp);
            if (!$sendResult['success']) {
                // Clean up the DB row on send failure
                try {
                    Capsule::table('mod_fps_otp_verifications')
                        ->where('otp_id', $otpId)
                        ->delete();
                } catch (\Throwable $e) {
                    // Non-fatal
                }
                return ['success' => false, 'message' => 'Failed to send SMS: ' . $sendResult['error'], 'otp_id' => ''];
            }

            logModuleCall(self::MODULE_NAME, 'SMS::OtpSent', [
                'phone_hash' => substr($phoneHash, 0, 12) . '...',
                'otp_id'     => $otpId,
            ], 'Sent successfully');

            return ['success' => true, 'message' => 'Verification code sent', 'otp_id' => $otpId];
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'SMS::SendOtpError', '', $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred sending the verification code', 'otp_id' => ''];
        }
    }

    /**
     * Verify an OTP code submitted by the user.
     *
     * Checks the code against the stored hash, enforces 5-minute expiry
     * and maximum 3 attempts per OTP.
     *
     * @param string $phone Raw phone number
     * @param string $code  User-submitted OTP code
     * @param string $otpId The OTP identifier returned by fps_sendOtp
     * @return array{valid: bool, message: string}
     */
    public function fps_verifyOtp(string $phone, string $code, string $otpId): array
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_otp_verifications')) {
                return ['valid' => false, 'message' => 'OTP verification unavailable'];
            }

            $otpId = trim($otpId);
            $code = trim($code);

            if ($otpId === '' || $code === '') {
                return ['valid' => false, 'message' => 'Missing verification code or OTP ID'];
            }

            $record = Capsule::table('mod_fps_otp_verifications')
                ->where('otp_id', $otpId)
                ->first();

            if (!$record) {
                return ['valid' => false, 'message' => 'Invalid verification session'];
            }

            // Check if already verified
            if ((int) $record->verified === 1) {
                return ['valid' => true, 'message' => 'Already verified'];
            }

            // Check expiry
            if (strtotime($record->expires_at) < time()) {
                return ['valid' => false, 'message' => 'Verification code has expired. Please request a new code.'];
            }

            // Check max attempts
            if ((int) $record->attempts >= self::MAX_ATTEMPTS) {
                return ['valid' => false, 'message' => 'Too many failed attempts. Please request a new code.'];
            }

            // Increment attempt counter
            Capsule::table('mod_fps_otp_verifications')
                ->where('otp_id', $otpId)
                ->increment('attempts');

            // Verify the code hash
            $codeHash = hash('sha256', $code);
            if (!hash_equals($record->otp_hash, $codeHash)) {
                $remaining = self::MAX_ATTEMPTS - ((int) $record->attempts + 1);
                $msg = 'Invalid verification code.';
                if ($remaining > 0) {
                    $msg .= ' ' . $remaining . ' attempt(s) remaining.';
                }
                return ['valid' => false, 'message' => $msg];
            }

            // Verify phone hash matches (prevents code reuse across numbers)
            $formattedPhone = $this->fps_formatPhone($phone, '');
            $phoneHash = hash('sha256', $formattedPhone);
            if (!hash_equals($record->phone_hash, $phoneHash)) {
                return ['valid' => false, 'message' => 'Phone number mismatch'];
            }

            // Mark as verified
            Capsule::table('mod_fps_otp_verifications')
                ->where('otp_id', $otpId)
                ->update(['verified' => 1]);

            logModuleCall(self::MODULE_NAME, 'SMS::OtpVerified', [
                'otp_id' => $otpId,
            ], 'Verified successfully');

            return ['valid' => true, 'message' => 'Phone number verified successfully'];
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'SMS::VerifyOtpError', '', $e->getMessage());
            return ['valid' => false, 'message' => 'Verification failed due to a system error'];
        }
    }

    /**
     * Check if SMS verification is enabled and properly configured.
     *
     * Requires sms_verification_enabled = '1' AND valid Twilio credentials
     * (account SID, auth token, and from number).
     */
    public function fps_isEnabled(): bool
    {
        try {
            $settings = $this->fps_loadSmsSettings();
            if (($settings['sms_verification_enabled'] ?? '0') !== '1') {
                return false;
            }
            // Require all three Twilio credentials
            if (empty($settings['twilio_account_sid'])
                || empty($settings['twilio_auth_token'])
                || empty($settings['twilio_from_number'])) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Normalize a phone number to E.164 format.
     *
     * Strips all non-digit characters, prepends country calling code
     * if missing (defaults to +1 for US/CA).
     *
     * @param string $phone       Raw phone input
     * @param string $countryCode ISO 3166-1 alpha-2 country code hint
     * @return string E.164 formatted number (e.g. "+14155551234") or empty on failure
     */
    public function fps_formatPhone(string $phone, string $countryCode): string
    {
        // Strip everything except digits and leading +
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlus = (strpos($phone, '+') === 0);
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '' || strlen($digits) < 7) {
            return '';
        }

        // If already has +, assume E.164
        if ($hasPlus && strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+' . $digits;
        }

        // Country code mapping (common codes)
        $countryCallingCodes = [
            'US' => '1', 'CA' => '1', 'GB' => '44', 'UK' => '44',
            'AU' => '61', 'DE' => '49', 'FR' => '33', 'IN' => '91',
            'BR' => '55', 'MX' => '52', 'JP' => '81', 'NL' => '31',
            'IT' => '39', 'ES' => '34', 'SE' => '46', 'NO' => '47',
            'DK' => '45', 'FI' => '358', 'NZ' => '64', 'SG' => '65',
            'HK' => '852', 'IE' => '353', 'ZA' => '27', 'PH' => '63',
        ];

        $cc = strtoupper(trim($countryCode));
        $callingCode = $countryCallingCodes[$cc] ?? '1'; // Default to US/CA

        // If the digits already start with the calling code, don't double-prepend
        if (strpos($digits, $callingCode) === 0 && strlen($digits) >= 10) {
            return '+' . $digits;
        }

        // Prepend calling code
        $e164 = '+' . $callingCode . $digits;

        // Sanity check E.164 length (7-15 digits after +)
        $finalDigits = preg_replace('/[^0-9]/', '', $e164);
        if (strlen($finalDigits) < 7 || strlen($finalDigits) > 15) {
            return '';
        }

        return $e164;
    }

    /**
     * Generate a cryptographically secure 6-digit OTP code.
     *
     * @return string Zero-padded 6-digit code (e.g. "042917")
     */
    private function fps_generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Send an SMS message via the Twilio API.
     *
     * @param string $to  E.164 formatted phone number
     * @param string $otp The OTP code to include in the message body
     * @return array{success: bool, error: string, sid: string}
     */
    private function fps_sendViaTwilio(string $to, string $otp): array
    {
        try {
            $settings = $this->fps_loadSmsSettings();
            $sid = $settings['twilio_account_sid'] ?? '';
            $token = $settings['twilio_auth_token'] ?? '';
            $from = $settings['twilio_from_number'] ?? '';

            if ($sid === '' || $token === '' || $from === '') {
                return ['success' => false, 'error' => 'Twilio credentials not configured', 'sid' => ''];
            }

            $url = 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode($sid) . '/Messages.json';
            $body = 'Your FPS verification code: ' . $otp . '. This code expires in 5 minutes.';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'From' => $from,
                    'To'   => $to,
                    'Body' => $body,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERPWD        => $sid . ':' . $token,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return ['success' => false, 'error' => 'cURL error: ' . $curlError, 'sid' => ''];
            }

            $data = json_decode((string) $response, true);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'error'   => '',
                    'sid'     => $data['sid'] ?? '',
                ];
            }

            $twilioError = $data['message'] ?? ('HTTP ' . $httpCode);
            logModuleCall(self::MODULE_NAME, 'Twilio::SendError', [
                'to' => substr($to, 0, 6) . '****',
                'http_code' => $httpCode,
            ], $twilioError);

            return ['success' => false, 'error' => $twilioError, 'sid' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'sid' => ''];
        }
    }

    /**
     * Load SMS-related settings from mod_fps_settings.
     *
     * @return array<string, string>
     */
    private function fps_loadSmsSettings(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $keys = [
            'sms_verification_enabled',
            'twilio_account_sid',
            'twilio_auth_token',
            'twilio_from_number',
        ];

        $cache = [];
        try {
            if (Capsule::schema()->hasTable('mod_fps_settings')) {
                $rows = Capsule::table('mod_fps_settings')
                    ->whereIn('setting_key', $keys)
                    ->pluck('setting_value', 'setting_key')
                    ->toArray();
                $cache = $rows;
            }
        } catch (\Throwable $e) {
            // Return empty cache -- caller handles missing keys
        }
        return $cache;
    }

    /**
     * Cleanup expired and verified OTP records older than 24 hours.
     *
     * Called by DailyCronJob hook for table hygiene.
     *
     * @return int Number of records purged
     */
    public function fps_cleanupExpiredOtps(): int
    {
        try {
            if (!Capsule::schema()->hasTable('mod_fps_otp_verifications')) {
                return 0;
            }

            $cutoff = date('Y-m-d H:i:s', time() - 86400);
            return Capsule::table('mod_fps_otp_verifications')
                ->where('expires_at', '<', $cutoff)
                ->delete();
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE_NAME, 'SMS::CleanupError', '', $e->getMessage());
            return 0;
        }
    }
}

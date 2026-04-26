<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsWebhookNotifier -- outbound webhook dispatcher for fraud alert notifications.
 *
 * Supports Slack incoming webhooks, Microsoft Teams MessageCard webhooks,
 * Discord embeds, and generic HTTP endpoints. Each platform receives a
 * natively formatted payload so alerts render correctly in every destination.
 *
 * Webhooks are pulled from mod_fps_webhook_configs. Each row carries an
 * optional HMAC secret; when present, outbound requests include an
 * X-FPS-Signature header so receiving systems can verify authenticity.
 *
 * All sends are logged to mod_fps_webhook_log for auditing.
 *
 * Webhook config table: mod_fps_webhook_configs
 * Webhook log table:    mod_fps_webhook_log
 */
class FpsWebhookNotifier
{
    private const MODULE_NAME    = 'fraud_prevention_suite';
    private const CURL_TIMEOUT   = 3;

    /**
     * Resolve module version from the global FPS_MODULE_VERSION constant so this
     * class stays in sync with the single source of truth. Falls back to 'unknown'
     * if the constant is somehow not defined (defensive; should never happen on a
     * properly-loaded module).
     */
    private static function version(): string
    {
        return defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
    }

    /** Map risk level to hex colour for Slack attachments */
    private const SLACK_COLOURS = [
        'critical' => '#eb3349',
        'high'     => '#f5576c',
        'medium'   => '#f5c842',
    ];

    /** Map risk level to hex colour (without #) for Teams ThemeColor */
    private const TEAMS_COLOURS = [
        'critical' => 'eb3349',
        'high'     => 'f5576c',
        'medium'   => 'f5c842',
    ];

    /** Map risk level to decimal embed colour for Discord */
    private const DISCORD_COLOURS = [
        'critical' => 15,  // 0xEB3349 = 15,  handled via fps_levelToDiscordColour
        'high'     => 16,
        'medium'   => 17,
    ];

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Send a fraud alert notification to all enabled webhook endpoints.
     *
     * Iterates over enabled rows in mod_fps_webhook_configs, filters by the
     * "fraud_alert" event type when the events column is populated, formats
     * the payload for the appropriate platform, and fires a non-blocking cURL
     * request (3-second timeout). Every attempt is logged to mod_fps_webhook_log.
     *
     * @param string $level    Risk level: medium | high | critical
     * @param int    $orderId  WHMCS order ID (0 for client-only checks)
     * @param int    $clientId WHMCS client ID
     * @param float  $score    Final risk score 0-100
     * @param array  $details  Human-readable risk factor strings
     */
    public function sendFraudAlert(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): void {
        $webhooks = $this->fps_fetchEnabledWebhooks('fraud_alert');

        foreach ($webhooks as $webhook) {
            $type    = (string) ($webhook->type ?? 'generic');
            $url     = (string) ($webhook->url  ?? '');
            $secret  = (string) ($webhook->secret ?? '');
            $webhookId = (int) ($webhook->id ?? 0);

            if ($url === '') {
                continue;
            }

            $payload = match ($type) {
                'slack'   => $this->fps_formatSlackPayload($level, $orderId, $clientId, $score, $details),
                'teams'   => $this->fps_formatTeamsPayload($level, $orderId, $clientId, $score, $details),
                'discord' => $this->fps_formatDiscordPayload($level, $orderId, $clientId, $score, $details),
                default   => $this->fps_formatGenericPayload($level, $orderId, $clientId, $score, $details),
            };

            $result = $this->fps_sendWebhook($url, $payload, $secret);

            $this->fps_logWebhookSend(
                $webhookId,
                'fraud_alert',
                $result['success'],
                $result['http_code'],
                $result['response']
            );
        }
    }

    /**
     * Send a test message to a single webhook and return the outcome.
     *
     * Useful from the admin UI to verify a webhook is correctly configured
     * before real fraud alerts are dispatched.
     *
     * @param int $webhookId Row ID from mod_fps_webhook_configs
     * @return array{success: bool, response: string, http_code: int}
     */
    public function sendTestWebhook(int $webhookId): array
    {
        $failResponse = ['success' => false, 'response' => '', 'http_code' => 0];

        try {
            $webhook = Capsule::table('mod_fps_webhook_configs')
                ->where('id', $webhookId)
                ->first();
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsWebhookNotifier::sendTestWebhook::DB',
                (string) $webhookId,
                $e->getMessage()
            );
            return $failResponse;
        }

        if ($webhook === null) {
            return array_merge($failResponse, ['response' => 'Webhook ID not found.']);
        }

        $type   = (string) ($webhook->type ?? 'generic');
        $url    = (string) ($webhook->url  ?? '');
        $secret = (string) ($webhook->secret ?? '');

        if ($url === '') {
            return array_merge($failResponse, ['response' => 'Webhook URL is empty.']);
        }

        // Build a representative test payload using sentinel values
        $payload = match ($type) {
            'slack'   => $this->fps_formatSlackPayload('high', 0, 0, 75.0, ['Test notification from Fraud Prevention Suite']),
            'teams'   => $this->fps_formatTeamsPayload('high', 0, 0, 75.0, ['Test notification from Fraud Prevention Suite']),
            'discord' => $this->fps_formatDiscordPayload('high', 0, 0, 75.0, ['Test notification from Fraud Prevention Suite']),
            default   => $this->fps_formatGenericPayload('high', 0, 0, 75.0, ['Test notification from Fraud Prevention Suite']),
        };

        $result = $this->fps_sendWebhook($url, $payload, $secret);

        $this->fps_logWebhookSend(
            $webhookId,
            'test',
            $result['success'],
            $result['http_code'],
            $result['response']
        );

        return [
            'success'   => $result['success'],
            'response'  => $result['response'],
            'http_code' => $result['http_code'],
        ];
    }

    // =========================================================================
    // Private: payload formatters
    // =========================================================================

    /**
     * Build a Slack incoming webhook JSON payload.
     *
     * Produces a rich attachment with a colour-coded sidebar, structured
     * fields for all fraud data, and a direct admin link.
     *
     * @return string JSON payload
     */
    private function fps_formatSlackPayload(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): string {
        $colour    = self::SLACK_COLOURS[$level] ?? '#cccccc';
        $levelText = strtoupper($level);
        $orderText = $orderId > 0 ? "#$orderId" : 'N/A';
        $scoreText = number_format($score, 1) . '/100';
        $adminUrl  = $this->fps_buildAdminUrl();

        $topFactors = array_slice($details, 0, 5);
        $factorsText = !empty($topFactors)
            ? implode("\n- ", array_merge([''], $topFactors))
            : 'None recorded';

        $attachment = [
            'fallback'    => "FPS Fraud Alert: {$levelText} risk (score {$scoreText})",
            'color'       => $colour,
            'pretext'     => ':rotating_light: *Fraud Prevention Suite Alert*',
            'title'       => "Risk Level: {$levelText}",
            'ts'          => time(),
            'footer'      => 'Fraud Prevention Suite v' . self::version(),
            'footer_icon' => 'https://whmcs.com/favicon.ico',
            'fields'      => [
                [
                    'title' => 'Risk Level',
                    'value' => $levelText,
                    'short' => true,
                ],
                [
                    'title' => 'Score',
                    'value' => $scoreText,
                    'short' => true,
                ],
                [
                    'title' => 'Client ID',
                    'value' => "#$clientId",
                    'short' => true,
                ],
                [
                    'title' => 'Order ID',
                    'value' => $orderText,
                    'short' => true,
                ],
                [
                    'title' => 'Top Risk Factors',
                    'value' => $factorsText,
                    'short' => false,
                ],
                [
                    'title' => 'View in WHMCS',
                    'value' => "<{$adminUrl}|Open Fraud Prevention Suite>",
                    'short' => false,
                ],
            ],
        ];

        return (string) json_encode(['attachments' => [$attachment]], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a Microsoft Teams MessageCard JSON payload.
     *
     * Uses the legacy MessageCard schema for broad connector compatibility.
     * Includes risk facts and a deep-link action button to the WHMCS admin.
     *
     * @return string JSON payload
     */
    private function fps_formatTeamsPayload(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): string {
        $colour    = self::TEAMS_COLOURS[$level] ?? 'cccccc';
        $levelText = strtoupper($level);
        $orderText = $orderId > 0 ? "#$orderId" : 'N/A';
        $scoreText = number_format($score, 1) . '/100';
        $adminUrl  = $this->fps_buildAdminUrl();

        $topFactors = array_slice($details, 0, 5);
        $factorsText = !empty($topFactors)
            ? implode('; ', $topFactors)
            : 'None recorded';

        $card = [
            '@type'       => 'MessageCard',
            '@context'    => 'https://schema.org/extensions',
            'themeColor'  => $colour,
            'summary'     => "FPS Fraud Alert: {$levelText} risk (score {$scoreText})",
            'title'       => 'Fraud Prevention Suite - Risk Alert',
            'sections'    => [
                [
                    'activityTitle'    => ":rotating_light: {$levelText} Risk Detected",
                    'activitySubtitle' => 'Fraud Prevention Suite v' . self::version(),
                    'facts'            => [
                        ['name' => 'Risk Level', 'value' => $levelText],
                        ['name' => 'Score',      'value' => $scoreText],
                        ['name' => 'Client ID',  'value' => "#$clientId"],
                        ['name' => 'Order ID',   'value' => $orderText],
                        ['name' => 'Timestamp',  'value' => date('Y-m-d H:i:s T')],
                        ['name' => 'Risk Factors', 'value' => $factorsText],
                    ],
                    'markdown' => true,
                ],
            ],
            'potentialAction' => [
                [
                    '@type'   => 'OpenUri',
                    'name'    => 'View in WHMCS Admin',
                    'targets' => [
                        ['os' => 'default', 'uri' => $adminUrl],
                    ],
                ],
            ],
        ];

        return (string) json_encode($card, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a Discord webhook JSON payload.
     *
     * Produces an embed with a decimal colour value, structured fields,
     * and a module footer.
     *
     * @return string JSON payload
     */
    private function fps_formatDiscordPayload(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): string {
        $colour    = $this->fps_levelToDiscordColour($level);
        $levelText = strtoupper($level);
        $orderText = $orderId > 0 ? "#$orderId" : 'N/A';
        $scoreText = number_format($score, 1) . '/100';
        $adminUrl  = $this->fps_buildAdminUrl();

        $topFactors = array_slice($details, 0, 5);
        $factorsText = !empty($topFactors)
            ? implode("\n- ", array_merge([''], $topFactors))
            : 'None recorded';

        $embed = [
            'title'       => ":rotating_light: Fraud Alert: {$levelText} Risk",
            'url'         => $adminUrl,
            'color'       => $colour,
            'description' => "A fraud check has flagged a {$levelText} risk event.",
            'fields'      => [
                ['name' => 'Risk Level', 'value' => $levelText,    'inline' => true],
                ['name' => 'Score',      'value' => $scoreText,    'inline' => true],
                ['name' => 'Client ID',  'value' => "#$clientId",  'inline' => true],
                ['name' => 'Order ID',   'value' => $orderText,    'inline' => true],
                ['name' => 'Timestamp',  'value' => date('Y-m-d H:i:s T'), 'inline' => false],
                ['name' => 'Top Risk Factors', 'value' => $factorsText, 'inline' => false],
            ],
            'footer' => [
                'text' => 'Fraud Prevention Suite v' . self::version(),
            ],
            'timestamp' => date('c'),
        ];

        return (string) json_encode(['embeds' => [$embed]], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a generic JSON webhook payload.
     *
     * Includes all raw fraud event data in a flat structure. Suitable for
     * n8n, Zapier, Make, custom HTTP listeners, and any endpoint that
     * expects unformatted JSON rather than a platform-specific schema.
     *
     * @return string JSON payload
     */
    private function fps_formatGenericPayload(
        string $level,
        int $orderId,
        int $clientId,
        float $score,
        array $details
    ): string {
        $payload = [
            'event'          => 'fraud_alert',
            'timestamp'      => date('c'),
            'module_version' => self::version(),
            'level'          => $level,
            'score'          => $score,
            'order_id'       => $orderId,
            'client_id'      => $clientId,
            'details'        => $details,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // Private: transport
    // =========================================================================

    /**
     * Dispatch a single webhook request via cURL.
     *
     * Uses a 3-second connect + transfer timeout so slow endpoints do not
     * stall the WHMCS request lifecycle. When a secret is provided the
     * payload is signed with HMAC-SHA256 and the signature is sent in the
     * X-FPS-Signature header (hex-encoded).
     *
     * @param string $url     Target URL
     * @param string $payload JSON body
     * @param string $secret  Optional HMAC signing secret
     * @return array{success: bool, http_code: int, response: string}
     */
    private function fps_sendWebhook(string $url, string $payload, string $secret = ''): array
    {
        $result = ['success' => false, 'http_code' => 0, 'response' => ''];

        // SSRF protection: validate URL before sending
        if (!self::fps_isValidWebhookUrl($url)) {
            $result['response'] = 'Invalid webhook URL: must be https with a public hostname';
            return $result;
        }

        $ch = curl_init();
        if ($ch === false) {
            $result['response'] = 'curl_init() failed';
            return $result;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: WHMCS-FPS/' . self::version(),
        ];

        if ($secret !== '') {
            $signature = hash_hmac('sha256', $payload, $secret);
            $headers[] = "X-FPS-Signature: {$signature}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false, // Disabled: prevent SSRF via redirect
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $result['response'] = $curlError !== '' ? $curlError : 'cURL exec returned false';
            $result['http_code'] = $httpCode;
            return $result;
        }

        $result['http_code'] = $httpCode;
        $result['response']  = substr((string) $response, 0, 2000);
        $result['success']   = $httpCode >= 200 && $httpCode < 300;

        return $result;
    }

    // =========================================================================
    // Private: database helpers
    // =========================================================================

    /**
     * Fetch all enabled webhook configs that subscribe to the given event type.
     *
     * Rows with a NULL or empty events column receive all event types.
     * Rows with a JSON array in the events column are filtered to those that
     * include $eventType.
     *
     * @return array<object>
     */
    private function fps_fetchEnabledWebhooks(string $eventType): array
    {
        try {
            $rows = Capsule::table('mod_fps_webhook_configs')
                ->where('enabled', 1)
                ->get();
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsWebhookNotifier::fps_fetchEnabledWebhooks',
                $eventType,
                $e->getMessage()
            );
            return [];
        }

        $matched = [];
        foreach ($rows as $row) {
            $eventsJson = $row->events ?? null;

            // Null or empty events column means subscribe to everything
            if ($eventsJson === null || $eventsJson === '') {
                $matched[] = $row;
                continue;
            }

            $events = json_decode((string) $eventsJson, true);
            if (!is_array($events)) {
                // Malformed JSON -- treat as no filter applied
                $matched[] = $row;
                continue;
            }

            if (in_array($eventType, $events, true)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * Write a send attempt record to mod_fps_webhook_log.
     *
     * Failures here are non-fatal and logged only to the module call log so
     * they do not suppress the actual webhook result.
     *
     * @param int    $webhookId ID of the webhook config row
     * @param string $type      Event type string (e.g. fraud_alert, test)
     * @param bool   $success   Whether the HTTP response was 2xx
     * @param int    $httpCode  HTTP status code received
     * @param string $response  First 2000 chars of response body
     */
    private function fps_logWebhookSend(
        int $webhookId,
        string $type,
        bool $success,
        int $httpCode,
        string $response
    ): void {
        try {
            Capsule::table('mod_fps_webhook_log')->insert([
                'webhook_id'    => $webhookId,
                'event_type'    => $type,
                'success'       => $success ? 1 : 0,
                'http_code'     => $httpCode,
                'response_body' => $response !== '' ? $response : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'FpsWebhookNotifier::fps_logWebhookSend',
                json_encode(['webhook_id' => $webhookId, 'type' => $type]),
                $e->getMessage()
            );
        }
    }

    // =========================================================================
    // Private: utility
    // =========================================================================

    /**
     * Convert a risk level string to a Discord-compatible decimal embed colour.
     *
     * Discord requires embed colours as decimal integers, not hex strings.
     */
    private function fps_levelToDiscordColour(string $level): int
    {
        return match ($level) {
            'critical' => 0xEB3349,  // red
            'high'     => 0xF5576C,  // coral
            'medium'   => 0xF5C842,  // amber
            default    => 0xAAAAAA,  // grey fallback
        };
    }

    /**
     * Build the WHMCS admin deep-link URL for the FPS addon module.
     *
     * Attempts to detect the WHMCS base URL from the server environment,
     * falling back to a relative path when detection fails.
     */
    private function fps_buildAdminUrl(): string
    {
        try {
            $adminDir = defined('WHMCS_ADMIN_DIR') ? constant('WHMCS_ADMIN_DIR') : 'admin';

            // Use WHMCS SystemURL (works in CLI/cron, unlike SERVER_NAME)
            $systemUrl = Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')->value('value') ?? '';
            if ($systemUrl) {
                $baseUrl = rtrim($systemUrl, '/');
                return "{$baseUrl}/{$adminDir}/addonmodules.php?module=fraud_prevention_suite";
            }

            // Fallback for web context only
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            if ($host !== '') {
                return "{$scheme}://{$host}/{$adminDir}/addonmodules.php?module=fraud_prevention_suite";
            }
        } catch (\Throwable) {
            // Fall through to safe fallback
        }

        return 'addonmodules.php?module=fraud_prevention_suite';
    }

    /**
     * Validate a webhook URL to prevent SSRF attacks.
     *
     * Only allows https:// URLs pointing to public (non-internal) IP addresses.
     * Resolves the hostname (A + AAAA) and rejects targets in:
     *   - IPv4 loopback (127.0.0.0/8), 0.0.0.0, link-local (169.254/16),
     *     and all RFC 1918 private ranges (10/8, 172.16/12, 192.168/16).
     *   - IPv4-mapped IPv6 wrapping any of the above (::ffff:0:0/96).
     *   - IPv6 loopback (::1), unique-local (fc00::/7), link-local (fe80::/10).
     * Uses PHP's FILTER_VALIDATE_IP with NO_PRIV_RANGE | NO_RES_RANGE as the
     * canonical allowlist; the explicit prefix checks above are a defence in
     * depth for IP variants that the filter is lenient about.
     */
    public static function fps_isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        // Enforce HTTPS only
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        $host = $parsed['host'];

        // Build the candidate IP list. For a hostname this is gethostbynamel
        // (IPv4) plus dns_get_record AAAA (IPv6) where the resolver supports
        // it. For a literal IP, just use the literal.
        $ips = [];
        $literal = filter_var($host, FILTER_VALIDATE_IP);
        if ($literal !== false) {
            $ips[] = $host;
        } else {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
            if (function_exists('dns_get_record')) {
                $v6 = @dns_get_record($host, DNS_AAAA);
                if (is_array($v6)) {
                    foreach ($v6 as $rec) {
                        if (!empty($rec['ipv6'])) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
        }

        if (empty($ips)) {
            return false; // Cannot resolve = not safe
        }

        foreach ($ips as $ip) {
            if (!self::fps_isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true only if the supplied address string is a routable
     * public IP (v4 or v6). Rejects private, reserved, loopback, link-local,
     * unique-local, and IPv4-mapped-IPv6 wrappers of the above.
     */
    private static function fps_isPublicIp(string $ip): bool
    {
        // Defence in depth: explicit IPv4 prefix denylist first.
        if (str_starts_with($ip, '127.')) return false;
        if (str_starts_with($ip, '10.')) return false;
        if (str_starts_with($ip, '192.168.')) return false;
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) return false;
        if (str_starts_with($ip, '169.254.')) return false;
        if ($ip === '0.0.0.0') return false;

        // IPv4-mapped IPv6 (::ffff:a.b.c.d). Re-check the embedded IPv4.
        if (stripos($ip, '::ffff:') === 0) {
            $embedded = substr($ip, 7);
            // Either dotted-quad (::ffff:192.168.0.1) or hex (::ffff:c0a8:1).
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::fps_isPublicIp($embedded);
            }
            // Hex form -- be conservative and reject.
            return false;
        }

        // IPv6: explicit denies for loopback, unique-local, link-local.
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            // ::1 loopback
            if ($ip === '::1') return false;
            $first = ord($packed[0]);
            // fc00::/7 unique-local (first byte 0xFC or 0xFD)
            if (($first & 0xFE) === 0xFC) return false;
            // fe80::/10 link-local
            $second = ord($packed[1]);
            if ($first === 0xFE && ($second & 0xC0) === 0x80) return false;
        }

        // Final canonical pass: PHP's filter rejects private + reserved
        // ranges in one shot. If it lets the address through after the
        // explicit checks above, treat it as public.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

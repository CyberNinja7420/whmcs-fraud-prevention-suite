<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsAutomationDetector (v5.6) -- AI-agent / headless-browser / automation
 * detection.
 *
 * Scores server-side + client-collected signals that betray a non-human client:
 * navigator.webdriver, automation-framework globals (Selenium/Puppeteer/
 * Playwright/PhantomJS/Nightmare + ChromeDriver `$cdc_` leaks), headless
 * user-agents, headless WebGL renderers (SwiftShader/llvmpipe/Mesa), and the
 * classic Headless-Chrome leaks (no plugins, no languages, missing window.chrome).
 *
 * Reads the browser fingerprint blob the checkout page already posts
 * (assets/js/fps-fingerprint.js -> features.automation/webdriver/plugins) plus
 * the request User-Agent and WebGL renderer. Pure request-time analysis -- no
 * external API.
 */
final class FpsAutomationDetector
{
    /** Headless / automation user-agent signatures. */
    private const UA_PATTERNS = [
        'headlesschrome', 'headless', 'phantomjs', 'slimerjs', 'electron',
        'puppeteer', 'playwright', 'selenium', 'webdriver', 'cypress',
        'python-requests', 'python-urllib', 'curl/', 'wget/', 'go-http-client',
        'okhttp', 'node-fetch', 'axios/', 'httpclient', 'java/', 'libwww-perl',
    ];

    /** Headless / software-renderer WebGL signatures. */
    private const RENDERER_PATTERNS = ['swiftshader', 'llvmpipe', 'mesa offscreen', 'google inc. (google)', 'virtualbox', 'vmware'];

    /**
     * @return array{score: float, details: string, reasons: array<int,string>, factors: array<int,array{factor:string,score:float}>}
     */
    public function detect(?string $fingerprintJson = null, ?string $userAgent = null): array
    {
        $blank = ['score' => 0.0, 'details' => '', 'reasons' => [], 'factors' => []];

        if (!$this->isEnabled()) {
            return $blank;
        }

        $ua = strtolower(trim($userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        $raw = $fingerprintJson;
        if ($raw === null) {
            $raw = is_string($_POST['fps_fingerprint'] ?? null) ? (string) $_POST['fps_fingerprint'] : '';
        }
        $fp = json_decode($raw, true);
        $fp = is_array($fp) ? $fp : [];

        $features = is_array($fp['features'] ?? null) ? $fp['features'] : [];
        $nav      = is_array($fp['nav'] ?? null) ? $fp['nav'] : [];
        $webgl    = is_array($fp['webgl'] ?? null) ? $fp['webgl'] : [];

        $reasons = [];
        $factors = [];
        $score   = 0.0;
        $add = function (string $reason, float $points, string $factor) use (&$reasons, &$factors, &$score): void {
            $reasons[] = $reason;
            $factors[] = ['factor' => $factor, 'score' => $points];
            $score += $points;
        };

        // 1. navigator.webdriver === true -- the canonical automation flag.
        if (($features['webdriver'] ?? null) === true) {
            $add('navigator.webdriver=true', (float) $this->setting('auto_weight_webdriver', '35'), 'webdriver');
        }

        // 2. Automation-framework globals collected client-side.
        $autoMarkers = trim((string) ($features['automation'] ?? ''));
        if ($autoMarkers !== '') {
            $list = array_filter(array_map('trim', explode(',', $autoMarkers)));
            // 'webdriver' here is the marker form; avoid double counting with #1.
            $list = array_values(array_diff($list, ['webdriver']));
            if ($list !== []) {
                $add('automation globals: ' . implode(',', array_slice($list, 0, 6)), (float) $this->setting('auto_weight_globals', '40'), 'automation_globals');
            }
        }

        // 3. Headless / scripted user-agent.
        foreach (self::UA_PATTERNS as $p) {
            if ($ua !== '' && strpos($ua, $p) !== false) {
                $add("automation user-agent ($p)", (float) $this->setting('auto_weight_ua', '30'), 'automation_ua');
                break;
            }
        }
        if ($ua === '') {
            $add('empty user-agent', (float) $this->setting('auto_weight_empty_ua', '20'), 'empty_ua');
        }

        // 4. Headless / software WebGL renderer (VM or headless GPU).
        $renderer = strtolower((string) ($webgl['renderer'] ?? $webgl['unmasked_renderer'] ?? ''));
        foreach (self::RENDERER_PATTERNS as $p) {
            if ($renderer !== '' && strpos($renderer, $p) !== false) {
                $add("headless/software GPU ($p)", (float) $this->setting('auto_weight_renderer', '18'), 'headless_gpu');
                break;
            }
        }

        // 5. Headless-Chrome leaks: desktop Chrome UA with no plugins + no languages.
        $plugins = trim((string) ($features['plugins'] ?? ''));
        $langs   = $nav['languages'] ?? ($nav['language'] ?? null);
        $langEmpty = $langs === null || $langs === '' || (is_array($langs) && $langs === []);
        $isChromeUa = strpos($ua, 'chrome') !== false && strpos($ua, 'mobile') === false;
        if ($isChromeUa && $plugins === '' && $langEmpty) {
            $add('headless Chrome leak (no plugins + no languages)', (float) $this->setting('auto_weight_headless_leak', '15'), 'headless_leak');
        }

        // 6. No mime types reported (common in headless).
        if (($features['mimeTypes'] ?? null) === 0 && $isChromeUa) {
            $add('no MIME types', (float) $this->setting('auto_weight_no_mime', '8'), 'no_mime');
        }

        $score = min(100.0, max(0.0, $score));
        return [
            'score'   => $score,
            'details' => $reasons === [] ? 'no automation signals' : implode('; ', $reasons),
            'reasons' => $reasons,
            'factors' => $factors,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->setting('automation_detection_enabled', '1') === '1';
    }

    private function setting(string $key, string $default = ''): string
    {
        try {
            $v = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            return $v === null ? $default : (string) $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

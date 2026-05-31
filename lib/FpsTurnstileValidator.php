<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * FpsTurnstileValidator -- Cloudflare Turnstile CAPTCHA validation.
 *
 * Turnstile is a free, invisible, privacy-friendly CAPTCHA alternative
 * that blocks automated bots without showing puzzles to real users.
 *
 * Setup instructions:
 * 1. Go to https://dash.cloudflare.com/ (free account)
 * 2. Navigate to Turnstile > Add Site
 * 3. Choose "Managed" widget mode (invisible)
 * 4. Copy Site Key and Secret Key into FPS Settings > Turnstile
 * 5. Enable per-form protection toggles
 *
 * Integration points:
 * - ClientAreaHeaderOutput hook injects the Turnstile JS API
 * - ClientAreaFooterOutput hook injects widgets into forms
 * - ShoppingCartValidateCheckout validates the token server-side
 * - ClientAdd validates and flags failed attempts
 */
class FpsTurnstileValidator
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const MODULE_NAME = 'fraud_prevention_suite';

    private FpsConfig $config;

    public function __construct(?FpsConfig $config = null)
    {
        $this->config = $config ?? FpsConfig::getInstance();
    }

    /**
     * Check if Turnstile is enabled and properly configured.
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled('turnstile_enabled')
            && $this->getSiteKey() !== ''
            && $this->getSecretKey() !== '';
    }

    /**
     * Get the Turnstile site key (public, embedded in HTML).
     */
    public function getSiteKey(): string
    {
        return trim((string) $this->config->getCustom('turnstile_site_key', ''));
    }

    /**
     * Get the Turnstile secret key (server-side only).
     */
    public function getSecretKey(): string
    {
        return trim((string) $this->config->getCustom('turnstile_secret_key', ''));
    }

    /**
     * Check if a specific form should be protected.
     */
    public function isFormProtected(string $formName): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->config->isEnabled('turnstile_protect_' . $formName);
    }

    /**
     * Get list of protected form names.
     */
    public function getProtectedForms(): array
    {
        $forms = ['registration', 'checkout', 'login', 'contact', 'ticket'];
        $protected = [];
        foreach ($forms as $form) {
            if ($this->isFormProtected($form)) {
                $protected[] = $form;
            }
        }
        return $protected;
    }

    /**
     * Validate a Turnstile token server-side.
     *
     * @param string $token    The cf-turnstile-response token from the form
     * @param string $remoteIp Client IP address (optional, improves accuracy)
     * @return array{success: bool, error_codes: array, raw: array}
     */
    public function validate(string $token, string $remoteIp = ''): array
    {
        if ($token === '') {
            return [
                'success'     => false,
                'error_codes' => ['missing-input-response'],
                'raw'         => [],
            ];
        }

        try {
            $postData = [
                'secret'   => $this->getSecretKey(),
                'response' => $token,
            ];
            if ($remoteIp !== '') {
                $postData['remoteip'] = $remoteIp;
            }

            $ch = curl_init(self::VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                logModuleCall(
                    self::MODULE_NAME,
                    'Turnstile::validate::CURL_ERROR',
                    json_encode(['http_code' => $httpCode]),
                    $curlError
                );
                // On network error, allow through (fail-open to not block real users)
                return [
                    'success'     => true,
                    'error_codes' => ['network-error-failopen'],
                    'raw'         => ['curl_error' => $curlError, 'http_code' => $httpCode],
                ];
            }

            $result = json_decode($response, true);
            if (!is_array($result)) {
                return [
                    'success'     => true,
                    'error_codes' => ['invalid-json-failopen'],
                    'raw'         => ['response' => substr($response, 0, 500)],
                ];
            }

            $success = (bool) ($result['success'] ?? false);

            logModuleCall(
                self::MODULE_NAME,
                'Turnstile::validate',
                json_encode(['ip' => $remoteIp, 'token_prefix' => substr($token, 0, 16) . '...']),
                json_encode(['success' => $success, 'error-codes' => $result['error-codes'] ?? []]),
                '',
                ['secret']
            );

            return [
                'success'     => $success,
                'error_codes' => $result['error-codes'] ?? [],
                'raw'         => $result,
            ];

        } catch (\Throwable $e) {
            logModuleCall(
                self::MODULE_NAME,
                'Turnstile::validate::ERROR',
                '',
                $e->getMessage()
            );
            // Fail-open: don't block real users on internal errors
            return [
                'success'     => true,
                'error_codes' => ['exception-failopen'],
                'raw'         => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Generate the HTML to inject Turnstile widget into a form via JavaScript.
     *
     * Returns an inline script that finds forms on the page and injects
     * the Turnstile widget div before the submit button.
     */
    public function getInjectionScript(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $siteKey = htmlspecialchars($this->getSiteKey(), ENT_QUOTES, 'UTF-8');
        $protectedForms = $this->getProtectedForms();

        if (empty($protectedForms)) {
            return '';
        }

        // Anchor on the REAL submit button of each protected form. We locate
        // the button, walk up to its enclosing <form>, and inject the widget
        // there -- guaranteeing the cf-turnstile-response field posts with the
        // correct form. Anchoring on the button (not a broad form selector) is
        // essential because:
        //   - WHMCS checkout hides #btnCompleteOrder (.hidden) and submits the
        //     form via a proxy button, so a "visible submit" heuristic misses it
        //   - The checkout page has MULTIPLE forms posting to cart.php (domain
        //     search, promo, the order form) and a broad form[action*=cart.php]
        //     selector matched the wrong one, dropping the token outside #frmCheckout
        // Each entry is an ordered list of button selectors; the first match
        // (that is not inside a modal) wins for that form type.
        $anchorSelectors = [];
        if (in_array('checkout', $protectedForms, true)) {
            $anchorSelectors[] = ['#btnCompleteOrder', '#frmCheckout button[type="submit"]', '#frmCheckout input[type="submit"]'];
        }
        if (in_array('registration', $protectedForms, true)) {
            $anchorSelectors[] = ['#frmRegister button[type="submit"]', '#frmRegister input[type="submit"]', 'form[action*="register.php"] button[type="submit"]', 'form[action*="register.php"] input[type="submit"]'];
        }
        if (in_array('login', $protectedForms, true)) {
            $anchorSelectors[] = ['.login-form button[type="submit"]', 'form[action*="dologin"] button[type="submit"]', 'form[action*="dologin"] input[type="submit"]'];
        }
        if (in_array('contact', $protectedForms, true)) {
            $anchorSelectors[] = ['#frmContactUs button[type="submit"]', 'form[action*="contact.php"] button[type="submit"]', 'form[action*="contact.php"] input[type="submit"]'];
        }
        if (in_array('ticket', $protectedForms, true)) {
            $anchorSelectors[] = ['#frmTicket button[type="submit"]', 'form[action*="submitticket.php"] button[type="submit"]', 'form[action*="submitticket.php"] input[type="submit"]'];
        }

        $selectorsJs = json_encode($anchorSelectors);

        return <<<JS
<script>
(function() {
    var siteKey = '{$siteKey}';
    // Array of anchor-selector GROUPS, one group per protected form type.
    // Each group is an ordered list of submit-button selectors; the first
    // non-modal match in a group identifies that form's real submit button.
    var anchorGroups = {$selectorsJs};

    // Find the real submit button for a group: first match NOT inside a modal.
    // Visibility is intentionally NOT required -- WHMCS hides #btnCompleteOrder
    // and submits via a proxy button, but the widget must still go in its form.
    function findAnchor(group) {
        for (var i = 0; i < group.length; i++) {
            var nodes = document.querySelectorAll(group[i]);
            for (var n = 0; n < nodes.length; n++) {
                var b = nodes[n];
                if (b.closest('.modal') || b.closest('[class*="modal"]')) continue;
                return b;
            }
        }
        return null;
    }

    function injectTurnstile() {
        var any = false;
        for (var g = 0; g < anchorGroups.length; g++) {
            var anchor = findAnchor(anchorGroups[g]);
            if (!anchor) continue;
            var form = anchor.closest('form');
            if (!form) continue;
            // Already injected into this form? done.
            if (form.querySelector('.cf-turnstile')) { any = true; continue; }
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'clear:both;width:100%;padding:12px 0;display:flex;justify-content:center;overflow:visible;';
            var div = document.createElement('div');
            div.className = 'cf-turnstile';
            div.setAttribute('data-sitekey', siteKey);
            div.setAttribute('data-theme', 'light');
            div.setAttribute('data-size', 'normal');
            wrapper.appendChild(div);
            // Insert immediately before the real submit button so the widget
            // (and the cf-turnstile-response field Cloudflare adds) is INSIDE
            // the posting form -- the token then posts with the order.
            anchor.parentNode.insertBefore(wrapper, anchor);
            // Explicit render in case Cloudflare auto-render missed this
            // dynamically-added node (guard against double render).
            try {
                if (window.turnstile && window.turnstile.render && !div.querySelector('iframe')) {
                    window.turnstile.render(div, { sitekey: siteKey, theme: 'light', size: 'normal' });
                }
            } catch (e) {}
            any = true;
        }
        return any;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectTurnstile);
    } else {
        injectTurnstile();
    }
    // Retry for dynamically rendered / late-loading checkout forms
    setTimeout(injectTurnstile, 500);
    setTimeout(injectTurnstile, 1500);
    setTimeout(injectTurnstile, 3000);
})();
</script>
JS;
    }
}

<?php
/**
 * FpsAnalyticsInjector -- builds the <script> blocks for client-side and
 * admin-side analytics. Pure string output, no side effects. Caller
 * (hooks.php) decides where to echo the result.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';
require_once __DIR__ . '/FpsAnalyticsConsentManager.php';

final class FpsAnalyticsInjector
{
    /**
     * Client-side script block. Returns '' when disabled or no IDs configured.
     *
     * @param array<string, mixed> $context Optional FPS user_properties to attach
     *                                      (e.g. ['fps_country' => 'US', 'fps_trust_score' => 0.9])
     */
    public static function client(string $visitorCountry, array $context = []): string
    {
        if (!FpsAnalyticsConfig::isClientEnabled()) return '';

        $ga4     = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        $clarity = FpsAnalyticsConfig::get('clarity_project_id_client', '');
        if ($ga4 === '' && $clarity === '') return '';

        $showBanner = FpsAnalyticsConsentManager::shouldShowBanner($visitorCountry);
        $userProps  = self::userPropertiesScript($context);
        $bannerHtml = $showBanner ? self::bannerHtml() : '';

        $out = "<!-- FPS analytics (client) -->\n";
        // Consent Mode v2 default-deny stub MUST come first, before any tag loads
        $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
        $out .= "gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});\n";
        $out .= "</script>\n";

        if ($ga4 !== '' && self::idValid($ga4, 'ga4')) {
            $idEsc = htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8');
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$idEsc}\"></script>\n";
            $out .= "<script>gtag('js', new Date());\n";
            // json_encode() produces a properly-quoted JS string literal. htmlspecialchars
            // is wrong here: HTML entities are NOT decoded inside <script>, so it would
            // produce literal &#039; etc. in the GA4 measurement ID.
            $out .= "gtag('config'," . json_encode($ga4, JSON_UNESCAPED_SLASHES) . ",{anonymize_ip:true,send_page_view:true});\n";
            $out .= $userProps;
            $out .= "</script>\n";
        }

        if ($clarity !== '' && self::idValid($clarity, 'clarity')) {
            $idEsc = htmlspecialchars($clarity, ENT_QUOTES, 'UTF-8');
            $out .= "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,\"clarity\",\"script\",{$idEsc});\n";
            // Attach FPS context as Clarity custom tags
            foreach ($context as $k => $v) {
                if (!is_scalar($v)) continue;
                // json_encode() produces a properly-quoted JS string literal (handles
                // single/double quotes, backslashes, control chars, unicode). HTML
                // entities are NOT decoded inside <script>, so htmlspecialchars would
                // corrupt the data with literal &#039; etc.
                $out .= "clarity('set'," . json_encode((string) $k, JSON_UNESCAPED_SLASHES) . "," . json_encode((string) $v, JSON_UNESCAPED_SLASHES) . ");\n";
            }
            $out .= "</script>\n";
        }

        $out .= $bannerHtml;
        return $out;
    }

    /**
     * Admin-side script block. Returns '' when disabled or no IDs configured.
     * Falls back to client IDs when admin-specific IDs are absent.
     * No consent banner (admin is internal use).
     */
    public static function admin(string $adminId = '', string $adminRole = ''): string
    {
        if (!FpsAnalyticsConfig::isAdminEnabled()) return '';

        $ga4 = FpsAnalyticsConfig::get('ga4_measurement_id_admin', '');
        if ($ga4 === '') {
            $ga4 = FpsAnalyticsConfig::get('ga4_measurement_id_client', '');
        }
        $clarity = FpsAnalyticsConfig::get('clarity_project_id_admin', '');
        if ($clarity === '') {
            $clarity = FpsAnalyticsConfig::get('clarity_project_id_client', '');
        }
        if ($ga4 === '' && $clarity === '') return '';

        $modVer    = defined('FPS_MODULE_VERSION') ? FPS_MODULE_VERSION : 'unknown';
        $userProps = self::userPropertiesScript([
            'fps_admin_id'       => $adminId,
            'fps_admin_role'     => $adminRole,
            'fps_module_version' => $modVer,
        ]);

        $out = "<!-- FPS analytics (admin) -->\n";

        if ($ga4 !== '' && self::idValid($ga4, 'ga4')) {
            $idEsc = htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8');
            $out .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$idEsc}\"></script>\n";
            $out .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
            // json_encode() produces a properly-quoted JS string literal. See note in client()
            // for why htmlspecialchars is wrong inside <script>.
            $out .= "gtag('js',new Date());gtag('config'," . json_encode($ga4, JSON_UNESCAPED_SLASHES) . ",{anonymize_ip:true,send_page_view:true});\n";
            $out .= $userProps;
            $out .= "</script>\n";
        }

        if ($clarity !== '' && self::idValid($clarity, 'clarity')) {
            $idEsc = htmlspecialchars($clarity, ENT_QUOTES, 'UTF-8');
            $out .= "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,\"clarity\",\"script\",{$idEsc});\n";
            // json_encode() produces a properly-quoted JS string. See note in client()
            // for why htmlspecialchars is wrong inside <script>.
            $out .= "clarity('identify'," . json_encode('admin_' . $adminId, JSON_UNESCAPED_SLASHES) . ");\n";
            $out .= "</script>\n";
        }

        return $out;
    }

    /**
     * Build a gtag user_properties call from a context array.
     * Returns '' for empty input. Filters non-scalars; coerces bools to 1/0.
     *
     * @param array<string, mixed> $context
     */
    private static function userPropertiesScript(array $context): string
    {
        if ($context === []) return '';

        $sanitized = [];
        foreach ($context as $k => $v) {
            if (!is_scalar($v)) continue;
            $sanitized[(string) $k] = is_bool($v) ? ($v ? 1 : 0) : $v;
        }

        if ($sanitized === []) return '';

        return "gtag('set','user_properties'," . json_encode($sanitized, JSON_UNESCAPED_SLASHES) . ");\n";
    }

    /** Returns the consent banner container div. */
    private static function bannerHtml(): string
    {
        return "<div id=\"fps-consent-banner\" data-active=\"1\" hidden></div>\n";
    }

    /** Dispatch ID validation to the appropriate FpsAnalyticsConfig method. */
    private static function idValid(string $id, string $kind): bool
    {
        return $kind === 'ga4'
            ? FpsAnalyticsConfig::isValidGa4Id($id)
            : FpsAnalyticsConfig::isValidClarityId($id);
    }
}

<?php
/**
 * FpsAnalyticsConsentManager -- decides whether to show the EEA cookie
 * banner and tracks the resulting consent state.
 *
 * EEA list cached as a const here; if the visitor's IP-derived country
 * is in the list AND analytics_eea_consent_required = '1', the banner
 * is rendered and Consent Mode v2 default-deny is set.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

require_once __DIR__ . '/FpsAnalyticsConfig.php';

final class FpsAnalyticsConsentManager
{
    /** EU + EEA + UK + Switzerland (Microsoft made consent mandatory for these in 2025). */
    private const EEA_COUNTRIES = [
        'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE',
        'IS','IT','LI','LT','LU','LV','MT','NL','NO','PL','PT','RO','SE','SI','SK',
        'GB','CH',
    ];

    public static function isEeaVisitor(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::EEA_COUNTRIES, true);
    }

    public static function shouldShowBanner(string $country): bool
    {
        if (FpsAnalyticsConfig::get('analytics_eea_consent_required', '1') !== '1') {
            return true; // operator chose: show banner for everyone
        }
        return self::isEeaVisitor($country);
    }

    /** Read the user's previously-stored consent decision from the cookie. */
    public static function readConsent(): ?bool
    {
        if (!isset($_COOKIE['fps_consent'])) return null;
        return $_COOKIE['fps_consent'] === '1';
    }
}

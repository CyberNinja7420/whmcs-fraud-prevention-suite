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
    public const EEA_COUNTRIES = [
        'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE',
        'IS','IT','LI','LT','LU','LV','MT','NL','NO','PL','PT','RO','SE','SI','SK',
        'GB','CH',
    ];

    public static function isEeaVisitor(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::EEA_COUNTRIES, true);
    }

    /**
     * Determines whether to show the cookie consent banner for a given visitor.
     *
     * Semantics (deliberately a little surprising):
     *   - When `analytics_eea_consent_required = '1'` (default): show banner ONLY
     *     to visitors whose IP-derived country is in the EEA list. Non-EEA
     *     visitors get the analytics scripts without a banner.
     *   - When `analytics_eea_consent_required = '0'`: show banner to EVERYONE.
     *     This is the conservative / simplest setting -- operators who want a
     *     uniform GDPR-style banner regardless of jurisdiction.
     *
     * Note: this method only decides VISIBILITY. The actual gating of analytics
     * scripts happens via Consent Mode v2 default-deny in FpsAnalyticsInjector
     * -- the banner just collects the user's choice and updates that state.
     */
    public static function shouldShowBanner(string $country): bool
    {
        if (FpsAnalyticsConfig::get('analytics_eea_consent_required', '1') !== '1') {
            return true; // operator chose: show banner for everyone
        }
        return self::isEeaVisitor($country);
    }

    /**
     * Read the visitor's previously-stored consent decision from the
     * fps_consent cookie set by the consent banner.
     *
     * @return ?bool null = decision pending (no cookie), true = consent granted,
     *               false = consent declined (cookie value not '1')
     *
     * @internal Reserved for Group E (banner JS interop) and Group H (GDPR
     *           purge respecting consent state).
     */
    public static function readConsent(): ?bool
    {
        if (!isset($_COOKIE['fps_consent'])) return null;
        return $_COOKIE['fps_consent'] === '1';
    }
}

<?php
/**
 * AnalyticsBootstrap -- explicit, unconditional include layer for the
 * global-namespace analytics helper classes that live in lib/Analytics/.
 *
 * Why this file exists
 * --------------------
 * lib/Autoloader.php only resolves the FraudPreventionSuite\Lib\ PSR-4
 * prefix. The FpsAnalytics* classes were intentionally created in the
 * GLOBAL namespace (see commit 9a8e4a7 + the header in
 * FpsAnalyticsConfig.php) so existing call sites need no "use" import
 * and so psalm doesn't flag conditional class declarations as
 * UndefinedClass. The autoloader therefore cannot resolve them.
 *
 * Without this bootstrap, hooks.php (which currently only loads
 * Autoloader.php) would fall back to require_once at every call site --
 * fragile, and prone to silent fatals when a hook fires on a request that
 * hasn't already pulled in the helper.
 *
 * Loading guarantee
 * -----------------
 * Once this file has been included exactly once (it self-guards each
 * require_once), every FpsAnalytics* class is available in the global
 * namespace for the remainder of the request. Safe to include from any
 * entry point (addon module bootstrap, hooks.php, public/api.php).
 *
 * NOTE: A full PSR-4 migration of the Analytics namespace was considered
 * but deferred -- see TODO-hardening.md, item "PSR-4 migration of
 * lib/Analytics/ globals". This bootstrap is the safe, mechanical fix.
 *
 * Part of v4.2.5 audit reconciliation.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$fpsAnalyticsHelpers = [
    'FpsAnalyticsConfig.php',
    'FpsAnalyticsLog.php',
    'FpsAnalyticsConsentManager.php',
    'FpsAnalyticsServerEvents.php',
    'FpsAnalyticsInjector.php',
    'FpsAnalyticsDataApi.php',
    'FpsAnalyticsAnomalyDetector.php',
];

foreach ($fpsAnalyticsHelpers as $fpsAnalyticsHelper) {
    $fpsAnalyticsHelperPath = __DIR__ . '/Analytics/' . $fpsAnalyticsHelper;
    if (file_exists($fpsAnalyticsHelperPath)) {
        require_once $fpsAnalyticsHelperPath;
    }
}

unset($fpsAnalyticsHelpers, $fpsAnalyticsHelper, $fpsAnalyticsHelperPath);

<?php
/**
 * Standalone API bootstrap for the Fraud Prevention Suite.
 * Provides clean URL access: /modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global
 *
 * This file initializes WHMCS and routes to the API controller.
 */

// Bootstrap WHMCS
$whmcsInit = dirname(dirname(dirname(dirname(__DIR__)))) . '/init.php';
if (!file_exists($whmcsInit)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'WHMCS initialization failed']);
    exit;
}

require_once $whmcsInit;

// Load autoloader
$autoloaderPath = dirname(__DIR__) . '/lib/Autoloader.php';
if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
}

$fpsAnalyticsBootstrap = dirname(__DIR__) . '/lib/AnalyticsBootstrap.php';
if (file_exists($fpsAnalyticsBootstrap)) {
    require_once $fpsAnalyticsBootstrap;
}

// Ensure the FPS_MODULE_VERSION constant is available in this entry point.
// The constant is normally defined at the top of fraud_prevention_suite.php,
// which WHMCS loads on admin requests - but this public/api.php bootstrap
// doesn't pull in that file. Read the version.json manifest so X-FPS-Version
// is accurate without re-including the full module.
if (!defined('FPS_MODULE_VERSION')) {
    $versionManifest = dirname(__DIR__) . '/version.json';
    $ver = 'unknown';
    if (file_exists($versionManifest)) {
        $decoded = json_decode((string) file_get_contents($versionManifest), true);
        if (is_array($decoded) && !empty($decoded['version'])) {
            $ver = (string) $decoded['version'];
        }
    }
    define('FPS_MODULE_VERSION', $ver);
}

// Route to API
if (class_exists('\\FraudPreventionSuite\\Lib\\Api\\FpsApiRouter')) {
    $router = new \FraudPreventionSuite\Lib\Api\FpsApiRouter();
    $router->handle();
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API router not available']);
}

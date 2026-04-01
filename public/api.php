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

// Route to API
if (class_exists('\\FraudPreventionSuite\\Lib\\Api\\FpsApiRouter')) {
    $router = new \FraudPreventionSuite\Lib\Api\FpsApiRouter();
    $router->handle();
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API router not available']);
}

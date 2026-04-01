<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * FraudPreventionSuite PSR-4-style Autoloader
 *
 * Maps the namespace prefix FraudPreventionSuite\Lib\ to the lib/ directory.
 * Handles nested namespaces: Models, Providers, Api, Admin.
 *
 * Usage:
 *   require_once __DIR__ . '/lib/Autoloader.php';
 *
 * This file registers itself on include -- no additional call needed.
 */

spl_autoload_register(function (string $class): void {
    // Only handle our namespace
    $prefix = 'FraudPreventionSuite\\Lib\\';
    $prefixLen = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLen) !== 0) {
        return;
    }

    // Strip the namespace prefix and convert namespace separators to directory separators
    $relativeClass = substr($class, $prefixLen);
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

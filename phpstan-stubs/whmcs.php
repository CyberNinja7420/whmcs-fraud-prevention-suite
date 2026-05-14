<?php
/**
 * PHPStan stub: WHMCS-host global helper functions used by FPS.
 *
 * These exist at runtime (provided by WHMCS itself) but are not
 * declared in any package PHPStan can autoload. Stubbing them here
 * keeps the analyser quiet about call sites that touch WHMCS internals.
 *
 * Capsule, Builder, Schema and Collection stubs live in separate files
 * (one namespace per file) so PHPStan accepts them as flat <?php scripts.
 */

/** @return mixed */
function logModuleCall(
    string $module,
    string $action,
    $request = null,
    $response = null,
    $processedData = null,
    $replaceVars = null
) {}

/** @return void */
function logActivity(string $description, ?int $userId = null) {}

/** @return string */
function generate_token(string $type = 'plain'): string { return ''; }

/** @return array<string, mixed> */
function localAPI(string $command, array $values = [], string $adminUser = ''): array { return []; }

/** @return string */
function getModuleName(): string { return ''; }

/** @return void */
function add_hook(string $hookName, int $priority, $callback): void {}

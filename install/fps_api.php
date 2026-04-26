<?php
/**
 * Stub for the legacy install/fps_api.php location.
 *
 * The fps_api WHMCS server provisioning module was moved to its
 * canonical WHMCS location:
 *
 *   modules/servers/fps_api/fps_api.php
 *
 * If WHMCS or any installer script tried to load the old path, fail loud
 * so the operator knows to upload the file to the correct place.
 *
 * Removed in v4.2.5 audit reconciliation.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

trigger_error(
    'fps_api server module has moved -- upload to modules/servers/fps_api/fps_api.php (this stub will be removed in v4.3.0).',
    E_USER_WARNING
);

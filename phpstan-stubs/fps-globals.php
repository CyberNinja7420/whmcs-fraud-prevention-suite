<?php
/**
 * Stub declarations for FPS global helper functions that live in
 * separate files but are called cross-file.
 *
 * psalm and phpstan both read the stubs listed in their respective
 * configs. Without these stubs, psalm at errorLevel 6 flags every
 * cross-file call to a global function as UndefinedFunction -- even
 * when the function is plainly defined in another file in <projectFiles>.
 *
 * Runtime function bodies live in:
 *   - lib/FpsMailHelper.php             fps_sendMail
 *   - lib/Gdpr/FpsGdprHelper.php        fps_gdprPurgeByEmail
 *   - lib/Install/FpsInstallHelper.php  fps_createDefaultProducts
 *   - fraud_prevention_suite.php        fps_computePreCheckoutLatency
 *
 * Each implementation is loaded exactly once via `require_once` at the
 * top of fraud_prevention_suite.php.
 *
 * Keep these signatures in sync with the real declarations. This file
 * lives under phpstan-stubs/ which is in psalm/phpstan's ignoreFiles
 * for the analysed source set, so there's no double-declaration conflict.
 */

/**
 * Module-safe mail sender wrapper -- see lib/FpsMailHelper.php.
 *
 * @param array<string, string> $headers
 */
function fps_sendMail(string $to, string $subject, string $body, array $headers = []): bool { return true; }

/**
 * GDPR Article 17 erasure helper -- see lib/Gdpr/FpsGdprHelper.php.
 *
 * @return array{subject: array<string, mixed>, tables: array<string, array{deleted:int, anonymised:int}>}
 */
function fps_gdprPurgeByEmail(string $emailHash, ?string $email = null, ?string $ip = null): array
{
    return ['subject' => [], 'tables' => []];
}

/**
 * Auto-create FPS API products and product group -- see lib/Install/FpsInstallHelper.php.
 *
 * @return array{created: int, error?: string}
 */
function fps_createDefaultProducts(): array { return ['created' => 0]; }

/**
 * Compute pre-checkout latency percentiles -- see fraud_prevention_suite.php.
 *
 * @return array{samples:int, p50:int, p95:int, p99:int, max:int}
 */
function fps_computePreCheckoutLatency(): array
{
    return ['samples' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0, 'max' => 0];
}

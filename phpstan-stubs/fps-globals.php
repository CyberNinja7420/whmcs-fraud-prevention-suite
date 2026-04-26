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

/**
 * Analytics settings reader and validator -- runtime impl in
 * lib/Analytics/FpsAnalyticsConfig.php. Stub here so psalm does
 * not flag cross-file static calls as UndefinedClass.
 */
class FpsAnalyticsConfig
{
    public const KEYS = [];
    public static function get(string $key, string $default = ''): string { return $default; }
    public static function isClientEnabled(): bool { return false; }
    public static function isAdminEnabled(): bool { return false; }
    public static function isServerEnabled(): bool { return false; }
    public static function isValidGa4Id(string $id): bool { return true; }
    public static function isValidClarityId(string $id): bool { return true; }
    public static function isValidServiceAccountJson(string $json): bool { return true; }
    public static function clearCache(): void {}
}

class FpsAnalyticsLog
{
    public const DEST_GA4_CLIENT = 'ga4_client';
    public const DEST_GA4_SERVER = 'ga4_server';
    public const DEST_CLARITY    = 'clarity';
    /** @param array<string, mixed> $payload */
    public static function record(string $eventName, array $payload, string $destination, string $status, ?string $error = null): void {}
    public static function countEventsToday(string $eventName): int { return 0; }
    public static function medianDailyCount(string $eventName, int $days = 14): int { return 0; }
    /** @return array{ts: string|null, count: int} */
    public static function statusSnapshot(string $destination): array { return ['ts' => null, 'count' => 0]; }
    public static function purgeOlderThan(int $days = 30): int { return 0; }
}

class FpsAnalyticsAnomalyDetector
{
    public static function runDaily(): int { return 0; }
}

class FpsAnalyticsServerEvents
{
    /** @var list<string> */
    public const EVENTS = [];
    /** @param array<string, mixed> $params */
    public static function send(string $name, array $params = [], string $cid = ''): void {}
    public static function flush(): void {}
}


class FpsAnalyticsConsentManager {
    public const EEA_COUNTRIES = [];
    public static function isEeaVisitor(string $country): bool { return false; }
    public static function shouldShowBanner(string $country): bool { return false; }
    public static function readConsent(): ?bool { return null; }
}

class FpsAnalyticsInjector {
    /** @param array<string, mixed> $context */
    public static function client(string $visitorCountry, array $context = []): string { return ''; }
    public static function admin(string $adminId = '', string $adminRole = ''): string { return ''; }
}

class FpsAnalyticsDataApi
{
    public static function getYesterdayCount(string $eventName): ?int { return null; }
    /** @return array<int, array{property_id:string, display_name:string, account_name:string}> */
    public static function discoverProperties(string $saJson): array { return []; }
}

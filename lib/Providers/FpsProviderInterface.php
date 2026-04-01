<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Contract for all Fraud Prevention Suite intelligence providers.
 *
 * Each provider performs an independent fraud signal check and returns
 * a normalised score (0-100) plus structured details. Providers MUST
 * degrade gracefully when external dependencies are unavailable.
 */
interface FpsProviderInterface
{
    /**
     * Human-readable provider name shown in admin UI.
     */
    public function getName(): string;

    /**
     * Whether this provider is currently enabled (API key present, etc.).
     */
    public function isEnabled(): bool;

    /**
     * Execute the fraud check against the supplied context.
     *
     * @param array $context Associative array with keys like:
     *   - email        (string)  Client email
     *   - ip           (string)  Client IP address
     *   - phone        (string)  Client phone number
     *   - country      (string)  Client country code (ISO 3166-1 alpha-2)
     *   - card_first6  (string)  First 6 digits of payment card (optional)
     *   - client_id    (int)     WHMCS client ID
     *   - order_id     (int)     WHMCS order ID (optional)
     *   - fingerprint_data (string) JSON browser fingerprint (optional)
     *
     * @return array{score: float, details: array, raw: mixed}
     *   - score   0.0 = no risk, 100.0 = maximum risk (can also be negative for trust signals)
     *   - details Structured key-value breakdown of signals detected
     *   - raw     Unprocessed API/check response for audit logging
     */
    public function check(array $context): array;

    /**
     * Weight multiplier applied to this provider's score during aggregation.
     * Higher = more influence on the composite score.
     */
    public function getWeight(): float;

    /**
     * Whether this provider is safe for pre-checkout inline use (< 1s typical).
     */
    public function isQuick(): bool;
}

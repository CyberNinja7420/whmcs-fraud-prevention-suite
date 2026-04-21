<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use WHMCS\Database\Capsule;
use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use FraudPreventionSuite\Lib\Models\FpsCheckResult;
use FraudPreventionSuite\Lib\Models\FpsRiskResult;
use FraudPreventionSuite\Lib\Models\FpsRuleResult;
use FraudPreventionSuite\Lib\Providers\TorDatacenterProvider;
use FraudPreventionSuite\Lib\Providers\SmtpVerificationProvider;

/**
 * FpsCheckRunner -- the fraud check orchestrator.
 *
 * Coordinates the full check pipeline:
 *   1. Build context from available data
 *   2. Run enabled providers (FraudRecord, IP intel, email validation, etc.)
 *   3. Run custom rules via FpsRuleEngine
 *   4. Aggregate scores via FpsRiskEngine
 *   5. Persist the check result to mod_fps_checks
 *   6. Update daily statistics
 *   7. Trigger notifications if thresholds are exceeded
 *   8. Return a FpsCheckResult
 *
 * Provides entry points for different check scenarios:
 *   - runFullCheck()    -- all providers, used for new order hooks
 *   - runPreCheckout()  -- fast providers only, <2s target
 *   - runManualCheck()  -- admin-initiated check on a client
 *   - runBulkCheck()    -- batch scan multiple clients
 */
class FpsCheckRunner
{
    private FpsConfig $config;
    private FpsRiskEngine $riskEngine;
    private FpsRuleEngine $ruleEngine;
    private FpsNotifier $notifier;
    private FpsStatsCollector $stats;
    private FpsClientTrustManager $trustManager;
    private FpsVelocityEngine $velocityEngine;
    private FpsGeoImpossibilityEngine $geoEngine;
    private FpsBehavioralScoringEngine $behavioralEngine;
    private ?FpsWebhookNotifier $webhookNotifier;

    public function __construct(
        ?FpsConfig $config = null,
        ?FpsRiskEngine $riskEngine = null,
        ?FpsRuleEngine $ruleEngine = null,
        ?FpsNotifier $notifier = null,
        ?FpsStatsCollector $stats = null
    ) {
        $this->config           = $config ?? FpsConfig::getInstance();
        $this->riskEngine       = $riskEngine ?? new FpsRiskEngine($this->config);
        $this->ruleEngine       = $ruleEngine ?? new FpsRuleEngine($this->config);
        $this->notifier         = $notifier ?? new FpsNotifier($this->config);
        $this->stats            = $stats ?? new FpsStatsCollector();
        $this->trustManager     = new FpsClientTrustManager($this->config);
        $this->velocityEngine   = new FpsVelocityEngine($this->config);
        $this->geoEngine        = new FpsGeoImpossibilityEngine();
        $this->behavioralEngine = new FpsBehavioralScoringEngine();
        try {
            $this->webhookNotifier = new FpsWebhookNotifier($this->config);
        } catch (\Throwable $e) {
            $this->webhookNotifier = null;
        }
    }

    /**
     * Run a full fraud check with ALL enabled providers.
     *
     * Used by the AfterShoppingCartCheckout hook and admin manual checks.
     * Runs every enabled provider, aggregates, stores, notifies.
     *
     * @param FpsCheckContext $context The check context
     * @return FpsCheckResult
     */
    public function runFullCheck(FpsCheckContext $context): FpsCheckResult
    {
        $startTime = microtime(true);

        try {
            // Step 0: Check client trust status (allowlist/blacklist)
            if ($context->clientId > 0) {
                if ($this->trustManager->shouldSkipCheck($context->clientId)) {
                    $executionMs = (microtime(true) - $startTime) * 1000;
                    return new FpsCheckResult(
                        checkId:     null,
                        risk:        FpsRiskResult::clean(),
                        rules:       \FraudPreventionSuite\Lib\Models\FpsRuleResult::noMatch(),
                        actionTaken: 'approved',
                        locked:      false,
                        context:     $context,
                        executionMs: $executionMs,
                    );
                }
                if ($this->trustManager->shouldAutoBlock($context->clientId)) {
                    $executionMs = (microtime(true) - $startTime) * 1000;
                    $riskResult = new FpsRiskResult(
                        score: 100.0,
                        level: 'critical',
                        providerScores: ['trust_blacklist' => 100.0],
                        details: ['Client is blacklisted -- auto-blocked'],
                        factors: [['factor' => 'client_blacklisted', 'score' => 100.0, 'provider' => 'trust']],
                    );
                    if ($context->orderId > 0) {
                        $this->fps_lockOrder($context->orderId);
                    }
                    $checkId = $this->fps_persistCheck($context, $riskResult, \FraudPreventionSuite\Lib\Models\FpsRuleResult::noMatch(), 'cancelled', true);
                    $result = new FpsCheckResult(
                        checkId:     $checkId,
                        risk:        $riskResult,
                        rules:       \FraudPreventionSuite\Lib\Models\FpsRuleResult::noMatch(),
                        actionTaken: 'cancelled',
                        locked:      true,
                        context:     $context,
                        executionMs: $executionMs,
                    );
                    $this->stats->recordCheck($result);
                    return $result;
                }
            }

            // Step 1: Collect results from all enabled providers
            $providerResults = $this->fps_runAllProviders($context);

            // Step 2: Run custom rules
            $ruleResult = $this->ruleEngine->evaluate($context);

            // Step 3: Add rule score to provider results for aggregation
            if ($ruleResult->hasMatches()) {
                $providerResults[] = $this->riskEngine->fps_ruleScoreToProviderFormat(
                    $ruleResult->ruleScore,
                    $ruleResult->details,
                    array_map(function (array $r): array {
                        return [
                            'factor' => "rule:{$r['rule_type']}:{$r['rule_name']}",
                            'score'  => $r['score'],
                        ];
                    }, $ruleResult->matchedRules)
                );
            }

            // Step 4: Aggregate via RiskEngine
            $riskResult = $this->riskEngine->aggregate($providerResults);

            // Step 5: Override level if rule engine demands a block
            if ($ruleResult->isBlocking() && $riskResult->level !== 'critical') {
                $riskResult = new FpsRiskResult(
                    score:          max($riskResult->score, 95.0),
                    level:          'critical',
                    providerScores: $riskResult->providerScores,
                    details:        array_merge($riskResult->details, ['Rule engine forced CRITICAL level']),
                    factors:        $riskResult->factors,
                );
            }

            // Step 6: Determine action
            $actionTaken = $this->fps_determineAction($riskResult, $ruleResult);
            $locked      = false;

            // Step 7: Execute action
            if ($actionTaken === 'cancelled' && $context->orderId > 0) {
                $this->fps_lockOrder($context->orderId);
                $locked = true;
            } elseif ($actionTaken === 'held' && $context->orderId > 0) {
                $this->fps_holdOrder($context->orderId);
            }

            $executionMs = (microtime(true) - $startTime) * 1000;

            // Step 8: Persist
            $checkId = $this->fps_persistCheck($context, $riskResult, $ruleResult, $actionTaken, $locked);

            $result = new FpsCheckResult(
                checkId:     $checkId,
                risk:        $riskResult,
                rules:       $ruleResult,
                actionTaken: $actionTaken,
                locked:      $locked,
                context:     $context,
                executionMs: $executionMs,
            );

            // Step 9: Update stats
            $this->stats->recordCheck($result);

            // Step 10: Notify if needed (email + webhooks)
            if ($riskResult->isAlertWorthy()) {
                $this->notifier->notifyAdmin(
                    $riskResult->level,
                    $context->orderId,
                    $context->clientId,
                    $riskResult->score,
                    $riskResult->details,
                );
                // Webhook notifications (Slack/Teams/Discord/generic)
                if ($this->webhookNotifier !== null) {
                    try {
                        $this->webhookNotifier->sendFraudAlert(
                            $riskResult->level,
                            $context->orderId,
                            $context->clientId,
                            $riskResult->score,
                            $riskResult->details,
                        );
                    } catch (\Throwable $e) {
                        // Webhook failure is non-fatal
                        logModuleCall('fraud_prevention_suite', 'WebhookNotify::ERROR', '', $e->getMessage());
                    }
                }
            }

            // Step 10b: Record velocity event for future checks
            if ($context->ip !== '') {
                $this->velocityEngine->recordEvent('order', $context->ip, $context->clientId);
            }

            logModuleCall(
                'fraud_prevention_suite',
                'FpsCheckRunner::runFullCheck',
                json_encode($context->toArray()),
                json_encode([
                    'check_id' => $checkId,
                    'score'    => $riskResult->score,
                    'level'    => $riskResult->level,
                    'action'   => $actionTaken,
                    'ms'       => round($executionMs, 1),
                ])
            );

            return $result;
        } catch (\Throwable $e) {
            $executionMs = (microtime(true) - $startTime) * 1000;

            logModuleCall(
                'fraud_prevention_suite',
                'FpsCheckRunner::runFullCheck::ERROR',
                json_encode($context->toArray()),
                $e->getMessage()
            );

            return FpsCheckResult::fromError($context, $e->getMessage(), $executionMs);
        }
    }

    /**
     * Run a pre-checkout check with fast providers only.
     *
     * Target: <2 seconds. Skips slow external API calls.
     * Uses: cached IP intelligence, email validation, fingerprint, rules.
     *
     * @param FpsCheckContext $context The check context
     * @return FpsCheckResult
     */
    public function runPreCheckout(FpsCheckContext $context): FpsCheckResult
    {
        $startTime = microtime(true);

        try {
            $providerResults = [];

            // Fast provider 1: Cached IP intelligence
            $providerResults[] = $this->fps_runCachedIpIntel($context);

            // Fast provider 2: Email domain check (no external API)
            $providerResults[] = $this->fps_runEmailDomainCheck($context);

            // Fast provider 3: Fingerprint check (DB lookup only)
            if ($context->fingerprintHash !== '') {
                $providerResults[] = $this->fps_runFingerprintCheck($context);
            }

            // Fast provider 4: Custom rules
            $ruleResult = $this->ruleEngine->evaluate($context);
            if ($ruleResult->hasMatches()) {
                $providerResults[] = $this->riskEngine->fps_ruleScoreToProviderFormat(
                    $ruleResult->ruleScore,
                    $ruleResult->details,
                    []
                );
            }

            // Aggregate
            $riskResult = $this->riskEngine->aggregate($providerResults);

            if ($ruleResult->isBlocking()) {
                $riskResult = new FpsRiskResult(
                    score:          max($riskResult->score, 95.0),
                    level:          'critical',
                    providerScores: $riskResult->providerScores,
                    details:        array_merge($riskResult->details, ['Pre-checkout: rule engine blocked']),
                    factors:        $riskResult->factors,
                );
            }

            $actionTaken = $this->fps_determineAction($riskResult, $ruleResult);
            $executionMs = (microtime(true) - $startTime) * 1000;

            // Pre-checkout does NOT lock orders, only reports the risk
            $checkId = $this->fps_persistCheck($context, $riskResult, $ruleResult, $actionTaken, false);

            $result = new FpsCheckResult(
                checkId:     $checkId,
                risk:        $riskResult,
                rules:       $ruleResult,
                actionTaken: $actionTaken,
                locked:      false,
                context:     $context,
                executionMs: $executionMs,
            );

            $this->stats->recordCheck($result);

            return $result;
        } catch (\Throwable $e) {
            $executionMs = (microtime(true) - $startTime) * 1000;

            logModuleCall(
                'fraud_prevention_suite',
                'FpsCheckRunner::runPreCheckout::ERROR',
                json_encode($context->toArray()),
                $e->getMessage()
            );

            return FpsCheckResult::fromError($context, $e->getMessage(), $executionMs);
        }
    }

    /**
     * Run an admin-initiated manual check on a specific client.
     *
     * Builds context from the client record and runs a full check.
     *
     * @param int $clientId The WHMCS client ID
     * @return FpsCheckResult
     */
    public function runManualCheck(int $clientId): FpsCheckResult
    {
        $context = FpsCheckContext::fromClientId($clientId, 'manual');
        return $this->runFullCheck($context);
    }

    /**
     * Run bulk checks on multiple clients.
     *
     * Returns an array of FpsCheckResult keyed by client ID.
     * Includes a small delay between checks to avoid overwhelming
     * external APIs and the database.
     *
     * @param array<int> $clientIds Array of client IDs
     * @return array<int, FpsCheckResult>
     */
    public function runBulkCheck(array $clientIds): array
    {
        $results = [];

        foreach ($clientIds as $clientId) {
            $clientId = (int) $clientId;
            if ($clientId <= 0) {
                continue;
            }

            try {
                $results[$clientId] = $this->runManualCheck($clientId);
            } catch (\Throwable $e) {
                $context = new FpsCheckContext(clientId: $clientId, checkType: 'bulk');
                $results[$clientId] = FpsCheckResult::fromError($context, $e->getMessage());
            }

            // Rate-limit: 100ms pause between checks
            usleep(100_000);
        }

        logModuleCall(
            'fraud_prevention_suite',
            'FpsCheckRunner::runBulkCheck',
            json_encode(['client_count' => count($clientIds)]),
            json_encode(['completed' => count($results)])
        );

        return $results;
    }

    // -----------------------------------------------------------------------
    // Provider runners
    // -----------------------------------------------------------------------

    /**
     * Run all enabled external providers.
     *
     * @return array<array{provider: string, score: float, details: string, factors: array, success: bool}>
     */
    private function fps_runAllProviders(FpsCheckContext $context): array
    {
        $results = [];

        // Provider: FraudRecord API
        if ($this->config->isEnabled('provider_fraudrecord') || $this->config->get('fraudrecord_api_key') !== '') {
            $results[] = $this->fps_runFraudRecordProvider($context);
        }

        // Provider: Cached IP intelligence
        $results[] = $this->fps_runCachedIpIntel($context);

        // Provider: Email domain check
        $results[] = $this->fps_runEmailDomainCheck($context);

        // Provider: Fingerprint
        if ($context->fingerprintHash !== '') {
            $results[] = $this->fps_runFingerprintCheck($context);
        }

        // Provider: Geo-mismatch (IP country vs billing country)
        if ($context->ip !== '' && $context->country !== '') {
            $results[] = $this->fps_runGeoMismatchCheck($context);
        }

        // Provider: OFAC Sanctions Screening (heavyweight -- runs last)
        if ($this->config->isEnabled('ofac_screening_enabled')) {
            $results[] = $this->fps_runOfacScreening($context);
        }

        // v4.0 Provider: AbuseIPDB (crowd-sourced IP abuse reports) -- with 24h cache
        try {
            if ($context->ip !== '' && $this->config->isEnabled('abuseipdb_enabled')
                && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\AbuseIpdbProvider')) {
                // Check cache first (avoid burning free tier quota)
                $cachedAb = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $context->ip)
                    ->where('cached_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                    ->first(['threat_score']);
                if ($cachedAb && isset($cachedAb->threat_score)) {
                    $abResult = ['score' => (float)$cachedAb->threat_score, 'details' => ['cached'], 'raw' => null];
                } else {
                    $abuseIpdb = new \FraudPreventionSuite\Lib\Providers\AbuseIpdbProvider();
                    $abResult = $abuseIpdb->check($context->toArray());
                }
                $results[] = [
                    'provider' => 'abuseipdb',
                    'score'    => (float)($abResult['score'] ?? 0),
                    'details'  => is_array($abResult['details'] ?? null) ? implode('; ', $abResult['details']) : (string)($abResult['details'] ?? ''),
                    'factors'  => [['factor' => 'abuseipdb_score', 'score' => (float)($abResult['score'] ?? 0)]],
                    'success'  => true,
                ];
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // v4.0 Provider: IPQualityScore (advanced proxy/VPN/bot detection) -- with 24h cache
        try {
            if ($context->ip !== '' && $this->config->isEnabled('ipqs_enabled')
                && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\IpQualityScoreProvider')) {
                // Check cache first
                $cachedIpqs = Capsule::table('mod_fps_ip_intel')
                    ->where('ip_address', $context->ip)
                    ->where('cached_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                    ->first(['is_proxy', 'is_vpn']);
                if ($cachedIpqs !== null && ($cachedIpqs->is_proxy !== null || $cachedIpqs->is_vpn !== null)) {
                    $ipqsScore = ((int)($cachedIpqs->is_proxy ?? 0) + (int)($cachedIpqs->is_vpn ?? 0)) * 25;
                    $ipqsResult = ['score' => (float)$ipqsScore, 'details' => ['cached'], 'raw' => null];
                } else {
                    $ipqs = new \FraudPreventionSuite\Lib\Providers\IpQualityScoreProvider();
                    $ipqsResult = $ipqs->check($context->toArray());
                }
                $results[] = [
                    'provider' => 'ipqualityscore',
                    'score'    => (float)($ipqsResult['score'] ?? 0),
                    'details'  => is_array($ipqsResult['details'] ?? null) ? implode('; ', $ipqsResult['details']) : (string)($ipqsResult['details'] ?? ''),
                    'factors'  => [['factor' => 'ipqs_fraud_score', 'score' => (float)($ipqsResult['score'] ?? 0)]],
                    'success'  => true,
                ];
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // v4.0 Provider: Abuse Signals (StopForumSpam + SpamHaus ZEN -- free, no key)
        try {
            if ($this->config->isEnabled('abuse_signal_enabled')
                && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\AbuseSignalProvider')) {
                $abuseSig = new \FraudPreventionSuite\Lib\Providers\AbuseSignalProvider();
                $sigResult = $abuseSig->check($context->toArray());
                $results[] = [
                    'provider' => 'abuse_signals',
                    'score'    => (float)($sigResult['score'] ?? 0),
                    'details'  => is_array($sigResult['details'] ?? null) ? implode('; ', $sigResult['details']) : (string)($sigResult['details'] ?? ''),
                    'factors'  => [['factor' => 'abuse_signal', 'score' => (float)($sigResult['score'] ?? 0)]],
                    'success'  => true,
                ];
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // v4.0 Provider: Domain Reputation (RDAP age, suspicious TLD)
        try {
            if ($context->email !== '' && $this->config->isEnabled('domain_reputation_enabled')
                && class_exists('\\FraudPreventionSuite\\Lib\\Providers\\DomainReputationProvider')) {
                $domRep = new \FraudPreventionSuite\Lib\Providers\DomainReputationProvider();
                $domResult = $domRep->check($context->toArray());
                $results[] = [
                    'provider' => 'domain_reputation',
                    'score'    => (float)($domResult['score'] ?? 0),
                    'details'  => is_array($domResult['details'] ?? null) ? implode('; ', $domResult['details']) : (string)($domResult['details'] ?? ''),
                    'factors'  => [['factor' => 'domain_reputation', 'score' => (float)($domResult['score'] ?? 0)]],
                    'success'  => true,
                ];
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // v4.0 Provider: Bot Pattern Detection (plus-tag, SMS gateway, disposable, numeric)
        try {
            if ($context->email !== '' && $this->config->isEnabled('bot_signup_blocking')
                && class_exists('\\FraudPreventionSuite\\Lib\\FpsBotDetector')) {
                $botScore = 0;
                $botDetails = [];
                $email = $context->email;
                $localPart = substr($email, 0, strpos($email, '@') ?: strlen($email));

                // Plus-tag detection (e.g., user+tag@gmail.com)
                if (strpos($email, '+') !== false) {
                    $botScore += 30;
                    $botDetails[] = 'Plus-tag email detected';
                }

                // Random-looking local part (high consonant density, numbers mixed with letters)
                $alphaOnly = preg_replace('/[^a-z]/i', '', $localPart);
                $digitCount = preg_match_all('/[0-9]/', $localPart);
                if (strlen($alphaOnly) > 0 && $digitCount > 3 && $digitCount / strlen($localPart) > 0.3) {
                    $botScore += 15;
                    $botDetails[] = 'Random-looking email local part';
                }

                // Dot-stuffed local part (e.g., b.e.t.t.y.t)
                $dotCount = substr_count($localPart, '.');
                if ($dotCount >= 3 && strlen($localPart) - $dotCount < 8) {
                    $botScore += 10;
                    $botDetails[] = 'Dot-stuffed email local part';
                }

                if ($botScore > 0) {
                    $results[] = [
                        'provider' => 'bot_detection',
                        'score'    => min(100, (float)$botScore),
                        'details'  => implode('; ', $botDetails),
                        'factors'  => array_map(fn($d) => ['factor' => 'bot_pattern', 'score' => 15.0], $botDetails),
                        'success'  => true,
                        'weight'   => 2.0, // High weight for bot detection
                    ];
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // v3.0 Provider: Tor exit node + datacenter IP detection
        if ($context->ip !== '') {
            $results[] = $this->fps_runTorDatacenterCheck($context);
        }

        // v3.0 Provider: Velocity engine (order/IP/BIN velocity)
        $results[] = $this->velocityEngine->checkVelocity($context->toArray());

        // v3.0 Provider: Geographic impossibility (multi-signal cross-correlation)
        if ($context->ip !== '' || $context->country !== '') {
            $results[] = $this->geoEngine->analyze($context->toArray());
        }

        // v3.0 Provider: SMTP verification (slow -- full check only)
        if ($context->email !== '') {
            $smtpProvider = new SmtpVerificationProvider();
            if ($smtpProvider->isEnabled()) {
                $smtpResult = $smtpProvider->check($context->toArray());
                $results[] = [
                    'provider' => 'smtp_verify',
                    'score'    => (float) ($smtpResult['score'] ?? 0),
                    'details'  => is_array($smtpResult['details'] ?? null)
                        ? implode('; ', array_map('strval', $smtpResult['details']))
                        : (string) ($smtpResult['details'] ?? ''),
                    'factors'  => [['factor' => 'smtp_verification', 'score' => (float) ($smtpResult['score'] ?? 0)]],
                    'success'  => true,
                ];
            }
        }

        // v3.0 Provider: Behavioral scoring (if fingerprint data includes behavioral signals)
        if (!empty($context->meta['behavioral_data'])) {
            $behavioralData = $context->meta['behavioral_data'];
            if (is_string($behavioralData)) {
                $behavioralData = json_decode($behavioralData, true) ?? [];
            }
            if (is_array($behavioralData) && !empty($behavioralData)) {
                $results[] = $this->behavioralEngine->analyze($behavioralData);
                $this->behavioralEngine->recordBehavioralEvent(
                    $context->clientId,
                    $behavioralData['session_id'] ?? bin2hex(random_bytes(16)),
                    $behavioralData,
                    (float) ($results[array_key_last($results)]['score'] ?? 0)
                );
            }
        }

        // v4.0 Provider: Global Threat Intelligence cross-reference
        try {
            if (class_exists('\\FraudPreventionSuite\\Lib\\FpsGlobalIntelChecker')
                && ($context->email !== '' || $context->ip !== '')) {
                $globalChecker = new \FraudPreventionSuite\Lib\FpsGlobalIntelChecker();
                $globalResult = $globalChecker->check($context->email, $context->ip);
                if ($globalResult['found']) {
                    $adjustment = $globalChecker->getScoreAdjustment($globalResult);
                    $results[] = [
                        'provider' => 'global_intel',
                        'score'    => $adjustment,
                        'details'  => "Global intel: seen {$globalResult['seen_count']}x"
                            . ($globalResult['instance_count'] > 1 ? " across {$globalResult['instance_count']} instances" : ''),
                        'factors'  => array_map(
                            fn($k, $v) => ['factor' => "global_{$k}", 'score' => $v ? 5.0 : 0],
                            array_keys($globalResult['evidence']),
                            array_values($globalResult['evidence'])
                        ),
                        'success'  => true,
                        'weight'   => 1.5,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Global intel check is non-fatal -- don't block the fraud check pipeline
        }

        return $results;
    }

    /**
     * FraudRecord API provider.
     */
    private function fps_runFraudRecordProvider(FpsCheckContext $context): array
    {
        $apiKey = $this->config->get('fraudrecord_api_key', '');
        if ($apiKey === '' || $context->email === '') {
            return [
                'provider' => 'fraudrecord',
                'score'    => 0.0,
                'details'  => 'API key not configured or no email',
                'factors'  => [],
                'success'  => false,
            ];
        }

        try {
            $payload = http_build_query([
                'api'    => $apiKey,
                'email'  => md5($context->email),
                'ip'     => $context->ip,
                'phone'  => $context->phone,
                'format' => 'json',
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://www.fraudrecord.com/api/',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'WHMCS-FraudPreventionSuite/2.0',
            ]);

            $raw      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            logModuleCall(
                'fraud_prevention_suite',
                'FraudRecord API',
                $payload,
                $raw,
                '',
                [$apiKey]
            );

            if ($curlErr || $httpCode !== 200) {
                return [
                    'provider' => 'fraudrecord',
                    'score'    => 0.0,
                    'details'  => 'API error: ' . ($curlErr ?: "HTTP {$httpCode}"),
                    'factors'  => [],
                    'success'  => false,
                ];
            }

            $data  = json_decode((string) $raw, true);
            $score = 0.0;

            if (is_array($data) && isset($data['score'])) {
                $score = (float) $data['score'];
            } elseif (is_numeric(trim((string) $raw))) {
                $score = (float) trim((string) $raw);
            }

            // Normalize FraudRecord score (their scale) to 0-100
            // FraudRecord returns 0-10, multiply by 10
            $normalizedScore = min(100.0, $score * 10.0);

            $factors = [];
            if ($normalizedScore > 0) {
                $factors[] = ['factor' => 'fraudrecord_score', 'score' => $normalizedScore];
            }

            return [
                'provider' => 'fraudrecord',
                'score'    => $normalizedScore,
                'details'  => "FraudRecord raw={$score}, normalized={$normalizedScore}",
                'factors'  => $factors,
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'fraudrecord',
                'score'    => 0.0,
                'details'  => 'Exception: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * Cached IP intelligence -- checks mod_fps_checks for known bad IPs.
     */
    private function fps_runCachedIpIntel(FpsCheckContext $context): array
    {
        if ($context->ip === '') {
            return [
                'provider' => 'ip_intel',
                'score'    => 0.0,
                'details'  => 'No IP provided',
                'factors'  => [],
                'success'  => false,
            ];
        }

        try {
            // Check how many times this IP has been flagged in the past
            $flagCount = Capsule::table('mod_fps_checks')
                ->where('ip_address', $context->ip)
                ->whereIn('risk_level', ['high', 'critical'])
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-90 days')))
                ->count();

            $score = min(100.0, (float) ($flagCount * 15));

            $factors = [];
            if ($flagCount > 0) {
                $factors[] = ['factor' => 'ip_repeat_offender', 'score' => $score];
            }

            return [
                'provider' => 'ip_intel',
                'score'    => $score,
                'details'  => "IP {$context->ip} flagged {$flagCount} times in 90 days",
                'factors'  => $factors,
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'ip_intel',
                'score'    => 0.0,
                'details'  => 'DB error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * Email domain check -- flags disposable email domains.
     */
    private function fps_runEmailDomainCheck(FpsCheckContext $context): array
    {
        if ($context->email === '') {
            return [
                'provider' => 'email_verify',
                'score'    => 0.0,
                'details'  => 'No email provided',
                'factors'  => [],
                'success'  => false,
            ];
        }

        try {
            $domain = $context->getEmailDomain();
            $score  = 0.0;
            $factors = [];

            // Check disposable domain list
            $disposableList = $this->config->getCustom('disposable_domains', '');
            if ($disposableList !== '') {
                $disposable = array_map('trim', explode(',', strtolower($disposableList)));
                if (in_array(strtolower($domain), $disposable, true)) {
                    $score = 40.0;
                    $factors[] = ['factor' => 'disposable_email_domain', 'score' => 40.0];
                }
            }

            // Check how many times this email has been flagged
            $emailFlags = Capsule::table('mod_fps_checks')
                ->where('email', $context->email)
                ->whereIn('risk_level', ['high', 'critical'])
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-180 days')))
                ->count();

            if ($emailFlags > 0) {
                $emailScore = min(60.0, (float) ($emailFlags * 20));
                $score      = max($score, $emailScore);
                $factors[]  = ['factor' => 'email_repeat_offender', 'score' => $emailScore];
            }

            return [
                'provider' => 'email_verify',
                'score'    => $score,
                'details'  => "Domain={$domain}, prior flags={$emailFlags}",
                'factors'  => $factors,
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'email_verify',
                'score'    => 0.0,
                'details'  => 'Error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * Fingerprint check -- looks for known bad fingerprints.
     */
    private function fps_runFingerprintCheck(FpsCheckContext $context): array
    {
        try {
            $badCount = Capsule::table('mod_fps_checks')
                ->where('details', 'like', '%' . $context->fingerprintHash . '%')
                ->whereIn('risk_level', ['high', 'critical'])
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-90 days')))
                ->count();

            $score = min(100.0, (float) ($badCount * 25));

            return [
                'provider' => 'fingerprint',
                'score'    => $score,
                'details'  => "Fingerprint seen in {$badCount} flagged checks",
                'factors'  => $badCount > 0 ? [['factor' => 'bad_fingerprint', 'score' => $score]] : [],
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'fingerprint',
                'score'    => 0.0,
                'details'  => 'Error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * Geo-mismatch check -- compares IP geolocation country to billing country.
     */
    private function fps_runGeoMismatchCheck(FpsCheckContext $context): array
    {
        try {
            // Check cached IP-to-country mapping
            $ipCountry = $this->config->getCustom('ipcountry_' . md5($context->ip), '');

            if ($ipCountry === '') {
                return [
                    'provider' => 'geo_mismatch',
                    'score'    => 0.0,
                    'details'  => 'No IP geolocation data cached',
                    'factors'  => [],
                    'success'  => false,
                ];
            }

            $mismatch = strtoupper($ipCountry) !== strtoupper($context->country);
            $score    = $mismatch ? 25.0 : 0.0;

            return [
                'provider' => 'geo_mismatch',
                'score'    => $score,
                'details'  => $mismatch
                    ? "IP country ({$ipCountry}) differs from billing ({$context->country})"
                    : "IP and billing country match ({$context->country})",
                'factors'  => $mismatch
                    ? [['factor' => 'geo_country_mismatch', 'score' => 25.0]]
                    : [],
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'geo_mismatch',
                'score'    => 0.0,
                'details'  => 'Error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * OFAC SDN sanctions screening provider.
     */
    private function fps_runOfacScreening(FpsCheckContext $context): array
    {
        try {
            $provider = new \FraudPreventionSuite\Lib\Providers\OfacScreeningProvider();
            if (!$provider->isEnabled()) {
                return [
                    'provider' => 'ofac_screening',
                    'score'    => 0.0,
                    'details'  => 'OFAC screening disabled',
                    'factors'  => [],
                    'success'  => false,
                ];
            }

            $result = $provider->check($context->toArray());

            $factors = [];
            $matchedNames = array_column($result['details']['matches'] ?? [], 'name');
            if (($result['score'] ?? 0) > 0) {
                $bestMatch = $result['details']['best_similarity'] ?? 0;
                $factors[] = [
                    'factor' => 'ofac_sdn_match',
                    'score'  => $result['score'],
                    'meta'   => [
                        'match_score'   => $bestMatch,
                        'matched_names' => $matchedNames,
                    ],
                ];
            }

            return [
                'provider' => 'ofac_screening',
                'score'    => (float)($result['score'] ?? 0),
                'details'  => !empty($matchedNames)
                    ? 'OFAC SDN match: ' . implode(', ', $matchedNames)
                    : 'No OFAC SDN matches',
                'factors'  => $factors,
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'ofac_screening',
                'score'    => 0.0,
                'details'  => 'Error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    /**
     * Tor exit node + datacenter IP provider (v3.0).
     */
    private function fps_runTorDatacenterCheck(FpsCheckContext $context): array
    {
        try {
            $provider = new TorDatacenterProvider();
            $result = $provider->check($context->toArray());

            $factors = [];
            $score = (float) ($result['score'] ?? 0);
            if ($score > 0) {
                $detailsArr = $result['details'] ?? [];
                $factorName = 'tor_datacenter';
                if (is_array($detailsArr)) {
                    if (!empty($detailsArr['is_tor'])) {
                        $factorName = 'tor_exit_node';
                    } elseif (!empty($detailsArr['is_datacenter'])) {
                        $factorName = 'datacenter_ip';
                    }
                }
                $factors[] = ['factor' => $factorName, 'score' => $score];
            }

            return [
                'provider' => 'tor_datacenter',
                'score'    => $score,
                'details'  => is_array($result['details'] ?? null)
                    ? json_encode($result['details'])
                    : (string) ($result['details'] ?? ''),
                'factors'  => $factors,
                'success'  => true,
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => 'tor_datacenter',
                'score'    => 0.0,
                'details'  => 'Error: ' . $e->getMessage(),
                'factors'  => [],
                'success'  => false,
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Action determination and order manipulation
    // -----------------------------------------------------------------------

    /**
     * Determine the action to take based on risk and rule results.
     *
     * @return string approved|held|cancelled
     */
    private function fps_determineAction(FpsRiskResult $risk, FpsRuleResult $rules): string
    {
        // Rule engine block overrides everything
        if ($rules->isBlocking()) {
            return 'cancelled';
        }

        // Resolve global action thresholds.
        //
        // Historically this function read `block_threshold` / `flag_threshold`
        // settings keys, but no admin UI writes those keys at the global scope -
        // the admin Settings tab only exposes the 4-tier classification keys
        // (risk_*_threshold). Per-gateway overrides DO store `block_threshold` /
        // `flag_threshold` in mod_fps_gateway_thresholds but that path is handled
        // elsewhere.
        //
        // Resolution order (for global scope):
        //   1. explicit block_threshold / flag_threshold setting if the admin
        //      has set one via custom config (preserves backward compatibility)
        //   2. derive from classification: block at risk_critical_threshold,
        //      flag at risk_high_threshold (matches what the admin UI actually
        //      controls)
        //   3. hardcoded safe defaults (80 / 60)
        $critical = $this->config->getFloat('risk_critical_threshold', 80.0, 0.0, 100.0);
        $high     = $this->config->getFloat('risk_high_threshold',     60.0, 0.0, 100.0);

        $blockOverride = $this->config->getFloat('block_threshold', 0.0, 0.0, 100.0);
        $flagOverride  = $this->config->getFloat('flag_threshold',  0.0, 0.0, 100.0);

        $blockThreshold = $blockOverride > 0 ? $blockOverride : $critical;
        $flagThreshold  = $flagOverride  > 0 ? $flagOverride  : $high;

        if ($risk->score >= $blockThreshold) {
            $autoLock = $this->config->isEnabled('auto_lock_critical');
            if ($autoLock) {
                return 'cancelled';
            }
            return 'held';
        }

        if ($risk->score >= $flagThreshold) {
            return 'held';
        }

        return 'approved';
    }

    /**
     * Lock an order by setting it to Fraud status.
     */
    private function fps_lockOrder(int $orderId): void
    {
        try {
            Capsule::table('tblorders')
                ->where('id', $orderId)
                ->update(['status' => 'Fraud']);

            logModuleCall(
                'fraud_prevention_suite',
                'fps_lockOrder',
                "Order #{$orderId}",
                'Status set to Fraud'
            );
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'fps_lockOrder::ERROR',
                "Order #{$orderId}",
                $e->getMessage()
            );
        }
    }

    /**
     * Hold an order by setting it to Pending status for review.
     */
    private function fps_holdOrder(int $orderId): void
    {
        try {
            Capsule::table('tblorders')
                ->where('id', $orderId)
                ->update(['status' => 'Pending']);

            logModuleCall(
                'fraud_prevention_suite',
                'fps_holdOrder',
                "Order #{$orderId}",
                'Status set to Pending'
            );
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'fps_holdOrder::ERROR',
                "Order #{$orderId}",
                $e->getMessage()
            );
        }
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    /**
     * Persist a check result to mod_fps_checks.
     *
     * @return int|null The inserted check ID
     */
    private function fps_persistCheck(
        FpsCheckContext $context,
        FpsRiskResult $risk,
        FpsRuleResult $rules,
        string $actionTaken,
        bool $locked
    ): ?int {
        try {
            $checkId = Capsule::table('mod_fps_checks')->insertGetId([
                'order_id'       => $context->orderId,
                'client_id'      => $context->clientId,
                'check_type'     => $context->checkType,
                'risk_score'     => $risk->score,
                'risk_level'     => $risk->level,
                'ip_address'     => $context->ip ?: null,
                'email'          => $context->email ?: null,
                'phone'          => $context->phone ?: null,
                'country'        => $context->country ?: null,
                'fraudrecord_id' => ($risk->providerScores['fraudrecord'] ?? 0) > 0
                    ? 'checked'
                    : null,
                'raw_response'   => json_encode($risk->toArray()),
                'details'        => json_encode([
                    'risk'    => $risk->toArray(),
                    'rules'   => $rules->toArray(),
                    'context' => $context->toArray(),
                ]),
                'action_taken'   => $actionTaken,
                'locked'         => $locked ? 1 : 0,
                'reported'       => 0,
                'reviewed_by'    => null,
                'reviewed_at'    => null,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            return (int) $checkId;
        } catch (\Throwable $e) {
            logModuleCall(
                'fraud_prevention_suite',
                'fps_persistCheck::ERROR',
                json_encode($context->toArray()),
                $e->getMessage()
            );
            return null;
        }
    }
}

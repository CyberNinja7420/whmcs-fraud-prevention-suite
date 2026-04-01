# FPS v3.0 Enhancement Design

**Date:** 2026-03-29
**Module:** Fraud Prevention Suite
**Upgrade:** v2.0.0 -> v3.0.0

## Summary

10 iterative enhancement loops adding ~60 new fraud detection signals to the existing 9-provider architecture. All new features use 100% free data sources (zero new API keys required). Estimated coverage improvement: ~50% -> ~85% of common hosting fraud patterns.

## Loop 1: Trusted Client Allowlist + Manual Blacklist

- New table: `mod_fps_client_trust` (client_id, status enum, reason, admin_id, timestamps)
- Status values: trusted, normal, blacklisted, suspended
- FpsCheckRunner skips checks for trusted clients, auto-blocks blacklisted
- Admin UI: Trust tab with search, bulk actions, audit log

## Loop 2: Tor Exit Node + Datacenter IP Detection

- New class: `TorDatacenterProvider` implements FpsProviderInterface
- Downloads Tor exit node list from dan.me.uk/tornodes (free, updated hourly)
- Downloads datacenter/hosting ASN list from iptoasn.com
- Cron job refreshes lists daily
- Sets is_tor/is_datacenter flags on mod_fps_ip_intel

## Loop 3: Disposable Email Domain Database Enhancement

- Enhance EmailValidationProvider to use bundled list (4900+ domains)
- Auto-update via cron from GitHub disposable-email-domains repo
- Already partially implemented (data/disposable_domains.txt exists)
- Add catch-all domain detection

## Loop 4: Velocity Engine

- New class: `FpsVelocityEngine`
- New table: `mod_fps_velocity_events` (event_type, identifier, timestamps)
- Tracks: orders/IP/hour, accounts/IP/day, failed payments/client/day, BIN reuse
- Sliding window counters with configurable thresholds
- Integrated into pre-checkout and full check pipelines

## Loop 5: SMTP Verification

- New class: `SmtpVerificationProvider` implements FpsProviderInterface
- Connects to mail server via SMTP (EHLO, MAIL FROM, RCPT TO)
- Detects catch-all domains (test with random address)
- Results cached in mod_fps_email_intel (smtp_valid, is_catchall columns)

## Loop 6: Geographic Impossibility Detection

- New class: `GeoImpossibilityEngine`
- Cross-correlates: IP country, billing country, phone country code, BIN issuing country
- Calculates mismatch score based on number of conflicting signals
- Uses existing provider data (no new external calls)

## Loop 7: Webhook Notifications

- Enhance FpsNotifier with webhook support
- New table: `mod_fps_webhook_configs` (url, type, events, secret, enabled)
- Presets: Slack, Teams, Discord, generic HTTP POST
- Payload templates with variable substitution
- Settings UI for webhook management

## Loop 8: Behavioral Fingerprinting

- New table: `mod_fps_behavioral_events`
- Client-side JS captures: form fill timing, mouse entropy, paste detection, time-on-page
- Server-side scoring via BehavioralScoringEngine
- Integrated into existing fingerprint collection JS

## Loop 9: Adaptive Score Weighting

- New class: `FpsAdaptiveScoring`
- New table: `mod_fps_weight_history` (provider, weight, confidence, period)
- Monthly analysis: which providers predicted actual chargebacks
- Simple logistic regression to adjust weights
- Runs via DailyCronJob (monthly trigger)

## Loop 10: Chargeback Correlation + Auto-Suspension

- Enhance existing InvoiceUnpaid hook
- Correlate chargebacks with original fraud scores
- Auto-suspend clients exceeding chargeback threshold
- Feed correlation data back to adaptive scoring (Loop 9)

## New Database Tables

1. mod_fps_client_trust
2. mod_fps_velocity_events
3. mod_fps_webhook_configs
4. mod_fps_webhook_log
5. mod_fps_behavioral_events
6. mod_fps_weight_history
7. mod_fps_tor_nodes (cached Tor exit node IPs)
8. mod_fps_datacenter_asns (cached hosting/datacenter ASNs)

## Integration Points

- FpsCheckRunner::fps_runAllProviders() - add new providers
- FpsRiskEngine::DEFAULT_WEIGHTS - add new provider weights
- hooks.php - new cron tasks, enhanced chargeback hook
- fraud_prevention_suite_activate() - new tables
- Admin tab navigation - add Trust Management tab
- Settings tab - add webhook, velocity, behavioral config sections

# Fraud Prevention Suite - Wiki Documentation Index

Welcome to the FPS comprehensive documentation. Use this index to find the right guide for your role or task.

**Module version:** v4.2.5 (2026-04-25)

## Documentation Overview

This wiki contains 15 detailed guides covering all aspects of the Fraud Prevention Suite module for WHMCS.

**Total Documentation**: 5,400+ lines across 15 files covering 16 providers, 14 admin tabs, 14 documented WHMCS hook integrations, 70+ AJAX actions, 30 database tables (28 module + 2 analytics), 9 REST API endpoints, and 1 server provisioning module.

---

## Quick Navigation by Role

### For WHMCS Administrators

Start here to install, configure, and operate FPS:

1. **Installation & Setup**
   - README.md (main module docs)
   - [Installation-Guide.md](Installation-Guide.md) - Step-by-step installation
   - [Global-Intel-Setup.md](Global-Intel-Setup.md) - Cross-instance fraud sharing hub
   - [API-Documentation.md](API-Documentation.md) - Enable public API, create keys
   - [Server-Module-Guide.md](Server-Module-Guide.md) - fps_api server module for selling API access

2. **Daily Operations**
   - [Bot-Cleanup-Guide.md](Bot-Cleanup-Guide.md) - Detect and purge bot accounts
   - [Troubleshooting.md](Troubleshooting.md) - Common issues and solutions

3. **Configuration & Customization**
   - [Adding-Providers.md](Adding-Providers.md) - Create custom fraud detection engines
   - [Database-Schema.md](Database-Schema.md) - Understand data storage

### For Developers & Integrators

Implement FPS features in your applications:

1. **Integration**
   - [API-Documentation.md](API-Documentation.md) - 9 REST endpoints with examples (Python, JS, cURL)
   - [Hook-Reference.md](Hook-Reference.md) - 14 WHMCS hooks for custom logic
   - [AJAX-Reference.md](AJAX-Reference.md) - 70+ admin dashboard actions

2. **Architecture & Customization**
   - [Architecture-Overview.md](Architecture-Overview.md) - System design, 16 detection engines, data flow
   - [Adding-Providers.md](Adding-Providers.md) - Build custom providers
   - [Database-Schema.md](Database-Schema.md) - 28 tables with full column reference

3. **Troubleshooting**
   - [Troubleshooting.md](Troubleshooting.md) - Debug integration issues

---

## Documentation by Topic

### Fraud Detection & Scoring

- [Architecture-Overview.md](Architecture-Overview.md) - Risk aggregation engine, 16 providers
- [Database-Schema.md](Database-Schema.md) - `mod_fps_checks` table (all fraud assessments)

### Bot Detection & Cleanup

- [Bot-Cleanup-Guide.md](Bot-Cleanup-Guide.md) - Detect bots using real financial data
- [Database-Schema.md](Database-Schema.md) - No dedicated bot table (detected via mod_fps_checks)

### Global Fraud Intelligence

- [Global-Intel-Setup.md](Global-Intel-Setup.md) - Hub registration, push/pull, GDPR compliance
- [Database-Schema.md](Database-Schema.md) - `mod_fps_global_intel` table, `mod_fps_global_config`

### Public REST API

- [API-Documentation.md](API-Documentation.md) - 9 endpoints, authentication, rate limiting
- [Hook-Reference.md](Hook-Reference.md) - ClientAreaPage hook (API routing)

### Custom Fraud Rules

- [Adding-Providers.md](Adding-Providers.md) - Custom providers (alternative to rules)
- [Architecture-Overview.md](Architecture-Overview.md) - FpsRuleEngine component
- [Database-Schema.md](Database-Schema.md) - `mod_fps_rules` table schema

### Server Module (API Provisioning)

- [Server-Module-Guide.md](Server-Module-Guide.md) - fps_api server module, WHMCS products, auto-provisioning
- [API-Documentation.md](API-Documentation.md) - Configurable rate limits, usage tracking
- [Database-Schema.md](Database-Schema.md) - `mod_fps_api_keys` client_id/service_id columns

### Admin Dashboard & AJAX

- [AJAX-Reference.md](AJAX-Reference.md) - 70+ actions (dashboard, cleanup, settings, etc.)
- [Architecture-Overview.md](Architecture-Overview.md) - Tab renderers and UI architecture

### WHMCS Integration

- [Hook-Reference.md](Hook-Reference.md) - 14 hooks: pre-checkout, post-order, registration, cron, chargebacks
- [Database-Schema.md](Database-Schema.md) - Tables for WHMCS data linkage

### Webhooks & Notifications

- [Database-Schema.md](Database-Schema.md) - `mod_fps_webhook_configs`, `mod_fps_webhook_log`
- [Hook-Reference.md](Hook-Reference.md) - When webhooks are triggered

---

## File Descriptions

| File | Lines | Primary Audience | Key Sections |
|------|-------|------------------|--------------|
| [Bot-Cleanup-Guide.md](Bot-Cleanup-Guide.md) | 220 | Admins | Bot detection, 4 actions, data preservation |
| [Global-Intel-Setup.md](Global-Intel-Setup.md) | 308 | Admins/DevOps | Hub deployment, API, GDPR compliance |
| [API-Documentation.md](API-Documentation.md) | 600+ | Integrators | 9 endpoints, configurable rate limits, usage tracking |
| [Troubleshooting.md](Troubleshooting.md) | 450+ | Admins/Ops | 25+ common issues and fixes |
| [Architecture-Overview.md](Architecture-Overview.md) | 480+ | Developers | 10 components, 16 providers, server module |
| [Adding-Providers.md](Adding-Providers.md) | 408 | Developers | Custom provider development guide |
| [Database-Schema.md](Database-Schema.md) | 570+ | All | 28 tables, columns, indexes, relationships |
| [Hook-Reference.md](Hook-Reference.md) | 720+ | Developers | 14 hooks with code examples |
| [AJAX-Reference.md](AJAX-Reference.md) | 750+ | Developers | 70+ admin actions, parameters, responses |
| [Server-Module-Guide.md](Server-Module-Guide.md) | 250+ | Admins/Resellers | fps_api server module, products, provisioning |

---

## Common Tasks

### "I want to..."

**Set up FPS for the first time**
1. Read: README.md (installation)
2. Read: [Troubleshooting.md](Troubleshooting.md) "Module Won't Activate"
3. Configure API keys in Setup Wizard

**Enable cross-instance fraud sharing**
1. Read: [Global-Intel-Setup.md](Global-Intel-Setup.md)
2. Deploy hub (Docker)
3. Register instance and configure sharing

**Build a custom fraud detection feature**
1. Read: [Architecture-Overview.md](Architecture-Overview.md) (system design)
2. Read: [Adding-Providers.md](Adding-Providers.md) (provider development)
3. Implement custom provider
4. Read: [Hook-Reference.md](Hook-Reference.md) (integrate with hooks)

**Sell FPS API access as a WHMCS product**
1. Read: [Server-Module-Guide.md](Server-Module-Guide.md)
2. Upload fps_api server module
3. Create WHMCS products (Free, Basic, Premium)
4. Clients auto-receive API keys on purchase

**Integrate FPS API into my app**
1. Read: [API-Documentation.md](API-Documentation.md)
2. Create API key in FPS Admin
3. Use examples (Python, JS, cURL) to implement
4. Implement rate limiting and error handling

**Clean up bot accounts**
1. Read: [Bot-Cleanup-Guide.md](Bot-Cleanup-Guide.md)
2. Go to FPS Dashboard > Bot Cleanup tab
3. Click "Detect Bots" and review list
4. Use preview/dry-run before executing

**Debug a problem**
1. Check [Troubleshooting.md](Troubleshooting.md) for your symptom
2. If not found, check Module Log (Admin > Utilities > Logs)
3. Search [Hook-Reference.md](Hook-Reference.md) or [AJAX-Reference.md](AJAX-Reference.md) for relevant code
4. Review [Database-Schema.md](Database-Schema.md) for data issues

**Understand how FPS works**
1. Read: [Architecture-Overview.md](Architecture-Overview.md) (high-level design)
2. Read: [Hook-Reference.md](Hook-Reference.md) (integration points)
3. Read: [Database-Schema.md](Database-Schema.md) (data model)

---

## Key Concepts

### Detection Pipeline

```
Signup/Order -> [Turnstile] -> [16 Providers] -> [Rules] -> [Velocity]
             -> [Geo-Impossibility] -> [Behavioral] -> [Risk Aggregation]
             -> [Action: Approve/Hold/Block/Cancel]
```

See: [Architecture-Overview.md](Architecture-Overview.md)

### Bot Detection

Identifies accounts with:
- Zero paid invoices AND
- Zero active hosting services

See: [Bot-Cleanup-Guide.md](Bot-Cleanup-Guide.md)

### Global Intel

Cross-instance fraud intelligence sharing via central hub:
- Push: Local fraud signals -> Hub
- Pull: Hub queries during signup
- Share: SHA-256 email hashes, IPs (optional), country codes

See: [Global-Intel-Setup.md](Global-Intel-Setup.md)

### Risk Scoring

Combined 0-100 score from 16 providers using configurable weights:
- Low (0-29): Approve
- Medium (30-59): Approve + flag
- High (60-79): Hold for review
- Critical (80-100): Auto-lock

See: [Architecture-Overview.md](Architecture-Overview.md)

### API Tiers

Four-tier public API access (rate limits now configurable in Settings):
- **Anonymous** (5 req/min default): Stats, hotspots only
- **Free** (30 req/min default): + Real-time events -- $0/mo WHMCS product
- **Basic** (120 req/min default): + IP full, email basic lookups -- $19/mo WHMCS product
- **Premium** (600 req/min default): + Email full, bulk, country reports -- $99/mo WHMCS product

Rate limit resolution: per-key override > tier DB setting > hardcoded fallback.

See: [API-Documentation.md](API-Documentation.md), [Server-Module-Guide.md](Server-Module-Guide.md)

---

## Database Quick Reference

### Critical Tables

- **mod_fps_checks** - All fraud assessments (order, client, risk score, action)
- **mod_fps_global_intel** - Cross-instance fraud signals (harvested before bot purge)
- **mod_fps_rules** - Custom fraud rules (IP blocks, email patterns, velocity limits)
- **mod_fps_settings** - Module configuration (API keys, thresholds, toggles)

See: [Database-Schema.md](Database-Schema.md) for all 28 tables

---

## Hook Execution Summary

| When | Hook | Purpose |
|------|------|---------|
| Pre-checkout | ShoppingCartValidateCheckout | Block high-risk before payment |
| Post-order | AfterShoppingCartCheckout | Full check with all 16 providers |
| Registration | ClientAdd | Flag high-risk signups |
| Daily | DailyCronJob | Maintenance, cache cleanup, stats |
| Chargeback | InvoiceUnpaid | Track with original fraud score |
| Refund | InvoiceRefunded | Detect refund abuse patterns |

See: [Hook-Reference.md](Hook-Reference.md) for all 14 hooks

---

## AJAX Quick Reference

### Dashboard Actions

- `get_dashboard_stats` - KPI cards and sparklines
- `get_recent_checks` - Last 10 checks
- `get_chart_data` - Time-series for ApexCharts

### Bot Cleanup

- `detect_bots` - Find suspected bot accounts
- `preview_bot_action` - Dry-run any action
- `purge_bots` - Delete with intelligence harvest
- `deep_purge_bots` - Aggressive purge (fraud/cancelled only)

### Global Intel

- `global_intel_register` - Register with hub
- `global_intel_push_now` - Manual push to hub
- `global_intel_purge` - GDPR erasure

See: [AJAX-Reference.md](AJAX-Reference.md) for all 70+ actions

---

## Search Tips

Use these search terms to find sections:

- **"risk score"** - How fraud scoring works
- **"provider"** - Detection engines (IP, email, fingerprint, etc.)
- **"hook"** - WHMCS integration points
- **"AJAX"** - Admin dashboard actions
- **"table"** - Database schema
- **"API"** - REST endpoints
- **"bot"** - Bot detection and cleanup
- **"global intel"** - Cross-instance sharing
- **"GDPR"** - Privacy and compliance

---

## Contributing & Updates

These docs are maintained alongside the FPS v4.2.4 codebase. To update:

1. Read the source files in `/lib/` and `fraud_prevention_suite.php`
2. Update relevant .md file with changes
3. Ensure code examples remain current
4. Test examples before committing

---

## Support & Resources

- **WHMCS Docs**: https://docs.whmcs.com
- **FPS Module Status**: Check Dashboard for setup wizard and provider health
- **Error Log**: Admin > Utilities > Logs > Module Log
- **Activity Log**: Admin > Utilities > Logs > Activity Log
- **Alert Log**: FPS Dashboard > Alert Log tab

---

**Last Updated**: 2026-04-22
**Module Version**: v4.2.4
**Docs Version**: 1.2

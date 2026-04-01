# Contributing to Fraud Prevention Suite

Thank you for your interest in contributing to the Fraud Prevention Suite. This document outlines the development standards, workflow, and guidelines for contributing code.

## Table of Contents

- [Development Environment](#development-environment)
- [Code Standards](#code-standards)
- [Module Architecture](#module-architecture)
- [Function Naming Convention](#function-naming-convention)
- [Database Conventions](#database-conventions)
- [Hook Safety Rules](#hook-safety-rules)
- [Testing Checklist](#testing-checklist)
- [Deployment Workflow](#deployment-workflow)
- [Pull Request Process](#pull-request-process)
- [Provider Development Guide](#provider-development-guide)
- [Admin Tab Development Guide](#admin-tab-development-guide)

---

## Development Environment

### Requirements
- PHP 8.2+ with strict_types
- MySQL 5.7+ or MariaDB 10.3+
- WHMCS 8.x development license
- cURL, JSON, and DNS PHP extensions
- Git for version control

### Local Setup
```bash
git clone <repo-url>
cd fraud_prevention_suite

# Verify PHP syntax on all files
find . -name "*.php" -exec php -l {} \;

# Deploy to WHMCS test instance
# (See Deployment Workflow below)
```

---

## Code Standards

### PHP
- `declare(strict_types=1)` at the top of every class file
- PSR-4 namespacing: `FraudPreventionSuite\Lib\{ClassName}`
- All files must start with `if (!defined("WHMCS")) die("...")` guard
- Use `Throwable` (not `Exception`) in all catch blocks
- Use Capsule ORM for all database operations -- never raw `mysql_*`
- Type declarations on all function parameters and return types
- PHPDoc blocks on all public methods

### JavaScript
- Vanilla ES6+ (no jQuery dependency)
- All functions namespaced under `FpsAdmin`, `FpsBot`, or `FpsGlobal`
- AJAX calls use the `ajax()` helper with `(err, data)` callback signature
- Toast notifications use types: `success`, `error`, `warning`, `info` (never `danger`)

### CSS
- Follow the 1000X Design System (`assets/css/fps-1000x.css`)
- Use CSS custom properties for colors (`--fps-*` variables)
- Support both dark and light modes
- Use `.fps-` prefix for all class names

### Smarty Templates
- Use `{literal}` tags around CSS/JS blocks containing curly braces
- Always escape output: `{$var|escape}` or `{$var|escape:'htmlall'}`
- Template variables must be passed from `clientarea()` -- never query DB in templates

---

## Module Architecture

```
fraud_prevention_suite.php    # Main module: config, activate, output, AJAX switch (62+ cases)
hooks.php                     # WHMCS hooks (ONLY add_hook() calls -- NO standalone functions)
lib/
  Autoloader.php              # PSR-4 autoloader (auto-registered on require)
  Fps*.php                    # Core engine classes (CheckRunner, RiskEngine, BotDetector, etc.)
  FpsGlobalIntel*.php         # Global intelligence hub classes
  Models/                     # Immutable data objects (FpsCheckContext, FpsRiskResult, etc.)
  Providers/                  # Fraud check providers (implement FpsProviderInterface)
  Admin/                      # Admin tab renderers (Tab*.php with render() method)
  Api/                        # REST API classes (Router, Auth, Controller, RateLimiter)
assets/
  css/                        # 1000X Design System styles
  js/                         # Admin UI controllers (FpsAdmin, FpsBot, FpsGlobal)
templates/                    # Smarty .tpl files for client area
public/
  api.php                     # REST API entry point
data/                         # Static data files (disposable domains, email providers)
docs/                         # Documentation and wiki
```

---

## Function Naming Convention

**ALL functions in the main module file MUST be prefixed with `fps_` or `fraud_prevention_suite_`.**

```php
// CORRECT:
function fps_ajaxDetectBots(): array { ... }
function fps_validateAbuseIpdbKey(string $apiKey): array { ... }
function fps_getPublicStats(): array { ... }
function fraud_prevention_suite_config(): array { ... }

// WRONG (will collide with other modules):
function detectBots(): array { ... }
function validateApiKey(string $key): array { ... }
```

---

## Database Conventions

### Table Naming
- All module tables: `mod_fps_{table_name}`
- Use lowercase with underscores
- Never modify core WHMCS `tbl*` tables

### Migration Rules (CRITICAL)
```php
// ALWAYS guard table creation with hasTable():
if (!Capsule::schema()->hasTable('mod_fps_new_table')) {
    Capsule::schema()->create('mod_fps_new_table', function ($table) {
        $table->increments('id');
        // ... columns
    });
}

// ALWAYS guard column additions with hasColumn():
if (!Capsule::schema()->hasColumn('mod_fps_existing', 'new_col')) {
    Capsule::schema()->table('mod_fps_existing', function ($table) {
        $table->string('new_col', 50)->nullable()->after('existing_col');
    });
}

// NEVER drop tables in deactivate()
// NEVER use raw SQL for INSERT/UPDATE/DELETE on core tables
```

### Dedup Pattern (for upsert operations)
```php
// Use raw statement with bound parameters for ON DUPLICATE KEY UPDATE:
Capsule::connection()->statement("
    INSERT INTO mod_fps_table (col1, col2, col3) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        col3 = VALUES(col3),
        counter = counter + 1
", [$val1, $val2, $val3]);

// NEVER use addslashes() for SQL -- always use bound parameters (?)
```

---

## Hook Safety Rules

### hooks.php Structure
hooks.php must contain ONLY:
1. `<?php` opening tag
2. `if (!defined("WHMCS")) die()` guard
3. `use` statements
4. `add_hook()` calls with closure callbacks

**NEVER put standalone function definitions in hooks.php.** WHMCS loads both the main module file and hooks.php -- duplicate functions cause "Cannot redeclare" fatal errors that crash the entire admin panel.

```php
// CORRECT: Call static methods from lib/ classes
add_hook('DailyCronJob', 1, function($vars) {
    try {
        \FraudPreventionSuite\Lib\FpsHookHelpers::fps_refreshDisposableDomains();
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'DailyCron', '', $e->getMessage());
    }
});

// WRONG: Standalone function in hooks.php
function fps_refreshDisposableDomains() { ... } // WILL cause "Cannot redeclare"
```

### Error Handling in Hooks
- **Every hook callback** must be wrapped in `try { } catch (\Throwable $e) { }`
- Use `\Throwable`, not `\Exception` (catches TypeError, ValueError, Error in PHP 8.x)
- AdminAreaPage hooks that crash will kill the ENTIRE admin panel
- Return `[]` or `''` on error -- never let exceptions propagate

### Execution Order in Checkout Hook
Score modifications (velocity, Tor, trust checks) must run BEFORE the database insert:
```
1. Trust check (early return if trusted/blacklisted)
2. Provider scoring (IP, email, fingerprint, etc.)
3. Velocity check (adds to score)
4. Tor/datacenter check (adds to score)
5. Global intel check (adds to score)
6. Persist final score to mod_fps_checks  <-- AFTER all modifications
7. Stats update
8. Blocking decision
```

---

## Testing Checklist

Run before every commit:

```bash
# 1. PHP syntax check ALL files
find . -name "*.php" -exec php -l {} \;

# 2. No standalone functions in hooks.php
grep -n "^function " hooks.php
# Must return ZERO results

# 3. No WHMCS core function name conflicts
grep -n "^function logTransaction\|^function logActivity\|^function sendMessage" *.php

# 4. All catch blocks use Throwable
grep -rn "catch (\\\\Exception" lib/ hooks.php
# Should return ZERO (use \Throwable instead)

# 5. No hardcoded IPs or domains in code
grep -rn "freeit\.us\|130\.12\.69\|47\.207\.89\|192\.168\.1\.210" lib/ hooks.php fraud_prevention_suite.php

# 6. No addslashes() for SQL
grep -rn "addslashes" lib/
# Should return ZERO

# 7. All tables have hasTable() guards
grep -n "Capsule::schema()->create(" fraud_prevention_suite.php | while read line; do
    echo "$line" | grep -q "hasTable" || echo "MISSING hasTable guard: $line"
done
```

---

## Deployment Workflow

### To Test Instance (freeit.us)
```bash
# Package
tar czf /tmp/fps-deploy.tar.gz fraud_prevention_suite/

# Deploy via two-hop SSH (CI/CD jump -> WHMCS)
scp fps-deploy.tar.gz cicd-server:/tmp/
ssh cicd-server 'scp /tmp/fps-deploy.tar.gz whmcs-server:/tmp/'
ssh cicd-server 'ssh whmcs-server "
  cd /path/to/whmcs/modules/addons/
  tar xzf /tmp/fps-deploy.tar.gz
  chown -R webuser:webuser fraud_prevention_suite/
  chmod -R 644 fraud_prevention_suite/
  find fraud_prevention_suite/ -type d -exec chmod 755 {} \;
"'
```

### Post-Deploy Verification
1. PHP syntax check on server
2. Module loads without error (check WHMCS admin)
3. All 14 tabs render
4. Bot scan works
5. Global intel stats load
6. No PHP errors in Apache log

---

## Pull Request Process

1. Create a feature branch from `main`
2. Make changes following all conventions above
3. Run the full testing checklist
4. Update CHANGELOG.md with your changes
5. Update version in: `fraud_prevention_suite.php` (config), `version.json`, admin header echo
6. Submit PR with description of changes and test evidence
7. PR must pass GitLab CI pipeline (syntax lint, conflict check, hooks audit)

---

## Provider Development Guide

To add a new fraud check provider:

### 1. Create the provider class

```php
<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Providers;

if (!defined("WHMCS")) die("...");

use WHMCS\Database\Capsule;

class MyNewProvider implements FpsProviderInterface
{
    public function check(array $context): array
    {
        // $context has: email, ip, phone, country, domain, client_id, etc.
        $score = 0;
        $details = [];

        // Your detection logic here

        return [
            'score'   => min(100, max(0, $score)),
            'details' => $details,
            'raw'     => [], // raw API response for debugging
        ];
    }

    public function isEnabled(): bool
    {
        return Capsule::table('mod_fps_settings')
            ->where('setting_key', 'my_provider_enabled')
            ->value('setting_value') === '1';
    }

    public function getWeight(): float
    {
        return 1.0; // Default weight, configurable in settings
    }
}
```

### 2. Wire into FpsCheckRunner

Add to `fps_runAllProviders()` in `lib/FpsCheckRunner.php`:
```php
if ($this->config->isEnabled('my_provider_enabled')) {
    $provider = new \FraudPreventionSuite\Lib\Providers\MyNewProvider();
    $result = $provider->check($context->toArray());
    $results[] = [
        'provider' => 'my_provider',
        'score'    => (float)($result['score'] ?? 0),
        'details'  => (string)($result['details'] ?? ''),
        'success'  => true,
    ];
}
```

### 3. Add weight to FpsRiskEngine

Add to `DEFAULT_WEIGHTS` in `lib/FpsRiskEngine.php`:
```php
'my_provider' => 1.0,
```

### 4. Add default setting

Add to the settings seed in `activate()`:
```php
'my_provider_enabled' => '0',
```

### 5. Add to Setup Wizard (if requires API key)

Add to `fps_ajaxGetSetupStatus()` providers array.

---

## Admin Tab Development Guide

To add a new admin tab:

### 1. Create the tab class

```php
<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) die("...");

class TabMyNewTab
{
    public function render(array $vars, string $modulelink): void
    {
        $ajaxUrl = htmlspecialchars($modulelink . '&ajax=1', ENT_QUOTES, 'UTF-8');
        echo '<div class="fps-card">...</div>';
    }
}
```

### 2. Register in navigation

Add to the `$tabs` array in `fraud_prevention_suite_output()`:
```php
'my_new_tab' => ['icon' => 'fa-icon-name', 'label' => 'My Tab'],
```

The slug `my_new_tab` automatically maps to class `TabMyNewTab` via the `fps_tabClassName()` function.

### 3. Add AJAX handlers (if needed)

Add cases to the switch in `fps_handleAjax()` and handler functions prefixed with `fps_ajax`.

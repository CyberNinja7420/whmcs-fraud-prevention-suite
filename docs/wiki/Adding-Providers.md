# Adding Custom Providers

## Overview

Providers are detection engines that analyze individual fraud signals (IP reputation, email validity, etc.). FPS includes 16 built-in providers; you can add custom ones by implementing the provider interface.

## Provider Interface

All providers implement `FpsProviderInterface`:

```php
namespace FraudPreventionSuite\Lib\Providers;

interface FpsProviderInterface
{
    /**
     * Check a context and return fraud score
     */
    public function check(FpsCheckContext $context): array;

    /**
     * Is this provider enabled?
     */
    public function isEnabled(): bool;

    /**
     * Weight multiplier for this provider (0.5 to 2.0)
     */
    public function getWeight(): float;

    /**
     * Default score if not run (0 to 1)
     */
    public function getScore(): float;
}
```

## Step-by-Step Guide

### Step 1: Create Provider Class

Create file: `lib/Providers/MyCustomProvider.php`

```php
<?php
namespace FraudPreventionSuite\Lib\Providers;

use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use WHMCS\Database\Capsule;

class MyCustomProvider implements FpsProviderInterface
{
    /**
     * Check context and return fraud signal
     */
    public function check(FpsCheckContext $context): array
    {
        // Your detection logic here
        $score = 0.0;
        $details = [];

        // Example: Check if email is from blacklisted domain
        if ($context->email && strpos($context->email, '@malicious.com') !== false) {
            $score = 0.95; // High fraud score
            $details[] = 'Email from known fraud domain';
        }

        // All providers must return this array
        return [
            'score' => $score,      // 0.0 to 1.0
            'details' => $details,  // Human-readable findings
        ];
    }

    /**
     * Is provider enabled in settings?
     */
    public function isEnabled(): bool
    {
        $enabled = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'custom_provider_enabled')
            ->value('setting_value');

        return $enabled === '1';
    }

    /**
     * Weight multiplier for aggregation
     */
    public function getWeight(): float
    {
        $weight = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'custom_provider_weight')
            ->value('setting_value');

        return (float)($weight ?: 1.0);
    }

    /**
     * Default score if check isn't run
     */
    public function getScore(): float
    {
        return 0.0;
    }
}
```

### Step 2: Add Settings Defaults

In `fraud_prevention_suite.php`, in the `activate()` function, add to the defaults array:

```php
$defaults = [
    // ... existing defaults ...
    'custom_provider_enabled' => '0',           // Disabled by default
    'custom_provider_weight' => '1.0',          // Weight = 1.0 (neutral)
    'custom_provider_api_key' => '',            // API key if needed
];
```

### Step 3: Wire into FpsCheckRunner

Edit `lib/FpsCheckRunner.php`, in the `runFullCheck()` method:

```php
public function runFullCheck(FpsCheckContext $context): FpsCheckResult
{
    $scores = [];

    // ... existing providers ...

    // Add custom provider
    if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\MyCustomProvider')) {
        $provider = new \FraudPreventionSuite\Lib\Providers\MyCustomProvider();
        if ($provider->isEnabled()) {
            $result = $provider->check($context);
            $scores['custom_provider'] = [
                'score' => ($result['score'] ?? 0) * $provider->getWeight(),
                'details' => $result['details'] ?? [],
            ];
        }
    }

    // ... rest of method ...
}
```

### Step 4: Add Weight to Risk Engine

Edit `lib/FpsRiskEngine.php`, in the `calculateScore()` method:

```php
public function calculateScore(array $providerScores): float
{
    $totalScore = 0;
    $totalWeight = 0;

    $weights = [
        'ip_intel' => $this->getWeight('ip_intel_weight'),
        'email_validation' => $this->getWeight('email_validation_weight'),
        'custom_provider' => $this->getWeight('custom_provider_weight'), // Add this
        // ... other providers ...
    ];

    foreach ($weights as $key => $weight) {
        if (isset($providerScores[$key])) {
            $totalScore += $providerScores[$key] * $weight;
            $totalWeight += $weight;
        }
    }

    return $totalWeight > 0 ? min(100, ($totalScore / $totalWeight) * 100) : 0;
}
```

### Step 5: Add UI Settings

Edit `lib/Admin/TabSettings.php`, in the provider settings section:

```php
// Custom Provider
?>
<div class="fps-setting-row">
    <div class="fps-setting-label">
        <strong>My Custom Provider</strong>
        <small>Enable and configure your custom fraud detection</small>
    </div>
    <div class="fps-setting-control">
        <label>
            <input type="checkbox" name="custom_provider_enabled"
                   value="1" <?php echo $settings['custom_provider_enabled'] === '1' ? 'checked' : '' ?>>
            Enable Custom Provider
        </label>
        <div style="margin-top: 8px;">
            <label>API Key (if needed):</label>
            <input type="text" name="custom_provider_api_key"
                   value="<?php echo htmlspecialchars($settings['custom_provider_api_key'] ?? '') ?>"
                   placeholder="Enter API key"
                   class="form-control fps-input">
        </div>
        <div style="margin-top: 8px;">
            <label>Weight:</label>
            <input type="range" name="custom_provider_weight"
                   min="0.5" max="2.0" step="0.1"
                   value="<?php echo htmlspecialchars($settings['custom_provider_weight'] ?? '1.0') ?>"
                   class="form-range" style="width: 200px;">
            <span id="custom-weight-value"><?php echo $settings['custom_provider_weight'] ?? '1.0' ?></span>
        </div>
    </div>
</div>

<script>
document.querySelector('input[name="custom_provider_weight"]').addEventListener('input', function(e) {
    document.getElementById('custom-weight-value').textContent = e.target.value;
});
</script>
<?php
```

## Example: Custom Domain Blocklist Provider

Here's a complete example provider that checks against an internal domain blocklist:

```php
<?php
namespace FraudPreventionSuite\Lib\Providers;

use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use WHMCS\Database\Capsule;

/**
 * Custom provider: Check email domain against internal blocklist
 */
class DomainBlocklistProvider implements FpsProviderInterface
{
    private array $blocklist = [
        'tempmail.com',
        'guerrillamail.com',
        'sharklasers.com',
        'mailinator.com',
    ];

    public function check(FpsCheckContext $context): array
    {
        $score = 0.0;
        $details = [];

        if (!$context->email) {
            return ['score' => $score, 'details' => $details];
        }

        // Extract domain
        $domain = strtolower(substr($context->email, strpos($context->email, '@') + 1));

        // Check against blocklist
        foreach ($this->blocklist as $blocked) {
            if ($domain === $blocked || fnmatch('*.' . $blocked, $domain)) {
                $score = 0.80;
                $details[] = "Email domain '{$domain}' is on internal blocklist";
                break;
            }
        }

        return ['score' => $score, 'details' => $details];
    }

    public function isEnabled(): bool
    {
        return Capsule::table('mod_fps_settings')
            ->where('setting_key', 'blocklist_provider_enabled')
            ->value('setting_value') === '1';
    }

    public function getWeight(): float
    {
        return (float)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'blocklist_provider_weight')
            ->value('setting_value') ?: 1.0);
    }

    public function getScore(): float
    {
        return 0.0;
    }
}
```

## Example: External API Provider

Provider that calls an external fraud API:

```php
<?php
namespace FraudPreventionSuite\Lib\Providers;

use FraudPreventionSuite\Lib\Models\FpsCheckContext;
use WHMCS\Database\Capsule;

class ExternalFraudApiProvider implements FpsProviderInterface
{
    private string $apiUrl = 'https://api.example.com/v1/check';
    private string $apiKey = '';

    public function __construct()
    {
        $this->apiKey = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'external_fraud_api_key')
            ->value('setting_value') ?? '';
    }

    public function check(FpsCheckContext $context): array
    {
        $score = 0.0;
        $details = [];

        if (!$this->apiKey) {
            return ['score' => $score, 'details' => ['API key not configured']];
        }

        try {
            // Call external API
            $response = json_decode($this->callApi([
                'email' => $context->email,
                'ip' => $context->ip,
                'phone' => $context->phone,
            ]), true);

            if ($response && isset($response['fraud_score'])) {
                $score = $response['fraud_score'] / 100; // Convert to 0-1 scale
                $details = $response['details'] ?? [];
            }
        } catch (\Throwable $e) {
            $details[] = 'External API error: ' . $e->getMessage();
        }

        return ['score' => $score, 'details' => $details];
    }

    private function callApi(array $params): string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($params),
        ]);

        $result = curl_exec($curl);
        curl_close($curl);
        return (string)$result;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->apiKey && Capsule::table('mod_fps_settings')
            ->where('setting_key', 'external_fraud_api_enabled')
            ->value('setting_value') === '1';
    }

    public function getWeight(): float
    {
        return (float)(Capsule::table('mod_fps_settings')
            ->where('setting_key', 'external_fraud_api_weight')
            ->value('setting_value') ?: 1.5); // Default higher weight for external source
    }

    public function getScore(): float
    {
        return 0.0;
    }
}
```

## Best Practices

1. **Always return 0-1 score**: Scores are normalized and weighted later
2. **Provide details**: Include human-readable findings for admin review
3. **Handle errors gracefully**: Catch exceptions; don't throw
4. **Cache results**: Store external API responses in `mod_fps_ip_intel` or similar
5. **Check isEnabled()**: Always respect admin configuration
6. **Use Capsule**: Access database via WHMCS query builder, not raw SQL
7. **Log failures**: Use `logModuleCall()` for debugging
8. **Document settings**: Explain what each setting does in TabSettings.php

## Testing Your Provider

1. Enable in Settings
2. Set weight (start with 1.0)
3. Run manual check: **Dashboard > Run Manual Check**
4. Review result in review queue
5. Adjust weight based on false positive rate
6. Monitor Module Log for errors

## Distribution

To share your provider:

1. Export class as standalone PHP file
2. Include installation instructions
3. Document required settings and API keys
4. Provide example webhook/notification format
5. Include unit tests if complex

<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// AUTOLOADER
// ---------------------------------------------------------------------------

require_once __DIR__ . '/lib/Autoloader.php';

// ---------------------------------------------------------------------------
// MODULE METADATA
// ---------------------------------------------------------------------------

function fraud_prevention_suite_config(): array
{
    return [
        'name'        => 'Fraud Prevention Suite',
        'description' => 'Enterprise-grade fraud intelligence platform with 15+ detection engines, adaptive ML scoring, Tor/datacenter detection, velocity analysis, behavioral fingerprinting, webhook alerts, and 1000X admin dashboard.',
        'version'     => '4.2.0',
        'author'      => 'EnterpriseVPS',
        'language'    => 'english',
        'fields'      => [
            // FraudRecord
            'fraudrecord_api_key' => [
                'FriendlyName' => 'FraudRecord API Key',
                'Type'         => 'text',
                'Size'         => '60',
                'Default'      => '',
                'Description'  => 'Your FraudRecord.com API key (optional)',
            ],
            // Core Settings
            'auto_check_orders' => [
                'FriendlyName' => 'Auto-Check New Orders',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Automatically run fraud check on every new order',
            ],
            'pre_checkout_blocking' => [
                'FriendlyName' => 'Pre-Checkout Blocking',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Block fraudulent orders before checkout completes',
            ],
            'auto_lock_critical' => [
                'FriendlyName' => 'Auto-Lock Critical Orders',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Automatically set orders to Fraud status on critical risk',
            ],
            // Thresholds
            'risk_medium_threshold' => [
                'FriendlyName' => 'Medium Risk Threshold',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '30',
                'Description'  => 'Score >= this triggers medium risk (0-100)',
            ],
            'risk_high_threshold' => [
                'FriendlyName' => 'High Risk Threshold',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '60',
                'Description'  => 'Score >= this triggers high risk (0-100)',
            ],
            'risk_critical_threshold' => [
                'FriendlyName' => 'Critical Risk Threshold',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '80',
                'Description'  => 'Score >= this triggers critical risk and auto-lock (0-100)',
            ],
            'pre_checkout_block_threshold' => [
                'FriendlyName' => 'Pre-Checkout Block Score',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '85',
                'Description'  => 'Score >= this blocks checkout entirely (0-100)',
            ],
            // Notifications
            'notify_email' => [
                'FriendlyName' => 'Notification Email',
                'Type'         => 'text',
                'Size'         => '60',
                'Default'      => '',
                'Description'  => 'Email for high/critical fraud alerts (blank = off)',
            ],
            'reporter_email' => [
                'FriendlyName' => 'Reporter Email (FraudRecord)',
                'Type'         => 'text',
                'Size'         => '60',
                'Default'      => '',
                'Description'  => 'Your email for FraudRecord submissions',
            ],
            // Provider Keys (Optional)
            'ipinfo_api_key' => [
                'FriendlyName' => 'IPInfo.io API Key',
                'Type'         => 'text',
                'Size'         => '40',
                'Default'      => '',
                'Description'  => 'Optional - enhances IP intelligence (free 50K/mo)',
            ],
            'hibp_api_key' => [
                'FriendlyName' => 'HIBP API Key',
                'Type'         => 'text',
                'Size'         => '40',
                'Default'      => '',
                'Description'  => 'Optional - enables breach checking ($3.50/mo)',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// ACTIVATE - Create/upgrade database tables
// ---------------------------------------------------------------------------

function fraud_prevention_suite_activate(): array
{
    try {
        // -- Existing tables from v1.0 --
        if (!Capsule::schema()->hasTable('mod_fps_checks')) {
            Capsule::schema()->create('mod_fps_checks', function ($table) {
                $table->increments('id');
                $table->integer('order_id')->index();
                $table->integer('client_id')->index();
                $table->string('check_type', 50)->default('auto');
                $table->decimal('risk_score', 5, 2)->default(0);
                $table->string('risk_level', 20)->default('low');
                $table->string('ip_address', 45)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('country', 5)->nullable();
                $table->string('fraudrecord_id', 100)->nullable();
                $table->text('raw_response')->nullable();
                $table->text('details')->nullable();
                $table->string('action_taken', 50)->nullable();
                $table->tinyInteger('locked')->default(0);
                $table->tinyInteger('reported')->default(0);
                $table->integer('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                // v2.0 columns
                $table->integer('fingerprint_id')->nullable()->index();
                $table->integer('ip_intel_id')->nullable()->index();
                $table->integer('email_intel_id')->nullable()->index();
                $table->text('provider_scores')->nullable();
                $table->text('check_context')->nullable();
                $table->tinyInteger('is_pre_checkout')->default(0);
                $table->integer('check_duration_ms')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_reports')) {
            Capsule::schema()->create('mod_fps_reports', function ($table) {
                $table->increments('id');
                $table->integer('check_id')->index();
                $table->integer('client_id')->index();
                $table->integer('order_id')->nullable();
                $table->string('report_type', 50)->default('internal');
                $table->text('report_data');
                $table->timestamp('submitted_at')->useCurrent();
                $table->text('response')->nullable();
                $table->string('status', 30)->default('pending');
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_rules')) {
            Capsule::schema()->create('mod_fps_rules', function ($table) {
                $table->increments('id');
                $table->string('rule_name', 255);
                $table->string('rule_type', 50);
                $table->text('rule_value');
                $table->string('action', 50)->default('flag');
                $table->tinyInteger('enabled')->default(1);
                $table->integer('hits')->default(0);
                $table->timestamp('created_at')->useCurrent();
                // v2.0 columns
                $table->integer('priority')->default(50);
                $table->decimal('score_weight', 5, 2)->default(1.0);
                $table->text('description')->nullable();
                $table->text('conditions')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_stats')) {
            Capsule::schema()->create('mod_fps_stats', function ($table) {
                $table->increments('id');
                $table->date('date')->unique();
                $table->integer('checks_total')->default(0);
                $table->integer('checks_flagged')->default(0);
                $table->integer('checks_blocked')->default(0);
                $table->integer('orders_locked')->default(0);
                $table->integer('reports_submitted')->default(0);
                $table->integer('false_positives')->default(0);
                // v2.0 columns
                $table->integer('pre_checkout_blocks')->default(0);
                $table->integer('api_requests')->default(0);
                $table->integer('unique_ips')->default(0);
                $table->decimal('avg_risk_score', 5, 2)->default(0);
                $table->text('top_countries')->nullable();
                $table->text('top_risk_factors')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_settings')) {
            Capsule::schema()->create('mod_fps_settings', function ($table) {
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value')->nullable();
            });
        }

        // -- New v2.0 tables --

        if (!Capsule::schema()->hasTable('mod_fps_fingerprints')) {
            Capsule::schema()->create('mod_fps_fingerprints', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->index();
                $table->string('fingerprint_hash', 64)->unique();
                $table->text('user_agent')->nullable();
                $table->string('canvas_hash', 64)->nullable();
                $table->string('webgl_hash', 64)->nullable();
                $table->string('screen_resolution', 30)->nullable();
                $table->string('timezone', 100)->nullable();
                $table->integer('timezone_offset')->nullable();
                $table->integer('hardware_concurrency')->nullable();
                $table->integer('device_memory')->nullable();
                $table->text('webrtc_local_ips')->nullable();
                $table->tinyInteger('webrtc_mismatch')->default(0);
                $table->text('raw_data')->nullable();
                $table->timestamp('first_seen_at')->useCurrent();
                $table->timestamp('last_seen_at')->useCurrent();
                $table->integer('times_seen')->default(1);
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_ip_intel')) {
            Capsule::schema()->create('mod_fps_ip_intel', function ($table) {
                $table->increments('id');
                $table->string('ip_address', 45)->unique();
                $table->integer('asn')->nullable();
                $table->string('asn_org', 255)->nullable();
                $table->string('isp', 255)->nullable();
                $table->string('country_code', 5)->nullable()->index();
                $table->string('region', 255)->nullable();
                $table->string('city', 255)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->tinyInteger('is_proxy')->default(0);
                $table->tinyInteger('is_vpn')->default(0);
                $table->tinyInteger('is_tor')->default(0);
                $table->tinyInteger('is_datacenter')->default(0);
                $table->string('proxy_type', 50)->nullable();
                $table->decimal('threat_score', 5, 2)->default(0);
                $table->text('raw_data')->nullable();
                $table->timestamp('cached_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_email_intel')) {
            Capsule::schema()->create('mod_fps_email_intel', function ($table) {
                $table->increments('id');
                $table->string('email', 255)->unique();
                $table->string('email_hash', 64)->index();
                $table->string('domain', 255)->index();
                $table->tinyInteger('mx_valid')->default(0);
                $table->tinyInteger('smtp_valid')->nullable();
                $table->tinyInteger('is_disposable')->default(0);
                $table->tinyInteger('is_role_account')->default(0);
                $table->tinyInteger('is_free_provider')->default(0);
                $table->integer('domain_age_days')->nullable();
                $table->integer('breach_count')->default(0);
                $table->tinyInteger('has_social_presence')->nullable();
                $table->text('social_signals')->nullable();
                $table->decimal('deliverability_score', 5, 2)->default(0);
                $table->text('raw_data')->nullable();
                $table->timestamp('cached_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_api_keys')) {
            Capsule::schema()->create('mod_fps_api_keys', function ($table) {
                $table->increments('id');
                $table->string('key_hash', 64)->unique();
                $table->string('key_prefix', 8);
                $table->string('name', 255);
                $table->string('tier', 20)->default('free');
                $table->string('owner_email', 255)->nullable();
                $table->integer('rate_limit_per_minute')->default(10);
                $table->integer('rate_limit_per_day')->default(1000);
                $table->text('allowed_endpoints')->nullable();
                $table->text('ip_whitelist')->nullable();
                $table->tinyInteger('is_active')->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->bigInteger('total_requests')->default(0);
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_api_logs')) {
            Capsule::schema()->create('mod_fps_api_logs', function ($table) {
                $table->bigIncrements('id');
                $table->integer('api_key_id')->nullable()->index();
                $table->string('endpoint', 100)->index();
                $table->string('method', 10)->default('GET');
                $table->string('ip_address', 45)->nullable();
                $table->text('request_params')->nullable();
                $table->integer('response_code')->default(200);
                $table->integer('response_time_ms')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_geo_events')) {
            Capsule::schema()->create('mod_fps_geo_events', function ($table) {
                $table->bigIncrements('id');
                $table->string('event_type', 30)->index();
                $table->string('country_code', 2)->index();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('risk_level', 20)->default('low');
                $table->decimal('risk_score', 5, 2)->default(0);
                $table->tinyInteger('is_anonymized')->default(1);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_rate_limits')) {
            Capsule::schema()->create('mod_fps_rate_limits', function ($table) {
                $table->increments('id');
                $table->string('identifier', 128)->unique();
                $table->decimal('tokens', 10, 2)->default(0);
                $table->timestamp('last_refill_at')->useCurrent();
                $table->integer('window_requests')->default(0);
                $table->timestamp('window_start_at')->useCurrent();
            });
        }

        // -- v2.0.1 tables: Chargebacks, Refund Abuse, Per-Gateway, Fraud Fingerprints --

        if (!Capsule::schema()->hasTable('mod_fps_chargebacks')) {
            Capsule::schema()->create('mod_fps_chargebacks', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->index();
                $table->integer('order_id')->index();
                $table->integer('invoice_id')->nullable();
                $table->decimal('amount', 10, 2)->default(0);
                $table->string('gateway', 100)->nullable();
                $table->decimal('fraud_score_at_order', 5, 2)->nullable();
                $table->string('risk_level_at_order', 20)->nullable();
                $table->text('provider_scores_at_order')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamp('chargeback_date')->useCurrent();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_refund_tracking')) {
            Capsule::schema()->create('mod_fps_refund_tracking', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->index();
                $table->integer('invoice_id')->nullable();
                $table->decimal('amount', 10, 2)->default(0);
                $table->string('reason', 255)->nullable();
                $table->timestamp('refund_date')->useCurrent();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_gateway_thresholds')) {
            Capsule::schema()->create('mod_fps_gateway_thresholds', function ($table) {
                $table->increments('id');
                $table->string('gateway', 100)->unique();
                $table->integer('block_threshold')->default(85);
                $table->integer('flag_threshold')->default(60);
                $table->tinyInteger('require_captcha')->default(0);
                $table->tinyInteger('enabled')->default(1);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_bin_cache')) {
            Capsule::schema()->create('mod_fps_bin_cache', function ($table) {
                $table->increments('id');
                $table->string('bin', 8)->unique();
                $table->text('bin_data')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_fraud_fingerprints')) {
            Capsule::schema()->create('mod_fps_fraud_fingerprints', function ($table) {
                $table->increments('id');
                $table->string('fingerprint_hash', 64)->unique();
                $table->integer('flagged_by')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // -- New v3.0 tables --

        if (!Capsule::schema()->hasTable('mod_fps_client_trust')) {
            Capsule::schema()->create('mod_fps_client_trust', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unique();
                $table->string('status', 20)->default('normal');
                $table->text('reason')->nullable();
                $table->integer('set_by_admin_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_velocity_events')) {
            Capsule::schema()->create('mod_fps_velocity_events', function ($table) {
                $table->bigIncrements('id');
                $table->string('event_type', 30)->index();
                $table->string('identifier', 255)->index();
                $table->integer('client_id')->default(0)->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('meta')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_tor_nodes')) {
            Capsule::schema()->create('mod_fps_tor_nodes', function ($table) {
                $table->increments('id');
                $table->string('ip_address', 45)->unique();
                $table->timestamp('last_seen_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_datacenter_asns')) {
            Capsule::schema()->create('mod_fps_datacenter_asns', function ($table) {
                $table->increments('id');
                $table->integer('asn')->unique();
                $table->string('description', 255)->nullable();
                $table->string('category', 50)->nullable();
                $table->timestamp('last_updated_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_webhook_configs')) {
            Capsule::schema()->create('mod_fps_webhook_configs', function ($table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->string('type', 20)->default('generic');
                $table->text('url');
                $table->string('secret', 255)->nullable();
                $table->text('events')->nullable();
                $table->tinyInteger('enabled')->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_webhook_log')) {
            Capsule::schema()->create('mod_fps_webhook_log', function ($table) {
                $table->bigIncrements('id');
                $table->integer('webhook_id')->index();
                $table->string('event_type', 50);
                $table->tinyInteger('success')->default(0);
                $table->integer('http_code')->default(0);
                $table->text('response_body')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_behavioral_events')) {
            Capsule::schema()->create('mod_fps_behavioral_events', function ($table) {
                $table->bigIncrements('id');
                $table->integer('client_id')->index();
                $table->string('session_id', 64)->index();
                $table->integer('form_fill_time_ms')->nullable();
                $table->decimal('mouse_entropy', 5, 3)->nullable();
                $table->tinyInteger('paste_detected')->default(0);
                $table->decimal('behavioral_score', 5, 2)->default(0);
                $table->text('raw_data')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_weight_history')) {
            Capsule::schema()->create('mod_fps_weight_history', function ($table) {
                $table->increments('id');
                $table->string('provider', 50);
                $table->decimal('old_weight', 5, 2);
                $table->decimal('new_weight', 5, 2);
                $table->decimal('precision_score', 5, 4)->nullable();
                $table->integer('true_positive_count')->default(0);
                $table->integer('false_positive_count')->default(0);
                $table->integer('sample_size')->default(0);
                $table->string('period', 7);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // -- v4.0: Global Fraud Intelligence tables --

        if (!Capsule::schema()->hasTable('mod_fps_global_intel')) {
            Capsule::schema()->create('mod_fps_global_intel', function ($table) {
                $table->increments('id');
                $table->string('email_hash', 64)->index();
                $table->string('ip_address', 45)->nullable()->index();
                $table->char('country', 2)->nullable();
                $table->decimal('risk_score', 5, 2)->default(0);
                $table->string('risk_level', 20)->default('low')->index();
                $table->string('source', 20)->default('local'); // local, hub, manual
                $table->text('evidence_flags')->nullable(); // JSON booleans
                $table->unsignedInteger('seen_count')->default(1);
                $table->timestamp('first_seen_at')->useCurrent();
                $table->timestamp('last_seen_at')->useCurrent();
                $table->timestamp('expires_at')->nullable()->index();
                $table->tinyInteger('pushed_to_hub')->default(0)->index();
                $table->timestamp('pushed_at')->nullable();
                $table->unique(['email_hash', 'ip_address'], 'uq_email_ip');
            });
        }

        if (!Capsule::schema()->hasTable('mod_fps_global_config')) {
            Capsule::schema()->create('mod_fps_global_config', function ($table) {
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value')->nullable();
            });

            // Seed default global config
            $globalDefaults = [
                'global_sharing_enabled' => '0',
                'hub_url' => 'http://130.12.69.6:8400',
                'instance_id' => bin2hex(random_bytes(16)),
                'instance_api_key' => '',
                'instance_domain' => '',
                'share_ip_addresses' => '1',
                'auto_push_enabled' => '1',
                'auto_pull_on_signup' => '1',
                'intel_retention_days' => '365',
                'last_push_at' => '',
                'last_pull_at' => '',
                'data_consent_accepted' => '0',
            ];
            foreach ($globalDefaults as $k => $v) {
                try {
                    Capsule::table('mod_fps_global_config')->insert([
                        'setting_key' => $k,
                        'setting_value' => $v,
                    ]);
                } catch (\Throwable $e) {
                    // Already exists
                }
            }
        }

        // -- v4.1: GDPR Data Removal Requests --

        if (!Capsule::schema()->hasTable('mod_fps_gdpr_requests')) {
            Capsule::schema()->create('mod_fps_gdpr_requests', function ($table) {
                $table->increments('id');
                $table->string('email', 255);
                $table->string('email_hash', 64)->index();
                $table->string('name', 255)->nullable();
                $table->text('reason')->nullable();
                $table->string('verification_token', 64)->nullable();
                $table->tinyInteger('email_verified')->default(0);
                $table->string('status', 20)->default('pending'); // pending, verified, approved, denied, completed
                $table->integer('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('admin_notes')->nullable();
                $table->integer('records_purged')->default(0);
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Add is_catchall column to mod_fps_email_intel if missing (v3.0)
        if (Capsule::schema()->hasTable('mod_fps_email_intel')
            && !Capsule::schema()->hasColumn('mod_fps_email_intel', 'is_catchall')) {
            Capsule::schema()->table('mod_fps_email_intel', function ($table) {
                $table->tinyInteger('is_catchall')->default(0)->after('smtp_valid');
            });
        }

        // Seed default settings (wrapped in try/catch for v1.0 compat)
        $defaults = [
            'module_version' => '3.0.0',
            'fingerprint_enabled' => '1',
            'ip_intel_enabled' => '1',
            'email_validation_enabled' => '1',
            'phone_validation_enabled' => '1',
            'bin_lookup_enabled' => '1',
            'breach_check_enabled' => '1',
            'social_presence_enabled' => '0',
            'public_api_enabled' => '1',
            'topology_enabled' => '1',
            'captcha_enabled' => '0',
            'captcha_provider' => 'hcaptcha',
            'captcha_site_key' => '',
            'captcha_secret_key' => '',
            'cache_ttl_ip' => '86400',
            'cache_ttl_email' => '604800',
            'geo_events_retention_days' => '90',
            'api_logs_retention_days' => '30',
            'ofac_screening_enabled' => '1',
            'refund_abuse_threshold' => '3',
            'refund_abuse_window_days' => '90',
            'chargeback_tracking_enabled' => '1',
            'disposable_list_auto_update' => '1',
            // v3.0 settings
            'smtp_verification_enabled' => '1',
            'tor_detection_enabled' => '1',
            'velocity_enabled' => '1',
            'behavioral_scoring_enabled' => '1',
            'adaptive_scoring_enabled' => '1',
            'auto_suspend_enabled' => '0',
            'auto_suspend_chargeback_threshold' => '3',
            'auto_suspend_chargeback_window_days' => '180',
            'velocity_orders_per_ip_hour' => '5',
            'velocity_regs_per_ip_day' => '3',
            'velocity_fails_per_client_day' => '5',
            'velocity_checkouts_per_ip_hour' => '10',
            'velocity_bin_reuse_day' => '3',
            // v4.1: User purge controls on WHMCS Users page
            'user_purge_on_users_page' => '1',
            'ui_font_scale' => '1.0',
        ];

        try {
            foreach ($defaults as $key => $val) {
                $exists = Capsule::table('mod_fps_settings')
                    ->where('setting_key', $key)
                    ->exists();
                if (!$exists) {
                    Capsule::table('mod_fps_settings')->insert([
                        'setting_key' => $key,
                        'setting_value' => $val,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Settings seeding is non-fatal - table may have different schema from v1.0
            logModuleCall('fraud_prevention_suite', 'SeedSettings', '', $e->getMessage());
        }

        return ['status' => 'success', 'description' => 'Fraud Prevention Suite v4.2.0 activated successfully. All tables ready.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// DEACTIVATE
// ---------------------------------------------------------------------------

function fraud_prevention_suite_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Fraud Prevention Suite deactivated. All data tables preserved.'];
}

// ---------------------------------------------------------------------------
// UPGRADE
// ---------------------------------------------------------------------------

function fraud_prevention_suite_upgrade($vars): void
{
    $currentVersion = '1.0.0';
    if (is_array($vars) && isset($vars['version'])) {
        $currentVersion = $vars['version'];
    } elseif (is_string($vars)) {
        $currentVersion = $vars;
    }

    try {
        if (version_compare($currentVersion, '2.0.0', '<')) {
            fps_migrate_to_2_0_0();
        }
        // v4.1: Ensure GDPR table exists (may have been added between activations)
        if (!Capsule::schema()->hasTable('mod_fps_gdpr_requests')) {
            Capsule::schema()->create('mod_fps_gdpr_requests', function ($table) {
                $table->increments('id');
                $table->string('email', 255);
                $table->string('email_hash', 64)->index();
                $table->string('name', 255)->nullable();
                $table->text('reason')->nullable();
                $table->string('verification_token', 64)->nullable();
                $table->tinyInteger('email_verified')->default(0);
                $table->string('status', 20)->default('pending');
                $table->integer('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('admin_notes')->nullable();
                $table->integer('records_purged')->default(0);
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }
        // v4.2: Add client_id/service_id to API keys for product provisioning
        if (Capsule::schema()->hasTable('mod_fps_api_keys')) {
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'client_id')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->integer('client_id')->nullable()->index()->after('owner_email');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_fps_api_keys', 'service_id')) {
                Capsule::schema()->table('mod_fps_api_keys', function ($table) {
                    $table->integer('service_id')->nullable()->unique()->after('client_id');
                });
            }
        }

        // v4.1: Ensure hub_url has a default value
        try {
            $hubUrl = Capsule::table('mod_fps_global_config')->where('setting_key', 'hub_url')->value('setting_value');
            if (empty($hubUrl)) {
                Capsule::table('mod_fps_global_config')->updateOrInsert(
                    ['setting_key' => 'hub_url'],
                    ['setting_value' => 'http://130.12.69.6:8400']
                );
            }
        } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        logModuleCall('fraud_prevention_suite', 'Upgrade', json_encode($vars), $e->getMessage());
    }
}

/**
 * v1.0 -> v2.0 migration: add new columns to existing tables, create new tables.
 */
function fps_migrate_to_2_0_0(): void
{
    // Add v2.0 columns to mod_fps_checks
    $newCheckCols = [
        'fingerprint_id' => ['integer', ['nullable' => true]],
        'ip_intel_id' => ['integer', ['nullable' => true]],
        'email_intel_id' => ['integer', ['nullable' => true]],
        'provider_scores' => ['text', ['nullable' => true]],
        'check_context' => ['text', ['nullable' => true]],
        'is_pre_checkout' => ['tinyInteger', ['default' => 0]],
        'check_duration_ms' => ['integer', ['nullable' => true]],
        'updated_at' => ['timestamp', ['nullable' => true]],
    ];

    foreach ($newCheckCols as $col => $def) {
        if (!Capsule::schema()->hasColumn('mod_fps_checks', $col)) {
            Capsule::schema()->table('mod_fps_checks', function ($table) use ($col, $def) {
                $method = $def[0];
                $column = $table->$method($col);
                foreach ($def[1] as $prop => $val) {
                    $column->$prop($val);
                }
            });
        }
    }

    // Add v2.0 columns to mod_fps_rules
    $newRuleCols = ['priority', 'score_weight', 'description', 'conditions', 'expires_at', 'created_by', 'updated_at'];
    foreach ($newRuleCols as $col) {
        if (!Capsule::schema()->hasColumn('mod_fps_rules', $col)) {
            Capsule::schema()->table('mod_fps_rules', function ($table) use ($col) {
                switch ($col) {
                    case 'priority': $table->integer('priority')->default(50); break;
                    case 'score_weight': $table->decimal('score_weight', 5, 2)->default(1.0); break;
                    case 'description': $table->text('description')->nullable(); break;
                    case 'conditions': $table->text('conditions')->nullable(); break;
                    case 'expires_at': $table->timestamp('expires_at')->nullable(); break;
                    case 'created_by': $table->integer('created_by')->nullable(); break;
                    case 'updated_at': $table->timestamp('updated_at')->nullable(); break;
                }
            });
        }
    }

    // Add v2.0 columns to mod_fps_stats
    $newStatCols = ['pre_checkout_blocks', 'api_requests', 'unique_ips', 'avg_risk_score', 'top_countries', 'top_risk_factors'];
    foreach ($newStatCols as $col) {
        if (!Capsule::schema()->hasColumn('mod_fps_stats', $col)) {
            Capsule::schema()->table('mod_fps_stats', function ($table) use ($col) {
                switch ($col) {
                    case 'pre_checkout_blocks': $table->integer('pre_checkout_blocks')->default(0); break;
                    case 'api_requests': $table->integer('api_requests')->default(0); break;
                    case 'unique_ips': $table->integer('unique_ips')->default(0); break;
                    case 'avg_risk_score': $table->decimal('avg_risk_score', 5, 2)->default(0); break;
                    case 'top_countries': $table->text('top_countries')->nullable(); break;
                    case 'top_risk_factors': $table->text('top_risk_factors')->nullable(); break;
                }
            });
        }
    }

    // New v2.0 tables are created via activate() which is idempotent
    // Just call activate internals for new tables
    fraud_prevention_suite_activate();

    logModuleCall('fraud_prevention_suite', 'Migration', 'v1.0 -> v2.0', 'Migration complete');
}

// ---------------------------------------------------------------------------
// HELPER: Get module config setting (legacy compat)
// ---------------------------------------------------------------------------

function fps_getSetting(string $key, string $default = ''): string
{
    try {
        $config = \FraudPreventionSuite\Lib\FpsConfig::getInstance();
        return $config->get($key, $default);
    } catch (\Throwable $e) {
        // Fallback to direct query if autoloader not ready
        try {
            $row = Capsule::table('tbladdonmodules')
                ->where('module', 'fraud_prevention_suite')
                ->where('setting', $key)
                ->first();
            return $row ? (string)$row->value : $default;
        } catch (\Throwable $e2) {
            return $default;
        }
    }
}

// ---------------------------------------------------------------------------
// HELPER: Risk badge HTML
// ---------------------------------------------------------------------------

function fps_riskBadge(string $level, ?float $score = null): string
{
    $colors = [
        'low'      => '#38ef7d',
        'medium'   => '#f5c842',
        'high'     => '#f5576c',
        'critical' => '#eb3349',
    ];
    $bg = $colors[$level] ?? '#aaa';
    $label = strtoupper($level) . ($score !== null ? ' (' . number_format($score, 1) . ')' : '');
    return '<span class="fps-badge fps-badge-' . htmlspecialchars($level) . '">' . htmlspecialchars($label) . '</span>';
}

// ---------------------------------------------------------------------------
// ADMIN OUTPUT - Tab dispatcher
// ---------------------------------------------------------------------------

function fraud_prevention_suite_output(array $vars): void
{
    $modulelink = $vars['modulelink'];
    $tab = isset($_GET['tab']) ? preg_replace('/[^a-z_]/', '', $_GET['tab']) : 'dashboard';

    // Handle AJAX requests
    if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
        fps_handleAjax($modulelink);
        return;
    }

    // Load admin CSS/JS (cache bust with version)
    $assetsUrl = '../modules/addons/fraud_prevention_suite/assets';
    $cacheBust = '?v=' . time();
    echo '<link rel="stylesheet" href="' . $assetsUrl . '/css/fps-1000x.css' . $cacheBust . '">';
    echo '<script src="https://cdn.jsdelivr.net/npm/apexcharts@3"></script>';
    echo '<script src="' . $assetsUrl . '/js/fps-admin.js' . $cacheBust . '"></script>';
    echo '<script src="' . $assetsUrl . '/js/fps-charts.js' . $cacheBust . '"></script>';
    echo '<script>var fpsModuleLink = ' . json_encode($modulelink) . ';';
    echo 'document.addEventListener("DOMContentLoaded",function(){';
    echo '  if(typeof FpsAdmin!=="undefined"&&FpsAdmin.init){FpsAdmin.init({modulelink:fpsModuleLink});}';
    echo '  if(localStorage.getItem("fps-theme")==="dark")document.documentElement.classList.add("fps-theme-dark");';
    echo '});</script>';

    // Module header -- apply saved font scale
    $fontScale = '1.0';
    try {
        $savedScale = Capsule::table('mod_fps_settings')
            ->where('setting_key', 'ui_font_scale')
            ->value('setting_value');
        if ($savedScale !== null && $savedScale !== '') {
            $fontScale = (string) max(0.85, min(1.4, (float) $savedScale));
        }
    } catch (\Throwable $e) {
        // Non-fatal
    }
    $zoomStyle = ($fontScale !== '1.0') ? ' style="zoom:' . htmlspecialchars($fontScale, ENT_QUOTES, 'UTF-8') . ';"' : '';
    echo '<div class="fps-module-wrapper"' . $zoomStyle . '>';
    echo '<div class="fps-header">';
    echo '  <div class="fps-header-content">';
    echo '    <h2><i class="fas fa-shield-halved"></i> Fraud Prevention Suite <span class="fps-version">v4.2.0</span></h2>';
    echo '    <div class="fps-header-actions">';
    echo '      <button class="fps-btn fps-btn-sm fps-btn-outline" onclick="FpsAdmin.toggleTheme()" title="Toggle Dark/Light Mode"><i class="fas fa-moon"></i></button>';
    echo '      <button class="fps-btn fps-btn-sm fps-btn-primary" onclick="FpsAdmin.refreshDashboard()"><i class="fas fa-sync-alt"></i> Refresh</button>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // Tab navigation
    $tabs = [
        'dashboard'        => ['icon' => 'fa-chart-line',       'label' => 'Dashboard'],
        'review_queue'     => ['icon' => 'fa-clipboard-check',  'label' => 'Review Queue'],
        'trust_management' => ['icon' => 'fa-shield-check',     'label' => 'Trust'],
        'client_profile'   => ['icon' => 'fa-user-shield',      'label' => 'Client Profile'],
        'mass_scan'        => ['icon' => 'fa-magnifying-glass', 'label' => 'Mass Scan'],
        'rules'            => ['icon' => 'fa-gavel',            'label' => 'Rules'],
        'reports'          => ['icon' => 'fa-flag',             'label' => 'Reports'],
        'statistics'       => ['icon' => 'fa-chart-pie',        'label' => 'Statistics'],
        'topology'         => ['icon' => 'fa-globe',            'label' => 'Topology'],
        'global_intel'     => ['icon' => 'fa-earth-americas',   'label' => 'Global Intel'],
        'bot_cleanup'      => ['icon' => 'fa-robot',            'label' => 'Bot Cleanup'],
        'alert_log'        => ['icon' => 'fa-bell',              'label' => 'Alert Log'],
        'api_keys'         => ['icon' => 'fa-key',              'label' => 'API Keys'],
        'settings'         => ['icon' => 'fa-gear',             'label' => 'Settings'],
    ];

    echo '<div class="fps-tabs">';
    foreach ($tabs as $tabId => $info) {
        $active = ($tab === $tabId) ? ' active' : '';
        $url = $modulelink . '&tab=' . $tabId;
        echo '<a href="' . htmlspecialchars($url) . '" class="fps-tab-btn' . $active . '">';
        echo '<i class="fas ' . $info['icon'] . '"></i> ' . $info['label'];
        echo '</a>';
    }
    echo '</div>';

    // Tab content
    echo '<div class="fps-tab-content">';
    try {
        $rendererClass = '\\FraudPreventionSuite\\Lib\\Admin\\Tab' . fps_tabClassName($tab);
        if (class_exists($rendererClass)) {
            $renderer = new $rendererClass();
            $renderer->render($vars, $modulelink);
        } else {
            fps_renderFallbackTab($tab, $vars, $modulelink);
        }
    } catch (\Throwable $e) {
        echo '<div class="fps-card"><div class="fps-card-body">';
        echo '<p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Error rendering tab: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div></div>';
        logModuleCall('fraud_prevention_suite', 'TabRender:' . $tab, '', $e->getMessage());
    }
    echo '</div>'; // .fps-tab-content
    echo '</div>'; // .fps-module-wrapper
}

/**
 * Convert tab slug to class name (dashboard -> Dashboard, review_queue -> ReviewQueue)
 */
function fps_tabClassName(string $tab): string
{
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $tab)));
}

/**
 * Fallback tab renderer when class file is missing
 */
function fps_renderFallbackTab(string $tab, array $vars, string $modulelink): void
{
    echo '<div class="fps-card">';
    echo '<div class="fps-card-header"><h3><i class="fas fa-wrench"></i> ' . ucwords(str_replace('_', ' ', $tab)) . '</h3></div>';
    echo '<div class="fps-card-body">';
    echo '<p>This tab is loading. If this persists, the tab renderer class may be missing.</p>';
    echo '</div></div>';
}

// ---------------------------------------------------------------------------
// AJAX HANDLER
// ---------------------------------------------------------------------------

function fps_handleAjax(string $modulelink): void
{
    header('Content-Type: application/json');

    if (!isset($_SESSION['adminid']) || (int)$_SESSION['adminid'] < 1) {
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    // CSRF protection: verify WHMCS token on state-changing POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['token'] ?? '';
        if (isset($_SESSION['token']) && $token !== $_SESSION['token']) {
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }
    }

    // GET 'a' param takes priority (set by JS ajax() URL) -- POST 'action' is fallback
    // This prevents form fields named 'action' from overriding the intended AJAX action
    $action = $_GET['a'] ?? $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'get_dashboard_stats':
                echo json_encode(fps_ajaxDashboardStats());
                break;

            case 'get_recent_checks':
                echo json_encode(fps_ajaxRecentChecks());
                break;

            case 'get_chart_data':
                echo json_encode(fps_ajaxChartData());
                break;

            case 'run_manual_check':
                echo json_encode(fps_ajaxManualCheck());
                break;

            case 'get_review_queue':
                echo json_encode(fps_ajaxReviewQueue());
                break;

            case 'approve_check':
                echo json_encode(fps_ajaxApproveCheck());
                break;

            case 'deny_check':
                echo json_encode(fps_ajaxDenyCheck());
                break;

            case 'get_client_profile':
                echo json_encode(fps_ajaxClientProfile());
                break;

            case 'start_mass_scan':
                echo json_encode(fps_ajaxMassScan());
                break;

            case 'save_rule':
                echo json_encode(fps_ajaxSaveRule());
                break;

            case 'delete_rule':
                echo json_encode(fps_ajaxDeleteRule());
                break;

            case 'toggle_rule':
                echo json_encode(fps_ajaxToggleRule());
                break;

            case 'save_settings':
                echo json_encode(fps_ajaxSaveSettings());
                break;

            case 'create_api_key':
                echo json_encode(fps_ajaxCreateApiKey());
                break;

            case 'revoke_api_key':
                echo json_encode(fps_ajaxRevokeApiKey());
                break;

            case 'get_topology_data':
                echo json_encode(fps_ajaxTopologyData());
                break;

            case 'bulk_terminate':
                echo json_encode(fps_ajaxBulkTerminate());
                break;

            case 'bulk_approve':
                echo json_encode(fps_ajaxBulkApprove());
                break;

            case 'bulk_deny':
                echo json_encode(fps_ajaxBulkDeny());
                break;

            case 'bulk_flag':
                echo json_encode(fps_ajaxBulkFlag());
                break;

            case 'report_fraudrecord':
                echo json_encode(fps_ajaxReportFraudRecord());
                break;

            case 'report_client_fraudrecord':
                echo json_encode(fps_ajaxReportClientFraudRecord());
                break;

            case 'terminate_client':
                echo json_encode(fps_ajaxTerminateClient());
                break;

            case 'purge_caches':
                echo json_encode(fps_ajaxPurgeCaches());
                break;

            case 'reset_statistics':
                echo json_encode(fps_ajaxResetStatistics());
                break;

            case 'get_api_key_detail':
                echo json_encode(fps_ajaxGetApiKeyDetail());
                break;

            case 'get_report_detail':
                echo json_encode(fps_ajaxGetReportDetail());
                break;

            case 'export_csv':
            case 'scan_export_csv':
                fps_ajaxExportCsv();
                break;

            case 'export_client_profile':
                fps_ajaxExportClientProfile();
                break;

            // v3.0: Trust management
            case 'set_client_trust':
                echo json_encode(fps_ajaxSetClientTrust());
                break;

            case 'get_trust_list':
                echo json_encode(fps_ajaxGetTrustList());
                break;

            // v3.0: Webhook management
            case 'save_webhook':
                echo json_encode(fps_ajaxSaveWebhook());
                break;

            case 'delete_webhook':
                echo json_encode(fps_ajaxDeleteWebhook());
                break;

            case 'test_webhook':
                echo json_encode(fps_ajaxTestWebhook());
                break;

            case 'get_webhooks':
                echo json_encode(fps_ajaxGetWebhooks());
                break;

            // v3.1: Check/report management
            case 'delete_check':
                echo json_encode(fps_ajaxDeleteCheck());
                break;

            case 'delete_checks_bulk':
                echo json_encode(fps_ajaxDeleteChecksBulk());
                break;

            case 'clear_all_checks':
                echo json_encode(fps_ajaxClearAllChecks());
                break;

            case 'delete_report':
                echo json_encode(fps_ajaxDeleteReport());
                break;

            case 'clear_all_reports':
                echo json_encode(fps_ajaxClearAllReports());
                break;

            case 'update_report_status':
                echo json_encode(fps_ajaxUpdateReportStatus());
                break;

            case 'rescan_client':
                echo json_encode(fps_ajaxRescanClient());
                break;

            case 'cross_account_scan':
                echo json_encode(fps_ajaxCrossAccountScan());
                break;

            case 'get_client_timeline':
                echo json_encode(fps_ajaxClientTimeline());
                break;

            // v4.0: API Key Validation & Setup Wizard
            case 'validate_api_key':
                echo json_encode(fps_ajaxValidateApiKey());
                break;

            case 'get_setup_status':
                echo json_encode(fps_ajaxGetSetupStatus());
                break;

            // v4.0: Bot Cleanup
            case 'detect_bots':
                echo json_encode(fps_ajaxDetectBots());
                break;

            case 'preview_bot_action':
                echo json_encode(fps_ajaxPreviewBotAction());
                break;

            case 'flag_bots':
                echo json_encode(fps_ajaxFlagBots());
                break;

            case 'deactivate_bots':
                echo json_encode(fps_ajaxDeactivateBots());
                break;

            case 'purge_bots':
                echo json_encode(fps_ajaxPurgeBots());
                break;

            case 'deep_purge_bots':
                echo json_encode(fps_ajaxDeepPurgeBots());
                break;

            // v4.1: User account cleanup (tblusers)
            case 'detect_orphan_users':
                echo json_encode(fps_ajaxDetectOrphanUsers());
                break;

            case 'purge_orphan_users':
                echo json_encode(fps_ajaxPurgeOrphanUsers());
                break;

            // v4.0: Alert Log Management
            case 'get_module_log':
                echo json_encode(fps_ajaxGetModuleLog());
                break;

            case 'clear_fps_logs':
                echo json_encode(fps_ajaxClearFpsLogs());
                break;

            // v4.0: Universal Preview (dry-run for all destructive ops)
            case 'preview_destructive':
                echo json_encode(fps_ajaxPreviewDestructive());
                break;

            // v4.0: Global Intelligence
            case 'global_intel_toggle':
                echo json_encode(fps_ajaxGlobalIntelToggle());
                break;

            case 'global_intel_register':
                echo json_encode(fps_ajaxGlobalIntelRegister());
                break;

            case 'global_intel_push_now':
                echo json_encode(fps_ajaxGlobalIntelPushNow());
                break;

            case 'global_intel_stats':
                echo json_encode(fps_ajaxGlobalIntelStats());
                break;

            case 'global_intel_browse':
                echo json_encode(fps_ajaxGlobalIntelBrowse());
                break;

            case 'global_intel_save_settings':
                echo json_encode(fps_ajaxGlobalIntelSaveSettings());
                break;

            case 'global_intel_purge':
                echo json_encode(fps_ajaxGlobalIntelPurge());
                break;

            // v4.1: GDPR Data Removal Requests (admin)
            case 'gdpr_get_requests':
                echo json_encode(fps_ajaxGdprGetRequests());
                break;

            case 'gdpr_review_request':
                echo json_encode(fps_ajaxGdprReviewRequest());
                break;

            // v4.1: GDPR Data Removal (public - no admin auth required)
            case 'gdpr_submit_request':
                echo json_encode(fps_ajaxGdprSubmitRequest());
                break;

            case 'gdpr_verify_email':
                echo json_encode(fps_ajaxGdprVerifyEmail());
                break;

            case 'global_intel_export':
                fps_ajaxGlobalIntelExport();
                break;

            default:
                echo json_encode(['error' => 'Unknown action: ' . $action]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        logModuleCall('fraud_prevention_suite', 'AJAX:' . $action, $_POST, $e->getMessage());
    }
    exit;
}

// ---------------------------------------------------------------------------
// AJAX IMPLEMENTATIONS
// ---------------------------------------------------------------------------

function fps_ajaxDashboardStats(): array
{
    $today = date('Y-m-d');
    $stats = Capsule::table('mod_fps_stats')->where('date', $today)->first();

    $reviewCount = Capsule::table('mod_fps_checks')
        ->whereIn('risk_level', ['high', 'critical'])
        ->whereNull('reviewed_by')
        ->count();

    $avgScore = Capsule::table('mod_fps_checks')
        ->where('created_at', '>=', $today . ' 00:00:00')
        ->avg('risk_score') ?? 0;

    $activeThreats = Capsule::table('mod_fps_checks')
        ->where('risk_level', 'critical')
        ->where('locked', 1)
        ->whereNull('reviewed_by')
        ->count();

    // 7-day sparkline data
    $sparkline = Capsule::table('mod_fps_stats')
        ->where('date', '>=', date('Y-m-d', strtotime('-7 days')))
        ->orderBy('date')
        ->pluck('checks_total')
        ->toArray();

    return [
        'success' => true,
        'data' => [
            'checks_today' => $stats->checks_total ?? 0,
            'blocked_today' => $stats->checks_blocked ?? 0,
            'pre_checkout_blocks' => $stats->pre_checkout_blocks ?? 0,
            'active_threats' => $activeThreats,
            'review_queue' => $reviewCount,
            'block_rate' => ($stats && $stats->checks_total > 0)
                ? round(($stats->checks_blocked / $stats->checks_total) * 100, 1)
                : 0,
            'avg_risk_score' => round($avgScore, 1),
            'api_requests' => $stats->api_requests ?? 0,
            'sparkline' => $sparkline,
        ],
    ];
}

function fps_ajaxRecentChecks(): array
{
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $checks = Capsule::table('mod_fps_checks')
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get();

    // Enrich with client details (name, company)
    $enriched = [];
    foreach ($checks as $check) {
        $row = (array)$check;
        $row['client_name'] = '';
        $row['client_company'] = '';
        if (!empty($check->client_id)) {
            $client = Capsule::table('tblclients')
                ->where('id', $check->client_id)
                ->first(['firstname', 'lastname', 'companyname', 'email']);
            if ($client) {
                $row['client_name'] = trim($client->firstname . ' ' . $client->lastname);
                $row['client_company'] = $client->companyname ?? '';
                if (empty($row['email'])) {
                    $row['email'] = $client->email;
                }
            } else {
                // Client was purged -- try to read snapshot from check_context
                $ctx = json_decode($check->check_context ?? '{}', true);
                if (!empty($ctx['original_client'])) {
                    $row['client_name'] = $ctx['original_client'];
                } else {
                    $row['client_name'] = '(Purged Client #' . $check->client_id . ')';
                }
            }
        }
        $enriched[] = $row;
    }

    return ['success' => true, 'data' => $enriched];
}

function fps_ajaxChartData(): array
{
    $days = min((int)($_GET['days'] ?? 30), 90);
    $from = date('Y-m-d', strtotime("-{$days} days"));

    $stats = Capsule::table('mod_fps_stats')
        ->where('date', '>=', $from)
        ->orderBy('date')
        ->get()
        ->toArray();

    // Risk distribution
    $distribution = Capsule::table('mod_fps_checks')
        ->where('created_at', '>=', $from . ' 00:00:00')
        ->selectRaw('risk_level, COUNT(*) as count')
        ->groupBy('risk_level')
        ->pluck('count', 'risk_level')
        ->toArray();

    // Country breakdown
    $countries = Capsule::table('mod_fps_checks')
        ->where('created_at', '>=', $from . ' 00:00:00')
        ->whereNotNull('country')
        ->selectRaw('country, COUNT(*) as count, AVG(risk_score) as avg_score')
        ->groupBy('country')
        ->orderByDesc('count')
        ->limit(20)
        ->get()
        ->toArray();

    return [
        'success' => true,
        'data' => [
            'daily' => $stats,
            'distribution' => $distribution,
            'countries' => $countries,
        ],
    ];
}

function fps_ajaxManualCheck(): array
{
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId < 1) {
        return ['error' => 'Invalid client ID'];
    }

    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) {
        return ['error' => 'Client not found'];
    }

    try {
        $runner = new \FraudPreventionSuite\Lib\FpsCheckRunner();
        $result = $runner->runManualCheck($clientId);
        return ['success' => true, 'data' => $result];
    } catch (\Throwable $e) {
        return ['error' => 'Check failed: ' . $e->getMessage()];
    }
}

function fps_ajaxReviewQueue(): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $query = Capsule::table('mod_fps_checks')
        ->whereIn('risk_level', ['high', 'critical'])
        ->whereNull('reviewed_by');

    $total = $query->count();

    $checks = $query->orderByDesc('risk_score')
        ->offset($offset)
        ->limit($perPage)
        ->get()
        ->toArray();

    // Attach client info
    foreach ($checks as &$check) {
        $client = Capsule::table('tblclients')
            ->where('id', $check->client_id)
            ->first(['id', 'firstname', 'lastname', 'email', 'companyname']);
        $check->client = $client;
    }

    return [
        'success' => true,
        'data' => $checks,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $perPage),
    ];
}

function fps_ajaxApproveCheck(): array
{
    $checkId = (int)($_POST['check_id'] ?? 0);
    if ($checkId < 1) return ['error' => 'Invalid check ID'];

    Capsule::table('mod_fps_checks')
        ->where('id', $checkId)
        ->update([
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'action_taken' => 'approved',
            'locked' => 0,
        ]);

    // Unlock the order if it was locked
    $check = Capsule::table('mod_fps_checks')->where('id', $checkId)->first();
    if ($check && $check->order_id) {
        Capsule::table('tblorders')
            ->where('id', $check->order_id)
            ->where('status', 'Fraud')
            ->update(['status' => 'Pending']);
    }

    logActivity("Fraud Prevention: Check #{$checkId} approved by admin #{$_SESSION['adminid']}");
    return ['success' => true];
}

function fps_ajaxDenyCheck(): array
{
    $checkId = (int)($_POST['check_id'] ?? 0);
    if ($checkId < 1) return ['error' => 'Invalid check ID'];

    Capsule::table('mod_fps_checks')
        ->where('id', $checkId)
        ->update([
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'action_taken' => 'denied',
            'locked' => 1,
        ]);

    $check = Capsule::table('mod_fps_checks')->where('id', $checkId)->first();
    if ($check && $check->order_id) {
        Capsule::table('tblorders')
            ->where('id', $check->order_id)
            ->update(['status' => 'Cancelled']);
    }

    logActivity("Fraud Prevention: Check #{$checkId} denied by admin #{$_SESSION['adminid']}");
    return ['success' => true];
}

function fps_ajaxClientProfile(): array
{
    $clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];

    $client = Capsule::table('tblclients')->where('id', $clientId)
        ->first(['id', 'firstname', 'lastname', 'email', 'companyname', 'address1',
                  'city', 'state', 'postcode', 'country', 'phonenumber', 'status',
                  'datecreated', 'lastlogin', 'ip', 'host']);
    if (!$client) return ['error' => 'Client not found'];

    // All checks for this client
    $checks = Capsule::table('mod_fps_checks')
        ->where('client_id', $clientId)
        ->orderByDesc('created_at')
        ->get()
        ->toArray();

    // Latest IP intel
    $latestCheck = Capsule::table('mod_fps_checks')
        ->where('client_id', $clientId)
        ->orderByDesc('created_at')
        ->first();

    $ipIntel = null;
    if ($latestCheck && $latestCheck->ip_address) {
        $ipIntel = Capsule::table('mod_fps_ip_intel')
            ->where('ip_address', $latestCheck->ip_address)
            ->first();
    }

    // Email intel
    $emailIntel = Capsule::table('mod_fps_email_intel')
        ->where('email', $client->email)
        ->first();

    // Fingerprints
    $fingerprints = Capsule::table('mod_fps_fingerprints')
        ->where('client_id', $clientId)
        ->orderByDesc('last_seen_at')
        ->get()
        ->toArray();

    // Associated accounts (shared fingerprints)
    $fpHashes = array_column($fingerprints, 'fingerprint_hash');
    $associated = [];
    if (!empty($fpHashes)) {
        $associated = Capsule::table('mod_fps_fingerprints')
            ->whereIn('fingerprint_hash', $fpHashes)
            ->where('client_id', '!=', $clientId)
            ->join('tblclients', 'tblclients.id', '=', 'mod_fps_fingerprints.client_id')
            ->select('tblclients.id', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                     'mod_fps_fingerprints.fingerprint_hash', 'mod_fps_fingerprints.times_seen')
            ->get()
            ->toArray();
    }

    // Orders
    $orders = Capsule::table('tblorders')
        ->where('userid', $clientId)
        ->orderByDesc('date')
        ->limit(20)
        ->get()
        ->toArray();

    return [
        'success' => true,
        'data' => [
            'client' => $client,
            'checks' => $checks,
            'ip_intel' => $ipIntel,
            'email_intel' => $emailIntel,
            'fingerprints' => $fingerprints,
            'associated' => $associated,
            'orders' => $orders,
            'risk_summary' => [
                'total_checks' => count($checks),
                'avg_score' => count($checks) > 0 ? round(array_sum(array_column($checks, 'risk_score')) / count($checks), 1) : 0,
                'highest_score' => count($checks) > 0 ? max(array_column($checks, 'risk_score')) : 0,
                'times_blocked' => count(array_filter($checks, fn($c) => $c->locked)),
            ],
        ],
    ];
}

function fps_ajaxMassScan(): array
{
    $clientIds = $_POST['client_ids'] ?? [];
    if (empty($clientIds)) {
        // Get batch of unscanned clients
        $status = $_POST['status'] ?? 'Active';
        $limit = min((int)($_POST['batch_size'] ?? 25), 50);
        $offset = (int)($_POST['offset'] ?? 0);

        $clients = Capsule::table('tblclients')
            ->where('status', $status)
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->pluck('id')
            ->toArray();

        $clientIds = $clients;
    }

    $results = [];
    foreach ($clientIds as $cid) {
        try {
            $runner = new \FraudPreventionSuite\Lib\FpsCheckRunner();
            $result = $runner->runManualCheck((int)$cid);
            $results[] = ['client_id' => (int)$cid, 'success' => true, 'data' => $result];
        } catch (\Throwable $e) {
            $results[] = ['client_id' => (int)$cid, 'success' => false, 'error' => $e->getMessage()];
        }
    }

    $total = Capsule::table('tblclients')
        ->where('status', $_POST['status'] ?? 'Active')
        ->count();

    return [
        'success' => true,
        'results' => $results,
        'total_clients' => $total,
        'processed' => count($results),
    ];
}

function fps_ajaxSaveRule(): array
{
    $data = [
        'rule_name' => trim($_POST['rule_name'] ?? ''),
        'rule_type' => trim($_POST['rule_type'] ?? ''),
        'rule_value' => trim($_POST['rule_value'] ?? ''),
        'action' => $_POST['rule_action'] ?? 'flag',
        'priority' => (int)($_POST['priority'] ?? 50),
        'score_weight' => (float)($_POST['score_weight'] ?? 1.0),
        'description' => trim($_POST['description'] ?? ''),
        'enabled' => 1,
    ];

    if (empty($data['rule_name']) || empty($data['rule_type']) || empty($data['rule_value'])) {
        return ['error' => 'Name, type, and value are required'];
    }

    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId > 0) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_fps_rules')->where('id', $ruleId)->update($data);
    } else {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['created_by'] = (int)$_SESSION['adminid'];
        $ruleId = Capsule::table('mod_fps_rules')->insertGetId($data);
    }

    return ['success' => true, 'rule_id' => $ruleId];
}

function fps_ajaxDeleteRule(): array
{
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId < 1) return ['error' => 'Invalid rule ID'];
    Capsule::table('mod_fps_rules')->where('id', $ruleId)->delete();
    return ['success' => true];
}

function fps_ajaxToggleRule(): array
{
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId < 1) return ['error' => 'Invalid rule ID'];
    $rule = Capsule::table('mod_fps_rules')->where('id', $ruleId)->first();
    if (!$rule) return ['error' => 'Rule not found'];
    Capsule::table('mod_fps_rules')->where('id', $ruleId)->update(['enabled' => $rule->enabled ? 0 : 1]);
    return ['success' => true, 'enabled' => !$rule->enabled];
}

function fps_ajaxSaveSettings(): array
{
    $settings = $_POST['settings'] ?? [];
    // Accept JSON-encoded settings string from JS
    if (is_string($settings)) {
        $decoded = json_decode($settings, true);
        $settings = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($settings) || empty($settings)) return ['error' => 'Invalid settings data'];

    // Protected keys: never overwrite with empty values (prevents accidental API key deletion)
    $protectedKeys = [
        'turnstile_site_key', 'turnstile_secret_key',
        'abuseipdb_api_key', 'ipqs_api_key',
        'safe_browsing_api_key', 'virustotal_api_key',
        'sfs_report_api_key', 'fraudrecord_api_key',
    ];

    // Keys that must be saved to tbladdonmodules (WHMCS module config table)
    // because providers read from there, not mod_fps_settings
    $addonModuleKeys = ['fraudrecord_api_key'];

    foreach ($settings as $key => $value) {
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        // Don't overwrite API keys with empty values
        if (in_array($key, $protectedKeys, true) && ($value === '' || $value === null)) {
            continue;
        }

        // Some keys must go to tbladdonmodules where providers read from
        if (in_array($key, $addonModuleKeys, true)) {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => 'fraud_prevention_suite', 'setting' => $key],
                ['value' => $value]
            );
        }

        // Always also save to mod_fps_settings for consistency
        Capsule::table('mod_fps_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }

    // Save per-gateway thresholds
    $gatewaySettings = $_POST['gateway_thresholds'] ?? [];
    if (is_string($gatewaySettings)) {
        $decoded = json_decode($gatewaySettings, true);
        $gatewaySettings = is_array($decoded) ? $decoded : [];
    }
    if (is_array($gatewaySettings)) {
        foreach ($gatewaySettings as $gateway => $config) {
            $gateway = preg_replace('/[^a-z0-9_]/', '', $gateway);
            if ($gateway === '') continue;

            Capsule::table('mod_fps_gateway_thresholds')->updateOrInsert(
                ['gateway' => $gateway],
                [
                    'block_threshold'  => max(0, min(100, (int)($config['block'] ?? 85))),
                    'flag_threshold'   => max(0, min(100, (int)($config['flag'] ?? 60))),
                    'require_captcha'  => !empty($config['captcha']) ? 1 : 0,
                    'enabled'          => !empty($config['enabled']) ? 1 : 0,
                    'updated_at'       => date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    return ['success' => true];
}

function fps_ajaxCreateApiKey(): array
{
    $name = trim($_POST['name'] ?? '');
    $tier = $_POST['tier'] ?? 'free';
    $email = trim($_POST['email'] ?? '');

    if (empty($name)) return ['error' => 'Name is required'];

    $rawKey = 'fps_' . bin2hex(random_bytes(24));
    $keyHash = hash('sha256', $rawKey);
    $prefix = substr($rawKey, 0, 8);

    $tierLimits = [
        'free' => ['minute' => 30, 'day' => 5000],
        'basic' => ['minute' => 120, 'day' => 50000],
        'premium' => ['minute' => 600, 'day' => 500000],
    ];
    $limits = $tierLimits[$tier] ?? $tierLimits['free'];

    Capsule::table('mod_fps_api_keys')->insert([
        'key_hash' => $keyHash,
        'key_prefix' => $prefix,
        'name' => $name,
        'tier' => $tier,
        'owner_email' => $email,
        'rate_limit_per_minute' => $limits['minute'],
        'rate_limit_per_day' => $limits['day'],
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return ['success' => true, 'api_key' => $rawKey, 'message' => 'Save this key - it will not be shown again'];
}

function fps_ajaxRevokeApiKey(): array
{
    $keyId = (int)($_POST['key_id'] ?? 0);
    if ($keyId < 1) return ['error' => 'Invalid key ID'];
    Capsule::table('mod_fps_api_keys')->where('id', $keyId)->update(['is_active' => 0]);
    return ['success' => true];
}

function fps_ajaxTopologyData(): array
{
    $hours = min((int)($_GET['hours'] ?? 24), 720);
    $since = date('Y-m-d H:i:s', time() - ($hours * 3600));

    $events = Capsule::table('mod_fps_geo_events')
        ->where('created_at', '>=', $since)
        ->orderByDesc('created_at')
        ->limit(1000)
        ->get()
        ->toArray();

    // Aggregate hotspots
    $hotspots = Capsule::table('mod_fps_geo_events')
        ->where('created_at', '>=', $since)
        ->selectRaw('country_code, ROUND(latitude, 1) as lat, ROUND(longitude, 1) as lng, COUNT(*) as count, AVG(risk_score) as avg_score')
        ->groupBy('country_code', Capsule::raw('ROUND(latitude, 1)'), Capsule::raw('ROUND(longitude, 1)'))
        ->orderByDesc('count')
        ->limit(200)
        ->get()
        ->toArray();

    // Global stats for the overlay counters
    $totalChecks = Capsule::table('mod_fps_geo_events')
        ->where('created_at', '>=', $since)->count();
    $activeCountries = Capsule::table('mod_fps_geo_events')
        ->where('created_at', '>=', $since)->distinct()->count('country_code');
    $totalBlocks = Capsule::table('mod_fps_geo_events')
        ->where('created_at', '>=', $since)
        ->whereIn('risk_level', ['high', 'critical'])->count();

    return [
        'success' => true,
        'data' => [
            'events' => array_slice($events, 0, 50),
            'hotspots' => $hotspots,
            'total_events' => count($events),
            'total_checks' => $totalChecks,
            'active_countries' => $activeCountries,
            'total_blocks' => $totalBlocks,
        ],
    ];
}

function fps_ajaxBulkTerminate(): array
{
    $clientIds = $_POST['client_ids'] ?? [];
    if (is_string($clientIds)) $clientIds = array_filter(explode(',', $clientIds));
    if (empty($clientIds)) {
        return ['error' => 'No clients selected'];
    }

    $terminated = 0;
    $errors = [];

    foreach ($clientIds as $cid) {
        $cid = (int)$cid;
        try {
            // Use WHMCS API to properly terminate
            $result = localAPI('CloseClient', ['clientid' => $cid], 'admin');
            if ($result['result'] === 'success') {
                $terminated++;
                logActivity("Fraud Prevention: Client #{$cid} terminated via bulk action by admin #{$_SESSION['adminid']}");
            } else {
                $errors[] = "Client #{$cid}: " . ($result['message'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $errors[] = "Client #{$cid}: " . $e->getMessage();
        }
    }

    return [
        'success' => true,
        'terminated' => $terminated,
        'errors' => $errors,
    ];
}

function fps_ajaxExportCsv(): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fps-export-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Client ID', 'Order ID', 'Risk Score', 'Risk Level', 'IP', 'Email', 'Country', 'Action', 'Created']);

    $checks = Capsule::table('mod_fps_checks')
        ->orderByDesc('created_at')
        ->limit(10000)
        ->get();

    foreach ($checks as $check) {
        fputcsv($output, [
            $check->id, $check->client_id, $check->order_id, $check->risk_score,
            $check->risk_level, $check->ip_address, $check->email, $check->country,
            $check->action_taken, $check->created_at,
        ]);
    }

    fclose($output);
}

function fps_ajaxBulkApprove(): array
{
    $checkIds = array_filter(explode(',', $_POST['check_ids'] ?? ''));
    if (empty($checkIds)) return ['error' => 'No items selected'];

    $count = 0;
    foreach ($checkIds as $cid) {
        $cid = (int)$cid;
        if ($cid < 1) continue;
        Capsule::table('mod_fps_checks')->where('id', $cid)->update([
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'action_taken' => 'approved',
            'locked' => 0,
        ]);
        $count++;
    }
    logActivity("Fraud Prevention: Bulk approved {$count} checks by admin #{$_SESSION['adminid']}");
    return ['success' => true, 'count' => $count];
}

function fps_ajaxBulkDeny(): array
{
    $checkIds = array_filter(explode(',', $_POST['check_ids'] ?? ''));
    if (empty($checkIds)) return ['error' => 'No items selected'];

    $count = 0;
    foreach ($checkIds as $cid) {
        $cid = (int)$cid;
        if ($cid < 1) continue;
        Capsule::table('mod_fps_checks')->where('id', $cid)->update([
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'action_taken' => 'denied',
            'locked' => 1,
        ]);
        $count++;
    }
    logActivity("Fraud Prevention: Bulk denied {$count} checks by admin #{$_SESSION['adminid']}");
    return ['success' => true, 'count' => $count];
}

function fps_ajaxBulkFlag(): array
{
    $clientIds = $_POST['client_ids'] ?? [];
    if (is_string($clientIds)) $clientIds = array_filter(explode(',', $clientIds));
    if (empty($clientIds)) return ['error' => 'No clients selected'];

    $flagged = 0;
    foreach ($clientIds as $cid) {
        $cid = (int)$cid;
        if ($cid < 1) continue;
        try {
            // Flag all checks for this client as "high risk - manual flag"
            Capsule::table('mod_fps_checks')->where('client_id', $cid)->update([
                'reviewed_by' => (int)$_SESSION['adminid'],
                'reviewed_at' => date('Y-m-d H:i:s'),
                'action_taken' => 'flagged',
            ]);
            $flagged++;
            logActivity("Fraud Prevention: Client #{$cid} flagged via bulk action by admin #{$_SESSION['adminid']}");
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
    return ['success' => true, 'processed' => $flagged];
}

function fps_ajaxReportFraudRecord(): array
{
    $checkId = (int)($_POST['check_id'] ?? 0);
    if ($checkId < 1) return ['error' => 'Invalid check ID'];

    $check = Capsule::table('mod_fps_checks')->where('id', $checkId)->first();
    if (!$check) return ['error' => 'Check not found'];

    if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\FraudRecordProvider')) {
        $provider = new \FraudPreventionSuite\Lib\Providers\FraudRecordProvider();
        $result = $provider->fps_reportToFraudRecord(
            $check->email ?? '',
            $check->ip_address ?? '',
            '',
            'Flagged by FPS: score ' . ($check->risk_score ?? 0) . ', level ' . ($check->risk_level ?? 'unknown')
        );
        return ['success' => true, 'result' => $result];
    }

    return ['error' => 'FraudRecord provider not available'];
}

function fps_ajaxReportClientFraudRecord(): array
{
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];

    $client = Capsule::table('tblclients')->where('id', $clientId)
        ->first(['id', 'email', 'ip', 'phonenumber']);
    if (!$client) return ['error' => 'Client not found'];

    if (class_exists('\\FraudPreventionSuite\\Lib\\Providers\\FraudRecordProvider')) {
        $provider = new \FraudPreventionSuite\Lib\Providers\FraudRecordProvider();
        $result = $provider->fps_reportToFraudRecord(
            $client->email ?? '',
            $client->ip ?? '',
            $client->phonenumber ?? '',
            'Reported via admin by admin #' . ($_SESSION['adminid'] ?? 0)
        );
        logActivity("Fraud Prevention: Client #{$clientId} reported to FraudRecord by admin #{$_SESSION['adminid']}");
        return ['success' => true, 'result' => $result];
    }

    return ['error' => 'FraudRecord provider not available'];
}

function fps_ajaxTerminateClient(): array
{
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];

    $result = localAPI('CloseClient', ['clientid' => $clientId], 'admin');
    if (($result['result'] ?? '') === 'success') {
        logActivity("Fraud Prevention: Client #{$clientId} terminated by admin #{$_SESSION['adminid']}");
        return ['success' => true];
    }

    return ['error' => $result['message'] ?? 'Termination failed'];
}

function fps_ajaxPurgeCaches(): array
{
    $tables = ['mod_fps_ip_intel', 'mod_fps_email_intel', 'mod_fps_bin_cache'];
    $purged = 0;
    foreach ($tables as $t) {
        try {
            $purged += Capsule::table($t)->count();
            Capsule::table($t)->truncate();
        } catch (\Throwable $e) {
            // Table may not exist
        }
    }
    logActivity("Fraud Prevention: All caches purged ({$purged} entries) by admin #{$_SESSION['adminid']}");
    return ['success' => true, 'purged' => $purged];
}

function fps_ajaxResetStatistics(): array
{
    try {
        Capsule::table('mod_fps_stats')->truncate();
        logActivity("Fraud Prevention: Statistics reset by admin #{$_SESSION['adminid']}");
        return ['success' => true];
    } catch (\Throwable $e) {
        return ['error' => 'Failed to reset statistics: ' . $e->getMessage()];
    }
}

function fps_ajaxGetApiKeyDetail(): array
{
    $keyId = (int)($_POST['key_id'] ?? $_GET['key_id'] ?? 0);
    if ($keyId < 1) return ['error' => 'Invalid key ID'];

    $key = Capsule::table('mod_fps_api_keys')->where('id', $keyId)->first();
    if (!$key) return ['error' => 'Key not found'];

    $recentLogs = Capsule::table('mod_fps_api_logs')
        ->where('api_key_id', $keyId)
        ->orderByDesc('created_at')
        ->limit(20)
        ->get()
        ->toArray();

    return [
        'success' => true,
        'data' => [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->key_prefix,
            'tier' => $key->tier,
            'owner_email' => $key->owner_email,
            'is_active' => (bool)$key->is_active,
            'rate_limit_per_minute' => $key->rate_limit_per_minute,
            'rate_limit_per_day' => $key->rate_limit_per_day,
            'total_requests' => $key->total_requests,
            'created_at' => $key->created_at,
            'last_used_at' => $key->last_used_at,
            'recent_logs' => $recentLogs,
        ],
    ];
}

function fps_ajaxExportClientProfile(): void
{
    $clientId = (int)($_GET['client_id'] ?? 0);
    if ($clientId < 1) {
        echo json_encode(['error' => 'Invalid client ID']);
        return;
    }

    $client = Capsule::table('tblclients')->where('id', $clientId)
        ->first(['id', 'firstname', 'lastname', 'email', 'companyname', 'country', 'status', 'datecreated']);
    if (!$client) {
        echo json_encode(['error' => 'Client not found']);
        return;
    }

    $checks = Capsule::table('mod_fps_checks')
        ->where('client_id', $clientId)
        ->orderByDesc('created_at')
        ->get()
        ->toArray();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fps-client-' . $clientId . '-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Check ID', 'Risk Score', 'Risk Level', 'IP', 'Country', 'Action', 'Reviewed By', 'Created']);
    foreach ($checks as $check) {
        fputcsv($output, [
            $check->id, $check->risk_score, $check->risk_level,
            $check->ip_address, $check->country, $check->action_taken,
            $check->reviewed_by, $check->created_at,
        ]);
    }
    fclose($output);
}

// ---------------------------------------------------------------------------
// CLIENT AREA - Public topology page + API
// ---------------------------------------------------------------------------

function fraud_prevention_suite_clientarea(array $vars): array
{
    $page = $_GET['page'] ?? '';

    // Handle public AJAX requests (GDPR -- no admin auth needed)
    if (isset($_GET['ajax']) && in_array($page, ['gdpr-submit', 'gdpr-verify'], true)) {
        header('Content-Type: application/json');
        try {
            if ($page === 'gdpr-submit') {
                echo json_encode(fps_ajaxGdprSubmitRequest());
            } elseif ($page === 'gdpr-verify') {
                echo json_encode(fps_ajaxGdprVerifyEmail());
            }
        } catch (\Throwable $e) {
            echo json_encode(['error' => 'Request failed']);
        }
        exit;
    }

    // Topology is a full-screen standalone page -- serve directly with injected stats
    if ($page === 'topology') {
        $tplPath = __DIR__ . '/templates/topology.tpl';
        if (file_exists($tplPath)) {
            // Use geo_events for topology stats (same source as API /v1/stats/global)
            $totalChecks = 0; $activeCountries = 0; $totalBlocks = 0;
            try {
                $totalChecks = Capsule::table('mod_fps_geo_events')->count();
                $activeCountries = Capsule::table('mod_fps_geo_events')
                    ->whereNotNull('country_code')->where('country_code', '!=', '')
                    ->distinct()->count('country_code');
                $totalBlocks = Capsule::table('mod_fps_geo_events')
                    ->whereIn('risk_level', ['high', 'critical'])->count();
            } catch (\Throwable $e) {
                // Fallback to mod_fps_checks if geo_events fails
                $stats = fps_getPublicStats();
                $totalChecks = $stats['total_checks'] ?? 0;
                $activeCountries = $stats['countries_monitored'] ?? 0;
                $totalBlocks = $stats['threats_blocked'] ?? 0;
            }
            $blockRate = $totalChecks > 0 ? round(($totalBlocks / $totalChecks) * 100) : 0;
            $initialData = json_encode([
                'total_checks' => $totalChecks,
                'active_countries' => $activeCountries,
                'total_blocks' => $totalBlocks,
                'block_rate' => $blockRate,
            ]);
            $apiBase = 'index.php?m=fraud_prevention_suite&api=1';
            $html = file_get_contents($tplPath);
            // Inject data before closing </head>
            $inject = '<script>window.FPS_INITIAL_STATS=' . $initialData . ';window.FPS_API_BASE="' . $apiBase . '";</script>';
            $html = str_replace('</head>', $inject . "\n</head>", $html);
            echo $html;
            exit;
        }
    }

    $liveStats = fps_getPublicStats();
    $gdprUrl = 'index.php?m=fraud_prevention_suite&page=gdpr-request';
    $commonVars = [
        'stats'          => $liveStats,
        'module_version' => '4.2.0',
        'topology_url'   => 'index.php?m=fraud_prevention_suite&page=topology',
        'global_url'     => 'index.php?m=fraud_prevention_suite&page=global',
        'api_docs_url'   => 'index.php?m=fraud_prevention_suite&page=api-docs',
        'gdpr_url'       => $gdprUrl,
        'overview_url'   => 'index.php?m=fraud_prevention_suite',
    ];

    // Route to the right template
    $templateMap = [
        'global'        => ['title' => 'Global Threat Intelligence', 'template' => 'global'],
        'api-docs'      => ['title' => 'API Documentation',          'template' => 'apidocs'],
        'gdpr-request'  => ['title' => 'Data Removal Request',       'template' => 'gdpr'],
    ];

    if (isset($templateMap[$page])) {
        $info = $templateMap[$page];
        return [
            'pagetitle'    => 'Fraud Prevention Suite - ' . $info['title'],
            'breadcrumb'   => [
                'index.php?m=fraud_prevention_suite' => 'Fraud Prevention Suite',
                'index.php?m=fraud_prevention_suite&page=' . $page => $info['title'],
            ],
            'templatefile' => $info['template'],
            'vars'         => $commonVars,
        ];
    }

    // Default: overview page
    return [
        'pagetitle'    => 'Fraud Prevention Suite - API & Intelligence Platform',
        'breadcrumb'   => ['index.php?m=fraud_prevention_suite' => 'Fraud Prevention Suite'],
        'templatefile' => 'overview',
        'vars'         => $commonVars,
    ];
}

// ---------------------------------------------------------------------------
// v3.0 AJAX IMPLEMENTATIONS: Trust Management
// ---------------------------------------------------------------------------

function fps_ajaxSetClientTrust(): array
{
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $status   = $_POST['status'] ?? '';
    $reason   = $_POST['reason'] ?? '';
    $adminId  = (int) ($_SESSION['adminid'] ?? 0);

    if ($clientId <= 0) {
        return ['error' => 'Invalid client ID'];
    }
    if (!in_array($status, ['trusted', 'normal', 'blacklisted', 'suspended'], true)) {
        return ['error' => 'Invalid status'];
    }

    try {
        $manager = new \FraudPreventionSuite\Lib\FpsClientTrustManager();
        $manager->setClientStatus($clientId, $status, $reason, $adminId);
        return ['success' => true, 'message' => "Client #{$clientId} set to {$status}"];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function fps_ajaxGetTrustList(): array
{
    $status  = $_GET['filter'] ?? $_POST['filter'] ?? '';
    $search  = trim($_GET['search'] ?? $_POST['search'] ?? '');
    $page    = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
    $perPage = 25;

    try {
        // When a specific status filter is set, query the trust table
        if ($status !== '' && in_array($status, ['trusted', 'blacklisted', 'suspended'], true)) {
            $manager = new \FraudPreventionSuite\Lib\FpsClientTrustManager();
            $result = $manager->getClientsWithStatus($status, $page, $perPage);

            $enriched = [];
            foreach ($result['rows'] as $row) {
                $client = Capsule::table('tblclients')->where('id', $row->client_id)
                    ->first(['firstname', 'lastname', 'email', 'companyname', 'status']);
                $enriched[] = [
                    'client_id'    => $row->client_id,
                    'status'       => $row->status,
                    'reason'       => $row->reason ?? '',
                    'admin_id'     => $row->set_by_admin_id ?? 0,
                    'updated_at'   => $row->updated_at ?? $row->created_at ?? '',
                    'client_name'  => $client ? trim($client->firstname . ' ' . $client->lastname) : '(Deleted #' . $row->client_id . ')',
                    'client_email' => $client ? ($client->email ?? '') : '',
                    'company'      => $client ? ($client->companyname ?? '') : '',
                    'whmcs_status' => $client ? ($client->status ?? '') : '',
                ];
            }
            return ['success' => true, 'rows' => $enriched, 'total' => $result['total'], 'pages' => $result['pages']];
        }

        // "All" or empty filter: show ALL clients with their trust status (LEFT JOIN)
        $query = Capsule::table('tblclients')
            ->leftJoin('mod_fps_client_trust', 'tblclients.id', '=', 'mod_fps_client_trust.client_id')
            ->select(
                'tblclients.id as client_id',
                'tblclients.firstname', 'tblclients.lastname',
                'tblclients.email', 'tblclients.companyname',
                'tblclients.status as whmcs_status',
                'mod_fps_client_trust.status as trust_status',
                'mod_fps_client_trust.reason',
                'mod_fps_client_trust.set_by_admin_id',
                'mod_fps_client_trust.updated_at'
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('tblclients.email', 'LIKE', '%' . $search . '%')
                  ->orWhere('tblclients.firstname', 'LIKE', '%' . $search . '%')
                  ->orWhere('tblclients.lastname', 'LIKE', '%' . $search . '%')
                  ->orWhere('tblclients.companyname', 'LIKE', '%' . $search . '%')
                  ->orWhere('tblclients.id', '=', (int)$search);
            });
        }

        $total = $query->count();
        $rows = $query->orderBy('tblclients.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $enriched = [];
        foreach ($rows as $row) {
            $enriched[] = [
                'client_id'    => $row->client_id,
                'status'       => $row->trust_status ?? 'normal',
                'reason'       => $row->reason ?? '',
                'admin_id'     => $row->set_by_admin_id ?? 0,
                'updated_at'   => $row->updated_at ?? '',
                'client_name'  => trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')),
                'client_email' => $row->email ?? '',
                'company'      => $row->companyname ?? '',
                'whmcs_status' => $row->whmcs_status ?? '',
            ];
        }

        return ['success' => true, 'rows' => $enriched, 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// v3.0 AJAX IMPLEMENTATIONS: Webhook Management
// ---------------------------------------------------------------------------

function fps_ajaxSaveWebhook(): array
{
    $id     = (int) ($_POST['webhook_id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $type   = $_POST['type'] ?? 'generic';
    $url    = trim($_POST['url'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    $events = $_POST['events'] ?? '[]';
    $enabled = (int) ($_POST['enabled'] ?? 1);

    if ($name === '' || $url === '') {
        return ['error' => 'Name and URL are required'];
    }
    if (!in_array($type, ['slack', 'teams', 'discord', 'generic'], true)) {
        return ['error' => 'Invalid webhook type'];
    }

    try {
        $data = [
            'name'    => $name,
            'type'    => $type,
            'url'     => $url,
            'secret'  => $secret,
            'events'  => $events,
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            Capsule::table('mod_fps_webhook_configs')->where('id', $id)->update($data);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = Capsule::table('mod_fps_webhook_configs')->insertGetId($data);
        }

        return ['success' => true, 'webhook_id' => $id];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function fps_ajaxDeleteWebhook(): array
{
    $id = (int) ($_POST['webhook_id'] ?? 0);
    if ($id <= 0) return ['error' => 'Invalid webhook ID'];

    try {
        Capsule::table('mod_fps_webhook_configs')->where('id', $id)->delete();
        return ['success' => true];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function fps_ajaxTestWebhook(): array
{
    $id = (int) ($_POST['webhook_id'] ?? 0);
    if ($id <= 0) return ['error' => 'Invalid webhook ID'];

    try {
        $notifier = new \FraudPreventionSuite\Lib\FpsWebhookNotifier();
        $result = $notifier->sendTestWebhook($id);
        return $result;
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function fps_ajaxGetWebhooks(): array
{
    try {
        $webhooks = Capsule::table('mod_fps_webhook_configs')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return ['success' => true, 'webhooks' => $webhooks];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// v4.0: BOT CLEANUP AJAX HANDLERS
// ---------------------------------------------------------------------------

/**
 * Scan for bot accounts using real WHMCS financial data.
 */
function fps_ajaxDetectBots(): array
{
    try {
        $status = $_GET['status'] ?? $_POST['status'] ?? '';
        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->detectBots($status);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Preview a bot action (dry-run) before executing it.
 * Supports: flag, deactivate, purge, deep_purge
 */
function fps_ajaxPreviewBotAction(): array
{
    try {
        $action = $_GET['preview_action'] ?? $_POST['preview_action'] ?? '';
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));

        if (empty($clientIds)) {
            return ['error' => 'No client IDs provided'];
        }

        $validActions = ['flag', 'deactivate', 'purge', 'deep_purge'];
        if (!in_array($action, $validActions, true)) {
            return ['error' => 'Invalid preview action: ' . $action];
        }

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();

        switch ($action) {
            case 'flag':
                $results = [];
                foreach ($clientIds as $id) {
                    $client = Capsule::table('tblclients')->where('id', $id)
                        ->first(['id', 'email', 'firstname', 'lastname', 'notes', 'status']);
                    if (!$client) continue;
                    $hasFlag = strpos($client->notes ?? '', '[FPS-BOT]') !== false;
                    $results[] = [
                        'id'     => (int)$client->id,
                        'email'  => $client->email,
                        'name'   => trim($client->firstname . ' ' . $client->lastname),
                        'status' => $client->status ?? '',
                        'impact' => $hasFlag ? 'Already flagged (no change)' : 'Will add [FPS-BOT] flag to notes',
                    ];
                }
                $newFlags = count(array_filter($results, fn($r) => strpos($r['impact'], 'Already') === false));
                return [
                    'success' => true,
                    'summary' => "{$newFlags} accounts will be flagged",
                    'count'   => $newFlags,
                    'total'   => count($results),
                    'details' => $results,
                ];

            case 'deactivate':
                return $detector->previewDeactivate($clientIds);

            case 'purge':
                return $detector->previewPurge($clientIds);

            case 'deep_purge':
                return $detector->previewDeepPurge($clientIds);
        }

        return ['error' => 'Unhandled action'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Flag selected bot accounts.
 */
function fps_ajaxFlagBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->flagBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Deactivate selected bot accounts.
 */
function fps_ajaxDeactivateBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->deactivateBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Purge selected bot accounts (zero-record accounts only).
 */
function fps_ajaxPurgeBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->purgeBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Deep purge selected bot accounts (accounts with only Fraud/Cancelled records).
 */
function fps_ajaxDeepPurgeBots(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $clientIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($clientIds)) return ['error' => 'No client IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->deepPurgeBotAccounts($clientIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Detect orphan user accounts (tblusers with no real clients).
 */
function fps_ajaxDetectOrphanUsers(): array
{
    try {
        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->detectOrphanUsers();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Purge selected orphan user accounts.
 */
function fps_ajaxPurgeOrphanUsers(): array
{
    try {
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
        $userIds = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($userIds)) return ['error' => 'No user IDs'];

        $detector = new \FraudPreventionSuite\Lib\FpsBotDetector();
        return $detector->purgeOrphanUsers($userIds);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Get paginated module log entries.
 */
function fps_ajaxGetModuleLog(): array
{
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $filter = $_GET['filter'] ?? 'all';

        $query = Capsule::table('tblmodulelog')
            ->where('module', 'fraud_prevention_suite')
            ->orderByDesc('date');

        if ($filter === 'errors') {
            $query->where('response', 'LIKE', '%rror%');
        } elseif ($filter === 'warnings') {
            $query->where(function ($q) {
                $q->where('response', 'LIKE', '%arning%')
                  ->orWhere('response', 'LIKE', '%rror%');
            });
        }

        $total = $query->count();
        $logs = $query->offset($offset)->limit($perPage)->get()->toArray();

        return [
            'success'    => true,
            'logs'       => $logs,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> (int)ceil($total / $perPage),
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Clear all FPS-related module log entries.
 */
function fps_ajaxClearFpsLogs(): array
{
    try {
        $deleted = Capsule::table('tblmodulelog')
            ->where('module', 'fraud_prevention_suite')
            ->delete();

        return ['success' => true, 'deleted' => $deleted];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Universal preview for ALL destructive operations (dry-run).
 * Supports: bulk_terminate, clear_all_checks, clear_all_reports,
 *           reset_statistics, terminate_client, delete_checks_bulk, bulk_deny
 */
function fps_ajaxPreviewDestructive(): array
{
    try {
        $previewAction = $_GET['preview_action'] ?? $_POST['preview_action'] ?? $_REQUEST['preview_action'] ?? '';
        $ids = $_GET['ids'] ?? $_POST['ids'] ?? $_REQUEST['ids'] ?? '';

        switch ($previewAction) {
            case 'bulk_terminate':
                $clientIds = array_filter(array_map('intval', explode(',', $ids)));
                if (empty($clientIds)) return ['error' => 'No IDs provided'];

                $details = [];
                foreach ($clientIds as $cid) {
                    $client = Capsule::table('tblclients')->where('id', $cid)
                        ->first(['id', 'email', 'firstname', 'lastname', 'status']);
                    if (!$client) continue;
                    $serviceCount = Capsule::table('tblhosting')->where('userid', $cid)
                        ->where('domainstatus', 'Active')->count();
                    $details[] = [
                        'id'       => (int)$client->id,
                        'email'    => $client->email,
                        'name'     => trim($client->firstname . ' ' . $client->lastname),
                        'status'   => $client->status,
                        'services' => $serviceCount,
                        'impact'   => "Will terminate {$serviceCount} active services and close account",
                    ];
                }
                return [
                    'success' => true,
                    'summary' => count($details) . " clients will be terminated",
                    'count'   => count($details),
                    'details' => $details,
                ];

            case 'clear_all_checks':
                $total = Capsule::table('mod_fps_checks')->count();
                $breakdown = Capsule::table('mod_fps_checks')
                    ->select('risk_level', Capsule::raw('COUNT(*) as cnt'))
                    ->groupBy('risk_level')
                    ->pluck('cnt', 'risk_level')
                    ->toArray();
                return [
                    'success' => true,
                    'summary' => "{$total} fraud check records will be deleted",
                    'count'   => $total,
                    'details' => [['breakdown' => $breakdown, 'total' => $total]],
                ];

            case 'clear_all_reports':
                $total = 0;
                try {
                    $total = Capsule::table('mod_fps_reports')->count();
                } catch (\Throwable $e) {
                    // Table may not exist
                }
                return [
                    'success' => true,
                    'summary' => "{$total} fraud reports will be deleted",
                    'count'   => $total,
                    'details' => [],
                ];

            case 'reset_statistics':
                $total = Capsule::table('mod_fps_stats')->count();
                return [
                    'success' => true,
                    'summary' => "{$total} daily statistics rows will be reset",
                    'count'   => $total,
                    'details' => [],
                ];

            case 'terminate_client':
                $clientIds = array_filter(array_map('intval', explode(',', $ids)));
                if (empty($clientIds)) return ['error' => 'No client ID provided'];
                $cid = $clientIds[0];

                $services = Capsule::table('tblhosting')
                    ->where('userid', $cid)
                    ->get(['id', 'domain', 'domainstatus', 'packageid'])
                    ->toArray();

                $details = [];
                foreach ($services as $svc) {
                    $product = Capsule::table('tblproducts')
                        ->where('id', $svc->packageid)->value('name') ?? 'Unknown';
                    $details[] = [
                        'service_id' => $svc->id,
                        'domain'     => $svc->domain,
                        'product'    => $product,
                        'status'     => $svc->domainstatus,
                    ];
                }
                return [
                    'success' => true,
                    'summary' => count($services) . " services for client #{$cid} will be affected",
                    'count'   => count($services),
                    'details' => $details,
                ];

            case 'delete_checks_bulk':
                $checkIds = array_filter(array_map('intval', explode(',', $ids)));
                if (empty($checkIds)) return ['error' => 'No check IDs'];

                $checks = Capsule::table('mod_fps_checks')
                    ->whereIn('id', $checkIds)
                    ->get(['id', 'client_id', 'risk_score', 'risk_level'])
                    ->toArray();

                $details = [];
                foreach ($checks as $chk) {
                    $details[] = [
                        'id'        => $chk->id,
                        'client_id' => $chk->client_id,
                        'score'     => $chk->risk_score,
                        'level'     => $chk->risk_level,
                    ];
                }
                return [
                    'success' => true,
                    'summary' => count($details) . " check records will be deleted",
                    'count'   => count($details),
                    'details' => $details,
                ];

            case 'bulk_deny':
                $checkIds = array_filter(array_map('intval', explode(',', $ids)));
                if (empty($checkIds)) return ['error' => 'No check IDs'];

                $checks = Capsule::table('mod_fps_checks')
                    ->whereIn('id', $checkIds)
                    ->get(['id', 'client_id', 'risk_score', 'risk_level'])
                    ->toArray();

                $details = [];
                foreach ($checks as $chk) {
                    $details[] = [
                        'id'        => $chk->id,
                        'client_id' => $chk->client_id,
                        'impact'    => "Check #{$chk->id} will be marked as denied (score: {$chk->risk_score})",
                    ];
                }
                return [
                    'success' => true,
                    'summary' => count($details) . " checks will be denied",
                    'count'   => count($details),
                    'details' => $details,
                ];

            default:
                return ['error' => 'Unknown preview action: ' . $previewAction];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// v3.1: CHECK/REPORT MANAGEMENT + CLIENT FORENSICS
// ---------------------------------------------------------------------------

function fps_ajaxDeleteCheck(): array
{
    $checkId = (int)($_REQUEST['check_id'] ?? 0);
    if ($checkId < 1) return ['error' => 'Invalid check ID'];
    try {
        Capsule::table('mod_fps_checks')->where('id', $checkId)->delete();
        logActivity("Fraud Prevention: Check #{$checkId} deleted by admin #{$_SESSION['adminid']}");
        return ['success' => true, 'message' => "Check #{$checkId} deleted"];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxDeleteChecksBulk(): array
{
    $raw = $_REQUEST['check_ids'] ?? '';
    $checkIds = is_array($raw) ? $raw : array_filter(explode(',', (string)$raw), 'strlen');
    if (empty($checkIds)) return ['error' => 'No check IDs provided'];
    try {
        $ids = array_map('intval', $checkIds);
        $deleted = Capsule::table('mod_fps_checks')->whereIn('id', $ids)->delete();
        return ['success' => true, 'deleted' => $deleted];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxClearAllChecks(): array
{
    if (strtolower(trim($_REQUEST['confirm'] ?? '')) !== 'yes') {
        return ['error' => 'Confirmation required. Pass confirm=yes to proceed.'];
    }
    try {
        $count = Capsule::table('mod_fps_checks')->count();
        Capsule::table('mod_fps_checks')->truncate();
        return ['success' => true, 'cleared' => $count];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxDeleteReport(): array
{
    $reportId = (int)($_REQUEST['report_id'] ?? 0);
    if ($reportId < 1) return ['error' => 'Invalid report ID'];
    try {
        Capsule::table('mod_fps_reports')->where('id', $reportId)->delete();
        return ['success' => true, 'message' => "Report #{$reportId} deleted"];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxClearAllReports(): array
{
    if (strtolower(trim($_REQUEST['confirm'] ?? '')) !== 'yes') {
        return ['error' => 'Confirmation required. Pass confirm=yes to proceed.'];
    }
    try {
        $count = Capsule::table('mod_fps_reports')->count();
        Capsule::table('mod_fps_reports')->truncate();
        return ['success' => true, 'cleared' => $count];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxUpdateReportStatus(): array
{
    $reportId = (int)($_REQUEST['report_id'] ?? 0);
    $newStatus = trim($_REQUEST['status'] ?? '');
    if ($reportId < 1) return ['error' => 'Invalid report ID'];
    if (!in_array($newStatus, ['pending', 'confirmed', 'false_positive', 'submitted'], true)) {
        return ['error' => 'Invalid status'];
    }
    try {
        Capsule::table('mod_fps_reports')->where('id', $reportId)->update([
            'status' => $newStatus, 'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['success' => true, 'message' => "Report #{$reportId} marked as {$newStatus}"];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxGetReportDetail(): array
{
    $reportId = (int)($_REQUEST['report_id'] ?? 0);
    if ($reportId < 1) return ['error' => 'Invalid report ID'];

    $report = Capsule::table('mod_fps_reports')->where('id', $reportId)->first();
    if (!$report) return ['error' => 'Report not found'];

    // Get associated check data
    $check = null;
    if ($report->check_id) {
        $check = Capsule::table('mod_fps_checks')->where('id', $report->check_id)->first();
    }

    // Get client info
    $client = null;
    if ($report->client_id) {
        $client = Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'email', 'companyname')
            ->where('id', $report->client_id)->first();
    }

    return [
        'success' => true,
        'report' => (array)$report,
        'check' => $check ? (array)$check : null,
        'client' => $client ? (array)$client : null,
    ];
}

function fps_ajaxRescanClient(): array
{
    $clientId = (int)($_REQUEST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];
    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) return ['error' => 'Client not found'];
    try {
        $runner = new \FraudPreventionSuite\Lib\FpsCheckRunner();
        $result = $runner->runManualCheck($clientId);
        $latestCheck = Capsule::table('mod_fps_checks')
            ->where('client_id', $clientId)->orderByDesc('created_at')->first();
        return ['success' => true, 'message' => 'Rescan complete', 'data' => $latestCheck ? (array)$latestCheck : []];
    } catch (\Throwable $e) { return ['error' => 'Rescan failed: ' . $e->getMessage()]; }
}

function fps_ajaxCrossAccountScan(): array
{
    $clientId = (int)($_REQUEST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];
    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) return ['error' => 'Client not found'];

    $matches = [];
    try {
        // Shared IPs
        $clientIps = Capsule::table('mod_fps_checks')
            ->where('client_id', $clientId)->whereNotNull('ip_address')
            ->distinct()->pluck('ip_address')->toArray();
        if (!empty($clientIps)) {
            $ipMatches = Capsule::table('mod_fps_checks')
                ->whereIn('ip_address', $clientIps)->where('client_id', '!=', $clientId)
                ->join('tblclients', 'tblclients.id', '=', 'mod_fps_checks.client_id')
                ->select('tblclients.id', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email', 'tblclients.status')
                ->distinct()->get()->toArray();
            foreach ($ipMatches as $m) {
                $key = 'c_' . $m->id;
                if (!isset($matches[$key])) {
                    $matches[$key] = ['client_id' => $m->id, 'name' => trim($m->firstname . ' ' . $m->lastname),
                        'email' => $m->email, 'status' => $m->status, 'match_types' => []];
                }
                $matches[$key]['match_types'][] = 'shared_ip';
            }
        }

        // Shared phone
        $phone = preg_replace('/[^0-9]/', '', $client->phonenumber ?? '');
        if (strlen($phone) >= 7) {
            $phoneMatches = Capsule::table('tblclients')->where('id', '!=', $clientId)
                ->whereRaw("REPLACE(REPLACE(REPLACE(phonenumber,' ',''),'-',''),'(','') LIKE ?", ['%' . $phone . '%'])
                ->get(['id', 'firstname', 'lastname', 'email', 'status'])->toArray();
            foreach ($phoneMatches as $m) {
                $key = 'c_' . $m->id;
                if (!isset($matches[$key])) {
                    $matches[$key] = ['client_id' => $m->id, 'name' => trim($m->firstname . ' ' . $m->lastname),
                        'email' => $m->email, 'status' => $m->status, 'match_types' => []];
                }
                $matches[$key]['match_types'][] = 'shared_phone';
            }
        }

        // Shared email domain (non-major providers)
        $emailParts = explode('@', $client->email ?? '');
        if (count($emailParts) === 2) {
            $domain = strtolower($emailParts[1]);
            $majorProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'protonmail.com'];
            if (!in_array($domain, $majorProviders, true)) {
                $domainMatches = Capsule::table('tblclients')->where('id', '!=', $clientId)
                    ->where('email', 'LIKE', '%@' . $domain)->get(['id', 'firstname', 'lastname', 'email', 'status'])->toArray();
                foreach ($domainMatches as $m) {
                    $key = 'c_' . $m->id;
                    if (!isset($matches[$key])) {
                        $matches[$key] = ['client_id' => $m->id, 'name' => trim($m->firstname . ' ' . $m->lastname),
                            'email' => $m->email, 'status' => $m->status, 'match_types' => []];
                    }
                    $matches[$key]['match_types'][] = 'shared_email_domain';
                }
            }
        }

        foreach ($matches as &$m) { $m['match_types'] = array_unique($m['match_types']); $m['match_count'] = count($m['match_types']); }
        unset($m);
        usort($matches, fn($a, $b) => $b['match_count'] - $a['match_count']);

        return ['success' => true, 'client_id' => $clientId, 'matches' => array_values($matches), 'total_matches' => count($matches)];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

function fps_ajaxClientTimeline(): array
{
    $clientId = (int)($_REQUEST['client_id'] ?? 0);
    if ($clientId < 1) return ['error' => 'Invalid client ID'];
    try {
        $events = [];
        $checks = Capsule::table('mod_fps_checks')->where('client_id', $clientId)
            ->orderBy('created_at')->get(['id', 'check_type', 'risk_score', 'risk_level', 'action_taken', 'ip_address', 'created_at']);
        foreach ($checks as $c) {
            $events[] = ['date' => $c->created_at, 'type' => 'check', 'level' => $c->risk_level,
                'title' => ucfirst(str_replace('_', ' ', $c->check_type)) . ' check',
                'detail' => "Score: {$c->risk_score}, Action: {$c->action_taken}", 'score' => (float)$c->risk_score];
        }
        if (Capsule::schema()->hasTable('mod_fps_chargebacks')) {
            foreach (Capsule::table('mod_fps_chargebacks')->where('client_id', $clientId)->get() as $cb) {
                $events[] = ['date' => $cb->created_at, 'type' => 'chargeback', 'level' => 'critical',
                    'title' => 'Chargeback', 'detail' => "\${$cb->amount} via {$cb->gateway}", 'score' => null];
            }
        }
        usort($events, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        $scoreHistory = array_values(array_filter(
            array_map(fn($e) => $e['score'] !== null ? ['date' => $e['date'], 'score' => $e['score']] : null, $events)
        ));
        return ['success' => true, 'events' => array_values($events), 'score_history' => $scoreHistory];
    } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
}

// ---------------------------------------------------------------------------
// v4.0: API KEY VALIDATION & SETUP WIZARD
// ---------------------------------------------------------------------------

function fps_ajaxValidateApiKey(): array
{
    $provider = trim($_REQUEST['provider'] ?? '');
    $apiKey = trim($_REQUEST['api_key'] ?? '');
    $siteKey = trim($_REQUEST['site_key'] ?? '');
    $secretKey = trim($_REQUEST['secret_key'] ?? '');
    if ($provider === '') return ['error' => 'Provider name is required'];
    try {
        switch ($provider) {
            case 'turnstile': return fps_validateTurnstileKeys($siteKey, $secretKey);
            case 'abuseipdb': return fps_validateAbuseIpdbKey($apiKey);
            case 'ipqualityscore': return fps_validateIpqsKey($apiKey);
            case 'fraudrecord': return fps_validateFraudRecordKey($apiKey);
            default: return ['error' => 'Unknown provider: ' . $provider];
        }
    } catch (\Throwable $e) { return ['error' => 'Validation failed: ' . $e->getMessage()]; }
}

function fps_ajaxGetSetupStatus(): array
{
    $config = \FraudPreventionSuite\Lib\FpsConfig::getInstance();
    $providers = [
        ['id' => 'turnstile', 'name' => 'Cloudflare Turnstile', 'icon' => 'fa-shield-halved',
            'description' => 'Invisible bot protection for all forms. FREE.', 'priority' => 'critical',
            'configured' => $config->getCustom('turnstile_site_key', '') !== '' && $config->getCustom('turnstile_secret_key', '') !== '',
            'enabled' => $config->isEnabled('turnstile_enabled'),
            'fields' => [['name' => 'site_key', 'label' => 'Site Key', 'type' => 'text'], ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password']]],
        ['id' => 'abuseipdb', 'name' => 'AbuseIPDB', 'icon' => 'fa-bug',
            'description' => 'Crowd-sourced IP abuse database. 1,000 free checks/day.', 'priority' => 'recommended',
            'configured' => $config->getCustom('abuseipdb_api_key', '') !== '',
            'enabled' => $config->isEnabled('abuseipdb_enabled'),
            'fields' => [['name' => 'api_key', 'label' => 'API Key', 'type' => 'text']]],
        ['id' => 'ipqualityscore', 'name' => 'IPQualityScore', 'icon' => 'fa-magnifying-glass-chart',
            'description' => 'Advanced proxy/VPN/bot detection. 5,000 free/month.', 'priority' => 'recommended',
            'configured' => $config->getCustom('ipqs_api_key', '') !== '',
            'enabled' => $config->isEnabled('ipqs_enabled'),
            'fields' => [['name' => 'api_key', 'label' => 'API Key', 'type' => 'text']]],
        ['id' => 'fraudrecord', 'name' => 'FraudRecord', 'icon' => 'fa-shield-alt',
            'description' => 'Hosting-industry shared fraud database.', 'priority' => 'optional',
            'configured' => $config->get('fraudrecord_api_key', '') !== '',
            'enabled' => $config->isEnabled('provider_fraudrecord'),
            'fields' => [['name' => 'api_key', 'label' => 'API Key', 'type' => 'text']]],
    ];
    $configuredCount = count(array_filter($providers, fn($p) => $p['configured']));
    return ['success' => true, 'providers' => $providers, 'configured_count' => $configuredCount, 'total_count' => count($providers)];
}

function fps_validateTurnstileKeys(string $siteKey, string $secretKey): array
{
    if ($siteKey === '' || $secretKey === '') return ['valid' => false, 'error' => 'Both Site Key and Secret Key are required'];
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['secret' => $secretKey, 'response' => 'DUMMY_VALIDATION_TOKEN']), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $response = curl_exec($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false) return ['valid' => false, 'error' => 'Could not reach Cloudflare API.'];
    $data = json_decode($response, true); $errors = $data['error-codes'] ?? [];
    if ($httpCode === 400 || $httpCode === 403 || in_array('invalid-input-secret', $errors, true))
        return ['valid' => false, 'error' => 'Invalid Secret Key.'];
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'turnstile_site_key'], ['setting_value' => $siteKey]);
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'turnstile_secret_key'], ['setting_value' => $secretKey]);
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'turnstile_enabled'], ['setting_value' => '1']);
    return ['valid' => true, 'message' => 'Turnstile keys validated! Bot protection is now active.'];
}

function fps_validateAbuseIpdbKey(string $apiKey): array
{
    $ch = curl_init('https://api.abuseipdb.com/api/v2/check?' . http_build_query(['ipAddress' => '8.8.8.8', 'maxAgeInDays' => 1]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Key: ' . $apiKey, 'Accept: application/json']]);
    $response = curl_exec($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($httpCode === 401 || $httpCode === 403) return ['valid' => false, 'error' => 'Invalid API key (HTTP ' . $httpCode . ')'];
    if ($httpCode === 429) return ['valid' => false, 'error' => 'Rate limit reached. Key appears valid but daily limit hit.'];
    if ($response === false || $httpCode !== 200) return ['valid' => false, 'error' => 'Could not reach AbuseIPDB (HTTP ' . $httpCode . ')'];
    $data = json_decode($response, true);
    if (!isset($data['data'])) return ['valid' => false, 'error' => 'Unexpected API response'];
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'abuseipdb_api_key'], ['setting_value' => $apiKey]);
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'abuseipdb_enabled'], ['setting_value' => '1']);
    return ['valid' => true, 'message' => 'AbuseIPDB key validated! IP reputation checking active.'];
}

function fps_validateIpqsKey(string $apiKey): array
{
    $ch = curl_init('https://ipqualityscore.com/api/json/ip/' . urlencode($apiKey) . '/8.8.8.8');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $response = curl_exec($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false || $httpCode !== 200) return ['valid' => false, 'error' => 'Could not reach IPQS (HTTP ' . $httpCode . ')'];
    $data = json_decode($response, true);
    if (!is_array($data)) return ['valid' => false, 'error' => 'Invalid IPQS response'];
    $isSuccess = ($data['success'] ?? false);
    $message = $data['message'] ?? '';
    if (!$isSuccess && stripos($message, 'insufficient credits') !== false) {
        Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'ipqs_api_key'], ['setting_value' => $apiKey]);
        Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'ipqs_enabled'], ['setting_value' => '1']);
        return ['valid' => true, 'message' => 'IPQS key valid (insufficient credits -- add credits at ipqualityscore.com).'];
    }
    if (!$isSuccess) return ['valid' => false, 'error' => 'IPQS rejected the key: ' . $message];
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'ipqs_api_key'], ['setting_value' => $apiKey]);
    Capsule::table('mod_fps_settings')->updateOrInsert(['setting_key' => 'ipqs_enabled'], ['setting_value' => '1']);
    return ['valid' => true, 'message' => 'IPQS key validated! Fraud score for test IP: ' . ($data['fraud_score'] ?? 'N/A')];
}

function fps_validateFraudRecordKey(string $apiKey): array
{
    $payload = json_encode(['apiKey' => $apiKey, 'action' => 'query', 'data' => ['email' => sha1('test@example.com')]]);
    $ch = curl_init('https://www.fraudrecord.com/api/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $response = curl_exec($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false) return ['valid' => false, 'error' => 'Could not reach FraudRecord API'];
    $data = json_decode($response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return ['valid' => false, 'error' => 'FraudRecord rejected the key: ' . ($data['error']['message'] ?? 'Unknown')];
    }
    Capsule::table('tbladdonmodules')->updateOrInsert(
        ['module' => 'fraud_prevention_suite', 'setting' => 'fraudrecord_api_key'], ['value' => $apiKey]);
    return ['valid' => true, 'message' => 'FraudRecord key validated!'];
}

/**
 * Gather real anonymized statistics for the public-facing page.
 */
function fps_getPublicStats(): array
{
    try {
        $monthAgo = date('Y-m-d', strtotime('-30 days'));
        $totalChecks = (int)Capsule::table('mod_fps_checks')->count();
        $threatsBlocked = (int)Capsule::table('mod_fps_checks')->whereIn('risk_level', ['high', 'critical'])->count();
        $uniqueIps = (int)Capsule::table('mod_fps_checks')->whereNotNull('ip_address')->distinct()->count('ip_address');
        $countriesMonitored = (int)Capsule::table('mod_fps_checks')->whereNotNull('country')->where('country', '!=', '')->distinct()->count('country');
        $botsDetected = (int)Capsule::table('mod_fps_checks')
            ->where(function($q) { $q->where('check_type', 'bot_detection')->orWhere('check_type', 'bot_signup_block'); })->count();

        // IP intel breakdowns
        $vpnDetected = 0; $torDetected = 0; $proxyDetected = 0; $dcDetected = 0;
        $disposableEmails = 0; $geoMismatches = 0;
        try {
            if (Capsule::schema()->hasTable('mod_fps_ip_intel')) {
                $vpnDetected = (int)Capsule::table('mod_fps_ip_intel')->where('is_vpn', 1)->count();
                $torDetected = (int)Capsule::table('mod_fps_ip_intel')->where('is_tor', 1)->count();
                $proxyDetected = (int)Capsule::table('mod_fps_ip_intel')->where('is_proxy', 1)->count();
                $dcDetected = (int)Capsule::table('mod_fps_ip_intel')->where('is_datacenter', 1)->count();
            }
            if (Capsule::schema()->hasTable('mod_fps_email_intel')) {
                $disposableEmails = (int)Capsule::table('mod_fps_email_intel')->where('is_disposable', 1)->count();
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Risk distribution
        $riskDistribution = [];
        try {
            $riskDistribution = Capsule::table('mod_fps_checks')
                ->select('risk_level', Capsule::raw('COUNT(*) as cnt'))
                ->groupBy('risk_level')
                ->pluck('cnt', 'risk_level')
                ->toArray();
        } catch (\Throwable $e) { /* non-fatal */ }

        // Top countries
        $topCountries = [];
        try {
            $topCountries = Capsule::table('mod_fps_checks')
                ->whereNotNull('country')->where('country', '!=', '')
                ->select('country', Capsule::raw('COUNT(*) as cnt'))
                ->groupBy('country')
                ->orderByDesc('cnt')
                ->limit(10)
                ->pluck('cnt', 'country')
                ->toArray();
        } catch (\Throwable $e) { /* non-fatal */ }

        // Average risk score
        $avgScore = 0;
        try {
            $avgScore = round((float)Capsule::table('mod_fps_checks')->avg('risk_score'), 1);
        } catch (\Throwable $e) { /* non-fatal */ }

        // Global intel stats
        $globalIntelCount = 0; $globalIntelInstances = 0;
        try {
            if (Capsule::schema()->hasTable('mod_fps_global_intel')) {
                $globalIntelCount = (int)Capsule::table('mod_fps_global_intel')->count();
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Enabled providers
        $providerCount = 0;
        $enabledProviders = [];
        $providerChecks = [
            'Turnstile' => 'turnstile_enabled', 'AbuseIPDB' => 'abuseipdb_enabled',
            'IPQualityScore' => 'ipqs_enabled', 'StopForumSpam' => 'abuse_signal_enabled',
            'Domain Reputation' => 'domain_reputation_enabled', 'IP Intelligence' => 'ip_intel_enabled',
            'Email Validation' => 'email_validation_enabled', 'Bot Detection' => 'bot_signup_blocking',
            'Device Fingerprinting' => 'fingerprint_enabled',
        ];
        foreach ($providerChecks as $name => $key) {
            $val = Capsule::table('mod_fps_settings')->where('setting_key', $key)->value('setting_value');
            if ($val === '1') { $providerCount++; $enabledProviders[] = $name; }
        }
        $providerCount += 4; // velocity, geo-impossibility, behavioral, phone

        return [
            'total_checks'        => $totalChecks,
            'threats_blocked'     => $threatsBlocked,
            'unique_ips'          => $uniqueIps,
            'countries_monitored' => $countriesMonitored,
            'bots_detected'       => $botsDetected,
            'provider_count'      => $providerCount,
            'enabled_providers'   => $enabledProviders,
            'vpn_detected'        => $vpnDetected,
            'tor_detected'        => $torDetected,
            'proxy_detected'      => $proxyDetected,
            'datacenter_detected' => $dcDetected,
            'disposable_emails'   => $disposableEmails,
            'risk_distribution'   => $riskDistribution,
            'top_countries'       => $topCountries,
            'avg_risk_score'      => $avgScore,
            'global_intel_count'  => $globalIntelCount,
        ];
    } catch (\Throwable $e) {
        return ['total_checks' => 0, 'threats_blocked' => 0, 'unique_ips' => 0,
            'countries_monitored' => 0, 'bots_detected' => 0, 'provider_count' => 0, 'enabled_providers' => [],
            'vpn_detected' => 0, 'tor_detected' => 0, 'proxy_detected' => 0, 'datacenter_detected' => 0,
            'disposable_emails' => 0, 'risk_distribution' => [], 'top_countries' => [],
            'avg_risk_score' => 0, 'global_intel_count' => 0];
    }
}

// v4.0: GLOBAL INTELLIGENCE AJAX HANDLERS
// ---------------------------------------------------------------------------

/**
 * Toggle global sharing on/off.
 */
function fps_ajaxGlobalIntelToggle(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_fps_global_config')) {
            return ['error' => 'Global config table not found. Please re-activate the module.'];
        }

        $current = Capsule::table('mod_fps_global_config')
            ->where('setting_key', 'global_sharing_enabled')
            ->value('setting_value');

        $newValue = ($current === '1') ? '0' : '1';
        Capsule::table('mod_fps_global_config')
            ->where('setting_key', 'global_sharing_enabled')
            ->update(['setting_value' => $newValue]);

        if ($newValue === '1') {
            // Set consent accepted
            Capsule::table('mod_fps_global_config')
                ->updateOrInsert(
                    ['setting_key' => 'data_consent_accepted'],
                    ['setting_value' => '1']
                );
            // Auto-detect domain from WHMCS SystemURL
            $systemUrl = Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')
                ->value('value') ?? '';
            $domain = parse_url($systemUrl, PHP_URL_HOST) ?: '';
            if ($domain) {
                Capsule::table('mod_fps_global_config')
                    ->updateOrInsert(
                        ['setting_key' => 'instance_domain'],
                        ['setting_value' => $domain]
                    );
            }
        }

        return [
            'success' => true,
            'enabled' => $newValue === '1',
            'message' => $newValue === '1' ? 'Global sharing enabled' : 'Global sharing disabled',
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Register this instance with the hub.
 */
function fps_ajaxGlobalIntelRegister(): array
{
    try {
        $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
        return $client->register();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Manual push of unpushed intel to hub.
 */
function fps_ajaxGlobalIntelPushNow(): array
{
    try {
        $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
        return $client->pushIntel();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Get global intel statistics (local + hub).
 */
function fps_ajaxGlobalIntelStats(): array
{
    try {
        $collector = new \FraudPreventionSuite\Lib\FpsGlobalIntelCollector();
        $localStats = $collector->getStats();

        // Try to get hub stats if connected
        $hubStats = [];
        try {
            $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
            if ($client->isConfigured()) {
                $hubStats = $client->getHubStats();
            }
        } catch (\Throwable $e) {
            // Hub unreachable is non-fatal
        }

        return [
            'success'   => true,
            'local'     => $localStats,
            'hub'       => $hubStats,
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Browse local intel with pagination and filters.
 */
function fps_ajaxGlobalIntelBrowse(): array
{
    try {
        $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
        $filters = [
            'risk_level' => $_GET['risk_level'] ?? $_POST['risk_level'] ?? '',
            'source'     => $_GET['source'] ?? $_POST['source'] ?? '',
            'search'     => $_GET['search'] ?? $_POST['search'] ?? '',
            'date_from'  => $_GET['date_from'] ?? $_POST['date_from'] ?? '',
            'date_to'    => $_GET['date_to'] ?? $_POST['date_to'] ?? '',
        ];

        $collector = new \FraudPreventionSuite\Lib\FpsGlobalIntelCollector();
        $result = $collector->browse($page, 25, $filters);
        $result['success'] = true;

        return $result;
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Save global intel settings.
 */
function fps_ajaxGlobalIntelSaveSettings(): array
{
    try {
        $settings = [
            'hub_url'              => trim($_POST['hub_url'] ?? ''),
            'share_ip_addresses'   => ($_POST['share_ip'] ?? '0') === '1' ? '1' : '0',
            'auto_push_enabled'    => ($_POST['auto_push'] ?? '0') === '1' ? '1' : '0',
            'auto_pull_on_signup'  => ($_POST['auto_pull'] ?? '0') === '1' ? '1' : '0',
            'intel_retention_days' => max(30, min(3650, (int)($_POST['retention'] ?? 365))),
        ];

        foreach ($settings as $key => $value) {
            Capsule::table('mod_fps_global_config')
                ->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => (string)$value]
                );
        }

        return ['success' => true, 'message' => 'Settings saved'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Purge local intel and/or hub contributions (GDPR).
 */
function fps_ajaxGlobalIntelPurge(): array
{
    try {
        $target = $_GET['target'] ?? $_POST['target'] ?? 'local';

        $result = ['success' => true];

        if ($target === 'local' || $target === 'all') {
            $collector = new \FraudPreventionSuite\Lib\FpsGlobalIntelCollector();
            $deleted = $collector->purgeAll();
            $result['local_deleted'] = $deleted;
        }

        if ($target === 'hub' || $target === 'all') {
            try {
                $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
                $hubResult = $client->purgeContributions();
                $result['hub_purged'] = $hubResult['success'] ?? false;
            } catch (\Throwable $e) {
                $result['hub_error'] = $e->getMessage();
            }
        }

        return $result;
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Export all local intel as JSON download.
 */
function fps_ajaxGlobalIntelExport(): void
{
    try {
        $collector = new \FraudPreventionSuite\Lib\FpsGlobalIntelCollector();
        $data = $collector->exportAll();

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="fps-global-intel-export-' . date('Y-m-d') . '.json"');
        echo json_encode([
            'exported_at' => date('Y-m-d H:i:s'),
            'record_count' => count($data),
            'records' => $data,
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// v4.1: GDPR DATA REMOVAL REQUEST SYSTEM
// ---------------------------------------------------------------------------

/**
 * PUBLIC: Submit a GDPR data removal request (no admin auth).
 */
function fps_ajaxGdprSubmitRequest(): array
{
    try {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'A valid email address is required'];
        }

        $emailHash = hash('sha256', $email);

        // Check if this email exists in our fraud intel
        $exists = Capsule::table('mod_fps_global_intel')
            ->where('email_hash', $emailHash)
            ->exists();

        if (!$exists) {
            return ['success' => true, 'message' => 'No data found for this email address in our system. No action needed.'];
        }

        // Check for existing pending request
        $existingRequest = Capsule::table('mod_fps_gdpr_requests')
            ->where('email_hash', $emailHash)
            ->whereIn('status', ['pending', 'verified'])
            ->first();

        if ($existingRequest) {
            return ['success' => true, 'message' => 'A removal request for this email is already pending review. Reference: #' . $existingRequest->id];
        }

        // Create verification token
        $token = bin2hex(random_bytes(32));

        // Insert request
        $requestId = Capsule::table('mod_fps_gdpr_requests')->insertGetId([
            'email'              => $email,
            'email_hash'         => $emailHash,
            'name'               => $name,
            'reason'             => $reason,
            'verification_token' => $token,
            'email_verified'     => 0,
            'status'             => 'pending',
            'ip_address'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Send verification email via WHMCS
        try {
            $systemUrl = Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')->value('value') ?? '';
            $verifyUrl = rtrim($systemUrl, '/') . '/index.php?m=fraud_prevention_suite&page=gdpr-verify&ajax=1&token=' . urlencode($token);

            $hostname = parse_url($systemUrl, PHP_URL_HOST) ?: 'localhost';
            $subject = 'Verify Your Data Removal Request - Reference #' . $requestId;
            $body = "You (or someone using your email) submitted a data removal request.\n\n"
                . "To verify this is you, please visit this link:\n"
                . $verifyUrl . "\n\n"
                . "If you did not make this request, please ignore this email.\n\n"
                . "Reference: #" . $requestId . "\n"
                . "This link expires in 72 hours.\n";

            $headers = "From: noreply@{$hostname}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            @mail($email, $subject, $body, $headers);
        } catch (\Throwable $e) {
            // Email send failure is non-fatal -- admin can verify manually
        }

        return [
            'success' => true,
            'message' => 'Request submitted (Reference #' . $requestId . '). Please check your email to verify your identity. The link expires in 72 hours.',
            'request_id' => $requestId,
        ];
    } catch (\Throwable $e) {
        return ['error' => 'Failed to submit request: ' . $e->getMessage()];
    }
}

/**
 * PUBLIC: Verify email ownership for a GDPR request (clicked from email link).
 */
function fps_ajaxGdprVerifyEmail(): array
{
    try {
        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        if (empty($token) || strlen($token) !== 64) {
            return ['error' => 'Invalid verification token'];
        }

        $request = Capsule::table('mod_fps_gdpr_requests')
            ->where('verification_token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$request) {
            return ['error' => 'Token not found or already used. The request may have expired or been processed.'];
        }

        // Check 72-hour expiry
        $created = strtotime($request->created_at);
        if ((time() - $created) > 259200) { // 72 hours
            Capsule::table('mod_fps_gdpr_requests')
                ->where('id', $request->id)
                ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
            return ['error' => 'This verification link has expired. Please submit a new request.'];
        }

        // Mark as verified
        Capsule::table('mod_fps_gdpr_requests')
            ->where('id', $request->id)
            ->update([
                'email_verified' => 1,
                'status' => 'verified',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'success' => true,
            'message' => 'Email verified. Your request (Reference #' . $request->id . ') is now pending admin review. You will be notified when it is processed.',
        ];
    } catch (\Throwable $e) {
        return ['error' => 'Verification failed'];
    }
}

/**
 * ADMIN: Get all GDPR removal requests with pagination.
 */
function fps_ajaxGdprGetRequests(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_fps_gdpr_requests')) {
            return ['success' => true, 'requests' => [], 'total' => 0];
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $status = $_GET['status'] ?? '';

        $query = Capsule::table('mod_fps_gdpr_requests')->orderByDesc('created_at');
        if ($status !== '' && in_array($status, ['pending', 'verified', 'approved', 'denied', 'completed'], true)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->toArray();

        // Enrich with intel record count
        foreach ($requests as &$r) {
            $r = (array)$r;
            $r['intel_records'] = Capsule::table('mod_fps_global_intel')
                ->where('email_hash', $r['email_hash'])
                ->count();
        }

        return [
            'success' => true,
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * ADMIN: Approve or deny a GDPR removal request.
 * Approve = delete all matching intel records from local DB + request hub purge.
 */
function fps_ajaxGdprReviewRequest(): array
{
    try {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = $_POST['review_action'] ?? ''; // approve or deny
        $notes = trim($_POST['admin_notes'] ?? '');

        if ($requestId < 1) return ['error' => 'Invalid request ID'];
        if (!in_array($action, ['approve', 'deny'], true)) return ['error' => 'Action must be approve or deny'];

        $request = Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->first();
        if (!$request) return ['error' => 'Request not found'];

        if ($action === 'deny') {
            Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->update([
                'status' => 'denied',
                'reviewed_by' => (int)$_SESSION['adminid'],
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['success' => true, 'message' => 'Request #' . $requestId . ' denied.'];
        }

        // APPROVE: Delete all matching intel records
        $deleted = Capsule::table('mod_fps_global_intel')
            ->where('email_hash', $request->email_hash)
            ->delete();

        // Also try to purge from hub
        $hubPurged = false;
        try {
            $client = new \FraudPreventionSuite\Lib\FpsGlobalIntelClient();
            if ($client->isConfigured()) {
                // Hub purge removes all records matching this email hash
                $hubResult = $client->purgeContributions();
                $hubPurged = $hubResult['success'] ?? false;
            }
        } catch (\Throwable $e) {
            // Hub purge failure is non-fatal
        }

        Capsule::table('mod_fps_gdpr_requests')->where('id', $requestId)->update([
            'status' => 'completed',
            'reviewed_by' => (int)$_SESSION['adminid'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'admin_notes' => $notes,
            'records_purged' => $deleted,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        logActivity("FPS GDPR: Request #{$requestId} approved by admin #{$_SESSION['adminid']}. {$deleted} local records purged. Hub purge: " . ($hubPurged ? 'yes' : 'no'));

        return [
            'success' => true,
            'message' => "Request #{$requestId} approved. {$deleted} local records purged." . ($hubPurged ? ' Hub data also purged.' : ''),
            'records_purged' => $deleted,
            'hub_purged' => $hubPurged,
        ];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

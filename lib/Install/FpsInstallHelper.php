<?php
/**
 * FpsInstallHelper -- module activation helpers extracted from
 * fraud_prevention_suite.php to keep the main file under control.
 *
 * Holds installation-time helpers that should not pollute the main
 * module file. Functions remain in the global namespace (no class
 * wrapper, no PSR-4 conversion) so existing call sites continue to
 * work without modification. The main file simply require_once's
 * this file at load time.
 *
 * Closes part of TODO-hardening.md item #4.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

if (!function_exists('fps_createDefaultProducts')) {
    /**
     * Auto-create FPS API products and product group in WHMCS.
     * Idempotent: skips if products already exist.
     */
    function fps_createDefaultProducts(): array
    {
        $created = 0;

        try {
            // Resolve the WHMCS default currency ID (do NOT hardcode to 1).
            // Resolution is centralised in FpsHookHelpers::fps_resolveDefaultCurrencyId()
            // so all callers share one canonical implementation.
            $defaultCurrency = \FraudPreventionSuite\Lib\FpsHookHelpers::fps_resolveDefaultCurrencyId();

            // Create product group
            $group = Capsule::table('tblproductgroups')->where('name', 'Fraud Intelligence API')->first();
            $groupId = $group->id ?? 0;

            if ($groupId === 0) {
                $insertData = [
                    'name'             => 'Fraud Intelligence API',
                    'headline'         => 'Real-time fraud detection and threat intelligence API',
                    'tagline'          => 'Protect your business with enterprise-grade fraud prevention',
                    'orderfrmtpl'      => '',
                    'disabledgateways' => '',
                    'hidden'           => 0,
                    'order'            => 0,
                ];
                // Set slug if column exists (WHMCS 8.x+)
                if (Capsule::schema()->hasColumn('tblproductgroups', 'slug')) {
                    $insertData['slug'] = 'fraud-intelligence-api';
                }
                $groupId = Capsule::table('tblproductgroups')->insertGetId($insertData);
            }

            // Ensure group slug is set (may be missing on existing groups)
            try {
                if (Capsule::schema()->hasColumn('tblproductgroups', 'slug')) {
                    $currentSlug = Capsule::table('tblproductgroups')->where('id', $groupId)->value('slug');
                    if (empty($currentSlug)) {
                        Capsule::table('tblproductgroups')->where('id', $groupId)->update(['slug' => 'fraud-intelligence-api']);
                    }
                }
            } catch (\Throwable $e) {}

            if ($groupId <= 0) return ['created' => 0, 'error' => 'Failed to create product group'];

            // Shared CSS injected once for all product descriptions
            $css = '<style>.fps-prod{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:left;line-height:1.6;}'
                . '.fps-prod .fp-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.04em;margin-bottom:10px;}'
                . '.fps-prod .fp-feat{list-style:none;padding:0;margin:8px 0;}'
                . '.fps-prod .fp-feat li{padding:5px 0 5px 22px;position:relative;font-size:.88rem;border-bottom:1px solid rgba(255,255,255,.04);}'
                . '.fps-prod .fp-feat li:last-child{border:none;}'
                . '.fps-prod .fp-feat li::before{content:"\\2713";position:absolute;left:0;color:#38ef7d;font-weight:700;}'
                . '.fps-prod .fp-ep{list-style:none;padding:0;margin:6px 0;font-family:monospace;font-size:.78rem;}'
                . '.fps-prod .fp-ep li{padding:3px 0;}'
                . '.fps-prod .fp-ep .m{color:#38ef7d;font-weight:700;}'
                . '.fps-prod .fp-ep .mp{color:#f5c842;font-weight:700;}'
                . '.fps-prod .fp-sec{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin:14px 0 6px;padding-top:10px;border-top:1px solid rgba(255,255,255,.06);}'
                . '.fps-prod .fp-rate{display:flex;gap:6px;justify-content:center;margin:10px 0;flex-wrap:wrap;}'
                . '.fps-prod .fp-rate span{padding:4px 12px;border-radius:6px;font-size:.78rem;font-weight:700;background:rgba(102,126,234,.12);border:1px solid rgba(102,126,234,.25);}'
                . '.fps-prod .fp-note{font-size:.75rem;opacity:.5;margin-top:10px;text-align:center;}'
                . '</style>';

            // Product definitions
            $products = [
                [
                    'name'  => 'FPS API - Free Tier',
                    'tier'  => 'free',
                    'price' => '0.00',
                    'desc'  => $css . '<div class="fps-prod">'
                        . '<div class="fp-badge" style="background:rgba(56,239,125,.15);color:#38ef7d;">STARTER</div>'
                        . '<p style="font-size:.9rem;opacity:.8;">Real-time fraud detection at no cost. Perfect for development and evaluation.</p>'
                        . '<div class="fp-rate"><span>30 req/min</span><span>5,000 req/day</span></div>'
                        . '<div class="fp-sec" style="color:#38ef7d;">Included Features</div>'
                        . '<ul class="fp-feat">'
                        . '<li>Global fraud statistics dashboard</li>'
                        . '<li>Threat topology visualization</li>'
                        . '<li>RESTful JSON API</li>'
                        . '<li>Rate limit headers on every response</li>'
                        . '<li>Client area usage dashboard</li>'
                        . '</ul>'
                        . '<div class="fp-sec" style="color:#667eea;">API Endpoints</div>'
                        . '<ul class="fp-ep">'
                        . '<li><span class="m">GET</span> /v1/stats/global</li>'
                        . '<li><span class="m">GET</span> /v1/topology/hotspots</li>'
                        . '</ul>'
                        . '<div class="fp-note">Code examples in cURL, PHP, Python &amp; JavaScript</div>'
                        . '</div>',
                ],
                [
                    'name'  => 'FPS API - Basic',
                    'tier'  => 'basic',
                    'price' => '19.00',
                    'desc'  => '<div class="fps-prod">'
                        . '<div class="fp-badge" style="background:rgba(102,126,234,.15);color:#667eea;">MOST POPULAR</div>'
                        . '<p style="font-size:.9rem;opacity:.8;">Full IP &amp; email intelligence for active fraud prevention. Ideal for hosting, e-commerce, and SaaS.</p>'
                        . '<div class="fp-rate"><span>120 req/min</span><span>50K req/day</span></div>'
                        . '<div class="fp-sec" style="color:#667eea;">Everything in Free +</div>'
                        . '<ul class="fp-feat">'
                        . '<li>IP threat intel (VPN, Tor, proxy, datacenter)</li>'
                        . '<li>IP geolocation (country, city, ISP, ASN)</li>'
                        . '<li>Email validation &amp; disposable detection</li>'
                        . '<li>Real-time fraud event feed</li>'
                        . '<li>Domain age &amp; reputation checks</li>'
                        . '</ul>'
                        . '<div class="fp-sec" style="color:#667eea;">Additional Endpoints</div>'
                        . '<ul class="fp-ep">'
                        . '<li><span class="m">GET</span> /v1/lookup/ip-basic</li>'
                        . '<li><span class="m">GET</span> /v1/lookup/email-basic</li>'
                        . '<li><span class="m">GET</span> /v1/topology/events</li>'
                        . '</ul>'
                        . '<div class="fp-sec" style="color:#667eea;">Use Cases</div>'
                        . '<ul class="fp-feat">'
                        . '<li>Block fraud IPs at signup</li>'
                        . '<li>Validate emails at checkout</li>'
                        . '<li>Monitor geographic patterns</li>'
                        . '<li>Enrich CRM with threat data</li>'
                        . '</ul>'
                        . '<div class="fp-note">Email support &middot; 24h response</div>'
                        . '</div>',
                ],
                [
                    'name'  => 'FPS API - Premium',
                    'tier'  => 'premium',
                    'price' => '99.00',
                    'desc'  => '<div class="fps-prod">'
                        . '<div class="fp-badge" style="background:rgba(255,215,0,.15);color:#ffd700;">ENTERPRISE</div>'
                        . '<p style="font-size:.9rem;opacity:.8;">Complete fraud intelligence suite with bulk ops, deep analysis, and priority support for enterprises &amp; MSPs.</p>'
                        . '<div class="fp-rate"><span style="border-color:rgba(255,215,0,.3);">600 req/min</span><span style="border-color:rgba(255,215,0,.3);">500K req/day</span></div>'
                        . '<div class="fp-sec" style="color:#ffd700;">Everything in Basic +</div>'
                        . '<ul class="fp-feat">'
                        . '<li>Full IP dossier (abuse history, risk scoring)</li>'
                        . '<li>Full email analysis (breaches, social, SMTP)</li>'
                        . '<li>Bulk lookup (100 items per request)</li>'
                        . '<li>Country-level fraud analytics</li>'
                        . '<li>Webhook notifications for high-risk</li>'
                        . '<li>Custom rate limit overrides</li>'
                        . '</ul>'
                        . '<div class="fp-sec" style="color:#ffd700;">Additional Endpoints</div>'
                        . '<ul class="fp-ep">'
                        . '<li><span class="m">GET</span> /v1/lookup/ip-full</li>'
                        . '<li><span class="m">GET</span> /v1/lookup/email-full</li>'
                        . '<li><span class="mp">POST</span> /v1/lookup/bulk</li>'
                        . '<li><span class="m">GET</span> /v1/reports/country/{CC}</li>'
                        . '</ul>'
                        . '<div class="fp-sec" style="color:#ffd700;">Enterprise</div>'
                        . '<ul class="fp-feat">'
                        . '<li>IP whitelist per API key</li>'
                        . '<li>Per-key custom rate limits</li>'
                        . '<li>99.9% uptime SLA</li>'
                        . '<li>Priority support (4h response)</li>'
                        . '</ul>'
                        . '<div class="fp-note">Dedicated account manager for annual plans</div>'
                        . '</div>',
                ],
            ];

            foreach ($products as $p) {
                // Skip if already exists
                if (Capsule::table('tblproducts')->where('name', $p['name'])->exists()) {
                    continue;
                }

                $pid = Capsule::table('tblproducts')->insertGetId([
                    'type'              => 'other',
                    'gid'               => $groupId,
                    'name'              => $p['name'],
                    'description'       => $p['desc'],
                    'hidden'            => 0,
                    'showdomainoptions' => 0,
                    'paytype'           => 'recurring',
                    'autosetup'         => 'order',
                    'servertype'        => 'fps_api',
                    'configoption1'     => $p['tier'],
                    'order'             => 0,
                    'retired'           => 0,
                    'is_featured'       => 0,
                    'stockcontrol'      => 0,
                ]);

                if ($pid > 0) {
                    // Set pricing (monthly only, other cycles disabled).
                    // Currency ID resolved from tblcurrencies default above.
                    Capsule::table('tblpricing')->insertOrIgnore([
                        'type'         => 'product',
                        'currency'     => $defaultCurrency,
                        'relid'        => $pid,
                        'msetupfee'    => '0.00',
                        'qsetupfee'    => '0.00',
                        'ssetupfee'    => '0.00',
                        'asetupfee'    => '0.00',
                        'bsetupfee'    => '0.00',
                        'tsetupfee'    => '0.00',
                        'monthly'      => $p['price'],
                        'quarterly'    => '-1.00',
                        'semiannually' => '-1.00',
                        'annually'     => '-1.00',
                        'biennially'   => '-1.00',
                        'triennially'  => '-1.00',
                    ]);

                    // Set product slug for SEO-friendly URLs
                    $slug = 'fps-api-' . $p['tier'];
                    try {
                        if (Capsule::schema()->hasColumn('tblproducts', 'slug')) {
                            Capsule::table('tblproducts')->where('id', $pid)->update(['slug' => $slug]);
                        }
                        // Also set in tblproducts_slugs if table exists (WHMCS 8.x with Lagom2)
                        if (Capsule::schema()->hasTable('tblproducts_slugs')) {
                            Capsule::table('tblproducts_slugs')->insertOrIgnore([
                                'product_id' => $pid,
                                'slug'       => $slug,
                                'group_slug' => 'fraud-intelligence-api',
                                'group_id'   => $groupId,
                                'active'     => 1,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        // Slug setting is non-fatal
                    }

                    $created++;
                }
            }

            return ['created' => $created];
        } catch (\Throwable $e) {
            logModuleCall('fraud_prevention_suite', 'CreateProducts', '', $e->getMessage());
            return ['created' => $created, 'error' => $e->getMessage()];
        }
    }
}

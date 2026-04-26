<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * FpsAnalyticsWizard -- 7-step modal scaffold for the analytics setup wizard.
 *
 * Renders a single static modal containing all 7 steps. Step visibility,
 * navigation, validation, auto-discovery and save are driven by the
 * companion JS at assets/js/fps-analytics-wizard.js. State lives entirely
 * in the browser -- there is no server-side wizard session.
 *
 * The modal id is fps-analytics-wizard. The "Run setup wizard" link in
 * the analytics card calls FpsAdmin.openModal('fps-analytics-wizard').
 */
class FpsAnalyticsWizard
{
    /**
     * Render the wizard modal HTML. Embed the result somewhere always-rendered
     * on the Settings tab (TabSettings::render appends it after the form).
     *
     * @return string Self-contained HTML (modal shell + 7 step divs + footer).
     */
    public static function render(): string
    {
        $stepStyle = '<style>'
            . '#fps-analytics-wizard .fps-wizard-step:not(.fps-wizard-active){display:none;}'
            . '#fps-analytics-wizard .fps-wiz-error{display:none;color:#b00020;background:#fdecea;border:1px solid #f5c6cb;padding:8px 12px;border-radius:4px;margin:8px 0;font-size:0.9em;}'
            . '#fps-analytics-wizard .fps-wiz-error.fps-wiz-error-show{display:block;}'
            . '#fps-analytics-wizard .fps-wizard-step h4{margin-top:0;}'
            . '#fps-analytics-wizard label{display:block;margin:10px 0 4px;font-weight:600;}'
            . '#fps-analytics-wizard input[type=text],#fps-analytics-wizard input[type=email],#fps-analytics-wizard textarea,#fps-analytics-wizard select{width:100%;padding:6px 8px;box-sizing:border-box;}'
            . '#fps-analytics-wizard textarea{min-height:120px;font-family:monospace;font-size:0.85em;}'
            . '#fps-analytics-wizard .fps-wiz-toggle-row{padding:10px 0;border-bottom:1px solid #eee;}'
            . '#fps-analytics-wizard .fps-wiz-help{color:#666;font-size:0.9em;margin:4px 0 0;}'
            . '#fps-analytics-wizard table.fps-wiz-summary-table{width:100%;border-collapse:collapse;}'
            . '#fps-analytics-wizard table.fps-wiz-summary-table th,#fps-analytics-wizard table.fps-wiz-summary-table td{border:1px solid #ddd;padding:6px 10px;text-align:left;font-size:0.9em;}'
            . '#fps-analytics-wizard table.fps-wiz-summary-table th{background:#f7f7f7;width:40%;}'
            . '#fps-analytics-wizard .fps-wiz-success{padding:14px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724;font-weight:600;}'
            . '</style>';

        $saPlaceholder = '{&quot;type&quot;:&quot;service_account&quot;,&quot;project_id&quot;:&quot;...&quot;,&quot;client_email&quot;:&quot;...&quot;,&quot;private_key&quot;:&quot;-----BEGIN PRIVATE KEY-----...&quot;}';

        $body = $stepStyle;

        // Step 1: Welcome + scope picker
        $body .= '<div class="fps-wizard-step fps-wizard-active" data-step="1">'
            . '<h4>Welcome -- pick what to enable</h4>'
            . '<p>This wizard walks you through Google Analytics 4 + Microsoft Clarity setup in 7 steps. Each toggle below corresponds to one of the three master switches in Settings.</p>'
            . '<div class="fps-wiz-toggle-row">'
            . '<label><input type="checkbox" id="fps-wiz-enable-client" checked> Client-side tracking (storefront + cart)</label>'
            . '<p class="fps-wiz-help">Injects gtag.js / Clarity into the public storefront. Required for funnel analytics.</p></div>'
            . '<div class="fps-wiz-toggle-row">'
            . '<label><input type="checkbox" id="fps-wiz-enable-admin"> Admin-side tracking (FPS admin tabs)</label>'
            . '<p class="fps-wiz-help">Tracks staff usage of the FPS admin UI. Useful for measuring rule-tuning effort.</p></div>'
            . '<div class="fps-wiz-toggle-row">'
            . '<label><input type="checkbox" id="fps-wiz-enable-server"> Server-side custom events (Measurement Protocol)</label>'
            . '<p class="fps-wiz-help">Sends fraud-decision + ban events to GA4 directly from PHP. Requires API secret + (optionally) Service Account JSON for property auto-discovery.</p></div>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-1-err"></div>'
            . '</div>';

        // Step 2: GA4 client measurement ID
        $body .= '<div class="fps-wizard-step" data-step="2">'
            . '<h4>GA4 measurement ID</h4>'
            . '<p><a href="https://analytics.google.com/analytics/web/" target="_blank" rel="noopener">Open GA4 console &#x2197;</a> &rarr; Admin (gear icon) &rarr; Data streams &rarr; (your stream) &rarr; Measurement ID.</p>'
            . '<label for="fps-wiz-ga4-id-client">GA4 measurement ID (client)</label>'
            . '<input type="text" id="fps-wiz-ga4-id-client" placeholder="G-XXXXXXXXXX" autocomplete="off">'
            . '<p class="fps-wiz-help">Must match <code>^G-[A-Z0-9]{8,12}$</code>. Example: <code>G-AB12CD34EF</code>.</p>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-2-err"></div>'
            . '</div>';

        // Step 3: GA4 server-side credentials
        $body .= '<div class="fps-wizard-step" data-step="3">'
            . '<h4>GA4 server-side credentials</h4>'
            . '<p>Required only if you enabled server-side events in step 1. Skip otherwise.</p>'
            . '<label for="fps-wiz-ga4-secret">Measurement Protocol API secret</label>'
            . '<input type="text" id="fps-wiz-ga4-secret" placeholder="(treat as a password)" autocomplete="off">'
            . '<label for="fps-wiz-ga4-property-id">GA4 property ID (numeric)</label>'
            . '<input type="text" id="fps-wiz-ga4-property-id" placeholder="123456789" autocomplete="off">'
            . '<p class="fps-wiz-help">Or paste a Service Account JSON below + click Discover to auto-fill.</p>'
            . '<label for="fps-wiz-ga4-sa-json">Service Account JSON (optional, for auto-discovery)</label>'
            . '<textarea id="fps-wiz-ga4-sa-json" placeholder="' . $saPlaceholder . '" autocomplete="off"></textarea>'
            . '<button type="button" id="fps-wiz-discover-btn" class="fps-btn fps-btn-sm fps-btn-secondary">Discover properties</button>'
            . '<label for="fps-wiz-ga4-property-select" style="margin-top:12px;">Discovered properties</label>'
            . '<select id="fps-wiz-ga4-property-select"><option value="">(none discovered yet)</option></select>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-3-err"></div>'
            . '</div>';

        // Step 4: Clarity client project ID
        $body .= '<div class="fps-wizard-step" data-step="4">'
            . '<h4>Microsoft Clarity project ID</h4>'
            . '<p><a href="https://clarity.microsoft.com/" target="_blank" rel="noopener">Open Clarity dashboard &#x2197;</a> &rarr; Settings &rarr; Setup &rarr; Project ID.</p>'
            . '<label for="fps-wiz-clarity-id-client">Clarity project ID</label>'
            . '<input type="text" id="fps-wiz-clarity-id-client" placeholder="abcdef1234" autocomplete="off">'
            . '<p class="fps-wiz-help">Must match <code>^[a-z0-9]{8,12}$</code>. Leave blank to skip Clarity entirely.</p>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-4-err"></div>'
            . '</div>';

        // Step 5: Consent + privacy
        $body .= '<div class="fps-wizard-step" data-step="5">'
            . '<h4>Consent &amp; privacy</h4>'
            . '<div class="fps-wiz-toggle-row">'
            . '<label><input type="checkbox" id="fps-wiz-eea-required" checked> Require EEA consent (Consent Mode v2 default-deny)</label>'
            . '<p class="fps-wiz-help">If enabled, no analytics cookies fire until the visitor accepts the consent banner.</p></div>'
            . '<p><a href="https://support.google.com/analytics/answer/9012600" target="_blank" rel="noopener">Sign GA4 DPA &#x2197;</a> &mdash; required before enabling for EEA visitors.</p>'
            . '<p><a href="https://www.microsoft.com/licensing/docs/view/Microsoft-Products-and-Services-Data-Protection-Addendum-DPA" target="_blank" rel="noopener">Sign Clarity DPA &#x2197;</a> &mdash; same requirement, separate Microsoft contract.</p>'
            . '<p class="fps-wiz-help"><strong>Limitations:</strong> GA4 supports manual user-data deletion via the GA4 console (not API). Clarity does NOT expose any DSR/deletion API as of 2026 -- deletion is via the dashboard UI only. Plan your DSR workflow accordingly.</p>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-5-err"></div>'
            . '</div>';

        // Step 6: Optional extras
        $body .= '<div class="fps-wizard-step" data-step="6">'
            . '<h4>Optional extras</h4>'
            . '<label for="fps-wiz-notify-email">Admin email for anomaly alerts (optional)</label>'
            . '<input type="email" id="fps-wiz-notify-email" placeholder="admin@example.com" autocomplete="off">'
            . '<label for="fps-wiz-sampling-rate">Server event sampling rate (1-100, default 100)</label>'
            . '<input type="text" id="fps-wiz-sampling-rate" placeholder="100" value="100" autocomplete="off">'
            . '<label for="fps-wiz-high-risk-threshold">High-risk signup threshold (0-100, default 80)</label>'
            . '<input type="text" id="fps-wiz-high-risk-threshold" placeholder="80" value="80" autocomplete="off">'
            . '<div class="fps-wiz-error" id="fps-wiz-step-6-err"></div>'
            . '</div>';

        // Step 7: Save + verify
        $body .= '<div class="fps-wizard-step" data-step="7">'
            . '<h4>Review &amp; save</h4>'
            . '<p>Confirm these values, then click Save.</p>'
            . '<div id="fps-wiz-summary"><em>(summary will appear here)</em></div>'
            . '<div style="margin-top:16px;">'
            . '<button type="button" id="fps-wiz-save-btn" class="fps-btn fps-btn-md fps-btn-success">'
            . '<i class="fas fa-save"></i> Save settings</button></div>'
            . '<div id="fps-wiz-save-result" style="margin-top:12px;"></div>'
            . '<div class="fps-wiz-error" id="fps-wiz-step-7-err"></div>'
            . '</div>';

        $footer = '<div style="display:flex;justify-content:space-between;align-items:center;width:100%;gap:12px;">'
            . '<span id="fps-wiz-step-counter" style="color:#666;">Step 1 of 7</span>'
            . '<div>'
            . '<button type="button" id="fps-wiz-back-btn" class="fps-btn fps-btn-sm fps-btn-ghost">Back</button> '
            . '<button type="button" id="fps-wiz-skip-btn" class="fps-btn fps-btn-sm fps-btn-ghost">Skip step</button> '
            . '<button type="button" id="fps-wiz-next-btn" class="fps-btn fps-btn-sm fps-btn-primary">Next</button>'
            . '</div></div>';

        return FpsAdminRenderer::renderModal('fps-analytics-wizard', 'Analytics Setup Wizard', $body, $footer);
    }
}

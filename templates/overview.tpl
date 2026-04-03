{literal}
<style>
:root {
  --fps-pub-bg: #ffffff;
  --fps-pub-text: #1a1a2e;
  --fps-pub-text-secondary: #555;
  --fps-pub-text-muted: #888;
  --fps-pub-card-bg: #ffffff;
  --fps-pub-card-border: #e8ecf4;
  --fps-pub-card-shadow: rgba(0,0,0,0.08);
  --fps-pub-input-bg: #ffffff;
  --fps-pub-input-border: #ddd;
  --fps-pub-table-header: #f8f9fc;
  --fps-pub-table-border: #f0f0f5;
  --fps-pub-code-bg: #1a1a2e;
  --fps-pub-code-text: #b0b8d1;
}
.fps-dark-mode {
  --fps-pub-bg: #1a1a3e;
  --fps-pub-text: #f0f0ff;
  --fps-pub-text-secondary: #dde4ff;
  --fps-pub-text-muted: #b8c0e0;
  --fps-pub-card-bg: #232350;
  --fps-pub-card-border: #3a3a6e;
  --fps-pub-card-shadow: rgba(0,0,0,0.4);
  --fps-pub-input-bg: #232350;
  --fps-pub-input-border: #4a4a7e;
  --fps-pub-table-header: #2a2a5e;
  --fps-pub-table-border: #3a3a6e;
  --fps-pub-code-bg: #0a0820;
  --fps-pub-code-text: #c8d0e8;
}
.fps-dark-mode .fps-pub-provider-badge{color:#c8d0ff;border-color:#667eea55;}
.fps-pub{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:inherit;line-height:1.7;}
.fps-pub *{box-sizing:border-box;}
.fps-pub-hero{background:linear-gradient(135deg,#0f0c29 0%,#1a1a3e 50%,#302b63 100%);color:#fff;padding:60px 30px;text-align:center;border-radius:16px;margin-bottom:32px;position:relative;overflow:hidden;}
.fps-pub-hero::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 30% 40%,rgba(102,126,234,0.12),transparent 60%),radial-gradient(ellipse at 70% 60%,rgba(118,75,162,0.08),transparent 50%);pointer-events:none;}
.fps-pub-hero h1{font-size:2.4rem;font-weight:800;margin:0 0 12px;letter-spacing:-0.5px;position:relative;z-index:1;}
.fps-pub-hero h1 i{color:#667eea;margin-right:10px;}
.fps-pub-hero p{font-size:1.15rem;color:#b0b8d1;margin:0 auto 28px;max-width:700px;position:relative;z-index:1;}
.fps-pub-hero .fps-pub-version{background:rgba(102,126,234,0.2);border:1px solid rgba(102,126,234,0.3);border-radius:20px;padding:4px 16px;font-size:0.85rem;color:#a0b0ff;display:inline-block;margin-bottom:16px;position:relative;z-index:1;}
.fps-pub-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;margin-bottom:40px;}
.fps-pub-stat{background:var(--fps-pub-card-bg);border-radius:12px;padding:28px 24px;text-align:center;box-shadow:0 4px 16px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);transition:transform 0.2s,box-shadow 0.2s;}
.fps-pub-stat:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(102,126,234,0.15);}
.fps-pub-stat-value{font-size:2.2rem;font-weight:800;color:var(--fps-pub-text);display:block;}
.fps-pub-stat-label{font-size:1rem;color:#dde4ff !important;text-transform:uppercase;letter-spacing:0.3px;margin-top:8px;display:block;font-weight:700;}
.fps-pub-stat-value.danger{color:#eb3349;}
.fps-pub-stat-value.primary{color:#667eea;}
.fps-pub-stat-value.success{color:#11998e;}
.fps-pub-section{margin-bottom:40px;}
.fps-pub-section h2{font-size:1.6rem;font-weight:700;color:inherit;margin:0 0 8px;display:flex;align-items:center;gap:10px;}
.fps-pub-section h2 i{color:#667eea;}
.fps-pub-section p.subtitle{font-size:1rem;color:inherit;opacity:0.7;margin:0 0 24px;}
.fps-pub-features{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
.fps-pub-feature{background:var(--fps-pub-card-bg);border-radius:12px;padding:24px;box-shadow:0 2px 8px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);color:var(--fps-pub-text);}
.fps-pub-feature h3{font-size:1.2rem;font-weight:800;margin:0 0 10px;display:flex;align-items:center;gap:10px;color:#ffffff !important;}
.fps-pub-feature h3 i{color:#88a4ff;width:22px;text-align:center;font-size:1.1rem;}
.fps-pub-feature p{font-size:1rem;color:#dde4ff !important;margin:0;line-height:1.6;}
.fps-pub-tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px;margin-bottom:40px;}
.fps-pub-tier{background:var(--fps-pub-card-bg);border-radius:16px;padding:32px 24px;box-shadow:0 4px 16px var(--fps-pub-card-shadow);border:2px solid var(--fps-pub-card-border);text-align:center;transition:border-color 0.3s,transform 0.2s;color:var(--fps-pub-text);}
.fps-pub-tier:hover{transform:translateY(-4px);}
.fps-pub-tier.featured{border-color:#667eea;box-shadow:0 8px 32px rgba(102,126,234,0.2);}
.fps-pub-tier h3{font-size:1.4rem;font-weight:800;margin:0 0 4px;color:#ffffff !important;}
.fps-pub-tier .price{font-size:2rem;font-weight:800;color:#667eea;margin:12px 0;}
.fps-pub-tier .price span{font-size:0.85rem;color:var(--fps-pub-text-muted);font-weight:400;}
.fps-pub-tier .price.free{color:#11998e;}
.fps-pub-tier ul{list-style:none;padding:0;margin:16px 0 24px;text-align:left;}
.fps-pub-tier ul li{padding:8px 0;font-size:1rem;color:#dde4ff !important;border-bottom:1px solid var(--fps-pub-table-border);display:flex;align-items:center;gap:8px;}
.fps-pub-tier ul li i.check{color:#11998e;}
.fps-pub-tier ul li i.cross{color:#ccc;}
.fps-pub-tier .tier-btn{display:inline-block;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.95rem;transition:all 0.2s;}
.fps-pub-tier .tier-btn.primary{background:#667eea;color:#fff;}
.fps-pub-tier .tier-btn.primary:hover{background:#5a6fd6;}
.fps-pub-tier .tier-btn.outline{background:var(--fps-pub-card-bg);color:#667eea;border:2px solid #667eea;}
.fps-pub-tier .tier-btn.outline:hover{background:#667eea;color:#fff;}
.fps-pub-providers{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}
.fps-pub-provider-badge{background:linear-gradient(135deg,#667eea22,#764ba222);border:1px solid #667eea44;border-radius:20px;padding:6px 16px;font-size:0.85rem;color:inherit;font-weight:600;opacity:0.85;}
.fps-pub-endpoint{background:var(--fps-pub-table-header);border:1px solid var(--fps-pub-card-border);border-radius:10px;padding:16px 20px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.fps-pub-endpoint .method{background:#11998e;color:#fff;padding:3px 10px;border-radius:4px;font-size:0.75rem;font-weight:700;font-family:monospace;min-width:50px;text-align:center;}
.fps-pub-endpoint .method.post{background:#667eea;}
.fps-pub-endpoint .method.delete{background:#eb3349;}
.fps-pub-endpoint .path{font-family:'Fira Code',monospace;font-size:0.9rem;color:var(--fps-pub-text);flex:1;}
.fps-pub-endpoint .desc{font-size:0.82rem;color:var(--fps-pub-text-muted);}
.fps-pub-endpoint .tier-badge{background:#667eea22;color:#667eea;padding:2px 8px;border-radius:4px;font-size:0.72rem;font-weight:700;}
.fps-pub-endpoint .tier-badge.free{background:#11998e22;color:#11998e;}
.fps-pub-endpoint .tier-badge.premium{background:#eb334922;color:#eb3349;}
.fps-pub-cta{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-align:center;padding:48px 30px;border-radius:16px;margin-top:40px;}
.fps-pub-cta h2{font-size:1.8rem;font-weight:800;margin:0 0 12px;color:#fff;}
.fps-pub-cta p{font-size:1.05rem;color:#d0d8ff;margin:0 auto 24px;max-width:600px;}
.fps-pub-cta a{display:inline-block;padding:14px 32px;background:#fff;color:#667eea;border-radius:10px;font-weight:700;text-decoration:none;transition:all 0.2s;}
.fps-pub-cta a:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
@media(max-width:768px){.fps-pub-hero h1{font-size:1.6rem;}.fps-pub-hero p{font-size:1rem;}.fps-pub-stat-value{font-size:1.5rem;}}
</style>
<script>(function(){document.body.classList.add('fps-dark-mode');})();</script>
{/literal}

<div class="fps-pub">

    {* === HERO SECTION === *}
    <div class="fps-pub-hero">
        <div class="fps-pub-version">v{$module_version} -- Enterprise Fraud Intelligence</div>
        <h1><i class="fas fa-shield-halved"></i> Fraud Prevention Suite</h1>
        <p>Enterprise-grade fraud detection platform with {$stats.provider_count}+ detection engines, real-time bot blocking, device fingerprinting, and shared global threat intelligence.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;position:relative;z-index:1;">
            <a href="{$store_url}" style="padding:12px 28px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:10px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(102,126,234,0.3);">
                <i class="fas fa-rocket"></i> Get API Access
            </a>
            <a href="{$topology_url}" style="padding:12px 24px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:10px;font-weight:600;text-decoration:none;">
                <i class="fas fa-globe"></i> Live Threat Map
            </a>
            <a href="{$api_docs_url}" style="padding:12px 24px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:10px;font-weight:600;text-decoration:none;">
                <i class="fas fa-code"></i> API Documentation
            </a>
            <a href="{$gdpr_url}" style="padding:12px 24px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:10px;font-weight:600;text-decoration:none;">
                <i class="fas fa-user-shield"></i> Data Removal
            </a>
        </div>
    </div>

    {* === LIVE STATS === *}
    <div class="fps-pub-section">
        <h2 style="color:#fff;font-size:1.6rem;"><i class="fas fa-chart-bar" style="color:#88a4ff;"></i> Live Platform Statistics</h2>
        <p class="subtitle" style="color:#b8c0e0;font-size:1rem;">Real-time data from our fraud detection infrastructure.</p>
    </div>

    <div class="fps-pub-stats">
        <div class="fps-pub-stat"><span class="fps-pub-stat-value primary">{$stats.total_checks|number_format}</span><span class="fps-pub-stat-label">Total Fraud Checks</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value danger">{$stats.threats_blocked|number_format}</span><span class="fps-pub-stat-label">Threats Blocked</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value success">{$stats.unique_ips|number_format}</span><span class="fps-pub-stat-label">Unique IPs Analyzed</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value primary">{$stats.countries_monitored}</span><span class="fps-pub-stat-label">Countries Monitored</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value danger">{$stats.bots_detected|number_format}</span><span class="fps-pub-stat-label">Bots Detected</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value success">{$stats.provider_count}</span><span class="fps-pub-stat-label">Detection Engines</span></div>
    </div>

    {* === ANONYMITY DETECTION STATS === *}
    <div class="fps-pub-section">
        <h2 style="color:#fff;font-size:1.6rem;"><i class="fas fa-mask" style="color:#88a4ff;"></i> Anonymity Network Detection</h2>
        <p class="subtitle" style="color:#b8c0e0;font-size:1rem;">Real-time detection of VPNs, Tor exit nodes, proxies, and datacenter IPs.</p>
    </div>

    <div class="fps-pub-stats">
        <div class="fps-pub-stat"><span class="fps-pub-stat-value" style="color:#667eea;">{$stats.vpn_detected|number_format}</span><span class="fps-pub-stat-label">VPNs Detected</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value danger">{$stats.tor_detected|number_format}</span><span class="fps-pub-stat-label">Tor Exit Nodes</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value" style="color:#f5a623;">{$stats.proxy_detected|number_format}</span><span class="fps-pub-stat-label">Proxies Flagged</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value danger">{$stats.datacenter_detected|number_format}</span><span class="fps-pub-stat-label">Datacenter IPs</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value" style="color:#f5a623;">{$stats.disposable_emails|number_format}</span><span class="fps-pub-stat-label">Disposable Emails</span></div>
        <div class="fps-pub-stat"><span class="fps-pub-stat-value primary">{$stats.global_intel_count|number_format}</span><span class="fps-pub-stat-label">Global Intel Records</span></div>
    </div>

    {* === DETECTION ENGINES === *}
    <div class="fps-pub-section">
        <h2 style="color:#fff;font-size:1.6rem;"><i class="fas fa-puzzle-piece" style="color:#88a4ff;"></i> Detection Engines</h2>
        <p class="subtitle" style="color:#b8c0e0;font-size:1rem;">Multi-layered fraud detection combining {$stats.provider_count}+ independent intelligence sources.</p>
    </div>

    <div class="fps-pub-features">
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-shield-halved" style="color:#88a4ff;"></i> Cloudflare Turnstile</h3><p style="color:#dde4ff;font-size:1rem;">Invisible bot challenge on all forms. Blocks automated signups without CAPTCHAs. Zero friction.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-fingerprint" style="color:#88a4ff;"></i> Device Fingerprinting</h3><p style="color:#dde4ff;font-size:1rem;">Canvas, WebGL, font, screen, timezone, and audio fingerprinting. Links fraud accounts across sessions.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-robot" style="color:#88a4ff;"></i> Bot Pattern Detection</h3><p style="color:#dde4ff;font-size:1rem;">Plus-addressing, SMS gateways, disposable emails, numeric locals, signup velocity. 15-pattern engine.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-network-wired" style="color:#88a4ff;"></i> IP Intelligence</h3><p style="color:#dde4ff;font-size:1rem;">Multi-source: ip-api.com, AbuseIPDB, IPQualityScore. Proxy, VPN, Tor, and datacenter detection.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-users-between-lines" style="color:#88a4ff;"></i> 15-Dimension Duplicate Detection</h3><p style="color:#dde4ff;font-size:1rem;">IP subnet, email base, phone, fingerprint, geo-mismatch, name, signup timing linkage.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-globe" style="color:#88a4ff;"></i> Geographic Analysis</h3><p style="color:#dde4ff;font-size:1rem;">Cross-correlates IP, billing country, phone prefix, BIN country. Detects impossible travel patterns.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-gauge-high" style="color:#88a4ff;"></i> Behavioral Biometrics</h3><p style="color:#dde4ff;font-size:1rem;">Mouse movement entropy, keystroke cadence, form fill speed, paste detection.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-bolt" style="color:#88a4ff;"></i> Velocity Engine</h3><p style="color:#dde4ff;font-size:1rem;">Rate limiting: orders/IP, registrations/IP, failed payments, checkout attempts, BIN reuse.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#fff;font-size:1.2rem;font-weight:800;"><i class="fas fa-earth-americas" style="color:#88a4ff;"></i> Global Threat Intel</h3><p style="color:#dde4ff;font-size:1rem;">Cross-instance fraud sharing. SHA-256 hashed data with GDPR compliance. Hub-and-spoke architecture.</p></div>
    </div>

    {* === Active providers === *}
    <div class="fps-pub-section" style="margin-top:24px;">
        <strong>Active Intelligence Sources:</strong>
        <div class="fps-pub-providers">
            {foreach from=$stats.enabled_providers item=provider}
                <span class="fps-pub-provider-badge"><i class="fas fa-check-circle"></i> {$provider}</span>
            {/foreach}
            <span class="fps-pub-provider-badge"><i class="fas fa-check-circle"></i> Velocity Engine</span>
            <span class="fps-pub-provider-badge"><i class="fas fa-check-circle"></i> Geo-Impossibility</span>
            <span class="fps-pub-provider-badge"><i class="fas fa-check-circle"></i> Behavioral Scoring</span>
            <span class="fps-pub-provider-badge"><i class="fas fa-check-circle"></i> Phone Validation</span>
        </div>
    </div>

    {* === API TIERS === *}
    <div class="fps-pub-section" style="margin-top:48px;color:#fff;">
        <h2><i class="fas fa-key"></i> API Access Tiers</h2>
        <p class="subtitle">Integrate fraud intelligence into your applications with our REST API.</p>
    </div>

    <div class="fps-pub-tiers">
        <div class="fps-pub-tier">
            <h3>Community</h3>
            <div class="price free">Free <span>forever</span></div>
            <ul>
                <li><i class="fas fa-check check"></i> Global threat statistics</li>
                <li><i class="fas fa-check check"></i> Threat topology hotspots</li>
                <li><i class="fas fa-check check"></i> 5 requests/minute</li>
                <li><i class="fas fa-check check"></i> 100 requests/day</li>
                <li><i class="fas fa-times cross"></i> IP/Email lookups</li>
                <li><i class="fas fa-times cross"></i> Bulk queries</li>
            </ul>
            <a href="{$api_docs_url}" class="tier-btn outline">Get Started</a>
        </div>
        <div class="fps-pub-tier">
            <h3>Free</h3>
            <div class="price free">Free <span>with API key</span></div>
            <ul>
                <li><i class="fas fa-check check"></i> Everything in Community</li>
                <li><i class="fas fa-check check"></i> Basic IP lookups</li>
                <li><i class="fas fa-check check"></i> Topology event feed</li>
                <li><i class="fas fa-check check"></i> 30 requests/minute</li>
                <li><i class="fas fa-times cross"></i> Full lookups</li>
                <li><i class="fas fa-times cross"></i> Bulk queries</li>
            </ul>
            <a href="{$store_url}" class="tier-btn outline">Get Started</a>
        </div>
        <div class="fps-pub-tier featured">
            <h3>Basic</h3>
            <div class="price">$19 <span>/month</span></div>
            <ul>
                <li><i class="fas fa-check check"></i> Everything in Free</li>
                <li><i class="fas fa-check check"></i> Full IP intelligence</li>
                <li><i class="fas fa-check check"></i> Email validation</li>
                <li><i class="fas fa-check check"></i> 120 requests/minute</li>
                <li><i class="fas fa-check check"></i> 50,000 requests/day</li>
                <li><i class="fas fa-times cross"></i> Bulk queries</li>
            </ul>
            <a href="{$store_url}" class="tier-btn primary">Get API Key</a>
        </div>
        <div class="fps-pub-tier">
            <h3>Premium</h3>
            <div class="price">$99 <span>/month</span></div>
            <ul>
                <li><i class="fas fa-check check"></i> Everything in Basic</li>
                <li><i class="fas fa-check check"></i> Full email intelligence</li>
                <li><i class="fas fa-check check"></i> Bulk IP/email lookups</li>
                <li><i class="fas fa-check check"></i> Country-level reports</li>
                <li><i class="fas fa-check check"></i> 600 requests/minute</li>
                <li><i class="fas fa-check check"></i> 500,000 requests/day</li>
            </ul>
            <a href="{$store_url}" class="tier-btn outline">Go Premium</a>
        </div>
    </div>

    {* === API ENDPOINTS === *}
    <div class="fps-pub-section" id="api-docs">
        <h2><i class="fas fa-code"></i> API Endpoints</h2>
        <p class="subtitle">RESTful JSON API. Authenticate via <code>X-FPS-API-Key</code> header. All responses include request timing and rate limit headers.</p>
        <p class="subtitle" style="margin-top:-16px;font-size:0.9rem;">Base URL: <code>https://your-whmcs.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=</code></p>
    </div>

    <h3 style="margin:0 0 12px;color:#667eea;"><i class="fas fa-chart-line"></i> Statistics & Topology</h3>

    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/stats/global</span>
        <span class="tier-badge free">Anonymous+</span>
        <span class="desc">Platform-wide aggregated statistics (30-day window)</span>
    </div>
    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/topology/hotspots</span>
        <span class="tier-badge free">Anonymous+</span>
        <span class="desc">Geographic threat heatmap data with lat/lng clustering</span>
    </div>
    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/topology/events</span>
        <span class="tier-badge">Free+</span>
        <span class="desc">Anonymized real-time event feed with risk levels</span>
    </div>

    <h3 style="margin:24px 0 12px;color:#667eea;"><i class="fas fa-search"></i> IP Intelligence</h3>

    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/lookup/ip-basic</span>
        <span class="tier-badge">Free+</span>
        <span class="desc">Basic IP check: country, proxy, VPN, Tor, datacenter, threat score</span>
    </div>
    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/lookup/ip-full</span>
        <span class="tier-badge">Basic+</span>
        <span class="desc">Full IP intel: ASN, ISP, region, city, lat/lng, proxy type, cached_at</span>
    </div>

    <h3 style="margin:24px 0 12px;color:#667eea;"><i class="fas fa-envelope"></i> Email Intelligence</h3>

    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/lookup/email-basic</span>
        <span class="tier-badge">Basic+</span>
        <span class="desc">Email validation: disposable, free provider, role account, MX valid, domain age</span>
    </div>
    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/lookup/email-full</span>
        <span class="tier-badge premium">Premium</span>
        <span class="desc">Full email intel: breach count, social presence, deliverability score, social profiles</span>
    </div>

    <h3 style="margin:24px 0 12px;color:#667eea;"><i class="fas fa-layer-group"></i> Bulk & Reports</h3>

    <div class="fps-pub-endpoint">
        <span class="method post">POST</span>
        <span class="path">/v1/lookup/bulk</span>
        <span class="tier-badge premium">Premium</span>
        <span class="desc">Batch IP/email lookups (up to 100 items per request)</span>
    </div>
    <div class="fps-pub-endpoint">
        <span class="method">GET</span>
        <span class="path">/v1/reports/country/{literal}{CC}{/literal}</span>
        <span class="tier-badge premium">Premium</span>
        <span class="desc">Per-country threat statistics with daily breakdown</span>
    </div>

    {* === AUTHENTICATION & RESPONSE FORMAT === *}
    <div class="fps-pub-section" style="margin-top:40px;">
        <h2><i class="fas fa-lock"></i> Authentication & Response Format</h2>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <div style="background:var(--fps-pub-table-header);border:1px solid var(--fps-pub-card-border);border-radius:10px;padding:20px;">
            <h4 style="margin:0 0 8px;"><i class="fas fa-key"></i> Authentication</h4>
            <pre style="background:var(--fps-pub-code-bg);color:var(--fps-pub-code-text);padding:12px;border-radius:8px;font-size:0.85rem;overflow-x:auto;margin:0;">curl -H "X-FPS-API-Key: YOUR_KEY" \
  "https://your-whmcs.com/.../api.php?endpoint=/v1/stats/global"</pre>
            <p style="font-size:0.85rem;color:var(--fps-pub-text-secondary);margin:8px 0 0;">Anonymous endpoints require no key. Others require the <code>X-FPS-API-Key</code> header.</p>
        </div>
        <div style="background:var(--fps-pub-table-header);border:1px solid var(--fps-pub-card-border);border-radius:10px;padding:20px;">
            <h4 style="margin:0 0 8px;"><i class="fas fa-code"></i> Response Format</h4>
            <pre style="background:var(--fps-pub-code-bg);color:var(--fps-pub-code-text);padding:12px;border-radius:8px;font-size:0.85rem;overflow-x:auto;margin:0;">{literal}{
  "success": true,
  "data": { ... },
  "meta": {
    "request_id": "fps_a1b2c3d4",
    "tier": "basic",
    "rate_limit": { "remaining": 118, "limit": 120 },
    "response_time_ms": 42
  }
}{/literal}</pre>
        </div>
    </div>

    <div style="margin-top:20px;background:var(--fps-pub-table-header);border:1px solid var(--fps-pub-card-border);border-radius:10px;padding:20px;">
        <h4 style="margin:0 0 8px;"><i class="fas fa-gauge-simple-high"></i> Rate Limit Headers</h4>
        <p style="font-size:0.9rem;color:var(--fps-pub-text-secondary);margin:0;">All responses include: <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, and <code>Retry-After</code> (on 429). Rate limits are per API key (or per IP for anonymous).</p>
    </div>

    {* === CTA === *}
    <div class="fps-pub-cta">
        <h2>Protect Your Business Today</h2>
        <p>Join hosting providers using Fraud Prevention Suite to block bots, detect fraud, and protect revenue with shared global intelligence.</p>
        <a href="{$topology_url}"><i class="fas fa-globe"></i> Explore Live Threat Map</a>
    </div>

</div>

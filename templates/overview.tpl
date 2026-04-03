{literal}
<style>
/* FPS Overview - EVPS 1000X Light Palette */
/* Navy #0f172a headings, Slate #334155 body, Green #16a34a primary, Blue #2563eb accent */
:root {
  --fps-pub-bg: #f8fafc;
  --fps-pub-text: #334155;
  --fps-pub-text-secondary: #475569;
  --fps-pub-text-muted: #64748b;
  --fps-pub-card-bg: #ffffff;
  --fps-pub-card-border: #e2e8f0;
  --fps-pub-card-shadow: rgba(15,23,42,0.06);
  --fps-pub-input-bg: #ffffff;
  --fps-pub-input-border: #cbd5e1;
  --fps-pub-table-header: #f8fafc;
  --fps-pub-table-border: #f1f5f9;
  --fps-pub-code-bg: #0f172a;
  --fps-pub-code-text: #e2e8f0;
}
.fps-pub{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#334155;line-height:1.7;}
.fps-pub *{box-sizing:border-box;}

/* Hero - dark gradient (looks great on light page) */
.fps-pub-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#16a34a 150%);color:#fff;padding:60px 30px;text-align:center;border-radius:20px;margin-bottom:36px;position:relative;overflow:hidden;box-shadow:0 12px 40px rgba(15,23,42,0.12);}
.fps-pub-hero::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 30% 40%,rgba(22,163,74,0.1),transparent 60%),radial-gradient(ellipse at 70% 60%,rgba(37,99,235,0.08),transparent 50%);pointer-events:none;}
.fps-pub-hero h1{font-size:2.4rem;font-weight:900;margin:0 0 12px;letter-spacing:-0.5px;position:relative;z-index:1;color:#fff;}
.fps-pub-hero h1 i{color:#4ade80;margin-right:10px;}
.fps-pub-hero p{font-size:1.15rem;color:rgba(255,255,255,0.75);margin:0 auto 28px;max-width:700px;position:relative;z-index:1;}
.fps-pub-hero .fps-pub-version{background:rgba(22,163,74,0.2);border:1px solid rgba(22,163,74,0.3);border-radius:20px;padding:4px 16px;font-size:0.85rem;color:#86efac;display:inline-block;margin-bottom:16px;position:relative;z-index:1;}

/* Stat cards - white with green accent hover */
.fps-pub-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;margin-bottom:40px;}
.fps-pub-stat{background:#fff;border-radius:14px;padding:28px 24px;text-align:center;box-shadow:0 2px 8px rgba(15,23,42,0.06);border:1px solid #e2e8f0;transition:transform 0.2s,box-shadow 0.2s;}
.fps-pub-stat:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(22,163,74,0.1);}
.fps-pub-stat-value{font-size:2.2rem;font-weight:900;color:#0f172a;display:block;}
.fps-pub-stat-label{font-size:0.8rem;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-top:8px;display:block;font-weight:700;}
.fps-pub-stat-value.danger{color:#ef4444;}
.fps-pub-stat-value.primary{color:#2563eb;}
.fps-pub-stat-value.success{color:#16a34a;}

/* Section headings */
.fps-pub-section{margin-bottom:40px;}
.fps-pub-section h2{font-size:1.6rem;font-weight:800;color:#0f172a;margin:0 0 8px;display:flex;align-items:center;gap:10px;}
.fps-pub-section h2 i{color:#16a34a;}
.fps-pub-section p.subtitle{font-size:1rem;color:#64748b;margin:0 0 24px;}

/* Detection engine cards - white with green hover border */
.fps-pub-features{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
.fps-pub-feature{background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(15,23,42,0.04);border:1px solid #e2e8f0;transition:border-color 0.25s,transform 0.2s,box-shadow 0.2s;}
.fps-pub-feature:hover{border-color:#16a34a;transform:translateY(-2px);box-shadow:0 6px 20px rgba(22,163,74,0.08);}
.fps-pub-feature h3{font-size:1.1rem;font-weight:800;margin:0 0 10px;display:flex;align-items:center;gap:10px;color:#0f172a !important;}
.fps-pub-feature h3 i{color:#16a34a;width:22px;text-align:center;font-size:1.1rem;}
.fps-pub-feature p{font-size:0.92rem;color:#475569 !important;margin:0;line-height:1.6;}

/* API tier cards */
.fps-pub-tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px;margin-bottom:40px;}
.fps-pub-tier{background:#fff;border-radius:16px;padding:32px 24px;box-shadow:0 2px 8px rgba(15,23,42,0.06);border:2px solid #e2e8f0;border-top:3px solid #cbd5e1;text-align:center;transition:border-color 0.3s,transform 0.2s;}
.fps-pub-tier:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(15,23,42,0.08);}
.fps-pub-tier.featured{border-color:#16a34a;border-top-color:#16a34a;box-shadow:0 4px 16px rgba(22,163,74,0.1);}
.fps-pub-tier h3{font-size:1.4rem;font-weight:800;margin:0 0 4px;color:#0f172a !important;}
.fps-pub-tier .price{font-size:2rem;font-weight:900;color:#16a34a;margin:12px 0;}
.fps-pub-tier .price span{font-size:0.85rem;color:#94a3b8;font-weight:400;}
.fps-pub-tier .price.free{color:#16a34a;}
.fps-pub-tier ul{list-style:none;padding:0;margin:16px 0 24px;text-align:left;}
.fps-pub-tier ul li{padding:8px 0;font-size:0.92rem;color:#475569 !important;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px;}
.fps-pub-tier ul li i.check{color:#16a34a;}
.fps-pub-tier ul li i.cross{color:#cbd5e1;}
.fps-pub-tier .tier-btn{display:inline-block;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:0.95rem;transition:all 0.2s;}
.fps-pub-tier .tier-btn.primary{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,0.3);}
.fps-pub-tier .tier-btn.primary:hover{box-shadow:0 6px 20px rgba(22,163,74,0.4);transform:translateY(-1px);}
.fps-pub-tier .tier-btn.outline{background:#fff;color:#334155;border:2px solid #e2e8f0;}
.fps-pub-tier .tier-btn.outline:hover{border-color:#16a34a;color:#16a34a;}

/* Provider badges */
.fps-pub-providers{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}
.fps-pub-provider-badge{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:20px;padding:6px 16px;font-size:0.85rem;color:#16a34a;font-weight:700;}

/* API endpoints */
.fps-pub-endpoint{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;transition:background 0.2s;}
.fps-pub-endpoint:hover{background:#f0fdf4;}
.fps-pub-endpoint .method{background:#16a34a;color:#fff;padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:700;font-family:monospace;min-width:50px;text-align:center;}
.fps-pub-endpoint .method.post{background:#2563eb;}
.fps-pub-endpoint .method.delete{background:#ef4444;}
.fps-pub-endpoint .path{font-family:'Fira Code','JetBrains Mono',monospace;font-size:0.9rem;color:#0f172a;flex:1;font-weight:600;}
.fps-pub-endpoint .desc{font-size:0.82rem;color:#64748b;}
.fps-pub-endpoint .tier-badge{background:#f0fdf4;color:#16a34a;padding:2px 10px;border-radius:6px;font-size:0.72rem;font-weight:700;border:1px solid #bbf7d0;}
.fps-pub-endpoint .tier-badge.free{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.fps-pub-endpoint .tier-badge.premium{background:#fef2f2;color:#ef4444;border-color:#fecaca;}

/* Bottom CTA */
.fps-pub-cta{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;text-align:center;padding:52px 36px;border-radius:20px;margin-top:40px;box-shadow:0 8px 32px rgba(15,23,42,0.2);}
.fps-pub-cta h2{font-size:1.8rem;font-weight:900;margin:0 0 12px;color:#fff;}
.fps-pub-cta p{font-size:1.05rem;color:rgba(255,255,255,0.7);margin:0 auto 24px;max-width:600px;}
.fps-pub-cta a{display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:10px;font-weight:700;text-decoration:none;transition:all 0.2s;box-shadow:0 4px 14px rgba(22,163,74,0.3);}
.fps-pub-cta a:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(22,163,74,0.4);}

/* Code blocks (auth & response format) */
.fps-pub pre,.fps-pub code{background:#0f172a;color:#e2e8f0;border-radius:10px;font-family:'Fira Code','JetBrains Mono',monospace;}

/* Page navigation bar */
.fps-pub-nav{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:36px;padding:16px 0;border-bottom:1px solid #e2e8f0;}
.fps-pub-nav a{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:10px;font-size:0.88rem;font-weight:600;text-decoration:none;color:#334155;background:#fff;border:1px solid #e2e8f0;transition:all 0.2s;}
.fps-pub-nav a:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4;transform:translateY(-1px);}
.fps-pub-nav a.active{background:#16a34a;color:#fff;border-color:#16a34a;}
.fps-pub-nav a i{font-size:0.9rem;}

@media(max-width:768px){.fps-pub-hero h1{font-size:1.6rem;}.fps-pub-hero p{font-size:1rem;}.fps-pub-stat-value{font-size:1.5rem;}.fps-pub-nav{gap:6px;}.fps-pub-nav a{padding:6px 12px;font-size:0.8rem;}}

/* Colorblind mode: swap ALL green to blue within FPS overview */
body.cb-mode .fps-pub-stat-value.success{color:#2563eb!important}
body.cb-mode .fps-pub-section h2 i{color:#2563eb!important}
body.cb-mode .fps-pub-feature:hover{border-color:#2563eb!important;box-shadow:0 6px 20px rgba(37,99,235,0.08)!important}
body.cb-mode .fps-pub-feature h3 i{color:#2563eb!important}
body.cb-mode .fps-pub-tier.featured{border-color:#2563eb!important;border-top-color:#2563eb!important;box-shadow:0 4px 16px rgba(37,99,235,0.1)!important}
body.cb-mode .fps-pub-tier .price,body.cb-mode .fps-pub-tier .price.free{color:#2563eb!important}
body.cb-mode .fps-pub-tier ul li i.check{color:#2563eb!important}
body.cb-mode .fps-pub-tier .tier-btn.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 14px rgba(37,99,235,0.3)!important}
body.cb-mode .fps-pub-tier .tier-btn.outline:hover{border-color:#2563eb!important;color:#2563eb!important}
body.cb-mode .fps-pub-provider-badge{background:#eff6ff!important;border-color:#bfdbfe!important;color:#2563eb!important}
body.cb-mode .fps-pub-endpoint .method{background:#2563eb!important}
body.cb-mode .fps-pub-endpoint .tier-badge{background:#eff6ff!important;color:#2563eb!important;border-color:#bfdbfe!important}
body.cb-mode .fps-pub-endpoint:hover{background:#eff6ff!important}
body.cb-mode .fps-pub-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#2563eb 150%)!important}
body.cb-mode .fps-pub-cta{background:linear-gradient(135deg,#0f172a,#1e3a5f)!important}
body.cb-mode .fps-pub-cta a{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 14px rgba(37,99,235,0.3)!important}
body.cb-mode .fps-pub-nav a.active{background:#2563eb!important;border-color:#2563eb!important}
body.cb-mode .fps-pub-nav a:hover{border-color:#2563eb!important;color:#2563eb!important;background:#eff6ff!important}
body.cb-mode .fps-pub-hero .fps-pub-version{background:rgba(37,99,235,0.2)!important;border-color:rgba(37,99,235,0.3)!important}
</style>
{/literal}

<div class="fps-pub">

    {* === HERO SECTION === *}
    <div class="fps-pub-hero">
        <div class="fps-pub-version">v{$module_version} -- Enterprise Fraud Intelligence</div>
        <h1><i class="fas fa-shield-halved"></i> Fraud Prevention Suite</h1>
        <p>Enterprise-grade fraud detection platform with {$stats.provider_count}+ detection engines, real-time bot blocking, device fingerprinting, and shared global threat intelligence.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;position:relative;z-index:1;">
            <a href="{$store_url}" style="padding:12px 28px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:10px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(22,163,74,0.3);">
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

    {* === PAGE NAVIGATION === *}
    <div class="fps-pub-nav">
        <a href="index.php?m=fraud_prevention_suite" class="active"><i class="fas fa-chart-line"></i> Overview</a>
        <a href="{$store_url}"><i class="fas fa-shopping-cart"></i> API Plans & Pricing</a>
        <a href="{$api_docs_url}"><i class="fas fa-book"></i> API Documentation</a>
        <a href="{$topology_url}"><i class="fas fa-globe"></i> Live Threat Map</a>
        <a href="index.php?m=fraud_prevention_suite&page=global"><i class="fas fa-network-wired"></i> Global Intel</a>
        <a href="{$gdpr_url}"><i class="fas fa-user-shield"></i> Data Removal</a>
    </div>

    {* === LIVE STATS === *}
    <div class="fps-pub-section">
        <h2 style="color:#0f172a;font-size:1.6rem;"><i class="fas fa-chart-bar" style="color:#16a34a;"></i> Live Platform Statistics</h2>
        <p class="subtitle" style="color:#64748b;font-size:1rem;">Real-time data from our fraud detection infrastructure.</p>
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
        <h2 style="color:#0f172a;font-size:1.6rem;"><i class="fas fa-mask" style="color:#16a34a;"></i> Anonymity Network Detection</h2>
        <p class="subtitle" style="color:#64748b;font-size:1rem;">Real-time detection of VPNs, Tor exit nodes, proxies, and datacenter IPs.</p>
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
        <h2 style="color:#0f172a;font-size:1.6rem;"><i class="fas fa-puzzle-piece" style="color:#16a34a;"></i> Detection Engines</h2>
        <p class="subtitle" style="color:#64748b;font-size:1rem;">Multi-layered fraud detection combining {$stats.provider_count}+ independent intelligence sources.</p>
    </div>

    <div class="fps-pub-features">
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-shield-halved" style="color:#16a34a;"></i> Cloudflare Turnstile</h3><p style="color:#475569;font-size:0.92rem;">Invisible bot challenge on all forms. Blocks automated signups without CAPTCHAs. Zero friction.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-fingerprint" style="color:#16a34a;"></i> Device Fingerprinting</h3><p style="color:#475569;font-size:0.92rem;">Canvas, WebGL, font, screen, timezone, and audio fingerprinting. Links fraud accounts across sessions.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-robot" style="color:#16a34a;"></i> Bot Pattern Detection</h3><p style="color:#475569;font-size:0.92rem;">Plus-addressing, SMS gateways, disposable emails, numeric locals, signup velocity. 15-pattern engine.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-network-wired" style="color:#16a34a;"></i> IP Intelligence</h3><p style="color:#475569;font-size:0.92rem;">Multi-source: ip-api.com, AbuseIPDB, IPQualityScore. Proxy, VPN, Tor, and datacenter detection.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-users-between-lines" style="color:#16a34a;"></i> 15-Dimension Duplicate Detection</h3><p style="color:#475569;font-size:0.92rem;">IP subnet, email base, phone, fingerprint, geo-mismatch, name, signup timing linkage.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-globe" style="color:#16a34a;"></i> Geographic Analysis</h3><p style="color:#475569;font-size:0.92rem;">Cross-correlates IP, billing country, phone prefix, BIN country. Detects impossible travel patterns.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-gauge-high" style="color:#16a34a;"></i> Behavioral Biometrics</h3><p style="color:#475569;font-size:0.92rem;">Mouse movement entropy, keystroke cadence, form fill speed, paste detection.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-bolt" style="color:#16a34a;"></i> Velocity Engine</h3><p style="color:#475569;font-size:0.92rem;">Rate limiting: orders/IP, registrations/IP, failed payments, checkout attempts, BIN reuse.</p></div>
        <div class="fps-pub-feature"><h3 style="color:#0f172a;font-size:1.1rem;font-weight:800;"><i class="fas fa-earth-americas" style="color:#16a34a;"></i> Global Threat Intel</h3><p style="color:#475569;font-size:0.92rem;">Cross-instance fraud sharing. SHA-256 hashed data with GDPR compliance. Hub-and-spoke architecture.</p></div>
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
    <div class="fps-pub-section" style="margin-top:48px;color:#334155;">
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

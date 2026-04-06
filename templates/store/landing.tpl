{literal}
<style>
.fps-store{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#334155;--fps-p:#16a34a;--fps-s:#15803d;--fps-g:#16a34a;--fps-w:#f59e0b;--fps-d:#ef4444;--fps-bg:#f8fafc;}

/* Hero */
.fps-hero{background:linear-gradient(135deg,#0a0e27 0%,#1a1040 50%,#0d1a3a 100%);padding:80px 0 60px;text-align:center;position:relative;overflow:hidden;}
.fps-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 50%,rgba(102,126,234,0.08) 0%,transparent 60%),radial-gradient(circle at 70% 30%,rgba(118,75,162,0.06) 0%,transparent 50%);pointer-events:none;}
.fps-hero h1{font-size:2.8rem;font-weight:900;margin:0 0 12px;color:#fff;}
.fps-hero .sub{font-size:1.15rem;color:#e2e8f0;max-width:600px;margin:0 auto 30px;line-height:1.6;}
.fps-hero .badges{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:30px;}
.fps-hero .badges span{padding:6px 16px;border-radius:20px;font-size:.8rem;font-weight:600;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);}
.fps-hero .cta-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.fps-hero .cta{padding:12px 32px;border-radius:8px;font-size:1rem;font-weight:700;text-decoration:none;transition:transform .2s,box-shadow .2s;display:inline-flex;align-items:center;gap:8px;}
.fps-hero .cta:hover{transform:translateY(-2px);}
.fps-hero .cta-primary{background:linear-gradient(135deg,var(--fps-p),var(--fps-s));color:#fff;box-shadow:0 4px 20px rgba(102,126,234,0.3);}
.fps-hero .cta-outline{background:transparent;color:#fff;border:2px solid rgba(255,255,255,0.2);}

/* Stats bar */
.fps-stat-bar{display:flex;justify-content:center;gap:40px;padding:30px 0;flex-wrap:wrap;border-bottom:1px solid rgba(255,255,255,0.05);}
.fps-stat-bar .s{text-align:center;}
.fps-stat-bar .sv{font-size:2rem;font-weight:900;background:linear-gradient(135deg,var(--fps-p),var(--fps-g));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.fps-stat-bar .sl{font-size:.75rem;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-top:2px;}

/* Pricing */
.fps-pricing{padding:60px 20px;max-width:1100px;margin:0 auto;}
.fps-pricing h2{text-align:center;font-size:2rem;font-weight:800;margin:0 0 8px;color:#0f172a;}
.fps-pricing .psub{text-align:center;color:#64748b;margin:0 0 40px;font-size:1rem;}
.fps-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;align-items:start;}
.fps-card{border-radius:16px;overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-top:3px solid #cbd5e1;transition:transform .3s,box-shadow .3s;box-shadow:0 2px 8px rgba(15,23,42,0.06);}
.fps-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(15,23,42,0.1);}
.fps-card.pop{border-color:#16a34a;border-top-color:#16a34a;box-shadow:0 4px 16px rgba(22,163,74,0.1);}
.fps-card.gold{border-color:#f59e0b;border-top-color:#f59e0b;}
.fps-card .ch{padding:28px 24px 20px;text-align:center;}
.fps-card .badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:.68rem;font-weight:800;letter-spacing:.06em;margin-bottom:12px;}
.fps-card .cn{font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 6px;}
.fps-card .cp{font-size:2.4rem;font-weight:900;color:#16a34a;margin:0 0 4px;}
.fps-card .cp small{font-size:.9rem;font-weight:400;color:#94a3b8;}
.fps-card .cb{padding:0 24px 24px;}
.fps-card .cb .sec{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin:16px 0 8px;padding-top:12px;border-top:1px solid #e2e8f0;color:#0f172a;}
.fps-card .cb ul{list-style:none;padding:0;margin:0;}
.fps-card .cb ul li{padding:6px 0 6px 24px;position:relative;font-size:.88rem;color:#475569;border-bottom:1px solid #f1f5f9;}
.fps-card .cb ul li:last-child{border:none;}
.fps-card .cb ul li::before{content:'\2713';position:absolute;left:0;color:var(--fps-g);font-weight:700;}
.fps-card .cb .ep{font-family:'Courier New',monospace;font-size:.78rem;padding:2px 0;color:#64748b;}
.fps-card .cb .ep .g{color:var(--fps-g);font-weight:700;}
.fps-card .cb .ep .y{color:var(--fps-w);font-weight:700;}
.fps-card .cf{padding:0 24px 24px;text-align:center;}
.fps-card .cf a{display:block;padding:14px;border-radius:10px;font-size:1rem;font-weight:700;text-decoration:none;text-align:center;transition:transform .15s,box-shadow .15s;}
.fps-card .cf a:hover{transform:translateY(-1px);}
.fps-free .cf a{background:#f0fdf4;color:#16a34a;border:2px solid #bbf7d0;}
.fps-free .cf a:hover{background:#16a34a;color:#fff;}
.fps-basic .cf a{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 16px rgba(22,163,74,0.3);}
.fps-prem .cf a{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;box-shadow:0 4px 16px rgba(245,158,11,0.3);}

/* Features grid */
.fps-features{padding:60px 20px;max-width:1000px;margin:0 auto;}
.fps-features h2{text-align:center;font-size:2rem;font-weight:800;color:#0f172a;margin:0 0 40px;}
.fps-fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
.fps-fi{padding:24px;border-radius:14px;background:#fff;border:1px solid #e2e8f0;transition:border-color .2s,box-shadow .2s;box-shadow:0 1px 3px rgba(15,23,42,0.04);}
.fps-fi:hover{border-color:#16a34a;box-shadow:0 4px 12px rgba(22,163,74,0.06);}
.fps-fi .ic{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:12px;}
.fps-fi h4{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 6px;}
.fps-fi p{font-size:.85rem;color:#64748b;margin:0;line-height:1.5;}

/* CTA bottom */
.fps-bottom{padding:60px 20px;text-align:center;background:linear-gradient(135deg,#16a34a,#0f172a 70%);border-radius:20px;margin:0 20px 40px;box-shadow:0 8px 32px rgba(15,23,42,0.2);}
.fps-bottom h2{font-size:1.8rem;font-weight:900;color:#fff;margin:0 0 12px;}
.fps-bottom p{color:#e2e8f0;margin:0 0 24px;font-size:1rem;}
</style>
{/literal}

<div class="fps-store">

<!-- Hero -->
<div class="fps-hero">
  <h1>Fraud Prevention Suite</h1>
  <p class="sub">Enterprise-grade fraud detection API with 16+ detection engines, real-time threat intelligence, and global data sharing across WHMCS instances.</p>
  <div class="badges">
    <span><i class="fas fa-shield-halved"></i> 16 Detection Engines</span>
    <span><i class="fas fa-globe"></i> Global Threat Intel</span>
    <span><i class="fas fa-bolt"></i> Real-time Blocking</span>
    <span><i class="fas fa-lock"></i> GDPR Compliant</span>
  </div>
  <div class="cta-row">
    <a href="#pricing" class="cta cta-primary" onclick="document.getElementById('pricing').scrollIntoView(true);return false;"><i class="fas fa-rocket"></i> View Plans</a>
    <a href="{$WEB_ROOT}/index.php?m=fraud_prevention_suite&page=api-docs" class="cta cta-outline"><i class="fas fa-book"></i> API Documentation</a>
  </div>
</div>

<!-- Stats -->
<div class="fps-stat-bar">
  <div class="s"><div class="sv">{$stats.total_checks|number_format}</div><div class="sl">Fraud Checks Run</div></div>
  <div class="s"><div class="sv">{$stats.countries_monitored}</div><div class="sl">Countries Monitored</div></div>
  <div class="s"><div class="sv">{$stats.provider_count}</div><div class="sl">Detection Engines</div></div>
  <div class="s"><div class="sv">{$stats.global_intel_count|number_format}</div><div class="sl">Global Intel Records</div></div>
</div>

<!-- Pricing -->
<div class="fps-pricing" id="pricing">
  <h2>Choose Your Plan</h2>
  <p class="psub">Start free, upgrade when you need more power. All plans include API key, usage dashboard, and documentation.</p>

  <div class="fps-cards">
    <!-- Free -->
    <div class="fps-card fps-free">
      <div class="ch">
        <div class="badge" style="background:rgba(56,239,125,0.12);color:#38ef7d;">STARTER</div>
        <div class="cn">Free Tier</div>
        <div class="cp">$0 <small>/month</small></div>
      </div>
      <div class="cb">
        <div class="sec" style="color:#38ef7d;">Included</div>
        <ul>
          <li>Global fraud statistics</li>
          <li>Threat topology visualization</li>
          <li>30 requests/minute</li>
          <li>5,000 requests/day</li>
          <li>Client area dashboard</li>
        </ul>
        <div class="sec" style="color:#38ef7d;">Endpoints</div>
        <div class="ep"><span class="g">GET</span> /v1/stats/global</div>
        <div class="ep"><span class="g">GET</span> /v1/topology/hotspots</div>
      </div>
      <div class="cf"><a href="{$WEB_ROOT}/cart.php?a=add&pid={$products.free.pid}&billingcycle=monthly&skipconfig=true"><i class="fas fa-key"></i> Get Free Key</a></div>
    </div>

    <!-- Basic (Popular) -->
    <div class="fps-card fps-basic pop">
      <div class="ch">
        <div class="badge" style="background:rgba(102,126,234,0.15);color:#667eea;">MOST POPULAR</div>
        <div class="cn">Basic</div>
        <div class="cp">$19 <small>/month</small></div>
      </div>
      <div class="cb">
        <div class="sec" style="color:#667eea;">Everything in Free +</div>
        <ul>
          <li>IP threat intelligence (VPN, Tor, proxy)</li>
          <li>IP geolocation (country, city, ISP)</li>
          <li>Email validation &amp; disposable detection</li>
          <li>Real-time fraud event feed</li>
          <li>120 requests/minute</li>
          <li>50,000 requests/day</li>
        </ul>
        <div class="sec" style="color:#667eea;">Endpoints</div>
        <div class="ep"><span class="g">GET</span> /v1/lookup/ip-basic</div>
        <div class="ep"><span class="g">GET</span> /v1/lookup/email-basic</div>
        <div class="ep"><span class="g">GET</span> /v1/topology/events</div>
      </div>
      <div class="cf"><a href="{$WEB_ROOT}/cart.php?a=add&pid={$products.basic.pid}&billingcycle=monthly&skipconfig=true"><i class="fas fa-bolt"></i> Get Started</a></div>
    </div>

    <!-- Premium -->
    <div class="fps-card fps-prem gold">
      <div class="ch">
        <div class="badge" style="background:rgba(255,215,0,0.12);color:#ffd700;">ENTERPRISE</div>
        <div class="cn">Premium</div>
        <div class="cp">$99 <small>/month</small></div>
      </div>
      <div class="cb">
        <div class="sec" style="color:#ffd700;">Everything in Basic +</div>
        <ul>
          <li>Full IP dossier (abuse, risk scoring)</li>
          <li>Full email analysis (breaches, SMTP)</li>
          <li>Bulk lookup (100 items/request)</li>
          <li>Country fraud analytics</li>
          <li>Webhook notifications</li>
          <li>600 requests/minute</li>
          <li>500,000 requests/day</li>
        </ul>
        <div class="sec" style="color:#ffd700;">Endpoints</div>
        <div class="ep"><span class="g">GET</span> /v1/lookup/ip-full</div>
        <div class="ep"><span class="g">GET</span> /v1/lookup/email-full</div>
        <div class="ep"><span class="y">POST</span> /v1/lookup/bulk</div>
        <div class="ep"><span class="g">GET</span> /v1/reports/country/CC</div>
      </div>
      <div class="cf"><a href="{$WEB_ROOT}/cart.php?a=add&pid={$products.premium.pid}&billingcycle=monthly&skipconfig=true"><i class="fas fa-crown"></i> Go Premium</a></div>
    </div>
  </div>
</div>

<!-- Features -->
<div class="fps-features">
  <h2>Why Choose Our API?</h2>
  <div class="fps-fg">
    <div class="fps-fi">
      <div class="ic" style="background:rgba(102,126,234,0.12);color:#667eea;"><i class="fas fa-network-wired"></i></div>
      <h4>16 Detection Engines</h4>
      <p>IP intelligence, email validation, device fingerprinting, bot detection, velocity analysis, OFAC screening, and more.</p>
    </div>
    <div class="fps-fi">
      <div class="ic" style="background:rgba(56,239,125,0.12);color:#38ef7d;"><i class="fas fa-globe"></i></div>
      <h4>Global Threat Network</h4>
      <p>Cross-instance intelligence sharing. When fraud is detected anywhere, all participants are protected.</p>
    </div>
    <div class="fps-fi">
      <div class="ic" style="background:rgba(245,200,66,0.12);color:#f5c842;"><i class="fas fa-bolt"></i></div>
      <h4>Real-time Blocking</h4>
      <p>Pre-checkout fraud blocking stops bad actors before orders are placed. Sub-second response times.</p>
    </div>
    <div class="fps-fi">
      <div class="ic" style="background:rgba(245,87,108,0.12);color:#f5576c;"><i class="fas fa-code"></i></div>
      <h4>Developer Friendly</h4>
      <p>RESTful JSON API with code examples in cURL, PHP, Python, and JavaScript. Rate limit headers on every response.</p>
    </div>
    <div class="fps-fi">
      <div class="ic" style="background:rgba(102,126,234,0.12);color:#667eea;"><i class="fas fa-shield-halved"></i></div>
      <h4>GDPR Compliant</h4>
      <p>Only anonymized data (SHA-256 hashes) leaves your instance. Full right-to-erasure support. Configurable retention.</p>
    </div>
    <div class="fps-fi">
      <div class="ic" style="background:rgba(56,239,125,0.12);color:#38ef7d;"><i class="fas fa-chart-line"></i></div>
      <h4>Usage Dashboard</h4>
      <p>Monitor API usage, rate limits, and request history directly in your client area. No external tools needed.</p>
    </div>
  </div>
</div>

<!-- Bottom CTA -->
<div class="fps-bottom">
  <h2>Ready to Protect Your Business?</h2>
  <p>Start with a free API key and upgrade anytime. No credit card required for the free tier.</p>
  <div class="cta-row" style="display:flex;gap:12px;justify-content:center;">
    <a href="{$WEB_ROOT}/cart.php?a=add&pid={$products.free.pid}&billingcycle=monthly&skipconfig=true" class="cta cta-primary" style="padding:14px 36px;border-radius:10px;font-size:1.05rem;font-weight:700;text-decoration:none;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;box-shadow:0 4px 20px rgba(102,126,234,0.3);display:inline-flex;align-items:center;gap:8px;"><i class="fas fa-rocket"></i> Get Started Free</a>
    <a href="{$WEB_ROOT}/index.php?m=fraud_prevention_suite&page=topology" class="cta cta-outline" style="padding:14px 36px;border-radius:10px;font-size:1.05rem;font-weight:700;text-decoration:none;background:transparent;color:#fff;border:2px solid rgba(255,255,255,0.2);display:inline-flex;align-items:center;gap:8px;"><i class="fas fa-globe"></i> Live Threat Map</a>
    <a href="https://github.com/CyberNinja7420/whmcs-fraud-prevention-suite" target="_blank" rel="noopener" class="cta cta-outline" style="padding:14px 36px;border-radius:10px;font-size:1.05rem;font-weight:700;text-decoration:none;background:transparent;color:#fff;border:2px solid rgba(255,255,255,0.2);display:inline-flex;align-items:center;gap:8px;"><i class="fab fa-github"></i> GitHub</a>
  </div>
</div>

</div>

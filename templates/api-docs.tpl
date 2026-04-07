{literal}
<style>
.fps-docs{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#d0d8f0;line-height:1.7;max-width:1100px;margin:0 auto;}
.fps-docs *{box-sizing:border-box;}
.fps-docs-hero{background:linear-gradient(135deg,#0f0c29 0%,#1a1a3e 50%,#302b63 100%);color:#fff;padding:60px 30px;text-align:center;border-radius:16px;margin-bottom:40px;position:relative;overflow:hidden;}
.fps-docs-hero::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 30% 40%,rgba(102,126,234,0.12),transparent 60%),radial-gradient(ellipse at 70% 60%,rgba(118,75,162,0.08),transparent 50%);pointer-events:none;}
.fps-docs-hero h1{font-size:2.2rem;font-weight:800;margin:0 0 12px;letter-spacing:-0.5px;position:relative;z-index:1;}
.fps-docs-hero h1 i{color:#667eea;margin-right:10px;}
.fps-docs-hero p{font-size:1.1rem;color:#b0b8d1;margin:0 auto 20px;max-width:700px;position:relative;z-index:1;}
.fps-docs-hero .fps-docs-version{background:rgba(102,126,234,0.2);border:1px solid rgba(102,126,234,0.3);border-radius:20px;padding:4px 16px;font-size:0.85rem;color:#a0b0ff;display:inline-block;margin-bottom:16px;position:relative;z-index:1;}
.fps-docs-nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:40px;padding:16px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;}
.fps-docs-nav a{padding:8px 16px;border-radius:8px;background:rgba(102,126,234,0.12);color:#a0b0ff;text-decoration:none;font-size:0.88rem;font-weight:600;transition:all 0.2s;}
.fps-docs-nav a:hover{background:rgba(102,126,234,0.25);color:#fff;}
.fps-docs-section{margin-bottom:48px;}
.fps-docs-section h2{font-size:1.5rem;font-weight:700;color:#ffffff;margin:0 0 8px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,0.1);}
.fps-docs-section h2 i{color:#667eea;}
.fps-docs-section p.subtitle{font-size:0.95rem;color:#8892b0;margin:8px 0 20px;}
.fps-docs-card{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:24px;margin-bottom:16px;}
.fps-docs-table{width:100%;border-collapse:collapse;margin:16px 0;}
.fps-docs-table th{background:rgba(102,126,234,0.15);color:#a0b0ff;font-weight:700;text-align:left;padding:10px 14px;font-size:0.85rem;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid rgba(102,126,234,0.2);}
.fps-docs-table td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.92rem;color:#c0c8e0;}
.fps-docs-table tr:hover td{background:rgba(102,126,234,0.06);}
.fps-docs-code{background:#0d0d1a;border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:16px 20px;margin:12px 0;overflow-x:auto;font-family:'Fira Code','Cascadia Code',Consolas,monospace;font-size:0.85rem;line-height:1.6;color:#c0c8ff;}
.fps-docs-code pre{margin:0;white-space:pre-wrap;word-break:break-all;}
.fps-docs-code .label{display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:700;margin-bottom:8px;color:#fff;}
.fps-docs-code .label-curl{background:#667eea;}
.fps-docs-code .label-json{background:#11998e;}
.fps-docs-endpoint{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:28px;margin-bottom:28px;}
.fps-docs-endpoint-header{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
.fps-docs-endpoint-header .method{padding:5px 14px;border-radius:6px;font-size:0.82rem;font-weight:800;font-family:monospace;color:#fff;text-transform:uppercase;}
.fps-docs-endpoint-header .method.get{background:#11998e;}
.fps-docs-endpoint-header .method.post{background:#667eea;}
.fps-docs-endpoint-header .path{font-family:'Fira Code',monospace;font-size:1.05rem;color:#e0e8ff;font-weight:600;}
.fps-docs-endpoint-header .tier{margin-left:auto;padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;}
.fps-docs-endpoint-header .tier-anonymous{background:rgba(56,239,125,0.15);color:#38ef7d;border:1px solid rgba(56,239,125,0.3);}
.fps-docs-endpoint-header .tier-free{background:rgba(56,239,125,0.15);color:#38ef7d;border:1px solid rgba(56,239,125,0.3);}
.fps-docs-endpoint-header .tier-basic{background:rgba(102,126,234,0.15);color:#a0b0ff;border:1px solid rgba(102,126,234,0.3);}
.fps-docs-endpoint-header .tier-premium{background:rgba(235,51,73,0.15);color:#ff8090;border:1px solid rgba(235,51,73,0.3);}
.fps-docs-endpoint p.desc{color:#8892b0;margin:0 0 16px;font-size:0.95rem;}
.fps-docs-badge-required{background:rgba(235,51,73,0.2);color:#ff8090;padding:1px 6px;border-radius:4px;font-size:0.75rem;font-weight:700;}
.fps-docs-badge-optional{background:rgba(255,255,255,0.08);color:#8892b0;padding:1px 6px;border-radius:4px;font-size:0.75rem;font-weight:700;}
.fps-docs-cta{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-align:center;padding:40px 30px;border-radius:16px;margin-top:40px;}
.fps-docs-cta h2{font-size:1.6rem;font-weight:800;margin:0 0 12px;color:#fff;}
.fps-docs-cta p{color:#d0d8ff;margin:0 auto 20px;max-width:600px;}
.fps-docs-cta a{display:inline-block;padding:12px 28px;background:#fff;color:#667eea;border-radius:10px;font-weight:700;text-decoration:none;transition:all 0.2s;}
.fps-docs-cta a:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
@media(max-width:768px){.fps-docs-hero h1{font-size:1.5rem;}.fps-docs-endpoint-header{flex-direction:column;align-items:flex-start;}.fps-docs-endpoint-header .tier{margin-left:0;}}
</style>
{/literal}

<div class="fps-docs">

    {* === HERO === *}
    <div class="fps-docs-hero">
        <div class="fps-docs-version">v{$module_version}</div>
        <h1><i class="fas fa-book"></i> API Documentation</h1>
        <p>Integrate fraud intelligence into your applications with our REST API. Real-time IP risk scoring, email validation, threat topology, and global statistics.</p>
    </div>

    {* === QUICK NAV === *}
    <div class="fps-docs-nav">
        <a href="#auth"><i class="fas fa-lock"></i> Authentication</a>
        <a href="#rate-limits"><i class="fas fa-tachometer-alt"></i> Rate Limits</a>
        <a href="#errors"><i class="fas fa-exclamation-triangle"></i> Error Codes</a>
        <a href="#ep-stats-global"><i class="fas fa-chart-bar"></i> Global Stats</a>
        <a href="#ep-topology-hotspots"><i class="fas fa-globe"></i> Hotspots</a>
        <a href="#ep-topology-events"><i class="fas fa-stream"></i> Events</a>
        <a href="#ep-ip-basic"><i class="fas fa-search"></i> IP Basic</a>
        <a href="#ep-ip-full"><i class="fas fa-search-plus"></i> IP Full</a>
        <a href="#ep-email-basic"><i class="fas fa-envelope"></i> Email Basic</a>
        <a href="#ep-email-full"><i class="fas fa-envelope-open"></i> Email Full</a>
        <a href="#ep-bulk"><i class="fas fa-layer-group"></i> Bulk Lookup</a>
        <a href="#ep-reports-country"><i class="fas fa-flag"></i> Country Report</a>
    </div>

    {* === BASE URL === *}
    <div class="fps-docs-section">
        <h2><i class="fas fa-link"></i> Base URL</h2>
        <div class="fps-docs-code">
            <pre>https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/...</pre>
        </div>
        <p class="subtitle">All endpoints are prefixed with <code>/v1/</code>. Pass the full endpoint path via the <code>endpoint</code> query parameter.</p>
    </div>

    {* === AUTHENTICATION === *}
    <div class="fps-docs-section" id="auth">
        <h2><i class="fas fa-lock"></i> Authentication</h2>
        <p class="subtitle">Some endpoints are available anonymously. Authenticated endpoints require an API key passed via header or query parameter.</p>
        <div class="fps-docs-card">
            <p style="color:#c0c8e0;margin:0 0 16px;"><strong style="color:#fff;">Option 1: HTTP Header (Recommended)</strong></p>
            <div class="fps-docs-code">
                <pre>X-FPS-API-Key: fps_your_api_key_here</pre>
            </div>
            <p style="color:#c0c8e0;margin:16px 0 0;"><strong style="color:#fff;">Option 2: Query Parameter</strong></p>
            <div class="fps-docs-code">
                <pre>?api_key=fps_your_api_key_here</pre>
            </div>
            <p style="color:#8892b0;margin:16px 0 0;font-size:0.9rem;">API keys start with <code>fps_</code> and are 52 characters long. Keys are hashed server-side and cannot be recovered if lost. Generate keys from your <a href="index.php?m=fraud_prevention_suite&page=api-keys" style="color:#667eea;">API Key Management</a> page.</p>
        </div>
    </div>

    {* === RATE LIMITS === *}
    <div class="fps-docs-section" id="rate-limits">
        <h2><i class="fas fa-tachometer-alt"></i> Rate Limits</h2>
        <p class="subtitle">Rate limits are applied per API key (or per IP for anonymous requests). Headers <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code> are included in every response.</p>
        <table class="fps-docs-table">
            <thead>
                <tr>
                    <th>Tier</th>
                    <th>Per Minute</th>
                    <th>Per Day</th>
                    <th>Endpoints</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong style="color:#38ef7d;">Anonymous</strong></td>
                    <td>5</td>
                    <td>100</td>
                    <td>2 (stats/global, topology/hotspots)</td>
                </tr>
                <tr>
                    <td><strong style="color:#38ef7d;">Free</strong></td>
                    <td>10</td>
                    <td>1,000</td>
                    <td>4 (+events, ip-basic)</td>
                </tr>
                <tr>
                    <td><strong style="color:#a0b0ff;">Basic</strong></td>
                    <td>30</td>
                    <td>10,000</td>
                    <td>6 (+ip-full, email-basic)</td>
                </tr>
                <tr>
                    <td><strong style="color:#ff8090;">Premium</strong></td>
                    <td>120</td>
                    <td>100,000</td>
                    <td>9 (all endpoints)</td>
                </tr>
            </tbody>
        </table>
    </div>

    {* === ERROR CODES === *}
    <div class="fps-docs-section" id="errors">
        <h2><i class="fas fa-exclamation-triangle"></i> Error Codes</h2>
        <p class="subtitle">All errors return a JSON body with an <code>error</code> field describing the issue.</p>
        <table class="fps-docs-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Meaning</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><strong>200</strong></td><td>OK</td><td>Request succeeded.</td></tr>
                <tr><td><strong>400</strong></td><td>Bad Request</td><td>Missing or invalid parameters.</td></tr>
                <tr><td><strong>401</strong></td><td>Unauthorized</td><td>Missing API key for an authenticated endpoint.</td></tr>
                <tr><td><strong>403</strong></td><td>Forbidden</td><td>API key does not have access to this endpoint (tier too low), or key is inactive/expired.</td></tr>
                <tr><td><strong>404</strong></td><td>Not Found</td><td>Unknown endpoint path.</td></tr>
                <tr><td><strong>429</strong></td><td>Rate Limited</td><td>Too many requests. Check <code>X-RateLimit-Reset</code> header for retry time.</td></tr>
                <tr><td><strong>500</strong></td><td>Server Error</td><td>Internal error. Contact support if persistent.</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-json">Error Response</span>
            <pre>{literal}{"error":"Rate limit exceeded","retry_after":42}{/literal}</pre>
        </div>
    </div>

    {* ================================================================ *}
    {* === ENDPOINTS === *}
    {* ================================================================ *}

    {* --- 1. GET /v1/stats/global --- *}
    <div class="fps-docs-endpoint" id="ep-stats-global">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/stats/global</span>
            <span class="tier tier-anonymous">Anonymous</span>
        </div>
        <p class="desc">Returns platform-wide aggregated statistics including total fraud checks performed, threats blocked, unique IPs analyzed, and detection engine counts.</p>
        <p style="color:#8892b0;font-size:0.88rem;margin-bottom:12px;"><strong style="color:#fff;">Parameters:</strong> None</p>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "total_checks": 148392,
  "threats_blocked": 12847,
  "unique_ips": 89201,
  "countries_monitored": 74,
  "bots_detected": 4219,
  "provider_count": 9,
  "checks_today": 523,
  "high_risk_today": 38,
  "avg_risk_score": 18.4,
  "last_updated": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 2. GET /v1/topology/hotspots --- *}
    <div class="fps-docs-endpoint" id="ep-topology-hotspots">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/topology/hotspots</span>
            <span class="tier tier-anonymous">Anonymous</span>
        </div>
        <p class="desc">Returns geographic threat heatmap data showing fraud hotspot concentrations by country, suitable for map visualization.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>period</code></td><td>string</td><td><span class="fps-docs-badge-optional">Optional</span></td><td>Time window: <code>24h</code>, <code>7d</code> (default), <code>30d</code></td></tr>
                <tr><td><code>limit</code></td><td>int</td><td><span class="fps-docs-badge-optional">Optional</span></td><td>Max countries to return. Default: 20, Max: 50</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/topology/hotspots&period=7d&limit=10"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "period": "7d",
  "hotspots": [
    {"country": "NG", "name": "Nigeria", "threat_count": 312, "avg_risk": 64.2, "lat": 9.082, "lng": 8.675},
    {"country": "IN", "name": "India", "threat_count": 189, "avg_risk": 42.7, "lat": 20.594, "lng": 78.963},
    {"country": "BR", "name": "Brazil", "threat_count": 147, "avg_risk": 38.1, "lat": -14.235, "lng": -51.925},
    {"country": "VN", "name": "Vietnam", "threat_count": 98, "avg_risk": 55.9, "lat": 14.058, "lng": 108.277}
  ],
  "generated_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 3. GET /v1/topology/events --- *}
    <div class="fps-docs-endpoint" id="ep-topology-events">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/topology/events</span>
            <span class="tier tier-free">Free</span>
        </div>
        <p class="desc">Returns an anonymized real-time feed of recent fraud detection events. IP addresses are masked to the /24 range. Useful for building live dashboards.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>limit</code></td><td>int</td><td><span class="fps-docs-badge-optional">Optional</span></td><td>Number of events. Default: 20, Max: 100</td></tr>
                <tr><td><code>min_risk</code></td><td>int</td><td><span class="fps-docs-badge-optional">Optional</span></td><td>Minimum risk score filter (0-100). Default: 0</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/topology/events&limit=5&min_risk=25"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "events": [
    {
      "timestamp": "2026-03-30T14:18:42Z",
      "ip_masked": "103.42.18.xxx",
      "country": "VN",
      "risk_score": 72,
      "risk_level": "high",
      "check_type": "registration",
      "action_taken": "blocked",
      "factors": ["vpn_detected", "disposable_email", "velocity_exceeded"]
    },
    {
      "timestamp": "2026-03-30T14:17:11Z",
      "ip_masked": "197.210.55.xxx",
      "country": "NG",
      "risk_score": 58,
      "risk_level": "medium",
      "check_type": "order",
      "action_taken": "flagged",
      "factors": ["geo_mismatch", "new_account"]
    }
  ],
  "total_available": 847,
  "generated_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 4. GET /v1/lookup/ip-basic --- *}
    <div class="fps-docs-endpoint" id="ep-ip-basic">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/lookup/ip-basic</span>
            <span class="tier tier-free">Free</span>
        </div>
        <p class="desc">Returns basic geolocation and risk assessment for an IP address. Includes country, ISP, and proxy/VPN detection.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>ip</code></td><td>string</td><td><span class="fps-docs-badge-required">Required</span></td><td>IPv4 or IPv6 address to look up</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/ip-basic&ip=185.220.101.34"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "ip": "185.220.101.34",
  "country": "DE",
  "country_name": "Germany",
  "city": "Frankfurt",
  "isp": "Hetzner Online GmbH",
  "is_proxy": false,
  "is_vpn": true,
  "is_tor": false,
  "is_datacenter": true,
  "risk_score": 45,
  "risk_level": "medium",
  "cached": false,
  "queried_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 5. GET /v1/lookup/ip-full --- *}
    <div class="fps-docs-endpoint" id="ep-ip-full">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/lookup/ip-full</span>
            <span class="tier tier-basic">Basic</span>
        </div>
        <p class="desc">Returns comprehensive IP intelligence including all basic data plus abuse history, ASN details, threat classification, and fraud score from multiple providers.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>ip</code></td><td>string</td><td><span class="fps-docs-badge-required">Required</span></td><td>IPv4 or IPv6 address to look up</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/ip-full&ip=185.220.101.34"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "ip": "185.220.101.34",
  "country": "DE",
  "country_name": "Germany",
  "region": "Hessen",
  "city": "Frankfurt",
  "latitude": 50.1109,
  "longitude": 8.6821,
  "isp": "Hetzner Online GmbH",
  "org": "Hetzner Online GmbH",
  "asn": "AS24940",
  "is_proxy": false,
  "is_vpn": true,
  "is_tor": false,
  "is_datacenter": true,
  "is_bogon": false,
  "risk_score": 45,
  "risk_level": "medium",
  "abuse_confidence": 28,
  "abuse_reports": 12,
  "threat_types": ["vpn", "datacenter"],
  "first_seen": "2025-11-02T08:14:00Z",
  "total_checks": 7,
  "providers_consulted": ["ip-api", "abuseipdb", "ipqualityscore"],
  "cached": false,
  "queried_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 6. GET /v1/lookup/email-basic --- *}
    <div class="fps-docs-endpoint" id="ep-email-basic">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/lookup/email-basic</span>
            <span class="tier tier-basic">Basic</span>
        </div>
        <p class="desc">Returns basic email validation results including format check, domain reputation, and disposable email detection.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>email</code></td><td>string</td><td><span class="fps-docs-badge-required">Required</span></td><td>Email address to validate</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/email-basic&email=test@tempmail.ninja"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "email": "test@tempmail.ninja",
  "valid_format": true,
  "is_disposable": true,
  "is_free_provider": false,
  "domain": "tempmail.ninja",
  "domain_age_days": 42,
  "has_mx_record": true,
  "risk_score": 78,
  "risk_level": "high",
  "flags": ["disposable_domain", "recently_registered_domain"],
  "queried_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 7. GET /v1/lookup/email-full --- *}
    <div class="fps-docs-endpoint" id="ep-email-full">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/lookup/email-full</span>
            <span class="tier tier-premium">Premium</span>
        </div>
        <p class="desc">Returns comprehensive email intelligence including breach history, social media presence, deliverability score, and fraud pattern analysis.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>email</code></td><td>string</td><td><span class="fps-docs-badge-required">Required</span></td><td>Email address to analyze</td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/email-full&email=suspicious@example.com"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "email": "suspicious@example.com",
  "valid_format": true,
  "is_disposable": false,
  "is_free_provider": false,
  "domain": "example.com",
  "domain_age_days": 10842,
  "has_mx_record": true,
  "is_deliverable": true,
  "is_catch_all": false,
  "breach_count": 3,
  "breach_sources": ["Collection #1 (2019)", "LinkedIn (2021)", "Unknown (2023)"],
  "spam_score": 12,
  "social_profiles_found": 2,
  "has_gravatar": true,
  "fraud_pattern_match": false,
  "is_plus_addressed": false,
  "risk_score": 35,
  "risk_level": "medium",
  "flags": ["breached_multiple"],
  "providers_consulted": ["stopforumspam", "internal", "disposable_db"],
  "queried_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 8. POST /v1/lookup/bulk --- *}
    <div class="fps-docs-endpoint" id="ep-bulk">
        <div class="fps-docs-endpoint-header">
            <span class="method post">POST</span>
            <span class="path">/v1/lookup/bulk</span>
            <span class="tier tier-premium">Premium</span>
        </div>
        <p class="desc">Submit a batch of IP addresses and/or email addresses for bulk risk assessment. Up to 100 items per request.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>items</code></td><td>array</td><td><span class="fps-docs-badge-required">Required</span></td><td>Array of objects, each with <code>type</code> ("ip" or "email") and <code>value</code></td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -X POST \
  -H "X-FPS-API-Key: fps_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{literal}{"items":[{"type":"ip","value":"185.220.101.34"},{"type":"ip","value":"103.42.18.97"},{"type":"email","value":"test@tempmail.ninja"}]}{/literal}' \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/lookup/bulk"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "results": [
    {"type": "ip", "value": "185.220.101.34", "risk_score": 45, "risk_level": "medium", "country": "DE", "is_vpn": true},
    {"type": "ip", "value": "103.42.18.97", "risk_score": 72, "risk_level": "high", "country": "VN", "is_proxy": true},
    {"type": "email", "value": "test@tempmail.ninja", "risk_score": 78, "risk_level": "high", "is_disposable": true}
  ],
  "total_items": 3,
  "processed": 3,
  "processing_time_ms": 842,
  "queried_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* --- 9. GET /v1/reports/country --- *}
    <div class="fps-docs-endpoint" id="ep-reports-country">
        <div class="fps-docs-endpoint-header">
            <span class="method get">GET</span>
            <span class="path">/v1/reports/country/{literal}{CC}{/literal}</span>
            <span class="tier tier-premium">Premium</span>
        </div>
        <p class="desc">Returns a detailed threat report for a specific country including risk breakdown, top threat types, trend data, and top offending ISPs.</p>
        <table class="fps-docs-table">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>cc</code></td><td>string</td><td><span class="fps-docs-badge-required">Required</span></td><td>ISO 3166-1 alpha-2 country code (e.g., <code>NG</code>, <code>US</code>, <code>BR</code>). Passed as part of the endpoint path.</td></tr>
                <tr><td><code>period</code></td><td>string</td><td><span class="fps-docs-badge-optional">Optional</span></td><td>Time window: <code>7d</code> (default), <code>30d</code>, <code>90d</code></td></tr>
            </tbody>
        </table>
        <div class="fps-docs-code">
            <span class="label label-curl">Example Request</span>
            <pre>curl -s -H "X-FPS-API-Key: fps_your_key_here" \
  "https://enterprisevpssolutions.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/reports/country/NG&period=30d"</pre>
        </div>
        <div class="fps-docs-code">
            <span class="label label-json">Example Response</span>
            <pre>{literal}{
  "country": "NG",
  "country_name": "Nigeria",
  "period": "30d",
  "total_checks": 1842,
  "high_risk_count": 312,
  "avg_risk_score": 54.7,
  "risk_distribution": {"low": 420, "medium": 710, "high": 512, "critical": 200},
  "top_threat_types": [
    {"type": "disposable_email", "count": 287},
    {"type": "vpn_detected", "count": 198},
    {"type": "velocity_exceeded", "count": 142}
  ],
  "top_isps": [
    {"isp": "MTN Nigeria", "checks": 412, "avg_risk": 48.2},
    {"isp": "Airtel Nigeria", "checks": 287, "avg_risk": 52.1}
  ],
  "trend": [
    {"date": "2026-03-01", "checks": 52, "threats": 18},
    {"date": "2026-03-02", "checks": 61, "threats": 22}
  ],
  "generated_at": "2026-03-30T14:22:01Z"
}{/literal}</pre>
        </div>
    </div>

    {* === CTA === *}
    <div class="fps-docs-cta">
        <h2>Ready to Get Started?</h2>
        <p>Generate a free API key and start querying our fraud intelligence in under a minute.</p>
        <a href="index.php?m=fraud_prevention_suite&page=api-keys"><i class="fas fa-key"></i> Get Your API Key</a>
    </div>

</div>

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
.fps-api{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:inherit;line-height:1.7;max-width:1200px;margin:0 auto;}
.fps-api *{box-sizing:border-box;}
.fps-api-hero{background:linear-gradient(135deg,#0f0c29 0%,#1a1a3e 50%,#302b63 100%);color:#fff;padding:50px 30px;text-align:center;border-radius:16px;margin-bottom:32px;}
.fps-api-hero h1{font-size:2.2rem;font-weight:800;margin:0 0 12px;}
.fps-api-hero p{font-size:1.1rem;color:#b0b8d1;margin:0 auto 16px;max-width:700px;}
.fps-api-nav{display:flex;gap:10px;justify-content:center;margin-bottom:32px;flex-wrap:wrap;}
.fps-api-nav a{padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;border:1px solid var(--fps-pub-card-border);color:#667eea;background:var(--fps-pub-card-bg);transition:all 0.2s;}
.fps-api-nav a:hover,.fps-api-nav a.active{background:#667eea;color:#fff;border-color:#667eea;}
.fps-api-section{background:var(--fps-pub-card-bg);border-radius:12px;padding:28px;box-shadow:0 2px 8px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);margin-bottom:24px;}
.fps-api-section h2{font-size:1.4rem;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:10px;}
.fps-api-section h2 i{color:#667eea;}
.fps-api-section h3{font-size:1.1rem;font-weight:700;margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--fps-pub-table-border);}
.fps-ep{background:var(--fps-pub-table-header);border:1px solid var(--fps-pub-card-border);border-radius:10px;padding:20px;margin-bottom:16px;}
.fps-ep-header{display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
.fps-ep-method{padding:4px 12px;border-radius:4px;font-size:0.78rem;font-weight:700;font-family:monospace;color:#fff;}
.fps-ep-method.get{background:#11998e;}
.fps-ep-method.post{background:#667eea;}
.fps-ep-method.delete{background:#eb3349;}
.fps-ep-path{font-family:'Fira Code',monospace;font-size:0.95rem;font-weight:600;color:var(--fps-pub-text);}
.fps-ep-tier{padding:2px 10px;border-radius:12px;font-size:0.72rem;font-weight:700;margin-left:auto;}
.fps-ep-tier.anon{background:#11998e22;color:#11998e;}
.fps-ep-tier.free{background:#667eea22;color:#667eea;}
.fps-ep-tier.basic{background:#f5a62322;color:#d4a017;}
.fps-ep-tier.premium{background:#eb334922;color:#eb3349;}
.fps-ep-desc{font-size:0.92rem;color:var(--fps-pub-text-secondary);margin-bottom:12px;}
.fps-ep-params{font-size:0.85rem;}
.fps-ep-params table{width:100%;border-collapse:collapse;}
.fps-ep-params th{background:var(--fps-pub-card-border);padding:6px 10px;text-align:left;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--fps-pub-text-muted);}
.fps-ep-params td{padding:6px 10px;border-bottom:1px solid var(--fps-pub-table-border);font-size:0.85rem;}
.fps-ep-params code{background:var(--fps-pub-card-border);padding:1px 6px;border-radius:3px;font-size:0.82rem;}
.fps-code{background:var(--fps-pub-code-bg);color:var(--fps-pub-code-text);padding:16px;border-radius:8px;font-size:0.85rem;overflow-x:auto;font-family:'Fira Code',monospace;line-height:1.5;}
.fps-code .key{color:#f5a623;}
.fps-code .str{color:#38ef7d;}
.fps-code .num{color:#667eea;}
.fps-api-tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px;margin-bottom:24px;}
.fps-api-tier{background:var(--fps-pub-card-bg);border-radius:12px;padding:24px;border:2px solid var(--fps-pub-card-border);text-align:center;}
.fps-api-tier.featured{border-color:#667eea;}
.fps-api-tier h3{font-size:1.1rem;margin:0 0 4px;}
.fps-api-tier .price{font-size:1.6rem;font-weight:800;color:#667eea;margin:8px 0;}
.fps-api-tier .price.free{color:#11998e;}
.fps-api-tier .limits{font-size:0.85rem;color:var(--fps-pub-text-muted);margin-bottom:12px;}
.fps-api-tier ul{list-style:none;padding:0;margin:0;text-align:left;font-size:0.88rem;}
.fps-api-tier ul li{padding:4px 0;display:flex;align-items:center;gap:6px;}
.fps-api-tier ul li i.y{color:#11998e;}.fps-api-tier ul li i.n{color:#ccc;}
</style>
<script>(function(){var s=localStorage.getItem('fps-pub-theme');if(s==='dark'){document.body.classList.add('fps-dark-mode');}else if(s!=='light'){var bg=window.getComputedStyle(document.body).backgroundColor;var m=bg.match(/\d+/g);if(m&&(parseInt(m[0])+parseInt(m[1])+parseInt(m[2]))<384){document.body.classList.add('fps-dark-mode');}}})();</script>
{/literal}

<div class="fps-api">

    <div class="fps-api-hero">
        <h1><i class="fas fa-code"></i> API Documentation</h1>
        <p>RESTful JSON API for fraud intelligence. Authenticate via <code style="background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:4px;">X-FPS-API-Key</code> header.</p>
        <div style="display:inline-block;padding:8px 20px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:8px;font-family:monospace;font-size:0.85rem;color:#b0b8d1;">
            Base URL: /modules/addons/fraud_prevention_suite/public/api.php?endpoint=
        </div>
        <div style="margin-top:16px;">
            <button onclick="document.body.classList.toggle('fps-dark-mode');localStorage.setItem('fps-pub-theme',document.body.classList.contains('fps-dark-mode')?'dark':'light')" style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.1);color:#fff;cursor:pointer;font-size:0.85rem;"><i class="fas fa-adjust"></i> Theme</button>
        </div>
    </div>

    <div class="fps-api-nav">
        <a href="{$overview_url}"><i class="fas fa-home"></i> Overview</a>
        <a href="{$global_url}"><i class="fas fa-earth-americas"></i> Global Intel</a>
        <a href="{$topology_url}"><i class="fas fa-globe"></i> Live Threat Map</a>
        <a href="{$api_docs_url}" class="active"><i class="fas fa-code"></i> API Docs</a>
    </div>

    {* === AUTHENTICATION === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-lock"></i> Authentication</h2>
        <p>Pass your API key in the <code>X-FPS-API-Key</code> HTTP header. Anonymous endpoints (stats, hotspots) work without a key but have lower rate limits.</p>
        <div class="fps-code">
curl -H "X-FPS-API-Key: YOUR_API_KEY" \<br>
&nbsp;&nbsp;"https://your-domain.com/modules/addons/fraud_prevention_suite/public/api.php?endpoint=/v1/stats/global"
        </div>
        <p style="margin-top:12px;font-size:0.9rem;color:var(--fps-pub-text-secondary);">API keys can also be passed as a query parameter: <code>?api_key=YOUR_KEY</code> (less secure, not recommended for production).</p>
    </div>

    {* === RATE LIMITS & TIERS === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-gauge-high"></i> API Tiers & Rate Limits</h2>
        <div class="fps-api-tiers">
            <div class="fps-api-tier">
                <h3>Anonymous</h3>
                <div class="price free">Free</div>
                <div class="limits">5/min | 100/day</div>
                <ul>
                    <li><i class="fas fa-check y"></i> Global stats</li>
                    <li><i class="fas fa-check y"></i> Threat hotspots</li>
                    <li><i class="fas fa-times n"></i> IP lookups</li>
                    <li><i class="fas fa-times n"></i> Email lookups</li>
                    <li><i class="fas fa-times n"></i> Bulk queries</li>
                </ul>
            </div>
            <div class="fps-api-tier">
                <h3>Free</h3>
                <div class="price free">Free</div>
                <div class="limits">30/min | 5,000/day</div>
                <ul>
                    <li><i class="fas fa-check y"></i> Everything in Anonymous</li>
                    <li><i class="fas fa-check y"></i> Event feed</li>
                    <li><i class="fas fa-check y"></i> Basic IP lookup</li>
                    <li><i class="fas fa-times n"></i> Full IP/email</li>
                    <li><i class="fas fa-times n"></i> Bulk queries</li>
                </ul>
            </div>
            <div class="fps-api-tier featured">
                <h3>Basic</h3>
                <div class="price">$0.005<span style="font-size:0.7rem;color:var(--fps-pub-text-muted);font-weight:400;">/query</span></div>
                <div class="limits">120/min | 50,000/day</div>
                <ul>
                    <li><i class="fas fa-check y"></i> Everything in Free</li>
                    <li><i class="fas fa-check y"></i> Full IP intelligence</li>
                    <li><i class="fas fa-check y"></i> Basic email lookup</li>
                    <li><i class="fas fa-times n"></i> Full email intel</li>
                    <li><i class="fas fa-times n"></i> Bulk queries</li>
                </ul>
            </div>
            <div class="fps-api-tier">
                <h3>Premium</h3>
                <div class="price">Custom</div>
                <div class="limits">600/min | 500,000/day</div>
                <ul>
                    <li><i class="fas fa-check y"></i> Everything in Basic</li>
                    <li><i class="fas fa-check y"></i> Full email intel</li>
                    <li><i class="fas fa-check y"></i> Bulk lookups (100/req)</li>
                    <li><i class="fas fa-check y"></i> Country reports</li>
                    <li><i class="fas fa-check y"></i> Dedicated support</li>
                </ul>
            </div>
        </div>
    </div>

    {* === RESPONSE FORMAT === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-reply"></i> Response Format</h2>
        <p>All endpoints return JSON with a standard envelope:</p>
        <div class="fps-code">
{literal}{{/literal}<br>
&nbsp;&nbsp;<span class="key">"success"</span>: <span class="num">true</span>,<br>
&nbsp;&nbsp;<span class="key">"data"</span>: {literal}{{ ... }}{/literal},<br>
&nbsp;&nbsp;<span class="key">"meta"</span>: {literal}{{/literal}<br>
&nbsp;&nbsp;&nbsp;&nbsp;<span class="key">"request_id"</span>: <span class="str">"fps_a1b2c3d4e5f6"</span>,<br>
&nbsp;&nbsp;&nbsp;&nbsp;<span class="key">"tier"</span>: <span class="str">"basic"</span>,<br>
&nbsp;&nbsp;&nbsp;&nbsp;<span class="key">"rate_limit"</span>: {literal}{{ "remaining": 118, "limit": 120 }}{/literal},<br>
&nbsp;&nbsp;&nbsp;&nbsp;<span class="key">"response_time_ms"</span>: <span class="num">42</span><br>
&nbsp;&nbsp;{literal}}}{/literal}<br>
{literal}}}{/literal}
        </div>
        <p style="margin-top:12px;font-size:0.9rem;">Rate limit headers: <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, <code>Retry-After</code> (on 429).</p>
    </div>

    {* === ENDPOINTS === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-route"></i> Endpoints</h2>

        <h3><i class="fas fa-chart-line"></i> Statistics & Topology</h3>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/stats/global</span>
                <span class="fps-ep-tier anon">Anonymous+</span>
            </div>
            <div class="fps-ep-desc">Platform-wide aggregated statistics for the last 30 days. Includes check totals, block rates, top countries, and risk distribution.</div>
            <div class="fps-ep-params">
                <strong>Parameters:</strong> None<br>
                <strong>Returns:</strong> <code>total_checks</code>, <code>total_blocks</code>, <code>pre_checkout_blocks</code>, <code>avg_risk_score</code>, <code>top_countries[]</code>, <code>risk_distribution{}</code>, <code>active_countries</code>
            </div>
        </div>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/topology/hotspots</span>
                <span class="fps-ep-tier anon">Anonymous+</span>
            </div>
            <div class="fps-ep-desc">Geographic threat heatmap data with latitude/longitude clustering. Powers the 3D globe visualization.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Default</th><th>Description</th></tr>
                    <tr><td><code>hours</code></td><td>int</td><td>24</td><td>Lookback window (max 168 = 7 days)</td></tr>
                </table>
                <strong>Returns:</strong> <code>hotspots[]</code> with <code>lat</code>, <code>lng</code>, <code>country_code</code>, <code>intensity</code>, <code>avg_risk</code>, <code>max_level</code>
            </div>
        </div>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/topology/events</span>
                <span class="fps-ep-tier free">Free+</span>
            </div>
            <div class="fps-ep-desc">Anonymized real-time event feed. Returns recent fraud check events with risk levels and country data.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Default</th><th>Description</th></tr>
                    <tr><td><code>since</code></td><td>datetime</td><td>24h ago</td><td>ISO 8601 timestamp cutoff</td></tr>
                    <tr><td><code>limit</code></td><td>int</td><td>100</td><td>Max events (max 500)</td></tr>
                </table>
            </div>
        </div>

        <h3><i class="fas fa-search"></i> IP Intelligence</h3>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/lookup/ip-basic</span>
                <span class="fps-ep-tier free">Free+</span>
            </div>
            <div class="fps-ep-desc">Basic IP risk assessment. Returns country, proxy/VPN/Tor/datacenter flags, and threat score.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td><code>ip</code></td><td>string</td><td>Yes</td><td>IPv4 or IPv6 address</td></tr>
                </table>
                <strong>Returns:</strong> <code>country</code>, <code>is_proxy</code>, <code>is_vpn</code>, <code>is_tor</code>, <code>is_datacenter</code>, <code>threat_score</code>
            </div>
        </div>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/lookup/ip-full</span>
                <span class="fps-ep-tier basic">Basic+</span>
            </div>
            <div class="fps-ep-desc">Full IP intelligence. Everything in basic plus ASN, ISP, region, city, coordinates, and proxy type classification.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td><code>ip</code></td><td>string</td><td>Yes</td><td>IPv4 or IPv6 address</td></tr>
                </table>
                <strong>Returns:</strong> All basic fields + <code>asn</code>, <code>asn_org</code>, <code>isp</code>, <code>region</code>, <code>city</code>, <code>latitude</code>, <code>longitude</code>, <code>proxy_type</code>
            </div>
        </div>

        <h3><i class="fas fa-envelope"></i> Email Intelligence</h3>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/lookup/email-basic</span>
                <span class="fps-ep-tier basic">Basic+</span>
            </div>
            <div class="fps-ep-desc">Email validation and reputation. Checks MX records, disposable domain list, free provider status, and role account detection.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td><code>email</code></td><td>string</td><td>Yes</td><td>Email address to check</td></tr>
                </table>
                <strong>Returns:</strong> <code>email_hash</code>, <code>domain</code>, <code>is_disposable</code>, <code>is_free_provider</code>, <code>is_role_account</code>, <code>mx_valid</code>, <code>domain_age_days</code>
            </div>
        </div>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/lookup/email-full</span>
                <span class="fps-ep-tier premium">Premium</span>
            </div>
            <div class="fps-ep-desc">Full email intelligence. Everything in basic plus breach history, social presence, deliverability scoring, and social profile data.</div>
            <div class="fps-ep-params">
                <table>
                    <tr><th>Param</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <tr><td><code>email</code></td><td>string</td><td>Yes</td><td>Email address to check</td></tr>
                </table>
                <strong>Returns:</strong> All basic fields + <code>breach_count</code>, <code>has_social_presence</code>, <code>deliverability_score</code>, <code>social_profiles{}</code>
            </div>
        </div>

        <h3><i class="fas fa-layer-group"></i> Bulk Operations & Reports</h3>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method post">POST</span>
                <span class="fps-ep-path">/v1/lookup/bulk</span>
                <span class="fps-ep-tier premium">Premium</span>
            </div>
            <div class="fps-ep-desc">Batch IP and email lookups in a single request. Submit up to 100 items per request.</div>
            <div class="fps-ep-params">
                <strong>Request body (JSON):</strong>
            </div>
            <div class="fps-code" style="margin-top:8px;">
{literal}{{/literal}<br>
&nbsp;&nbsp;<span class="key">"items"</span>: [<br>
&nbsp;&nbsp;&nbsp;&nbsp;{literal}{{ "type": "ip", "value": "1.2.3.4" }}{/literal},<br>
&nbsp;&nbsp;&nbsp;&nbsp;{literal}{{ "type": "email", "value": "test@example.com" }}{/literal}<br>
&nbsp;&nbsp;]<br>
{literal}}}{/literal}
            </div>
        </div>

        <div class="fps-ep">
            <div class="fps-ep-header">
                <span class="fps-ep-method get">GET</span>
                <span class="fps-ep-path">/v1/reports/country/{literal}{CC}{/literal}</span>
                <span class="fps-ep-tier premium">Premium</span>
            </div>
            <div class="fps-ep-desc">Country-level threat statistics with daily breakdown. Replace {literal}{CC}{/literal} with a 2-letter ISO country code (e.g., US, RU, CN).</div>
            <div class="fps-ep-params">
                <strong>Returns:</strong> <code>total_events</code>, <code>avg_risk_score</code>, <code>critical_events</code>, <code>high_events</code>, <code>daily_breakdown[]</code>
            </div>
        </div>
    </div>

    {* === CODE EXAMPLES === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-terminal"></i> Code Examples</h2>

        <h3>cURL</h3>
        <div class="fps-code">
# Check an IP address<br>
curl -H "X-FPS-API-Key: YOUR_KEY" \<br>
&nbsp;&nbsp;"https://your-domain.com/.../api.php?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4"
        </div>

        <h3>PHP</h3>
        <div class="fps-code">
$ch = curl_init();<br>
curl_setopt_array($ch, [<br>
&nbsp;&nbsp;CURLOPT_URL => 'https://your-domain.com/.../api.php?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4',<br>
&nbsp;&nbsp;CURLOPT_HTTPHEADER => ['X-FPS-API-Key: YOUR_KEY'],<br>
&nbsp;&nbsp;CURLOPT_RETURNTRANSFER => true,<br>
]);<br>
$result = json_decode(curl_exec($ch), true);<br>
curl_close($ch);<br>
echo $result['data']['is_vpn'] ? 'VPN detected!' : 'Clean IP';
        </div>

        <h3>Python</h3>
        <div class="fps-code">
import requests<br><br>
resp = requests.get(<br>
&nbsp;&nbsp;'https://your-domain.com/.../api.php',<br>
&nbsp;&nbsp;params={literal}{'endpoint': '/v1/lookup/ip-basic', 'ip': '1.2.3.4'}{/literal},<br>
&nbsp;&nbsp;headers={literal}{'X-FPS-API-Key': 'YOUR_KEY'}{/literal}<br>
)<br>
data = resp.json()['data']<br>
print(f"VPN: {literal}{data['is_vpn']}{/literal}, Tor: {literal}{data['is_tor']}{/literal}")
        </div>

        <h3>JavaScript (fetch)</h3>
        <div class="fps-code">
const resp = await fetch(<br>
&nbsp;&nbsp;'https://your-domain.com/.../api.php?endpoint=/v1/lookup/ip-basic&ip=1.2.3.4',<br>
&nbsp;&nbsp;{literal}{{ headers: { 'X-FPS-API-Key': 'YOUR_KEY' } }}{/literal}<br>
);<br>
const {literal}{{ data }}{/literal} = await resp.json();<br>
console.log(`VPN: ${literal}${data.is_vpn}{/literal}, Threat: ${literal}${data.threat_score}{/literal}`);
        </div>
    </div>

    {* === ERROR CODES === *}
    <div class="fps-api-section">
        <h2><i class="fas fa-exclamation-triangle"></i> Error Codes</h2>
        <table style="width:100%;border-collapse:collapse;">
            <tr style="background:var(--fps-pub-table-header);"><th style="padding:8px 12px;text-align:left;">Code</th><th style="padding:8px 12px;text-align:left;">Meaning</th><th style="padding:8px 12px;text-align:left;">Solution</th></tr>
            <tr><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);"><code>400</code></td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Bad Request</td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Missing or invalid parameters</td></tr>
            <tr><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);"><code>403</code></td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Forbidden</td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Endpoint not available on your tier -- upgrade API key</td></tr>
            <tr><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);"><code>404</code></td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Not Found</td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Invalid endpoint path</td></tr>
            <tr><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);"><code>429</code></td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Rate Limited</td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Wait for <code>Retry-After</code> seconds, or upgrade tier</td></tr>
            <tr><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);"><code>503</code></td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">Service Unavailable</td><td style="padding:8px 12px;border-bottom:1px solid var(--fps-pub-table-border);">API is disabled in module settings</td></tr>
        </table>
    </div>

</div>

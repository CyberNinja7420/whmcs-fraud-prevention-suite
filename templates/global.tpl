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
  --fps-pub-bg: #0f0c29;
  --fps-pub-text: #e0e0f0;
  --fps-pub-text-secondary: #b0b8d1;
  --fps-pub-text-muted: #8888aa;
  --fps-pub-card-bg: #1a1a3e;
  --fps-pub-card-border: #2a2a5e;
  --fps-pub-card-shadow: rgba(0,0,0,0.3);
  --fps-pub-input-bg: #1a1a3e;
  --fps-pub-input-border: #3a3a6e;
  --fps-pub-table-header: #1e1e4e;
  --fps-pub-table-border: #2a2a5e;
  --fps-pub-code-bg: #0a0820;
  --fps-pub-code-text: #b0b8d1;
}
.fps-global{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--fps-pub-text);line-height:1.7;max-width:1200px;margin:0 auto;}
.fps-global *{box-sizing:border-box;}
.fps-global-hero{background:linear-gradient(135deg,#0f0c29 0%,#1a1a3e 50%,#302b63 100%);color:#fff;padding:50px 30px;text-align:center;border-radius:16px;margin-bottom:32px;position:relative;overflow:hidden;}
.fps-global-hero h1{font-size:2.2rem;font-weight:800;margin:0 0 12px;}
.fps-global-hero p{font-size:1.1rem;color:#b0b8d1;margin:0 auto 20px;max-width:700px;}
.fps-global-nav{display:flex;gap:10px;justify-content:center;margin-bottom:32px;flex-wrap:wrap;}
.fps-global-nav a{padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;border:1px solid var(--fps-pub-card-border);color:#667eea;background:var(--fps-pub-card-bg);transition:all 0.2s;}
.fps-global-nav a:hover,.fps-global-nav a.active{background:#667eea;color:#fff;border-color:#667eea;}
.fps-g-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:32px;}
.fps-g-card{background:var(--fps-pub-card-bg);border-radius:12px;padding:24px;text-align:center;box-shadow:0 4px 16px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);}
.fps-g-card .val{font-size:2rem;font-weight:800;display:block;}
.fps-g-card .lbl{font-size:0.85rem;color:var(--fps-pub-text-muted);text-transform:uppercase;letter-spacing:0.5px;}
.fps-g-card .val.red{color:#eb3349;}.fps-g-card .val.blue{color:#667eea;}.fps-g-card .val.green{color:#11998e;}.fps-g-card .val.orange{color:#f5a623;}
.fps-g-section{background:var(--fps-pub-card-bg);border-radius:12px;padding:28px;box-shadow:0 2px 8px var(--fps-pub-card-shadow);border:1px solid var(--fps-pub-card-border);margin-bottom:24px;}
.fps-g-section h2{font-size:1.4rem;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:10px;color:var(--fps-pub-text);}
.fps-g-section h2 i{color:#667eea;}
.fps-g-bar{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.fps-g-bar .label{width:120px;font-size:0.9rem;font-weight:600;}
.fps-g-bar .bar{flex:1;height:24px;background:var(--fps-pub-table-border);border-radius:4px;overflow:hidden;}
.fps-g-bar .bar .fill{height:100%;border-radius:4px;transition:width 0.5s;}
.fps-g-bar .count{width:60px;text-align:right;font-size:0.85rem;font-weight:700;color:var(--fps-pub-text-secondary);}
.fps-g-table{width:100%;border-collapse:collapse;}
.fps-g-table th{background:var(--fps-pub-table-header);padding:10px 14px;text-align:left;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--fps-pub-text-muted);border-bottom:2px solid var(--fps-pub-card-border);}
.fps-g-table td{padding:10px 14px;border-bottom:1px solid var(--fps-pub-table-border);font-size:0.9rem;}
.fps-g-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:0.78rem;font-weight:700;}
.fps-g-badge.critical{background:rgba(235,51,73,0.12);color:#eb3349;}
.fps-g-badge.high{background:rgba(245,87,108,0.12);color:#f5576c;}
.fps-g-badge.medium{background:rgba(245,200,66,0.12);color:#d4a017;}
.fps-g-badge.low{background:rgba(56,239,125,0.12);color:#11998e;}
</style>
<script>if(localStorage.getItem('fps-pub-theme')==='dark')document.body.classList.add('fps-dark-mode');</script>
{/literal}

<div class="fps-global">

    <div class="fps-global-hero">
        <h1><i class="fas fa-earth-americas"></i> Global Threat Intelligence</h1>
        <p>Cross-instance fraud intelligence sharing powered by the Fraud Prevention Suite network. Anonymized, GDPR-compliant, community-driven protection.</p>
        <div style="margin-top:16px;position:relative;z-index:1;">
            <button onclick="document.body.classList.toggle('fps-dark-mode');localStorage.setItem('fps-pub-theme',document.body.classList.contains('fps-dark-mode')?'dark':'light')" style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.1);color:#fff;cursor:pointer;font-size:0.85rem;"><i class="fas fa-adjust"></i> Theme</button>
        </div>
    </div>

    <div class="fps-global-nav">
        <a href="{$overview_url}"><i class="fas fa-home"></i> Overview</a>
        <a href="{$global_url}" class="active"><i class="fas fa-earth-americas"></i> Global Intel</a>
        <a href="{$topology_url}"><i class="fas fa-globe"></i> Live Threat Map</a>
        <a href="{$api_docs_url}"><i class="fas fa-code"></i> API Docs</a>
    </div>

    {* === THREAT DETECTION STATS === *}
    <div class="fps-g-grid">
        <div class="fps-g-card"><span class="val blue">{$stats.total_checks|number_format}</span><span class="lbl">Total Checks</span></div>
        <div class="fps-g-card"><span class="val red">{$stats.threats_blocked|number_format}</span><span class="lbl">Threats Blocked</span></div>
        <div class="fps-g-card"><span class="val orange">{$stats.vpn_detected|number_format}</span><span class="lbl">VPNs Detected</span></div>
        <div class="fps-g-card"><span class="val red">{$stats.tor_detected|number_format}</span><span class="lbl">Tor Exit Nodes</span></div>
        <div class="fps-g-card"><span class="val orange">{$stats.proxy_detected|number_format}</span><span class="lbl">Proxies Flagged</span></div>
        <div class="fps-g-card"><span class="val red">{$stats.datacenter_detected|number_format}</span><span class="lbl">Datacenter IPs</span></div>
        <div class="fps-g-card"><span class="val blue">{$stats.unique_ips|number_format}</span><span class="lbl">Unique IPs</span></div>
        <div class="fps-g-card"><span class="val green">{$stats.countries_monitored}</span><span class="lbl">Countries</span></div>
        <div class="fps-g-card"><span class="val red">{$stats.bots_detected|number_format}</span><span class="lbl">Bots Detected</span></div>
        <div class="fps-g-card"><span class="val orange">{$stats.disposable_emails|number_format}</span><span class="lbl">Disposable Emails</span></div>
        <div class="fps-g-card"><span class="val blue">{$stats.global_intel_count|number_format}</span><span class="lbl">Global Intel Records</span></div>
        <div class="fps-g-card"><span class="val green">{$stats.provider_count}</span><span class="lbl">Detection Engines</span></div>
    </div>

    {* === ANONYMITY NETWORK DETECTION === *}
    <div class="fps-g-section">
        <h2><i class="fas fa-mask"></i> Anonymity & Obfuscation Detection</h2>
        <p style="color:var(--fps-pub-text-secondary);margin:0 0 20px;">Breakdown of detected anonymity tools and obfuscation methods across all scanned IPs.</p>

        {assign var="maxAnon" value=1}
        {if $stats.vpn_detected > $maxAnon}{assign var="maxAnon" value=$stats.vpn_detected}{/if}
        {if $stats.tor_detected > $maxAnon}{assign var="maxAnon" value=$stats.tor_detected}{/if}
        {if $stats.proxy_detected > $maxAnon}{assign var="maxAnon" value=$stats.proxy_detected}{/if}
        {if $stats.datacenter_detected > $maxAnon}{assign var="maxAnon" value=$stats.datacenter_detected}{/if}

        <div class="fps-g-bar">
            <span class="label"><i class="fas fa-shield-halved"></i> VPN</span>
            <div class="bar"><div class="fill" style="width:{if $maxAnon > 0}{math equation="x/y*100" x=$stats.vpn_detected y=$maxAnon}{else}0{/if}%;background:linear-gradient(90deg,#667eea,#764ba2);"></div></div>
            <span class="count">{$stats.vpn_detected|number_format}</span>
        </div>
        <div class="fps-g-bar">
            <span class="label"><i class="fas fa-user-secret"></i> Tor</span>
            <div class="bar"><div class="fill" style="width:{if $maxAnon > 0}{math equation="x/y*100" x=$stats.tor_detected y=$maxAnon}{else}0{/if}%;background:linear-gradient(90deg,#eb3349,#f45c43);"></div></div>
            <span class="count">{$stats.tor_detected|number_format}</span>
        </div>
        <div class="fps-g-bar">
            <span class="label"><i class="fas fa-arrows-rotate"></i> Proxy</span>
            <div class="bar"><div class="fill" style="width:{if $maxAnon > 0}{math equation="x/y*100" x=$stats.proxy_detected y=$maxAnon}{else}0{/if}%;background:linear-gradient(90deg,#f5a623,#f7c948);"></div></div>
            <span class="count">{$stats.proxy_detected|number_format}</span>
        </div>
        <div class="fps-g-bar">
            <span class="label"><i class="fas fa-server"></i> Datacenter</span>
            <div class="bar"><div class="fill" style="width:{if $maxAnon > 0}{math equation="x/y*100" x=$stats.datacenter_detected y=$maxAnon}{else}0{/if}%;background:linear-gradient(90deg,#11998e,#38ef7d);"></div></div>
            <span class="count">{$stats.datacenter_detected|number_format}</span>
        </div>
    </div>

    {* === RISK DISTRIBUTION === *}
    <div class="fps-g-section">
        <h2><i class="fas fa-chart-pie"></i> Risk Level Distribution</h2>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
            <div style="text-align:center;padding:16px;border-radius:10px;background:rgba(56,239,125,0.06);border:1px solid rgba(56,239,125,0.15);">
                <div style="font-size:1.8rem;font-weight:800;color:#11998e;">{$stats.risk_distribution.low|default:0}</div>
                <div style="font-size:0.85rem;color:var(--fps-pub-text-secondary);">Low Risk</div>
            </div>
            <div style="text-align:center;padding:16px;border-radius:10px;background:rgba(245,200,66,0.06);border:1px solid rgba(245,200,66,0.15);">
                <div style="font-size:1.8rem;font-weight:800;color:#d4a017;">{$stats.risk_distribution.medium|default:0}</div>
                <div style="font-size:0.85rem;color:var(--fps-pub-text-secondary);">Medium Risk</div>
            </div>
            <div style="text-align:center;padding:16px;border-radius:10px;background:rgba(245,87,108,0.06);border:1px solid rgba(245,87,108,0.15);">
                <div style="font-size:1.8rem;font-weight:800;color:#f5576c;">{$stats.risk_distribution.high|default:0}</div>
                <div style="font-size:0.85rem;color:var(--fps-pub-text-secondary);">High Risk</div>
            </div>
            <div style="text-align:center;padding:16px;border-radius:10px;background:rgba(235,51,73,0.06);border:1px solid rgba(235,51,73,0.15);">
                <div style="font-size:1.8rem;font-weight:800;color:#eb3349;">{$stats.risk_distribution.critical|default:0}</div>
                <div style="font-size:0.85rem;color:var(--fps-pub-text-secondary);">Critical Risk</div>
            </div>
        </div>
    </div>

    {* === TOP COUNTRIES === *}
    {if $stats.top_countries}
    <div class="fps-g-section">
        <h2><i class="fas fa-flag"></i> Top Source Countries</h2>
        <table class="fps-g-table">
            <thead><tr><th>Country</th><th>Events</th><th>Share</th></tr></thead>
            <tbody>
            {foreach from=$stats.top_countries key=cc item=cnt}
                <tr>
                    <td><strong>{$cc}</strong></td>
                    <td>{$cnt|number_format}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:8px;background:var(--fps-pub-table-border);border-radius:4px;overflow:hidden;">
                                <div style="height:100%;background:#667eea;border-radius:4px;width:{math equation="min(x/y*100,100)" x=$cnt y=$stats.total_checks}%;"></div>
                            </div>
                            <span style="font-size:0.8rem;color:var(--fps-pub-text-muted);">{math equation="round(x/y*100,1)" x=$cnt y=$stats.total_checks}%</span>
                        </div>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {* === HOW IT WORKS === *}
    <div class="fps-g-section">
        <h2><i class="fas fa-diagram-project"></i> How Global Threat Intelligence Works</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
            <div style="padding:20px;border-radius:10px;background:rgba(102,126,234,0.04);border:1px solid rgba(102,126,234,0.12);">
                <h3 style="margin:0 0 8px;font-size:1rem;"><i class="fas fa-database" style="color:#667eea;"></i> 1. Local Collection</h3>
                <p style="margin:0;font-size:0.9rem;color:var(--fps-pub-text-secondary);">Each WHMCS instance running FPS collects fraud signals locally: email hashes, IPs, risk scores, and boolean evidence flags.</p>
            </div>
            <div style="padding:20px;border-radius:10px;background:rgba(102,126,234,0.04);border:1px solid rgba(102,126,234,0.12);">
                <h3 style="margin:0 0 8px;font-size:1rem;"><i class="fas fa-cloud-arrow-up" style="color:#667eea;"></i> 2. Anonymized Sharing</h3>
                <p style="margin:0;font-size:0.9rem;color:var(--fps-pub-text-secondary);">Data is anonymized (SHA-256 email hashes) and pushed to the central hub. Raw emails, names, and billing data never leave your instance.</p>
            </div>
            <div style="padding:20px;border-radius:10px;background:rgba(102,126,234,0.04);border:1px solid rgba(102,126,234,0.12);">
                <h3 style="margin:0 0 8px;font-size:1rem;"><i class="fas fa-shield-halved" style="color:#667eea;"></i> 3. Cross-Reference</h3>
                <p style="margin:0;font-size:0.9rem;color:var(--fps-pub-text-secondary);">When new clients sign up, their email and IP are checked against the global database. Known fraudsters are flagged across all instances.</p>
            </div>
            <div style="padding:20px;border-radius:10px;background:rgba(102,126,234,0.04);border:1px solid rgba(102,126,234,0.12);">
                <h3 style="margin:0 0 8px;font-size:1rem;"><i class="fas fa-gavel" style="color:#667eea;"></i> 4. GDPR Compliant</h3>
                <p style="margin:0;font-size:0.9rem;color:var(--fps-pub-text-secondary);">Opt-in only. Full right to erasure (Art. 17). Data export (Art. 20). Configurable retention. IP sharing is optional and toggleable.</p>
            </div>
        </div>
    </div>

    {* === PRIVACY NOTICE === *}
    <div class="fps-g-section" style="background:linear-gradient(135deg,rgba(102,126,234,0.03),rgba(118,75,162,0.03));">
        <h2><i class="fas fa-user-shield"></i> Privacy & Data Protection</h2>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <div>
                <h4 style="color:#11998e;margin:0 0 8px;"><i class="fas fa-check-circle"></i> What IS Shared</h4>
                <ul style="margin:0;padding-left:20px;font-size:0.9rem;color:var(--fps-pub-text-secondary);">
                    <li>SHA-256 email hashes (irreversible)</li>
                    <li>IP addresses (optional -- admin toggle)</li>
                    <li>2-letter country codes</li>
                    <li>Risk scores (0-100)</li>
                    <li>Boolean evidence flags (tor: true/false)</li>
                </ul>
            </div>
            <div>
                <h4 style="color:#eb3349;margin:0 0 8px;"><i class="fas fa-times-circle"></i> What is NEVER Shared</h4>
                <ul style="margin:0;padding-left:20px;font-size:0.9rem;color:var(--fps-pub-text-secondary);">
                    <li>Raw email addresses or names</li>
                    <li>Phone numbers or billing addresses</li>
                    <li>Client IDs, order IDs, invoice data</li>
                    <li>Payment details or card numbers</li>
                    <li>Fingerprint or behavioral data</li>
                </ul>
            </div>
        </div>
    </div>

</div>

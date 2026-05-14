{literal}
<style>
/* FPS Client Usage Dashboard */
:root {
  --fps-u-bg: #f8fafc;
  --fps-u-card: #ffffff;
  --fps-u-border: #e2e8f0;
  --fps-u-text: #334155;
  --fps-u-text-secondary: #64748b;
  --fps-u-primary: #667eea;
  --fps-u-success: #16a34a;
  --fps-u-warning: #f59e0b;
  --fps-u-danger: #ef4444;
}
.fps-usage{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--fps-u-text);line-height:1.6;}
.fps-usage *{box-sizing:border-box;}

/* Header */
.fps-usage-header{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#16a34a 150%);color:#fff;padding:36px 28px;border-radius:16px;margin-bottom:28px;position:relative;overflow:hidden;}
.fps-usage-header::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse at 30% 40%,rgba(22,163,74,0.1),transparent 60%);pointer-events:none;}
.fps-usage-header h1{font-size:1.8rem;font-weight:800;margin:0 0 8px;position:relative;z-index:1;}
.fps-usage-header p{font-size:1rem;color:#e2e8f0;margin:0;position:relative;z-index:1;}
.fps-usage-header .fps-u-nav{display:flex;gap:12px;margin-top:16px;position:relative;z-index:1;}
.fps-usage-header .fps-u-nav a{color:#fff;text-decoration:none;padding:6px 16px;border-radius:8px;font-size:0.9rem;font-weight:600;border:1px solid rgba(255,255,255,0.3);transition:all 0.2s;}
.fps-usage-header .fps-u-nav a:hover{background:rgba(255,255,255,0.15);}
.fps-usage-header .fps-u-nav a.active{background:rgba(255,255,255,0.2);border-color:rgba(255,255,255,0.5);}

/* No keys state */
.fps-u-empty{text-align:center;padding:60px 20px;background:#fff;border-radius:16px;border:2px dashed #e2e8f0;}
.fps-u-empty i{font-size:3rem;color:#cbd5e1;margin-bottom:16px;display:block;}
.fps-u-empty h3{font-size:1.3rem;font-weight:700;color:#0f172a;margin:0 0 8px;}
.fps-u-empty p{font-size:1rem;color:#64748b;margin:0 0 20px;}
.fps-u-empty a.cta{display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:10px;text-decoration:none;font-weight:700;box-shadow:0 4px 14px rgba(22,163,74,0.3);transition:all 0.2s;}
.fps-u-empty a.cta:hover{box-shadow:0 6px 20px rgba(22,163,74,0.4);transform:translateY(-1px);}

/* Key cards */
.fps-u-keys{display:grid;grid-template-columns:1fr;gap:20px;}
.fps-u-key-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,0.04);transition:box-shadow 0.2s;}
.fps-u-key-card:hover{box-shadow:0 4px 16px rgba(15,23,42,0.08);}
.fps-u-key-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px;}
.fps-u-key-name{font-size:1.1rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;}
.fps-u-key-prefix{font-family:monospace;font-size:0.85rem;color:#64748b;background:#f1f5f9;padding:2px 8px;border-radius:4px;}
.fps-u-tier{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;}
.fps-u-tier-free{background:#d1fae5;color:#065f46;}
.fps-u-tier-basic{background:#dbeafe;color:#1e40af;}
.fps-u-tier-premium{background:#ede9fe;color:#5b21b6;}

/* Stats row inside key card */
.fps-u-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#f1f5f9;}
.fps-u-stat{background:#fff;padding:16px 14px;text-align:center;}
.fps-u-stat-value{font-size:1.5rem;font-weight:800;color:#0f172a;}
.fps-u-stat-label{font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
.fps-u-stat-value.today{color:#16a34a;}
.fps-u-stat-value.month{color:#2563eb;}
.fps-u-stat-value.limit{color:#64748b;}

/* Usage bar */
.fps-u-bar-container{padding:16px 24px;border-top:1px solid #f1f5f9;}
.fps-u-bar-label{display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:6px;}
.fps-u-bar-label strong{color:#0f172a;}
.fps-u-bar-label span{color:#64748b;}
.fps-u-bar{height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.fps-u-bar-fill{height:100%;border-radius:4px;transition:width 0.5s ease;}
.fps-u-bar-fill.green{background:linear-gradient(90deg,#16a34a,#4ade80);}
.fps-u-bar-fill.yellow{background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.fps-u-bar-fill.red{background:linear-gradient(90deg,#ef4444,#f87171);}

/* Chart area */
.fps-u-chart{padding:20px 24px;border-top:1px solid #f1f5f9;}
.fps-u-chart h4{font-size:0.95rem;font-weight:700;color:#0f172a;margin:0 0 12px;}
.fps-u-chart-bars{display:flex;align-items:flex-end;gap:3px;height:80px;padding:4px 0;}
.fps-u-chart-bar{flex:1;background:linear-gradient(to top,#667eea,#a78bfa);border-radius:2px 2px 0 0;min-width:4px;transition:height 0.3s;position:relative;cursor:default;}
.fps-u-chart-bar:hover{opacity:0.8;}
.fps-u-chart-bar:hover::after{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;font-size:0.7rem;padding:2px 6px;border-radius:4px;white-space:nowrap;z-index:10;}
.fps-u-chart-labels{display:flex;gap:3px;font-size:0.65rem;color:#94a3b8;margin-top:4px;}
.fps-u-chart-labels span{flex:1;text-align:center;overflow:hidden;}

/* Key meta */
.fps-u-meta{display:flex;gap:20px;padding:12px 24px;background:#f8fafc;border-top:1px solid #f1f5f9;font-size:0.82rem;color:#64748b;flex-wrap:wrap;}
.fps-u-meta span{display:flex;align-items:center;gap:5px;}

/* Upgrade CTA */
.fps-u-upgrade{background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:2px solid #bbf7d0;border-radius:14px;padding:24px;text-align:center;margin-top:24px;}
.fps-u-upgrade h3{font-size:1.1rem;font-weight:800;color:#0f172a;margin:0 0 8px;}
.fps-u-upgrade p{font-size:0.95rem;color:#475569;margin:0 0 16px;}
.fps-u-upgrade a{display:inline-block;padding:10px 24px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:0.95rem;transition:all 0.2s;}
.fps-u-upgrade a:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(22,163,74,0.3);}

/* Loading spinner */
.fps-u-loading{text-align:center;padding:40px;color:#94a3b8;}
.fps-u-loading i{font-size:2rem;animation:fps-spin 1s linear infinite;}
@keyframes fps-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

/* Responsive */
@media (max-width:640px) {
  .fps-u-stats{grid-template-columns:repeat(2,1fr);}
  .fps-usage-header{padding:24px 20px;}
  .fps-u-key-header{flex-direction:column;align-items:flex-start;}
}
</style>
{/literal}

<div class="fps-usage">

  <!-- Header -->
  <div class="fps-usage-header">
    <h1><i class="fas fa-chart-bar"></i> API Usage Dashboard</h1>
    <p>Monitor your API key usage, rate limits, and request history.</p>
    <div class="fps-u-nav">
      <a href="{$overview_url}">Overview</a>
      <a href="{$usage_url}" class="active">Usage</a>
      <a href="{$api_docs_url}">API Docs</a>
      <a href="{$store_url}">Plans</a>
    </div>
  </div>

  {if $client_id < 1}
    <!-- Not logged in -->
    <div class="fps-u-empty">
      <i class="fas fa-lock"></i>
      <h3>Login Required</h3>
      <p>Please log in to view your API key usage statistics.</p>
      <a href="clientarea.php" class="cta">Log In</a>
    </div>

  {elseif empty($api_keys)}
    <!-- No API keys -->
    <div class="fps-u-empty">
      <i class="fas fa-key"></i>
      <h3>No API Keys Found</h3>
      <p>You don't have any active API keys yet. Subscribe to get started with fraud prevention checks.</p>
      <a href="{$store_url}" class="cta"><i class="fas fa-rocket"></i> View API Plans</a>
    </div>

  {else}
    <!-- API Keys listing -->
    <div id="fps-u-keys-container" class="fps-u-keys">
      <div class="fps-u-loading">
        <i class="fas fa-circle-notch"></i>
        <p>Loading usage data...</p>
      </div>
    </div>

    <!-- Upgrade CTA for free tier -->
    {foreach from=$api_keys item=key}
      {if $key.tier == 'free'}
        <div class="fps-u-upgrade">
          <h3><i class="fas fa-arrow-up"></i> Upgrade Your Plan</h3>
          <p>Unlock higher rate limits, priority support, and advanced endpoints with a paid plan.</p>
          <a href="{$store_url}"><i class="fas fa-rocket"></i> Compare Plans</a>
        </div>
        {break}
      {/if}
    {/foreach}
  {/if}

</div>

{if $client_id > 0 && !empty($api_keys)}
{literal}
<script>
(function() {
  var ajaxUrl = '{/literal}{$ajax_url}{literal}';
  var container = document.getElementById('fps-u-keys-container');
  if (!container) return;

  function fpsLoadUsage() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl, true);
    xhr.onreadystatechange = function() {
      if (xhr.readyState !== 4) return;
      try {
        var resp = JSON.parse(xhr.responseText);
        if (resp.success && resp.keys) {
          fpsRenderKeys(resp.keys);
        } else {
          fpsShowError(resp.error || 'Failed to load usage data');
        }
      } catch(e) {
        fpsShowError('Failed to parse response');
      }
    };
    xhr.send();
  }

  function fpsShowError(msg) {
    while (container.firstChild) container.removeChild(container.firstChild);
    var wrapper = document.createElement('div');
    wrapper.className = 'fps-u-empty';
    var icon = document.createElement('i');
    icon.className = 'fas fa-exclamation-triangle';
    var h3 = document.createElement('h3');
    h3.textContent = 'Error';
    var p = document.createElement('p');
    p.textContent = msg;
    wrapper.appendChild(icon);
    wrapper.appendChild(h3);
    wrapper.appendChild(p);
    container.appendChild(wrapper);
  }

  function fpsRenderKeys(keys) {
    while (container.firstChild) container.removeChild(container.firstChild);
    for (var i = 0; i < keys.length; i++) {
      container.appendChild(fpsRenderKeyCard(keys[i]));
    }
  }

  function fpsRenderKeyCard(key) {
    var tierClass = 'fps-u-tier-' + key.tier;
    var tierLabel = key.tier.charAt(0).toUpperCase() + key.tier.slice(1);
    var todayPct = key.rate_limit_per_day > 0 ? Math.min(100, Math.round((key.requests_today / key.rate_limit_per_day) * 100)) : 0;
    var barClass = todayPct < 60 ? 'green' : (todayPct < 85 ? 'yellow' : 'red');
    var remaining = Math.max(0, key.rate_limit_per_day - key.requests_today);
    var lastUsed = key.last_used_at ? fpsFormatDate(key.last_used_at) : 'Never';
    var created = key.created_at ? fpsFormatDate(key.created_at) : '--';

    var card = document.createElement('div');
    card.className = 'fps-u-key-card';

    // Header
    var header = document.createElement('div');
    header.className = 'fps-u-key-header';

    var nameDiv = document.createElement('div');
    nameDiv.className = 'fps-u-key-name';
    var keyIcon = document.createElement('i');
    keyIcon.className = 'fas fa-key';
    nameDiv.appendChild(keyIcon);
    nameDiv.appendChild(document.createTextNode(' ' + key.name + ' '));
    var prefix = document.createElement('span');
    prefix.className = 'fps-u-key-prefix';
    prefix.textContent = key.key_prefix + '...';
    nameDiv.appendChild(prefix);
    header.appendChild(nameDiv);

    var tierSpan = document.createElement('span');
    tierSpan.className = 'fps-u-tier ' + tierClass;
    var tagIcon = document.createElement('i');
    tagIcon.className = 'fas fa-tag';
    tierSpan.appendChild(tagIcon);
    tierSpan.appendChild(document.createTextNode(' ' + tierLabel));
    header.appendChild(tierSpan);
    card.appendChild(header);

    // Stats grid
    var statsGrid = document.createElement('div');
    statsGrid.className = 'fps-u-stats';
    statsGrid.appendChild(fpsCreateStat(fpsFormatNum(key.requests_today), 'Today', 'today'));
    statsGrid.appendChild(fpsCreateStat(fpsFormatNum(key.requests_month), 'This Month', 'month'));
    statsGrid.appendChild(fpsCreateStat(fpsFormatNum(key.total_requests), 'All Time', ''));
    statsGrid.appendChild(fpsCreateStat(fpsFormatNum(remaining), 'Remaining Today', 'limit'));
    card.appendChild(statsGrid);

    // Usage bar
    var barContainer = document.createElement('div');
    barContainer.className = 'fps-u-bar-container';
    var barLabel = document.createElement('div');
    barLabel.className = 'fps-u-bar-label';
    var barStrong = document.createElement('strong');
    barStrong.textContent = 'Daily Usage';
    var barSpan = document.createElement('span');
    barSpan.textContent = todayPct + '% of ' + fpsFormatNum(key.rate_limit_per_day) + '/day';
    barLabel.appendChild(barStrong);
    barLabel.appendChild(barSpan);
    barContainer.appendChild(barLabel);
    var barOuter = document.createElement('div');
    barOuter.className = 'fps-u-bar';
    var barFill = document.createElement('div');
    barFill.className = 'fps-u-bar-fill ' + barClass;
    barFill.style.width = todayPct + '%';
    barOuter.appendChild(barFill);
    barContainer.appendChild(barOuter);
    card.appendChild(barContainer);

    // Chart
    var chartSection = document.createElement('div');
    chartSection.className = 'fps-u-chart';
    var chartTitle = document.createElement('h4');
    var chartIcon = document.createElement('i');
    chartIcon.className = 'fas fa-chart-area';
    chartTitle.appendChild(chartIcon);
    chartTitle.appendChild(document.createTextNode(' Last 30 Days'));
    chartSection.appendChild(chartTitle);
    fpsRenderChart(chartSection, key.daily_usage);
    card.appendChild(chartSection);

    // Meta
    var meta = document.createElement('div');
    meta.className = 'fps-u-meta';
    meta.appendChild(fpsCreateMeta('fas fa-tachometer-alt', 'Rate: ' + key.rate_limit_per_minute + '/min'));
    meta.appendChild(fpsCreateMeta('fas fa-clock', 'Last used: ' + lastUsed));
    meta.appendChild(fpsCreateMeta('fas fa-calendar', 'Created: ' + created));
    card.appendChild(meta);

    return card;
  }

  function fpsCreateStat(value, label, extraClass) {
    var stat = document.createElement('div');
    stat.className = 'fps-u-stat';
    var val = document.createElement('div');
    val.className = 'fps-u-stat-value' + (extraClass ? ' ' + extraClass : '');
    val.textContent = value;
    var lbl = document.createElement('div');
    lbl.className = 'fps-u-stat-label';
    lbl.textContent = label;
    stat.appendChild(val);
    stat.appendChild(lbl);
    return stat;
  }

  function fpsCreateMeta(iconClass, text) {
    var span = document.createElement('span');
    var icon = document.createElement('i');
    icon.className = iconClass;
    span.appendChild(icon);
    span.appendChild(document.createTextNode(' ' + text));
    return span;
  }

  function fpsRenderChart(parent, dailyUsage) {
    var days = [];
    var values = [];
    var maxVal = 1;
    for (var d = 29; d >= 0; d--) {
      var dt = new Date();
      dt.setDate(dt.getDate() - d);
      var k = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
      var val = (dailyUsage && dailyUsage[k]) ? dailyUsage[k] : 0;
      days.push(k);
      values.push(val);
      if (val > maxVal) maxVal = val;
    }

    var barsDiv = document.createElement('div');
    barsDiv.className = 'fps-u-chart-bars';
    var labelsDiv = document.createElement('div');
    labelsDiv.className = 'fps-u-chart-labels';

    for (var i = 0; i < 30; i++) {
      var pct = Math.max(2, Math.round((values[i] / maxVal) * 100));
      var bar = document.createElement('div');
      bar.className = 'fps-u-chart-bar';
      bar.style.height = pct + '%';
      bar.setAttribute('data-tooltip', days[i] + ': ' + values[i] + ' requests');
      barsDiv.appendChild(bar);

      var lbl = document.createElement('span');
      if (i === 0 || i === 7 || i === 14 || i === 21 || i === 29) {
        var parts = days[i].split('-');
        lbl.textContent = parseInt(parts[1]) + '/' + parseInt(parts[2]);
      }
      labelsDiv.appendChild(lbl);
    }

    parent.appendChild(barsDiv);
    parent.appendChild(labelsDiv);
  }

  function fpsFormatNum(n) {
    return (n || 0).toLocaleString();
  }

  function fpsFormatDate(str) {
    if (!str) return '--';
    try {
      var d = new Date(str.replace(' ', 'T'));
      return d.toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'});
    } catch(e) {
      return str.substring(0, 10);
    }
  }

  // Load on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fpsLoadUsage);
  } else {
    fpsLoadUsage();
  }
})();
</script>
{/literal}
{/if}

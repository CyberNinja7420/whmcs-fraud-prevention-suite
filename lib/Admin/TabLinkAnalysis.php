<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Admin;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * TabLinkAnalysis -- fraud-ring / shared-identity clustering.
 *
 * Links accounts the way Kount links transactions: surfaces IP addresses,
 * device fingerprints, and emails shared across multiple distinct accounts,
 * with the count of blocks in each cluster -- so an operator can spot a ring
 * ("this device is shared by 5 accounts, 4 of them blocked"). Data comes from
 * the get_link_analysis AJAX endpoint (live mod_fps_checks + mod_fps_fingerprints).
 *
 * All dynamic cell content is rendered with DOM textContent (no innerHTML of
 * user/DB-derived values) to avoid any XSS surface.
 */
class TabLinkAnalysis
{
    public function render(array $vars, string $modulelink): void
    {
        $token = function_exists('generate_token') ? generate_token('plain') : ($_SESSION['token'] ?? '');
        $tokenJs = json_encode($token);
        $urlJs   = json_encode($modulelink . '&ajax=1');

        echo <<<HTML
<div class="fps-card">
  <div class="fps-card-header"><i class="fas fa-diagram-project"></i> Fraud-Ring Link Analysis</div>
  <div class="fps-card-body">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
      <span class="fps-text-muted" style="font-size:0.85rem;">
        Accounts linked by shared IP, device fingerprint, or email. A cluster with multiple
        accounts &mdash; especially with blocks &mdash; is a likely fraud ring.
      </span>
      <div style="display:flex;gap:4px;" id="fps-la-period">
        <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" data-d="30" onclick="fpsLaLoad(30)">30d</button>
        <button type="button" class="fps-btn fps-btn-xs fps-btn-primary" data-d="90" onclick="fpsLaLoad(90)">90d</button>
        <button type="button" class="fps-btn fps-btn-xs fps-btn-outline" data-d="365" onclick="fpsLaLoad(365)">1y</button>
      </div>
    </div>
    <div id="fps-la-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;"></div>
  </div>
</div>

<div class="fps-card">
  <div class="fps-card-header"><i class="fas fa-network-wired"></i> Shared IP Addresses (multi-account)</div>
  <div class="fps-card-body"><div id="fps-la-ips"></div></div>
</div>

<div class="fps-card">
  <div class="fps-card-header"><i class="fas fa-fingerprint"></i> Shared Device Fingerprints (multi-account)</div>
  <div class="fps-card-body"><div id="fps-la-devices"></div></div>
</div>

<div class="fps-card">
  <div class="fps-card-header"><i class="fas fa-envelope"></i> Shared Emails Across Blocked Checks</div>
  <div class="fps-card-body"><div id="fps-la-emails"></div></div>
</div>
HTML;

        echo '<script>(function(){';
        echo 'var laUrl=' . $urlJs . ';var laToken=' . $tokenJs . ';';
        echo <<<'JS'
function fpsLaText(tag,txt,css){var e=document.createElement(tag);e.textContent=(txt==null?'':String(txt));if(css)e.style.cssText=css;return e;}
function fpsLaStat(label,val,color){
  var c=document.createElement('div');
  c.style.cssText='padding:12px;border-radius:8px;border:1px solid rgba(102,126,234,0.12);text-align:center;';
  c.appendChild(fpsLaText('div',val,'font-size:1.5rem;font-weight:700;color:'+(color||'#667eea')+';'));
  c.appendChild(fpsLaText('div',label,'font-size:0.72rem;color:#6a7195;text-transform:uppercase;letter-spacing:0.05em;'));
  return c;
}
function fpsLaBadge(n,danger){
  var s=document.createElement('span');
  if(!n||n<=0){s.className='fps-text-muted';s.textContent='0';return s;}
  s.className='fps-badge '+(danger?'fps-badge-critical':'fps-badge-medium');s.textContent=String(n);return s;
}
function fpsLaTable(container,cols,rows,emptyMsg){
  var el=document.getElementById(container); el.textContent='';
  if(!rows||rows.length===0){el.appendChild(fpsLaText('p',emptyMsg,'padding:8px;color:#6a7195;'));return;}
  var t=document.createElement('table');t.className='fps-table';t.style.width='100%';
  var thead=document.createElement('thead');var htr=document.createElement('tr');
  cols.forEach(function(c){var th=fpsLaText('th',c.label);th.style.textAlign=c.align||'left';htr.appendChild(th);});
  thead.appendChild(htr);t.appendChild(thead);
  var tbody=document.createElement('tbody');
  rows.forEach(function(r){
    var tr=document.createElement('tr');
    cols.forEach(function(c){var td=document.createElement('td');td.style.textAlign=c.align||'left';var node=c.cell(r);
      if(node instanceof Node)td.appendChild(node);else td.textContent=(node==null?'':String(node));tr.appendChild(td);});
    tbody.appendChild(tr);
  });
  t.appendChild(tbody);el.appendChild(t);
}
window.fpsLaLoad=function(days){
  document.querySelectorAll('#fps-la-period button').forEach(function(b){
    b.className='fps-btn fps-btn-xs '+(parseInt(b.getAttribute('data-d'))===days?'fps-btn-primary':'fps-btn-outline');
  });
  var u=laUrl+'&a=get_link_analysis&days='+days+'&token='+encodeURIComponent(laToken);
  fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    var sg=document.getElementById('fps-la-summary');sg.textContent='';
    if(!j.success){sg.appendChild(fpsLaText('p',j.error||'Failed to load','color:#ef4444;'));return;}
    var d=j.data, s=d.summary||{};
    sg.appendChild(fpsLaStat('IP Clusters',s.ip_clusters||0,'#667eea'));
    sg.appendChild(fpsLaStat('IP Rings w/ Blocks',s.ip_rings_blocked||0,(s.ip_rings_blocked>0?'#ef4444':'#16a34a')));
    sg.appendChild(fpsLaStat('Device Clusters',s.device_clusters||0,(s.device_clusters>0?'#f59e0b':'#16a34a')));
    sg.appendChild(fpsLaStat('Email Clusters',s.email_clusters||0,(s.email_clusters>0?'#f59e0b':'#16a34a')));

    fpsLaTable('fps-la-ips',[
      {label:'IP Address',cell:function(r){return r.ip_address;}},
      {label:'Country',cell:function(r){return r.country||'-';}},
      {label:'Accounts',align:'center',cell:function(r){return fpsLaBadge(r.accounts,false);}},
      {label:'Checks',align:'center',cell:function(r){return r.checks;}},
      {label:'Blocked',align:'center',cell:function(r){return fpsLaBadge(r.blocks,true);}},
      {label:'Peak Risk',align:'center',cell:function(r){return r.peak_risk;}}
    ],d.shared_ips,'No IPs shared across multiple accounts.');

    fpsLaTable('fps-la-devices',[
      {label:'Device Fingerprint',cell:function(r){var code=document.createElement('code');code.textContent=String(r.fingerprint_hash).substring(0,24)+'…';return code;}},
      {label:'Accounts',align:'center',cell:function(r){return fpsLaBadge(r.accounts,true);}},
      {label:'Sightings',align:'center',cell:function(r){return r.sightings;}}
    ],d.shared_devices,'No device shared across multiple accounts.');

    fpsLaTable('fps-la-emails',[
      {label:'Email',cell:function(r){return r.email;}},
      {label:'Accounts',align:'center',cell:function(r){return r.accounts;}},
      {label:'Checks',align:'center',cell:function(r){return r.checks;}},
      {label:'Blocked',align:'center',cell:function(r){return fpsLaBadge(r.blocks,true);}}
    ],d.shared_emails,'No emails repeated across blocked checks.');
  }).catch(function(){var sg=document.getElementById('fps-la-summary');sg.textContent='';sg.appendChild(fpsLaText('p','Network error loading link analysis','color:#ef4444;'));});
};
fpsLaLoad(90);
JS;
        echo '})();</script>';
    }
}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Fraud Threat Topology - Fraud Prevention Suite</title>
    <link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-topology.css?v={$module_version}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- API base and initial stats are injected server-side by PHP -->
    <style>
    /* Colorblind mode for topology page -- override the green CSS variable at root */
    body.cb-mode{--topo-green:#2563eb!important}
    body.cb-mode .topo-live-dot{background:#2563eb!important;box-shadow:0 0 8px rgba(37,99,235,0.6)!important}
    body.cb-mode .topo-stat-bar-value--green{color:#2563eb!important}
    body.cb-mode .topo-event-icon--approved,body.cb-mode .topo-event-icon--low{color:#2563eb!important}
    body.cb-mode .topo-event-tag--low{color:#2563eb!important;border-color:#2563eb!important;background:rgba(37,99,235,0.1)!important}
    body.cb-mode .topo-event-tag--medium{color:#f59e0b!important;border-color:#f59e0b!important;background:rgba(245,158,11,0.1)!important}
    body.cb-mode .topo-event-tag--high{color:#dc2626!important;border-color:#dc2626!important;background:rgba(220,38,38,0.1)!important}
    body.cb-mode .topo-event-tag--critical{color:#dc2626!important;border-color:#dc2626!important;background:rgba(220,38,38,0.15)!important}
    body.cb-mode .topo-stat-bar-value--amber{color:#f59e0b!important}
    body.cb-mode .topo-stat-bar-value--red{color:#dc2626!important}
    body.cb-mode [class*="topo"] .fa-circle-check,body.cb-mode [class*="topo"] .fa-check{color:#2563eb!important}
    body.cb-mode .topo-header{background:linear-gradient(135deg,#0f172a,#1e3a5f)!important}
    body.cb-mode .topo-brand-icon{background:linear-gradient(135deg,#2563eb,#1d4ed8)!important}
    body.cb-mode .topo-nav-btn.active{border-color:#2563eb!important;color:#2563eb!important}
    body.cb-mode .topo-feed-section{border-color:rgba(37,99,235,0.15)!important}
    /* Topology bar charts: green -> blue */
    body.cb-mode [style*="background-color: rgb(57, 255, 20)"],body.cb-mode [style*="background:#39ff14"],body.cb-mode [style*="background-color:#39ff14"]{background-color:#2563eb!important}
    body.cb-mode [style*="color: rgb(57, 255, 20)"],body.cb-mode [style*="color:#39ff14"]{color:#2563eb!important}
    /* Toggle button */
    .fps-cb-toggle{position:fixed;bottom:20px;left:20px;z-index:9999;width:48px;height:48px;border-radius:50%;border:2px solid rgba(255,255,255,0.2);background:rgba(15,23,42,0.8);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.3);transition:all 0.3s;font-size:1.2rem;color:#fff;}
    .fps-cb-toggle:hover{box-shadow:0 6px 20px rgba(0,0,0,0.4);transform:scale(1.05);}
    .fps-cb-toggle[aria-pressed="true"]{background:#2563eb;border-color:#2563eb;}
    .fps-cb-toggle .fps-cb-tooltip{display:none;position:absolute;bottom:56px;left:0;background:#0f172a;color:#fff;padding:6px 12px;border-radius:8px;font-size:0.75rem;white-space:nowrap;pointer-events:none;}
    .fps-cb-toggle:hover .fps-cb-tooltip{display:block;}
    </style>
</head>
<body class="fps-topology-page" style="width:100vw;height:100vh;margin:0;padding:0;max-width:none;overflow-x:hidden;">

    <div class="topo-header">
        <div class="topo-brand">
            <div class="topo-brand-icon">
                <i class="fas fa-shield-halved"></i>
            </div>
            <div>
                <div class="topo-brand-name">Global Fraud Threat Topology</div>
                <div class="topo-brand-sub">Real-time fraud intelligence powered by FPS v{$module_version}</div>
            </div>
        </div>
        <div class="topo-header-right">
            <div class="topo-live-dot">LIVE</div>
            <div class="topo-time-selector">
                <button class="topo-time-btn" data-hours="1">1H</button>
                <button class="topo-time-btn topo-active" data-hours="24">24H</button>
                <button class="topo-time-btn" data-hours="168">7D</button>
                <button class="topo-time-btn" data-hours="720">30D</button>
                <button class="topo-time-btn" data-hours="2160">90D</button>
                <button class="topo-time-btn" data-hours="0">ALL</button>
            </div>
            <button class="topo-control-btn" id="fps-fullscreen" title="Fullscreen" style="background:rgba(0,212,255,0.1);border:1px solid rgba(0,212,255,0.2);border-radius:6px;color:var(--topo-cyan);width:32px;height:32px;cursor:pointer;">
                <i class="fas fa-expand"></i>
            </button>
        </div>
    </div>

    <div class="topo-main">
        <div class="topo-globe-section">
            <div class="topo-globe-wrap">
                <div class="fps-topo-globe-container" id="fps-globe-container">
                    <div class="topo-globe-loader" id="fps-globe-loading">
                        <div class="topo-globe-loader-rings"></div>
                        <div class="topo-globe-loader-text">Initializing Threat Topology...</div>
                    </div>
                </div>
            </div>
            <div class="topo-stat-bar">
                <div class="topo-stat-bar-item">
                    <div class="topo-stat-bar-value" id="fps-stat-events">0</div>
                    <div class="topo-stat-bar-label">Events Tracked</div>
                </div>
                <div class="topo-stat-bar-item">
                    <div class="topo-stat-bar-value topo-stat-bar-value--green" id="fps-stat-countries">0</div>
                    <div class="topo-stat-bar-label">Active Countries</div>
                </div>
                <div class="topo-stat-bar-item">
                    <div class="topo-stat-bar-value topo-stat-bar-value--red" id="fps-stat-threats">0</div>
                    <div class="topo-stat-bar-label">Active Threats</div>
                </div>
                <div class="topo-stat-bar-item">
                    <div class="topo-stat-bar-value topo-stat-bar-value--amber" id="fps-stat-blockrate">0%</div>
                    <div class="topo-stat-bar-label">Block Rate</div>
                </div>
            </div>
        </div>

        <div class="topo-feed-section">
            <div class="topo-feed-header">
                <div class="topo-feed-title">Live Threat Feed</div>
                <div class="topo-feed-count" id="fps-feed-count">0 events</div>
            </div>
            <div class="topo-feed-body" id="fps-events-feed">
                <!-- Events populated by JS -->
            </div>

            <div class="topo-feed-header" style="border-top:1px solid var(--topo-border);">
                <div class="topo-feed-title"><i class="fas fa-globe-americas" style="margin-right:4px;"></i> Top Threat Origins</div>
            </div>
            <div class="topo-feed-body" id="fps-top-countries" style="max-height:200px;">
                <!-- Countries populated by JS -->
            </div>
        </div>
    </div>

    <div class="topo-footer">
        <span>Fraud Prevention Suite v{$module_version} by EnterpriseVPS</span>
        <span>Data updated every 60 seconds</span>
        <a href="?m=fraud_prevention_suite" style="color:var(--topo-cyan);text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Back to Overview
        </a>
    </div>

    <!-- 3D vendor libs: prefer the vendored copies under assets/vendor/.
         The PHP topology block substitutes the {THREE_SRC} / {GLOBE_SRC}
         placeholders below before emission so we avoid a CDN runtime
         dependency. Falls back to jsdelivr only if the vendor file is missing
         on disk at the time the topology page is rendered. -->
    <script src="{THREE_SRC}"></script>
    <script src="{GLOBE_SRC}"></script>
    <script src="/modules/addons/fraud_prevention_suite/assets/js/fps-topology.js?v={$module_version}"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof FpsTopology !== 'undefined') {
            FpsTopology.init();
        }
    });
    </script>

    <!-- Colorblind accessibility toggle -->
    <button class="fps-cb-toggle" id="fps-cb-btn" aria-pressed="false" aria-label="Toggle colorblind-friendly mode" title="Colorblind-friendly mode">
        <span style="font-size:1.1rem;">&#128065;</span>
        <span class="fps-cb-tooltip">Colorblind-Friendly Mode</span>
    </button>
    <script>
    (function(){
        var btn=document.getElementById("fps-cb-btn");
        if(!btn)return;
        var active=localStorage.getItem("fps-cb-mode")==="1";
        if(active){document.body.classList.add("cb-mode");btn.setAttribute("aria-pressed","true");}
        btn.addEventListener("click",function(){
            active=!active;
            document.body.classList.toggle("cb-mode",active);
            btn.setAttribute("aria-pressed",active?"true":"false");
            localStorage.setItem("fps-cb-mode",active?"1":"0");
            // Re-color topology bar charts inline styles
            document.querySelectorAll('[style]').forEach(function(el){
                var s=el.style;
                if(active){
                    if(s.backgroundColor==='rgb(57, 255, 20)') s.backgroundColor='#2563eb';
                    if(s.color==='rgb(57, 255, 20)') s.color='#2563eb';
                } else {
                    if(s.backgroundColor==='#2563eb'||s.backgroundColor==='rgb(37, 99, 235)') s.backgroundColor='rgb(57, 255, 20)';
                    if(s.color==='#2563eb'||s.color==='rgb(37, 99, 235)') s.color='rgb(57, 255, 20)';
                }
            });
        });
        // Apply on initial load if active
        if(active){
            setTimeout(function(){
                document.querySelectorAll('[style]').forEach(function(el){
                    var s=el.style;
                    if(s.backgroundColor==='rgb(57, 255, 20)') s.backgroundColor='#2563eb';
                    if(s.color==='rgb(57, 255, 20)') s.color='#2563eb';
                });
            }, 2000); // Wait for topology JS to render
        }
    })();
    </script>

</body>
</html>

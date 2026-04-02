<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Fraud Threat Topology - Fraud Prevention Suite</title>
    <link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-topology.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- API base and initial stats are injected server-side by PHP -->
</head>
<body class="fps-topology-page" style="width:100vw;height:100vh;margin:0;padding:0;max-width:none;overflow-x:hidden;">

    <div class="topo-header">
        <div class="topo-brand">
            <div class="topo-brand-icon">
                <i class="fas fa-shield-halved"></i>
            </div>
            <div>
                <div class="topo-brand-name">Global Fraud Threat Topology</div>
                <div class="topo-brand-sub">Real-time fraud intelligence powered by FPS v4.1.3</div>
            </div>
        </div>
        <div class="topo-header-right">
            <div class="topo-live-dot">LIVE</div>
            <div class="topo-time-selector">
                <button class="topo-time-btn" data-hours="1">1H</button>
                <button class="topo-time-btn topo-active" data-hours="24">24H</button>
                <button class="topo-time-btn" data-hours="168">7D</button>
                <button class="topo-time-btn" data-hours="720">30D</button>
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
        <span>Fraud Prevention Suite v4.1.3 by EnterpriseVPS</span>
        <span>Data updated every 60 seconds</span>
        <a href="?m=fraud_prevention_suite" style="color:var(--topo-cyan);text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Back to Overview
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/globe.gl@2.31.0/dist/globe.gl.min.js"></script>
    <script src="/modules/addons/fraud_prevention_suite/assets/js/fps-topology.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof FpsTopology !== 'undefined') {
            FpsTopology.init();
        }
    });
    </script>

</body>
</html>

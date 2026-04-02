<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Fraud Threat Topology - Fraud Prevention Suite</title>
    <link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-topology.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
        <div class="topo-controls">
            <div class="topo-time-range">
                <button class="topo-time-btn" data-hours="1">1H</button>
                <button class="topo-time-btn topo-active" data-hours="24">24H</button>
                <button class="topo-time-btn" data-hours="168">7D</button>
                <button class="topo-time-btn" data-hours="720">30D</button>
            </div>
            <button class="topo-control-btn" id="fps-fullscreen" title="Fullscreen">
                <i class="fas fa-expand"></i>
            </button>
        </div>
    </div>

    <div class="topo-stats">
        <div class="topo-stat">
            <div class="topo-stat-value" id="fps-stat-events" data-countup="0">0</div>
            <div class="topo-stat-label">Events Tracked</div>
        </div>
        <div class="topo-stat">
            <div class="topo-stat-value topo-cyan" id="fps-stat-countries" data-countup="0">0</div>
            <div class="topo-stat-label">Active Countries</div>
        </div>
        <div class="topo-stat">
            <div class="topo-stat-value topo-red" id="fps-stat-threats" data-countup="0">0</div>
            <div class="topo-stat-label">Active Threats</div>
        </div>
        <div class="topo-stat">
            <div class="topo-stat-value topo-amber" id="fps-stat-blockrate" data-countup="0">0%</div>
            <div class="topo-stat-label">Block Rate</div>
        </div>
    </div>

    <div class="topo-main">
        <div class="fps-topo-globe-container" id="fps-globe-container">
            <div class="topo-loading" id="fps-globe-loading">
                <div class="topo-spinner"></div>
                <p>Initializing Threat Topology...</p>
            </div>
        </div>

        <div class="topo-sidebar">
            <div class="topo-sidebar-section">
                <h3><i class="fas fa-bolt"></i> Live Threat Feed</h3>
                <span class="topo-live-dot"></span> <span style="color:var(--topo-green);font-size:0.75rem;font-weight:700;">LIVE</span>
            </div>
            <div class="topo-event-feed" id="fps-events-feed">
                <!-- Events populated by JS -->
            </div>

            <div class="topo-sidebar-section">
                <h4><i class="fas fa-globe-americas"></i> Top Threat Origins</h4>
                <div id="fps-top-countries" class="topo-country-list">
                    <!-- Countries populated by JS -->
                </div>
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Fraud Threat Topology - Fraud Prevention Suite</title>
    <link rel="stylesheet" href="/modules/addons/fraud_prevention_suite/assets/css/fps-topology.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="fps-topology-page">

    <div class="fps-topo-header">
        <div class="fps-topo-brand">
            <div class="fps-topo-logo">
                <i class="fas fa-shield-halved"></i>
            </div>
            <div class="fps-topo-title">
                <h1>Global Fraud Threat Topology</h1>
                <span class="fps-topo-subtitle">Real-time fraud intelligence powered by FPS v4.1.2</span>
            </div>
        </div>
        <div class="fps-topo-controls">
            <div class="fps-topo-time-range">
                <button class="fps-topo-time-btn" data-hours="1">1H</button>
                <button class="fps-topo-time-btn active" data-hours="24">24H</button>
                <button class="fps-topo-time-btn" data-hours="168">7D</button>
                <button class="fps-topo-time-btn" data-hours="720">30D</button>
            </div>
            <button class="fps-topo-control-btn" id="fps-fullscreen" title="Fullscreen">
                <i class="fas fa-expand"></i>
            </button>
        </div>
    </div>

    <div class="fps-topo-stats">
        <div class="fps-topo-stat">
            <div class="fps-topo-stat-value" id="fps-stat-events" data-countup="0">0</div>
            <div class="fps-topo-stat-label">Events Tracked</div>
        </div>
        <div class="fps-topo-stat">
            <div class="fps-topo-stat-value fps-glow-cyan" id="fps-stat-countries" data-countup="0">0</div>
            <div class="fps-topo-stat-label">Active Countries</div>
        </div>
        <div class="fps-topo-stat">
            <div class="fps-topo-stat-value fps-glow-red" id="fps-stat-threats" data-countup="0">0</div>
            <div class="fps-topo-stat-label">Active Threats</div>
        </div>
        <div class="fps-topo-stat">
            <div class="fps-topo-stat-value fps-glow-amber" id="fps-stat-blockrate" data-countup="0">0%</div>
            <div class="fps-topo-stat-label">Block Rate</div>
        </div>
    </div>

    <div class="fps-topo-main">
        <div class="fps-topo-globe-container" id="fps-globe-container">
            <div class="fps-topo-loading" id="fps-globe-loading">
                <div class="fps-topo-spinner"></div>
                <p>Initializing Threat Topology...</p>
            </div>
        </div>

        <div class="fps-topo-sidebar">
            <div class="fps-topo-sidebar-header">
                <h3><i class="fas fa-bolt"></i> Live Threat Feed</h3>
                <span class="fps-topo-live-indicator"><span class="fps-pulse-dot"></span> LIVE</span>
            </div>
            <div class="fps-topo-events-feed" id="fps-events-feed">
                <!-- Events populated by JS -->
            </div>

            <div class="fps-topo-sidebar-section">
                <h4><i class="fas fa-globe-americas"></i> Top Threat Origins</h4>
                <div id="fps-top-countries" class="fps-topo-country-list">
                    <!-- Countries populated by JS -->
                </div>
            </div>
        </div>
    </div>

    <div class="fps-topo-footer">
        <span>Fraud Prevention Suite v4.1.2 by EnterpriseVPS</span>
        <span>Data updated every 60 seconds</span>
        <a href="?m=fraud_prevention_suite&api=1&endpoint=/v1/stats/global" class="fps-topo-api-link">
            <i class="fas fa-code"></i> API Access
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

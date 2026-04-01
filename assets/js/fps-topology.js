/**
 * Fraud Prevention Suite v4.1.2 - Globe Topology Visualization
 * Uses globe.gl (Three.js) for WebGL 3D globe rendering
 * Polls API for real-time fraud event data
 */
(function() {
    'use strict';

    const FpsTopology = {
        globe: null,
        refreshInterval: null,
        currentHours: 24,
        apiBase: '',
        isAdmin: false,
        containerId: 'fps-globe-container',

        /**
         * Called by TabTopology.php: FpsTopology.initAdminGlobe(containerId, ajaxUrl, isPublic)
         */
        initAdminGlobe: function(containerId, ajaxUrl, isPublic) {
            this.containerId = containerId || 'fps-globe-container';
            this.apiBase = ajaxUrl || '';
            this.isAdmin = !isPublic;
            this.initGlobe();
            this.initControls();
            this.loadData();
            this.startAutoRefresh(60000);
        },

        init: function() {
            // Detect API base path
            const meta = document.querySelector('meta[name="fps-api-base"]');
            this.apiBase = meta ? meta.content : (window.location.pathname.includes('/admin/')
                ? '../modules/addons/fraud_prevention_suite/public/api.php'
                : 'index.php?m=fraud_prevention_suite&api=1');

            this.isAdmin = window.location.pathname.includes('/admin/');

            this.initGlobe();
            this.initControls();
            this.loadData();
            this.startAutoRefresh(60000);
        },

        initGlobe: function() {
            const container = document.getElementById(this.containerId);
            if (!container || typeof Globe === 'undefined') {
                console.warn('FPS: Globe.gl not available, falling back to text mode');
                this.showFallback(container);
                return;
            }

            const width = container.clientWidth;
            const height = container.clientHeight || 600;

            this.globe = Globe()
                .globeImageUrl('https://unpkg.com/three-globe@2.31.0/example/img/earth-blue-marble.jpg')
                .bumpImageUrl('https://unpkg.com/three-globe@2.31.0/example/img/earth-topology.png')
                .backgroundImageUrl('https://unpkg.com/three-globe@2.31.0/example/img/night-sky.png')
                .width(width)
                .height(height)
                .backgroundColor('#0a0a1a00')
                .atmosphereColor('#00d4ff')
                .atmosphereAltitude(0.15)
                // Heat points
                .pointsData([])
                .pointLat('lat')
                .pointLng('lng')
                .pointAltitude(d => d.intensity * 0.01)
                .pointRadius(d => Math.max(0.3, Math.min(2, d.intensity * 0.1)))
                .pointColor(d => {
                    const level = d.max_level || 'low';
                    const colors = { low: '#38ef7d', medium: '#f5c842', high: '#f5576c', critical: '#eb3349' };
                    return colors[level] || '#667eea';
                })
                .pointsMerge(false)
                // Arcs between source and target
                .arcsData([])
                .arcStartLat(d => d.startLat)
                .arcStartLng(d => d.startLng)
                .arcEndLat(d => d.endLat)
                .arcEndLng(d => d.endLng)
                .arcColor(d => [`rgba(0, 212, 255, 0.6)`, `rgba(255, 0, 255, 0.3)`])
                .arcStroke(0.5)
                .arcDashLength(0.4)
                .arcDashGap(0.2)
                .arcDashAnimateTime(2000)
                // Labels
                .labelsData([])
                .labelLat('lat')
                .labelLng('lng')
                .labelText(d => d.country_code)
                .labelSize(1.5)
                .labelColor(() => '#ffffff')
                .labelDotRadius(0.5)
                .labelAltitude(0.01)
                (container);

            // Auto-rotate
            this.globe.controls().autoRotate = true;
            this.globe.controls().autoRotateSpeed = 0.5;

            // Responsive resize
            const self = this;
            window.addEventListener('resize', () => {
                if (self.globe) {
                    const c = document.getElementById(self.containerId);
                    if (c) self.globe.width(c.clientWidth).height(c.clientHeight || 600);
                }
            });

            // Hide loading
            const loading = document.getElementById('fps-globe-loading');
            if (loading) {
                setTimeout(() => { loading.style.display = 'none'; }, 2000);
            }
        },

        showFallback: function(container) {
            if (!container) return;
            container.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#00d4ff;font-size:1.2rem;flex-direction:column;">' +
                '<i class="fas fa-globe" style="font-size:4rem;margin-bottom:1rem;opacity:0.5;"></i>' +
                '<p>3D Globe requires WebGL support.</p>' +
                '<p style="font-size:0.9rem;color:#666;">Fraud data is still available via the API.</p></div>';
        },

        initControls: function() {
            // Time range buttons
            document.querySelectorAll('.fps-topo-time-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.fps-topo-time-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    this.currentHours = parseInt(btn.dataset.hours) || 24;
                    this.loadData();
                });
            });

            // Fullscreen toggle
            const fsBtn = document.getElementById('fps-fullscreen');
            if (fsBtn) {
                fsBtn.addEventListener('click', () => {
                    if (!document.fullscreenElement) {
                        document.documentElement.requestFullscreen().catch(() => {});
                    } else {
                        document.exitFullscreen().catch(() => {});
                    }
                });
            }
        },

        loadData: function() {
            if (this.isAdmin && this.apiBase.includes('ajax=1')) {
                // Admin mode: use WHMCS AJAX handler
                const url = this.apiBase + '&a=get_topology_data&hours=' + this.currentHours;
                fetch(url, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(response => {
                        if (response.success && response.data) {
                            this.updateGlobe(response.data.hotspots || []);
                            this.updateStats(response.data);
                            this.updateEventFeed(response.data.events || []);
                            this.updateGlobalStats(response.data);
                        }
                    })
                    .catch(err => console.warn('FPS Topology: Admin data load failed', err));
                return;
            }

            // Public API mode
            const endpoint = this.apiBase + (this.apiBase.includes('?') ? '&' : '?') +
                'endpoint=/v1/topology/hotspots&hours=' + this.currentHours;

            fetch(endpoint)
                .then(r => r.json())
                .then(response => {
                    if (response.success && response.data) {
                        this.updateGlobe(response.data.hotspots || []);
                        this.updateStats(response.data);
                    }
                })
                .catch(err => console.warn('FPS Topology: Data load failed', err));

            // Load recent events for sidebar
            const eventsEndpoint = this.apiBase + (this.apiBase.includes('?') ? '&' : '?') +
                'endpoint=/v1/topology/events&limit=50';

            fetch(eventsEndpoint)
                .then(r => r.json())
                .then(response => {
                    if (response.success && response.data) {
                        this.updateEventFeed(response.data.events || []);
                    }
                })
                .catch(() => {});

            // Load global stats
            const statsEndpoint = this.apiBase + (this.apiBase.includes('?') ? '&' : '?') +
                'endpoint=/v1/stats/global';

            fetch(statsEndpoint)
                .then(r => r.json())
                .then(response => {
                    if (response.success && response.data) {
                        this.updateGlobalStats(response.data);
                    }
                })
                .catch(() => {});
        },

        updateGlobe: function(hotspots) {
            if (!this.globe || !hotspots.length) return;

            // Update points
            const points = hotspots.map(h => ({
                lat: parseFloat(h.lat),
                lng: parseFloat(h.lng),
                intensity: parseInt(h.count || h.intensity || 1),
                max_level: h.max_level || (h.avg_risk > 60 ? 'high' : 'low'),
                country_code: h.country_code
            }));

            this.globe.pointsData(points);

            // Generate arcs between high-risk points
            const arcs = [];
            const highRisk = points.filter(p => p.intensity > 3);
            for (let i = 0; i < Math.min(highRisk.length - 1, 20); i++) {
                arcs.push({
                    startLat: highRisk[i].lat,
                    startLng: highRisk[i].lng,
                    endLat: highRisk[i + 1].lat,
                    endLng: highRisk[i + 1].lng,
                });
            }
            this.globe.arcsData(arcs);

            // Labels for top hotspots
            const topLabels = points
                .sort((a, b) => b.intensity - a.intensity)
                .slice(0, 10);
            this.globe.labelsData(topLabels);

            // Update top countries sidebar
            this.updateTopCountries(hotspots);
        },

        updateStats: function(data) {
            const totalEvents = data.total_events || 0;
            this.animateCounter('fps-stat-events', totalEvents);
        },

        updateGlobalStats: function(data) {
            this.animateCounter('fps-stat-events', data.total_checks || 0);
            this.animateCounter('fps-stat-countries', data.active_countries || 0);
            this.animateCounter('fps-stat-threats', data.total_blocks || 0);

            const blockRate = data.total_checks > 0
                ? Math.round((data.total_blocks / data.total_checks) * 100)
                : 0;
            const rateEl = document.getElementById('fps-stat-blockrate');
            if (rateEl) rateEl.textContent = blockRate + '%';
        },

        updateEventFeed: function(events) {
            const feed = document.getElementById('fps-events-feed');
            if (!feed) return;

            const levelIcons = {
                low: 'fa-circle-check',
                medium: 'fa-triangle-exclamation',
                high: 'fa-circle-exclamation',
                critical: 'fa-skull-crossbones'
            };

            const levelColors = {
                low: '#38ef7d',
                medium: '#f5c842',
                high: '#f5576c',
                critical: '#eb3349'
            };

            feed.innerHTML = events.slice(0, 30).map(e => {
                const level = e.risk_level || 'low';
                const time = new Date(e.created_at).toLocaleTimeString();
                const icon = levelIcons[level] || 'fa-circle';
                const color = levelColors[level] || '#666';

                return '<div class="fps-topo-event" style="border-left-color:' + color + '">' +
                    '<i class="fas ' + icon + '" style="color:' + color + '"></i>' +
                    '<div class="fps-topo-event-info">' +
                        '<span class="fps-topo-event-country">' + (e.country_code || 'XX') + '</span>' +
                        '<span class="fps-topo-event-type">' + (e.event_type || 'check') + '</span>' +
                        '<span class="fps-topo-event-score">' + parseFloat(e.risk_score || 0).toFixed(0) + '</span>' +
                    '</div>' +
                    '<span class="fps-topo-event-time">' + time + '</span>' +
                '</div>';
            }).join('');
        },

        updateTopCountries: function(hotspots) {
            const container = document.getElementById('fps-top-countries');
            if (!container) return;

            // Aggregate by country
            const countries = {};
            hotspots.forEach(h => {
                const cc = h.country_code || 'XX';
                if (!countries[cc]) countries[cc] = { count: 0, avg_risk: 0, n: 0 };
                countries[cc].count += parseInt(h.count || h.intensity || 1);
                countries[cc].avg_risk += parseFloat(h.avg_score || h.avg_risk || 0);
                countries[cc].n++;
            });

            const sorted = Object.entries(countries)
                .map(([cc, d]) => ({ cc, count: d.count, avg_risk: d.n > 0 ? d.avg_risk / d.n : 0 }))
                .sort((a, b) => b.count - a.count)
                .slice(0, 10);

            const maxCount = sorted.length > 0 ? sorted[0].count : 1;

            container.innerHTML = sorted.map(c => {
                const pct = Math.round((c.count / maxCount) * 100);
                const riskColor = c.avg_risk > 60 ? '#f5576c' : c.avg_risk > 30 ? '#f5c842' : '#38ef7d';
                return '<div class="fps-topo-country-row">' +
                    '<span class="fps-topo-country-code">' + c.cc + '</span>' +
                    '<div class="fps-topo-country-bar-wrap">' +
                        '<div class="fps-topo-country-bar" style="width:' + pct + '%;background:' + riskColor + '"></div>' +
                    '</div>' +
                    '<span class="fps-topo-country-count">' + c.count + '</span>' +
                '</div>';
            }).join('');
        },

        animateCounter: function(elementId, target) {
            const el = document.getElementById(elementId);
            if (!el) return;

            const current = parseInt(el.textContent.replace(/[^0-9]/g, '')) || 0;
            const diff = target - current;
            const steps = 30;
            const increment = diff / steps;
            let step = 0;

            const timer = setInterval(() => {
                step++;
                const value = Math.round(current + (increment * step));
                el.textContent = value.toLocaleString();
                if (step >= steps) {
                    el.textContent = target.toLocaleString();
                    clearInterval(timer);
                }
            }, 30);
        },

        startAutoRefresh: function(interval) {
            if (this.refreshInterval) clearInterval(this.refreshInterval);
            this.refreshInterval = setInterval(() => this.loadData(), interval);
        },

        destroy: function() {
            if (this.refreshInterval) clearInterval(this.refreshInterval);
            if (this.globe) {
                const container = document.getElementById(this.containerId);
                if (container) container.innerHTML = '';
            }
        }
    };

    // Export for external access (init is called explicitly by the page)
    window.FpsTopology = FpsTopology;
})();

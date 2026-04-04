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
            // Use injected API base or detect from URL
            this.apiBase = window.FPS_API_BASE || (window.location.pathname.includes('/admin/')
                ? '../modules/addons/fraud_prevention_suite/public/api.php'
                : 'index.php?m=fraud_prevention_suite&api=1');

            this.isAdmin = window.location.pathname.includes('/admin/');

            // Apply server-injected initial stats immediately (avoids API rate limit on first load)
            this._hasInitialStats = false;
            if (window.FPS_INITIAL_STATS) {
                this.updateGlobalStats(window.FPS_INITIAL_STATS);
            }

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
            document.querySelectorAll('.topo-time-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.topo-time-btn').forEach(b => b.classList.remove('topo-active'));
                    btn.classList.add('topo-active');
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

            // Use server-injected data first (no API calls, no rate limits)
            var initial = window.FPS_INITIAL_STATS || {};
            if (initial.events && initial.events.length > 0) {
                this.updateEventFeed(initial.events);
            }
            if (initial.hotspots && initial.hotspots.length > 0) {
                this.updateGlobe(initial.hotspots);
                this.updateTopCountries(initial.hotspots);
            }
            if (initial.total_checks !== undefined) {
                this.updateGlobalStats(initial);
            }

            // Also fetch fresh data from API (refreshes in background)
            var self = this;
            var hoursParam = this.currentHours;
            var endpoint = this.apiBase + (this.apiBase.includes('?') ? '&' : '?') +
                'endpoint=/v1/topology/hotspots&hours=' + hoursParam;

            fetch(endpoint)
                .then(function(r) { return r.json(); })
                .then(function(response) {
                    if (response.success && response.data) {
                        self.updateGlobe(response.data.hotspots || []);
                        self.updateStats(response.data);
                        self.updateTopCountries(response.data.hotspots || []);
                        // Update ALL stat counters from API response
                        self.updateAllCounters(response.data);
                    }
                })
                .catch(function(err) { console.warn('FPS Topology: Hotspots refresh skipped', err); });

            // Pass hours to events endpoint so it matches the selected timeline
            var eventsEndpoint = this.apiBase + (this.apiBase.includes('?') ? '&' : '?') +
                'endpoint=/v1/topology/events&limit=50&hours=' + hoursParam;

            fetch(eventsEndpoint)
                .then(function(r) { return r.json(); })
                .then(function(response) {
                    if (response.success && response.data) {
                        self.updateEventFeed(response.data.events || []);
                    }
                })
                .catch(function() {});
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
            var totalEvents = data.total_events !== undefined ? data.total_events : 0;
            // Always update, even if 0 (fixes stale counter on 1H with no events)
            this.animateCounter('fps-stat-events', totalEvents);
            this.animateCounter('fps-topo-events', totalEvents);
        },

        // Update ALL counters from API response (countries, threats, block rate)
        updateAllCounters: function(data) {
            var events = data.total_events !== undefined ? data.total_events : 0;
            var countries = data.active_countries || 0;
            var threats = data.total_blocks || 0;
            var blockRate = data.block_rate !== undefined ? data.block_rate : (events > 0 ? Math.round((threats / events) * 100) : 0);

            this.animateCounter('fps-stat-events', events);
            this.animateCounter('fps-topo-events', events);
            this.animateCounter('fps-stat-countries', countries);
            this.animateCounter('fps-topo-countries', countries);
            this.animateCounter('fps-stat-threats', threats);
            this.animateCounter('fps-topo-threats', threats);

            var rateEl = document.getElementById('fps-stat-blockrate') || document.getElementById('fps-topo-blockrate');
            if (rateEl) rateEl.textContent = blockRate + '%';
        },

        updateGlobalStats: function(data) {
            const checks = data.total_checks || data.total_events || 0;
            const countries = data.active_countries || data.countries || 0;
            const threats = data.total_blocks || data.threats || 0;
            const blockRate = checks > 0
                ? Math.round((threats / checks) * 100)
                : (data.block_rate || 0);

            if (checks > 0 || !this._hasInitialStats) {
                // Try both public page IDs and admin tab IDs
                this.animateCounter('fps-stat-events', checks);
                this.animateCounter('fps-topo-events', checks);
                this.animateCounter('fps-stat-countries', countries);
                this.animateCounter('fps-topo-countries', countries);
                this.animateCounter('fps-stat-threats', threats);
                this.animateCounter('fps-topo-threats', threats);

                var rateEl = document.getElementById('fps-stat-blockrate') || document.getElementById('fps-topo-blockrate');
                if (rateEl) rateEl.textContent = blockRate + '%';
            }
            this._hasInitialStats = true;
        },

        updateEventFeed: function(events) {
            // Try both public and admin element IDs
            const feed = document.getElementById('fps-events-feed') || document.getElementById('fps-event-feed');
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

            const countEl = document.getElementById('fps-feed-count');
            if (countEl) countEl.textContent = events.length + ' events';

            feed.innerHTML = events.slice(0, 30).map(e => {
                const level = e.risk_level || 'low';
                const time = new Date(e.created_at).toLocaleTimeString();
                const icon = levelIcons[level] || 'fa-circle';
                const iconClass = 'topo-event-icon--' + (level === 'critical' || level === 'high' ? 'blocked' : level === 'medium' ? 'flagged' : 'approved');

                return '<div class="topo-event topo-event-new">' +
                    '<div class="topo-event-icon ' + iconClass + '"><i class="fas ' + icon + '"></i></div>' +
                    '<div class="topo-event-content">' +
                        '<div class="topo-event-title">' + (e.country_code || 'XX') + ' - ' + (e.event_type || 'Fraud Check') + '</div>' +
                        '<div class="topo-event-detail">Score: ' + parseFloat(e.risk_score || 0).toFixed(0) + '</div>' +
                        '<div class="topo-event-meta"><span class="topo-event-tag topo-event-tag--' + level + '">' + level.toUpperCase() + '</span></div>' +
                    '</div>' +
                    '<span class="topo-event-time">' + time + '</span>' +
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
                const riskColor = c.avg_risk > 60 ? 'var(--topo-red)' : c.avg_risk > 30 ? 'var(--topo-amber)' : 'var(--topo-green)';
                return '<div class="topo-country-stat" style="padding:6px 12px;">' +
                    '<span style="font-family:var(--topo-font-mono);font-weight:700;width:30px;display:inline-block;">' + c.cc + '</span>' +
                    '<div style="flex:1;height:6px;background:rgba(255,255,255,0.07);border-radius:9999px;overflow:hidden;margin:0 10px;">' +
                        '<div style="width:' + pct + '%;height:100%;background:' + riskColor + ';border-radius:9999px;transition:width 0.5s ease;"></div>' +
                    '</div>' +
                    '<span class="topo-country-stat-value" style="font-size:0.8rem;">' + c.count + '</span>' +
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

        setRange: function(range) {
            // Convert range string (1h, 6h, 24h, 7d, 30d) to hours
            const map = {'1h': 1, '6h': 6, '24h': 24, '7d': 168, '30d': 720};
            this.currentHours = map[range] || 24;
            this.loadData();
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
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

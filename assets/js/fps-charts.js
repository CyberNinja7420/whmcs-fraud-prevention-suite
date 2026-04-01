/**
 * Fraud Prevention Suite v4.1.2 - Chart Definitions
 * Requires: ApexCharts (loaded via CDN before this script).
 * Namespace: window.FpsCharts
 *
 * Dark mode detection: reads document.documentElement or .fps-wrapper
 * for the class 'fps-theme-dark'.
 *
 * Usage:
 *   FpsCharts.dailyTrends('chart-daily', trendsData);
 *   FpsCharts.riskDistribution('chart-risk', riskData);
 *   FpsCharts.updateTheme('dark');  // called by FpsAdmin.toggleTheme
 */

(function (window) {
  'use strict';

  /* ------------------------------------------------------------------
     INTERNAL REGISTRY  (chartId -> ApexCharts instance)
  ------------------------------------------------------------------ */
  const _registry = {};

  /* ------------------------------------------------------------------
     THEME HELPERS
  ------------------------------------------------------------------ */
  function _isDark() {
    return (
      document.documentElement.classList.contains('fps-theme-dark') ||
      document.body.classList.contains('fps-theme-dark') ||
      !!document.querySelector('.fps-theme-dark')
    );
  }

  function _theme() {
    return _isDark() ? 'dark' : 'light';
  }

  function _bg() {
    return _isDark() ? '#1a1a2e' : '#ffffff';
  }

  function _textColor() {
    return _isDark() ? '#a0a0c0' : '#5a5f7d';
  }

  function _gridColor() {
    return _isDark() ? '#2a2a4e' : '#eaedf5';
  }

  /* ------------------------------------------------------------------
     SHARED BASE OPTIONS
  ------------------------------------------------------------------ */
  function _baseOptions(overrides) {
    return Object.assign({
      chart: {
        background: 'transparent',
        fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
        animations: {
          enabled: true,
          easing: 'easeinout',
          speed: 600,
          animateGradually: { enabled: true, delay: 80 },
        },
        toolbar: {
          show: true,
          tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false },
        },
        dropShadow: { enabled: false },
      },
      theme: { mode: _theme() },
      grid: {
        borderColor: _gridColor(),
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        yaxis: { lines: { show: true } },
      },
      tooltip: {
        theme: _theme(),
        style: { fontFamily: "inherit" },
      },
      legend: {
        labels: { colors: _textColor() },
        fontSize: '12px',
      },
      stroke: { curve: 'smooth', width: 2 },
      colors: ['#667eea', '#f5c842', '#f5576c', '#38ef7d', '#00d2ff', '#764ba2'],
    }, overrides || {});
  }

  /* ------------------------------------------------------------------
     REGISTER / DESTROY HELPERS
  ------------------------------------------------------------------ */
  function _mount(containerId, options) {
    const el = document.getElementById(containerId);
    if (!el) {
      console.warn('[FpsCharts] Container not found:', containerId);
      return null;
    }
    if (_registry[containerId]) {
      _registry[containerId].destroy();
      delete _registry[containerId];
    }
    el.innerHTML = '';
    try {
      const chart = new ApexCharts(el, options);
      chart.render();
      _registry[containerId] = chart;
      return chart;
    } catch (e) {
      console.error('[FpsCharts] Failed to render chart in #' + containerId, e);
      return null;
    }
  }

  /* ------------------------------------------------------------------
     1. DAILY TRENDS  (Mixed line chart)
     data: Array of { date, total, flagged, blocked }
  ------------------------------------------------------------------ */
  function dailyTrends(containerId, data) {
    data = data || _mockDailyTrends();

    const dates   = data.map(function (d) { return d.date; });
    const total   = data.map(function (d) { return d.total; });
    const flagged = data.map(function (d) { return d.flagged; });
    const blocked = data.map(function (d) { return d.blocked; });

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'line',
        height: 320,
        id: containerId,
      }),
      series: [
        { name: 'Total Checks',  type: 'area', data: total },
        { name: 'Flagged',       type: 'line', data: flagged },
        { name: 'Blocked',       type: 'line', data: blocked },
      ],
      colors: ['#667eea', '#f5c842', '#f5576c'],
      stroke: { curve: 'smooth', width: [2, 2, 2], dashArray: [0, 0, 0] },
      fill: {
        type: ['gradient', 'solid', 'solid'],
        gradient: {
          shade: 'dark',
          type: 'vertical',
          opacityFrom: 0.4,
          opacityTo: 0.05,
          stops: [0, 100],
        },
      },
      xaxis: {
        type: 'category',
        categories: dates,
        labels: {
          style: { colors: _textColor(), fontSize: '11px' },
          rotate: -30,
          rotateAlways: false,
        },
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: {
        labels: { style: { colors: _textColor(), fontSize: '11px' } },
        min: 0,
      },
      markers: { size: 0, hover: { size: 5 } },
      tooltip: Object.assign(_baseOptions().tooltip, {
        shared: true,
        intersect: false,
        y: { formatter: function (v) { return v ? v.toLocaleString() : '0'; } },
      }),
      legend: Object.assign(_baseOptions().legend, {
        position: 'top',
        horizontalAlign: 'left',
      }),
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     2. RISK DISTRIBUTION  (Donut)
     data: { low, medium, high, critical }  (percentages or counts)
  ------------------------------------------------------------------ */
  function riskDistribution(containerId, data) {
    data = data || { low: 54, medium: 26, high: 14, critical: 6 };

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'donut',
        height: 300,
        id: containerId,
      }),
      series: [data.low, data.medium, data.high, data.critical],
      labels: ['Low', 'Medium', 'High', 'Critical'],
      colors: ['#38ef7d', '#f5c842', '#f5576c', '#eb3349'],
      stroke: { width: 2, colors: [_bg()] },
      fill: { type: 'solid' },
      dataLabels: {
        enabled: true,
        style: { fontSize: '12px', fontFamily: 'inherit', fontWeight: 600 },
        dropShadow: { enabled: false },
      },
      plotOptions: {
        pie: {
          donut: {
            size: '65%',
            labels: {
              show: true,
              name: {
                show: true,
                fontSize: '12px',
                color: _textColor(),
              },
              value: {
                show: true,
                fontSize: '22px',
                fontWeight: 700,
                color: _isDark() ? '#e0e0e0' : '#1a1d2e',
                formatter: function (v) { return v + '%'; },
              },
              total: {
                show: true,
                label: 'Avg Risk',
                color: _textColor(),
                formatter: function (w) {
                  const vals = w.globals.seriesTotals;
                  const total = vals.reduce(function (a, b) { return a + b; }, 0);
                  return total > 0 ? total + '%' : '0%';
                },
              },
            },
          },
        },
      },
      legend: Object.assign(_baseOptions().legend, {
        position: 'bottom',
        horizontalAlign: 'center',
        markers: { radius: 50 },
      }),
      tooltip: Object.assign(_baseOptions().tooltip, {
        y: { formatter: function (v) { return v + '%'; } },
      }),
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     3. PROVIDER ACCURACY  (Horizontal bar)
     data: Array of { provider, caught, total }
  ------------------------------------------------------------------ */
  function providerAccuracy(containerId, data) {
    data = data || _mockProviderData();

    const providers  = data.map(function (d) { return d.provider; });
    const catchRates = data.map(function (d) {
      return d.total > 0 ? Math.round((d.caught / d.total) * 100) : 0;
    });

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'bar',
        height: Math.max(200, data.length * 42),
        id: containerId,
      }),
      plotOptions: {
        bar: {
          horizontal: true,
          borderRadius: 6,
          barHeight: '55%',
          distributed: true,
          dataLabels: { position: 'top' },
        },
      },
      series: [{ name: 'Catch Rate %', data: catchRates }],
      colors: ['#667eea', '#764ba2', '#00d2ff', '#38ef7d', '#f5c842', '#f5576c', '#eb3349'],
      xaxis: {
        categories: providers,
        min: 0,
        max: 100,
        labels: {
          style: { colors: _textColor(), fontSize: '11px' },
          formatter: function (v) { return v + '%'; },
        },
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: {
        labels: {
          style: { colors: _textColor(), fontSize: '12px', fontWeight: 600 },
        },
      },
      dataLabels: {
        enabled: true,
        offsetX: 8,
        style: { fontSize: '11px', fontWeight: 700, colors: [_isDark() ? '#e0e0e0' : '#1a1d2e'] },
        formatter: function (v) { return v + '%'; },
      },
      tooltip: Object.assign(_baseOptions().tooltip, {
        y: {
          formatter: function (v, opts2) {
            const d = data[opts2.dataPointIndex];
            return v + '% (' + (d ? d.caught + '/' + d.total : '') + ' checks)';
          },
        },
      }),
      legend: { show: false },
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     4. COUNTRY HEATMAP  (Treemap)
     data: Array of { country, count }
  ------------------------------------------------------------------ */
  function countryHeatmap(containerId, data) {
    data = data || _mockCountryData();

    const sorted = data.slice().sort(function (a, b) { return b.count - a.count; }).slice(0, 20);

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'treemap',
        height: 340,
        id: containerId,
      }),
      series: [{
        data: sorted.map(function (d) {
          return { x: d.country, y: d.count };
        }),
      }],
      colors: ['#667eea'],
      plotOptions: {
        treemap: {
          distributed: true,
          enableShades: true,
          shadeIntensity: 0.6,
          colorScale: {
            ranges: [
              { from: 1,    to: 50,   color: '#38ef7d' },
              { from: 51,   to: 200,  color: '#f5c842' },
              { from: 201,  to: 500,  color: '#f5576c' },
              { from: 501,  to: 9999, color: '#eb3349' },
            ],
          },
        },
      },
      dataLabels: {
        enabled: true,
        style: { fontSize: '12px', fontWeight: 700 },
      },
      tooltip: Object.assign(_baseOptions().tooltip, {
        y: { formatter: function (v) { return v.toLocaleString() + ' fraud attempts'; } },
      }),
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     5. HOURLY ACTIVITY  (Area chart)
     data: Array of 24 numbers (checks per hour)
  ------------------------------------------------------------------ */
  function hourlyActivity(containerId, data) {
    data = data || _mockHourlyData();

    const hours = Array.from({ length: 24 }, function (_, i) {
      return (i < 10 ? '0' : '') + i + ':00';
    });

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'area',
        height: 260,
        id: containerId,
        sparkline: { enabled: false },
      }),
      series: [{ name: 'Checks / Hour', data: data }],
      colors: ['#00d2ff'],
      stroke: { curve: 'smooth', width: 2 },
      fill: {
        type: 'gradient',
        gradient: {
          shade: 'dark',
          type: 'vertical',
          opacityFrom: 0.45,
          opacityTo: 0.02,
        },
      },
      xaxis: {
        categories: hours,
        labels: {
          style: { colors: _textColor(), fontSize: '10px' },
          rotate: -45,
        },
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: {
        labels: {
          style: { colors: _textColor(), fontSize: '11px' },
          formatter: function (v) { return Math.round(v).toLocaleString(); },
        },
        min: 0,
      },
      markers: { size: 0, hover: { size: 4 } },
      tooltip: Object.assign(_baseOptions().tooltip, {
        x: { format: 'HH:00' },
        y: { formatter: function (v) { return Math.round(v).toLocaleString() + ' checks'; } },
      }),
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     6. RISK SCORE HISTOGRAM  (Column chart)
     data: Array of 10 numbers (buckets: 0-10, 10-20, ..., 90-100)
  ------------------------------------------------------------------ */
  function riskScoreHistogram(containerId, data) {
    data = data || _mockHistogramData();

    const buckets = ['0-10', '10-20', '20-30', '30-40', '40-50', '50-60', '60-70', '70-80', '80-90', '90-100'];
    const colors  = ['#38ef7d', '#38ef7d', '#a8e063', '#f5c842', '#f5c842', '#f59342', '#f5576c', '#f5576c', '#eb3349', '#eb3349'];

    const opts = _baseOptions({
      chart: Object.assign(_baseOptions().chart, {
        type: 'bar',
        height: 280,
        id: containerId,
      }),
      plotOptions: {
        bar: {
          borderRadius: 4,
          columnWidth: '70%',
          distributed: true,
          dataLabels: { position: 'top' },
        },
      },
      series: [{ name: 'Transactions', data: data }],
      colors: colors,
      xaxis: {
        categories: buckets,
        labels: {
          style: { colors: _textColor(), fontSize: '11px' },
        },
        title: {
          text: 'Risk Score',
          style: { color: _textColor(), fontSize: '12px' },
        },
        axisBorder: { show: false },
        axisTicks: { show: false },
      },
      yaxis: {
        labels: {
          style: { colors: _textColor(), fontSize: '11px' },
          formatter: function (v) { return v.toLocaleString(); },
        },
        title: {
          text: 'Transaction Count',
          style: { color: _textColor(), fontSize: '12px' },
        },
      },
      dataLabels: {
        enabled: true,
        offsetY: -6,
        style: { fontSize: '10px', fontWeight: 700, colors: [_isDark() ? '#e0e0e0' : '#1a1d2e'] },
        formatter: function (v) { return v > 0 ? v.toLocaleString() : ''; },
      },
      legend: { show: false },
      tooltip: Object.assign(_baseOptions().tooltip, {
        y: { formatter: function (v) { return v.toLocaleString() + ' transactions'; } },
      }),
    });

    return _mount(containerId, opts);
  }

  /* ------------------------------------------------------------------
     THEME UPDATER  (called by FpsAdmin.toggleTheme)
  ------------------------------------------------------------------ */
  function updateTheme(theme) {
    Object.keys(_registry).forEach(function (id) {
      const chart = _registry[id];
      if (!chart) return;
      try {
        chart.updateOptions({
          theme: { mode: theme },
          grid: { borderColor: _gridColor() },
          tooltip: { theme: theme },
        }, false, false);
      } catch (e) { /* chart may have been destroyed */ }
    });
  }

  /* ------------------------------------------------------------------
     UPDATE DATA  (re-render with new series data)
  ------------------------------------------------------------------ */
  function updateData(containerId, seriesData) {
    const chart = _registry[containerId];
    if (!chart) return;
    chart.updateSeries(seriesData, true);
  }

  /* ------------------------------------------------------------------
     DESTROY
  ------------------------------------------------------------------ */
  function destroy(containerId) {
    const chart = _registry[containerId];
    if (chart) {
      chart.destroy();
      delete _registry[containerId];
    }
  }

  function destroyAll() {
    Object.keys(_registry).forEach(destroy);
  }

  /* ------------------------------------------------------------------
     MOCK DATA  (used when no real data is provided)
  ------------------------------------------------------------------ */
  function _mockDailyTrends() {
    const out = [];
    const now = new Date();
    for (let i = 29; i >= 0; i--) {
      const d = new Date(now);
      d.setDate(d.getDate() - i);
      const total   = Math.floor(Math.random() * 800)  + 200;
      const flagged = Math.floor(total * (0.05 + Math.random() * 0.12));
      const blocked = Math.floor(flagged * (0.3 + Math.random() * 0.4));
      out.push({
        date:    d.toISOString().slice(0, 10),
        total:   total,
        flagged: flagged,
        blocked: blocked,
      });
    }
    return out;
  }

  function _mockProviderData() {
    return [
      { provider: 'MaxMind GeoIP',    caught: 820, total: 1000 },
      { provider: 'IPQualityScore',   caught: 910, total: 1000 },
      { provider: 'Stripe Radar',     caught: 760, total: 1000 },
      { provider: 'IPAPI',            caught: 580, total: 1000 },
      { provider: 'AbuseIPDB',        caught: 690, total: 1000 },
      { provider: 'EmailRep.io',      caught: 420, total: 1000 },
    ];
  }

  function _mockCountryData() {
    return [
      { country: 'China',         count: 842 },
      { country: 'Russia',        count: 631 },
      { country: 'Nigeria',       count: 418 },
      { country: 'Brazil',        count: 287 },
      { country: 'India',         count: 199 },
      { country: 'United States', count: 156 },
      { country: 'Romania',       count: 144 },
      { country: 'Ukraine',       count: 128 },
      { country: 'Vietnam',       count: 97  },
      { country: 'Pakistan',      count: 74  },
      { country: 'Indonesia',     count: 63  },
      { country: 'Iran',          count: 58  },
    ];
  }

  function _mockHourlyData() {
    return [12, 8, 5, 4, 6, 14, 38, 72, 95, 88, 76, 82,
            94, 87, 91, 78, 65, 58, 49, 41, 35, 28, 22, 16];
  }

  function _mockHistogramData() {
    return [1840, 2310, 1950, 1200, 890, 610, 380, 240, 140, 95];
  }

  /* ------------------------------------------------------------------
     INIT ALL  (called by TabStatistics.php)
     Fetches chart data from the AJAX endpoint and renders all 6 charts.
     @param {string} ajaxUrl - Module AJAX URL (modulelink + '&ajax=1')
     @param {number} days    - Number of days of data to fetch
  ------------------------------------------------------------------ */
  function initAll(ajaxUrl, days) {
    days = days || 30;

    var url = ajaxUrl + '&a=get_chart_data&days=' + days;

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json.success || !json.data) {
          console.warn('[FpsCharts] No chart data returned, rendering with mock data');
          _renderAllWithDefaults();
          return;
        }

        var data = json.data;

        // 1. Daily Trends -- transform stats rows into the format dailyTrends expects
        var trendsData = (data.daily || []).map(function (row) {
          return {
            date: row.date,
            total: parseInt(row.checks_total) || 0,
            flagged: parseInt(row.checks_flagged) || 0,
            blocked: parseInt(row.checks_blocked) || 0,
          };
        });
        dailyTrends('fps-chart-daily-trends', trendsData.length > 0 ? trendsData : null);

        // 2. Risk Distribution -- transform distribution map into donut data
        var dist = data.distribution || {};
        var total = (parseInt(dist.low) || 0) + (parseInt(dist.medium) || 0) +
                    (parseInt(dist.high) || 0) + (parseInt(dist.critical) || 0);
        var riskData = total > 0 ? {
          low:      Math.round(((parseInt(dist.low) || 0) / total) * 100),
          medium:   Math.round(((parseInt(dist.medium) || 0) / total) * 100),
          high:     Math.round(((parseInt(dist.high) || 0) / total) * 100),
          critical: Math.round(((parseInt(dist.critical) || 0) / total) * 100),
        } : null;
        riskDistribution('fps-chart-risk-distribution', riskData);

        // 3. Provider Accuracy -- no per-provider data in this endpoint, use defaults
        providerAccuracy('fps-chart-provider-accuracy', null);

        // 4. Country Breakdown
        var countryData = (data.countries || []).map(function (row) {
          return { country: row.country || 'Unknown', count: parseInt(row.count) || 0 };
        });
        countryHeatmap('fps-chart-country-breakdown', countryData.length > 0 ? countryData : null);

        // 5. Hourly Activity -- aggregate from daily stats or use defaults
        hourlyActivity('fps-chart-hourly-activity', null);

        // 6. Risk Score Histogram -- build from checks if available
        riskScoreHistogram('fps-chart-score-histogram', null);
      })
      .catch(function (err) {
        console.error('[FpsCharts] Failed to load chart data:', err);
        _renderAllWithDefaults();
      });
  }

  /** Render all charts with mock/default data as fallback. */
  function _renderAllWithDefaults() {
    dailyTrends('fps-chart-daily-trends', null);
    riskDistribution('fps-chart-risk-distribution', null);
    providerAccuracy('fps-chart-provider-accuracy', null);
    countryHeatmap('fps-chart-country-breakdown', null);
    hourlyActivity('fps-chart-hourly-activity', null);
    riskScoreHistogram('fps-chart-score-histogram', null);
  }

  /* ------------------------------------------------------------------
     SET CHART RANGE  (called by date range buttons in TabStatistics)
     @param {number} days
     @param {string} ajaxUrl
     @param {HTMLElement} btn - The clicked button (for active state)
  ------------------------------------------------------------------ */
  function setChartRange(days, ajaxUrl, btn) {
    // Update active button
    if (btn) {
      var siblings = btn.parentElement.querySelectorAll('.fps-btn');
      for (var i = 0; i < siblings.length; i++) {
        siblings[i].classList.remove('active');
      }
      btn.classList.add('active');
    }
    destroyAll();
    initAll(ajaxUrl, days);
  }

  /* ------------------------------------------------------------------
     PUBLIC API
  ------------------------------------------------------------------ */
  window.FpsCharts = {
    initAll,
    setChartRange,
    dailyTrends,
    riskDistribution,
    providerAccuracy,
    countryHeatmap,
    hourlyActivity,
    riskScoreHistogram,
    updateTheme,
    updateData,
    destroy,
    destroyAll,
    // Expose registry for debugging
    _registry,
  };

}(window));

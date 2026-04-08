/**
 * Fraud Prevention Suite v4.1.2 - Admin JavaScript
 * All functions are namespaced under window.FpsAdmin.
 *
 * Dependencies: None (vanilla ES6+, no jQuery).
 * AJAX endpoint: modulelink + '&ajax=1&a=' + action
 *
 * Usage:
 *   FpsAdmin.init({ modulelink: '...', theme: 'light' });
 */

(function (window) {
  'use strict';

  /* ------------------------------------------------------------------
     PRIVATE STATE
  ------------------------------------------------------------------ */
  const _state = {
    modulelink: '',
    theme: 'light',
    toastCounter: 0,
    autoRefreshTimer: null,
    activeModal: null,
    dateTables: {},
  };

  /* ------------------------------------------------------------------
     INIT
  ------------------------------------------------------------------ */
  function init(config) {
    config = config || {};
    _state.modulelink = config.modulelink || '';

    // Restore saved theme
    const saved = localStorage.getItem('fps-theme');
    _state.theme = saved || config.theme || 'light';
    _applyTheme(_state.theme);

    // Build toast container once
    if (!document.getElementById('fps-toast-container')) {
      const tc = document.createElement('div');
      tc.id = 'fps-toast-container';
      tc.className = 'fps-toast-container';
      tc.setAttribute('aria-live', 'polite');
      tc.setAttribute('aria-atomic', 'false');
      document.body.appendChild(tc);
    }

    // Build modal container once
    if (!document.getElementById('fps-modal-overlay')) {
      document.body.insertAdjacentHTML('beforeend', _modalShell());
    }

    // Keyboard: ESC closes modal
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && _state.activeModal) closeModal();
    });
  }

  /* ------------------------------------------------------------------
     THEME
  ------------------------------------------------------------------ */
  function toggleTheme() {
    const next = _state.theme === 'light' ? 'dark' : 'light';
    _state.theme = next;
    localStorage.setItem('fps-theme', next);
    _applyTheme(next);
  }

  function _applyTheme(theme) {
    var wrapper = document.querySelector('.fps-module-wrapper')
      || document.querySelector('.fps-wrapper')
      || document.documentElement;
    if (theme === 'dark') {
      wrapper.classList.add('fps-theme-dark');
      document.documentElement.classList.add('fps-theme-dark');
    } else {
      wrapper.classList.remove('fps-theme-dark');
      document.documentElement.classList.remove('fps-theme-dark');
    }
    // Sync charts if present
    if (window.FpsCharts && typeof FpsCharts.updateTheme === 'function') {
      FpsCharts.updateTheme(theme);
    }
  }

  /* ------------------------------------------------------------------
     TOAST SYSTEM
  ------------------------------------------------------------------ */
  /**
   * Show a toast notification.
   * @param {string} message  - Primary message text.
   * @param {'success'|'error'|'warning'|'info'} type
   * @param {number} duration - Ms before auto-dismiss (0 = persistent).
   * @param {string} [title]  - Optional bold title line.
   */
  function toast(message, type, duration, title) {
    type = type || 'info';
    duration = (duration === undefined) ? 4000 : duration;

    const id = 'fps-toast-' + (++_state.toastCounter);
    const icons = { success: '&#10003;', error: '&#10005;', warning: '&#9888;', info: '&#9432;' };
    const titles = { success: 'Success', error: 'Error', warning: 'Warning', info: 'Info' };

    const html = `
      <div id="${id}" class="fps-toast fps-toast-${type}" role="alert" aria-live="assertive">
        <span class="fps-toast-icon" aria-hidden="true">${icons[type] || icons.info}</span>
        <div class="fps-toast-content">
          <div class="fps-toast-title">${_esc(title || titles[type])}</div>
          <div class="fps-toast-message">${_esc(message)}</div>
        </div>
        <button class="fps-toast-dismiss" aria-label="Dismiss notification" onclick="FpsAdmin.dismissToast('${id}')">&times;</button>
        ${duration > 0 ? `<div class="fps-toast-progress" style="animation-duration:${duration}ms"></div>` : ''}
      </div>`;

    const container = document.getElementById('fps-toast-container');
    container.insertAdjacentHTML('beforeend', html);

    if (duration > 0) {
      setTimeout(function () { dismissToast(id); }, duration);
    }

    return id;
  }

  function dismissToast(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('fps-toast-exiting');
    setTimeout(function () { el && el.remove(); }, 300);
  }

  /* ------------------------------------------------------------------
     AJAX WRAPPER
  ------------------------------------------------------------------ */
  /**
   * Send an AJAX request to the module endpoint.
   * @param {string}   action   - Action name appended to URL.
   * @param {object}   data     - POST body key/values.
   * @param {function} callback - Called with (error, responseData).
   * @returns {Promise}
   */
  function ajax(action, data, callback) {
    const url = _state.modulelink + '&ajax=1&a=' + encodeURIComponent(action);
    const body = new URLSearchParams();

    if (data && typeof data === 'object') {
      Object.keys(data).forEach(function (k) {
        body.append(k, data[k]);
      });
    }

    // Include WHMCS CSRF token if present (try multiple sources)
    const tokenEl = document.getElementById('fps-csrf-token')
      || document.querySelector('input[name="token"]')
      || document.querySelector('#frmFraudPreventionSuite input[name="token"]');
    if (tokenEl && tokenEl.value) body.append('token', tokenEl.value);

    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: body.toString(),
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status + ': ' + res.statusText);
        return res.json();
      })
      .then(function (json) {
        if (typeof callback === 'function') callback(null, json);
        return json;
      })
      .catch(function (err) {
        console.error('[FpsAdmin.ajax]', action, err);
        if (typeof callback === 'function') callback(err, null);
        throw err;
      });
  }

  /* ------------------------------------------------------------------
     TAB SWITCHING
  ------------------------------------------------------------------ */
  /**
   * Activate a named tab pane.
   * @param {string} tabName - Matches data-tab attribute on buttons / panes.
   * @param {string} [groupId] - Optional parent element ID to scope the query.
   */
  function switchTab(tabName, groupId) {
    const scope = groupId ? document.getElementById(groupId) : document;
    if (!scope) return;

    // Deactivate all buttons
    scope.querySelectorAll('.fps-tab-btn').forEach(function (btn) {
      btn.classList.remove('fps-tab-active');
      btn.setAttribute('aria-selected', 'false');
    });

    // Deactivate all panes
    scope.querySelectorAll('.fps-tab-content').forEach(function (pane) {
      pane.classList.remove('fps-tab-active', 'fps-active');
    });

    // Activate matching button
    const activeBtn = scope.querySelector('[data-tab="' + tabName + '"].fps-tab-btn');
    if (activeBtn) {
      activeBtn.classList.add('fps-tab-active');
      activeBtn.setAttribute('aria-selected', 'true');
    }

    // Activate matching pane
    const activePane = scope.querySelector('#fps-pane-' + tabName + ', [data-pane="' + tabName + '"]');
    if (activePane) {
      activePane.classList.add('fps-tab-active', 'fps-active');
    }

    // Persist selection
    try { sessionStorage.setItem('fps-active-tab', tabName); } catch (e) { /* ignore */ }
  }

  /* ------------------------------------------------------------------
     COUNT-UP ANIMATION
  ------------------------------------------------------------------ */
  /**
   * Animate a numeric counter from 0 (or current) to target.
   * @param {HTMLElement|string} el       - Element or selector.
   * @param {number}             target   - Final value.
   * @param {number}             duration - Animation duration in ms.
   * @param {string}             [prefix] - Text before number (e.g. '$').
   * @param {string}             [suffix] - Text after number (e.g. '%').
   */
  function animateNumber(el, target, duration, prefix, suffix) {
    const element = typeof el === 'string' ? document.querySelector(el) : el;
    if (!element) return;

    prefix = prefix || '';
    suffix = suffix || '';
    duration = duration || 1200;

    const start = parseFloat(element.textContent.replace(/[^0-9.]/g, '')) || 0;
    const startTime = performance.now();
    const isFloat = String(target).includes('.');
    const decimals = isFloat ? (String(target).split('.')[1] || '').length : 0;

    function easeOut(t) { return 1 - Math.pow(1 - t, 3); }

    function step(now) {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const current = start + (target - start) * easeOut(progress);
      element.textContent = prefix + current.toFixed(decimals) + suffix;
      if (progress < 1) requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
  }

  /* ------------------------------------------------------------------
     MODAL SYSTEM
  ------------------------------------------------------------------ */
  function _modalShell() {
    return `
      <div id="fps-modal-overlay" class="fps-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="fps-modal-title">
        <div class="fps-modal" id="fps-modal">
          <div class="fps-modal-header">
            <h2 class="fps-modal-title" id="fps-modal-title">Modal</h2>
            <button class="fps-modal-close" aria-label="Close" onclick="FpsAdmin.closeModal()">&times;</button>
          </div>
          <div class="fps-modal-body" id="fps-modal-body"></div>
          <div class="fps-modal-footer" id="fps-modal-footer"></div>
        </div>
      </div>`;
  }

  /**
   * Display the shared modal.
   * @param {string}          title    - Modal heading.
   * @param {string|HTMLNode} content  - Body HTML string.
   * @param {object}          [opts]   - { footerHTML, onClose, size }
   */
  function showModal(title, content, opts) {
    opts = opts || {};
    const overlay = document.getElementById('fps-modal-overlay');
    if (!overlay) return;

    document.getElementById('fps-modal-title').textContent = title;
    const body = document.getElementById('fps-modal-body');
    const footer = document.getElementById('fps-modal-footer');

    if (typeof content === 'string') {
      body.innerHTML = content;
    } else if (content instanceof HTMLElement) {
      body.innerHTML = '';
      body.appendChild(content);
    }

    footer.innerHTML = opts.footerHTML || '';

    if (opts.size) {
      const modal = document.getElementById('fps-modal');
      modal.style.maxWidth = opts.size;
    }

    overlay.classList.add('fps-modal-open');
    overlay.setAttribute('aria-hidden', 'false');
    _state.activeModal = { onClose: opts.onClose };

    // Trap focus
    setTimeout(function () {
      const firstFocusable = overlay.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (firstFocusable) firstFocusable.focus();
    }, 50);

    // Click backdrop to close
    overlay.addEventListener('click', _backdropClose);
  }

  function _backdropClose(e) {
    if (e.target.id === 'fps-modal-overlay') {
      closeModal();
    }
  }

  function closeModal(modalId) {
    // Handle PHP-rendered modals (fps-rule-modal, fps-key-detail-modal, etc.)
    if (modalId) {
      var phpModal = document.getElementById(modalId);
      if (phpModal && phpModal.classList.contains('fps-modal')) {
        phpModal.style.display = 'none';
        phpModal.classList.remove('fps-modal-open');
        return;
      }
    }
    // Handle the shared JS modal overlay
    const overlay = document.getElementById('fps-modal-overlay');
    if (!overlay) return;
    overlay.classList.remove('fps-modal-open');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.removeEventListener('click', _backdropClose);
    if (_state.activeModal && typeof _state.activeModal.onClose === 'function') {
      _state.activeModal.onClose();
    }
    _state.activeModal = null;
  }

  /* ------------------------------------------------------------------
     CONFIRM DIALOG
  ------------------------------------------------------------------ */
  /**
   * Show a styled confirmation modal.
   * @param {string}   message  - Question text.
   * @param {function} callback - Called with true (confirmed) or false.
   * @param {object}   [opts]   - { title, confirmLabel, cancelLabel, type }
   */
  function confirm(message, callback, opts) {
    opts = opts || {};
    const title = opts.title || 'Confirm Action';
    const confirmLabel = opts.confirmLabel || 'Confirm';
    const cancelLabel  = opts.cancelLabel  || 'Cancel';
    const type = opts.type || 'danger';

    const footerHTML = `
      <button class="fps-btn fps-btn-ghost fps-btn-sm" onclick="FpsAdmin.closeModal()">${_esc(cancelLabel)}</button>
      <button class="fps-btn fps-btn-${type} fps-btn-sm" id="fps-confirm-ok">${_esc(confirmLabel)}</button>`;

    showModal(title, `<p style="margin:0;font-size:0.95rem;">${_esc(message)}</p>`, {
      footerHTML: footerHTML,
    });

    document.getElementById('fps-confirm-ok').addEventListener('click', function () {
      closeModal();
      if (typeof callback === 'function') callback(true);
    });
  }

  /* ------------------------------------------------------------------
     DATA TABLE
  ------------------------------------------------------------------ */
  /**
   * Enhance a <table> with sorting and filtering.
   * @param {string} tableId
   * @param {object} [config] - { sortable: true, pageSize: 25 }
   */
  function initTable(tableId, config) {
    const table = document.getElementById(tableId);
    if (!table) return;
    config = Object.assign({ sortable: true, pageSize: 25 }, config || {});

    const tbody = table.querySelector('tbody');
    const allRows = Array.from(tbody ? tbody.querySelectorAll('tr') : []);
    let filtered = allRows.slice();
    let sortCol = -1;
    let sortAsc = true;

    // State stored for external access
    _state.dateTables[tableId] = { allRows, filtered, config };

    if (config.sortable) {
      table.querySelectorAll('thead th').forEach(function (th, idx) {
        th.style.cursor = 'pointer';
        th.setAttribute('tabindex', '0');
        th.innerHTML += ' <span class="fps-sort-icon" aria-hidden="true">&#8597;</span>';

        function sort() {
          if (sortCol === idx) {
            sortAsc = !sortAsc;
          } else {
            sortCol = idx;
            sortAsc = true;
          }

          // Visual indicator
          table.querySelectorAll('thead th').forEach(function (h) {
            h.classList.remove('fps-sorted');
            const icon = h.querySelector('.fps-sort-icon');
            if (icon) icon.innerHTML = '&#8597;';
          });
          th.classList.add('fps-sorted');
          const icon = th.querySelector('.fps-sort-icon');
          if (icon) icon.innerHTML = sortAsc ? '&#8593;' : '&#8595;';

          filtered.sort(function (a, b) {
            const aVal = (a.cells[idx] ? a.cells[idx].textContent : '').trim();
            const bVal = (b.cells[idx] ? b.cells[idx].textContent : '').trim();
            const aNum = parseFloat(aVal);
            const bNum = parseFloat(bVal);
            if (!isNaN(aNum) && !isNaN(bNum)) {
              return sortAsc ? aNum - bNum : bNum - aNum;
            }
            return sortAsc
              ? aVal.localeCompare(bVal)
              : bVal.localeCompare(aVal);
          });

          _renderRows(tbody, filtered);
        }

        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') sort(); });
      });
    }
  }

  function _renderRows(tbody, rows) {
    while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
    const frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r); });
    tbody.appendChild(frag);
  }

  /* ------------------------------------------------------------------
     LIVE SEARCH FILTER
  ------------------------------------------------------------------ */
  /**
   * Attach live filtering to a table via an input.
   * @param {string} inputId
   * @param {string} tableId
   */
  function initSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;

    const tbody = table.querySelector('tbody');
    const allRows = Array.from(tbody ? tbody.querySelectorAll('tr') : []);

    input.addEventListener('input', function () {
      const q = input.value.toLowerCase().trim();
      const visible = allRows.filter(function (row) {
        return !q || row.textContent.toLowerCase().includes(q);
      });
      _renderRows(tbody, visible);

      // Update row count badge if present
      const badge = document.querySelector('[data-table-count="' + tableId + '"]');
      if (badge) badge.textContent = visible.length;

      // Toggle empty state
      const empty = document.querySelector('[data-table-empty="' + tableId + '"]');
      if (empty) empty.style.display = visible.length === 0 ? '' : 'none';
    });
  }

  /* ------------------------------------------------------------------
     EXPORT CSV
  ------------------------------------------------------------------ */
  /**
   * Download table data as a CSV file.
   * @param {string} tableId
   * @param {string} [filename]
   */
  function exportCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    filename = filename || ('fps-export-' + _dateStamp() + '.csv');

    const rows = [];
    table.querySelectorAll('tr').forEach(function (row) {
      const cols = Array.from(row.querySelectorAll('th, td')).map(function (cell) {
        return '"' + cell.textContent.replace(/"/g, '""').trim() + '"';
      });
      if (cols.length) rows.push(cols.join(','));
    });

    const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    toast('Export downloaded: ' + filename, 'success', 3000);
  }

  /* ------------------------------------------------------------------
     BATCH OPERATIONS
  ------------------------------------------------------------------ */
  /** Toggle all checkboxes with a given class. */
  function selectAll(checkboxClass, checked) {
    const boxes = document.querySelectorAll('.' + checkboxClass);
    const state = (checked !== undefined) ? checked : !_allChecked(boxes);
    boxes.forEach(function (cb) { cb.checked = state; });
  }

  /** Return an array of values from checked checkboxes. */
  function getSelected(checkboxClass) {
    return Array.from(document.querySelectorAll('.' + checkboxClass + ':checked'))
      .map(function (cb) { return cb.value; });
  }

  function _allChecked(boxes) {
    return Array.from(boxes).every(function (cb) { return cb.checked; });
  }

  /* ------------------------------------------------------------------
     RISK BADGE RENDERER
  ------------------------------------------------------------------ */
  /**
   * Return badge HTML for a risk level.
   * @param {'low'|'medium'|'high'|'critical'} level
   * @param {number} [score] - Optional numeric score to append.
   * @returns {string} HTML string
   */
  function riskBadge(level, score) {
    const valid = ['low', 'medium', 'high', 'critical'];
    const l = valid.includes(level) ? level : 'low';
    const scoreHtml = (score !== undefined) ? ' <span style="opacity:0.75">' + score + '</span>' : '';
    return `<span class="fps-badge fps-badge-${l}">${l.charAt(0).toUpperCase() + l.slice(1)}${scoreHtml}</span>`;
  }

  /* ------------------------------------------------------------------
     LOADING STATE
  ------------------------------------------------------------------ */
  function showLoading(containerId) {
    const el = typeof containerId === 'string'
      ? document.getElementById(containerId)
      : containerId;
    if (!el) return;

    // Avoid duplicate overlays
    if (el.querySelector('.fps-loading-overlay')) return;

    el.style.position = el.style.position || 'relative';
    el.insertAdjacentHTML('beforeend', `
      <div class="fps-loading-overlay" id="${containerId}-loader">
        <div class="fps-spinner fps-spinner-lg"></div>
      </div>`);
  }

  function hideLoading(containerId) {
    const loader = document.getElementById(containerId + '-loader') ||
      (document.getElementById(containerId) &&
       document.getElementById(containerId).querySelector('.fps-loading-overlay'));
    if (loader) loader.remove();
  }

  /* ------------------------------------------------------------------
     AUTO-REFRESH
  ------------------------------------------------------------------ */
  /**
   * Call callback on a repeating interval.
   * @param {function} callback
   * @param {number}   interval - Milliseconds (default 30000).
   * @returns {function} stop function
   */
  function startAutoRefresh(callback, interval) {
    interval = interval || 30000;
    if (_state.autoRefreshTimer) clearInterval(_state.autoRefreshTimer);
    _state.autoRefreshTimer = setInterval(callback, interval);
    return function stop() {
      clearInterval(_state.autoRefreshTimer);
      _state.autoRefreshTimer = null;
    };
  }

  function stopAutoRefresh() {
    if (_state.autoRefreshTimer) {
      clearInterval(_state.autoRefreshTimer);
      _state.autoRefreshTimer = null;
    }
  }

  /* ------------------------------------------------------------------
     CLIPBOARD COPY
  ------------------------------------------------------------------ */
  /**
   * Copy text to clipboard with toast feedback.
   * @param {string} text
   * @param {string} [label] - Optional description for the toast.
   */
  function copy(text, label) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        toast((label || 'Value') + ' copied to clipboard', 'success', 2000);
      }).catch(function () {
        _fallbackCopy(text, label);
      });
    } else {
      _fallbackCopy(text, label);
    }
  }

  function _fallbackCopy(text, label) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-100px;left:-100px;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      toast((label || 'Value') + ' copied to clipboard', 'success', 2000);
    } catch (e) {
      toast('Could not copy to clipboard', 'error', 3000);
    }
    document.body.removeChild(ta);
  }

  /* ------------------------------------------------------------------
     DATE RANGE PICKER
  ------------------------------------------------------------------ */
  /**
   * Inject simple from/to date inputs next to an element.
   * @param {string}   containerId - ID of container element.
   * @param {function} [onChange]  - Called with { from, to } on change.
   */
  function initDateRange(containerId, onChange) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const today = _dateStamp();
    const thirtyAgo = _dateStamp(-30);

    container.innerHTML = `
      <div class="fps-flex fps-flex-gap-sm" style="align-items:center;flex-wrap:wrap;">
        <div class="fps-form-group fps-mb-0" style="flex:1;min-width:140px;">
          <label class="fps-label" for="${containerId}-from">From</label>
          <input type="date" class="fps-input fps-input-sm" id="${containerId}-from" value="${thirtyAgo}" max="${today}">
        </div>
        <div class="fps-form-group fps-mb-0" style="flex:1;min-width:140px;">
          <label class="fps-label" for="${containerId}-to">To</label>
          <input type="date" class="fps-input fps-input-sm" id="${containerId}-to" value="${today}" max="${today}">
        </div>
        <div style="padding-top:20px;">
          <button class="fps-btn fps-btn-primary fps-btn-sm" id="${containerId}-apply">Apply</button>
        </div>
      </div>`;

    document.getElementById(containerId + '-apply').addEventListener('click', function () {
      const from = document.getElementById(containerId + '-from').value;
      const to   = document.getElementById(containerId + '-to').value;
      if (typeof onChange === 'function') onChange({ from, to });
    });
  }

  /** Return date string YYYY-MM-DD offset by `offsetDays` from today. */
  function _dateStamp(offsetDays) {
    const d = new Date();
    if (offsetDays) d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }

  /* ------------------------------------------------------------------
     PROGRESS TRACKER
  ------------------------------------------------------------------ */
  /**
   * Show or update a shared progress bar toast.
   * @param {number} current
   * @param {number} total
   * @param {string} [message]
   */
  function showProgress(current, total, message) {
    const pct  = total > 0 ? Math.round((current / total) * 100) : 0;
    const text = message || ('Processing ' + current + ' / ' + total);
    let bar = document.getElementById('fps-progress-toast');

    if (!bar) {
      const container = document.getElementById('fps-toast-container');
      container.insertAdjacentHTML('beforeend', `
        <div id="fps-progress-toast" class="fps-toast fps-toast-info" role="status" aria-live="polite">
          <div class="fps-toast-content" style="width:100%">
            <div class="fps-toast-title fps-flex fps-flex-between">
              <span id="fps-prog-msg">${_esc(text)}</span>
              <span id="fps-prog-pct">${pct}%</span>
            </div>
            <div class="fps-progress fps-progress-sm" style="margin-top:6px;">
              <div class="fps-progress-bar fps-progress-bar--info" id="fps-prog-bar" style="width:${pct}%"></div>
            </div>
          </div>
        </div>`);
    } else {
      document.getElementById('fps-prog-msg').textContent = text;
      document.getElementById('fps-prog-pct').textContent = pct + '%';
      document.getElementById('fps-prog-bar').style.width = pct + '%';
    }

    if (current >= total) {
      setTimeout(function () {
        const el = document.getElementById('fps-progress-toast');
        if (el) el.remove();
      }, 1500);
    }
  }

  /* ------------------------------------------------------------------
     SPARKLINE (canvas-based mini line chart)
  ------------------------------------------------------------------ */
  /**
   * Draw a tiny line chart inside a container element.
   * @param {string|HTMLElement} container - Element or selector.
   * @param {number[]}           data      - Array of numeric values.
   * @param {object}             [opts]    - { color, width, height, fill }
   */
  function sparkline(container, data, opts) {
    const el = typeof container === 'string' ? document.querySelector(container) : container;
    if (!el || !data || !data.length) return;

    opts = Object.assign({ color: '#667eea', width: 80, height: 28, fill: true }, opts || {});

    let canvas = el.querySelector('canvas.fps-sparkline-canvas');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.className = 'fps-sparkline fps-sparkline-canvas';
      el.appendChild(canvas);
    }

    canvas.width  = opts.width;
    canvas.height = opts.height;
    canvas.style.width  = opts.width  + 'px';
    canvas.style.height = opts.height + 'px';

    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, opts.width, opts.height);

    const min = Math.min.apply(null, data);
    const max = Math.max.apply(null, data);
    const range = (max - min) || 1;
    const pad = 2;
    const W = opts.width  - pad * 2;
    const H = opts.height - pad * 2;

    function px(i) { return pad + (i / (data.length - 1)) * W; }
    function py(v) { return pad + H - ((v - min) / range) * H; }

    ctx.beginPath();
    data.forEach(function (v, i) {
      if (i === 0) ctx.moveTo(px(i), py(v));
      else ctx.lineTo(px(i), py(v));
    });

    ctx.strokeStyle = opts.color;
    ctx.lineWidth = 1.5;
    ctx.lineJoin = 'round';
    ctx.stroke();

    if (opts.fill) {
      ctx.lineTo(px(data.length - 1), opts.height - pad);
      ctx.lineTo(px(0), opts.height - pad);
      ctx.closePath();
      const grad = ctx.createLinearGradient(0, 0, 0, opts.height);
      grad.addColorStop(0, opts.color + '55');
      grad.addColorStop(1, opts.color + '00');
      ctx.fillStyle = grad;
      ctx.fill();
    }
  }

  /* ------------------------------------------------------------------
     HELPER UTILITIES
  ------------------------------------------------------------------ */
  /** HTML-escape a string. */
  function _esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /** Debounce a function. */
  function debounce(fn, delay) {
    let timer;
    return function () {
      clearTimeout(timer);
      const args = arguments;
      const ctx  = this;
      timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
  }

  /** Format a number with comma separators. */
  function formatNumber(n) {
    return Number(n).toLocaleString();
  }

  /** Format bytes to human-readable string. */
  function formatBytes(bytes) {
    const sizes = ['B', 'KB', 'MB', 'GB'];
    if (!bytes) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
  }

  /** Relative time string (e.g. "3 minutes ago"). */
  function timeAgo(dateStr) {
    const diff = Date.now() - new Date(dateStr).getTime();
    const s = Math.floor(diff / 1000);
    if (s < 60)   return s + 's ago';
    const m = Math.floor(s / 60);
    if (m < 60)   return m + 'm ago';
    const h = Math.floor(m / 60);
    if (h < 24)   return h + 'h ago';
    return Math.floor(h / 24) + 'd ago';
  }

  /* ------------------------------------------------------------------
     DASHBOARD LOADERS
  ------------------------------------------------------------------ */

  /**
   * Load dashboard stat cards via AJAX and animate numbers.
   * Also starts 30-second auto-refresh cycle.
   */
  function loadDashboardStats(ajaxUrl) {
    var url = ajaxUrl + '&a=get_dashboard_stats';

    function _load() {
      fetch(url, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (!json.success || !json.data) return;
          var d = json.data;

          var mapping = {
            checks_today: { val: d.checks_today || 0 },
            blocked_today: { val: d.blocked_today || 0 },
            pre_checkout_blocks: { val: d.pre_checkout_blocks || 0 },
            active_threats: { val: d.active_threats || 0 },
            review_queue: { val: d.review_queue || 0 },
            block_rate: { val: d.block_rate || 0, suffix: '%' },
            avg_risk_score: { val: d.avg_risk_score || 0 },
            api_requests: { val: d.api_requests || 0 },
          };

          Object.keys(mapping).forEach(function (key) {
            var el = document.getElementById('fps-val-' + key);
            if (el) {
              var m = mapping[key];
              animateNumber(el, m.val, 1200, m.prefix || '', m.suffix || '');
            }
          });

          // Active threats pulsing glow
          var threatCard = document.getElementById('fps-stat-active_threats');
          if (threatCard) {
            if ((d.active_threats || 0) > 0) {
              threatCard.classList.add('fps-threat-pulse');
            } else {
              threatCard.classList.remove('fps-threat-pulse');
            }
          }

          // Sparkline for checks_today (7-day trend)
          if (d.sparkline && d.sparkline.length) {
            var sparkEl = document.getElementById('fps-spark-checks_today');
            if (sparkEl) {
              sparkline(sparkEl.parentElement, d.sparkline, {
                color: '#667eea', width: 100, height: 30,
              });
            }
          }
        })
        .catch(function (err) {
          console.error('[FpsAdmin] Dashboard stats load failed:', err);
        });
    }

    _load();
    startAutoRefresh(_load, 30000);
  }

  /**
   * Load recent checks table via AJAX.
   */
  function loadRecentChecks(ajaxUrl) {
    var container = document.getElementById('fps-recent-checks-container');
    if (!container) return;

    var url = ajaxUrl + '&a=get_recent_checks';

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json.success || !json.data || !json.data.length) {
          container.innerHTML = '<div class="fps-empty-state">No recent checks found.</div>';
          return;
        }

        var rows = json.data.map(function (c) {
          var clientDisplay = c.client_name ? _esc(c.client_name) :
            (c.client_id > 0 ? '#' + c.client_id : '<span style="opacity:0.5;" title="' + _esc(c.check_type || 'pre_checkout') + '">' + _esc(c.ip_address || 'Guest') + '</span>');
          var actionClass = (c.action_taken === 'approved') ? 'fps-badge-low' :
            (c.action_taken === 'denied' || c.action_taken === 'blocked') ? 'fps-badge-critical' :
            (c.action_taken === 'held' || c.action_taken === 'flagged') ? 'fps-badge-medium' : '';
          var actionLabel = _esc(c.action_taken || 'pending');

          var actions = '<div class="fps-action-group" style="gap:2px;">';
          if (!c.reviewed_by) {
            actions += '<button class="fps-btn fps-btn-xs fps-btn-success" onclick="FpsAdmin.approveCheck(' + c.id + ')" title="Approve"><i class="fas fa-check"></i></button>';
            actions += '<button class="fps-btn fps-btn-xs fps-btn-danger" onclick="FpsAdmin.denyCheck(' + c.id + ')" title="Deny"><i class="fas fa-times"></i></button>';
          }
          actions += '<a href="' + _state.modulelink + '&tab=client_profile&client_id=' + (c.client_id || 0) + '" class="fps-btn fps-btn-xs fps-btn-info" title="View Profile"><i class="fas fa-user-shield"></i></a>';
          actions += '</div>';

          return '<tr>' +
            '<td style="font-size:0.85rem;">' + clientDisplay + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(c.email || '') + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(c.ip_address || '') + '</td>' +
            '<td><span class="fps-badge fps-badge-' + _esc(c.risk_level || 'low') + '">' + _esc(c.risk_level || '') + ' (' + parseFloat(c.risk_score || 0).toFixed(0) + ')</span></td>' +
            '<td><span class="fps-badge ' + actionClass + '">' + actionLabel + '</span></td>' +
            '<td style="font-size:0.8rem;">' + timeAgo(c.created_at || '') + '</td>' +
            '<td>' + actions + '</td>' +
            '</tr>';
        });

        container.innerHTML =
          '<table class="fps-table fps-table-striped" id="fps-recent-checks-table">' +
          '<thead><tr><th>Client</th><th>Email</th><th>IP</th><th>Risk</th><th>Status</th><th>Time</th><th>Actions</th></tr></thead>' +
          '<tbody>' + rows.join('') + '</tbody></table>';

        initTable('fps-recent-checks-table');
      })
      .catch(function (err) {
        container.innerHTML = '<div class="fps-empty-state">Failed to load recent checks.</div>';
        console.error('[FpsAdmin] Recent checks load failed:', err);
      });
  }

  /**
   * Refresh the entire dashboard (stats + recent checks).
   */
  function refreshDashboard() {
    var ajaxUrl = _state.modulelink + '&ajax=1';
    loadDashboardStats(ajaxUrl);
    loadRecentChecks(ajaxUrl);
    toast('Dashboard refreshed', 'info', 2000);
  }

  /**
   * Run a manual fraud check.
   * Called from Dashboard (no clientId arg, reads from input) or
   * Client Profile (clientId passed as 2nd arg).
   */
  function runManualCheck(ajaxUrl, clientIdArg) {
    var clientId;

    // If called with explicit clientId (e.g., from Client Profile Scan Now buttons)
    if (clientIdArg && parseInt(clientIdArg, 10) > 0) {
      clientId = parseInt(clientIdArg, 10);
    } else {
      // Read from dashboard input
      var clientIdInput = document.getElementById('fps-manual-client-id');
      if (!clientIdInput) {
        toast('No client ID provided', 'warning');
        return;
      }
      clientId = parseInt(clientIdInput.value, 10);
    }

    if (!clientId || clientId < 1) {
      toast('Please enter a valid Client ID', 'warning');
      return;
    }

    // Show result in either the dashboard result div or a toast
    var resultDiv = document.getElementById('fps-manual-check-result');
    if (resultDiv) {
      resultDiv.style.display = '';
      resultDiv.innerHTML = '<div class="fps-skeleton-container"><div class="fps-skeleton-line" style="width:100%"></div><div class="fps-skeleton-line" style="width:80%"></div></div>';
    } else {
      toast('Scanning client #' + clientId + '...', 'info');
    }

    ajax('run_manual_check', { client_id: clientId }, function (err, data) {
      if (err || !data || !data.success) {
        var errMsg = (data && (data.error || data.message)) || 'Unknown error';
        if (resultDiv) {
          resultDiv.innerHTML = '<div class="fps-alert fps-alert-danger">Check failed: ' + _esc(errMsg) + '</div>';
        } else {
          toast('Scan failed: ' + errMsg, 'error');
        }
        return;
      }

      var r = data.data || {};
      var score = r.risk ? (r.risk.score || 0) : (r.risk_score || 0);
      var lvl = r.risk ? (r.risk.level || 'low') : (r.risk_level || 'low');
      var providers = r.risk ? Object.keys(r.risk.providerScores || {}).length : (r.provider_count || 0);
      var duration = r.executionMs || r.duration_ms || 0;
      var action = r.actionTaken || r.action_taken || 'none';

      var html =
        '<div class="fps-alert fps-alert-' + (lvl === 'critical' || lvl === 'high' ? 'danger' : lvl === 'medium' ? 'warning' : 'success') + '">' +
        '<strong>Risk Score: ' + _esc(String(Math.round(score * 10) / 10)) + '/100</strong> ' +
        '<span class="fps-badge fps-badge-' + _esc(lvl) + '">' + _esc(lvl.toUpperCase()) + '</span><br>' +
        '<small>Action: ' + _esc(action) + ' | Providers: ' + _esc(String(providers)) + ' | Duration: ' + _esc(String(Math.round(duration))) + 'ms</small>' +
        '</div>';

      if (resultDiv) {
        resultDiv.innerHTML = html;
      } else {
        // On client profile page, show as toast + reload to refresh data
        toast('Client #' + clientId + ': Score ' + Math.round(score * 10) / 10 + '/100 (' + lvl.toUpperCase() + ')',
          lvl === 'critical' || lvl === 'high' ? 'error' : lvl === 'medium' ? 'warning' : 'success');
        setTimeout(function() { location.reload(); }, 2000);
      }
    });
  }

  /* ------------------------------------------------------------------
     PUBLIC API
  ------------------------------------------------------------------ */
  window.FpsAdmin = {
    // Core
    init,
    // Theme
    toggleTheme,
    // Toast
    toast,
    dismissToast,
    // AJAX
    ajax,
    // Tabs
    switchTab,
    // Animations
    animateNumber,
    // Modal
    showModal,
    closeModal,
    confirm,
    // Table
    initTable,
    initSearch,
    exportCSV,
    // Batch
    selectAll,
    getSelected,
    // Render helpers
    riskBadge,
    // Loading
    showLoading,
    hideLoading,
    // Refresh
    startAutoRefresh,
    stopAutoRefresh,
    // Clipboard
    copy,
    // Date range
    initDateRange,
    // Progress
    showProgress,
    // Sparkline
    sparkline,
    // Dashboard
    loadDashboardStats,
    loadRecentChecks,
    refreshDashboard,
    runManualCheck,
    // Utilities
    debounce,
    formatNumber,
    formatBytes,
    timeAgo,

    // Font scale / display size
    changeFontScale: function(scale) {
      scale = parseFloat(scale) || 1.0;
      if (scale < 0.85) scale = 0.85;
      if (scale > 1.4) scale = 1.4;
      var wrapper = document.querySelector('.fps-module-wrapper');
      if (wrapper) {
        wrapper.style.setProperty('--fps-font-scale', scale);
        wrapper.style.zoom = scale;
      }
      ajax('save_settings', {settings: JSON.stringify({ui_font_scale: String(scale)})}, function(err, r) {
        if (!err && r && !r.error) toast('Display size updated to ' + Math.round(scale * 100) + '%', 'success', 2000);
      });
    },

    // --- Theme color live preview ---

    previewColor: function(key, value) {
      if (!/^#[0-9a-fA-F]{6}$/.test(value)) return;
      var wrapper = document.querySelector('.fps-module-wrapper') || document.documentElement;
      var map = {
        'admin_primary_color':   '--fps-primary',
        'admin_secondary_color': '--fps-secondary',
        'admin_bg_color':        '--fps-bg',
        'admin_surface_color':   '--fps-surface',
        'admin_text_color':      '--fps-text-primary'
      };
      if (map[key]) {
        wrapper.style.setProperty(map[key], value);
        // Update gradient if primary or secondary changed
        if (key === 'admin_primary_color' || key === 'admin_secondary_color') {
          var p = key === 'admin_primary_color' ? value :
            (document.querySelector('input[name="admin_primary_color"]') || {}).value || '#667eea';
          var s = key === 'admin_secondary_color' ? value :
            (document.querySelector('input[name="admin_secondary_color"]') || {}).value || '#764ba2';
          wrapper.style.setProperty('--fps-grad-primary', 'linear-gradient(135deg, ' + p + ' 0%, ' + s + ' 100%)');
        }
      }
    },

    toggleDarkMode: function(enabled) {
      var wrapper = document.querySelector('.fps-module-wrapper');
      if (wrapper) {
        if (enabled) {
          wrapper.classList.add('fps-theme-dark');
        } else {
          wrapper.classList.remove('fps-theme-dark');
        }
      }
      ajax('save_settings', {settings: JSON.stringify({admin_dark_mode: enabled ? '1' : '0'})}, function(err, r) {
        if (!err && r && !r.error) toast('Dark mode ' + (enabled ? 'enabled' : 'disabled'), 'success', 2000);
      });
    },

    resetThemeDefaults: function() {
      var el = document.getElementById('fps-color-defaults');
      if (!el) return;
      try {
        var defaults = JSON.parse(el.value);
        var settings = {};
        for (var key in defaults) {
          if (!defaults.hasOwnProperty(key)) continue;
          settings[key] = defaults[key];
          // Update both color and text inputs
          var inputs = document.querySelectorAll('input[name="' + key + '"]');
          inputs.forEach(function(inp) { inp.value = defaults[key]; });
          // Apply preview
          FpsAdmin.previewColor(key, defaults[key]);
        }
        // Also reset dark mode
        settings['admin_dark_mode'] = '0';
        var dmCb = document.querySelector('input[name="admin_dark_mode"]');
        if (dmCb) dmCb.checked = false;
        FpsAdmin.toggleDarkMode(false);

        ajax('save_settings', {settings: JSON.stringify(settings)}, function(err, r) {
          if (!err && r && !r.error) toast('Theme colors reset to defaults', 'success', 3000);
        });
      } catch(e) { console.error('resetThemeDefaults:', e); }
    },

    // --- Per-section font size live preview ---
    previewFontSize: function(key, value) {
      var map = {
        'font_size_tabs':         '--fps-size-tabs',
        'font_size_stats':        '--fps-size-stats',
        'font_size_stat_labels':  '--fps-size-stat-labels',
        'font_size_table_header': '--fps-size-th',
        'font_size_table_body':   '--fps-size-td',
        'font_size_card_header':  '--fps-size-card-h',
        'font_size_card_body':    '--fps-size-card-body',
      };
      var cssVar = map[key];
      if (!cssVar) return;
      var parsed = parseFloat(value);
      if (isNaN(parsed) || parsed < 0.6 || parsed > 2.0) return;
      var wrapper = document.querySelector('.fps-module-wrapper') || document.documentElement;
      wrapper.style.setProperty(cssVar, parsed.toFixed(2) + 'rem');
    },

    // -- Full Typography Panel -----------------------------------------------------------------

    _typoActive: 'tabs',

    _typoDefaults: {
      tabs:         { family: 'system', weight: '600', size: '0.84', letterSpacing: '0.01', lineHeight: '1.4' },
      stats:        { family: 'system', weight: '700', size: '1.80', letterSpacing: '-0.02', lineHeight: '1.2' },
      stat_labels:  { family: 'system', weight: '500', size: '0.85', letterSpacing: '0.06', lineHeight: '1.4' },
      table_header: { family: 'system', weight: '600', size: '0.80', letterSpacing: '0.07', lineHeight: '1.4' },
      table_body:   { family: 'system', weight: '400', size: '0.90', letterSpacing: '0.00', lineHeight: '1.5' },
      card_header:  { family: 'system', weight: '600', size: '1.10', letterSpacing: '0.01', lineHeight: '1.3' },
      card_body:    { family: 'system', weight: '400', size: '0.95', letterSpacing: '0.00', lineHeight: '1.6' },
    },

    _typo: null,

    _fontTokenMap: {
      'system':       "system-ui,-apple-system,'Segoe UI',sans-serif",
      'georgia':      "Georgia,'Times New Roman',serif",
      'mono':         "'JetBrains Mono','Fira Code',Consolas,monospace",
      'arial':        "Arial,Helvetica,sans-serif",
      'inter-sys':    "Inter,system-ui,sans-serif",
      'inter':        "Inter,system-ui,sans-serif",
      'roboto':       "Roboto,system-ui,sans-serif",
      'poppins':      "Poppins,system-ui,sans-serif",
      'opensans':     "'Open Sans',system-ui,sans-serif",
      'lato':         "Lato,system-ui,sans-serif",
      'nunito':       "Nunito,system-ui,sans-serif",
      'merriweather': "Merriweather,Georgia,serif",
      'playfair':     "'Playfair Display',Georgia,serif",
      'jetbrains':    "'JetBrains Mono',Consolas,monospace",
    },

    _googleFontUrls: {
      'inter':        'Inter:wght@300;400;600;700',
      'roboto':       'Roboto:wght@300;400;600;700',
      'poppins':      'Poppins:wght@300;400;600;700',
      'opensans':     'Open+Sans:wght@300;400;600;700',
      'lato':         'Lato:wght@300;400;600;700',
      'nunito':       'Nunito:wght@300;400;600;700',
      'merriweather': 'Merriweather:wght@300;400;700',
      'playfair':     'Playfair+Display:wght@400;700',
      'jetbrains':    'JetBrains+Mono:wght@300;400;600;700',
    },

    _sizeVarMap: {
      'tabs':         '--fps-size-tabs',
      'stats':        '--fps-size-stats',
      'stat_labels':  '--fps-size-stat-labels',
      'table_header': '--fps-size-th',
      'table_body':   '--fps-size-td',
      'card_header':  '--fps-size-card-h',
      'card_body':    '--fps-size-card-body',
    },

    _cssPrefixMap: {
      'tabs':         'tabs',
      'stats':        'stats',
      'stat_labels':  'stat-labels',
      'table_header': 'table-header',
      'table_body':   'table-body',
      'card_header':  'card-header',
      'card_body':    'card-body',
    },

    initTypo: function() {
      var self = FpsAdmin;
      var init = window._fpsTypoInit;
      if (!init) return;
      self._typo = {};
      var keys = ['tabs','stats','stat_labels','table_header','table_body','card_header','card_body'];
      keys.forEach(function(k) {
        var src = init[k] || {};
        var def = self._typoDefaults[k];
        self._typo[k] = {
          family:        src.family        || def.family,
          weight:        src.weight        || def.weight,
          size:          src.size          || def.size,
          letterSpacing: src.letterSpacing || def.letterSpacing,
          lineHeight:    src.lineHeight    || def.lineHeight,
        };
      });
      self.switchTypoSection('tabs');
    },

    switchTypoSection: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;
      self._typoActive = sectionKey;
      var tv = self._typo[sectionKey];

      // Update mini-tab active styles
      document.querySelectorAll('.fps-typo-tab').forEach(function(btn) {
        var active = btn.getAttribute('data-section') === sectionKey;
        btn.style.background  = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)';
        btn.style.color       = active ? '#fff' : 'var(--fps-text-secondary,#5a6176)';
        btn.style.borderColor = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-border,#dde1ef)';
      });

      // Update family select
      var sel = document.getElementById('fps-typo-family-select');
      if (sel) sel.value = tv.family;

      // Update weight toggle active styles
      document.querySelectorAll('.fps-typo-weight-btn').forEach(function(btn) {
        var active = btn.getAttribute('data-weight') === tv.weight;
        btn.style.background  = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)';
        btn.style.color       = active ? '#fff' : 'var(--fps-text-primary,#1a1d2e)';
        btn.style.borderColor = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-border,#dde1ef)';
      });

      // Update sliders + value displays
      var sliderData = {
        'fps-typo-size':          { val: tv.size,          unit: 'rem' },
        'fps-typo-letterSpacing': { val: tv.letterSpacing, unit: 'em'  },
        'fps-typo-lineHeight':    { val: tv.lineHeight,     unit: ''   },
      };
      Object.keys(sliderData).forEach(function(id) {
        var el    = document.getElementById(id);
        var valEl = document.getElementById(id + '-val');
        if (el) el.value = sliderData[id].val;
        if (valEl) valEl.textContent = sliderData[id].val + sliderData[id].unit;
      });

      // Update live preview
      self._updateTypoPreview(sectionKey, tv);
    },

    previewTypo: function(sectionKey, property, value) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;

      // Update state
      if (property === 'letterSpacing' || property === 'lineHeight'
          || property === 'size' || property === 'family' || property === 'weight') {
        self._typo[sectionKey][property] = value;
      }

      var tv         = self._typo[sectionKey];
      var wrapper    = document.querySelector('.fps-module-wrapper') || document.documentElement;
      var cssPrefix  = self._cssPrefixMap[sectionKey] || sectionKey;

      if (property === 'family') {
        self.loadGoogleFont(value).then(function() {
          var stack = self._fontTokenMap[value] || self._fontTokenMap['system'];
          wrapper.style.setProperty('--fps-font-' + cssPrefix, stack);
          self._updateTypoPreview(sectionKey, self._typo[sectionKey]);
        });
      } else if (property === 'weight') {
        wrapper.style.setProperty('--fps-weight-' + cssPrefix, value);
        // Update weight toggle active styles
        document.querySelectorAll('.fps-typo-weight-btn').forEach(function(btn) {
          var active = btn.getAttribute('data-weight') === value;
          btn.style.background  = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-surface-2,#f8f9fc)';
          btn.style.color       = active ? '#fff' : 'var(--fps-text-primary,#1a1d2e)';
          btn.style.borderColor = active ? 'var(--fps-primary,#667eea)' : 'var(--fps-border,#dde1ef)';
        });
        self._updateTypoPreview(sectionKey, tv);
      } else if (property === 'size') {
        var sizeVar = self._sizeVarMap[sectionKey] || ('--fps-size-' + cssPrefix);
        wrapper.style.setProperty(sizeVar, parseFloat(value).toFixed(2) + 'rem');
        var sizeValEl = document.getElementById('fps-typo-size-val');
        if (sizeValEl) sizeValEl.textContent = parseFloat(value).toFixed(2) + 'rem';
        self._updateTypoPreview(sectionKey, tv);
      } else if (property === 'letterSpacing') {
        wrapper.style.setProperty('--fps-tracking-' + cssPrefix, parseFloat(value).toFixed(2) + 'em');
        var lsValEl = document.getElementById('fps-typo-letterSpacing-val');
        if (lsValEl) lsValEl.textContent = parseFloat(value).toFixed(2) + 'em';
        self._updateTypoPreview(sectionKey, tv);
      } else if (property === 'lineHeight') {
        wrapper.style.setProperty('--fps-lh-' + cssPrefix, parseFloat(value).toFixed(1));
        var lhValEl = document.getElementById('fps-typo-lineHeight-val');
        if (lhValEl) lhValEl.textContent = parseFloat(value).toFixed(1);
        self._updateTypoPreview(sectionKey, tv);
      }

      self._syncTypoHidden(sectionKey);
    },

    loadGoogleFont: function(token) {
      var self = FpsAdmin;
      if (!self._googleFontUrls[token]) {
        return Promise.resolve();
      }
      if (document.querySelector('link[data-fps-font="' + token + '"]')) {
        return Promise.resolve();
      }
      return new Promise(function(resolve) {
        var link  = document.createElement('link');
        link.rel  = 'stylesheet';
        link.setAttribute('data-fps-font', token);
        link.href = 'https://fonts.googleapis.com/css2?family='
                  + self._googleFontUrls[token] + '&display=swap';
        var timer = setTimeout(resolve, 800);
        link.onload  = function() { clearTimeout(timer); resolve(); };
        link.onerror = function() { clearTimeout(timer); resolve(); };
        document.head.appendChild(link);
      });
    },

    _syncTypoHidden: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typo || !self._typo[sectionKey]) return;
      var tv = self._typo[sectionKey];
      var el = document.getElementById('fps-typo-hidden-' + sectionKey);
      if (!el) return;
      el.value = JSON.stringify({
        family:        tv.family,
        weight:        tv.weight,
        size:          tv.size,
        letterSpacing: tv.letterSpacing,
        lineHeight:    tv.lineHeight,
      });
    },

    _updateTypoPreview: function(sectionKey, tv) {
      var self = FpsAdmin;
      var prev = document.getElementById('fps-typo-preview');
      if (!prev) return;
      var stack = self._fontTokenMap[tv.family] || self._fontTokenMap['system'];
      prev.style.fontFamily    = stack;
      prev.style.fontWeight    = tv.weight;
      prev.style.fontSize      = parseFloat(tv.size).toFixed(2) + 'rem';
      prev.style.letterSpacing = parseFloat(tv.letterSpacing).toFixed(2) + 'em';
      prev.style.lineHeight    = parseFloat(tv.lineHeight).toFixed(1);
    },

    resetTypoSection: function(sectionKey) {
      var self = FpsAdmin;
      if (!self._typo || !self._typoDefaults[sectionKey]) return;
      self._typo[sectionKey] = Object.assign({}, self._typoDefaults[sectionKey]);
      self.switchTypoSection(sectionKey);
      self._syncTypoHidden(sectionKey);
    },

    resetAllTypo: function() {
      var self = FpsAdmin;
      if (!self._typo) return;
      Object.keys(self._typoDefaults).forEach(function(k) {
        self._typo[k] = Object.assign({}, self._typoDefaults[k]);
        self._syncTypoHidden(k);
      });
      self.switchTypoSection(self._typoActive);
    },

    // --- Tab-specific action handlers (AJAX wrappers) ---

    // API Keys tab
    createApiKey: function(ajaxUrl) {
      var name = document.getElementById('fps-apikey-name');
      var tier = document.getElementById('fps-apikey-tier');
      var email = document.getElementById('fps-apikey-email');
      ajax('create_api_key', {name: name ? name.value : '', tier: tier ? tier.value : 'free', email: email ? email.value : ''}, function(err, r) {
        if (err || !r || r.error) { toast(r ? r.error : 'Request failed', 'error'); return; }
        toast('API key created', 'success');
        if (r.api_key) {
          var valEl = document.getElementById('fps-apikey-value');
          var wrap = document.getElementById('fps-apikey-generated');
          if (valEl) valEl.textContent = r.api_key;
          if (wrap) wrap.style.display = 'block';
        }
        setTimeout(function() { location.reload(); }, 2000);
      });
    },
    viewKeyDetail: function(keyId, ajaxUrl) {
      ajax('get_api_key_detail', {key_id: keyId}, function(err, r) {
        if (err || !r) return;
        var html = r.html || '<pre>' + JSON.stringify(r.data || r, null, 2) + '</pre>';
        showModal('API Key Details', html);
      });
    },
    revokeApiKey: function(keyId, ajaxUrl) {
      confirm('Revoke this API key? This cannot be undone.', function() {
        ajax('revoke_api_key', {key_id: keyId}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Revoke failed', 'error'); return; }
          toast('API key revoked', 'success'); location.reload();
        });
      });
    },
    copyApiKey: function() {
      var el = document.getElementById('fps-apikey-value');
      if (el) copy(el.textContent);
    },

    // Review Queue tab
    approveCheck: function(checkId, ajaxUrl) {
      ajax('approve_check', {check_id: checkId}, function(err, data) {
        if (err || (data && data.error)) { toast(data ? data.error : 'Approve failed', 'error'); return; }
        toast('Check approved', 'success'); location.reload();
      });
    },
    denyCheck: function(checkId, ajaxUrl) {
      ajax('deny_check', {check_id: checkId}, function(err, data) {
        if (err || (data && data.error)) { toast(data ? data.error : 'Deny failed', 'error'); return; }
        toast('Check denied', 'success'); location.reload();
      });
    },
    bulkAction: function(action, ajaxUrl) {
      // archive_guest is a server-side operation on all client_id=0 rows -- no selection needed
      if (action === 'archive_guest') {
        confirm(
          'Archive all unreviewed guest (pre-checkout) checks? These are fraud events from anonymous visitors with no associated client account.',
          function() {
            ajax('archive_guest', {}, function(err, data) {
              if (err || (data && data.error)) { toast(data ? data.error : 'Archive failed', 'error'); return; }
              toast((data.message || 'Guest checks archived'), 'success');
              setTimeout(function() { location.reload(); }, 800);
            });
          }
        );
        return;
      }
      var ids = getSelected('fps-queue-check');
      if (!ids.length) { toast('No items selected', 'warning'); return; }
      ajax('bulk_' + action, {check_ids: ids.join(',')}, function(err, data) {
        if (err || (data && data.error)) { toast(data ? data.error : 'Bulk action failed', 'error'); return; }
        toast('Bulk ' + action + ' complete', 'success'); location.reload();
      });
    },
    reportToFraudRecord: function(checkId, ajaxUrl) {
      confirm('Report this check to FraudRecord?', function() {
        ajax('report_fraudrecord', {check_id: checkId}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Report failed', 'error'); return; }
          toast('Reported to FraudRecord', 'success');
        });
      });
    },

    // Settings tab
    saveSettings: function(ajaxUrl) {
      var form = document.getElementById('fps-settings-form') || document.querySelector('.fps-module-wrapper');
      var settings = {};
      var gatewayThresholds = {};
      if (form) {
        form.querySelectorAll('input, select, textarea').forEach(function(el) {
          var name = el.name || el.id;
          if (!name) return;
          // Skip non-setting fields
          if (name === 'token' || name === 'module' || name === 'action') return;
          // Skip typography UI controls (not hidden inputs) - they have no name and sync via _syncTypoHidden
          if (el.id && el.id.indexOf('fps-typo-') === 0 && el.type !== 'hidden') return;
          var val = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
          // Detect gateway threshold fields: gateway_thresholds[gateway][field]
          var gwMatch = name.match(/^gateway_thresholds\[([^\]]+)\]\[([^\]]+)\]$/);
          if (gwMatch) {
            if (!gatewayThresholds[gwMatch[1]]) gatewayThresholds[gwMatch[1]] = {};
            gatewayThresholds[gwMatch[1]][gwMatch[2]] = val;
          } else {
            settings[name] = val;
          }
        });
      }
      ajax('save_settings', {settings: JSON.stringify(settings), gateway_thresholds: JSON.stringify(gatewayThresholds)}, function() { toast('Settings saved', 'success'); });
    },
    purgeAllCaches: function(ajaxUrl) {
      confirm('Purge all caches? This may temporarily slow down checks.', function() {
        ajax('purge_caches', {}, function() { toast('Caches purged', 'success'); });
      });
    },
    resetStatistics: function(ajaxUrl) {
      confirm('Reset all statistics? This cannot be undone.', function() {
        ajax('reset_statistics', {}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Reset failed', 'error'); return; }
          toast('Statistics reset', 'success');
        });
      });
    },
    clearFpsLogs: function(ajaxUrl) {
      confirm('Clear ALL module logs? This permanently deletes all log entries and cannot be undone.', function() {
        ajax('clear_fps_logs', {}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Clear failed', 'error'); return; }
          toast('Cleared ' + (data.deleted || 0) + ' log entries', 'success');
          setTimeout(function() { location.reload(); }, 1000);
        });
      });
    },
    clearAllChecks: function(ajaxUrl) {
      confirm('Clear ALL fraud checks? This permanently deletes all check history and cannot be undone. Statistics are preserved.', function() {
        ajax('clear_all_checks', {confirm: 'yes'}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Clear failed', 'error'); return; }
          toast('All fraud checks cleared', 'success');
          setTimeout(function() { location.reload(); }, 1000);
        });
      });
    },
    toggleAccordion: function(key) {
      var body = document.getElementById('fps-accordion-' + key);
      if (body) body.style.display = body.style.display === 'none' ? 'block' : 'none';
    },
    updateSliderValue: function(name, value) {
      var display = document.querySelector('[data-slider-display="' + name + '"]');
      if (!display) {
        var slider = document.querySelector('[name="' + name + '"]') || document.getElementById(name);
        if (slider) { var sib = slider.nextElementSibling; if (sib) sib.textContent = value; }
      } else { display.textContent = value; }
    },

    // Trust Management tab
    loadTrustList: function(ajaxUrl, filter, search) {
      var container = document.getElementById('fps-trust-list-container');
      if (!container) return;
      container.innerHTML = '<div style="text-align:center;padding:2rem;opacity:0.5;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

      var params = {filter: filter || ''};
      if (search) params.search = search;

      ajax('get_trust_list', params, function(err, data) {
        if (err || !data) { container.innerHTML = '<div class="fps-alert fps-alert-danger">Failed to load trust list</div>'; return; }
        if (data.error) { container.innerHTML = '<div class="fps-alert fps-alert-danger">' + _esc(data.error) + '</div>'; return; }

        var rows = data.rows || [];
        if (rows.length === 0) {
          container.innerHTML = '<div style="text-align:center;padding:2rem;opacity:0.5;"><i class="fas fa-users-slash"></i> No clients found matching this filter.</div>';
          return;
        }

        var html = '<table class="fps-table" id="fps-trust-table"><thead><tr>'
          + '<th>ID</th><th>Client</th><th>Email</th><th>WHMCS Status</th><th>Trust Status</th><th>Reason</th><th>Action</th>'
          + '</tr></thead><tbody>';
        for (var i = 0; i < rows.length; i++) {
          var r = rows[i];
          var ts = r.trust_status || r.status || 'normal';
          var statusBadge = ts === 'trusted' ? '<span class="fps-badge fps-badge-low">Trusted</span>' :
            ts === 'blacklisted' ? '<span class="fps-badge fps-badge-critical">Blacklisted</span>' :
            ts === 'suspended' ? '<span class="fps-badge fps-badge-high">Suspended</span>' :
            '<span class="fps-badge fps-badge-medium">Normal</span>';
          var whmcsStatus = _esc(r.whmcs_status || '');
          var whmcsBadge = whmcsStatus === 'Active' ? '<span class="fps-badge fps-badge-low">' + whmcsStatus + '</span>' :
            whmcsStatus === 'Inactive' ? '<span class="fps-badge fps-badge-medium">' + whmcsStatus + '</span>' :
            whmcsStatus === 'Closed' ? '<span class="fps-badge fps-badge-critical">' + whmcsStatus + '</span>' :
            '<span class="fps-badge">' + whmcsStatus + '</span>';

          // Quick-action button to set trust from the list
          var quickAction = '<button class="fps-btn fps-btn-xs fps-btn-outline" onclick="'
            + 'document.getElementById(\'fps-trust-client-id\').value=' + (r.client_id || 0)
            + ';document.getElementById(\'fps-trust-client-id\').scrollIntoView({behavior:\'smooth\'})"'
            + ' title="Edit trust for this client"><i class="fas fa-pen"></i></button>';

          html += '<tr>' +
            '<td>#' + (r.client_id || '') + '</td>' +
            '<td>' + _esc(r.client_name || '') + (r.company ? ' <span style="opacity:0.6;font-size:0.8rem;">(' + _esc(r.company) + ')</span>' : '') + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(r.client_email || '') + '</td>' +
            '<td>' + whmcsBadge + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td style="font-size:0.85rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;">' + _esc(r.reason || '-') + '</td>' +
            '<td>' + quickAction + '</td>' +
            '</tr>';
        }
        html += '</tbody></table>';
        var totalInfo = data.total ? (data.total + ' total, page ' + Math.ceil((data.total > 0 ? 1 : 0)) + ' of ' + (data.pages || 1)) : rows.length + ' shown';
        html += '<div style="margin-top:0.5rem;font-size:0.85rem;opacity:0.7;">' + totalInfo + '</div>';
        container.innerHTML = html;
        initTable('fps-trust-table');
      });
    },
    searchTrustList: function(ajaxUrl) {
      var searchInput = document.getElementById('fps-trust-search');
      var searchVal = searchInput ? searchInput.value.trim() : '';
      this.loadTrustList(ajaxUrl, '', searchVal);
    },
    setClientTrust: function(ajaxUrl) {
      var clientId = document.getElementById('fps-trust-client-id');
      var status = document.getElementById('fps-trust-status');
      var reason = document.getElementById('fps-trust-reason');
      if (!clientId || !clientId.value) { toast('Enter a client ID', 'warning'); return; }
      ajax('set_client_trust', {
        client_id: clientId.value,
        status: status ? status.value : 'normal',
        reason: reason ? reason.value : ''
      }, function(err, data) {
        if (err) { toast('Failed: ' + err.message, 'error'); return; }
        if (data && data.error) { toast(data.error, 'error'); return; }
        toast(data && data.message ? data.message : 'Updated', 'success');
        var result = document.getElementById('fps-trust-result');
        if (result) { result.style.display = 'block'; result.innerHTML = '<div class="fps-alert fps-alert-success">' + _esc(data.message || 'Trust status updated') + '</div>'; }
        // Reload the trust list to show the change
        setTimeout(function() { location.reload(); }, 1000);
      });
    },

    // Rules tab
    openRuleModal: function(ruleData, ajaxUrl) {
      var modal = document.getElementById('fps-rule-modal');
      if (!modal) return;
      // Reset form fields
      modal.querySelectorAll('input, select, textarea').forEach(function(el) { if (el.name) el.value = ''; });
      // Populate fields from ruleData if editing an existing rule
      if (ruleData) {
        // Map DB column names to form field names
        var mapping = {
          'rule_name': 'rule_name', 'rule_type': 'rule_type', 'rule_value': 'rule_value',
          'action': 'action', 'priority': 'priority', 'score_weight': 'score_weight',
          'description': 'description', 'expires_at': 'expires_at'
        };
        Object.keys(mapping).forEach(function(dbKey) {
          var formName = mapping[dbKey];
          var el = modal.querySelector('[name="' + formName + '"]');
          if (el && ruleData[dbKey] !== undefined && ruleData[dbKey] !== null) el.value = ruleData[dbKey];
        });
        var idEl = modal.querySelector('[name="rule_id"]'); if (idEl && ruleData.id) idEl.value = ruleData.id;
      }
      // Show the pre-rendered modal
      modal.style.display = 'flex';
      modal.classList.add('fps-modal-open');
    },
    saveRule: function(ajaxUrl) {
      var modal = document.getElementById('fps-rule-modal');
      var data = {};
      if (modal) {
        modal.querySelectorAll('input, select, textarea').forEach(function(el) {
          if (el.name) data[el.name] = el.value;
        });
        // Map form field 'action' to PHP's expected 'rule_action'
        if (data.action && !data.rule_action) {
          data.rule_action = data.action;
        }
      }
      ajax('save_rule', data, function(err, result) {
        if (err) { toast('Save failed: ' + err.message, 'error'); return; }
        if (result && result.error) { toast('Error: ' + result.error, 'error'); return; }
        toast('Rule saved', 'success');
        location.reload();
      });
    },
    deleteRule: function(ruleId, ajaxUrl) {
      confirm('Delete this rule?', function() {
        ajax('delete_rule', {rule_id: ruleId}, function(err, data) {
          if (err || (data && data.error)) { toast(data ? data.error : 'Delete failed', 'error'); return; }
          toast('Rule deleted', 'success'); location.reload();
        });
      });
    },
    toggleRule: function(ruleId, enabled, ajaxUrl) {
      ajax('toggle_rule', {rule_id: ruleId, enabled: enabled ? 1 : 0}, function(err, data) {
        if (err) { toast('Toggle failed', 'error'); return; }
        if (data && data.error) { toast(data.error, 'error'); return; }
        toast('Rule ' + (enabled ? 'enabled' : 'disabled'), 'success');
      });
    },

    // Reports tab
    submitReport: function(ajaxUrl) {
      var form = document.getElementById('fps-report-form');
      var data = {};
      if (form) form.querySelectorAll('input, select, textarea').forEach(function(el) { if (el.name) data[el.name] = el.value; });
      ajax('report_fraudrecord', data, function(err, r) {
        if (err) { toast('Submit failed', 'error'); return; }
        if (r && r.error) { toast(r.error, 'error'); return; }
        toast('Report submitted', 'success');
        location.reload();
      });
    },
    confirmReport: function(ajaxUrl) {
      var form = document.getElementById('fps-report-form');
      var data = {};
      if (form) form.querySelectorAll('input, select, textarea').forEach(function(el) { if (el.name) data[el.name] = el.value; });
      confirm('Submit this fraud report?', function() {
        ajax('report_fraudrecord', data, function(err, r) {
          if (err) { toast('Report failed', 'error'); return; }
          if (r && r.error) { toast(r.error, 'error'); return; }
          toast('Fraud report confirmed and submitted', 'success');
          location.reload();
        });
      });
    },
    viewReportDetail: function(reportId, ajaxUrl) {
      ajax('get_report_detail', {report_id: reportId}, function(err, data) {
        if (err || !data || data.error) { toast(data ? data.error : 'Failed to load report', 'error'); return; }
        var r = data.report || {};
        var c = data.client || {};
        var html = '<div style="padding:10px;font-size:0.9rem;line-height:1.6;">';
        html += '<p><strong>Report #' + r.id + '</strong> - ' + (r.status || 'pending') + '</p>';
        html += '<p>Type: ' + (r.report_type || 'internal') + '</p>';
        if (c.email) html += '<p>Client: ' + c.firstname + ' ' + c.lastname + ' (' + c.email + ')</p>';
        html += '<p>Submitted: ' + (r.submitted_at || 'N/A') + '</p>';
        if (r.report_data) html += '<p>Data: <pre style="white-space:pre-wrap;max-height:200px;overflow:auto;">' + r.report_data + '</pre></p>';
        html += '</div>';
        showModal('Report Detail', html);
      });
    },
    updateReportStatus: function(reportId, newStatus, ajaxUrl) {
      ajax('update_report_status', {report_id: reportId, status: newStatus}, function(err, data) {
        if (err) { toast('Update failed', 'error'); return; }
        if (data && data.error) { toast(data.error, 'error'); return; }
        toast('Report status updated to ' + newStatus, 'success');
        location.reload();
      });
    },
    deleteReport: function(reportId, ajaxUrl) {
      confirm('Delete report #' + reportId + '?', function() {
        ajax('delete_report', {report_id: reportId}, function(err, data) {
          if (err) { toast('Delete failed', 'error'); return; }
          if (data && data.error) { toast(data.error, 'error'); return; }
          toast('Report deleted', 'success');
          location.reload();
        });
      });
    },

    // Statistics tab
    loadCustomChartRange: function(ajaxUrl) {
      var fromEl = document.getElementById('fps-chart-date-from');
      var toEl = document.getElementById('fps-chart-date-to');
      var from = fromEl ? fromEl.value : '';
      var to = toEl ? toEl.value : '';
      if (!from || !to) { toast('Select both start and end dates', 'warning'); return; }
      ajax('get_chart_data', {date_from: from, date_to: to}, function(err, data) {
        if (err || !data) return;
        if (typeof FpsCharts !== 'undefined' && FpsCharts.update) FpsCharts.update(data);
      });
    },
    setChartRange: function(days, ajaxUrl, btn) {
      document.querySelectorAll('.fps-chart-range-btn').forEach(function(b) { b.classList.remove('active'); });
      if (btn) btn.classList.add('active');
      ajax('get_chart_data', {days: days}, function(err, data) {
        if (err || !data) return;
        // Update charts if FpsCharts is available
        if (typeof FpsCharts !== 'undefined' && FpsCharts.update) {
          FpsCharts.update(data);
        }
      });
    },

    // Mass Scan tab
    startMassScan: function(ajaxUrl) {
      var self = this;
      var filters = {};
      var form = document.getElementById('fps-scan-filters');
      if (form) form.querySelectorAll('input, select').forEach(function(el) { if (el.name) filters[el.name] = el.value; });

      // Reset state
      window._fpsScanCancelled = false;
      window._fpsScanResults = [];
      window._fpsScanOffset = 0;
      window._fpsScanFlagged = 0;
      window._fpsScanBlocked = 0;

      // Show progress card, hide results card
      var progressCard = document.getElementById('fps-scan-progress-card');
      var resultsCard = document.getElementById('fps-scan-results-card');
      var startBtn = document.getElementById('fps-scan-start-btn');
      var cancelBtn = document.getElementById('fps-scan-cancel-btn');
      if (progressCard) progressCard.style.display = '';
      if (resultsCard) resultsCard.style.display = 'none';
      if (startBtn) startBtn.disabled = true;
      if (cancelBtn) cancelBtn.style.display = '';

      // Update status text
      var statusEl = document.getElementById('fps-scan-status-text');
      if (statusEl) statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting scan...';

      toast('Scanning clients...', 'info');

      // Run batch loop
      function runBatch(offset) {
        if (window._fpsScanCancelled) {
          finishScan('Scan cancelled');
          return;
        }

        filters.offset = offset;
        filters.batch_size = 25;

        ajax('start_mass_scan', filters, function(err, r) {
          if (err || !r || !r.success) {
            finishScan('Scan error: ' + (r ? r.error || 'Unknown' : 'Connection failed'));
            return;
          }

          var total = r.total_clients || 0;
          var processed = r.processed || 0;
          var allProcessed = offset + processed;

          // Accumulate results
          if (r.results) {
            r.results.forEach(function(res) {
              window._fpsScanResults.push(res);
              if (res.success && res.data) {
                var score = res.data.risk_score || res.data.score || 0;
                if (score >= 70) window._fpsScanBlocked++;
                else if (score >= 40) window._fpsScanFlagged++;
              }
            });
          }

          // Update progress bar
          var pct = total > 0 ? Math.round((allProcessed / total) * 100) : 100;
          var bar = document.getElementById('fps-scan-progress-bar');
          var pctEl = document.getElementById('fps-scan-progress-pct');
          if (bar) bar.style.width = pct + '%';
          if (pctEl) pctEl.textContent = pct + '%';
          if (statusEl) statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanned ' + allProcessed + ' of ' + total + ' clients...';

          // Update mini stats
          var scannedEl = document.getElementById('fps-scan-scanned');
          var flaggedEl = document.getElementById('fps-scan-flagged');
          var blockedEl = document.getElementById('fps-scan-blocked');
          if (scannedEl) scannedEl.textContent = allProcessed;
          if (flaggedEl) flaggedEl.textContent = window._fpsScanFlagged;
          if (blockedEl) blockedEl.textContent = window._fpsScanBlocked;

          // More batches needed?
          if (processed > 0 && allProcessed < total) {
            setTimeout(function() { runBatch(allProcessed); }, 200);
          } else {
            finishScan('Scan complete: ' + allProcessed + ' clients processed');
          }
        });
      }

      function finishScan(message) {
        if (statusEl) statusEl.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
        if (startBtn) startBtn.disabled = false;
        if (cancelBtn) cancelBtn.style.display = 'none';
        toast(message, 'success');

        // Update result summary counters
        var totalEl      = document.getElementById('fps-scan-total-result');
        var flaggedResEl = document.getElementById('fps-scan-flagged-result');
        var blockedResEl = document.getElementById('fps-scan-blocked-result');
        if (totalEl)      totalEl.textContent      = window._fpsScanResults.length;
        if (flaggedResEl) flaggedResEl.textContent  = window._fpsScanFlagged;
        if (blockedResEl) blockedResEl.textContent  = window._fpsScanBlocked;

        // ---- local helpers ----

        // Risk level -> badge CSS class
        function levelBadge(lvl) {
          return { critical: 'fps-badge-critical', high: 'fps-badge-high',
                   medium: 'fps-badge-medium', low: 'fps-badge-low' }[lvl] || 'fps-badge-low';
        }
        // Capitalise first letter
        function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

        // Action taken -> badge HTML (icon + label + colour)
        function actionBadge(action) {
          var a = (action || '').toLowerCase().replace(/[^a-z]/g, '');
          var map = {
            block:     ['fps-badge-blocked',  'fa-ban',              'Blocked'],
            lock:      ['fps-badge-blocked',  'fa-lock',             'Locked'],
            flag:      ['fps-badge-pending',  'fa-flag',             'Flagged'],
            allow:     ['fps-badge-approved', 'fa-check-circle',     'Allowed'],
            review:    ['fps-badge-pending',  'fa-search',           'Review'],
            terminate: ['fps-badge-critical', 'fa-skull-crossbones', 'Terminated'],
          };
          var m = map[a] || ['fps-scan-badge-none', 'fa-minus-circle', action ? ucFirst(action) : 'None'];
          return '<span class="fps-badge ' + m[0] + '"><i class="fas ' + m[1] + '"></i> ' + m[2] + '</span>';
        }

        // Risk score -> mini progress bar + coloured number
        function scoreCell(score) {
          var pct  = Math.min(100, Math.max(0, score));
          var barCls  = score >= 70 ? 'fps-progress-bar--danger'
                      : score >= 40 ? 'fps-progress-bar--warning'
                      : 'fps-progress-bar--success';
          var numCls  = score >= 70 ? 'fps-text-danger'
                      : score >= 40 ? 'fps-text-warning'
                      : 'fps-text-success';
          return '<div class="fps-scan-score-cell">'
            + '<span class="fps-scan-score-num ' + numCls + '">' + score + '</span>'
            + '<div class="fps-scan-score-bar">'
            + '<div class="fps-scan-score-fill ' + barCls + '" style="width:' + pct + '%"></div>'
            + '</div>'
            + '</div>';
        }

        // Format milliseconds to human-readable
        function fmtMs(ms) {
          if (!ms || ms < 0) return '<span class="fps-text-muted">-</span>';
          return '<span class="fps-mono fps-text-muted">' + (ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : ms + 'ms') + '</span>';
        }

        // 2-letter ISO code -> flag emoji (Unicode regional indicator trick)
        function countryFlag(code) {
          if (!code || code.length !== 2) return '';
          try {
            return String.fromCodePoint(
              0x1F1E6 + code.toUpperCase().charCodeAt(0) - 65,
              0x1F1E6 + code.toUpperCase().charCodeAt(1) - 65
            ) + '\u00A0';
          } catch (e) { return ''; }
        }

        // Provider scores object -> tooltip string
        function providerTip(scores) {
          if (!scores || typeof scores !== 'object') return '';
          return Object.keys(scores).map(function(k) {
            return k + ': ' + Math.round(scores[k]);
          }).join(' | ');
        }

        // ---- build table ----
        var tableEl = document.getElementById('fps-scan-results-table');
        if (!tableEl) { return; }

        if (!window._fpsScanResults.length) {
          tableEl.innerHTML = '<p class="fps-text-muted"><i class="fas fa-info-circle"></i> No clients scanned.</p>';
          document.getElementById('fps-scan-results-card').style.display = '';
          return;
        }

        var html = '<div class="fps-table-wrapper fps-scan-table-wrap">'
          + '<table class="fps-table fps-scan-results-table">'
          + '<thead><tr>'
          + '<th class="fps-col-check"><input type="checkbox" onclick="FpsAdmin.toggleSelectAll(\'fps-scan-row-check\')" title="Select all"></th>'
          + '<th class="fps-col-client">Client</th>'
          + '<th class="fps-col-ip">IP&nbsp;/ Country</th>'
          + '<th class="fps-col-score">Risk Score</th>'
          + '<th class="fps-col-level">Level</th>'
          + '<th class="fps-col-action">Action Taken</th>'
          + '<th class="fps-col-providers" title="Number of detection providers queried">Providers</th>'
          + '<th class="fps-col-time" title="Total scan duration">Scan Time</th>'
          + '<th class="fps-col-actions">Actions</th>'
          + '</tr></thead>'
          + '<tbody>';

        window._fpsScanResults.forEach(function(res) {
          var d         = (res.success && res.data) ? res.data : {};
          var score     = Math.round(d.risk_score || 0);
          var level     = d.risk_level || (score >= 70 ? 'critical' : score >= 40 ? 'medium' : 'low');
          var action    = d.action_taken || '';
          var providers = d.providers_checked || 0;
          var execMs    = d.execution_ms || 0;
          var email     = d.email || '';
          var ip        = d.ip || '';
          var name      = d.name || '';
          var country   = d.country || '';
          var checkId   = d.check_id || '';
          var locked    = d.locked || false;
          var provScores = d.provider_scores || {};

          var clientHref = 'clientssummary.php?userid=' + res.client_id;
          var profileHref = 'addonmodules.php?module=fraud_prevention_suite&tab=client_profile&client_id=' + res.client_id;

          // Client cell: name (link) + ID + email stacked
          var displayName = name || ('#' + res.client_id);
          var clientCell  = '<div class="fps-scan-client-cell">'
            + '<a href="' + clientHref + '" class="fps-scan-client-name" target="_blank">' + _esc(displayName) + '</a>'
            + (name ? '<span class="fps-scan-client-id">&nbsp;#' + res.client_id + '</span>' : '')
            + (email ? '<span class="fps-scan-client-email"><i class="fas fa-envelope" style="opacity:0.45;font-size:0.7em;margin-right:3px;"></i>' + _esc(email) + '</span>' : '')
            + '</div>';
          var lockIcon = locked ? ' <i class="fas fa-lock fps-text-danger fps-scan-lock-icon" title="Account locked"></i>' : '';

          // IP + country cell
          var flag    = countryFlag(country);
          var ipCell  = ip
            ? '<span class="fps-mono fps-scan-ip">' + _esc(ip) + '</span>'
              + (country ? '<br><small class="fps-scan-country fps-text-muted">' + flag + _esc(country) + '</small>' : '')
            : '<span class="fps-text-muted">—</span>';

          html += '<tr class="fps-scan-result-row' + (locked ? ' fps-scan-row-locked' : '') + '" data-client-id="' + res.client_id + '">';
          html += '<td class="fps-col-check"><input type="checkbox" class="fps-scan-row-check" value="' + res.client_id + '"></td>';
          html += '<td class="fps-col-client">' + clientCell + lockIcon + '</td>';
          html += '<td class="fps-col-ip">' + ipCell + '</td>';

          if (res.success) {
            var tip = providerTip(provScores);
            html += '<td class="fps-col-score">' + scoreCell(score) + '</td>';
            html += '<td class="fps-col-level"><span class="fps-badge ' + levelBadge(level) + '">' + ucFirst(level) + '</span></td>';
            html += '<td class="fps-col-action">' + actionBadge(action) + '</td>';
            html += '<td class="fps-col-providers fps-mono" title="' + _esc(tip) + '">'
              + '<span class="fps-scan-provider-count">' + providers + '</span>'
              + (tip ? '<i class="fas fa-info-circle fps-text-muted fps-scan-tip-icon" title="' + _esc(tip) + '"></i>' : '')
              + '</td>';
            html += '<td class="fps-col-time">' + fmtMs(execMs) + '</td>';
            html += '<td class="fps-col-actions fps-scan-actions-cell">'
              + '<a href="' + profileHref + '" class="fps-btn fps-btn-xs fps-btn-primary" title="FPS client profile">'
              + '<i class="fas fa-shield-alt"></i> Profile</a>'
              + '</td>';
          } else {
            html += '<td colspan="6">'
              + '<span class="fps-badge fps-badge-critical"><i class="fas fa-exclamation-triangle"></i> '
              + _esc(res.error || 'Scan error') + '</span>'
              + '</td>';
          }

          html += '</tr>';
        });

        html += '</tbody></table></div>';
        tableEl.innerHTML = html;

        // Show results card
        var resultsCard = document.getElementById('fps-scan-results-card');
        if (resultsCard) resultsCard.style.display = '';
      }

      // Start first batch
      runBatch(0);
    },
    cancelMassScan: function() {
      window._fpsScanCancelled = true;
      var cancelBtn = document.getElementById('fps-scan-cancel-btn');
      if (cancelBtn) cancelBtn.style.display = 'none';
      toast('Scan cancelled by user', 'warning');
    },
    scanBulkAction: function(action, ajaxUrl) {
      var ids = getSelected('fps-scan-row-check');
      if (!ids.length) { toast('No items selected', 'warning'); return; }
      ajax('bulk_' + action, {client_ids: ids.join(',')}, function(err, data) {
        if (err || (data && data.error)) { toast(data ? data.error : 'Bulk action failed', 'error'); return; }
        toast('Bulk ' + action + ' complete (' + (data.processed || 0) + ' clients)', 'success'); location.reload();
      });
    },
    scanExportCsv: function(ajaxUrl) {
      window.location = ajaxUrl + '&a=scan_export_csv';
    },
    toggleSelectAll: function(className) { selectAll(className); },

    // Client Profile tab
    loadClientProfile: function(modulelink) {
      var clientId = document.getElementById('fps-profile-client-input');
      if (clientId && clientId.value) { window.location = modulelink + '&tab=client_profile&client_id=' + encodeURIComponent(clientId.value); }
    },
    reportClientToFraudRecord: function(clientId, ajaxUrl) {
      confirm('Report client #' + clientId + ' to FraudRecord?', function() {
        ajax('report_client_fraudrecord', {client_id: clientId}, function() { toast('Reported to FraudRecord', 'success'); });
      });
    },
    terminateClient: function(clientId, ajaxUrl) {
      confirm('Terminate all services for client #' + clientId + '? This cannot be undone!', function() {
        ajax('terminate_client', {client_id: clientId}, function() { toast('Client terminated', 'success'); location.reload(); });
      });
    },
    exportClientProfile: function(clientId, ajaxUrl) {
      window.location = ajaxUrl + '&a=export_client_profile&client_id=' + clientId;
    },

    // Topology tab
    setTopologyRange: function(range, ajaxUrl, btn) {
      if (typeof FpsTopology !== 'undefined' && FpsTopology.setRange) FpsTopology.setRange(range, ajaxUrl);
      document.querySelectorAll('.fps-topo-range-btn').forEach(function(b) { b.classList.remove('active'); });
      if (btn) btn.classList.add('active');
    },
    toggleTopologyAutoRefresh: function(enabled) {
      if (typeof FpsTopology !== 'undefined') { enabled ? FpsTopology.startAutoRefresh(60000) : FpsTopology.stopAutoRefresh(); }
    },
    toggleTopologyFullscreen: function() {
      var el = document.getElementById('fps-admin-globe');
      if (el) { if (!document.fullscreenElement) el.requestFullscreen(); else document.exitFullscreen(); }
    },
  };

  /* ------------------------------------------------------------------
     FpsBot -- Bot Detection & Cleanup Controller
  ------------------------------------------------------------------ */

  var _botState = {
    suspects: [],
    selected: new Set(),
    page: 1,
    perPage: 50,
  };

  window.FpsBot = {
    /**
     * Run bot detection scan.
     */
    scan: function() {
      var statusEl = document.getElementById('fps-bot-status');
      var status = statusEl ? statusEl.value : '';
      var btn = document.getElementById('fps-bot-scan-btn');
      var scanStatus = document.getElementById('fps-bot-scan-status');
      var progressBar = document.getElementById('fps-bot-progress');
      var scanText = document.getElementById('fps-bot-scan-text');

      if (btn) btn.disabled = true;
      if (scanStatus) scanStatus.style.display = 'block';
      if (progressBar) progressBar.style.width = '30%';
      if (scanText) scanText.textContent = 'Scanning clients...';

      ajax('detect_bots', {status: status}, function(err, data) {
        if (err) { toast('Scan failed: ' + err.message, 'error'); if (btn) btn.disabled = false; return; }
        if (btn) btn.disabled = false;
        if (progressBar) progressBar.style.width = '100%';

        if (data.error) {
          toast(data.error, 'error');
          if (scanText) scanText.textContent = 'Scan failed';
          return;
        }

        _botState.suspects = data.suspects || [];
        _botState.selected = new Set();
        _botState.page = 1;

        if (scanText) scanText.textContent = 'Scan complete!';
        toast(data.total + ' suspected bot accounts detected out of ' + data.scanned + ' scanned (' + data.real_count + ' real clients)', 'info');

        // Show summary badges
        var summaryEl = document.getElementById('fps-bot-summary');
        if (summaryEl) {
          summaryEl.innerHTML =
            '<span class="fps-badge fps-badge-critical">' + data.total + ' bots</span>' +
            '<span class="fps-badge fps-badge-low">' + data.real_count + ' real</span>' +
            '<span class="fps-badge" style="background:#6495ed;">' + data.scanned + ' scanned</span>';
        }

        // Show results card and render
        var resultsCard = document.getElementById('fps-bot-results-card');
        if (resultsCard) resultsCard.style.display = 'block';

        FpsBot._renderPage();

        // Hide scan status after a moment
        setTimeout(function() {
          if (scanStatus) scanStatus.style.display = 'none';
        }, 2000);
      });
    },

    /**
     * Render the current page of bot results.
     */
    _renderPage: function() {
      var tbody = document.getElementById('fps-bot-tbody');
      if (!tbody) return;

      var start = (_botState.page - 1) * _botState.perPage;
      var end = Math.min(start + _botState.perPage, _botState.suspects.length);
      var pageData = _botState.suspects.slice(start, end);

      var html = '';
      for (var i = 0; i < pageData.length; i++) {
        var s = pageData[i];
        var checked = _botState.selected.has(s.id) ? ' checked' : '';
        var statusClass = s.status === 'Active' ? 'fps-badge-high' :
                          s.status === 'Inactive' ? 'fps-badge-medium' : '';
        html += '<tr>' +
          '<td><input type="checkbox" class="fps-bot-check" data-id="' + s.id + '"' + checked + ' onchange="FpsBot._toggleSelect(' + s.id + ', this.checked)"></td>' +
          '<td>' + s.id + '</td>' +
          '<td style="font-size:0.85rem;">' + _esc(s.email) + '</td>' +
          '<td>' + _esc(s.name) + '</td>' +
          '<td><span class="fps-badge ' + statusClass + '">' + _esc(s.status) + '</span></td>' +
          '<td style="font-size:0.85rem;">' + _esc(s.created) + '</td>' +
          '<td>' + s.orders + '</td>' +
          '<td>' + s.invoices + '</td>' +
          '<td>' + s.hosting + '</td>' +
          '<td style="font-size:0.8rem;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + _esc(s.evidence) + '">' + _esc(s.evidence) + '</td>' +
          '</tr>';
      }
      tbody.innerHTML = html;

      // Update pagination
      var pageInfo = document.getElementById('fps-bot-page-info');
      var totalPages = Math.max(1, Math.ceil(_botState.suspects.length / _botState.perPage));
      if (pageInfo) pageInfo.textContent = 'Page ' + _botState.page + ' of ' + totalPages + ' (' + _botState.suspects.length + ' total)';

      var prevBtn = document.getElementById('fps-bot-prev');
      var nextBtn = document.getElementById('fps-bot-next');
      if (prevBtn) prevBtn.disabled = (_botState.page <= 1);
      if (nextBtn) nextBtn.disabled = (_botState.page >= totalPages);

      FpsBot._updateSelectedCount();
    },

    _toggleSelect: function(id, checked) {
      if (checked) _botState.selected.add(id);
      else _botState.selected.delete(id);
      FpsBot._updateSelectedCount();
    },

    _updateSelectedCount: function() {
      var el = document.getElementById('fps-bot-selected-count');
      if (el) el.textContent = _botState.selected.size + ' accounts selected';
    },

    selectAll: function() {
      _botState.suspects.forEach(function(s) { _botState.selected.add(s.id); });
      FpsBot._renderPage();
    },

    deselectAll: function() {
      _botState.selected = new Set();
      FpsBot._renderPage();
    },

    toggleAll: function(checked) {
      if (checked) FpsBot.selectAll();
      else FpsBot.deselectAll();
    },

    prevPage: function() {
      if (_botState.page > 1) { _botState.page--; FpsBot._renderPage(); }
    },

    nextPage: function() {
      var totalPages = Math.ceil(_botState.suspects.length / _botState.perPage);
      if (_botState.page < totalPages) { _botState.page++; FpsBot._renderPage(); }
    },

    /**
     * Get selected IDs, or all suspect IDs if none selected.
     */
    _getIds: function() {
      if (_botState.selected.size > 0) return Array.from(_botState.selected);
      // If none selected, prompt to select
      return [];
    },

    /**
     * Preview (dry-run) a bot action.
     */
    preview: function(action) {
      var ids = FpsBot._getIds();
      if (ids.length === 0) {
        toast('Select accounts first (or use Select All)', 'warning');
        return;
      }

      var titleMap = {
        flag: 'Preview: Flag Accounts',
        deactivate: 'Preview: Deactivate Accounts',
        purge: 'Preview: Standard Purge',
        deep_purge: 'Preview: Deep Purge'
      };

      var titleEl = document.getElementById('fps-bot-preview-title');
      if (titleEl) titleEl.innerHTML = '<i class="fas fa-eye"></i> ' + (titleMap[action] || 'Preview');

      ajax('preview_bot_action', {preview_action: action, ids: ids.join(',')}, function(err, data) {
        if (err) { toast('Preview failed: ' + err.message, 'error'); return; }
        if (data.error) {
          toast(data.error, 'error');
          return;
        }

        // Show summary
        var summaryEl = document.getElementById('fps-bot-preview-summary');
        if (summaryEl) {
          summaryEl.innerHTML = '<strong>' + _esc(data.summary) + '</strong>' +
            ' (' + data.count + ' of ' + data.total + ' accounts affected)';
        }

        // Build table
        var theadEl = document.getElementById('fps-bot-preview-thead');
        var tbodyEl = document.getElementById('fps-bot-preview-tbody');

        if (action === 'deep_purge') {
          if (theadEl) theadEl.innerHTML = '<tr><th>ID</th><th>Email</th><th>Orders</th><th>Invoices</th><th>Hosting</th><th>Eligible</th><th>Impact</th></tr>';
          var rows = '';
          (data.details || []).forEach(function(d) {
            var badge = d.can_deep_purge
              ? '<span class="fps-badge fps-badge-critical">Yes</span>'
              : '<span class="fps-badge fps-badge-low">No</span>';
            rows += '<tr>' +
              '<td>' + d.id + '</td>' +
              '<td style="font-size:0.85rem;">' + _esc(d.email) + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.order_statuses) + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.invoice_statuses) + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.hosting_statuses) + '</td>' +
              '<td>' + badge + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.impact) + '</td>' +
              '</tr>';
          });
          if (tbodyEl) tbodyEl.innerHTML = rows;
        } else if (action === 'purge') {
          if (theadEl) theadEl.innerHTML = '<tr><th>ID</th><th>Email</th><th>Status</th><th>Orders</th><th>Invoices</th><th>Hosting</th><th>Can Purge</th><th>Impact</th></tr>';
          var rows = '';
          (data.details || []).forEach(function(d) {
            var badge = d.can_purge
              ? '<span class="fps-badge fps-badge-critical">Yes</span>'
              : '<span class="fps-badge fps-badge-low">No</span>';
            rows += '<tr>' +
              '<td>' + d.id + '</td>' +
              '<td style="font-size:0.85rem;">' + _esc(d.email) + '</td>' +
              '<td>' + _esc(d.status) + '</td>' +
              '<td>' + d.orders + '</td>' +
              '<td>' + d.invoices + '</td>' +
              '<td>' + d.hosting + '</td>' +
              '<td>' + badge + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.impact) + '</td>' +
              '</tr>';
          });
          if (tbodyEl) tbodyEl.innerHTML = rows;
        } else {
          // Flag or deactivate
          if (theadEl) theadEl.innerHTML = '<tr><th>ID</th><th>Email</th><th>Name</th><th>Status</th><th>Impact</th></tr>';
          var rows = '';
          (data.details || []).forEach(function(d) {
            rows += '<tr>' +
              '<td>' + d.id + '</td>' +
              '<td style="font-size:0.85rem;">' + _esc(d.email) + '</td>' +
              '<td>' + _esc(d.name || '') + '</td>' +
              '<td>' + _esc(d.status || '') + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(d.impact) + '</td>' +
              '</tr>';
          });
          if (tbodyEl) tbodyEl.innerHTML = rows;
        }

        // Show modal
        var overlay = document.getElementById('fps-bot-preview-overlay');
        if (overlay) overlay.style.display = 'flex';
      });
    },

    closePreview: function() {
      var overlay = document.getElementById('fps-bot-preview-overlay');
      if (overlay) overlay.style.display = 'none';
    },

    /**
     * Execute a bot action with confirmation.
     */
    execute: function(action) {
      var ids = FpsBot._getIds();
      if (ids.length === 0) {
        toast('Select accounts first', 'warning');
        return;
      }

      var actionMap = {
        flag: 'flag_bots',
        deactivate: 'deactivate_bots',
        purge: 'purge_bots',
        deep_purge: 'deep_purge_bots',
      };

      var labelMap = {
        flag: 'Flag ' + ids.length + ' accounts as bots?',
        deactivate: 'Deactivate ' + ids.length + ' bot accounts? This will set them to Inactive.',
        purge: 'PERMANENTLY DELETE ' + ids.length + ' accounts with zero records? This cannot be undone!',
        deep_purge: 'PERMANENTLY DELETE ' + ids.length + ' accounts including their Fraud/Cancelled records? This CANNOT be undone!',
      };

      var ajaxAction = actionMap[action];
      if (!ajaxAction) { toast('Unknown action', 'error'); return; }

      confirm(labelMap[action] || ('Confirm ' + action + '?'), function() {
        ajax(ajaxAction, {ids: ids.join(',')}, function(err, data) {
          if (err) { toast('Action failed: ' + err.message, 'error'); return; }
          if (data.error) {
            toast(data.error, 'error');
            return;
          }

          var msg = '';
          if (data.flagged !== undefined) msg = data.flagged + ' accounts flagged';
          else if (data.deactivated !== undefined) msg = data.deactivated + ' accounts deactivated';
          else if (data.purged !== undefined) msg = data.purged + ' accounts purged' + (data.skipped ? ' (' + data.skipped + ' skipped)' : '');
          else msg = 'Action completed';

          toast(msg, 'success');

          // Re-scan to refresh the list
          setTimeout(function() { FpsBot.scan(); }, 1500);
        });
      });
    },
  };

  /* ------------------------------------------------------------------
     FpsGlobal -- Global Intelligence Controller
  ------------------------------------------------------------------ */

  var _globalPage = 1;

  window.FpsGlobal = {
    register: function() {
      confirm('Register this instance with the FPS Hub? The hub will verify your domain.', function() {
        ajax('global_intel_register', {}, function(err, data) {
          if (err) { toast('Registration failed: ' + err.message, 'error'); return; }
          if (data.error) { toast(data.error, 'error'); return; }
          toast('Registered! Domain: ' + (data.domain || ''), 'success');
          setTimeout(function() { location.reload(); }, 1500);
        });
      });
    },

    toggle: function() {
      ajax('global_intel_toggle', {}, function(err, data) {
        if (err) { toast('Toggle failed: ' + err.message, 'error'); return; }
        if (data.error) { toast(data.error, 'error'); return; }
        toast(data.message || 'Toggled', data.enabled ? 'success' : 'info');
        setTimeout(function() { location.reload(); }, 1000);
      });
    },

    pushNow: function() {
      ajax('global_intel_push_now', {}, function(err, data) {
        if (err) { toast('Push failed: ' + err.message, 'error'); return; }
        if (data.error) { toast(data.error, 'error'); return; }
        toast('Pushed ' + (data.pushed || 0) + ' records to hub', 'success');
      });
    },

    refreshStats: function() {
      ajax('global_intel_stats', {}, function(err, data) {
        if (err) return;
        if (data.error) return;

        var el = document.getElementById('fps-global-stats-body');
        if (!el) return;

        var local = data.local || {};
        var hub = data.hub || {};

        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">';

        // Local stats cards
        html += '<div style="padding:1rem;border-radius:8px;background:rgba(56,239,125,0.08);border:1px solid rgba(56,239,125,0.2);text-align:center;">';
        html += '<div style="font-size:2rem;font-weight:700;color:#38ef7d;">' + (local.total || 0) + '</div>';
        html += '<div style="font-size:0.85rem;opacity:0.7;">Local Intel Records</div></div>';

        html += '<div style="padding:1rem;border-radius:8px;background:rgba(100,149,237,0.08);border:1px solid rgba(100,149,237,0.2);text-align:center;">';
        html += '<div style="font-size:2rem;font-weight:700;color:#6495ed;">' + (local.unpushed || 0) + '</div>';
        html += '<div style="font-size:0.85rem;opacity:0.7;">Unpushed to Hub</div></div>';

        html += '<div style="padding:1rem;border-radius:8px;background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);text-align:center;">';
        html += '<div style="font-size:2rem;font-weight:700;color:#f5c842;">' + (local.total_seen || 0) + '</div>';
        html += '<div style="font-size:0.85rem;opacity:0.7;">Total Sightings</div></div>';

        if (hub.total_records !== undefined) {
          html += '<div style="padding:1rem;border-radius:8px;background:rgba(235,51,73,0.08);border:1px solid rgba(235,51,73,0.2);text-align:center;">';
          html += '<div style="font-size:2rem;font-weight:700;color:#eb3349;">' + (hub.total_records || 0) + '</div>';
          html += '<div style="font-size:0.85rem;opacity:0.7;">Hub Total Records</div></div>';
        }

        html += '</div>';

        // Risk level breakdown
        if (local.by_level) {
          html += '<div style="margin-top:1rem;"><strong>Risk Level Breakdown:</strong> ';
          var levels = ['critical', 'high', 'medium', 'low'];
          var colors = {critical: '#eb3349', high: '#f5576c', medium: '#f5c842', low: '#38ef7d'};
          for (var i = 0; i < levels.length; i++) {
            var lv = levels[i];
            var cnt = local.by_level[lv] || 0;
            if (cnt > 0) {
              html += '<span class="fps-badge" style="background:' + colors[lv] + ';margin-right:0.25rem;">' + lv.toUpperCase() + ': ' + cnt + '</span>';
            }
          }
          html += '</div>';
        }

        // Top countries
        if (local.top_countries && Object.keys(local.top_countries).length > 0) {
          html += '<div style="margin-top:0.75rem;"><strong>Top Countries:</strong> ';
          var countries = Object.entries(local.top_countries);
          for (var c = 0; c < Math.min(countries.length, 10); c++) {
            html += '<span class="fps-badge fps-badge-outline" style="margin-right:0.25rem;">' + countries[c][0] + ': ' + countries[c][1] + '</span>';
          }
          html += '</div>';
        }

        el.innerHTML = html;
      });
    },

    saveSettings: function() {
      var hubUrl = document.getElementById('fps-global-hub-url');
      var retention = document.getElementById('fps-global-retention');
      var shareIp = document.getElementById('fps-global-share-ip');
      var autoPush = document.getElementById('fps-global-auto-push');
      var autoPull = document.getElementById('fps-global-auto-pull');

      ajax('global_intel_save_settings', {
        hub_url: hubUrl ? hubUrl.value : '',
        retention: retention ? retention.value : '365',
        share_ip: shareIp && shareIp.checked ? '1' : '0',
        auto_push: autoPush && autoPush.checked ? '1' : '0',
        auto_pull: autoPull && autoPull.checked ? '1' : '0',
      }, function(err, data) {
        if (err) { toast('Save failed: ' + err.message, 'error'); return; }
        if (data.error) { toast(data.error, 'error'); return; }
        toast('Settings saved', 'success');
      });
    },

    browse: function(page) {
      _globalPage = page || 1;
      var search = document.getElementById('fps-global-search');
      var level = document.getElementById('fps-global-level-filter');
      var source = document.getElementById('fps-global-source-filter');

      ajax('global_intel_browse', {
        page: _globalPage,
        search: search ? search.value : '',
        risk_level: level ? level.value : '',
        source: source ? source.value : '',
      }, function(err, data) {
        if (err) { toast('Browse failed', 'error'); return; }
        if (data.error) { toast(data.error, 'error'); return; }

        var tbody = document.getElementById('fps-global-intel-tbody');
        if (!tbody) return;

        var records = data.records || [];
        if (records.length === 0) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;opacity:0.5;">No records found</td></tr>';
        } else {
          var html = '';
          for (var i = 0; i < records.length; i++) {
            var r = records[i];
            var levelClass = r.risk_level === 'critical' ? 'fps-badge-critical' :
                             r.risk_level === 'high' ? 'fps-badge-high' :
                             r.risk_level === 'medium' ? 'fps-badge-medium' : 'fps-badge-low';
            var pushed = r.pushed_to_hub == 1 ? '<i class="fas fa-check" style="color:#38ef7d;"></i>' : '<i class="fas fa-clock" style="opacity:0.3;"></i>';
            html += '<tr>' +
              '<td style="font-size:0.75rem;font-family:monospace;">' + _esc((r.email_hash || '').substring(0, 16)) + '...</td>' +
              '<td style="font-size:0.85rem;">' + _esc(r.ip_address || '-') + '</td>' +
              '<td>' + _esc(r.country || '-') + '</td>' +
              '<td>' + parseFloat(r.risk_score || 0).toFixed(1) + '</td>' +
              '<td><span class="fps-badge ' + levelClass + '">' + _esc(r.risk_level || 'low') + '</span></td>' +
              '<td>' + _esc(r.source || 'local') + '</td>' +
              '<td>' + (r.seen_count || 1) + '</td>' +
              '<td style="font-size:0.8rem;">' + _esc(r.last_seen_at || '') + '</td>' +
              '<td>' + pushed + '</td>' +
              '</tr>';
          }
          tbody.innerHTML = html;
        }

        // Pagination
        var info = document.getElementById('fps-global-page-info');
        if (info) info.textContent = 'Page ' + data.page + ' of ' + data.total_pages + ' (' + data.total + ' total)';
        var prev = document.getElementById('fps-global-prev');
        var next = document.getElementById('fps-global-next');
        if (prev) prev.disabled = (_globalPage <= 1);
        if (next) next.disabled = (_globalPage >= data.total_pages);
      });
    },

    prevPage: function() { if (_globalPage > 1) FpsGlobal.browse(_globalPage - 1); },
    nextPage: function() { FpsGlobal.browse(_globalPage + 1); },

    exportAll: function() {
      window.location = _state.modulelink + '&ajax=1&a=global_intel_export';
    },

    purgeLocal: function() {
      confirm('PERMANENTLY delete ALL local intelligence records? This cannot be undone.', function() {
        ajax('global_intel_purge', {target: 'local'}, function(err, data) {
          if (err) { toast('Purge failed', 'error'); return; }
          if (data.error) { toast(data.error, 'error'); return; }
          toast('Purged ' + (data.local_deleted || 0) + ' local records', 'success');
          FpsGlobal.refreshStats();
          FpsGlobal.browse(1);
        });
      });
    },

    purgeHub: function() {
      confirm('Remove ALL this instance\'s contributions from the hub? This is a GDPR erasure request.', function() {
        ajax('global_intel_purge', {target: 'hub'}, function(err, data) {
          if (err) { toast('Hub purge failed', 'error'); return; }
          if (data.error) { toast(data.error, 'error'); return; }
          toast('Hub contributions purged', 'success');
        });
      });
    },
  };

  /* ------------------------------------------------------------------
     FpsBotUsers -- Orphan User Account Cleanup (WHMCS 8.x tblusers)
  ------------------------------------------------------------------ */

  var _userState = { users: [], selected: new Set() };

  window.FpsBotUsers = {
    scan: function() {
      var btn = document.getElementById('fps-user-scan-btn');
      if (btn) btn.disabled = true;
      ajax('detect_orphan_users', {}, function(err, data) {
        if (btn) btn.disabled = false;
        if (err) { toast('User scan failed: ' + err.message, 'error'); return; }
        if (data.error) { toast(data.error, 'error'); return; }

        _userState.users = data.users || [];
        _userState.selected = new Set();

        var results = document.getElementById('fps-user-results');
        if (results) results.style.display = 'block';

        var summary = document.getElementById('fps-user-summary');
        if (summary) {
          summary.innerHTML = '<span class="fps-badge fps-badge-critical">' + data.total + ' orphan users</span>' +
            '<span class="fps-badge" style="background:#6495ed;">' + data.total_users + ' total users</span>';
        }

        var tbody = document.getElementById('fps-user-tbody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < _userState.users.length; i++) {
          var u = _userState.users[i];
          html += '<tr>' +
            '<td><input type="checkbox" class="fps-user-check" data-id="' + u.id + '" onchange="FpsBotUsers._toggle(' + u.id + ',this.checked)"></td>' +
            '<td>' + u.id + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(u.email) + '</td>' +
            '<td>' + _esc(u.name) + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(u.last_ip) + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(u.last_login) + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(u.created) + '</td>' +
            '<td>' + u.clients + '</td>' +
            '<td style="font-size:0.8rem;">' + _esc(u.reason) + '</td>' +
            '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="9" style="text-align:center;opacity:0.5;">No orphan users found</td></tr>';
        toast(data.total + ' orphan users found out of ' + data.total_users + ' total', 'info');
      });
    },

    _toggle: function(id, checked) {
      if (checked) _userState.selected.add(id); else _userState.selected.delete(id);
    },

    selectAll: function() {
      _userState.users.forEach(function(u) { _userState.selected.add(u.id); });
      document.querySelectorAll('.fps-user-check').forEach(function(cb) { cb.checked = true; });
    },

    deselectAll: function() {
      _userState.selected = new Set();
      document.querySelectorAll('.fps-user-check').forEach(function(cb) { cb.checked = false; });
    },

    toggleAll: function(checked) {
      if (checked) FpsBotUsers.selectAll();
      else FpsBotUsers.deselectAll();
    },

    preview: function() {
      var ids = Array.from(_userState.selected);
      if (ids.length === 0) { toast('Select users first', 'warning'); return; }

      // Show preview in the bot preview modal (reuse it)
      var titleEl = document.getElementById('fps-bot-preview-title');
      if (titleEl) titleEl.innerHTML = '<i class="fas fa-eye"></i> Dry-Run: User Purge Preview';

      var summaryEl = document.getElementById('fps-bot-preview-summary');
      if (summaryEl) summaryEl.innerHTML = '<strong>Previewing purge of ' + ids.length + ' user accounts</strong>';

      var theadEl = document.getElementById('fps-bot-preview-thead');
      var tbodyEl = document.getElementById('fps-bot-preview-tbody');
      if (theadEl) theadEl.innerHTML = '<tr><th>User ID</th><th>Email</th><th>Name</th><th>Last IP</th><th>Clients</th><th>Impact</th></tr>';

      var rows = '';
      ids.forEach(function(uid) {
        var u = _userState.users.find(function(x) { return x.id === uid; });
        if (!u) return;
        rows += '<tr>' +
          '<td>' + u.id + '</td>' +
          '<td style="font-size:0.85rem;">' + _esc(u.email) + '</td>' +
          '<td>' + _esc(u.name) + '</td>' +
          '<td style="font-size:0.85rem;">' + _esc(u.last_ip) + '</td>' +
          '<td>' + u.clients + '</td>' +
          '<td style="font-size:0.8rem;">Login account will be permanently deleted. Fraud intel (email hash + IP) saved to global database.</td>' +
          '</tr>';
      });
      if (tbodyEl) tbodyEl.innerHTML = rows;

      var overlay = document.getElementById('fps-bot-preview-overlay');
      if (overlay) overlay.style.display = 'flex';
    },

    purge: function() {
      var ids = Array.from(_userState.selected);
      if (ids.length === 0) { toast('Select users first', 'warning'); return; }
      confirm('PERMANENTLY DELETE ' + ids.length + ' user login accounts? They will not be able to log in again.', function() {
        ajax('purge_orphan_users', {ids: ids.join(',')}, function(err, data) {
          if (err) { toast('Purge failed: ' + err.message, 'error'); return; }
          if (data.error) { toast(data.error, 'error'); return; }
          toast(data.purged + ' user accounts purged', 'success');
          setTimeout(function() { FpsBotUsers.scan(); }, 1500);
        });
      });
    },
  };

  /* ------------------------------------------------------------------
     FpsGdpr -- GDPR Data Removal Request Admin Controller
  ------------------------------------------------------------------ */

  window.FpsGdpr = {
    loadRequests: function(page) {
      page = page || 1;
      var statusFilter = document.getElementById('fps-gdpr-status-filter');
      var status = statusFilter ? statusFilter.value : '';

      ajax('gdpr_get_requests', {page: page, status: status}, function(err, data) {
        if (err || !data || data.error) { toast(data ? data.error : 'Failed to load', 'error'); return; }

        var tbody = document.getElementById('fps-gdpr-tbody');
        if (!tbody) return;

        var requests = data.requests || [];
        if (requests.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;opacity:0.5;">No requests found</td></tr>';
          return;
        }

        var html = '';
        for (var i = 0; i < requests.length; i++) {
          var r = requests[i];
          var statusBadge = r.status === 'verified' ? '<span class="fps-badge fps-badge-high">Verified</span>' :
                           r.status === 'completed' ? '<span class="fps-badge fps-badge-low">Completed</span>' :
                           r.status === 'denied' ? '<span class="fps-badge fps-badge-critical">Denied</span>' :
                           r.status === 'pending' ? '<span class="fps-badge fps-badge-medium">Pending</span>' :
                           '<span class="fps-badge">' + _esc(r.status) + '</span>';
          var verified = r.email_verified == 1 ? '<i class="fas fa-check-circle" style="color:#38ef7d;"></i>' : '<i class="fas fa-clock" style="opacity:0.3;"></i>';

          var actions = '';
          if (r.status === 'verified' || r.status === 'pending') {
            actions = '<button class="fps-btn fps-btn-xs" style="background:#38ef7d;color:#000;" onclick="FpsGdpr.review(' + r.id + ',\'approve\')"><i class="fas fa-check"></i> Approve</button> ' +
                      '<button class="fps-btn fps-btn-xs" style="background:#eb3349;" onclick="FpsGdpr.review(' + r.id + ',\'deny\')"><i class="fas fa-times"></i> Deny</button>';
          } else if (r.status === 'completed') {
            actions = '<span style="font-size:0.8rem;opacity:0.6;">' + (r.records_purged || 0) + ' purged</span>';
          } else {
            actions = '<span style="font-size:0.8rem;opacity:0.6;">' + _esc(r.status) + '</span>';
          }

          html += '<tr>' +
            '<td>#' + r.id + '</td>' +
            '<td style="font-size:0.85rem;">' + _esc(r.email) + '</td>' +
            '<td>' + _esc(r.name || '-') + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td>' + verified + '</td>' +
            '<td>' + (r.intel_records || 0) + '</td>' +
            '<td style="font-size:0.8rem;">' + _esc(r.created_at || '') + '</td>' +
            '<td>' + actions + '</td>' +
            '</tr>';
        }
        tbody.innerHTML = html;
      });
    },

    review: function(requestId, action) {
      var label = action === 'approve'
        ? 'APPROVE this request and DELETE all matching fraud intel records?'
        : 'DENY this data removal request?';

      confirm(label, function() {
        var notes = prompt('Admin notes (optional):') || '';
        ajax('gdpr_review_request', {request_id: requestId, review_action: action, admin_notes: notes}, function(err, data) {
          if (err || !data) { toast('Review failed', 'error'); return; }
          if (data.error) { toast(data.error, 'error'); return; }
          toast(data.message || 'Done', 'success');
          FpsGdpr.loadRequests();
        });
      });
    },
  };

  // Initialize typography panel if on Settings page
  document.addEventListener('DOMContentLoaded', function() {
    if (window._fpsTypoInit) {
      FpsAdmin.initTypo();
    }
  });

  /* ------------------------------------------------------------------
     PRIVATE HELPER: HTML escape (shared)
  ------------------------------------------------------------------ */
  function _esc(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

}(window));

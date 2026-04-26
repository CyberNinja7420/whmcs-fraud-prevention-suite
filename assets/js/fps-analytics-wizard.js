/**
 * Fraud Prevention Suite v4.2.6 - Analytics Setup Wizard
 *
 * State machine for the 7-step wizard rendered by FpsAnalyticsWizard::render().
 * State lives entirely in the DOM (active step + form values). No globals
 * exported -- the IIFE wires itself onto DOMContentLoaded and exits silently
 * if #fps-analytics-wizard is absent.
 *
 * Pattern matches assets/js/fps-consent-banner.js: vanilla ES2018+, no deps.
 */
(function () {
  'use strict';

  var TOTAL_STEPS = 7;
  var GA4_RE = /^G-[A-Z0-9]{8,12}$/;
  var CLARITY_RE = /^[a-z0-9]{8,12}$/;

  function $(id) { return document.getElementById(id); }
  function val(id) { var el = $(id); return el ? (el.value || '').trim() : ''; }
  function checked(id) { var el = $(id); return !!(el && el.checked); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  }); }

  function showError(stepN, msg) {
    var box = $('fps-wiz-step-' + stepN + '-err');
    if (!box) return;
    box.textContent = msg;
    box.classList.add('fps-wiz-error-show');
  }
  function clearError(stepN) {
    var box = $('fps-wiz-step-' + stepN + '-err');
    if (box) { box.textContent = ''; box.classList.remove('fps-wiz-error-show'); }
  }

  function getCurrentStep(root) {
    var active = root.querySelector('.fps-wizard-step.fps-wizard-active');
    return active ? parseInt(active.getAttribute('data-step'), 10) || 1 : 1;
  }

  function setStep(root, n) {
    if (n < 1) n = 1;
    if (n > TOTAL_STEPS) n = TOTAL_STEPS;
    var steps = root.querySelectorAll('.fps-wizard-step');
    for (var i = 0; i < steps.length; i++) {
      steps[i].classList.remove('fps-wizard-active');
      if (parseInt(steps[i].getAttribute('data-step'), 10) === n) {
        steps[i].classList.add('fps-wizard-active');
      }
    }
    var counter = $('fps-wiz-step-counter');
    if (counter) counter.textContent = 'Step ' + n + ' of ' + TOTAL_STEPS;
    var backBtn = $('fps-wiz-back-btn');
    if (backBtn) backBtn.disabled = (n === 1);
    var nextBtn = $('fps-wiz-next-btn');
    if (nextBtn) nextBtn.textContent = (n === TOTAL_STEPS) ? 'Done' : 'Next';
    if (n === 7) buildSummary();
  }

  function ga4Enabled() { return checked('fps-wiz-enable-client') || checked('fps-wiz-enable-admin') || checked('fps-wiz-enable-server'); }

  function validateStep(n) {
    clearError(n);
    if (n === 1) {
      if (!checked('fps-wiz-enable-client') && !checked('fps-wiz-enable-admin') && !checked('fps-wiz-enable-server')) {
        showError(1, 'Pick at least one scope, or close the wizard.');
        return false;
      }
    } else if (n === 2) {
      var id = val('fps-wiz-ga4-id-client');
      if (ga4Enabled() && id !== '' && !GA4_RE.test(id)) {
        showError(2, 'GA4 measurement ID must match G-XXXXXXXXXX (8-12 alphanumerics after G-).');
        return false;
      }
    } else if (n === 3) {
      // Server-side credentials only required if server toggle on; otherwise skip is fine.
      // No hard validation here -- discover button does its own check.
    } else if (n === 4) {
      var cid = val('fps-wiz-clarity-id-client');
      if (cid !== '' && !CLARITY_RE.test(cid)) {
        showError(4, 'Clarity project ID must match ^[a-z0-9]{8,12}$.');
        return false;
      }
    } else if (n === 6) {
      var sr = val('fps-wiz-sampling-rate');
      if (sr !== '' && (isNaN(sr) || +sr < 1 || +sr > 100)) {
        showError(6, 'Sampling rate must be between 1 and 100.');
        return false;
      }
      var th = val('fps-wiz-high-risk-threshold');
      if (th !== '' && (isNaN(th) || +th < 0 || +th > 100)) {
        showError(6, 'High-risk threshold must be between 0 and 100.');
        return false;
      }
    }
    return true;
  }

  function buildSummary() {
    var rows = [
      ['Client tracking', checked('fps-wiz-enable-client') ? 'enabled' : 'off'],
      ['Admin tracking', checked('fps-wiz-enable-admin') ? 'enabled' : 'off'],
      ['Server events', checked('fps-wiz-enable-server') ? 'enabled' : 'off'],
      ['GA4 measurement ID', val('fps-wiz-ga4-id-client') || '(blank)'],
      ['GA4 API secret', val('fps-wiz-ga4-secret') ? '(set)' : '(blank)'],
      ['GA4 property ID', val('fps-wiz-ga4-property-id') || '(blank)'],
      ['SA JSON', val('fps-wiz-ga4-sa-json') ? '(set)' : '(blank)'],
      ['Clarity project ID', val('fps-wiz-clarity-id-client') || '(blank)'],
      ['EEA consent required', checked('fps-wiz-eea-required') ? 'yes' : 'no'],
      ['Notify email', val('fps-wiz-notify-email') || '(blank)'],
      ['Sampling rate', val('fps-wiz-sampling-rate') || '100'],
      ['High-risk threshold', val('fps-wiz-high-risk-threshold') || '80']
    ];
    var html = '<table class="fps-wiz-summary-table"><tbody>';
    for (var i = 0; i < rows.length; i++) {
      html += '<tr><th>' + esc(rows[i][0]) + '</th><td>' + esc(rows[i][1]) + '</td></tr>';
    }
    html += '</tbody></table>';
    var box = $('fps-wiz-summary');
    if (box) box.innerHTML = html;
  }

  function ajaxPost(action, params) {
    if (window.FpsAdmin && typeof window.FpsAdmin.ajax === 'function') {
      return window.FpsAdmin.ajax(action, params);
    }
    var url = (window.fpsModuleLink || (window.location.pathname + window.location.search)) + '&ajax=1&a=' + encodeURIComponent(action);
    var body = new URLSearchParams();
    body.append('action', action);
    Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
    var tokenEl = document.getElementById('fps-csrf-token') || document.querySelector('input[name="token"]');
    if (tokenEl && tokenEl.value) body.append('token', tokenEl.value);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function toast(msg, type) {
    if (window.FpsAdmin && typeof window.FpsAdmin.toast === 'function') {
      window.FpsAdmin.toast(msg, type || 'info');
    }
  }

  function discoverProperties() {
    var saJson = val('fps-wiz-ga4-sa-json');
    if (saJson === '') { showError(3, 'Paste a Service Account JSON first.'); return; }
    try { JSON.parse(saJson); } catch (e) { showError(3, 'Service Account JSON is not valid JSON.'); return; }
    clearError(3);
    var btn = $('fps-wiz-discover-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Discovering...'; }
    ajaxPost('fps_ajaxAnalyticsDiscoverProperties', { sa_json: saJson }).then(function (resp) {
      if (btn) { btn.disabled = false; btn.textContent = 'Discover properties'; }
      if (!resp || !resp.success) { showError(3, (resp && resp.error) || 'Discovery failed.'); return; }
      var props = (resp.data && resp.data.properties) || [];
      var sel = $('fps-wiz-ga4-property-select');
      if (!sel) return;
      sel.innerHTML = '<option value="">(' + props.length + ' properties found)</option>';
      for (var i = 0; i < props.length; i++) {
        var p = props[i];
        var label = (p.account_name || '?') + ' / ' + (p.display_name || '?') + ' (' + p.property_id + ')';
        var opt = document.createElement('option');
        opt.value = p.property_id;
        opt.textContent = label;
        sel.appendChild(opt);
      }
      if (props.length === 0) toast('No GA4 properties accessible by this service account.', 'warning');
      else toast('Found ' + props.length + ' GA4 properties.', 'success');
    }).catch(function (err) {
      if (btn) { btn.disabled = false; btn.textContent = 'Discover properties'; }
      showError(3, 'Discovery error: ' + (err && err.message ? err.message : 'unknown'));
    });
  }

  function saveSettings() {
    var resultBox = $('fps-wiz-save-result');
    var btn = $('fps-wiz-save-btn');
    if (btn) { btn.disabled = true; }
    var params = {
      enable_client_analytics: checked('fps-wiz-enable-client') ? '1' : '0',
      enable_admin_analytics:  checked('fps-wiz-enable-admin')  ? '1' : '0',
      enable_server_events:    checked('fps-wiz-enable-server') ? '1' : '0',
      ga4_measurement_id_client: val('fps-wiz-ga4-id-client'),
      ga4_api_secret:           val('fps-wiz-ga4-secret'),
      ga4_property_id:          val('fps-wiz-ga4-property-id'),
      ga4_service_account_json: val('fps-wiz-ga4-sa-json'),
      clarity_project_id_client: val('fps-wiz-clarity-id-client'),
      analytics_eea_consent_required: checked('fps-wiz-eea-required') ? '1' : '0',
      notification_email:       val('fps-wiz-notify-email'),
      analytics_event_sampling_rate: val('fps-wiz-sampling-rate'),
      analytics_high_risk_signup_threshold: val('fps-wiz-high-risk-threshold')
    };
    ajaxPost('fps_ajaxAnalyticsWizardSave', params).then(function (resp) {
      if (btn) btn.disabled = false;
      if (!resp || !resp.success) {
        showError(7, (resp && resp.error) || 'Save failed.');
        if (resultBox) resultBox.innerHTML = '';
        return;
      }
      clearError(7);
      if (resultBox) {
        resultBox.innerHTML = '<div class="fps-wiz-success"><i class="fas fa-check-circle"></i> Saved! Allow up to 30 seconds for events to appear in GA4 Realtime.</div>';
      }
      toast('Wizard settings saved.', 'success');
      setTimeout(function () {
        if (window.FpsAdmin && typeof window.FpsAdmin.closeModal === 'function') {
          window.FpsAdmin.closeModal('fps-analytics-wizard');
        }
      }, 4000);
    }).catch(function (err) {
      if (btn) btn.disabled = false;
      showError(7, 'Save error: ' + (err && err.message ? err.message : 'unknown'));
    });
  }

  function init() {
    var root = document.getElementById('fps-analytics-wizard');
    if (!root) return;

    setStep(root, 1);

    var nextBtn = $('fps-wiz-next-btn');
    var backBtn = $('fps-wiz-back-btn');
    var skipBtn = $('fps-wiz-skip-btn');
    if (nextBtn) nextBtn.addEventListener('click', function () {
      var cur = getCurrentStep(root);
      if (!validateStep(cur)) return;
      if (cur >= TOTAL_STEPS) return;
      setStep(root, cur + 1);
    });
    if (backBtn) backBtn.addEventListener('click', function () {
      var cur = getCurrentStep(root);
      setStep(root, cur - 1);
    });
    if (skipBtn) skipBtn.addEventListener('click', function () {
      var cur = getCurrentStep(root);
      clearError(cur);
      if (cur < TOTAL_STEPS) setStep(root, cur + 1);
    });

    var discoverBtn = $('fps-wiz-discover-btn');
    if (discoverBtn) discoverBtn.addEventListener('click', discoverProperties);

    var sel = $('fps-wiz-ga4-property-select');
    if (sel) sel.addEventListener('change', function () {
      if (sel.value) { var p = $('fps-wiz-ga4-property-id'); if (p) p.value = sel.value; }
    });

    var saveBtn = $('fps-wiz-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', saveSettings);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

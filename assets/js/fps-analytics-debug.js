/**
 * Fraud Prevention Suite v4.2.5 - Analytics Debug Overlay
 *
 * Loaded ONLY when ?fps_analytics_debug=1 is in the URL (FpsAnalyticsInjector
 * emits the script tag conditionally). Wraps gtag() and clarity() — or installs
 * no-op stubs — and logs every call to console.table + a fixed on-page panel.
 * Badge in bottom-right corner; click toggles the event log. No dependencies.
 */
(function () {
  const LOG_ID = 'fps-debug-events';
  let rows = 0;

  function logEvent(fn, args) {
    const when = new Date().toISOString();
    console.table([{ when, fn, args: JSON.stringify(args) }]);
    const log = document.getElementById(LOG_ID);
    if (!log || rows >= 200) return;
    rows++;
    const pre = document.createElement('pre');
    pre.style.cssText = 'margin:2px 0;padding:3px 6px;background:rgba(255,255,255,.07);border-radius:3px;font-size:11px;word-break:break-all';
    pre.textContent = when + '  [' + fn + ']  ' + JSON.stringify(args);
    log.appendChild(pre);
  }

  function wrap(name) {
    const isStub = typeof window[name] !== 'function';
    if (!isStub) {
      const orig = window[name];
      window[name] = function () { logEvent(name, [...arguments]); return orig.apply(this, arguments); };
    } else {
      window[name] = function () { logEvent(name + '[stub]', [...arguments]); };
      if (name === 'gtag') window.dataLayer = window.dataLayer || [];
    }
  }

  function init() {
    // Event log panel
    const log = document.createElement('div');
    log.id = LOG_ID;
    log.style.cssText = 'display:none;position:fixed;bottom:48px;right:12px;width:520px;max-width:90vw;max-height:340px;overflow-y:auto;z-index:1000001;background:rgba(10,12,18,.97);color:#a0f0a0;border:1px solid #3a82f7;border-radius:8px;padding:8px;font-family:monospace';
    document.body.appendChild(log);

    // Floating badge
    const badge = document.createElement('button');
    badge.textContent = 'FPS analytics debug active';
    badge.setAttribute('aria-label', 'Toggle FPS analytics debug log');
    badge.style.cssText = 'position:fixed;bottom:12px;right:12px;z-index:1000002;background:#3a82f7;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.4);outline-offset:3px';
    badge.addEventListener('click', () => { log.style.display = log.style.display === 'none' ? 'block' : 'none'; });
    document.body.appendChild(badge);

    wrap('gtag');
    wrap('clarity');
  }

  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();
}());

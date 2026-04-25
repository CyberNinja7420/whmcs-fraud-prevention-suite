/**
 * Fraud Prevention Suite v4.2.5 - Cookie Consent Banner
 *
 * Renders a GDPR/ePrivacy consent banner into the placeholder div emitted by
 * FpsAnalyticsInjector::client(). Handles Consent Mode v2 (all 4 signals) on
 * Accept; leaves default-deny stub in place on Decline. Single IIFE, no imports,
 * no external deps, ES2018+ (modern browsers only, per WHMCS 8.x baseline).
 *
 * Design decisions:
 *  - CSS vars pulled from host page so banner inherits the active FPS theme.
 *  - Focus trap keeps keyboard users inside the dialog until dismissed.
 *  - Esc key wired to Decline (not Accept) per WAI-ARIA dialog guidance.
 *  - sessionStorage flag prevents re-firing gtag update on every page load once
 *    consent is already stored (avoids double-grant in SPA-style navigation).
 */
(function () {
  const ID  = 'fps-consent-banner', LS = 'fps_consent', SF = 'fps_consent_asserted';
  const MAX = 31536000;

  const getCookie = n => (document.cookie.split(';').map(s=>s.trim()).find(s=>s.startsWith(n+'='))||'').split('=')[1];
  const setCookie = (n,v) => { document.cookie = `${n}=${v};Max-Age=${MAX};path=/;SameSite=Lax`; };
  const cssVar    = (n,fb) => getComputedStyle(document.documentElement).getPropertyValue(n).trim() || fb;
  const isDark    = () => /dark/i.test(document.body.className||'') ||
                          !!(window.matchMedia && matchMedia('(prefers-color-scheme:dark)').matches);

  const V4 = { ad_storage:'granted', analytics_storage:'granted', ad_user_data:'granted', ad_personalization:'granted' };

  function assertConsent() {
    if (window.gtag) gtag('consent', 'update', V4);
    if (window.clarity) clarity('consent');
  }

  function buildBanner(el) {
    const dark = isDark();
    const bg   = cssVar('--fps-bg-card',     dark ? 'rgb(20,22,26)' : '#ffffff');
    const fg   = cssVar('--fps-text-primary', dark ? '#ffffff'       : '#111111');
    const ac   = cssVar('--fps-accent',       '#3a82f7');
    const url  = el.dataset.privacyUrl || '/privacypolicy.php';
    el.setAttribute('role','dialog');
    el.setAttribute('aria-label','Cookie consent');
    el.setAttribute('aria-describedby','fps-cb-desc');
    el.setAttribute('aria-modal','true');
    el.style.cssText = `position:fixed;bottom:0;left:0;right:0;z-index:999999;display:flex;justify-content:center;background:${bg};box-shadow:0 -2px 16px rgba(0,0,0,.25);padding:16px`;
    el.innerHTML = `<div style="max-width:720px;width:100%;display:flex;flex-wrap:wrap;gap:12px;align-items:center;color:${fg};font-size:16px;font-family:inherit">
      <p id="fps-cb-desc" style="margin:0;flex:1 1 300px;line-height:1.5">We use Google Analytics &amp; Microsoft Clarity to improve our service. They set cookies on your device.</p>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a id="fps-cb-prv" href="${url}" target="_blank" rel="noopener" style="color:${ac};text-decoration:underline;font-size:14px;white-space:nowrap">Privacy policy &#8599;</a>
        <button id="fps-cb-no"  style="background:transparent;border:2px solid ${fg};color:${fg};padding:8px 16px;border-radius:6px;font-size:15px;cursor:pointer;outline-offset:3px">Decline</button>
        <button id="fps-cb-yes" style="background:${ac};border:2px solid ${ac};color:#fff;padding:8px 20px;border-radius:6px;font-size:15px;cursor:pointer;font-weight:600;outline-offset:3px">Accept</button>
      </div></div>`;
  }

  function run() {
    const el = document.getElementById(ID);
    if (!el || el.dataset.active !== '1') return;

    const ls = localStorage.getItem(LS), ck = getCookie(LS);

    if (ls === '1' || ck === '1') {                        // previously accepted
      if (!sessionStorage.getItem(SF)) { sessionStorage.setItem(SF,'1'); assertConsent(); }
      return;
    }
    if (ls === '0' || ck === '0') return;                  // previously declined

    buildBanner(el);
    el.hidden = false;

    function dismiss(accept) {
      if (accept) {
        assertConsent();
        localStorage.setItem(LS,'1'); setCookie(LS,'1');
      } else {
        // Default-deny stays. No gtag/clarity calls.
        localStorage.setItem(LS,'0'); setCookie(LS,'0');
      }
      el.hidden = true; el.innerHTML = '';
      document.removeEventListener('keydown', onKey);
    }

    function onKey(e) {
      if (e.key === 'Escape') { dismiss(false); return; }
      if (e.key !== 'Tab') return;
      const nodes = [...el.querySelectorAll('a[href],button:not([disabled])')];
      if (!nodes.length) return;
      if (e.shiftKey && document.activeElement === nodes[0])
        { e.preventDefault(); nodes[nodes.length-1].focus(); }
      else if (!e.shiftKey && document.activeElement === nodes[nodes.length-1])
        { e.preventDefault(); nodes[0].focus(); }
    }

    document.getElementById('fps-cb-yes').addEventListener('click', () => dismiss(true));
    document.getElementById('fps-cb-no').addEventListener('click',  () => dismiss(false));
    document.addEventListener('keydown', onKey);
    (el.querySelector('a[href],button') || el).focus();
  }

  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', run)
    : run();
}());

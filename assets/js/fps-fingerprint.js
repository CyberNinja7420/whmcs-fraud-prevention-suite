/**
 * Fraud Prevention Suite v4.1.2 - Client Fingerprint Collector
 * Injected on WHMCS checkout pages. Self-contained IIFE.
 * No dependencies. All signals wrapped in try/catch.
 *
 * Output:
 *   1. Hidden input named 'fps_fingerprint' injected into the checkout form.
 *   2. Async POST to fps_callback_url (set via data attribute on script tag).
 *
 * Configuration (via script tag attributes):
 *   data-callback="/path/to/fps/api.php?a=fingerprint"
 *   data-form="checkout-form"  (optional, defaults to first <form>)
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------------
     COMMON FONT LIST for intersection test
  ------------------------------------------------------------------ */
  var FONT_LIST = [
    'Arial','Arial Black','Arial Narrow','Calibri','Cambria','Comic Sans MS',
    'Courier New','Georgia','Helvetica','Impact','Lucida Console','Lucida Sans Unicode',
    'Microsoft Sans Serif','Palatino Linotype','Segoe UI','Tahoma','Times New Roman',
    'Trebuchet MS','Verdana','Wingdings',
  ];

  /* ------------------------------------------------------------------
     SHA-256 via SubtleCrypto (returns hex string Promise)
  ------------------------------------------------------------------ */
  function sha256(str) {
    try {
      var buf = new TextEncoder().encode(str);
      return crypto.subtle.digest('SHA-256', buf).then(function (hash) {
        return Array.from(new Uint8Array(hash))
          .map(function (b) { return ('00' + b.toString(16)).slice(-2); })
          .join('');
      });
    } catch (e) {
      return Promise.resolve('unsupported');
    }
  }

  /* ------------------------------------------------------------------
     NAVIGATOR SIGNALS
  ------------------------------------------------------------------ */
  function collectNavigator() {
    var n = navigator;
    return {
      ua:               _safe(function () { return n.userAgent; }),
      lang:             _safe(function () { return n.language; }),
      langs:            _safe(function () { return (n.languages || []).slice(0, 5).join(','); }),
      platform:         _safe(function () { return n.platform; }),
      cores:            _safe(function () { return n.hardwareConcurrency; }),
      mem:              _safe(function () { return n.deviceMemory; }),
      touch:            _safe(function () { return n.maxTouchPoints; }),
      cookies:          _safe(function () { return n.cookieEnabled; }),
      dnt:              _safe(function () { return n.doNotTrack; }),
      vendor:           _safe(function () { return n.vendor; }),
      appVersion:       _safe(function () { return n.appVersion; }),
      pdfPlugin:        _safe(function () { return n.pdfViewerEnabled; }),
    };
  }

  /* ------------------------------------------------------------------
     SCREEN SIGNALS
  ------------------------------------------------------------------ */
  function collectScreen() {
    return {
      w:    _safe(function () { return screen.width; }),
      h:    _safe(function () { return screen.height; }),
      aw:   _safe(function () { return screen.availWidth; }),
      ah:   _safe(function () { return screen.availHeight; }),
      cd:   _safe(function () { return screen.colorDepth; }),
      dpr:  _safe(function () { return window.devicePixelRatio; }),
      ori:  _safe(function () { return screen.orientation && screen.orientation.type; }),
    };
  }

  /* ------------------------------------------------------------------
     TIMEZONE
  ------------------------------------------------------------------ */
  function collectTimezone() {
    return {
      tz:     _safe(function () { return Intl.DateTimeFormat().resolvedOptions().timeZone; }),
      offset: _safe(function () { return new Date().getTimezoneOffset(); }),
    };
  }

  /* ------------------------------------------------------------------
     CANVAS FINGERPRINT
  ------------------------------------------------------------------ */
  function canvasFingerprint() {
    return new Promise(function (resolve) {
      try {
        var c = document.createElement('canvas');
        c.width  = 240;
        c.height = 60;
        var ctx = c.getContext('2d');
        ctx.textBaseline = 'alphabetic';

        // Background
        ctx.fillStyle = '#f0f0f0';
        ctx.fillRect(0, 0, 240, 60);

        // Gradient text
        var grad = ctx.createLinearGradient(0, 0, 240, 0);
        grad.addColorStop(0, '#667eea');
        grad.addColorStop(1, '#764ba2');
        ctx.fillStyle = grad;
        ctx.font = '16px Arial';
        ctx.fillText('FPS\u2122 \u00ab\u00bb', 10, 35);

        // Colored shapes
        ctx.fillStyle = 'rgba(102,126,234,0.3)';
        ctx.beginPath();
        ctx.arc(190, 30, 18, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = 'rgba(118,75,162,0.4)';
        ctx.fillRect(5, 5, 12, 12);

        var dataURL = c.toDataURL('image/png');
        sha256(dataURL).then(function (hash) {
          resolve({ hash: hash, raw: dataURL.slice(0, 60) });
        });
      } catch (e) {
        resolve({ hash: 'blocked', raw: null });
      }
    });
  }

  /* ------------------------------------------------------------------
     WEBGL FINGERPRINT
  ------------------------------------------------------------------ */
  function webglFingerprint() {
    return new Promise(function (resolve) {
      try {
        var c = document.createElement('canvas');
        var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
        if (!gl) { resolve({ vendor: null, renderer: null, hash: 'unsupported' }); return; }

        var ext  = gl.getExtension('WEBGL_debug_renderer_info');
        var v    = ext ? gl.getParameter(ext.UNMASKED_VENDOR_WEBGL)   : gl.getParameter(gl.VENDOR);
        var r    = ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);

        var params = [
          gl.getParameter(gl.VERSION),
          gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
          gl.getParameter(gl.MAX_TEXTURE_SIZE),
          gl.getParameter(gl.MAX_VIEWPORT_DIMS),
          gl.getParameter(gl.MAX_VERTEX_ATTRIBS),
        ].join('|');

        sha256(v + '|' + r + '|' + params).then(function (hash) {
          resolve({ vendor: v, renderer: r, hash: hash });
        });
      } catch (e) {
        resolve({ vendor: null, renderer: null, hash: 'blocked' });
      }
    });
  }

  /* ------------------------------------------------------------------
     WEBRTC LOCAL IP (timeout 2 seconds)
  ------------------------------------------------------------------ */
  function webrtcIPs() {
    return new Promise(function (resolve) {
      var ips   = [];
      var timer = setTimeout(function () { resolve(ips); }, 2000);

      try {
        var pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
        pc.createDataChannel('fps');
        pc.onicecandidate = function (e) {
          if (!e || !e.candidate) { clearTimeout(timer); pc.close(); resolve(ips); return; }
          var match = /([0-9]{1,3}(?:\.[0-9]{1,3}){3}|[a-f0-9:]{2,})/.exec(e.candidate.candidate || '');
          if (match && ips.indexOf(match[1]) === -1) ips.push(match[1]);
        };
        pc.createOffer().then(function (offer) { return pc.setLocalDescription(offer); }).catch(function () {
          clearTimeout(timer); resolve(ips);
        });
      } catch (e) {
        clearTimeout(timer);
        resolve(ips);
      }
    });
  }

  /* ------------------------------------------------------------------
     FONT DETECTION (canvas intersection test)
  ------------------------------------------------------------------ */
  function detectFonts() {
    try {
      var c   = document.createElement('canvas');
      var ctx = c.getContext('2d');
      var BASE  = 'monospace';
      var TEXT  = 'mmmmmmmmmmlli';
      var SIZE  = '72px ';

      // Measure baseline
      ctx.font = SIZE + BASE;
      var bw = ctx.measureText(TEXT).width;

      return FONT_LIST.filter(function (font) {
        ctx.font = SIZE + "'" + font + "'," + BASE;
        return ctx.measureText(TEXT).width !== bw;
      });
    } catch (e) {
      return [];
    }
  }

  /* ------------------------------------------------------------------
     AUDIO FINGERPRINT
  ------------------------------------------------------------------ */
  function audioFingerprint() {
    return new Promise(function (resolve) {
      try {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) { resolve(null); return; }

        var ctx  = new AC();
        var osc  = ctx.createOscillator();
        var anal = ctx.createAnalyser();
        var gain = ctx.createGain();

        gain.gain.value = 0;
        osc.type = 'triangle';
        osc.frequency.value = 10000;
        osc.connect(anal);
        anal.connect(gain);
        gain.connect(ctx.destination);

        osc.start(0);
        anal.fftSize = 1024;

        setTimeout(function () {
          var buf = new Float32Array(anal.frequencyBinCount);
          anal.getFloatFrequencyData(buf);
          osc.stop();
          try { ctx.close(); } catch (e2) { /* ignore */ }

          var sum = 0;
          for (var i = 0; i < buf.length; i++) sum += buf[i];
          resolve(sum.toString());
        }, 100);
      } catch (e) {
        resolve(null);
      }
    });
  }

  /* ------------------------------------------------------------------
     MISC BROWSER FEATURES
  ------------------------------------------------------------------ */
  function collectFeatures() {
    return {
      localStorage:  _safe(function () { localStorage.setItem('fps_t', '1'); localStorage.removeItem('fps_t'); return true; }),
      sessionStorage:_safe(function () { sessionStorage.setItem('fps_t', '1'); sessionStorage.removeItem('fps_t'); return true; }),
      indexedDB:     _safe(function () { return !!window.indexedDB; }),
      openDB:        _safe(function () { return !!window.openDatabase; }),
      addBehavior:   _safe(function () { return typeof document.body.addBehavior !== 'undefined'; }),
      cpuClass:      _safe(function () { return navigator.cpuClass; }),
      oscpu:         _safe(function () { return navigator.oscpu; }),
      plugins:       _safe(function () {
        return Array.from(navigator.plugins || []).slice(0, 10).map(function (p) { return p.name; }).join(',');
      }),
      mimeTypes:     _safe(function () { return navigator.mimeTypes ? navigator.mimeTypes.length : 0; }),
      webdriver:     _safe(function () { return navigator.webdriver; }),
      window_size:   _safe(function () { return window.innerWidth + 'x' + window.innerHeight; }),
    };
  }

  /* ------------------------------------------------------------------
     SAFE WRAPPER
  ------------------------------------------------------------------ */
  function _safe(fn) {
    try { return fn(); } catch (e) { return null; }
  }

  /* ------------------------------------------------------------------
     MAIN COLLECTOR
  ------------------------------------------------------------------ */
  function collect() {
    return Promise.all([
      canvasFingerprint(),
      webglFingerprint(),
      webrtcIPs(),
      audioFingerprint(),
    ]).then(function (results) {
      var fp = {
        v:          '2.0',
        ts:         Date.now(),
        nav:        collectNavigator(),
        screen:     collectScreen(),
        tz:         collectTimezone(),
        fonts:      detectFonts(),
        features:   collectFeatures(),
        canvas:     results[0],
        webgl:      results[1],
        webrtc_ips: results[2],
        audio:      results[3],
      };

      // Composite hash of stable signals
      var stable = [
        fp.canvas.hash,
        fp.webgl.hash,
        fp.nav.ua,
        fp.screen.w + 'x' + fp.screen.h,
        fp.tz.tz,
        fp.nav.cores,
        fp.nav.mem,
      ].join('|');

      return sha256(stable).then(function (compositeHash) {
        fp.fp_id = compositeHash;
        return fp;
      });
    });
  }

  /* ------------------------------------------------------------------
     INJECT INTO FORM
  ------------------------------------------------------------------ */
  function injectIntoForm(fpJson) {
    var scriptTag = document.currentScript ||
      document.querySelector('script[data-callback]') ||
      document.querySelector('script[src*="fps-fingerprint"]');

    var formId = scriptTag && scriptTag.getAttribute('data-form');
    var form   = (formId && document.getElementById(formId)) ||
                 document.querySelector('form');

    if (!form) return;

    var existing = form.querySelector('input[name="fps_fingerprint"]');
    var input    = existing || document.createElement('input');
    input.type   = 'hidden';
    input.name   = 'fps_fingerprint';
    input.value  = fpJson;
    if (!existing) form.appendChild(input);
  }

  /* ------------------------------------------------------------------
     POST TO CALLBACK
  ------------------------------------------------------------------ */
  function postToCallback(fpJson) {
    try {
      var scriptTag = document.currentScript ||
        document.querySelector('script[data-callback]') ||
        document.querySelector('script[src*="fps-fingerprint"]');

      var url = scriptTag && scriptTag.getAttribute('data-callback');
      if (!url) return;

      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: fpJson,
        keepalive: true,
      }).catch(function () { /* silently fail */ });
    } catch (e) { /* ad-blocker or CORS - ignore */ }
  }

  /* ------------------------------------------------------------------
     ENTRY POINT (runs after DOM is ready)
  ------------------------------------------------------------------ */
  function run() {
    collect().then(function (fp) {
      var fpJson = JSON.stringify(fp);
      injectIntoForm(fpJson);
      postToCallback(fpJson);
    }).catch(function () { /* complete failure - do not break page */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

}());

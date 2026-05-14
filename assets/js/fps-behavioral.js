/**
 * FPS Behavioral Fingerprint Collector
 *
 * Monitors user interaction patterns during checkout to distinguish
 * humans from bots. Signals are scored server-side by
 * FpsBehavioralScoringEngine::analyze().
 *
 * Collected signals:
 *   - Mouse movement count and total pixel distance
 *   - Mouse path entropy (Shannon entropy of movement angles)
 *   - Click and touch event counts
 *   - Keystroke count and inter-key timing intervals
 *   - Paste events on form fields (field name + text length)
 *   - Form fill duration from first input to submission
 *   - Scroll event count
 *   - Tab/visibility switch count and total focus time
 *   - Total time on page
 *
 * Output field names match FpsBehavioralScoringEngine::analyze() exactly:
 *   form_fill_time_ms, mouse_movements, mouse_distance_px, mouse_entropy,
 *   paste_events[], keypress_intervals_ms[], tab_switches, time_on_page_ms,
 *   scroll_events, touch_events
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------
    var state = {
        mouseMovements: 0,
        mouseDistancePx: 0,
        lastMouse: null,
        mouseAngles: [],         // for entropy calculation
        clicks: 0,
        touchEvents: 0,
        keystrokes: 0,
        keypressIntervalsMs: [],
        lastKeyTime: 0,
        pasteEvents: [],
        formFillStart: 0,
        scrollEvents: 0,
        tabSwitches: 0,
        focusTimeMs: 0,
        focusStart: Date.now(),
        pageLoadTime: Date.now()
    };

    // -----------------------------------------------------------------------
    // Mouse tracking
    // -----------------------------------------------------------------------
    document.addEventListener('mousemove', function (e) {
        try {
            state.mouseMovements++;
            if (state.lastMouse) {
                var dx = e.clientX - state.lastMouse.x;
                var dy = e.clientY - state.lastMouse.y;
                var dist = Math.sqrt(dx * dx + dy * dy);
                state.mouseDistancePx += dist;

                // Record angle bucket (8 cardinal directions) for entropy
                if (dist > 2) { // ignore sub-pixel jitter
                    var angle = Math.atan2(dy, dx);
                    // Quantize to 8 buckets (0-7)
                    var bucket = Math.floor(((angle + Math.PI) / (2 * Math.PI)) * 8) % 8;
                    state.mouseAngles.push(bucket);
                }
            }
            state.lastMouse = { x: e.clientX, y: e.clientY };
        } catch (e2) { /* non-fatal */ }
    });

    // -----------------------------------------------------------------------
    // Click and touch
    // -----------------------------------------------------------------------
    document.addEventListener('click', function () { state.clicks++; });
    document.addEventListener('touchstart', function () { state.touchEvents++; });

    // -----------------------------------------------------------------------
    // Scroll
    // -----------------------------------------------------------------------
    document.addEventListener('scroll', function () { state.scrollEvents++; });

    // -----------------------------------------------------------------------
    // Keyboard tracking
    // -----------------------------------------------------------------------
    document.addEventListener('keydown', function (e) {
        try {
            state.keystrokes++;
            var now = Date.now();
            if (state.lastKeyTime > 0) {
                var interval = now - state.lastKeyTime;
                // Cap at 10 seconds to avoid skewing from idle pauses
                if (interval < 10000) {
                    state.keypressIntervalsMs.push(interval);
                }
            }
            state.lastKeyTime = now;

            // Record form fill start on first INPUT/TEXTAREA keystroke
            if (state.formFillStart === 0 && e.target) {
                var tag = e.target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                    state.formFillStart = now;
                }
            }
        } catch (e2) { /* non-fatal */ }
    });

    // -----------------------------------------------------------------------
    // Paste detection
    // -----------------------------------------------------------------------
    document.addEventListener('paste', function (e) {
        try {
            var field = 'unknown';
            if (e.target) {
                field = e.target.name || e.target.id || 'unknown';
            }
            // Try to get pasted text length from clipboard
            var pastedLength = 0;
            if (e.clipboardData && typeof e.clipboardData.getData === 'function') {
                try {
                    var text = e.clipboardData.getData('text/plain');
                    pastedLength = text ? text.length : 0;
                } catch (ce) {
                    pastedLength = 0;
                }
            }
            state.pasteEvents.push({
                field: field,
                length: pastedLength
            });
        } catch (e2) { /* non-fatal */ }
    });

    // -----------------------------------------------------------------------
    // Tab visibility / focus tracking
    // -----------------------------------------------------------------------
    document.addEventListener('visibilitychange', function () {
        try {
            if (document.hidden) {
                state.tabSwitches++;
                state.focusTimeMs += Date.now() - state.focusStart;
            } else {
                state.focusStart = Date.now();
            }
        } catch (e) { /* non-fatal */ }
    });

    // -----------------------------------------------------------------------
    // Shannon entropy of mouse angle buckets (8 directions)
    // Returns 0-3.0 range (log2(8) = 3.0 for perfectly uniform distribution)
    // Low entropy = linear/robotic paths. High entropy = natural human movement.
    // -----------------------------------------------------------------------
    function calculateMouseEntropy() {
        var angles = state.mouseAngles;
        if (angles.length < 10) return 0.0; // not enough data

        // Count occurrences of each bucket (0-7)
        var counts = [0, 0, 0, 0, 0, 0, 0, 0];
        for (var i = 0; i < angles.length; i++) {
            counts[angles[i]]++;
        }

        // Shannon entropy
        var total = angles.length;
        var entropy = 0.0;
        for (var b = 0; b < 8; b++) {
            if (counts[b] > 0) {
                var p = counts[b] / total;
                entropy -= p * Math.log2(p);
            }
        }
        return Math.round(entropy * 1000) / 1000; // 3 decimal places
    }

    // -----------------------------------------------------------------------
    // Build the payload matching FpsBehavioralScoringEngine::analyze() schema
    // -----------------------------------------------------------------------
    function buildPayload() {
        // Finalize focus time (add current focus session if page is visible)
        var focusTime = state.focusTimeMs;
        if (!document.hidden) {
            focusTime += Date.now() - state.focusStart;
        }

        var formFillMs = 0;
        if (state.formFillStart > 0) {
            formFillMs = Date.now() - state.formFillStart;
        }

        return {
            // Fields named to match FpsBehavioralScoringEngine::analyze() parameter
            form_fill_time_ms:     formFillMs,
            mouse_movements:       state.mouseMovements,
            mouse_distance_px:     Math.round(state.mouseDistancePx),
            mouse_entropy:         calculateMouseEntropy(),
            paste_events:          state.pasteEvents,
            keypress_intervals_ms: state.keypressIntervalsMs,
            tab_switches:          state.tabSwitches,
            time_on_page_ms:       Date.now() - state.pageLoadTime,
            scroll_events:         state.scrollEvents,
            touch_events:          state.touchEvents,
            // Extra fields for server-side context (not scored, but logged)
            clicks:                state.clicks,
            keystrokes:            state.keystrokes,
            focus_time_ms:         focusTime
        };
    }

    // -----------------------------------------------------------------------
    // Inject payload into checkout forms before submission
    // -----------------------------------------------------------------------
    function injectBehavioral() {
        try {
            var payload = JSON.stringify(buildPayload());
            var forms = document.querySelectorAll(
                'form[method="post"], #frmCheckout, form[action*="cart.php"]'
            );
            for (var i = 0; i < forms.length; i++) {
                var form = forms[i];
                var existing = form.querySelector('input[name="fps_behavioral"]');
                if (existing) existing.remove();

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'fps_behavioral';
                input.value = payload;
                form.appendChild(input);
            }
        } catch (e) { /* non-fatal */ }
    }

    // -----------------------------------------------------------------------
    // Hook form submission (capture phase so we run before other handlers)
    // -----------------------------------------------------------------------
    document.addEventListener('submit', function () {
        injectBehavioral();
    }, true);

    // Also inject before any AJAX checkout via fetch()
    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch;
        window.fetch = function () {
            injectBehavioral();
            return origFetch.apply(this, arguments);
        };
    }

    // Also intercept XMLHttpRequest.send for jQuery-based AJAX checkouts
    if (typeof XMLHttpRequest !== 'undefined') {
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function () {
            injectBehavioral();
            return origSend.apply(this, arguments);
        };
    }
})();

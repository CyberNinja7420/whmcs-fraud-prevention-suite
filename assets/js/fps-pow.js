/**
 * FPS Proof-of-Work Challenge
 *
 * Generates a SHA-256 hash with a required number of leading zero bits.
 * Real browsers solve this in ~100-300ms. Bots running thousands of
 * requests per minute pay a cumulative CPU cost that makes automated
 * checkout abuse economically unviable at scale.
 *
 * Difficulty 16 = 16 leading zero bits = ~65K iterations on average.
 * SubtleCrypto is hardware-accelerated in modern browsers so the solve
 * time stays well under the 500ms budget on desktop and mobile.
 */
(function () {
    'use strict';

    var DIFFICULTY = 16; // leading zero BITS (4 hex chars = 16 bits)

    /**
     * Solve the PoW challenge by brute-forcing nonces until the SHA-256
     * hash of challenge + ':' + nonce has DIFFICULTY leading zero bits.
     */
    function solvePow(challenge) {
        var nonce = 0;
        var prefix = challenge + ':';
        var hexChars = Math.floor(DIFFICULTY / 4);

        // Async solver that yields to the UI thread every 10K iterations
        return new Promise(function (resolve) {
            function step() {
                var end = nonce + 10000;
                var remaining = end;

                function checkNext() {
                    if (nonce >= remaining) {
                        // Yield to UI thread before next batch
                        setTimeout(step, 0);
                        return;
                    }
                    var input = prefix + nonce;
                    var current = nonce;
                    nonce++;
                    sha256(input).then(function (hash) {
                        if (hasLeadingZeros(hash, hexChars)) {
                            resolve({ nonce: current, hash: hash });
                        } else {
                            checkNext();
                        }
                    });
                }

                checkNext();
            }

            step();
        });
    }

    /**
     * SHA-256 via SubtleCrypto (hardware-accelerated). Returns hex string.
     */
    function sha256(str) {
        try {
            var buffer = new TextEncoder().encode(str);
            return crypto.subtle.digest('SHA-256', buffer).then(function (hashBuffer) {
                return Array.from(new Uint8Array(hashBuffer))
                    .map(function (b) { return b.toString(16).padStart(2, '0'); })
                    .join('');
            });
        } catch (e) {
            return Promise.resolve('unsupported');
        }
    }

    /**
     * Check whether the first `hexChars` characters of the hash are all '0'.
     */
    function hasLeadingZeros(hash, hexChars) {
        for (var i = 0; i < hexChars; i++) {
            if (hash.charAt(i) !== '0') return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Auto-solve on checkout pages
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll(
            'form[method="post"], #frmCheckout, form[action*="cart.php"]'
        );
        if (forms.length === 0) return;

        // Generate challenge from page timestamp + random salt
        var challenge = 'fps:' + Date.now() + ':' + Math.random().toString(36).substr(2, 8);
        var solveStart = Date.now();

        // Start solving immediately in the background
        solvePow(challenge).then(function (solution) {
            var solveTime = Date.now() - solveStart;
            var payload = JSON.stringify({
                challenge: challenge,
                nonce: solution.nonce,
                hash: solution.hash,
                difficulty: DIFFICULTY,
                solve_time_ms: solveTime
            });

            // Inject solution into all checkout forms
            for (var i = 0; i < forms.length; i++) {
                var form = forms[i];
                // Remove any stale input from a previous solve
                var existing = form.querySelector('input[name="fps_pow_solution"]');
                if (existing) existing.remove();

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'fps_pow_solution';
                input.value = payload;
                form.appendChild(input);
            }
        });
    });
})();

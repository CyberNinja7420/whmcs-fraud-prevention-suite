#!/usr/bin/env bash
# refresh-vendor-assets.sh
# ----------------------------------------------------------------------------
# Refresh vendored frontend dependencies for the Fraud Prevention Suite from
# their canonical CDN sources. Pin to a specific upstream version so the
# refresh is deterministic between runs.
#
# Designed to run quarterly (e.g. systemd timer or cron monthly with a 90-day
# guard) on the deploy host. Idempotent -- skips downloads when the local file
# already matches the pinned version.
#
# Exit codes:
#   0 = success (all assets present and up-to-date)
#   1 = at least one download failed (file unchanged)
#   2 = environment error (missing curl, write permission, etc.)
# ----------------------------------------------------------------------------

set -euo pipefail

# --- Configuration -----------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_ROOT="${ADDON_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
VENDOR_DIR="${ADDON_ROOT}/assets/vendor"

# Pinned versions (bump these consciously and run a manual refresh + smoke test
# before deploying). Keeping the major version stable preserves the JS API.
APEXCHARTS_VERSION="3.54.1"
THREEJS_VERSION="0.160.0"
GLOBE_GL_VERSION="2.31.0"

# CDN URLs (jsdelivr is the canonical mirror used by the runtime fallback)
APEXCHARTS_URL="https://cdn.jsdelivr.net/npm/apexcharts@${APEXCHARTS_VERSION}/dist/apexcharts.min.js"
THREEJS_URL="https://cdn.jsdelivr.net/npm/three@${THREEJS_VERSION}/build/three.min.js"
GLOBE_GL_URL="https://cdn.jsdelivr.net/npm/globe.gl@${GLOBE_GL_VERSION}/dist/globe.gl.min.js"

# --- Helpers -----------------------------------------------------------------
log() { printf '[refresh-vendor] %s\n' "$*"; }
err() { printf '[refresh-vendor] ERROR: %s\n' "$*" >&2; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { err "missing required command: $1"; exit 2; }
}

require_cmd curl
require_cmd sha256sum
require_cmd mkdir
require_cmd mv

mkdir -p "${VENDOR_DIR}"

FAILED=0

fetch_pinned() {
  local url="$1"
  local dest="$2"
  local label="$3"
  local tmpfile

  tmpfile="$(mktemp)"
  log "fetching ${label} from ${url}"
  if curl -fsSL --max-time 60 -o "${tmpfile}" "${url}"; then
    if [[ -f "${dest}" ]]; then
      local oldhash newhash
      oldhash="$(sha256sum "${dest}" | awk '{print $1}')"
      newhash="$(sha256sum "${tmpfile}" | awk '{print $1}')"
      if [[ "${oldhash}" == "${newhash}" ]]; then
        log "  unchanged (sha256 match) -- ${dest}"
        rm -f "${tmpfile}"
        return 0
      fi
    fi
    mv "${tmpfile}" "${dest}"
    log "  updated ${dest} ($(wc -c < "${dest}") bytes)"
  else
    err "download failed for ${label} (${url})"
    rm -f "${tmpfile}"
    FAILED=1
  fi
}

# --- Run ---------------------------------------------------------------------
log "addon root: ${ADDON_ROOT}"
log "vendor dir: ${VENDOR_DIR}"

fetch_pinned "${APEXCHARTS_URL}"  "${VENDOR_DIR}/apexcharts.min.js" "ApexCharts ${APEXCHARTS_VERSION}"
fetch_pinned "${THREEJS_URL}"     "${VENDOR_DIR}/three.min.js"      "three.js ${THREEJS_VERSION}"
fetch_pinned "${GLOBE_GL_URL}"    "${VENDOR_DIR}/globe.gl.min.js"   "globe.gl ${GLOBE_GL_VERSION}"

if [[ "${FAILED}" -ne 0 ]]; then
  err "one or more downloads failed -- existing vendor files left in place"
  exit 1
fi

log "all vendor assets up to date"
exit 0

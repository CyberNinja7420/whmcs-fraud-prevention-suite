#!/usr/bin/env bash
# check-release-integrity.sh
#
# Verifies that all release/version metadata surfaces are aligned and
# that documented critical paths exist. Exits non-zero with a clear
# message if any drift is detected.
#
# Wired into CI as a gate (.gitlab-ci.yml `integrity` stage and the
# GitHub Actions QA workflow). Designed to be runnable locally with no
# arguments from the repo root.
#
# Added in the audit-findings reconciliation pass to make sure the
# version drift the audit caught (FPS_MODULE_VERSION vs version.json vs
# README) cannot silently re-emerge.

set -u
fail=0
say() { printf "%s\n" "$*"; }
warn() { printf "[FAIL] %s\n" "$*" >&2; fail=1; }
ok()   { printf "[ OK ] %s\n" "$*"; }

# Move to repo root regardless of where the script is invoked from.
cd "$(dirname "$0")/.." || exit 2

# 1. Extract canonical version from fraud_prevention_suite.php
php_version="$(grep -oE 'define\("FPS_MODULE_VERSION", "[0-9]+\.[0-9]+\.[0-9]+"\)' fraud_prevention_suite.php \
    | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
if [ -z "$php_version" ]; then
    warn "could not extract FPS_MODULE_VERSION from fraud_prevention_suite.php"
    exit $fail
fi
say "Canonical version (fraud_prevention_suite.php): $php_version"

# 2. version.json must match
if [ ! -f version.json ]; then
    warn "version.json missing"
else
    json_version="$(grep -oE '"version"\s*:\s*"[0-9]+\.[0-9]+\.[0-9]+"' version.json \
        | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
    if [ "$json_version" = "$php_version" ]; then
        ok "version.json version matches ($json_version)"
    else
        warn "version.json version=$json_version but FPS_MODULE_VERSION=$php_version"
    fi
fi

# 3. CHANGELOG.md must contain a heading for this version
if grep -qE "^##[[:space:]]*\[?$php_version\]?" CHANGELOG.md; then
    ok "CHANGELOG.md has section for $php_version"
else
    warn "CHANGELOG.md has no '## [$php_version]' or '## $php_version' heading"
fi

# 4. README.md must mention this version somewhere
if grep -qE "(^|[^0-9])$php_version([^0-9]|$)" README.md; then
    ok "README.md mentions $php_version"
else
    warn "README.md does not mention version $php_version"
fi

# 5. Server module file must live at the documented WHMCS path
if [ -f modules/servers/fps_api/fps_api.php ]; then
    ok "modules/servers/fps_api/fps_api.php exists"
else
    warn "modules/servers/fps_api/fps_api.php is MISSING (docs claim this path)"
fi

# 6. install/fps_api.php must NOT contain the old server-module body
if [ -f install/fps_api.php ] && grep -q "ServerCreateAccount\|ServerSuspendAccount\|fps_api_ConfigOptions" install/fps_api.php; then
    warn "install/fps_api.php still appears to contain server-module logic; should be a stub or removed"
else
    ok "install/fps_api.php is clean (stub or absent)"
fi

# 7. Stale version strings in source/docs (not in CHANGELOG history)
# Only report files that are NOT CHANGELOG.md and NOT historical docs.
stale="$(grep -REn '4\.2\.[0-4]([^0-9]|$)' \
    --include='*.php' --include='*.json' --include='*.yml' \
    --exclude-dir=vendor --exclude-dir=.git --exclude-dir=node_modules \
    . 2>/dev/null \
    | grep -vE '(CHANGELOG\.md|\.bak\.|legacy|historical|migration|comment|//)' \
    | grep -vE 'pre-v4\.2\.|legacy installs|earlier \(v4\.2\.|Items-?12?4|Pass-2|originally landed in|landed in the v4\.2\.|in the v4\.2\. release|since v4\.2\.|reader migration' \
    || true)"
if [ -n "$stale" ]; then
    warn "stale 4.2.0-4.2.4 version strings found in source files:"
    printf "%s\n" "$stale" | head -20 | sed 's/^/        /'
else
    ok "no stale 4.2.0-4.2.4 version strings in PHP/JSON/YAML"
fi

# 8. Docs must not point to legacy install/fps_api.php upload path
legacy_install_refs="$(grep -REn 'install/fps_api\.php' docs README.md 2>/dev/null \
    | grep -vE 'Removed in|deprecated|legacy stub' || true)"
if [ -n "$legacy_install_refs" ]; then
    warn "docs still tell users to upload install/fps_api.php:"
    printf "%s\n" "$legacy_install_refs" | head -10 | sed 's/^/        /'
else
    ok "docs reference modules/servers/fps_api/ (correct path)"
fi

# 9. No raw API key persistence or misleading "safely stored" comments
unsafe_comments="$(grep -REn 'safely stored|safe to keep|safe to store' \
    --include='*.php' \
    --exclude-dir=vendor lib modules public 2>/dev/null || true)"
if [ -n "$unsafe_comments" ]; then
    warn "misleading 'safely stored' comments still present:"
    printf "%s\n" "$unsafe_comments" | sed 's/^/        /'
else
    ok "no misleading 'safely stored' comments"
fi

# 10. No request-state mutation in API/controller layer
get_mutation="$(grep -REn '\$_GET\[[^]]+\][[:space:]]*=' \
    --include='*.php' --exclude-dir=vendor public lib 2>/dev/null || true)"
if [ -n "$get_mutation" ]; then
    warn "\$_GET mutation found (controllers should be read-only):"
    printf "%s\n" "$get_mutation" | sed 's/^/        /'
else
    ok "no \$_GET mutation in public/ or lib/"
fi

echo
if [ $fail -eq 0 ]; then
    say "Release integrity: PASS"
    exit 0
else
    say "Release integrity: FAIL"
    exit 1
fi

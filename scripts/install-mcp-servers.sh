#!/usr/bin/env bash
#
# install-mcp-servers.sh -- merges Google Analytics + Microsoft Clarity MCP
# server entries into the user's ~/.claude/settings.json (or
# $CLAUDE_SETTINGS if set).
#
# Backs up the existing file first (.bak.YYYYMMDD-HHMMSS). Idempotent --
# re-running just refreshes both entries to current versions.
#
# After running, verify with: claude mcp list
#
set -euo pipefail

SETTINGS="${CLAUDE_SETTINGS:-$HOME/.claude/settings.json}"
TS=$(date +%Y%m%d-%H%M%S)
BACKUP="${SETTINGS}.bak.${TS}"

mkdir -p "$(dirname "$SETTINGS")"

if [[ ! -f "$SETTINGS" ]]; then
    echo '{"mcpServers":{}}' > "$SETTINGS"
    echo "Created $SETTINGS (was missing)"
fi

cp "$SETTINGS" "$BACKUP"
echo "Backed up to $BACKUP"

python3 - <<PYEOF
import json, os
path = os.environ.get('CLAUDE_SETTINGS', os.path.expanduser('~/.claude/settings.json'))
with open(path) as f:
    data = json.load(f)
data.setdefault('mcpServers', {})
data['mcpServers']['google-analytics'] = {
    'command': 'npx',
    'args': ['-y', '@google/analytics-mcp-server']
}
data['mcpServers']['microsoft-clarity'] = {
    'command': 'uvx',
    'args': ['--from', 'git+https://github.com/microsoft/clarity-mcp-server', 'clarity-mcp']
}
with open(path, 'w') as f:
    json.dump(data, f, indent=2)
print(f"Wrote 2 MCP servers (google-analytics + microsoft-clarity) to {path}")
PYEOF

echo
echo "Now run: claude mcp list"
echo "Then in Claude Code: ask 'list my GA4 properties' or 'show me yesterday's Clarity sessions'"

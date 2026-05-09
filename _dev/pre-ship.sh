#!/usr/bin/env bash
# Royal MCP pre-ship gate — refuses to exit 0 unless all three gates pass.
#
# Run this before any Royal MCP release. If anything fails, the ship is blocked.
#
# The audit gate (3) is the one that previously didn't exist. Pre-1.4.15 we
# shipped 4 releases in 6 weeks each containing customer-reported bugs that
# the static gates didn't catch. This script ensures behavioral coverage is
# part of every ship from now on.
#
# Usage:
#     ./_dev/pre-ship.sh
#
# Requires:
#     - Local copy of royal-mcp checked out at C:/Users/JaySun/PLUGINS BUSINESS/royal-mcp
#     - gsc.royalplugins.com running the latest code (deploy + reload before running)
#     - python with playwright (for security-scanner) and standard library (for audit)

set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_DIR="$(cd "$PLUGIN_ROOT/.." && pwd)"
GSC_URL="https://gsc.royalplugins.com"

cd "$BASE_DIR"

red()   { printf "\033[31m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
bold()  { printf "\033[1m%s\033[0m\n" "$*"; }

bold "============================================================"
bold "  Royal MCP pre-ship gate"
bold "============================================================"

# ============================================================
# Gate 1 — security-scanner.py
# ============================================================
echo ""
bold "[1/3] security-scanner.py"
if python security-scanner.py royal-mcp > /tmp/rmcp-security.log 2>&1; then
    if grep -q "CRITICAL: 0" /tmp/rmcp-security.log && grep -q "HIGH: 0" /tmp/rmcp-security.log; then
        green "  PASS — 0 critical, 0 high"
        grep -E "(MEDIUM|LOW):" /tmp/rmcp-security.log | head -2
    else
        red "  FAIL — critical or high severity issues found"
        cat /tmp/rmcp-security.log
        exit 1
    fi
else
    red "  FAIL — scanner errored out"
    cat /tmp/rmcp-security.log
    exit 1
fi

# ============================================================
# Gate 2 — audit-plugin.py
# ============================================================
echo ""
bold "[2/3] audit-plugin.py"
if python audit-plugin.py royal-mcp > /tmp/rmcp-audit.log 2>&1; then
    if grep -qE "Critical Issues: 0" /tmp/rmcp-audit.log; then
        green "  PASS — 0 critical"
        grep -E "(Warnings|Info):" /tmp/rmcp-audit.log | head -2
    else
        red "  FAIL — audit found critical issues"
        tail -20 /tmp/rmcp-audit.log
        exit 1
    fi
else
    red "  FAIL — auditor errored out"
    tail -20 /tmp/rmcp-audit.log
    exit 1
fi

# ============================================================
# Gate 3 — behavioral audit against gsc.royalplugins.com
# ============================================================
echo ""
bold "[3/3] behavioral audit against $GSC_URL"
echo "      (assumes the candidate version is already deployed to gsc)"

# Pull the gsc API key via SSH (memory: my.royalplugins-vps wp-cli ban; use raw mysql)
GSC_KEY=$(ssh royalplugins-vps "mysql -u royalplugins -pIhnsH4G0abqMfE5dTcVLriEyH0Z3Ulo gsc_royalplugins -Bse \"SELECT option_value FROM wp_options WHERE option_name = 'royal_mcp_settings'\"" 2>/dev/null \
    | python -c "import sys, re; raw = sys.stdin.read(); m = re.search(r'\"api_key\";s:\d+:\"([^\"]+)\"', raw); print(m.group(1) if m else '')" )

if [ -z "$GSC_KEY" ]; then
    red "  FAIL — couldn't pull API key from gsc DB"
    exit 1
fi

if python "$PLUGIN_ROOT/_dev/audit.py" "$GSC_URL" "$GSC_KEY" > /tmp/rmcp-behavioral.log 2>&1; then
    PASS_COUNT=$(grep -c "^\[OK\]" /tmp/rmcp-behavioral.log || echo 0)
    green "  PASS — $PASS_COUNT tests"
    tail -1 /tmp/rmcp-behavioral.log
else
    red "  FAIL — behavioral audit found regressions"
    cat /tmp/rmcp-behavioral.log
    exit 1
fi

echo ""
bold "============================================================"
green "  ALL THREE GATES PASSED — Royal MCP is clear to ship"
bold "============================================================"
exit 0

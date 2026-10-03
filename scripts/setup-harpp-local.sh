#!/usr/bin/env bash
#
# scripts/setup-harpp-local.sh — enable the local harpp.test host.
#
# Run this YOURSELF:  bash scripts/setup-harpp-local.sh
#
# It needs root, and it is deliberately NOT run by an AI agent. A sudo prompt cannot be
# answered from an agent session, and routing a password through an agent's command text
# would put it in a log. Your password goes into your own terminal and nowhere else.
#
# Idempotent: safe to re-run. It reports what it changed and what was already correct.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="harpp.test"
SRC="$REPO/config/vhosts/${HOST}.conf"
DEST="/etc/apache2/sites-available/${HOST}.conf"

echo "=== HARPP local host setup: ${HOST} ==="

if [ ! -f "$SRC" ]; then
    echo "  ✗ missing $SRC"
    echo "    (it ships in the repo; check you are on the right branch)"
    exit 1
fi

# ── 1. the vhost ─────────────────────────────────────────────────────────────
# Derived from baronledger.test.conf, which is the correct template for a tenant of THIS
# checkout: the same DocumentRoot, because HARPP is served by this app and scoped by host.
if cmp -s "$SRC" "$DEST" 2>/dev/null; then
    echo "  1/3 vhost already installed and identical"
else
    echo "  1/3 installing vhost -> $DEST"
    sudo cp "$SRC" "$DEST" || { echo "  ✗ copy failed"; exit 1; }
fi

# ── 2. enable + reload ───────────────────────────────────────────────────────
if [ -L "/etc/apache2/sites-enabled/${HOST}.conf" ]; then
    echo "  2/3 site already enabled"
else
    echo "  2/3 enabling site"
    sudo a2ensite "${HOST}" >/dev/null || { echo "  ✗ a2ensite failed"; exit 1; }
fi
echo "      reloading apache"
sudo systemctl reload apache2 || { echo "  ✗ reload failed"; exit 1; }

# ── 3. /etc/hosts ────────────────────────────────────────────────────────────
# Without this the hostname does not resolve at all. Apache would answer 400 because no
# vhost matches, which looks like an app fault but is not one.
if grep -qE "^[0-9.]+[[:space:]]+.*\b${HOST}\b" /etc/hosts 2>/dev/null; then
    echo "  3/3 ${HOST} already in /etc/hosts"
else
    echo "  3/3 adding ${HOST} to /etc/hosts"
    echo "127.0.0.1 ${HOST}" | sudo tee -a /etc/hosts >/dev/null || { echo "  ✗ hosts update failed"; exit 1; }
fi

echo
echo "=== verify ==="
code=$(curl -s -o /dev/null -w '%{http_code}' "http://${HOST}/harpp/login" 2>/dev/null)
echo "  http://${HOST}/harpp/login -> HTTP ${code}"
if [ "$code" = "200" ]; then
    echo
    echo "  ✓ ready. Sign in at http://${HOST}/harpp/login"
    echo "    email:    owner@harpp.local"
    echo "    password: admin1234"
else
    echo
    echo "  ! expected 200. If this is 400 the vhost did not load; if 500 check"
    echo "    storage/logs/app.log and error.log."
fi

#!/usr/bin/env bash
# ACCEPTANCE — on a 10-inch tablet the Daily Sheet keeps Product / BEG / ADDTL in view while the
# branch columns scroll sideways, and the ending balance column pins to the right edge.
#
# MEASURED RED before dispatch: with the sheet scrolled, `product.left = -857` at an 800x1280
# viewport — the identifying columns scroll entirely off-screen. So the first check fails for the
# reason the change exists, and "green afterwards" means something.
#
# Checks 2 and 3 are the geometry oracles. `position: sticky` does not remove a cell from layout,
# so a correct fix cannot move a column; if it does, these fail. They are included because the
# obvious wrong fix (giving BEG/ADDTL a fixed width, or padding the pinned cells) would pass
# check 1 and quietly wreck the sheet's rhythm.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${APP_URL:-http://baronledger.test}"
FAILED=0

run() {
    local label="$1" spec="$2"
    echo "== ${label} =="
    ( cd "$ROOT" && APP_URL="$APP" npx playwright test "$spec" --reporter=line ) || FAILED=1
    echo
}

run "1. a 10-inch tablet keeps Product / BEG / ADDTL visible while the sheet scrolls" \
    "tests/browser/daily-ledger-sheet-sticky-columns.spec.js"

run "2. the sheet's column geometry is unchanged (pinning must not move or pad a column)" \
    "tests/browser/daily-ledger-sheet-columns.spec.js"

run "3. the printed sheet is unchanged (sticky must not reach paper)" \
    "tests/browser/daily-ledger-print-daily-sheet.spec.js"

if [ "$FAILED" -eq 0 ]; then echo "ACCEPTANCE: PASS"; else echo "ACCEPTANCE: FAIL"; fi
exit "$FAILED"

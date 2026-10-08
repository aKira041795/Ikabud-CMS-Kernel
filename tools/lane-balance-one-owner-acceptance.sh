#!/usr/bin/env bash
#
# ACCEPTANCE GATE — the production balance formula must be written ONCE, and behave identically.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-balance-one-owner.contract.md
#
# WHY. `beg + produced - dispatched - wastage` is written in SIX places in
# modules/daily-ledger/handlers.php (audit §7.4 item 3):
#
#   SQL, `book_balance` alias : 3898, 4736, 20150  (byte-identical)
#                               19356               (`cpl.`-prefixed variant)
#   PHP arithmetic            : 2869  ($movements)
#                               3617  (remaining_qty)
#
# A financial formula written six times has six chances to drift, and a drift here is wrong money.
# This gate is PAIRED, deliberately:
#
#   CRITERION 1 (structural)  the arithmetic appears in at most TWO places - the two helpers.
#                             RED on base (it is written 6 times). This is what the work achieves.
#   CRITERION 2 (equivalence) the helpers return the ORIGINAL expressions BYTE-FOR-BYTE, checked
#                             against a baseline frozen before any edit.
#
# Criterion 1 alone could be satisfied by DELETING the arithmetic, which would be catastrophic on a
# balance. Criterion 2 is therefore not optional, and it is what makes criterion 1 safe.
#
# Frozen baseline (captured before the change):
#   /tmp/chair-freeze/expressions-before.txt  sha1 5a586ac6710533ba6171f7f3929bdedbf5202f59
#   /tmp/chair-freeze/read-before.json        sha1 8d087dad697efaaec8ccbdb1e56aced915eb706b
#
# The frozen read-path value is NON-TRIVIAL (book_balance = -3, the consignee depletion effect), so
# the differential discriminates rather than comparing zeros.
#
# Usage:  bash tools/lane-balance-one-owner-acceptance.sh
# Exit 0 = formula written once AND behaviour byte-identical.  Exit 1 = otherwise.

set -u

cd /var/www/html/applicationostest || exit 1

HANDLERS="modules/daily-ledger/handlers.php"
FREEZE="/tmp/chair-freeze"
BASELINE_EXPR_SHA="5a586ac6710533ba6171f7f3929bdedbf5202f59"
BASELINE_READ_SHA="8d087dad697efaaec8ccbdb1e56aced915eb706b"

fails=0

pass() { printf '  PASS  %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; fails=$((fails + 1)); }

echo "== acceptance: the balance formula is written once, and behaves identically =="

# ---------------------------------------------------------------------------------------------
# 0. Preconditions. A differential with no baseline proves nothing.
# ---------------------------------------------------------------------------------------------
if [ ! -f "$FREEZE/expressions-before.txt" ] || [ ! -f "$FREEZE/read-before.json" ]; then
  fail "the frozen baseline is missing from $FREEZE - the differential cannot run"
  echo
  echo "ACCEPTANCE: FAIL (no baseline)"
  exit 1
fi

if [ ! -f "$HANDLERS" ]; then
  fail "handlers.php not found"
  echo
  echo "ACCEPTANCE: FAIL"
  exit 1
fi

actual_expr_sha=$(sha1sum "$FREEZE/expressions-before.txt" | awk '{print $1}')
if [ "$actual_expr_sha" != "$BASELINE_EXPR_SHA" ]; then
  fail "the frozen expression baseline CHANGED (sha1 $actual_expr_sha != $BASELINE_EXPR_SHA)"
  echo "        The baseline must be immutable. Something rewrote it, so this run is void."
  echo
  echo "ACCEPTANCE: FAIL"
  exit 1
fi

# ---------------------------------------------------------------------------------------------
# 1. STRUCTURAL — the arithmetic is written in at most TWO places (the two helpers).
#    Counted in any form: bare, prefixed, or `(int)$row['beg_qty']` style.
#
#    COMMENT lines are excluded. Prose that NAMES the formula (lines 2704, 2858 on base) is
#    documentation, not a site - counting it would over-report the base state (8 instead of 6)
#    and could false-red a correct tree after the change. Measured: excluding comments gives
#    exactly the 6 real sites on base.
# ---------------------------------------------------------------------------------------------
SITES_PATTERN="beg_qty'?\]? *\+ *(\(int\)\\\$row\[')?produced_qty|beg_qty \+ produced_qty|cpl\.beg_qty \+ cpl\.produced_qty|newProduced - \\\$newDispatched|\\\$beg \+ \\\$produced - \\\$dispatched - \\\$wastage"

write_sites=$(grep -nE "$SITES_PATTERN" "$HANDLERS" \
  | grep -vE ':[[:space:]]*(//|\*|/\*)' \
  | wc -l)

bare=$(grep -c "beg_qty + produced_qty - dispatched_qty - wastage_qty" "$HANDLERS" || true)
prefixed=$(grep -c "cpl.beg_qty + cpl.produced_qty - cpl.dispatched_qty - cpl.wastage_qty" "$HANDLERS" || true)

printf '  write sites: %s (bare literal %s incl. comments, cpl-prefixed %s)\n' "$write_sites" "$bare" "$prefixed"
echo "        base state is 6: four SQL aliases (3898, 4736, 19356, 20150) and two PHP expressions (2869, 3617)"

if [ "$write_sites" -le 2 ]; then
  pass "the balance arithmetic is written in ${write_sites} place(s) (the helpers) - one owner"
else
  fail "the balance arithmetic is still written in ${write_sites} place(s); want <= 2"
  echo "        (base state: 6 - four SQL aliases and two PHP expressions)"
fi

# ---------------------------------------------------------------------------------------------
# 2. EQUIVALENCE — the helpers must emit the ORIGINAL text byte-for-byte.
#    Without this, criterion 1 could be met by changing the arithmetic.
# ---------------------------------------------------------------------------------------------
PROBE="/tmp/acc-balance-equivalence.php"
cat > "$PROBE" <<'PHP_EOF'
<?php

declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');
$db = app()->dbForTenant(207);

$problems = [];

// --- 2a. The SQL fragment helper must reproduce the frozen fragments exactly. ------------------
// The originals, verbatim from the frozen baseline (parenthesised, as they appear in the queries).
$WANT_BARE     = '(beg_qty + produced_qty - dispatched_qty - wastage_qty)';
$WANT_PREFIXED = '(cpl.beg_qty + cpl.produced_qty - cpl.dispatched_qty - cpl.wastage_qty)';

if (!function_exists('dl_productionBalanceSql')) {
    $problems[] = 'dl_productionBalanceSql() does not exist - the SQL sites have no owner';
} else {
    $gotBare = dl_productionBalanceSql('');
    if ($gotBare !== $WANT_BARE) {
        $problems[] = "dl_productionBalanceSql('') = [{$gotBare}] want [{$WANT_BARE}]";
    }
    $gotPrefixed = dl_productionBalanceSql('cpl.');
    if ($gotPrefixed !== $WANT_PREFIXED) {
        $problems[] = "dl_productionBalanceSql('cpl.') = [{$gotPrefixed}] want [{$WANT_PREFIXED}]";
    }
}

// --- 2b. The PHP helper must compute the same value for the real row and for the edges. --------
if (!function_exists('dl_productionBalance')) {
    $problems[] = 'dl_productionBalance() does not exist - the PHP sites have no owner';
} else {
    $cases = [
        [0, 0, 3, 0, -3],      // the real row: consignee depletion
        [10, 5, 3, 2, 10],
        [0, 0, 0, 0, 0],
        [-5, 0, 0, 0, -5],
        [100, 0, 250, 0, -150],
    ];
    foreach ($cases as [$b, $p, $d, $w, $want]) {
        $got = dl_productionBalance($b, $p, $d, $w);
        if ($got !== $want) {
            $problems[] = "dl_productionBalance({$b},{$p},{$d},{$w}) = {$got} want {$want}";
        }
    }
}

// --- 2c. The read-only formula site must return the SAME output as the frozen baseline. --------
$readRow = null;
if (function_exists('dl_readCommissaryProductLedgerRow')) {
    try {
        $readRow = dl_readCommissaryProductLedgerRow($db, 18, 53, '2026-10-07', 'AM');
    } catch (Throwable $e) {
        $problems[] = 'dl_readCommissaryProductLedgerRow threw: ' . $e->getMessage();
    }
} else {
    $problems[] = 'dl_readCommissaryProductLedgerRow() is missing';
}

$frozen = json_decode((string)file_get_contents('/tmp/chair-freeze/read-before.json'), true);
$frozenRow = $frozen['result'] ?? null;
if ($readRow === null || $frozenRow === null) {
    $problems[] = 'could not compare the read-path output';
} else {
    // Compare as canonical JSON so key order cannot create a false difference.
    $a = $readRow; $b = $frozenRow;
    ksort($a); ksort($b);
    if (json_encode($a) !== json_encode($b)) {
        $problems[] = 'read-path output CHANGED: now ' . json_encode($readRow)
                    . ' was ' . json_encode($frozenRow);
    }
    // The number that actually matters on a balance.
    if ((int)($readRow['book_balance'] ?? 0) !== (int)($frozenRow['book_balance'] ?? 0)) {
        $problems[] = 'book_balance CHANGED: now ' . ($readRow['book_balance'] ?? 'null')
                    . ' was ' . ($frozenRow['book_balance'] ?? 'null');
    }
}

if ($problems === []) {
    echo "VERDICT=PASS\n";
    exit(0);
}

echo "VERDICT=FAIL\n";
foreach ($problems as $p) {
    echo "  - {$p}\n";
}
exit(1);
PHP_EOF

eq_out=$(php "$PROBE" 2>&1)
eq_code=$?

if [ "$eq_code" -eq 0 ]; then
  pass "equivalence: helpers emit the ORIGINAL expressions; the read path returns book_balance unchanged"
else
  fail "equivalence: behaviour is NOT identical to the frozen baseline"
  printf '%s\n' "$eq_out" | sed 's/^/        /'
fi

# ---------------------------------------------------------------------------------------------
# 3. The differential itself must be able to discriminate.
# ---------------------------------------------------------------------------------------------
if grep -q '"book_balance": -3' "$FREEZE/read-before.json"; then
  pass "the frozen read-path value is non-trivial (book_balance -3), so the differential discriminates"
else
  fail "the frozen read-path value looks trivial; the differential may not discriminate"
fi

# ---------------------------------------------------------------------------------------------
# 4. Syntax + scope.
# ---------------------------------------------------------------------------------------------
if php -l "$HANDLERS" >/dev/null 2>&1; then
  pass "handlers.php has no syntax errors"
else
  fail "handlers.php has a syntax error"
fi

unexpected=$(git status --porcelain 2>/dev/null \
  | awk '{print $NF}' \
  | grep -vE '^(modules/daily-ledger/handlers\.php|tools/lane-balance[^/]*|tools/chair-[^/]*|\.ai/[^/]*|docs/reviews/windows-desktop-client-feasibility-2026-10-07\.md)$' \
  || true)
if [ -n "$unexpected" ]; then
  fail "scope: unexpected changed path(s):"
  printf '%s\n' "$unexpected" | sed 's/^/        /'
else
  pass "scope: only handlers.php changed"
fi

echo
if [ "$fails" -eq 0 ]; then
  echo "ACCEPTANCE: PASS (one owner for the balance formula, behaviour byte-identical)"
  exit 0
fi

echo "ACCEPTANCE: FAIL (${fails} criterion/criteria)"
exit 1

#!/usr/bin/env bash
#
# ACCEPTANCE GATE — consignees carry an editable Order, and it governs their display order.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-sort-order.contract.md
#
# OWNER REQUIREMENT (2026-10-08, verbatim):
#   "adding a consignee must be sortable in order, so we need an Order field. default zero.
#    if 1 exists, zero value does after any order with numeric data other than 0"
#
# So the rule is: NON-ZERO orders sort first, ascending; zeros sort LAST. That is what the owner
# asked for, and it is also the useful behaviour - a newly added consignee (default 0) must not
# leapfrog ahead of ones that were deliberately ordered.
#
#   ORDER BY (sort_order = 0) ASC, sort_order ASC, <existing tiebreak>
#            ^ non-zero rows yield 0 here, so they come first
#
# THE DISCRIMINATING CRITERION is the ordering OUTCOME, not the presence of a column. It is
# exercised by inserting consignees at orders 0, 5 and 2 inside a TRANSACTION and asserting the
# sequence comes back as 2, 5, then the zeros. Rolled back and the row count re-asserted.
#
# On the unchanged tree the column does not exist, so the ordering cannot be exercised at all:
#   RED.
#
# Usage:  bash tools/lane-consignee-sort-order-acceptance.sh
# Exit 0 = Order exists, defaults to 0, and governs ordering.  Exit 1 = otherwise.

set -u

cd /var/www/html/applicationostest || exit 1

HANDLERS="modules/daily-ledger/handlers.php"
TEMPLATE="templates/modules/daily-ledger/admin/branches.disyl"
MODULE_JSON="modules/daily-ledger/module.json"

fails=0
pass() { printf '  PASS  %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; fails=$((fails + 1)); }

echo "== acceptance: consignee Order field governs sort order =="

# ---------------------------------------------------------------------------------------------
# 0. Preconditions.
# ---------------------------------------------------------------------------------------------
for f in "$HANDLERS" "$TEMPLATE" "$MODULE_JSON"; do
  if [ ! -f "$f" ]; then
    fail "missing $f"
  fi
done
if [ "$fails" -gt 0 ]; then
  echo
  echo "ACCEPTANCE: FAIL (missing files)"
  exit 1
fi

# ---------------------------------------------------------------------------------------------
# 1. SCHEMA — the migration exists, is REGISTERED (or it will never run on the live tenant),
#    and the column is present with a default of 0.
# ---------------------------------------------------------------------------------------------
migration=$(ls modules/daily-ledger/database/migrations/ 2>/dev/null | grep -E '^087_.*sort' | head -1)
if [ -n "$migration" ]; then
  pass "migration present: 087 ($migration)"
else
  fail "no migration 087_add_*sort*_order*.sql found"
  migration=$(ls modules/daily-ledger/database/migrations/ 2>/dev/null | grep -E '^087_' | head -1)
fi

if [ -n "$migration" ]; then
  if grep -q "$migration" "$MODULE_JSON"; then
    pass "migration is registered in module.json (so tenant:migrate will run it on live)"
  else
    fail "migration $migration is NOT registered in module.json - it would never run on the tenant"
  fi
  # A migration that is not guarded will fail on tenants that already have the column.
  if grep -qiE "information_schema|SHOW COLUMNS|IF NOT EXISTS" "$(dirname "$HANDLERS")/database/migrations/$migration"; then
    pass "migration is guarded (safe to re-run)"
  else
    fail "migration does not look guarded - a re-run would fail on an existing column"
  fi
fi

coltype=$(mysql -uroot baronledger -N -e "SHOW COLUMNS FROM dl_consignees LIKE 'sort_order';" 2>/dev/null \
  | awk '{print $1" "$2" "$3" "$4" "$5}' | sed 's/ NO / NOT NULL /')
if [ -n "$coltype" ]; then
  pass "dl_consignees.sort_order exists: $coltype"
  case "$coltype" in
    *"NOT NULL"*) : ;;
    *) fail "sort_order should be NOT NULL" ;;
  esac
  case "$coltype" in
    *"0"*) : ;;
    *) fail "sort_order should default to 0" ;;
  esac
else
  fail "dl_consignees.sort_order does not exist (migration not applied to this tenant)"
fi

# ---------------------------------------------------------------------------------------------
# 2. ORDERING OUTCOME — the owner's rule, exercised on real rows inside a transaction.
# ---------------------------------------------------------------------------------------------
PROBE="/tmp/acc-consignee-order.php"
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

if (!function_exists('dl_entityOrderBySql')) {
    echo "VERDICT=FAIL\n  - dl_entityOrderBySql() does not exist: the ordering rule has no owner\n";
    exit(1);
}

// --- The helper must express the owner's rule, and only once. ---------------------------------
// The rule already exists verbatim for branches (handlers.php:19558-19566): non-zero ascending
// first, zeros LAST so a newly added row can never jump to the front.
$orderBy = dl_entityOrderBySql('c.');
$want = '(c.sort_order = 0) ASC, c.sort_order ASC, c.name ASC';
if ($orderBy !== $want) {
    $problems[] = "dl_entityOrderBySql('c.') = [{$orderBy}] want [{$want}]";
}

$before = (int)$db->query('SELECT COUNT(*) FROM dl_consignees')->fetchColumn();

$db->beginTransaction();
try {
    $ins = $db->prepare(
        'INSERT INTO dl_consignees (code, name, area, address, assigned_commissary_id, price_group_id, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, NULL, 1, ?)'
    );
    // Deliberately inserted out of order, and with a leading-zero row, to prove the rule rather
    // than an accidental insertion sequence.
    $ins->execute(['ZZORDER5', 'Order Probe Five',  'X', 'X', 18, 5]);
    $ins->execute(['ZZORDER2', 'Order Probe Two',   'X', 'X', 18, 2]);
    $ins->execute(['ZZORDER0', 'Order Probe Zero',  'X', 'X', 18, 0]);

    $rows = $db->query(
        "SELECT code, name, sort_order, id FROM dl_consignees c ORDER BY {$orderBy}"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sequence = array_map(static fn(array $r): string => (string)$r['code'], $rows);

    // Independent expectation computed in PHP, not by re-reading the SQL:
    //   non-zero ascending first, then zeros.
    $all = $db->query('SELECT code, name, sort_order, id FROM dl_consignees')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $nonZero = $all; $zeros = [];
    foreach ($all as $r) {
        if ((int)$r['sort_order'] === 0) { $zeros[] = $r; }
    }
    $nonZero = array_values(array_filter($all, static fn(array $r): bool => (int)$r['sort_order'] !== 0));
    usort($nonZero, static fn(array $a, array $b): int => [(int)$a['sort_order'], (string)$a['name'], (int)$a['id']] <=> [(int)$b['sort_order'], (string)$b['name'], (int)$b['id']]);
    usort($zeros, static fn(array $a, array $b): int => [(string)$a['name'], (int)$a['id']] <=> [(string)$b['name'], (int)$b['id']]);
    $expected = array_map(static fn(array $r): string => (string)$r['code'], array_merge($nonZero, $zeros));

    if ($sequence !== $expected) {
        $problems[] = 'order mismatch: got [' . implode(', ', $sequence) . '] want [' . implode(', ', $expected) . ']';
    }

    // The rule stated directly: every non-zero must precede every zero.
    $seenZero = false;
    foreach ($sequence as $code) {
        $isZero = str_ends_with($code, '0') && $code !== '';
        if ($code === 'ZZORDER0') { $seenZero = true; continue; }
        if ($seenZero && ($code === 'ZZORDER2' || $code === 'ZZORDER5')) {
            $problems[] = "non-zero {$code} sorted AFTER a zero order value";
        }
    }

    // Non-zero must be ascending among themselves.
    $pos = array_flip($sequence);
    if (isset($pos['ZZORDER2'], $pos['ZZORDER5']) && $pos['ZZORDER2'] > $pos['ZZORDER5']) {
        $problems[] = 'order 2 did not sort before order 5';
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$after = (int)$db->query('SELECT COUNT(*) FROM dl_consignees')->fetchColumn();
if ($after !== $before) {
    $problems[] = "rollback leaked: before={$before} after={$after}";
}

// --- Persistence: a create/update must round-trip the value. ----------------------------------
$db->beginTransaction();
try {
    $db->prepare(
        'INSERT INTO dl_consignees (code, name, area, address, assigned_commissary_id, price_group_id, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, NULL, 1, ?)'
    )->execute(['ZZPERSIST', 'Persist Probe', 'X', 'X', 18, 7]);

    $got = (int)$db->query("SELECT sort_order FROM dl_consignees WHERE code = 'ZZPERSIST'")->fetchColumn();
    if ($got !== 7) {
        $problems[] = "a new consignee did not keep its sort_order (got {$got}, want 7)";
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($problems === []) {
    echo "VERDICT=PASS\n";
    exit(0);
}
echo "VERDICT=FAIL\n";
foreach ($problems as $p) { echo "  - {$p}\n"; }
exit(1);
PHP_EOF

order_out=$(php "$PROBE" 2>&1)
order_code=$?

if [ "$order_code" -eq 0 ]; then
  pass "ordering rule holds: non-zero ascending first, zeros last (rollback clean)"
else
  fail "the ordering rule does not hold"
  printf '%s\n' "$order_out" | sed 's/^/        /'
fi

# ---------------------------------------------------------------------------------------------
# 3. ONE OWNER — the ORDER RULE must be written once, not at each listing site.
#
#    Scoped to the RULE `(x.sort_order = 0)`, NOT to `sort_order` in general: dl_products and
#    dl_raw_materials legitimately use plain `ORDER BY sort_order, name` in ~13 places, and
#    counting those would false-red a correct tree for ever.
#
#    Measured on base: the rule is hardcoded TWICE - 17436 (branches admin list) and 19566
#    (Daily Sheet branch columns) - so branches already set the precedent and no helper owns it.
# ---------------------------------------------------------------------------------------------
rule_sites=$(grep -nE "\(.*sort_order = 0\)" "$HANDLERS" \
  | grep -vE ':[[:space:]]*(//|\*|/\*)' \
  | wc -l)
helper_calls=$(grep -c "dl_entityOrderBySql(" "$HANDLERS" || true)
printf '  ordering rule: %s hardcoded site(s), %s helper call(s)\n' "$rule_sites" "$helper_calls"

echo "        base state is 2 hardcoded: 17436 (branches list) and 19566 (sheet branch columns)"

if [ "$rule_sites" -le 1 ]; then
  pass "the sort rule is written once (inside the helper)"
else
  fail "the sort rule is hardcoded in ${rule_sites} place(s); it must live in one helper"
fi

# Branches (2 sites) + consignees (the listing sites) should all route through it.
if [ "$helper_calls" -ge 4 ]; then
  pass "${helper_calls} call site(s) use the helper"
else
  fail "only ${helper_calls} call site(s) use the helper; branch and consignee listings should"
fi

# ---------------------------------------------------------------------------------------------
# 4. UI WIRING — present in the template source, SCOPED TO CONSIGNEE CODE.
#
#    The branch form already contains `sort_order` and a `sortOrder` parameter (openEditBranch,
#    edit-sort, line 488). An unscoped grep passes on the BRANCH code and would report a green
#    result for an unchanged template. Each check below therefore extracts only the consignee
#    region / function first.
#
#    The chair verifies it RENDERS in a browser: storage/cache/compiled is www-data-owned, so
#    CLI cannot compile an edited template.
# ---------------------------------------------------------------------------------------------
# The consignee modal + list live between the consignees panel and the openConsignee definition.
sed -n '/id="consignees-panel"/,/function openConsignee/p' "$TEMPLATE" > /tmp/acc-consignee-region.txt
sed -n '/function saveConsignee/,/^    }/p' "$TEMPLATE" > /tmp/acc-consignee-save.txt

if grep -q "consignee-sort-order" /tmp/acc-consignee-region.txt; then
  pass "the consignee modal has an Order input (#consignee-sort-order)"
else
  fail "no #consignee-sort-order input in the consignee modal"
fi

if grep -qE "^\s*sort_order: parseInt" /tmp/acc-consignee-save.txt; then
  pass "saveConsignee sends sort_order"
else
  fail "saveConsignee does not send sort_order - the field could not be saved"
fi

if grep -qE "function openConsignee\([^)]*sortOrder" /tmp/acc-consignee-region.txt \
   || sed -n '/function openConsignee/,/^    }/p' "$TEMPLATE" | grep -qE "function openConsignee\([^)]*sortOrder"; then
  pass "openConsignee carries a sortOrder parameter so Edit populates the field"
else
  fail "openConsignee has no sortOrder parameter - Edit would reset the order to 0"
fi

# The list must expose the value, or the Edit button cannot pass it in.
if grep -qE "openConsignee\([^)]*sort_order" /tmp/acc-consignee-region.txt; then
  pass "the consignee list passes sort_order into openConsignee"
else
  fail "the Edit button does not pass sort_order - the saved order would not load back"
fi

# The documented DiSyL trap: a single-line JS object literal whose first key is a bare
# identifier is consumed as a DiSyL tag. The save body must stay multi-line.
if grep -q "Keep this object literal multi-line" "$TEMPLATE"; then
  pass "the documented DiSyL multi-line-object-literal guard is still in place"
else
  fail "the DiSyL object-literal guard comment is gone - the save body may have been inlined"
fi

# ---------------------------------------------------------------------------------------------
# 5. Syntax.
# ---------------------------------------------------------------------------------------------
if php -l "$HANDLERS" >/dev/null 2>&1; then
  pass "handlers.php has no syntax errors"
else
  fail "handlers.php has a syntax error"
fi

echo
if [ "$fails" -eq 0 ]; then
  echo "ACCEPTANCE: PASS (consignee Order exists, defaults to 0, and governs display order)"
  exit 0
fi

echo "ACCEPTANCE: FAIL (${fails} criterion/criteria)"
exit 1

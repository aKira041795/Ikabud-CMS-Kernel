#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 11d (daily-ledger POS — FINAL dl batch).
#
# PASS = the scoped daily-ledger census reports write_enforced=66 (59 from slices 3/7/11/11b/11c +
# this slice's 7 POS writes: mode/select, cart save/abandon, checkout [cashier,supervisor,admin];
# sales void/refund, fallback [supervisor,admin]) with NO delegated drift. 66 is the complete
# declarable write set: the remaining 4 (cashier/ledger withdrawals, dispatch, receive-delivery,
# receive-paper-dr) are auth-only (dlCurrentUser() with no role set) and cannot carry role
# declarations without changing handler behavior.
# Base after slice 11c lands: write_enforced=59 (verify live at dispatch — the gate proves it).
#
# Runtime note: scoped daily-ledger census is ~53s.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("daily-ledger");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . "\n";
exit(((int)$s["dispatch_enforced"] === 66 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

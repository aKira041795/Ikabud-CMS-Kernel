#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 11b (daily-ledger cashier family).
#
# PASS = the scoped daily-ledger census reports write_enforced=44 (33 from slices 3/7/11 + this
# slice's 11: cashier/ledger save/save-batch/carry-beginnings/finalize-pm/withdrawals-edit +
# delivery-edit + offline enroll/revoke/reconcile [cashier,supervisor,admin]; close-day
# [cashier,supervisor,admin,production_in_charge]; products/toggle
# [cashier,supervisor,production_in_charge,admin]) with NO delegated drift.
# Base after slice 11 lands: write_enforced=33 (verify live at dispatch — the gate proves it).
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
exit(((int)$s["dispatch_enforced"] === 44 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

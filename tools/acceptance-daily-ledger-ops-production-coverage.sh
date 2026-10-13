#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 11 (daily-ledger operations + production).
#
# PASS = the scoped daily-ledger census reports write_enforced >= 33 (21 slice-3/7 + this
# slice's 12: reopen-day/variances/reconciliation [admin,supervisor], production x4 +
# commissary run/material/dispatch [admin,supervisor,production_in_charge],
# carry-beginnings [.. auditor], finalize-pm [admin,production_in_charge]) with NO delegated drift.
# FAILS before the slice (write_enforced=21 — verified at dispatch).
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
exit(((int)$s["dispatch_enforced"] === 33 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

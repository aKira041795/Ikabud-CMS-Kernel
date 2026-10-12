#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 3 (daily-ledger user/role admin).
#
# PASS = the scoped daily-ledger census reports write_enforced >= 5, i.e. the five
# declared admin write routes (dl-users create/update/delete/restore + settings/permissions)
# are dispatch-enforced.
# FAILS on the pre-declaration base (write_enforced=0, rc=1 — verified 2026-10-13).
#
# Runtime note: scoped daily-ledger census is ~53s (measured 2026-10-13).
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("daily-ledger");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"] . "\n";
exit(($s["dispatch_enforced"] >= 5) ? 0 : 1);
'

#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 10 (attendance-wage manage + approve).
#
# PASS = the scoped attendance-wage census reports write_enforced >= 34 (9a/9b's 25 + this
# slice's 9: 3 manage@1 writes [admin,supervisor] + 6 approve@1 writes [admin]) with NO
# delegated drift.
# FAILS on base (write_enforced=25 after 9b, rc=1 — verified).
#
# Runtime note: scoped attendance-wage census is ~0.5s.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("attendance-wage");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . "\n";
exit(((int)$s["dispatch_enforced"] === 34 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

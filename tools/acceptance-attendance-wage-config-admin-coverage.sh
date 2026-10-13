#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 9b (attendance-wage config admin).
#
# PASS = the scoped attendance-wage census reports write_enforced >= 25 (9a's 12 + this
# batch's 13: holidays, locations, settings management) with NO delegated drift.
# FAILS on base (write_enforced=0 pre-9a / 12 post-9a — fails either way).
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
exit(((int)$s["dispatch_enforced"] === 25 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

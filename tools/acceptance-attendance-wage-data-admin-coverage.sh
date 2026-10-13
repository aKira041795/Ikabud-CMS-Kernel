#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 9a (attendance-wage data admin).
#
# PASS = the scoped attendance-wage census reports write_enforced >= 12 (the workforce/data
# admin set: records, employees, periods, compute, schedules, migration) with NO delegated drift.
# FAILS on base (write_enforced=0, rc=1 — verified 2026-10-13).
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
exit(((int)$s["dispatch_enforced"] === 12 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 2 (cms entity-capability writes).
#
# PASS = the scoped cms census reports write_enforced >= 3, i.e. the three declared
# entity-capability write routes (attach / preset / detach) are dispatch-enforced.
# FAILS on the pre-declaration base (write_enforced=0, rc=1 — verified 2026-10-13).
#
# Runtime note: scoped cms census is ~164s (tokenizes the whole module). Under the
# harness 300s acceptance cap, but not cheap — run it as the gate, not per-edit.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("cms");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"] . "\n";
exit(($s["dispatch_enforced"] >= 3) ? 0 : 1);
'

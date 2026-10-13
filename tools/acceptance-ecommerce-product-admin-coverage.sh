#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 6 (ecommerce product admin writes).
#
# PASS = the scoped ecommerce census reports write_enforced >= 4 with NO delegated drift
# (the 4 declared product routes: admin form create/edit + API create/update).
# FAILS on the pre-declaration base (write_enforced=0, rc=1 — verified 2026-10-13).
#
# Runtime note: scoped ecommerce census is ~55s.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("ecommerce");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . "\n";
exit(((int)$s["dispatch_enforced"] === 4 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

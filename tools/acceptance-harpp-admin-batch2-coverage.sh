#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 8 (harpp admin batch 2).
#
# PASS = the scoped harpp census reports write_enforced >= 20 (slice-5's 7 + the
# batch-2 13 harpp.manage writes) with NO delegated drift.
# FAILS on base (write_enforced=7 after slice 5, rc=1 — verified).
#
# Runtime note: scoped harpp census is ~0.5s.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("harpp");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . "\n";
exit(((int)$s["dispatch_enforced"] === 20 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

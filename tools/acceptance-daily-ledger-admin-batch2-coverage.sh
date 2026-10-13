#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 7 (daily-ledger admin batch 2).
#
# PASS = the scoped daily-ledger census reports write_enforced >= 21 (slice-3's 5 + the
# batch-2 16 exact-admin writes) with NO delegated drift.
# FAILS on base (write_enforced=5 after slice 3, rc=1 — verified).
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
exit(((int)$s["dispatch_enforced"] === 21 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

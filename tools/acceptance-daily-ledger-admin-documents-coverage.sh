#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 11c (daily-ledger admin documents).
#
# PASS = the scoped daily-ledger census reports write_enforced=59 (44 from slices 3/7/11/11b +
# this slice's 15: branch-supply-rules + deliveries create/post/correct/consignee-return/void +
# change-destination + receivings/void [admin,supervisor]; review-provenance + receivings
# create/post [admin,supervisor,production_in_charge]; price-groups x2 + assign-branch +
# product-prices [admin]) with NO delegated drift.
# Base after slice 11b lands: write_enforced=44 (verify live at dispatch — the gate proves it).
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
exit(((int)$s["dispatch_enforced"] === 59 && (int)$s["delegated_bound"] === 0) ? 0 : 1);
'

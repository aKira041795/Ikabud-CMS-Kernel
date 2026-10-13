#!/usr/bin/env bash
#
# Acceptance — 6.3 delegated-mode proof slice (cms settings route, chair ruling 2026-10-13).
#
# PASS = (1) executable evidence: the delegated enforcement test passes, AND
#        (2) census: exactly one bound delegation on cms with NO dispatch inflation
#            (dispatch_enforced stays exactly 3 — slice 2's role-mode set).
# FAILS on base: the delegated test does not exist and no delegated declaration is
# present (delegated_bound=0).
#
# Runtime note: scoped cms census ~164s.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! php tests/cms_delegated_settings_route_test.php > /tmp/cms-delegated-test.out 2>&1; then
    echo "executable evidence FAILED:"
    tail -5 /tmp/cms-delegated-test.out
    exit 1
fi

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("cms");
$s = $r["modules"][0]["summary"];
echo "delegated_bound=" . $s["delegated_bound"] . " delegated_unbound=" . $s["delegated_unbound"]
    . " delegated_invalid=" . $s["delegated_invalid"] . " dispatch_enforced=" . $s["dispatch_enforced"] . "\n";
exit(((int)$s["delegated_bound"] === 1 && (int)$s["delegated_unbound"] === 0
    && (int)$s["delegated_invalid"] === 0 && (int)$s["dispatch_enforced"] === 3) ? 0 : 1);
'

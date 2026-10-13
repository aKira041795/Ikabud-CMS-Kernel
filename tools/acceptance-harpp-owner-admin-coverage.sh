#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 5 (harpp owner/admin controls).
#
# PASS = the scoped harpp census reports write_enforced >= 8 AND read_enforced >= 2
# (the declared admin write set + the admin-gated API reads), with NO delegated drift
# (delegated_bound stays 0 — this slice declares in role mode only).
# FAILS on the pre-declaration base (write_enforced=0 read_enforced=0, rc=1 — verified 2026-10-13).
#
# Runtime note: scoped harpp census is ~0.5s.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("harpp");
$s = $r["modules"][0]["summary"];
// Arithmetic: 10 routes declared (8 writes + 2 admin-gated reads). The census
// business denominator deliberately EXCLUDES infrastructure routes matching
// login|logout|refresh|forgot-password|reset-password|session (isBusiness), so
// POST /api/v1/harpp/users/{id}/reset-password is enforced but not counted in the
// business figure: expected write_enforced 7 + that one op asserted below.
$resetEnforced = false;
foreach ($r["modules"][0]["operations"] as $op) {
    if ($op["method"] === "POST" && $op["route"] === "/api/v1/harpp/users/{id}/reset-password" && $op["dispatch"] === "enforced") {
        $resetEnforced = true;
    }
}
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . " reset_password_op=" . ($resetEnforced ? "enforced" : "MISSING") . "\n";
exit(((int)$s["dispatch_enforced"] === 7 && (int)$s["read_dispatch_enforced"] === 2
    && (int)$s["delegated_bound"] === 0 && $resetEnforced) ? 0 : 1);
'

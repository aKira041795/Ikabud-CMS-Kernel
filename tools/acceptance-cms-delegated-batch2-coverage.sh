#!/usr/bin/env bash
#
# Acceptance gate — 6.3 Authority Enforcement Coverage SLICE 12 (cms delegated batch 2).
#
# PASS = the scoped cms census reports delegated_bound=10 (1 from slice 4b + 9 new: settings.manage
# x6 [settings/reset, permissions, permissions/reset, themes/upload, themes/activate,
# themes/{slug}/delete] + customizer.manage x3 [customizer/presets/apply,
# customizer/{scope}/{section}, customizer/{section}]) with NO delegated_unbound/invalid drift and
# write_enforced unchanged at 3.
# Base before the slice: delegated_bound=1 (verify live at dispatch — the gate proves it).
#
# Runtime note: scoped cms census is ~164s — run the dispatch DETACHED (setsid nohup) so the
# terminal wrapper cannot kill the gate mid-run.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r '
require "kernel/Workbench/Governance/GovernanceCensus.php";
$r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("cms");
$s = $r["modules"][0]["summary"];
echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"]
    . " delegated_bound=" . $s["delegated_bound"] . "\n";
exit(((int)$s["delegated_bound"] === 10 && (int)$s["delegated_unbound"] === 0
    && (int)$s["delegated_invalid"] === 0 && (int)$s["dispatch_enforced"] === 3) ? 0 : 1);
'

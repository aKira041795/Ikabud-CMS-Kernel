#!/usr/bin/env bash
#
# Chair-owned pre-dispatch criterion for the `harpp-attachments` lane.
#
# HARPP must accept a FILE from the owner (uploaded from a phone, away from the workstation)
# and let the harness fetch it - that is what makes it competitive with an IDE away from the
# workstation: attach a spec, a receipt, a photo, a CSV, and the harness can use it.
#
# exit 0 = attachments are wired on BOTH sides (owner upload + bridge fetch)  -> the target
# exit 1 = at least one side is missing                                      -> the base
#
# DELIBERATELY NAME-TOLERANT. It does not assert the exact paths, because the lane may
# reasonably name them differently and a criterion that dictates the route string is the
# false-red class this repo has already paid for four times. It asserts only that:
#   * the OWNER side exposes at least one POST route containing "attachment"
#   * the BRIDGE side exposes at least one GET route containing "attachment"
# Those are the two outcomes. Everything else - naming, shape, storage layout - is the lane's.
#
# MUST-ALLOW CONTROL (run by the chair, not by the lane):
#   bash tools/harpp-attachments-probe.sh message
#   must exit 1 and list message routes - otherwise the probe matches nothing at all and a
#   green result would prove only that it cannot see anything.
#
set -u
cd "$(dirname "$0")/.." || exit 1

HARPP_PROBE_NEEDLE="${1:-attachment}" php -r '
$needle = strtolower(getenv("HARPP_PROBE_NEEDLE") ?: "attachment");
$routes = require "modules/harpp/routes.php";

$owner  = [];
$bridge = [];
foreach (["GET", "POST", "PUT", "PATCH", "DELETE"] as $method) {
    foreach (array_keys($routes[$method] ?? []) as $path) {
        if (stripos($path, $needle) === false) {
            continue;
        }
        if (strpos($path, "/bridge/") !== false) {
            if ($method === "GET") { $bridge[] = $method . " " . $path; }
        } else {
            if ($method === "POST") { $owner[] = $method . " " . $path; }
        }
    }
}

if ($owner && $bridge) {
    echo "OK: owner upload + bridge fetch are both wired\n";
    foreach (array_merge($owner, $bridge) as $h) { echo "  " . $h . "\n"; }
    exit(0);
}

if ($owner)  { fwrite(STDERR, "owner upload present but the BRIDGE cannot fetch: the harness could never use the file\n"); }
if ($bridge) { fwrite(STDERR, "bridge fetch present but there is NO owner upload route\n"); }
if (!$owner && !$bridge) { fwrite(STDERR, "no attachment route on either side\n"); }
fwrite(STDERR, "owner POST routes matching \"" . $needle . "\": " . count($owner) . "\n");
fwrite(STDERR, "bridge GET routes matching \"" . $needle . "\": " . count($bridge) . "\n");
exit(1);
'

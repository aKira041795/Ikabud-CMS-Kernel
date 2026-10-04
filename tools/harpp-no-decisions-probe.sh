#!/usr/bin/env bash
#
# Chair-owned pre-dispatch criterion for the `harpp-chat-first` lane.
#
# HARPP is being REDUCED to chat + deploy + workspaces. The owner's instruction:
#   "let's discard the decisions lane. harpp becomes better without it.
#    as an away tool, linked to my workstation, all i want is a chat lane, aside from the
#    deploy and workspaces"
#
# exit 0 = the decisions surface is GONE from HARPP's route table  (the target)
# exit 1 = decision routes are still exposed                      (the base, measured)
#
# It reads the route map the module actually loads, rather than grepping source, so a
# commented-out route does not satisfy it and a differently-written one still fails it.
#
# MUST-ALLOW CONTROL (run by the chair, not by the lane):
#   bash tools/harpp-no-decisions-probe.sh message
#   must exit 1 with message routes listed - otherwise the needle matches nothing at all
#   and a green result would prove only that the probe cannot see anything.
#
set -u
cd "$(dirname "$0")/.." || exit 1

HARPP_PROBE_NEEDLE="${1:-decision}" php -r '
$needle = getenv("HARPP_PROBE_NEEDLE") ?: "decision";
$routes = require "modules/harpp/routes.php";
$hits = [];
foreach (["GET", "POST", "PUT", "PATCH", "DELETE"] as $method) {
    foreach (array_keys($routes[$method] ?? []) as $path) {
        if (strpos($path, $needle) !== false) {
            $hits[] = $method . " " . $path;
        }
    }
}
if (!$hits) {
    echo "OK: no route matching \"" . $needle . "\" exposed by modules/harpp/routes.php\n";
    exit(0);
}
fwrite(STDERR, "surface still exposed (" . count($hits) . " routes matching \"" . $needle . "\"):\n");
foreach ($hits as $h) {
    fwrite(STDERR, "  " . $h . "\n");
}
exit(1);
'

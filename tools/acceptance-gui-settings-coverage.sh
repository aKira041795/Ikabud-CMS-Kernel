#!/usr/bin/env bash
# Slice-1 acceptance: gui-settings route authority coverage (basis: Gate 0 census, 0/0).
cd /var/www/html/applicationostest || exit 1
php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $r = (new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("gui-settings"); $s = $r["modules"][0]["summary"]; echo "write_enforced=" . $s["dispatch_enforced"] . " read_enforced=" . $s["read_dispatch_enforced"] . "\n"; exit(($s["dispatch_enforced"] >= 2 && $s["read_dispatch_enforced"] >= 1) ? 0 : 1);'

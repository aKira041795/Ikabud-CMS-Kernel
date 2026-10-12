#!/usr/bin/env bash
# Quick census acceptance — fits the harness 300s cap. Scans the three polyglot
# service-modules (the `"routes": []` regression targets) and asserts each returns
# a report. Full-fleet evidence: tools/acceptance-census-fleet.sh (~6-8 min; on demand).
cd /var/www/html/applicationostest || exit 1
php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $c = new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()); foreach (["weather-service","ai-orchestrator","academic-similarity-semantic-service"] as $m) { $r = $c->scan($m); if (($r["modules"][0]["module"] ?? null) !== $m) { fwrite(STDERR, "BAD {$m}\n"); exit(1); } } echo "census_quick_ok 3/3\n";'

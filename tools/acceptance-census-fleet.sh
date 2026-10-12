#!/usr/bin/env bash
# Gate 0 evidence run: the fleet governance census must run to completion and include the
# three polyglot service-modules (before the fix it fatals on `"routes": []`).
#
# COST NOTE (measured 2026-10-12): the full-fleet scan tokenizes every module and takes
# ~6-8 minutes — it EXCEEDS the harness 300s acceptance cap, so `lane.sh` records it as
# TIMEOUT even when the scan is working (false red). Use tools/acceptance-census-quick.sh
# as the lane acceptance gate; run THIS script on demand for full evidence.
cd /var/www/html/applicationostest || exit 1
php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $r=(new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scanAll(); $ids=array_column($r["modules"] ?? [], "module"); foreach (["weather-service","ai-orchestrator","academic-similarity-semantic-service"] as $m){ if(!in_array($m,$ids,true)){ fwrite(STDERR,"MISSING $m\n"); exit(1);} } echo "census_ok entries=".count($ids)."\n";'

#!/usr/bin/env bash
# Gate 0 acceptance: the fleet governance census must run to completion and include the
# three polyglot service-modules (before the fix it fatals on `"routes": []`).
cd /var/www/html/applicationostest || exit 1
php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $r=(new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scanAll(); $ids=array_column($r["modules"] ?? [], "module"); foreach (["weather-service","ai-orchestrator","academic-similarity-semantic-service"] as $m){ if(!in_array($m,$ids,true)){ fwrite(STDERR,"MISSING $m\n"); exit(1);} } echo "census_ok entries=".count($ids)."\n";'

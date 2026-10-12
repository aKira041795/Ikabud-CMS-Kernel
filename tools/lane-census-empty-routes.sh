#!/usr/bin/env bash
#
# Lane: census-empty-routes — Gate 0 tooling fix discovered tonight: GovernanceCensus
# fatals on `"routes": []` (how service-modules declare "no routes"), so the fleet census
# cannot run at all. Fix is content-based ("no routes" is a legitimate state), never
# type-based (the C3b lesson: never skip by module type).
#
# Criterion (fails on base: the census fatals, rc=255):
#   census scanAll() over the real fleet completes and includes the three service-modules.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# DEFECT (measured tonight)
kernel/Workbench/Governance/GovernanceCensus.php scanAll() over the real fleet FATALS:
  "academic-similarity-semantic-service: routes must reference a static PHP route file"
because `scanManifest()` (line ~74-83) requires `$manifest['routes']` to be a string, while
service-modules declare `"routes": []` (an empty array = "no routes"). Measured: all three
service-modules (weather-service, ai-orchestrator, academic-similarity-semantic-service) carry
`"routes": []`; the census was never runnable over the fleet outside fixtures.

# FIX (content-based, NOT type-based)
In scanManifest(), normalize an EMPTY array to the existing "no routes" representation ('')
BEFORE the is_string() check — exactly as `false` is already normalized. "No routes declared"
is a legitimate state for any module; the question "is there a static PHP route file" is
inapplicable only when there are no routes — never skip a scan by module `type`
(the C3b lesson: a check skipped on TYPE rather than CONTENT is a blind spot).
Keep throwing for non-empty non-strings (integers, objects, lists) — those are still malformed.

# TESTS (extend tests/workbench_governance_census_test.php, keep its fixtures green)
- MUST-ALLOW: a fixture module with `"routes": []` scans without throwing; its report exists
  in the output with zero routed operations; its non-HTTP scan still runs.
- MUST-REFUSE: `"routes": 5` still throws; `"routes": ["a"]` (non-empty list) still throws.
- The three REAL service-modules are covered by the acceptance run below (fleet scan).

# CONSTRAINTS
- Change ONLY: kernel/Workbench/Governance/GovernanceCensus.php and
  tests/workbench_governance_census_test.php. Keep the port provenance docblock intact.
  Do NOT touch module.json files or any other code. Do NOT git add / git commit.

# VERIFY (report the verbatim lines)
1. Fleet scan command (must complete, exit 0):
   php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $r=(new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scanAll(); $ids=array_column($r["modules"] ?? [], "module"); foreach (["weather-service","ai-orchestrator","academic-similarity-semantic-service"] as $m){ if(!in_array($m,$ids,true)){ fwrite(STDERR,"MISSING $m\n"); exit(1);} } echo "census_ok entries=".count($ids)."\n";'
2. php tests/workbench_governance_census_test.php        -> all green
3. php tests/authority_census_contract_test.php          -> 12 passed, 0 failed (neighbour stays green)
4. php tests/extension_point_census_test.php             -> stays green
5. php -l kernel/Workbench/Governance/GovernanceCensus.php -> No syntax errors

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: the normalization used; why not a type-based skip; the MUST-ALLOW/MUST-REFUSE fixtures added
verification: the five commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-census-empty-routes

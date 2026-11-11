#!/usr/bin/env bash
#
# Lane: sol-disyl-semantic - slice E: semantic validation of references.
#
# Until now the extension SUGGESTS from the registry but never CHECKS against it:
#   {x|json_attr}                                  -> silent (filter not registered)
#   {ikb_not_a_component}                          -> silent
#   {ikb_entity_list source="no_such_source" ...}  -> silent
# Those are invisible failures, the worst kind for a mid-level or AI author.
#
# MEASURED FALSE-POSITIVE TRAPS - the implementation must avoid both:
#  1. `||` read as a filter pipe. A naive regex produced 555 repo-wide "unknown filter"
#     hits; excluding `||` left 24 real ones. => FILTERS MUST BE AST-DERIVED, never regex.
#  2. `source="attendance_record.recent"` is the dotted source.view form. ALL 10 naive
#     "unknown source" hits were this form, i.e. zero real errors.
#
# MEASURED BASELINE that must not change (all new checks are WARNINGS):
#   php ikabud disyl:lint --json  ->  errors=0 warnings=71 status=ok

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Add semantic reference validation to `php ikabud disyl:lint` (slice E of
docs/disyl/editor-tooling-plan.md). Three new diagnostic codes:
  DISYL_UNKNOWN_FILTER     - a filter name not in FilterRegistry
  DISYL_UNKNOWN_COMPONENT  - a component not in the rendering catalogue
  DISYL_UNKNOWN_SOURCE     - an entity `source` not in the registered source list

# HOW TO IMPLEMENT EACH (this is the whole point - do not shortcut)

1. FILTERS: derive them from the v4 PARSER AST, not from text regex.
   `kernel/DiSyL/v4/AST/FilterNode.php` exposes `getName()`. Parse the template with the
   v4 Parser and validate every FilterNode name with `(new FilterRegistry())->has($name)`.
   WHY: a text regex cannot tell a filter pipe from logical OR. Measured: naive regex gave
   555 false "unknown filter" hits repo-wide (from `{if a || b}`); excluding `||` left 24.
   If you use a regex here the lane fails - the acceptance has an explicit `||` guard.

2. COMPONENTS: reuse the engine's own tag shape. TemplateEngine.php:4339 uses
   `/\{(ikb_\w+|island|state)[\s}]/` to find component tags - match that behaviour so the
   linter and the engine agree. Validate against ComponentRenderer's catalogue
   (the same source `disyl:describe --json` now uses; it exposes >=34 governed components).
   Use the renderer's existing closest-match helper for a "did you mean" suffix.

3. SOURCES: parse `source="..."` from entity component tags and validate against the
   registered source list (the same authority `disyl:list-sources --json` uses).
   CRITICAL: the dotted form `source="attendance_record.recent"` means source.view.
   Split on the FIRST '.' and validate only the source part; treat the remainder as a view.
   Measured: all 10 naive "unknown source" hits were this dotted form, i.e. false positives.

# SEVERITY - non-negotiable
All three are **warnings**, never errors. Measured baseline is errors=0, warnings=71,
status=ok, and the repo must stay that way so `disyl:lint` keeps exiting 0.

# DO NOT
- Do NOT add filters to FilterRegistry, and do NOT edit templates. Real findings exist
  (`substr`, `ucfirst`, `strpos`, `date_format`, `time_format`, `escape_js` are used as
  filters) - REPORT them in your report; fixing them is a separate decision.
- Do NOT change rendering or any existing check's behaviour.
- Do NOT touch the extension (it consumes lint --json and will surface these automatically).
- Do not commit or push. Leave modules/healthcare and modules/sms untouched.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree (3 checks fail)
  php .ai/tmp/disyl-semantic-check.php
It asserts BOTH directions, so read it before you start:
  - the three genuine mistakes ARE reported
  - `{if a || b}` is NOT reported as an unknown filter
  - `source="attendance_record.recent"` is NOT reported as an unknown source
  - a real filter (`|upper`) is NOT reported
  - the corpus still has 0 errors, status still "ok", diagnostics still an array
All must pass. The six guard checks already pass today - do not "fix" the criterion by
weakening them.

# ALSO VERIFY AND REPORT
  # the four existing contracts must still pass
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php
  php ikabud disyl:list-sources --json 2>/dev/null | php .ai/tmp/disyl-sources-contract.php
  # no engine regressions
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)
  # corpus impact: report the exact errors/warnings counts after your change
  php ikabud disyl:lint --json 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN),true);$e=0;$w=0;foreach($d["diagnostics"] as $x){$x["severity"]==="error"?$e++:$w++;}echo "errors=$e warnings=$w status={$d["status"]}\n";'

# SCOPE
- Edit ONLY: `ikabud` and docs/disyl/editor-tooling-plan.md
- If AST-derived filter validation is impossible (e.g. the parser cannot be driven per file
  from the CLI), STOP and report BLOCKED with what you found rather than falling back to regex.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
filter_source: how filters were derived (AST path), and the evidence the `||` trap is avoided
component_source: the catalogue + tag pattern used, and a sample "did you mean"
source_parsing: how the dotted source.view form is handled
corpus: errors/warnings/status after the change vs the 0/71/ok baseline
real_findings: the unknown filters/sources the check actually found across the repo
acceptance: the checker output and exit code
suites: each summary line
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-semantic

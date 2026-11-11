#!/usr/bin/env bash
#
# Lane: sol-disyl-semantic-fix - remediation for slice E.
#
# Slice E shipped, but its unknown-source check splits `source.view` on the FIRST dot.
# Source names THEMSELVES contain dots: `cms.post` is registered (7 cms* sources exist),
# so `source="cms.post.recent"` means source `cms.post` + view `recent`. Splitting on the
# first dot yields `cms`, which is not registered, and fabricates DISYL_UNKNOWN_SOURCE.
# The corpus has exactly 1 such case (templates/modules/cms/admin/dashboard.disyl:225).
#
# CORRECT RULE: resolve by the LONGEST registered source prefix, not the first dot.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

Slice E (semantic reference validation) is already shipped and working except for one
false positive. Fix only that. Do not redesign the other checks.

# THE PROVEN DEFECT
Reproduce first:  php .ai/tmp/disyl-semantic-check.php
  FAIL  source names containing dots resolve by longest prefix (cms.post.recent)  (DISYL_UNKNOWN_SOURCE)

Cause: the unknown-source check resolves a dotted attribute by splitting on the FIRST '.'.
But registered source names contain dots. Measured:
  registered sources include: cms.page, cms.post, cms_media, cms_page, cms_post,
                              cms_product, cms_user  (55 total)
  "cms" is NOT a source; "cms.post" IS.
So `source="cms.post.recent"` must resolve to source `cms.post` + view `recent`.
The current code resolves source `cms` -> not found -> false DISYL_UNKNOWN_SOURCE.

# THE FIX
Resolve the source attribute by trying candidate prefixes from LONGEST to SHORTEST:
split the value on '.', then for n = count(parts) down to 1, test whether
implode('.', first n parts) is a registered source; the first match wins and the
remaining parts (if any) are the view. Do the same longest-prefix logic wherever the
view is derived from the dotted form. Keep the explicit separate `view="..."` attribute
working exactly as it does now.

Do not special-case `cms`. Do not hardcode candidate names. Use the registered source list.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree (1 of 10)
  php .ai/tmp/disyl-semantic-check.php
All ten assertions must pass, including:
  - unknown filter / component / entity source ARE still reported
  - `{if a || b}` is NOT an unknown filter
  - `attendance_record.recent` is NOT an unknown source
  - `cms.post.recent` is NOT an unknown source (the new case)
  - a real filter stays silent
  - corpus still has 0 errors, status "ok", diagnostics an array

# ALSO REQUIRED - report the corpus impact
Baseline after slice E was: errors=0 warnings=92 status=ok, with
DISYL_UNKNOWN_SOURCE=1 (the false positive). After your fix that warning should be gone,
so expect DISYL_UNKNOWN_SOURCE=0 and warnings=91. Paste the actual numbers.

  php ikabud disyl:lint --json 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN),true);$e=0;$w=0;$c=[];foreach($d["diagnostics"] as $x){$x["severity"]==="error"?$e++:$w++;$c[$x["code"]]=($c[$x["code"]]??0)+1;}echo "errors=$e warnings=$w status={$d["status"]}\n";arsort($c);echo json_encode($c),"\n";'

# ALSO VERIFY
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php
  php ikabud disyl:list-sources --json 2>/dev/null | php .ai/tmp/disyl-sources-contract.php
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)

# SCOPE
- Edit ONLY: `ikabud`
- Do NOT edit .ai/tmp/disyl-semantic-check.php (the criterion). It is deliberately outside
  your declared touches; changing it will be recorded as a policy violation.
- Do NOT change severity levels, do NOT add filters, do NOT edit templates.
- Do not commit or push. Leave modules/healthcare and modules/sms untouched.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
resolution_rule: how longest-prefix resolution is implemented (no hardcoded names)
cases: each dotted case and its resolution (attendance_record.recent, cms.post.recent, plain source)
corpus: errors/warnings/codes before and after
acceptance: the checker output (all 10) and exit code
suites: each summary line
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-semantic-fix

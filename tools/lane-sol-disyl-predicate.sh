#!/usr/bin/env bash
#
# Lane: sol-disyl-predicate-filter - close the last 2 corpus warnings.
#
# Real corpus usage (modules/project-audit-ledger/.../pages/bill-of-materials.disyl):
#   {bom|filter:item=>item.source_type=='quotation'|count}
#   {bom|filter:item=>item.source_type=='sales'|count}
#
# DiSyL has no closures, so this cannot be a general lambda. The policy-compliant answer
# is to teach `filter` the SIMPLE PREDICATE form the templates already use - improving
# DiSyL rather than editing the templates.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Implement a `filter` filter that accepts the SIMPLE PREDICATE syntax already present in
templates, and register it on BOTH filter surfaces.

# THE EXACT SYNTAX TO SUPPORT
  {collection|filter:<var>=><path> <op> <literal>|...}
  e.g. {bom|filter:item=>item.source_type=='quotation'|count}
       {bom|filter:row=>row.amt>=20|count}
       {bom|filter:item=>item.source_type!='sales'|count}
- `<var>`: any identifier; it is bound per element. Do not hardcode `item`.
- `<path>`: must start with that variable, then dotted access (item.source_type).
- `<op>`: one of == != < > <= >=
- `<literal>`: single-quoted string, double-quoted string, integer/float, true, false, null.
- Elements missing the path simply do not match.
- Return a REINDEXED array (array_values) so chaining with |count, |first, |slice behaves.

# SCOPE LIMIT - this is NOT a general expression evaluator
Support exactly the grammar above. Do not add function calls, boolean operators,
arithmetic, or nested predicates. An unsupported predicate must NOT fatal or leak the raw
tag into output: pass the collection through unchanged (and log via write_log if the
existing code does that for unknown filters).

# WHERE TO REGISTER (there are two surfaces; both are required)
1. `kernel/DiSyL/TemplateEngine.php` :: registerDefaultFilters() (~line 5322) - this drives
   the interpreted pipeline AND is copied into the compiled FilterRegistry at compile time.
2. `kernel/DiSyL/v4/FilterRegistry.php` :: registerDefaults() - what disyl:describe, the
   editor completion and the unknown-filter lint check consult.
A filter on only one surface is broken in one direction. The acceptance's drift guard from
slice F already enforces this; do not weaken it.

# CONTEXT ALREADY IN PLACE
`filter` currently resolves to nothing: unregistered filters return the value unchanged,
so `{items|filter:...|count}` renders the FULL count (3) instead of the filtered one.
Verify that yourself before editing.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree (8 of 9)
  php .ai/tmp/disyl-predicate-check.php
It asserts exact counts (not just interpreted==compiled), several operators, a non-`item`
predicate variable, both pipelines, that FilterRegistry knows `filter`, that the corpus has
ZERO unknown-filter warnings, and that corpus errors stay 0.

# ALSO REQUIRED
- Add parity cases for the predicate filter to tests/disyl_parity_test.php.
- No regressions:
  php tests/disyl_parity_test.php         (expect 203/203 currently, then more)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)
  php .ai/tmp/disyl-filter-check.php      (slice F drift guard must stay green)
  php .ai/tmp/disyl-semantic-check.php    (slice E must stay green)
  php ikabud disyl:lint >/dev/null 2>&1; echo "lint_exit=$?"   (must be 0)

# SCOPE
- Edit ONLY: kernel/DiSyL/TemplateEngine.php, kernel/DiSyL/v4/FilterRegistry.php,
  tests/disyl_parity_test.php, docs/disyl/editor-tooling-plan.md
- Do NOT edit templates (the point is that they start working unchanged).
- Do NOT edit .ai/tmp/* criteria; they are outside your touches on purpose.
- Do not commit or push. Leave modules/healthcare and modules/sms untouched.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
grammar: the exact predicate grammar implemented, and what is deliberately rejected
behaviour: reindexing, missing-path, unsupported-predicate outcome
manifestation: the real bill-of-materials usage, before and after (counts)
corpus: unknown-filter count before (2) and after
acceptance: the checker output and exit code
suites: each summary line
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-predicate

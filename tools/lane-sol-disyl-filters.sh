#!/usr/bin/env bash
#
# Lane: sol-disyl-filters - slice F: add the filters templates actually use, and
# reconcile the two filter surfaces that have drifted apart.
#
# MEASURED, after slice E's unknown-filter check ran over the corpus (20 warnings):
#   substr(6) date_format(3) time_format(3) filter(2) ucfirst(2) strpos(2)
#   escape_js(1) replace(1)
# Under the owner policy these are capability gaps (the template asked for something
# reasonable), not template errors - so DiSyL gains them.
#
# THE ARCHITECTURAL TRAP this lane must not fall into:
#   interpreted  -> ExpressionEvaluator::$filters, synced from TemplateEngine::$filters
#   compiled     -> a FilterRegistry built FROM TemplateEngine::$filters
#   describe / semantic lint -> FilterRegistry::registerDefaults()
# Adding a filter to only ONE surface creates either a compiled-only filter (a new
# dual-mode divergence) or a filter the editor offers but rendering rejects.
#
# AND THE DRIFT IS ALREADY REAL (the acceptance's drift guard found it):
#   engine-only (works, but editor/lint deny it): json_attr, pluralize
#   registry-only (offered, but engine rejects it): escape

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Slice F of docs/disyl/editor-tooling-plan.md: add the missing filters and make the two
filter surfaces agree.

Read the architecture before editing:
- `kernel/DiSyL/TemplateEngine.php` :: registerDefaultFilters() (~line 5322) builds the
  authoritative `$this->filters` map. `registerFilter()` (~5309) also syncs the evaluator.
- Compiled mode builds a fresh `v4\FilterRegistry` and registers `$this->filters` on top
  of its own defaults (~lines 465/481/489).
- `kernel/DiSyL/v4/FilterRegistry.php` :: registerDefaults() is what `disyl:describe` and the
  unknown-filter lint check consult.
=> A filter must exist in BOTH `TemplateEngine::registerDefaultFilters()` and
   `FilterRegistry::registerDefaults()`, or it is broken in one direction.

# PART 1 - ADD THESE FILTERS (to BOTH surfaces)
Each must render identically in interpreted and compiled mode.
- `ucfirst`   : uppercase the first character. Must be MULTIBYTE safe (É -> É).
- `substr`    : args (start, length). MUST be multibyte safe (mb_substr, UTF-8) so
                `{name|substr:0:1}` on "Émile" yields "É", not a broken byte.
- `strpos`    : args (needle). Returns the 0-based offset, and FALSE when absent.
                Templates use `{if scope|strpos:'ecommerce' === 0}`; the absent case must
                not compare equal to 0.
- `replace`   : args (search, replace). `{status|replace:'_':' '}` => "a b".
- `date_format`: alias of the existing `date` filter (same arguments, same output).
- `time_format`: with an argument behaves exactly like `date`; with NO argument formats
                as a time (e.g. "g:i A"). It is used as `{row.scheduled_time|time_format}`.
- `escape_js` : alias of the existing `esc_js` (a near-miss name templates already use).

# PART 2 - RECONCILE THE EXISTING DRIFT (the acceptance fails on this today)
The drift guard reports:
  engine-only  : json_attr, pluralize   -> register them in FilterRegistry so describe/
                                          lint/completion stop denying a working filter
  registry-only: escape                 -> implement it in the engine (it is the meta-filter
                                          that delegates to esc_js/esc_html/esc_attr/esc_url
                                          by first argument, per the FilterRegistry entry)
Decide each one correctly; report what you chose and why. Do NOT delete a working filter to
make the guard pass - that would break templates.

# OUT OF SCOPE
- `filter` with a lambda predicate: `{bom|filter:item=>item.source_type=='x'|count}`.
  DiSyL has no closure support; that is a language feature, not a filter. Leave those
  2 corpus warnings standing.
- Do NOT edit templates. Do NOT change escaping semantics (esc_* behaviour is security
  sensitive and pinned by tests).

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree (9 of 17)
  php .ai/tmp/disyl-filter-check.php
It requires: each new filter renders identically in both modes; FilterRegistry knows each;
the two surfaces agree in BOTH directions; and the corpus unknown-filter count drops to <= 2.
Note the parity assertions pass vacuously today (an unregistered filter silently returns the
value unchanged), which is why the registry and drift assertions exist.

# ALSO REQUIRED
- Add parity cases for the new filters to tests/disyl_parity_test.php (interpreted == compiled).
- No regressions:
  php tests/disyl_parity_test.php         (expect all pass; 195 currently)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
  php ikabud disyl:semantic-check 2>/dev/null || php .ai/tmp/disyl-semantic-check.php
  php ikabud disyl:lint >/dev/null 2>&1; echo "lint_exit=$?"   (must be 0)
- Report the resulting filter count (was 38 in describe; it will grow).

# SCOPE
- Edit ONLY: kernel/DiSyL/TemplateEngine.php, kernel/DiSyL/v4/FilterRegistry.php,
  tests/disyl_parity_test.php, docs/disyl/editor-tooling-plan.md
- Do NOT edit .ai/tmp/* (the criteria). They are outside your touches on purpose; touching
  them is recorded as a policy violation.
- Do not commit or push. Leave modules/healthcare and modules/sms untouched.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
added_filters: each name -> both registration points + its exact semantics
drift_resolution: json_attr / pluralize / escape, what you did and why
mb_safety: how substr and ucfirst handle multibyte input, with the rendered evidence
corpus: unknown-filter count before (20) and after
acceptance: the checker output and exit code
suites: each summary line
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-filters

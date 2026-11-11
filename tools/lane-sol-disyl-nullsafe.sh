#!/usr/bin/env bash
#
# Lane: sol-disyl-nullsafe - implement nullsafe `?->` parity in DiSyL.
#
# IMPLEMENTATION lane. Sol may edit ONLY the four declared files and must leave the
# tree green. The design is settled (the owner policy says improve DiSyL rather than
# keep a restriction), so this is a surgical edit task, not a design debate.
#
# Dispatched through tools/lane.sh, which enforces the pre-dispatch acceptance gate.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Implement nullsafe property access `?->` in DiSyL so that `{user?->name}` renders
IDENTICALLY in the interpreted and compiled pipelines.

The design is SETTLED. Do not redesign it, do not add a second evaluation path.
DiSyL dot access is already null-tolerant (null in -> null out), so `?->` is an explicit
spelling that must LOWER to the existing dot-access walk. One semantic, two spellings.

# WHY THIS IS ALLOWED (owner policy, 2026-11-11, verbatim)
"if policies restrict disyl, improve disyl rather than keep the restrictions"

`?->` currently sits in config/disyl-feature-inventory.json as prohibited_application_logic.
The owner has directed that such restrictions be REMOVED by implementing the construct, not
entrenched as lint rules. Do not write any rule that forbids `?->`.

STAY OUT OF SCOPE - these remain boundaries, do NOT implement them:
- `.` as PHP string concatenation (ambiguous: dot already means path access; `~` concatenates)
- `@` error suppression
- arbitrary raw PHP
Touching those fails the task.

# CURRENT MEASURED STATE (verify it yourself, do not trust it)
Probe: php .ai/tmp/disyl-parity-probe.php
  -> nullsafe  DIVERGENT  interpreted=""  compiled="{user?->name}"
Interpreted swallows the tag to empty; compiled leaks the raw tag into the page.

# THE PATTERN THAT WAS JUST USED FOR BRACKET ACCESS - FOLLOW IT
Bracket access had the same shape of defect and was fixed by:
- kernel/DiSyL/ExpressionEvaluator.php: new splitAccessPath() walks `a.b[0].c`;
  resolveValue() uses it instead of explode('.'); semantics mirror
  v4/RenderContext::getProperty() (null in -> null out, arrays by key, objects by
  property then getter).
- kernel/DiSyL/TemplateEngine.php: isValidTemplateVariablePath() accepts the new syntax.
- kernel/DiSyL/v4/Parser.php: isProcessableTemplateExpression() gate accepts it, and
  buildAccessChain() parses `base` + any mix of `.name` and `[key]`.
Read those three functions first. Reuse them; do not duplicate.

# THE REAL HAZARD - READ THIS BEFORE EDITING
`?->` contains `-` and `>`, which several places treat as OPERATOR characters. If you
merely add `?->` to a path regex, the expression may be split as binary minus or
greater-than before it ever reaches the access walk. Audit and guard ALL of:
- ExpressionEvaluator::resolveValue() arithmetic branch: preg_match('/[+\-*\/%]/', $path)
- ExpressionEvaluator::containsArithmeticOperator() / findLastBinaryOp() callers
- v4\Parser::containsArithmeticOperator() and findLastBinaryOp()
- v4\Parser::parseExprValue() postfix handling (ends with '--' would be misread)
- ternary detection: `?` searches must not treat the `?` of `?->` as a ternary
A clean solution is to normalise `?->` to `.` at ONE well-chosen point per pipeline,
before operator analysis but after quoted-string literals are recognised - so a string
literal containing `?->` is never rewritten, and no downstream code needs to know the
spelling exists. Prove your choice handles: {user?->name}, {user?->profile?->name},
{a?->b == 'x'}, {items[0]?->name}, {'a?->b'}, and {x ?? 'd'}.

# GATES THAT MUST ACCEPT IT
- kernel/DiSyL/TemplateEngine.php :: isValidTemplateVariablePath()
- kernel/DiSyL/v4/Parser.php :: isProcessableTemplateExpression()
(accepting it does not mean implementing it twice - both just rout to the same walk)

# ACCEPTANCE - the lane gate runs exactly this, and it FAILS on the current tree
  php .ai/tmp/disyl-parity-probe.php | grep -qE '^nullsafe +SAME'
It must exit 0 when you are done. It asserts an OUTCOME (both pipelines agree), not a mechanism.

# ALSO REQUIRED
1. Add a `?->` section to tests/disyl_parity_test.php in the existing style, covering at
   minimum: simple nullsafe, chained nullsafe, nullsafe on a present value, nullsafe
   combined with a filter, and nullsafe on a missing path. The suite must report a full pass.
2. Do not regress these; run them and paste the summary lines:
   php tests/disyl_parity_test.php          (expect: PARITY RESULTS: N/N passed)
   php tools/disyl-conformance-check.php    (expect: promotion: promoted=41 partial=0, disagreements: none)
   php tests/disyl_engine_test.php          (baseline is 347 passed, 1 FAILED - the failure
                                             'variables in script are raw (no HTML-escape)' is
                                             PRE-EXISTING and out of scope; leave it alone,
                                             but do not add a second failure)
3. php -l every file you touch.

# RULES
- Edit ONLY: kernel/DiSyL/ExpressionEvaluator.php, kernel/DiSyL/TemplateEngine.php,
  kernel/DiSyL/v4/Parser.php, tests/disyl_parity_test.php
- Do NOT commit, do NOT push, do NOT touch unrelated modules (modules/healthcare and
  modules/sms are already dirty from other work - leave them exactly as they are).
- Production target is MySQL 5.7; this task is PHP-only, keep it that way.
- If the design cannot be met without widening scope, STOP and report BLOCKED with the
  specific reason instead of inventing architecture.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
changed_files: list
approach: the one normalisation point you chose per pipeline, and why that point
hazards_checked: each operator path above, and its result
acceptance_exit: the exit code of the acceptance command, with its output line
suites: the summary line of each of the three commands above
new_tests: the ?-> cases you added
not_done: anything you deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-nullsafe

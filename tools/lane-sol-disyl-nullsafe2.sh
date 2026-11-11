#!/usr/bin/env bash
#
# Lane: sol-disyl-nullsafe2 - FIX the condition-position defect left by disyl-nullsafe.
#
# The first attempt reported PASS, but its acceptance criterion only covered OUTPUT
# position. In CONDITION position `?->` does not lower to dot semantics:
#
#   {if user?->role}yes{else}no{/if}   with user=null
#     interpreted -> "yes"   (WRONG: treats the tag as a truthy non-empty string)
#     compiled    -> "no"    (correct)
#   {if user.role}yes{else}no{/if}     with user=null
#     interpreted -> "no"    compiled -> "no"    (dot access is already null-safe)
#
# So the settled design - one semantic, two spellings - is still not met.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

A previous attempt implemented nullsafe `?->` and reported PASS. It is INCOMPLETE.
Your job is to finish it, not to rewrite it from scratch - read the existing diff first.

# THE PROVEN DEFECT (reproduce it before changing anything)
Command: php .ai/tmp/disyl-parity-probe.php
Current rows:
  nullsafe          SAME        interpreted=""     compiled=""        <- output position OK
  nullsafe-if-null  DIVERGENT   interpreted="yes"  compiled="no"      <- BUG
  dot-if-null       SAME        interpreted="no"   compiled="no"      <- the target semantics

  nullsafe-if-null = {if user?->role}yes{else}no{/if}  with ['user' => null]
  dot-if-null      = {if user.role}yes{else}no{/if}    with ['user' => null]

Interpretation: in condition position the interpreted path does not lower `?->` to dot
access, so `user?->role` is treated as a truthy non-empty tag/string instead of resolving
to null. Dot access on a null parent already yields falsy in BOTH pipelines, so `?->` must
produce exactly the same result. This is a correctness bug that can show a whole block of
UI that should not render.

# WHAT THE PREVIOUS ATTEMPT DID (read it, then decide whether to keep or replace)
It did NOT use a single normalisation point. It added `?->` as an alternative inside regexes
(TemplateEngine::isValidTemplateVariablePath, v4\Parser::isProcessableTemplateExpression,
v4\Parser line ~573) and added ad-hoc operator guards:
  - TemplateEngine.php:4740   $operatorProbe = str_replace('?->', '', $expr)
  - TemplateEngine.php:4915   strpbrk(str_replace('?->', '', $expr), '+-*/%()')
  - ExpressionEvaluator.php:346 / :1169   quote-aware `?->` scanners
  - v4\Parser.php:1558 / :2401            the same
The adversarial probe rows `nullsafe`, `nullsafe-chain`, `nullsafe-bracket`, `nullsafe-filter`,
`nullsafe-literal`, `quoted-plain`, `quoted-symbol` all pass, so those paths work. ONLY the
condition path is wrong. Prefer the smallest change that fixes condition position; if you find
that the scattered approach is what caused the miss, you MAY consolidate onto ONE quote-aware
normalisation point per pipeline - but only if the full probe and suites stay green.

# FIND THE CAUSE, DO NOT GUESS
Trace how `{if <expr>}` is evaluated in the INTERPRETED pipeline (start from the `{if}`
handling in kernel/DiSyL/TemplateEngine.php and the condition/truthiness helper it calls).
Determine why `user?->role` is truthy while `user.role` is falsy. State the exact function and
line in your report. Do not "fix" it by special-casing the probe string.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php .ai/tmp/disyl-parity-probe.php | grep -cE '^(nullsafe|nullsafe-if-null|dot-if-null) +SAME' | grep -qx 3
It requires all three rows to agree, i.e. `?->` and `.` must be indistinguishable in BOTH
output and condition position. It must exit 0 when you are done.

# ALSO REQUIRED
1. Add CONDITION-position cases to tests/disyl_parity_test.php (the previous attempt only added
   output-position cases, which is exactly how this slipped through). At minimum:
   - nullsafe in an if with a present parent
   - nullsafe in an if with a NULL parent (renders the else branch)
   - nullsafe in an if with a missing intermediate key
   - the same three using dot access as controls
2. Do not regress; run and paste the summary line of each:
   php tests/disyl_parity_test.php        (currently 189/189 passed)
   php tools/disyl-conformance-check.php  (currently promoted=41 partial=0, disagreements: none)
   php tests/disyl_engine_test.php        (baseline 347 passed, 1 FAILED; the failure
                                           'variables in script are raw (no HTML-escape)' is
                                           PRE-EXISTING - leave it alone, do not add a second)
3. php -l every file you touch.

# SCOPE
- Edit ONLY: kernel/DiSyL/ExpressionEvaluator.php, kernel/DiSyL/TemplateEngine.php,
  kernel/DiSyL/v4/Parser.php, tests/disyl_parity_test.php
- Do NOT implement `.` concatenation, `@` suppression, or raw PHP. Those stay out.
- Do NOT commit or push. Leave modules/healthcare and modules/sms untouched.
- If the correct fix requires widening scope, STOP and report BLOCKED with the reason.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
root_cause: the exact function + line where condition evaluation lost the `?->` lowering
approach: what you changed and why it cannot miss condition position again
acceptance: the command output and exit code
suites: the summary line of each of the three commands
new_tests: the condition-position cases you added
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-nullsafe2

#!/usr/bin/env bash
#
# Lane: fix-health-view-test
#
# A PRE-EXISTING time bomb, attributed by checkout experiment (2026-10-12): the same three
# assertions fail on the pre-wave tree (e0f179ff). tests/daily-ledger/business_health_view_test.php
# asserts state-dependent strings against the LIVE "today" default period; the business date rolled
# from 2026-10-11 (103 pending rows) to 2026-10-12 (no rows), so:
#   - 'Awaiting finalization' is no longer rendered today (state is no-sales),
#   - the opportunity metric is no longer '<n> ledger rows are pending',
#   - the trend is no longer 'Not yet comparable' with a delta-pending chip.
#
# Criterion (RED now: 12/15): php tests/daily-ledger/business_health_view_test.php
#
# Owner directive (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7. Tenant 207 is a copy of production on the local site baronledger.test.

# THE DEFECT (attributed by experiment, not assumed)

tests/daily-ledger/business_health_view_test.php currently fails 3 of 15 assertions. PROVEN
pre-existing: the same 3 fail on the pre-wave tree (checkout e0f179ff), so no recent change caused
them. The cause is a date-rollover time bomb: three assertions render the page with NO period
argument and assert state-dependent strings that describe "today":

  line ~72: 'unfinished reporting is not rendered as zero headline sales'
            asserts 'Awaiting finalization' renders today (true on 2026-10-11: 103 rows, all
            pending; false on 2026-10-12 when today has no ledger rows -> state is 'no-sales').
  line ~73: 'opportunity is named, measured, and linked to branch detail'
            asserts '<n> of <m> ledger rows are pending' - the opportunity metric only when a
            pending branch exists today.
  line ~100: 'no honest comparison shows a neutral pending state, not zero or a green arrow'
            asserts 'Not yet comparable' + delta-pending - only when the current period has pending
            rows; an empty today renders 'No sales recorded' instead.

# THE FIX (make the test date-robust WITHOUT weakening what it protects)

Re-anchor those three checks to a period that GENUINELY has the state they describe, discovered
from the data at run time - do not hard-code a date that will rot again, and do not rely on
'today'. The rules:

1. Discover the period from tenant 207's data using the module's OWN semantics, not hand-rolled
   SQL guesses: dl_overviewLedgerCompleteness() (helpers/reporting.php) is what the page itself
   uses; its result exposes rows_total / pending / completed and its filter shape is built the
   same way handleAdminOverview() builds $filters (see handlers.php around line 12495). Prefer the
   LATEST date with pending > 0 (for the 'Awaiting finalization' check also require completed === 0,
   because that card only renders in the pending state; if no such date exists, search back for any
   date with pending > 0 and use a date where the state the assertions need is real).
2. ASSERT THE PREMISE IN-TEST: add an assertion (or fold into the failing checks) that the chosen
   period has the state the following checks depend on (pending count > 0; for 'Awaiting
   finalization', completed === 0). The test must NEVER pass vacuously: if the data changes so no
   pending period exists anywhere, it must fail with a message naming that as the reason to
   re-anchor. Also print the chosen period in the failure details so a future reader sees which
   data state the check was anchored to.
3. Keep the three checks' SEMANTICS unchanged (same strings asserted, same negative clauses -
   no PHP 0.00 headline, no delta-up, no (+|-)0.0%, a branch_id link present). Only the render
   period changes for those checks; the other 12 assertions must stay as they are and keep passing.
4. The test is MODE_PURE + fingerprint-based (see its header): do not alter the harness usage,
   allowLogLines, or fingerprints beyond what the re-anchor strictly needs.

# CONSTRAINTS

- Edit ONLY tests/daily-ledger/business_health_view_test.php. Nothing else - not the handler, not
  the template, not other tests.
- MySQL 5.7: if you query for discovery through module()->db(), no window functions / CTEs /
  JSON_TABLE; prefer reusing the module helpers.
- Do NOT git add / git commit; leave the tree for review.

# VERIFY (report the actual output lines)

1. php tests/daily-ledger/business_health_view_test.php   -> 15/15 passed, exit 0
2. php tests/daily-ledger/daily_ledger_overview_test.php  -> stays green (no collateral)
3. Prove non-vacuity: show the discovered period and its pending count from the test output (the
   added premise check must print or report them), and state which data state the three checks
   are anchored to.

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: how the pending period is discovered; the premise assertion added
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-fix-health-view-test

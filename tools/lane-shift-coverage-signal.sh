#!/usr/bin/env bash
#
# Lane: shift-coverage-signal
#
# Closes the last open finding from the Sol review of 32f2e431 (finding 2b, high severity), which
# the scope/copy lane deliberately left alone because it is a GRAIN question, and the chair has
# since made the product decision:
#
#   DECISION (chair, 2026-10-05, owner unavailable, "work autonomously and make good decisions"):
#   Do NOT turn the view into a full date x shift x product grid (that would be up to 364 rows for
#   one day, multiplying with the date range, and the owner's standing rule is to keep the UI
#   simple). Instead disclose, in ONE additive line, the case the admin cannot currently see.
#
# THE GAP, MEASURED (branch 8, 2026-10-03, tenant 207):
#   AM has 73 ledger rows. PM has ZERO. 71 of the AM rows lack an ending.
#   The page renders 178 rows (105 of them "No record") and a banner naming 2026-10-03 as having
#   pending data -- but NOTHING on the page says the PM shift has no rows at all. Filtering
#   Shift=PM does reveal it (all 178 become "No record"), but an auditor on the default
#   Shift=All view has no signal that a whole shift is missing.
#
#   That asymmetry -- one shift with rows, the other with none -- is the exact blind spot this
#   whole effort exists to remove. Rejected alternative: relabelling each placeholder with the
#   missing shift, which would require aggregating the driven set per product and would put the
#   verified money invariant at risk for the sake of a label.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are IMPLEMENTING a small, additive disclosure in the Ikabud repo at
/var/www/html/applicationostest. Read the committed state first:

    git --no-pager show --stat 7eda56e3
    git --no-pager log --oneline -3

`handleAdminSales()` in modules/daily-ledger/handlers.php drives the admin Sales VIEW from a
derived UNION so every active product appears, including products with no ledger row. It is
verified: official/provisional money is identical to the row-driven RECORD across many windows,
no ledger row is ever written, and the totals were cross-checked against the rendered page.

## The gap to close
Nothing tells the admin that a shift has NO rows at all. Measured on branch 8 / 2026-10-03:
AM has 73 rows, PM has ZERO. The page shows 178 rows and a "pending data" banner, so a whole
missing shift is invisible on the default Shift=All view.

## What to build (FIX D) — one additive disclosure, no row growth, no money impact
After the main queries, run ONE additional cheap grouped query over the SAME branch scope and
SAME date range that counts ledger rows per (ledger_date, shift). Then disclose, in the existing
informational area of the template:

  D1. Per date in range where EXACTLY ONE shift has rows and the other has ZERO:
      name it plainly, e.g. "No PM rows recorded for 2026-10-03." / "No AM rows recorded
      for <date>." Use the real shift name and the real date. Cap the list sensibly (if many
      dates qualify, summarise the count rather than printing hundreds of dates).
  D2. Per date in range where BOTH shifts have zero rows while the range overall has rows:
      "No rows recorded at all for <date>."

Requirements:
- Additive only. Do NOT change the driving query, the money expressions, the row grain, the
  existing truncation warning, or the existing pending-dates banner.
- The disclosure must NEVER be triggered by no-record (synthetic) rows. Only rows that exist in
  dl_daily_ledger count toward coverage. A product with no movement is not a missing shift.
- If no date in range qualifies, render NOTHING extra (no empty banner, no "0 shifts missing").
- The two shifts are 'AM' and 'PM'; treat any other/unknown shift value conservatively.
- MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep both logs clean: storage/logs/error.log must stay empty.

## Provenance / caution
Two different models have written this view today. Re-read the current code before editing rather
than assuming intent, and keep the change small enough to review at a glance.

## Oracle (extend tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php, which passes
## 53/53 now — it must still pass in full)
On its PRIVATE fixture branch (99230), add cases proving BY MEASUREMENT:
  D1. a date with AM rows and NO PM rows renders the "No PM rows recorded for <date>"
      disclosure.
  D2. a date with NO rows in either shift, in a range where another date has rows, renders the
      "no rows recorded at all" disclosure.
  D3. a date with BOTH shifts recorded renders NEITHER disclosure.
  D4. a date whose products are ALL no-record (synthetic) rows renders NEITHER disclosure —
      no movement is not a missing shift. This is the false-alarm case; prove it does not fire.
  D5. the money totals are unchanged by FIX D.
State clearly which of these FAIL on the base tree (they should — D1/D2 are the new behaviour).

## Acceptance
Report the exact output of:
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l modules/daily-ledger/handlers.php
    git --no-pager diff --stat
Then re-run the chair's live probes and report them verbatim:
    php /tmp/chair-verify-full-sheet.php
    APP_URL=http://baronledger.test npx playwright test \
        tests/browser/daily-ledger-admin-sales-full-sheet.spec.js --reporter=line --retries=0
(The browser spec is the chair's independent end-to-end money cross-check; it must stay 3/3.
If your change makes it fail, your change is wrong — the spec asserts money and row counts
against independently measured values, not against your implementation.)

Also verify on live data that the real 2026-10-03 case now DOES disclose the missing PM shift,
and quote the rendered sentence.

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep, no grain change.
- Never weaken, skip, or delete an existing assertion to reach green.
- Do not touch `{grand_amount | number_format}` or any money formatting — that is a separate,
  deliberately open question for the owner.
- If the disclosure cannot be produced without changing the driving query or risking the money
  invariant, STOP and report BLOCKED with the reason.
- Report status PASS | PARTIAL | BLOCKED, files changed, and measured evidence.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/shift-coverage-signal
rc=$?
echo "lane: shift-coverage-signal — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

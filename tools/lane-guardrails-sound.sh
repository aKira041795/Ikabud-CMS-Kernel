#!/usr/bin/env bash
#
# Lane: guardrails-sound
#
# Closes the two remaining decided items:
#   3. the SQL copies of the provisional predicate can silently diverge from the canonical PHP rule;
#   4. two browser specs depend on fixture state that a run consumes and the seed does not restore.
#
# Both are "make the guardrails trustworthy", which is why they ship together.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing TWO guardrail defects in the Daily Ledger. Neither changes user-visible behaviour
today; both remove a way for the system to lie to us later.

================================================================================
PART 1 - one provisional rule, not four
================================================================================

The rule "is this ledger row provisional?" exists in FOUR places:

  A. modules/daily-ledger/helpers/reporting.php  dl_rowIsProvisional()   <- CANONICAL (PHP)
  B. modules/daily-ledger/handlers.php ~L9876    $provisionalExpr        <- SQL (dashboard)
  C. modules/daily-ledger/handlers.php ~L10580   $provisionalExpr        <- SQL (sales view)
  D. (a fourth, hand-written in templates/.../sales.disyl, was deleted on 2026-10-05)

B and C are subtly different from A. A reads:

    bal_end IS NULL                                   -> provisional
    shift_status IS NULL and shift == 'PM'            -> provisional
    shift_status NOT NULL and shift_status <> 'finalized'  -> provisional   (ANY shift)
    otherwise                                          -> official

B and C gate the status clause on `dl.shift = 'PM'`, so an AM row whose shift row exists and is
not finalized is PROVISIONAL under A and OFFICIAL under B/C.

MEASURED 2026-10-05: the divergence is currently 0 rows (2976 vs 2976), because every AM shift
row in dl_daily_ledger is either 'finalized' (2597) or absent (3506) - no AM row has a
non-finalized status. So B and C are currently correct BY LUCK OF THE DATA, not by construction.
One AM shift row gaining an 'open' status silently moves that row between the official and
provisional totals, and the dashboard would disagree with the sales report.

WHAT TO DO
  - Add ONE shared SQL fragment helper, next to the canonical PHP predicate, so the SQL form is
    derived from a single source. Something like:

        function dl_provisionalSqlExpr(string $ledgerAlias, string $shiftAlias): string

    returning a fragment equivalent to dl_rowIsProvisional(), i.e. covering all three clauses
    above including the AM case.
  - Use it in BOTH B and C. There must be no remaining hand-written copy of the rule in SQL.
  - Keep the PHP predicate as the row-level authority; do not reimplement the rule in the helper
    in a way that can drift from it. State in a comment which function it mirrors, in the same
    spirit as the existing "the bucket must follow the SAME predicate" comment in reporting.php.

PROVE IT IS BEHAVIOURALLY INERT TODAY
  The change must not move a single row between buckets on live data. Measure and report
  official_units, official_amount, provisional_units, provisional_amount from BOTH call sites
  BEFORE and AFTER your change, and show they are identical. If any figure moves, STOP and report -
  that would mean the two rules differed on real data, which is a much bigger finding.

================================================================================
PART 2 - make the browser fixture deterministic
================================================================================

Two browser specs need a PRIOR business day that is still open with an unfinalized PM shift:

  tests/browser/daily-ledger-nextday-entry.spec.js   (the flag + not hampering entry)
  tests/browser/daily-ledger-prior-ledger-link.spec.js (the #prior-pending banner link)

That state is live data which a run CONSUMES and nothing restores, so both specs go red after the
first run and stay red. database/seeds/browser_environment.php does not establish it - it has no
prior-day setup and no date logic at all.

They fail LOUDLY rather than vacuously, which is correct: prior-ledger-link asserts
`linkCount > 0` with "the prior-pending banner must be present for this test to mean anything".
So the FIXTURE is at fault, not the assertion. Do NOT relax those assertions.

WHAT TO DO
  Extend database/seeds/browser_environment.php so it deterministically creates the prior-pending
  state those specs need, so the suite is idempotent across repeated runs:
    - a prior business day for the commissary the specs use, left OPEN, with its PM shift
      unfinalized, so the banner renders and the next-day flag fires.
  Match what the specs actually read. Read the two spec files first and satisfy them exactly
  rather than guessing the shape. Keep the seed idempotent (safe to run repeatedly) and keep it
  inside the existing seed's conventions.

ACCEPTANCE
  The gate command (currently FAILS on the base, because the fixture is absent):
    php database/seeds/browser_environment.php >/dev/null 2>&1 \
      && APP_URL=http://baronledger.test npx playwright test \
           tests/browser/daily-ledger-nextday-entry.spec.js \
           tests/browser/daily-ledger-prior-ledger-link.spec.js --reporter=line
    -> 2 passed

  Then, after the seed, the whole daily-ledger browser set must pass:
    APP_URL=http://baronledger.test npx playwright test \
      tests/browser/daily-ledger-reopen-edit.spec.js \
      tests/browser/daily-ledger-nextday-entry.spec.js \
      tests/browser/daily-ledger-close-failure-guidance.spec.js \
      tests/browser/daily-ledger-settled-endings.spec.js \
      tests/browser/daily-ledger-prior-ledger-link.spec.js \
      tests/browser/daily-ledger-sales-pending-marker.spec.js --reporter=line
    -> 9 passed

  AND prove idempotence: run the seed + the two specs a SECOND time and show they still pass.
  A seed that works once is the defect you are fixing.

  No PHP regressions:
    php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php  -> 11/11
    php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php   -> 21/21
    php tests/daily-ledger/daily_ledger_reporting_test.php              -> 76/76
    php tests/daily-ledger/daily_ledger_production_controls_test.php    -> 70/70
    php tests/daily-ledger/daily_ledger_handlers_test.php               -> 229/229
    php tests/daily-ledger/daily_ledger_overview_test.php               -> 104/104
    php tests/daily-ledger/daily_ledger_routes_test.php                 -> 82/82
    php ikabud module:validate daily-ledger

IMPORTANT
  - APP_URL IS REQUIRED for playwright. Without it the config falls back to
    http://palsystem.test, every spec hits the wrong tenant and fails at the login selector with
    "We could not find that page." - a misleading failure that looks like a product defect.
  - Do NOT edit any test or spec file under tests/. Fix the seed and the source.
  - Do NOT weaken any assertion.
  - Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  PART 1: the helper, which call sites now use it, and the BEFORE/AFTER bucket figures proving 0
          rows moved
  PART 2: what state the seed now creates and which spec assertion it satisfies
  idempotence: the second run's result
  verification: exact commands and numbers, including the full browser set
  logs:
  risks / unresolved:

BLOCKED is correct if aligning the SQL would move a row between buckets on live data, or if the
seed cannot create the state without a product decision.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/guardrails-sound
rc=$?
echo "lane: guardrails-sound — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

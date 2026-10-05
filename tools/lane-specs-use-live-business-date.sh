#!/usr/bin/env bash
#
# Lane: specs-use-live-business-date
#
# Removes an unacceptable side effect introduced by the previous lane.
#
# The previous lane made the two prior-pending specs green by having the browser seed PIN the
# TEST TENANT's operating clock (operating_timezone -> Pacific/Midway, close_of_day_time -> 00:00)
# so that "now" landed on the date the specs hard-code. Measured consequences:
#
#   1. It MUTATES tenant 207, which the owner logs into by hand. Before the seed the sheet read
#      "Timezone: Asia/Manila / Cutoff: 23:59" and business date 2026-10-05; after it, the tenant's
#      business date read 2026-10-04. The owner's own manual testing was silently showing the
#      wrong business day. The chair has already restored Asia/Manila + 23:59 by hand.
#   2. It EXPIRES. Once no timezone puts "now" on 2026-10-04 (about 2026-10-05 12:00 UTC) the seed
#      logs a warning and both specs go red again.
#
# With the clock restored, the two specs FAIL — proving they depend entirely on the tenant
# mutation rather than on the fixture.
#
# The durable fix: the specs must derive their dates from the LIVE business date, and the seed must
# build the fixture around that same live date. A test may create data; it must not change the
# product's operating clock.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are removing a defect your predecessor introduced. Read this whole brief before editing.

WHAT HAPPENED
Two browser specs needed a PRIOR day that is still open with an unfinalized PM shift, and the
state kept being consumed, so they were red. The previous lane fixed that by extending
database/seeds/browser_environment.php to recreate the fixture - AND by pinning the TEST TENANT's
operating clock so that the app's "today" landed on the date the specs hard-code:

    operating_timezone -> 'Pacific/Midway'
    close_of_day_time  -> '00:00'

That is unacceptable and it is what you are fixing:
  1. Tenant 207 is the tenant the OWNER logs into by hand. Before the seed its sheet read
     "Timezone: Asia/Manila / Cutoff: 23:59" with business date 2026-10-05. The seed silently moved
     the tenant's business date to 2026-10-04, so the owner's manual testing showed the wrong day.
     A test may create data. It must NOT move the product's clock.
  2. It also EXPIRES: once no timezone puts "now" on 2026-10-04 (about 2026-10-05 12:00 UTC) the
     seed warns and both specs go red again. A fix with a twelve-hour shelf life is not a fix.

The chair has ALREADY restored tenant 207 to operating_timezone='Asia/Manila',
close_of_day_time='23:59'. Verified now: business date resolves to 2026-10-05.

CURRENT STATE, MEASURED with that clock restored and WITHOUT re-running the seed:
    php tests -> unaffected
    npx playwright test daily-ledger-nextday-entry.spec.js daily-ledger-prior-ledger-link.spec.js
        -> 2 FAILED
i.e. those two specs pass ONLY because of the tenant mutation. That is the thing to remove.

WHAT TO DO

PART A - the specs must derive their dates from the app, not hard-code them.
  tests/browser/daily-ledger-nextday-entry.spec.js
  tests/browser/daily-ledger-prior-ledger-link.spec.js
  Both currently hard-code 2026-10-03 (prior) and 2026-10-04 (today). Read the LIVE business date
  instead - the ledger pages render it (the sheet shows "Business date: ..."), and the specs
  already log in and can read it from the DOM. Then:
      today = the live business date
      prior = today minus one day
  and drive the navigations/assertions from those two values.
  KEEP EVERY ASSERTION INTACT. You are changing WHERE THE DATES COME FROM, nothing else:
  `expect(linkCount).toBeGreaterThan(0)`, `expect(priorStatus).toBe('open')`,
  `expect(banner).toBeVisible()`, `expect(nextStatus).toBe('open')` and the write/persist checks
  must all stay exactly as strong. Removing an assertion to get green is a FAIL, not a fix.

PART B - the seed must build the fixture around the live business date, and touch no clock.
  database/seeds/browser_environment.php
    - DELETE the operating_timezone / close_of_day_time / auto_close_enabled writes entirely. The
      seed must not write those keys at all, under any circumstance.
    - Compute the live business date the same way the app does and create the fixture for
      `today` and `today - 1` (prior open with reopened_at set so the render-time auto-close and
      auto-finalize leave it alone, prior PM shift unfinalized, next day open, plus whatever PM
      shift rows the specs read).
    - Keep it idempotent (ON DUPLICATE KEY), as it is now.

PART C - leave the tenant exactly as it is now.
  Do NOT re-pin anything. Do NOT "restore" Asia/Manila in the seed either - simply do not touch
  the key. The seed's job is data, not configuration.

ACCEPTANCE
  The gate command (currently FAILS on the base, because the seed moves the clock AND the specs
  fail without that move):
    a=$(php tools/read-dl-clock.php) && \
    php database/seeds/browser_environment.php >/dev/null 2>&1 && \
    b=$(php tools/read-dl-clock.php) && \
    [ "$a" = "$b" ] && \
    APP_URL=http://baronledger.test npx playwright test \
      tests/browser/daily-ledger-nextday-entry.spec.js \
      tests/browser/daily-ledger-prior-ledger-link.spec.js --reporter=line 2>&1 | grep -q '2 passed'
  `tools/read-dl-clock.php` prints "timezone|cutoff|business_date" for tenant 207.

  Prove it is DURABLE, not just green today: change the machine's date forward by a day using
  the tools available to you, or otherwise demonstrate that the specs still pass for a DIFFERENT
  business date. If you cannot move the clock, say so plainly in the report rather than claiming
  durability you did not measure.

  Then the whole daily-ledger browser set must pass:
    APP_URL=http://baronledger.test npx playwright test \
      tests/browser/daily-ledger-reopen-edit.spec.js tests/browser/daily-ledger-nextday-entry.spec.js \
      tests/browser/daily-ledger-close-failure-guidance.spec.js tests/browser/daily-ledger-settled-endings.spec.js \
      tests/browser/daily-ledger-prior-ledger-link.spec.js tests/browser/daily-ledger-sales-pending-marker.spec.js \
      --reporter=line
    -> 9 passed

  No PHP regressions:
    php tests/daily-ledger/daily_ledger_c1_derived_ending_test.php       -> 7/7
    php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   -> 11/11
    php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    -> 21/21
    php tests/daily-ledger/daily_ledger_reporting_test.php               -> 76/76
    php tests/daily-ledger/daily_ledger_production_controls_test.php     -> 70/70
    php tests/daily-ledger/daily_ledger_routes_test.php                  -> 82/82

IMPORTANT
  - APP_URL IS REQUIRED for playwright; without it the config falls back to http://palsystem.test
    and every spec fails at the login selector with "We could not find that page."
  - Assertions stay as strong as they are. Dates become dynamic; that is the only relaxation.
  - If a spec genuinely cannot derive the date it needs, report BLOCKED with what you found rather
    than re-introducing a clock pin.
  - Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  how each spec now obtains `today` and `prior`
  confirm the seed writes NO operating_timezone / close_of_day_time / auto_close_enabled
  the before/after clock line from tools/read-dl-clock.php around a seed run (must be identical)
  durability: what you did to show it is not a one-day fix, or that you could not
  verification: exact commands and numbers, including the full 9-test browser set
  logs:
  risks / unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/specs-use-live-business-date
rc=$?
echo "lane: specs-use-live-business-date — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

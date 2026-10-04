#!/usr/bin/env bash
#
# Lane: duplicate-withdrawal-guard
#
# Closes ONE defect: an identical offline withdrawal re-apply is not rejected, so a client retry
# with a DIFFERENT idempotency key records the money twice.
#
# MEASURED on the base (chair, 2026-10-04):
#   tests/daily-ledger/daily_ledger_offline_pwa_test.php    107/109  (2 red)
#   tests/daily-ledger/daily_ledger_shift_target_test.php    35/38   (3 red)
#
# All five red assertions are the SAME defect. Evidence from shift_target:
#   expects dl_daily_ledger addtl {AM:1,PM:1} and 2 withdrawal rows
#   measured                                            {AM:1,PM:2} and 3 rows
# i.e. the PM line was recorded twice.
#
# The mechanism ALREADY EXISTS but cannot fire - see THE CRUX in the prompt.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing ONE defect in the Ikabud Daily Ledger. Nothing else.

THE DEFECT

An identical offline withdrawal re-apply is NOT rejected, so a retry records the withdrawal
twice. Five assertions across two suites are red for this one reason.

MEASURED ON THE BASE (do not re-derive, verify if cheap):
  php tests/daily-ledger/daily_ledger_offline_pwa_test.php    107/109
  php tests/daily-ledger/daily_ledger_shift_target_test.php    35/38

RED ASSERTIONS - all one defect:
  [offline_pwa]
    - offline withdrawal DB guard rejects identical re-apply with a different key
    - offline withdrawal DB guard adds no duplicate row
  [shift_target, section "Top-bar server clock"]
    - replaying the identical PM line is still rejected
    - both shifts carry the adjustment        expects {AM:1,PM:1}   measured {AM:1,PM:2}
    - exactly two withdrawal rows exist (AM + PM)   expects 2      measured 3

THE MECHANISM THAT ALREADY EXISTS - DO NOT REBUILD IT

modules/daily-ledger/handlers-offline.php (~line 751) already:
  - binds :dedup = $dedupHash into the dl_cashier_withdrawals INSERT
  - catches PDOException, and on dl_isDuplicateKeyError() throws DlDuplicateWithdrawalException
  - which becomes a 409 conflict receipt -> quarantine, no retry
The comment there calls it the "DB-level dedup guard". So the design is already in place.

THE CRUX - THIS IS THE WHOLE TASK, DO NOT GUESS IT

The dedup hash is derived from the client's IDEMPOTENCY KEY via dl_withdrawalSubmissionId($idempotencyKey).
A retry that mints a NEW key therefore produces a NEW hash, so the guard CANNOT fire and the
line is recorded again. The red assertions require an identical LINE to be rejected REGARDLESS
of the key. So the guard must key on the LINE's natural identity, not on the client's key.

TWO THINGS ALREADY SOLVE THIS PROBLEM - READ THEM BEFORE WRITING ANYTHING:

  1. THE RECEIVE TWIN. php tests/daily-ledger/daily_ledger_receive_offline_guard_test.php is
     11/11 and INCLUDES A REVERT-FAILING CASE (removing the guard makes the write proceed) -
     so that guard is proven, not merely asserted. It is the same problem on the sibling path.
     FIND how the receive path derives its dedup identity and MIRROR it. Do NOT invent a rule.

  2. dl_withdrawalSubmissionId() - read it. It may already have a "no key supplied" behaviour
     (the comment at :730 says the queued op mints one when it carries no key, and that it
     "must match the online path"). Understand WHEN a hash is deterministic and when it is not.
     There may also be an ONLINE withdrawal path (apiSaveCashierWithdrawals) that must agree.

THE CONSTRAINT THAT MAKES THIS HARD - a legitimately repeated withdrawal MUST STILL INSERT.
An operator may make two trips for the same product on the same date and shift. Choose the
natural key so that a genuine repeat is still accepted, and write a comment above the guard
naming the natural key and stating what a legitimate repeat must differ by. If you conclude
that no natural key can distinguish a retry from a genuine repeat without a PRODUCT DECISION,
report BLOCKED with the two candidate semantics - do NOT pick one silently.

WHAT YOU MAY CHANGE
  - modules/daily-ledger/handlers-offline.php and, if the online path is implicated,
    modules/daily-ledger/handlers.php - only as far as this one guard requires
  - ONE new migration for any index/constraint: modules/daily-ledger/database/migrations/075_*.sql
    (075 IS FREE - verified; 074 is the current highest). Register it in module.json.
    It must be guarded/idempotent like 074, use ENGINE=InnoDB semantics, and must NOT use any
    MySQL 8 construct (no CTE, no window function, no JSON_TABLE) - the target is MySQL 5.7.
  - a test file ONLY if an assertion is factually wrong - and then say so in the report with
    the evidence that shows the assertion, not the code, is wrong.

WHAT YOU MUST NOT DO
  - Do NOT weaken, delete, skip, reorder or loosen any assertion to reach green.
  - Do NOT delete the duplicate PM row in a fixture to make the count pass. Fix the WRITE PATH.
  - Do NOT touch the receive guard - it is proven 11/11.
  - Do NOT make the withdrawal guard one-sided: a legitimately DISTINCT withdrawal must still
    insert, and a genuinely repeated one must still be rejected. Both directions must hold.
  - Do NOT add a UNIQUE index blind to existing rows. If pre-existing duplicate rows would block
    it, the migration must FAIL LOUDLY listing the offending rows - never silently skip the index
    and never delete data. Report those rows if you find them.

IF THE MIGRATION ADDS A UNIQUE INDEX, PROVE IT BITES
Mirror the receive test's discipline: after the fix, show that WITHOUT the guard the duplicate
is created and WITH it the duplicate is rejected. A guard nobody proved can be reverted is the
failure mode this repo has already been burned by.

ACCEPTANCE - all of these must pass, and all currently FAIL on the base:
  php tests/daily-ledger/daily_ledger_offline_pwa_test.php        -> 109/109
  php tests/daily-ledger/daily_ledger_shift_target_test.php        ->  38/38
  php tests/daily-ledger/daily_ledger_receive_offline_guard_test.php     -> 11/11 (no regression)
  php tests/daily-ledger/daily_ledger_withdrawal_recomputes_sales_test.php  (no regression)
  php tests/daily-ledger/daily_ledger_login_name_and_withdrawal_filter_test.php (no regression)
  php tests/daily-ledger/daily_ledger_production_controls_test.php -> 70/70 (no regression)
  php ikabud module:validate daily-ledger

Check BOTH logs (storage/logs/app.log and storage/logs/error.log) while running - the repo
requires it - and say what you found. A suite failing on an undeclared log line is a real
finding, not noise. Do NOT silence it by loosening the assertion; declare the line with
$h->allowLogLines('needle') ONLY if the line is genuinely expected from a path the test
exercises itself.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  the natural key you chose, and WHY a legitimate repeat still inserts
  the proof that the guard bites (with the guard reverted, and with it in place)
  verification: the exact commands and their numbers
  logs:
  risks / unresolved:

BLOCKED is a correct and useful answer when the natural key needs a product decision, or when
fixing this would require changing another module, or when the tests contradict each other.
Do not improvise past a contradiction - report it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/duplicate-withdrawal-guard
rc=$?
echo "lane: duplicate-withdrawal-guard — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

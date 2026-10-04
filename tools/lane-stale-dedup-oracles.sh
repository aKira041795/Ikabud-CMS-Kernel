#!/usr/bin/env bash
#
# Lane: stale-dedup-oracles
#
# CORRECTS TWO STALE TEST SUITES. It does NOT touch the write path.
#
# Chain, established by `git merge-base --is-ancestor` (not by reading):
#   fa84a2e8  2026-08-15  fix(daily-ledger): DB-level dedup guard for cashier withdrawals
#            -> added the CONTENT-identity guard; added offline_pwa's 2 red assertions
#   0889ac93  2026-09-14  fix(daily-ledger): make the withdrawal duplicate guard shift-aware
#            -> added shift_target's 3 red assertions
#   a971e41c  2026-09-22  daily-ledger: dedup a withdrawal submission by identity, not by content
#            -> DELIBERATELY REVERSED content-identity to submission-identity
# Neither suite is an ancestor of a971e41c, so both assert the SUPERSEDED contract.
#
# A previous lane (duplicate-withdrawal-guard) correctly reported BLOCKED on this, refusing to
# revert a971e41c. This lane closes the other direction: fix the oracle, not the code.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are correcting TWO STALE TEST SUITES in the Ikabud Daily Ledger. You are NOT fixing a bug.

READ THIS FIRST - the write path is CORRECT and you must not change it.

Commit a971e41c (2026-09-22), "dedup a withdrawal submission by identity, not by content",
DELIBERATELY reversed the duplicate guard from content-identity to submission-identity. Its own
message says content-identity "has produced this same bug three times: box-vs-pcs (057),
AM-vs-PM (059), and now a taken-back entry keeping its claim", and it added permanence guards
specifically to stop anyone reverting it:

  - dl_withdrawalSubmissionId() uses the caller's idempotency key and MINTS one when a caller
    sends none: "an absent key means 'a new submission', never a silent fall back to content
    identity - that silent fallback is what made the old behaviour invisible".
  - a FROZEN FINGERPRINT VALUE pins the content fields and their order, so changing them fails
    a suite and forces a migration/re-hash conversation.
  - a SOURCE ASSERTION counts BOTH add paths using the minted identity, "because a half-fix
    that covers one path is invisible in production".
  - a test named "a new submission must not be reported as a duplicate".

So: an identical line on a NEW submission is RECORDED. A replay of the SAME submission is REFUSED.
That is the current, intended contract.

THE FIVE RED ASSERTIONS ARE THE OLD CONTRACT. They have been wrong since 2026-09-22.

Chronology, already verified - do not re-derive unless you doubt it:
  php tests/daily-ledger/daily_ledger_offline_pwa_test.php    107/109   <- 2 stale
  php tests/daily-ledger/daily_ledger_shift_target_test.php    35/38    <- 3 stale
  php tests/daily-ledger/daily_ledger_handlers_test.php       229/229   <- PINS the current contract

  [offline_pwa]  added by fa84a2e8 (2026-08-15, content guard)
    - offline withdrawal DB guard rejects identical re-apply with a different key
    - offline withdrawal DB guard adds no duplicate row
    Both are the wrong way round: a DIFFERENT KEY IS A NEW SUBMISSION and MUST be recorded.
    Note the suite ALREADY has a passing same-key test ("offline withdrawal replay with same key
    is deduped") - that one is the correct half and must be left alone.

  [shift_target]  added by 0889ac93 (2026-09-14, shift-aware content guard)
    - replaying the identical PM line is still rejected
    - both shifts carry the adjustment      expects {AM:1,PM:1}   measured {AM:1,PM:2}
    - exactly two withdrawal rows exist (AM + PM)   expects 2     measured 3
    A no-key replay is a NEW submission, so it IS recorded: {AM:1,PM:2}, 3 rows. The measured
    values are the CORRECT ones; the expectations are stale.

WHAT TO CHANGE - ONLY THESE TWO FILES
  tests/daily-ledger/daily_ledger_offline_pwa_test.php
  tests/daily-ledger/daily_ledger_shift_target_test.php

For each of the five assertions:
  1. Correct it to assert the CURRENT contract, and RENAME it so the name states the contract it
     now checks (e.g. "a new submission with identical content is recorded"). A passing test whose
     NAME describes the old contract is how this rots a second time.
  2. Put a short comment above it naming a971e41c and why the old expectation was wrong, so the
     next reader does not "fix" the code back. This matters more than the assertion itself.
  3. Decide the shape yourself for shift_target: either assert the recorded outcome ({AM:1,PM:2},
     3 rows) OR make the retry carry a stable key and assert the dedup. State which you chose and
     why in your report. Prefer the one that reflects how the CLIENT actually behaves - read the
     offline queue/replay path to find out whether a queued op carries a stable identity across
     retries.

WHAT YOU MUST NOT DO
  - Do NOT modify handlers-offline.php, handlers.php, dl_withdrawalSubmissionId(), the frozen
    fingerprint value, or any non-test source file. If you believe the write path is wrong, STOP
    and report BLOCKED - do not touch it.
  - Do NOT delete an assertion to reach green, and do NOT simply relax an expectation to whatever
    the code happens to return. First establish that the CURRENT contract requires that value, then
    assert it. Deleting an assertion where the contract still holds is a defect, not a cleanup.
  - Do NOT touch daily_ledger_handlers_test.php - it is 229/229 and it is the guard that keeps the
    deliberate design intact. Its continuing pass is the evidence your correction is legitimate.

EVERY CORRECTED ASSERTION MUST BE PROVEN FALSIFIABLE
This repo's own standard (a971e41c was "verified falsifiable by an adversarial review"). A test
that cannot fail is decorative. For each corrected assertion, state the mutation that turns it red
- e.g. "if dl_withdrawalSubmissionId() reused the content hash, this assertion fails with
received 2, expected 1". If you can, actually perform one mutation, observe the red, and revert it.
Report the observed failure text.

ACCEPTANCE - all must pass, and the first currently FAILS on the base:
  php tests/daily-ledger/daily_ledger_shift_target_test.php           -> 38/38
  php tests/daily-ledger/daily_ledger_offline_pwa_test.php            -> 109/109
  php tests/daily-ledger/daily_ledger_handlers_test.php               -> 229/229  (UNCHANGED file)
  php tests/daily-ledger/daily_ledger_receive_offline_guard_test.php  ->  11/11   (no regression)
  php tests/daily-ledger/daily_ledger_withdrawal_recomputes_sales_test.php          (no regression)
  php tests/daily-ledger/daily_ledger_production_controls_test.php    ->  70/70   (no regression)
  php ikabud module:validate daily-ledger

Check BOTH logs (storage/logs/app.log and storage/logs/error.log) throughout and report what you
found. Do NOT silence an undeclared log line by loosening an assertion; use $h->allowLogLines()
only for a line genuinely produced by a path the test itself exercises.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  for each of the 5 assertions: old expectation -> new expectation, and the one-line reason
  the shift_target shape you chose and why (and what the client does on retry)
  falsifiability: the mutation and the observed red for each corrected assertion
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if you find the write path really is wrong, or if a corrected assertion cannot
be made to fail by any mutation (which would mean it does not test anything).
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/stale-dedup-oracles
rc=$?
echo "lane: stale-dedup-oracles — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

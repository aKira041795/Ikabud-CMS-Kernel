#!/usr/bin/env bash
#
# Lane: fix-b-byte-identity-flake
#
# The byte-identity test B is FLAKY and therefore cannot be trusted as the oracle for the
# data-preservation property. Measured by the chair, running it ALONE:
#     run 1: 1 passed | run 2: 1 passed | run 3: 1 FAILED   (~1 in 3)
#
# It is NOT the byte-identity comparison that fails. The failure is a PRECONDITION guard:
#     Error: expect(locator).toHaveText(expected) failed
#     Locator:  locator('#ledger-body .sales-computed')
#     Expected: "105"
#     Received: "92"
#     at drop-queue-loud-retry.spec.js:235
# It hard-codes a COMPUTED BUSINESS VALUE against shared, mutable ledger data (other specs in
# this directory save values), so the guard is non-deterministic.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing ONE flaky browser test in the Ikabud repo at /var/www/html/applicationostest.

## The defect (measured by the chair — this is a diagnosis, not a guess)
`tests/browser/modules/daily-ledger/ledger/drop-queue-loud-retry.spec.js`, test **B**.

Run ALONE with `-g "byte-identical"`, it produced:
    run 1: 1 passed
    run 2: 1 passed
    run 3: 1 FAILED

The failing assertion is a PRECONDITION guard at line ~235:
    await expect(page.locator('#ledger-body .sales-computed')).toHaveText('105');
with
    Expected: "105"   Received: "92"

That literal `'105'` is a COMPUTED BUSINESS VALUE read from shared, mutable ledger data — and other
specs in the same directory save values — so the guard is non-deterministic. It fails ~1 run in 3.

## What is NOT the problem (do not "fix" these)
The byte-identity assertions are correct and MUST NOT be weakened, removed, or retried:
    expect(afterLoad, 'page load must not change any stored entry').toEqual(before);
    expect(afterSync, 'a sync attempt must not change any stored entry').toEqual(before);
    expect(JSON.parse(parsed[info.pending])).toEqual([fixture.valid]);
    expect(JSON.parse(parsed[info.foreign])).toEqual([fixture.foreign]);
    expect(JSON.parse(parsed[info.legacy])).toEqual(fixture.legacy);
    expect(JSON.parse(parsed[info.quarantine])).toEqual(fixture.quarantine);
Those guard data preservation. Leave their semantics exactly as they are.

## Your task
Replace the non-deterministic precondition with a DETERMINISTIC readiness guard that does not depend
on mutable business data — e.g. wait for the ledger body to be populated / for an element that the
spec itself controls to be present. It must still be a REAL guard: if the sheet failed to load, the
test must fail.

## Constraints
- Test-only change. You may edit ONLY
  `tests/browser/modules/daily-ledger/ledger/drop-queue-loud-retry.spec.js`.
- Do NOT touch `templates/modules/daily-ledger/cashier/ledger.disyl`.
- Do NOT weaken, delete or relax any byte-identity assertion.
- Do NOT fix a flake with retries, `waitForTimeout`, `expect.poll` on a business value, or by
  loosening the matcher. The guard must become DETERMINISTIC, not luckier. (Repo policy: do not use
  retries to hide instability.)
- Do not hard-code any other business value.

## Acceptance — prove BOTH directions
1. DETERMINISM: run the test alone 5 consecutive times and show 5/5 PASS:
   `for i in 1 2 3 4 5; do APP_URL=http://baronledger.test npx playwright test \
      tests/browser/modules/daily-ledger/ledger/drop-queue-loud-retry.spec.js \
      -g "byte-identical" --reporter=line; done`
   APP_URL is REQUIRED, or every spec fails at the login selector with a misleading
   "We could not find that page."
2. THE GUARD STILL FAILS: demonstrate that your new readiness guard actually fails when the sheet
   does not load (block the ledger document/route, or point the spec at a route that yields no
   ledger body) and show the failure. A guard that cannot fail is not a guard.
3. Byte-identity still enforced: show the full spec file green (all 7 tests).
4. Report `storage/logs/error.log` and `storage/logs/app.log` for anything new.

## Report
status (PASS / FAIL / PARTIAL / BLOCKED), the exact change, the 5-run evidence, the must-fail
evidence for the new guard, the full-spec result, and anything you could not verify.
If you cannot make the guard deterministic without weakening the byte-identity assertions, report
BLOCKED with the precise reason — do NOT trade determinism for a weaker preservation check.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/fix-b-byte-identity-flake
rc=$?
echo "lane: fix-b-byte-identity-flake — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

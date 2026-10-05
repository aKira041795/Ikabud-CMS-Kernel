#!/usr/bin/env bash
#
# Lane: admin-refusal-visibility
#
# RESOLVES the blocker from lane cashier-truth-3-states and HARPP decision #144, option (1):
# expand scope to the backend + admin surface so a refusal diagnostic and pending marker ARE
# persisted before the refusal and DO reach the admin.
#
# MEASURED BY THE CHAIR (in modules/daily-ledger/handlers-offline.php):
#   - dl_offlineRecordPendingReport() is called at line 1552, AFTER the enrollment verdict at
#     line 1534, inside apiOfflineReconcile(). So a whole-batch refusal
#     (expired/revoked/account-mismatch/schema-mismatch/inactive) records NO pending count and
#     NO reason. The alarm is wired to the wrong side of the same switch.
#   - Live proof: the branch-8 tablet has last_reported_pending_count = 0 and pending_since = NULL
#     while holding up to five weeks of unsynced edits.
#   - The admin device list filters "AND e.last_reported_pending_count > 0" (line ~1259), so a
#     device appears ONLY once it has successfully reported. An expired/revoked device is
#     therefore invisible precisely when it needs an admin most.
#
# OWNER'S FRAMING: "we just ensure these things are covered." The admin is the only actor who can
# reopen a closed day, so the admin is the actor who must be told.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing an admin-visibility gap in the Daily Ledger module at /var/www/html/applicationostest.

## The gap (measured, not theory)
In `modules/daily-ledger/handlers-offline.php`, `apiOfflineReconcile()`:

    line ~1534   $valid = dl_offlineValidateEnrollment($user, $row);
    line ~1535   if (!$valid['ok']) { dlJson([...], 403); return; }     <-- refusal returns here
    line ~1552   dl_offlineRecordPendingReport($row, pending_count, pending_since, pending_fields);

A whole-batch refusal therefore records NO pending marker and NO reason. Live evidence: the branch-8
device shows `last_reported_pending_count = 0` and `pending_since = NULL` while holding up to five
weeks of unsynced edits.

And the admin device list filters `AND e.last_reported_pending_count > 0` (~line 1259), so a device is
listed ONLY once it has successfully reported. An expired or revoked device is invisible exactly when
it most needs attention.

## Why this matters
The admin is the only actor who can reopen a closed day and the only one who can act on a stranded
device. A device that cannot report is a device the admin cannot see. This is the same class of silent
failure that let the original incident run five weeks unnoticed.

## Your task
1. **Record the pending report BEFORE the enrollment verdict**, so a refusal can never suppress it.
   The report is a VISIBILITY MARKER ONLY — it must authorize nothing.
2. **Make the admin surface list a device that holds reported unsynced work even when its enrollment
   is expired, revoked, or otherwise not active**, and SHOW that state so the admin knows to act.
3. **Surface the refusal reason per device to the admin** — the precise diagnostic
   (expired / revoked / account-mismatch / schema-mismatch / inactive) that the cashier's sheet
   deliberately omits in favour of plain language.

## HARD CONSTRAINTS (violating any of these fails the task)
- **The refusal must STILL refuse.** An invalid grant must NOT be able to sync. Prove both directions:
  the marker is recorded AND the batch is not processed.
- **Do NOT weaken the closed-day guard, and do NOT widen any cashier access.**
- **Do NOT change the cashier-facing three-state plain language** from lane `cashier-truth-3-states`.
  The precise diagnostic belongs on the admin side; the cashier keeps plain language and never needs
  the words queue, grant, reconcile or enrollment.
- **Reuse the existing admin surface and notification channel.** Do not build a second one.
- **MySQL 5.7 safe:** no window functions, no CTEs, no JSON_TABLE, no `LIMIT` inside an
  `IN (subquery)`, InnoDB, FK types matched. State your 5.7 reasoning.
- **Do NOT touch `public/daily-ledger/assets/` or `public/daily-ledger/sw.js`** — the service worker
  precaches those cache-first from a hard-coded cache version, so changes there silently never reach
  any device.
- Files: `modules/daily-ledger/handlers-offline.php`, `modules/daily-ledger/handlers.php`,
  `modules/daily-ledger/helpers/**`, the admin dashboard template, and `tests/**`.

## METHOD
1. BEFORE changing anything, capture the RED state: show that a REFUSED batch carrying a
   `pending_count` records nothing, and that an expired-grant device is absent from the admin list.
2. Smallest change that satisfies it.
3. Re-run and show GREEN.

## ACCEPTANCE (RED pre-change; show actual output)
A. A REFUSED batch carrying `pending_count`/`pending_since`/`pending_fields` DOES record the pending
   report for that device. Pre-change: the refusal returns first, so nothing is recorded.
B. The admin device list includes a device whose grant is EXPIRED or REVOKED while it holds reported
   unsynced work, with its enrollment state visible. Pre-change: `last_reported_pending_count > 0`
   gates it and a refusal never sets it, so it is invisible.
C. The per-device refusal reason is visible on the admin side.
D. **The guard still bites:** the refused batch is NOT processed and no operation is applied — prove
   it alongside A, so A cannot have been achieved by letting the batch through.
E. The cashier-facing three-state plain language is unchanged, and no raw diagnostic leaked onto the
   cashier's sheet.

## VERIFICATION YOU MUST RUN AND REPORT
- `php -l` on every changed PHP file.
- The daily-ledger PHP suites with pass counts (note: `dispatch_enforcement` and
  `preserve_cashier_variance` fail PRE-EXISTINGLY on notification-count pollution — confirm they fail
  the same way and are not made worse).
- `APP_URL=http://baronledger.test npx playwright test tests/browser/modules/daily-ledger/` and report
  counts.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.
- Re-run each acceptance item 3x to show it is DETERMINISTIC — a flaky oracle is worse than none.
- MySQL 5.7 reasoning for any changed SQL, stated explicitly.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, RED/GREEN evidence for A-E, suite counts,
determinism runs, your MySQL 5.7 reasoning, anything you could NOT verify, and any ambiguity.
If you cannot satisfy an item without weakening the guard or widening cashier access, report BLOCKED
with the precise reason. Do NOT weaken a criterion or a guard to reach PASS.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/admin-refusal-visibility
rc=$?
echo "lane: admin-refusal-visibility — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

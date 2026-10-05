#!/usr/bin/env bash
#
# Lane: drop-queue-loud-retry
#
# OWNER DIRECTIVE (2026-10-05, verbatim):
#   "my 2 cents, if device has slow connection, drop the queue and have the cashier retry.
#    we will solve the offline data sync in another session"
#
# So this slice does ONE thing: a ledger cell save must never be silently queued and must never
# present an unconfirmed write as saved. The offline path itself is DEFERRED, not fixed here.
#
# WHY PRESERVATION IS THE HARD CONSTRAINT:
#   A Miputak tablet holds the ONLY copy of up to ~5 weeks of offline-encoded cashier data.
#   `dl_offline_sync_receipts` = 0 and `last_sync_at` IS NULL on the single enrollment: the server
#   never received any of it. It is STILL THERE only because the server refused every batch BEFORE
#   processing ops (grant expired 2026-08-29). Nothing has been destroyed yet. Do not destroy it.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a bounded, safety-critical change in the Ikabud repo at
/var/www/html/applicationostest.

## OWNER DIRECTIVE (verbatim)
"my 2 cents, if device has slow connection, drop the queue and have the cashier retry.
 we will solve the offline data sync in another session"

## BACKGROUND (measured by the chair, not theory)
- A Miputak tablet holds the ONLY copy of up to ~5 weeks of offline-encoded cashier data.
  `dl_offline_sync_receipts` = 0 and `last_sync_at` IS NULL on the single enrollment, i.e. the
  server never received any of it. It survives only because the server refused every batch BEFORE
  processing ops (the device grant expired 2026-08-29).
- The offline path is NOT being fixed in this slice. It is DEFERRED.
- Your job is exactly two things: (1) stop NEW ledger saves from silently queueing and make a failed
  save loud and retryable, and (2) do not damage anything already stored.

## THE TREE ALREADY CONTAINS uncommitted work from lane `offline-queue-scope-stranding`
KEEP these parts:
  - the destructive "Purge stale saves" button is removed — keep it removed;
  - `syncPendingStorage()` no longer blindly deletes another actor's queue key;
  - the banner reports foreign-queued and quarantined counts honestly.
REMOVE this part, and explain the reason in your report:
  - the AUTO-MIGRATION: `canMigratePendingEntry()` and `mergeMigratedPending()` and the code that
    re-scopes another actor's stored entries onto the current actor's sheet and then reduces the
    source key. It would replay up to 5 weeks of stranded entries on first open, onto days that are
    already CLOSED, where the server currently answers 403 'Reference only' / 403 'Day is closed'
    and records a PERMANENT rejection — destroying the very data it is meant to rescue.

## YOUR TASK
1. A ledger cell save must never be silently queued, and must never present an unconfirmed write as
   saved. When the save cannot be confirmed by the server, show an explicit, persistent, actionable
   failure stating the value was NOT saved and to retry. Do not fabricate success. Do not create a
   new pending/queued entry for that edit.
2. Keep the value the cashier typed available so they can retry without re-entering it.
3. Preserve every existing stored entry (pending, legacy, foreign, quarantined) EXACTLY.
   No migration, no re-scoping, no removal, no purge.
4. Leave the offline vault / enrollment / reconcile code in place and untouched, for the later
   session.

## HARD CONSTRAINTS (violating any of these fails the task)
- Do NOT delete, purge, rewrite, re-scope or migrate ANY stored pending/quarantined entry.
- Do NOT restore the "Purge stale saves" control.
- Do NOT turn a failed save into an apparent success.
- Do NOT break the ONLINE success path: a save the server confirms must still show success.
- Do NOT touch the server-side reconcile classification or the offline endpoints.
- Do NOT weaken auth / CSRF / session handling.
- Files you may change: `templates/modules/daily-ledger/cashier/ledger.disyl` and `tests/**` only.

## ACCEPTANCE (each MUST fail on the pre-change tree; record the pre-change result FIRST)
A. With the save endpoint forced to fail (network error or non-2xx) for a ledger cell, the cashier
   sees an explicit "not saved — retry" indication, the cell is visibly unsaved, and NO new
   pending/queued entry is created for that edit.
   Pre-change: the edit is queued and presented as saved.
B. BYTE-IDENTITY: every pre-existing stored entry is UNCHANGED after a page load and after a sync
   attempt. Build a fixture of stored entries (pending + foreign + legacy + quarantine keys) in
   localStorage, load the sheet, attempt a sync, then compare stored content before/after. Report the
   comparison.
C. A save the server confirms still succeeds (no regression).
D. Static: `canMigratePendingEntry` and `mergeMigratedPending` no longer exist in the file.

## METHOD — do not skip this
1. BEFORE changing anything, establish the baseline: run your acceptance for A and B against the
   UNCHANGED tree and capture the failing output. If a criterion does not fail on the current tree,
   say so rather than weakening it.
2. Make the smallest change that satisfies the acceptance.
3. Re-run; report RED-before / GREEN-after for A and B with the actual output.

## VERIFICATION YOU MUST RUN AND REPORT
- Playwright: `APP_URL=http://baronledger.test npx playwright test <your spec>`.
  APP_URL is REQUIRED — without it every spec fails at the login selector with a misleading
  "We could not find that page."
- Run the daily-ledger suites you can, and report pass counts.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, the pre/post evidence for A and B, the
suites run with counts, anything you could NOT verify, and any ambiguity in the directive.
If you cannot satisfy a criterion, report BLOCKED with the precise reason. Do NOT weaken a criterion,
and do NOT improvise around data preservation.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/drop-queue-loud-retry
rc=$?
echo "lane: drop-queue-loud-retry — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

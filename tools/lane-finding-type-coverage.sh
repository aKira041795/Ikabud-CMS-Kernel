#!/usr/bin/env bash
#
# Lane: finding-type-coverage
#
# COVERAGE GAP (measured by the chair):
#   dl_integrity_notifications.finding_type is an ENUM:
#     enum('variance','unresolved_origin','uncounted_receipt','historical_digest','receipt_mismatch')
#   The D4 notifications raised by 531106f8 for "the day did not close because the PM shift is still
#   open" had to be filed as 'variance', because that lane's allowed file set excluded migrations.
#   Measured right now: 2 such rows, sitting among 38,429 genuine 'variance' rows.
#
#   So the admin IS told - but an accountant filtering by type CANNOT find it. The alert exists and is
#   not addressable. Owner's framing: "we just ensure these things are covered."
#
# Owner's guards that must not be weakened:
#   - "the closed day is a process set and only admin can re-open. that's a fact and a guard."
#   - a shift must stay unfinalized + flagged so the admin can ask why encoding was not finished.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing a COVERAGE gap in the Daily Ledger module at /var/www/html/applicationostest.

## The gap (measured, not theory)
`dl_integrity_notifications.finding_type` is an ENUM:
    enum('variance','unresolved_origin','uncounted_receipt','historical_digest','receipt_mismatch')

Commit `531106f8` added admin notifications for a business day that did NOT close because its PM shift
is still open (`closed_without_pm_finalize`). Because that commit's allowed file set excluded
migrations, the notification had to reuse the existing `'variance'` type. Live count right now: **2**
such rows among **38,429** genuine `'variance'` rows.

Consequence: an accountant filtering the admin notification surface by type cannot find these events,
and they look like variances. The admin is told, but the signal is not addressable.

## Your task
1. **Add a migration** for the enum change. **DO NOT ASSUME THE NUMBER — determine it at run time.**
   Run `ls modules/daily-ledger/database/migrations/ | grep -oE "^[0-9]+" | sort -n | tail -1` and use
   the NEXT free number after the highest. When this brief was written 074 was the highest and 075 was
   free, but a later lane claimed `075_offline_refusal_visibility.sql`, so the next free number is now
   **076** — verify that for yourself and use whatever is actually next at the moment you run.
   Rationale: a duplicated migration number is a real defect (there is already a pre-existing duplicate
   at 041). Do NOT renumber or touch that historical one. The migration must extend the
   `finding_type` ENUM on `dl_integrity_notifications` with a single new value,
   `closed_without_pm_finalize`.
2. **Switch the two D4 call sites** in `modules/daily-ledger/handlers.php` from `'variance'` to the new
   value (the two `closed_without_pm_finalize-day-...` and `closed_without_pm_finalize-pm-...` sites).
3. **Register the migration in `modules/daily-ledger/module.json`** the same way its neighbours are.
4. **Check whether the admin surface maps finding_type to a label.** If it does, add the new value's
   label. If it renders the type raw, confirm nothing breaks.

## HARD CONSTRAINTS (violating any of these fails the task)
- **NEVER drop, rename or reorder an existing ENUM value.** Reordering an ENUM silently corrupts every
  existing row that stores a later ordinal. The migration must only ADD the new value at the END of the
  existing list, preserving all five current values in their current order.
- **MySQL 5.7 safe.** There is no 5.7 server available locally (the test server is 8.0.46), so you must
  reason explicitly: `ALTER TABLE ... MODIFY COLUMN ... ENUM(<existing five>, 'closed_without_pm_finalize')`
  with the same NULL/NOT NULL and default characteristics. No window functions, no CTEs, no JSON_TABLE,
  no `LIMIT` inside `IN (subquery)`. State your 5.7 reasoning in the report.
- **The migration must be re-runnable** (guarded the way the neighbouring migrations are), because the
  runner tracks by filename but a re-run must not error.
- **Do NOT change the 38,429 existing `'variance'` rows.** No backfill, no relabelling of history.
- **Do NOT weaken the closed-day guard, and do NOT suppress or auto-resolve the unfinalized signal.**
- **Do not build a second notification channel** — reuse `dl_raiseIntegrityNotification()`.
- Files: `modules/daily-ledger/database/migrations/**`, `modules/daily-ledger/module.json`,
  `modules/daily-ledger/handlers.php`, the admin template only if a label map needs the new value, and
  `tests/**`.

## METHOD
1. BEFORE changing anything, capture the RED state: show that a `closed_without_pm_finalize` event
   currently produces a row with `finding_type = 'variance'`.
2. Make the smallest change that satisfies it.
3. Re-run and show GREEN.

## ACCEPTANCE (RED pre-change; show actual output)
A. A `closed_without_pm_finalize` event produces a `dl_integrity_notifications` row whose
   `finding_type` is the NEW value, not `'variance'`. Pre-change: it is `'variance'`.
B. **Enum integrity proven:** after the migration, every one of the five ORIGINAL values is still valid
   and a pre-existing row that stored each of them is unchanged. Prove it by reading them back.
C. The migration is re-runnable: running the apply path twice does not error and does not change the
   enum twice.
D. The admin notification surface still renders the notification with the new type (no breakage).
E. The count of `'variance'` rows is UNCHANGED by your migration (no historical relabelling).

## VERIFICATION YOU MUST RUN AND REPORT
- Apply the migration to the tenant and show the resulting column definition (`SHOW COLUMNS`).
- `php -l` on every changed PHP file.
- The daily-ledger PHP suites with pass counts (note: `dispatch_enforcement` and
  `preserve_cashier_variance` fail PRE-EXISTINGLY on notification-count pollution — confirm they fail
  the same way and are not made worse).
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.
- MySQL 5.7 reasoning for the ALTER, stated explicitly.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, RED/GREEN evidence for A-E, the resulting column
definition, suite counts, your MySQL 5.7 reasoning, anything you could NOT verify, and any ambiguity.
Report BLOCKED with a precise reason rather than weakening a criterion or an enum guarantee.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/finding-type-coverage
rc=$?
echo "lane: finding-type-coverage — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

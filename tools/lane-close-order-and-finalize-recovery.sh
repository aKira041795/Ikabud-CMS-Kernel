#!/usr/bin/env bash
#
# Lane: close-order-and-finalize-recovery
#
# THE INCIDENT: cashiers cannot save on Miputak since the Oct 1-3 production integration.
#
# MEASURED BY THE CHAIR (evidence, not theory):
#   - The day auto-closes while the PM shift is still open. Live state on EVERY damaged date:
#     day=closed with shift_status PM=open (09-25, 09-28, 09-30, 10-01, 10-03, 10-04).
#   - Once the day is closed, a cashier is locked out of BOTH paths:
#       dl_cashierMayEdit(branch, date, 'AM'|'PM', today, 'closed') returns FALSE  (measured)
#         -> every cell write is refused 403 'Reference only'
#       apiFinalizePmShift() -> 422 'This business date is closed'
#       (and for a cashier any date other than today/yesterday is 403 'Reference only')
#   - Only a PM ending has a bridge: $lateEndingEligible requires $column==='bal_end' && $shift==='PM'.
#   - Finalization is gated on EVERY active product having a PM ending:
#       $missing counts active products with (cpl.id IS NULL OR cpl.actual_end_qty IS NULL)
#       and $missing > 0 sets finalized = false (flag only, audit 'closed_without_pm_finalize').
#   - That event reaches NOBODY: it only sets pending_notified_at (a one-shot) and writes an audit
#     row. It never calls dl_raiseIntegrityNotification(), while uncounted_receipt, variance and
#     unresolved_origin all DO. A missing receipt count alerts admins; a whole day closing without
#     its endings does not.
#   - Damage, branch 8: 09-25 PM 79/79, 09-28 AM 92/92, 10-03 AM 73/71, 10-04 AM 71/71, 10-04 PM
#     77/77; 1,164 NULL endings; last write 2026-10-04 20:41:08. Whole shifts with no rows: 09-26 AM,
#     09-30 PM, 10-03 PM.
#
# OWNER'S GUARD (must not be weakened): "the closed day is a process set and only admin can re-open.
# that's a fact and a guard."

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the fix for a PRODUCTION incident in the Ikabud repo at
/var/www/html/applicationostest. Work from the authoritative contract at
`.ai/dl-close-order-and-finalize-recovery.contract.md` — READ IT FIRST, it has the measurements.

Summary of the four deliverables:

**D1 — Order the close after the work is finalized.** A day must not close while a shift is still open
and expected to write. Either the close follows PM finalization, or the close is refused/flagged while
a shift is unfinalized. `day=closed` + `PM=open` must not be a state a cashier is stranded in. This is
the primary fix: the cashier was refused because the day closed before they finished.

**D2 — The refusal must name the sanctioned remedy, and that remedy must work.**
A closed day is a DELIBERATE PROCESS GUARD. The owner's words: *"the closed day is a process set and
only admin can re-open. that's a fact and a guard."*
- Do **NOT** widen a cashier's write access on a closed day. No new cashier bridge, no AM exception.
  Letting cashiers past the guard is a guard weakening, not a fix.
- DO make the refusal honest: a cashier refused on a closed day must be told the day is closed and
  that an **admin must reopen it**, instead of the opaque `403 'Reference only'`.
- DO verify the existing admin reopen path (`apiReopenDay`, and the `reopened_at` exemption that
  already suppresses auto-close/auto-finalize) actually works for a day that closed with endings
  missing, so the sanctioned repair is usable end to end.

**D3 — Do not count no-movement products as missing.** `$missing` must count only cells that were
expected to hold a value — products with activity (a beginning movement, a delivery, a sale). A cell
with no movement is not a gap and must not block finalization. Keep `NULL` meaning exactly one thing:
"not yet entered". Owner: *"why would I, as a human, keep on entering zero data in cells of products
discontinued?"*

**D4 — Tell the admin immediately.** `closed_without_pm_finalize` must raise an admin notification
through the EXISTING `dl_raiseIntegrityNotification($db, key, type, branchId, entityType, entityId,
title, detail, email)` — it already addresses active admins and branch supervisors, dedups by
`aggregate_key`, tracks `seen_at`, appears in the admin surface, and can email. REUSE it. Do not build
a second notification channel. The admin is the only actor who can reopen a closed day, so they must
be the one who is told.

## HARD CONSTRAINTS (violating any of these fails the task)
- Do NOT weaken the closed-day guard or the admin-only reopen.
- Do NOT force-finalize, and do NOT invent or fabricate a count. A missing ending stays missing.
- Do NOT lift the immutability of an already-finalized shift.
- Do NOT gut a guard: a product WITH movement and NO ending must still flag/block finalization.
- Do NOT weaken auth/authorization or let an unauthorized actor through.
- Do NOT add any cashier-facing step, dialog, page or extra tap. Owner: "a good UI offers few clicks
  to the user. the backend takes care of the complexity."
- Do NOT remove the deliberately-reopened-day exemption (`reopened_at` must still suppress
  auto-close and auto-finalize).
- Files: `modules/daily-ledger/handlers.php`, `modules/daily-ledger/handlers-offline.php`,
  `modules/daily-ledger/helpers/*.php` if needed, and `tests/**` only.

## METHOD
1. BEFORE changing anything, reproduce and capture the RED state for each acceptance item below
   against the UNCHANGED tree. If a criterion does not fail on the current tree, SAY SO rather than
   weakening or inventing one.
2. Make the smallest change that satisfies it.
3. Re-run and show GREEN.

## ACCEPTANCE (each RED on the pre-change tree; show actual output)
A. **(D1)** Reproduce `day=closed` + `PM=open` as a cashier dead-end, then show it cannot occur: after
   the close path runs, the PM shift is finalized, or the close did not proceed while it was open.
B. **(D2)** A cashier writing to a closed day is STILL REFUSED — PROVE the guard holds — and the
   refusal names the real remedy (the day is closed; an admin must reopen it) rather than the bare
   `403 'Reference only'`. Then prove the sanctioned repair end to end: an admin reopens the day and a
   subsequent cashier write succeeds.
C. **(D3)** A shift in which EVERY product with movement has an ending FINALIZES even though
   no-movement products have no ending. Pre-change: `$missing > 0` -> `finalized = false`.
D. **(D3, other direction)** A product WITH movement and NO ending STILL flags/blocks. A guard that
   cannot fail is not a guard.
E. **(D4)** `closed_without_pm_finalize` creates a `dl_integrity_notifications` row addressed to the
   branch's admins/supervisors and visible in the admin surface. Pre-change: no row exists.
F. No cashier-facing step or extra tap is added anywhere.

## VERIFICATION YOU MUST RUN AND REPORT
- `php -l` on every changed PHP file.
- The daily-ledger PHP integration suites, with pass counts.
- Browser specs you touch, with `APP_URL=http://baronledger.test` (REQUIRED, or every spec fails at
  the login selector with a misleading "We could not find that page.").
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`.
- MySQL 5.7 compatibility: no window functions, no CTEs, no JSON_TABLE, no `LIMIT` inside an
  `IN (subquery)`, InnoDB, FK types matched. D3 changes a query — check it.
- Include evidence that the closed-day guard still refuses a cashier (B) — do not report PASS without
  it.

## REPORT
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, the RED/GREEN evidence for A-E, the guard
evidence for B and D, suite counts, anything you could NOT verify, and any ambiguity.
Report BLOCKED with a precise reason rather than weakening a criterion or the guard.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/close-order-and-finalize-recovery
rc=$?
echo "lane: close-order-and-finalize-recovery — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

#!/usr/bin/env bash
#
# Lane: dl-tranche2-areas-remarks
#
# Two client concerns whose scope the client defined themselves (2026-11-09):
#   #7 — "dili nako makita ang manukan, labason, sindangan ug liloy ... c sindangan naa syay
#         consignee-PUREGOLD dili ko ka buhat"  (said by the ADMIN)
#   #3 — "no need makausab ang branch ... need lang naay correcting area both branch ug need naay
#         remarks ... then kami ra diri sa office ang makausab"
#
# Contract: .ai/daily-ledger-nine-concerns-2026-11-09.contract.md
#
# MEASURED BY THE CHAIR:
#   - `dl_areas` (migration 091) exists but is READ-ONLY in the application: `dl_areas` appears only
#     in SELECTs (handlers.php:19927, 19959, 19985, 20000). No route, no handler, no template creates
#     or edits an area. The table is seeded once from DISTINCT historical free-text `area` strings on
#     dl_branches / dl_consignees, so an area that was never typed on a branch or consignee does not
#     exist. `dl_resolveCanonicalArea()` requires an EXISTING active row and throws
#     "Select an active canonical area." otherwise. That is exactly why the admin cannot create
#     PUREGOLD under Sindangan: Sindangan is not a row.
#   - `apiSaveConsignee` (20034) is already wired (route `/daily-ledger/api/v1/admin/consignees`),
#     already admin-only, and already area-aware. It fails only for want of the area row.
#   - `dl_variance_flags` already carries sent_qty, received_qty, resolution_status/choice,
#     original_counted_qty, review_note, reviewed_by/at, and `apiResolveDeliveryVariance` (19190) is
#     already admin-only, already requires a note, already preserves the original count, and already
#     posts exactly one addtl delta. The client does NOT want a new approval machine — they want a
#     place for BOTH branches to write, with the office still the only actor who corrects.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing two client-reported gaps in the Daily Ledger module at /var/www/html/applicationostest.

READ FIRST: `.ai/daily-ledger-nine-concerns-2026-11-09.contract.md`.
The diagnosis is measured. Do not re-diagnose, and do not build more than the client asked for.

Work in place. Do NOT `git checkout`, revert, or touch files outside the listed scope.

=====================================================================
TASK A — an admin cannot create an area, so cannot create the consignee (concern 7)
=====================================================================
`dl_areas` exists (migration 091) but nothing in the application can create or edit a row. Areas were
seeded once from the free-text `area` strings already present on branches/consignees, so Sindangan —
which the client needs for a PUREGOLD consignee — has no row, and `dl_resolveCanonicalArea()` throws.

A1. Add an ADMIN-ONLY create/update surface for areas, mirroring how branches are wired:
      - routes in `modules/daily-ledger/routes.php` next to the branches routes
        (`apiCreateBranch` at `/daily-ledger/api/v1/admin/branches` is the pattern)
      - handler(s) in `modules/daily-ledger/handlers.php`: create + update (name, code, is_active,
        sort_order). Guard with `dlCurrentUser(['admin'])`, exactly like `apiSaveConsignee`.
      - an admin UI surface. Prefer adding it to `templates/modules/daily-ledger/admin/branches.disyl`
        (where areas are already selected) over inventing a new page. Follow
        `.github/instructions/ui-ux-conventions.instructions.md`: the new control sits beside the work,
        uses existing design-system classes, labels state what the control actually does.
A2. Validation: name required, trimmed, non-empty, at most the column width; code required and unique
    (surface the duplicate as a clear 422, do not 500); `is_active` explicit. Do NOT silently create an
    area from a free-text name — the client's problem is precisely that free text was never promoted to
    a row, and guessing would resurrect that ambiguity.
A3. **Route <-> contract consistency is enforced in BOTH directions** by
    `tests/daily-ledger/daily_ledger_routes_test.php` against `modules/daily-ledger/workbench-contract.json`
    (GET pages ~L15, GET APIs ~L51, POST APIs ~L89). Every route you add MUST get a contract entry, or
    that test fails. Adding only one side fails the test in the other direction.
A4. Prove the client's actual journey: create an area named for Sindangan, then create a consignee in
    that area through the existing `apiSaveConsignee` path, and show the consignee row exists with the
    right `area_id`. Do this in a test, cleaning up what it creates.

=====================================================================
TASK B — two-sided remarks on a delivery discrepancy (concern 3)
=====================================================================
Today there is ONE `review_note` on the variance row, written by the reviewing office user. Neither
branch has anywhere to record what happened, and the client explicitly asked for that.

The client's own scope: branches do NOT change quantities; they write remarks; only the office corrects.
`apiResolveDeliveryVariance` already enforces the office-only correction. Do not replace it.

B1. Add per-remark storage on the delivery variance record: a SENDER remark and a RECEIVER remark,
    each with the author id, the author's branch id, and a timestamp. A migration is allowed here
    (`modules/daily-ledger/database/migrations/`, next free number — CHECK the directory first, and
    grep `workbench-contract.json` / other lanes for a number already claimed). Follow the repo's
    idempotent-migration conventions and add an `owns_tables` note only if a NEW table is introduced
    (prefer columns on the existing table).
B2. Add an endpoint that lets a branch — and ONLY the branch on the relevant side — record its remark
    against a delivery variance:
      - the sending branch may write the sender remark;
      - the receiving branch may write the receiver remark;
      - anyone else is refused 403;
      - the endpoint changes NO quantity, NO ledger value, and NO resolution status.
    Reuse `dl_authorizeBranch` / the module's existing branch-authorization helpers rather than
    writing a new authorization model.
B3. Make the remarks VISIBLE in the variance surface the office already uses
    (`templates/modules/daily-ledger/admin/variances.disyl`) alongside the existing variance figures,
    so the client's own words — "makita pod na namo kauban sa variance" — are satisfied.
B4. Keep the existing office correction untouched and still admin-only. Do NOT add a branch-approval
    step, an approve/reject branch workflow, or new resolution states beyond what already exists.

=====================================================================
HARD CONSTRAINTS — violating any of these fails the task
=====================================================================
1. No new approval state machine. The client asked for remarks + office-only correction, not a
   multi-stage workflow.
2. Do NOT put a `dl_users` row into a stock-source or location role anywhere.
3. Do NOT weaken `apiResolveDeliveryVariance`'s admin-only guard or its required-note rule.
4. Do NOT edit historical rows. The migration adds nullable columns only; existing rows keep NULL.
5. MySQL 5.7 only: no CTEs, no window functions, no JSON_TABLE. Every `CREATE TABLE` you add must end
   `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
6. DiSyL: no `{...}` inside inline HTML event handlers — DiSyL eats the braces and lints clean while
   producing broken JS. Use delegated addEventListener in a `{block scripts}`.
7. Do NOT edit `$shouldAutoCreateFormalDelivery` or the posting decision at handlers.php ~L5932. That
   is a later tranche with an owner decision attached.
8. Do NOT weaken or delete an existing test to reach PASS.

=====================================================================
VERIFICATION YOU MUST RUN AND REPORT
=====================================================================
- `php -l` on every changed PHP file.
- `php tests/daily-ledger/daily_ledger_routes_test.php` — the route/contract test, in BOTH directions.
- Any variance-related daily-ledger suites you touched, with pass counts.
- New tests: area create + duplicate-code refusal + consignee creation in the new area; and
  sender-remark / receiver-remark authorization (allowed on the right side, 403 on the wrong side,
  and quantities unchanged after a remark). Run each twice for determinism.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log` after every run.
- Report pre-existing failures explicitly as pre-existing, with evidence.

=====================================================================
REPORT
=====================================================================
status (PASS / FAIL / PARTIAL / BLOCKED), exact files changed, migration number used and why it was
free, per-task evidence, test counts, what you could NOT verify, and any ambiguity.
If an item cannot be satisfied without breaking a hard constraint, report BLOCKED with the precise
reason. Never reach PASS by weakening a guard, a test, or an authorization check.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/dl-tranche2
rc=$?
echo "lane: dl-tranche2-areas-remarks — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

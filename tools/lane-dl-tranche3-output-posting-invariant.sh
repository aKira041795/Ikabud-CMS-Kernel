#!/usr/bin/env bash
#
# Lane: dl-tranche3-output-posting-invariant
#
# CLIENT CONCERN #1 + #8 — the P0 integrity defect:
#   "naadd sya automatic besan wla gireceive ni branch, ky pg log in nila naa na daw mga
#    additional nga wla pa sila kaentry"   and   "ang mga entry delivery ni commi automatic
#    mawithdraw kag branch bsan wla pa silay gientry or gireceive sa receiving stock"
#
# Contract: .ai/daily-ledger-nine-concerns-2026-11-09.contract.md
#
# MEASURED BY THE CHAIR (handlers.php, in dl_processProductionMovement):
#   :5814  $shouldAutoCreateFormalDelivery = movementType==='output' && ref===null
#                                            && formalEnabled && drNumber!=='' && !isCommissaryDirectOutput;
#   :5959  if ($shouldAutoCreateFormalDelivery || $isCommissaryDirectOutput) { ledgerState = [col => 0]; }
#          else { dl_applyLedgerDelta($destinationBranchId, ..., 'addtl'); }   <-- DIRECT BRANCH CREDIT
#
#   So under formal delivery, an 'output' addressed to a BRANCH but carrying NO DR number falls
#   into the else-branch and credits that branch's `addtl` with no dispatch and no receipt. That
#   is the client's "automatic additional".
#
#   The asymmetry that proves it is a defect rather than a design: the SAME function already
#   refuses a DR-less 'withdrawal' under formal delivery (:5762-5765). Output was simply never
#   given the same guard.
#
# WHY THE OBVIOUS FIX IS WRONG (measured trap — do not "simplify" past this):
#   :4624 calls dl_processProductionMovement($user, 'output', [...]) with NO dr_number for the
#   Daily Sheet "Record Addition" on a commissary. Its destination is the COMMISSARY ITSELF.
#   A blanket "output requires a DR" would break every Record Addition.
#   The discriminator is $isCommissaryDirectOutput, set true at :5800-5804 exactly when the
#   destination branch IS a commissary. Scope the new guard to !$isCommissaryDirectOutput.
#
# PROBE RESULT (read-only, tenant 207): formal_delivery_workflow_enabled = true.
#   So the exposure at this tenant is exactly "output to a branch with no DR", not the legacy
#   formal-OFF path. Tenants running formal delivery OFF keep their current behaviour — this lane
#   must NOT change the formal-OFF path.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing the highest-severity defect in the Daily Ledger module at
/var/www/html/applicationostest: branch stock that appears with no dispatch and no receipt.

READ FIRST: `.ai/daily-ledger-nine-concerns-2026-11-09.contract.md`.
The diagnosis below is MEASURED, with line numbers. Do not re-diagnose. Do not build a workflow.

=====================================================================
THE DEFECT
=====================================================================
In `dl_processProductionMovement()` (modules/daily-ledger/handlers.php):

    $shouldAutoCreateFormalDelivery = $movementType === 'output'
        && $referenceMovementId === null
        && $formalDeliveryEnabled
        && $drNumber !== ''
        && !$isCommissaryDirectOutput;

    if ($shouldAutoCreateFormalDelivery || $isCommissaryDirectOutput) {
        $ledgerState = [$ledgerColumn => 0];          // no direct credit
    } else {
        $ledgerState = dl_applyLedgerDelta($destinationBranchId, $productId, $ledgerDate,
                                           $delta, $actorId, $ledgerColumn);   // DIRECT 'addtl' CREDIT
    }

Under the formal delivery workflow, an `output` addressed to a BRANCH that carries no DR number
skips the delivery and credits that branch's `addtl` immediately. The branch opens its ledger and
stock is already there — the client's exact complaint.

Proof this is a defect and not a design: the SAME function already refuses a DR-less `withdrawal`
under formal delivery (search for "Delivery Receipt number is required for production withdrawal").
Output simply never got the equivalent guard.

=====================================================================
THE FIX (bounded — do exactly this)
=====================================================================
Add the missing symmetric guard, next to the existing withdrawal guard:

    if ($formalDeliveryEnabled
        && $movementType === 'output'
        && $flowMode === 'production'
        && !$isCommissaryDirectOutput
        && $drNumber === '') {
        throw new \RuntimeException('<clear operator-facing message>: an output addressed to a
            branch must carry a Delivery Receipt number while the formal delivery workflow is
            enabled.');
    }

Requirements:
1. The message must tell the operator WHAT TO DO (record/enter the DR number), not just that
   something failed. Match the tone of the existing withdrawal message.
2. Keep the existing withdrawal guard EXACTLY as it is.
3. Do NOT change `$shouldAutoCreateFormalDelivery` itself. With the guard in place, its
   `$drNumber !== ''` conjunct is always satisfied on that path, and the direct-credit
   else-branch becomes unreachable for non-commissary destinations — which is the goal.
4. Do NOT change behaviour when formal delivery is DISABLED. The legacy path must stay
   byte-identical for those tenants.
5. Do NOT remove the `else` branch. It still serves the legacy (formal-OFF) path and
   `$isCommissaryDirectOutput` handling.

**THE TRAP — read before editing.** Around line 4624 there is an internal caller:
    dl_processProductionMovement($user, 'output', [... 'destination_branch_id' => $commissaryBranchId, 'flow_mode' => 'production', ...])
It passes NO dr_number, for the Daily Sheet "Record Addition" on a commissary. Its destination is
the COMMISSARY ITSELF, so `$isCommissaryDirectOutput` is true and your guard must not fire.
If you find yourself needing to add a dr_number to that caller, you have scoped the guard wrongly.
`$isCommissaryDirectOutput` is set true when the destination branch IS a commissary — verify that
by reading the `$destBranch` block immediately above.

=====================================================================
HARD CONSTRAINTS
=====================================================================
1. NO new migration, NO schema change, NO new route.
2. Do NOT touch the receiving paths, the variance logic, or `apiResolveDeliveryVariance`.
3. Do NOT edit historical ledger rows, and do NOT write a backfill.
4. Do NOT widen any permission or role list.
5. Do NOT weaken or delete an existing test to reach PASS.
6. DiSyL: no `{...}` inside inline HTML event handlers.
7. If a UI surface sends output-without-a-DR today and your guard would break it, STOP and report
   PARTIAL naming that surface — do not silently change the UI's contract.

=====================================================================
TEST YOU MUST ADD
=====================================================================
Under `tests/daily-ledger/`, following the existing harness pattern:
  T1  formal delivery ON  + output to a BRANCH + no DR  -> refused, and NO branch addtl is written
  T2  formal delivery ON  + output to a COMMISSARY + no DR -> ALLOWED (the Record Addition path),
      proving the guard does not over-fire
  T3  formal delivery OFF + output to a branch + no DR -> unchanged legacy behaviour (still credits)
Snapshots of the formal-delivery setting and any branch row you touch must be restored in `finally`.
Assert on the LEDGER ROW, not only on the thrown exception — T1's real claim is that the branch's
`addtl` did not move.

=====================================================================
VERIFICATION YOU MUST RUN AND REPORT
=====================================================================
- `php -l` on every changed PHP file.
- Your new tests, run twice for determinism.
- `php tests/daily-ledger/daily_ledger_single_shift_mode_test.php`,
  `php tests/daily-ledger/daily_ledger_shift_target_test.php`,
  `php tests/daily-ledger/daily_ledger_delta_audit_test.php` — all must stay green.
- `php tests/daily-ledger/daily_ledger_routes_test.php` — expected 82/82; it is green on the base tree.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log`; both are 0 lines on base.
- MySQL 5.7 reasoning for any changed SQL, stated explicitly.

=====================================================================
REPORT
=====================================================================
status (PASS / FAIL / PARTIAL / BLOCKED), files changed, per-test RED/GREEN evidence,
whether the Record-Addition caller was proven unaffected and HOW, determinism runs, anything you
could NOT verify. Never reach PASS by weakening a guard, a test, or an authorization check.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/dl-tranche3
rc=$?
echo "lane: dl-tranche3-output-posting-invariant — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

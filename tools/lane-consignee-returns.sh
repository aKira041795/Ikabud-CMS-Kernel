#!/usr/bin/env bash
#
# Lane: consignee-returns-input — an admin enters returns/pullouts from the report.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-returns-input.contract.md
# ACCEPTANCE GATE:          tools/lane-consignee-returns-acceptance.sh  (measured RED)
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a bounded feature in the Ikabud repo at /var/www/html/applicationostest.
Read the contract FIRST - it is the authority:

    .ai/consignee-returns-input.contract.md

## THE OWNER'S REQUIREMENT (verbatim)

  "what if there are returns/pullouts?"
  "so, where do admin input the returns/pullouts? for me, after the qty column"
  "UI, move text in parenthesis under the main column header title in small font to save width space"

## THE FINDING THAT DEFINES THE JOB (already measured - do not re-derive it)

There is NO input path for a consignee return today.
  - the Stock Adjustment / pullout modal is BRANCH-side only ("unsold goods returning to
    commissary"); it has no consignee concept at all
  - the only consignee-debiting paths are a delivery CORRECTION and a delivery VOID

And the report ignores custody-out entirely:
    SUM(l.addtl) AS quantity, SUM(l.addtl * price_snapshot) AS dispatch_value, WHERE l.addtl > 0
It never reads `withdraw`. So a correction (3 sent / 2 received -> addtl=3, withdraw=1) and a
VOID (addtl=3, withdraw=3) BOTH still report the full gross dispatch. The report overstates an
"estimate of what may be collectible".

## THE MECHANIC YOU MUST REUSE, NOT REINVENT

dl_applyConsigneeLedgerDelta() (handlers.php ~3205) already models custody-out correctly:

    if ($delta >= 0) UPDATE ... SET addtl = addtl + :q
    else             UPDATE ... SET withdraw = withdraw + :q

So a return is a NEGATIVE delta and needs no new arithmetic. It also ALREADY refuses with
  "Reverse quantity exceeds consignee custody stock."
and ALREADY calls dl_assertLedgerMutationMutable() which refuses on a finalized shift with
  "This shift is finalized and locked. Reopen the shift before editing."
Do NOT add a second custody guard or a second lock check. Call the existing one.

## SCHEMA CONSTRAINTS (measured against the live DB - they shape the migration)

dl_consignee_ledger_effects:
    effect_kind       enum('credit','adjustment') NOT NULL DEFAULT 'credit'   <-- needs widening
    delivery_id       bigint unsigned NOT NULL                               <-- must become NULLable
    delivery_item_id  bigint unsigned NOT NULL                               <-- must become NULLable
    UNIQUE KEY uq_dl_consignee_effect_item_kind (delivery_item_id, effect_kind)

A return is NOT tied to a delivery, so the migration widens the enum AND makes both delivery
columns NULLable. MySQL allows many NULLs in a unique index, so repeated returns are fine.

Migration number 088 was verified FREE before being written into the contract. Use only 088.
(041 is a pre-existing duplicate - do NOT touch it, and do not renumber any applied migration.)
Guard the ALTERs with information_schema checks so a re-run is a no-op (copy the 087 pattern),
tag it @mysql57-compat, and REGISTER it in modules/daily-ledger/module.json. An unregistered
migration never runs on the live tenant - that is the easiest thing to forget.

## THE ACCEPTANCE GATE IS AUTHORITATIVE

    bash tools/lane-consignee-returns-acceptance.sh

It fails on the base tree today (measured). Make it PASS without editing it. It checks, among
other things, that the Returns column is positioned AFTER Quantity by comparing byte offsets in
the template - so the column ORDER is part of the contract, not a style choice.

## DO NOT BREAK THESE (they render the same template with a FIXED variable set)

    php tests/daily-ledger/daily_ledger_consignee_commercial_slice8_test.php   # 7/7
    php tests/daily-ledger/daily_ledger_consignee_sales_mode_test.php          # 12/12

Any new template variable must tolerate being ABSENT, or these two go red.

## BEFORE YOU REPORT

Run and paste the raw output of:
  1. php -l on every changed PHP file
  2. php _lint_disyl.php
  3. bash tools/lane-consignee-returns-acceptance.sh
  4. both oracle tests above, with their pass counts
  5. APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-consignee-dispatch-flow.spec.js --reporter=line
  6. git status --porcelain
  7. tail of storage/logs/app.log and storage/logs/error.log

Report PASS / FAIL / BLOCKED / PARTIAL. If the effect-row model cannot work without a change
outside the contract's Scope, STOP and report BLOCKED with the exact constraint. Do not invent a
parallel mechanism, and do not weaken the custody guard to make a test green.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-returns

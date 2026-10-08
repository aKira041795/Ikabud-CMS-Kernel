#!/usr/bin/env bash
#
# Lane: daily-sheet-sticky-columns — the Daily Sheet keeps Product / BEG / ADDTL in view when a
# 10-inch tablet scrolls the branch columns sideways.
#
# CONTRACT (AUTHORITATIVE): .ai/daily-sheet-sticky-columns.contract.md
# ACCEPTANCE GATE:          tools/lane-daily-sheet-sticky-acceptance.sh  (measured RED)
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a bounded, single-file UI change in the Ikabud repo at
/var/www/html/applicationostest. Read the contract FIRST — it is the authority:

    .ai/daily-sheet-sticky-columns.contract.md

## THE OWNER'S REQUIREMENT (verbatim, and their clarification)

  "for production and consigneed ledger, there's a hozontal scroll due to the many branches and
   consignees. keep the product name, beginning and ending columns when scrolling left/right so
   encoder will always see the product name, beginning and additional"

  "the issue is when using tablet with 10 inches screen"

## THE MEASURED PROBLEM (already measured by the chair — do not re-derive it)

The Daily Sheet at /daily-ledger/admin/commissary renders 15 columns and is 1348px wide
(200px Product + 14 x 82px), inside a scroll container that is only 494px wide at an 800x1280
(10-inch portrait) viewport. It overflows at EVERY 10-inch tablet size (857 / 633 / 377px at
800x1280 / 1024x768 / 1280x800). Scrolled sideways, the Product cell's left edge sits at -857px:
the encoder cannot see which row they are editing.

## WHAT TO BUILD

Pin, in `templates/modules/daily-ledger/admin/commissary.disyl` ONLY:
  - Product, BEG, ADDTL  -> pinned to the LEFT edge of the scroll container, at ALL widths
  - ACTUAL BAL (the ending balance) -> pinned to the RIGHT edge, only at >= 1024px viewport,
    with VARIANCE as the inner member of the right group when it renders

## THE HARD PART — read the contract section "The offset problem"

Product is a fixed 200px column, so 0 and 200px are constants. Every column after it is FLUID
under `table-layout: fixed`, so the cumulative offset of ADDTL is NOT a constant CSS can know.
Solve it exactly — either derive the fluid column width in CSS from the real column count, or
measure the rendered cells in a small inline script and re-run it on resize and after the
`dlLoadUrl` content swap. The acceptance measures OUTCOMES, so either mechanism is fine.

## DO NOT

  - do NOT hard-code BEG/ADDTL widths to make the offsets constant. That passes the sticky
    criterion and silently changes the sheet's equal-share rhythm.
  - do NOT add or change padding / border / width / min-width / max-width / box-sizing on any
    sheet cell. `position: sticky` must be the only layout-affecting declaration. Use background
    and box-shadow for the visual separator.
  - do NOT leave a pinned cell transparent or under-painted; the columns slide beneath it.
  - do NOT pin ACTUAL BAL with a bare `right: 0` while VARIANCE renders after it.
  - do NOT let stickiness reach paper: neutralise it inside the existing `@media print` block.
  - do NOT edit the acceptance spec or the two geometry oracles.
  - do NOT add a dependency, a framework or a build step.

## THE ACCEPTANCE GATE IS AUTHORITATIVE AND ALREADY MEASURED RED

    bash tools/lane-daily-sheet-sticky-acceptance.sh

Check 1 fails today (product.left = -857 when the sheet is scrolled). Checks 2 and 3 are GREEN
baselines (the sheet's column geometry, and the printed sheet) — if you turn either red you have
broken the sheet while "fixing" it. Make the gate PASS without editing it.

## BEFORE YOU REPORT

Run and paste the raw output of:
  1. php _lint_disyl.php
  2. bash tools/lane-daily-sheet-sticky-acceptance.sh
  3. a screenshot at 800x1280 with the sheet scrolled half way -> /tmp/daily-sheet-sticky-portrait.png
  4. a screenshot at 1280x800 scrolled half way -> /tmp/daily-sheet-sticky-landscape.png
  5. git status --porcelain
  6. tail of storage/logs/app.log and storage/logs/error.log

Report PASS / FAIL / BLOCKED / PARTIAL. If stickiness cannot be done without changing the sheet's
geometry, or the offsets cannot be made exact, STOP and report BLOCKED with the exact constraint
and what you tried. Do not force it by hard-coding column widths, and do not weaken a check.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/daily-sheet-sticky

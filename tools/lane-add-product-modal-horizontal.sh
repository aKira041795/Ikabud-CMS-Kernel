#!/usr/bin/env bash
#
# Lane: add-product-modal-horizontal
#
# OWNER (2026-10-06, verbatim): "also, for add product modal, relayout s it's horizontally set than vertical.
# better view for user"
#
# CHAIR RECON — this is a REUSE, not new design work:
#   * The ADD modal (templates/modules/daily-ledger/admin/products.disyl ~282) uses the plain `.modal` and
#     stacks 18 form groups VERTICALLY.
#   * The EDIT modal (~357) already does exactly what the owner wants: `.modal.product-modal` ->
#     `.product-modal__body` -> `.product-modal__grid` (CSS at line 18: `grid-template-columns:
#     repeat(2, minmax(0, 1fr))`, collapsing to 1 column in the existing media query at ~52) -> two
#     `.product-modal__section`s titled "Catalog" and "Production Profile" -> `.product-modal__actions`.
#   * So the horizontal CSS ALREADY EXISTS in this file. No new CSS should be needed.
#   Consequence worth stating in the report: making Add match Edit also removes an inconsistency the user can
#   feel — today the same product form looks different depending on how you opened it.
#
# THE ONE THING THAT CAN BREAK: `submitAddProduct()` (~line 557) and `toggleAddBranchPicker()` (~line 530) read
# the fields BY ID. A relayout that drops or renames an id silently breaks CREATING PRODUCTS, and nobody would
# notice until a client tried to add one. Observed references include (derive the COMPLETE list yourself from
# every reader of the add modal — do not trust this list to be exhaustive):
#     add-modal, add-name, add-category, add-price, add-sort, add-output-pieces, add-output-unit,
#     add-pcs-per-pack, add-batch-kilo-qty, add-batch-egg-qty, add-submit,
#     input[name="add-assignment-mode"], .add-branch-check, add-branch-picker
#
# QUEUED: a lane was already running when this was briefed, so this is dispatched immediately after it lands.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are relaying out ONE modal in the Ikabud repo at /var/www/html/applicationostest. Template work only.
PHP 8.5 local; production is MySQL 5.7 (no local 5.7 server — irrelevant here, no SQL should change).

FILE: templates/modules/daily-ledger/admin/products.disyl

## GOAL
The ADD-PRODUCT modal must be laid out HORIZONTALLY (multi-column) instead of a single vertical stack of 18
fields, so the form is readable at a glance.

## HOW — reuse the CSS that is already in this file. Do NOT write new CSS unless genuinely unavoidable.
The EDIT modal in the same file already implements the target layout. Mirror it exactly:
    <div class="modal-overlay" id="add-modal">
      <div class="modal product-modal">
        <div class="product-modal__header"> … title …
        <div class="product-modal__body">
          <div class="product-modal__grid">
            <div class="product-modal__section">
              <div class="product-modal__section-title">Catalog</div>            … fields …
            <div class="product-modal__section">
              <div class="product-modal__section-title">Production Profile</div>  … fields …
              … a section for the assignment control …
        <div class="modal-actions product-modal__actions"> … Cancel / Add …
  - Use the SAME section names and the SAME field ORDER as the edit modal, so Add and Edit read identically.
    Put the "Show in branches" control in its own clearly-titled section.
  - The existing media query already collapses `.product-modal__grid` to one column on small screens — rely on
    it, and verify it still applies (do not add a second, conflicting breakpoint).
  - The branch checkbox list is a 19-item scrollable block. It must NOT be squeezed into a narrow column:
    give it full grid width (or its own full-width row) and keep it comfortably readable/scrollable.

## NON-NEGOTIABLE: preserve every id / name / class the JavaScript reads
`submitAddProduct()`, `toggleAddBranchPicker()` and the open/close helpers look elements up BY ID. Before you
edit, READ those functions and write down the COMPLETE list of ids, names and classes they read; after editing,
confirm every one still exists with the same id/name/class. Renaming or dropping one silently breaks creating
products — that is the single worst outcome of this change.
Preserve the inline `onchange` handlers, the `checked` default on the "All active branches" radio, the
`hidden` default on `#add-branch-picker`, and the `{foreach branches as b}` loop shape.

## ALSO PRESERVE
- Accessibility: label/control association (`for`/`id` or wrapping label), focus behaviour when the modal opens
  (`#add-name` receives focus today), Escape/overlay close, and the `data-add-assignment` hook.
- The modal's behaviour, its payload, and the API it posts to: unchanged.
- The rest of the file: the Products list, the assignment tab, and the EDIT modal's markup/behaviour must not
  change at all.

## VERIFICATION — required, and the first one is the important one
1. **ID integrity check (the real risk).** Add a check that every id/name/class referenced by the add-modal
   JavaScript is present in the rendered markup. A static cross-check is acceptable, but it must be derived
   from the JS itself, not from a hand-copied list, so it keeps working if the JS changes. Report the exact
   list it checked and the result.
2. **Browser assertion (READ-ONLY).** Extend tests/browser/daily-ledger-branch-product-visibility.spec.js with
   a case that opens the add modal and asserts it renders with `.product-modal__grid`, that the expected number
   of fields are present, and that the grid computes to TWO columns at desktop width (and one at mobile width).
   DO NOT click "Add" / submit: the add endpoint writes a REAL product to the live tenant. A spec that creates
   a real product is a defect, not a test.
   Run ONLY that spec:
     APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
   Login: shiela_baina / shielab123.
3. `php -l` is not applicable (template); instead confirm the page renders 200 and the Products tab still lists
   182 rows.

## HARD CONSTRAINTS
- Template only. Do NOT touch handlers, routes, SQL, the guard, the picker logic, or the import.
- Do NOT change the assignment tab or the edit modal.
- No new CSS if the existing classes can do it; if you must add any, say why in the report.
- Do NOT add fields, remove fields, or change what the form submits.
- Keep storage/logs/error.log empty.

## ACCEPTANCE — run and report verbatim
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    git --no-pager diff --stat
Report the diff as a short BEFORE/AFTER of the add-modal structure (a few lines, not the whole template).

## Rules
- Smallest correct change; this is a layout change, nothing else.
- Never weaken, skip, or delete an assertion to reach green.
- Report status PASS | PARTIAL | BLOCKED; the complete id list you checked and its result; the browser output;
  whether any new CSS was needed and why; anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/add-product-modal-horizontal
rc=$?
echo "lane: add-product-modal-horizontal — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

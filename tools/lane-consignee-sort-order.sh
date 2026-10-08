#!/usr/bin/env bash
#
# Lane: consignee-sort-order — consignees get an Order field, mirroring branches.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-sort-order.contract.md
# ACCEPTANCE GATE:          tools/lane-consignee-sort-order-acceptance.sh
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a bounded feature in the Ikabud repo at /var/www/html/applicationostest.
Read the contract FIRST - it is the authority:

    .ai/consignee-sort-order.contract.md

## THE OWNER'S REQUIREMENT (verbatim)

  "adding a consignee must be sortable in order, so we need an Order field. default zero.
   if 1 exists, zero value does after any order with numeric data other than 0"

## THE KEY POINT: THIS ALREADY EXISTS FOR BRANCHES. MIRROR IT, DO NOT INVENT IT.

The rule is already implemented AND documented for branches at handlers.php:19558-19566:

    // Branch column order = the order the admin set on each branch (lowest
    // number prints leftmost, matching the paper form). Unnumbered branches
    // (sort_order = 0) print AFTER all numbered ones so a newly added branch
    // can never jump to the front. With every branch still at 0 the leading
    // flag ties for every row, so the whole clause collapses to plain
    // alphabetical order -- i.e. exactly the pre-order behaviour.

        ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC

Branches already have the whole feature. Copy the shape:

  column     dl_branches.sort_order INT NOT NULL DEFAULT 0   (migration 065)
  form field <input type="number" id="edit-sort" min="0" step="1" placeholder="0">   (line 270)
  populate   openEditBranch(..., sortOrder) -> value = sortOrder == null ? 0 : sortOrder  (431)
  save       sort_order: parseInt(document.getElementById('edit-sort').value, 10) || 0     (488)

So your job is: give CONSIGNEES exactly this behaviour.

## DELIVERABLE 1 - migration 087

Create modules/daily-ledger/database/migrations/087_add_consignee_sort_order.sql
MIRROR 065_add_branch_sort_order.sql exactly: guarded dynamic ALTER via
information_schema.columns, INT NOT NULL DEFAULT 0, no backfill, @mysql57-compat annotation, and
a docblock saying the default is load-bearing.

REGISTER IT in modules/daily-ledger/module.json's "migrations" array. WITHOUT registration
tenant:migrate will never run it on the live tenant, so the deploy ships a column that does not
exist and every consignee query breaks. This is the single easiest thing to forget.

Migration number 087 was verified FREE before being written into the contract. Use only 087.
(041 is a pre-existing duplicate - do NOT touch it.)

## DELIVERABLE 2 - one owner for the rule

The rule `(sort_order = 0) ASC, sort_order ASC, name ASC` is currently hardcoded TWICE - at
17436 (branches admin list) and 19566 (Daily Sheet branch columns) - and the consignee
requirement adds SIX more listing sites. Eight copies of one rule is the defect just fixed for
the balance formula, so give it one owner:

    /** Display order for an orderable entity: numbered first (ascending), unnumbered last. */
    function dl_entityOrderBySql(string $prefix = ''): string
    {
        return "({$prefix}sort_order = 0) ASC, {$prefix}sort_order ASC, {$prefix}name ASC";
    }

Replace BOTH branch sites and route these consignee sites through it:

    8060   ORDER BY c.name                (a JOIN listing)
    11197  ORDER BY c.name                (a JOIN listing)
    15115  ORDER BY name                  (active consignees)
    16238  ORDER BY name                  (active consignees)
    17438  $consigneeSql .= ' ORDER BY c.name'   (the admin list)
    19094  ORDER BY name ASC, id ASC      (Daily Sheet consignee columns)

BRANCH BEHAVIOUR MUST STAY BYTE-IDENTICAL - it is already this exact string. Do NOT add an `id`
tiebreak and do NOT "improve" the branch clause; it is documented and working.

## DELIVERABLE 3 - persist the value

  17432  admin list SELECT: add c.sort_order so the template can show it and feed Edit
  17523  UPDATE: add sort_order = :sort_order
  17532  INSERT: add the sort_order column and its bind

## DELIVERABLE 4 - the UI, mirroring the branch form

In templates/modules/daily-ledger/admin/branches.disyl:

  * consignee modal (~line 356, after Area/Address): add an Order input
        <input class="form-input" type="number" id="consignee-sort-order" min="0" step="1" placeholder="0">
    labelled Order, with a short hint that it is optional and lower numbers come first.
  * openConsignee(...) (line 382): add a sortOrder parameter and set the input,
    defaulting to 0 the way openEditBranch does.
  * the Edit button (line 341): pass {c.sort_order | default:0} as the LAST argument.
  * saveConsignee() (line 395): add
        sort_order: parseInt(document.getElementById('consignee-sort-order').value, 10) || 0,
  * optionally show Order as a list column.

## THE DiSyL TRAP - IT IS DOCUMENTED IN THE FILE, READ IT FIRST

saveConsignee()'s JSON.stringify argument must stay MULTI-LINE. DiSyL scans a <script> body for
{tag} output and its tags do not span newlines: a SINGLE-LINE literal whose first key is a bare
identifier is consumed as a tag. That silently emitted `JSON.stringify(0)`, posted the body `0`,
and the kernel returned a 500 error page - it looked correct in the template and failed only in
the browser. The comment explaining this is right above the literal. Do not inline it.

## DO NOT

- Do NOT use a migration number other than 087. Do NOT touch 041.
- Do NOT change the branch sort rule's text.
- Do NOT add any other schema change.
- Do NOT change product behaviour, rendering elsewhere, or other modules.
- `sort_order`, never `order` (`order` is a MySQL reserved word).
- `git add -A` is forbidden. Do NOT commit.
- Do NOT claim browser verification - storage/cache/compiled is www-data-owned so CLI cannot
  compile an edited template. The chair verifies the rendered UI.

## ENVIRONMENT TRAPS (measured in this repo - all real)

- The DB needs the password: it is in .env as DB_PASSWORD. Use
  DBPASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '\"') then export MYSQL_PWD=$DBPASS,
  or bootstrap through the app the way the gates do.
- information_schema is FORBIDDEN under modulePushContext (it throws). The migration file itself
  uses information_schema in SQL, which is fine - that is MySQL, not PHP.
- $ctx->json() EXITS the process. Run fixture-driving handler calls in a CHILD PROCESS.
- ONLY_FULL_GROUP_BY is ON: every aggregate SELECT must GROUP BY its non-aggregated columns.
- Do not end an acceptance command with a pipe (`| head`): the exit code becomes the pipe's and a
  real failure reads as success.
- The dev database may ALREADY have the column if the migration ran. Your migration must tolerate
  that (it is guarded).

## VERIFY BEFORE YOU REPORT

- php -l on every touched PHP file.
- Apply the migration to tenant 207 - `php ikabud tenant:migrate 207 daily-ledger` - and confirm
  dl_consignees.sort_order exists with NOT NULL and default 0.
- bash tools/lane-consignee-sort-order-acceptance.sh   -> must exit 0 (it exits 1 before you)
- State that the two branch sites are byte-identical after your change.
- Both storage/logs/app.log and storage/logs/error.log must be 0 bytes at the end.

## REPORT

  status:        PASS | FAIL | BLOCKED
  changed:       files with line counts
  migration:     the filename, confirmation it is registered in module.json, and the applied result
  ordering:      the helper, the 2 branch sites + 6 consignee sites, and confirmation the branch
                 clause text is unchanged
  persistence:   the UPDATE/INSERT/SELECT line numbers now carrying sort_order
  ui:            the 4 template wirings with line numbers
  ordering_rule: evidence the rule holds (orders 0/5/2 come back as 2, 5, then zeros)
  verification:  php -l, the gate's exit code, the migration result, both log sizes
  scope:         git status --porcelain
  unresolved:    anything you could not establish

Report BLOCKED with a precise reason rather than improvising. If the branch sites cannot be
routed through the helper without changing their emitted SQL, leave them alone and SAY SO - a
partial refactor with unchanged branch behaviour beats a complete one that reorders the sheet.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-consignee-sort-order

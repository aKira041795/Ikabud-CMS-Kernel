#!/usr/bin/env bash
#
# Lane: balance-one-owner — one owner for the production balance formula.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-balance-one-owner.contract.md
# ACCEPTANCE GATE:          tools/lane-balance-one-owner-acceptance.sh
#
# Do NOT hard-code a model chain: lane-model.sh supplies it from tools/model-chain.txt.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are performing a SMALL, PURELY TEXTUAL refactor in the Ikabud repo at
/var/www/html/applicationostest. Read the contract FIRST - it is the authority:

    .ai/commissary-balance-one-owner.contract.md

This is being done before a deploy to a live production site, so the single requirement that
matters is: NO FIGURE MAY MOVE.

## THE FORMULA IS WRITTEN SIX TIMES

`beg + produced - dispatched - wastage` appears at exactly these six lines of
modules/daily-ledger/handlers.php:

  SQL  : 3898, 4736, 20150   -> "(beg_qty + produced_qty - dispatched_qty - wastage_qty) AS book_balance"
                              (these three are byte-identical)
  SQL  : 19356              -> "(cpl.beg_qty + cpl.produced_qty - cpl.dispatched_qty - cpl.wastage_qty) AS book_balance,"
  PHP  : 2869               -> $movements = (int)$row['beg_qty'] + (int)$row['produced_qty'] - (int)$row['dispatched_qty'] - (int)$row['wastage_qty'];
  PHP  : 3617               -> 'remaining_qty' => (int)$row['beg_qty'] + $newProduced - $newDispatched - $newWastage,

Enclosing functions, for orientation:
  2869  dl_settlePendingEndingsForShift        (WRITE path)
  3617  dl_applyCommissaryProductLedgerDelta   (WRITE path)
  3898  dl_saveCommissaryBeginningQty          (WRITE path)
  20150 dl_saveProductionOutputLedgerCell      (WRITE path)
  4736  dl_readCommissaryProductLedgerRow      (READ path)
  19356 handleAdminCommissary                  (READ path)

## WHAT TO DO

Add TWO helpers, next to each other:

    /** The production balance expression, for use inside SQL. $prefix is '' or 'cpl.'. */
    function dl_productionBalanceSql(string $prefix = ''): string

    /** The same balance, computed in PHP. */
    function dl_productionBalance(int $beg, int $produced, int $dispatched, int $wastage): int

Then replace all six sites with calls to them.

## THE ONE THING THAT WILL FAIL YOU IF YOU GET IT WRONG

The helpers must emit the ORIGINAL text BYTE-FOR-BYTE, including parentheses and the single
spaces around + and -:

    dl_productionBalanceSql('')     === '(beg_qty + produced_qty - dispatched_qty - wastage_qty)'
    dl_productionBalanceSql('cpl.') === '(cpl.beg_qty + cpl.produced_qty - cpl.dispatched_qty - cpl.wastage_qty)'

    dl_productionBalance($b, $p, $d, $w) === $b + $p - $d - $w

A near-match FAILS. The acceptance gate asserts these against a baseline frozen BEFORE any edit
(sha1 5a586ac6710533ba6171f7f3929bdedbf5202f59). Do not "tidy" spacing, do not drop the
parentheses, do not reorder the terms. The existing columns are in the order beg, produced,
dispatched, wastage - keep it.

A natural implementation:

    function dl_productionBalanceSql(string $prefix = ''): string
    {
        return "({$prefix}beg_qty + {$prefix}produced_qty - {$prefix}dispatched_qty - {$prefix}wastage_qty)";
    }

Check by eye that with $prefix = '' this yields the exact string above, and with 'cpl.' likewise.

## THE TWO PHP SITES DIFFER - READ THEM BEFORE REPLACING

  2869: (int)$row['beg_qty'] + (int)$row['produced_qty'] - (int)$row['dispatched_qty'] - (int)$row['wastage_qty']
  3617: (int)$row['beg_qty'] + $newProduced - $newDispatched - $newWastage

Both become dl_productionBalance(...). Pass already-cast values; the helper takes ints and is
declared int.

## DO NOT

- Do NOT change the arithmetic, an operator, a parenthesis, a space, or the term order.
- Do NOT add a migration or a generated column. Promoting this to a generated column is a schema
  change on MySQL 5.7 with no local 5.7 server - explicitly out of scope.
- Do NOT touch the calc_variance expression in migrations 064/074. It is the DB's own separate
  expression and is not yours.
- Do NOT reformat or reorder anything else in the queries. Keep the diff to the two helper
  definitions plus six substitutions. Reformatting makes the change un-reviewable.
- Do NOT run the four WRITE-path functions against the dev database to "check" them - that would
  mutate tenant data. The proof is expression equivalence, NOT execution.
- No product behaviour, rendering, template, or other-module changes.
- `git add -A` is forbidden. Do NOT commit.

## VERIFY BEFORE YOU REPORT

- php -l modules/daily-ledger/handlers.php
- bash tools/lane-balance-one-owner-acceptance.sh   -> must exit 0 (it exits 1 before you)
- The gate also re-runs dl_readCommissaryProductLedgerRow() and requires its output to equal the
  frozen baseline. Its book_balance is -3 (commissary 18, product 53, 2026-10-07, shift AM) - a
  REAL value, so the comparison discriminates. Confirm it is still -3.
- Both storage/logs/app.log and storage/logs/error.log must be 0 bytes at the end.
- Confirm the exact site count drops: the gate reports "write sites: N". It must read 0-2.

## ENVIRONMENT TRAPS (measured in this repo - all real)

- information_schema is FORBIDDEN under modulePushContext (it throws; a try/catch turns it into a
  SILENT FALSE). Use SHOW COLUMNS.
- $ctx->json() EXITS the process. Run fixture-driving handler calls in a CHILD PROCESS.
- ONLY_FULL_GROUP_BY is ON: every aggregate SELECT must GROUP BY its non-aggregated columns.
- Do not end an acceptance command with a pipe (`| head`): the exit code becomes the pipe's and a
  real failure reads as success.
- Write regexes containing \b or \d into a FILE with single-quoted patterns.

## REPORT

  status:        PASS | FAIL | BLOCKED
  changed:       handlers.php with line counts
  substitutions: the six line numbers BEFORE and the six AFTER, so the change is auditable
  helpers:       the two helper definitions, quoted verbatim
  equivalence:   the gate's result, and the read-path book_balance value (must be -3)
  site_count:    "write sites: N" from the gate before and after
  verification:  php -l, the gate's exit code, both log sizes
  scope:         git status --porcelain
  unresolved:    anything you could not establish

Report BLOCKED with a precise reason rather than improvising. If you cannot make a site use the
helper WITHOUT changing the emitted SQL, leave that site alone and say so - a partial refactor
with a correct formula beats a complete one with a changed balance.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-balance-one-owner

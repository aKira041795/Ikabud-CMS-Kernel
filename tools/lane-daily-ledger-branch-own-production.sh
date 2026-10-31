#!/usr/bin/env bash
#
# Lane: daily-ledger-branch-own-production — "Own production" capability for a
# branch that produces without being a commissary (client request 2026-10-31).
#
# Criterion (fails on base: the test file below does not exist yet):
#   php tests/daily-ledger/daily_ledger_own_production_capability_test.php -> all green
#
# ALREADY DONE BY THE ARCHITECT — do not redo, do not overwrite:
#   - modules/daily-ledger/database/migrations/096_branch_has_production.sql (added, registered
#     in module.json). Adds dl_branches.has_production; backfills is_commissary=1 OR
#     default_supply_mode IN ('self_managed','hybrid') so nobody loses today's capability.
#   - dl_branchHasProduction(int $branchId): bool in handlers-deliveries.php (live read,
#     fail-open TRUE, commissary OR has_production).
# The remaining work is the gate, the switch plumbing, the UI and the test.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.
Tenant fixture for tests: baronledger.test (tenant 207).

# TASK
The client wants branches that PRODUCE STOCK, not only commissaries, to be able to mark
"Own production" for additional quantity. The cashier Add Stock path already records the
type `own_production` into the Addt'l cell; what is missing is (a) an explicit per-branch
capability, (b) enforcing it, (c) exposing it as a branch switch, and (d) a clear UI.

# ALREADY DONE — USE AS IS, DO NOT REWRITE OR REMOVE
1. modules/daily-ledger/database/migrations/096_branch_has_production.sql
   - adds `dl_branches.has_production TINYINT(1) NOT NULL DEFAULT 0 AFTER single_shift_mode`
   - one-time backfill: `is_commissary = 1 OR default_supply_mode IN ('self_managed','hybrid')`
   - already registered at the end of `database.migrations` in modules/daily-ledger/module.json
2. `dl_branchHasProduction(int $branchId): bool` — handlers-deliveries.php, next to
   dl_branchPaperlessDrEnabled(). Live read (NO static cache), fail-open TRUE, true when
   `is_commissary = 1` OR `has_production = 1`.

# REQUIRED WORK

## A. Enforce the capability on the own-production write path
- handlers.php, `apiSaveCashierWithdrawals()`: after the existing `PAPERLESS_DR_DISABLED`
  guard for `['adjustment_add','own_production']`, add:
      if ($type === 'own_production' && !dl_branchHasProduction((int)$branchId)) { 422 }
      payload: ['ok' => false,
                'error' => 'Own production is not enabled for this branch. An admin can enable it in Settings > Branch switches > Own production.',
                'code' => 'OWN_PRODUCTION_DISABLED']
  Place it AFTER the paperless guard and BEFORE the shift resolution, and mirror the exact
  same rule in handlers-offline.php around its `own_production` handling (the offline replay
  must refuse for the same reason, ordering it so the reason is the capability, not the shift).
- Do NOT gate `adjustment_add` or any other type on the capability.

## B. Make `has_production` a first-class branch switch (shared applier — do not add a 4th path)
- `dl_applyBranchFlags()` (handlers.php): extend the allowlist to
  ['paperless_dr_enabled','single_shift_mode','has_production']; it must write it and include
  all three flags in the before/after audit JSON. The R7 guard stays tied to
  `single_shift_mode` ONLY — has_production is always changeable.
- `apiUpdateBranchFlags()`: include 'has_production' in the parsed switch loop.
- `dlBranchOperatingSwitches()`: SELECT and return `has_production` (default 0).
- `apiCreateBranch()` and `apiUpdateBranch()`: accept, validate (same boolean parse as
  single_shift_mode) and persist `has_production`; in apiUpdateBranch route it through
  `dl_applyBranchFlags()` exactly like the other two switches (they were moved there on
  2026-10-30 — do not reintroduce a direct column write).
- Do NOT touch the branches list/table columns beyond that.

## C. UI (the client explicitly asked for a UX lift)
1. templates/modules/daily-ledger/admin/settings.disyl — Branch switches modal: a THIRD
   toggle next to the existing two:
      <input type="checkbox" class="toggle" data-branch-id="{b.id}"
             data-branch-flag="has_production" onchange="dlToggleBranchFlag(this)"
             {if b.has_production}checked{/if}>
   with a short label "Own production" and one explanatory sentence
   ("This branch makes stock of its own (not only a commissary). Cashiers get
   Add Stock (own production) for the Addt'l cell."). Extend the card's helper paragraph.
2. templates/modules/daily-ledger/admin/branches.disyl — the add/edit branch forms: a third
   checkbox (ids `add-has-production` / `edit-has-production`), included in the create/update
   payloads as `has_production`, and passed through `openEditBranch(...)` like the other two
   flags (keep the positional argument list consistent everywhere it is called).
3. handlers.php `handleCashierLedger()`: pass `'branch_has_production' => dl_branchHasProduction((int)$branchId)`
   into the view data.
4. templates/modules/daily-ledger/cashier/ledger.disyl: expose it for the modal the same way
   SHIFT/BRANCH_ID are exposed (window.BRANCH_HAS_PRODUCTION = true/false from the server value).
5. templates/modules/daily-ledger/cashier/modal_patch.disyl (Stock Adjustment modal):
   - When the branch has NO production capability, do not offer `own_production`: remove it
     from the Type select for that branch (server flag is the source of truth, never a
     browser guess) and show a neutral hint line where its panel would be:
     "Own production is off for this branch. An admin can enable it in Settings > Branch
      switches > Own production."
   - When it HAS the capability, make the choice a first-class action: a two-button quick
     row at the top of the modal — "Own production" and "Other adjustment" — which sets
     Type (and Reason for own production) and reveals the existing teal explainer. Preset
     via `this.$watch('header.withdrawal_type', ...)` / explicit method calls, NEVER by
     relying on a native `change` event (measured trap: selectOption/native change can land
     before x-model syncs). The `<option value="own_production">` element MUST still exist in
     the select, or the rendered value silently shows the old type.
   - Keep the existing production-shift picker and its rule intact (own production lands on
     the chosen AM/PM; a finalized shift refuses).
   - Colour comes from the existing palette (teal for own production) — do not invent tokens.
   - All new copy must be plain and state what happens, in the existing tone.

## D. Test — tests/daily-ledger/daily_ledger_own_production_capability_test.php
Use the repo convention (see tests/daily-ledger/daily_ledger_branch_switch_wiring_test.php):
TestHarness MODE_INTEGRATION against 'baronledger.test', a private fixture branch id (e.g.
99861) created and removed in a finally block, `$h->test(...)` assertions each carrying the
failure mode. Cover:
1. dl_branchHasProduction: commissary => true; non-commissary with has_production=1 => true;
   non-commissary with has_production=0 => false.
2. dl_applyBranchFlags accepts 'has_production' (changed=true, column written, audit JSON
   contains has_production in BOTH old and new), a no-op re-apply writes/audits nothing, and
   unknown keys are still refused. The R7 shift-mode guard still refuses a mid-day
   single_shift_mode change while has_production remains changeable.
3. Source wiring assertions (each with a remedy message): handlers.php contains the
   OWN_PRODUCTION_DISABLED guard and it calls dl_branchHasProduction; handlers-offline.php
   contains the same guard; settings.disyl renders data-branch-flag="has_production";
   handleCashierLedger passes branch_has_production; ledger.disyl exposes
   BRANCH_HAS_PRODUCTION; modal_patch.disyl hides own_production behind that flag and keeps
   `<option value="own_production">`; module.json registers 096.
4. Cleanup pin: every private-id row removed, dl_branches row gone, audit rows gone.

# CONSTRAINTS
- Change ONLY these files:
  modules/daily-ledger/handlers.php
  modules/daily-ledger/handlers-offline.php
  modules/daily-ledger/handlers-deliveries.php (only if a helper is genuinely needed)
  modules/daily-ledger/module.json (only if a path really must change)
  templates/modules/daily-ledger/admin/settings.disyl
  templates/modules/daily-ledger/admin/branches.disyl
  templates/modules/daily-ledger/cashier/ledger.disyl
  templates/modules/daily-ledger/cashier/modal_patch.disyl
  tests/daily-ledger/daily_ledger_own_production_capability_test.php (new)
- Do NOT edit the migration or tests/daily-ledger/daily_ledger_branch_switch_wiring_test.php.
- Do NOT touch other modules, kernel/, tools/, or .ai/.
- Do NOT git add / git commit / git push.
- Apply the new column to the fixture tenant BEFORE running the suites:
      php ikabud tenant:migrate baronledger.test daily-ledger
  (a missing column throws 1265 / hides the refusal behind a DB error).

# VERIFY (report the verbatim result lines)
1. php tests/daily-ledger/daily_ledger_own_production_capability_test.php   -> all green (state the count)
2. php tests/daily-ledger/daily_ledger_own_production_test.php             -> still green
3. php tests/daily-ledger/daily_ledger_branch_switch_wiring_test.php       -> 20/20
4. php tests/daily-ledger/daily_ledger_paperless_dr_branch_test.php        -> 26/26
5. php tests/daily-ledger/daily_ledger_adjustment_matrix_test.php          -> all green
6. php -l on every PHP file you touched; php _lint_disyl.php --path templates/modules/daily-ledger --ci -> exit 0
7. git status --porcelain -> only the files listed above (plus the three pre-existing
   architect changes: handlers-deliveries.php, module.json, migration 096)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: where the capability guard sits and why; how the switch reached the shared
  applier; what the modal does when the branch cannot produce; anything you deliberately
  did NOT change
verification: the commands' result lines, verbatim
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-branch-own-production

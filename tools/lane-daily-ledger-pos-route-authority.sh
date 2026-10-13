#!/usr/bin/env bash
#
# Lane: daily-ledger-pos-route-authority — 6.3 Authority Enforcement Coverage SLICE 11d (FINAL dl batch).
# Declare route authority for the daily-ledger POS writes. Each route's gate is
# dl_pos_apiGuard([...], '<permission>') -> dlCurrentUser([...]) with a static exact role set:
#   ['cashier','supervisor','admin']   x4  pos/mode/select (sell), cart save/abandon (sell), checkout (sell)
#   ['supervisor','admin']             x3  sales/void (void), sales/refund (refund), fallback (fallback)
#
# Downstream discipline stays UNCHANGED: the fine-grained dl_roleHasPermission grants after the
# role gate, POS feature/session rules, kernel-admin opt-in (default OFF). The guard only adds
# refusals for roles outside the declared sets and never grants.
# After this slice the declarable dl write set is COMPLETE: 66 of 70 (4 auth-only routes have no
# role set to declare) — this suite owns the exact 66-key full-set pin.
#
# Criterion (fails on base: write_enforced=59 after slice 11c — gate re-proves live at dispatch):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced=66 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 11d (FINAL daily-ledger batch): declare route authority for the daily-ledger POS writes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only (source discipline is downstream).
- Target handlers gate via dl_pos_apiGuard([...], '<permission>') which calls dlCurrentUser([...]) (EXACT in_array on role) then a fine-grained dl_roleHasPermission grant. Role sets were verified by the harness from full function bodies. The fine-grained grant, POS session/feature rules and kernel-admin opt-in stay DOWNSTREAM and unchanged. The guard only adds refusals for roles outside the declared sets and never grants.
- Capability id for all declarations: kernel.auth.authenticate@1 (the module's settled id, consistent with the 59 existing declarations). This extends them — multi-batch rule: this suite owns the exact 66-key full-set pin (the complete declarable dl set); the slice-11c suite (tests/daily_ledger_admin_documents_route_authority_declaration_test.php, 59 keys) MUST be converted to a subset pin of its keys in this same change — that conversion IS authorized here (three files total). Earlier suites are already subset pins; leave them.

# TARGET SET (7 routes, byte-exact keys; two role groups)
Group A, allowed_roles ["cashier","supervisor","admin"]:
  POST /daily-ledger/api/v1/pos/mode/select
  POST /daily-ledger/api/v1/pos/cart/save
  POST /daily-ledger/api/v1/pos/cart/abandon
  POST /daily-ledger/api/v1/pos/checkout
Group B, allowed_roles ["supervisor","admin"]:
  POST /daily-ledger/api/v1/pos/sales/void
  POST /daily-ledger/api/v1/pos/sales/refund
  POST /daily-ledger/api/v1/pos/fallback

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — ADD these 7 declarations to the EXISTING `capabilities.routes` map; each with kernel.auth.authenticate@1 and the EXACT allowed_roles of its group. The 59 existing declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/daily_ledger_pos_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 66 keys (full-set pin — this suite owns the final totality); each new route with kernel.auth.authenticate@1 and the exact allowed_roles of its group. ALSO assert + document the PERMANENT EXCLUSION: the 4 auth-only dl writes (cashier/ledger/withdrawals, cashier/ledger/dispatch, cashier/ledger/receive-delivery, cashier/ledger/receive-paper-dr) must REMAIN undeclared (assertNotDeclared per route with a comment: dlCurrentUser() with no role set — notdeclarable without changing handler behavior). 66 declared + 4 permanently undeclared = the complete 70-route write surface.
   b. MUST-ALLOW matrix: per route × the roles OF ITS GROUP (Daily Ledger source rows) + kernel-source admin (guard-admitted nuance) — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): all module roles NOT in the route's group (Group A must refuse auditor, viewer; Group B must refuse cashier, auditor, viewer) + a foreign role (e.g. 'technician'). Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks. NB: cashier is a Group-B refuser — the POS fine-grained grant is downstream, the declared boundary is the role set.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + a role outside the group => 403 and the fixture handler marker stays UNSET; an in-group role => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared daily-ledger write route remains permitted — use POST /daily-ledger/api/v1/cashier/ledger/withdrawals with a cashier actor => allowed && !configured && reason=no_authority_declared.
3. Convert tests/daily_ledger_admin_documents_route_authority_declaration_test.php exact-59 totality to a SUBSET pin of its 59 keys (established pattern: `array_diff($expected, array_keys($declared)) === []`, per-route exact checks unchanged, comment naming your suite as the final totality owner).
4. Test header must document the ROLLBACK FALSIFIER: any caller the handlers admit (a role in the route's declared group) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the downstream-discipline nuance and the permanent 4-route exclusion.

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json, the new test file, and the authorized 11c conversion. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_pos_route_authority_declaration_test.php              -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-pos-coverage.sh                           -> write_enforced=66 read_enforced=0 delegated_bound=0, rc 0 (~53s; run once)
3. php tests/workbench_governance_census_test.php                               -> PASS rc=0
4. slices stay green: gui 24/0; cms 37/0 + 15/0; dl 46/0 + 134/0 + slice-11/11b/11c suites (subset pins green); harpp 66/0 + 84/0; ecommerce 34/0; aw 77/0 + 84/0 + 60/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and the two test files (one new ??, one modified)
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; group role boundaries; downstream-discipline nuances; the permanent 4-route exclusion; pre-handler proof; per-route teeth; the 59-key conversion; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-pos

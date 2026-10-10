#!/usr/bin/env bash
#
# Lane: operational-role-branch-scope
#
# Owner-reported symptoms (2026-10-10), verbatim:
#   1. "for a production user, area branches are not properly filtered. dipolog Rizal Commissary
#       includes Pagadian commissary branches"
#   2. "daily sheet also displays non area branches"
#   3. "then let's also ensure cashier role user accounts are properly scoped to assigned branches,
#       so with production user accounts"
#
# The chair has already MEASURED the topology and the code map (below). Start from those facts;
# re-measure anything you doubt rather than trusting the summary.
#
# DISPATCHED MODEL: Sol. This is reasoning work (find a cross-view inconsistency) plus surgical
# edits at the call sites, which is Sol's lane by the repo's role split.
#
# PLATFORM: PHP 8.5 locally; PRODUCTION IS MYSQL 5.7 and no 5.7 server exists locally - label any
# 5.7 claim as INSPECTION-ONLY.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing a cross-view branch-scoping defect in the Ikabud repo at
/var/www/html/applicationostest. PHP 8.5 local, MySQL 5.7 in production (INSPECTION-ONLY locally).

# OWNER-REPORTED SYMPTOMS (verbatim, do not water down)
1. "for a production user, area branches are not properly filtered. dipolog Rizal Commissary
   includes Pagadian commissary branches"
2. "daily sheet also displays non area branches"
3. "then let's also ensure cashier role user accounts are properly scoped to assigned branches,
   so with production user accounts"

# MEASURED TOPOLOGY (tenant 207, real data - re-verify if you doubt it)
Two commissaries:
  PAG-COMMISARY1  "Pagadian Commisary"  area=Pagadian City
      DAP/other? NO - its 6 branches are ALL area=Pagadian City:
      PAG-DUMALINAO1 PAG-GATAS1 PAG-LABANGAN1 PAG-LUMBIA1 PAG-PULMONES1 PAG-SAN JOSE1
  RIZAL-COMMIS1   "RIZAL-COMMIS"        area=Dipolog
      its 11 assigned branches span TWO areas:
      Dapitan : DAP-BAGTING1 DAP-POLO1 DAP-PRINCE1
      Dipolog : DPL-FISHPORT1 DPL-GENLUNA1 DPL-HERITG1 DPL-KATIPUNAN1 DPL-MINAOG1 DPL-MP1
                DPL-OBAY1 DPL-RIZAL1
So RIZAL-COMMIS1 legitimately serving Dapitan + Dipolog is CORRECT and must not be "fixed".
Pagadian branches appearing under the Dipolog commissary is the defect.

Operational users (all roles cashier / production_in_charge / supervisor) each have EXACTLY ONE
dl_user_branches row:
  #27 prod-rizal        -> RIZAL-COMMIS1
  #32 Prod_RizalPM      -> RIZAL-COMMIS1
  cashiers #19..#43     -> one branch each
That makes "is every operational user scoped to assigned branches" a CHECKABLE invariant.

# THE CODE MAP THE CHAIR ALREADY BUILT
modules/daily-ledger/handlers.php:2550  dl_accessibleBranchIds(array $user): array  <- canonical resolver
    admin/auditor/viewer -> ALL active branches
    supervisor           -> dl_user_branches
    production_in_charge -> dl_user_branches
    everything else      -> dl_getUserBranchId()  (single branch)   <- cashier path
modules/daily-ledger/helpers/admin-area-scope.php:159  dl_adminViewBranchIds(array $user, ?array $request)
    = dl_accessibleBranchIds() then, ONLY when role === 'admin', intersect with the persisted
      area/commissary view scope. For EVERY non-admin role it RETURNS THE RAW SET UNCHANGED.
modules/daily-ledger/helpers/admin-area-scope.php:44  AdminAreaScope::resolve()
    returns type=ALL for every non-admin role, i.e. an operational role never has a view scope at all.

Call-site split (re-run these greps yourself; counts are from 2026-10-10):
    dl_adminViewBranchIds(  -> 17 call sites (view/presentation queries)
    dl_accessibleBranchIds( -> 34 call sites, MOSTLY authorization  e.g.
        in_array($branchId, dl_accessibleBranchIds($user), true)   <- CORRECT, leave alone
      but SOME are PRESENTATION and look unscoped, e.g.
        handlers.php:11532  apiProductionDestinations   $allowedBranchIds = dl_accessibleBranchIds($user)
        handlers.php:22153  $allowedBranchIds = dl_accessibleBranchIds($user)
        handlers.php:21215  $allowedBranchIds = dl_accessibleBranchIds($user)
        handlers.php:20364  foreach (dl_accessibleBranchIds($user) as $accessibleBranchId)
        handlers-deliveries.php:2343  'accessible' => dl_accessibleBranchIds($user)
The daily sheet's destination columns come from handleAdminCommissary around handlers.php:20396-20440:
    $sheetScopeSql / $sheetCommissarySql appended to
    SELECT id, code, name FROM dl_branches WHERE is_active = 1 ...  ORDER BY ...
and the dispatch matrix filter (dl_fetchProductionSheetDispatchMatrix, ~line 1120) scopes the
DELIVERY ROWS by commissary but NOT the COLUMNS - the columns come from that branch SELECT.

# THE LAW YOU MUST HOLD (this is the whole design)
Separate the two meanings, and never let one leak into the other:
  AUTHORIZATION  what an actor MAY touch. Resolved ONLY by dl_accessibleBranchIds(). A view scope
                 must NEVER widen it - and must never narrow it either, because narrowing the
                 authorized set by a UI filter turns a presentation choice into an access decision.
  PRESENTATION   what a list, picker, dropdown, sheet column or report SHOWS. MUST be scoped to the
                 actor's assigned branches, and further narrowed by the area/commissary scope when
                 one applies.
An operational role that is shown a branch outside dl_accessibleBranchIds($user) is the bug. Fix it
by scoping the PRESENTATION path, not by weakening authorization.

# WHAT TO DO
STEP 1 - REPRODUCE FIRST, and report the repro even if everything after it fails.
  Write a probe under .ai/ (gitignored) that, for a production user at RIZAL-COMMIS1 and for one
  cashier, prints the branch set each of these actually exposes:
     (a) the production destination picker   (apiProductionDestinations path)
     (b) the daily/production sheet destination columns  (handleAdminCommissary column SELECT)
     (c) the deliveries 'accessible' list    (handlers-deliveries.php:2343)
     (d) any other picker you find in the call-site map
  and asserts: every listed branch is in that user's dl_user_branches set; and no branch whose
  assigned_commissary_id is PAG-COMMISARY1 appears for a RIZAL-COMMIS1 user.
  SHOW THE PROBE FAILING before you change anything. If it does NOT fail, STOP and report that the
  symptom is not reproduced by these paths - with the evidence - instead of inventing a fix. A
  trusted wrong guard is worse than none, and a green "fix" for an unreproduced symptom is not a fix.

STEP 2 - Fix minimally at the presentation call sites you PROVED wrong. Prefer routing them through
  one existing helper over adding a new one; if the honest answer is that the helper itself is wrong
  for operational roles, change the helper and say so explicitly.

STEP 3 - Keep Dapitan+Dipolog under RIZAL-COMMIS1 working. Do not "fix" the two-area network.
  Do not change authorization semantics. Do not touch migrations unless unavoidable.

STEP 4 - Tests. Add assertions to the existing daily-ledger suites in tests/daily-ledger/ following
  their conventions (see tests/daily-ledger/daily_ledger_area_rollout_test.php). Every guard you add
  must be shown FALSIFIABLE: mutate the fix, show the assertion goes red, restore, show green.
  Report the mutation you used. A guard that cannot go red is decorative.

# HARD CONSTRAINTS
- MySQL 5.7: no window functions, no CTEs, no JSON_TABLE, no CHECK. InnoDB + utf8mb4.
- Do NOT weaken, disable or "adjust" an existing assertion to reach PASS. If an existing oracle
  disagrees, find which side is stale (git log -S the assertion, then git log -1 the commit) and
  report it. Measured precedent in this repo: a red test is evidence of DISAGREEMENT, not of a bug.
- Do NOT expand scope beyond branch scoping for operational roles.
- Report BLOCKED with the precise reason rather than improvising if the contract cannot be satisfied.

# REPORT (keep it compact, in this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
repro: <the probe, and the command that shows it red on the base tree>
root_cause: <one paragraph, naming file:line>
changed: <file:line for each edit, one line each>
fix_summary: <what the presentation paths now do>
verification:
  probe_after: <green output>
  suites: <suite name -> passed/failed, from the JSON in test_results/, not from grep>
  falsification: <the mutation, and the assertion that went red>
scope:
  unexpected_files: <any file you changed that is not branch scoping>
risks:
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-operational-role-branch-scope

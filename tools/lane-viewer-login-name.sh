#!/usr/bin/env bash
#
# Lane: viewer-login-name
#
# Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md — §4 F12, §6 W9, §11 D6.
#   F12: the login marks Full Name `required` for EVERY account, and for a personal account whose
#        profile overwrite is refused, the typed value still becomes the session/audit actor name.
#   D6:  minimum fix - not required for personal accounts, and never let the typed value replace
#        the authenticated session identity. Do NOT break the shared-cashier name workflow.
#
# Criterion (2 RED on the base, proven by the dispatch gate):
#   tools/acceptance-viewer-login-name.php   (probe accounts + real HTTP; cleans up in finally)
#
# Owner directive driving this dispatch (2026-10-12): delegate to Sol until rate is exhausted.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is
MySQL 5.7. Local site http://baronledger.test runs tenant 207 (a copy of production). Surface: the
daily-ledger login page and its auth endpoint, seen as the business owner does.

# CONTEXT (read first)

- docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md - §4 F12, §6 W9, §11 D6.
- tools/acceptance-viewer-login-name.php - YOUR ORACLE. 2 RED right now. Make it 0 RED WITHOUT
  weakening it. N3 (shared cashier workflow), N4 (login with no name typed) and N5 (refusal still
  logged with both values) are REGRESSION PINS that must stay green.
- The authoritative contract for the shared-account workflow lives in
  .ai/shared-account-latest-holder-contract.md and is enforced by
  tests/daily-ledger/daily_ledger_shared_account_latest_holder_test.php - read the test's AC2: the
  personal account's DB rules (empty-only capture + refusal warning) are NOT yours to change; only
  the SESSION payload precedence is broken.

# MEASURED DEFECT (the oracle prints it)

The typed Full Name currently overrides the session identity BEFORE the account-type rules run
(handlers.php ~7769-7772: `$payload['name'] = $enteredFullName; $payload['full_name'] = ...`).
For a personal account (role NOT in dl_sharedBranchAccountRoles()) whose overwrite is refused, the
stored profile keeps the correct name but the JWT - and therefore every audit actor_name stamped
from the session - carries "Intruder Rewrite". Measured: stored="Personal Probe Stored",
session="Intruder Rewrite". For a shared cashier account the override is CORRECT behaviour and
must stay (latest holder).

# REQUIRED OUTCOME (D6 minimum)

1. templates/modules/daily-ledger/pages/login.disyl: the `input name="full_name"` must NOT carry
   the `required` attribute (keep the field, its id/autocomplete/placeholder and the
   {login_full_name_label | default:...} mechanism). A personal account can legitimately leave it
   empty. You may adjust the label/placeholder wording to say who should fill it (shared branch
   accounts) - keep the customizable default mechanism intact.
2. modules/daily-ledger/handlers.php, the full_name block in dailyLedgerAuthLogin (~7755-7885):
   - SHARED accounts (dl_sharedBranchAccountRoles): behaviour unchanged - typed name updates the
     profile (latest holder) and becomes the session name.
   - PERSONAL accounts: the typed name must NEVER replace the session identity when it did not
     become the stored profile name. Concretely: after the existing rules ran,
     - if the typed value was captured into an EMPTY profile (rowCount>0), session name = typed
       (it now equals the stored name - fine);
     - if the overwrite was REFUSED (or the DB row was missing), session name/full_name must be
       the STORED profile name (or the same fallback the no-typed-name path uses:
       `$u['full_name'] ?? $payload['name']` from ~7759/7881).
   - Do NOT change: the empty-only capture SQL, the refusal warning line, the shared update line
     or its previous/new fields, the role list, the audit stamps, the login audit row.
3. Keep the no-typed-name path (~7880-7882) working as it does today.

# CONSTRAINTS

- Files you may change: templates/modules/daily-ledger/pages/login.disyl and the full_name block
  of modules/daily-ledger/handlers.php ONLY.
- Do NOT modify the oracle, tests/**, or the contract files. If an existing test disagrees with
  the settled design, report it as unresolved (the harness re-anchors oracles itself).
- MySQL 5.7: no window functions / CTEs / JSON_TABLE.
- Do NOT git add / git commit; leave the tree for review.
- DiSyL lint: `php _lint_disyl.php` must stay at 703/703.

# VERIFY (report the actual output lines)

1. php tools/acceptance-viewer-login-name.php                            -> must end '0 RED'
2. php tests/daily-ledger/daily_ledger_shared_account_latest_holder_test.php -> must stay green
   (it exercises the real HTTP flow with its own probe accounts; skip is NOT acceptable here -
   the server is reachable)
3. php _lint_disyl.php                                                   -> 703/703

# REPORT (exactly this shape)

status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: how the session name is chosen for each account type now (one sentence), and the
  wording decision on the login field
verification: the three commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-viewer-login-name

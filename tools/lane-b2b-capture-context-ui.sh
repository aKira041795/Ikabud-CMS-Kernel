#!/usr/bin/env bash
#
# Lane: b2b-capture-context-ui
#
# OWNER REQUEST (2026-10-06, verbatim): "i looked at the send to branch modal.
#   1. we can visually show the shift and name of logged in cashier - this make for the capturing of
#      the dispatch simpler
#   2. receiving - when modal is opened - visually sees the branch source, shift and cashier name -
#      easy comparison to DR paper aside from the products listed
#   our process then easily capture these data flow at the background"
# OWNER DECISION: "approved as live join or snapshotted column in case shift has ended and cashier
# receiving encodes late" => SNAPSHOT the dispatcher name, with a live-join fallback for old rows.
#
# CONTRACT (AUTHORITATIVE): .ai/b2b-capture-context-ui-contract.md
#
# READ THIS BEFORE MEASURING ANYTHING. The chair already measured this ground and two of its initial
# assumptions were WRONG, so re-deriving from scratch will re-introduce them:
#   * the receive modal ALREADY shows the source branch, the production shift (required) and the DR
#     chip, and ALREADY locks the receiving shift for a shift-bound cashier. Do not "add" those.
#   * the ONLY missing field on the receiving side is the DISPATCHER'S NAME.
#   * user_name is already in the cashier page payload; window.SHIFT / window.SHIFT_LOCKED are already
#     globals the modals use; the dispatch modal already posts shift: (window.SHIFT || '').
#   * apiReceiveDelivery forces the receive date to TODAY and never asserts shift mutability, so a
#     late receipt after a shift ended is deliberately ALLOWED. Do not add a block.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "use SOl"). This is small but has two correctness traps - a displayed value that
# differs from the stored one, and a blank where no electronic source exists. terra is the strong
# fallback; flash last.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the branch-to-branch capture-context UI in the Ikabud repo at
/var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim
INSPECTION-ONLY.

READ FIRST, and treat it as authoritative:
  .ai/b2b-capture-context-ui-contract.md
Then only as needed:
  modules/daily-ledger/handlers.php                    apiGetIncomingDeliveries (~8821), the dispatch
                                                       INSERT (~7916) and the informal receive (~8964)
  modules/daily-ledger/handlers-offline.php            the offline dispatch INSERT (~697)
  templates/modules/daily-ledger/cashier/dispatch_modal.disyl
  templates/modules/daily-ledger/cashier/receive_modal.disyl
  modules/daily-ledger/database/migrations/072_delivery_receipt_shifts.sql   (the guarded migration pattern to copy)
  modules/daily-ledger/database/migrations/055_cashier_withdrawal_shift.sql

## C1 — snapshot the dispatching cashier's name (migration 079)
Add a nullable column named `encoded_by_name_snapshot` beside the existing dispatcher id on
dl_cashier_withdrawals, following the SAME pattern as the existing liable_user_name snapshot: written
at INSERT time from dl_userDisplayNameById(), NOT resolved later. BOTH dispatch INSERTs must write it
(handlers.php and handlers-offline.php) — a snapshot written on one path only is worse than none.
Historical rows stay NULL. Guarded information_schema + PREPARE, rerun-safe, MySQL 5.7-safe, and
registered in module.json.

DO NOT reuse the name `encoded_by_name`: it is ALREADY TAKEN by a live-resolving alias at
handlers.php:13630, and a column sharing that name would invite a reader to treat a frozen value as
live. The `_snapshot` suffix is the contract.

## C2 — the receiving modal can see WHO SENT IT
apiGetIncomingDeliveries must return the dispatching cashier's id AND name per transfer: the
SNAPSHOT when present, else a live LEFT JOIN dl_users fallback, else null. Use the module's existing
COALESCE shape: COALESCE(NULLIF(snapshot,''), NULLIF(u.full_name,''), u.username, '').
ADDITIVE to the payload — do not rename or drop any existing key.

## C3 — the dispatch modal shows what it is about to stamp
One read-only line: the logged-in cashier's name and the shift. The name comes from `{user_name}`
(already in the page payload) and the shift must be EXACTLY `window.SHIFT` — the same expression the
form already posts. NEVER re-derive it: if the displayed shift and the posted shift can diverge, the
UI lies about what it recorded, which is worse than showing nothing.

## C4 — the receiving modal shows who is receiving, and who sent it
- A read-only "Receiving as: {user_name} · <shift> shift". When window.SHIFT_LOCKED, present it as
  LOCKED the same way the modal's existing shift control does; for an unbound user show the resolved
  value they are about to commit.
- Per transfer: "Sent by: <name>". When the name is genuinely absent for old data, render the
  explicit text "not recorded (historical)" — NEVER a blank.
- PAPER-ONLY CASE (owner's scenario 2): a transfer that exists only as a captured paper DR has no
  electronic dispatcher and no stored dispatch shift. State that explicitly, e.g.
  "not electronically dispatched — captured from paper DR", and LEAVE the production-shift selector
  REQUIRED there, because that value can only come from the paper.

## ORACLE — discrimination required
Fixtures with private ids (never real branch 8 or the live tenant's real data); clean up in finally.
For every case state whether it fails on the base tree:
  D1 (**discriminating**) the incoming payload carries the dispatcher's name (base: the field is not
      selected at all).
  D2 (**discriminating**) a dispatch written by the new code stores encoded_by_name_snapshot (base:
      the column does not exist).
  D3 (**discriminating**) the dispatch modal renders the logged-in name and the shift, and the
      rendered shift EQUALS the shift the form submits — assert BOTH, because the trap is divergence.
  D4 (**discriminating**) the receiving modal renders the dispatcher for a transfer, and for a
      paper-only transfer renders the explicit not-electronically-dispatched sentence rather than an
      empty field.
  D5 (pin) the receive modal still shows source branch + production shift + DR, and still locks the
      receiving shift for a shift-bound cashier (these already exist — assert they SURVIVED).
  D6 (pin) a late receipt with an ended shift is still ALLOWED (do not let this lane introduce a block).
If a case also passes on base, LABEL it a pin — do not present it as a win.

## Rules
- Smallest correct change. Display + one snapshot column. No refactor, no new dependency.
- Never weaken, skip or delete an assertion to reach green.
- A test that writes to storage/logs/app.log MUST restore the log to its pre-test bytes, so it leaves
  no [error] residue an admin would chase. (This cost the owner a real "what is this?" this session.)
- Run: php -l on each changed PHP file; php scripts/run-tests.php --dir=tests/daily-ledger; check BOTH
  storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).
- The suite's known noise: one test trips on the benign `kernel_state_cache: module_registry rebuilt`
  cold-cache log line, and one prints "Result: 11 passed, 0 failed" instead of "N/N passed". Neither
  is a real failure; do not "fix" them, and do not use them to excuse a REAL failure.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree
  discrimination per case; anything you could not verify; and any place the chair's contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/b2b-capture-context-ui
rc=$?
echo "lane: b2b-capture-context-ui — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

#!/usr/bin/env bash
#
# Lane: b2b-capture-context-ui-completion
#
# Completes the slice that lane b2b-capture-context-ui correctly reported as PARTIAL.
#
# WHY THIS EXISTS: the chair's original contract named the WRONG table for the active dispatch path.
# The Send to Branch modal posts to /api/v1/cashier/ledger/dispatch -> apiCreateCashierDispatch(),
# which inserts dl_deliveries; the contract named the dl_cashier_withdrawals withdrawal writers. The
# lane raised HARPP decision #147 and refused to improvise, which is exactly right.
#
# CONTRACT (AUTHORITATIVE, read the CORRECTION section at the end):
#   .ai/b2b-capture-context-ui-contract.md
#
# ALREADY DONE by the previous lane (do NOT redo, do NOT revert):
#   migration 079 + encoded_by_name_snapshot on dl_cashier_withdrawals (both withdrawal writers),
#   the dispatcher name in the incoming payload with snapshot-first live fallback, the dispatch modal
#   identity line, the receive modal receiver context + historical fallback + paper-only wording.
#   That lane's oracle is 7/7 and must stay green.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "use SOl"). The remaining work is small but contains the single most dangerous
# trap in this whole slice: a paper-captured delivery's creator IS the receiver, so labelling it
# "Sent by" would state, as fact, that the receiver shipped goods to themselves.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are COMPLETING the branch-to-branch capture-context slice in the Ikabud repo at
/var/www/html/applicationostest. A previous Sol lane implemented most of it and reported PARTIAL
because the chair's contract named the wrong table. Read the CORRECTION section at the end of
.ai/b2b-capture-context-ui-contract.md — it is the authority for this lane.

PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim
INSPECTION-ONLY.

## THE MEASURED FACT THE PREVIOUS CONTRACT GOT WRONG
  The active Send to Branch modal posts to /api/v1/cashier/ledger/dispatch ->
  apiCreateCashierDispatch() (handlers.php ~8651-8822), which inserts **dl_deliveries** +
  dl_delivery_items. The contract named the dl_cashier_withdrawals INSERTs, which belong to the
  withdrawal writers — a different path with a different table. Both paths exist and the receive
  modal shows BOTH kinds, so both need the dispatcher name available.

## C1 — extend migration 079 with a dl_deliveries creator-name snapshot
Add a nullable snapshot of the creator's display name to dl_deliveries, in the SAME guarded,
rerun-safe migration file the previous lane created. Freeze it on BOTH delivery-creation paths
(apiCreateCashierDispatch AND the paper-DR capture INSERT in apiReceivePaperDelivery), because the
column records a fact about the row. Write it the way the previous lane wrote its counterpart —
from dl_userDisplayNameById(), NOT resolved later.

## C2 — THE GUARD. This is the most important requirement in this lane.
  On a PAPER-CAPTURED delivery, created_by is the RECEIVING branch's cashier: they built the row from
  the paper. Rendering that name under "Sent by" would assert, as fact, that the receiver shipped the
  goods to themselves.
  Therefore the LABEL must be driven by PROVENANCE, never by "is the name non-null":
    - provenance_status = 'none'             -> "Sent by: <snapshot name>"
    - provenance_status = 'paper_dr_pending' -> the explicit paper-DR sentence
                                               ("not electronically dispatched - captured from paper DR")
  The audit's own `source` value ('cashier_dispatch' vs 'captured_from_paper_dr') records the same
  distinction; keep the two consistent.

## C3 — the incoming payload must cover BOTH kinds
apiGetIncomingDeliveries must return the dispatcher name for the dl_deliveries-backed transfers
(delivery_ids) as well as the withdrawal-backed ones, using the module's existing COALESCE shape
(snapshot first, then a live LEFT JOIN dl_users fallback, else null). ADDITIVE to the payload — do not
rename or drop existing keys. If the endpoint serves both kinds from separate queries, change both.

## C4 — the receive modal labels by provenance
Use the corrected label rule above. Keep everything the previous lane already added working.

## ORACLE — extend the existing suite, do not start a new file
  The previous lane's oracle (tests/daily-ledger/daily_ledger_b2b_capture_context_test.php, 7/7) must
  stay green; extend it.
  D7 (**discriminating**) a cashier-dispatched delivery's incoming payload carries the dispatcher
      name and the modal labels it "Sent by". Base: field absent -> fails.
  D8 (**discriminating**) a PAPER-CAPTURED delivery with a POPULATED creator snapshot is NOT labelled
      "Sent by" — it renders the explicit paper-DR sentence. This is the mislabelling guard; build the
      fixture with a populated snapshot so the case can only pass by reading provenance, not by
      finding an empty field. Base: no such logic -> fails.
  Re-state discrimination for anything you touch. A case that passes on base is a PIN — label it.

## Rules
- Smallest correct change. Do NOT revert or restyle the previous lane's work.
- Never weaken, skip or delete an assertion to reach green.
- A test that writes to storage/logs/app.log MUST restore the log to its pre-test bytes so it leaves
  no [error] residue an admin would chase.
- Run: php -l on each changed PHP file; php scripts/run-tests.php --dir=tests/daily-ledger; check BOTH
  storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).
- KNOWN, PRE-EXISTING, NOT YOURS: daily_ledger_shared_account_latest_holder_test.php fails its
  LOG-OBSERVATION assertions (its functional assertions pass). The chair proved this is pre-existing —
  it fails identically with the handler files at 3c93ad6c, before any of today's work. Do NOT attempt
  to fix it, and do NOT use it to excuse a genuine failure you introduce.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree discrimination
  per case; anything you could not verify; and any further place the chair's contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/b2b-capture-context-ui-completion
rc=$?
echo "lane: b2b-capture-context-ui-completion — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

#!/usr/bin/env bash
#
# Lane: offline-queue-scope-stranding
#
# P0. Cashiers report they cannot save data since the October 1 production-daily-sheet upgrade.
#
# MEASURED BY THE CHAIR (evidence, not theory):
#   - Both real cashier accounts authenticate fine with cmiputak123:
#       cashier-miputak    = user id 19, role cashier, shift 'PM'   (LOGIN OK)
#       cashier-miputakAM  = user id 22, role cashier, shift 'AM'   (LOGIN OK)
#     There is NO cashier-miputakPM user. Login is NOT the fault.
#   - A direct cell save on the ONLINE path works:
#       POST /daily-ledger/api/v1/cashier/ledger/save -> 200 {"ok":true,"field":"beg_bal","value":2}
#   - The sheet reports day status "open" and "All saved", yet shows an amber banner:
#       "Pending ledger saves from another user, branch, date, or schema were quarantined and will
#        not be retried automatically."   with the count "1 pending".
#   - Server logs are CLEAN: no errors, only slow_request warnings. The stranded ops never reach
#     the server.
#
# THE MECHANISM THE CHAIR LOCATED (templates/.../cashier/ledger.disyl):
#   L501-506  PENDING_STORAGE_PREFIX='daily-ledger:pending-saves'
#             LEGACY_PENDING_PREFIX='bbs_pending_saves'
#             PENDING_SCHEMA_VERSION = 3
#             PENDING_TENANT_SCOPE = window.DL_TENANT_SCOPE || 'tenant'
#             PENDING_ACTOR_SCOPE  = window.DL_USER_ID || 'anonymous'      <-- the ACTOR
#   L515      QUARANTINE_KEY includes PENDING_ACTOR_SCOPE
#   L673      an entry whose schema_version !== PENDING_SCHEMA_VERSION is dropped
#   L694-708  quarantinePendingEntries(entries, reason, sourceKey)
#   L710-724  listPendingKeys() returns EVERY key starting with the pending prefix
#   L730-762  syncPendingStorage(): for each key that is NOT the current PENDING_KEY it quarantines
#             the entries with reason 'scope-mismatch' (or 'legacy-key') and then calls
#             localStorage.removeItem(key) - they leave the active path and are NEVER retried
#
# WHY THAT MATCHES "since October 1": that release split cashiers into PER-SHIFT ACCOUNTS
# (886545fe "restrict production environment to the Daily Sheet; add AM/PM shifts", b6e928f5,
# e678d001). A device whose queue is keyed to the OLD user id no longer matches the current
# PENDING_KEY, so its queued saves are quarantined as coming from "another user".
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are debugging a P0 DATA-LOSS report in the Daily Ledger. The chair has formed a hypothesis and
wants it CONFIRMED OR REFUTED BY MEASUREMENT. Do not reason forward from the brief - test it.

THE REPORT
Cashiers (and probably production users) say they cannot save data since the October 1 upgrade that
introduced the production daily sheets.

WHAT THE CHAIR MEASURED (verified, take as given)
  - Both real cashier accounts authenticate fine with cmiputak123:
      cashier-miputak    = user id 19, role cashier, shift 'PM'   -> LOGIN OK
      cashier-miputakAM  = user id 22, role cashier, shift 'AM'   -> LOGIN OK
    There is NO cashier-miputakPM user. Login is NOT the fault.
  - A direct cell save on the ONLINE path WORKS:
      POST /daily-ledger/api/v1/cashier/ledger/save -> 200 {"ok":true,"field":"beg_bal","value":2}
    and the sheet reports day status "open" / "All saved".
  - Despite that, the sheet shows an amber banner with the count "1 pending":
      "Pending ledger saves from another user, branch, date, or schema were quarantined and will
       not be retried automatically."
  - Server logs are CLEAN: no errors, only slow_request warnings - so the stranded ops never reach
    the server.

THE HYPOTHESIS (templates/modules/daily-ledger/cashier/ledger.disyl)
  L501-506  PENDING_STORAGE_PREFIX='daily-ledger:pending-saves'
            LEGACY_PENDING_PREFIX='bbs_pending_saves'
            PENDING_SCHEMA_VERSION = 3
            PENDING_TENANT_SCOPE = window.DL_TENANT_SCOPE || 'tenant'
            PENDING_ACTOR_SCOPE  = window.DL_USER_ID || 'anonymous'      <-- the ACTOR
  L515      QUARANTINE_KEY includes PENDING_ACTOR_SCOPE
  L673      an entry whose schema_version !== PENDING_SCHEMA_VERSION does not normalize
  L694-708  quarantinePendingEntries(entries, reason, sourceKey)
  L710-724  listPendingKeys() returns EVERY key starting with the pending prefix
  L730-762  syncPendingStorage(): for each key that is NOT the current PENDING_KEY it quarantines
            the entries with reason 'scope-mismatch' (or 'legacy-key') and then calls
            localStorage.removeItem(key) - so they leave the active path and are NEVER retried.

  The October 1 release split cashiers into PER-SHIFT ACCOUNTS (886545fe "restrict production
  environment to the Daily Sheet; add AM/PM shifts", b6e928f5, e678d001). If a device's queue is
  keyed to the OLD user id, it no longer matches the current PENDING_KEY and its queued saves are
  quarantined as "another user" - matching the banner text and the date.

================================================================================
1. CONFIRM OR REFUTE BY SIMULATION - this is the decisive evidence
================================================================================
Reproduce it end to end in a browser:
  - log in as one cashier account and put an entry in its queue (or write a queue entry directly
    into localStorage under the PREFIX:<tenant>:<that user id> key);
  - clear cookies, log in as the OTHER cashier account (different user id);
  - show what happens to the first account's entries.
Report the localStorage keys and the quarantine payload you actually observed, and the banner count
before and after. If it does NOT reproduce, say so plainly and keep looking - a refuted hypothesis
is a useful result, not a failure.

================================================================================
2. QUANTIFY THE BLAST RADIUS
================================================================================
  - Is cashier-miputak (id 19, shift 'PM') the PRE-EXISTING identity and cashier-miputakAM (id 22)
    a NEW one? Settle it with created_at on dl_users. If id 22 was created on/after Oct 1, then any
    device that queued under id 19 and now logs in as id 22 has stranded entries.
  - Is the PRODUCTION sheet exposed to the same hazard? Check whether window.DL_USER_ID is emitted
    there and whether it has its own queue/quarantine keys.
  - Enumerate EVERY trigger that can strand a queue the same way (actor change, tenant change,
    schema bump, branch change, legacy prefix). Each one is a data-loss path with this shape.

================================================================================
3. THE FIX - and it must not lose data
================================================================================
The cross-user safety is legitimate: a device must not replay another user's entries into the wrong
ledger. Do NOT turn that guard into a no-op. Find a fix that KEEPS the safety and STOPS the loss.
Consider and RANK with reasoning:
  a. RE-KEY: when the actor changes but the entries are for a branch+date+shift the CURRENT actor is
     allowed to write, migrate them into the current queue rather than quarantining;
  b. RECOVER: keep quarantining, but add an explicit verified recovery action that replays the
     quarantined entries under the current identity. Note the UI already has a "Purge stale saves"
     button, and PURGING IS NOT RECOVERY;
  c. both.
Requirements for whichever you choose:
  - NO DOUBLE POST: replay must stay idempotent (the ops carry an idempotency key / client_op_id and
    the reconcile path dedups on it). PROVE the dedup holds for a recovered entry.
  - NO LEAKAGE: an entry for a branch the actor cannot access must NOT be replayed; it may stay
    quarantined.
  - The banner must tell the operator the truth about what is recoverable versus lost.
If the proper fix needs a product decision, state exactly what the decision is.

================================================================================
4. WAS THIS AN OVERSIGHT IN THE OCT 1 RELEASE?
================================================================================
Check whether the shift-split commit(s) shipped any migration for existing device queues. If the
split changed the actor identity of real users and nothing re-keyed or migrated their queues, name
the commit that should have handled it.

================================================================================
5. DELIVERABLE
================================================================================
A written diagnosis: the confirmed mechanism (or your correction), the blast radius with numbers,
the ranked fix, and the code change you propose. IMPLEMENT the fix you rank first if it is bounded
and safe; if it is not, implement nothing and report the decision needed.
Add a test that FAILS on the current tree and passes after your fix. It must reproduce the STRANDING
(queue under actor A, log in as actor B, assert the entries SURVIVE and are replayable rather than
being quarantined and dropped) - not merely assert that a function exists.

CONSTRAINTS
  - APP_URL=http://baronledger.test IS REQUIRED for playwright.
  - Do NOT weaken the cross-user guard into a no-op.
  - Do NOT delete or purge quarantined data anywhere, in the fix or in the tests.
  - Keep existing suites green:
      daily_ledger_production_controls_test 70/70, daily_ledger_reporting_test 76/76,
      daily_ledger_handlers_test 229/229, daily_ledger_routes_test 82/82,
      daily_ledger_c1_derived_ending_test 7/7
  - Check BOTH logs (storage/logs/app.log, storage/logs/error.log) and report what you found.

Useful chair-written repro scripts - read them, extend them, do not break them:
  .ai/repro-cashier-save.js         login + attempt a cell save, capture network
  .ai/repro-cashier-logins.js       credential mapping across candidate accounts
  .ai/repro-autosave-persist.js     page state + queue dump + a targeted cell write

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  the confirmed mechanism with the measurement that proves it, or your correction
  blast radius with numbers and the created_at evidence
  every trigger that can strand a queue
  the ranked fix, what you implemented, and why
  the test you added, and the failure it produced BEFORE the fix
  verification: exact commands and numbers
  logs:
  risks / unresolved
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/offline-queue-scope-stranding
rc=$?
echo "lane: offline-queue-scope-stranding — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

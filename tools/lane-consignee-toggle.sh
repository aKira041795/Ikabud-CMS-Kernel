#!/usr/bin/env bash
#
# Lane: consignee-feature-toggle — switch the whole consignee capability on/off from admin settings.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-feature-toggle.contract.md
#
# Owner, 2026-10-08: "and let's make a feature we can turn on/off, just like POS at admin settings".
#
# MIRROR THE POS TOGGLE - it is already the established pattern:
#   setting key `pos_enabled` in dlSettingsDefaults(); helper dl_isPosEnabled();
#   settings.disyl has `<input type="checkbox" id="feature-pos" {if pos_enabled}checked{/if}>` with an
#   explanatory line, a "POS is Enabled/Disabled" status pill, and
#   `payload.pos_enabled = document.getElementById('feature-pos').checked;` on save.
#
# THE RULE THIS SLICE LIVES OR DIES BY:
#   "off" disables the CAPABILITY, it NEVER hides HISTORY.
# Tenant 207 already holds real consignee records (Lee Plaza, ledger rows, dispatch E2E-B2C-001 and its
# verified provenance). A "disable" that blanks or filters those would be a data-integrity failure
# wearing a settings costume. Gate case E exists solely to catch that.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the consignee feature toggle in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-feature-toggle.contract.md FIRST - it is the authority, including the measured POS
precedent and the prohibited list.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## THE RULE
"Off" disables the CAPABILITY. It NEVER hides history.

The lazy implementation is "when disabled, hide everything" - which would make already-recorded
consignee deliveries, ledger rows and audit entries INVISIBLE. Tenant 207 already holds real consignee
records (Lee Plaza, consignee ledger rows, a dispatch E2E-B2C-001 with verified provenance). Turning the
feature off must leave every one of them readable.

  DISABLED blocks new use: no consignee creation, no new consignee dispatch, and the entry points are
  hidden (Branches -> Consignees tab, the dispatch modal's Consignees optgroup, the production sheet's
  Consignees tab, Products -> Show in Consignees).
  DISABLED preserves history: consignee records already written stay visible wherever they are history -
  the admin Deliveries list, the activity log, the consignee ledger. Do not blank them, do not filter
  them out of an existing record view.

DEFAULT MUST BE ENABLED (true). The capability is built and in live use; defaulting to disabled would
behave like a regression on upgrade. A tenant that does not want consignees switches it off deliberately.

## MIRROR THE POS TOGGLE (measured)
- `pos_enabled` in dlSettingsDefaults(); helper dl_isPosEnabled()
- settings.disyl: `<input type="checkbox" id="feature-pos" {if pos_enabled}checked{/if}>` plus an
  explanatory line, and a status pill "POS is Enabled/Disabled"
- save: `payload.pos_enabled = document.getElementById('feature-pos').checked;`
Do the same for consignees: `consignee_enabled`, `dl_isConsigneeEnabled()`, `#feature-consignee`, a
"Consignees are Enabled/Disabled" pill, and `payload.consignee_enabled`.

## WORK
R1 add `consignee_enabled` (bool, default TRUE) to dlSettingsDefaults() with a normalizer that coerces
   anything unrecognised to the DEFAULT, never to false - an unknown value must not silently disable a
   live feature. Add dl_isConsigneeEnabled() mirroring dl_isPosEnabled().
R2 add the toggle to /daily-ledger/admin/settings mirroring POS in markup, placement, copy and pill. It
   must round-trip: save, reload, reflect the stored value.
R3 when DISABLED: hide the entry points listed above AND make apiCreateCashierDispatch REFUSE a
   consignee destination with a clear message. Hiding a button is not a guard - a stale open page could
   still post.
R4 when DISABLED: every already-recorded consignee record remains queryable and visible in its existing
   history view. Prove it with a test that turns the feature OFF with existing consignee data present and
   asserts the data is still returned and unchanged.
R5 when ENABLED: nothing behaves differently from today.
R6 the toggle must not delete, soft-delete, deactivate or renumber any consignee, ledger row, delivery
   or effect.

## PROHIBITED
- do NOT hide or filter out recorded consignee history when disabled
- do NOT default to disabled
- do NOT delete or deactivate data on disable
- do NOT let this toggle change any posting - it grants or withholds capability and nothing more
- do NOT weaken, skip or delete an existing test; no new dependencies
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - the setting exists and defaults to ENABLED
  - it round-trips through dlPersistModuleSettings
  - a garbage value coerces to the default, NOT to disabled
  - with the feature DISABLED the dispatch API refuses a consignee destination (and the refusal names
    the feature, not some unrelated reason)
  - PIN: with the feature DISABLED, pre-existing consignee history is still returned and unchanged
  - PIN: enabling restores exactly today's behaviour (a branch dispatch still works)
Label each case discriminating or pin and say what each pin defends. Restore any setting you change and
assert you restored it - the suite runs against a live tenant. Clean up fixture rows.
Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() exits the process, so an in-process
call silently false-passes (measured on the slice-2 gate, rc=0 with no assertions run).

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log
- daily_ledger_manifest_test.php 125/125 and daily_ledger_routes_test.php 82/82 stay green
- no regression in daily_ledger_consignee_admin_test.php, daily_ledger_consignee_activity_test.php,
  daily_ledger_consignee_isolation_test.php 9/9, daily_ledger_consignee_audit_test.php, b2b 14/14 and
  10/10, and the consignee sales-mode suite if present
- KNOWN PRE-EXISTING, NOT YOURS: daily_ledger_consignee_audit_test.php's F pin (branch production sheet
  render) fails because storage/cache is www-data-owned and the CLI cannot compile the template - the
  page renders correctly live. Do NOT try to fix it and do NOT call it a regression you caused. Eight
  further suites are red with counts identical at HEAD, and daily_ledger_handlers_test.php aborts
  resolving the base db. Do not fix them; do not use them to excuse a failure you introduce.
- ENVIRONMENT TRAPS you must respect when writing tests/probes:
    * information_schema is FORBIDDEN under modulePushContext ("Access to system schema
      'information_schema' is forbidden for modules"). It throws, and a try/catch turns that into a
      SILENT FALSE. Use SHOW COLUMNS.
    * DiSyL ESCAPES rendered output, so a value reaches the screen as {&quot;x&quot;:1}. HTML-decode
      before scanning markup for JSON.
    * DiSyL TRAP: a JS object literal in a <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
      followed by an identifier AND the braces contain a ? : ternary - that shipped JSON.stringify(0)
      and an HTTP 500 in slice 1.
    * A baseline snapshot must be taken AFTER your fixture exists, or your own fixture makes it look
      like something changed.
- Report BLOCKED with a precise reason rather than improvising around the contract.
- Report status PASS | PARTIAL | BLOCKED; files changed; per-case discrimination; the evidence that
  disabling destroys nothing; anywhere the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-feature-toggle
rc=$?
echo "lane: consignee-feature-toggle — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

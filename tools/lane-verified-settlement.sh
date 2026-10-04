#!/usr/bin/env bash
#
# Lane: verified-settlement
#
# Owner: "move it but allow admin to verify for finality. that will solve the irreversible status"
#
# Writes the settled ending into the ledger - but tagged, provisional while unverified, and
# REVERSIBLE. That is what makes the write acceptable, and it keeps caveat C1 intact: a derived
# ending is never indistinguishable from a count.
#
# Contract: .ai/daily-ledger-verified-settlement.contract.md (R1-R9, measured M1-M4).
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing settle -> admin-verify-for-finality -> revert for unfinalized shifts in the
Ikabud Daily Ledger. The design is already decided and measured; your job is the code.

STEP 1 - read the contract and follow it exactly, including every ruling R1-R9:
  /var/www/html/applicationostest/.ai/daily-ledger-verified-settlement.contract.md
Context you should NOT redo: dl_settleUnfinalizedRow() and dl_rowIsProvisional() already exist from
an earlier commit, with the ladder rungs 'counted' | 'counted-unsigned' | 'derived-next-beginning' |
'derived-from-movements' | 'zero-forced'. Build on them.

WHAT TO BUILD

R1. MIGRATION 074 (074 is VERIFIED FREE - the highest daily-ledger migration is 073).
    File: modules/daily-ledger/database/migrations/074_<slug>.sql
    Register it in modules/daily-ledger/module.json "migrations".
    House style is in modules/daily-ledger/database/migrations/070_production_daily_sheet_shift.sql:
    every operation is guarded and rerun-safe, MySQL 5.7 compatible:
      SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = '<t>' AND column_name = '<c>'), 'SELECT 1', 'ALTER TABLE <t> ADD COLUMN ...');
      PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    Add FOUR columns to BOTH dl_daily_ledger and dl_commissary_product_ledger:
      end_source VARCHAR(24) NULL DEFAULT NULL, end_settled_at DATETIME NULL DEFAULT NULL,
      end_verified_by INT UNSIGNED NULL DEFAULT NULL, end_verified_at DATETIME NULL DEFAULT NULL
    Do NOT backfill: historical NULL means "a person counted this", which is its meaning today.

R2. Vocabulary - these strings only, the oracle asserts them literally:
      NULL                      the ending (if any) is a COUNT
      'derived-from-movements'  rule 2 settle, awaiting verification
      'zero-forced'             rule 1 settle, awaiting verification

R3/R4/R5. THREE SERVICES in modules/daily-ledger/handlers.php. NOTE THE SIGNATURE: they take the
    resolved $actor ARRAY (with 'id' and 'role'), not a bare id, so authorization is enforced inside
    and is assertable in-process:
      dl_settlePendingEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
      dl_verifySettledEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
      dl_revertSettledEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
    Return arrays carrying at least ['settled' => n] / ['verified' => n] / ['reverted' => n] - the
    oracle reads those keys.

    SETTLE: for each row of that shift whose ending is NULL, compute movements with the CALLER's own
    invariant (cashier beg+addtl-withdraw; production beg_qty+produced_qty-dispatched_qty-wastage_qty
    - keep the two separate, never unify), run dl_settleUnfinalizedRow(null, <shift finalized?>,
    movements, null, false), then WRITE that ending into bal_end / actual_end_qty, set
    end_source = the rung, end_settled_at = NOW(). MUST NOT touch a row whose ending is not NULL.
    Idempotent: a repeat call settles nothing and writes no audit.

    VERIFY (ADMIN ONLY): promote every unverified derived row of the shift to a count -
    end_source = NULL, end_verified_by = $actor['id'], end_verified_at = NOW(). The ENDING VALUE IS
    NOT CHANGED: a verify is a human certifying the number, not a recomputation.

    REVERT (ADMIN ONLY): for every UNVERIFIED derived row, ending = NULL, end_source = NULL,
    end_settled_at = NULL. This is the irreversibility guarantee. A VERIFIED row is NOT reverted.

R7. AUTHORIZATION INSIDE the services: settle may be done by admin/supervisor/production_in_charge;
    VERIFY and REVERT are role 'admin' ONLY. A non-admin attempt must throw a RuntimeException and
    change NOTHING - so check the role BEFORE any write.

R6. A DERIVED ROW MUST NOT MASQUERADE AS A COUNT:
    (a) dl_rowIsProvisional() in modules/daily-ledger/helpers/reporting.php must return TRUE for any
        row whose 'end_source' is a derived rung, whatever the shift status - so a settled row is
        provisional and cannot be official before verification.
    (b) a derived ending must NOT manufacture a variance of zero. Find where variance is recomputed
        for the commissary ledger (calc_variance) and make it SKIP rows whose end_source is a derived
        rung, leaving calc_variance NULL - otherwise a settle silently silences the "nobody counted
        this" signal (measured M4).

R8. Each operation runs in ONE transaction and writes one audit row per changed row:
    actions 'settle_derived_ending' / 'verify_derived_ending' / 'revert_derived_ending', through the
    module's existing dl_auditLog() helper, with the before/after ending and the rung.

R9. Expose the three operations through routes.php + thin handlers in the existing
    'daily-ledger:functionName' style, so an admin can actually perform them.

HARD CONSTRAINTS
  - Do NOT touch the tests. Do NOT edit, skip or weaken any assertion.
  - Do NOT wire rung 3 / 'derived-next-beginning'. It stays unreachable: the carry audit records a
    row COUNT, not product ids, so per-product provenance does not exist.
  - Do NOT change the ladder's rung strings or either movement invariant.
  - Do NOT settle automatically from a page render or from the auto-close. Settling is explicit.
  - Do NOT add any migration other than 074. Do not renumber existing migrations.
  - Do NOT touch templates in this lane (the admin control is a separate, smaller lane).

STEP 2 - VERIFY, in order, and paste the actual output:
  a) php -l on every file you changed; and prove the migration is rerun-safe by applying it TWICE
     through the module's normal CLI (php ikabud tenant:migrate <tenant> daily-ledger) and showing
     both runs succeed.
  b) THE PRIMARY ORACLE. It reads 56/57 now (8 lifecycle assertions are parked behind the missing
     services); it must read 65/65:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
  c) REGRESSION
       php tests/daily-ledger/daily_ledger_production_sheet_test.php          # 53/53
       php tests/daily-ledger/daily_ledger_production_beg_carry_test.php      # 12/12
       php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php      # 8/8
       php tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php  # 17/17
       php tests/daily-ledger/daily_ledger_addtl_correction_test.php          # 83/83
       php tools/disyl-conformance-check.php
  d) BROWSER (the sheets must still work end to end):
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-close-failure-guidance.spec.js --reporter=line
     must be 1 passed, 1 passed, 2 passed.
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:  (say where each of the three services is called from)
  migration:               (the file, the columns added, and proof the double-apply succeeded)
  verification:            (the actual commands and their outcome)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result. If a derived value cannot be kept distinguishable from a
count, STOP and report BLOCKED.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/verified-settlement
rc=$?
echo "lane: verified-settlement — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"

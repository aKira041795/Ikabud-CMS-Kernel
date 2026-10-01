# Daily Ledger — complete sweep baseline

Recorded by the preservation lane that restored the lost auto-DR offline guard
(2026-10-01). The point of this file is that a future lane compares against
numbers that were **measured**, across **all** daily-ledger suites, not the
37-and-one-format subset the earlier sweeps used.

## The instrument

    tools/sweep-daily-ledger.sh            # human summary
    tools/sweep-daily-ledger.sh --json     # JSONL: one object per suite + a summary object
    tools/sweep-daily-ledger.sh --baseline=FILE

It runs three trees, understands three output formats, and treats `FATAL` and
`NO RESULT` as their own outcomes (never passes). It exits non-zero when any
suite fails, fatals, or produces no result. A suite must print a fully-passing
summary **and** exit 0 to count as a pass; a non-zero exit after a passing
summary is itself a failure. Under `--json`, the output is pure JSON Lines (no
human headers or separators): one object per suite
(`{"suite":...,"outcome":...,"got":...,"want":...,"exit":...}`) followed by
one `{"summary":true,...}` object; the process exit status is unchanged.

Trees (48 suites at the time of record):

| tree | suites |
|---|---|
| `tests/daily-ledger/*_test.php` | 38 |
| `tests/daily_ledger*_test.php` (root) | 9 |
| `tests/load/daily_ledger_load_test.php` | 1 |

(The brief said "46 suites"; 37 + 9 + 1 was already 47, and this lane added one
behavioural guard suite, so the measured total is 48.)

Output formats handled:

* `N/N passed` — `TestHarness` suites in `tests/daily-ledger/`
* `Result: N passed, M failed` — root suites and `tests/daily_ledger_*`
* `PASS: N   FAIL: M   TOTAL: T` — `daily_ledger_drless_box_edit_test.php` etc.
* no summary → `FATAL` (only when no summary exists *and* the output carries a
  fatal/exception line) or `NO RESULT`

Parsing was verified by hand against captured raw output. The important case is
`daily_ledger_handlers_test.php`: it prints `SQLSTATE[42S02]` inside an
informational bullet while passing **229/229**. The sweep parses the summary
first, so that string is not mistaken for a fatal.

## Before / after

Before = `HEAD` at `9246d6c8` with the cashier-phase fixes as they were found
(the auto-DR guard absent, the button-feedback lock assertion red, the sweep
still in `/tmp`). After = this lane's changes.

| | suites | passed | failed | fatal | no result | assertions |
|---|---|---|---|---|---|---|
| before | 47 | 36 | 7 | 2 | 2 | 2317 / 2329 |
| after  | 48 | 40 | 5 | 1 | 2 | 2350 / 2360 |

Suites that changed state, and why:

| suite | before | after | why |
|---|---|---|---|
| `daily_ledger_receive_offline_guard_test.php` | (new) | 11/11 | behavioural proof of the restored auto-DR offline guard |
| `daily_ledger_button_feedback_contract_test.php` | 41/42 | 43/43 | lock assertion rewritten to protect each gated trigger (not a fixed count of 2), plus a self-falsifying check that removing one lock is detected |
| `daily_ledger_drless_box_edit_test.php` | 35/36 | 36/36 | string-only guard assertion replaced with a "never enqueues" check; behaviour proven by the new suite |
| `daily_ledger_password_reset_test.php` | FATAL | 18/18 | pointed at tenant 207 (`baronledger.test`), not the base DB; interpreted template pipeline for CLI |
| `daily_ledger_offline_pwa_test.php` | 106/108 | 107/109 | server-side auto-DR replay backstop added (2 pre-existing failures remain) |
| `daily_ledger_full_process_test.php` | 49/52 | 49/52 | still fails on the removed selling-account flow, but now deletes the integrity notifications it used to leak each run |

Unchanged pre-existing reds / non-results (named, not hidden):

| suite | outcome | note |
|---|---|---|
| `daily_ledger_offline_pwa_test.php` | 107/109 | 2 failures in the offline-withdrawal DB guard, pre-existing |
| `daily_ledger_overview_test.php` | 104/105 | fails its "app.log untouched" check (DISyL compile log line), pre-existing |
| `daily_ledger_reporting_test.php` | 76/77 | same app.log check, pre-existing |
| `daily_ledger_shift_target_test.php` | 35/38 | PM duplicate-replay timing, pre-existing |
| `daily_ledger_full_process_test.php` | 49/52 | removed selling-account flow (`destination_type` enum), not-a-guard |
| `daily_ledger_inventory_spec_test.php` | FATAL | same removed selling-account flow, not-a-guard |
| `daily_ledger_android_api_test.php` | NO RESULT | empty stub, not-a-guard |
| `tests/load/daily_ledger_load_test.php` | NO RESULT | load harness; needs seeded fixtures and a live server, not-a-guard |

## Tenant datum (queried, not asserted)

`baronledger.test` (tenant 207, db `baronledger`):

| | `dl_integrity_notifications` | `dl_integrity_notification_recipients` |
|---|---|---|
| before sweep | 1 | 1 |
| after sweep  | 1 | 1 |

`daily_ledger_full_process_test.php` was leaking one notification and two
recipients per run (uncounted-receipt findings for its test branches). Its
teardown now deletes them. The counts above were read with a throwaway query
before and after a full 48-suite sweep.

## Reproduce

```bash
BEFORE=$(php -r '...count query...')   # see lane notes
tools/sweep-daily-ledger.sh
AFTER=$(php -r '...count query...')
# BEFORE and AFTER must be identical
```

## Full before listing (47 suites, HEAD 9246d6c8, before this lane)

```
daily_ledger_addstock_reason_test.php                      42/42
daily_ledger_addtl_correction_test.php                     83/83
daily_ledger_adjustment_matrix_test.php                    58/58
daily_ledger_admin_trace_test.php                          66/66
daily_ledger_authz_test.php                                82/82
daily_ledger_branch_cell_entry_test.php                    29/29
daily_ledger_branch_order_test.php                         13/13
daily_ledger_brand_asset_downscale_test.php                33/33
daily_ledger_daily_sheet_log_evidence_test.php             16/16
daily_ledger_dated_pricing_test.php                        23/23
daily_ledger_defect_fixes_s13_test.php                     47/47
daily_ledger_delayed_producer_test.php                     27/27
daily_ledger_dispatch_enforcement_test.php                 14/14
daily_ledger_forecast_test.php                             18/18
daily_ledger_handlers_test.php                             229/229
daily_ledger_integrity_resolution_test.php                 8/8
daily_ledger_log_order_test.php                            3/3
daily_ledger_manifest_test.php                             125/125
daily_ledger_offline_pwa_test.php                          106/108
daily_ledger_overview_settings_restore_test.php            9/9
daily_ledger_overview_test.php                             104/105
daily_ledger_pos_test.php                                  201/201
daily_ledger_preserve_cashier_variance_test.php            28/28
daily_ledger_production_sheet_test.php                     53/53
daily_ledger_pullout_classification_test.php               21/21
daily_ledger_pwa_assets_test.php                           63/63
daily_ledger_receipt_count_test.php                        8/8
daily_ledger_received_vs_sent_test.php                     25/25
daily_ledger_recompute_sales_button_test.php               57/57
daily_ledger_reporting_test.php                            76/77
daily_ledger_routes_test.php                               82/82
daily_ledger_same_location_release_test.php                129/129
daily_ledger_shift_reconciliation_test.php                 80/80
daily_ledger_shift_target_test.php                         35/38
daily_ledger_variance_review_note_test.php                 32/32
daily_ledger_variance_test.php                             112/112
daily_ledger_withdrawal_recomputes_sales_test.php          28/28
daily_ledger_android_api_test.php                          NO RESULT
daily_ledger_audit_legacy_schema_test.php                  12/12
daily_ledger_button_feedback_contract_test.php             41/42
daily_ledger_dispatch_destination_test.php                 9/9
daily_ledger_drless_box_edit_test.php                      35/36
daily_ledger_full_process_test.php                         49/52
daily_ledger_inventory_spec_test.php                       FATAL
daily_ledger_password_reset_test.php                       FATAL
daily_ledger_settings_compare_test.php                     6/6
daily_ledger_load_test.php                                 NO RESULT
```

## Full after listing (48 suites, this lane)

```
--- tests/daily-ledger/*_test.php ---
  daily_ledger_addstock_reason_test.php                      42/42
  daily_ledger_addtl_correction_test.php                     83/83
  daily_ledger_adjustment_matrix_test.php                    58/58
  daily_ledger_admin_trace_test.php                          66/66
  daily_ledger_authz_test.php                                82/82
  daily_ledger_branch_cell_entry_test.php                    29/29
  daily_ledger_branch_order_test.php                         13/13
  daily_ledger_brand_asset_downscale_test.php                33/33
  daily_ledger_daily_sheet_log_evidence_test.php             16/16
  daily_ledger_dated_pricing_test.php                        23/23
  daily_ledger_defect_fixes_s13_test.php                     47/47
  daily_ledger_delayed_producer_test.php                     27/27
  daily_ledger_dispatch_enforcement_test.php                 14/14
  daily_ledger_forecast_test.php                             18/18
  daily_ledger_handlers_test.php                             229/229
  daily_ledger_integrity_resolution_test.php                 8/8
  daily_ledger_log_order_test.php                            3/3
  daily_ledger_manifest_test.php                             125/125
  daily_ledger_offline_pwa_test.php                          107/109  <-- FAIL
  daily_ledger_overview_settings_restore_test.php            9/9
  daily_ledger_overview_test.php                             104/105  <-- FAIL
  daily_ledger_pos_test.php                                  201/201
  daily_ledger_preserve_cashier_variance_test.php            28/28
  daily_ledger_production_sheet_test.php                     53/53
  daily_ledger_pullout_classification_test.php               21/21
  daily_ledger_pwa_assets_test.php                           63/63
  daily_ledger_receipt_count_test.php                        8/8
  daily_ledger_received_vs_sent_test.php                     25/25
  daily_ledger_receive_offline_guard_test.php                11/11
  daily_ledger_recompute_sales_button_test.php               57/57
  daily_ledger_reporting_test.php                            76/77  <-- FAIL
  daily_ledger_routes_test.php                               82/82
  daily_ledger_same_location_release_test.php                129/129
  daily_ledger_shift_reconciliation_test.php                 80/80
  daily_ledger_shift_target_test.php                         35/38  <-- FAIL
  daily_ledger_variance_review_note_test.php                 32/32
  daily_ledger_variance_test.php                             112/112
  daily_ledger_withdrawal_recomputes_sales_test.php          28/28
--- tests/daily_ledger*_test.php ---
  daily_ledger_android_api_test.php                          NO RESULT
  daily_ledger_audit_legacy_schema_test.php                  12/12
  daily_ledger_button_feedback_contract_test.php             43/43
  daily_ledger_dispatch_destination_test.php                 9/9
  daily_ledger_drless_box_edit_test.php                      36/36
  daily_ledger_full_process_test.php                         49/52  <-- FAIL
  daily_ledger_inventory_spec_test.php                       FATAL
  daily_ledger_password_reset_test.php                       18/18
  daily_ledger_settings_compare_test.php                     6/6
--- tests/load/daily_ledger_load_test.php ---
  daily_ledger_load_test.php                                 NO RESULT

=====================================================
  suites:     passed=40 failed=5 fatal=1 no_result=2
              total=48
  assertions: 2350 / 2360 (where a summary was produced)
=====================================================
  PROBLEMS:
```

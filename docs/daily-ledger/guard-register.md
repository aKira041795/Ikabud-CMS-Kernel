# Daily Ledger — cashier-phase guard register (preservation)

Owner's directive: *"just make sure that the fixes we have set during the cashier
ledger testing phase which were resolved are preserved and then enhanced by these
discussions."*

This file records, for each behaviour fixed during the cashier testing phase, the
suite that fails when the behaviour regresses. A fix is preserved only when
something fails loudly the moment it is undone — and when that check actually
runs. Unguarded behaviours are called out explicitly: those are the ones that
can silently regress, as the auto-DR guard did.

## Restored by this lane

### Auto-DR production receive cannot be queued offline

* **History.** `templates/modules/daily-ledger/cashier/receive_modal.disyl` had
  `autoDrBlockedOffline()` with three call sites through `febe6029^`. `febe6029`
  deleted all of them while making ledger writes online-only. The old test only
  asserted `strpos($tpl, 'autoDrBlockedOffline') !== false`, so it stayed green.
* **Why it matters.** Auto-DR production receive mints the DR server-side; the
  offline replay path (`dl_offlineApplyReceivePaperDr`) requires an explicit
  paper DR, so a queued auto-DR receipt could never be honoured. The cashier
  would be told it would sync.
* **What was actually true at HEAD.** The same commit that deleted the guard
  (`febe6029`) also removed *all* queue calls from the receive modal and made
  ledger writes online-only. So the modal no longer queued auto-DR itself; the
  brief's "will queue that" was true before `febe6029`, not at HEAD. The
  invariant was therefore held only by the *absence* of queue calls — a comment
  (`Do NOT reintroduce enqueueOperation here`) is not a guard, and the queue
  writers and offline shell still exist. The restored guard is not dead
  duplication:
  * it refuses offline auto-DR **before any write** and gives the specific,
    actionable reason instead of a generic network error;
  * the server replay now rejects `auto_dr` explicitly, which nothing did before;
  * the behavioural suite fails if the guard is removed *or* a queue call is
    reintroduced into the write path.
* **Restored.**
  * Client: `autoDrBlockedOffline()` is back, with the original message, and is
    checked before the write and in the transport-failure path. Offline +
    auto-DR is refused before any write and never enqueued.
  * Server backstop: `dl_offlineApplyReceivePaperDr()` now rejects any payload
    carrying `auto_dr` with a 422 and the reason, so a queue entry that still
    reaches the replay path is refused explicitly.
* **Guard.** `tests/daily-ledger/daily_ledger_receive_offline_guard_test.php`
  runs the real `receiveModal()` script in Node and asserts the behaviour
  (refused, no write, no enqueue, the reason shown). It also mutates the
  template to remove the guard and asserts the same scenario then *does* write —
  a revert-failing check, so the assertions cannot pass tautologically.
  The server backstop is asserted in
  `tests/daily-ledger/daily_ledger_offline_pwa_test.php`
  ("offline replay rejects an auto-DR receive with the reason").
* **Before this lane:** UNGUARDED (string presence only).

## Behaviours with a real guard

| behaviour | guarding suite |
|---|---|
| Session expiry surfaces (401 + `code=session_expired`), keeps the edit retryable, and does not quarantine it | `tests/daily_ledger_button_feedback_contract_test.php` |
| A finalized shift locks the editable cells and triggers; the guard checks **each** trigger, not a fixed count | `tests/daily_ledger_button_feedback_contract_test.php` (rewritten this lane) |
| `shouldQueueOperationFailure()` treats `finalized`/`locked`/`Reference only` as deterministic | `tests/daily_ledger_button_feedback_contract_test.php` |
| Encoder-omission Add Stock needs no liable user; other Add Stock does | `tests/daily-ledger/daily_ledger_addstock_reason_test.php` |
| A negative Add Stock/correction is rejected (422), never silently clamped at 0 | `tests/daily-ledger/daily_ledger_addtl_correction_test.php` |
| Variance review note: present-and-empty clears, absent preserves; max length enforced | `tests/daily-ledger/daily_ledger_variance_review_note_test.php` |
| Finalized-shift lifecycle / PM duplicate replay | `tests/daily-ledger/daily_ledger_shift_target_test.php` (currently red — see baseline) |

## Behaviours that remain UNGUARDED (can silently regress)

| behaviour | why it is unguarded |
|---|---|
| **Native number-spinner guard** — the delegated `mouseup` handler skips `type === 'number'` so `preventDefault()` cannot pin the spinner and make it auto-increment after release (`ledger.disyl` ~1775) | No swept suite asserts the skip. It was reproduced with headed Chrome + CDP mouse events, which is not in `tools/sweep-daily-ledger.sh` and has no Playwright spec in `tests/browser/`. |
| **Cashier history read-only** — past ledger dates are view-only; cashier writes are rejected with `403 "Reference only"` in `apiSaveLedgerField` and friends | `tests/daily-ledger/daily_ledger_authz_test.php` "Business-date enforcements" re-implements the rule in a local `$dateRuleTest` closure and tests the closure, not the handler. Changing the handler to allow yesterday would leave that test green. Only the classifier string `'Reference only'` is pinned in `button_feedback_contract_test.php`. |
| **Auto-DR online success path** (server mints the DR; `auto_dr` mode of `apiReceivePaperDelivery`) | The new guard suite only proves the offline refusal and that online still attempts the write. The minting itself is exercised indirectly by `daily_ledger_drless_box_edit_test.php`; there is no end-to-end HTTP assertion that an online auto-DR receive mints `AUTO-dd/mm/yyyy-n` and posts it. |
| **Production output does not bump `addtl` until the branch receives the delivery** | The only assertion of this behaviour is `tests/daily_ledger_full_process_test.php` (`output resulting_addtl reflects delivery-based flow`), and that suite is a not-a-guard (fails on the removed selling-account flow). `daily_ledger_inventory_spec_test.php` also covered this flow but fatals before reaching it. |

## Formally not-a-guard

These produce no usable result (or fail on removed behaviour) and protect
nothing today. They are kept in the sweep count but named here so nobody reads
their colour as signal.

| suite | outcome | reason |
|---|---|---|
| `tests/daily_ledger_full_process_test.php` | 49/52 | Tests a removed feature: it inserts `destination_type = "selling_account"`, which migration `039_cleanup_selling_account_enums.sql` removed ("Selling accounts feature removed in commit cc5f07e"). The `1265 Data truncated` is the ENUM rejecting the value, not a product bug. Its teardown was fixed this lane so it no longer leaks integrity notifications. |
| `tests/daily_ledger_inventory_spec_test.php` | FATAL | Same removed selling-account flow; fatals on the same `destination_type` truncation. |
| `tests/daily_ledger_android_api_test.php` | NO RESULT | Empty stub (`require_once __DIR__ . '/../bootstrap.php'; // Wait, let's look at one file first`). It asserts nothing. |
| `tests/load/daily_ledger_load_test.php` | NO RESULT | Load/concurrency harness, not a correctness guard. It exits 1 with `ERROR no load-test fixtures found` unless `tests/load/seed_load_users.php` has seeded a live server. |

`tests/daily_ledger_password_reset_test.php` was also in this class; it is now
**fixed** (18/18) and guards the password-reset flow against tenant 207.

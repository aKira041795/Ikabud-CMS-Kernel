<?php

declare(strict_types=1);

/**
 * Concern #3 (owner, 2026-11-17): "if the dispatch from production was wrong for an item, the
 * receiving can correct the wrong item selected to the correct one."
 *
 * The RECEIVING branch is the operator. They pick the line they actually got, name the product
 * that should have been sent, and give a reason. The correction must then reach the production
 * side by itself, because dl_commissary_product_ledger.dispatched_qty is a DERIVED read model
 * fed from posted deliveries (dl_applyPostedDeliveryCommissaryLedger) - so the delivery item is
 * corrected and that effect is re-run, rather than adjusting commissary stock by hand.
 *
 * Shape follows the proven wrong-branch correction (dl_moveDeliveryToBranch): reverse the wrong
 * side, apply the correct side, update the record, audit both sides, respect the day/shift lock.
 *
 * This is a CONTRACT test (the pre-dispatch gate can run it with no server). The DB-level
 * round-trip is the follow-up integration test.
 */

$pass = 0;
$fail = 0;
$errors = [];

function dlSubTest(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "\n=== DAILY LEDGER: RECEIVING CORRECTS A WRONG DISPATCHED ITEM ===\n\n";

$root = dirname(__DIR__);
$routes = (string)@file_get_contents($root . '/modules/daily-ledger/routes.php');
$handlerPath = $root . '/modules/daily-ledger/handlers-deliveries.php';
$handlers = (string)@file_get_contents($handlerPath);

dlSubTest('routes.php is readable', $routes !== '');
dlSubTest('handlers-deliveries.php is readable', $handlers !== '');

// 1. A route exists, and it is a POST under the deliveries API (a GET cannot mutate stock).
dlSubTest(
    'a substitute-item route is registered',
    (bool)preg_match('~[\'"]/daily-ledger/api/v1/deliveries/substitute-item[\'"]\s*=>~', $routes)
);

// 2. The handler exists and is the function the route points at.
dlSubTest(
    'the handler function exists',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(~', $handlers)
);
dlSubTest(
    'the route dispatches to that handler',
    (bool)preg_match('~substitute-item[\'"]\s*=>\s*[\'"]daily-ledger:apiSubstituteDeliveryItem[\'"]~', $routes)
);

// 3. Authority: the RECEIVING operator may correct (owner 2026-11-17), and the permission is
//    scoped to the branch they actually work at.
dlSubTest(
    'the handler admits the receiving cashier as well as the office roles',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dlCurrentUser\(\[[^\]]*["\']cashier["\'].*?[\"\']supervisor[\"\']~s', $handlers)
);
dlSubTest(
    'authority is checked against the receiving branch',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_authorizeBranch~s', $handlers)
);

// 4. A reason is mandatory: a stock swap with no explanation is not reviewable.
dlSubTest(
    'a non-empty reason is required',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?\$reason\s*===\s*[\'"]{2}~s', $handlers)
);

// 5. The wrong product must be REVERSED, not silently forgotten.
dlSubTest(
    'the wrong product is reversed with a negative ledger delta',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_applyLedgerDelta\([^;]*-\s*\$quantity~s', $handlers)
);

// 6. The correct product must be CREDITED in the same transaction - but only when the stock
//    actually arrived, otherwise the reversal is refused by the ledger (measured live 2026-11-17).
dlSubTest(
    'the correct product is credited with a positive ledger delta',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_applyLedgerDelta\([^;]*\+\s*\$quantity~s', $handlers)
);
dlSubTest(
    'the stock pair is skipped when the branch has not received the line yet',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?\$stockMoved\s*=\s*\$receivingId\s*>\s*0\s*&&\s*\$receivedQty\s*>\s*0~s', $handlers)
);

// 7. A closed day must refuse the correction (the rule every other mutation follows).
dlSubTest(
    'a closed day refuses the substitution',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_getDayStatus~s', $handlers)
);

// 8. Both sides must be audited so the change is reviewable later.
dlSubTest(
    'the substitution is audited',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_auditLog\([^;]*substitut~s', $handlers)
);

// 9. PRODUCTION SIDE: the derived dispatch effect is re-run, never hand-adjusted.
dlSubTest(
    'the production-side posted-delivery effect is re-applied',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_applyPostedDeliveryCommissaryLedger~s', $handlers)
);
// The Daily Sheet path creates no effect row, so a correction must still undo the dispatch it made.
dlSubTest(
    'a dispatch with no effect row is still reversed on the production side',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?\$hasEffectRow~s', $handlers)
    && str_contains($handlers, 'dl_applyCommissaryProductLedgerDelta')
);

// 10. APPEND-ONLY: the reversal must be recorded, never erased.
$hMain = (string)@file_get_contents($root . '/modules/daily-ledger/handlers.php');
dlSubTest(
    'the correction reverses through the shared helper',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_reversePostedDeliveryCommissaryLedger~s', $handlers)
);
dlSubTest(
    'the correction never deletes a ledger effect row',
    !str_contains($handlers, 'DELETE FROM dl_delivery_ledger_effects'),
    'deleting effects destroys the applied/reversed audit trail'
);
dlSubTest(
    'a reversed effect no longer blocks re-application',
    (bool)preg_match('~delivery_item_id = :id AND effect_status = "applied"~', $hMain),
    'dl_applyPostedDeliveryCommissaryLedger still skips on ANY effect row'
);

// 11. The unique key that made append-only impossible must be gone, with the lookup index kept.
$migration = (string)@file_get_contents(
    $root . '/modules/daily-ledger/database/migrations/104_delivery_ledger_effect_append_only.sql'
);
dlSubTest('migration 104 exists', $migration !== '');
dlSubTest(
    'migration 104 drops the one-row-per-item unique key',
    str_contains($migration, 'DROP INDEX uq_dl_delivery_ledger_effect_item')
);
dlSubTest(
    'migration 104 keeps a per-item lookup index',
    str_contains($migration, 'idx_dl_delivery_effect_item_status (delivery_item_id, effect_status)')
);
// Measured 2026-11-17: dropping the unique key first fails with "needed in a foreign key
// constraint". The replacement index must exist before the drop, or the migration cannot apply.
$idxPos = strpos($migration, 'ADD INDEX idx_dl_delivery_effect_item_status');
$dropPos = strpos($migration, 'DROP INDEX uq_dl_delivery_ledger_effect_item');
dlSubTest(
    'migration 104 creates the replacement index BEFORE dropping the unique key',
    $idxPos !== false && $dropPos !== false && $idxPos < $dropPos,
    'InnoDB will refuse the drop while the FK depends on the unique key'
);
$manifest = (string)@file_get_contents($root . '/modules/daily-ledger/module.json');
dlSubTest(
    'migration 104 is registered in module.json',
    str_contains($manifest, '104_delivery_ledger_effect_append_only.sql')
);

// 13. NOTIFICATION: production and the admin must be told what changed, through the EXISTING
//     integrity-notification store (one row, both surfaces - not a second notifier).
dlSubTest(
    'the correction raises an integrity notification for production',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?dl_raiseIntegrityNotification~s', $handlers)
);
dlSubTest(
    'production_in_charge is included in the notification recipients',
    (bool)preg_match('~function\s+apiSubstituteDeliveryItem\s*\(.*?[\'\"]production_in_charge[\'\"]~s', $handlers)
);
$modal = (string)@file_get_contents($root . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
dlSubTest('the receiving modal is readable', $modal !== '');
dlSubTest(
    'the receiving modal posts to substitute-item',
    str_contains($modal, "/api/v1/deliveries/substitute-item")
);
dlSubTest(
    'the modal sends the wrong and correct product ids',
    str_contains($modal, 'wrong_product_id:') && str_contains($modal, 'correct_product_id:')
);
dlSubTest(
    'each received line offers the correction control',
    str_contains($modal, 'openSubstitution(row.g, row.it)')
);
dlSubTest(
    'the modal states that production follows automatically',
    str_contains($modal, 'do not adjust the commissary by hand')
);
dlSubTest(
    'the server exposes delivery_id on each received line',
    preg_match('~\'delivery_id\' => \(int\)\$row\[\'delivery_id\'\],~', (string)@file_get_contents($root . '/modules/daily-ledger/handlers.php')) === 1
);

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);

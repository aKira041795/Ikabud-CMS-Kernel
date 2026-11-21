<?php

declare(strict_types=1);

/**
 * Daily Ledger — provenance for the Addt'l column.
 *
 * THE COMPLAINT (branch, via the owner):
 *   "pag open nila sa ilang ledger kay naa namay nka additional daan spl ug mga bread, bisan wla pa
 *    nagsend ang rizal sulod ug gawas"
 *
 * Traced on the live dump: not carry-forward (that writes beg_bal only), not a commissary push
 * (blocked, plus an acceptance tool), but `own_production` / `self_production` rows keyed by the
 * branch's own AM account the evening before, stamped to the NEXT business date. Real movements -
 * 748 pcs PANDESAL on branch 25 at 2026-11-19 19:38 for ledger 2026-11-20 AM, and 316+6 pcs SPL on
 * branch 9 at 18:13 - rendered by the sheet exactly like a carried-forward beginning.
 *
 * So the cell must say which it is. These tests pin the sentence: the quantity, the kind, the
 * person who keyed it and when, and the entry count when more than one movement built the number.
 *
 * Integration mode — real tenant DB (207), isolated fixture branch/product.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-addtl-provenance', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
if (!modulePushContext('daily-ledger')) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

$db = module('daily-ledger')->db();

$branchId = 99081;
$productId = 99081;
$otherProductId = 99082;
$date = '2030-05-01';

$cleanup = static function () use ($db, $branchId, $productId, $otherProductId): void {
    $db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $otherProductId]);
};
$cleanup();

// A real branch/product, because dl_cashier_withdrawals has foreign keys.
$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, has_production, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1, 1)',
    [':id' => $branchId, ':code' => 'T-PROV', ':name' => 'Provenance Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'PROV-TEST', ':name' => 'Provenance Product']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $otherProductId, ':sku' => 'PROV-TEST-2', ':name' => 'No Movement Product']
);

$insert = $db->prepare(
    'INSERT INTO dl_cashier_withdrawals
        (branch_id, product_id, ledger_date, shift, withdrawal_type, reason_code, quantity, unit,
         encoded_by, encoded_by_name_snapshot, dedup_hash)
     VALUES (:b, :p, :d, :s, :t, :rc, :q, "pcs", 990081, :name, :hash)'
);
$insert->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => 'AM', ':t' => 'own_production', ':rc' => 'self_production', ':q' => 748, ':name' => 'Marife egas', ':hash' => 'prov-a']);
$insert->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => 'AM', ':t' => 'own_production', ':rc' => 'self_production', ':q' => 6, ':name' => 'Marife egas', ':hash' => 'prov-b']);
$insert->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => 'AM', ':t' => 'adjustment_add', ':rc' => 'encoder_omission', ':q' => -4, ':name' => 'Marife egas', ':hash' => 'prov-c']);
// A different shift must not leak into this sentence.
$insert->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => 'PM', ':t' => 'own_production', ':rc' => 'self_production', ':q' => 99, ':name' => 'Someone Else', ':hash' => 'prov-d']);

$map = dl_addtlProvenanceFor($db, $branchId, $date, 'AM');

$h->test('a product with recorded Add Stock gets a sentence', isset($map[$productId]));
$sentence = (string)($map[$productId] ?? '');
$h->test('the sentence sums every contributing movement (+748 +6, -4 = 750)', str_contains($sentence, '+750'));
$h->test('it names the kind of movement', str_contains($sentence, 'own production') && str_contains($sentence, 'adjustment'));
$h->test('it names WHO keyed it', str_contains($sentence, 'Marife egas'));
$h->test('it names WHEN (day and time)', (bool)preg_match('/\d{2} [A-Z][a-z]{2} \d{2}:\d{2}/', $sentence));
$h->test('it counts the entries when more than one built the number', str_contains($sentence, '3 entries'));
$h->test('it does not pull in the other shift', !str_contains($sentence, 'Someone Else') && !str_contains($sentence, '99'));

$h->test('a product with no movement has no sentence (nothing invented)', !isset($map[$otherProductId]));
$h->test('an empty branch or date yields nothing', dl_addtlProvenanceFor($db, 0, $date, 'AM') === [] && dl_addtlProvenanceFor($db, $branchId, '', 'AM') === []);
$h->test('an unknown branch yields nothing', dl_addtlProvenanceFor($db, 989999, $date, 'AM') === []);

// The template must actually carry the sentence onto the cell.
$partial = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');
$h->test('the editable Addt\'l cell renders the title', str_contains($partial, 'class="addtl-value" data-field="addtl" data-product="{row.product_id}"{if row.addtl_source} title="{row.addtl_source}"{/if}'));
$h->test('the read-only chip renders it too', str_contains($partial, 'class="ledger-chip"' . "\n" . '              data-field="addtl" data-product="{row.product_id}"{if row.addtl_source} title="{row.addtl_source}"{/if}'));
$h->test('the page handler attaches it to the rows it renders', str_contains((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), "'addtl_source'] = \$provenance[\$pid]"));

// Cleanup.
$cleanup();
$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the fixture rows', $left === 0);

$h->done();

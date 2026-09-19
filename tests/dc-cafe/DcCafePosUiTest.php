<?php
/**
 * DC Cafe — POS UI contract test.
 *
 * Template-contract assertions for POS presentation affordances. These guard
 * behaviour that a future template edit could silently drop, which no
 * PHP-level test would catch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-pos-ui', TestHarness::MODE_INTEGRATION, 'dccafe.test');
$h->fingerprint('templates/modules/dc-cafe/pos/index.disyl');

$pos = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/pos/index.disyl');

// ── View switch (cards / rows) ──
$h->section('Product View Switch');

$h->test('view state exists', (bool) preg_match("/view:\s*'(grid|list)'/", $pos));
$h->test(
    'both view options are offered',
    str_contains($pos, "setView('grid')") && str_contains($pos, "setView('list')")
);
$h->test(
    'toggle buttons expose accessible state',
    substr_count($pos, ':aria-pressed="view === ') === 2
    && str_contains($pos, 'aria-label="Card view"')
    && str_contains($pos, 'aria-label="List view"')
);
$h->test(
    'each view is gated so only one renders at a time',
    str_contains($pos, "x-show=\"view === 'grid'\"") && str_contains($pos, "x-show=\"view === 'list'\"")
);
$h->test(
    'preference persists across reloads',
    str_contains($pos, "localStorage.setItem('dcPosView'")
    && str_contains($pos, "localStorage.getItem('dcPosView'")
);
$h->test(
    'storage failures are tolerated (private mode)',
    (bool) preg_match("/localStorage\.setItem\('dcPosView'[\s\S]{0,220}catch/", $pos)
);

// Both views must remain add-to-cart surfaces.
$h->test(
    'list rows use the same add-to-cart handler as cards',
    substr_count($pos, '@click="addToCart(p)"') === 2
);
$h->test(
    'both views iterate the same filtered product set',
    substr_count($pos, 'x-for="p in filteredProducts"') === 2
);
$h->test(
    'views use distinct :key namespaces so x-for does not collide',
    str_contains($pos, ':key="p.id"') && str_contains($pos, ":key=\"'row-' + p.id\"")
);

// Box badge and stock badge must survive in both renderings.
$h->test(
    'box slot badge shown in both views',
    substr_count($pos, 'p.slot_count + \' pcs\'') === 2
);
$h->test(
    'stock badge shown in both views',
    substr_count($pos, "p.current_stock <= 0 ? 'OUT' : p.current_stock") === 2
);

// ── Empty state ──
$h->section('Empty State');
$h->test(
    'a no-results message exists and waits for loading to finish',
    str_contains($pos, 'No products match your search')
    && str_contains($pos, '!loading.products && filteredProducts.length === 0')
);

// ── Prior behaviour preserved ──
$h->section('Existing POS Behaviour Preserved');
$h->test('box products still open the slot picker', str_contains($pos, 'if (product.is_box)'));
$h->test('soft-serve still opens the customizer', str_contains($pos, 'if (product.is_variable)'));
$h->test('payment still posts customizations', str_contains($pos, 'customizations: i.customizations || undefined'));

// ── Default view ──
$h->section('Default Product View');
$h->test(
    'list is the default because a cashier scans rows faster',
    (bool) preg_match("/view:\s*'list'/", $pos)
);

// ── Discount types ──
// The list and its rates come from dc_discount_types. The till keeps a small
// built-in fallback so it still works when the endpoint cannot be reached, but
// the configured list always wins once it arrives.
$h->section('Discount Types');
$h->test('the till loads the configured discount types',
    str_contains($pos, 'loadDiscountTypes()') && str_contains($pos, "/dc-cafe/api/v1/discount-types"));
$h->test('a fallback list exists for a failed load',
    (bool) preg_match('/discountTypes:\s*\[[\s\S]{0,240}senior/', $pos));
$h->test('the select is built from the configured list, not hard-coded',
    str_contains($pos, 'x-for="dt in discountTypes"') && !str_contains($pos, 'Senior Citizen (20%)'));
$h->test('the option label carries the configured rate',
    (bool) preg_match('/dt\.name \+ \' \(\' \+ dt\.default_pct \+ \'%\)\'/', $pos));
$h->test(
    'selecting a type applies its configured rate',
    (bool) preg_match('/onDiscountTypeChange\(\)[\s\S]{0,260}discountRateFor\(this\.discountType\)/', $pos)
);
$h->test(
    'the rate is looked up per type rather than assumed',
    (bool) preg_match('/discountRateFor\(code\)[\s\S]{0,220}discountTypes\.find\(/', $pos)
);
$h->test(
    'a previous rate is not carried onto another discount type',
    (bool) preg_match('/onDiscountTypeChange\(\)[\s\S]{0,520}discountInput = \'\'/', $pos)
);
$h->test(
    'the receipt label resolves from the configured list',
    (bool) preg_match('/discountTypeLabel[\s\S]{0,200}discountTypes\.find\(/', $pos)
);
$h->test('a partner-card discount ships by default', str_contains($pos, "code: 'senior'"));
$h->test(
    'the discount panel can be switched off by policy',
    str_contains($pos, 'x-show="allowDiscount"')
);
$h->test(
    'discount reason is always recorded on the order',
    (bool) preg_match('/discountReasonForPayload[\s\S]{0,200}discount_type|discountReasonForPayload\(\)/', $pos)
    || str_contains($pos, 'discount_reason: this.discountReasonForPayload')
);
$h->test(
    'reason records the applied rate, not just the label',
    (bool) preg_match("/discountTypeLabel \+ ' \(' \+ pct \+ '%\)'/", $pos)
);
$h->test(
    'a peso-amount discount is not mislabelled with a percentage',
    (bool) preg_match("/includes\('%'\)[\s\S]{0,120}: 0;/", $pos)
);

// ── GCash handling ──
$h->section('GCash Tender');
$h->test(
    'GCash is detected by stable code, not display name alone',
    str_contains($pos, "paymentMethodMatches('gcash', 'gcash')")
);
$h->test(
    'cash detection also uses the stable code',
    str_contains($pos, "paymentMethodMatches('cash', 'cash')")
);
$h->test(
    'a digital payment settles at the exact amount due',
    (bool) preg_match('/effectiveTendered[\s\S]{0,220}isGcashPayment \? this\.finalTotal/', $pos)
);
$h->test(
    'the tendered input is replaced, not just hidden, for GCash',
    str_contains($pos, 'x-if="!isGcashPayment"') && str_contains($pos, 'x-if="isGcashPayment"')
);
$h->test('a reference field is offered', str_contains($pos, 'GCash reference no.'));
$h->test(
    'the reference stays optional unless the branch requires it',
    (bool) preg_match("/requireGcashReference \? 'GCash reference no\. \(required\)' : 'GCash reference no\. \(optional\)'/", $pos)
);
$h->test(
    'the required state is signalled visually, not only in the placeholder',
    str_contains($pos, "requireGcashReference && !referenceId ? 'border-amber-400'")
);
$h->test(
    'an empty reference is still omitted from the payload when optional',
    str_contains($pos, 'reference_id: this.referenceId || undefined')
);
$h->test(
    'a reference is dropped when the tender changes',
    (bool) preg_match('/onPaymentMethodChange\(\)[\s\S]{0,180}this\.referenceId = /', $pos)
);
$h->test(
    'the payload sends the effective tendered amount',
    str_contains($pos, 'amount_tendered: this.effectiveTendered')
);

// ── Quick stock entry ──
// A delivery that has not been recorded yet should not stop a sale; the
// cashier tops up the item from the cart instead of leaving the till.
$h->section('Quick Stock Entry');
$h->test('the affordance is gated on the branch policy',
    str_contains($pos, 'quickStockEntry && item.has_stock && item.current_stock <= 0'));
$h->test('only stock-tracked items are offered a top-up',
    str_contains($pos, 'item.has_stock && item.current_stock <= 0'));
$h->test('the top-up reuses the cashier-permitted receive endpoint',
    str_contains($pos, "'/dc-cafe/api/v1/products/receive/batch'"));
$h->test('a zero or negative quantity is refused before the request',
    (bool) preg_match('/saveQuickStock\(item\)[\s\S]{0,260}!\(qty > 0\)/', $pos));
$h->test('the balance is refreshed after a successful top-up',
    (bool) preg_match('/saveQuickStock\(item\)[\s\S]{0,1200}this\.refreshProducts\(\)/', $pos)
    || str_contains($pos, 'refreshProducts()'));
$h->test('cart rows are kept in step with the new balance',
    (bool) preg_match('/refreshProducts\(\)[\s\S]{0,700}i\.current_stock = byId\[i\.id\]\.current_stock/', $pos));

// ── Inventory view switch ──
$h->section('Inventory Product View Switch');
$inv = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/inventory/stock.disyl');
$h->test('inventory offers table and card views',
    str_contains($inv, "setProdView('table')") && str_contains($inv, "setProdView('cards')"));
$h->test('inventory defaults to the table (inline editing is the job)',
    (bool) preg_match("/prodView:\s*'table'/", $inv));
$h->test('inventory view persists per device',
    str_contains($inv, "localStorage.setItem('dcInvProdView'") && str_contains($inv, "localStorage.getItem('dcInvProdView'"));
$h->test('card view keeps stock editable for admin/supervisor',
    (bool) preg_match('/prodView === \'cards\'[\s\S]{0,2600}saveProdStock\(item\)/', $inv));
$h->test('card view is read-only for other roles',
    (bool) preg_match('/prodView === \'cards\'[\s\S]{0,2600}\{else\}[\s\S]{0,400}parseFloat\(item\.current_stock\)/', $inv));

$h->done();

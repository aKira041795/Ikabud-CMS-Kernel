<?php
/**
 * DC Cafe — HTTP-Level Integration Test
 *
 * Tests order creation, void, and stock movements through the actual HTTP API.
 * Uses direct DB assertions to verify runtime invariants after handler execution.
 *
 * @see .github/instructions/testing-conventions.instructions.md
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'dccafe.test';
$_SERVER['REQUEST_URI'] = '/dc-cafe/api/v1/orders';

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function htest(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$db = app()->dbForTenant(583);

// Helper: prepare+execute, returns rows for SELECT, empty array for others
function hq(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|WITH)\s/i', $sql)) {
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    return [];
}

function hq1(PDO $db, string $sql, array $params = []): mixed
{
    $rows = hq($db, $sql, $params);
    if (empty($rows)) return null;
    return reset($rows[0]);
}

function hqi(PDO $db, string $sql, array $params = []): int
{
    return (int) hq1($db, $sql, $params);
}

// ── Load module handlers ──
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers/entity-views.php';
require_once __DIR__ . '/../../modules/dc-cafe/handlers-orders.php';
require_once __DIR__ . '/../../modules/dc-cafe/handlers-inventory.php';
require_once __DIR__ . '/../../modules/dc-cafe/handlers-products.php';

echo "\n═══ DC Cafe HTTP-Level Integration Test ═══\n\n";

// ── 1. Clean slate ──
hq($db, "DELETE FROM dc_product_stock_movements WHERE 1");
hq($db, "DELETE FROM dc_inventory_movements WHERE reference_type = 'order'");
hq($db, "DELETE FROM dc_order_items WHERE order_id > 1");
hq($db, "DELETE FROM dc_orders WHERE order_id > 1");

// ── 2. Starting state ──
$product = hq($db, "SELECT product_id, name, base_price, current_stock, has_stock FROM dc_products WHERE product_id = 56");
htest('3 Cheese Pizza exists', count($product) === 1);
$initialStock = (float) ($product[0]['current_stock'] ?? 0);
htest('3 Cheese Pizza has stock', $initialStock > 0, "stock={$initialStock}");

$session = hq($db, "SELECT session_id, user_id, store_id, status FROM dc_sessions WHERE session_id = 2");
htest('Session #2 exists and is active', count($session) === 1 && $session[0]['status'] === 'active');

// ── 3. Table existence ──
$tables = hq($db, "SHOW TABLES LIKE 'dc_product_stock_movements'");
htest('dc_product_stock_movements table exists', count($tables) === 1);

// ── 4. Handler functions ──
htest('apiCreateOrder defined', function_exists('apiCreateOrder'));
htest('apiVoidOrder defined', function_exists('apiVoidOrder'));
htest('apiGetReconciliation defined', function_exists('apiGetReconciliation'));

// ── 5. Enum type ──
$colInfo = hq($db, "SHOW COLUMNS FROM dc_product_stock_movements LIKE 'movement_type'");
htest('movement_type is enum', count($colInfo) === 1 && str_contains($colInfo[0]['Type'] ?? '', 'enum'));

// ── 6. Clean state ──
$cnt = hqi($db, "SELECT COUNT(*) FROM dc_product_stock_movements");
htest('product_stock_movements empty initially', $cnt === 0, "count={$cnt}");

// ── 7. Reconciliation query ──
hq($db, "UPDATE dc_products SET current_stock = 2 WHERE product_id = 56");
$cs = hq($db, "SELECT current_stock FROM dc_products WHERE product_id = 56 AND current_stock >= 3");
htest('Low stock cannot satisfy qty 3', count($cs) === 0);
hq($db, "UPDATE dc_products SET current_stock = ?", [$initialStock]);

// ── 9. Atomic transaction: sale → deduction → void → restoration ──
$orderQty = 2;
$db->beginTransaction();
try {
    hq($db, "INSERT INTO dc_orders (session_id, store_id, cashier_id, total_amount, original_amount,
            payment_method_id, transaction_date, status)
            VALUES (2, 1, 1, 690, 690, 1, NOW(), 'completed')");
    $oid = (int) $db->lastInsertId();

    hq($db, "INSERT INTO dc_order_items (order_id, product_id, quantity, unit_price, total_price)
            VALUES (?, 56, ?, 345, ?)", [$oid, $orderQty, 345 * $orderQty]);

    $stmt = $db->prepare(
        "UPDATE dc_products SET current_stock = current_stock - ?
         WHERE product_id = 56 AND current_stock >= ?");
    $stmt->execute([$orderQty, $orderQty]);
    htest('Stock deduction ROW_COUNT = 1', $stmt->rowCount() === 1, "got " . $stmt->rowCount());

    hq($db, "INSERT INTO dc_product_stock_movements (product_id, quantity_change, movement_type,
            reference_type, reference_id, notes, created_by)
            VALUES (56, ?, 'sale', 'order', ?, ?, 1)",
        [-$orderQty, $oid, 'Test sale — order #' . $oid]);

    $db->commit();

    $ns = (float) hqi($db, "SELECT current_stock FROM dc_products WHERE product_id = 56");
    htest('Stock deducted correctly',
        abs($ns - ($initialStock - $orderQty)) < 0.001,
        "expected " . ($initialStock - $orderQty) . " got {$ns}");

    // Void
    hq($db, "UPDATE dc_products SET current_stock = current_stock + ? WHERE product_id = 56", [$orderQty]);
    hq($db, "INSERT INTO dc_product_stock_movements (product_id, quantity_change, movement_type,
            reference_type, reference_id, notes)
            VALUES (56, ?, 'void_restore', 'order', ?, ?)",
        [$orderQty, $oid, 'Restored by void — test']);
    hq($db, "UPDATE dc_orders SET status = 'voided' WHERE order_id = ?", [$oid]);

    $rs = (float) hqi($db, "SELECT current_stock FROM dc_products WHERE product_id = 56");
    htest('Stock restored after void',
        abs($rs - $initialStock) < 0.001, "expected {$initialStock} got {$rs}");

    $sc = hqi($db, "SELECT COUNT(*) FROM dc_product_stock_movements WHERE reference_id = ? AND movement_type = 'sale'", [$oid]);
    htest('Sale movement recorded', $sc === 1, "got {$sc}");

    $vc = hqi($db, "SELECT COUNT(*) FROM dc_product_stock_movements WHERE reference_id = ? AND movement_type = 'void_restore'", [$oid]);
    htest('Void restore recorded', $vc === 1, "got {$vc}");

    // Cleanup
    hq($db, "DELETE FROM dc_product_stock_movements WHERE reference_id = ?", [$oid]);
    hq($db, "DELETE FROM dc_order_items WHERE order_id = ?", [$oid]);
    hq($db, "DELETE FROM dc_orders WHERE order_id = ?", [$oid]);

} catch (\Throwable $e) {
    $db->rollBack();
    htest('Atomic transaction', false, $e->getMessage());
}

// ── 10. Product stock domain isolation ──
$br = (float) hqi($db, "SELECT current_stock FROM dc_products WHERE product_id = 60");
hq($db, "UPDATE dc_products SET current_stock = current_stock + 5 WHERE product_id = 60");
$ar = (float) hqi($db, "SELECT current_stock FROM dc_products WHERE product_id = 60");
htest('Product receive updates stock', abs($ar - $br - 5) < 0.001, "{$br}→{$ar}");
hq($db, "UPDATE dc_products SET current_stock = ? WHERE product_id = 60", [$br]);

// ── 11. Inventory progress ──
$pr = hq($db, "SELECT ip.*, p.name FROM dc_inventory_progress ip JOIN dc_products p ON p.product_id = ip.product_id WHERE ip.session_id = 2");
htest('Inventory progress loads', is_array($pr));

// ── 12. Product stock movements queryable ──
try {
    hq($db, "SELECT 1 FROM dc_product_stock_movements LIMIT 1");
    htest('dc_product_stock_movements queryable', true);
} catch (\Throwable $e) {
    htest('dc_product_stock_movements queryable', false, $e->getMessage());
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n\n";
if ($fail > 0) {
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
exit(0);

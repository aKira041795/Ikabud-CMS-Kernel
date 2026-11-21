<?php
/**
 * R36 — ModuleDB CTE/Complex SQL Test
 *
 * Verifies the SQL firewall in ModuleDB correctly blocks or allows
 * various complex SQL patterns: CTEs, UNIONs, subqueries, multi-statement.
 *
 * Run: php tests/moduledb_sql_firewall_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Contracts\ModuleDB;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== ModuleDB SQL Firewall ===\n";

// ── Setup: create a ModuleDB with specific table permissions ──
$db = new ModuleDB(
    app()->db(),
    'test-module',
    ['test_items', 'test_categories'],  // owns_tables
    ['users']                            // reads_tables
);

// ── Test: Allowed queries ──
echo "--- Allowed queries ---\n";

$allowed = [
    'SELECT * FROM test_items WHERE id = 1',
    'SELECT t.*, c.name FROM test_items t JOIN test_categories c ON t.cat_id = c.id',
    'INSERT INTO test_items (name) VALUES ("test")',
    'UPDATE test_items SET name = "updated" WHERE id = 1',
    'DELETE FROM test_items WHERE id = 1',
    'SELECT id, name FROM users WHERE id = 1', // read-only table
    // Derived table: the SELECT list is not a table list. Splitting it on commas used to report
    // the column alias `dl` as an undeclared table and deny a valid statement.
    'SELECT SUM(d.total) AS capped FROM (SELECT t.id, t.name, SUM(t.id) AS total'
        . ' FROM test_items t GROUP BY t.id, t.name) d',
    // Mixed comma list: the real table first, the derived table second.
    'SELECT 1 FROM test_items t, (SELECT c.id FROM test_categories c) x WHERE t.id = x.id',
];

foreach ($allowed as $i => $sql) {
    $ok = true;
    try {
        // Use reflection to call enforceAccess directly
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\Throwable $e) {
        $ok = false;
    }
    $shortSql = substr($sql, 0, 50) . (strlen($sql) > 50 ? '...' : '');
    t("allowed: {$shortSql}", $ok);
}

// ── Test: Blocked queries — DDL ──
echo "\n--- Blocked: DDL ---\n";

$ddlBlocked = [
    'CREATE TABLE evil (id INT)',
    'DROP TABLE test_items',
    'ALTER TABLE test_items ADD COLUMN x INT',
    'TRUNCATE TABLE test_items',
];

foreach ($ddlBlocked as $sql) {
    $blocked = false;
    try {
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    $shortSql = substr($sql, 0, 50);
    t("blocked DDL: {$shortSql}", $blocked);
}

// ── Test: Blocked queries — system schemas ──
echo "\n--- Blocked: system schemas ---\n";

$schemaBlocked = [
    'SELECT * FROM mysql.user',
    'SELECT * FROM performance_schema.events_statements_current',
    'SELECT * FROM sys.schema_tables_with_full_table_scans',
];

foreach ($schemaBlocked as $sql) {
    $blocked = false;
    try {
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    $shortSql = substr($sql, 0, 50);
    t("blocked schema: {$shortSql}", $blocked);
}

// ── Test: Blocked queries — multi-statement injection ──
echo "\n--- Blocked: multi-statement ---\n";

$multiBlocked = [
    'SELECT 1; DROP TABLE test_items',
    'SELECT * FROM test_items; SELECT * FROM users',
];

foreach ($multiBlocked as $sql) {
    $blocked = false;
    try {
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    $shortSql = substr($sql, 0, 50);
    t("blocked multi: {$shortSql}", $blocked);
}

// ── Test: Blocked queries — undeclared tables ──
echo "\n--- Blocked: undeclared tables ---\n";

$tableBlocked = [
    'SELECT * FROM secret_data',
    'INSERT INTO users (name) VALUES ("hack")',  // users is read-only
    'UPDATE users SET admin = 1 WHERE id = 1',   // users is read-only
    'DELETE FROM users WHERE id = 1',             // users is read-only
    // Declared table outer, undeclared inner: skipping the derived clause must NOT hide it.
    'SELECT * FROM (SELECT * FROM secret_data) d',
];

foreach ($tableBlocked as $sql) {
    $blocked = false;
    try {
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    $shortSql = substr($sql, 0, 50);
    t("blocked table: {$shortSql}", $blocked);
}

// ── Test: DCL blocked ──
echo "\n--- Blocked: DCL/dangerous ---\n";

$dclBlocked = [
    'GRANT ALL ON test_items TO "hacker"',
    'LOAD DATA INFILE "/etc/passwd" INTO TABLE test_items',
];

foreach ($dclBlocked as $sql) {
    $blocked = false;
    try {
        $ref = new \ReflectionMethod($db, 'enforceAccess');
        $ref->invoke($db, $sql);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    $shortSql = substr($sql, 0, 50);
    t("blocked DCL: {$shortSql}", $blocked);
}

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);

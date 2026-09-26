<?php
/**
 * Phase 0 — ModuleDB SQL firewall: joined-table extraction.
 *
 * Regression test for the extractor bug where the optional alias group
 * consumed the literal JOIN keyword, so the second table in a join chain was
 * never checked against the module's owns_tables / reads_tables policy.
 *
 * Calls the real ModuleDB::assertAccess(); uses an isolated in-memory SQLite
 * PDO so no tenant/base database is touched.
 *
 * Run: php tests/phase0_module_db_join_firewall_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Contracts\ModuleDB;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
    }
}

$pdo = new PDO('sqlite::memory:');

/**
 * @param string[] $owns
 * @param string[] $reads
 */
function assertAccessOutcome(PDO $pdo, string $sql, array $owns, array $reads): string
{
    $db = new ModuleDB($pdo, 'phase0-test', $owns, $reads);
    try {
        $db->assertAccess($sql);
        return 'ALLOWED';
    } catch (\RuntimeException $e) {
        return 'REFUSED';
    }
}

echo "=== Phase 0: ModuleDB join firewall ===\n";

$owns = ['test_items', 'test_categories'];

echo "--- must refuse (joined undeclared table) ---\n";
$mustRefuse = [
    'bare JOIN'        => 'SELECT k.* FROM test_items JOIN kernel_secrets k',
    'bare FROM'        => 'SELECT k.* FROM kernel_secrets',
    'AS alias'         => 'SELECT * FROM kernel_secrets AS k',
    'LEFT JOIN'        => 'SELECT * FROM test_items LEFT JOIN kernel_secrets k ON 1=1',
    'chained JOINs'    => 'SELECT * FROM test_items JOIN test_categories c ON c.id=test_items.id JOIN kernel_secrets k ON 1=1',
    'comma list'       => 'SELECT * FROM test_items, kernel_secrets',
    'JOIN then comma'  => 'SELECT * FROM test_items JOIN kernel_secrets, other_secret',
];
foreach ($mustRefuse as $label => $sql) {
    t("refuse {$label}", assertAccessOutcome($pdo, $sql, $owns, []) === 'REFUSED');
}

echo "--- must allow (owned tables only) ---\n";
$mustAllow = [
    'single owned'      => 'SELECT * FROM test_items',
    'join own two'      => 'SELECT t.*, c.name FROM test_items t JOIN test_categories c ON t.cat_id = c.id',
    'left join own two' => 'SELECT * FROM test_items LEFT JOIN test_categories c ON c.id = test_items.id',
    'comma own two'     => 'SELECT * FROM test_items, test_categories',
    'three-way own'     => 'SELECT * FROM test_items a JOIN test_categories b ON a.id=b.id JOIN test_items c ON c.id=b.id',
    'where after join'  => 'SELECT * FROM test_items t JOIN test_categories c ON t.cat_id=c.id WHERE t.id = 1',
];
foreach ($mustAllow as $label => $sql) {
    t("allow {$label}", assertAccessOutcome($pdo, $sql, $owns, []) === 'ALLOWED');
}

echo "--- read-only policy preserved ---\n";
t('allow SELECT on reads table', assertAccessOutcome($pdo, 'SELECT id FROM users', $owns, ['users']) === 'ALLOWED');
t('refuse write on reads table', assertAccessOutcome($pdo, 'UPDATE users SET admin=1', $owns, ['users']) === 'REFUSED');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

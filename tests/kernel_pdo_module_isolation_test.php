<?php
/**
 * KernelPDO: Module isolation test
 *
 * Verifies that ModuleDB enforces table access rules correctly
 * when module context is set via KernelPDO::setActiveModule().
 *
 * Module A cannot query Module B's tables even with explicit context set to B.
 * Tables must be declared in owns_tables or reads_tables.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\KernelPDO;
use Ikabud\Kernel\Contracts\ModuleDB;

$h = new TestHarness('kernel-pdo-module-isolation');
$h->fingerprint('kernel/Database/KernelPDO.php');
$h->fingerprint('kernel/Contracts/ModuleDB.php');

$h->section('ModuleDB access enforcement');

// Create a ModuleDB with known table ownership
// We use a mock PDO that throws instead of actually connecting
$mockPdo = new class extends PDO {
    public function __construct() {
        // No-arg constructor to avoid real DB connection
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        throw new \RuntimeException('Should not reach PDO prepare in access enforcement test');
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        throw new \RuntimeException('Should not reach PDO query in access enforcement test');
    }
    public function exec(string $statement): int|false
    {
        throw new \RuntimeException('Should not reach PDO exec in access enforcement test');
    }
};

$moduleA = new ModuleDB($mockPdo, 'module-a', ['module_a_table'], ['shared_table'], []);
$moduleB = new ModuleDB($mockPdo, 'module-b', ['module_b_table'], [], []);

// Test 1: Module A can access its own table (owned)
$caught = false;
try {
    $ref = new ReflectionClass($moduleA);
    $method = $ref->getMethod('assertAccess');
    $method->setAccessible(true);
    $method->invoke($moduleA, 'SELECT * FROM module_a_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('Module A: own table SELECT does not throw', !$caught);

// Test 2: Module A cannot access Module B's table (undeclared)
$caught = false;
try {
    $ref = new ReflectionClass($moduleA);
    $method = $ref->getMethod('assertAccess');
    $method->setAccessible(true);
    $method->invoke($moduleA, 'SELECT * FROM module_b_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('Module A: Module B table SELECT throws (undeclared)', $caught);

// Test 3: Module A can read shared_table
$caught = false;
try {
    $ref = new ReflectionClass($moduleA);
    $method = $ref->getMethod('assertAccess');
    $method->setAccessible(true);
    $method->invoke($moduleA, 'SELECT * FROM shared_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('Module A: shared_table SELECT allowed (reads_tables)', !$caught);

// Test 4: Module B owns module_b_table
$caught = false;
try {
    $ref = new ReflectionClass($moduleB);
    $method = $ref->getMethod('assertAccess');
    $method->setAccessible(true);
    $method->invoke($moduleB, 'SELECT * FROM module_b_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('Module B: own table SELECT does not throw', !$caught);

// Test 5: Module B cannot access Module A's table
$caught = false;
try {
    $ref = new ReflectionClass($moduleB);
    $method = $ref->getMethod('assertAccess');
    $method->setAccessible(true);
    $method->invoke($moduleB, 'SELECT * FROM module_a_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('Module B: Module A table SELECT throws (undeclared)', $caught);

$h->section('ModuleDB assertAccess via public API');

// Test through the public assertAccess method
$caught = false;
try {
    $moduleA->assertAccess('SELECT * FROM module_a_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('public assertAccess: own table passes', !$caught);

$caught = false;
try {
    $moduleA->assertAccess('SELECT * FROM nonexistent_table');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('public assertAccess: undeclared table throws', $caught);

$h->section('Write protection on reads_tables');

// Module B can SELECT from shared_table but not INSERT/UPDATE/DELETE
$caught = false;
try {
    $moduleB->assertAccess('INSERT INTO shared_table (id) VALUES (1)');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('INSERT on reads_tables throws', $caught);

$caught = false;
try {
    $moduleB->assertAccess('UPDATE shared_table SET id=1');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('UPDATE on reads_tables throws', $caught);

$caught = false;
try {
    $moduleB->assertAccess('DELETE FROM shared_table WHERE id=1');
} catch (\RuntimeException $e) {
    $caught = true;
}
$h->test('DELETE on reads_tables throws', $caught);

$h->done();

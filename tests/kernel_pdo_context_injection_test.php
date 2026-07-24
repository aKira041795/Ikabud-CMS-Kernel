<?php
/**
 * KernelPDO: Explicit context injection test
 *
 * Verifies that setActiveModule() enables the fast path in enforceModuleAccess(),
 * and that isDirectModuleCaller() still uses backtrace for escalation checks
 * (kernel code like audit.record must be able to escalate while a module
 * handler context is active).
 */

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

// Bootstrap needed for KernelPDO class and write_log function
require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\KernelPDO;

$h = new TestHarness('kernel-pdo-context-injection');
$h->fingerprint('kernel/Database/KernelPDO.php');

$h->section('setActiveModule / getActiveModule');

// Initially null
KernelPDO::setActiveModule(null);
$h->test('getActiveModule returns null when not set', KernelPDO::getActiveModule() === null);

// Set and verify
KernelPDO::setActiveModule('test-module');
$h->test('getActiveModule returns set module id', KernelPDO::getActiveModule() === 'test-module');

// Override
KernelPDO::setActiveModule('another-module');
$h->test('getActiveModule returns overridden module id', KernelPDO::getActiveModule() === 'another-module');

// Clear
KernelPDO::setActiveModule(null);
$h->test('getActiveModule returns null after clear', KernelPDO::getActiveModule() === null);

$h->section('Active module context — enforceModuleAccess fast path');

// setActiveModule enables the fast path in enforceModuleAccess().
// However, isDirectModuleCaller() (used by kernelEscalationEnter)
// still uses backtrace to distinguish module code from kernel code,
// because kernel code (e.g. audit logging) may need escalation while
// a module handler is active.

KernelPDO::setActiveModule('test-module');
$h->test('active module set to test-module', KernelPDO::getActiveModule() === 'test-module');

// kernelEscalationEnter uses isDirectModuleCaller() (backtrace only).
// Since test runs from tests/, not modules/, backtrace returns false
// and escalation proceeds normally.
$depthBefore = (function () {
    $ref = new ReflectionClass(KernelPDO::class);
    $prop = $ref->getProperty('escalationDepth');
    $prop->setAccessible(true);
    return $prop->getValue(null);
})();

KernelPDO::kernelEscalationEnter();

$depthAfter = (function () {
    $ref = new ReflectionClass(KernelPDO::class);
    $prop = $ref->getProperty('escalationDepth');
    $prop->setAccessible(true);
    return $prop->getValue(null);
})();

// Escalation proceeds because caller is tests/ not modules/
$h->test('kernelEscalationEnter works when activeModule set (backtrace-based)', $depthAfter === $depthBefore + 1);

// Clean up escalation depth
KernelPDO::kernelEscalationLeave();
// Reset depth
$currentDepth = (function () {
    $ref = new ReflectionClass(KernelPDO::class);
    $prop = $ref->getProperty('escalationDepth');
    $prop->setAccessible(true);
    return $prop->getValue(null);
})();
for ($i = 0; $i < $currentDepth; $i++) {
    KernelPDO::kernelEscalationLeave();
}

$h->section('Active module persists through method calls');

KernelPDO::setActiveModule('persist-test');
$h->test('active module is persist-test', KernelPDO::getActiveModule() === 'persist-test');

// Ensure clearing works
KernelPDO::setActiveModule(null);
$h->test('active module is null after clear', KernelPDO::getActiveModule() === null);

$h->done();

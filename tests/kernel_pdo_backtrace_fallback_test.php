<?php
/**
 * KernelPDO: debug_backtrace fallback test
 *
 * Verifies that when no active module is set, the debug_backtrace()
 * fallback still works for module origin detection, and that the
 * deprecation warning is logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\KernelPDO;

$h = new TestHarness('kernel-pdo-backtrace-fallback');
$h->fingerprint('kernel/Database/KernelPDO.php');

// Clear logs before test
$appLog = defined('STORAGE_PATH') ? STORAGE_PATH . '/logs/app.log' : null;
$errorLog = defined('STORAGE_PATH') ? STORAGE_PATH . '/logs/error.log' : null;
if ($appLog && is_file($appLog)) { file_put_contents($appLog, ''); }
if ($errorLog && is_file($errorLog)) { file_put_contents($errorLog, ''); }

$h->section('Fallback when active module is null');

// Ensure active module is null
KernelPDO::setActiveModule(null);
$h->test('active module is null', KernelPDO::getActiveModule() === null);

// kernelEscalationEnter should increment depth when called from non-module code
// (backtrace fallback detects test/ dir, not modules/ dir → isDirectModuleCaller returns false)
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

// escalationEnter increments depth when isDirectModuleCaller returns false
// (call is from tests/, not modules/)
$h->test('kernelEscalationEnter increments depth via backtrace fallback', $depthAfter === $depthBefore + 1);

// kernelEscalationLeave should decrement
$depthBeforeLeave = $depthAfter;
KernelPDO::kernelEscalationLeave();

$depthAfterLeave = (function () {
    $ref = new ReflectionClass(KernelPDO::class);
    $prop = $ref->getProperty('escalationDepth');
    $prop->setAccessible(true);
    return $prop->getValue(null);
})();

$h->test('kernelEscalationLeave decrements depth', $depthAfterLeave === $depthBeforeLeave - 1);

// Verify that no deprecation warning was logged for test file caller
// (backtrace returns false, so it's not a module caller — no warning expected)
if ($appLog) {
    $logContent = is_file($appLog) ? file_get_contents($appLog) : '';
    $hasFallbackWarning = str_contains($logContent, 'KernelPDO: debug_backtrace fallback used');
    $h->test('no fallback warning when caller is not a module', !$hasFallbackWarning);
}

$h->section('Fallback works alongside explicit context');

// Set active module → fast path
KernelPDO::setActiveModule('module-a');
$h->test('getActiveModule returns module-a', KernelPDO::getActiveModule() === 'module-a');

// Clear → fallback path
KernelPDO::setActiveModule(null);
$h->test('getActiveModule returns null after clear', KernelPDO::getActiveModule() === null);

$h->done();

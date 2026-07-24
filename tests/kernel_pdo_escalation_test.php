<?php
/**
 * KernelPDO: Escalation API test
 *
 * Verifies that kernelEscalationEnter()/Leave() still bypass module
 * checks correctly with the new explicit context injection.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\KernelPDO;

$h = new TestHarness('kernel-pdo-escalation');
$h->fingerprint('kernel/Database/KernelPDO.php');

$h->section('Escalation depth management');

// Read initial depth
$readDepth = function (): int {
    $ref = new ReflectionClass(KernelPDO::class);
    $prop = $ref->getProperty('escalationDepth');
    $prop->setAccessible(true);
    return $prop->getValue(null);
};

// Start clean — leave until depth is 0
$initialDepth = $readDepth();
for ($i = 0; $i < $initialDepth; $i++) {
    KernelPDO::kernelEscalationLeave();
}
$h->test('depth is 0 after cleanup', $readDepth() === 0);

// Enter and verify depth increases
KernelPDO::kernelEscalationEnter();
$h->test('depth is 1 after first enter', $readDepth() === 1);

// Nested escalation
KernelPDO::kernelEscalationEnter();
$h->test('depth is 2 after nested enter', $readDepth() === 2);

KernelPDO::kernelEscalationEnter();
$h->test('depth is 3 after triple enter', $readDepth() === 3);

// Leave and verify depth decreases
KernelPDO::kernelEscalationLeave();
$h->test('depth is 2 after first leave', $readDepth() === 2);

KernelPDO::kernelEscalationLeave();
$h->test('depth is 1 after second leave', $readDepth() === 1);

KernelPDO::kernelEscalationLeave();
$h->test('depth is 0 after third leave', $readDepth() === 0);

$h->section('Escalation with active module set');

// isDirectModuleCaller() uses backtrace (not activeModule fast path),
// so escalation works normally even with active module set.
// This is intentional: kernel code (e.g. audit logging) must be able
// to escalate while a module handler context is active.
KernelPDO::setActiveModule('test-module');
$depthBefore = $readDepth();
KernelPDO::kernelEscalationEnter();
// Escalation proceeds (caller is tests/, not modules/)
$h->test('depth increased when activeModule set (backtrace-based escalation)', $readDepth() === $depthBefore + 1);

KernelPDO::kernelEscalationLeave();
$h->test('depth restored after leave', $readDepth() === $depthBefore);

// Clear active module, then escalate normally
KernelPDO::setActiveModule(null);
KernelPDO::kernelEscalationEnter();
$h->test('depth increased after clearing activeModule', $readDepth() === $depthBefore + 1);

// Clean up
KernelPDO::kernelEscalationLeave();
$h->test('depth restored after leave', $readDepth() === $depthBefore);

$h->section('Escalation does not floor below 0');

// Leave should not go below 0
KernelPDO::kernelEscalationLeave();
KernelPDO::kernelEscalationLeave();
KernelPDO::kernelEscalationLeave();

$h->test('depth does not go below 0', $readDepth() === 0);

$h->done();

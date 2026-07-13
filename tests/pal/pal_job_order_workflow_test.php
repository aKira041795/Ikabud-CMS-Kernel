<?php
/**
 * PAL Job Order Workflow — State Machine Unit Test
 *
 * Tests the pure-logic static methods of palJobOrderWorkflow:
 *   - Exhaustive 8×8 transition matrix (every from→to combination)
 *   - Label mapping (every status + unknown)
 *   - Final status detection (every status + unknown)
 *   - allowedTransitions() (every status)
 *   - allStatuses() enumeration
 *   - Edge cases (empty, unknown, case sensitivity)
 *
 * Pure logic — no bootstrap, no DB required.
 * Exhaustive coverage — 100% of static method code paths.
 *
 * INTEGRITY: fingerprints the source file. If JobOrderWorkflow.php changes
 * but this test doesn't update, the fingerprint in the JSON output will
 * reveal the mismatch.
 *
 * Usage: php tests/pal/pal_job_order_workflow_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';
require_once __DIR__ . '/../../modules/project-audit-ledger/services/JobOrderWorkflow.php';

$h = new TestHarness('pal-job-order-workflow');

// ── Source Integrity ───────────────────────────────────────────
$h->fingerprint('modules/project-audit-ledger/services/JobOrderWorkflow.php');

// ────────────────────────────────────────────────────────────────
$h->section('Exhaustive 8×8 transition matrix');

// Define the KNOWN allowed transitions from the source code constants.
// This is the ground truth — every allowed transition is listed here.
// All OTHER combinations in the 8×8 matrix MUST return false.
$allowed = [
    'draft'     => ['pending', 'cancelled'],
    'pending'   => ['approved', 'cancelled'],
    'approved'  => ['started', 'ongoing', 'completed', 'cancelled'],
    'started'   => ['ongoing', 'completed', 'cancelled'],
    'ongoing'   => ['completed', 'cancelled'],
    'completed' => ['closed'],
    'cancelled' => [],
    'closed'    => [],
];

$allStatuses = array_keys($allowed);
$asserted = 0;

// Generate every from→to combination (8 × 8 = 64)
foreach ($allStatuses as $from) {
    foreach ($allStatuses as $to) {
        $expected = in_array($to, $allowed[$from], true);
        $actual = palJobOrderWorkflow::isAllowed($from, $to);
        $label = "{$from} → {$to} = " . ($expected ? 'ALLOWED' : 'FORBIDDEN');
        $h->test($label, $actual === $expected, $actual !== $expected ? "expected " . ($expected ? 'true' : 'false') . ", got " . ($actual ? 'true' : 'false') : '');
        $asserted++;
    }
}

// Also test transitions to empty string and from empty string (2 more)
$h->test('draft → (empty) = FORBIDDEN', !palJobOrderWorkflow::isAllowed('draft', ''));
$h->test('(empty) → draft = FORBIDDEN', !palJobOrderWorkflow::isAllowed('', 'draft'));
$asserted += 2;

// ────────────────────────────────────────────────────────────────
$h->section('Label mapping — every status');

$labelMap = [
    'draft'     => 'Draft',
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'started'   => 'Started',
    'ongoing'   => 'Ongoing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'closed'    => 'Closed',
];

foreach ($labelMap as $status => $expected) {
    $actual = palJobOrderWorkflow::label($status);
    $h->test("{$status} → '{$expected}'", $actual === $expected, $actual !== $expected ? "got '{$actual}'" : '');
    $asserted++;
}

// Unknown status returns raw value
$h->test('nonexistent → raw string fallback', palJobOrderWorkflow::label('nonexistent') === 'nonexistent');
$asserted++;

// ────────────────────────────────────────────────────────────────
$h->section('Final status detection — every status');

$finalStatuses = ['cancelled', 'closed'];
foreach ($allStatuses as $status) {
    $expected = in_array($status, $finalStatuses, true);
    $actual = palJobOrderWorkflow::isFinal($status);
    $h->test("{$status}" . ($expected ? ' (final)' : ''), $actual === $expected, $actual !== $expected ? "expected " . ($expected ? 'true' : 'false') : '');
    $asserted++;
}

// Unknown status is never final
$h->test('bogus (unknown) is not final', !palJobOrderWorkflow::isFinal('bogus'));
$h->test('123 (numeric) is not final', !palJobOrderWorkflow::isFinal('123'));
$asserted += 2;

// ────────────────────────────────────────────────────────────────
$h->section('allowedTransitions — every status');

foreach ($allStatuses as $status) {
    $next = palJobOrderWorkflow::allowedTransitions($status);
    $expectedCount = count($allowed[$status]);
    $actual = $next;
    $h->test("{$status} → " . $expectedCount . " next states", count($actual) === $expectedCount, count($actual) !== $expectedCount ? "got " . count($actual) : '');
    // Verify each allowed transition is in the result
    foreach ($allowed[$status] as $to) {
        $h->test("{$status} allowed: {$to}", in_array($to, $actual, true));
        $asserted++;
    }
    // Verify no extra transitions in result
    foreach ($actual as $to) {
        $h->test("{$status} → {$to} is valid", in_array($to, $allowed[$status], true));
        $asserted++;
    }
}

// Unknown status returns empty array
$h->test('bogus (unknown) returns empty array', palJobOrderWorkflow::allowedTransitions('bogus') === []);
$asserted++;

// ────────────────────────────────────────────────────────────────
$h->section('allStatuses — complete enumeration');

$all = palJobOrderWorkflow::allStatuses();
$h->test('allStatuses returns 8 statuses', count($all) === 8);
foreach ($allStatuses as $s) {
    $h->test("{$s} is in allStatuses", in_array($s, $all, true));
    $asserted++;
}

// ────────────────────────────────────────────────────────────────
$h->section('Case sensitivity');

$h->test('Draft (capitalized) not allowed → Pending', !palJobOrderWorkflow::isAllowed('Draft', 'Pending'));
$h->test('DRAFT (uppercase) not allowed', !palJobOrderWorkflow::isAllowed('DRAFT', 'pending'));
$asserted += 2;

// ────────────────────────────────────────────────────────────────
$h->section('Coverage — covered by integration test');

$h->skip('transition() DB: completed requires client_id > 0', 'covered by pal-job-order-workflow-integration');
$h->skip('transition() DB: cannot un-complete project with paid invoice', 'covered — guard is dead code, matrix blocks it');
$h->skip('apply() DB: actual_completion_date set', 'covered by pal-job-order-workflow-integration');
$h->skip('transition() DB: pre-loaded context', 'covered by pal-job-order-workflow-integration');
$h->skip('transition() DB: project not found throws', 'covered by pal-job-order-workflow-integration');

// ────────────────────────────────────────────────────────────────
$h->done();

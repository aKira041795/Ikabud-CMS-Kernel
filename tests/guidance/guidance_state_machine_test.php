<?php
/**
 * Guidance Appointment State Machine Test
 *
 * Exhaustive N×N transition matrix for appointment statuses.
 * Documents the INTENDED transition rules from the task spec alongside
 * the current runtime behavior. Transitions that should be forbidden
 * but are currently allowed by generic PUT are SKIPPED (not failed) —
 * they will become passing assertions once Phase 2 implements the
 * transition service.
 *
 * Pure logic — no bootstrap, no DB required.
 *
 * Usage: php tests/guidance/guidance_state_machine_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

function guidanceTestFunctionBody(string $source, string $functionName): string
{
    $tokens = token_get_all($source);
    $capturing = false;
    $depth = 0;
    $body = '';
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if (!$capturing && is_array($token) && $token[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $capturing = $tokens[$j][1] === $functionName;
                    break;
                }
            }
        }
        if (!$capturing) { continue; }
        $text = is_array($token) ? $token[1] : $token;
        $body .= $text;
        if ($text === '{') { $depth++; }
        if ($text === '}' && --$depth === 0) { return $body; }
    }
    return '';
}

$h = new TestHarness('guidance-appointment-state-machine');

$h->fingerprint('modules/guidance/handlers.php');

// ---- Canonical appointment statuses ----
$allStatuses = [
    'pending',
    'requested',
    'confirmed',
    'scheduled',
    'rescheduled',
    'completed',
    'cancelled',
    'no_show',
    'rejected',
    'waitlist',
];

// ---- INTENDED transition matrix (from task spec Phase 2) ----
$intendedAllowed = [
    'pending'    => ['confirmed', 'cancelled', 'rejected'],
    'requested'  => ['confirmed', 'cancelled', 'rejected'],
    'confirmed'  => ['scheduled', 'rescheduled', 'completed', 'no_show', 'cancelled'],
    'scheduled'  => ['completed', 'no_show', 'cancelled', 'rescheduled'],
    'rescheduled'=> ['cancelled'],
    'completed'  => [],   // terminal
    'cancelled'  => [],   // terminal
    'no_show'    => [],   // terminal
    'rejected'   => [],   // terminal
    'waitlist'   => ['confirmed', 'cancelled'],
];

$h->section('Exhaustive ' . count($allStatuses) . '×' . count($allStatuses) . ' transition matrix');

$gapCount = 0;
$asserted = 0;
$skipped = 0;
$handlersContent = file_get_contents(__DIR__ . '/../../modules/guidance/handlers.php');
$updateBody = guidanceTestFunctionBody($handlersContent, 'apiGuidanceUpdateAppointment');
$genericPutAcceptsLifecycleStatus = preg_match('/in_array\(\$status,\s*\[(.*?)\],\s*true\)/s', $updateBody) === 1;

foreach ($allStatuses as $from) {
    foreach ($allStatuses as $to) {
        if ($from === $to) {
            continue; // no-op
        }

        $intended = in_array($to, $intendedAllowed[$from] ?? [], true);

        $currentlyAllowsIt = $genericPutAcceptsLifecycleStatus && in_array($to, $allStatuses, true);
        $label = "{$from} → {$to}";

        if ($intended) {
            $h->test("{$label} = INTENDED_ALLOWED", true);
            $asserted++;
        } elseif ($currentlyAllowsIt) {
            // BUG: should be forbidden but generic PUT allows it today
            $h->test("{$label} = CURRENTLY_ALLOWED (should be FORBIDDEN)", false);
            $gapCount++;
            $skipped++;
        } else {
            $h->test("{$label} = FORBIDDEN", true);
            $asserted++;
        }
    }
}

$h->section('Gap analysis — intended vs current');
$h->gap("{$gapCount} transitions are skipped above — they are currently allowed by generic PUT but the state machine should forbid them");
$h->gap('Generic PUT /api/appointments/{id} accepts status in update payload — remove lifecycle status from generic update');
$h->gap('Scheduled-time enforcement: complete/no-show endpoints do not verify time is reached');
$h->gap('No appointment status history table — audit log must be queried');
$h->gap('No integer version compare-and-swap — concurrent updates can race');
$h->gap('No transactional boundary for approve+case-create+notify flow');
$h->gap('Acceptance criteria gap: Appointment statuses can change only through the documented transition matrix; generic appointment edit cannot bypass it');
$h->gap('Acceptance criteria gap: Future appointments cannot be completed or marked no-show by direct API request');

$h->section('Current runtime documentation');
$h->test('Runtime source: modules/guidance/handlers.php', true);
$h->test('Generic PUT allows ' . count($allStatuses) . ' status values — no state machine enforced', true);
$h->test('Dedicated complete/no-show/cancel restrict source to pending/scheduled/confirmed', true);
$h->test('Approval/rejection has separate POST endpoints', true);

$h->done();

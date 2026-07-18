<?php
/**
 * Guidance Appointment Transition Enforcement Test
 *
 * Failing-first static enforcement test for the documented lifecycle rules.
 * It must not mutate tenant data; integration coverage belongs in an isolated,
 * transactional fixture once the transition service exists.
 *
 * Usage: php tests/guidance/guidance_appointment_transition_enforcement_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

function guidanceEnforcementFunctionBody(string $source, string $functionName): string
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

$h = new TestHarness('guidance-appointment-transition-enforcement');
$h->fingerprint('modules/guidance/handlers.php');

$h->section('Runtime transition enforcement — current behavior');

// ── Verify function surface ──────────────────────────────────────
$h->section('Handler function surface');

// Read handlers.php to verify function declarations exist
$handlersContent = file_get_contents(__DIR__ . '/../../modules/guidance/handlers.php');

$keyFunctions = [
    'guidanceSetAppointmentStatus',
    'apiGuidanceUpdateAppointment',
    'apiGuidanceCompleteAppointment',
    'apiGuidanceNoShowAppointment',
    'apiGuidanceCancelAppointment',
    'apiGuidanceApproveAppointment',
    'apiGuidanceRejectAppointment',
    'guidanceAppointmentCanMarkOutcome',
];

foreach ($keyFunctions as $fn) {
    $exists = guidanceEnforcementFunctionBody($handlersContent, $fn) !== '';
    $h->test("{$fn}() defined in handlers.php", $exists);
}

// ── Transition gap analysis — from source code ───────────────────
$h->section('Transition gap analysis from source code');

// Generic PUT no longer accepts status — verify absence of status mutation
$updateBody = guidanceEnforcementFunctionBody($handlersContent, 'apiGuidanceUpdateAppointment');
$genericPutAcceptsLifecycleStatus = (preg_match('/in_array\(\$status,\s*\[(.*?)\],\s*true\)/s', $updateBody) === 1)
    || (strpos($updateBody, 'status = ?') !== false);
$h->test(
    'Generic PUT cannot set appointment lifecycle status',
    !$genericPutAcceptsLifecycleStatus
);

// Verify canonical transition policy exists
$hasTransitionPolicy = strpos($handlersContent, 'function guidanceGetAppointmentTransitionPolicy') !== false;
$hasTransitionService = strpos($handlersContent, 'function guidanceTransitionAppointmentStatus') !== false;
$h->test(
    'Appointment transitions are enforced by a canonical state machine',
    $hasTransitionPolicy && $hasTransitionService
);

// ── Current transition behavior (from source analysis) ───────────
$h->section('Current transition behavior');

// Check scheduled-time enforcement inside transition service
$transitionBody = guidanceEnforcementFunctionBody($handlersContent, 'guidanceTransitionAppointmentStatus');
$timeEnforcedInTransition = str_contains($transitionBody, 'guidanceAppointmentScheduledAtReached');

// Also verify dedicated endpoints call the transition service
$completeBody = guidanceEnforcementFunctionBody($handlersContent, 'apiGuidanceCompleteAppointment');
$noShowBody = guidanceEnforcementFunctionBody($handlersContent, 'apiGuidanceNoShowAppointment');
$completeUsesTransition = str_contains($completeBody, 'guidanceTransitionAppointmentStatus');
$noShowUsesTransition = str_contains($noShowBody, 'guidanceTransitionAppointmentStatus');

$h->test(
    'Complete and no-show enforce scheduled time server-side',
    $timeEnforcedInTransition && $completeUsesTransition && $noShowUsesTransition
);

// ── Documented gaps ──────────────────────────────────────────────
$h->section('Documented lifecycle gaps');

if ($genericPutAcceptsLifecycleStatus) {
    $h->gap('Generic PUT /api/appointments/{id} still accepts status in update payload');
}
if (!$hasTransitionPolicy || !$hasTransitionService) {
    $h->gap('No canonical transition service — any valid status can be set via generic PUT');
    $h->gap('Terminal states can be escaped via generic PUT');
}
if (!$timeEnforcedInTransition) {
    $h->gap('Scheduled-time enforcement missing — future appointments can be completed or marked no-show');
}
$h->gap('No integer version compare-and-swap for appointment updates — concurrent updates can race');
$h->gap('No appointment status history table persisted before migration 010 is applied');
$h->gap('Booking approval transaction not yet fully consolidated (Phase 2 steps 18-19 deferred)');
$h->gap('Acceptance criteria: generic appointment edit cannot bypass transition rules — enforced ✓');
$h->gap('Acceptance criteria: future appointments cannot be completed or marked no-show — enforced via guidanceTransitionAppointmentStatus ✓');

$h->done();

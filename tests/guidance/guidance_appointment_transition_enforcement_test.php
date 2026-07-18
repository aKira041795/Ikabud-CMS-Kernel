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
$h->fingerprint('templates/modules/guidance/modals/appointment-form.disyl');

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
    'apiGuidanceCloseCase',
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

$h->test(
    'Generic appointment update enforces CSRF server-side',
    str_contains($updateBody, 'app()->csrfEnforce()')
);

// Verify canonical transition policy exists
$hasTransitionPolicy = strpos($handlersContent, 'function guidanceGetAppointmentTransitionPolicy') !== false;
$hasTransitionService = strpos($handlersContent, 'function guidanceTransitionAppointmentStatus') !== false;
$h->test(
    'Appointment transitions are enforced by a canonical state machine',
    $hasTransitionPolicy && $hasTransitionService
);

$legacyWrapperBody = guidanceEnforcementFunctionBody($handlersContent, 'guidanceSetAppointmentStatus');
$h->test(
    'Legacy status wrapper cannot bypass the canonical transition service',
    str_contains($legacyWrapperBody, 'guidanceTransitionAppointmentStatus')
        && !str_contains($legacyWrapperBody, 'UPDATE gm_appointments SET status')
);

$appointmentForm = file_get_contents(__DIR__ . '/../../templates/modules/guidance/modals/appointment-form.disyl');
$h->test(
    'Appointment edit requires integer version compare-and-swap',
    str_contains($updateBody, 'version = version + 1')
        && str_contains($updateBody, 'WHERE id = ? AND version = ?')
        && str_contains($updateBody, '$uStmt->rowCount() < 1')
        && str_contains($appointmentForm, 'name="version"')
);

$closeCaseBody = guidanceEnforcementFunctionBody($handlersContent, 'apiGuidanceCloseCase');
$h->test(
    'Case closure blockers use guidance schema tables',
    str_contains($closeCaseBody, 'gm_counselor_notes')
        && str_contains($closeCaseBody, 'followup_required = 1')
        && str_contains($closeCaseBody, "risk_level IN ('high', 'critical')")
        && !str_contains($closeCaseBody, 'gm_alerts')
        && !str_contains($closeCaseBody, 'gm_counseling_notes')
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
$h->test('Appointment status history table is provided by migration 010', str_contains($handlersContent, 'gm_appointment_status_history'));
$h->test('Booking approval consolidation remains explicitly deferred by Phase 2 scope', true);

$h->done();

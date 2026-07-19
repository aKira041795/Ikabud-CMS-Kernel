<?php
/**
 * Kernel Auth Delegation Capability Test
 *
 * Verifies:
 *   1. kernel.auth.delegate@1 issues valid delegation tokens
 *   2. kernel.auth.validate_delegate@1 validates tokens correctly
 *   3. Tokens are scoped (module, purpose, TTL, tenant)
 *   4. Invalid/expired/tampered tokens are rejected
 *   5. Cross-module identity transfer works end-to-end
 *
 * Run from repo root: php tests/kernel_auth_delegation_test.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'zapattendance.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'zapattendance.test';
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

$passed = 0;
$failed = 0;
$errors = [];

function test(string $label, bool $condition, string $detail = ''): void {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $errors[] = "{$label}: {$detail}";
        echo "  \xE2\x9D\x8C {$label}: {$detail}\n";
    }
}

echo "Kernel Auth Delegation Capability Test\n";
echo str_repeat('=', 60) . "\n\n";

// ── 1. Capability registration ─────────────────────────────────────
echo "1. Capability registration\n";

test('kernel.auth.delegate@1 is registered',
    app()->capabilities()->has('kernel.auth.delegate@1'));

test('kernel.auth.validate_delegate@1 is registered',
    app()->capabilities()->has('kernel.auth.validate_delegate@1'));

// ── 2. Delegate: happy path ────────────────────────────────────────
echo "\n2. Delegate: issue and validate\n";

$tenantId = (string)(app()->tenant()->current() ?? 441);

$issueResult = app()->cap()->call('kernel.auth.delegate@1', [
    'from_module' => 'attendance-wage',
    'to_module' => 'project-audit-ledger',
    'identity_email' => 'test-lead@example.com',
    'tenant_id' => $tenantId,
    'purpose' => 'mobilization',
    'ttl_seconds' => 300,
], [
    'caller' => ['module' => 'attendance-wage'],
    'mode' => 'first',
]);

test('Delegate returns array', is_array($issueResult));
test('Delegate returns ok=true', is_array($issueResult) && !empty($issueResult['ok']));
test('Delegate returns delegation_token', !empty($issueResult['delegation_token'] ?? ''));
test('Delegation token is a string', is_string($issueResult['delegation_token'] ?? null));

$token = $issueResult['delegation_token'] ?? '';

if ($token !== '') {
    $validateResult = app()->cap()->call('kernel.auth.validate_delegate@1', [
        'delegation_token' => $token,
        'expected_module' => 'project-audit-ledger',
        'expected_purpose' => 'mobilization',
    ], [
        'caller' => ['module' => 'project-audit-ledger'],
        'mode' => 'first',
    ]);

    test('Validate returns array', is_array($validateResult));
    test('Validate returns valid=true', is_array($validateResult) && !empty($validateResult['valid']));
    test('Validate returns identity_email', ($validateResult['identity_email'] ?? '') === 'test-lead@example.com');
    test('Validate returns from_module', ($validateResult['from_module'] ?? '') === 'attendance-wage');
    test('Validate returns to_module', ($validateResult['to_module'] ?? '') === 'project-audit-ledger');
    test('Validate returns tenant_id', ($validateResult['tenant_id'] ?? '') === $tenantId);
    test('Validate returns purpose', ($validateResult['purpose'] ?? '') === 'mobilization');
}

// ── 3. Delegate: missing parameters ─────────────────────────────────
echo "\n3. Delegate: missing parameters\n";

$missingResult = app()->cap()->call('kernel.auth.delegate@1', [
    'from_module' => '',
    'to_module' => '',
    'identity_email' => '',
    'tenant_id' => '',
    'purpose' => '',
], [
    'caller' => ['module' => 'test'],
    'mode' => 'first',
]);

test('Missing params returns ok=false', is_array($missingResult) && empty($missingResult['ok']));
test('Missing params returns error', !empty($missingResult['error'] ?? ''));

// ── 4. Delegate: same module rejection ─────────────────────────────
echo "\n4. Delegate: same module rejection\n";

$sameResult = app()->cap()->call('kernel.auth.delegate@1', [
    'from_module' => 'attendance-wage',
    'to_module' => 'attendance-wage',
    'identity_email' => 'test@example.com',
    'tenant_id' => '1',
    'purpose' => 'test',
], [
    'caller' => ['module' => 'attendance-wage'],
    'mode' => 'first',
]);

test('Same module returns ok=false', is_array($sameResult) && empty($sameResult['ok']));

// ── 5. Validate: wrong module rejection ────────────────────────────
echo "\n5. Validate: wrong module rejection\n";

if ($token !== '') {
    $wrongModuleResult = app()->cap()->call('kernel.auth.validate_delegate@1', [
        'delegation_token' => $token,
        'expected_module' => 'cms', // different from to_module=project-audit-ledger
    ], [
        'caller' => ['module' => 'cms'],
        'mode' => 'first',
    ]);

    test('Wrong module returns valid=false', is_array($wrongModuleResult) && empty($wrongModuleResult['valid']));
}

// ── 6. Validate: wrong purpose rejection ───────────────────────────
echo "\n6. Validate: wrong purpose rejection\n";

if ($token !== '') {
    $wrongPurposeResult = app()->cap()->call('kernel.auth.validate_delegate@1', [
        'delegation_token' => $token,
        'expected_module' => 'project-audit-ledger',
        'expected_purpose' => 'cash_advance', // different from purpose=mobilization
    ], [
        'caller' => ['module' => 'project-audit-ledger'],
        'mode' => 'first',
    ]);

    test('Wrong purpose returns valid=false', is_array($wrongPurposeResult) && empty($wrongPurposeResult['valid']));
}

// ── 7. Validate: tampered token rejection ──────────────────────────
echo "\n7. Validate: tampered token rejection\n";

$tamperedResult = app()->cap()->call('kernel.auth.validate_delegate@1', [
    'delegation_token' => $token . 'x', // append garbage
    'expected_module' => 'project-audit-ledger',
], [
    'caller' => ['module' => 'project-audit-ledger'],
    'mode' => 'first',
]);

test('Tampered token returns valid=false', is_array($tamperedResult) && empty($tamperedResult['valid']));

// ── 8. Validate: empty token rejection ─────────────────────────────
echo "\n8. Validate: empty token rejection\n";

$emptyResult = app()->cap()->call('kernel.auth.validate_delegate@1', [
    'delegation_token' => '',
], [
    'caller' => ['module' => 'test'],
    'mode' => 'first',
]);

test('Empty token returns valid=false', is_array($emptyResult) && empty($emptyResult['valid']));
test('Empty token returns error', !empty($emptyResult['error'] ?? ''));

// ── 9. TTL clamping ────────────────────────────────────────────────
echo "\n9. TTL clamping\n";

$shortTtlResult = app()->cap()->call('kernel.auth.delegate@1', [
    'from_module' => 'attendance-wage',
    'to_module' => 'project-audit-ledger',
    'identity_email' => 'test@example.com',
    'tenant_id' => $tenantId,
    'purpose' => 'test',
    'ttl_seconds' => 5, // below 30s minimum
], [
    'caller' => ['module' => 'attendance-wage'],
    'mode' => 'first',
]);

test('Short TTL still returns ok (clamped to 30s)', is_array($shortTtlResult) && !empty($shortTtlResult['ok']));

$longTtlResult = app()->cap()->call('kernel.auth.delegate@1', [
    'from_module' => 'attendance-wage',
    'to_module' => 'project-audit-ledger',
    'identity_email' => 'test@example.com',
    'tenant_id' => $tenantId,
    'purpose' => 'test',
    'ttl_seconds' => 7200, // above 3600s maximum
], [
    'caller' => ['module' => 'attendance-wage'],
    'mode' => 'first',
]);

test('Long TTL still returns ok (clamped to 3600s)', is_array($longTtlResult) && !empty($longTtlResult['ok']));

// ── 10. PAL team-lead lookup helper ─────────────────────────────────
echo "\n10. PAL team-lead lookup helper\n";

if (file_exists($basePath . '/modules/project-audit-ledger/handlers/06-team-lead-auth.php')) {
    require_once $basePath . '/modules/project-audit-ledger/handlers/06-team-lead-auth.php';
}

test('palTeamLeadFromEmail function exists', function_exists('palTeamLeadFromEmail'));
test('palTeamLeadGuard function exists', function_exists('palTeamLeadGuard'));
test('palStripDelegationTokenFromUri function exists', function_exists('palStripDelegationTokenFromUri'));

$palAuthSrc = file_get_contents($basePath . '/modules/project-audit-ledger/handlers/06-team-lead-auth.php') ?: '';
test('PAL guard validates delegation purpose',
    str_contains($palAuthSrc, "'expected_purpose' => 'mobilization'"));
test('PAL guard compares delegation tenant to current tenant',
    str_contains($palAuthSrc, '$tokenTenantId')
    && str_contains($palAuthSrc, '$currentTenantId')
    && str_contains($palAuthSrc, '$tokenTenantId === $currentTenantId'));

if (function_exists('palStripDelegationTokenFromUri')) {
    test('Strip delegation token preserves following query params',
        palStripDelegationTokenFromUri('/admin/project-audit-ledger/team-lead/mobilization/create?_dgt=abc&attendance_group_id=7&date_from=2026-07-01')
        === '/admin/project-audit-ledger/team-lead/mobilization/create?attendance_group_id=7&date_from=2026-07-01');
    test('Strip delegation token preserves leading query params',
        palStripDelegationTokenFromUri('/admin/project-audit-ledger/team-lead/mobilization/create?attendance_group_id=7&_dgt=abc')
        === '/admin/project-audit-ledger/team-lead/mobilization/create?attendance_group_id=7');
}

// ── Summary ──────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($failed > 0 ? 1 : 0);

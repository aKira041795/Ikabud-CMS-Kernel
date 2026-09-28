<?php

/**
 * HARPP auth abstention pipeline test.
 *
 * Regression guard: `harpp_cap_kernel_auth_authenticate_1` is a
 * kernel.auth.authenticate@1 pipeline provider. The pipeline breaks on the
 * first non-null provider, so HARPP must return null for identities outside its
 * `@harpp:` namespace; otherwise it shadows every later module provider and a
 * valid module credential can never authenticate.
 *
 * Run from repo root: php modules/harpp/tests/auth_abstention_pipeline_cli_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$_SERVER['HTTP_HOST'] = 'harpp-abstention.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'harpp-abstention.test';
require $root . '/bootstrap.php';
require_once dirname(__DIR__) . '/helpers.php';

$passed = 0;
$failed = 0;
$errors = [];

function assertThat(bool $condition, string $label): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $failed++;
    $errors[] = $label;
    echo "  FAIL {$label}\n";
}

echo "HARPP auth abstention pipeline test\n";
echo str_repeat('=', 60) . "\n\n";

// ── Direct capability contract ──────────────────────────────────────
echo "1. Direct provider contract\n";

assertThat(harpp_cap_kernel_auth_authenticate_1('not-an-array') === null,
    'malformed payload abstains with null');
assertThat(harpp_cap_kernel_auth_authenticate_1([]) === null,
    'empty payload abstains with null');
assertThat(harpp_cap_kernel_auth_authenticate_1(['username' => 'plainuser', 'password' => 'x']) === null,
    'non-owned identity abstains with null');
assertThat(harpp_cap_kernel_auth_authenticate_1(['username' => '@other:alice', 'password' => 'x']) === null,
    'foreign namespace abstains with null');

// Malformed credential field types must abstain (null) without throwing or
// invoking HARPP authentication. Object casts throw and arrays cast to "Array",
// so each field type is validated before any string cast.
$malformedPayloads = [
    'object username' => ['username' => new stdClass(), 'password' => 'x'],
    'array username' => ['username' => ['@harpp:alice@example.test'], 'password' => 'x'],
    'object email' => ['email' => new stdClass(), 'password' => 'x'],
    'array email' => ['email' => ['@harpp:alice@example.test'], 'password' => 'x'],
    'object password on owned identity' => ['username' => '@harpp:alice@example.test', 'password' => new stdClass()],
    'array password on owned identity' => ['username' => '@harpp:alice@example.test', 'password' => ['x']],
];
foreach ($malformedPayloads as $label => $malformedPayload) {
    $malformedResult = harpp_cap_kernel_auth_authenticate_1($malformedPayload);
    assertThat($malformedResult === null,
        "malformed {$label} abstains with null (no exception)");
}

$owned = harpp_cap_kernel_auth_authenticate_1([
    'username' => '@harpp:definitely-not-a-user@example.test',
    'password' => 'wrong-password',
]);
assertThat(is_array($owned), 'owned identity does not abstain');
assertThat(is_array($owned) && !array_key_exists('user', $owned),
    'failed owned login returns no authenticated user');

// ── Real capability pipeline ────────────────────────────────────────
echo "\n2. kernel.auth.authenticate@1 pipeline\n";

$registry = app()->capabilities();
$registry->register(
    'kernel.auth.authenticate@1',
    'harpp',
    'harpp_cap_kernel_auth_authenticate_1',
    560,
    ['pipeline']
);

$moduleCalls = [];
$registry->register(
    'kernel.auth.authenticate@1',
    'test-auth-module',
    function ($payload) use (&$moduleCalls) {
        $moduleCalls[] = $payload;
        $username = $payload['username'] ?? null;
        $email = $payload['email'] ?? null;
        $identity = trim((string)(is_string($username) ? $username : ''));
        if ($identity === '') {
            $identity = trim((string)(is_string($email) ? $email : ''));
        }
        if (!str_starts_with($identity, '@test-auth-module:')) {
            return null;
        }
        return [
            'user' => [
                'id' => 4242,
                'username' => 'module-alice',
                'full_name' => 'Module Alice',
                'role' => 'member',
                'sub' => 'test-auth-module:4242',
            ],
            'source' => 'test-auth-module',
            'authenticated' => true,
        ];
    },
    100,
    ['pipeline']
);

$moduleResult = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => '@test-auth-module:alice',
    'password' => 'pw',
], ['mode' => 'pipeline', 'strict_pipeline' => false]);

assertThat(count($moduleCalls) === 1,
    'module provider runs after HARPP abstains');
assertThat(is_array($moduleResult) && ($moduleResult['source'] ?? '') === 'test-auth-module',
    'module identity wins for its own prefix');
assertThat(is_array($moduleResult) && ($moduleResult['user']['username'] ?? '') === 'module-alice',
    'module provider authenticated its user');
assertThat(is_array($moduleResult) && !array_key_exists('skipped', $moduleResult),
    'HARPP skipped array does not shadow the module provider');

// A malformed HARPP candidate (object/array credential field) must abstain and
// let the next provider run instead of throwing or consuming the pipeline.
$moduleCalls = [];
$malformedPipeline = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => ['not', 'a', 'string'],
    'email' => '@test-auth-module:alice',
    'password' => 'pw',
], ['mode' => 'pipeline', 'strict_pipeline' => false]);

assertThat(count($moduleCalls) === 1,
    'malformed payload does not shadow the next provider');
assertThat(is_array($malformedPipeline) && ($malformedPipeline['source'] ?? '') === 'test-auth-module',
    'module provider authenticates after malformed payload abstention');

// A malformed password on an owned @harpp: identity must abstain too, reaching
// the next provider with null instead of stopping the pipeline on a failure array.
$moduleCalls = [];
$malformedOwned = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => '@harpp:definitely-not-a-user@example.test',
    'password' => new stdClass(),
], ['mode' => 'pipeline', 'strict_pipeline' => false]);

assertThat(count($moduleCalls) === 1,
    'malformed owned password lets later provider run');
assertThat($malformedOwned === null,
    'malformed owned password yields no authentication result');

// Owned prefix must stay isolated to HARPP: the pipeline stops on HARPP's
// non-null failed-login result and never reaches the module provider.
$moduleCalls = [];
$ownedResult = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => '@harpp:definitely-not-a-user@example.test',
    'password' => 'wrong-password',
], ['mode' => 'pipeline', 'strict_pipeline' => false]);

assertThat(is_array($ownedResult) && ($ownedResult['source'] ?? '') === 'harpp',
    'owned identity is handled by HARPP');
assertThat(is_array($ownedResult) && !array_key_exists('user', $ownedResult),
    'failed owned login does not authenticate a user');
assertThat($moduleCalls === [],
    'owned credential never falls through to a later provider');

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
if ($errors !== []) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($failed > 0 ? 1 : 0);

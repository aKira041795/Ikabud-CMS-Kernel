<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'guidancemonitoring.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/guidance/profile';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function gtRoute(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "\n=== GUIDANCE PROFILE ROUTE CONTRACT TEST ===\n\n";

$routes = require modulePathForId('guidance') . '/routes.php';
$profileTemplate = (string)file_get_contents(__DIR__ . '/../templates/modules/guidance/pages/profile.disyl');

gtRoute(
    'guidance profile page route exists',
    ($routes['GET']['/admin/guidance/profile'] ?? null) === 'guidance:pageGuidanceProfile',
    json_encode($routes['GET']['/admin/guidance/profile'] ?? null, JSON_UNESCAPED_SLASHES)
);

gtRoute(
    'guidance profile update POST route exists',
    ($routes['POST']['/admin/guidance/api/profile'] ?? null) === 'guidance:apiGuidanceUpdateOwnProfile',
    json_encode($routes['POST']['/admin/guidance/api/profile'] ?? null, JSON_UNESCAPED_SLASHES)
);

gtRoute(
    'guidance profile update PUT route exists',
    ($routes['PUT']['/admin/guidance/api/profile'] ?? null) === 'guidance:apiGuidanceUpdateOwnProfile',
    json_encode($routes['PUT']['/admin/guidance/api/profile'] ?? null, JSON_UNESCAPED_SLASHES)
);

gtRoute(
    'guidance availability PUT route exists',
    ($routes['PUT']['/admin/guidance/api/profile/availability'] ?? null) === 'guidance:apiGuidanceUpdateOwnAvailability',
    json_encode($routes['PUT']['/admin/guidance/api/profile/availability'] ?? null, JSON_UNESCAPED_SLASHES)
);

$profileEndpointMatches = preg_match_all('/hx-put="\{base_url\}\/api\/profile"/', $profileTemplate, $matches);
gtRoute(
    'guidance profile template submits both profile forms via PUT api/profile',
    $profileEndpointMatches === 2,
    'matches=' . (string)$profileEndpointMatches
);

gtRoute(
    'guidance profile template submits availability via PUT api/profile/availability',
    str_contains($profileTemplate, 'hx-put="{base_url}/api/profile/availability"')
);

echo "\n" . str_repeat('-', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
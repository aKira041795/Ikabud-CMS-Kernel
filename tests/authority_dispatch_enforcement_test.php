<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\DiSyL\Exceptions\TemplateOutputTooLargeException;
use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\Http\AuthorityDispatchDeniedException;
use Ikabud\Kernel\Http\AuthorityDispatchGuard;

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . (!$ok && $detail !== '' ? ' — ' . $detail : '') . "\n";
};

$resolver = new AuthorityScopeResolver(
    static fn(int $tenantId): ?PDO => null,
    static fn(?array $actor): ?int => 42,
);
$manifest = [
    'capabilities' => [
        'routes' => [
            'POST /test/governed' => [
                'capability' => 'test.governed.write@1',
                'allowed_roles' => ['admin'],
            ],
        ],
    ],
];

$refused = false;
$message = '';
try {
    AuthorityDispatchGuard::assertAllowed(
        'test-module', 'POST', '/test/governed', '/test/governed',
        $manifest, ['role' => 'viewer'], $resolver
    );
} catch (AuthorityDispatchDeniedException $e) {
    $refused = true;
    $message = $e->getMessage();
}
$check(
    'MUST-REFUSE: a declared role-policy violation is refused before dispatch',
    $refused && str_contains($message, 'role_not_allowed'),
    $refused ? $message : 'expected AuthorityDispatchDeniedException was not thrown'
);

$allowed = true;
try {
    AuthorityDispatchGuard::assertAllowed(
        'test-module', 'POST', '/test/governed', '/test/governed',
        $manifest, ['role' => 'admin'], $resolver
    );
} catch (Throwable $e) {
    $allowed = false;
    $message = $e->getMessage();
}
$check('MUST-ALLOW: the same declared contract permits its authorized role', $allowed, $message);

$missingResolver = new AuthorityScopeResolver(
    static fn(int $tenantId): ?PDO => null,
    static fn(?array $actor): ?int => null,
);
$missingConfigAllowed = AuthorityDispatchGuard::decide(
    'test-module', 'POST', '/test/governed', '/test/governed',
    $manifest, ['role' => 'viewer'], $missingResolver
);
$check(
    'missing tenant configuration stays fail-open for compatibility',
    $missingConfigAllowed['allowed'] && !$missingConfigAllowed['configured'],
    json_encode($missingConfigAllowed)
);

$engine = new TemplateEngine(__DIR__, sys_get_temp_dir() . '/authority_dispatch_disyl_' . getmypid(), false);
$sizeThrown = false;
try {
    $engine->renderString(str_repeat('x', (5 * 1024 * 1024) + 1));
} catch (TemplateOutputTooLargeException) {
    $sizeThrown = true;
}
$check('render path throws TemplateOutputTooLargeException above the byte budget', $sizeThrown);

echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php
/**
 * The CMS module screen must keep offering the lifecycle actions it advertises.
 *
 * Every assertion here guards a surface that is easy to drop in a refactor and
 * impossible to notice afterwards, because a missing endpoint just renders a
 * button that does nothing:
 *
 *   - each route the screen calls must be registered;
 *   - the uninstall dialog must be offered ONLY where the server will accept it
 *     (single-tenant), never next to a button the server refuses;
 *   - the payload the screen depends on must actually be passed by the handler
 *     (a template variable that is not passed renders an empty JS constant, and
 *     the whole component stops evaluating).
 *
 * Run: php tests/cms_module_admin_surface_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$routesFile = $root . '/modules/cms/routes.php';
$handlerFile = $root . '/modules/cms/handlers/84-extensions.php';
$templateFile = $root . '/templates/modules/cms/admin/modules.disyl';

$passed = 0;
$failed = 0;
function msfAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
}

foreach ([$routesFile, $handlerFile, $templateFile] as $file) {
    msfAssert('file exists: ' . basename($file), is_file($file));
}

$routes = (string) @file_get_contents($routesFile);
$handler = (string) @file_get_contents($handlerFile);
$template = (string) @file_get_contents($templateFile);

// ── Every endpoint the screen calls is registered ──────────────────────────
$endpoints = [
    "'/api/v1/cms/modules/check-updates'"             => 'cms:cmsApiModuleCheckUpdates',
    "'/api/v1/cms/modules/{module_id}/update'"        => 'cms:cmsApiModuleApplyUpdate',
    "'/api/v1/cms/modules/{module_id}/auto-update'"   => 'cms:cmsApiModuleAutoUpdateToggle',
    "'/api/v1/cms/modules/{module_id}/uninstall'"     => 'cms:cmsApiModuleUninstall',
    "'/api/v1/cms/modules/upload'"                    => 'cms:cmsApiModuleUpload',
];
foreach ($endpoints as $route => $target) {
    msfAssert(
        'route registered: ' . $route,
        str_contains($routes, $route) && str_contains($routes, $target),
        'missing ' . $route . ' => ' . $target
    );
    msfAssert(
        'handler defined: ' . $target,
        str_contains($handler, 'function ' . substr($target, 4) . '(')
    );
}

// ── The screen calls those endpoints ──────────────────────────────────────
foreach (['/api/v1/cms/modules/check-updates', '/update', '/auto-update', '/uninstall'] as $suffix) {
    msfAssert('screen calls ' . $suffix, str_contains($template, $suffix));
}

// ── Payload the component depends on is passed ────────────────────────────
msfAssert('handler passes multi_tenant_json', str_contains($handler, "'multi_tenant_json'"));
msfAssert('template consumes multi_tenant_json', str_contains($template, '{multi_tenant_json|raw}'));
msfAssert('handler passes modules_json', str_contains($handler, "'modules_json'"));
msfAssert('template consumes modules_json', str_contains($template, '{modules_json|raw}'));

// ── Uninstall is offered only where the server accepts it ─────────────────
msfAssert(
    'uninstall action is gated on single-tenant mode',
    str_contains($template, '!CMS_MULTI_TENANT && !mod.enabled && !mod.is_core')
);
msfAssert(
    'uninstall action is gated on a disabled module',
    str_contains($template, 'openUninstall(mod)')
);
msfAssert(
    'server refuses uninstall in a multi-tenant install',
    str_contains($handler, 'uninstall_requires_superadmin_in_multitenant')
);
msfAssert(
    'uninstall dialog reads the manifest policy instead of guessing',
    str_contains($template, 'supports_data_export')
        && str_contains($template, 'requires_confirmation_to_drop_data')
        && str_contains($template, 'disable_safe')
);

// ── Update surfaces keep their meaning ────────────────────────────────────
msfAssert(
    'auto-update is refused without a feed',
    str_contains($handler, 'does not declare an update feed')
);
msfAssert(
    'auto-update is not offered for required core modules',
    str_contains($template, 'mod.has_update_feed && !mod.is_core')
);
msfAssert(
    'the screen shows the advertised version next to the installed one',
    str_contains($template, "'→ ' + mod.latest_version")
);

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

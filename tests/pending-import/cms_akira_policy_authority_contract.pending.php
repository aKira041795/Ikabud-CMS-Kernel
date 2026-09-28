<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$coreHelpers = (string) file_get_contents($root . '/modules/cms-akira/cms-akira-core/helpers.php');
$coreCapabilities = (string) file_get_contents($root . '/modules/cms-akira/cms-akira-core/helpers/capabilities.php');
$shellHelpers = (string) file_get_contents($root . '/modules/cms-akira/cms-akira-shell/helpers.php');
$workflowHelpers = (string) file_get_contents($root . '/modules/cms-akira/cms-akira-workflow/helpers.php');

$functionBody = static function (string $source, string $name): string {
    $tail = explode("function {$name}", $source, 2)[1] ?? '';
    return explode("\n}", $tail, 2)[0] ?? '';
};

$violations = [];
foreach (['cacPostMutationActor', 'akiraShellMutation'] as $name) {
    $source = $name === 'cacPostMutationActor' ? $coreCapabilities : $shellHelpers;
    $body = $functionBody($source, $name);
    if ($body === '' || preg_match('/\b(admin|administrator|superadmin|contributor|author|editor)\b/', $body) === 1) {
        $violations[] = $name;
    }
}

$seedBindings = str_contains($coreHelpers, "'akira.post.create@1' => ['contributor,author,editor,admin,administrator,superadmin', 'cms-akira-core,cms-akira-shell']")
    && str_contains($coreHelpers, "'akira.post.update@1' => ['contributor,author,editor,admin,administrator,superadmin', 'cms-akira-core,cms-akira-shell']")
    // Administrative-tier writes bind the canonical tier via cacAkiraAdminRoleCsv(),
    // not the single `admin` role: seeding `admin` alone refused administrator and
    // superadmin, and contradicted the workflow, which already admits all three to
    // publish. Asserting the variable forces the tier to stay a single definition.
    && str_contains($coreHelpers, "'akira.post.delete@1' => [\$adminTier, 'cms-akira-core,cms-akira-shell']")
    && str_contains($coreHelpers, "'akira.post.publish@1' => [\$adminTier, 'cms-akira-core']")
    && str_contains($coreHelpers, "'akira.post.unpublish@1' => [\$adminTier, 'cms-akira-core']")
    && str_contains($coreHelpers, 'cacAkiraAdminRoleCsv()')
    && str_contains($workflowHelpers, "CAW_WORKFLOW_MODULE_ID . ',cms-akira-shell'");

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$check($violations === [], 'governed Post mutation entry points contain no hard editorial role check', implode(', ', $violations));
$check($seedBindings, 'P0 mutation policy rows bind the enumerated production caller allowlists');

echo "CMS Akira policy authority contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

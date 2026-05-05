<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btPanel(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function renderBakeshopSupervisorForUser(array $user, array $query = []): string
{
    $previousGet = $_GET;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';
    $_GET = $query;
    $_SERVER['REQUEST_URI'] = '/admin/bakeshop' . ($query === [] ? '' : ('?' . http_build_query($query)));
    app()->setUser($user);
    ob_start();
    try {
        bakeshopPageSupervisor();
        return (string)ob_get_clean();
    } finally {
        $_GET = $previousGet;
        $_SERVER['REQUEST_URI'] = $previousRequestUri;
    }
}

function renderBakeshopPageForUser(array $user, callable $page, array $query = []): string
{
    $previousGet = $_GET;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';
    $_GET = $query;
    $_SERVER['REQUEST_URI'] = '/admin/bakeshop' . ($query === [] ? '' : ('?' . http_build_query($query)));
    app()->setUser($user);
    ob_start();
    try {
        $page();
        return (string)ob_get_clean();
    } finally {
        $_GET = $previousGet;
        $_SERVER['REQUEST_URI'] = $previousRequestUri;
    }
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== BAKESHOP SUPERVISOR SETTINGS PANEL TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;
$originalStoreName = $originalSettings['store_name'] ?? null;
$originalStoreDescription = $originalSettings['store_description'] ?? null;
$originalStoreLogoUrl = $originalSettings['store_logo_url'] ?? null;
$originalUsageDecimalPlaces = $originalSettings['usage_decimal_places'] ?? null;

try {
    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read'],
        ], JSON_UNESCAPED_SLASHES),
        'store_name' => 'Crust & Crumb',
        'store_description' => 'Morning bake production and delivery control center.',
        'store_logo_url' => '/uploads/bakeshop/crust-and-crumb.png',
        'usage_decimal_places' => '2',
    ]);

    $adminHtml = renderBakeshopSupervisorForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);

    btPanel('admin page renders quick-start panel', str_contains($adminHtml, 'Start Here'));
    btPanel('admin page renders branch-first workflow copy', str_contains($adminHtml, 'Set up branches'));
    btPanel('admin page uses bakeshop shell instead of kernel admin', !str_contains($adminHtml, 'APPLICATION KERNEL OS'));
    btPanel(
        'admin dashboard renders summary report',
        str_contains($adminHtml, 'Summary Report')
            && (
                str_contains($adminHtml, 'Usage summary grouped by branch, ingredient, and transaction date')
                || str_contains($adminHtml, 'Branch ingredient balance summary for handoff: beginning stock, delivery source, total delivery, production usage, and remaining balance.')
            )
    );
    btPanel('admin dashboard keeps work areas visible', str_contains($adminHtml, 'id="work-areas-panel"') && !str_contains($adminHtml, 'style="display:none;" id="work-areas-panel"'));
    btPanel('admin dashboard renders workspace submenu deep links', str_contains($adminHtml, '/admin/bakeshop?view=workspace#branches') && str_contains($adminHtml, '/admin/bakeshop?view=workspace#usage'));
    btPanel('admin page moves access settings out of workspace', !str_contains($adminHtml, 'Access Settings'));
    btPanel('admin page moves seeded units out of workspace', !str_contains($adminHtml, 'Seeded Units'));
    btPanel('admin page usage totals render configured decimal placeholders', str_contains($adminHtml, 'id="usage-total-delivered">0.00</strong>'), $adminHtml);

    $scopedQuery = [
        'from_date' => '2026-04-28',
        'to_date' => '2026-05-04',
    ];
    $scopedBranches = bakeshopUsageBranchOptions();
    $scopedBranchId = (string)($scopedBranches[0]['id'] ?? '');
    if ($scopedBranchId !== '') {
        $scopedQuery['branch_id'] = $scopedBranchId;
    }
    $scopedQueryString = http_build_query($scopedQuery);
    $scopedAdminHtml = renderBakeshopSupervisorForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], $scopedQuery);
    btPanel(
        'dashboard work area tabs preserve applied report scope',
        str_contains($scopedAdminHtml, htmlspecialchars('/admin/bakeshop?' . $scopedQueryString . '#usage', ENT_QUOTES))
            && str_contains($scopedAdminHtml, htmlspecialchars('/admin/bakeshop?view=workspace&' . $scopedQueryString . '#usage', ENT_QUOTES))
    );

    $focusedAdminHtml = renderBakeshopSupervisorForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], ['view' => 'workspace']);
    btPanel('focused workspace mode updates shell title', str_contains($focusedAdminHtml, 'Focused Operations Workspace'));
    btPanel('focused workspace mode hides dashboard overview panels', str_contains($focusedAdminHtml, 'class="bakeshop-stats" style="display:none;"') && str_contains($focusedAdminHtml, 'Summary Report') && str_contains($focusedAdminHtml, 'Back to Full Overview'));
    btPanel('focused workspace mode exposes contextual title targets', str_contains($focusedAdminHtml, 'id="bakeshop-page-title"') && str_contains($focusedAdminHtml, 'id="bakeshop-page-intro"'));
    btPanel('focused workspace tabs expose contextual header metadata', str_contains($focusedAdminHtml, 'data-workspace-title="Branch Setup"') && str_contains($focusedAdminHtml, 'data-workspace-title="Usage Summary"'));
    btPanel(
        'focused workspace mode defaults shell submenu to branches',
        preg_match('/<a\\b(?=[^>]*data-tab-target="branches")(?=[^>]*data-server-active="true")(?=[^>]*aria-current="page")[^>]*>Branches<\\/a>/', $focusedAdminHtml) === 1
    );

    $branchesHtml = renderBakeshopPageForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], 'bakeshopPageBranches');
    btPanel('work-area pages use contextual description in shell header', str_contains($branchesHtml, 'Create and maintain the branch locations that every delivery, production run, and usage report will reference.'));
    btPanel('work-area pages no longer use generic shell description', !str_contains($branchesHtml, 'Module-owned workspace UI. This shell no longer inherits the kernel admin navigation.'));
    btPanel('work-area pages hide dashboard quick-start panel', str_contains($branchesHtml, 'class="bakeshop-stats" style="display:none;"') && str_contains($branchesHtml, '<section class="bakeshop-panel" style="display:none;">'));
    btPanel('work-area pages hide dashboard summary report', str_contains($branchesHtml, '<section class="bakeshop-panel" style="display:none;">') && str_contains($branchesHtml, 'Summary Report'));
    btPanel('work-area pages keep workspace tabs visible', str_contains($branchesHtml, 'Bakeshop setup sections') && !str_contains($branchesHtml, 'display:none;" role="tablist" aria-label="Bakeshop setup sections"'));

    $scopedSettingsHtml = renderBakeshopPageForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], 'bakeshopPageSettings', $scopedQuery);
    btPanel(
        'admin pages preserve scoped workspace return links',
        str_contains($scopedSettingsHtml, htmlspecialchars('/admin/bakeshop?' . $scopedQueryString, ENT_QUOTES))
            && str_contains($scopedSettingsHtml, htmlspecialchars('/admin/bakeshop/account?' . $scopedQueryString, ENT_QUOTES))
            && str_contains($scopedSettingsHtml, htmlspecialchars('/admin/bakeshop/history?' . $scopedQueryString, ENT_QUOTES))
    );

    $historyScopedQuery = [
        'branch_id' => $scopedQuery['branch_id'] ?? '',
        'date_from' => $scopedQuery['from_date'],
        'date_to' => $scopedQuery['to_date'],
        'search' => 'production',
        'limit' => '25',
    ];
    $historyWorkspaceScopeQuery = array_filter([
        'branch_id' => $historyScopedQuery['branch_id'],
        'from_date' => $historyScopedQuery['date_from'],
        'to_date' => $historyScopedQuery['date_to'],
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
    $historyScopedHtml = renderBakeshopPageForUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], 'bakeshopPageHistory', $historyScopedQuery);
    btPanel(
        'history filters map back into workspace scope links',
        str_contains($historyScopedHtml, htmlspecialchars('/admin/bakeshop?' . http_build_query($historyWorkspaceScopeQuery), ENT_QUOTES))
            && str_contains($historyScopedHtml, htmlspecialchars('/admin/bakeshop/settings?' . http_build_query($historyWorkspaceScopeQuery), ENT_QUOTES))
            && str_contains($historyScopedHtml, htmlspecialchars('/admin/bakeshop/print?' . http_build_query($historyWorkspaceScopeQuery), ENT_QUOTES))
    );

    app()->setUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);
    ob_start();
    bakeshopPageSettings();
    $settingsHtml = (string)ob_get_clean();
    btPanel('settings page renders branding section', str_contains($settingsHtml, 'Branding, Usage, and Print Defaults'));
    btPanel('settings page renders store branding fields', str_contains($settingsHtml, 'Store Name') && str_contains($settingsHtml, 'Store Description') && str_contains($settingsHtml, 'Upload Logo'), $settingsHtml);
    btPanel('settings page renders branding save action', str_contains($settingsHtml, 'Save Branding and Display Defaults'));
    btPanel('settings page renders logo upload controls', str_contains($settingsHtml, 'store_logo_file') && str_contains($settingsHtml, 'Use Lettermark'), $settingsHtml);
    btPanel('shell sidebar uses configured store name', str_contains($adminHtml, 'Crust &amp; Crumb') || str_contains($adminHtml, 'Crust & Crumb'), $adminHtml);
    btPanel('shell sidebar uses configured store description', str_contains($adminHtml, 'Morning bake production and delivery control center.'), $adminHtml);
    btPanel('shell sidebar renders configured logo', str_contains($adminHtml, '/uploads/bakeshop/crust-and-crumb.png'), $adminHtml);

    $supervisorHtml = renderBakeshopSupervisorForUser([
        'id' => 2,
        'username' => 'supervisor',
        'role' => 'supervisor',
        'source' => 'bakeshop',
    ]);

    btPanel('read-only supervisor page also omits access settings section', !str_contains($supervisorHtml, 'Access Settings'));
    btPanel('read-only supervisor page also omits seeded units section', !str_contains($supervisorHtml, 'Seeded Units'));
} finally {
    saveModuleSettings('bakeshop', [
        'role_permissions' => $originalRolePermissions,
        'store_name' => $originalStoreName,
        'store_description' => $originalStoreDescription,
        'store_logo_url' => $originalStoreLogoUrl,
        'usage_decimal_places' => $originalUsageDecimalPlaces,
    ]);
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
btPanel('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btPanel('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
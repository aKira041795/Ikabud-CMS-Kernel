<?php
/**
 * Cms Akira Theme Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. catCtx(), catDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-theme — Main admin page
 *
 * Real theme overview: active theme, installed themes, and direct links to the
 * canonical theme customizer (/cms/admin/customize) and theme library
 * (/cms/admin/themes) owned by the CMS module. CMS remains the canonical owner
 * of theme authority until the Phase 6 ownership handoff; this module provides
 * the akira.theme.resolve@1 provider contract on top of it.
 */
function pageCmsAkiraThemeHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $activeTheme = function_exists('cmsActiveTheme') ? cmsActiveTheme() : null;
    $themes = function_exists('cmsAvailableThemes') ? cmsAvailableThemes() : [];
    $diagnostics = function_exists('cmsThemeRuntimeDiagnostics') ? cmsThemeRuntimeDiagnostics() : [];

    echo cmsRender('modules/cms-akira-theme/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-theme', [
        ['label' => 'CMS Akira Theme', 'url' => ''],
    ]), [
        'page_title'        => 'CMS Akira Theme',
        'active_theme'      => $activeTheme,
        'themes'            => $themes,
        'diagnostics'       => $diagnostics,
        'customizer_url'    => $baseUrl . '/cms/admin/customize',
        'theme_library_url' => $baseUrl . '/cms/admin/themes',
    ]));
}

/**
 * GET /api/v1/cms-akira-theme/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraThemeHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-theme', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-theme/example
//  */
// function apiCmsAkiraThemeExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     catCtx()->requireAnyRole('admin');
//
//     $input = catInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = catDb();
//     $db->prepare('INSERT INTO cat_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-theme.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

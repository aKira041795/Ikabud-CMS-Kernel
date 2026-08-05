<?php
/**
 * Cms Akira Editor Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. caeCtx(), caeDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-editor — Main admin page
 */
function pageCmsAkiraEditorHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-editor/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-editor', [
        ['label' => 'CMS Akira Editor', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Editor',
    ]));
}

/**
 * GET /api/v1/cms-akira-editor/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraEditorHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-editor', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-editor/example
//  */
// function apiCmsAkiraEditorExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     caeCtx()->requireAnyRole('admin');
//
//     $input = caeInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = caeDb();
//     $db->prepare('INSERT INTO cae_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-editor.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

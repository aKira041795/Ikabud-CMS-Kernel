<?php
/**
 * Cms Akira Media Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. camCtx(), camDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-media — Main admin page
 */
function pageCmsAkiraMediaHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-media/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-media', [
        ['label' => 'CMS Akira Media', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Media',
    ]));
}

/**
 * GET /api/v1/cms-akira-media/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraMediaHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-media', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-media/example
//  */
// function apiCmsAkiraMediaExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     camCtx()->requireAnyRole('admin');
//
//     $input = camInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = camDb();
//     $db->prepare('INSERT INTO cam_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-media.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

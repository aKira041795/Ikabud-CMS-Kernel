<?php
/**
 * Cms Akira Profile Visual Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. capvCtx(), capvDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-profile-visual — Main admin page
 */
function pageCmsAkiraProfileVisualHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-profile-visual/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-profile-visual', [
        ['label' => 'CMS Akira Profile Visual', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Profile Visual',
    ]));
}

/**
 * GET /api/v1/cms-akira-profile-visual/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraProfileVisualHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-profile-visual', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-profile-visual/example
//  */
// function apiCmsAkiraProfileVisualExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     capvCtx()->requireAnyRole('admin');
//
//     $input = capvInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = capvDb();
//     $db->prepare('INSERT INTO capv_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-profile-visual.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

<?php
/**
 * Cms Akira Profile Standard Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. capsCtx(), capsDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-profile-standard — Main admin page
 */
function pageCmsAkiraProfileStandardHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-profile-standard/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-profile-standard', [
        ['label' => 'CMS Akira Profile Standard', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Profile Standard',
    ]));
}

/**
 * GET /api/v1/cms-akira-profile-standard/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraProfileStandardHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-profile-standard', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-profile-standard/example
//  */
// function apiCmsAkiraProfileStandardExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     capsCtx()->requireAnyRole('admin');
//
//     $input = capsInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = capsDb();
//     $db->prepare('INSERT INTO caps_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-profile-standard.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

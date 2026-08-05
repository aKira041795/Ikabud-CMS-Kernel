<?php
/**
 * Cms Akira Profile Minimal Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. capmCtx(), capmDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-profile-minimal — Main admin page
 */
function pageCmsAkiraProfileMinimalHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-profile-minimal/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-profile-minimal', [
        ['label' => 'CMS Akira Profile Minimal', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Profile Minimal',
    ]));
}

/**
 * GET /api/v1/cms-akira-profile-minimal/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraProfileMinimalHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-profile-minimal', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-profile-minimal/example
//  */
// function apiCmsAkiraProfileMinimalExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     capmCtx()->requireAnyRole('admin');
//
//     $input = capmInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = capmDb();
//     $db->prepare('INSERT INTO capm_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-profile-minimal.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

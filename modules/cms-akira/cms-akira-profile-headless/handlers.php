<?php
/**
 * Cms Akira Profile Headless Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. caphCtx(), caphDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-profile-headless — Main admin page
 */
function pageCmsAkiraProfileHeadlessHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    echo cmsRender('modules/cms-akira-profile-headless/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-profile-headless', [
        ['label' => 'CMS Akira Profile Headless', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Profile Headless',
    ]));
}

/**
 * GET /api/v1/cms-akira-profile-headless/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraProfileHeadlessHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-profile-headless', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-profile-headless/example
//  */
// function apiCmsAkiraProfileHeadlessExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     caphCtx()->requireAnyRole('admin');
//
//     $input = caphInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = caphDb();
//     $db->prepare('INSERT INTO caph_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-profile-headless.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

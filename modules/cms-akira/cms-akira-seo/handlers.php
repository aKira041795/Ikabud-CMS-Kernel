<?php
/**
 * Cms Akira Seo Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. casCtx(), casDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-seo — Main admin page
 */
function pageCmsAkiraSeoHome(array $params = []): void
{
    casCtx()->requireAnyRole('admin', 'supervisor', 'administrator');

    echo casRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Seo',
    ]);
}

/**
 * GET /api/v1/cms-akira-seo/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraSeoHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-seo', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-seo/example
//  */
// function apiCmsAkiraSeoExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     casCtx()->requireAnyRole('admin');
//
//     $input = casInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = casDb();
//     $db->prepare('INSERT INTO cas_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-seo.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

<?php
/**
 * Cms Akira Navigation Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. canCtx(), canDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-navigation — Main admin page
 */
function pageCmsAkiraNavigationHome(array $params = []): void
{
    canCtx()->requireAnyRole('admin', 'supervisor', 'administrator');

    echo canRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Navigation',
    ]);
}

/**
 * GET /api/v1/cms-akira-navigation/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraNavigationHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-navigation', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-navigation/example
//  */
// function apiCmsAkiraNavigationExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     canCtx()->requireAnyRole('admin');
//
//     $input = canInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = canDb();
//     $db->prepare('INSERT INTO can_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-navigation.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

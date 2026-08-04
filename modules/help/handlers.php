<?php
/**
 * Help Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. hCtx(), hDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/help — Main admin page
 */
function pageHelpHome(array $params = []): void
{
    hCtx()->requireAnyRole('admin', 'supervisor');

    echo hRender('pages/home.disyl', [
        'page_title' => 'Help',
    ]);
}

/**
 * GET /api/v1/help/health — Dependency-free lifecycle smoke endpoint.
 */
function apiHelpHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'help', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/help/example
//  */
// function apiHelpExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     hCtx()->requireAnyRole('admin');
//
//     $input = hInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = hDb();
//     $db->prepare('INSERT INTO h_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('help.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

<?php
/**
 * Cms Akira Builder Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. cabCtx(), cabDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-builder — Main admin page
 */
function pageCmsAkiraBuilderHome(array $params = []): void
{
    cabCtx()->requireAnyRole('admin', 'supervisor');

    echo cabRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Builder',
    ]);
}

/**
 * GET /api/v1/cms-akira-builder/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraBuilderHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-builder', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-builder/example
//  */
// function apiCmsAkiraBuilderExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     cabCtx()->requireAnyRole('admin');
//
//     $input = cabInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = cabDb();
//     $db->prepare('INSERT INTO cab_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-builder.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

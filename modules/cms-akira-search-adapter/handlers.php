<?php
/**
 * Cms Akira Search Adapter Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. casaCtx(), casaDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-search-adapter — Main admin page
 */
function pageCmsAkiraSearchAdapterHome(array $params = []): void
{
    casaCtx()->requireAnyRole('admin', 'supervisor');

    echo casaRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Search Adapter',
    ]);
}

/**
 * GET /api/v1/cms-akira-search-adapter/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraSearchAdapterHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-search-adapter', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-search-adapter/example
//  */
// function apiCmsAkiraSearchAdapterExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     casaCtx()->requireAnyRole('admin');
//
//     $input = casaInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = casaDb();
//     $db->prepare('INSERT INTO casa_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-search-adapter.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

<?php
/**
 * Cms Akira Ai Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. caaCtx(), caaDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-ai — Main admin page
 */
function pageCmsAkiraAiHome(array $params = []): void
{
    caaCtx()->requireAnyRole('admin', 'supervisor');

    echo caaRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Ai',
    ]);
}

/**
 * GET /api/v1/cms-akira-ai/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraAiHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-ai', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-ai/example
//  */
// function apiCmsAkiraAiExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     caaCtx()->requireAnyRole('admin');
//
//     $input = caaInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = caaDb();
//     $db->prepare('INSERT INTO caa_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-ai.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

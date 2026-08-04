<?php
/**
 * Cms Akira Workflow Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. cawCtx(), cawDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-workflow — Main admin page
 */
function pageCmsAkiraWorkflowHome(array $params = []): void
{
    cawCtx()->requireAnyRole('admin', 'supervisor');

    echo cawRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Workflow',
    ]);
}

/**
 * GET /api/v1/cms-akira-workflow/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraWorkflowHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-workflow', 'version' => '1.0.0']);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-workflow/example
//  */
// function apiCmsAkiraWorkflowExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     cawCtx()->requireAnyRole('admin');
//
//     $input = cawInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = cawDb();
//     $db->prepare('INSERT INTO caw_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-workflow.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

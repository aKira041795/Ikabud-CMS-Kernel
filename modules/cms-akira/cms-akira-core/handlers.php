<?php
/**
 * Cms Akira Core Module — Handlers
 *
 * Each function is a route handler. Signature: function name(array $params = []): void
 * Access module context via the scoped helpers in helpers.php (e.g. cacCtx(), cacDb()).
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /admin/cms-akira-core — Main admin page
 */
function pageCmsAkiraCoreHome(array $params = []): void
{
    cacCtx()->requireAnyRole('admin', 'supervisor');

    echo cacRender('pages/home.disyl', [
        'page_title' => 'Cms Akira Core',
    ]);
}

/**
 * GET /api/v1/cms-akira-core/health — Dependency-free lifecycle smoke endpoint.
 */
function apiCmsAkiraCoreHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-core', 'version' => '1.0.0']);
}

/**
 * GET /api/v1/cms-akira-core/providers/health — Provider boundary runtime status.
 */
function apiCmsAkiraCoreProvidersHealth(array $params = []): void
{
    header('Content-Type: application/json');

    $result = cac_cap_akira_providers_status_1([], 'akira.providers.status@1', 'cms-akira-core');
    if (empty($result['ok'])) {
        http_response_code(500);
    }

    echo json_encode($result);
}

// Example API handler (uncomment route in routes.php):
//
// /**
//  * POST /api/v1/cms-akira-core/example
//  */
// function apiCmsAkiraCoreExample(array $params = []): void
// {
//     header('Content-Type: application/json');
//     cacCtx()->requireAnyRole('admin');
//
//     $input = cacInput();
//     $name = trim((string)($input['name'] ?? ''));
//     if ($name === '') {
//         http_response_code(422);
//         echo json_encode(['ok' => false, 'error' => 'Name is required']);
//         return;
//     }
//
//     $db = cacDb();
//     $db->prepare('INSERT INTO cac_items (name) VALUES (:name)')->execute([':name' => $name]);
//
//     app()->events()->fire('cms-akira-core.item.created', [
//         'id' => (int)$db->lastInsertId(),
//         'name' => $name,
//     ]);
//
//     echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
// }

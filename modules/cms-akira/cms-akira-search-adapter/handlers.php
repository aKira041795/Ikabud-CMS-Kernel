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
    $user = cmsRequireCap('settings.manage');

    // Live suite status: is this adapter active and is it providing search documents?
    $status = [
        'enabled' => function_exists('isModuleEnabled') && isModuleEnabled('cms-akira-search-adapter'),
        'provider_mode' => 'fallback',
        'capability' => 'akira.search.document.build@1',
        'depends' => ['akira.content.get@1'],
    ];
    if (function_exists('cacProviderRuntimeStatus')) {
        foreach (cacProviderRuntimeStatus() as $row) {
            if (($row['provider'] ?? '') === 'SearchIndexer') {
                $status['provider_mode'] = (string)($row['mode'] ?? 'fallback');
                $status['enabled'] = (bool)($row['enabled'] ?? false);
                break;
            }
        }
    }

    echo cmsRender('modules/cms-akira-search-adapter/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-search-adapter', [
        ['label' => 'CMS Akira Search Adapter', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Search Adapter',
        'adapter_status' => $status,
    ]));
}

/**
 * POST /api/v1/cms-akira-search-adapter/build-document — Try the search document builder.
 * Builds a search document from a title + body so admins can preview what gets indexed.
 */
function apiCmsAkiraSearchAdapterBuildDocument(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('settings.manage');
    app()->csrfEnforce();

    $input = cmsInput();
    $title = trim((string)($input['title'] ?? ''));
    $body = (string)($input['body'] ?? '');
    $slug = trim((string)($input['slug'] ?? ''));

    if ($title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title is required']);
        exit;
    }

    try {
        $result = app()->cap()->call('akira.search.document.build@1', [
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
        ]);
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Search document builder failed: ' . $e->getMessage()]);
    }
    exit;
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

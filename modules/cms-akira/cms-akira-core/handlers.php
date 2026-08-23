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
 *
 * Provider-boundary runtime status: which Akira providers are enabled, what
 * contract each exposes, and whether requests resolve in provider or fallback
 * mode. This is the live evidence surface for the Phase 2 provider-boundary
 * gating (graceful degradation across the suite).
 */
function pageCmsAkiraCoreHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $providers = function_exists('cacProviderRuntimeStatus') ? cacProviderRuntimeStatus() : [];

    $enabled = 0;
    $fallback = 0;
    foreach ($providers as $p) {
        if (!empty($p['enabled'])) {
            $enabled++;
        }
        if (($p['mode'] ?? '') === 'fallback') {
            $fallback++;
        }
    }

    echo cmsRender('modules/cms-akira-core/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-core', [
        ['label' => 'CMS Akira Core', 'url' => ''],
    ]), [
        'page_title'          => 'CMS Akira Core',
        'providers'           => $providers,
        'provider_total'      => count($providers),
        'provider_enabled'    => $enabled,
        'provider_fallback'   => $fallback,
    ]));
}

/**
 * GET /admin/ark-status — Read-only ARK POC status panel.
 *
 * Surfaces ARK theme + Workbench profile registration/selection status via
 * akira.ark.status@1 (capability-bus, read-only). The panel never mutates
 * selection or the registries.
 */
function pageAkiraArkStatus(array $params = []): void
{
    $user = cmsRequireCap('dashboard.view');

    $result = cac_cap_akira_ark_status_1([], 'akira.ark.status@1', 'cms-akira-core');
    $status = $result['data'] ?? [];

    echo cmsRender('modules/cms-akira-core/pages/ark-status.disyl', array_merge(cmsAdminContext($user, 'ark-status', [
        ['label' => 'CMS Akira Core', 'url' => '/admin/cms-akira-core'],
        ['label' => 'ARK Status', 'url' => ''],
    ]), [
        'page_title' => 'ARK Status',
        'ark'        => $status,
        'ark_ok'     => (bool)($result['ok'] ?? false),
    ]));
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

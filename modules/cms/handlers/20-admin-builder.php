<?php

declare(strict_types=1);

function cmsAdminBuilderAssetUrl(string $asset): string
{
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $relativePath = 'assets/cms/builder/' . ltrim($asset, '/');
    $absolutePath = PUBLIC_PATH . '/' . $relativePath;
    $version = is_file($absolutePath) ? (string)@filemtime($absolutePath) : '';

    return $baseUrl . '/' . $relativePath . ($version !== '' ? '?v=' . $version : '');
}

function cmsAdminReactBuilderCreate(array $params = []): void
{
    $user = cmsRequireCap('builder.access');
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $previewTheme = cmsBuilderLivePreviewThemePayload();

    $bootData = [
        'contentId' => null,
        'baseUrl'   => $baseUrl,
        'csrfToken' => app()->csrfToken(),
        'previewTheme' => $previewTheme,
        'user'      => [
            'id'       => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? $user['name'] ?? ''),
            'role'     => (string)($user['role'] ?? 'contributor'),
        ],
    ];

    echo cmsRender('modules/cms/admin/react-page-builder.disyl', [
        'page_title'        => 'Page Builder',
        'site_name'         => app()->config('app_name', 'CMS'),
        'builder_css_url'   => cmsAdminBuilderAssetUrl('builder.css'),
        'builder_js_url'    => cmsAdminBuilderAssetUrl('builder.js'),
        'builder_boot_json' => json_encode($bootData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function cmsAdminReactBuilderEdit(array $params = []): void
{
    $user = cmsRequireCap('builder.access');
    $id = (int)($params['id'] ?? 0);
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    if ($id <= 0) {
        http_response_code(404);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Not Found']);
        return;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Not Found']);
        return;
    }
    // Only 'page' type content uses the page builder
    if (($content['type'] ?? '') !== 'page') {
        http_response_code(400);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Not a Page']);
        return;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        return;
    }

    $previewTheme = cmsBuilderLivePreviewThemePayload([
        'content' => $content,
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'traditional',
    ]);

    $bootData = [
        'contentId' => (int)$content['id'],
        'baseUrl'   => $baseUrl,
        'csrfToken' => app()->csrfToken(),
        'previewTheme' => $previewTheme,
        'user'      => [
            'id'       => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? $user['name'] ?? ''),
            'role'     => (string)($user['role'] ?? 'contributor'),
        ],
    ];

    echo cmsRender('modules/cms/admin/react-page-builder.disyl', [
        'page_title'        => 'Page Builder: ' . ($content['title'] ?? ''),
        'site_name'         => app()->config('app_name', 'CMS'),
        'builder_css_url'   => cmsAdminBuilderAssetUrl('builder.css'),
        'builder_js_url'    => cmsAdminBuilderAssetUrl('builder.js'),
        'builder_boot_json' => json_encode($bootData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

<?php

declare(strict_types=1);

function cmsAdminCustomizer(array $params = []): void
{
    $user = cmsRequireCap('customizer.manage');

    $cacheKey = 'cms.customizer';
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/theme-customizer.disyl', array_merge(cmsAdminContext($user, 'customize', [
            ['label' => 'Theme Customizer', 'url' => ''],
        ]), $cached));
        return;
    }

    $db = cmsDb();

    // Load footer customizer data
    $footer = cmsCustomizerGet($db, 'footer');

    // Load header customizer data
    $header = cmsCustomizerGet($db, 'header');

    // Load sidebar customizer data
    $sidebar = cmsCustomizerGet($db, 'sidebar');

    // Load colors customizer data
    $colors = cmsCustomizerGet($db, 'colors');

    // Load custom code customizer data
    $customCode = cmsCustomizerGet($db, 'custom_code');

    // Load theme layout customizer data
    $themeLayout = cmsCustomizerGet($db, 'theme');

    // Load available menus for the nav_menu widget type
    $menus = [];
    try {
        $stmt = $db->query("SELECT id, name, location FROM cms_menus ORDER BY name ASC");
        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    $menuLocations = cmsGetMenuLocations();
    $sidebarTemplates = cmsSidebarTemplateTargets();
    $sidebarTemplateFileExists = cmsSidebarThemeTemplateExists();

    // Load recent posts for the recent_posts widget preview
    $recentPosts = [];
    try {
        $stmt = $db->query(
            "SELECT id, title, slug, created_at FROM cms_content
             WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL
             ORDER BY created_at DESC LIMIT 10"
        );
        $recentPosts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $settings = readCmsSettings();

    $payload = [
        'page_title'          => 'Theme Customizer',
        'footer_settings'     => $footer['settings'],
        'footer_widgets'      => $footer['widgets'],
        'footer_settings_json' => json_encode($footer['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'footer_widgets_json' => json_encode($footer['widgets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'header_settings'     => $header['settings'],
        'header_settings_json' => json_encode($header['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'header_widgets'      => $header['widgets'],
        'header_widgets_json' => json_encode($header['widgets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sidebar_settings'     => $sidebar['settings'],
        'sidebar_settings_json' => json_encode($sidebar['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sidebar_widgets'      => $sidebar['widgets'],
        'sidebar_widgets_json' => json_encode($sidebar['widgets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'available_menus'     => $menus,
        'available_menus_json' => json_encode($menus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'menu_locations'      => $menuLocations,
        'menu_locations_json' => json_encode($menuLocations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sidebar_templates'   => $sidebarTemplates,
        'sidebar_templates_json' => json_encode($sidebarTemplates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sidebar_template_file_exists' => $sidebarTemplateFileExists ? 1 : 0,
        'recent_posts'        => $recentPosts,
        'colors_settings'     => $colors['settings'],
        'colors_settings_json' => json_encode($colors['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'custom_code_settings'     => $customCode['settings'],
        'custom_code_settings_json' => json_encode($customCode['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'theme_layout_settings'     => $themeLayout['settings'],
        'theme_layout_settings_json' => json_encode($themeLayout['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'site_title'          => $settings['site_title'] ?? '',
        'site_tagline'        => $settings['site_tagline'] ?? '',
        'social_links_json'   => json_encode(cmsPublicSocialLinks($settings)),
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:customizer'], $user);

    echo cmsRender('modules/cms/admin/theme-customizer.disyl', array_merge(cmsAdminContext($user, 'customize', [
        ['label' => 'Theme Customizer', 'url' => ''],
    ]), $payload));
}

/**
 * GET API: Retrieve customizer section data
 */

function cmsApiCustomizerGet(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('customizer.manage');

    $section = trim((string)($params['section'] ?? ''));
    if ($section === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Section required']);
        exit;
    }

    $db = cmsDb();
    $data = cmsCustomizerGet($db, $section);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

/**
 * POST API: Save customizer section data
 */

function cmsApiCustomizerSave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('customizer.manage');
    app()->csrfEnforce();

    $section = trim((string)($params['section'] ?? ''));
    if ($section === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Section required']);
        exit;
    }

    $input = cmsInput();
    $settings = $input['settings'] ?? null;
    $widgets  = $input['widgets'] ?? null;

    if (!is_array($settings)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'settings object required']);
        exit;
    }

    // Validate section-specific settings
    if ($section === 'footer') {
        $settings = cmsValidateFooterSettings($settings);
    } elseif ($section === 'sidebar') {
        $settings = cmsValidateSidebarSettings($settings);
    } elseif ($section === 'header') {
        $settings = cmsValidateHeaderSettings($settings);
    } elseif ($section === 'colors') {
        $settings = cmsValidateColorsSettings($settings);
    } elseif ($section === 'custom_code') {
        $settings = cmsValidateCustomCodeSettings($settings);
    } elseif ($section === 'theme') {
        $settings = cmsValidateThemeLayoutSettings($settings);
    }

    $db = cmsDb();
    $userId = (int)($user['id'] ?? 0);

    $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // When widgets are not provided in the request, preserve existing widgets
    // rather than wiping them. This allows settings-only saves to work safely.
    if (is_array($widgets)) {
        $widgetsJson = json_encode($widgets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        // Read existing widgets from the database
        $existingStmt = $db->prepare("SELECT widgets_json FROM cms_theme_customizer WHERE section = :section LIMIT 1");
        $existingStmt->execute([':section' => $section]);
        $widgetsJson = $existingStmt->fetchColumn() ?: '[]';
    }

    $stmt = $db->prepare(
        "INSERT INTO cms_theme_customizer (section, settings_json, widgets_json, updated_by)
         VALUES (:section, :settings, :widgets, :uid)
         ON DUPLICATE KEY UPDATE
            settings_json = VALUES(settings_json),
            widgets_json = VALUES(widgets_json),
            updated_by = VALUES(updated_by)"
    );
    $stmt->execute([
        ':section'  => $section,
        ':settings' => $settingsJson,
        ':widgets'  => $widgetsJson,
        ':uid'      => $userId ?: null,
    ]);

    cmsCustomizerClearPersistentCache($section);

    $response = json_encode(['ok' => true]);
    echo $response;
    release_session_lock_if_active();
    finish_response_if_possible();

    // Customizer saves change runtime data, not compiled templates. Avoid
    // synchronous template cache wipes on every save.
    cmsCacheFlushAll();
    adminViewCacheInvalidate(['cms:admin']);

    // Audit
    if ($ctx = module('cms')) {
        $ctx->audit('cms.customizer.save', null, 'cms_theme_customizer', $section, null, [
            'section'  => $section,
            'settings' => $settings,
        ]);
    }
    exit;
}

/**
 * GET API: Preview rendered footer HTML
 */

function cmsApiCustomizerFooterPreview(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('customizer.manage');

    $db = cmsDb();
    $html = cmsRenderFooterWidgets($db);
    echo json_encode(['ok' => true, 'html' => $html]);
    exit;
}

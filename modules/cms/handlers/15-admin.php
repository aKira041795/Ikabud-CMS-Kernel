<?php

declare(strict_types=1);

function cmsAdminDashboard(array $params = []): void
{
    $user = cmsRequireCap('dashboard.view');

    $cacheKey = 'cms.dashboard';
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/dashboard.disyl', array_merge(cmsAdminContext($user, 'dashboard', []), $cached));
        return;
    }

    $db = cmsDb();

    $contentCounts = [];
    try {
        $stmt = $db->query(
            "SELECT type, status, COUNT(*) as cnt FROM cms_content WHERE deleted_at IS NULL GROUP BY type, status"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contentCounts[$row['type']][$row['status']] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        $contentCounts = [];
    }

    $mediaCnt = 0;
    try {
        $mediaCnt = (int)$db->query("SELECT COUNT(*) FROM cms_media")->fetchColumn();
    } catch (Throwable $e) {}

    $userCnt = 0;
    try {
        $userCnt = (int)$db->query("SELECT COUNT(*) FROM cms_users WHERE is_active = 1")->fetchColumn();
    } catch (Throwable $e) {}

    $recentContent = [];
    try {
        $stmt = $db->query(
            "SELECT c.id, c.title, c.slug, c.type, c.status, c.updated_at, u.display_name as author_name,
                    c.featured_image_id, m.file_path as featured_image
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             LEFT JOIN cms_media m ON m.id = c.featured_image_id
             WHERE c.deleted_at IS NULL
             ORDER BY c.updated_at DESC
             LIMIT 10"
        );
        $recentContent = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $activityFeed = [];
    try {
        $stmt = $db->query(
            "SELECT a.action, a.entity_type, a.entity_id, a.created_at, a.metadata_json,
                    u.display_name as actor_name
             FROM audit_logs a
             LEFT JOIN cms_users u ON u.id = a.actor_user_id
             WHERE a.module = 'cms'
             ORDER BY a.created_at DESC
             LIMIT 15"
        );
        $activityFeed = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $postTotal = 0;
    $pageTotal = 0;
    $customTypeTotal = 0;
    $draftTotal = 0;
    $publishedTotal = 0;
    foreach ($contentCounts as $type => $statuses) {
        $typeSum = array_sum($statuses);
        if ($type === 'post') $postTotal = $typeSum;
        elseif ($type === 'page') $pageTotal = $typeSum;
        else $customTypeTotal += $typeSum;

        $draftTotal += (int)($statuses['draft'] ?? 0);
        $publishedTotal += (int)($statuses['published'] ?? 0);
    }

    $totalContent = $postTotal + $pageTotal + $customTypeTotal;

    $contentOverview = [
        [
            'label' => 'Posts',
            'count' => $postTotal,
            'color' => '#2563eb',
            'share' => $totalContent > 0 ? round(($postTotal / $totalContent) * 100, 1) : 0,
        ],
        [
            'label' => 'Pages',
            'count' => $pageTotal,
            'color' => '#06b6d4',
            'share' => $totalContent > 0 ? round(($pageTotal / $totalContent) * 100, 1) : 0,
        ],
        [
            'label' => 'Custom Types',
            'count' => $customTypeTotal,
            'color' => '#f43f5e',
            'share' => $totalContent > 0 ? round(($customTypeTotal / $totalContent) * 100, 1) : 0,
        ],
    ];

    foreach ($recentContent as &$item) {
        $item['featured_image_url'] = !empty($item['featured_image'])
            ? cmsResolveUploadUrl((string)$item['featured_image'])
            : '';
    }
    unset($item);

    $quickActions = [
        [
            'label' => 'Create Post',
            'description' => 'Write new editorial content',
            'url' => '/cms/admin/content/create?type=post',
            'accent' => 'sky',
            'icon' => 'post',
        ],
        [
            'label' => 'Create Page',
            'description' => 'Build a new static page',
            'url' => '/cms/admin/content/create?type=page',
            'accent' => 'emerald',
            'icon' => 'page',
        ],
        [
            'label' => 'Media Library',
            'description' => 'Upload and manage assets',
            'url' => '/cms/admin/media',
            'accent' => 'amber',
            'icon' => 'media',
        ],
        [
            'label' => 'Page Builder',
            'description' => 'Design pages visually',
            'url' => '/cms/admin/react-builder/create',
            'accent' => 'violet',
            'icon' => 'builder',
        ],
        [
            'label' => 'Theme',
            'description' => 'Adjust site presentation',
            'url' => '/cms/admin/customize',
            'accent' => 'rose',
            'icon' => 'theme',
        ],
        [
            'label' => 'Navigation',
            'description' => 'Manage menus and links',
            'url' => '/cms/admin/menus',
            'accent' => 'indigo',
            'icon' => 'navigation',
        ],
    ];

    $activitySummary = [
        [
            'label' => 'Published',
            'value' => $publishedTotal,
            'tone' => 'emerald',
        ],
        [
            'label' => 'Drafts',
            'value' => $draftTotal,
            'tone' => 'amber',
        ],
        [
            'label' => 'Recent Events',
            'value' => count($activityFeed),
            'tone' => 'sky',
        ],
    ];

    $welcomeHeadline = 'Welcome back';
    if (!empty($user['display_name'])) {
        $welcomeHeadline .= ', ' . trim((string)$user['display_name']);
    }

    $payload = [
        'page_title'     => 'CMS Dashboard',
        'post_total'     => $postTotal,
        'page_total'     => $pageTotal,
        'custom_type_total' => $customTypeTotal,
        'total_content'  => $totalContent,
        'content_overview' => $contentOverview,
        'media_count'    => $mediaCnt,
        'user_count'     => $userCnt,
        'published_total' => $publishedTotal,
        'draft_total'    => $draftTotal,
        'activity_summary' => $activitySummary,
        'welcome_headline' => $welcomeHeadline,
        'quick_actions'  => $quickActions,
        'recent_content' => $recentContent,
        'activity_feed'  => $activityFeed,
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:dashboard'], $user);
    echo cmsRender('modules/cms/admin/dashboard.disyl', array_merge(cmsAdminContext($user, 'dashboard', []), $payload));
}

function cmsAdminContentList(array $params = []): void
{
    $user  = cmsRequireCap('content.list');
    $input = cmsInput();

    $type   = trim((string)($input['type'] ?? 'all'));
    if ($type === '') {
        $type = 'all';
    }
    $status = trim((string)($input['status'] ?? ''));
    $q      = trim((string)($input['q'] ?? ''));
    $page   = max(1, (int)($input['page'] ?? 1));

    // Allow per-page override from URL (5–100); falls back to setting default
    $cmsSettings = readCmsSettings();
    $defaultPerPage = max(5, min(100, (int)($cmsSettings['posts_per_page'] ?? 20)));
    $perPage = isset($input['per_page']) ? max(5, min(100, (int)$input['per_page'])) : $defaultPerPage;

    $cacheKey = 'cms.content_list:' . md5(json_encode([
        'type' => ($q !== '' ? '' : $type),
        'status' => $status,
        'q' => $q,
        'page' => $page,
        'per_page' => $perPage,
        'author_id' => $input['author_id'] ?? null,
        'category_id' => $input['category_id'] ?? null,
        'tag' => $input['tag'] ?? null,
        'date_from' => $input['date_from'] ?? null,
        'date_to' => $input['date_to'] ?? null,
        'is_sticky' => $input['is_sticky'] ?? null,
        'is_featured' => $input['is_featured'] ?? null,
        'role' => $user['role'] ?? null,
        'source' => $user['source'] ?? null,
        'user_id' => $user['id'] ?? null,
    ]));
    $currentPage = $type === 'all' ? 'content' : ($type === 'page' ? 'pages' : ($type === 'post' ? 'posts' : 'content'));
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $breadcrumbs = [
        ['label' => $type === 'all' ? 'All Content' : ucfirst($type) . 's', 'url' => $baseUrl . '/cms/admin/content?type=' . $type],
    ];
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/content-list.disyl', array_merge(cmsAdminContext($user, $currentPage, $breadcrumbs), $cached));
        return;
    }

    // $cmsSettings and $perPage already resolved above

    $authorId   = isset($input['author_id'])   ? (int)$input['author_id']   : null;
    $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : null;
    $tag        = isset($input['tag'])         ? trim((string)$input['tag']) : null;
    $dateFrom   = isset($input['date_from'])   ? trim((string)$input['date_from']) : null;
    $dateTo     = isset($input['date_to'])     ? trim((string)$input['date_to'])   : null;
    $isSticky   = isset($input['is_sticky'])   ? (int)(bool)$input['is_sticky']    : null;
    $isFeatured = isset($input['is_featured']) ? (int)(bool)$input['is_featured']  : null;

    // Trash items have deleted_at set; all other views exclude soft-deleted rows
    $where = [$status === 'trash' ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL'];
    $bind  = [];

    if ($type !== '' && $type !== 'all' && $q === '') {
        $where[] = 'c.type = :type';
        $bind[':type'] = $type;
    }
    if ($status !== '') {
        $where[] = 'c.status = :status';
        $bind[':status'] = $status;
    }
    if ($q !== '') {
        $where[] = '(c.title LIKE :q1 OR c.excerpt LIKE :q2 OR c.body LIKE :q3)';
        $bind[':q1'] = '%' . $q . '%';
        $bind[':q2'] = '%' . $q . '%';
        $bind[':q3'] = '%' . $q . '%';
    }
    if ($authorId !== null && $authorId > 0) {
        $where[] = 'c.author_id = :filter_author';
        $bind[':filter_author'] = $authorId;
    }
    if ($isSticky !== null) {
        $where[] = 'c.is_sticky = :is_sticky';
        $bind[':is_sticky'] = $isSticky;
    }
    if ($isFeatured !== null) {
        $where[] = 'c.is_featured = :is_featured';
        $bind[':is_featured'] = $isFeatured;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $ts = strtotime($dateFrom);
        if ($ts !== false) {
            $where[] = 'c.published_at >= :date_from';
            $bind[':date_from'] = date('Y-m-d 00:00:00', $ts);
        }
    }
    if ($dateTo !== null && $dateTo !== '') {
        $ts = strtotime($dateTo);
        if ($ts !== false) {
            $where[] = 'c.published_at <= :date_to';
            $bind[':date_to'] = date('Y-m-d 23:59:59', $ts);
        }
    }

    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if ($source === 'cms' && !cmsRoleAtLeast($role, 'editor')) {
        $where[] = 'c.author_id = :uid';
        $bind[':uid'] = (int)($user['id'] ?? 0);
    }

    $db = cmsDb();

    $joinCategory = '';
    if ($categoryId !== null && $categoryId > 0) {
        $joinCategory = "INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = :cat_id";
        $bind[':cat_id'] = $categoryId;
    }

    $joinTag = '';
    if ($tag !== null && $tag !== '') {
        $tagRow = null;
        try {
            $tagStmt = $db->prepare("SELECT id FROM cms_tags WHERE slug = :ts OR name = :tn LIMIT 1");
            $tagStmt->execute([':ts' => $tag, ':tn' => $tag]);
            $tagRow = $tagStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        if ($tagRow) {
            $joinTag = "INNER JOIN cms_content_tags ctag ON ctag.content_id = c.id AND ctag.tag_id = :tag_id";
            $bind[':tag_id'] = (int)$tagRow['id'];
        }
    }

    $whereStr = implode(' AND ', $where);
    $joins    = trim($joinCategory . ' ' . $joinTag);

    $total = 0;
    try {
        $cStmt = $db->prepare("SELECT COUNT(DISTINCT c.id) FROM cms_content c {$joins} WHERE {$whereStr}");
        $cStmt->execute($bind);
        $total = (int)$cStmt->fetchColumn();
    } catch (Throwable $e) {}

    $offset = ($page - 1) * $perPage;
    $rows   = [];
    try {
        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.type, c.status,
                    c.featured_image_id,
                    c.is_sticky, c.is_featured, c.post_format,
                    c.word_count, c.reading_time, c.comment_count,
                    c.published_at, c.updated_at,
                    u.display_name as author_name, u.id as author_id,
                    m.file_path as featured_image
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             LEFT JOIN cms_media m ON m.id = c.featured_image_id
             {$joins}
             WHERE {$whereStr}
             GROUP BY c.id
             ORDER BY c.is_sticky DESC, c.updated_at DESC, c.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    foreach ($rows as $i => &$row) {
        $row['row_number'] = ($page - 1) * $perPage + $i + 1;
        $row['featured_image_url'] = !empty($row['featured_image'])
            ? cmsResolveUploadUrl((string)$row['featured_image'])
            : '';
    }
    unset($row);

    $totalPages = max(1, (int)ceil($total / $perPage));

    $authorList = [];
    if (cmsRoleAtLeast($role, 'editor')) {
        try {
            if ($type === 'all') {
                $aStmt = $db->prepare(
                    "SELECT DISTINCT u.id, u.display_name
                     FROM cms_users u
                     INNER JOIN cms_content c ON c.author_id = u.id
                     WHERE c.deleted_at IS NULL
                     ORDER BY u.display_name ASC"
                );
                $aStmt->execute();
            } else {
                $aStmt = $db->prepare(
                    "SELECT DISTINCT u.id, u.display_name
                     FROM cms_users u
                     INNER JOIN cms_content c ON c.author_id = u.id
                     WHERE c.type = :type AND c.deleted_at IS NULL
                     ORDER BY u.display_name ASC"
                );
                $aStmt->execute([':type' => $type]);
            }
            $authorList = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    $categoryList = [];
    try {
        $catListStmt = $db->query("SELECT id, name, slug FROM cms_categories WHERE parent_id IS NULL ORDER BY name ASC");
        $categoryList = $catListStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // Active custom content types (beyond post/page) for tab navigation
    $customTypes = [];
    try {
        $ctStmt = $db->query(
            "SELECT slug, label FROM cms_content_types WHERE is_active = 1 AND slug NOT IN ('post','page') ORDER BY sort_order ASC, slug ASC"
        );
        $customTypes = $ctStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // Trash item count for the badge on the Trash tab
    $trashCount = 0;
    try {
        if ($type === 'all') {
            $trashStmt = $db->query("SELECT COUNT(*) FROM cms_content WHERE deleted_at IS NOT NULL");
        } else {
            $trashStmt  = $db->prepare(
                "SELECT COUNT(*) FROM cms_content WHERE deleted_at IS NOT NULL AND type = :trash_type"
            );
            $trashStmt->execute([':trash_type' => $type]);
        }
        $trashCount = (int)$trashStmt->fetchColumn();
    } catch (Throwable $e) {}

    $payload = [
        'page_title'         => $type === 'all' ? 'All Content' : ucfirst($type) . 's',
        'content_type'       => $type,
        'rows'               => $rows,
        'total'              => $total,
        'page_num'           => $page,
        'total_pages'        => $totalPages,
        'prev_page'          => max(1, $page - 1),
        'next_page'          => min($page + 1, $totalPages),
        'search'             => $q,
        'status_filter'      => $status,
        'author_filter'      => $authorId,
        'category_filter'    => $categoryId,
        'tag_filter'         => $tag,
        'date_from'          => $dateFrom,
        'date_to'            => $dateTo,
        'is_sticky_filter'   => $isSticky,
        'is_featured_filter' => $isFeatured,
        'author_list'        => $authorList,
        'category_list'      => $categoryList,
        'custom_types'       => $customTypes,
        'trash_count'        => $trashCount,
        'per_page'           => $perPage,
        'default_per_page'   => $defaultPerPage,
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:content', 'cms:type:' . $type], $user);
    echo cmsRender('modules/cms/admin/content-list.disyl', array_merge(cmsAdminContext($user, $currentPage, $breadcrumbs), $payload));
}

function cmsAdminContentCreate(array $params = []): void
{
    $user  = cmsRequireCap('content.create');
    $input = cmsInput();
    $type  = trim((string)($input['type'] ?? 'post'));

    $fieldDefs = [];
    try {
        $stmt = cmsDb()->prepare(
            "SELECT f.id, f.field_key, f.field_type, f.label, f.placeholder, f.options_json, f.validation_json
             FROM cms_field_definitions f
             INNER JOIN cms_content_types t ON t.id = f.content_type_id
             WHERE t.slug = :slug AND t.is_active = 1
             ORDER BY f.sort_order ASC, f.id ASC"
        );
        $stmt->execute([':slug' => $type]);
        $fieldDefs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $fieldDefs = [];
    }

    foreach ($fieldDefs as &$fd) {
        $fd['options'] = [];
        if (($fd['field_type'] ?? '') === 'select' && !empty($fd['options_json']) && is_string($fd['options_json'])) {
            $decoded = json_decode($fd['options_json'], true);
            if (is_array($decoded)) {
                $fd['options'] = $decoded;
            }
        }
    }
    unset($fd);

    $cmsSettings        = readCmsSettings();
    $builderSupported   = cmsBuilderSupportedForType($type);
    $resolvedContext    = cmsResolveEntityContextForType($type);
    $recommendedPresets = cmsEntityPresetRecommendationsForType($type, ['resolved_context' => $resolvedContext]);
    $defaultPresetId    = (string)($recommendedPresets[0]['id'] ?? '');
    $contextBase        = trim((string)($resolvedContext['binding']['base'] ?? ''));
    $enabledPostFormats = array_values(array_filter(array_map('trim', explode(',', (string)($cmsSettings['enabled_post_formats'] ?? 'standard')))));

    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    $isKernelAdmin = ($source === 'kernel' && $role === 'admin');
    $capabilities = [
        'can_edit'        => true,
        'can_publish'     => cmsCanPublish($user),
        'can_trash'       => cmsRoleAtLeast($role, 'contributor') || $isKernelAdmin,
        'can_restore'     => cmsRoleAtLeast($role, 'contributor') || $isKernelAdmin,
        'can_workflow'    => cmsCanPublish($user),
        'ai_tools'        => cmsRoleAtLeast($role, 'contributor') || $isKernelAdmin,
        'ai_seo_suggest'  => cmsRoleAtLeast($role, 'contributor') || $isKernelAdmin,
        'ai_refine'       => (cmsRoleAtLeast($role, 'contributor') || $isKernelAdmin) && function_exists('cmsUserCan') && cmsUserCan($user, 'ai.refine'),
        'can_duplicate'   => false,
        'builder_access'  => $builderSupported,
    ];

    $extBlocks        = cmsGetExtensionBlockTypes();
    $extFields        = cmsGetExtensionSidebarFields($type);
    $tinyMceAssets    = cmsTinyMceAssets('cms.content', 'default');
    $tinyMceConfig    = cmsTinyMceConfig('cms.content', 'default', false);
    $allCategories    = cmsGetCategories();
    $contentTemplates = cmsGetContentTemplates($type);

    $currentPage = $type === 'page' ? 'pages' : 'posts';
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    echo cmsRender('modules/cms/admin/content-editor.disyl', array_merge(cmsAdminContext($user, $currentPage, [
        ['label' => ucfirst($type) . 's', 'url' => $baseUrl . '/cms/admin/content?type=' . $type],
        ['label' => 'New ' . ucfirst($type), 'url' => ''],
    ]), [
        'page_title'                    => 'Create ' . ucfirst($type),
        'content'                       => null,
        'content_meta'                  => null,
        'content_blocks'                => [],
        'featured_image_url'            => '',
        'field_defs'                    => $fieldDefs,
        'dyn_meta'                      => [],
        'ext_block_types'               => $extBlocks,
        'ext_sidebar_fields'            => $extFields,
        'tinymce_assets'                => $tinyMceAssets,
        'tinymce_config'                => $tinyMceConfig,
        'content_type'                  => $type,
        'is_new'                        => true,
        'all_categories'                => $allCategories,
        'content_category_ids'          => [],
        'all_tags'                      => cmsGetTags(),
        'content_tag_names'             => [],
        'content_templates'             => $contentTemplates,
        'selected_template'             => 'default',
        'page_builder_supported'        => $builderSupported,
        'page_builder_url'              => $builderSupported ? $baseUrl . '/cms/admin/react-builder/create?type=' . $type : '',
        'page_builder_enabled'          => false,
        'builder_locked'                => false,
        'has_recommended_entity_presets'=> $recommendedPresets !== [],
        'recommended_entity_presets'    => $recommendedPresets,
        'recommended_entity_preset_default' => $defaultPresetId,
        'entity_context_base'           => $contextBase,
        'content_default_status'        => $cmsSettings['default_post_status'] ?? 'draft',
        'content_default_comment_status'=> $cmsSettings['default_comment_status'] ?? 'open',
        'enabled_post_formats'          => $enabledPostFormats,
        'duplicate_url'                 => '',
        'permalink'                     => '',
        'capabilities'                  => $capabilities,
        'ai_automation_json'            => '{}',
    ]));
}

function cmsAdminContentEdit(array $params = []): void
{
    $user = cmsRequireCap('content.edit_own');
    $id   = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Not Found']);
        return;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$content) {
        http_response_code(404);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Not Found']);
        return;
    }

    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        return;
    }

    $meta        = cmsLoadContentMeta($db, $id);
    $contentType = (string)($content['type'] ?? 'post');

    $aiAutomation = function_exists('cmsAiAutomationContentStateFromMeta')
        ? cmsAiAutomationContentStateFromMeta($meta)
        : ['enabled' => ($meta['_ai_generated'] ?? '') === '1'];

    $fieldDefs = [];
    try {
        $stmt = $db->prepare(
            "SELECT f.id, f.field_key, f.field_type, f.label, f.placeholder, f.options_json, f.validation_json
             FROM cms_field_definitions f
             INNER JOIN cms_content_types t ON t.id = f.content_type_id
             WHERE t.slug = :slug AND t.is_active = 1
             ORDER BY f.sort_order ASC, f.id ASC"
        );
        $stmt->execute([':slug' => $contentType]);
        $fieldDefs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $fieldDefs = [];
    }

    foreach ($fieldDefs as &$fd) {
        $fd['options'] = [];
        if (($fd['field_type'] ?? '') === 'select' && !empty($fd['options_json']) && is_string($fd['options_json'])) {
            $decoded = json_decode($fd['options_json'], true);
            if (is_array($decoded)) {
                $fd['options'] = $decoded;
            }
        }
    }
    unset($fd);

    $dynMeta = [];
    foreach ($fieldDefs as $fd) {
        $k = (string)($fd['field_key'] ?? '');
        if ($k === '') continue;
        $dynMeta[$k] = $meta[$k] ?? '';
    }

    $blocks = [];
    try {
        $rawBlocks = $content['blocks_json'] ?? null;
        if (is_string($rawBlocks) && trim($rawBlocks) !== '') {
            $decoded = json_decode($rawBlocks, true);
            if (is_array($decoded)) {
                foreach ($decoded as $b) {
                    if (!is_array($b)) continue;
                    $bType = (string)($b['type'] ?? '');
                    if ($bType === 'list') {
                        $items = $b['items'] ?? [];
                        if (!is_array($items)) $items = [];
                        $b['items_text'] = implode("\n", array_map(fn($x) => (string)$x, $items));
                    }
                    $blocks[] = $b;
                }
            }
        }
    } catch (Throwable $e) {
        $blocks = [];
    }

    $editLockWarning = '';
    $currentUserId   = (int)($user['id'] ?? 0);
    $currentUserName = (string)($user['full_name'] ?? $user['username'] ?? $user['display_name'] ?? 'Unknown');
    try {
        $lockStmt = $db->prepare("SELECT meta_value FROM cms_content_meta WHERE content_id = :cid AND meta_key = '_edit_lock'");
        $lockStmt->execute([':cid' => $id]);
        $lockVal = $lockStmt->fetchColumn();
        if ($lockVal) {
            $lockData = json_decode($lockVal, true);
            if (is_array($lockData)) {
                $lockTime = (int)($lockData['ts'] ?? 0);
                $lockUid  = (int)($lockData['user_id'] ?? 0);
                $lockName = (string)($lockData['user_name'] ?? 'Someone');
                if ($lockUid !== $currentUserId && (time() - $lockTime) < 120) {
                    $editLockWarning = $lockName . ' is currently editing this content (lock acquired ' . gmdate('H:i:s', $lockTime) . ' UTC). Your changes may conflict.';
                }
            }
        }
        $lockJson = json_encode(['user_id' => $currentUserId, 'user_name' => $currentUserName, 'ts' => time()]);
        $upsertStmt = $db->prepare(
            "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:cid, '_edit_lock', :val)
             ON DUPLICATE KEY UPDATE meta_value = :val2"
        );
        $upsertStmt->execute([':cid' => $id, ':val' => $lockJson, ':val2' => $lockJson]);
    } catch (Throwable $e) {}

    $cmsSettings        = readCmsSettings();
    $builderSupported   = cmsBuilderSupportedForType($contentType);
    $builderEnabled     = cmsPageBuilderEnabled($meta);
    $builderAccess      = $builderSupported || $builderEnabled;
    $builderLocked      = $builderEnabled && cmsBuilderIsLocked($meta);
    $resolvedContext    = cmsResolveEntityContextForType($contentType);
    $recommendedPresets = cmsEntityPresetRecommendationsForType($contentType, ['resolved_context' => $resolvedContext]);
    $contextBase        = trim((string)($resolvedContext['binding']['base'] ?? ''));
    $enabledPostFormats = array_values(array_filter(array_map('trim', explode(',', (string)($cmsSettings['enabled_post_formats'] ?? 'standard')))));

    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    $isKernelAdmin = ($source === 'kernel' && $role === 'admin');
    $canEdit = cmsCanEditContent($user, $content);
    $capabilities = [
        'can_edit'        => $canEdit,
        'can_publish'     => cmsCanPublish($user),
        'can_trash'       => $canEdit,
        'can_restore'     => $canEdit,
        'can_workflow'    => cmsCanPublish($user),
        'ai_tools'        => $canEdit,
        'ai_seo_suggest'  => $canEdit,
        'ai_refine'       => $canEdit && cmsUserCan($user, 'ai.refine'),
        'can_duplicate'   => $canEdit,
        'builder_access'  => $builderAccess,
        'is_kernel_admin' => $isKernelAdmin,
    ];

    $extBlocks        = cmsGetExtensionBlockTypes();
    $extFields        = cmsGetExtensionSidebarFields($contentType);
    $tinyMceAssets    = cmsTinyMceAssets('cms.content', 'default');
    $tinyMceConfig    = cmsTinyMceConfig('cms.content', 'default', false);
    $allCategories    = cmsGetCategories();
    $contentTemplates = cmsGetContentTemplates($contentType);
    $selectedTemplate = $meta['_template'] ?? 'default';

    foreach ($extFields as $ef) {
        $k = (string)($ef['key'] ?? '');
        if ($k !== '' && !array_key_exists($k, $dynMeta)) {
            $dynMeta[$k] = $meta[$k] ?? '';
        }
    }

    $baseUrl      = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $currentPage  = $contentType === 'page' ? 'pages' : 'posts';
    $duplicateUrl = $baseUrl . '/api/v1/cms/content/' . $id . '/duplicate';
    $permalink    = cmsContentPermalink($content);

    $featuredImageUrl = '';
    if (!empty($content['featured_image_id'])) {
        try {
            $stmt = $db->prepare("SELECT file_path FROM cms_media WHERE id = :mid LIMIT 1");
            $stmt->execute([':mid' => (int)$content['featured_image_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['file_path'])) {
                $featuredImageUrl = cmsResolveUploadUrl((string)$row['file_path']);
            }
        } catch (Throwable $e) {}
    }

    echo cmsRender('modules/cms/admin/content-editor.disyl', array_merge(cmsAdminContext($user, $currentPage, [
        ['label' => ucfirst($contentType) . 's', 'url' => $baseUrl . '/cms/admin/content?type=' . $contentType],
        ['label' => ($content['title'] ?? 'Edit'), 'url' => ''],
    ]), [
        'page_title'                    => 'Edit: ' . ($content['title'] ?? ''),
        'content'                       => $content,
        'content_meta'                  => $meta,
        'content_blocks'                => $blocks,
        'featured_image_url'            => $featuredImageUrl,
        'field_defs'                    => $fieldDefs,
        'dyn_meta'                      => $dynMeta,
        'ext_block_types'               => $extBlocks,
        'ext_sidebar_fields'            => $extFields,
        'tinymce_assets'                => $tinyMceAssets,
        'tinymce_config'                => $tinyMceConfig,
        'content_type'                  => $contentType,
        'is_new'                        => false,
        'edit_lock_warning'             => $editLockWarning,
        'all_categories'                => $allCategories,
        'content_category_ids'          => cmsGetContentCategoryIds($id),
        'all_tags'                      => cmsGetTags(),
        'content_tag_names'             => cmsGetContentTagNames($id),
        'content_templates'             => $contentTemplates,
        'selected_template'             => $selectedTemplate,
        'page_builder_supported'        => $builderAccess,
        'page_builder_url'              => $builderAccess ? $baseUrl . '/cms/admin/react-builder/' . $id : '',
        'page_builder_enabled'          => $builderEnabled,
        'builder_locked'                => $builderLocked,
        'has_recommended_entity_presets'=> $recommendedPresets !== [],
        'recommended_entity_presets'    => $recommendedPresets,
        'recommended_entity_preset_default' => '',
        'entity_context_base'           => $contextBase,
        'content_default_status'        => $cmsSettings['default_post_status'] ?? 'draft',
        'content_default_comment_status'=> $cmsSettings['default_comment_status'] ?? 'open',
        'enabled_post_formats'          => $enabledPostFormats,
        'duplicate_url'                 => $duplicateUrl,
        'permalink'                     => $permalink,
        'capabilities'                  => $capabilities,
        'ai_automation_json'            => json_encode($aiAutomation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]));
}


// ═══════════════════════════════════════════════════════════════════════
// REACT PAGE BUILDER (standalone SPA shell)
// ═══════════════════════════════════════════════════════════════════════

function cmsAdminMedia(array $params = []): void
{
    $user = cmsRequireCap('media.list');
    $input = cmsInput();

    $page = max(1, (int)($input['page'] ?? 1));
    $cacheKey = 'cms.media:' . $page;
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/media-library.disyl', array_merge(cmsAdminContext($user, 'media', [
            ['label' => 'Media', 'url' => ''],
        ]), $cached));
        return;
    }

    $db = cmsDb();
    $perPage = 24;
    $offset  = ($page - 1) * $perPage;

    $total = 0;
    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM cms_media")->fetchColumn();
    } catch (Throwable $e) {}

    $rows = [];
    try {
        $stmt = $db->prepare(
            "SELECT m.*, u.display_name as uploader_name
             FROM cms_media m
             LEFT JOIN cms_users u ON u.id = m.uploaded_by
             ORDER BY m.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $totalPages = max(1, (int)ceil($total / $perPage));

    // Resolve per-file URLs (tenant-aware with legacy fallback)
    foreach ($rows as &$_mr) {
        $_mr['url'] = cmsResolveUploadUrl((string)($_mr['file_path'] ?? ''));
    }
    unset($_mr);

    $payload = [
        'page_title'  => 'Media Library',
        'media'       => $rows,
        'total'       => $total,
        'page_num'    => $page,
        'total_pages' => $totalPages,
        'next_page'   => min($page + 1, $totalPages),
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:media'], $user);
    echo cmsRender('modules/cms/admin/media-library.disyl', array_merge(cmsAdminContext($user, 'media', [
        ['label' => 'Media', 'url' => ''],
    ]), $payload));
}

function cmsAdminUsers(array $params = []): void
{
    $user = cmsRequireCap('users.manage');
    $input = cmsInput();

    $q = trim((string)($input['q'] ?? ''));
    $cacheKey = 'cms.users:' . md5($q);
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/users.disyl', array_merge(cmsAdminContext($user, 'users', [
            ['label' => 'Users', 'url' => ''],
        ]), $cached));
        return;
    }

    $db = cmsDb();

    $where = ['1=1'];
    $bind  = [];
    if ($q !== '') {
        $where[] = '(username LIKE :q1 OR display_name LIKE :q2 OR email LIKE :q3)';
        $bind[':q1'] = '%' . $q . '%';
        $bind[':q2'] = '%' . $q . '%';
        $bind[':q3'] = '%' . $q . '%';
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare(
        "SELECT id, username, email, display_name, role, is_active, last_login_at, created_at
         FROM cms_users WHERE {$whereStr} ORDER BY created_at DESC"
    );
    $stmt->execute($bind);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Ecommerce: batch-load store assignments for all users ──────────
    $storeAssignmentsMap = [];
    $allStores           = [];
    $ecActive            = false;
    if (function_exists('ecStoreStorageAvailable') && ecStoreStorageAvailable()
        && function_exists('ecDb') && function_exists('ecStoreList')) {
        $ecActive = true;
        $userIds  = array_column($users, 'id');
        if ($userIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $rows = ecDb()->query(
                    "SELECT su.user_id, su.role, s.id AS store_id, s.name AS store_name, s.slug
                     FROM ec_store_users su
                     JOIN ec_stores s ON s.id = su.store_id
                     WHERE su.user_id IN ($placeholders)
                     ORDER BY FIELD(su.role,'owner','manager','supervisor'), s.name ASC",
                    array_values($userIds)
                )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $storeAssignmentsMap[(int)$row['user_id']][] = $row;
                }
            } catch (\Throwable $ignored) {}
        }
        try {
            $allStores = ecStoreList(['active_only' => true, 'limit' => 200])['items'];
        } catch (\Throwable $ignored) {}
    }

    $payload = [
        'page_title'              => 'CMS Users',
        'cms_users'               => $users,
        'search'                  => $q,
        'ec_active'               => $ecActive,
        'store_assignments_map'   => $storeAssignmentsMap,
        'all_stores'              => $allStores,
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:users'], $user);
    echo cmsRender('modules/cms/admin/users.disyl', array_merge(cmsAdminContext($user, 'users', [
        ['label' => 'Users', 'url' => ''],
    ]), $payload));
}

function cmsAdminSettings(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');

    $cacheKey = 'cms.settings';
    $cached = adminViewCacheGet($cacheKey, $user);
    if (is_array($cached)) {
        echo cmsRender('modules/cms/admin/settings.disyl', array_merge(cmsAdminContext($user, 'settings', [
            ['label' => 'Settings', 'url' => ''],
        ]), $cached));
        return;
    }

    $settings = readCmsSettings();
    $defaults = cmsSettingsDefaults();

    $pages = [];
    try {
        $db = cmsDb();
        $stmt = $db->query("SELECT id, title FROM cms_content WHERE type = 'page' AND deleted_at IS NULL AND status = 'published' ORDER BY title ASC");
        $pages = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        // pages list optional
    }

    $payload = [
        'page_title'       => 'CMS Settings',
        'cms_settings'     => $settings,
        'cms_settings_json'=> json_encode(array_merge($settings, [
            'active_theme' => cmsActiveTheme() ?? 'default',
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        'defaults'         => $defaults,
        'setting_keys'     => array_keys($defaults),
        'available_themes' => cmsAvailableThemes(),
        'active_theme'     => cmsActiveTheme() ?? 'default',
        'pages'            => $pages,
    ];

    adminViewCacheSet($cacheKey, $payload, ['cms:admin', 'cms:admin:settings'], $user);
    echo cmsRender('modules/cms/admin/settings.disyl', array_merge(cmsAdminContext($user, 'settings', [
        ['label' => 'Settings', 'url' => ''],
    ]), $payload));
}

function cmsAdminContentTypes(array $params = []): void
{
    $user = cmsRequireCap('content_types.manage');

    echo cmsRender('modules/cms/admin/content-types.disyl', array_merge(cmsAdminContext($user, 'content_types', [
        ['label' => 'Content Types', 'url' => ''],
    ]), [
        'page_title' => 'Content Types',
    ]));
}

// ═══════════════════════════════════════════════════════════════════════
// CONTENT API HANDLERS
// ═══════════════════════════════════════════════════════════════════════

<?php

declare(strict_types=1);

function cmsPublicRenderTimingEnabled(): bool
{
    return timing_logs_enabled('CMS_PUBLIC_RENDER_TIMING') || timing_logs_enabled('APP_TIMING_LOGS');
}

function cmsPublicRenderLogTiming(string $message, float $startTime, array $context = []): ?float
{
    return log_timing(
        $message,
        $startTime,
        $context,
        'CMS_PUBLIC_RENDER_TIMING',
        'CMS_PUBLIC_RENDER_TIMING_THRESHOLD_MS'
    );
}

function cmsPublicRespond(string $body): void
{
    echo $body;
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function cmsApiPublicPosts(array $params = []): void
{
    cmsApiPublicListByType('post');
}

function cmsApiPublicContentByType(array $params = []): void
{
    $type = trim((string)($params['type'] ?? ''));
    if ($type === '') {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    cmsApiPublicListByType($type);
}

function cmsApiPublicPostBySlug(array $params = []): void
{
    cmsApiPublicGetBySlug('post', $params);
}

function cmsApiPublicPageBySlug(array $params = []): void
{
    cmsApiPublicGetBySlug('page', $params);
}

function cmsApiPublicListByType(string $type): void
{
    header('Content-Type: application/json');
    $input = cmsInput();

    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = min(100, max(1, (int)($input['per_page'] ?? 10)));
    $offset = ($page - 1) * $perPage;

    $fieldsParam = trim((string)($input['fields'] ?? ''));
    $includeParam = trim((string)($input['include'] ?? ''));

    $fields = $fieldsParam !== '' ? array_values(array_filter(array_map('trim', explode(',', $fieldsParam)))) : [];
    $include = $includeParam !== '' ? array_values(array_filter(array_map('trim', explode(',', $includeParam)))) : [];

    $allowFields = [
        'id','uuid','title','slug','excerpt','type','status','published_at','created_at','updated_at',
        'author_id','featured_image_id','parent_id','comment_status','blocks_json','body',
    ];

    $select = [];
    if (!empty($fields)) {
        foreach ($fields as $f) {
            if (in_array($f, $allowFields, true)) {
                $select[] = 'c.' . $f;
            }
        }
    }
    if (empty($select)) {
        $select = [
            'c.id','c.uuid','c.title','c.slug','c.excerpt','c.type','c.status','c.published_at','c.created_at','c.updated_at',
            'c.author_id','c.featured_image_id',
        ];
    }

    if (in_array('blocks', $include, true) && !in_array('c.blocks_json', $select, true)) {
        $select[] = 'c.blocks_json';
    }

    $joins = '';
    if (in_array('author', $include, true)) {
        $joins .= " LEFT JOIN cms_users u ON u.id = c.author_id ";
        $select[] = 'u.display_name as author_name';
    }
    if (in_array('featured_image', $include, true)) {
        $joins .= " LEFT JOIN cms_media m ON m.id = c.featured_image_id ";
        $select[] = 'm.file_path as featured_image_path';
        $select[] = 'm.mime_type as featured_image_mime';
    }

    $db = cmsDb();
    $total = 0;
    try {
        $cStmt = $db->prepare(
            "SELECT COUNT(*) FROM cms_content c WHERE c.deleted_at IS NULL AND c.type = :type AND " . cmsPublicVisibilitySql('c')
        );
        $cStmt->execute([':type' => $type]);
        $total = (int)$cStmt->fetchColumn();
    } catch (Throwable $e) {
        $total = 0;
    }

    $rows = [];
    try {
        $sql = "SELECT " . implode(', ', array_unique($select)) .
               " FROM cms_content c {$joins} " .
               " WHERE c.deleted_at IS NULL AND c.type = :type AND " . cmsPublicVisibilitySql('c') . " " .
               " ORDER BY c.published_at DESC, c.created_at DESC " .
               " LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute([':type' => $type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    // include=meta loads cms_content_meta for all returned rows (1 query)
    if (in_array('meta', $include, true) && !empty($rows)) {
        $ids = array_map(fn($r) => (int)($r['id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids, fn($x) => $x > 0));
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $mStmt = $db->prepare("SELECT content_id, meta_key, meta_value FROM cms_content_meta WHERE content_id IN ({$in})");
            $mStmt->execute($ids);
            $metaRows = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byId = [];
            foreach ($metaRows as $mr) {
                $cid = (int)($mr['content_id'] ?? 0);
                $k = (string)($mr['meta_key'] ?? '');
                if ($cid <= 0 || $k === '') continue;
                $byId[$cid][$k] = $mr['meta_value'];
            }
            foreach ($rows as &$r) {
                $cid = (int)($r['id'] ?? 0);
                $r['meta'] = $byId[$cid] ?? new stdClass();
            }
            unset($r);
        }
    }

    // Strip heavy fields unless explicitly requested
    if (!in_array('blocks', $include, true)) {
        // default: strip blocks_json/body if requested fields didn't include
        foreach ($rows as &$r) {
            if (!in_array('blocks_json', $fields, true)) {
                unset($r['blocks_json']);
            }
            if (!in_array('body', $fields, true)) {
                unset($r['body']);
            }
        }
        unset($r);
    }

    // Build featured_image url
    foreach ($rows as &$r) {
        if (isset($r['featured_image_path'])) {
            $r['featured_image_url'] = cmsResolveUploadUrl((string)$r['featured_image_path']);
        }
    }
    unset($r);

    $pages = max(1, (int)ceil($total / $perPage));

    echo json_encode([
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ],
    ]);
    exit;
}

function cmsApiPublicGetBySlug(string $type, array $params): void
{
    header('Content-Type: application/json');
    $input = cmsInput();

    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $includeParam = trim((string)($input['include'] ?? ''));
    $include = $includeParam !== '' ? array_values(array_filter(array_map('trim', explode(',', $includeParam)))) : [];

    $db = cmsDb();

    $select = ['c.*'];
    $joins = '';
    if (in_array('author', $include, true)) {
        $joins .= " LEFT JOIN cms_users u ON u.id = c.author_id ";
        $select[] = 'u.display_name as author_name';
    }
    if (in_array('featured_image', $include, true)) {
        $joins .= " LEFT JOIN cms_media m ON m.id = c.featured_image_id ";
        $select[] = 'm.file_path as featured_image_path';
        $select[] = 'm.mime_type as featured_image_mime';
    }

    $stmt = $db->prepare(
        "SELECT " . implode(', ', $select) . " FROM cms_content c {$joins}
         WHERE c.slug = :slug AND c.type = :type AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug, ':type' => $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    if (in_array('meta', $include, true)) {
        $mStmt = $db->prepare("SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = :cid");
        $mStmt->execute([':cid' => (int)$row['id']]);
        $meta = [];
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $k = (string)($m['meta_key'] ?? '');
            if ($k === '') continue;
            $meta[$k] = $m['meta_value'];
        }
        $row['meta'] = !empty($meta) ? $meta : new stdClass();
    }

    if (in_array('rendered_html', $include, true)) {
        $row['rendered_html'] = cmsContentRenderedHtml($row);
    }

    if (isset($row['featured_image_path'])) {
        $row['featured_image_url'] = cmsResolveUploadUrl((string)$row['featured_image_path']);
    }

    echo json_encode(['ok' => true, 'data' => $row]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC PAGE HANDLERS
// ═══════════════════════════════════════════════════════════════════════

function cmsPublicHome(array $params = []): void
{
    $timingEnabled = cmsPublicRenderTimingEnabled();
    $requestStart = $timingEnabled ? microtime(true) : 0.0;
    $cmsSettings = readCmsSettings();
    $forcePostsListing = !empty($params['force_posts_listing']);

    // ── Static page homepage ──
    if (!$forcePostsListing && ($cmsSettings['homepage_type'] ?? 'posts') === 'page') {
        $pageId = (int)($cmsSettings['homepage_page_id'] ?? 0);
        if ($pageId > 0) {
            $db = cmsDb();
            $stmt = $db->prepare(
                "SELECT c.*, u.display_name as author_name
                 FROM cms_content c
                 LEFT JOIN cms_users u ON u.id = c.author_id
                 WHERE c.id = :id AND c.type = 'page' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
                 LIMIT 1"
            );
            $stmt->execute([':id' => $pageId]);
            $staticPage = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($staticPage) {
                $meta = cmsLoadContentMeta($db, (int)$staticPage['id']);
                $staticPage['meta'] = $meta;
                $renderedHtml = cmsFilterRenderedContent(cmsContentRenderedHtml($staticPage), $staticPage);
                $publicHead = cmsGetPublicHeadHtml($staticPage);
                $builderEnabled = cmsPageBuilderEnabled($meta);
                $builderSettings = cmsPageBuilderSettings($meta);
                $structuredData = cmsStructuredDataJsonLd($staticPage);

                $html = cmsPublicCanonicalRenderEntityView($staticPage, [
                    'content_type' => 'page',
                    'meta' => $meta,
                    'rendered_html' => $renderedHtml,
                    'public_head' => $publicHead,
                    'structured_data' => $structuredData,
                    'builder_enabled' => $builderEnabled,
                    'builder_page_settings' => $builderSettings,
                    'entity_view_context' => [
                        'show_header' => true,
                        'show_meta' => false,
                        'show_media' => false,
                        'bypass_shell' => $builderEnabled,
                    ],
                    'public_render_origin' => 'cms',
                    'public_route_kind' => 'front-page',
                    'public_presentation_mode' => 'canonical',
                    'template_context' => [
                        'is_front_page' => true,
                    ],
                ]);

                if ($timingEnabled) {
                    cmsPublicRenderLogTiming('cms.public.home.static_page.total', $requestStart, [
                        'page_id' => (int)($staticPage['id'] ?? 0),
                        'cache_status' => 'bypass_static_page',
                    ]);
                }
                cmsPublicRespond($html);
                return;
            }
            // Fall through to posts listing if static page not found
        }
    }

    // ── Posts listing homepage ──
    $input = cmsInput();
    $page = max(1, (int)($input['page'] ?? 1));
    $archive = trim((string)($input['archive'] ?? ''));
    $archiveKey = '';
    $archiveLabel = '';
    $archiveStart = '';
    $archiveEnd = '';
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $archive)) {
        $archiveKey = $archive;
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $archive . '-01 00:00:00');
        if ($dt instanceof DateTime) {
            $archiveStart = $dt->format('Y-m-d H:i:s');
            $archiveEnd = $dt->modify('+1 month')->format('Y-m-d H:i:s');
            $archiveLabel = date('F Y', strtotime($archiveStart));
        }
    }
    $cacheKey = 'cms:home:entity_contract_v3:page:' . $page . ':archive:' . ($archiveKey !== '' ? $archiveKey : 'all');

    // Check cache
    $cacheStageStart = $timingEnabled ? microtime(true) : 0.0;
    $cached = cmsCacheGet($cacheKey);
    if ($timingEnabled) {
        cmsPublicRenderLogTiming('cms.public.home.cache_lookup', $cacheStageStart, [
            'page' => $page,
            'archive' => $archiveKey !== '' ? $archiveKey : 'all',
            'cache_hit' => $cached !== null && isset($cached['html']),
        ]);
    }
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        if ($timingEnabled) {
            cmsPublicRenderLogTiming('cms.public.home.total', $requestStart, [
                'page' => $page,
                'archive' => $archiveKey !== '' ? $archiveKey : 'all',
                'cache_status' => 'hit',
                'post_count' => null,
            ]);
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $queryArgs = cmsFilterQueryArgs([
        'per_page' => (int)($cmsSettings['posts_per_page'] ?? 10),
        'order_by' => 'c.published_at DESC',
    ], 'post');
    $perPage = max(1, min(100, (int)($queryArgs['per_page'] ?? 10)));
    $orderBy = (string)($queryArgs['order_by'] ?? 'c.published_at DESC');
    $offset = ($page - 1) * $perPage;

    $whereSql = "c.type = 'post' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c');
    $bind = [];
    if ($archiveStart !== '' && $archiveEnd !== '') {
        $whereSql .= ' AND c.published_at >= :archive_start AND c.published_at < :archive_end';
        $bind[':archive_start'] = $archiveStart;
        $bind[':archive_end'] = $archiveEnd;
    }

    $queryStageStart = $timingEnabled ? microtime(true) : 0.0;
    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.slug, c.excerpt, c.body, c.type, c.published_at,
                c.featured_image_id, u.display_name as author_name, m.file_path as featured_image
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         LEFT JOIN cms_media m ON m.id = c.featured_image_id
         WHERE {$whereSql}
         ORDER BY {$orderBy}
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($bind);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $posts = cmsProcessPostExcerpts($posts);
    foreach ($posts as &$postRow) {
        if (!empty($postRow['featured_image'])) {
            $postRow['featured_image_url'] = cmsResolveUploadUrl((string)$postRow['featured_image']);
        }
    }
    unset($postRow);

    $totalStmt = $db->prepare(
        "SELECT COUNT(*) FROM cms_content c WHERE {$whereSql}"
    );
    $totalStmt->execute($bind);
    $total = (int)$totalStmt->fetchColumn();
    if ($timingEnabled) {
        cmsPublicRenderLogTiming('cms.public.home.db_fetch', $queryStageStart, [
            'page' => $page,
            'archive' => $archiveKey !== '' ? $archiveKey : 'all',
            'post_count' => count($posts),
            'total_posts' => $total,
            'per_page' => $perPage,
        ]);
    }

    $totalPages = max(1, (int)ceil($total / $perPage));

    $renderStageStart = $timingEnabled ? microtime(true) : 0.0;
    $baseUrl = cmsPublicCanonicalBaseUrl();
    $resultLabel = cmsEntityListResultLabel($total);
    $listTitle = $archiveLabel !== '' ? ('Archive: ' . $archiveLabel) : 'Blog';
    $listDescription = $archiveLabel !== ''
        ? ($resultLabel . ' from ' . $archiveLabel)
        : ($resultLabel . ' in Blog');
    $paginationQuery = [];
    if ($archiveKey !== '') {
        $paginationQuery['archive'] = $archiveKey;
    }
    $pagination = [
        'current' => $page,
        'total' => $totalPages,
        'prev_url' => $page > 1 ? cmsEntityListPageUrl($baseUrl . '/cms/blog', $page - 1, $paginationQuery) : '',
        'next_url' => $page < $totalPages ? cmsEntityListPageUrl($baseUrl . '/cms/blog', $page + 1, $paginationQuery) : '',
    ];
    $html = cmsPublicCanonicalRenderEntityList($posts, [
        'default_type' => 'post',
        'page_title' => $listTitle,
        'list_title' => $listTitle,
        'list_description' => $listDescription,
        'pagination' => $pagination,
        'entity_list_context' => [
            'base_list_url' => $baseUrl . '/cms/blog',
            'search_action_url' => $baseUrl . '/cms/search',
            'all_items_url' => $archiveKey !== '' ? ($baseUrl . '/cms/blog') : '',
            'result_count' => $total,
            'result_label' => $resultLabel,
            'active_filter_count' => $archiveKey !== '' ? 1 : 0,
            'search_placeholder' => 'Search published content',
            'search_button_label' => 'Search',
            'show_item_meta' => true,
            'show_item_author' => true,
            'show_item_date' => true,
            'empty_title' => 'No posts found.',
            'empty_body' => $archiveKey !== ''
                ? 'Try a different archive month or browse all posts.'
                : 'There are no published posts yet.',
            'empty_link_label' => 'Browse all posts',
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => $archiveKey !== '' ? 'archive' : 'blog-home',
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'archive_month' => $archiveKey,
        ],
    ]);
    if ($timingEnabled) {
        cmsPublicRenderLogTiming('cms.public.home.render', $renderStageStart, [
            'page' => $page,
            'archive' => $archiveKey !== '' ? $archiveKey : 'all',
            'post_count' => count($posts),
        ]);
    }

    // Cache the rendered output
    $updatedAt = !empty($posts) ? ($posts[0]['published_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
    $etag = md5($html);
    $cacheTags = ['cms:home', 'cms:type:post'];
    if ($archiveKey !== '') {
        $cacheTags[] = 'cms:archive:' . $archiveKey;
    }
    $cacheWriteStageStart = $timingEnabled ? microtime(true) : 0.0;
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], $cacheTags);
    if ($timingEnabled) {
        cmsPublicRenderLogTiming('cms.public.home.cache_store', $cacheWriteStageStart, [
            'page' => $page,
            'archive' => $archiveKey !== '' ? $archiveKey : 'all',
            'tag_count' => count($cacheTags),
        ]);
    }

    cmsSendCacheHeaders($etag, $updatedAt);
    if ($timingEnabled) {
        cmsPublicRenderLogTiming('cms.public.home.total', $requestStart, [
            'page' => $page,
            'archive' => $archiveKey !== '' ? $archiveKey : 'all',
            'cache_status' => 'miss',
            'post_count' => count($posts),
            'total_posts' => $total,
        ]);
    }
    cmsPublicRespond($html);
}

function cmsPublicArchive(array $params = []): void
{
    $params['force_posts_listing'] = true;
    cmsPublicHome($params);
}

function cmsPublicCategoryArchive(array $params = []): void
{
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsPublicRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $page = max(1, (int)(cmsInput('page', 'GET') ?: 1));
    $cacheKey = 'cms:category:entity_contract_v3:' . $slug . ':page:' . $page;

    // Check cache
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $catStmt = $db->prepare("SELECT id, name, slug, description FROM cms_categories WHERE slug = ? LIMIT 1");
    $catStmt->execute([$slug]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        http_response_code(404);
        cmsPublicRespond(cmsPublicRender('pages/404.disyl', ['page_title' => 'Category Not Found']));
        return;
    }

    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    $vis = cmsPublicVisibilitySql('c');

    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.slug, c.excerpt, c.type, c.status, c.published_at,
                u.display_name AS author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = ?
         WHERE c.type = 'post' AND c.deleted_at IS NULL AND {$vis}
         ORDER BY c.published_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute([(int)$category['id']]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $posts = cmsProcessPostExcerpts($posts);

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM cms_content c
         INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = ?
         WHERE c.type = 'post' AND c.deleted_at IS NULL AND {$vis}"
    );
    $countStmt->execute([(int)$category['id']]);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $baseUrl = cmsPublicCanonicalBaseUrl();
    $resultLabel = cmsEntityListResultLabel($total);
    $listDescription = trim((string)($category['description'] ?? ''));
    if ($listDescription === '') {
        $listDescription = $resultLabel . ' in ' . (string)($category['name'] ?? $slug);
    }
    $pagination = [
        'current' => $page,
        'total' => $totalPages,
        'prev_url' => $page > 1 ? cmsEntityListPageUrl($baseUrl . '/cms/category/' . rawurlencode($slug), $page - 1) : '',
        'next_url' => $page < $totalPages ? cmsEntityListPageUrl($baseUrl . '/cms/category/' . rawurlencode($slug), $page + 1) : '',
    ];
    $html = cmsPublicCanonicalRenderEntityList($posts, [
        'default_type' => 'post',
        'page_title' => 'Category: ' . ($category['name'] ?? $slug),
        'list_title' => 'Category: ' . ($category['name'] ?? $slug),
        'list_description' => $listDescription,
        'pagination' => $pagination,
        'entity_list_context' => [
            'base_list_url' => $baseUrl . '/cms/category/' . rawurlencode($slug),
            'search_action_url' => $baseUrl . '/cms/search',
            'all_items_url' => $baseUrl . '/cms/blog',
            'category_name' => (string)($category['name'] ?? ''),
            'result_count' => $total,
            'result_label' => $resultLabel,
            'active_filter_count' => 1,
            'search_placeholder' => 'Search published content',
            'search_button_label' => 'Search',
            'show_item_meta' => true,
            'show_item_author' => true,
            'show_item_date' => true,
            'empty_title' => 'No posts found.',
            'empty_body' => 'This category does not have any published posts yet.',
            'empty_link_label' => 'Browse all posts',
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'category',
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'archive_type' => 'category',
            'archive_name' => $category['name'],
            'archive_desc' => $category['description'] ?? '',
        ],
    ]);

    // Cache the rendered output
    $updatedAt = !empty($posts) ? ($posts[0]['published_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:category:' . $slug, 'cms:type:post']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

function cmsPublicTagArchive(array $params = []): void
{
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsPublicRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $page = max(1, (int)(cmsInput('page', 'GET') ?: 1));
    $cacheKey = 'cms:tag:entity_contract_v3:' . $slug . ':page:' . $page;

    // Check cache
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $tagStmt = $db->prepare("SELECT id, name, slug FROM cms_tags WHERE slug = ? LIMIT 1");
    $tagStmt->execute([$slug]);
    $tag = $tagStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tag) {
        http_response_code(404);
        cmsPublicRespond(cmsPublicRender('pages/404.disyl', ['page_title' => 'Tag Not Found']));
        return;
    }

    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    $vis = cmsPublicVisibilitySql('c');

    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.slug, c.excerpt, c.type, c.status, c.published_at,
                u.display_name AS author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         INNER JOIN cms_content_tags ct ON ct.content_id = c.id AND ct.tag_id = ?
         WHERE c.type = 'post' AND c.deleted_at IS NULL AND {$vis}
         ORDER BY c.published_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute([(int)$tag['id']]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $posts = cmsProcessPostExcerpts($posts);

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM cms_content c
         INNER JOIN cms_content_tags ct ON ct.content_id = c.id AND ct.tag_id = ?
         WHERE c.type = 'post' AND c.deleted_at IS NULL AND {$vis}"
    );
    $countStmt->execute([(int)$tag['id']]);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $baseUrl = cmsPublicCanonicalBaseUrl();
    $resultLabel = cmsEntityListResultLabel($total);
    $pagination = [
        'current' => $page,
        'total' => $totalPages,
        'prev_url' => $page > 1 ? cmsEntityListPageUrl($baseUrl . '/cms/tag/' . rawurlencode($slug), $page - 1) : '',
        'next_url' => $page < $totalPages ? cmsEntityListPageUrl($baseUrl . '/cms/tag/' . rawurlencode($slug), $page + 1) : '',
    ];
    $html = cmsPublicCanonicalRenderEntityList($posts, [
        'default_type' => 'post',
        'page_title' => 'Tag: ' . ($tag['name'] ?? $slug),
        'list_title' => 'Tag: ' . ($tag['name'] ?? $slug),
        'list_description' => $resultLabel . ' tagged with ' . (string)($tag['name'] ?? $slug),
        'pagination' => $pagination,
        'entity_list_context' => [
            'base_list_url' => $baseUrl . '/cms/tag/' . rawurlencode($slug),
            'search_action_url' => $baseUrl . '/cms/search',
            'all_items_url' => $baseUrl . '/cms/blog',
            'result_count' => $total,
            'result_label' => $resultLabel,
            'active_filter_count' => 1,
            'search_placeholder' => 'Search published content',
            'search_button_label' => 'Search',
            'show_item_meta' => true,
            'show_item_author' => true,
            'show_item_date' => true,
            'empty_title' => 'No posts found.',
            'empty_body' => 'This tag does not have any published posts yet.',
            'empty_link_label' => 'Browse all posts',
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'tag',
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'archive_type' => 'tag',
            'archive_name' => $tag['name'],
            'archive_desc' => '',
        ],
    ]);

    // Cache the rendered output
    $updatedAt = !empty($posts) ? ($posts[0]['published_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:tag:' . $slug, 'cms:type:post']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

// ── Tag API ──────────────────────────────────────────────────────────

function cmsPublicSearch(array $params = []): void
{
    $q = trim((string)(cmsInput('search', 'GET') ?: cmsInput('q', 'GET') ?: ''));
    $page = max(1, (int)(cmsInput('page', 'GET') ?: 1));
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    $posts = [];
    $total = 0;

    if ($q !== '') {
        $db = cmsDb();
        $vis = cmsPublicVisibilitySql('c');
        $like = '%' . $q . '%';

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM cms_content c
             WHERE c.deleted_at IS NULL AND {$vis}
             AND (c.title LIKE ? OR c.body LIKE ? OR c.excerpt LIKE ?)"
        );
        $countStmt->execute([$like, $like, $like]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.excerpt, c.type, c.status, c.published_at,
                    u.display_name AS author_name
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             WHERE c.deleted_at IS NULL AND {$vis}
             AND (c.title LIKE ? OR c.body LIKE ? OR c.excerpt LIKE ?)
             ORDER BY c.published_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute([$like, $like, $like]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $posts = cmsProcessPostExcerpts($posts);

    $totalPages = max(1, (int)ceil(max($total, 1) / $perPage));
    $baseUrl = cmsPublicCanonicalBaseUrl();
    $resultLabel = cmsEntityListResultLabel($total);
    $paginationQuery = $q !== '' ? ['search' => $q] : [];
    $pagination = [
        'current' => $page,
        'total' => $totalPages,
        'prev_url' => $page > 1 ? cmsEntityListPageUrl($baseUrl . '/cms/search', $page - 1, $paginationQuery) : '',
        'next_url' => $page < $totalPages ? cmsEntityListPageUrl($baseUrl . '/cms/search', $page + 1, $paginationQuery) : '',
    ];

    $html = cmsPublicCanonicalRenderEntityList($posts, [
        'default_type' => 'post',
        'page_title' => $q !== '' ? ('Search: ' . $q) : 'Search',
        'list_title' => $q !== '' ? ('Search: ' . $q) : 'Search',
        'list_description' => $q !== ''
            ? ($resultLabel . ' for "' . $q . '"')
            : 'Search across published content.',
        'pagination' => $pagination,
        'entity_list_context' => [
            'base_list_url' => $baseUrl . '/cms/search',
            'search_action_url' => $baseUrl . '/cms/search',
            'search' => $q,
            'all_items_url' => $baseUrl . '/cms/blog',
            'result_count' => $total,
            'result_label' => $resultLabel,
            'active_filter_count' => $q !== '' ? 1 : 0,
            'search_placeholder' => 'Search posts, pages, and more',
            'search_button_label' => 'Search',
            'show_item_meta' => true,
            'show_item_author' => true,
            'show_item_date' => true,
            'show_item_type_badge' => true,
            'empty_title' => $q !== '' ? 'No results found.' : 'Enter a search term.',
            'empty_body' => $q !== ''
                ? 'Try a broader term or browse the blog instead.'
                : 'Use the search field above to find published content.',
            'empty_link_label' => 'Browse the blog',
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'search',
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'query' => $q,
            'total' => $total,
        ],
    ]);

    cmsPublicRespond($html);
}

function cmsPublicSitemapXml(array $params = []): void
{
    header('Content-Type: application/xml; charset=UTF-8');

    $cacheKey = 'cms:sitemap';
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['xml'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['xml']);
        return;
    }

    $xml = cmsBuildSitemapXml();

    $updatedAt = date('Y-m-d H:i:s');
    try {
        $row = cmsDb()->query(
            "SELECT COALESCE(MAX(updated_at), MAX(published_at), NOW()) as lastmod\n             FROM cms_content\n             WHERE deleted_at IS NULL AND " . cmsPublicVisibilitySql('cms_content')
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['lastmod'])) {
            $updatedAt = (string)$row['lastmod'];
        }
    } catch (Throwable $e) {}

    $etag = md5($xml);
    cmsCacheSet($cacheKey, [
        'xml'        => $xml,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:sitemap']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($xml);
}

function cmsPublicListItemPrimaryImageUrl(array $item): string
{
    $featuredImageUrl = trim((string)($item['featured_image_url'] ?? ''));
    if ($featuredImageUrl !== '') {
        return $featuredImageUrl;
    }

    $capabilityData = $item['capability_data'] ?? [];
    $mediaGallery = is_array($capabilityData) ? ($capabilityData['media_gallery'] ?? null) : null;
    $galleryItems = is_array($mediaGallery) ? ($mediaGallery['items'] ?? null) : null;

    if (is_array($galleryItems)) {
        foreach ($galleryItems as $galleryItem) {
            if (!is_array($galleryItem)) {
                continue;
            }

            foreach (['thumb', 'url', 'src'] as $key) {
                $candidate = trim((string)($galleryItem[$key] ?? ''));
                if ($candidate === '') {
                    continue;
                }

                if (preg_match('#^(https?:)?//#i', $candidate) === 1 || str_starts_with($candidate, '/')) {
                    return $candidate;
                }

                return cmsResolveUploadUrl($candidate);
            }
        }
    }

    if ((string)($item['type'] ?? '') === 'product') {
        return '/assets/ecommerce/product-placeholder.svg';
    }

    return '';
}

// ── RSS Feed ─────────────────────────────────────────────────────────

function cmsPublicRssFeed(array $params = []): void
{
    header('Content-Type: application/rss+xml; charset=UTF-8');

    $cacheKey = 'cms:rss_feed';
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['xml'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['xml']);
        return;
    }

    $xml = cmsBuildRssFeedXml();

    $updatedAt = date('Y-m-d H:i:s');
    try {
        $row = cmsDb()->query(
            "SELECT COALESCE(MAX(published_at), NOW()) as lastmod
             FROM cms_content
             WHERE deleted_at IS NULL AND type = 'post' AND " . cmsPublicVisibilitySql('cms_content')
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['lastmod'])) {
            $updatedAt = (string)$row['lastmod'];
        }
    } catch (Throwable $e) {}

    $etag = md5($xml);
    cmsCacheSet($cacheKey, [
        'xml'        => $xml,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:feed']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($xml);
}

function cmsPublicSingle(array $params = []): void
{
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $cacheKey = 'cms:post:entity_contract_v3:' . $slug;

    // Check cache
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name, m.file_path as featured_image
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         LEFT JOIN cms_media m ON m.id = c.featured_image_id
         WHERE c.slug = :slug AND c.type = 'post' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        // Check for slug redirect (old slug → new slug)
        $redirect = cmsLookupSlugRedirect($slug);
        if ($redirect && ($redirect['type'] ?? '') === 'post') {
            $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
            header('Location: ' . $baseUrl . '/cms/blog/' . $redirect['slug'], true, 301);
            exit;
        }
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $meta = cmsLoadContentMeta($db, (int)$post['id']);

    $post['meta'] = $meta;
    if (!empty($post['featured_image'])) {
        $post['featured_image_url'] = cmsResolveUploadUrl((string)$post['featured_image']);
    }

    $renderedHtml = cmsFilterRenderedContent(cmsContentRenderedHtml($post), $post);
    $publicHead = cmsGetPublicHeadHtml($post);
    $builderEnabled = cmsPageBuilderEnabled($meta);
    $builderSettings = cmsPageBuilderSettings($meta);

    $structuredData = cmsStructuredDataJsonLd($post);

    $html = cmsPublicCanonicalRenderEntityView($post, [
        'content_type' => 'post',
        'meta' => $meta,
        'rendered_html' => $renderedHtml,
        'public_head' => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'entity_back_link_url' => cmsPublicCanonicalBaseUrl() . '/cms/blog',
        'entity_back_link_label' => 'Back to blog',
        'entity_view_context' => [
            'show_header' => true,
            'show_meta' => true,
            'show_media' => true,
            'bypass_shell' => $builderEnabled,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'post',
        'public_presentation_mode' => 'canonical',
    ]);

    // Cache the rendered output
    $updatedAt = (string)($post['updated_at'] ?? $post['published_at'] ?? date('Y-m-d H:i:s'));
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:post:' . $slug, 'cms:content:' . (int)$post['id'], 'cms:type:post']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

function cmsPublicPage(array $params = []): void
{
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $ecommercePages = [
        'shop' => 'ecommerce:ecPublicShop',
        'cart' => 'ecommerce:ecPublicCart',
        'checkout' => 'ecommerce:ecPublicCheckout',
        'my-orders' => 'ecommerce:ecPublicMyOrders',
    ];
    if (isset($ecommercePages[$slug]) && function_exists('executeModuleHandler')) {
        executeModuleHandler($ecommercePages[$slug]);
        return;
    }

    $cacheKey = 'cms:page:entity_contract_v3:' . $slug;

    // Check cache
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.slug = :slug AND c.type = 'page' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page) {
        // Check for slug redirect (old slug → new slug)
        $redirect = cmsLookupSlugRedirect($slug);
        if ($redirect && ($redirect['type'] ?? '') === 'page') {
            $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
            header('Location: ' . $baseUrl . '/cms/page/' . $redirect['slug'], true, 301);
            exit;
        }
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $meta = cmsLoadContentMeta($db, (int)$page['id']);
    $page['meta'] = $meta;

    $renderedHtml = cmsFilterRenderedContent(cmsContentRenderedHtml($page), $page);
    $publicHead = cmsGetPublicHeadHtml($page);
    $builderEnabled = cmsPageBuilderEnabled($meta);
    $builderSettings = cmsPageBuilderSettings($meta);

    $structuredData = cmsStructuredDataJsonLd($page);

    $html = cmsPublicCanonicalRenderEntityView($page, [
        'content_type' => 'page',
        'meta' => $meta,
        'rendered_html' => $renderedHtml,
        'public_head' => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'entity_view_context' => [
            'show_header' => true,
            'show_meta' => false,
            'show_media' => false,
            'bypass_shell' => $builderEnabled,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'canonical',
    ]);

    // Cache the rendered output
    $updatedAt = (string)($page['updated_at'] ?? $page['published_at'] ?? date('Y-m-d H:i:s'));
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:page:' . $slug, 'cms:content:' . (int)$page['id'], 'cms:type:page']);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

function cmsPublicEntityBook(array $params = []): void
{
    cmsPublicRenderEntityActionPage($params, 'book');
}

function cmsPublicEntityInquiry(array $params = []): void
{
    cmsPublicRenderEntityActionPage($params, 'inquiry');
}

// ═══════════════════════════════════════════════════════════════════════
// ENTITY VIEW / LIST (custom content types)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Public single entity view for custom content types (not post/page).
 * Route: /cms/{type}/{slug}
 */
function cmsPublicEntityView(array $params = []): void
{
    $type = trim((string)($params['type'] ?? ''));
    $slug = trim((string)($params['slug'] ?? ''));

    // Reserved prefixes handled by dedicated routes
    if ($type === '' || $slug === '' || in_array($type, ['blog', 'page', 'admin', 'search', 'category', 'tag', 'feed'], true)) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $cacheKey = 'cms:entity:' . $type . ':' . $slug;

    // Check cache
    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $entity = cmsPublicLoadVisibleEntityByTypeSlug($db, $type, $slug);

    if (!$entity) {
        // Check for slug redirect
        $redirect = cmsLookupSlugRedirect($slug);
        if ($redirect && ($redirect['type'] ?? '') === $type) {
            $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
            header('Location: ' . $baseUrl . '/cms/' . rawurlencode($type) . '/' . rawurlencode($redirect['slug']), true, 301);
            exit;
        }
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $meta = cmsLoadContentMeta($db, (int)$entity['id']);
    $entity['meta'] = $meta;

    $renderedHtml = cmsFilterRenderedContent(cmsContentRenderedHtml($entity), $entity);
    $publicHead = cmsGetPublicHeadHtml($entity);
    $builderEnabled = cmsPageBuilderEnabled($meta);
    $builderSettings = cmsPageBuilderSettings($meta);
    $structuredData = cmsStructuredDataJsonLd($entity);

    $html = cmsPublicCanonicalRenderEntityView($entity, [
        'content_type' => $type,
        'meta' => $meta,
        'rendered_html' => $renderedHtml,
        'public_head' => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'public_render_origin' => (string)($params['public_render_origin'] ?? 'cms'),
        'public_route_kind' => (string)($params['public_route_kind'] ?? 'generic'),
        'public_presentation_mode' => (string)($params['public_presentation_mode'] ?? 'canonical'),
    ]);

    $updatedAt = (string)($entity['updated_at'] ?? $entity['published_at'] ?? date('Y-m-d H:i:s'));
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:entity:' . $type . ':' . $slug, 'cms:content:' . (int)$entity['id'], 'cms:type:' . $type]);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

function cmsEntityListPageUrl(string $baseUrl, int $page, array $query = []): string
{
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
        $baseUrl = '/';
    }

    $query = array_filter($query, static function ($value): bool {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return true;
    });

    $query['page'] = max(1, $page);
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    return $baseUrl . $separator . http_build_query($query);
}

function cmsEntityListResultLabel(int $count): string
{
    return number_format(max(0, $count)) . (max(0, $count) === 1 ? ' result' : ' results');
}

function cmsPublicEntityListItemBlockContext(array $item, array $pageContext, string $defaultType): array
{
    $itemContext = $pageContext;
    $itemContext['entity'] = $item;
    $itemContext['content_type'] = trim((string)($item['type'] ?? $defaultType));
    $itemContext['capabilities'] = is_array($item['capabilities'] ?? null) ? $item['capabilities'] : [];
    $itemContext['capability_data'] = is_array($item['capability_data'] ?? null) ? $item['capability_data'] : [];
    return $itemContext;
}

function cmsEntityListCardExcerpt(string $excerpt, int $length): string
{
    $excerpt = trim($excerpt);
    if ($excerpt === '') {
        return '';
    }

    $length = max(40, min(220, $length));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($excerpt, 0, $length, '...');
    }

    if (strlen($excerpt) <= $length) {
        return $excerpt;
    }

    return rtrim(substr($excerpt, 0, max(0, $length - 3))) . '...';
}

/**
 * Public entity listing for custom content types.
 * Route: /cms/{type}
 */
function cmsPublicEntityList(array $params = []): void
{
    $type = trim((string)($params['type'] ?? ''));

    // Reserved prefixes handled by dedicated routes
    if ($type === '' || in_array($type, ['blog', 'page', 'admin', 'search', 'category', 'tag', 'feed', 'sitemap.xml'], true)) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    // ── Optional caller overrides ────────────────────────────────────
    $perPageOverride = isset($params['per_page']) ? (int)$params['per_page'] : 0;
    $categoryId      = isset($params['category_id']) ? (int)$params['category_id'] : 0;
    $categorySlug    = trim((string)($params['category_slug'] ?? ''));
    $searchOverride  = array_key_exists('search', $params) ? trim((string)$params['search']) : null;
    $baseListUrl     = trim((string)($params['base_list_url'] ?? ''));
    $itemBaseUrl     = trim((string)($params['item_base_url'] ?? ''));
    $requestedListTitle = trim((string)($params['list_title'] ?? ''));
    $searchActionUrl = trim((string)($params['search_action_url'] ?? ''));
    $allItemsUrl = trim((string)($params['all_items_url'] ?? ''));
    $availableCategoriesRaw = is_array($params['available_categories'] ?? null)
        ? $params['available_categories']
        : [];

    $availableCategories = [];
    foreach ($availableCategoriesRaw as $category) {
        if (!is_array($category)) {
            continue;
        }

        $resolvedCategoryId = (int)($category['id'] ?? 0);
        $resolvedCategoryName = trim((string)($category['name'] ?? ''));
        if ($resolvedCategoryId <= 0 || $resolvedCategoryName === '') {
            continue;
        }

        $availableCategories[] = [
            'id' => $resolvedCategoryId,
            'slug' => trim((string)($category['slug'] ?? '')),
            'name' => $resolvedCategoryName,
            'url' => trim((string)($category['url'] ?? '')),
            'is_active' => (bool)($category['is_active'] ?? false),
        ];
    }

    $input   = cmsInput();
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = $perPageOverride > 0 && $perPageOverride <= 100 ? $perPageOverride : 12;
    $offset  = ($page - 1) * $perPage;
    $search  = $searchOverride !== null ? $searchOverride : trim((string)($input['search'] ?? ''));

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    // Resolve category_id from slug if only slug provided
    if ($categorySlug !== '' && $categoryId === 0) {
        try {
            $row = cmsDb()->query("SELECT id FROM cms_categories WHERE slug = ? LIMIT 1", [$categorySlug])->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $categoryId = (int)$row['id'];
            }
        } catch (\Throwable $e) {}
    }

    $publicRenderOrigin = (string)($params['public_render_origin'] ?? 'cms');
    $publicRouteKind = (string)($params['public_route_kind'] ?? 'generic');
    $publicPresentationMode = (string)($params['public_presentation_mode'] ?? 'traditional');
    $entityRenderContext = [
        'content_type' => $type,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ];

    $templatePath = cmsResolveContentTemplate('public/entity.list.disyl', [], $type, $entityRenderContext);
    $templateAbsolutePath = BASE_PATH . '/templates/' . ltrim($templatePath, '/');
    $templateVersion = md5($templatePath . '|' . @filemtime($templateAbsolutePath));
    $renderContextVersion = md5((string)json_encode([
        'base_list_url' => $baseListUrl,
        'item_base_url' => $itemBaseUrl,
        'list_title' => $requestedListTitle,
        'search_action_url' => $searchActionUrl,
        'all_items_url' => $allItemsUrl,
        'available_categories' => $availableCategories,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ]));

    $cacheKey = 'cms:entity_list:' . $type . ':p' . $page
        . ($perPage !== 12 ? ':pp' . $perPage : '')
        . ($categoryId > 0 ? ':cat' . $categoryId : '')
        . ($search !== '' ? ':s' . md5($search) : '')
        . ':tpl:' . $templateVersion
        . ':ctx:' . $renderContextVersion;

    $cached = cmsCacheGet($cacheKey);
    if ($cached !== null && isset($cached['html'])) {
        if (!empty($cached['etag']) && !empty($cached['updated_at'])) {
            if (cmsSendCacheHeaders($cached['etag'], $cached['updated_at'])) {
                exit;
            }
        }
        cmsPublicRespond((string)$cached['html']);
        return;
    }

    $db = cmsDb();
    $listTitle = '';
    $listDescription = '';
    $activeCategory = null;

    try {
        $typeStmt = $db->prepare("SELECT label FROM cms_content_types WHERE slug = :slug AND is_active = 1 LIMIT 1");
        $typeStmt->execute([':slug' => $type]);
        $typeName = $typeStmt->fetchColumn();
        if (!is_string($typeName) || trim($typeName) === '') {
            http_response_code(404);
            cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
            return;
        }
        $listTitle = trim($typeName);
        if ($requestedListTitle !== '') {
            $listTitle = $requestedListTitle;
        }
    } catch (\Throwable $e) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    if ($categoryId > 0) {
        try {
            $categoryStmt = $db->prepare('SELECT id, name, slug FROM cms_categories WHERE id = :id LIMIT 1');
            $categoryStmt->execute([':id' => $categoryId]);
            $activeCategory = $categoryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $activeCategory = null;
        }
    }

    // ── Build dynamic SQL fragments ──────────────────────────────────
    $categoryJoin   = '';
    $bindParams     = [':type' => $type];

    if ($categoryId > 0) {
        $categoryJoin = " INNER JOIN cms_content_categories ccc ON ccc.content_id = c.id AND ccc.category_id = :cat_id";
        $bindParams[':cat_id'] = $categoryId;
    }

    $searchClause = '';
    if ($search !== '') {
        $searchClause = " AND c.title LIKE :search";
        $bindParams[':search'] = '%' . $search . '%';
    }

    $visibilityWhere = cmsPublicVisibilitySql('c');

    // Get total count
    $cStmt = $db->prepare(
        "SELECT COUNT(*) FROM cms_content c{$categoryJoin}"
        . " WHERE c.deleted_at IS NULL AND c.type = :type AND {$visibilityWhere}{$searchClause}"
    );
    $cStmt->execute($bindParams);
    $total = (int)$cStmt->fetchColumn();

    $resultLabel = cmsEntityListResultLabel($total);
    $activeFilterCount = 0;
    if ($search !== '') {
        $activeFilterCount++;
    }
    if (is_array($activeCategory)) {
        $activeFilterCount++;
        if ($requestedListTitle === '') {
            $listTitle = trim((string)($activeCategory['name'] ?? '')) ?: $listTitle;
        }
    }

    if ($search !== '' && is_array($activeCategory)) {
        $listDescription = $resultLabel . ' in ' . (string)($activeCategory['name'] ?? $listTitle) . ' for "' . $search . '"';
    } elseif ($search !== '') {
        $listDescription = $resultLabel . ' for "' . $search . '"';
    } elseif (is_array($activeCategory)) {
        $listDescription = $resultLabel . ' in ' . (string)($activeCategory['name'] ?? $listTitle);
    } else {
        $listDescription = $resultLabel . ' in ' . $listTitle;
    }

    // Fetch items
    $stmt = $db->prepare(
        "SELECT c.id, c.uuid, c.title, c.slug, c.excerpt, c.type, c.status, c.published_at,
            c.author_id, c.featured_image_id, u.display_name as author_name, m.file_path as featured_image,
            ct.label as content_type_label
         FROM cms_content c{$categoryJoin}
         LEFT JOIN cms_users u ON u.id = c.author_id
         LEFT JOIN cms_media m ON m.id = c.featured_image_id
         LEFT JOIN cms_content_types ct ON ct.slug = c.type
         WHERE c.deleted_at IS NULL AND c.type = :type AND {$visibilityWhere}{$searchClause}
         ORDER BY c.published_at DESC, c.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($bindParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Enrich each item with capabilities, capability_data, and url
    $items = [];
    foreach ($rows as $row) {
        $entityId = (int)($row['id'] ?? 0);
        $itemSlug = rawurlencode((string)($row['slug'] ?? ''));
        if ($itemBaseUrl !== '') {
            $row['url'] = rtrim($itemBaseUrl, '/') . '/' . $itemSlug;
        } else {
            $row['url'] = $baseUrl . '/cms/' . rawurlencode($type) . '/' . $itemSlug;
        }
        try {
            $row['capabilities']    = $entityId > 0 ? cmsEntityCapabilityContext($entityId) : [];
            $row['capability_data'] = $entityId > 0 ? cmsEntityCapabilityData($entityId, $row) : [];
        } catch (\Throwable $e) {
            $row['capabilities']    = [];
            $row['capability_data'] = [];
        }
        if (!empty($row['featured_image'])) {
            $row['featured_image_url'] = cmsResolveUploadUrl((string)$row['featured_image']);
        }
        $row['primary_image_url'] = cmsPublicListItemPrimaryImageUrl($row);
        $items[] = $row;
    }

    // Pagination
    $totalPages  = max(1, (int)ceil($total / $perPage));
    $listBase    = $baseListUrl !== '' ? $baseListUrl : ($baseUrl . '/cms/' . rawurlencode($type));
    $paginationQuery = [];
    if ($search !== '') {
        $paginationQuery['search'] = $search;
    }
    if ($categoryId > 0 && $categorySlug === '') {
        $paginationQuery['cat'] = $categoryId;
    }
    $pagination = [
        'current'  => $page,
        'total'    => $totalPages,
        'prev_url' => $page > 1 ? cmsEntityListPageUrl($listBase, $page - 1, $paginationQuery) : '',
        'next_url' => $page < $totalPages ? cmsEntityListPageUrl($listBase, $page + 1, $paginationQuery) : '',
    ];

    $listContext = [
        'content_type' => $type,
        'base_list_url' => $listBase,
        'item_base_url' => $itemBaseUrl,
        'search' => $search,
        'category_id' => $categoryId,
        'category_slug' => $categorySlug !== '' ? $categorySlug : (string)($activeCategory['slug'] ?? ''),
        'category_name' => is_array($activeCategory) ? (string)($activeCategory['name'] ?? '') : '',
        'result_count' => $total,
        'result_label' => $resultLabel,
        'active_filter_count' => $activeFilterCount,
        'summary_text' => $listDescription,
        'available_categories' => $availableCategories,
        'all_items_url' => $allItemsUrl,
        'search_action_url' => $searchActionUrl,
    ];

    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'entity-list');
    $pageContext = cmsPublicContext([
        'page_title'       => $listTitle,
        'list_title'       => $listTitle,
        'list_description' => $listDescription,
        'pagination'       => $pagination,
        'entity_list_context' => $listContext,
        'content_type'     => $type,
        'sidebar_template' => $sidebarTemplateKey,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ]);
    $pageContext['entity_render_family'] = cmsCanonicalEntityRenderFamily(array_merge($entityRenderContext, ['items' => $items]));
    $pageContext['entity_presentation'] = cmsCanonicalEntityPresentationConfig(
        is_array($pageContext['entity_presentation_settings'] ?? null)
            ? $pageContext['entity_presentation_settings']
            : (is_array($pageContext['theme_settings'] ?? null) ? $pageContext['theme_settings'] : []),
        array_merge($entityRenderContext, ['items' => $items])
    );

    foreach ($items as &$item) {
        $itemContext = cmsPublicEntityListItemBlockContext($item, $pageContext, $type);
        $capabilities = is_array($itemContext['capabilities'] ?? null) ? $itemContext['capabilities'] : [];
        $presentation = is_array($pageContext['entity_presentation'] ?? null) ? $pageContext['entity_presentation'] : [];
        $item['list_card_excerpt'] = !empty($presentation['list_show_excerpt'])
            ? cmsEntityListCardExcerpt((string)($item['excerpt'] ?? ''), (int)($presentation['list_excerpt_length'] ?? 120))
            : '';

        $item['list_card_pricing_html'] = !empty($capabilities['pricing'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', $itemContext)
            : '';
        $item['list_card_inventory_html'] = !empty($capabilities['inventory'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-inventory.block.disyl', $itemContext)
            : '';
        $item['list_card_progress_html'] = !empty($capabilities['progress_tracking'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-progress.block.disyl', $itemContext)
            : '';
        $item['list_card_action_html'] = (!empty($capabilities['pricing']) && !empty($pageContext['cart_enabled']))
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-action.block.disyl', $itemContext)
            : '';
    }
    unset($item);

    $pageContext['items'] = $items;
    $html = cmsRenderThemeAwareTemplate($templatePath, $pageContext);

    $updatedAt = date('Y-m-d H:i:s');
    $etag = md5($html);
    cmsCacheSet($cacheKey, [
        'html'       => $html,
        'etag'       => $etag,
        'updated_at' => $updatedAt,
    ], ['cms:type:' . $type]);

    cmsSendCacheHeaders($etag, $updatedAt);
    cmsPublicRespond($html);
}

// ═══════════════════════════════════════════════════════════════════════
// INTERNAL HELPERS
// ═══════════════════════════════════════════════════════════════════════

function cmsPublicLoadVisibleEntityByTypeSlug(object $db, string $type, string $slug): ?array
{
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name, m.file_path as featured_image, ct.label as content_type_label
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         LEFT JOIN cms_media m ON m.id = c.featured_image_id
         LEFT JOIN cms_content_types ct ON ct.slug = c.type
         WHERE c.slug = :slug AND c.type = :type AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug, ':type' => $type]);
    $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($entity) || $entity === []) {
        return null;
    }

    if (!empty($entity['featured_image'])) {
        $entity['featured_image_url'] = cmsResolveUploadUrl((string)$entity['featured_image']);
    }

    return $entity;
}

function cmsPublicRenderEntityActionPage(array $params, string $actionType): void
{
    $type = trim((string)($params['type'] ?? ''));
    $slug = trim((string)($params['slug'] ?? ''));
    if ($type === '' || $slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $db = cmsDb();
    $entity = cmsPublicLoadVisibleEntityByTypeSlug($db, $type, $slug);
    if ($entity === null) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $requiredCapability = $actionType === 'book' ? 'booking' : 'inquiry';
    $capabilities = cmsEntityCapabilityContext((int)$entity['id']);
    if (empty($capabilities[$requiredCapability])) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $meta = cmsLoadContentMeta($db, (int)$entity['id']);
    $entity['meta'] = $meta;

    $entityTitle = trim((string)($entity['title'] ?? ''));
    $pageTitle = $actionType === 'book'
        ? 'Book ' . $entityTitle
        : 'Inquire About ' . $entityTitle;
    $blockTemplate = $actionType === 'book'
        ? 'modules/cms/public/blocks/entity-book-form.block.disyl'
        : 'modules/cms/public/blocks/entity-inquiry-form.block.disyl';

    $renderedHtml = cmsRenderThemeAwareBlockTemplate($blockTemplate, [
        'entity' => $entity,
        'content_type' => $type,
        'base_url' => cmsPublicCanonicalBaseUrl(),
        'entity_action_target_title' => $entityTitle,
    ]);

    $html = cmsPublicCanonicalRenderEntityView($entity, [
        'content_type' => $type,
        'meta' => $meta,
        'rendered_html' => $renderedHtml,
        'public_head' => cmsGetPublicHeadHtml($entity),
        'structured_data' => cmsStructuredDataJsonLd($entity),
        'entity_back_link_url' => cmsPublicCanonicalEntityUrl($entity, $type),
        'entity_back_link_label' => 'Back to ' . $entityTitle,
        'entity_view_context' => [
            'header_title' => $pageTitle,
            'show_header' => true,
            'show_meta' => false,
            'show_media' => false,
            'show_summary' => false,
            'show_lessons' => false,
            'show_taxonomies' => false,
            'show_back_link' => true,
        ],
        'public_render_origin' => (string)($params['public_render_origin'] ?? 'cms'),
        'public_route_kind' => $actionType,
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'page_title' => $pageTitle,
        ],
    ]);

    cmsPublicRespond($html);
}

function cmsPublicCanonicalBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function cmsPublicCanonicalContentTypeLabel(string $type, array $entity = []): string
{
    $label = trim((string)($entity['content_type_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return match ($type) {
        'post' => 'Post',
        'page' => 'Page',
        default => ucwords(str_replace(['-', '_'], ' ', trim($type))),
    };
}

function cmsPublicCanonicalEntityUrl(array $entity, string $defaultType = ''): string
{
    $type = trim((string)($entity['type'] ?? $defaultType));
    $slug = trim((string)($entity['slug'] ?? ''));
    $baseUrl = cmsPublicCanonicalBaseUrl();

    if ($slug === '') {
        return $baseUrl . '/';
    }

    return match ($type) {
        'post' => $baseUrl . '/cms/blog/' . rawurlencode($slug),
        'page' => $baseUrl . '/cms/page/' . rawurlencode($slug),
        default => $baseUrl . '/cms/' . rawurlencode($type) . '/' . rawurlencode($slug),
    };
}

function cmsPublicCanonicalPublishedAtDisplay(string $publishedAt): string
{
    $publishedAt = trim($publishedAt);
    if ($publishedAt === '') {
        return '';
    }

    $timestamp = strtotime($publishedAt);
    if ($timestamp === false) {
        return $publishedAt;
    }

    return date('M j, Y', $timestamp);
}

function cmsPublicCanonicalEntityTaxonomies(int $contentId, string $type): array
{
    if ($contentId <= 0 || $type !== 'post') {
        return ['categories' => [], 'tags' => []];
    }

    $baseUrl = cmsPublicCanonicalBaseUrl();
    $categories = [];
    foreach (cmsGetContentCategories($contentId) as $category) {
        if (!is_array($category)) {
            continue;
        }

        $slug = trim((string)($category['slug'] ?? ''));
        $categories[] = [
            'id' => (int)($category['id'] ?? 0),
            'name' => trim((string)($category['name'] ?? '')),
            'slug' => $slug,
            'url' => $slug !== '' ? ($baseUrl . '/cms/category/' . rawurlencode($slug)) : '',
        ];
    }

    $tags = [];
    try {
        $stmt = cmsDb()->prepare(
            "SELECT t.name, t.slug
             FROM cms_tags t
             INNER JOIN cms_content_tags ct ON ct.tag_id = t.id
             WHERE ct.content_id = :content_id
             ORDER BY t.name ASC"
        );
        $stmt->execute([':content_id' => $contentId]);
        $tagRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tagRows as $tagRow) {
            $slug = trim((string)($tagRow['slug'] ?? ''));
            $tags[] = [
                'name' => trim((string)($tagRow['name'] ?? '')),
                'slug' => $slug,
                'url' => $slug !== '' ? ($baseUrl . '/cms/tag/' . rawurlencode($slug)) : '',
            ];
        }
    } catch (Throwable $e) {
        foreach (cmsGetContentTagNames($contentId) as $tagName) {
            $tagName = trim((string)$tagName);
            if ($tagName === '') {
                continue;
            }

            $tags[] = [
                'name' => $tagName,
                'slug' => '',
                'url' => '',
            ];
        }
    }

    return [
        'categories' => $categories,
        'tags' => $tags,
    ];
}

function cmsPublicCanonicalRenderEntityView(array $entity, array $options = []): string
{
    $type = trim((string)($options['content_type'] ?? $entity['type'] ?? ''));
    $entityId = (int)($entity['id'] ?? 0);
    $publicRenderOrigin = (string)($options['public_render_origin'] ?? 'cms');
    $publicRouteKind = (string)($options['public_route_kind'] ?? 'generic');
    $publicPresentationMode = (string)($options['public_presentation_mode'] ?? 'canonical');
    $meta = is_array($options['meta'] ?? null)
        ? $options['meta']
        : (is_array($entity['meta'] ?? null) ? $entity['meta'] : []);

    $entity['type'] = $type;
    $entity['meta'] = $meta;
    $entity['content_type_label'] = cmsPublicCanonicalContentTypeLabel($type, $entity);
    if (empty($entity['featured_image_url']) && !empty($entity['featured_image'])) {
        $entity['featured_image_url'] = cmsResolveUploadUrl((string)$entity['featured_image']);
    }
    if (!empty($entity['published_at']) && empty($entity['published_at_display'])) {
        $entity['published_at_display'] = cmsPublicCanonicalPublishedAtDisplay((string)$entity['published_at']);
    }

    $renderedHtml = array_key_exists('rendered_html', $options)
        ? (string)$options['rendered_html']
        : cmsFilterRenderedContent(cmsContentRenderedHtml($entity), $entity);
    $publicHead = array_key_exists('public_head', $options)
        ? (string)$options['public_head']
        : cmsGetPublicHeadHtml($entity);
    $structuredData = array_key_exists('structured_data', $options)
        ? (string)$options['structured_data']
        : cmsStructuredDataJsonLd($entity);
    $builderEnabled = array_key_exists('builder_enabled', $options)
        ? (bool)$options['builder_enabled']
        : cmsPageBuilderEnabled($meta);
    $builderSettings = is_array($options['builder_page_settings'] ?? null)
        ? $options['builder_page_settings']
        : cmsPageBuilderSettings($meta);

    $viewSettings = [
        'show_header' => true,
        'show_meta' => $type !== 'page',
        'show_media' => $type !== 'page',
        'bypass_shell' => $builderEnabled && in_array($type, ['post', 'page'], true),
    ];
    if (is_array($options['entity_view_context'] ?? null)) {
        $viewSettings = array_merge($viewSettings, $options['entity_view_context']);
    }

    $entityTaxonomies = is_array($options['entity_taxonomies'] ?? null)
        ? $options['entity_taxonomies']
        : cmsPublicCanonicalEntityTaxonomies($entityId, $type);
    $hasEntityCategories = !empty($entityTaxonomies['categories']) && is_array($entityTaxonomies['categories']);
    $hasEntityTags = !empty($entityTaxonomies['tags']) && is_array($entityTaxonomies['tags']);

    $entityRenderContext = [
        'content_type' => $type,
        'entity' => $entity,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
        'url' => (string)($entity['url'] ?? ''),
    ];

    $templatePath = cmsResolveContentTemplate('public/entity.view.disyl', $meta, $type, $entityRenderContext);
    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'entity-view');
    $templateContext = is_array($options['template_context'] ?? null) ? $options['template_context'] : [];

    $viewContext = cmsPublicContext(array_merge([
        'page_title' => (string)($entity['title'] ?? ''),
        'entity' => $entity,
        'entity_meta' => $meta,
        'entity_view_context' => $viewSettings,
        'entity_taxonomies' => $entityTaxonomies,
        'entity_taxonomies_has_categories' => $hasEntityCategories,
        'entity_taxonomies_has_tags' => $hasEntityTags,
        'entity_back_link_url' => (string)($options['entity_back_link_url'] ?? ''),
        'entity_back_link_label' => (string)($options['entity_back_link_label'] ?? 'Back'),
        'post' => $entity,
        'post_meta' => $meta,
        'content' => $entity,
        'content_meta' => $meta,
        'post_html' => $renderedHtml,
        'content_html' => $renderedHtml,
        'cms_head' => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'sidebar_template' => $sidebarTemplateKey,
        'content_type' => $type,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ], $templateContext));

    $viewContext['entity_render_family'] = cmsCanonicalEntityRenderFamily(array_merge($entityRenderContext, [
        'capabilities' => is_array($viewContext['capabilities'] ?? null) ? $viewContext['capabilities'] : [],
    ]));
    $viewContext['entity_presentation'] = cmsCanonicalEntityPresentationConfig(
        is_array($viewContext['entity_presentation_settings'] ?? null)
            ? $viewContext['entity_presentation_settings']
            : (is_array($viewContext['theme_settings'] ?? null) ? $viewContext['theme_settings'] : []),
        array_merge($entityRenderContext, [
            'capabilities' => is_array($viewContext['capabilities'] ?? null) ? $viewContext['capabilities'] : [],
        ])
    );
    $viewContext['show_entity_categories'] = $hasEntityCategories
        && !empty($viewContext['entity_presentation_settings']['single_show_categories']);
    $viewContext['show_entity_tags'] = $hasEntityTags
        && !empty($viewContext['entity_presentation_settings']['single_show_tags']);

    $capabilities = is_array($viewContext['capabilities'] ?? null) ? $viewContext['capabilities'] : [];
    if (!empty($capabilities['pricing'])) {
        $viewContext['pricing_block_html'] = cmsRenderThemeAwareBlockTemplate(
            'modules/cms/public/blocks/pricing.block.disyl',
            $viewContext
        );
    }

    if (!empty($capabilities['pricing']) || !empty($capabilities['booking']) || !empty($capabilities['inquiry'])) {
        $viewContext['action_block_html'] = cmsRenderThemeAwareBlockTemplate(
            'modules/cms/public/blocks/action.block.disyl',
            $viewContext
        );
    }

    return cmsRenderThemeAwareTemplate($templatePath, $viewContext);
}

function cmsPublicCanonicalRenderEntityList(array $items, array $options = []): string
{
    $defaultType = trim((string)($options['default_type'] ?? $options['content_type'] ?? 'post'));
    $publicRenderOrigin = (string)($options['public_render_origin'] ?? 'cms');
    $publicRouteKind = (string)($options['public_route_kind'] ?? 'generic');
    $publicPresentationMode = (string)($options['public_presentation_mode'] ?? 'canonical');
    $entityRenderContext = [
        'content_type' => $defaultType,
        'items' => $items,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ];

    $templatePath = cmsResolveContentTemplate('public/entity.list.disyl', [], $defaultType, $entityRenderContext);
    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'entity-list');
    $pagination = is_array($options['pagination'] ?? null) ? $options['pagination'] : [];
    $listContext = is_array($options['entity_list_context'] ?? null) ? $options['entity_list_context'] : [];
    $listContext = array_merge([
        'base_list_url' => '',
        'item_base_url' => '',
        'search' => '',
        'category_id' => 0,
        'category_slug' => '',
        'category_name' => '',
        'result_count' => count($items),
        'result_label' => cmsEntityListResultLabel(count($items)),
        'active_filter_count' => 0,
        'summary_text' => '',
        'available_categories' => [],
        'all_items_url' => '',
        'search_action_url' => '',
        'search_placeholder' => 'Search',
        'search_button_label' => 'Search',
        'category_navigation_label' => 'Categories',
        'all_items_label' => 'All Items',
        'category_submit_label' => 'Browse',
        'show_item_meta' => false,
        'show_item_author' => false,
        'show_item_date' => false,
        'show_item_type_badge' => false,
        'empty_title' => 'No items found.',
        'empty_body' => '',
        'empty_link_label' => 'Browse all items',
    ], $listContext);
    if ((string)$listContext['result_label'] === '') {
        $listContext['result_label'] = cmsEntityListResultLabel((int)$listContext['result_count']);
    }

    $templateContext = is_array($options['template_context'] ?? null) ? $options['template_context'] : [];
    $pageContext = cmsPublicContext(array_merge([
        'page_title' => (string)($options['page_title'] ?? $options['list_title'] ?? ''),
        'list_title' => (string)($options['list_title'] ?? ''),
        'list_description' => (string)($options['list_description'] ?? ''),
        'pagination' => $pagination,
        'entity_list_context' => $listContext,
        'content_type' => $defaultType,
        'sidebar_template' => $sidebarTemplateKey,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
    ], $templateContext));

    $pageContext['entity_render_family'] = cmsCanonicalEntityRenderFamily($entityRenderContext);
    $pageContext['entity_presentation'] = cmsCanonicalEntityPresentationConfig(
        is_array($pageContext['entity_presentation_settings'] ?? null)
            ? $pageContext['entity_presentation_settings']
            : (is_array($pageContext['theme_settings'] ?? null) ? $pageContext['theme_settings'] : []),
        $entityRenderContext
    );

    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $entityType = trim((string)($item['type'] ?? $defaultType));
        $entityId = (int)($item['id'] ?? 0);
        $item['type'] = $entityType;
        $item['entity_type'] = $entityType;
        $item['content_type_label'] = cmsPublicCanonicalContentTypeLabel($entityType, $item);
        $item['entity_type_label'] = $item['content_type_label'];
        if (empty($item['url'])) {
            $item['url'] = cmsPublicCanonicalEntityUrl($item, $defaultType);
        }
        if (empty($item['featured_image_url']) && !empty($item['featured_image'])) {
            $item['featured_image_url'] = cmsResolveUploadUrl((string)$item['featured_image']);
        }
        if (!empty($item['published_at']) && empty($item['published_at_display'])) {
            $item['published_at_display'] = cmsPublicCanonicalPublishedAtDisplay((string)$item['published_at']);
        }
        if (!isset($item['capabilities']) || !is_array($item['capabilities'])) {
            try {
                $item['capabilities'] = $entityId > 0 ? cmsEntityCapabilityContext($entityId) : [];
            } catch (Throwable $e) {
                $item['capabilities'] = [];
            }
        }
        if (!isset($item['capability_data']) || !is_array($item['capability_data'])) {
            try {
                $item['capability_data'] = $entityId > 0 ? cmsEntityCapabilityData($entityId, $item) : [];
            } catch (Throwable $e) {
                $item['capability_data'] = [];
            }
        }
        $item['primary_image_url'] = cmsPublicListItemPrimaryImageUrl($item);

        $itemContext = cmsPublicEntityListItemBlockContext($item, $pageContext, $defaultType);
        $capabilities = is_array($itemContext['capabilities'] ?? null) ? $itemContext['capabilities'] : [];
        $presentation = is_array($pageContext['entity_presentation'] ?? null) ? $pageContext['entity_presentation'] : [];
        $item['list_card_excerpt'] = !empty($presentation['list_show_excerpt'])
            ? cmsEntityListCardExcerpt((string)($item['excerpt'] ?? ''), (int)($presentation['list_excerpt_length'] ?? 120))
            : '';
        $item['list_card_pricing_html'] = !empty($capabilities['pricing'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', $itemContext)
            : '';
        $item['list_card_inventory_html'] = !empty($capabilities['inventory'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-inventory.block.disyl', $itemContext)
            : '';
        $item['list_card_progress_html'] = !empty($capabilities['progress_tracking'])
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-progress.block.disyl', $itemContext)
            : '';
        $item['list_card_action_html'] = (!empty($capabilities['pricing']) && !empty($pageContext['cart_enabled']))
            ? cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-action.block.disyl', $itemContext)
            : '';

        $normalizedItems[] = $item;
    }

    $pageContext['items'] = $normalizedItems;
    $pageContext['posts'] = $normalizedItems;
    $pageContext['query'] = (string)($listContext['search'] ?? '');
    $pageContext['total'] = (int)($listContext['result_count'] ?? count($normalizedItems));
    $pageContext['page_num'] = (int)($pagination['current'] ?? 1);
    $pageContext['total_pages'] = (int)($pagination['total'] ?? 1);
    $pageContext['next_page'] = min(
        max(1, (int)($pagination['current'] ?? 1)) + 1,
        max(1, (int)($pagination['total'] ?? 1))
    );

    return cmsRenderThemeAwareTemplate($templatePath, $pageContext);
}

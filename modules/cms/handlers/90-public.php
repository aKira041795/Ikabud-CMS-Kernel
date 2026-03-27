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

                $templatePath = cmsResolveContentTemplate('public/page.disyl', $meta, 'page');
                $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'page');
                $html = cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
                    'page_title'   => $staticPage['title'],
                    'content'      => $staticPage,
                    'content_meta' => $meta,
                    'content_html' => $renderedHtml,
                    'cms_head'     => $publicHead,
                    'structured_data' => $structuredData,
                    'builder_enabled' => $builderEnabled,
                    'builder_page_settings' => $builderSettings,
                    'sidebar_template' => $sidebarTemplateKey,
                    'is_front_page' => true,
                ]));

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
    $cacheKey = 'cms:home:page:' . $page . ':archive:' . ($archiveKey !== '' ? $archiveKey : 'all');

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
    $html = cmsPublicRender('public/home.disyl', cmsPublicContext([
        'page_title'  => $archiveLabel !== '' ? ('Archive: ' . $archiveLabel) : 'Blog',
        'posts'       => $posts,
        'page_num'    => $page,
        'total_pages' => $totalPages,
        'next_page'   => min($page + 1, $totalPages),
        'archive_month' => $archiveKey,
        'sidebar_template' => 'home',
    ]));
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
    $cacheKey = 'cms:category:' . $slug . ':page:' . $page;

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

    $html = cmsPublicRender('public/archive.disyl', cmsPublicContext([
        'page_title'    => 'Category: ' . ($category['name'] ?? $slug),
        'archive_type'  => 'category',
        'archive_name'  => $category['name'],
        'archive_desc'  => $category['description'] ?? '',
        'posts'         => $posts,
        'page_num'      => $page,
        'total_pages'   => $totalPages,
        'next_page'     => min($page + 1, $totalPages),
        'sidebar_template' => 'archive',
    ]));

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
    $cacheKey = 'cms:tag:' . $slug . ':page:' . $page;

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

    $html = cmsPublicRender('public/archive.disyl', cmsPublicContext([
        'page_title'    => 'Tag: ' . ($tag['name'] ?? $slug),
        'archive_type'  => 'tag',
        'archive_name'  => $tag['name'],
        'archive_desc'  => '',
        'posts'         => $posts,
        'page_num'      => $page,
        'total_pages'   => $totalPages,
        'next_page'     => min($page + 1, $totalPages),
        'sidebar_template' => 'archive',
    ]));

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
    $q = trim((string)(cmsInput('q', 'GET') ?: ''));
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

    cmsPublicRespond(cmsPublicRender('public/search.disyl', cmsPublicContext([
        'page_title'  => $q !== '' ? 'Search: ' . htmlspecialchars($q) : 'Search',
        'query'       => $q,
        'posts'       => $posts,
        'total'       => $total,
        'page_num'    => $page,
        'total_pages' => $totalPages,
        'next_page'   => min($page + 1, $totalPages),
        'sidebar_template' => 'search',
    ])));
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

    $cacheKey = 'cms:post:' . $slug;

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

    // Fetch categories and tags for this post
    $postCategories = cmsGetContentCategories((int)$post['id']);
    $postTags       = cmsGetContentTagNames((int)$post['id']);

    $templatePath = cmsResolveContentTemplate('public/single.disyl', $meta, 'post');
    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'single');
    $html = cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
        'page_title'  => $post['title'],
        'post'        => $post,
        'post_meta'   => $meta,
        'post_html'   => $renderedHtml,
        'cms_head'    => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'sidebar_template' => $sidebarTemplateKey,
        'post_categories' => $postCategories,
        'post_tags'       => $postTags,
    ]));

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

    $cacheKey = 'cms:page:' . $slug;

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

    $templatePath = cmsResolveContentTemplate('public/page.disyl', $meta, 'page');
    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'page');
    $html = cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
        'page_title'   => $page['title'],
        'content'      => $page,
        'content_meta' => $meta,
        'content_html' => $renderedHtml,
        'cms_head'     => $publicHead,
        'structured_data' => $structuredData,
        'builder_enabled' => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'sidebar_template' => $sidebarTemplateKey,
    ]));

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
    $type = trim((string)($params['type'] ?? ''));
    $slug = trim((string)($params['slug'] ?? ''));
    if ($type === '' || $slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.slug = :slug AND c.type = :type AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug, ':type' => $type]);
    $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entity) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    cmsPublicRespond(cmsRenderThemeAwareTemplate('modules/cms/public/entity.book.disyl', cmsPublicContext([
        'page_title' => 'Book ' . (string)$entity['title'],
        'entity' => $entity,
        'content_type' => $type,
    ])));
}

function cmsPublicEntityInquiry(array $params = []): void
{
    $type = trim((string)($params['type'] ?? ''));
    $slug = trim((string)($params['slug'] ?? ''));
    if ($type === '' || $slug === '') {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.slug = :slug AND c.type = :type AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug, ':type' => $type]);
    $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entity) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
    }

    cmsPublicRespond(cmsRenderThemeAwareTemplate('modules/cms/public/entity.inquire.disyl', cmsPublicContext([
        'page_title' => 'Inquire About ' . (string)$entity['title'],
        'entity' => $entity,
        'content_type' => $type,
    ])));
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
    if (!empty($entity['featured_image'])) {
        $entity['featured_image_url'] = cmsResolveUploadUrl((string)$entity['featured_image']);
    }

    $renderedHtml = cmsFilterRenderedContent(cmsContentRenderedHtml($entity), $entity);
    $publicHead = cmsGetPublicHeadHtml($entity);
    $builderEnabled = cmsPageBuilderEnabled($meta);
    $builderSettings = cmsPageBuilderSettings($meta);
    $structuredData = cmsStructuredDataJsonLd($entity);

    $templatePath = cmsResolveContentTemplate('public/entity.view.disyl', $meta, $type);
    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'entity-view');
    $html = cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
        'page_title'            => $entity['title'],
        'entity'                => $entity,
        'entity_meta'           => $meta,
        'post_html'             => $renderedHtml,
        'cms_head'              => $publicHead,
        'structured_data'       => $structuredData,
        'builder_enabled'       => $builderEnabled,
        'builder_page_settings' => $builderSettings,
        'sidebar_template'      => $sidebarTemplateKey,
        'content_type'          => $type,
    ]));

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

    $templatePath = cmsResolveContentTemplate('public/entity.list.disyl', [], $type);
    $templateAbsolutePath = BASE_PATH . '/templates/' . ltrim($templatePath, '/');
    $templateVersion = md5($templatePath . '|' . @filemtime($templateAbsolutePath));

    $cacheKey = 'cms:entity_list:' . $type . ':p' . $page
        . ($perPage !== 12 ? ':pp' . $perPage : '')
        . ($categoryId > 0 ? ':cat' . $categoryId : '')
        . ($search !== '' ? ':s' . md5($search) : '')
        . ':tpl:' . $templateVersion;

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
    } catch (\Throwable $e) {
        http_response_code(404);
        cmsPublicRespond(cmsRender('pages/404.disyl', ['page_title' => 'Not Found']));
        return;
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
    $pagination = [
        'current'  => $page,
        'total'    => $totalPages,
        'prev_url' => $page > 1 ? $listBase . '?page=' . ($page - 1) : '',
        'next_url' => $page < $totalPages ? $listBase . '?page=' . ($page + 1) : '',
    ];

    $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'entity-list');
    $html = cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
        'page_title'       => $listTitle,
        'list_title'       => $listTitle,
        'list_description' => '',
        'items'            => $items,
        'pagination'       => $pagination,
        'content_type'     => $type,
        'sidebar_template' => $sidebarTemplateKey,
    ]));

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

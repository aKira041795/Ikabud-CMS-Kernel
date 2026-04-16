<?php

declare(strict_types=1);

function cmsApiContentList(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.list');
    $input   = cmsInput();
    $cmsSettings = readCmsSettings();

    $type      = trim((string)($input['type'] ?? 'post'));
    $status    = trim((string)($input['status'] ?? ''));
    $defaultLimit = (int)($cmsSettings['posts_per_page'] ?? 20);
    $limit     = min(100, max(1, (int)($input['limit'] ?? $defaultLimit)));
    $offset    = max(0, (int)($input['offset'] ?? 0));

    // Advanced filter params
    $authorId    = isset($input['author_id'])    ? (int)$input['author_id']    : null;
    $categoryId  = isset($input['category_id'])  ? (int)$input['category_id']  : null;
    $categorySlug = isset($input['category'])    ? trim((string)$input['category']) : null;
    $tag         = isset($input['tag'])          ? trim((string)$input['tag']) : null;
    $dateFrom    = isset($input['date_from'])    ? trim((string)$input['date_from']) : null;
    $dateTo      = isset($input['date_to'])      ? trim((string)$input['date_to'])   : null;
    $isSticky    = isset($input['is_sticky'])    ? (int)(bool)$input['is_sticky']    : null;
    $isFeatured  = isset($input['is_featured'])  ? (int)(bool)$input['is_featured']  : null;
    $postFormat  = isset($input['post_format'])  ? trim((string)$input['post_format']) : null;
    $q           = isset($input['q'])            ? trim((string)$input['q']) : null;

    // Sorting
    $allowedSortCols = ['published_at', 'created_at', 'updated_at', 'title', 'reading_time', 'word_count', 'comment_count'];
    $sortBy  = in_array($input['sort_by'] ?? '', $allowedSortCols, true)
        ? $input['sort_by']
        : 'published_at';
    $sortDir = strtoupper((string)($input['sort_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = ['c.deleted_at IS NULL', 'c.type = :type'];
    $bind  = [':type' => $type];

    if ($status !== '') {
        $where[] = 'c.status = :status';
        $bind[':status'] = $status;
    }
    if ($authorId !== null && $authorId > 0) {
        $where[] = 'c.author_id = :author_id';
        $bind[':author_id'] = $authorId;
    }
    if ($isSticky !== null) {
        $where[] = 'c.is_sticky = :is_sticky';
        $bind[':is_sticky'] = $isSticky;
    }
    if ($isFeatured !== null) {
        $where[] = 'c.is_featured = :is_featured';
        $bind[':is_featured'] = $isFeatured;
    }
    if ($postFormat !== null && $postFormat !== '') {
        $where[] = 'c.post_format = :post_format';
        $bind[':post_format'] = $postFormat;
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
    if ($q !== null && $q !== '') {
        $where[] = '(c.title LIKE :q1 OR c.excerpt LIKE :q2)';
        $bind[':q1'] = '%' . $q . '%';
        $bind[':q2'] = '%' . $q . '%';
    }

    $db = cmsDb();

    // Category filter (resolve slug → id if needed)
    $joinCategory = '';
    if ($categoryId !== null && $categoryId > 0) {
        $joinCategory = "INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = :cat_id";
        $bind[':cat_id'] = $categoryId;
    } elseif ($categorySlug !== null && $categorySlug !== '') {
        $catStmt = $db->prepare("SELECT id FROM cms_categories WHERE slug = :catslug LIMIT 1");
        $catStmt->execute([':catslug' => $categorySlug]);
        $catRow = $catStmt->fetch(\PDO::FETCH_ASSOC);
        if ($catRow) {
            $joinCategory = "INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = :cat_id";
            $bind[':cat_id'] = (int)$catRow['id'];
        }
    }

    // Tag filter
    $joinTag = '';
    if ($tag !== null && $tag !== '') {
        $tagStmt = $db->prepare("SELECT id FROM cms_tags WHERE slug = :tslug OR name = :tname LIMIT 1");
        $tagStmt->execute([':tslug' => $tag, ':tname' => $tag]);
        $tagRow = $tagStmt->fetch(\PDO::FETCH_ASSOC);
        if ($tagRow) {
            $joinTag = "INNER JOIN cms_content_tags ct ON ct.content_id = c.id AND ct.tag_id = :tag_id";
            $bind[':tag_id'] = (int)$tagRow['id'];
        }
    }

    $whereStr = implode(' AND ', $where);
    $joins    = trim($joinCategory . ' ' . $joinTag);
    $orderBy  = "c.{$sortBy} {$sortDir}, c.id DESC";

    // Count total matching rows
    $total = 0;
    try {
        $cStmt = $db->prepare("SELECT COUNT(DISTINCT c.id) FROM cms_content c {$joins} WHERE {$whereStr}");
        $cStmt->execute($bind);
        $total = (int)$cStmt->fetchColumn();
    } catch (\Throwable $e) {}

    $stmt = $db->prepare(
        "SELECT c.id, c.uuid, c.title, c.slug, c.excerpt, c.type, c.status,
                c.is_sticky, c.is_featured, c.post_format,
                c.reading_time, c.word_count, c.comment_count,
                c.published_at, c.created_at, c.updated_at,
                u.display_name as author_name, u.id as author_id
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         {$joins}
         WHERE {$whereStr}
         GROUP BY c.id
         ORDER BY {$orderBy}
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'     => true,
        'data'   => $rows,
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
    exit;
}

function cmsApiContentGet(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.read');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.*, u.display_name as author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $meta = cmsLoadContentMeta($db, (int)$row['id']);
    $row['meta'] = $meta;
    $row['page_builder_enabled'] = ((string)($row['type'] ?? '') === 'page') ? cmsPageBuilderEnabled($meta) : false;
    $row['rendered_html'] = cmsFilterRenderedContent(cmsContentRenderedHtml(array_merge($row, ['meta' => $meta])), array_merge($row, ['meta' => $meta]));

    echo json_encode(['ok' => true, 'data' => $row]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// WORKFLOW API
// ═══════════════════════════════════════════════════════════════════════

function cmsApiContentWorkflowState(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('workflow.view');
    $id   = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT id FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    try {
        $res = app()->cap()->call('workflow.state.get@1', [
            'workflow_key' => 'cms.content',
            'module' => 'cms',
            'entity_type' => 'cms_content',
            'entity_id' => (string)$id,
        ], ['caller_module' => 'cms', 'caller_user' => $user]);

        echo json_encode(['ok' => true, 'workflow' => $res['workflow'] ?? null]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Workflow capability not available']);
        exit;
    }
}

function cmsApiContentWorkflowTransition(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('workflow.transition');
    $id   = (int)($params['id'] ?? 0);

    $input = cmsInput();
    $action = trim((string)($input['action'] ?? ''));
    if ($id <= 0 || $action === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'id and action are required']);
        exit;
    }

    try {
        $res = app()->cap()->call('workflow.transition@1', [
            'workflow_key' => 'cms.content',
            'module' => 'cms',
            'entity_type' => 'cms_content',
            'entity_id' => (string)$id,
            'action' => $action,
        ], ['caller_module' => 'cms', 'caller_user' => $user]);

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Transition failed')]);
            exit;
        }

        if (($res['to_state'] ?? '') === 'published') {
            $db = cmsDb();
            $meta = cmsLoadContentMeta($db, $id);
            $desiredPublishAt = trim((string)($meta['_ai_desired_publish_at'] ?? ''));
            $normalizedDesiredPublishAt = cmsNormalizePublishAt($desiredPublishAt);
            if ($normalizedDesiredPublishAt !== null && strtotime($normalizedDesiredPublishAt) > time()) {
                $db->prepare(
                    "UPDATE cms_content SET status = 'scheduled', published_at = :pub, updated_at = NOW() WHERE id = :id LIMIT 1"
                )->execute([':pub' => $normalizedDesiredPublishAt, ':id' => $id]);

                try {
                    app()->cap()->call('kernel.audit.record@1', [
                        'module' => 'cms',
                        'action' => 'content.schedule_from_ai_approval',
                        'entity_type' => 'cms_content',
                        'entity_id' => (string)$id,
                        'new_data' => ['status' => 'scheduled', 'published_at' => $normalizedDesiredPublishAt],
                    ]);
                } catch (Throwable $ignored) {
                }

                echo json_encode([
                    'ok' => true,
                    'transition' => $res,
                    'content_status' => 'scheduled',
                    'published_at' => $normalizedDesiredPublishAt,
                ]);
                exit;
            }

            cmsApiContentPublish(['id' => $id]);
            return;
        }

        echo json_encode(['ok' => true, 'transition' => $res]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Workflow capability call failed']);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// AI HELPERS API
// ═══════════════════════════════════════════════════════════════════════

function cmsApiContentAiSummary(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.summary');
    $id   = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT id, title, excerpt, body, blocks_json, type, status FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $c)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $rawText = trim((string)($c['body'] ?? ''));
    $blocks = trim((string)($c['blocks_json'] ?? ''));
    if ($blocks !== '') {
        $rawText .= "\n\n" . $blocks;
    }
    $rawText = strip_tags($rawText);
    if (strlen($rawText) > 8000) {
        $rawText = substr($rawText, 0, 8000);
    }

    try {
        $res = app()->cap()->call('ai.text.generate@1', [
            'messages' => [
                ['role' => 'system', 'content' => 'You are an editor assistant. Write a concise summary for CMS content. Return plain text only.'],
                ['role' => 'user', 'content' => "Title: " . (string)($c['title'] ?? '') . "\n\nContent:\n" . $rawText],
            ],
            'temperature' => 0.2,
            'json' => false,
            'timeout_ms' => 20000,
            'max_tokens' => 220,
            'preferred_tier' => 'free',
        ], ['caller_module' => 'cms', 'caller_user' => $user, 'timeout_ms' => 20000]);

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI provider error')]);
            exit;
        }

        echo json_encode(['ok' => true, 'summary' => trim((string)($res['content'] ?? ''))]);
        exit;
    } catch (Throwable $e) {
        write_log('cms ai summary failed: ' . $e->getMessage(), 'error', [
            'content_id' => $id,
            'user_id' => (int)($user['id'] ?? 0),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
        exit;
    }
}

function cmsApiContentAiSeo(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.seo');
    $id   = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT id, title, excerpt, body, blocks_json, type, status FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $c)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $rawText = trim((string)($c['body'] ?? ''));
    $blocks = trim((string)($c['blocks_json'] ?? ''));
    if ($blocks !== '') {
        $rawText .= "\n\n" . $blocks;
    }
    $rawText = strip_tags($rawText);
    if (strlen($rawText) > 8000) {
        $rawText = substr($rawText, 0, 8000);
    }

    $prompt = [
        'task' => 'seo_suggestions',
        'input' => [
            'title' => (string)($c['title'] ?? ''),
            'excerpt' => (string)($c['excerpt'] ?? ''),
            'content' => $rawText,
        ],
        'output_schema' => [
            'type' => 'object',
            'required' => ['seo_title', 'seo_description'],
            'properties' => [
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
            ],
        ],
        'constraints' => [
            'seo_title_max' => 60,
            'seo_description_max' => 160,
            'return_json_only' => true,
        ],
    ];

    try {
        $res = app()->cap()->call('ai.text.generate@1', [
            'messages' => [
                ['role' => 'system', 'content' => 'You are an SEO assistant for a CMS. Return ONLY valid JSON matching the requested schema.'],
                ['role' => 'user', 'content' => json_encode($prompt, JSON_UNESCAPED_SLASHES)],
            ],
            'temperature' => 0.2,
            'json' => true,
            'timeout_ms' => 20000,
            'max_tokens' => 220,
            'preferred_tier' => 'free',
        ], ['caller_module' => 'cms', 'caller_user' => $user, 'timeout_ms' => 20000]);

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI provider error')]);
            exit;
        }

        $decoded = json_decode((string)($res['content'] ?? ''), true);
        if (!is_array($decoded)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'AI returned invalid JSON']);
            exit;
        }

        echo json_encode(['ok' => true, 'seo' => [
            'seo_title' => (string)($decoded['seo_title'] ?? ''),
            'seo_description' => (string)($decoded['seo_description'] ?? ''),
        ]]);
        exit;
    } catch (Throwable $e) {
        write_log('cms ai seo failed: ' . $e->getMessage(), 'error', [
            'content_id' => $id,
            'user_id' => (int)($user['id'] ?? 0),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
        exit;
    }
}

function cmsAiFetchOpenverseImageCandidates(string $query, int $limit = 12): array
{
    if (!function_exists('curl_init')) {
        return [];
    }

    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $url = 'https://api.openverse.org/v1/images/?q=' . rawurlencode($q)
        . '&license_type=all&page_size=' . max(1, min(20, $limit));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Ikabud-CMS/1.0 (+https://ikabud.com)',
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return [];
    }

    $decoded = json_decode($raw, true);
    $items = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    if ($items === []) {
        return [];
    }

    $candidates = [];
    foreach ($items as $item) {
        $imgUrl = trim((string)($item['url'] ?? ''));
        if ($imgUrl === '' || !preg_match('/^https?:\/\//i', $imgUrl)) {
            continue;
        }

        $title = trim((string)($item['title'] ?? ''));
        $creator = trim((string)($item['creator'] ?? ''));
        $license = trim((string)($item['license'] ?? ''));
        $licenseVersion = trim((string)($item['license_version'] ?? ''));
        $licenseUrl = trim((string)($item['license_url'] ?? ''));
        $thumb = trim((string)($item['thumbnail'] ?? ''));
        $pageUrl = trim((string)($item['foreign_landing_url'] ?? ''));

        $candidates[] = [
            'id'            => 'ov:' . (string)($item['id'] ?? md5($imgUrl)),
            'url'           => $imgUrl,
            'thumbnail_url' => $thumb !== '' ? $thumb : $imgUrl,
            'original_name' => $title !== '' ? $title : basename(parse_url($imgUrl, PHP_URL_PATH) ?: 'image'),
            'alt_text'      => $title,
            'creator'       => $creator,
            'license'       => trim($license . ($licenseVersion !== '' ? ' ' . $licenseVersion : '')),
            'license_url'   => $licenseUrl,
            'source'        => 'openverse',
            'source_url'    => $pageUrl,
            'external'      => true,
        ];

        if (count($candidates) >= $limit) {
            break;
        }
    }

    return $candidates;
}

function cmsAiFetchWikimediaImageCandidates(string $query, int $limit = 12): array
{
    if (!function_exists('curl_init')) {
        return [];
    }

    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $url = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search'
        . '&gsrnamespace=6&gsrsearch=' . rawurlencode($q)
        . '&gsrlimit=' . max(1, min(20, $limit))
        . '&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=640&format=json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Ikabud-CMS/1.0 (+https://ikabud.com)',
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return [];
    }

    $decoded = json_decode($raw, true);
    $pages = is_array($decoded['query']['pages'] ?? null) ? $decoded['query']['pages'] : [];
    if ($pages === []) {
        return [];
    }

    $rows = [];
    foreach ($pages as $page) {
        $ii = is_array($page['imageinfo'][0] ?? null) ? $page['imageinfo'][0] : null;
        if (!is_array($ii)) {
            continue;
        }

        $imgUrl = trim((string)($ii['url'] ?? ''));
        if ($imgUrl === '' || !preg_match('/^https?:\/\//i', $imgUrl)) {
            continue;
        }

        $thumbUrl = trim((string)($ii['thumburl'] ?? ''));
        $pageId = (int)($page['pageid'] ?? 0);
        $title = trim((string)($page['title'] ?? ''));
        $meta = is_array($ii['extmetadata'] ?? null) ? $ii['extmetadata'] : [];

        $artist = trim(strip_tags((string)($meta['Artist']['value'] ?? '')));
        $license = trim(strip_tags((string)($meta['LicenseShortName']['value'] ?? '')));
        $licenseUrl = trim((string)($meta['LicenseUrl']['value'] ?? ''));
        $sourceUrl = trim((string)($ii['descriptionurl'] ?? ''));

        $rows[] = [
            'id'            => 'wc:' . ($pageId > 0 ? (string)$pageId : md5($imgUrl)),
            'url'           => $imgUrl,
            'thumbnail_url' => $thumbUrl !== '' ? $thumbUrl : $imgUrl,
            'original_name' => $title !== '' ? preg_replace('/^File:/i', '', $title) : basename(parse_url($imgUrl, PHP_URL_PATH) ?: 'image'),
            'alt_text'      => $title,
            'creator'       => $artist,
            'license'       => $license,
            'license_url'   => $licenseUrl,
            'source'        => 'wikimedia',
            'source_url'    => $sourceUrl,
            'external'      => true,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function cmsAiFetchWikimediaImageCandidatesFromQueries(array $queries, int $limit = 12): array
{
    $seenQueries = [];
    $seenUrls = [];
    $merged = [];

    foreach ($queries as $query) {
        $q = trim((string)$query);
        if ($q === '') {
            continue;
        }

        $normalized = preg_replace('/\s+/', ' ', mb_strtolower($q));
        if ($normalized === '' || isset($seenQueries[$normalized])) {
            continue;
        }
        $seenQueries[$normalized] = true;

        $variants = [$q];
        $simplified = trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q));
        if ($simplified !== '' && $simplified !== $q) {
            $variants[] = $simplified;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $q))));
        if (!empty($parts)) {
            $variants[] = implode(' ', array_slice($parts, 0, 3));
            $variants[] = (string)($parts[0] ?? '');
        }

        foreach ($variants as $variant) {
            $variant = trim($variant);
            if ($variant === '') {
                continue;
            }
            $rows = cmsAiFetchWikimediaImageCandidates($variant, $limit);
            foreach ($rows as $row) {
                $url = trim((string)($row['url'] ?? ''));
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $merged[] = $row;
                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        if (count($merged) >= 3) {
            break;
        }
    }

    if ($merged !== []) {
        return $merged;
    }

    foreach (['software architecture', 'web development', 'computer code', 'technology'] as $fallback) {
        $rows = cmsAiFetchWikimediaImageCandidates($fallback, $limit);
        foreach ($rows as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $merged[] = $row;
            if (count($merged) >= $limit) {
                return $merged;
            }
        }
        if ($merged !== []) {
            break;
        }
    }

    return $merged;
}

function cmsAiPexelsApiKey(): string
{
    $key = trim((string)(getenv('PEXELS_API_KEY') ?: ''));
    if ($key !== '') {
        return $key;
    }
    if (isset($_ENV['PEXELS_API_KEY'])) {
        $key = trim((string)$_ENV['PEXELS_API_KEY']);
    }
    return $key;
}

function cmsAiFetchPexelsImageCandidates(string $query, int $limit = 12): array
{
    if (!function_exists('curl_init')) {
        return [];
    }
    $apiKey = cmsAiPexelsApiKey();
    if ($apiKey === '') {
        return [];
    }

    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $url = 'https://api.pexels.com/v1/search?query=' . rawurlencode($q)
        . '&per_page=' . max(1, min(40, $limit));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: ' . $apiKey,
        ],
        CURLOPT_USERAGENT      => 'Ikabud-CMS/1.0 (+https://ikabud.com)',
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return [];
    }

    $decoded = json_decode($raw, true);
    $photos = is_array($decoded['photos'] ?? null) ? $decoded['photos'] : [];
    if ($photos === []) {
        return [];
    }

    $rows = [];
    foreach ($photos as $photo) {
        $src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
        $imgUrl = trim((string)($src['large2x'] ?? $src['large'] ?? $src['medium'] ?? $src['original'] ?? ''));
        if ($imgUrl === '' || !preg_match('/^https?:\/\//i', $imgUrl)) {
            continue;
        }
        $thumb = trim((string)($src['medium'] ?? $src['small'] ?? $imgUrl));
        $id = (int)($photo['id'] ?? 0);
        $title = trim((string)($photo['alt'] ?? ''));
        $creator = trim((string)($photo['photographer'] ?? ''));
        $sourceUrl = trim((string)($photo['url'] ?? ''));

        $rows[] = [
            'id'            => 'px:' . ($id > 0 ? (string)$id : md5($imgUrl)),
            'url'           => $imgUrl,
            'thumbnail_url' => $thumb,
            'original_name' => $title !== '' ? $title : basename(parse_url($imgUrl, PHP_URL_PATH) ?: 'image'),
            'alt_text'      => $title,
            'creator'       => $creator,
            'license'       => 'Pexels License',
            'license_url'   => 'https://www.pexels.com/license/',
            'source'        => 'pexels',
            'source_url'    => $sourceUrl,
            'external'      => true,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function cmsAiFetchPexelsImageCandidatesFromQueries(array $queries, int $limit = 12): array
{
    if (cmsAiPexelsApiKey() === '') {
        return [];
    }

    $seenQueries = [];
    $seenUrls = [];
    $merged = [];

    foreach ($queries as $query) {
        $q = trim((string)$query);
        if ($q === '') {
            continue;
        }
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower($q));
        if ($normalized === '' || isset($seenQueries[$normalized])) {
            continue;
        }
        $seenQueries[$normalized] = true;

        $variants = [$q];
        $simplified = trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q));
        if ($simplified !== '' && $simplified !== $q) {
            $variants[] = $simplified;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $q))));
        if (!empty($parts)) {
            $variants[] = implode(' ', array_slice($parts, 0, 3));
            $variants[] = (string)($parts[0] ?? '');
        }

        foreach ($variants as $variant) {
            $rows = cmsAiFetchPexelsImageCandidates($variant, $limit);
            foreach ($rows as $row) {
                $url = trim((string)($row['url'] ?? ''));
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $merged[] = $row;
                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        if (count($merged) >= 3) {
            break;
        }
    }

    if ($merged !== []) {
        return $merged;
    }

    foreach (['software architecture', 'web development', 'computer code', 'technology'] as $fallback) {
        $rows = cmsAiFetchPexelsImageCandidates($fallback, $limit);
        foreach ($rows as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $merged[] = $row;
            if (count($merged) >= $limit) {
                return $merged;
            }
        }
        if ($merged !== []) {
            break;
        }
    }

    return $merged;
}

function cmsAiFetchOpenverseImageCandidatesFromQueries(array $queries, int $limit = 12): array
{
    $seenQueries = [];
    $merged = [];
    $seenUrls = [];

    foreach ($queries as $query) {
        $q = trim((string)$query);
        if ($q === '') {
            continue;
        }

        $normalized = preg_replace('/\s+/', ' ', mb_strtolower($q));
        if ($normalized === '' || isset($seenQueries[$normalized])) {
            continue;
        }
        $seenQueries[$normalized] = true;

        $variants = [$q];
        $simplified = trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q));
        if ($simplified !== '' && $simplified !== $q) {
            $variants[] = $simplified;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $q))));
        if (!empty($parts)) {
            $variants[] = implode(' ', array_slice($parts, 0, 3));
            $variants[] = (string)($parts[0] ?? '');
        }

        foreach ($variants as $variant) {
            $variant = trim($variant);
            if ($variant === '') {
                continue;
            }
            $rows = cmsAiFetchOpenverseImageCandidates($variant, $limit);
            foreach ($rows as $row) {
                $url = trim((string)($row['url'] ?? ''));
                if ($url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $merged[] = $row;
                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        // Keep latency bounded: stop early once we have useful results.
        if (count($merged) >= 3) {
            break;
        }
    }

    if ($merged !== []) {
        return $merged;
    }

    $combined = mb_strtolower(implode(' ', array_map(static fn($q) => trim((string)$q), $queries)));
    $fallbackQueries = [];
    if (str_contains($combined, 'kernel') || str_contains($combined, 'modular') || str_contains($combined, 'architecture')) {
        $fallbackQueries[] = 'software architecture';
    }
    if (str_contains($combined, 'web') || str_contains($combined, 'website')) {
        $fallbackQueries[] = 'web development';
    }
    if (str_contains($combined, 'code') || str_contains($combined, 'developer') || str_contains($combined, 'program')) {
        $fallbackQueries[] = 'computer code';
    }
    $fallbackQueries[] = 'technology';

    $fallbackSeen = [];
    foreach ($fallbackQueries as $fallbackQuery) {
        $fq = trim($fallbackQuery);
        if ($fq === '' || isset($fallbackSeen[$fq])) {
            continue;
        }
        $fallbackSeen[$fq] = true;
        $rows = cmsAiFetchOpenverseImageCandidates($fq, $limit);
        foreach ($rows as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $merged[] = $row;
            if (count($merged) >= $limit) {
                return $merged;
            }
        }
        if ($merged !== []) {
            break;
        }
    }

    return $merged;
}

function cmsApiAiFeaturedImageSuggest(array $params = []): void
{
    header('Content-Type: application/json');
    $user  = cmsRequireCap('content.create');
    $input = cmsInput();

    $title   = trim(strip_tags((string)($input['title']   ?? '')));
    $excerpt = trim(strip_tags((string)($input['excerpt'] ?? '')));
    $tags    = trim(strip_tags((string)($input['tags']    ?? '')));
    $type    = trim((string)($input['type'] ?? 'post'));
    $searchKeywords = trim(strip_tags((string)($input['search_keywords'] ?? '')));

    if ($title === '' && $searchKeywords === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title or search keywords are required for AI image suggestions']);
        exit;
    }

    $db = cmsDb();

    // Load recent images from media library (images only, capped at 60)
    $mediaRows = [];
    try {
        $stmt = $db->prepare(
            "SELECT id, original_name, alt_text, file_path
             FROM cms_media
             WHERE mime_type LIKE 'image/%'
             ORDER BY created_at DESC
             LIMIT 60"
        );
        $stmt->execute();
        $mediaRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        write_log('cms ai featured image suggest: media fetch failed: ' . $e->getMessage(), 'error');
    }

    // Resolve URLs for all local candidates
    foreach ($mediaRows as &$mr) {
        $mr['url'] = cmsResolveUploadUrl((string)($mr['file_path'] ?? ''));
    }
    unset($mr);

    // Build compact local image list for AI (id + name + alt)
    $localImageList = [];
    foreach ($mediaRows as $mr) {
        $label = trim((string)($mr['original_name'] ?? ''));
        $alt   = trim((string)($mr['alt_text']      ?? ''));
        $localImageList[] = [
            'id'   => (int)$mr['id'],
            'name' => $label !== '' ? $label : basename((string)($mr['file_path'] ?? '')),
            'alt'  => $alt,
            'kind' => 'local_media',
        ];
    }

    $contextParts = [];
    if ($title !== '') $contextParts[] = "Title: {$title}";
    if ($excerpt !== '') $contextParts[] = "Excerpt: {$excerpt}";
    if ($tags    !== '') $contextParts[] = "Tags: {$tags}";
    if ($type    !== '') $contextParts[] = "Content type: {$type}";
    if ($searchKeywords !== '') $contextParts[] = "Search keywords override: {$searchKeywords}";
    $context = implode("\n", $contextParts);

    // Pull free/licensed web images from Openverse + Wikimedia as additional candidates.
    $searchQueries = [];
    if ($searchKeywords !== '') {
        $searchQueries[] = $searchKeywords;
    }
    if ($tags !== '') {
        $searchQueries[] = $tags;
    }
    if ($title !== '') {
        $searchQueries[] = $title;
    }
    if ($title !== '' && $tags !== '') {
        $searchQueries[] = $title . ' ' . $tags;
    }
    if ($type !== '') {
        $searchQueries[] = $tags !== '' ? ($tags . ' ' . $type) : ($title . ' ' . $type);
    }
    $openverseCandidates = cmsAiFetchOpenverseImageCandidatesFromQueries($searchQueries, 8);
    $wikimediaCandidates = cmsAiFetchWikimediaImageCandidatesFromQueries($searchQueries, 8);
    $pexelsCandidates = cmsAiFetchPexelsImageCandidatesFromQueries($searchQueries, 8);
    $webCandidates = [];
    $seenWebUrls = [];
    foreach (array_merge($openverseCandidates, $wikimediaCandidates, $pexelsCandidates) as $wc) {
        $u = trim((string)($wc['url'] ?? ''));
        if ($u === '' || isset($seenWebUrls[$u])) {
            continue;
        }
        $seenWebUrls[$u] = true;
        $webCandidates[] = $wc;
        if (count($webCandidates) >= 12) {
            break;
        }
    }
    $webImageList = [];
    foreach ($webCandidates as $ov) {
        $webImageList[] = [
            'id'   => (string)($ov['id'] ?? ''),
            'name' => (string)($ov['original_name'] ?? ''),
            'alt'  => (string)($ov['alt_text'] ?? ''),
            'kind' => 'openverse_web',
            'creator' => (string)($ov['creator'] ?? ''),
            'license' => (string)($ov['license'] ?? ''),
        ];
    }

    $candidateList = array_merge($localImageList, $webImageList);
    if ($candidateList === []) {
        echo json_encode(['ok' => true, 'suggestions' => [], 'reason' => 'No image candidates available']);
        exit;
    }

    $systemPrompt = 'You are a CMS image curator. Given a piece of content and a list of image candidates (from local media and free web image sources), select up to 3 images that would be most suitable as the featured image for that content.' . "\n"
        . 'Return ONLY valid JSON with this exact schema: {"suggestions":[{"id":"candidate-id","reason":"Brief reason why this image fits"}]}' . "\n"
        . 'Return between 0 and 3 suggestions. Prioritise relevance by filename and alt text. You may return IDs from either local media or web candidates. If no image is a good fit, return an empty suggestions array.';

    $userPrompt = "Content:\n{$context}\n\nAvailable images:\n" . json_encode($candidateList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $res = app()->cap()->call('ai.text.generate@1', [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature'    => 0.2,
            'json'           => true,
            'timeout_ms'     => 20000,
            'max_tokens'     => 400,
            'preferred_tier' => 'free',
        ], ['caller_module' => 'cms', 'caller_user' => $user, 'timeout_ms' => 20000]);

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI provider error')]);
            exit;
        }

        $decoded = json_decode((string)($res['content'] ?? ''), true);
        $rawSuggestions = is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [];

        // Build local-media map for fast lookup and validate IDs
        $mediaMap = [];
        foreach ($mediaRows as $mr) {
            $mediaMap[(int)$mr['id']] = $mr;
        }

        // Build web-candidate map for fast lookup
        $webMap = [];
        foreach ($webCandidates as $ov) {
            $oid = (string)($ov['id'] ?? '');
            if ($oid !== '') {
                $webMap[$oid] = $ov;
            }
        }

        $suggestions = [];
        foreach ($rawSuggestions as $s) {
            $reason = trim(strip_tags((string)($s['reason'] ?? '')));
            $rawId = $s['id'] ?? null;
            $idStr = trim((string)$rawId);

            $sid = (int)$rawId;
            if ($sid > 0 && isset($mediaMap[$sid])) {
                $mr = $mediaMap[$sid];
                $suggestions[] = [
                    'id'            => $sid,
                    'url'           => $mr['url'],
                    'original_name' => (string)($mr['original_name'] ?? ''),
                    'alt_text'      => (string)($mr['alt_text']      ?? ''),
                    'reason'        => $reason,
                    'source'        => 'media',
                    'external'      => false,
                ];
                if (count($suggestions) >= 3) break;
                continue;
            }

            if ($idStr !== '' && isset($webMap[$idStr])) {
                $ov = $webMap[$idStr];
                $suggestions[] = [
                    'id'            => null,
                    'url'           => (string)($ov['url'] ?? ''),
                    'thumbnail_url' => (string)($ov['thumbnail_url'] ?? ''),
                    'original_name' => (string)($ov['original_name'] ?? ''),
                    'alt_text'      => (string)($ov['alt_text'] ?? ''),
                    'reason'        => $reason,
                    'source'        => (string)($ov['source'] ?? 'openverse'),
                    'source_url'    => (string)($ov['source_url'] ?? ''),
                    'creator'       => (string)($ov['creator'] ?? ''),
                    'license'       => (string)($ov['license'] ?? ''),
                    'license_url'   => (string)($ov['license_url'] ?? ''),
                    'external'      => true,
                ];
            }

            if (count($suggestions) >= 3) break;
        }

        // Ensure we still surface free web options so suggestions are not local-only.
        if ($webCandidates !== []) {
            $existingUrls = [];
            $hasExternal = false;
            foreach ($suggestions as $s) {
                $u = (string)($s['url'] ?? '');
                if ($u !== '') {
                    $existingUrls[$u] = true;
                }
                if (!empty($s['external'])) {
                    $hasExternal = true;
                }
            }

            $buildExternalSuggestion = static function (array $ov): array {
                return [
                    'id'            => null,
                    'url'           => (string)($ov['url'] ?? ''),
                    'thumbnail_url' => (string)($ov['thumbnail_url'] ?? ''),
                    'original_name' => (string)($ov['original_name'] ?? ''),
                    'alt_text'      => (string)($ov['alt_text'] ?? ''),
                    'reason'        => 'Free web image candidate from Openverse.',
                    'source'        => (string)($ov['source'] ?? 'openverse'),
                    'source_url'    => (string)($ov['source_url'] ?? ''),
                    'creator'       => (string)($ov['creator'] ?? ''),
                    'license'       => (string)($ov['license'] ?? ''),
                    'license_url'   => (string)($ov['license_url'] ?? ''),
                    'external'      => true,
                ];
            };

            $firstExternal = null;
            foreach ($webCandidates as $ov) {
                $url = (string)($ov['url'] ?? '');
                if ($url === '' || isset($existingUrls[$url])) {
                    continue;
                }
                $firstExternal = $buildExternalSuggestion($ov);
                break;
            }

            if ($firstExternal !== null && !$hasExternal) {
                if (count($suggestions) >= 3) {
                    $suggestions[count($suggestions) - 1] = $firstExternal;
                } else {
                    $suggestions[] = $firstExternal;
                }
                $existingUrls[(string)$firstExternal['url']] = true;
                $hasExternal = true;
            }

            if (count($suggestions) < 3) {
                foreach ($webCandidates as $ov) {
                    $url = (string)($ov['url'] ?? '');
                    if ($url === '' || isset($existingUrls[$url])) {
                        continue;
                    }
                    $suggestions[] = $buildExternalSuggestion($ov);
                    $existingUrls[$url] = true;
                    if (count($suggestions) >= 3) {
                        break;
                    }
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'suggestions' => $suggestions,
            'sources' => [
                'local_media_count' => count($localImageList),
                'openverse_count'   => count($openverseCandidates),
                'wikimedia_count'   => count($wikimediaCandidates),
                'pexels_count'      => count($pexelsCandidates),
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms ai featured image suggest failed: ' . $e->getMessage(), 'error', [
            'user_id' => (int)($user['id'] ?? 0),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
        exit;
    }
}

function cmsApiContentCreate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.create');

    $input       = cmsInput();
    $cmsSettings = readCmsSettings();

    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title is required']);
        exit;
    }

    $type       = trim((string)($input['type'] ?? 'post'));
    $body       = (string)($input['body'] ?? '');
    $body       = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($body, 'cms.content'), 'cms.content');
    $blocksJson = cmsNormalizeBlocksJson($input['blocks'] ?? ($input['blocks_json'] ?? null));
    $excerpt    = trim((string)($input['excerpt'] ?? ''));

    // Use settings default status, fallback to 'draft'
    $settingDefaultStatus = in_array($cmsSettings['default_post_status'] ?? 'draft', ['draft', 'published'], true)
        ? ($cmsSettings['default_post_status'] ?? 'draft')
        : 'draft';
    $status = trim((string)($input['status'] ?? $settingDefaultStatus));

    // Use settings default comment_status
    $settingDefaultComment = ($cmsSettings['default_comment_status'] ?? 'open') === 'closed' ? 'closed' : 'open';
    $commentStatus = trim((string)($input['comment_status'] ?? $settingDefaultComment));
    if (!in_array($commentStatus, ['open', 'closed'], true)) {
        $commentStatus = $settingDefaultComment;
    }

    $slug           = trim((string)($input['slug'] ?? ''));
    $parentId       = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
    $publishAtInput = cmsNormalizePublishAt($input['published_at'] ?? null);

    // New enrichment fields
    $isSticky   = isset($input['is_sticky'])   ? (int)(bool)$input['is_sticky']   : 0;
    $isFeatured = isset($input['is_featured'])  ? (int)(bool)$input['is_featured'] : 0;
    $postFormat = trim((string)($input['post_format'] ?? 'standard'));
    $knownFormats = ['standard', 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat'];
    if (!in_array($postFormat, $knownFormats, true)) {
        $postFormat = 'standard';
    }
    $password     = !empty($input['password']) ? trim((string)$input['password']) : null;
    $passwordHash = ($password !== null && $password !== '') ? hash('sha256', $password) : null;

    // Auto stats
    $wordCount   = cmsCalculateWordCount($body, $blocksJson);
    $readingTime = ($cmsSettings['reading_time_enabled'] ?? '1') === '1'
        ? cmsCalculateReadingTime($wordCount)
        : 0;

    $featuredImageId = !empty($input['featured_image_id']) ? (int)$input['featured_image_id'] : null;
    $featuredImageUrl = trim((string)($input['featured_image_url'] ?? ''));
    $featuredImageAlt = trim((string)($input['featured_image_alt'] ?? ''));

    // Contributors can only create drafts
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if ($source === 'cms' && !cmsCanPublish($user) && $status !== 'draft') {
        $status = 'draft';
    }
    if (!in_array($status, ['draft', 'published', 'scheduled', 'private'], true)) {
        $status = 'draft';
    }

    if ($slug === '') {
        $slug = cmsSlugify($title);
    }
    $slug = cmsEnsureUniqueSlug($slug, $type);

    $entityPresetId = trim((string)($input['entity_preset_id'] ?? ''));
    if ($entityPresetId !== '' && !isset(cmsEntityPresets()[$entityPresetId])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unknown entity preset']);
        exit;
    }

    $uuid     = cmsUuid();
    $authorId = (int)($user['id'] ?? 0);
    if ($status === 'published') {
        $publishedAt = $publishAtInput ?? date('Y-m-d H:i:s');
    } elseif ($status === 'scheduled') {
        if ($publishAtInput === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Publish date and time are required for scheduled content']);
            exit;
        }
        $publishedAt = $publishAtInput;
    } else {
        $publishedAt = $publishAtInput;
    }

    $db = cmsDb();
    if (($featuredImageId === null || $featuredImageId <= 0)
        && $featuredImageUrl !== ''
        && function_exists('cmsImportMediaFromUrl')) {
        try {
            $importedId = cmsImportMediaFromUrl($featuredImageUrl, $featuredImageAlt, $authorId, $db);
            if (is_int($importedId) && $importedId > 0) {
                $featuredImageId = $importedId;
            }
        } catch (Throwable $e) {
            write_log('cms content create featured-image import failed: ' . $e->getMessage(), 'warning', [
                'author_id' => $authorId,
            ]);
        }
    }
    $stmt = $db->prepare(
        "INSERT INTO cms_content
            (uuid, title, slug, body, blocks_json, excerpt,
             type, status, author_id, parent_id,
             comment_status, is_sticky, is_featured, post_format,
             password, word_count, reading_time,
             featured_image_id, published_at, created_at)
         VALUES
            (:uuid, :title, :slug, :body, :blocks_json, :excerpt,
             :type, :status, :author_id, :parent_id,
             :comment_status, :is_sticky, :is_featured, :post_format,
             :password, :word_count, :reading_time,
             :featured_image_id, :pub, NOW())"
    );
    $stmt->execute([
        ':uuid'          => $uuid,
        ':title'         => $title,
        ':slug'          => $slug,
        ':body'          => $body,
        ':blocks_json'   => $blocksJson,
        ':excerpt'       => $excerpt,
        ':type'          => $type,
        ':status'        => $status,
        ':author_id'     => $authorId,
        ':parent_id'     => $parentId,
        ':comment_status'=> $commentStatus,
        ':is_sticky'     => $isSticky,
        ':is_featured'   => $isFeatured,
        ':post_format'   => $postFormat,
        ':password'      => $passwordHash,
        ':word_count'    => $wordCount,
        ':reading_time'  => $readingTime,
        ':featured_image_id' => $featuredImageId,
        ':pub'           => $publishedAt,
    ]);
    $contentId = (int)$db->lastInsertId();

    // Save meta if provided
    if (!empty($input['meta']) && is_array($input['meta'])) {
        $metaToSave = $input['meta'];
        cmsSanitizeRichTextMeta($metaToSave, $type);
        cmsSaveMeta($db, $contentId, $metaToSave);
    }

    // Sync taxonomy
    if (isset($input['category_ids']) && is_array($input['category_ids'])) {
        cmsSyncContentCategories($contentId, $input['category_ids']);
    }
    if (isset($input['tag_names']) && is_array($input['tag_names'])) {
        cmsSyncContentTags($contentId, $input['tag_names']);
    }

    // Sync media usage
    cmsSyncMediaUsage($contentId, ['featured_image_id' => $featuredImageId], $blocksJson);

    $presetApplied = false;
    if ($entityPresetId !== '') {
        cmsApplyEntityPreset($contentId, $entityPresetId);
        $presetApplied = true;
    }

    // Audit
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module'      => 'cms',
            'action'      => 'content.create',
            'entity_type' => 'cms_content',
            'entity_id'   => (string)$contentId,
            'new_data'    => ['title' => $title, 'type' => $type, 'status' => $status],
        ]);
    } catch (Throwable $e) {}

    // Event
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.created', [
            'content_id' => $contentId,
            'title'      => $title,
            'type'       => $type,
            'author_id'  => $authorId,
        ]);
    }

    adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);

    echo json_encode([
        'ok' => true,
        'id' => $contentId,
        'slug' => $slug,
        'preset_applied' => $presetApplied,
        'entity_preset_id' => $entityPresetId !== '' ? $entityPresetId : null,
    ]);
    exit;
}

function cmsApiContentUpdate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.edit_own');
    $id   = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    if (!cmsCanEditContent($user, $existing)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $input = cmsInput();
    $fields = [];
    $bind   = [':id' => $id];
    $nextStatus = (string)($existing['status'] ?? 'draft');
    $entityPresetId = trim((string)($input['entity_preset_id'] ?? ''));

    $cmsSettings = readCmsSettings();

    if ($entityPresetId !== '' && !isset(cmsEntityPresets()[$entityPresetId])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unknown entity preset']);
        exit;
    }

    // Builder-lock enforcement: if content was built with page builder and lock is enabled,
    // reject attempts to edit body or blocks via the classic content API.
    $existingMeta = cmsLoadContentMeta($db, $id);
    if (cmsBuilderIsLocked($existingMeta) && (isset($input['body']) || isset($input['blocks']) || isset($input['blocks_json']))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This content is managed by the Page Builder. Edit content through the builder interface.', 'builder_locked' => true]);
        exit;
    }

    foreach (['title', 'body', 'excerpt', 'slug', 'status', 'comment_status'] as $f) {
        if (array_key_exists($f, $input)) {
            $val = trim((string)$input[$f]);
            if ($f === 'body') {
                $val = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($val, 'cms.content'), 'cms.content');
            }
            if ($f === 'status' && !in_array($val, ['draft', 'published', 'scheduled', 'private'], true)) {
                continue;
            }
            if ($f === 'status' && !cmsCanPublish($user) && $val !== 'draft') {
                $val = 'draft';
            }
            $fields[] = "{$f} = :{$f}";
            $bind[":{$f}"] = $val;
            if ($f === 'status') {
                $nextStatus = $val;
            }
        }
    }

    if (!empty($input['parent_id'])) {
        $fields[] = 'parent_id = :parent_id';
        $bind[':parent_id'] = (int)$input['parent_id'];
    }
    if (array_key_exists('blocks', $input) || array_key_exists('blocks_json', $input)) {
        $normalized = cmsNormalizeBlocksJson($input['blocks'] ?? ($input['blocks_json'] ?? null));
        $fields[] = 'blocks_json = :blocks_json';
        $bind[':blocks_json'] = $normalized;
    }
    if (array_key_exists('featured_image_id', $input)) {
        $fid = $input['featured_image_id'];
        $fields[] = 'featured_image_id = :fimg';
        $bind[':fimg'] = $fid !== null && $fid !== '' ? (int)$fid : null;
    }

    $featuredImageUrl = trim((string)($input['featured_image_url'] ?? ''));
    $featuredImageAlt = trim((string)($input['featured_image_alt'] ?? ''));
    if ($featuredImageUrl !== '' && function_exists('cmsImportMediaFromUrl')) {
        $uploadedBy = (int)($existing['author_id'] ?? ($user['id'] ?? 0));
        if ($uploadedBy > 0) {
            try {
                $importedId = cmsImportMediaFromUrl($featuredImageUrl, $featuredImageAlt, $uploadedBy, $db);
                if (is_int($importedId) && $importedId > 0) {
                    if (!in_array('featured_image_id = :fimg', $fields, true)) {
                        $fields[] = 'featured_image_id = :fimg';
                    }
                    $bind[':fimg'] = $importedId;
                }
            } catch (Throwable $e) {
                write_log('cms content update featured-image import failed: ' . $e->getMessage(), 'warning', [
                    'content_id' => $id,
                    'user_id' => (int)($user['id'] ?? 0),
                ]);
            }
        }
    }

    // New enrichment fields
    if (array_key_exists('is_sticky', $input)) {
        $fields[] = 'is_sticky = :is_sticky';
        $bind[':is_sticky'] = (int)(bool)$input['is_sticky'];
    }
    if (array_key_exists('is_featured', $input)) {
        $fields[] = 'is_featured = :is_featured';
        $bind[':is_featured'] = (int)(bool)$input['is_featured'];
    }
    if (array_key_exists('post_format', $input)) {
        $knownFormats = ['standard', 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat'];
        $fmt = trim((string)$input['post_format']);
        $fields[] = 'post_format = :post_format';
        $bind[':post_format'] = in_array($fmt, $knownFormats, true) ? $fmt : 'standard';
    }
    // Password: clear with empty string, set with non-empty value
    if (array_key_exists('password', $input)) {
        $rawPw = trim((string)$input['password']);
        $fields[] = 'password = :password';
        $bind[':password'] = $rawPw !== '' ? hash('sha256', $rawPw) : null;
    }

    // Handle slug uniqueness if changed
    if (isset($bind[':slug']) && $bind[':slug'] !== $existing['slug']) {
        $bind[':slug'] = cmsEnsureUniqueSlug($bind[':slug'], $existing['type'], $id);
    }

    $publishAtProvided = array_key_exists('published_at', $input);
    $publishAtInput = cmsNormalizePublishAt($input['published_at'] ?? null);
    if ($nextStatus === 'scheduled') {
        if ($publishAtProvided && $publishAtInput === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Publish date and time are required for scheduled content']);
            exit;
        }
        $fields[] = 'published_at = :pub';
        $bind[':pub'] = $publishAtInput ?? ($existing['published_at'] ?: null);
        if ($bind[':pub'] === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Publish date and time are required for scheduled content']);
            exit;
        }
    } elseif ($nextStatus === 'published') {
        $fields[] = 'published_at = :pub';
        $bind[':pub'] = $publishAtInput ?? ($existing['published_at'] ?: date('Y-m-d H:i:s'));
    } elseif ($publishAtProvided) {
        $fields[] = 'published_at = :pub';
        $bind[':pub'] = $publishAtInput;
    }

    $hasSupplementalChanges = $entityPresetId !== ''
        || (isset($input['meta']) && is_array($input['meta']))
        || (isset($input['category_ids']) && is_array($input['category_ids']))
        || (isset($input['tag_names']) && is_array($input['tag_names']));

    if (empty($fields) && !$hasSupplementalChanges) {
        echo json_encode(['ok' => true, 'message' => 'No changes']);
        exit;
    }

    $contentUpdated = !empty($fields);

    if ($contentUpdated) {
        // Save revision snapshot of current state before updating
        $authorId = (int)($user['id'] ?? 0);
        cmsSaveRevision(
            $id, $authorId,
            (string)$existing['title'],
            $existing['body'] ?? null,
            $existing['blocks_json'] ?? null,
            null
        );

        // Track slug change for 301 redirects
        if (isset($bind[':slug']) && $bind[':slug'] !== $existing['slug']) {
            cmsSaveSlugRedirect($id, (string)$existing['slug']);
        }

        $fields[] = 'updated_at = NOW()';
        $setStr = implode(', ', $fields);
        $db->prepare("UPDATE cms_content SET {$setStr} WHERE id = :id")->execute($bind);

        // Recalculate reading stats when body or blocks change
        $bodyChanged = array_key_exists('body', $input) || array_key_exists('blocks', $input) || array_key_exists('blocks_json', $input);
        if ($bodyChanged) {
            $newBody       = $bind[':body'] ?? ($existing['body'] ?? null);
            $newBlocksJson = $bind[':blocks_json'] ?? ($existing['blocks_json'] ?? null);
            $wc = cmsCalculateWordCount($newBody, $newBlocksJson);
            $rt = ($cmsSettings['reading_time_enabled'] ?? '1') === '1' ? cmsCalculateReadingTime($wc) : 0;
            $db->prepare("UPDATE cms_content SET word_count = :wc, reading_time = :rt WHERE id = :id")
               ->execute([':wc' => $wc, ':rt' => $rt, ':id' => $id]);
        }
    }

    // Sync media usage
    $updatedFeaturedImageId = $bind[':fimg'] ?? ($existing['featured_image_id'] ?? null);
    $updatedBlocksJson = $bind[':blocks_json'] ?? ($existing['blocks_json'] ?? null);
    cmsSyncMediaUsage($id, ['featured_image_id' => $updatedFeaturedImageId], $updatedBlocksJson);

    // Save meta if provided
    if (!empty($input['meta']) && is_array($input['meta'])) {
        $metaToSave = $input['meta'];
        cmsSanitizeRichTextMeta($metaToSave, (string)($existing['type'] ?? 'post'));
        cmsSaveMeta($db, $id, $metaToSave);
    }

    // Sync categories
    if (isset($input['category_ids']) && is_array($input['category_ids'])) {
        cmsSyncContentCategories($id, $input['category_ids']);
    }

    // Sync tags
    if (isset($input['tag_names']) && is_array($input['tag_names'])) {
        cmsSyncContentTags($id, $input['tag_names']);
    }

    $presetApplied = false;
    if ($entityPresetId !== '') {
        cmsApplyEntityPreset($id, $entityPresetId);
        $presetApplied = true;
    }

    // Audit
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module'      => 'cms',
            'action'      => 'content.update',
            'entity_type' => 'cms_content',
            'entity_id'   => (string)$id,
            'old_data'    => ['title' => $existing['title'], 'status' => $existing['status']],
            'new_data'    => $input,
        ]);
    } catch (Throwable $e) {}

    // Event + cache invalidation
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.updated', [
            'content_id' => $id,
            'title'      => $bind[':title'] ?? $existing['title'],
            'slug'       => $bind[':slug'] ?? $existing['slug'],
            'type'       => $existing['type'],
        ]);
    }
    cmsCacheInvalidateContent($existing);
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);

    echo json_encode([
        'ok' => true,
        'preset_applied' => $presetApplied,
        'entity_preset_id' => $entityPresetId !== '' ? $entityPresetId : null,
    ]);
    exit;
}

function cmsApiContentTrash(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.trash');
    $id   = (int)($params['id'] ?? 0);

    $db = cmsDb();
    $stmt = $db->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $existing)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $db->prepare("UPDATE cms_content SET status = 'trash', deleted_at = NOW(), updated_at = NOW() WHERE id = :id")
       ->execute([':id' => $id]);

    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms', 'action' => 'content.trash',
            'entity_type' => 'cms_content', 'entity_id' => (string)$id,
            'old_data' => ['status' => $existing['status']],
        ]);
    } catch (Throwable $e) {}

    // Event + cache invalidation
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.deleted', [
            'content_id' => $id,
            'slug'       => $existing['slug'],
            'type'       => $existing['type'],
        ]);
    }
    cmsCacheInvalidateContent($existing);
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);

    echo json_encode(['ok' => true]);
    exit;
}

function cmsApiContentPublish(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.publish');
    $id   = (int)($params['id'] ?? 0);

    if (!cmsCanPublish($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cannot publish']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $existing)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $db->prepare("UPDATE cms_content SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW() WHERE id = :id")
       ->execute([':id' => $id]);

    // Auto-publish builder document if builder is enabled and a draft exists
    $meta = cmsLoadContentMeta($db, $id);
    if (cmsPageBuilderEnabled($meta)) {
        $draft = cmsBuilderLoadDocumentRow($id, 'draft');
        if ($draft && !empty($draft['document_json'])) {
            $document = cmsBuilderNormalizeDocument((string)$draft['document_json']);
            $actorId = (int)($user['id'] ?? 0);
            $title = trim((string)($existing['title'] ?? 'Untitled'));
            try {
                $publishedId = cmsBuilderPersistDocument($id, $document, 'published', $title, $actorId);
                cmsBuilderCreateRevision($publishedId, $document, $actorId, 'Auto-published with content');
                $db->prepare("UPDATE cms_content SET builder_document_id = :doc_id WHERE id = :id")
                    ->execute([':doc_id' => $publishedId, ':id' => $id]);
            } catch (\Throwable $e) {
                // Log but don't fail the content publish
                if (function_exists('app')) {
                    app()->log('warning', 'Auto-publish builder document failed for content ' . $id . ': ' . $e->getMessage());
                }
            }
        }
    }

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.published', [
            'content_id' => $id,
            'title'      => $existing['title'],
            'slug'       => $existing['slug'],
            'type'       => $existing['type'],
        ]);
    }
    cmsCacheInvalidateContent($existing);
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);

    echo json_encode(['ok' => true]);
    exit;
}

function cmsApiContentRestore(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.restore');
    $id   = (int)($params['id'] ?? 0);

    $db = cmsDb();
    $db->prepare("UPDATE cms_content SET status = 'draft', deleted_at = NULL, updated_at = NOW() WHERE id = :id")
       ->execute([':id' => $id]);

    // Invalidate cache for the restored content
    $restored = $db->prepare("SELECT id, slug, type FROM cms_content WHERE id = :id LIMIT 1");
    $restored->execute([':id' => $id]);
    $restoredRow = $restored->fetch(PDO::FETCH_ASSOC);
    if ($restoredRow) {
        cmsCacheInvalidateContent($restoredRow);
    }
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);

    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// MEDIA API HANDLERS
// ═══════════════════════════════════════════════════════════════════════

function cmsApiContentAutosave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.autosave');
    $id = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid content ID']);
        exit;
    }

    $db = cmsDb();
    $existing = $db->prepare("SELECT id, title, body, blocks_json, author_id FROM cms_content WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Content not found']);
        exit;
    }

    if (!cmsCanEditContent($user, $row)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $input = cmsInput();
    $title = trim((string)($input['title'] ?? ''));
    $body = $input['body'] ?? null;
    $blocksJson = null;
    if (isset($input['blocks']) && is_array($input['blocks'])) {
        $blocksJson = json_encode($input['blocks']);
    }

    // Save as a revision with autosave note
    $revId = cmsSaveRevision(
        $id,
        (int)($user['id'] ?? 0),
        $title !== '' ? $title : $row['title'],
        $body,
        $blocksJson,
        'Autosave'
    );

    echo json_encode(['ok' => true, 'revision_id' => $revId, 'saved_at' => date('Y-m-d H:i:s')]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC SEARCH
// ═══════════════════════════════════════════════════════════════════════

// cmsEnsureUniqueSlug is defined in helpers/15-utils.php and loaded with the module helpers.

function cmsSaveMeta(object $db, int $contentId, array $meta): void
{
    foreach ($meta as $key => $value) {
        $key = trim((string)$key);
        if ($key === '') continue;
        $db->prepare(
            "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:cid, :k, :v)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        )->execute([':cid' => $contentId, ':k' => $key, ':v' => $value]);
    }
}

function cmsLoadContentMeta(object $db, int $contentId): array
{
    $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = :cid");
    $metaStmt->execute([':cid' => $contentId]);

    $meta = [];
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[$row['meta_key']] = $row['meta_value'];
    }

    return $meta;
}

// ═══════════════════════════════════════════════════════════════════════
// THEME CUSTOMIZER HANDLERS
// ═══════════════════════════════════════════════════════════════════════

/**
 * Admin page: Theme Customizer
 * Renders the customizer admin template with Alpine.js/Tailwind UI.
 */

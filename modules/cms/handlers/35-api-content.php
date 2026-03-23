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
        $where[] = '(c.title LIKE :q OR c.excerpt LIKE :q)';
        $bind[':q'] = '%' . $q . '%';
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
    $stmt = $db->prepare(
        "INSERT INTO cms_content
            (uuid, title, slug, body, blocks_json, excerpt,
             type, status, author_id, parent_id,
             comment_status, is_sticky, is_featured, post_format,
             password, word_count, reading_time,
             published_at, created_at)
         VALUES
            (:uuid, :title, :slug, :body, :blocks_json, :excerpt,
             :type, :status, :author_id, :parent_id,
             :comment_status, :is_sticky, :is_featured, :post_format,
             :password, :word_count, :reading_time,
             :pub, NOW())"
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
    cmsSyncMediaUsage($contentId, ['featured_image_id' => $input['featured_image_id'] ?? null], $blocksJson);

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

    echo json_encode(['ok' => true, 'id' => $contentId, 'slug' => $slug]);
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

    $cmsSettings = readCmsSettings();

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

    if (empty($fields)) {
        echo json_encode(['ok' => true, 'message' => 'No changes']);
        exit;
    }

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
    $bodyChanged   = array_key_exists('body', $input) || array_key_exists('blocks', $input) || array_key_exists('blocks_json', $input);
    if ($bodyChanged) {
        $newBody       = $bind[':body'] ?? ($existing['body'] ?? null);
        $newBlocksJson = $bind[':blocks_json'] ?? ($existing['blocks_json'] ?? null);
        $wc = cmsCalculateWordCount($newBody, $newBlocksJson);
        $rt = ($cmsSettings['reading_time_enabled'] ?? '1') === '1' ? cmsCalculateReadingTime($wc) : 0;
        $db->prepare("UPDATE cms_content SET word_count = :wc, reading_time = :rt WHERE id = :id")
           ->execute([':wc' => $wc, ':rt' => $rt, ':id' => $id]);
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

    echo json_encode(['ok' => true]);
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

function cmsEnsureUniqueSlug(string $slug, string $type, ?int $excludeId = null): string
{
    $db = cmsDb();
    $base = $slug;
    $counter = 1;

    while (true) {
        $sql = "SELECT COUNT(*) FROM cms_content WHERE type = :type AND slug = :slug";
        $bind = [':type' => $type, ':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= " AND id != :eid";
            $bind[':eid'] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

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

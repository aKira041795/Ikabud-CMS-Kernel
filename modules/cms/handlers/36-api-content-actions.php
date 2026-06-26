<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════
// CONTENT DUPLICATE
// ═══════════════════════════════════════════════════════════════════════

/**
 * POST /api/v1/cms/content/{id}/duplicate
 *
 * Clones content row + meta + categories + tags into a new draft.
 * Builder documents are NOT copied (the clone starts as a classic editor or fresh builder page).
 * Returns: { ok: true, id: <new_id>, slug: <new_slug> }
 */
function cmsApiContentDuplicate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.duplicate');
    $id   = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $original = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$original) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $original)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $newTitle = 'Copy of ' . (string)($original['title'] ?? 'Untitled');
    $newSlug  = cmsEnsureUniqueSlug(cmsSlugify($newTitle), (string)($original['type'] ?? 'post'));
    $newUuid  = cmsUuid();
    $authorId = (int)($user['id'] ?? 0);

    $ins = $db->prepare(
        "INSERT INTO cms_content
            (uuid, title, slug, body, blocks_json, excerpt,
             type, status, author_id, parent_id,
             comment_status, is_sticky, is_featured, post_format,
             word_count, reading_time,
             created_at)
         VALUES
            (:uuid, :title, :slug, :body, :blocks_json, :excerpt,
             :type, 'draft', :author_id, :parent_id,
             :comment_status, :is_sticky, :is_featured, :post_format,
             :word_count, :reading_time,
             NOW())"
    );
    $ins->execute([
        ':uuid'          => $newUuid,
        ':title'         => $newTitle,
        ':slug'          => $newSlug,
        ':body'          => $original['body'] ?? null,
        ':blocks_json'   => $original['blocks_json'] ?? null,
        ':excerpt'       => $original['excerpt'] ?? null,
        ':type'          => $original['type'] ?? 'post',
        ':author_id'     => $authorId,
        ':parent_id'     => $original['parent_id'] ?? null,
        ':comment_status'=> $original['comment_status'] ?? 'open',
        ':is_sticky'     => 0, // duplicates are never sticky by default
        ':is_featured'   => 0,
        ':post_format'   => $original['post_format'] ?? 'standard',
        ':word_count'    => (int)($original['word_count'] ?? 0),
        ':reading_time'  => (int)($original['reading_time'] ?? 0),
    ]);
    $newId = (int)$db->lastInsertId();

    // Copy meta (skip builder-specific keys so clone starts clean)
        $skipMetaKeys = ['_page_builder_enabled', '_builder_document_id', '_builder_page_settings', '_builder_seo_settings', '_edit_lock'];
    $metaStmt = $db->prepare("SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = :cid");
    $metaStmt->execute([':cid' => $id]);
    $ins2 = $db->prepare(
        "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:cid, :k, :v)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
    );
    foreach ($metaStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        if (in_array($row['meta_key'], $skipMetaKeys, true)) {
            continue;
        }
        $ins2->execute([':cid' => $newId, ':k' => $row['meta_key'], ':v' => $row['meta_value']]);
    }

    // Copy categories
    $catStmt = $db->prepare("SELECT category_id FROM cms_content_categories WHERE content_id = :cid");
    $catStmt->execute([':cid' => $id]);
    $catIds = array_column($catStmt->fetchAll(\PDO::FETCH_ASSOC), 'category_id');
    if (!empty($catIds)) {
        cmsSyncContentCategories($newId, $catIds);
    }

    // Copy tags
    $tagStmt = $db->prepare(
        "SELECT t.name FROM cms_tags t
         INNER JOIN cms_content_tags ct ON ct.tag_id = t.id
         WHERE ct.content_id = :cid"
    );
    $tagStmt->execute([':cid' => $id]);
    $tagNames = array_column($tagStmt->fetchAll(\PDO::FETCH_ASSOC), 'name');
    if (!empty($tagNames)) {
        cmsSyncContentTags($newId, $tagNames);
    }

    // Audit
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module'      => 'cms',
            'action'      => 'content.duplicate',
            'entity_type' => 'cms_content',
            'entity_id'   => (string)$newId,
            'new_data'    => ['source_id' => $id, 'title' => $newTitle, 'type' => $original['type']],
        ]);
    } catch (\Throwable $e) {
        write_log('Audit record creation (duplicate): ' . $e->getMessage(), 'warning');
    }

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.created', [
            'content_id' => $newId,
            'title'      => $newTitle,
            'type'       => $original['type'],
            'author_id'  => $authorId,
            'source'     => 'duplicate',
        ]);
    }

    echo json_encode(['ok' => true, 'id' => $newId, 'slug' => $newSlug]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// CONTENT BULK OPERATIONS
// ═══════════════════════════════════════════════════════════════════════

/**
 * POST /api/v1/cms/content/bulk
 *
 * Body: { action: 'trash'|'publish'|'restore'|'delete_permanent', ids: [1,2,...] }
 *
 * Permissions:
 *   - trash / restore / publish  → editor
 *   - delete_permanent           → administrator
 *
 * Returns: { ok: true, results: { <id>: { ok: true|false, error?: '...' } } }
 */
function cmsApiContentBulk(array $params = []): void
{
    header('Content-Type: application/json');
    $input  = cmsInput();
    $action = trim((string)($input['action'] ?? ''));
    $ids    = is_array($input['ids'] ?? null) ? array_map('intval', $input['ids']) : [];

    $allowedActions = ['trash', 'publish', 'restore', 'delete_permanent'];
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid action. Allowed: ' . implode(', ', $allowedActions)]);
        exit;
    }
    if (empty($ids)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'ids array is required and must not be empty']);
        exit;
    }
    if (count($ids) > 100) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Maximum 100 items per bulk operation']);
        exit;
    }

    // Require role based on action
    $user    = cmsRequireCap('content.bulk_actions');

    $db      = cmsDb();
    $results = [];

    foreach ($ids as $id) {
        if ($id <= 0) {
            $results[$id] = ['ok' => false, 'error' => 'Invalid ID'];
            continue;
        }

        // Fetch the item
        $stmt = $db->prepare("SELECT * FROM cms_content WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $results[$id] = ['ok' => false, 'error' => 'Not found'];
            continue;
        }
        if (!cmsCanEditContent($user, $row)) {
            $results[$id] = ['ok' => false, 'error' => 'Permission denied'];
            continue;
        }

        try {
            switch ($action) {
                case 'trash':
                    $db->prepare(
                        "UPDATE cms_content SET status = 'trash', deleted_at = NOW(), updated_at = NOW() WHERE id = :id"
                    )->execute([':id' => $id]);
                    cmsCacheInvalidateContent($row);
                    $results[$id] = ['ok' => true];
                    break;

                case 'publish':
                    if (!cmsCanPublish($user)) {
                        $results[$id] = ['ok' => false, 'error' => 'Cannot publish'];
                        break;
                    }
                    $db->prepare(
                        "UPDATE cms_content SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW(), deleted_at = NULL WHERE id = :id"
                    )->execute([':id' => $id]);
                    cmsCacheInvalidateContent($row);
                    $results[$id] = ['ok' => true];
                    break;

                case 'restore':
                    $db->prepare(
                        "UPDATE cms_content SET status = 'draft', deleted_at = NULL, updated_at = NOW() WHERE id = :id"
                    )->execute([':id' => $id]);
                    cmsCacheInvalidateContent($row);
                    $results[$id] = ['ok' => true];
                    break;

                case 'delete_permanent':
                    // Hard-delete content + meta + taxonomy + media usage
                    $db->prepare("DELETE FROM cms_content_meta WHERE content_id = :id")->execute([':id' => $id]);
                    $db->prepare("DELETE FROM cms_content_categories WHERE content_id = :id")->execute([':id' => $id]);
                    $db->prepare("DELETE FROM cms_content_tags WHERE content_id = :id")->execute([':id' => $id]);
                    $db->prepare("DELETE FROM cms_media_usage WHERE content_id = :id")->execute([':id' => $id]);
                    $db->prepare("DELETE FROM cms_content WHERE id = :id")->execute([':id' => $id]);
                    cmsCacheInvalidateContent($row);
                    $results[$id] = ['ok' => true];
                    break;
            }

            // Audit each item
            try {
                app()->cap()->call('kernel.audit.record@1', [
                    'module'      => 'cms',
                    'action'      => 'content.bulk.' . $action,
                    'entity_type' => 'cms_content',
                    'entity_id'   => (string)$id,
                    'old_data'    => ['status' => $row['status']],
                ]);
            } catch (\Throwable $ae) {
                write_log('Audit record creation (bulk): ' . $ae->getMessage(), 'warning');
            }

        } catch (\Throwable $e) {
            $results[$id] = ['ok' => false, 'error' => 'Database error'];
        }
    }

    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.bulk', [
            'action'  => $action,
            'ids'     => $ids,
            'success' => $successCount,
        ]);
    }

    echo json_encode(['ok' => true, 'results' => $results, 'success' => $successCount, 'total' => count($ids)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// CONTENT PERMALINK
// ═══════════════════════════════════════════════════════════════════════

/**
 * GET /api/v1/cms/content/{id}/permalink
 *
 * Returns the public URL and preview URL for a content item.
 * Used by the editor to provide "View / Preview" links.
 */
function cmsApiContentPermalink(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.read');
    $id   = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db   = cmsDb();
    $stmt = $db->prepare(
        "SELECT id, title, slug, type, status FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $permalink   = cmsContentPermalink($row);
    $previewLink = $permalink . '?preview=1';

    echo json_encode([
        'ok'           => true,
        'permalink'    => $permalink,
        'preview_link' => $previewLink,
        'status'       => $row['status'],
        'slug'         => $row['slug'],
        'type'         => $row['type'],
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// SCHEDULED CONTENT PUBLISHER
// ═══════════════════════════════════════════════════════════════════════

/**
 * POST /api/v1/cms/content/publish-scheduled
 *
 * Publishes all content items past their scheduled published_at date.
 * Intended to be called from a cron job or the kernel scheduler.
 * Requires administrator role.
 *
 * Returns: { ok: true, published: [<ids>] }
 */
function cmsApiContentPublishScheduled(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content.schedule');

    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT id, title, slug, type, status FROM cms_content
         WHERE status = 'scheduled'
           AND published_at IS NOT NULL
           AND published_at <= NOW()
           AND deleted_at IS NULL"
    );
    $stmt->execute();
    $due = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $published = [];
    foreach ($due as $row) {
        try {
            $db->prepare(
                "UPDATE cms_content SET status = 'published', updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $row['id']]);
            cmsCacheInvalidateContent($row);
            $published[] = (int)$row['id'];

            // Fire publish event
            if ($ctx = module('cms')) {
                $ctx->fireEvent('cms.content.published', [
                    'content_id' => (int)$row['id'],
                    'title'      => $row['title'],
                    'slug'       => $row['slug'],
                    'type'       => $row['type'],
                    'source'     => 'scheduled',
                ]);
            }

            try {
                app()->cap()->call('kernel.audit.record@1', [
                    'module'      => 'cms',
                    'action'      => 'content.auto_published',
                    'entity_type' => 'cms_content',
                    'entity_id'   => (string)$row['id'],
                    'new_data'    => ['status' => 'published', 'source' => 'scheduled'],
                ]);
            } catch (\Throwable $ae) {
                write_log('Audit record creation (auto_publish): ' . $ae->getMessage(), 'warning');
            }
        } catch (\Throwable $e) {
            // Log but keep processing remaining items
            write_log('warning', 'Failed auto-publishing content ' . $row['id'] . ': ' . $e->getMessage(), 'cms');
        }
    }

    echo json_encode(['ok' => true, 'published' => $published, 'count' => count($published)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// EMPTY TRASH
// ═══════════════════════════════════════════════════════════════════════

/**
 * POST /api/v1/cms/content/empty-trash
 *
 * Permanently deletes all items currently in the trash.
 * Optional body param: { type: 'post'|'page'|'...' } — scope to a single content type.
 * Requires administrator role.
 */
function cmsApiEmptyTrash(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('content.bulk_actions');
    $role = (string)($user['role'] ?? '');

    if (!cmsRoleAtLeast($role, 'administrator')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Requires administrator role']);
        exit;
    }

    $input = cmsInput();
    $type  = trim((string)($input['type'] ?? ''));

    $db = cmsDb();
    $deleted = 0;

    try {
        $where = ['deleted_at IS NOT NULL'];
        $bind  = [];
        if ($type !== '') {
            $where[] = 'type = :type';
            $bind[':type'] = $type;
        }
        $whereStr = implode(' AND ', $where);

        $idStmt = $db->prepare("SELECT id FROM cms_content WHERE {$whereStr}");
        $idStmt->execute($bind);
        $ids = array_column($idStmt->fetchAll(\PDO::FETCH_ASSOC), 'id');

        foreach ($ids as $id) {
            $db->prepare("DELETE FROM cms_content_meta WHERE content_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM cms_content_categories WHERE content_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM cms_content_tags WHERE content_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM cms_media_usage WHERE content_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM cms_content WHERE id = :id")->execute([':id' => $id]);
            $deleted++;
        }

        adminViewCacheInvalidate(['cms:admin', 'cms:admin:dashboard', 'cms:admin:content']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }

    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module'      => 'cms',
            'action'      => 'content.empty_trash',
            'entity_type' => 'cms_content',
            'entity_id'   => '0',
            'old_data'    => ['type' => $type ?: 'all', 'deleted' => $deleted],
        ]);
    } catch (\Throwable $e) {
        write_log('Audit record creation (empty_trash): ' . $e->getMessage(), 'warning');
    }

    echo json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
}

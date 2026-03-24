<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════
// IMPORT / EXPORT — Content data portability
// ═══════════════════════════════════════════════════════════════════════

/**
 * Admin page for import/export.
 */
function cmsAdminImportExport(array $params = []): void
{
    $user = cmsRequireCap('import_export.manage');

    // Counts for the stats display
    $db = cmsDb();
    $postCount = (int)($db->query("SELECT COUNT(*) FROM cms_content WHERE type='post' AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $pageCount = (int)($db->query("SELECT COUNT(*) FROM cms_content WHERE type='page' AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $catCount  = (int)($db->query("SELECT COUNT(*) FROM cms_categories")->fetchColumn() ?: 0);
    $tagCount  = (int)($db->query("SELECT COUNT(*) FROM cms_tags")->fetchColumn() ?: 0);
    $mediaCount = (int)($db->query("SELECT COUNT(*) FROM cms_media")->fetchColumn() ?: 0);

    echo cmsRender('modules/cms/admin/import-export.disyl', array_merge(cmsAdminContext($user, 'import_export', [
        ['label' => 'Import / Export', 'url' => ''],
    ]), [
        'page_title'  => 'Import / Export',
        'post_count'  => $postCount,
        'page_count'  => $pageCount,
        'cat_count'   => $catCount,
        'tag_count'   => $tagCount,
        'media_count' => $mediaCount,
    ]));
}

// ── Export API ────────────────────────────────────────────────────────

/**
 * GET /api/v1/cms/export — returns JSON file download of CMS content.
 *
 * Query params:
 *   types=post,page (default: all)
 *   include_categories=1 (default: 1)
 *   include_tags=1 (default: 1)
 *   include_media_meta=1 (default: 0)
 */
function cmsApiExport(array $params = []): void
{
    cmsRequireCap('import_export.manage');

    $typeFilter = trim((string)($_GET['types'] ?? ''));
    $types = $typeFilter !== '' ? array_map('trim', explode(',', $typeFilter)) : [];
    $includeCats = ((int)($_GET['include_categories'] ?? 1)) === 1;
    $includeTags = ((int)($_GET['include_tags'] ?? 1)) === 1;
    $includeMediaMeta = ((int)($_GET['include_media_meta'] ?? 0)) === 1;

    $db = cmsDb();

    // ── Content ──
    $sql = "SELECT id, title, slug, type, body, excerpt, status, author_id,
                   featured_image_id, published_at, created_at, updated_at,
                   blocks_json, content_mode, post_format
            FROM cms_content WHERE deleted_at IS NULL";
    $sqlParams = [];
    if (!empty($types)) {
        $placeholders = [];
        foreach ($types as $i => $t) {
            $key = ":type{$i}";
            $placeholders[] = $key;
            $sqlParams[$key] = $t;
        }
        $sql .= " AND type IN (" . implode(',', $placeholders) . ")";
    }
    $sql .= " ORDER BY id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($sqlParams);
    $content = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Parse blocks_json for each content item
    foreach ($content as &$c) {
        if (!empty($c['blocks_json']) && is_string($c['blocks_json'])) {
            $decoded = json_decode($c['blocks_json'], true);
            $c['blocks_json'] = is_array($decoded) ? $decoded : null;
        }
    }
    unset($c);

    // ── Categories ──
    $categories = [];
    $contentCategories = [];
    if ($includeCats) {
        $categories = $db->query("SELECT id, name, slug, description, parent_id FROM cms_categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $contentCategories = $db->query("SELECT content_id, category_id FROM cms_content_categories")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Tags ──
    $tags = [];
    $contentTags = [];
    if ($includeTags) {
        $tags = $db->query("SELECT id, name, slug FROM cms_tags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $contentTags = $db->query("SELECT content_id, tag_id FROM cms_content_tags")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Media meta ──
    $media = [];
    if ($includeMediaMeta) {
        $media = $db->query(
            "SELECT id, file_path, original_name, mime_type, file_size, alt_text, created_at
             FROM cms_media ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $export = [
        'cms_export_version' => '1.0',
        'exported_at'        => date('Y-m-d\TH:i:s\Z'),
        'content'            => $content,
        'categories'         => $categories,
        'content_categories' => $contentCategories,
        'tags'               => $tags,
        'content_tags'       => $contentTags,
        'media'              => $media,
    ];

    $filename = 'cms-export-' . date('Y-m-d-His') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Import API ───────────────────────────────────────────────────────

/**
 * POST /api/v1/cms/import — imports CMS JSON exports or WordPress XML/WXR.
 *
 * Expects multipart form with field "file".
 * Body param: mode=merge|replace (default: merge)
 *   merge: skip existing slugs
 *   replace: overwrite existing by slug match
 */
function cmsApiImport(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('import_export.manage');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No valid file uploaded']);
        exit;
    }

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    if (!is_string($raw) || trim($raw) === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Uploaded file is empty']);
        exit;
    }

    $mode = trim((string)($_POST['mode'] ?? 'merge'));

    if (!in_array($mode, ['merge', 'replace'], true)) {
        $mode = 'merge';
    }

    try {
        $format = cmsDetectImportFormat($raw, (string)($_FILES['file']['name'] ?? ''));
        if ($format === 'json') {
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['content'])) {
                throw new InvalidArgumentException('Invalid JSON export file or empty content');
            }
        } else {
            $data = cmsParseWordPressWxr($raw);
            if (empty($data['content'])) {
                throw new InvalidArgumentException('WordPress XML file contains no importable posts or pages');
            }
        }

        $stats = cmsImportStructuredPayload($data, $mode, (int)($user['id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        write_log('CMS import failed: ' . $e->getMessage(), 'error', ['source' => 'cms.import']);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Import failed']);
        exit;
    }

    echo json_encode(['ok' => true, 'format' => $format, 'stats' => $stats]);
    exit;
}

function cmsDetectImportFormat(string $raw, string $filename = ''): string
{
    $trimmed = ltrim($raw);
    if ($trimmed === '') {
        throw new InvalidArgumentException('Uploaded file is empty');
    }

    $firstChar = $trimmed[0] ?? '';
    if ($firstChar === '{' || $firstChar === '[') {
        return 'json';
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        return 'json';
    }
    if ($ext === 'xml') {
        return 'wordpress-xml';
    }

    if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<rss') || str_starts_with($trimmed, '<')) {
        return 'wordpress-xml';
    }

    throw new InvalidArgumentException('Unsupported import file. Upload a CMS JSON export or WordPress XML export.');
}

function cmsImportStructuredPayload(array $data, string $mode, int $defaultAuthorId = 0): array
{
    $db = cmsDb();
    $resolvedAuthorId = cmsResolveImportAuthorId($defaultAuthorId);
    $stats = ['imported' => 0, 'skipped' => 0, 'updated' => 0, 'errors' => 0, 'categories_imported' => 0, 'tags_imported' => 0];

    $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
    $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
    $content = is_array($data['content'] ?? null) ? $data['content'] : [];

    $catIdMap = [];
    $findCategory = $db->prepare("SELECT id FROM cms_categories WHERE slug = :slug LIMIT 1");
    $insertCategory = $db->prepare(
        "INSERT INTO cms_categories (name, slug, description, parent_id) VALUES (:name, :slug, :desc, NULL)"
    );
    foreach ($categories as $index => $cat) {
        $slug = trim((string)($cat['slug'] ?? ''));
        $name = trim((string)($cat['name'] ?? ''));
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            continue;
        }
        if ($name === '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }
        $oldId = (int)($cat['id'] ?? ($index + 1));

        $findCategory->execute([':slug' => $slug]);
        $row = $findCategory->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $catIdMap[$oldId] = (int)$row['id'];
            continue;
        }

        $insertCategory->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':desc' => (string)($cat['description'] ?? ''),
        ]);
        $catIdMap[$oldId] = (int)$db->lastInsertId();
        $stats['categories_imported']++;
    }

    $updateCategoryParent = $db->prepare("UPDATE cms_categories SET parent_id = :parent_id WHERE id = :id");
    foreach ($categories as $index => $cat) {
        $oldId = (int)($cat['id'] ?? ($index + 1));
        $newId = $catIdMap[$oldId] ?? null;
        $newParentId = null;
        if (!empty($cat['parent_id'])) {
            $newParentId = $catIdMap[(int)$cat['parent_id']] ?? null;
        }
        if ($newId) {
            $updateCategoryParent->execute([':parent_id' => $newParentId, ':id' => $newId]);
        }
    }

    $tagIdMap = [];
    $tagNameByOldId = [];
    $findTag = $db->prepare("SELECT id FROM cms_tags WHERE slug = :slug LIMIT 1");
    $insertTag = $db->prepare("INSERT INTO cms_tags (name, slug) VALUES (:name, :slug)");
    foreach ($tags as $index => $tag) {
        $slug = trim((string)($tag['slug'] ?? ''));
        $name = trim((string)($tag['name'] ?? ''));
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            continue;
        }
        if ($name === '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }

        $oldId = (int)($tag['id'] ?? ($index + 1));
        $tagNameByOldId[$oldId] = $name;

        $findTag->execute([':slug' => $slug]);
        $row = $findTag->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tagIdMap[$oldId] = (int)$row['id'];
            continue;
        }

        $insertTag->execute([':name' => $name, ':slug' => $slug]);
        $tagIdMap[$oldId] = (int)$db->lastInsertId();
        $stats['tags_imported']++;
    }

    $contentCatMap = [];
    foreach ((array)($data['content_categories'] ?? []) as $cc) {
        $contentCatMap[(int)($cc['content_id'] ?? 0)][] = (int)($cc['category_id'] ?? 0);
    }

    $contentTagMap = [];
    foreach ((array)($data['content_tags'] ?? []) as $ct) {
        $contentTagMap[(int)($ct['content_id'] ?? 0)][] = (int)($ct['tag_id'] ?? 0);
    }

    $findExistingContent = $db->prepare("SELECT id FROM cms_content WHERE slug = :slug AND type = :type AND deleted_at IS NULL LIMIT 1");
    $updateExistingContent = $db->prepare(
        "UPDATE cms_content SET title = :title, body = :body, excerpt = :excerpt, status = :status,
                published_at = :pub, blocks_json = :bj, updated_at = NOW()
         WHERE id = :id"
    );
    $insertContent = $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, type, body, excerpt, status, author_id, published_at, blocks_json, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :type, :body, :excerpt, :status, :author_id, :pub, :bj, NOW(), NOW())"
    );

    foreach ($content as $index => $item) {
        $slug = trim((string)($item['slug'] ?? ''));
        $type = trim((string)($item['type'] ?? 'post'));
        $title = trim((string)($item['title'] ?? ''));
        if ($slug === '') {
            $slug = cmsSlugify($title);
        }
        if ($slug === '' || $title === '') {
            $stats['errors']++;
            continue;
        }

        $blocksJson = null;
        if (!empty($item['blocks_json'])) {
            $blocksJson = is_array($item['blocks_json']) ? json_encode($item['blocks_json']) : (string)$item['blocks_json'];
        }

        $status = cmsNormalizeImportedStatus((string)($item['status'] ?? 'draft'));
        $publishedAt = cmsNormalizeImportedDate($item['published_at'] ?? null);
        $oldId = (int)($item['id'] ?? ($index + 1));

        $findExistingContent->execute([':slug' => $slug, ':type' => $type]);
        $existingRow = $findExistingContent->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
            if ($mode === 'merge') {
                $stats['skipped']++;
                continue;
            }

            $updateExistingContent->execute([
                ':title'   => $title,
                ':body'    => (string)($item['body'] ?? ''),
                ':excerpt' => (string)($item['excerpt'] ?? ''),
                ':status'  => $status,
                ':pub'     => $publishedAt,
                ':bj'      => $blocksJson,
                ':id'      => $existingRow['id'],
            ]);
            $newId = (int)$existingRow['id'];
            $stats['updated']++;
        } else {
            try {
                $insertContent->execute([
                    ':uuid'      => cmsUuid(),
                    ':title'     => $title,
                    ':slug'      => cmsEnsureUniqueSlug($slug, $type),
                    ':type'      => $type,
                    ':body'      => (string)($item['body'] ?? ''),
                    ':excerpt'   => (string)($item['excerpt'] ?? ''),
                    ':status'    => $status,
                    ':author_id' => $resolvedAuthorId,
                    ':pub'       => $publishedAt,
                    ':bj'        => $blocksJson,
                ]);
                $newId = (int)$db->lastInsertId();
                $stats['imported']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                write_log("Import content failed for slug '{$slug}': " . $e->getMessage(), 'error', ['source' => 'cms.import']);
                continue;
            }
        }

        $resolvedCategoryIds = [];
        foreach ((array)($item['category_ids'] ?? ($contentCatMap[$oldId] ?? [])) as $oldCatId) {
            $mappedId = $catIdMap[(int)$oldCatId] ?? null;
            if ($mappedId) {
                $resolvedCategoryIds[] = (int)$mappedId;
            }
        }
        cmsSyncContentCategories($newId, array_values(array_unique($resolvedCategoryIds)));

        $resolvedTagNames = [];
        if (!empty($item['tag_names']) && is_array($item['tag_names'])) {
            foreach ($item['tag_names'] as $tagName) {
                $tagName = trim((string)$tagName);
                if ($tagName !== '') {
                    $resolvedTagNames[] = $tagName;
                }
            }
        } else {
            foreach ((array)($contentTagMap[$oldId] ?? []) as $oldTagId) {
                $tagName = trim((string)($tagNameByOldId[(int)$oldTagId] ?? ''));
                if ($tagName !== '') {
                    $resolvedTagNames[] = $tagName;
                }
            }
        }
        cmsSyncContentTags($newId, array_values(array_unique($resolvedTagNames)));
    }

    return $stats;
}

function cmsNormalizeImportedStatus(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'publish', 'published' => 'published',
        'future', 'scheduled' => 'scheduled',
        'private' => 'private',
        default => 'draft',
    };
}

function cmsResolveImportAuthorId(int $preferredAuthorId): int
{
    $db = cmsDb();
    if ($preferredAuthorId > 0) {
        $stmt = $db->prepare("SELECT id FROM cms_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $preferredAuthorId]);
        $matched = $stmt->fetchColumn();
        if ($matched) {
            return (int)$matched;
        }
    }

    $fallback = $db->query("SELECT id FROM cms_users ORDER BY CASE WHEN role IN ('superadmin', 'administrator') THEN 0 ELSE 1 END, id ASC LIMIT 1")
        ->fetchColumn();
    if ($fallback) {
        return (int)$fallback;
    }

    throw new InvalidArgumentException('Import failed because no CMS author account is available');
}

function cmsNormalizeImportedDate(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function cmsParseWordPressWxr(string $raw): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new InvalidArgumentException('WordPress XML import requires the SimpleXML PHP extension');
    }

    $previousErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    if ($xml === false || !isset($xml->channel)) {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        throw new InvalidArgumentException('Invalid WordPress XML export file');
    }

    $channel = $xml->channel;
    $namespaces = $xml->getNamespaces(true);
    $wpNs = $namespaces['wp'] ?? null;
    if ($wpNs === null) {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        throw new InvalidArgumentException('Invalid WordPress XML export: missing wp namespace');
    }

    $contentNs = $namespaces['content'] ?? null;
    $excerptNs = $namespaces['excerpt'] ?? null;
    $channelWp = $channel->children($wpNs);

    $categoriesBySlug = [];
    $categoryIdBySlug = [];
    foreach ($channelWp->category as $categoryNode) {
        $slug = trim((string)$categoryNode->category_nicename);
        $name = trim((string)$categoryNode->cat_name);
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            continue;
        }

        $termId = (int)$categoryNode->term_id;
        if ($termId <= 0) {
            $termId = count($categoriesBySlug) + 1;
        }

        $categoriesBySlug[$slug] = [
            'id' => $termId,
            'name' => $name !== '' ? $name : ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => trim((string)$categoryNode->category_description),
            'parent_slug' => trim((string)$categoryNode->category_parent),
        ];
        $categoryIdBySlug[$slug] = $termId;
    }

    $tagsBySlug = [];
    $tagIdBySlug = [];
    foreach ($channelWp->tag as $tagNode) {
        $slug = trim((string)$tagNode->tag_slug);
        $name = trim((string)$tagNode->tag_name);
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            continue;
        }

        $termId = (int)$tagNode->term_id;
        if ($termId <= 0) {
            $termId = count($tagsBySlug) + 1;
        }

        $tagsBySlug[$slug] = [
            'id' => $termId,
            'name' => $name !== '' ? $name : ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ];
        $tagIdBySlug[$slug] = $termId;
    }

    $content = [];
    $contentCategories = [];
    $contentTags = [];

    foreach ($channel->item as $item) {
        $itemWp = $item->children($wpNs);
        $postType = strtolower(trim((string)$itemWp->post_type));
        if (!in_array($postType, ['post', 'page'], true)) {
            continue;
        }

        $status = cmsNormalizeImportedStatus((string)$itemWp->status);
        $title = trim((string)$item->title);
        $slug = trim((string)$itemWp->post_name);
        if ($slug === '') {
            $slug = cmsSlugify($title);
        }
        if ($slug === '') {
            continue;
        }

        $postId = (int)$itemWp->post_id;
        if ($postId <= 0) {
            $postId = count($content) + 1;
        }

        $contentNode = $contentNs !== null ? $item->children($contentNs) : null;
        $excerptNode = $excerptNs !== null ? $item->children($excerptNs) : null;
        $body = $contentNode !== null ? (string)$contentNode->encoded : (string)$item->description;
        $excerpt = $excerptNode !== null ? trim((string)$excerptNode->encoded) : '';

        $content[] = [
            'id' => $postId,
            'title' => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => $postType,
            'body' => $body,
            'excerpt' => $excerpt,
            'status' => $status,
            'published_at' => cmsNormalizeImportedDate((string)$itemWp->post_date_gmt) ?: cmsNormalizeImportedDate((string)$itemWp->post_date),
            'comment_status' => strtolower(trim((string)$itemWp->comment_status)) === 'closed' ? 'closed' : 'open',
        ];

        foreach ($item->category as $termNode) {
            $domain = strtolower(trim((string)$termNode['domain']));
            $slugName = trim((string)$termNode['nicename']);
            $termName = trim((string)$termNode);

            if ($domain === 'category') {
                if ($slugName === '') {
                    $slugName = cmsSlugify($termName);
                }
                if ($slugName === '') {
                    continue;
                }
                if (!isset($categoriesBySlug[$slugName])) {
                    $termId = count($categoriesBySlug) + 1;
                    $categoriesBySlug[$slugName] = [
                        'id' => $termId,
                        'name' => $termName !== '' ? $termName : ucwords(str_replace('-', ' ', $slugName)),
                        'slug' => $slugName,
                        'description' => '',
                        'parent_slug' => '',
                    ];
                    $categoryIdBySlug[$slugName] = $termId;
                }
                $contentCategories[] = ['content_id' => $postId, 'category_id' => $categoryIdBySlug[$slugName]];
                continue;
            }

            if (in_array($domain, ['post_tag', 'tag'], true)) {
                if ($slugName === '') {
                    $slugName = cmsSlugify($termName);
                }
                if ($slugName === '') {
                    continue;
                }
                if (!isset($tagsBySlug[$slugName])) {
                    $termId = count($tagsBySlug) + 1;
                    $tagsBySlug[$slugName] = [
                        'id' => $termId,
                        'name' => $termName !== '' ? $termName : ucwords(str_replace('-', ' ', $slugName)),
                        'slug' => $slugName,
                    ];
                    $tagIdBySlug[$slugName] = $termId;
                }
                $contentTags[] = ['content_id' => $postId, 'tag_id' => $tagIdBySlug[$slugName]];
            }
        }
    }

    $categories = [];
    foreach ($categoriesBySlug as $slug => $category) {
        $parentSlug = trim((string)($category['parent_slug'] ?? ''));
        $category['parent_id'] = $parentSlug !== '' ? ($categoryIdBySlug[$parentSlug] ?? null) : null;
        unset($category['parent_slug']);
        $categories[] = $category;
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    return [
        'cms_export_version' => 'wordpress-wxr-1.0',
        'content' => $content,
        'categories' => array_values($categories),
        'content_categories' => array_values($contentCategories),
        'tags' => array_values($tagsBySlug),
        'content_tags' => array_values($contentTags),
    ];
}

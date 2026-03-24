<?php

declare(strict_types=1);

function wordpressImporterRenderTemplate(string $relativePath, array $context = []): string
{
    $fullPath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (!is_file($fullPath)) {
        throw new RuntimeException('Template not found: ' . $relativePath);
    }

    $source = (string) file_get_contents($fullPath);
    $app = app();
    $appUrl = (string) $app->config('app.url', '');
    $baseUrl = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?: ''), '/');
    $user = $app->user();

    $baseContext = [
        'user' => $user,
        'is_htmx' => $app->isHtmx() && !$app->isHtmxBoosted(),
        'base_url' => $baseUrl,
        'app_url' => $appUrl,
        'cookie_name' => $app->config('app.cookie_name', 'guidance_token'),
        'csrf_token' => $app->csrfToken(),
        'csrf_field' => $app->csrfField(),
    ];

    return $app->templates()->renderString($source, array_merge($baseContext, $context));
}

function wordpressImporterAdminPage(array $params = []): void
{
    $user = cmsRequireCap('import_export.manage');

    echo wordpressImporterRenderTemplate('templates/admin/wordpress-importer.disyl', array_merge(
        cmsAdminContext($user, 'wordpress_importer', [
            ['label' => 'WordPress Import', 'url' => ''],
        ]),
        [
            'page_title' => 'WordPress Import',
        ]
    ));
}

function wordpressImporterApiImport(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('import_export.manage');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No valid WordPress XML file uploaded']);
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
        $data = wordpressImporterParseWxr($raw);
        if (empty($data['content'])) {
            throw new InvalidArgumentException('WordPress XML file contains no importable posts or pages');
        }

        $stats = wordpressImporterImportStructuredPayload($data, $mode, (int)($user['id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        write_log('WordPress importer failed: ' . $e->getMessage(), 'error', ['source' => 'wordpress-importer']);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'WordPress import failed']);
        exit;
    }

    echo json_encode(['ok' => true, 'stats' => $stats]);
    exit;
}

function wordpressImporterImportStructuredPayload(array $data, string $mode, int $preferredAuthorId = 0): array
{
    $db = cmsDb();
    $resolvedAuthorId = wordpressImporterResolveAuthorId($preferredAuthorId);
    $stats = ['imported' => 0, 'skipped' => 0, 'updated' => 0, 'errors' => 0, 'categories_imported' => 0, 'tags_imported' => 0, 'category_links' => 0, 'tag_links' => 0];

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
        $bodyHtml = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml((string)($item['body'] ?? ''), 'cms.content'), 'cms.content');
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

        $status = wordpressImporterNormalizeStatus((string)($item['status'] ?? 'draft'));
        $publishedAt = wordpressImporterNormalizeDate($item['published_at'] ?? null);
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
                ':body'    => $bodyHtml,
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
                    ':slug'      => wordpressImporterEnsureUniqueSlug($slug, $type),
                    ':type'      => $type,
                    ':body'      => $bodyHtml,
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
                write_log("WordPress import failed for slug '{$slug}': " . $e->getMessage(), 'error', ['source' => 'wordpress-importer']);
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
        $resolvedCategoryIds = array_values(array_unique($resolvedCategoryIds));
        $stats['category_links'] += count($resolvedCategoryIds);
        cmsSyncContentCategories($newId, $resolvedCategoryIds);

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
        $resolvedTagNames = array_values(array_unique($resolvedTagNames));
        $stats['tag_links'] += count($resolvedTagNames);
        cmsSyncContentTags($newId, $resolvedTagNames);
    }

    return $stats;
}

function wordpressImporterEnsureUniqueSlug(string $slug, string $type, ?int $excludeId = null): string
{
    $db = cmsDb();
    $baseSlug = $slug;
    $counter = 1;

    while (true) {
        $sql = 'SELECT COUNT(*) FROM cms_content WHERE type = :type AND slug = :slug';
        $bind = [':type' => $type, ':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $bind[':exclude_id'] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function wordpressImporterResolveAuthorId(int $preferredAuthorId): int
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

function wordpressImporterNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'publish', 'published' => 'published',
        'future', 'scheduled' => 'scheduled',
        'private' => 'private',
        default => 'draft',
    };
}

function wordpressImporterNormalizeDate(mixed $value): ?string
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

function wordpressImporterParseWxr(string $raw): array
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
    $categorySlugByTermId = [];
    $registerCategory = static function (string $slug, string $name, string $description = '', string $parentRef = '', ?int $termId = null) use (&$categoriesBySlug, &$categoryIdBySlug, &$categorySlugByTermId): ?int {
        $slug = trim($slug);
        $name = trim($name);
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            return null;
        }

        $existingId = $categoryIdBySlug[$slug] ?? null;
        $resolvedId = ($termId !== null && $termId > 0) ? $termId : ($existingId ?? (count($categoriesBySlug) + 1));
        $existing = $categoriesBySlug[$slug] ?? [];
        $categoriesBySlug[$slug] = [
            'id' => $resolvedId,
            'name' => $name !== '' ? $name : ($existing['name'] ?? ucwords(str_replace('-', ' ', $slug))),
            'slug' => $slug,
            'description' => $description !== '' ? $description : (string)($existing['description'] ?? ''),
            'parent_ref' => $parentRef !== '' ? $parentRef : (string)($existing['parent_ref'] ?? ''),
        ];
        $categoryIdBySlug[$slug] = $resolvedId;
        if ($resolvedId > 0) {
            $categorySlugByTermId[$resolvedId] = $slug;
        }

        return $resolvedId;
    };
    foreach ($channelWp->category as $categoryNode) {
        $slug = trim((string)$categoryNode->category_nicename);
        $name = trim((string)$categoryNode->cat_name);
        $registerCategory(
            $slug,
            $name,
            trim((string)$categoryNode->category_description),
            trim((string)$categoryNode->category_parent),
            (int)$categoryNode->term_id
        );
    }

    $tagsBySlug = [];
    $tagIdBySlug = [];
    $registerTag = static function (string $slug, string $name, ?int $termId = null) use (&$tagsBySlug, &$tagIdBySlug): ?int {
        $slug = trim($slug);
        $name = trim($name);
        if ($slug === '') {
            $slug = cmsSlugify($name);
        }
        if ($slug === '') {
            return null;
        }

        $existingId = $tagIdBySlug[$slug] ?? null;
        $resolvedId = ($termId !== null && $termId > 0) ? $termId : ($existingId ?? (count($tagsBySlug) + 1));
        $existing = $tagsBySlug[$slug] ?? [];
        $tagsBySlug[$slug] = [
            'id' => $resolvedId,
            'name' => $name !== '' ? $name : ($existing['name'] ?? ucwords(str_replace('-', ' ', $slug))),
            'slug' => $slug,
        ];
        $tagIdBySlug[$slug] = $resolvedId;

        return $resolvedId;
    };
    foreach ($channelWp->tag as $tagNode) {
        $slug = trim((string)$tagNode->tag_slug);
        $name = trim((string)$tagNode->tag_name);
        $registerTag($slug, $name, (int)$tagNode->term_id);
    }

    foreach ($channelWp->term as $termNode) {
        $taxonomy = strtolower(trim((string)$termNode->term_taxonomy));
        $slug = trim((string)$termNode->term_slug);
        $name = trim((string)$termNode->term_name);
        $termId = (int)$termNode->term_id;

        if ($taxonomy === 'category') {
            $registerCategory(
                $slug,
                $name,
                trim((string)$termNode->term_description),
                trim((string)$termNode->term_parent),
                $termId
            );
            continue;
        }

        if (in_array($taxonomy, ['post_tag', 'tag'], true)) {
            $registerTag($slug, $name, $termId);
        }
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

        $status = wordpressImporterNormalizeStatus((string)$itemWp->status);
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
            'published_at' => wordpressImporterNormalizeDate((string)$itemWp->post_date_gmt) ?: wordpressImporterNormalizeDate((string)$itemWp->post_date),
        ];

        foreach ($item->category as $termNode) {
            $domain = strtolower(trim((string)$termNode['domain']));
            $slugName = trim((string)$termNode['nicename']);
            $termName = trim((string)$termNode);

            if ($domain === 'category') {
                $categoryId = $registerCategory($slugName, $termName);
                if ($categoryId === null) {
                    continue;
                }
                $contentCategories[] = ['content_id' => $postId, 'category_id' => $categoryId];
                continue;
            }

            if (in_array($domain, ['post_tag', 'tag'], true)) {
                $tagId = $registerTag($slugName, $termName);
                if ($tagId === null) {
                    continue;
                }
                $contentTags[] = ['content_id' => $postId, 'tag_id' => $tagId];
            }
        }
    }

    $categories = [];
    foreach ($categoriesBySlug as $slug => $category) {
        $parentRef = trim((string)($category['parent_ref'] ?? ''));
        $parentSlug = '';
        if ($parentRef !== '') {
            $parentSlug = ctype_digit($parentRef)
                ? (string)($categorySlugByTermId[(int)$parentRef] ?? '')
                : $parentRef;
        }
        $category['parent_id'] = $parentSlug !== '' ? ($categoryIdBySlug[$parentSlug] ?? null) : null;
        unset($category['parent_ref']);
        $categories[] = $category;
    }

    $contentCategories = array_values(array_reduce($contentCategories, static function (array $carry, array $item): array {
        $key = (int)($item['content_id'] ?? 0) . ':' . (int)($item['category_id'] ?? 0);
        $carry[$key] = $item;
        return $carry;
    }, []));

    $contentTags = array_values(array_reduce($contentTags, static function (array $carry, array $item): array {
        $key = (int)($item['content_id'] ?? 0) . ':' . (int)($item['tag_id'] ?? 0);
        $carry[$key] = $item;
        return $carry;
    }, []));

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
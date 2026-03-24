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
 * POST /api/v1/cms/import — imports JSON export file.
 *
 * Expects multipart form with field "file" (the .json).
 * Body param: mode=merge|replace (default: merge)
 *   merge: skip existing slugs
 *   replace: overwrite existing by slug match
 */
function cmsApiImport(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No valid file uploaded']);
        exit;
    }

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['content'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON export file or empty content']);
        exit;
    }

    $mode = trim((string)($_POST['mode'] ?? 'merge'));
    if (!in_array($mode, ['merge', 'replace'], true)) {
        $mode = 'merge';
    }

    $db = cmsDb();
    $stats = ['imported' => 0, 'skipped' => 0, 'updated' => 0, 'errors' => 0, 'categories_imported' => 0, 'tags_imported' => 0];

    // ── Import categories ──
    $catIdMap = []; // old_id => new_id
    if (!empty($data['categories'])) {
        foreach ($data['categories'] as $cat) {
            $existing = $db->prepare("SELECT id FROM cms_categories WHERE slug = :slug LIMIT 1");
            $existing->execute([':slug' => $cat['slug']]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $catIdMap[(int)$cat['id']] = (int)$row['id'];
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO cms_categories (name, slug, description, parent_id) VALUES (:name, :slug, :desc, :pid)"
                );
                $stmt->execute([
                    ':name' => $cat['name'],
                    ':slug' => $cat['slug'],
                    ':desc' => $cat['description'] ?? '',
                    ':pid'  => $cat['parent_id'] ?? null,
                ]);
                $catIdMap[(int)$cat['id']] = (int)$db->lastInsertId();
                $stats['categories_imported']++;
            }
        }
    }

    // ── Import tags ──
    $tagIdMap = []; // old_id => new_id
    if (!empty($data['tags'])) {
        foreach ($data['tags'] as $tag) {
            $existing = $db->prepare("SELECT id FROM cms_tags WHERE slug = :slug LIMIT 1");
            $existing->execute([':slug' => $tag['slug']]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tagIdMap[(int)$tag['id']] = (int)$row['id'];
            } else {
                $stmt = $db->prepare("INSERT INTO cms_tags (name, slug) VALUES (:name, :slug)");
                $stmt->execute([':name' => $tag['name'], ':slug' => $tag['slug']]);
                $tagIdMap[(int)$tag['id']] = (int)$db->lastInsertId();
                $stats['tags_imported']++;
            }
        }
    }

    // Content → category mapping
    $contentCatMap = [];
    if (!empty($data['content_categories'])) {
        foreach ($data['content_categories'] as $cc) {
            $contentCatMap[(int)$cc['content_id']][] = (int)$cc['category_id'];
        }
    }

    // Content → tag mapping
    $contentTagMap = [];
    if (!empty($data['content_tags'])) {
        foreach ($data['content_tags'] as $ct) {
            $contentTagMap[(int)$ct['content_id']][] = (int)$ct['tag_id'];
        }
    }

    // ── Import content ──
    foreach ($data['content'] as $item) {
        $slug = trim((string)($item['slug'] ?? ''));
        $type = trim((string)($item['type'] ?? 'post'));
        $title = trim((string)($item['title'] ?? ''));
        if ($slug === '' || $title === '') {
            $stats['errors']++;
            continue;
        }

        $blocksJson = null;
        if (!empty($item['blocks_json'])) {
            $blocksJson = is_array($item['blocks_json']) ? json_encode($item['blocks_json']) : (string)$item['blocks_json'];
        }

        // Check existing
        $existing = $db->prepare("SELECT id FROM cms_content WHERE slug = :slug AND type = :type AND deleted_at IS NULL LIMIT 1");
        $existing->execute([':slug' => $slug, ':type' => $type]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

        $oldId = (int)($item['id'] ?? 0);

        if ($existingRow) {
            if ($mode === 'merge') {
                $stats['skipped']++;
                continue;
            }
            // Replace mode — update
            $stmt = $db->prepare(
                "UPDATE cms_content SET title=:title, body=:body, excerpt=:excerpt, status=:status,
                        published_at=:pub, blocks_json=:bj, updated_at=NOW()
                 WHERE id=:id"
            );
            $stmt->execute([
                ':title'   => $title,
                ':body'    => $item['body'] ?? '',
                ':excerpt' => $item['excerpt'] ?? '',
                ':status'  => $item['status'] ?? 'draft',
                ':pub'     => !empty($item['published_at']) ? $item['published_at'] : null,
                ':bj'      => $blocksJson,
                ':id'      => $existingRow['id'],
            ]);
            $newId = (int)$existingRow['id'];
            $stats['updated']++;
        } else {
            // Insert new
            $stmt = $db->prepare(
                "INSERT INTO cms_content (title, slug, type, body, excerpt, status, published_at, blocks_json, created_at, updated_at)
                 VALUES (:title, :slug, :type, :body, :excerpt, :status, :pub, :bj, NOW(), NOW())"
            );
            try {
                $stmt->execute([
                    ':title'   => $title,
                    ':slug'    => $slug,
                    ':type'    => $type,
                    ':body'    => $item['body'] ?? '',
                    ':excerpt' => $item['excerpt'] ?? '',
                    ':status'  => $item['status'] ?? 'draft',
                    ':pub'     => !empty($item['published_at']) ? $item['published_at'] : null,
                    ':bj'      => $blocksJson,
                ]);
                $newId = (int)$db->lastInsertId();
                $stats['imported']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                write_log("Import content failed for slug '{$slug}': " . $e->getMessage(), 'error', ['source' => 'import']);
                continue;
            }
        }

        // Wire categories
        if (isset($newId) && !empty($contentCatMap[$oldId])) {
            foreach ($contentCatMap[$oldId] as $oldCatId) {
                $newCatId = $catIdMap[$oldCatId] ?? null;
                if ($newCatId) {
                    try {
                        $db->prepare(
                            "INSERT IGNORE INTO cms_content_categories (content_id, category_id) VALUES (:cid, :catid)"
                        )->execute([':cid' => $newId, ':catid' => $newCatId]);
                    } catch (Throwable $e) {}
                }
            }
        }

        // Wire tags
        if (isset($newId) && !empty($contentTagMap[$oldId])) {
            foreach ($contentTagMap[$oldId] as $oldTagId) {
                $newTagId = $tagIdMap[$oldTagId] ?? null;
                if ($newTagId) {
                    try {
                        $db->prepare(
                            "INSERT IGNORE INTO cms_content_tags (content_id, tag_id) VALUES (:cid, :tid)"
                        )->execute([':cid' => $newId, ':tid' => $newTagId]);
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'stats' => $stats]);
    exit;
}

<?php

declare(strict_types=1);

function cmsSaveRevision(int $contentId, int $authorId, string $title, ?string $body, ?string $blocksJson, ?string $note = null): int
{
    try {
        $stmt = cmsDb()->prepare(
            "INSERT INTO cms_revisions (content_id, author_id, title, body, blocks_json, revision_note)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$contentId, $authorId, $title, $body, $blocksJson, $note]);
        return (int)cmsDb()->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get revisions for a content item, newest first.
 */

function cmsGetRevisions(int $contentId, int $limit = 25): array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT r.id, r.content_id, r.author_id, r.title, r.revision_note, r.created_at,
                    u.display_name AS author_name
             FROM cms_revisions r
             LEFT JOIN cms_users u ON u.id = r.author_id
             WHERE r.content_id = ?
             ORDER BY r.created_at DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute([$contentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a single revision by ID.
 */

function cmsGetRevision(int $revisionId): ?array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT r.*, u.display_name AS author_name
             FROM cms_revisions r
             LEFT JOIN cms_users u ON u.id = r.author_id
             WHERE r.id = ? LIMIT 1"
        );
        $stmt->execute([$revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Count revisions for a content item.
 */

function cmsRevisionCount(int $contentId): int
{
    try {
        $stmt = cmsDb()->prepare("SELECT COUNT(*) FROM cms_revisions WHERE content_id = ?");
        $stmt->execute([$contentId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ── Slug Redirects ─────────────────────────────────────────────────

/**
 * Record an old slug redirect for SEO.
 */

function cmsSaveSlugRedirect(int $contentId, string $oldSlug): void
{
    $oldSlug = trim($oldSlug);
    if ($oldSlug === '') return;
    try {
        // Don't duplicate
        $check = cmsDb()->prepare(
            "SELECT id FROM cms_slug_redirects WHERE content_id = ? AND old_slug = ? LIMIT 1"
        );
        $check->execute([$contentId, $oldSlug]);
        if ($check->fetch()) return;

        cmsDb()->prepare(
            "INSERT INTO cms_slug_redirects (content_id, old_slug) VALUES (?, ?)"
        )->execute([$contentId, $oldSlug]);
    } catch (Throwable $e) {}
}

/**
 * Look up a content ID by an old slug redirect.
 * Returns [content_id, current_slug, type] or null.
 */

function cmsLookupSlugRedirect(string $slug): ?array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT r.content_id, c.slug, c.type
             FROM cms_slug_redirects r
             INNER JOIN cms_content c ON c.id = r.content_id
             WHERE r.old_slug = ? AND c.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ── CMS Extension Points (module-local hooks via kernel Hooks) ─────
//
// These functions fire CMS-namespaced hooks through the kernel Hooks
// singleton. Other modules register listeners via:
//   app()->hooks()->on('cms.editor.block_types', fn($blocks) => ..., 10);
//
// Hook contracts:
//   cms.editor.block_types      (filter) array $blockTypes
//     Each entry: ['type'=>string, 'label'=>string, 'icon'=>string, 'fields'=>array]
//     Allows modules to register custom block types in the content editor.
//
//   cms.editor.sidebar_fields   (filter) array $fields, string $contentType
//     Each entry: ['key'=>string, 'type'=>string, 'label'=>string, 'placeholder'=>string, 'options'=>array]
//     Allows modules to inject extra sidebar fields into the content editor.
//
//   cms.admin.nav_items         (filter) array $items
//     Each entry: ['label'=>string, 'url'=>string, 'icon'=>string, 'active_key'=>string]
//     Allows modules to add links to the CMS admin sidebar.
//
//   cms.public.head             (filter) string $headHtml, array $content
//     Allows modules to inject extra tags into the <head> of public pages.
//
//   cms.public.render_content   (filter) string $html, array $content
//     Allows modules to transform rendered content HTML before output.
//
//   cms.content.query_args      (filter) array $args, string $contentType
//     Allows modules to modify query arguments for public content lists.
//
//   cms.content.templates       (filter) array $templates, string $contentType
//     Each entry: ['slug'=>string, 'label'=>string, 'types'=>['post','page'], 'path'=>string]
//     Allows themes/modules to register per-content template choices.
//     'path' is relative to templates/ (e.g. 'modules/cms/public/single-wide.disyl').

/**
 * Get available content templates for a given content type.
 * Built-in default is always included. Themes/modules may add more via hook.
 */

function cmsPruneContentRevisions(int $contentId, int $maxRevisions = 50): void
{
    if ($maxRevisions < 1) return;

    try {
        $db = cmsDb();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM cms_revisions WHERE content_id = :id");
        $countStmt->execute([':id' => $contentId]);
        $total = (int)$countStmt->fetchColumn();

        if ($total <= $maxRevisions) return;

        $stmt = $db->prepare(
            "SELECT id FROM cms_revisions
             WHERE content_id = :id
             ORDER BY id DESC
             LIMIT 1 OFFSET :offset"
        );
        $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $maxRevisions - 1, PDO::PARAM_INT);
        $stmt->execute();
        $cutoffId = (int)$stmt->fetchColumn();

        if ($cutoffId > 0) {
            $db->prepare(
                "DELETE FROM cms_revisions WHERE content_id = :id AND id < :cutoff"
            )->execute([':id' => $contentId, ':cutoff' => $cutoffId]);
        }
    } catch (Throwable $e) {
        app()->log('warning', 'Content revision prune failed: ' . $e->getMessage());
    }
}

// ── Dangerous File Signature Check — adopted from ikabud-kernel MediaService ──

/**
 * Check if an uploaded file contains dangerous signatures.
 * Returns error string if dangerous, null if safe.
 */

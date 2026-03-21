<?php

declare(strict_types=1);

function cms_capability_handlers(): array
{
    return [
        'cms.content.get@1' => 'cms_cap_cms_content_get_1',
        'cms.content.list@1' => 'cms_cap_cms_content_list_1',
        'cms.content.create@1' => 'cms_cap_cms_content_create_1',
        'kernel.auth.authenticate@1' => 'cms_cap_kernel_auth_authenticate_1',
        'cms.media.list@1' => 'cms_cap_cms_media_list_1',
        'cms.media.upload@1' => 'cms_cap_cms_media_upload_1',
        'cms.builder.get@1' => 'cms_cap_cms_builder_get_1',
        'cms.builder.render@1' => 'cms_cap_cms_builder_render_1',
        'cms.settings.get@1' => 'cms_cap_cms_settings_get_1',
        'cms.themes.list@1' => 'cms_cap_cms_themes_list_1',
    ];
}

function cms_cap_cms_content_get_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $id = 0;
    if (is_array($payload)) {
        $id = (int)($payload['id'] ?? 0);
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id is required'];
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT c.*, u.display_name as author_name
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Not found'];
        }
        return ['ok' => true, 'data' => $row];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_cms_content_list_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $type = 'post';
    $status = 'published';
    $limit = 20;
    $offset = 0;

    if (is_array($payload)) {
        $type = trim((string)($payload['type'] ?? $type)) ?: $type;
        $status = trim((string)($payload['status'] ?? $status)) ?: $status;
        $limit = min(100, max(1, (int)($payload['limit'] ?? $limit)));
        $offset = max(0, (int)($payload['offset'] ?? $offset));
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT c.id, c.uuid, c.title, c.slug, c.excerpt, c.type, c.status,
                    c.published_at, c.created_at, u.display_name as author_name
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             WHERE c.deleted_at IS NULL AND c.type = :type AND " . cmsPublicVisibilitySql('c') . "
             ORDER BY COALESCE(c.published_at, c.created_at) DESC, c.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([':type' => $type]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return ['ok' => true, 'data' => $rows];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_cms_content_create_1(mixed $payload, string $capabilityId, string $providerId): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    $type = trim((string)($payload['type'] ?? 'post')) ?: 'post';
    $body = (string)($payload['body'] ?? '');
    $excerpt = trim((string)($payload['excerpt'] ?? ''));
    $status = trim((string)($payload['status'] ?? 'draft')) ?: 'draft';
    $slug = trim((string)($payload['slug'] ?? ''));

    if (!in_array($status, ['draft', 'published', 'scheduled', 'private'], true)) {
        $status = 'draft';
    }
    if ($slug === '') {
        $slug = cmsSlugify($title);
    }
    $slug = cmsEnsureUniqueSlug($slug, $type);

    $uuid = cmsUuid();
    $authorId = (int)($payload['author_id'] ?? 0);
    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }
    if ($authorId <= 0) {
        // fallback to current kernel user id if available
        $u = $ctx->user();
        $authorId = $u ? (int)($u['id'] ?? 0) : 0;
    }
    if ($authorId <= 0) {
        return ['ok' => false, 'error' => 'author_id is required'];
    }

    $publishAtInput = cmsNormalizePublishAt($payload['published_at'] ?? null);
    if ($status === 'published') {
        $publishedAt = $publishAtInput ?? date('Y-m-d H:i:s');
    } elseif ($status === 'scheduled') {
        $publishedAt = $publishAtInput;
        if ($publishedAt === null) {
            return ['ok' => false, 'error' => 'published_at is required for scheduled content'];
        }
    } else {
        $publishedAt = $publishAtInput;
    }

    try {
        $db = $ctx->db();
        $stmt = $db->prepare(
            "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at)
             VALUES (:uuid, :title, :slug, :body, :excerpt, :type, :status, :author_id, :pub, NOW())"
        );
        $stmt->execute([
            ':uuid' => $uuid,
            ':title' => $title,
            ':slug' => $slug,
            ':body' => $body,
            ':excerpt' => $excerpt,
            ':type' => $type,
            ':status' => $status,
            ':author_id' => $authorId,
            ':pub' => $publishedAt,
        ]);
        $id = (int)$db->lastInsertId();
        return ['ok' => true, 'id' => $id, 'slug' => $slug];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }
    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    $ctx = module('cms');
    if (!$ctx) {
        return null;
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, username, email, password_hash, display_name, role, is_active
             FROM cms_users
             WHERE (username = :u1 OR email = :u2) AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([':u1' => $username, ':u2' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (is_array($row) && password_verify($password, (string)$row['password_hash'])) {
            return [
                'user' => [
                    'id'        => (int)$row['id'],
                    'username'  => (string)$row['username'],
                    'full_name' => (string)$row['display_name'],
                    'role'      => (string)$row['role'],
                    'sub'       => 'cms:' . $row['id'],
                ],
                'source' => 'cms',
            ];
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

// ── Phase C: CMS Capability Contracts ────────────────────────────────
//
// Media, Builder, Settings, and Themes capabilities for enhanced integration
// with other modules and external systems via the capability bus.

function cms_cap_cms_media_list_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $limit = 20;
    $offset = 0;
    $search = '';
    $media_type = '';

    if (is_array($payload)) {
        $limit = min(100, max(1, (int)($payload['limit'] ?? $limit)));
        $offset = max(0, (int)($payload['offset'] ?? $offset));
        $search = trim((string)($payload['search'] ?? ''));
        $media_type = trim((string)($payload['type'] ?? ''));
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $sql = "SELECT id, uuid, upload_name, file_key, mime_type, file_size, alt_text, title, created_at, updated_at
                FROM cms_media
                WHERE deleted_at IS NULL";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (upload_name LIKE :search OR alt_text LIKE :search OR title LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($media_type !== '') {
            $sql .= " AND mime_type LIKE :type";
            $params[':type'] = $media_type . '%';
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $ctx->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'data' => $rows];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_cms_media_upload_1(mixed $payload, string $capabilityId, string $providerId): array
{
    // Note: File upload via capability bus is complex due to multipart/form-data requirements.
    // This handler accepts base64-encoded file data to support non-HTTP use cases.
    // For standard HTTP file uploads, use /api/v1/cms/media/upload route instead.

    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $file_data = $payload['file_data'] ?? null;
    $file_name = trim((string)($payload['file_name'] ?? ''));
    $alt_text = trim((string)($payload['alt_text'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));

    if ($file_name === '' || $file_data === null) {
        return ['ok' => false, 'error' => 'file_name and file_data are required'];
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    // Validate file extension
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        return ['ok' => false, 'error' => 'File type not allowed'];
    }

    try {
        // Decode base64 if needed
        $binary_data = is_string($file_data) ? base64_decode($file_data, true) : $file_data;
        if ($binary_data === false) {
            return ['ok' => false, 'error' => 'Invalid file data encoding'];
        }

        // Detect MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_buffer($finfo, $binary_data) ?: 'application/octet-stream';
        finfo_close($finfo);

        // Generate storage key and save file
        $uuid = cmsUuid();
        $file_key = 'media/' . $uuid . '.' . $ext;
        $storage_path = cmsUploadsPath() . '/' . $file_key;

        if (!is_dir(dirname($storage_path))) {
            mkdir(dirname($storage_path), 0755, true);
        }

        if (file_put_contents($storage_path, $binary_data) === false) {
            return ['ok' => false, 'error' => 'Failed to save file'];
        }

        // Record in database
        $stmt = $ctx->db()->prepare(
            "INSERT INTO cms_media (uuid, upload_name, file_key, mime_type, file_size, alt_text, title, created_at, updated_at)
             VALUES (:uuid, :name, :key, :mime, :size, :alt, :title, NOW(), NOW())"
        );
        $stmt->execute([
            ':uuid' => $uuid,
            ':name' => $file_name,
            ':key' => $file_key,
            ':mime' => $mime_type,
            ':size' => strlen($binary_data),
            ':alt' => $alt_text,
            ':title' => $title ?: $file_name,
        ]);
        $media_id = (int)$ctx->db()->lastInsertId();

        return [
            'ok' => true,
            'media_id' => $media_id,
            'uuid' => $uuid,
            'url' => cmsUploadsUrl($file_key),
        ];
    } catch (\Throwable $e) {
        write_log('cms_media_upload_error', 'WARN', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Upload failed'];
    }
}

function cms_cap_cms_builder_get_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $id = 0;
    if (is_array($payload)) {
        $id = (int)($payload['id'] ?? 0);
    }
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id is required'];
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, uuid, content_id, title, json, status, published_at, created_at, updated_at
             FROM cms_builder_documents
             WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Document not found'];
        }

        // Parse JSON if it exists
        if ($row['json'] !== null && is_string($row['json'])) {
            $row['document'] = @json_decode($row['json'], true) ?: [];
        } else {
            $row['document'] = [];
        }

        return ['ok' => true, 'data' => $row];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_cms_builder_render_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $id = 0;
    $mode = 'preview';

    if (is_array($payload)) {
        $id = (int)($payload['id'] ?? 0);
        $mode = in_array((string)($payload['mode'] ?? ''), ['preview', 'publish'], true)
            ? (string)($payload['mode'])
            : 'preview';
    }

    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id is required'];
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        // Fetch the builder document
        $stmt = $ctx->db()->prepare(
            "SELECT id, json FROM cms_builder_documents WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || !is_string($row['json'])) {
            return ['ok' => false, 'error' => 'Document not found'];
        }

        $document = @json_decode($row['json'], true);
        if (!is_array($document)) {
            return ['ok' => false, 'error' => 'Invalid document format'];
        }

        // Render document to HTML using CMS renderer
        // This delegates to cmsRenderBuilder() which handles node traversal and styling
        $html = cmsRenderBuilder($document);

        return ['ok' => true, 'html' => $html, 'mode' => $mode];
    } catch (\Throwable $e) {
        write_log('cms_builder_render_error', 'WARN', ['error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Render failed'];
    }
}

function cms_cap_cms_settings_get_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $key = null;
    if (is_array($payload)) {
        $key_input = trim((string)($payload['key'] ?? ''));
        $key = $key_input !== '' ? $key_input : null;
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        if ($key !== null) {
            // Get specific setting
            $stmt = $ctx->db()->prepare(
                "SELECT setting_key, setting_value FROM cms_config WHERE setting_key = :key LIMIT 1"
            );
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return ['ok' => false, 'error' => 'Setting not found'];
            }

            $value = @json_decode($row['setting_value'], true) ?? $row['setting_value'];
            return ['ok' => true, 'key' => $key, 'value' => $value];
        } else {
            // Get all settings
            $stmt = $ctx->db()->prepare(
                "SELECT setting_key, setting_value FROM cms_config WHERE deleted_at IS NULL ORDER BY setting_key"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $settings = [];
            foreach ($rows as $row) {
                $value = @json_decode($row['setting_value'], true) ?? $row['setting_value'];
                $settings[$row['setting_key']] = $value;
            }

            return ['ok' => true, 'settings' => $settings];
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function cms_cap_cms_themes_list_1(mixed $payload, string $capabilityId, string $providerId): array
{
    $limit = 20;
    $offset = 0;

    if (is_array($payload)) {
        $limit = min(100, max(1, (int)($payload['limit'] ?? $limit)));
        $offset = max(0, (int)($payload['offset'] ?? $offset));
    }

    $ctx = module('cms');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, uuid, theme_name, author, description, version, is_active, created_at, updated_at
             FROM cms_themes
             WHERE deleted_at IS NULL
             ORDER BY is_active DESC, theme_name ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'data' => $rows];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

// ── CMS Cache Layer ─────────────────────────────────────────────────
//
// Uses kernel Cache with instance ID 'cms'. Tag-based invalidation
// ensures content changes flush the right cache entries.
//
// Cache tags:
//   cms:home         — blog home / archive listing
//   cms:post:{slug}  — individual post page
//   cms:page:{slug}  — individual static page
//   cms:api:posts    — headless API post listing
//   cms:api:pages    — headless API page listing
//   cms:content:{id} — any cache related to a specific content ID
//   cms:type:{type}  — any cache related to a content type (post/page)

define('CMS_CACHE_INSTANCE', 'cms');
define('CMS_CACHE_TTL', 600); // 10 minutes default

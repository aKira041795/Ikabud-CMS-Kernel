<?php

declare(strict_types=1);

function media_capability_handlers(): array
{
    return [
        'media.list@1' => 'media_cap_media_list_1',
        'media.upload@1' => 'media_cap_media_upload_1',
        'media.delete@1' => 'media_cap_media_delete_1',
    ];
}

function mediaList(int $limit = 24, int $offset = 0): array
{
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $ctx = module('media');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT m.*, u.display_name as uploader_name\n             FROM cms_media m\n             LEFT JOIN cms_users u ON u.id = m.uploaded_by\n             ORDER BY m.created_at DESC\n             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['ok' => true, 'data' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function mediaDelete(int $id, ?string $uploadsPath = null): array
{
    if ($id <= 0) return ['ok' => false, 'error' => 'id is required'];

    $ctx = module('media');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $db = $ctx->db();
        $stmt = $db->prepare("SELECT * FROM cms_media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($media)) return ['ok' => false, 'error' => 'Not found'];

        $uploadsPath = $uploadsPath !== null ? $uploadsPath : (function_exists('cmsUploadsPath') ? cmsUploadsPath() : null);
        if (is_string($uploadsPath) && $uploadsPath !== '') {
            $filePath = rtrim($uploadsPath, '/') . '/' . (string)($media['file_path'] ?? '');
            if (is_file($filePath)) {
                kernelDeletePath($filePath);
            }
        }

        $db->prepare("DELETE FROM cms_media WHERE id = :id")->execute([':id' => $id]);

        $ctx->fireEvent('media.deleted', ['media_id' => $id]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function mediaUpload(array $payload): array
{
    $original = trim((string)($payload['original_name'] ?? ''));
    $mime = trim((string)($payload['mime_type'] ?? ''));
    $size = (int)($payload['file_size'] ?? 0);
    $uploadedBy = (int)($payload['uploaded_by'] ?? 0);
    $tmpPath = trim((string)($payload['tmp_path'] ?? ''));
    $b64 = (string)($payload['contents_base64'] ?? '');

    if ($original === '' || $mime === '' || $uploadedBy <= 0) {
        return ['ok' => false, 'error' => 'original_name, mime_type, uploaded_by are required'];
    }
    if ($size > (2 * 1024 * 1024)) {
        return ['ok' => false, 'error' => 'File exceeds 2MB limit'];
    }

    if (!function_exists('cmsUploadsPath') || !function_exists('cmsUploadsUrl')) {
        return ['ok' => false, 'error' => 'CMS uploads helpers not available'];
    }

    $ctx = module('media');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $safeExts = ['jpg','jpeg','png','gif','webp','svg','pdf','txt','csv','doc','docx','xls','xlsx'];
    if (!in_array($ext, $safeExts, true)) {
        $ext = 'bin';
    }
    $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $subDir = date('Y') . '/' . date('m');
    $uploadDir = cmsUploadsPath() . '/' . $subDir;
    if (!is_dir($uploadDir)) {
        kernelEnsureDirectory($uploadDir);
    }
    $destPath = $uploadDir . '/' . $filename;

    $written = false;
    if ($tmpPath !== '' && is_file($tmpPath)) {
        $written = kernelCopyFile($tmpPath, $destPath);
    } elseif ($b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw !== false) {
            $written = kernelWriteFile($destPath, $raw);
            if ($size <= 0) {
                $size = is_file($destPath) ? (int)filesize($destPath) : 0;
            }
        }
    }

    if (!$written) {
        return ['ok' => false, 'error' => 'Failed to write file'];
    }

    $relPath = $subDir . '/' . $filename;
    try {
        $db = $ctx->db();
        $stmt = $db->prepare(
            "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)\n             VALUES (:fname, :oname, :mime, :size, :path, :uid, NOW())"
        );
        $stmt->execute([
            ':fname' => $filename,
            ':oname' => $original,
            ':mime' => $mime,
            ':size' => max(0, $size),
            ':path' => $relPath,
            ':uid' => $uploadedBy,
        ]);
        $mediaId = (int)$db->lastInsertId();

        $ctx->fireEvent('media.uploaded', [
            'media_id' => $mediaId,
            'filename' => $filename,
            'mime_type' => $mime,
        ]);

        return ['ok' => true, 'id' => $mediaId, 'url' => cmsUploadsUrl($relPath), 'filename' => $filename];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function media_cap_media_list_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = 24;
    $offset = 0;
    if (is_array($payload)) {
        $limit = (int)($payload['limit'] ?? $limit);
        $offset = (int)($payload['offset'] ?? $offset);
    }
    return mediaList($limit, $offset);
}

function media_cap_media_upload_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    return mediaUpload($payload);
}

function media_cap_media_delete_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = is_array($payload) ? (int)($payload['id'] ?? 0) : 0;
    return mediaDelete($id);
}

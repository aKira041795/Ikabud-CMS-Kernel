<?php

declare(strict_types=1);

function cmsApiMediaList(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('media.list');
    $input = cmsInput();

    $db = cmsDb();
    $limit  = min(100, max(1, (int)($input['limit'] ?? 24)));
    $offset = max(0, (int)($input['offset'] ?? 0));

    $stmt = $db->prepare("SELECT * FROM cms_media ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Add URLs
    foreach ($rows as &$r) {
        $r['url'] = cmsResolveUploadUrl((string)($r['file_path'] ?? ''));
    }
    unset($r);

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiMediaUpload(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('media.upload');
    $file = kernelUploadedFile('file');

    if (!is_array($file) || empty($file)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
        exit;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Upload error: ' . $file['error']]);
        exit;
    }

    // Use max_upload_mb from CMS settings (default 2MB)
    $cmsSettings = readCmsSettings();
    $maxMb = max(1, min(64, (int)($cmsSettings['max_upload_mb'] ?? 2)));
    $maxSize = $maxMb * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'File exceeds ' . $maxMb . 'MB limit']);
        exit;
    }

    // Whitelist MIME types
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'text/plain', 'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'File type not allowed: ' . $mimeType]);
        exit;
    }

    // Check for dangerous file signatures (PHP, shell scripts, executables)
    $dangerCheck = cmsCheckDangerousFileSignature($file['tmp_name']);
    if ($dangerCheck !== null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $dangerCheck]);
        exit;
    }

    // Generate safe filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeExts = ['jpg','jpeg','png','gif','webp','svg','pdf','txt','csv','doc','docx','xls','xlsx'];
    if (!in_array($ext, $safeExts, true)) {
        $ext = 'bin';
    }
    $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;

    // Organize by year/month
    $subDir = date('Y') . '/' . date('m');
    $uploadDir = cmsUploadsPath() . '/' . $subDir;
    if (!is_dir($uploadDir)) {
        kernelEnsureDirectory($uploadDir);
    }

    $destPath = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    $relPath = $subDir . '/' . $filename;
    $authorId = (int)($user['id'] ?? 0);

    // Generate thumbnails for images
    $thumbnails = [];
    $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (in_array($mimeType, $imageMimes, true)) {
        $filenameBase = pathinfo($filename, PATHINFO_FILENAME);
        $thumbnails = cmsGenerateThumbnails($destPath, $subDir, $filenameBase, $ext);
    }

    $db = cmsDb();
    $stmt = $db->prepare(
        "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)
         VALUES (:fname, :oname, :mime, :size, :path, :uid, NOW())"
    );
    $stmt->execute([
        ':fname' => $filename,
        ':oname' => $file['name'],
        ':mime'  => $mimeType,
        ':size'  => $file['size'],
        ':path'  => $relPath,
        ':uid'   => $authorId,
    ]);
    $mediaId = (int)$db->lastInsertId();

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.media.uploaded', [
            'media_id'  => $mediaId,
            'filename'  => $filename,
            'mime_type' => $mimeType,
        ]);
    }

    adminViewCacheInvalidate(['cms:admin', 'cms:admin:media']);

    $thumbUrls = [];
    foreach ($thumbnails as $size => $thumbRelPath) {
        $thumbUrls[$size] = cmsResolveUploadUrl($thumbRelPath);
    }

    echo json_encode([
        'ok'  => true,
        'id'  => $mediaId,
        'url' => cmsResolveUploadUrl($relPath),
        'file_path' => $relPath,
        'filename' => $filename,
        'thumbnails' => $thumbUrls,
    ]);
    exit;
}

function cmsApiMediaEdit(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('media.edit');
    $id = (int)($params['id'] ?? 0);
    $input = cmsInput();

    $operation = trim((string)($input['operation'] ?? ''));
    $mode = trim((string)($input['mode'] ?? 'copy'));
    $allowedOps = ['rotate_left', 'rotate_right', 'flip_horizontal', 'flip_vertical', 'crop', 'resize'];

    if ($id <= 0 || !in_array($operation, $allowedOps, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid edit request']);
        exit;
    }

    if (!in_array($mode, ['copy', 'replace'], true)) {
        $mode = 'copy';
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT * FROM cms_media WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Media not found']);
        exit;
    }

    $mime = (string)($media['mime_type'] ?? '');
    if (!cmsMediaIsEditableImageMime($mime)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Only JPEG, PNG, GIF, and WebP images can be edited']);
        exit;
    }

    if (!extension_loaded('gd')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Image editing requires the GD extension']);
        exit;
    }

    $sourceRelativePath = (string)($media['file_path'] ?? '');
    $sourceAbsolutePath = cmsResolveUploadAbsolutePath($sourceRelativePath);
    if (!is_file($sourceAbsolutePath)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Source image file not found']);
        exit;
    }

    $sourceFilename = basename($sourceRelativePath);
    $ext = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
    $relativeDir = trim(str_replace('\\', '/', dirname($sourceRelativePath)), '/.');
    $relativePrefix = $relativeDir !== '' ? ($relativeDir . '/') : '';

    $editOptions = [];
    if ($operation === 'crop') {
        $cropX = (int)($input['x'] ?? 0);
        $cropY = (int)($input['y'] ?? 0);
        $cropWidth = (int)($input['width'] ?? 0);
        $cropHeight = (int)($input['height'] ?? 0);
        $outputWidth = (int)($input['output_width'] ?? 0);
        $outputHeight = (int)($input['output_height'] ?? 0);
        $editOptions = [
            'x' => max(0, $cropX),
            'y' => max(0, $cropY),
            'width' => $cropWidth,
            'height' => $cropHeight,
            'output_width' => max(0, $outputWidth),
            'output_height' => max(0, $outputHeight),
        ];
        if ($editOptions['width'] <= 0 || $editOptions['height'] <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Crop width and height are required']);
            exit;
        }
    } elseif ($operation === 'resize') {
        $resizeWidth = (int)($input['width'] ?? 0);
        $resizeHeight = (int)($input['height'] ?? 0);
        $editOptions = [
            'width' => $resizeWidth,
            'height' => $resizeHeight,
        ];
        if ($editOptions['width'] <= 0 || $editOptions['height'] <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Resize width and height are required']);
            exit;
        }
    }

    $targetRelativePath = $sourceRelativePath;
    if ($mode === 'copy') {
        $newFilename = date('Ymd_His') . '_edit_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $targetRelativePath = $relativePrefix . $newFilename;
        $targetAbsolutePath = cmsUploadsPath() . '/' . $targetRelativePath;
        $targetDir = dirname($targetAbsolutePath);
        if (!is_dir($targetDir)) {
            kernelEnsureDirectory($targetDir);
        }
        if (!@copy($sourceAbsolutePath, $targetAbsolutePath)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create editable copy']);
            exit;
        }
    } else {
        $targetAbsolutePath = $sourceAbsolutePath;
    }

    if (!cmsMediaApplyImageEdit($targetAbsolutePath, $mime, $operation, $editOptions)) {
        if ($mode === 'copy' && is_file($targetAbsolutePath)) {
            @unlink($targetAbsolutePath);
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process image edit']);
        exit;
    }

    $targetFilename = basename($targetRelativePath);
    $targetBase = pathinfo($targetFilename, PATHINFO_FILENAME);
    $targetExt = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));
    $targetDirAbsolute = dirname($targetAbsolutePath);
    foreach (['thumb', 'medium', 'large'] as $sizeName) {
        $oldThumb = $targetDirAbsolute . '/' . $targetBase . '-' . $sizeName . '.' . $targetExt;
        if (is_file($oldThumb)) {
            @unlink($oldThumb);
        }
    }

    $thumbs = cmsGenerateThumbnails($targetAbsolutePath, $relativeDir, $targetBase, $targetExt);
    $thumbUrls = [];
    foreach ($thumbs as $size => $thumbRelPath) {
        $thumbUrls[$size] = cmsResolveUploadUrl($thumbRelPath);
    }

    $newSize = (int)(@filesize($targetAbsolutePath) ?: 0);
    if ($newSize <= 0) {
        $newSize = (int)($media['file_size'] ?? 0);
    }

    $imageInfo = @getimagesize($targetAbsolutePath);
    $resultWidth = (int)($imageInfo[0] ?? 0);
    $resultHeight = (int)($imageInfo[1] ?? 0);

    $authorId = (int)($user['id'] ?? 0);
    if ($mode === 'copy') {
        $originalName = (string)($media['original_name'] ?? $targetFilename);
        $editedName = preg_replace('/(\.[^.]+)$/', ' (edited)$1', $originalName);
        if (!is_string($editedName) || $editedName === '') {
            $editedName = $originalName . ' (edited)';
        }

        $insert = $db->prepare(
            "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, alt_text, title, created_at)
             VALUES (:fname, :oname, :mime, :size, :path, :uid, :alt, :title, NOW())"
        );
        $insert->execute([
            ':fname' => $targetFilename,
            ':oname' => $editedName,
            ':mime'  => $mime,
            ':size'  => $newSize,
            ':path'  => $targetRelativePath,
            ':uid'   => $authorId,
            ':alt'   => $media['alt_text'] ?? null,
            ':title' => $media['title'] ?? null,
        ]);
        $resultId = (int)$db->lastInsertId();
    } else {
        $db->prepare("UPDATE cms_media SET file_size = :size WHERE id = :id")->execute([
            ':size' => $newSize,
            ':id'   => $id,
        ]);
        $resultId = $id;
    }

    adminViewCacheInvalidate(['cms:admin', 'cms:admin:media']);

    echo json_encode([
        'ok' => true,
        'id' => $resultId,
        'mode' => $mode,
        'url' => cmsResolveUploadUrl($targetRelativePath),
        'file_path' => $targetRelativePath,
        'width' => $resultWidth,
        'height' => $resultHeight,
        'thumbnails' => $thumbUrls,
    ]);
    exit;
}

function cmsApiMediaDelete(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('media.delete');
    $id   = (int)($params['id'] ?? 0);

    $db = cmsDb();
    $stmt = $db->prepare("SELECT * FROM cms_media WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    // Delete file
    $filePath = cmsResolveUploadAbsolutePath((string)($media['file_path'] ?? ''));
    if (is_file($filePath)) {
        kernelDeletePath($filePath);
    }

    $db->prepare("DELETE FROM cms_media WHERE id = :id")->execute([':id' => $id]);
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:media']);
    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// USER API HANDLERS
// ═══════════════════════════════════════════════════════════════════════

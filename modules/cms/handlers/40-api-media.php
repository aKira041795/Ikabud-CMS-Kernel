<?php

declare(strict_types=1);

function cmsMediaFetchOpenverseResults(string $query, int $limit = 24): array
{
    if (!function_exists('curl_init')) {
        return [];
    }

    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $callOpenverse = static function (string $search, int $limit) {
        $url = 'https://api.openverse.org/v1/images/?q=' . rawurlencode($search)
            . '&license_type=all&page_size=' . max(1, min(30, $limit));

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
        return is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    };

    $queryVariants = [$q];
    $simplified = trim((string)preg_replace('/[^\p{L}\p{N}\s,]+/u', ' ', $q));
    if ($simplified !== '' && $simplified !== $q) {
        $queryVariants[] = $simplified;
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', $q))));
    if (!empty($parts)) {
        $queryVariants[] = implode(' ', array_slice($parts, 0, 3));
        $queryVariants[] = (string)($parts[0] ?? '');
    }
    $words = preg_split('/\s+/', $simplified !== '' ? $simplified : $q);
    if (is_array($words) && count($words) > 6) {
        $queryVariants[] = implode(' ', array_slice($words, 0, 6));
    }

    $results = [];
    $seen = [];
    foreach ($queryVariants as $variant) {
        $variant = trim((string)$variant);
        if ($variant === '') {
            continue;
        }
        $key = mb_strtolower($variant);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $results = $callOpenverse($variant, $limit);
        if ($results !== []) {
            break;
        }
    }

    if ($results === []) {
        $combined = mb_strtolower($q);
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

        $seenFallback = [];
        foreach ($fallbackQueries as $fq) {
            $fq = trim($fq);
            if ($fq === '' || isset($seenFallback[$fq])) {
                continue;
            }
            $seenFallback[$fq] = true;
            $results = $callOpenverse($fq, $limit);
            if ($results !== []) {
                break;
            }
        }
    }

    if ($results === []) {
        return [];
    }

    $rows = [];
    foreach ($results as $r) {
        $imgUrl = trim((string)($r['url'] ?? ''));
        if ($imgUrl === '' || !preg_match('/^https?:\/\//i', $imgUrl)) {
            continue;
        }

        $title = trim((string)($r['title'] ?? ''));
        $thumb = trim((string)($r['thumbnail'] ?? ''));
        $creator = trim((string)($r['creator'] ?? ''));
        $license = trim((string)($r['license'] ?? ''));
        $licenseVersion = trim((string)($r['license_version'] ?? ''));
        $licenseUrl = trim((string)($r['license_url'] ?? ''));
        $landingUrl = trim((string)($r['foreign_landing_url'] ?? ''));

        $rows[] = [
            'id' => 'ov:' . (string)($r['id'] ?? md5($imgUrl)),
            'url' => $imgUrl,
            'thumbnail_url' => $thumb !== '' ? $thumb : $imgUrl,
            'original_name' => $title !== '' ? $title : basename(parse_url($imgUrl, PHP_URL_PATH) ?: 'image'),
            'file_path' => '',
            'mime_type' => 'image/jpeg',
            'alt_text' => $title,
            'creator' => $creator,
            'license' => trim($license . ($licenseVersion !== '' ? ' ' . $licenseVersion : '')),
            'license_url' => $licenseUrl,
            'source' => 'openverse',
            'source_url' => $landingUrl,
            'external' => true,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function cmsMediaFetchWikimediaResults(string $query, int $limit = 24): array
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
        . '&gsrlimit=' . max(1, min(30, $limit))
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
    foreach ($pages as $p) {
        $ii = is_array($p['imageinfo'][0] ?? null) ? $p['imageinfo'][0] : null;
        if (!is_array($ii)) {
            continue;
        }
        $imgUrl = trim((string)($ii['url'] ?? ''));
        if ($imgUrl === '' || !preg_match('/^https?:\/\//i', $imgUrl)) {
            continue;
        }

        $title = trim((string)($p['title'] ?? ''));
        $thumb = trim((string)($ii['thumburl'] ?? ''));
        $meta = is_array($ii['extmetadata'] ?? null) ? $ii['extmetadata'] : [];
        $artist = trim(strip_tags((string)($meta['Artist']['value'] ?? '')));
        $license = trim(strip_tags((string)($meta['LicenseShortName']['value'] ?? '')));
        $licenseUrl = trim((string)($meta['LicenseUrl']['value'] ?? ''));
        $sourceUrl = trim((string)($ii['descriptionurl'] ?? ''));

        $rows[] = [
            'id' => 'wc:' . (string)($p['pageid'] ?? md5($imgUrl)),
            'url' => $imgUrl,
            'thumbnail_url' => $thumb !== '' ? $thumb : $imgUrl,
            'original_name' => $title !== '' ? preg_replace('/^File:/i', '', $title) : basename(parse_url($imgUrl, PHP_URL_PATH) ?: 'image'),
            'file_path' => '',
            'mime_type' => 'image/jpeg',
            'alt_text' => $title,
            'creator' => $artist,
            'license' => $license,
            'license_url' => $licenseUrl,
            'source' => 'wikimedia',
            'source_url' => $sourceUrl,
            'external' => true,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

function cmsApiMediaFreeSearch(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('media.list');
    $input = cmsInput();

    $q = trim((string)($input['q'] ?? ''));
    $limit = min(30, max(1, (int)($input['limit'] ?? 24)));
    if ($q === '') {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }

    try {
        $openverseRows = cmsMediaFetchOpenverseResults($q, $limit);
        $wikimediaRows = cmsMediaFetchWikimediaResults($q, $limit);

        $rows = [];
        $seen = [];
        foreach (array_merge($openverseRows, $wikimediaRows) as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    } catch (Throwable $e) {
        write_log('cms media free search failed: ' . $e->getMessage(), 'warning', [
            'user_id' => (int)($user['id'] ?? 0),
            'query' => $q,
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Free image search failed']);
        exit;
    }
}

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

    $validation = cmsValidateMediaUploadFile((string)$file['tmp_name'], (string)$file['name'], (int)($file['size'] ?? 0));
    if (empty($validation['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => (string)($validation['error'] ?? 'Upload validation failed')]);
        exit;
    }

    $mimeType = (string)$validation['mime_type'];
    $filename = (string)$validation['filename'];
    $ext = (string)$validation['extension'];
    $fileSize = (int)($validation['file_size'] ?? (int)($file['size'] ?? 0));

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

    // F13 Security: Sanitize SVG uploads to remove XSS vectors.
    if ($mimeType === 'image/svg+xml' && is_file($destPath)) {
        $svgContent = (string)file_get_contents($destPath);
        $sanitized = cmsSanitizeSvgContent($svgContent);
        if ($sanitized !== $svgContent) {
            file_put_contents($destPath, $sanitized);
        }
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
        ':size'  => $fileSize,
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

<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// WordPress Bridge — Media Pipeline
//
// Fetches, deduplicates, and registers WordPress attachment files into the
// CMS media library. Returns a URL rewrite map so content bodies can have
// old WP URLs replaced with local CMS URLs before writing.
//
// SSRF protection: only downloads from hosts matching source WXR base URLs.
// Dedup: url_hash (same URL never downloaded twice) + file_hash (same
// content at a different URL reuses the existing cms_media row).
// ─────────────────────────────────────────────────────────────────────────

/**
 * Fetch all WordPress media attachments for a bridge import run.
 *
 * Returns a URL map: { external_url => local_cms_url }
 * Also includes domain-swapped variants as keys so body rewriting catches
 * both the original WP URL and any version that had its domain swapped by
 * wordpressImporterRewriteInternalUrls() during WXR parsing.
 *
 * @param string   $source         Bridge source identifier (e.g. 'wordpress')
 * @param array    $attachments    From wordpressImporterParseWxr() 'attachments' key
 * @param array    $sourceBaseUrls From wordpressImporterParseWxr() 'source_base_urls' key
 * @param int      $uploadedBy     cms_users.id to associate with cms_media rows
 * @return array<string, string>   {old_url: new_local_url}
 */
function wpBridgeFetchAllMedia(
    string $source,
    array $attachments,
    array $sourceBaseUrls,
    int $uploadedBy
): array {
    if (empty($attachments)) {
        return [];
    }

    if (!ini_get('allow_url_fopen')) {
        write_log('Bridge media: allow_url_fopen is disabled — skipping media fetch', 'warning', ['source' => 'wordpress-bridge']);
        return [];
    }

    $db          = wpBridgeDb();
    $urlMap      = [];
    $currentBase = function_exists('wordpressImporterNormalizedBaseUrl') && function_exists('cmsExternalBaseUrl')
        ? wordpressImporterNormalizedBaseUrl(cmsExternalBaseUrl())
        : '';

    foreach ($attachments as $attachment) {
        $externalUrl = trim((string)($attachment['attachment_url'] ?? ''));
        if ($externalUrl === '') {
            continue;
        }

        // ── SSRF guard — only download from known WP source domains ─────────
        if (!wpBridgeMediaIsAllowedUrl($externalUrl, $sourceBaseUrls)) {
            continue;
        }

        $urlHash = hash('sha256', strtolower($externalUrl));

        // ── URL-based dedup: already processed this exact URL ────────────────
        $stmt = $db->prepare(
            "SELECT status, cms_media_id, local_url FROM bridge_media_log WHERE url_hash = :uh LIMIT 1"
        );
        $stmt->execute([':uh' => $urlHash]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'fetched' && !empty($existing['local_url'])) {
                $urlMap[$externalUrl] = (string)$existing['local_url'];
            }
            // failed previously — skip without re-attempting
            continue;
        }

        // ── Download to temp ─────────────────────────────────────────────────
        $downloadResult = wpBridgeDownloadToTemp($externalUrl);
        if (empty($downloadResult['ok'])) {
            $error = (string)($downloadResult['error'] ?? 'Download failed');
            write_log("Bridge media: download failed for {$externalUrl} — {$error}", 'warning', ['source' => 'wordpress-bridge']);
            wpBridgeLogMedia($source, $externalUrl, $urlHash, null, null, null, 'failed', $error);
            continue;
        }

        $tmpPath      = (string)$downloadResult['tmp_path'];
        $originalName = basename((string)(parse_url($externalUrl, PHP_URL_PATH) ?: $externalUrl));
        if ($originalName === '' || $originalName === '/') {
            $originalName = 'media.jpg';
        }

        // ── Content-based dedup: same file content at different URL ──────────
        $fileHash      = hash_file('sha256', $tmpPath);
        $hashStmt      = $db->prepare(
            "SELECT cms_media_id, local_url FROM bridge_media_log
             WHERE file_hash = :fh AND status = 'fetched' LIMIT 1"
        );
        $hashStmt->execute([':fh' => $fileHash]);
        $existingByHash = $hashStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingByHash && !empty($existingByHash['local_url'])) {
            // Same content, different URL — reuse existing media entry
            @unlink($tmpPath);
            $localUrl = (string)$existingByHash['local_url'];
            wpBridgeLogMedia($source, $externalUrl, $urlHash, $fileHash, (int)($existingByHash['cms_media_id'] ?? 0), $localUrl, 'fetched');
            $urlMap[$externalUrl] = $localUrl;
            continue;
        }

        // ── Save file to CMS uploads + register in cms_media ─────────────────
        $saveResult = wpBridgeSaveAsMediaFile($tmpPath, $originalName, $uploadedBy);
        @unlink($tmpPath); // Always clean up temp, regardless of outcome

        if (empty($saveResult['ok'])) {
            $error = (string)($saveResult['error'] ?? 'Save failed');
            write_log("Bridge media: save failed for {$externalUrl} — {$error}", 'warning', ['source' => 'wordpress-bridge']);
            wpBridgeLogMedia($source, $externalUrl, $urlHash, $fileHash, null, null, 'failed', $error);
            continue;
        }

        $cmsMediaId = (int)$saveResult['cms_media_id'];
        $localUrl   = (string)$saveResult['url'];
        wpBridgeLogMedia($source, $externalUrl, $urlHash, $fileHash, $cmsMediaId, $localUrl, 'fetched');
        $urlMap[$externalUrl] = $localUrl;

        write_log("Bridge media: fetched {$externalUrl} → {$localUrl}", 'info', ['source' => 'wordpress-bridge']);
    }

    // ── Expand map to cover domain-swapped URL variants ──────────────────────
    // wordpressImporterRewriteInternalUrls() normalizes srcBase before replacing.
    // We must do the same so our variant keys match what actually ends up in body content.
    if (!empty($urlMap) && !empty($sourceBaseUrls) && $currentBase !== '') {
        $variants = [];
        foreach ($urlMap as $oldUrl => $newUrl) {
            foreach ($sourceBaseUrls as $srcBaseRaw) {
                $srcBase = function_exists('wordpressImporterNormalizedBaseUrl')
                    ? wordpressImporterNormalizedBaseUrl((string)$srcBaseRaw)
                    : rtrim(strtolower((string)$srcBaseRaw), '/');
                if ($srcBase === '' || $srcBase === $currentBase) {
                    continue;
                }
                // Generate domain-swap variant (mirrors wordpressImporterRewriteInternalUrls)
                $swapped = str_replace($srcBase . '/', $currentBase . '/', $oldUrl);
                $swapped = str_replace($srcBase, $currentBase, $swapped);
                if ($swapped !== $oldUrl && !isset($urlMap[$swapped])) {
                    $variants[$swapped] = $newUrl;
                }
            }
        }
        $urlMap = array_merge($urlMap, $variants);
    }

    return $urlMap;
}

/**
 * SSRF guard: only allow downloads from hosts matching known WP source base URLs.
 * Rejects IP addresses, non-http/https schemes, and any host not in the source list.
 */
function wpBridgeMediaIsAllowedUrl(string $url, array $sourceBaseUrls): bool
{
    $scheme = strtolower(trim((string)(parse_url($url, PHP_URL_SCHEME) ?: '')));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $urlHost = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?: '')));
    if ($urlHost === '') {
        return false;
    }

    // Reject bare IP addresses to prevent SSRF to internal services
    if (filter_var($urlHost, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    foreach ($sourceBaseUrls as $base) {
        $baseHost = strtolower(trim((string)(parse_url((string)$base, PHP_URL_HOST) ?: '')));
        if ($baseHost !== '' && $urlHost === $baseHost) {
            return true;
        }
    }

    return false;
}

/**
 * Download a file from a URL into a temp file.
 * Enforces size limit and does not follow more than 3 redirects.
 *
 * @return array{ok: bool, tmp_path?: string, size?: int, error?: string}
 */
function wpBridgeDownloadToTemp(string $url): array
{
    $maxBytes = function_exists('cmsMediaMaxUploadBytes') ? cmsMediaMaxUploadBytes() : (32 * 1024 * 1024);

    $context = stream_context_create([
        'http' => [
            'method'           => 'GET',
            'header'           => "User-Agent: WordPress-Bridge-Importer/1.0\r\n",
            'timeout'          => 30,
            'follow_location'  => true,
            'max_redirects'    => 3,
            'ignore_errors'    => false,
        ],
        'https' => [
            'timeout' => 30,
        ],
    ]);

    $resource = @fopen($url, 'r', false, $context);
    if ($resource === false) {
        return ['ok' => false, 'error' => "Could not open URL: {$url}"];
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'wpbridge_media_');
    if ($tmpPath === false) {
        fclose($resource);
        return ['ok' => false, 'error' => 'Could not create temp file'];
    }

    $tmpHandle = @fopen($tmpPath, 'wb');
    if ($tmpHandle === false) {
        fclose($resource);
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Could not open temp file for writing'];
    }

    $written = 0;
    $error   = null;

    while (!feof($resource)) {
        $chunk = fread($resource, 8192);
        if ($chunk === false) {
            $error = 'Read error during download';
            break;
        }
        $written += strlen($chunk);
        if ($written > $maxBytes) {
            $error = 'File exceeds maximum upload size (' . round($maxBytes / 1048576, 1) . ' MB)';
            break;
        }
        fwrite($tmpHandle, $chunk);
    }

    fclose($resource);
    fclose($tmpHandle);

    if ($error !== null) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => $error];
    }

    if ($written === 0) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Downloaded file is empty'];
    }

    return ['ok' => true, 'tmp_path' => $tmpPath, 'size' => $written];
}

/**
 * Validate a downloaded temp file, move it to the CMS uploads directory,
 * and register it in the cms_media table.
 *
 * @return array{ok: bool, cms_media_id?: int, file_path?: string, url?: string, error?: string}
 */
function wpBridgeSaveAsMediaFile(string $tmpPath, string $originalName, int $uploadedBy): array
{
    if (!is_file($tmpPath)) {
        return ['ok' => false, 'error' => 'Temp file not found'];
    }

    // Validate mime type against CMS allowed list
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpPath) ?: '');

    if ($mimeType === '' || !in_array($mimeType, cmsAllowedMediaMimeTypes(), true)) {
        return ['ok' => false, 'error' => 'File type not allowed: ' . ($mimeType ?: 'unknown')];
    }

    // Sanitize original name to extract a safe extension
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $originalName) ?: 'media.jpg';
    $filename = cmsGenerateMediaFilename($safeName);
    $subDir   = date('Y') . '/' . date('m');
    $uploadDir = cmsUploadsPath() . '/' . $subDir;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory'];
    }

    $destPath = $uploadDir . '/' . $filename;

    if (!copy($tmpPath, $destPath)) {
        return ['ok' => false, 'error' => 'Could not copy file to uploads directory'];
    }

    // SVG sanitization (prevent XSS in SVG uploads)
    if ($mimeType === 'image/svg+xml' && function_exists('cmsSanitizeSvgContent')) {
        $svgContent = (string)file_get_contents($destPath);
        $sanitized  = cmsSanitizeSvgContent($svgContent);
        if ($sanitized !== $svgContent) {
            file_put_contents($destPath, $sanitized);
        }
    }

    $relPath  = $subDir . '/' . $filename;
    $fileSize = (int)(@filesize($destPath) ?: 0);

    // Register in cms_media (via CMS module's DB context — it owns this table)
    $db = cmsDb();
    $stmt = $db->prepare(
        "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)
         VALUES (:fname, :oname, :mime, :size, :path, :uid, NOW())"
    );
    $stmt->execute([
        ':fname' => $filename,
        ':oname' => $originalName,
        ':mime'  => $mimeType,
        ':size'  => $fileSize,
        ':path'  => $relPath,
        ':uid'   => max(1, $uploadedBy),
    ]);
    $mediaId = (int)$db->lastInsertId();

    return [
        'ok'          => true,
        'cms_media_id' => $mediaId,
        'file_path'   => $relPath,
        'url'         => cmsResolveUploadUrl($relPath),
    ];
}

/**
 * Write (or update) a record in bridge_media_log.
 * Called for both successful fetches and failures.
 */
function wpBridgeLogMedia(
    string  $source,
    string  $externalUrl,
    string  $urlHash,
    ?string $fileHash,
    ?int    $cmsMediaId,
    ?string $localUrl,
    string  $status,
    string  $errorMessage = ''
): void {
    $db = wpBridgeDb();
    $stmt = $db->prepare(
        "INSERT INTO bridge_media_log
             (source, external_url, url_hash, file_hash, cms_media_id, local_url, status, error_message)
         VALUES (:source, :url, :uh, :fh, :mid, :lurl, :status, :err)
         ON DUPLICATE KEY UPDATE
             file_hash     = COALESCE(VALUES(file_hash),    file_hash),
             cms_media_id  = COALESCE(VALUES(cms_media_id), cms_media_id),
             local_url     = COALESCE(VALUES(local_url),    local_url),
             status        = VALUES(status),
             error_message = VALUES(error_message)"
    );
    $stmt->execute([
        ':source' => $source,
        ':url'    => $externalUrl,
        ':uh'     => $urlHash,
        ':fh'     => $fileHash,
        ':mid'    => $cmsMediaId,
        ':lurl'   => $localUrl,
        ':status' => $status,
        ':err'    => $errorMessage !== '' ? $errorMessage : null,
    ]);
}

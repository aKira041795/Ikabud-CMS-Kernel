<?php

declare(strict_types=1);

/**
 * Page: Download attachment
 */
function palPageAttachmentDownload(array $rp = []): void
{
    $u = palCurrentUser();
    $tid = (int)($u['tenant_id'] ?? 0);
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $db = palDb();
    $stmt = $db->prepare("SELECT * FROM pal_attachments WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tid]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$att) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $absPath = PUBLIC_PATH . '/' . $att['file_path'];
    if (!file_exists($absPath)) {
        http_response_code(404);
        echo 'File not found on disk';
        return;
    }

    header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($att['original_filename']));
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

/**
 * API: Upload attachment
 */
function palApiAttachmentUpload(): void
{
    palResponseGuard(function (): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $tid = (int)(app()->tenant()->current() ?? $u['tenant_id'] ?? 0);

        $entityType = $_POST['entity_type'] ?? '';
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $description = $_POST['description'] ?? '';

        if ($entityType === '') {
            palJsonError('Entity type is required.');
            return;
        }
        // entity_id=0 is allowed for pre-creation uploads (e.g. mockup before save)

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $details = '';
            if (!isset($_FILES['file'])) {
                $details = 'No file in request.';
            } else {
                $code = $_FILES['file']['error'];
                $errNames = [0=>'OK',1=>'INI_SIZE',2=>'FORM_SIZE',3=>'PARTIAL',4=>'NO_FILE',6=>'TMP_DIR',7=>'CANT_WRITE',8=>'EXTENSION'];
                $details = 'Upload error code ' . $code . ' (' . ($errNames[$code] ?? 'UNKNOWN') . ')';
            }
            palJsonError('File upload failed: ' . $details, 422);
            return;
        }

        $file = $_FILES['file'];
        $originalName = basename($file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;

        // Relative path: uploads/pal/{tenant_id}/{entity_type}/{entity_id}/
        $relDir = 'uploads/pal/' . $tid . '/' . $entityType . '/' . $entityId;
        $absDir = PUBLIC_PATH . '/' . $relDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }

        $destPath = $absDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            palJsonError('Failed to save file.');
            return;
        }

        $db = palDb();
        $stmt = $db->prepare(
            "INSERT INTO pal_attachments (tenant_id, entity_type, entity_id, filename, original_filename, mime_type, file_size, file_path, description, uploaded_by)
             VALUES (:t, :et, :eid, :fn, :ofn, :mime, :fs, :fp, :desc, :ub)"
        );
        $stmt->execute([
            ':t' => $tid,
            ':et' => $entityType,
            ':eid' => $entityId,
            ':fn' => $safeName,
            ':ofn' => $originalName,
            ':mime' => $file['type'] ?? null,
            ':fs' => $file['size'] ?? 0,
            ':fp' => $relDir . '/' . $safeName,
            ':desc' => $description ?: null,
            ':ub' => (int)$u['id'],
        ]);

        $attachId = (int)$db->lastInsertId();
        palAudit('pal.attachment.uploaded', (int)$u['id'], $entityType, (string)$entityId, null, [
            'attachment_id' => $attachId, 'filename' => $originalName,
        ]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $attachId]);
    });
}

/**
 * API: Delete attachment
 */
function palApiAttachmentDelete(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $tid = (int)(app()->tenant()->current() ?? $u['tenant_id'] ?? 0);
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);

        if ($id <= 0) {
            palJsonError('Invalid attachment ID.');
            return;
        }

        $db = palDb();
        $stmt = $db->prepare("SELECT * FROM pal_attachments WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$att) {
            palJsonError('Attachment not found.');
            return;
        }

        // Delete file from disk
        $absPath = PUBLIC_PATH . '/' . $att['file_path'];
        if (file_exists($absPath)) {
            unlink($absPath);
        }

        $del = $db->prepare("DELETE FROM pal_attachments WHERE id = :id AND tenant_id = :tid");
        $del->execute([':id' => $id, ':tid' => $tid]);

        palAudit('pal.attachment.deleted', (int)$u['id'], $att['entity_type'], (string)$att['entity_id'], null, [
            'attachment_id' => $id, 'filename' => $att['original_filename'],
        ]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

/**
 * Helper: Render PO image gallery (thumbnail grid with lightbox)
 */
function palRenderPoImages(int $projectId, ?int $tenantId = null): string
{
    try {
        $db = palDb();
        $tid = $tenantId ?? (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare(
            "SELECT * FROM pal_attachments WHERE tenant_id = :tid AND entity_type = 'po' AND entity_id = :eid ORDER BY created_at DESC"
        );
        $stmt->execute([':tid' => $tid, ':eid' => $projectId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($files)) return '<p class="text-xs text-gray-400 col-span-full">No PO images uploaded yet.</p>';

        $html = '';
        foreach ($files as $f) {
            $imgUrl = '/' . $f['file_path'];
            $caption = htmlspecialchars($f['description'] ?? $f['original_filename'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="relative group w-20">';
            $html .= '<a href="#" onclick="openLightbox(\'' . $imgUrl . '\',\'' . addslashes($caption) . '\');return false" class="block w-20 border rounded overflow-hidden bg-gray-100 cursor-zoom-in">';
            $html .= '<img src="' . $imgUrl . '" class="w-20 h-20 object-cover rounded" loading="lazy">';
            $html .= '</a>';
            if ($f['description']) {
                $html .= '<p class="text-xs text-gray-500 mt-1 truncate">' . htmlspecialchars($f['description'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html .= '<button onclick="deletePoImage(' . (int)$f['id'] . ')" class="absolute top-1 right-1 bg-red-600 text-white text-xs px-1.5 py-0.5 rounded opacity-0 group-hover:opacity-100 transition-opacity">✕</button>';
            $html .= '</div>';
        }
        return $html;
    } catch (Throwable) {
        return '<p class="text-xs text-gray-400">Error loading images.</p>';
    }
}
function palRenderAttachments(string $entityType, int $entityId, ?int $tenantId = null): string
{
    try {
        $db = palDb();
        $tid = $tenantId ?? (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare(
            "SELECT * FROM pal_attachments WHERE tenant_id = :tid AND entity_type = :et AND entity_id = :eid ORDER BY created_at DESC"
        );
        $stmt->execute([':tid' => $tid, ':et' => $entityType, ':eid' => $entityId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($files)) return '<p class="text-xs text-gray-400">No files uploaded yet.</p>';

        $html = '<div class="space-y-1">';
        foreach ($files as $f) {
            $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $f['original_filename']);
            $html .= '<div class="flex justify-between items-center text-sm py-1">';
            if ($isImg) {
                $imgUrl = '/' . $f['file_path'];
                $html .= '<a href="#" onclick="openLightbox(\'' . $imgUrl . '\',\'' . addslashes(htmlspecialchars($f['original_filename'], ENT_QUOTES, 'UTF-8')) . '\');return false" class="text-blue-600 hover:text-blue-800">🖼 ' . htmlspecialchars($f['original_filename'], ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $html .= '<a href="/admin/project-audit-ledger/attachments/' . (int)$f['id'] . '/download" class="text-blue-600 hover:text-blue-800">📎 ' . htmlspecialchars($f['original_filename'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            $html .= '<span class="text-xs text-gray-400">' . number_format((int)$f['file_size'] / 1024, 1) . ' KB</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    } catch (Throwable) {
        return '';
    }
}

/**
 * API: List attachments by entity type and ID (JSON)
 */
function palApiAttachmentList(): void
{
    $u = palCurrentUser();
    $tid = (int)(app()->tenant()->current() ?? $u['tenant_id'] ?? 0);
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = (int)($_GET['entity_id'] ?? 0);

    if (!$entityType || !$entityId) {
        header('Content-Type: application/json');
        echo json_encode([]);
        return;
    }

    $db = palDb();
    $stmt = $db->prepare("SELECT id, original_filename, description, file_path, mime_type, file_size, created_at FROM pal_attachments WHERE tenant_id = :tid AND entity_type = :et AND entity_id = :eid ORDER BY created_at DESC");
    $stmt->execute([':tid' => $tid, ':et' => $entityType, ':eid' => $entityId]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

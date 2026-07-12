<?php

declare(strict_types=1);

/**
 * Domain service for attachment management in PAL.
 *
 * Handles upload, retrieval, listing, deletion, and lifecycle of file
 * attachments across all PAL entity types (projects, sales, expenses, etc.).
 * Enforces ownership boundaries and provides audit logging for all operations.
 */
class palAttachmentService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;
    private int $userId;

    /** @var array<string, string[]> Allowlisted MIME type → extension pairs */
    private const ALLOWED_MIMES = [
        'image/jpeg'               => ['jpg', 'jpeg'],
        'image/png'                => ['png'],
        'image/gif'                => ['gif'],
        'image/webp'               => ['webp'],
        'application/pdf'          => ['pdf'],
        'application/msword'       => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'text/plain'               => ['txt', 'csv'],
        'text/csv'                 => ['csv'],
        'application/zip'          => ['zip'],
    ];

    /** @var int Maximum file size in bytes (10MB) */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var string[] Allowlisted entity types */
    private const ALLOWED_ENTITY_TYPES = [
        'project', 'sale', 'expense', 'purchase', 'quotation', 'client', 'supplier', 'po',
    ];

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $userId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    /**
     * Upload a file and create an attachment record.
     *
     * @param string $entityType Entity type (project, sale, expense, etc.)
     * @param int $entityId Entity ID (0 allowed for pre-creation uploads)
     * @param array $file $_FILES['file'] array
     * @param string $description Optional description
     * @return int New attachment ID
     * @throws InvalidArgumentException on validation failure
     */
    public function upload(string $entityType, int $entityId, array $file, string $description = ''): int
    {
        if ($entityType === '') {
            throw new InvalidArgumentException('Entity type is required.');
        }

        // Validate entity type against allowlist
        if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Entity type '{$entityType}' is not allowed.");
        }

        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = $file['error'] ?? -1;
            throw new InvalidArgumentException("File upload failed (error code: {$code}).");
        }
        if ($file['size'] <= 0) {
            throw new InvalidArgumentException('Uploaded file is empty.');
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File exceeds maximum size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB.');
        }

        // Determine MIME type server-side only (never trust client-provided MIME)
        $detectedMime = mime_content_type($file['tmp_name']);
        if ($detectedMime === false || $detectedMime === '') {
            throw new InvalidArgumentException('Could not determine file type.');
        }

        $originalName = basename($file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validate MIME + extension against allowlist
        $allowedExts = self::ALLOWED_MIMES[$detectedMime] ?? null;
        if ($allowedExts === null) {
            throw new InvalidArgumentException("File type '{$detectedMime}' is not allowed.");
        }
        if (!in_array($ext, $allowedExts, true)) {
            throw new InvalidArgumentException("Extension '.{$ext}' does not match detected type '{$detectedMime}'.");
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;

        // Build relative path: private/pal/{tenant_id}/{entity_type}/{entity_id}/
        // Files are stored outside the public web root and served only through
        // the authenticated download handler at /admin/.../attachments/{id}/download.
        $relDir = 'private/pal/' . $this->tenantId . '/' . $entityType . '/' . $entityId;
        $absDir = STORAGE_PATH . '/' . $relDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }

        $destPath = $absDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new InvalidArgumentException('Failed to save uploaded file.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO pal_attachments 
                (tenant_id, entity_type, entity_id, filename, original_filename, mime_type, file_size, file_path, description, uploaded_by)
             VALUES (:t, :et, :eid, :fn, :ofn, :mime, :fs, :fp, :desc, :ub)"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':et' => $entityType,
            ':eid' => $entityId,
            ':fn' => $safeName,
            ':ofn' => $originalName,
            ':mime' => $detectedMime,
            ':fs' => $file['size'],
            ':fp' => $relDir . '/' . $safeName,
            ':desc' => mb_substr($description, 0, 255),
            ':ub' => $this->userId,
        ]);
        $newId = (int)$this->db->lastInsertId();

        palAudit('pal.attachment.uploaded', $this->userId, 'pal_attachments', (string)$newId,
            null, ['entity_type' => $entityType, 'entity_id' => $entityId, 'filename' => $originalName]);

        return $newId;
    }

    /**
     * Get attachment by ID.
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pal_attachments WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List attachments for a specific entity.
     */
    public function listForEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, u.full_name AS uploaded_by_name
             FROM pal_attachments a
             LEFT JOIN pal_users u ON a.uploaded_by = u.id
             WHERE a.tenant_id = :tid AND a.entity_type = :et AND a.entity_id = :eid
             ORDER BY a.created_at DESC"
        );
        $stmt->execute([':tid' => $this->tenantId, ':et' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete an attachment (removes file from disk and record from DB).
     *
     * @throws InvalidArgumentException if attachment not found or not owned by tenant
     */
    public function delete(int $id): bool
    {
        $att = $this->get($id);
        if ($att === null) {
            throw new InvalidArgumentException('Attachment not found.');
        }

        // Remove file from private storage
        $absPath = STORAGE_PATH . '/' . $att['file_path'];
        if (file_exists($absPath)) {
            unlink($absPath);
        }

        $this->db->prepare("DELETE FROM pal_attachments WHERE id = :id AND tenant_id = :tid")
            ->execute([':id' => $id, ':tid' => $this->tenantId]);

        palAudit('pal.attachment.deleted', $this->userId, 'pal_attachments', (string)$id,
            null, ['entity_type' => $att['entity_type'], 'entity_id' => $att['entity_id'], 'filename' => $att['original_filename']]);

        return true;
    }

    /**
     * Reassign attachments from one entity to another
     * (e.g., when an entity ID changes from 0 to the real ID after creation).
     */
    public function reassign(int $oldEntityId, int $newEntityId, string $entityType): int
    {
        $stmt = $this->db->prepare(
            "UPDATE pal_attachments SET entity_id = :neid WHERE tenant_id = :tid AND entity_type = :et AND entity_id = :oeid"
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':et' => $entityType,
            ':oeid' => $oldEntityId,
            ':neid' => $newEntityId,
        ]);
        $affected = $stmt->rowCount();

        // Also move files on disk
        $oldDir = STORAGE_PATH . '/private/pal/' . $this->tenantId . '/' . $entityType . '/' . $oldEntityId;
        $newDir = STORAGE_PATH . '/private/pal/' . $this->tenantId . '/' . $entityType . '/' . $newEntityId;
        if (is_dir($oldDir) && $oldEntityId !== $newEntityId) {
            if (!is_dir($newDir)) {
                mkdir($newDir, 0755, true);
            }
            foreach (scandir($oldDir) as $file) {
                if ($file[0] === '.') continue;
                rename($oldDir . '/' . $file, $newDir . '/' . $file);
            }
            rmdir($oldDir);
        }

        return $affected;
    }

    /**
     * Get the absolute file path for serving/download.
     */
    public function getFilePath(array $attachment): string
    {
        return STORAGE_PATH . '/' . ltrim($attachment['file_path'] ?? '', '/');
    }
}

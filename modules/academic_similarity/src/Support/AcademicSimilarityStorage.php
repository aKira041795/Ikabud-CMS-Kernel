<?php
declare(strict_types=1);

class AcademicSimilarityStorage
{
    /**
     * Store a file from a local path into the academic similarity storage area.
     *
     * @param string $sourcePath      Absolute path to the source file.
     * @param string $originalFilename Original filename for extension detection.
     * @return array{storage_name: string, storage_path: string, file_size_bytes: int, checksum_sha256: string}
     */
    public function storeFile(string $sourcePath, string $originalFilename): array
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storageName = $this->generateStorageName($extension);
        $relativePath = $this->relativeStoragePath($storageName);
        $fullPath = $this->getFullPath($relativePath);

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!copy($sourcePath, $fullPath)) {
            throw new RuntimeException("Failed to copy file to storage: {$fullPath}");
        }

        return $this->buildResult($storageName, $relativePath, $fullPath);
    }

    /**
     * Store an uploaded file from the $_FILES array.
     *
     * @param array $file A single $_FILES entry (must have tmp_name, name, error).
     * @return array{storage_name: string, storage_path: string, file_size_bytes: int, checksum_sha256: string}
     */
    public function storeUploadedFile(array $file): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file: not a valid upload');
        }

        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($file['error']));
        }

        return $this->storeFile($file['tmp_name'], $file['name'] ?? 'upload.bin');
    }

    /**
     * Get the absolute filesystem path for a relative storage path.
     *
     * @param string $storagePath Relative storage path (e.g. "ab/cd/uuid.ext").
     * @return string Absolute path.
     */
    public function getFullPath(string $storagePath): string
    {
        $base = $this->storageBasePath();
        return rtrim($base, '/') . '/' . ltrim($storagePath, '/');
    }

    /**
     * Delete a file from storage by its relative path.
     *
     * @param string $storagePath Relative storage path.
     */
    public function deleteFile(string $storagePath): void
    {
        $fullPath = $this->getFullPath($storagePath);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Get the base storage path for academic similarity files.
     * Delegates to the helper function defined by the module.
     *
     * @return string Absolute path to the storage root.
     */
    public function storageBasePath(): string
    {
        return academic_similarity_storage_path();
    }

    /**
     * Generate a UUID-based storage name with the given extension.
     */
    private function generateStorageName(string $extension): string
    {
        $uuid = $this->generateUuid();
        $ext = ltrim($extension, '.');
        return $ext !== '' ? "{$uuid}.{$ext}" : $uuid;
    }

    /**
     * Compute a relative storage path using a 2-level directory prefix
     * from the storage name to avoid too many files in a single directory.
     */
    private function relativeStoragePath(string $storageName): string
    {
        $prefix = substr($storageName, 0, 2);
        $subPrefix = substr($storageName, 2, 2);
        return "{$prefix}/{$subPrefix}/{$storageName}";
    }

    /**
     * Build the result array with checksum and size.
     */
    private function buildResult(string $storageName, string $relativePath, string $fullPath): array
    {
        $checksum = hash_file('sha256', $fullPath);
        if ($checksum === false) {
            throw new RuntimeException('Failed to compute file checksum');
        }

        $size = filesize($fullPath);
        if ($size === false) {
            throw new RuntimeException('Failed to read file size');
        }

        return [
            'storage_name' => $storageName,
            'storage_path' => $relativePath,
            'file_size_bytes' => $size,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * Generate a v4 UUID.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to 10xx
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Map PHP upload error codes to human-readable messages.
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server maximum upload size',
            UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds form-specified maximum size',
            UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially received',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing temporary upload directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => "Unknown upload error (code: {$code})",
        };
    }
}

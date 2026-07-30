<?php
declare(strict_types=1);

class AcademicSimilarityFileValidator
{
    /** @var array<string, string> Map of allowed extensions to canonical MIME types */
    private const ALLOWED_EXTENSION_MAP = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
    ];

    /** @var array<int, string> Allowed MIME types (canonical forms) */
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/pdf',
        'text/plain',
        'text/plain; charset=utf-8',
    ];

    /**
     * Validate an uploaded file against module settings.
     *
     * @param array $file     A $_FILES entry.
     * @param array $settings Module settings (expects 'max_file_size_mb' int).
     * @param bool  $allowTrustedLocalFile Allow an internal capability-created temp file.
     * @return array{ok: bool, error?: string}
     */
    public function validate(array $file, array $settings, bool $allowTrustedLocalFile = false): array
    {
        // Check for upload errors
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorString($file['error']));
        }

        // Ensure a file was actually provided
        $tmpName = (string)($file['tmp_name'] ?? '');
        $validUploadedFile = $tmpName !== '' && is_uploaded_file($tmpName);
        $validTrustedFile = $allowTrustedLocalFile && $tmpName !== '' && is_file($tmpName);
        if (!$validUploadedFile && !$validTrustedFile) {
            return $this->fail('No valid uploaded file provided');
        }

        // Validate extension
        $filename = $file['name'] ?? '';
        $allowedExtensions = (string)($settings['allowed_extensions'] ?? 'docx,pdf,txt');

        if (!$this->validateExtension($filename, $allowedExtensions)) {
            return $this->fail("File extension not allowed. Allowed: {$allowedExtensions}");
        }

        // Detect and validate MIME type
        $detectedMime = $this->detectMimeType($file['tmp_name']);
        if (!$this->validateMimeType($detectedMime)) {
            return $this->fail(
                "File MIME type '{$detectedMime}' is not allowed. Allowed: " . implode(', ', self::ALLOWED_MIME_TYPES)
            );
        }

        // Validate file size
        $maxMb = (int)($settings['max_file_size_mb'] ?? 10);
        if (!$this->validateFileSize(filesize($file['tmp_name']), $maxMb)) {
            return $this->fail("File exceeds maximum size of {$maxMb} MB");
        }

        // Validate content safety (zip bombs, encrypted PDFs)
        if (!$this->validateContent($file['tmp_name'])) {
            return $this->fail('File content failed safety validation');
        }

        return ['ok' => true];
    }

    /**
     * Validate file extension against a comma-separated list of allowed extensions.
     * Also rejects filenames with null bytes or path traversal sequences.
     *
     * @param string $filename          Original filename.
     * @param string $allowedExtensions Comma-separated list, e.g. "docx,pdf,txt".
     * @return bool True if extension is allowed and filename is safe.
     */
    public function validateExtension(string $filename, string $allowedExtensions): bool
    {
        // Reject null bytes
        if (str_contains($filename, "\0")) {
            return false;
        }
        // Reject path traversal sequences
        if (preg_match('#(?:^|/)\\.\\.(?:/|$)#', $filename)) {
            return false;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = array_map('trim', explode(',', strtolower($allowedExtensions)));
        return in_array($ext, $allowed, true);
    }

    /**
     * Validate that a MIME type is in the allowed list.
     *
     * @param string $mimeType Detected MIME type.
     * @return bool True if allowed.
     */
    public function validateMimeType(string $mimeType): bool
    {
        // Normalize: strip charset suffix for comparison
        $normalized = strtok(strtolower($mimeType), ';');
        $normalized = trim($normalized);

        foreach (self::ALLOWED_MIME_TYPES as $allowed) {
            $allowedNorm = strtok(strtolower($allowed), ';');
            $allowedNorm = trim($allowedNorm);
            if ($normalized === $allowedNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate file size against a maximum in megabytes.
     *
     * @param int $bytes Actual file size in bytes.
     * @param int $maxMb Maximum allowed size in megabytes.
     * @return bool True if within limit.
     */
    public function validateFileSize(int $bytes, int $maxMb): bool
    {
        return $bytes <= ($maxMb * 1024 * 1024);
    }

    /**
     * Detect MIME type using both file extension and content-based sniffing.
     *
     * @param string $path Absolute path to the file.
     * @return string Detected MIME type.
     */
    public function detectMimeType(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            return 'application/octet-stream';
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime !== false && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }

        // Fallback: mime_content_type
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime !== false && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }

        // Last resort: sniff magic bytes
        return $this->sniffMimeByMagicBytes($path);
    }

    /**
     * Validate content safety: check for zip bombs in DOCX, encryption in PDF.
     *
     * @param string $path Absolute path to the file.
     * @return bool True if content passes safety checks.
     */
    public function validateContent(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'docx' => $this->validateDocxContent($path),
            'pdf'  => $this->validatePdfContent($path),
            'txt'  => true, // Plain text has no known bomb vectors
            default => true,
        };
    }

    /**
     * Validate DOCX content: check uncompressed size against a safety limit.
     */
    private function validateDocxContent(string $path): bool
    {
        $zip = new ZipArchive();
        $res = $zip->open($path);
        if ($res !== true) {
            return false;
        }

        $totalSize = 0;
        $maxUncompressedBytes = 100 * 1024 * 1024; // 100 MB safety limit

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                return false;
            }
            $totalSize += $stat['size'] ?? 0;
            if ($totalSize > $maxUncompressedBytes) {
                $zip->close();
                return false; // Exceeds limit — likely a zip bomb
            }
        }

        $zip->close();
        return true;
    }

    /**
     * Validate PDF content: check that it is not encrypted.
     */
    private function validatePdfContent(string $path): bool
    {
        $content = file_get_contents($path, false, null, 0, 4096);
        if ($content === false) {
            return false;
        }

        // Check for encryption marker in PDF trailer or catalog
        if (stripos($content, '/Encrypt') !== false) {
            return false; // Encrypted PDFs are not supported
        }

        return true;
    }

    /**
     * Sniff MIME type from magic bytes as a last resort.
     */
    private function sniffMimeByMagicBytes(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'application/octet-stream';
        }

        $bytes = fread($handle, 8);
        fclose($handle);

        if ($bytes === false || $bytes === '') {
            return 'application/octet-stream';
        }

        // PDF: starts with %PDF
        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }

        // DOCX / ZIP: starts with PK\x03\x04
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        // TXT: check if printable ASCII or UTF-8
        if ($this->looksLikeText($bytes)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    /**
     * Check if byte sequence looks like printable text.
     */
    private function looksLikeText(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length === 0) {
            return false;
        }

        $printable = 0;
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($bytes[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $printable++;
            } elseif (in_array($ord, [9, 10, 13], true)) {
                // Tab, newline, carriage return
                $printable++;
            } elseif ($ord >= 0xC0 && $ord <= 0xFD) {
                // Likely UTF-8 multi-byte start
                $printable++;
            }
        }

        return ($printable / $length) > 0.8;
    }

    /**
     * Return a failure result array.
     *
     * @param string $message Error description.
     * @return array{ok: false, error: string}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    /**
     * Map upload error code to a human-readable string.
     */
    private function uploadErrorString(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds the server maximum upload size',
            UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the maximum allowed size',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was selected for upload',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temporary directory',
            UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A server extension prevented the upload',
            default => "Unknown upload error (code: {$code})",
        };
    }
}

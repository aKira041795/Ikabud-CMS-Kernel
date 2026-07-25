<?php
declare(strict_types=1);

/**
 * Extraction resource limits for document text extraction.
 *
 * Prevents out-of-memory crashes caused by large or malicious
 * document files that expand significantly during text extraction.
 * These limits are checked before and during extraction, not after
 * memory pressure is already detected.
 */
final class ExtractionLimits
{
    /** Maximum uploaded file size (bytes). Matches the 20 MB upload limit. */
    public const MAX_UPLOAD_BYTES = 20_000_000;

    /** Maximum total uncompressed size of ZIP archive entries (bytes).
     *  A 20 MB DOCX can contain heavily compressed XML that expands
     *  many times over when decompressed. */
    public const MAX_UNCOMPRESSED_BYTES = 50_000_000;

    /** Maximum extracted plain-text characters after strip_tags + decode. */
    public const MAX_EXTRACTED_CHARACTERS = 500_000;

    /** Maximum ZIP entries in a DOCX archive. Prevents zip-bomb via
     *  many small entries that collectively expand beyond memory limits. */
    public const MAX_ZIP_ENTRIES = 2_000;

    /** Maximum pasted text length (characters). Pasted text bypasses
     *  file-based extraction but still consumes memory. */
    public const MAX_PASTED_CHARACTERS = 500_000;

    /** Maximum segments allowed from a single submission.
     *  Prevents unbounded segment-storage blowup from extreme
     *  paragraph/sentence counts in extracted text. */
    public const MAX_SEGMENTS = 10_000;

    /**
     * Check file size against the upload limit.
     *
     * @param int $bytes Actual file size in bytes.
     * @return string|null Error message if over limit, null if OK.
     */
    public static function checkFileSize(int $bytes): ?string
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            $mb = self::MAX_UPLOAD_BYTES / 1_000_000;
            return "File size ({$bytes} bytes) exceeds maximum upload size of {$mb} MB";
        }
        return null;
    }

    /**
     * Check ZIP archive structure for DOCX bombs.
     *
     * Verifies:
     * - Entry count does not exceed MAX_ZIP_ENTRIES
     * - Total uncompressed size does not exceed MAX_UNCOMPRESSED_BYTES
     *
     * @param string $zipPath Absolute path to the ZIP/DOCX file.
     * @return string|null Error message if limits exceeded, null if OK.
     */
    public static function checkZipArchive(string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return null; // Will be caught by the extractor
        }

        $numEntries = $zip->count();
        if ($numEntries === false || $numEntries > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            return "ZIP archive contains {$numEntries} entries, exceeds limit of " . self::MAX_ZIP_ENTRIES;
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $numEntries; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $totalUncompressed += $stat['size'] ?? 0;
                if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                    $zip->close();
                    $mb = self::MAX_UNCOMPRESSED_BYTES / 1_000_000;
                    return "Total uncompressed ZIP size exceeds {$mb} MB limit";
                }
            }
        }

        $zip->close();
        return null;
    }

    /**
     * Check extracted text length against the character limit.
     *
     * @param string $text Extracted plain text.
     * @return string|null Error message if over limit, null if OK.
     */
    public static function checkExtractedText(string $text): ?string
    {
        $len = mb_strlen($text);
        if ($len > self::MAX_EXTRACTED_CHARACTERS) {
            return "Extracted text length ({$len} characters) exceeds limit of " . self::MAX_EXTRACTED_CHARACTERS;
        }
        return null;
    }

    /**
     * Check pasted text length against the paste limit.
     *
     * @param string $text Pasted submission text.
     * @return string|null Error message if over limit, null if OK.
     */
    public static function checkPastedText(string $text): ?string
    {
        $len = mb_strlen($text);
        if ($len > self::MAX_PASTED_CHARACTERS) {
            return "Pasted text length ({$len} characters) exceeds limit of " . self::MAX_PASTED_CHARACTERS;
        }
        return null;
    }
}

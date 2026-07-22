<?php
declare(strict_types=1);

class AcademicSimilarityTextExtractor
{
    /**
     * Extract text from a file based on its MIME type.
     *
     * @param string $storagePath Absolute path to the stored file.
     * @param string $mimeType    MIME type of the file.
     * @return array{text: string, page_count: int, method: string}
     */
    public function extract(string $storagePath, string $mimeType): array
    {
        return match ($mimeType) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword' => $this->extractDocxResult($storagePath),
            'application/pdf' => $this->extractPdfResult($storagePath),
            'text/plain', 'text/plain; charset=utf-8' => $this->extractTxtResult($storagePath),
            default => throw new InvalidArgumentException("Unsupported MIME type: {$mimeType}"),
        };
    }

    /**
     * Extract text from a DOCX file.
     *
     * @param string $path Absolute path to the .docx file.
     * @return string Extracted plain text.
     */
    public function extractDocx(string $path): string
    {
        $zip = new ZipArchive();
        $res = $zip->open($path);
        if ($res !== true) {
            throw new RuntimeException("Failed to open DOCX file: {$path}");
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('DOCX archive missing word/document.xml');
        }

        // Strip XML tags, decode HTML entities, normalize whitespace
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Extract text from a PDF file using a simple regex-based approach.
     * This is a best-effort extractor for MVP; no external PDF library is required.
     *
     * @param string $path Absolute path to the .pdf file.
     * @return string Extracted plain text.
     */
    public function extractPdf(string $path): string
    {
        $externalText = $this->extractPdfWithPdftotext($path);
        if ($externalText !== '') {
            return $externalText;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read PDF file: {$path}");
        }

        $text = '';
        preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all('/\(([^)]*)\)/', $block, $matches);
            foreach ($matches[1] as $part) {
                $part = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", "\r", "\t"], $part);
                $text .= $part . ' ';
            }
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
    }

    private function extractPdfWithPdftotext(string $path): string
    {
        if (!function_exists('exec')) {
            return '';
        }

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $binary) {
            if (!is_executable($binary)) {
                continue;
            }

            $output = [];
            $exitCode = 1;
            @exec(escapeshellarg($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                $text = trim((string)preg_replace('/\s+/u', ' ', implode("\n", $output)));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Extract text from a TXT file.
     *
     * @param string $path Absolute path to the .txt file.
     * @return string Extracted text.
     */
    public function extractTxt(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read text file: {$path}");
        }

        // Detect and re-encode if not UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Normalize line endings and trim
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        return trim($content);
    }

    /**
     * Extract DOCX and return result array.
     */
    private function extractDocxResult(string $path): array
    {
        $text = $this->extractDocx($path);
        return [
            'text' => $text,
            'page_count' => $this->estimatePageCountDocx($path),
            'method' => 'docx_zip',
        ];
    }

    /**
     * Extract PDF and return result array.
     */
    private function extractPdfResult(string $path): array
    {
        $text = $this->extractPdf($path);
        return [
            'text' => $text,
            'page_count' => $this->estimatePageCountPdf($path),
            'method' => 'pdf_regex',
        ];
    }

    /**
     * Extract TXT and return result array.
     */
    private function extractTxtResult(string $path): array
    {
        $text = $this->extractTxt($path);
        return [
            'text' => $text,
            'page_count' => 1,
            'method' => 'txt_plain',
        ];
    }

    /**
     * Estimate page count from a DOCX file by counting section breaks.
     */
    private function estimatePageCountDocx(string $path): int
    {
        $zip = new ZipArchive();
        $res = $zip->open($path);
        if ($res !== true) {
            return 1;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return 1;
        }

        // Count explicit page breaks and section breaks as a rough estimate
        $breaks = substr_count($xml, 'w:type="page"');
        $sections = substr_count($xml, 'w:sectPr');
        return max(1, $breaks + $sections);
    }

    /**
     * Estimate page count from a PDF file by counting page objects.
     */
    private function estimatePageCountPdf(string $path): int
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return 1;
        }

        // Count /Type /Page entries (not /PageLabels or /PageMode)
        preg_match_all('/\/Type\s*\/Page[^s]/', $content, $matches);
        $count = count($matches[0]);

        return max(1, $count);
    }
}

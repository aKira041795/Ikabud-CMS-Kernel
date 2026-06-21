<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Kernel Export Service — governed document export.
 *
 * Provides a unified kernel-level export surface for all modules.
 * Modules register data via capabilities; the kernel handles format,
 * output, and governance.
 *
 * Supported formats: pdf, docx, xlsx, csv
 *
 * NOTE: All methods are static with internal static state. Tests should
 * call reset() between test cases to avoid state leakage.
 *
 * @package Ikabud\Kernel\Services
 * @version 1.0.0
 */
final class KernelExport
{
    /** @var array<string, array<string, mixed>> registered export handlers */
    private static array $handlers = [];

    /** @var array<string, bool> supported formats */
    private const SUPPORTED_FORMATS = [
        'pdf' => true,
        'docx' => true,
        'xlsx' => false, // PhpSpreadsheet not yet required
        'csv' => true,
    ];

    // ── Handler registration ──

    /**
     * Register an export handler for a specific entity type + format.
     *
     * @param callable $handler  (array $data, array $options) → string filePath
     */
    public static function register(string $entityType, string $format, callable $handler, string $providerId = 'kernel'): void
    {
        $format = strtolower(trim($format));
        $key = trim($entityType) . '.' . $format;
        self::$handlers[$key] = [
            'entity_type' => $entityType,
            'format' => $format,
            'handler' => $handler,
            'provider' => $providerId,
        ];
    }

    /**
     * Check if export is supported for an entity type + format combination.
     */
    public static function supports(string $entityType, string $format): bool
    {
        $format = strtolower(trim($format));
        if (empty(self::SUPPORTED_FORMATS[$format])) {
            // CSV always works as a fallback
            if ($format === 'csv') {
                return true;
            }
            return false;
        }

        $key = trim($entityType) . '.' . $format;
        return isset(self::$handlers[$key]) || $format === 'csv';
    }

    /**
     * Export data to a file and return the file path.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $options  title, filename, columns, orientation
     * @return array{path: string, filename: string, mime: string, size: int}|null
     */
    public static function export(string $entityType, string $format, array $rows, array $options = []): ?array
    {
        $format = strtolower(trim($format));
        $key = trim($entityType) . '.' . $format;

        $title = (string)($options['title'] ?? ucfirst($entityType) . ' Export');
        $filename = (string)($options['filename'] ?? $entityType . '-export-' . date('Y-m-d') . '.' . $format);

        // CSV fallback — always works
        if ($format === 'csv' || !isset(self::$handlers[$key])) {
            return self::exportCsv($rows, $title, $filename, $options);
        }

        // Registered handler
        try {
            $handler = self::$handlers[$key]['handler'];
            $path = $handler($rows, array_merge($options, [
                'title' => $title,
                'filename' => $filename,
            ]));

            if (!is_string($path) || !file_exists($path)) {
                return null;
            }

            return [
                'path' => $path,
                'filename' => basename($path),
                'mime' => self::mimeType($format),
                'size' => filesize($path),
            ];
        } catch (\Throwable $e) {
            if (\function_exists('write_log')) {
                \write_log("KernelExport: export failed for '{$entityType}.{$format}'", 'warning', [
                    'error' => $e->getMessage(),
                    'entity_type' => $entityType,
                    'format' => $format,
                ]);
            }
            return null;
        }
    }

    // ── Built-in CSV exporter ──

    /**
     * Export rows as CSV (always available, no library required).
     */
    public static function exportCsv(array $rows, string $title, string $filename, array $options = []): ?array
    {
        if (empty($rows)) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . '/' . uniqid('export_', true) . '.csv';
        $fh = fopen($tmpPath, 'w');
        if (!$fh) {
            return null;
        }

        // BOM for Excel compatibility
        fwrite($fh, "\xEF\xBB\xBF");

        // Header
        $columns = is_array($options['columns'] ?? null) ? $options['columns'] : array_keys(reset($rows));
        fputcsv($fh, $columns);

        // Rows
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                if (is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                }
                $line[] = (string)$val;
            }
            fputcsv($fh, $line);
        }

        fclose($fh);

        return [
            'path' => $tmpPath,
            'filename' => $filename,
            'mime' => 'text/csv; charset=utf-8',
            'size' => filesize($tmpPath),
        ];
    }

    // ── Built-in DOCX exporter (PHPWord) ──

    /**
     * Export rows as a DOCX document using PHPWord.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $options  title, filename, columns, orientation
     * @return array{path: string, filename: string, mime: string, size: int}|null
     */
    public static function exportDocx(array $rows, string $title, string $filename, array $options = []): ?array
    {
        if (empty($rows)) {
            return null;
        }

        if (!class_exists('PhpOffice\\PhpWord\\PhpWord')) {
            // Fall back to CSV if PHPWord not available
            return self::exportCsv($rows, $title, str_replace('.docx', '.csv', $filename), $options);
        }

        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();

            // Title
            $section->addTitle(htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), 1);

            // Timestamp
            $section->addText('Generated: ' . date('Y-m-d H:i'), ['size' => 9, 'color' => '888888']);

            // Table
            $columns = is_array($options['columns'] ?? null) ? $options['columns'] : array_keys(reset($rows));
            $table = $section->addTable(['borderSize' => 1, 'borderColor' => 'CCCCCC', 'cellMargin' => 50]);

            // Header row
            $table->addRow();
            foreach ($columns as $col) {
                $cell = $table->addCell(2000);
                $cell->addText(htmlspecialchars(ucwords(str_replace('_', ' ', (string)$col)), ENT_QUOTES, 'UTF-8'),
                    ['bold' => true, 'size' => 10], ['bgColor' => 'F3F4F6']);
            }

            // Data rows
            foreach ($rows as $row) {
                $table->addRow();
                foreach ($columns as $col) {
                    $cell = $table->addCell(2000);
                    $val = $row[$col] ?? '';
                    if (is_array($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                    }
                    $cell->addText(htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'), ['size' => 9]);
                }
            }

            $tmpPath = sys_get_temp_dir() . '/' . uniqid('export_', true) . '.docx';
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tmpPath);

            return [
                'path' => $tmpPath,
                'filename' => $filename,
                'mime' => self::mimeType('docx'),
                'size' => filesize($tmpPath),
            ];
        } catch (\Throwable $e) {
            if (\function_exists('write_log')) {
                \write_log("KernelExport: DOCX export failed", 'warning', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * Register default built-in handlers for common entity types.
     * Call during kernel boot to make CSV and DOCX available for all entities.
     */
    public static function registerDefaults(): void
    {
        // Generic CSV handler — works for any entity type
        self::$handlers['*.csv'] = [
            'entity_type' => '*',
            'format' => 'csv',
            'handler' => fn(array $rows, array $opts) => self::exportCsv(
                $rows,
                (string)($opts['title'] ?? 'Export'),
                (string)($opts['filename'] ?? 'export.csv'),
                $opts
            )['path'] ?? null,
            'provider' => 'kernel',
        ];

        // Generic DOCX handler — works for any entity type
        self::$handlers['*.docx'] = [
            'entity_type' => '*',
            'format' => 'docx',
            'handler' => fn(array $rows, array $opts) => self::exportDocx(
                $rows,
                (string)($opts['title'] ?? 'Export'),
                (string)($opts['filename'] ?? 'export.docx'),
                $opts
            )['path'] ?? null,
            'provider' => 'kernel',
        ];

        // Generic PDF handler — works for any entity type (DomPDF)
        self::$handlers['*.pdf'] = [
            'entity_type' => '*',
            'format' => 'pdf',
            'handler' => fn(array $rows, array $opts) => self::exportPdf(
                $rows,
                (string)($opts['title'] ?? 'Export'),
                (string)($opts['filename'] ?? 'export.pdf'),
                $opts
            )['path'] ?? null,
            'provider' => 'kernel',
        ];
    }

    // ── PDF exporter (DomPDF) ──

    /**
     * Export rows as a PDF document using DomPDF.
     */
    public static function exportPdf(array $rows, string $title, string $filename, array $options = []): ?array
    {
        if (empty($rows)) {
            return null;
        }

        if (!class_exists('Dompdf\\Dompdf')) {
            return self::exportCsv($rows, $title, str_replace('.pdf', '.csv', $filename), $options);
        }

        try {
            $columns = is_array($options['columns'] ?? null) ? $options['columns'] : array_keys(reset($rows));
            $orientation = ($options['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
            $signatureBlock = !empty($options['signature_block']);

            // Build HTML table
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #333; margin: 20px; }
                h1 { font-size: 16px; color: #1e3a5f; margin-bottom: 4px; }
                .meta { font-size: 8px; color: #888; margin-bottom: 16px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
                th { background: #f3f4f6; font-weight: bold; text-align: left; padding: 6px 8px; border-bottom: 2px solid #d1d5db; font-size: 9px; }
                td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
                tr:nth-child(even) td { background: #fafafa; }
                .signature { margin-top: 40px; border-top: 1px solid #333; padding-top: 8px; font-size: 9px; }
                .signature-line { display: inline-block; width: 200px; border-bottom: 1px solid #333; margin: 0 20px; }
                .footer { font-size: 7px; color: #aaa; margin-top: 30px; text-align: center; }
            </style></head><body>';

            $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
            $html .= '<div class="meta">Generated: ' . date('Y-m-d H:i') . ' | Kernel OS 5.3</div>';

            // Table
            $html .= '<table><thead><tr>';
            foreach ($columns as $col) {
                $html .= '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', (string)$col)), ENT_QUOTES, 'UTF-8') . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($columns as $col) {
                    $val = $row[$col] ?? '';
                    if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                    $html .= '<td>' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';

            // Signature block
            if ($signatureBlock) {
                $html .= '<div class="signature">';
                $html .= '<p><strong>Prepared by:</strong> <span class="signature-line"></span></p>';
                $html .= '<p style="margin-top:12px;"><strong>Reviewed by:</strong> <span class="signature-line"></span></p>';
                $html .= '<p style="margin-top:12px;"><strong>Approved by:</strong> <span class="signature-line"></span></p>';
                $html .= '<p style="margin-top:8px;font-size:8px;color:#888;">Date: _______________</p>';
                $html .= '</div>';
            }

            $html .= '<div class="footer">Kernel OS 5.3 — Governed Business Platform | Page {PAGE_NUM} of {PAGE_COUNT}</div>';
            $html .= '</body></html>';

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->setPaper('A4', $orientation);
            $dompdf->loadHtml($html);
            $dompdf->render();

            $tmpPath = sys_get_temp_dir() . '/' . uniqid('export_', true) . '.pdf';
            file_put_contents($tmpPath, $dompdf->output());

            return [
                'path' => $tmpPath,
                'filename' => $filename,
                'mime' => self::mimeType('pdf'),
                'size' => filesize($tmpPath),
            ];
        } catch (\Throwable $e) {
            if (\function_exists('write_log')) {
                \write_log("KernelExport: PDF export failed", 'warning', ['error' => $e->getMessage()]);
            }
            return null;
        }
    }

    // ── Signature block presets ──

    public static function signaturePresets(): array
    {
        return [
            'standard' => ['prepared_by', 'reviewed_by', 'approved_by'],
            'simple' => ['approved_by'],
            'medical' => ['attending_physician', 'reviewed_by', 'hospital_administrator'],
            'financial' => ['prepared_by', 'reviewed_by', 'cfo_approved'],
            'legal' => ['prepared_by', 'reviewed_by', 'legal_counsel', 'notary'],
        ];
    }

    // ── Export audit log ──

    public static function auditExport(string $entityType, string $format, ?string $filename, ?int $userId, string $requestId): void
    {
        if (!\function_exists('write_log')) return;

        \write_log('kernel.export', 'info', [
            'entity_type' => $entityType,
            'format' => $format,
            'filename' => $filename,
            'user_id' => $userId,
            'request_id' => $requestId,
            'timestamp' => date('c'),
        ]);
    }

    // ── Helpers ──

    public static function mimeType(string $format): string
    {
        return match (strtolower($format)) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return string[]
     */
    public static function supportedFormats(): array
    {
        return array_keys(array_filter(self::SUPPORTED_FORMATS));
    }

    /**
     * @return string[]
     */
    public static function registeredEntityTypes(): array
    {
        $types = [];
        foreach (array_keys(self::$handlers) as $key) {
            $dot = strrpos($key, '.');
            if ($dot !== false) {
                $types[] = substr($key, 0, $dot);
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * Reset all registered handlers (for testing).
     */
    public static function reset(): void
    {
        self::$handlers = [];
    }
}

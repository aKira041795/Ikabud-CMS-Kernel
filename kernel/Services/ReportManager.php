<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Report Manager — 5.3 Reporting + Business Output
 *
 * Manages report templates, archives, permissions, scheduled reports,
 * and module-specific report packs.
 */
final class ReportManager
{
    private const STORAGE_DIR = 'report-archive';

    // ── Report Template Manager ──

    /** @return array<int, array<string, mixed>> */
    public static function listTemplates(): array
    {
        $dir = STORAGE_PATH . '/report-templates';
        if (!is_dir($dir)) return [];

        $templates = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $data['id'] = basename($file, '.json');
                $templates[] = $data;
            }
        }
        return $templates;
    }

    public static function saveTemplate(string $id, array $config): bool
    {
        $dir = STORAGE_PATH . '/report-templates';
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $config['updated_at'] = date('c');
        return file_put_contents(
            $dir . '/' . preg_replace('/[^a-z0-9\-]/', '_', $id) . '.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    public static function deleteTemplate(string $id): bool
    {
        $file = STORAGE_PATH . '/report-templates/' . preg_replace('/[^a-z0-9\-]/', '_', $id) . '.json';
        return is_file($file) && unlink($file);
    }

    // ── Report Archive ──

    /** @return array<int, array<string, mixed>> */
    public static function listArchived(): array
    {
        $dir = STORAGE_PATH . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) return [];

        $reports = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $data['id'] = basename($file, '.json');
                $reports[] = $data;
            }
        }

        usort($reports, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        return $reports;
    }

    public static function archiveReport(string $entityType, string $format, string $filePath, string $title, array $meta = []): ?string
    {
        $dir = STORAGE_PATH . '/' . self::STORAGE_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $id = uniqid('rpt_', true);
        $archivePath = $dir . '/' . $id . '.' . $format;

        if (!copy($filePath, $archivePath)) return null;

        $meta['id'] = $id;
        $meta['entity_type'] = $entityType;
        $meta['format'] = $format;
        $meta['title'] = $title;
        $meta['file'] = $archivePath;
        $meta['created_at'] = date('c');

        file_put_contents(
            $dir . '/' . $id . '.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $id;
    }

    public static function getArchivedReport(string $id): ?array
    {
        $metaFile = STORAGE_PATH . '/' . self::STORAGE_DIR . '/' . $id . '.json';
        if (!is_file($metaFile)) return null;
        $meta = json_decode(file_get_contents($metaFile), true);
        return is_array($meta) ? $meta : null;
    }

    // ── Report Permissions ──

    public static function canExport(string $entityType, string $format, ?array $user): bool
    {
        $role = (string)($user['role'] ?? 'guest');

        // Superadmin and administrator can export anything
        if (in_array($role, ['superadmin', 'administrator'], true)) return true;

        // Check capability gate
        $capId = "export.{$entityType}";
        try {
            if (\function_exists('app') && \app()->capabilities()->has($capId)) {
                return true;
            }
        } catch (\Throwable $e) {}

        // Editors can export common formats
        if ($role === 'editor' && in_array($format, ['csv', 'pdf'], true)) return true;

        return false;
    }

    // ── Module-Specific Report Packs ──

    /** @return array<int, array<string, mixed>> */
    public static function moduleReportPacks(): array
    {
        $modules = \function_exists('discoverModules') ? discoverModules() : [];
        $packs = [];

        foreach ($modules as $id => $manifest) {
            $reports = $manifest['report_packs'] ?? $manifest['reports'] ?? null;
            if (!is_array($reports) || empty($reports)) continue;

            foreach ($reports as $report) {
                if (!is_array($report) || empty($report['id'])) continue;
                $packs[] = [
                    'module' => $id,
                    'module_name' => $manifest['name'] ?? $id,
                    'report_id' => $report['id'],
                    'title' => $report['title'] ?? $report['id'],
                    'description' => $report['description'] ?? '',
                    'entity_type' => $report['entity_type'] ?? $id,
                    'formats' => $report['formats'] ?? ['csv', 'pdf'],
                    'schedule' => $report['schedule'] ?? null,
                    'permission' => $report['permission'] ?? null,
                ];
            }
        }

        return $packs;
    }

    // ── Scheduled Reports ──

    /** @return array<int, array<string, mixed>> */
    public static function listScheduled(): array
    {
        $file = STORAGE_PATH . '/scheduled-reports.json';
        if (!is_file($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function scheduleReport(string $entityType, string $format, string $schedule, array $options = []): bool
    {
        $scheduled = self::listScheduled();
        $scheduled[] = [
            'id' => uniqid('sch_', true),
            'entity_type' => $entityType,
            'format' => $format,
            'schedule' => $schedule, // cron expression or 'daily'/'weekly'/'monthly'
            'options' => $options,
            'created_at' => date('c'),
            'last_run' => null,
        ];

        return file_put_contents(
            STORAGE_PATH . '/scheduled-reports.json',
            json_encode($scheduled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    public static function cancelScheduled(string $id): bool
    {
        $scheduled = array_filter(self::listScheduled(), fn($s) => ($s['id'] ?? '') !== $id);
        return file_put_contents(
            STORAGE_PATH . '/scheduled-reports.json',
            json_encode(array_values($scheduled), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    // ── Consistency test helper ──

    public static function consistencyCheck(string $entityType, array $rows): array
    {
        $results = [];
        $formats = ['csv', 'docx', 'pdf'];

        foreach ($formats as $format) {
            $t0 = microtime(true);
            try {
                $exported = KernelExport::export($entityType, $format, $rows, [
                    'title' => "Consistency Test — {$entityType}",
                ]);
                $results[$format] = [
                    'ok' => $exported !== null,
                    'size' => $exported['size'] ?? 0,
                    'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                ];
            } catch (\Throwable $e) {
                $results[$format] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
                ];
            }
        }

        return $results;
    }
}

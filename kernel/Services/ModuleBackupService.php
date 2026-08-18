<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Module Backup Service — data-only SQL dump + secure download for any module DB.
 *
 * Standard backup pattern for module-owned databases. Any module can call this
 * service from its handlers to back up its tenant tables and serve downloads,
 * without reimplementing dump/retention/path-safety per module.
 *
 * Behavior:
 *   - Dumps every table matching a prefix ({prefix}_%) to a data-only SQL file
 *     under storage/backups/{moduleId}/ (schema must already exist; restore =
 *     run the file against the already-migrated schema).
 *   - Streams secure downloads (filename validated, path-traversal safe).
 *   - Enforces retention cleanup and leaves an audit trail via write_log().
 *
 * Usage (module handler):
 *   $res = ModuleBackupService::generate($ctx, 'dc_', 'manual backup', [
 *       'include_users_table' => 'dc_users',   // exclude a table (optional)
 *       'retention_days'      => 14,
 *       'download_path'       => '/dc-cafe/api/v1/backup/download',
 *   ]);
 *   // ... return $res to the UI, which shows download_url ...
 *   ModuleBackupService::download($ctx, 'dc_', $file); // in the download route
 *
 * @package Ikabud\Kernel\Services
 */
final class ModuleBackupService
{
    /** Backup filename: {slug}-db-backup-YYYYMMDD-HHMMSS.sql */
    public const FILE_PATTERN = '/^[a-z0-9_-]+-db-backup-[0-9]{8}-[0-9]{6}\.sql$/';

    /** @var int max rows per multi-row INSERT */
    private const BATCH_SIZE = 100;

    /** @var int default retention days */
    private const DEFAULT_RETENTION_DAYS = 14;

    /**
     * Generate a data-only SQL backup of all tables sharing the given prefix.
     *
     * @param \Ikabud\Kernel\Contracts\ModuleContext $ctx module context (moduleId/db)
     * @param string $tablePrefix e.g. 'dc_' → tables LIKE 'dc\_%'
     * @param string $reason      audit reason label (e.g. 'manual backup')
     * @param array  $options     [
     *                              'include_users_table' => string|null  table to EXCLUDE (e.g. 'dc_users'),
     *                              'retention_days'      => int,
     *                              'download_path'       => string      URL path for downloads (e.g. '/dc-cafe/api/v1/backup/download'),
     *                              'event'               => string      audit event (default '<moduleId>.backup.created'),
     *                            ]
     * @return array{file_name:string,file_size_bytes:int,download_url:string,tables:list<array{table:string,rows:int}>,total_rows:int,retention_days:int,deleted_old_backups:int}
     */
    public static function generate(\Ikabud\Kernel\Contracts\ModuleContext $ctx, string $tablePrefix, string $reason, array $options = []): array
    {
        $moduleId = (string) $ctx->moduleId();
        $db = $ctx->db();
        $excludeTable = isset($options['include_users_table']) && is_string($options['include_users_table'])
            ? trim((string) $options['include_users_table'])
            : '';
        $retentionDays = max(1, min(90, (int) ($options['retention_days'] ?? self::DEFAULT_RETENTION_DAYS)));
        $downloadPath = rtrim((string) ($options['download_path'] ?? ''), '/');
        // Acting user: prefer explicit option, else resolve from the module
        // context (supports both 'id' and 'user_id' key conventions).
        $byUser = (int) ($options['by_user'] ?? 0);
        if ($byUser <= 0) {
            $ctxUser = $ctx->user();
            $byUser = (int) (is_array($ctxUser) ? ($ctxUser['id'] ?? $ctxUser['user_id'] ?? 0) : 0);
        }

        // Enumerate from the module manifest (owns_tables) — SHOW TABLES is
        // blocked by ModuleDB enforcement, and the manifest is the authoritative
        // list of tables this module owns.
        $manifest = $ctx->manifest();
        $tables = array_values(array_filter(
            is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [],
            static fn (mixed $t): bool => is_string($t)
                && ($tablePrefix === '' || str_starts_with($t, $tablePrefix))
                && ($excludeTable === '' || $t !== $excludeTable)
        ));
        sort($tables);

        if ($tables === []) {
            throw new \RuntimeException('No tables found to back up for prefix: ' . $tablePrefix);
        }

        $dir = self::ensureBackupDir($moduleId);
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $moduleId));
        $filename = $slug . '-db-backup-' . date('Ymd-His') . '.sql';
        $target = $dir . '/' . $filename;
        $tmpTarget = $target . '.tmp';

        $fh = @fopen($tmpTarget, 'wb');
        if (!is_resource($fh)) {
            throw new \RuntimeException('Failed to open backup file for writing.');
        }

        $totalRows = 0;
        $tableSummaries = [];

        try {
            fwrite($fh, '-- ' . strtoupper($moduleId) . " SQL Backup\n");
            fwrite($fh, '-- Generated at: ' . date('c') . "\n");
            fwrite($fh, '-- Reason: ' . $reason . "\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $safe = self::safeIdentifier($table);
                $count = (int) (($db->query('SELECT COUNT(*) FROM ' . $safe))->fetchColumn() ?: 0);
                $totalRows += $count;

                fwrite($fh, '-- ------------------------------------------------------------' . "\n");
                fwrite($fh, '-- Table: ' . $safe . ' (rows: ' . $count . ')' . "\n");
                fwrite($fh, "-- Data-only backup (schema must already exist).\n");
                fwrite($fh, 'DELETE FROM `' . $safe . "`;\n");

                if ($count > 0) {
                    $data = $db->query('SELECT * FROM ' . $safe);
                    $batch = [];
                    $columnSql = null;
                    while ($row = $data->fetch(\PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        if ($columnSql === null) {
                            $cols = array_map(static fn ($c): string => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($row));
                            $columnSql = implode(', ', $cols);
                        }
                        $vals = [];
                        foreach ($row as $v) {
                            $vals[] = self::sqlQuote($v);
                        }
                        $batch[] = '(' . implode(', ', $vals) . ')';
                        if (count($batch) >= self::BATCH_SIZE) {
                            fwrite($fh, 'INSERT INTO `' . $safe . '` (' . $columnSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        fwrite($fh, 'INSERT INTO `' . $safe . '` (' . $columnSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    }
                }
                fwrite($fh, "\n");
                $tableSummaries[] = ['table' => $safe, 'rows' => $count];
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);
            @chmod($tmpTarget, 0640);
            if (!@rename($tmpTarget, $target)) {
                @unlink($tmpTarget);
                throw new \RuntimeException('Failed to finalize backup file.');
            }
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                @fclose($fh);
            }
            @unlink($tmpTarget);
            throw $e;
        }

        $deletedOld = self::cleanup($moduleId, $retentionDays);

        $result = [
            'file_name' => $filename,
            'file_size_bytes' => (int) @filesize($target),
            'download_url' => ($downloadPath !== '' ? $downloadPath . '/' : '') . '?file=' . rawurlencode($filename),
            'tables' => $tableSummaries,
            'total_rows' => $totalRows,
            'retention_days' => $retentionDays,
            'deleted_old_backups' => $deletedOld,
            'by_user' => $byUser,
        ];

        self::audit($moduleId, (string) ($options['event'] ?? $moduleId . '.backup.created'), $reason, $result);

        return $result;
    }

    /**
     * List existing backups (newest first) with sizes and download URLs.
     *
     * @return list<array{file_name:string,file_size_bytes:int,created_at:string,download_url:string}>
     */
    public static function list(string $moduleId, string $downloadPath): array
    {
        $dir = self::backupDir($moduleId);
        $items = is_dir($dir) ? @scandir($dir) : false;
        $out = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!preg_match(self::FILE_PATTERN, $item)) {
                    continue;
                }
                $p = $dir . '/' . $item;
                if (!is_file($p)) {
                    continue;
                }
                $out[] = [
                    'file_name' => $item,
                    'file_size_bytes' => (int) @filesize($p),
                    'created_at' => date('Y-m-d H:i:s', (int) @filemtime($p)),
                    'download_url' => rtrim($downloadPath, '/') . '/?file=' . rawurlencode($item),
                ];
            }
            usort($out, static fn (array $a, array $b): int => strcmp((string) $b['file_name'], (string) $a['file_name']));
        }
        return $out;
    }

    /**
     * Stream a backup file as a download. Validates the filename and guards
     * against path traversal. Exits after serving.
     */
    public static function download(\Ikabud\Kernel\Contracts\ModuleContext $ctx, string $tablePrefix, string $file): void
    {
        $moduleId = (string) $ctx->moduleId();
        if ($file === '' || !preg_match(self::FILE_PATTERN, $file)) {
            http_response_code(400);
            echo 'Invalid backup file name.';
            exit;
        }

        $dir = self::backupDir($moduleId);
        $realDir = realpath($dir);
        $realPath = realpath($dir . '/' . $file);
        if ($realDir === false || $realPath === false || strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
            http_response_code(404);
            echo 'Backup file not found.';
            exit;
        }

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($realPath) . '"');
        header('Content-Length: ' . (string) filesize($realPath));
        readfile($realPath);
        exit;
    }

    // ── Internals ──

    private static function backupDir(string $moduleId): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : sys_get_temp_dir());
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $moduleId));
        return rtrim((string) $base, '/\\') . '/backups/' . $slug;
    }

    private static function ensureBackupDir(string $moduleId): string
    {
        $dir = self::backupDir($moduleId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create backup directory.');
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
            @chmod($ht, 0644);
        }
        return $dir;
    }

    private static function cleanup(string $moduleId, int $retentionDays): int
    {
        $dir = self::backupDir($moduleId);
        if (!is_dir($dir)) {
            return 0;
        }
        $deleted = 0;
        $threshold = time() - ($retentionDays * 86400);
        $items = @scandir($dir);
        if (!is_array($items)) {
            return 0;
        }
        foreach ($items as $item) {
            if (!preg_match(self::FILE_PATTERN, $item)) {
                continue;
            }
            $path = $dir . '/' . $item;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $threshold && @unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private static function sqlQuote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $string = (string) $value;
        $string = str_replace(
            ["\\", "\0", "\n", "\r", "\x1a", "'"],
            ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'"],
            $string
        );
        return "'" . $string . "'";
    }

    private static function safeIdentifier(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/i', '', $name);
        if (!is_string($safe) || $safe === '' || $safe !== $name) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
        return $safe;
    }

    /** @param array<string, mixed> $result */
    private static function audit(string $moduleId, string $event, string $reason, array $result): void
    {
        if (function_exists('write_log')) {
            write_log($event, 'info', array_merge([
                'reason' => $reason,
                'file_name' => $result['file_name'],
                'file_size_bytes' => $result['file_size_bytes'],
                'total_rows' => $result['total_rows'],
                'deleted_old_backups' => $result['deleted_old_backups'],
            ], isset($result['by_user']) ? ['by_user' => $result['by_user']] : []));
        }
    }
}

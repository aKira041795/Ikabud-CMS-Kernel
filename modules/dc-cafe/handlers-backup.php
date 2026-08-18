<?php
/**
 * DC Cafe — Backup Handlers (module-owned DB backup + download)
 *
 * Uses the shared ModuleBackupService (kernel) so every module can offer the
 * same SQL-backup-with-download pattern without duplicating dump/retention/
 * path-safety logic.
 */

declare(strict_types=1);

use Ikabud\Kernel\Services\ModuleBackupService;

/** Download URL path for dc-cafe backups. */
function dc_backupDownloadPath(): string
{
    return '/dc-cafe/api/v1/backup/download';
}

/**
 * POST /dc-cafe/api/v1/backup/generate
 *
 * Generates a data-only SQL backup of all dc_% tables and returns the file
 * metadata + existing backup list. Requires the caller to confirm via confirm=1.
 */
function apiGenerateBackup(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $confirm = dcInput('confirm');
    if ((int) $confirm !== 1) {
        dcJsonError('Confirm the backup request.', 422);
    }

    try {
        $result = ModuleBackupService::generate($ctx, 'dc_', 'manual backup', [
            'download_path' => dc_backupDownloadPath(),
            'retention_days' => 14,
            'event' => 'dc_cafe.backup.created',
            'by_user' => (int) ($ctx->user()['user_id'] ?? 0),
        ]);
    } catch (\Throwable $e) {
        write_log('dc_cafe.backup.error', 'error', ['message' => $e->getMessage()]);
        dcJsonError('Failed to generate backup.', 500);
    }

    dcJsonResponse([
        'ok' => true,
        'backup' => $result,
        'backups' => ModuleBackupService::list('dc-cafe', dc_backupDownloadPath()),
        'message' => 'Backup created: ' . $result['file_name'],
    ]);
}

/**
 * GET /dc-cafe/api/v1/backup/download?file=dc-cafe-db-backup-YYYYMMDD-HHMMSS.sql
 */
function handleBackupDownload(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    ModuleBackupService::download($ctx, 'dc_', (string) ($_GET['file'] ?? ''));
}

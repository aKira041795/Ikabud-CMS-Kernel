<?php

declare(strict_types=1);

/**
 * Moto Inventory — Import/Export API handlers.
 */

function motoApiImports(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        moto_json_ok(['imports' => ImportService::imports($ctx, ['branch_id' => $branch])]);
    });
}

function motoApiBackups(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $db = moto_db((int)$ctx['tenant_id']);
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $sql = 'SELECT id, branch_id, backup_version, filename, scope, row_counts, created_by_name, created_at
                FROM moto_backups WHERE tenant_id = :tid';
        $sqlParams = [':tid' => (int)$ctx['tenant_id']];
        if ($branch !== null) {
            $sql .= ' AND branch_id = :bid';
            $sqlParams[':bid'] = $branch;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $db->query($sql, $sqlParams);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['row_counts'] = $row['row_counts'] !== null ? json_decode((string)$row['row_counts'], true) : [];
        }
        unset($row);
        moto_json_ok(['backups' => $rows]);
    });
}

function motoApiExport(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        if ($branch === null || $branch <= 0) {
            moto_json_error('A branch is required for export');
            return;
        }
        $payload = ImportService::export($ctx, $branch, (string)($_GET['scope'] ?? 'full'));
        $filename = 'moto-inventory-' . $branch . '-' . date('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    });
}

function motoApiImportErrors(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $importId = (int)$params['id'];
        $csv = ImportService::errorReport($ctx, $importId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="moto-inventory-import-errors-' . $importId . '.csv"');
        echo $csv;
    });
}

function motoApiImportStage(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');

        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $brandId = (int)($input['brand_id'] ?? 0);
        $sheetIndex = max(0, (int)($input['sheet_index'] ?? 0));
        $headerRow = max(0, (int)($input['header_row'] ?? 0));
        // Default to row 1 (skip the header row) so a UI-driven import does not
        // import the header as a product; matches ImportService::stage default.
        $dataStartRow = array_key_exists('data_start_row', $input)
            ? max(0, (int)$input['data_start_row'])
            : 1;
        $idem = (string)($input['idempotency_key'] ?? '');
        // Column mapping is optional from the UI: when absent, ImportService
        // auto-guesses it from the header row (guessMappingFromHeaders). A
        // client-supplied mapping is honoured when it is a real array.
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === []) {
            $mapping = null;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            moto_json_error('No file uploaded');
            return;
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            moto_json_error('File upload failed (error ' . (int)($file['error'] ?? 0) . ')');
            return;
        }

        $filename = (string)($file['name'] ?? 'upload.xlsx');
        $mime = (string)($file['type'] ?? '');

        $result = ImportService::stage(
            $ctx, $branchId, $brandId, (string)$file['tmp_name'], $filename, $mime,
            $mapping, $sheetIndex, $headerRow, $dataStartRow,
            $idem !== '' ? $idem : null
        );
        moto_json_ok($result, 201);
    });
}

function motoApiImportCommit(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $overwriteQty = !empty($input['overwrite_qty']);
        $result = ImportService::commit($ctx, (int)$params['id'], $overwriteQty);
        moto_json_ok($result);
    });
}

function motoApiBackupImport(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');

        $input = moto_input();
        $branchId = (int)($input['branch_id'] ?? 0);
        $idem = (string)($input['idempotency_key'] ?? '');
        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            moto_json_error('Backup payload is required');
            return;
        }

        $result = ImportService::importBackup($ctx, $branchId, $payload, $idem !== '' ? $idem : null);
        moto_json_ok($result);
    });
}

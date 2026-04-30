<?php

declare(strict_types=1);

/**
 * Returns the base URL for the Daily Ledger module.
 */
function dlGetBaseUrl(): string
{
    return '/daily-ledger';
}

function daily_ledger_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'daily_ledger_cap_kernel_auth_authenticate_1',
    ];
}

function dlCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('daily-ledger');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function dlInput(): array
{
    $input = dlCtx()->input();
    return is_array($input) ? $input : [];
}

function dlRender(string $template, array $context = []): string
{
    return dlCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function dlNormalizeLoginRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Daily Ledger Sign In',
    ], ['page_title'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierLedgerRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
        'branch_id' => 0,
        'branch_name' => '',
        'ledger_date' => '',
        'today' => '',
        'day_status' => '',
        'branches' => [],
        'is_cashier' => false,
        'reference_only' => false,
        'can_ledger_override' => false,
        'business_date_label' => '',
        'close_of_day_time' => '',
        'auto_close_enabled' => false,
        'operating_timezone' => '',
        'operating_region' => '',
    ], ['page_title', 'user_name', 'user_role', 'current_page', 'base_url', 'branch_id', 'branch_name', 'ledger_date', 'today', 'day_status', 'branches', 'is_cashier'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierRowsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'rows' => [],
        'branch_id' => 0,
        'ledger_date' => '',
        'day_status' => '',
        'reference_only' => false,
    ], ['rows', 'branch_id', 'ledger_date', 'day_status'], $missingKeys, $typeMismatches);
}

function dlNormalizeAdminRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
    ], ['page_title', 'user_name', 'user_role', 'current_page', 'base_url'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('daily-ledger.page.login', [
    'template' => 'modules/daily-ledger/pages/login.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeLoginRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.ledger', [
    'template' => 'modules/daily-ledger/cashier/ledger.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierLedgerRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.rows', [
    'template' => 'modules/daily-ledger/cashier/partials/ledger-rows.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierRowsRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.admin.shell', [
    'prefix' => 'modules/daily-ledger/admin/',
    'priority' => 20,
    'normalize' => 'dlNormalizeAdminRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

function dlRedirect(string $url, int $status = 302): void
{
    dlCtx()->redirect($url, $status);
}

function dlJson(array $data, int $status = 200): void
{
    dlCtx()->json($data, $status);
}

/**
 * Daily Ledger Module — helpers
 *
 * Module-local utilities only. Cross-module integration is via capability contracts.
 */

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (($user['source'] ?? null) !== 'daily-ledger') {
        return $url;
    }

    if ($role === 'cashier') {
        return '/daily-ledger/ledger';
    }

    if ($role === 'production_in_charge') {
        return '/daily-ledger/admin/production';
    }

    if (in_array($role, ['admin', 'supervisor'], true)) {
        return '/daily-ledger/admin/dashboard';
    }

    return $url;
}, 80);

function daily_ledger_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    // Username prefix policy: non-kernel providers must require @provider:username to avoid collisions.
    // Daily-ledger provider accepts only usernames prefixed with '@daily-ledger:'.
    $prefix = '@daily-ledger:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    $ctx = module('daily-ledger');
    if (!$ctx) {
        return null;
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, username, password_hash, full_name, role
             FROM dl_users
             WHERE username = :username AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
            return null;
        }

        $id = (int)($row['id'] ?? 0);
        $role = (string)($row['role'] ?? '');
        if ($id <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
            return null;
        }

        return [
            'user' => [
                // IMPORTANT: do not collide with kernel users.id (used by audit_logs FK).
                // The actual dl_users id is encoded in `sub` and parsed by dl_getActorUserId().
                'id' => 0,
                'sub' => $role . ':' . $id,
                'username' => (string)($row['username'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => $role,
            ],
            'source' => 'daily-ledger',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — CSV Import / Export Helpers
// ─────────────────────────────────────────────────────────────────────────

function dlCsvResponse(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, $ordered);
    }

    fclose($stream);
    exit;
}

function dlCsvNormalizeHeader(string $header): string
{
    $normalized = strtolower(trim($header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function dlCsvRowsFromString(string $csvContent): array
{
    $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
    $csvContent = trim($csvContent);
    if ($csvContent === '') {
        throw new RuntimeException('CSV content is required.');
    }

    $lines = preg_split('/\r\n|\n|\r/', $csvContent) ?: [];
    $headers = null;
    $rows = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $values = str_getcsv($line);
        if ($headers === null) {
            $headers = array_map(static fn(string $header): string => dlCsvNormalizeHeader($header), $values);
            continue;
        }

        $values = array_pad($values, count($headers), null);
        $rows[] = array_combine($headers, array_map(
            static fn(mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $values
        ));
    }

    if ($headers === null) {
        throw new RuntimeException('CSV header row is required.');
    }

    return $rows;
}

function dlImportReadUploadedCsv(string $field, int $maxBytes = 5242880): array
{
    $file = kernelUploadedFile($field);
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 422, 'error' => 'Upload a valid CSV file first.'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is not available.'];
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'CSV upload did not arrive through the HTTP upload pipeline.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file exceeds the maximum allowed size.'];
    }

    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }

    return ['ok' => true, 'file' => $file, 'raw' => $raw];
}

function dlCsvNullableFloat(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!is_numeric($normalized)) {
        throw new RuntimeException('Expected a numeric decimal value.');
    }

    return round((float)$normalized, 2);
}

function dlCsvNullableInt(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^-?\d+$/', $normalized)) {
        throw new RuntimeException('Expected an integer value.');
    }

    return (int)$normalized;
}

<?php

declare(strict_types=1);

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

    $db = $ctx->db();

    try {
        // Try admin
        $dlStmt = $db->prepare(
            "SELECT id, username, password_hash, full_name, 'admin' AS role
             FROM dl_admins
             WHERE username = :username AND is_active = 1
             LIMIT 1"
        );
        $dlStmt->execute([':username' => $username]);
        $dlUser = $dlStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($dlUser) && password_verify($password, (string)($dlUser['password_hash'] ?? ''))) {
            $id = (int)($dlUser['id'] ?? 0);
            return [
                'user' => [
                    'id' => 0,
                    'sub' => 'admin:' . $id,
                    'username' => (string)($dlUser['username'] ?? ''),
                    'full_name' => (string)($dlUser['full_name'] ?? ''),
                    'role' => 'admin',
                ],
                'source' => 'daily-ledger',
            ];
        }

        // Try cashier
        $dlStmt = $db->prepare(
            "SELECT id, username, password_hash, full_name, 'cashier' AS role
             FROM dl_cashiers
             WHERE username = :username AND is_active = 1
             LIMIT 1"
        );
        $dlStmt->execute([':username' => $username]);
        $dlUser = $dlStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($dlUser) && password_verify($password, (string)($dlUser['password_hash'] ?? ''))) {
            $id = (int)($dlUser['id'] ?? 0);
            return [
                'user' => [
                    // IMPORTANT: do not collide with kernel users.id (used by audit_logs FK)
                    'id' => 0,
                    'sub' => 'cashier:' . $id,
                    'username' => (string)($dlUser['username'] ?? ''),
                    'full_name' => (string)($dlUser['full_name'] ?? ''),
                    'role' => 'cashier',
                ],
                'source' => 'daily-ledger',
            ];
        }

        // Try supervisor
        $dlStmt = $db->prepare(
            "SELECT id, username, password_hash, full_name, 'supervisor' AS role
             FROM dl_supervisors
             WHERE username = :username AND is_active = 1
             LIMIT 1"
        );
        $dlStmt->execute([':username' => $username]);
        $dlUser = $dlStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($dlUser) && password_verify($password, (string)($dlUser['password_hash'] ?? ''))) {
            $id = (int)($dlUser['id'] ?? 0);
            return [
                'user' => [
                    'id' => 0,
                    'sub' => 'supervisor:' . $id,
                    'username' => (string)($dlUser['username'] ?? ''),
                    'full_name' => (string)($dlUser['full_name'] ?? ''),
                    'role' => 'supervisor',
                ],
                'source' => 'daily-ledger',
            ];
        }

        // Try production in-charge
        $dlStmt = $db->prepare(
            "SELECT id, username, password_hash, full_name, 'production_in_charge' AS role
             FROM dl_production_incharges
             WHERE username = :username AND is_active = 1
             LIMIT 1"
        );
        $dlStmt->execute([':username' => $username]);
        $dlUser = $dlStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($dlUser) && password_verify($password, (string)($dlUser['password_hash'] ?? ''))) {
            $id = (int)($dlUser['id'] ?? 0);
            return [
                'user' => [
                    'id' => 0,
                    'sub' => 'production_in_charge:' . $id,
                    'username' => (string)($dlUser['username'] ?? ''),
                    'full_name' => (string)($dlUser['full_name'] ?? ''),
                    'role' => 'production_in_charge',
                ],
                'source' => 'daily-ledger',
            ];
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

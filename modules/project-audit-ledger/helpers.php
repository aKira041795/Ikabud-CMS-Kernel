<?php

declare(strict_types=1);

app()->registerAuthTable('project-audit-ledger', 'pal_users');

/**
 * Register all capability handlers for the project-audit-ledger module.
 * Uses the standard naming convention: {module_prefix}_capability_handlers()
 * Module prefix derived from 'project-audit-ledger' → 'project_audit_ledger'
 */
function project_audit_ledger_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'pal_cap_kernel_auth_authenticate_1',
        'pal.read@1' => 'pal_cap_read_1',
        'pal.manage@1' => 'pal_cap_manage_1',
        'pal.projects.read@1' => 'pal_cap_projects_read_1',
        'pal.projects.write@1' => 'pal_cap_projects_write_1',
        'pal.expenses.read@1' => 'pal_cap_expenses_read_1',
        'pal.expenses.write@1' => 'pal_cap_expenses_write_1',
        'pal.inventory.read@1' => 'pal_cap_inventory_read_1',
        'pal.inventory.write@1' => 'pal_cap_inventory_write_1',
        'pal.purchases.read@1' => 'pal_cap_purchases_read_1',
        'pal.purchases.write@1' => 'pal_cap_purchases_write_1',
        'pal.sales.read@1' => 'pal_cap_sales_read_1',
        'pal.sales.write@1' => 'pal_cap_sales_write_1',
        'pal.collections.read@1' => 'pal_cap_collections_read_1',
        'pal.collections.write@1' => 'pal_cap_collections_write_1',
        'pal.fabrication.read@1' => 'pal_cap_fabrication_read_1',
        'pal.fabrication.write@1' => 'pal_cap_fabrication_write_1',
        'pal.approvals.read@1' => 'pal_cap_approvals_read_1',
        'pal.approvals.write@1' => 'pal_cap_approvals_write_1',
        'pal.reports.read@1' => 'pal_cap_reports_read_1',
        'pal.audit.read@1' => 'pal_cap_audit_read_1',
        'pal.settings.read@1' => 'pal_cap_settings_read_1',
        'pal.settings.write@1' => 'pal_cap_settings_write_1',
        'pal.users.manage@1' => 'pal_cap_users_manage_1',
        'entity.list.pal_project@1' => 'pal_cap_entity_list_project_1',
        'entity.get.pal_project@1' => 'pal_cap_entity_get_project_1',
        'entity.list.pal_expense@1' => 'pal_cap_entity_list_expense_1',
        'entity.get.pal_expense@1' => 'pal_cap_entity_get_expense_1',
        'entity.list.pal_material@1' => 'pal_cap_entity_list_material_1',
        'entity.get.pal_material@1' => 'pal_cap_entity_get_material_1',
        'entity.list.pal_purchase@1' => 'pal_cap_entity_list_purchase_1',
        'entity.get.pal_purchase@1' => 'pal_cap_entity_get_purchase_1',
        'entity.list.pal_sale@1' => 'pal_cap_entity_list_sale_1',
        'entity.get.pal_sale@1' => 'pal_cap_entity_get_sale_1',
        'entity.list.pal_collection@1' => 'pal_cap_entity_list_collection_1',
        'entity.list.pal_fabrication_due@1' => 'pal_cap_entity_list_fabrication_due_1',
        'entity.list.pal_audit_log@1' => 'pal_cap_entity_list_audit_log_1',
    ];
}

// ── Capability handler stubs (to be implemented per-phase) ──

function pal_cap_kernel_auth_authenticate_1(array $args): array
{
    return palAuthLogin($args['username'] ?? '', $args['password'] ?? '');
}

function pal_cap_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_manage_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_projects_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_projects_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_expenses_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_expenses_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_inventory_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_inventory_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_purchases_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_purchases_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_sales_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_sales_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_collections_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_collections_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_fabrication_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_fabrication_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_approvals_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_approvals_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_reports_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_audit_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_settings_read_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_settings_write_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_users_manage_1(array $args): array
{
    return ['ok' => true, 'data' => null];
}

function pal_cap_entity_list_project_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_get_project_1(array $args): array
{
    return ['ok' => false, 'error' => 'Not implemented yet.'];
}

function pal_cap_entity_list_expense_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_get_expense_1(array $args): array
{
    return ['ok' => false, 'error' => 'Not implemented yet.'];
}

function pal_cap_entity_list_material_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_get_material_1(array $args): array
{
    return ['ok' => false, 'error' => 'Not implemented yet.'];
}

function pal_cap_entity_list_purchase_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_get_purchase_1(array $args): array
{
    return ['ok' => false, 'error' => 'Not implemented yet.'];
}

function pal_cap_entity_list_sale_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_get_sale_1(array $args): array
{
    return ['ok' => false, 'error' => 'Not implemented yet.'];
}

function pal_cap_entity_list_collection_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_list_fabrication_due_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

function pal_cap_entity_list_audit_log_1(array $args): array
{
    return ['ok' => true, 'rows' => [], 'total' => 0];
}

// ── URL and cookie helpers ──

function palBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function palExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function palCookieName(): string
{
    return 'pal_token';
}

function palSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    if (headers_sent()) {
        return;
    }

    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(palCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function palLoginPageContext(array $overrides = []): array
{
    $baseUrl = palBaseUrl();
    $settings = palSettings();

    return array_merge([
        'page_title' => 'Project Ledger — Sign In',
        'login_subtitle' => 'Project costing, inventory, and fabrication management',
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/api/v1/project-audit-ledger/auth/login',
        'login_button_text' => 'Sign In',
        'login_loading_text' => 'Opening workspace...',
        'login_brand_html' => 'Project <span>Ledger</span>',
        'login_forgot_url' => $baseUrl . '/project-audit-ledger/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'login_helper_title' => 'First Time Here?',
        'login_helper_html' => '<p>Contact your system administrator for credentials.</p><ul><li>Admins can manage users, projects, and settings.</li><li>Supervisors review and approve transactions.</li><li>Encoders create project records and submit for approval.</li></ul>',
        'gui' => [
            'app_name' => 'Project Audit Ledger',
            'app_name_accent' => 'Project',
            'app_name_rest' => 'Ledger',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#2563eb',
            'color_primary_hover' => '#1d4ed8',
            'color_primary_light' => 'rgba(37, 99, 235, 0.18)',
            'color_bg' => '#f8fafc',
            'color_surface' => '#ffffff',
            'color_border' => '#e2e8f0',
            'color_text' => '#0f172a',
            'color_text_muted' => '#64748b',
        ],
    ], $overrides);
}

function palClearAuthCookie(): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(palCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

// ── Runtime helpers ──

function palCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('project-audit-ledger');
    if ($ctx === null) {
        throw new RuntimeException('Project Audit Ledger module context not available.');
    }
    return $ctx;
}

function palDb(): Ikabud\Kernel\Contracts\ModuleDB
{
    // Auth-owned module: force tenant context to match the authenticated user.
    // Overrides host-based tenant resolution so queries always hit the correct
    // tenant database (palsystem for tenant 502) regardless of the request host.
    // For unauthenticated routes (login, forgot-password), host-based resolution
    // continues to apply — those only work when accessed through the correct host.
    $sessionUser = $_SESSION['pal_user'] ?? null;
    if (is_array($sessionUser) && !empty($sessionUser['tenant_id'])) {
        $userTid = (int)$sessionUser['tenant_id'];
        $currentTid = app()->tenant()->current();
        if ($currentTid === null || $currentTid !== $userTid) {
            app()->tenant()->setTenantId($userTid);
        }
    }
    return palCtx()->db();
}

function palRender(string $template, array $context = []): void
{
    $settings = palSettings();
    $context['pal_app_name'] = $settings['app_name'] ?? 'Project Audit Ledger';
    $context['pal_logo_path'] = $settings['logo_path'] ?? '';

    // Auto-render page body from individual template file
    $pageContent = $context['page_content'] ?? '';
    if ($pageContent !== '') {
        $pageTemplate = __DIR__ . '/templates/project-audit-ledger/pages/' . $pageContent . '.disyl';
        if (file_exists($pageTemplate)) {
            $context['page_body'] = app()->render($pageTemplate, $context);
        } else {
            $context['page_body'] = '<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center"><p class="text-gray-400 text-sm">Page template not found: ' . htmlspecialchars($pageContent, ENT_QUOTES, 'UTF-8') . '</p></div>';
        }
    }

    echo app()->render($template, $context);
}

function palJsonError(string $message, int $status = 422, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

function palAudit(string $action, ?int $actorUserId = null, ?string $entityType = null, ?string $entityId = null, mixed $oldData = null, mixed $newData = null): void
{
    try {
        $db = palDb();
        $stmt = $db->prepare(
            'INSERT INTO pal_audit_logs (tenant_id, actor_user_id, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent, created_at)
             VALUES (:tenant_id, :actor_user_id, :action, :entity_type, :entity_id, :old_data, :new_data, :ip_address, :user_agent, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => (int)(app()->tenant()->current() ?? 0),
            ':actor_user_id' => $actorUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':old_data' => $oldData !== null ? json_encode($oldData) : null,
            ':new_data' => $newData !== null ? json_encode($newData) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palAudit failed: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Create a pal_approvals record and return its ID.
 */
function palCreateApproval(string $entityType, int $entityId, int $submittedBy, string $previousStatus, string $newStatus = 'pending_approval'): int
{
    $db = palDb();
    $stmt = $db->prepare(
        "INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, previous_status, new_status, decision, escalation_level)
         VALUES (:t, :et, :eid, :sb, :ps, :ns, 'pending', 0)"
    );
    $stmt->execute([
        ':t' => (int)(app()->tenant()->current() ?? 0),
        ':et' => $entityType,
        ':eid' => $entityId,
        ':sb' => $submittedBy,
        ':ps' => $previousStatus,
        ':ns' => $newStatus,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Fire a domain event through the kernel event bus.
 */
function palFireEvent(string $event, array $payload = []): void
{
    try {
        if (function_exists('app') && ($a = app()) !== null && method_exists($a, 'events')) {
            $a->events()->fire($event, $payload, 'project-audit-ledger');
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log("palFireEvent failed: {$event} — " . $e->getMessage(), 'warning');
        }
    }
}

function palSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $db = palDb();
        $stmt = $db->query('SELECT setting_key, setting_value FROM pal_settings');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable) {
    }

    return $cache;
}

function palIsKernelSuperadmin(?array $user): bool
{
    return is_array($user)
        && ($user['role'] ?? '') === 'superadmin'
        && ($user['source'] ?? '') === 'kernel';
}

function palIsModuleUser(?array $user): bool
{
    return is_array($user) && isset($user['source']) && $user['source'] === 'module';
}

function palAuthenticatedUserRecord(int $userId): ?array
{
    try {
        $db = palDb();
        $stmt = $db->prepare(
            'SELECT id, username, email, password_hash, full_name, role, is_active, token_version
             FROM pal_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function palSupportsTokenVersion(): bool
{
    return true;
}

function palRejectStaleSession(): void
{
    palClearAuthCookie();
    unset($_SESSION['pal_user']);
    app()->redirect('/project-audit-ledger/login?error=Session+expired.+Please+log+in+again.');
}

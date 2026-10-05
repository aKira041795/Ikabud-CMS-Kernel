<?php

declare(strict_types=1);

// The variance dashboard renders each flag in both the grouped and the list view,
// so a busy tenant can push the DiSyL renderer past its 5 MB output ceiling and
// the page fails with "Template output exceeds maximum allowed size". The rendered
// list is capped; the dashboard figures stay true because they are aggregated in
// SQL over every matching flag rather than over this page slice.
const DL_VARIANCE_PAGE_ROW_LIMIT = 400;

// The sales page lists ledger rows for the filtered range. A wide range on a
// busy tenant exceeds the same 5 MB ceiling, so the rendered list is capped and
// the grand totals are aggregated in SQL over every matching row.
const DL_SALES_PAGE_ROW_LIMIT = 400;

// A variance review note is a short operator remark ("cashier short 3 pcs",
// "counted twice"), not a document. Capped so a paste cannot bloat the row or the
// rendered page; the column itself is TEXT. `review_note` has existed since the
// original schema and the variances report has always read it - nothing ever
// wrote it, so this is the first writer.
const DL_VARIANCE_NOTE_MAX = 500;

// Same reasoning as DL_VARIANCE_NOTE_MAX, for the note on a shift reconciliation.
const DL_RECON_NOTE_MAX = 500;

// Float-safety only: the figures are decimal(12,2), so a genuine difference is always at
// least a centavo. This is NOT a business tolerance - it exists so an exact match is not
// reported as a mismatch by binary floating point, and it must never be widened to
// "explain away" a real shortfall.
const DL_RECON_MATCH_TOLERANCE = 0.005;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/helpers/entity-views.php';
require_once __DIR__ . '/helpers/reporting.php';
require_once __DIR__ . '/handlers-deliveries.php';
require_once __DIR__ . '/handlers-pos.php';
require_once __DIR__ . '/handlers-offline.php';

// Load DiSyL entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}

/**
 * Daily Ledger Module — Handlers
 *
 * Cashier: neutral encoding form (no computed totals, no variance, no enforcement)
 * Admin: dashboard, sales summary, variance flags, product/branch/user management
 */

// ─── Helpers ───────────────────────────────────────────────────────────
function dl_auditLog(string $action, ?int $branchId = null, ?string $entityType = null, ?string $entityId = null, $oldData = null, $newData = null, ?string $reason = null): void
{
    $ctx = module();
    if (!$ctx) {
        return;
    }

    try {
        $ctx->audit($action, $branchId, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

function dl_refreshTokenCacheKey(string $refreshToken): string
{
    return 'refresh_token:' . hash('sha256', $refreshToken);
}

function dl_registerRefreshToken(string $refreshToken, ?int $ttl = null): void
{
    if ($refreshToken === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_refreshTokenCacheKey($refreshToken), ['active' => true], $ttl ?? (30 * 86400));
}

function dl_isRefreshTokenActive(string $refreshToken): bool
{
    if ($refreshToken === '') {
        return false;
    }

    $cached = app()->cache()->get('daily-ledger', dl_refreshTokenCacheKey($refreshToken));
    if (!is_array($cached)) {
        return true;
    }

    return !empty($cached['active']);
}

function dl_revokeRefreshToken(string $refreshToken): void
{
    if ($refreshToken === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_refreshTokenCacheKey($refreshToken), ['active' => false], 30 * 86400);
}

function dl_idempotencyCacheKey(string $scope, string $idempotencyKey): string
{
    return 'idempotency:' . $scope . ':' . hash('sha256', $idempotencyKey);
}

function dl_loadIdempotentResponse(string $scope, string $idempotencyKey): ?array
{
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return null;
    }

    $cached = app()->cache()->get('daily-ledger', dl_idempotencyCacheKey($scope, $idempotencyKey));
    if (!is_array($cached) || !is_array($cached['response'] ?? null)) {
        return null;
    }

    return $cached['response'];
}

function dl_storeIdempotentResponse(string $scope, string $idempotencyKey, array $response, int $ttl = 600): void
{
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return;
    }

    app()->cache()->set('daily-ledger', dl_idempotencyCacheKey($scope, $idempotencyKey), ['response' => $response], $ttl);
}

function dl_lockDayStatusRow($db, int $branchId, string $date): string
{
    $ensureStmt = $db->prepare(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status)
         VALUES (:bid, :d, "open")
         ON DUPLICATE KEY UPDATE branch_id = branch_id'
    );
    $ensureStmt->execute([':bid' => $branchId, ':d' => $date]);

    $lockStmt = $db->prepare(
        'SELECT status
           FROM dl_ledger_day_status
          WHERE branch_id = :bid AND ledger_date = :d
          LIMIT 1
          FOR UPDATE'
    );
    $lockStmt->execute([':bid' => $branchId, ':d' => $date]);
    $status = (string)($lockStmt->fetchColumn() ?: 'open');
    return $status === 'closed' ? 'closed' : 'open';
}

function dl_allowedColumn(string $field, array $map): ?string
{
    $column = $map[$field] ?? null;
    return is_string($column) && $column !== '' ? $column : null;
}

function dl_normalizeEffectiveFrom($value, string $defaultDate): ?string
{
    if ($value === null) {
        return $defaultDate;
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->format('Y-m-d') === $value ? $value : null;
}

/**
 * Replace mutable ledger price snapshots with the branch/date-resolved price.
 * Closed days and frozen variance snapshots remain financially immutable.
 *
 * @return array{updated_rows:int,unchanged_rows:int,skipped_rows:int,skipped_days:array<int,array<string,mixed>>}
 */
function dl_repriceProductLedgerRows(\Ikabud\Kernel\Contracts\ModuleDB $db, int $productId, string $effectiveFrom): array
{
    $stmt = $db->prepare(
        'SELECT dl.id, dl.branch_id, dl.ledger_date, dl.price_snapshot
           FROM dl_daily_ledger dl
          WHERE dl.product_id = :pid AND dl.ledger_date >= :effective_from
          ORDER BY dl.ledger_date, dl.branch_id, dl.id'
    );
    $stmt->execute([':pid' => $productId, ':effective_from' => $effectiveFrom]);

    $update = $db->prepare(
        'UPDATE dl_daily_ledger
            SET price_snapshot = :price, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    );
    $dayStatusStmt = $db->prepare(
        'SELECT status FROM dl_ledger_day_status
          WHERE branch_id = :bid AND ledger_date = :d
          LIMIT 1 FOR UPDATE'
    );
    $frozenStmt = $db->prepare(
        'SELECT EXISTS (
             SELECT 1 FROM dl_variance_flags
              WHERE branch_id = :bid AND ledger_date = :d AND frozen_at IS NOT NULL
         )'
    );
    $summary = ['updated_rows' => 0, 'unchanged_rows' => 0, 'skipped_rows' => 0, 'skipped_days' => []];
    $skippedDays = [];
    $dayStates = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (int)$row['branch_id'] . ':' . (string)$row['ledger_date'];
        if (!isset($dayStates[$key])) {
            // Serialize against close-day so a row cannot become immutable while
            // its price is being changed. An absent status row means open.
            $dayParams = [':bid' => (int)$row['branch_id'], ':d' => (string)$row['ledger_date']];
            $dayStatusStmt->execute($dayParams);
            $status = (string)($dayStatusStmt->fetchColumn() ?: 'open');
            $frozenStmt->execute($dayParams);
            $dayStates[$key] = ['status' => $status, 'frozen' => (bool)$frozenStmt->fetchColumn()];
        }

        $reason = null;
        if ($dayStates[$key]['status'] === 'closed') {
            $reason = 'closed';
        } elseif ($dayStates[$key]['frozen']) {
            $reason = 'variance_frozen';
        }
        if ($reason !== null) {
            $summary['skipped_rows']++;
            if (!isset($skippedDays[$key])) {
                $skippedDays[$key] = [
                    'branch_id' => (int)$row['branch_id'],
                    'ledger_date' => (string)$row['ledger_date'],
                    'reason' => $reason,
                    'rows' => 0,
                ];
            }
            $skippedDays[$key]['rows']++;
            continue;
        }

        $resolved = dl_resolveBranchProductPrice((int)$row['branch_id'], $productId, (string)$row['ledger_date']);
        if (abs((float)$row['price_snapshot'] - $resolved) < 0.00001) {
            $summary['unchanged_rows']++;
            continue;
        }
        $update->execute([':price' => $resolved, ':id' => (int)$row['id']]);
        $summary['updated_rows']++;
    }

    $summary['skipped_days'] = array_values($skippedDays);
    return $summary;
}

/** @param array<int,array<string,mixed>> $rows */
function dl_applyLedgerDisplayPrices(array $rows, int $branchId, string $ledgerDate): array
{
    foreach ($rows as &$row) {
        $row['current_price'] = isset($row['price_snapshot']) && $row['price_snapshot'] !== null
            ? (float)$row['price_snapshot']
            : dl_resolveBranchProductPrice($branchId, (int)$row['product_id'], $ledgerDate);
    }
    unset($row);
    return $rows;
}

function dl_generateAuthTokens(array $payload): array
{
    $accessPayload = $payload;
    unset($accessPayload['token_type']);
    $accessToken = app()->jwt()->generate($accessPayload);

    $refreshPayload = $payload;
    $refreshPayload['token_type'] = 'refresh';
    $refreshJwt = new \Ikabud\Kernel\JWT(
        config('app.jwt.secret'),
        30 * 86400
    );
    $refreshToken = $refreshJwt->generate($refreshPayload);
    dl_registerRefreshToken($refreshToken, 30 * 86400);

    return [
        'token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => (int)config('app.jwt.expiration', 86400),
        'refresh_expires_in' => 30 * 86400,
    ];
}

function dl_verifyRefreshToken(string $refreshToken): ?array
{
    if ($refreshToken === '') {
        return null;
    }

    $refreshJwt = new \Ikabud\Kernel\JWT(
        config('app.jwt.secret'),
        30 * 86400
    );
    $payload = $refreshJwt->verify($refreshToken);
    if (!is_array($payload)) {
        return null;
    }

    if (($payload['source'] ?? '') !== 'daily-ledger' || ($payload['token_type'] ?? '') !== 'refresh') {
        return null;
    }
    if (!dl_isRefreshTokenActive($refreshToken)) {
        return null;
    }

    unset($payload['token_type']);
    return $payload;
}

function dl_getUserBranchId(): ?int
{
    $ctx = module();
    if (!$ctx) return null;

    $user = dlUserFromRequest();
    if (!$user) return null;

    $userId = (int)($user['id'] ?? 0);
    $sub = (string)($user['sub'] ?? '');
    if ($userId <= 0 && preg_match('/^cashier:(\d+)$/', $sub, $m)) {
        $userId = (int)$m[1];
    } elseif (is_numeric($sub)) {
        $userId = (int)$sub;
    }
    
    $role   = (string)($user['role'] ?? '');

    // Admin/supervisor: can work with any branch (selected via param)
    if (in_array($role, ['admin', 'supervisor'], true)) {
        $input = $ctx->input();
        $branchId = $input['branch_id'] ?? null;
        if ($branchId) return (int)$branchId;
        // Default: first branch
        $stmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    // Cashier: locked to assigned branch (single row in dl_user_branches).
    $stmt = $ctx->db()->prepare(
        'SELECT ub.branch_id
         FROM dl_user_branches ub
         INNER JOIN dl_users u ON u.id = ub.user_id
         WHERE ub.user_id = :id AND u.is_active = 1 AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $bid = (int)($stmt->fetchColumn() ?: 0);
    return $bid > 0 ? $bid : null;
}

function dlCurrentUser(array $roles = ['cashier', 'supervisor', 'admin', 'production_in_charge']): array
{
    $u = dlRequireAuth($roles);

    // Kernel OS admin access is opt-in (stored in modules.json settings).
    // Default: kernel admin cannot use this module.
    if (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin') {
        $settings = getModuleSettings('daily-ledger');
        $allowed = (string)($settings['allow_kernel_admin'] ?? '0');
        if (!in_array($allowed, ['1', 'true', 'yes', 'on'], true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    // production_in_charge is a deliberately narrow role: the Daily Sheet and
    // only the three write shapes used by that sheet. This is an authorization
    // boundary, not a navigation convenience; direct URLs and unrelated APIs
    // are refused here even if an individual handler still lists the role.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (in_array((string)($u['role'] ?? ''), ['supervisor', 'auditor'], true) && in_array($path, [
        '/daily-ledger/admin/usage',
        '/daily-ledger/admin/production-output',
        '/daily-ledger/admin/deliveries',
        '/daily-ledger/admin/trace',
    ], true)) {
        http_response_code(403);
        echo 'Forbidden: admin-only production view';
        exit;
    }

    if (($u['role'] ?? '') === 'production_in_charge' && str_starts_with($path, '/daily-ledger/')) {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $allowed = $method === 'GET' && in_array($path, [
            '/daily-ledger/admin/commissary',
            '/daily-ledger/api/v1/me',
        ], true);
        if ($method === 'POST') {
            $input = module() ? module()->input() : [];
            $entity = (string)($input['entity'] ?? '');
            $allowed = ($path === '/daily-ledger/api/v1/commissary/run' && $entity === 'production_addition')
                || ($path === '/daily-ledger/api/v1/commissary/material' && in_array($entity, ['product_beg', 'product_count'], true))
                || $path === '/daily-ledger/api/v1/commissary/carry-beginnings'
                || ($path === '/daily-ledger/api/v1/commissary/dispatch' && (!empty($input['sheet_entry']) || (string)($input['source'] ?? '') === 'daily_sheet'))
                || $path === '/daily-ledger/api/v1/commissary/finalize-pm'
                || $path === '/daily-ledger/api/v1/commissary/settle-endings';
        }
        if (!$allowed) {
            http_response_code(403);
            if (str_starts_with($path, '/daily-ledger/api/')) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Forbidden: Daily Sheet access only']);
            } else {
                echo 'Forbidden: Daily Sheet access only';
            }
            exit;
        }
    }

    return $u;
}

function dl_allPermissionActions(): array
{
    return [
        'ledger.override',
        'production.override',
        'pos.sell',
        'pos.void',
        'pos.refund',
        'pos.fallback',
        'pos.report',
        'delivery.edit',
    ];
}

function dl_defaultRolePermissions(): array
{
    return [
        'admin' => ['ledger.override', 'production.override', 'pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'supervisor' => ['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'production_in_charge' => [],
        'cashier' => ['pos.sell'],
        'auditor' => [],
        'viewer' => [],
    ];
}

function dlSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['daily-ledger'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function dlModuleSettings(bool $refresh = false): array
{
    static $cache = null;
    if (!$refresh && $cache !== null) {
        return $cache;
    }
    $cache = array_merge(dlSettingsDefaults(), getModuleSettings('daily-ledger'));
    return $cache;
}

function dlPersistModuleSettings(array $settings): bool
{
    if ($settings === []) {
        return true;
    }

    saveModuleSettings('daily-ledger', $settings);
    $fresh = dlModuleSettings(true);

    foreach ($settings as $key => $expected) {
        if (!array_key_exists($key, $fresh)) {
            return false;
        }

        $actual = $fresh[$key];
            if (!dlSettingValuesMatch($actual, $expected)) {
            return false;
        }
    }

    return true;
}

function dl_rolePermissions(bool $refresh = false): array
{
    static $cache = null;
    if (!$refresh && $cache !== null) {
        return $cache;
    }

    $defaults = dl_defaultRolePermissions();
    $settings = dlModuleSettings();
    $raw = $settings['role_permissions'] ?? null;

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    if (!is_array($raw)) {
        $cache = $defaults;
        return $cache;
    }

    $allowedActions = array_flip(dl_allPermissionActions());
    $result = $defaults;
    foreach ($defaults as $role => $defaultPerms) {
        $vals = $raw[$role] ?? $defaultPerms;
        if (!is_array($vals)) {
            $vals = $defaultPerms;
        }
        $clean = [];
        foreach ($vals as $perm) {
            $perm = (string)$perm;
            if ($perm !== '' && isset($allowedActions[$perm])) {
                $clean[$perm] = true;
            }
        }
        $result[$role] = array_keys($clean);
    }

    $cache = $result;
    return $cache;
}

function dl_roleHasPermission(string $role, string $permission): bool
{
    $permissions = dl_rolePermissions();
    $rolePerms = $permissions[$role] ?? [];
    return in_array($permission, $rolePerms, true);
}

function dl_isKernelAdmin(array $user): bool
{
    return (($user['source'] ?? '') === 'kernel' && in_array($user['role'] ?? '', ['admin', 'superadmin'], true));
}

function dl_canManageFeatureActivation(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['admin', 'superadmin'], true);
}

function dl_featureSettings(): array
{
    $settings = dlModuleSettings();

    return [
        'production_output_enabled' => dl_settingToBool($settings['production_output_enabled'] ?? false),
        'formal_delivery_workflow_enabled' => dl_settingToBool($settings['formal_delivery_workflow_enabled'] ?? false),
        'price_groups_enabled' => dl_settingToBool($settings['price_groups_enabled'] ?? true),
        'selling_accounts_enabled' => dl_settingToBool($settings['selling_accounts_enabled'] ?? false),
        'pos_enabled' => dl_settingToBool($settings['pos_enabled'] ?? false),
        'pos_sort_by_sales' => dl_settingToBool($settings['pos_sort_by_sales'] ?? true),
    ];
}

function dl_isFeatureEnabled(string $feature): bool
{
    $features = dl_featureSettings();
    return !empty($features[$feature]);
}

function dl_settingToBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function dl_normalizeCloseOfDayTime($value): string
{
    $normalized = trim((string)$value);
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $normalized)) {
        return $normalized;
    }

    return '00:00';
}

function dl_normalizeTimezone($value): string
{
    $timezone = trim((string)$value);
    if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
        return $timezone;
    }

    $fallback = (string)config('app.timezone', 'Asia/Manila');
    if ($fallback !== '' && in_array($fallback, timezone_identifiers_list(), true)) {
        return $fallback;
    }

    return 'Asia/Manila';
}

function dl_normalizeRegion($value): string
{
    $region = trim((string)$value);
    if ($region === '') {
        return 'Default Region';
    }

    return mb_substr($region, 0, 100);
}

function dl_normalizeOutputUnitLabel($value): string
{
    $label = strtolower(trim((string)$value));
    if ($label === '') {
        return 'pcs';
    }
    if (!preg_match('/^[a-z][a-z0-9\-_ ]{0,19}$/', $label)) {
        return 'pcs';
    }

    return $label;
}

function dl_normalizePiecesPerBatch($value): ?int
{
    $num = (int)$value;
    if ($num <= 0) {
        return null;
    }

    return min($num, 1000000);
}

function dl_fetchActiveProductsForProduction($db): array
{
    $priceDate = dl_businessDate();
    $cacheKey = 'active_products_for_production_' . $priceDate;
    $cached = app()->cache()->get('daily-ledger', $cacheKey);
    if (is_array($cached) && isset($cached['rows'])) {
        return $cached['rows'];
    }

    $effectivePrice = dl_effectivePriceSql('p', ':catalog_price_at');
    try {
        $stmt = $db->prepare('SELECT p.id, p.name, p.sku, ' . $effectivePrice . ' AS current_price, p.output_pieces_per_batch, p.batch_input_qty, p.batch_egg_qty, p.output_unit_label, p.product_category FROM dl_products p WHERE p.is_active = 1 ORDER BY p.product_category, p.name');
        $stmt->execute([':catalog_price_at' => $priceDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $stmt = $db->prepare('SELECT p.id, p.name, p.sku, ' . $effectivePrice . ' AS current_price FROM dl_products p WHERE p.is_active = 1 ORDER BY p.name');
        $stmt->execute([':catalog_price_at' => $priceDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($rows as &$row) {
        if (!array_key_exists('output_pieces_per_batch', $row)) {
            $row['output_pieces_per_batch'] = null;
        }
        if (!array_key_exists('output_unit_label', $row)) {
            $row['output_unit_label'] = 'pcs';
        }
        if (!array_key_exists('batch_input_qty', $row)) {
            $row['batch_input_qty'] = null;
        }
        if (!array_key_exists('batch_egg_qty', $row)) {
            $row['batch_egg_qty'] = null;
        }
    }
    unset($row);

    foreach ($rows as &$row) {
        if (!array_key_exists('product_category', $row)) {
            $row['product_category'] = 'bread';
        }
    }
    unset($row);

    app()->cache()->setWithTags('daily-ledger', $cacheKey, ['rows' => $rows], ['dl_products'], 300);
    return $rows;
}

/**
 * Product universe for the production Daily Sheet. This deliberately mirrors
 * the cashier ledger's branch-product definition instead of reusing the
 * differently ordered production-output cache above.
 */
function dl_fetchProductionSheetProducts($db, int $branchId): array
{
    $sql = 'SELECT DISTINCT p.id, p.name, p.sku, p.sort_order,
                p.output_pieces_per_batch, p.output_unit_label
           FROM dl_products p
           INNER JOIN dl_branch_products bp
             ON bp.product_id = p.id AND bp.is_active = 1';
    $bind = [];
    if ($branchId > 0) {
        $sql .= ' AND bp.branch_id = :bid';
        $bind[':bid'] = $branchId;
    } else {
        $sql .= ' INNER JOIN dl_branches source_branch
                    ON source_branch.id = bp.branch_id
                   AND source_branch.is_commissary = 1
                   AND source_branch.is_active = 1';
    }
    $sql .= ' WHERE p.is_active = 1 ORDER BY p.sort_order, p.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Confirmed paper labels belong here; unresolved names must not be guessed. */
function dl_productionSheetLabel(array $product): string
{
    $confirmedLabelsByProductId = [];
    $productId = (int)($product['id'] ?? 0);
    return (string)($confirmedLabelsByProductId[$productId] ?? $product['name'] ?? '');
}

function dl_unresolvedProductionSheetLabels(): array
{
    return ['BDAY CAKE ORD', 'BDAY CAKE ORD HALF', 'UBE CAKE HALF', 'CUSTARD BIG'];
}

function dl_fetchProductionSheetDispatchMatrix($db, string $ledgerDate, int $commissaryBranchId = 0, ?string $shift = null): array
{
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $sql = "SELECT d.destination_id AS branch_id, di.product_id, SUM(di.quantity) AS quantity,
                   MAX(d.origin_id IS NULL AND d.resolved_origin_id IS NULL) AS origin_unresolved
              FROM dl_deliveries d
              INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
              LEFT JOIN dl_branches sheet_dst ON sheet_dst.id = d.destination_id
             WHERE d.delivery_date = :sheet_date
               AND (:sheet_shift IS NULL OR d.production_shift = :sheet_shift_value)
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.status = 'posted'";
    $bind = [':sheet_date' => $ledgerDate, ':sheet_shift' => $shift, ':sheet_shift_value' => $shift];
    if ($commissaryBranchId > 0) {
        // Unresolved historical rows remain visible through the destination's
        // assignment, but are explicitly labelled; it is not treated as origin.
        $sql .= ' AND (COALESCE(d.resolved_origin_id, d.origin_id) = :sheet_cid'
            . ' OR (d.origin_id IS NULL AND d.resolved_origin_id IS NULL AND sheet_dst.assigned_commissary_id = :sheet_unresolved_cid))';
        $bind[':sheet_cid'] = $commissaryBranchId;
        $bind[':sheet_unresolved_cid'] = $commissaryBranchId;
    }
    $sql .= ' GROUP BY d.destination_id, di.product_id';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $matrix = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dispatch) {
        $matrix[(int)$dispatch['product_id']][(int)$dispatch['branch_id']] = (int)$dispatch['quantity'];
    }
    return $matrix;
}

/**
 * S10: which (product, branch) cells already carry a recorded delivery for the
 * day. The cell remains displayed from the delivery SUM, but the modal needs to
 * know whether an entry exists so that a first entry is a plain quantity while
 * editing an existing one requires Type + Reason Code. The shape is kept
 * separate from dl_fetchProductionSheetDispatchMatrix so the verified matrix
 * contract (int quantity keyed by product/branch) is untouched.
 */
function dl_fetchProductionSheetDispatchEntryFlags($db, string $ledgerDate, int $commissaryBranchId = 0, ?string $shift = null): array
{
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $sql = "SELECT d.destination_id AS branch_id, di.product_id, COUNT(*) AS entry_count
              FROM dl_deliveries d
              INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
              LEFT JOIN dl_branches sheet_dst ON sheet_dst.id = d.destination_id
             WHERE d.delivery_date = :sheet_date
               AND (:sheet_shift IS NULL OR d.production_shift = :sheet_shift_value)
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.status = 'posted'";
    $bind = [':sheet_date' => $ledgerDate, ':sheet_shift' => $shift, ':sheet_shift_value' => $shift];
    if ($commissaryBranchId > 0) {
        $sql .= ' AND (COALESCE(d.resolved_origin_id, d.origin_id) = :sheet_cid'
            . ' OR (d.origin_id IS NULL AND d.resolved_origin_id IS NULL AND sheet_dst.assigned_commissary_id = :sheet_unresolved_cid))';
        $bind[':sheet_cid'] = $commissaryBranchId;
        $bind[':sheet_unresolved_cid'] = $commissaryBranchId;
    }
    $sql .= ' GROUP BY d.destination_id, di.product_id';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $flags = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
        $flags[(int)$entry['product_id']][(int)$entry['branch_id']] = true;
    }
    return $flags;
}

/**
 * S12: receipt state for each (product, branch) cell on the Daily Sheet.
 *
 * The branch cell is what production SENT. The cashier separately records what
 * ARRIVED. This reads BOTH sides from the item rows: sent =
 * dl_delivery_items.quantity and received =
 * dl_branch_receiving_items.quantity_received, joined on delivery_item_id.
 *
 * It deliberately does NOT read dl_delivery_variance_flags: that table is an
 * exception list (dl_recordReceivingVariances skips zero variances), so a
 * normal full receipt has no row there and reading it would blank every good
 * line. It also must not substitute the sent quantity for a missing receipt
 * pattern (handlers-deliveries.php:1417): that assumes full receipt and would
 * render an unreceived delivery as a zero difference, a false all-clear.
 *
 * Three states stay distinct:
 *   - pending : at least one sent item has no receiving row -> never 0
 *   - full    : every sent item received, received == sent  -> no difference
 *   - variance: every sent item received, received != sent  -> signed difference
 *
 * Returned shape: $matrix[product_id][branch_id] = [
 *   'sent'       => int,
 *   'received'   => int    (sum of the receiving rows that exist),
 *   'pending'    => bool   (a sent item has no receiving row),
 *   'dr_numbers' => string[] (dl_deliveries.dr_number for the cell),
 * ].
 */
function dl_fetchProductionSheetReceivingMatrix($db, string $ledgerDate, int $commissaryBranchId = 0, ?string $shift = null): array
{
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $sql = "SELECT d.destination_id AS branch_id,
                   di.product_id,
                   di.quantity AS sent,
                   rcv.quantity_received AS received,
                   rcv.count_basis,
                   (d.origin_id IS NULL AND d.resolved_origin_id IS NULL) AS origin_unresolved,
                   d.dr_number
              FROM dl_deliveries d
              INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
              LEFT JOIN dl_branches sheet_dst ON sheet_dst.id = d.destination_id
              LEFT JOIN (
                  SELECT bri.delivery_item_id, SUM(bri.quantity_received) AS quantity_received,
                         CASE WHEN MAX(br.count_basis = 'copied') = 1 THEN 'copied'
                              WHEN MIN(br.count_basis = 'independently_counted') = 1 THEN 'independently_counted'
                              ELSE NULL END AS count_basis
                    FROM dl_branch_receiving_items bri
                    INNER JOIN dl_branch_receivings br
                      ON br.id = bri.receiving_id AND br.status = 'posted'
                   GROUP BY bri.delivery_item_id
              ) rcv ON rcv.delivery_item_id = di.id
             WHERE d.delivery_date = :sheet_date
               AND (:sheet_shift IS NULL OR d.production_shift = :sheet_shift_value)
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.status = 'posted'";
    $bind = [':sheet_date' => $ledgerDate, ':sheet_shift' => $shift, ':sheet_shift_value' => $shift];
    if ($commissaryBranchId > 0) {
        $sql .= ' AND (COALESCE(d.resolved_origin_id, d.origin_id) = :sheet_cid'
            . ' OR (d.origin_id IS NULL AND d.resolved_origin_id IS NULL AND sheet_dst.assigned_commissary_id = :sheet_unresolved_cid))';
        $bind[':sheet_cid'] = $commissaryBranchId;
        $bind[':sheet_unresolved_cid'] = $commissaryBranchId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $matrix = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int)$row['product_id'];
        $branchId = (int)$row['branch_id'];
        if (!isset($matrix[$productId][$branchId])) {
            $matrix[$productId][$branchId] = [
                'sent' => 0,
                'received' => 0,
                'pending' => false,
                'dr_numbers' => [],
                'not_independently_counted' => false,
                'count_basis_unresolved' => false,
                'origin_unresolved' => false,
            ];
        }
        $matrix[$productId][$branchId]['sent'] += (int)$row['sent'];
        $matrix[$productId][$branchId]['origin_unresolved'] =
            $matrix[$productId][$branchId]['origin_unresolved'] || !empty($row['origin_unresolved']);
        if ((string)($row['count_basis'] ?? '') === 'copied') {
            $matrix[$productId][$branchId]['not_independently_counted'] = true;
        } elseif ($row['received'] !== null && ($row['count_basis'] ?? null) === null) {
            $matrix[$productId][$branchId]['count_basis_unresolved'] = true;
        }
        if ($row['received'] === null) {
            // No receiving row for this sent item: pending, NOT a zero difference.
            $matrix[$productId][$branchId]['pending'] = true;
        } else {
            $matrix[$productId][$branchId]['received'] += (int)$row['received'];
        }
        $dr = trim((string)($row['dr_number'] ?? ''));
        if ($dr !== '' && !in_array($dr, $matrix[$productId][$branchId]['dr_numbers'], true)) {
            $matrix[$productId][$branchId]['dr_numbers'][] = $dr;
        }
    }
    return $matrix;
}

function dl_operatingRegionChoices(string $currentRegion): array
{
    $choices = [
        'Default Region',
        'Metro Manila',
        'Manila',
        'Cebu',
        'Davao',
        'Luzon',
        'Visayas',
        'Mindanao',
    ];

    if (!in_array($currentRegion, $choices, true)) {
        array_unshift($choices, $currentRegion);
    }

    return $choices;
}

function dl_operatingTimezoneChoices(string $currentTimezone): array
{
    $choices = [
        'Asia/Manila',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
        'Asia/Seoul',
        'Australia/Sydney',
        'UTC',
    ];

    if (!in_array($currentTimezone, $choices, true)) {
        array_unshift($choices, $currentTimezone);
    }

    return $choices;
}

function dl_isAllowedAutoCloseTime(string $time): bool
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
        return false;
    }

    $hours = (int)$matches[1];
    return $hours >= 0 && $hours < 24;
}

function dlSettingValuesMatch(mixed $actual, mixed $expected): bool
{
    return json_encode(dlNormalizeSettingValue($actual), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        === json_encode(dlNormalizeSettingValue($expected), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dlNormalizeSettingValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[$key] = dlNormalizeSettingValue($item);
    }

    if ($normalized !== [] && array_keys($normalized) !== range(0, count($normalized) - 1)) {
        ksort($normalized);
    }

    return $normalized;
}

function dlAuditLogHasColumn(string $column): bool
{
    return dlTableHasColumn('audit_logs', $column);
}

/**
 * Generic column-existence check that is safe on shared-host (Bluehost)
 * databases where optional migration columns may be missing. Selecting a
 * column that does not exist throws SQLSTATE[42S22] and 500s the request,
 * so optional columns must be gated behind this check.
 */
function dlTableHasColumn(string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $safeTable = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $safeColumn = preg_replace('/[^a-z0-9_]+/i', '', $column);
    if ($safeTable === '' || $safeColumn === '') {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $stmt = dlCtx()->db()->query("SHOW COLUMNS FROM {$safeTable} LIKE '" . $safeColumn . "'");
        $cache[$cacheKey] = $stmt->fetchColumn() !== false;
        return $cache[$cacheKey];
    } catch (Throwable) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function dlActiveAdminCount(): int
{
    try {
        $stmt = dlCtx()->db()->query(
            "SELECT COUNT(*) FROM dl_users WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1"
        );
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

function dl_backupSettings(): array
{
    $settings = dlModuleSettings();

    $enabled = dl_settingToBool($settings['backup_before_reset_enabled'] ?? '1');
    $includeUsers = dl_settingToBool($settings['backup_include_users'] ?? '1');
    $retentionDays = (int)($settings['backup_retention_days'] ?? 14);
    if ($retentionDays < 1) {
        $retentionDays = 1;
    }
    if ($retentionDays > 90) {
        $retentionDays = 90;
    }

    return [
        'backup_before_reset_enabled' => $enabled,
        'backup_include_users' => $includeUsers,
        'backup_retention_days' => $retentionDays,
    ];
}

function dl_resetSecondConfirmPhrase(): string
{
    return 'I UNDERSTAND THIS WILL DELETE ALL DAILY LEDGER DATA';
}

function dl_resetSafeguardSettings(): array
{
    $settings = dlModuleSettings();
    return [
        'reset_second_phrase_enabled' => dl_settingToBool($settings['reset_second_phrase_enabled'] ?? '1'),
        'reset_second_phrase' => dl_resetSecondConfirmPhrase(),
    ];
}

function dl_listDailyLedgerTables($db, bool $includeUsers): array
{
    $tables = [];
    $stmt = $db->query("SHOW TABLES LIKE 'dl\\_%'");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $table = (string)($row[0] ?? '');
        if ($table === '') {
            continue;
        }
        if (!$includeUsers && $table === 'dl_users') {
            continue;
        }
        $tables[] = $table;
    }

    sort($tables);
    return $tables;
}

function dl_generateDatabaseBackup(array $user, string $reason, ?bool $includeUsers = null): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $backupSettings = dl_backupSettings();
    $includeUsersFlag = $includeUsers !== null ? $includeUsers : $backupSettings['backup_include_users'];

    // Standard backup via the shared kernel service: enumerates dl_* tables
    // from module.json owns_tables, data-only dump + secure download + retention.
    $result = \Ikabud\Kernel\Services\ModuleBackupService::generate($ctx, 'dl_', $reason, [
        'include_users_table' => $includeUsersFlag ? null : 'dl_users',
        'retention_days' => (int) $backupSettings['backup_retention_days'],
        'download_path' => '/daily-ledger/admin/settings/backup-download',
        'event' => 'daily_ledger.backup.created',
        'by_user' => (int) ($user['id'] ?? $user['user_id'] ?? 0),
    ]);

    // Keep daily-ledger's existing return contract for the settings UI.
    $contract = [
        'file_name' => $result['file_name'],
        'file_size_bytes' => $result['file_size_bytes'],
        'download_url' => $result['download_url'],
        'tables' => $result['tables'],
        'total_rows' => $result['total_rows'],
        'include_users' => $includeUsersFlag,
        'retention_days' => (int) $backupSettings['backup_retention_days'],
        'deleted_old_backups' => $result['deleted_old_backups'],
    ];

    dl_auditLog('database_backup_created', null, 'module_settings', 'daily-ledger', null, [
        'reason' => $reason,
        'file_name' => $contract['file_name'],
        'file_size_bytes' => $contract['file_size_bytes'],
        'total_rows' => $contract['total_rows'],
        'include_users' => $includeUsersFlag,
        'deleted_old_backups' => $contract['deleted_old_backups'],
        'performed_by_role' => (string) ($user['role'] ?? ''),
        'performed_by_source' => (string) ($user['source'] ?? ''),
    ]);

    return $contract;
}

function dl_deploymentResetTables($db): array
{
    // Full deployment reset wipes all module-owned dl_* tables; preserved admin is restored after purge.
    return dl_listDailyLedgerTables($db, true);
}

function dl_preservedAdminRowForReset($db, array $user): array
{
    $actorId = (int)($user['id'] ?? 0);
    $actorUsername = trim((string)($user['username'] ?? ''));
    $actorEmail = trim((string)($user['email'] ?? ''));

    if (!dl_tableExists($db, 'dl_users')) {
        throw new RuntimeException('dl_users table not found; cannot preserve admin account.');
    }

    $row = null;
    if ($actorId > 0) {
        $stmt = $db->prepare('SELECT * FROM dl_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ((!is_array($row) || $row === []) && $actorUsername !== '') {
        $stmt = $db->prepare(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1 AND username = :username
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':username' => $actorUsername]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ((!is_array($row) || $row === []) && $actorEmail !== '' && dlTableHasColumn('dl_users', 'email')) {
        $stmt = $db->prepare(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1 AND email = :email
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':email' => $actorEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($row) || $row === []) {
        // Fallback for kernel-admin sessions: keep the last known active module admin account.
        $stmt = $db->query(
            "SELECT * FROM dl_users
             WHERE role = 'admin' AND deleted_at IS NULL AND is_active = 1
             ORDER BY id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($row) || $row === []) {
        throw new RuntimeException('No active admin account found to preserve.');
    }

    $role = strtolower(trim((string)($row['role'] ?? '')));
    if ($role !== 'admin') {
        throw new RuntimeException('Only a Daily Ledger admin account can be preserved by deployment reset.');
    }

    return $row;
}

function dl_restorePreservedAdminRowAfterReset($db, array $row): void
{
    if (!dl_tableExists($db, 'dl_users')) {
        return;
    }

    $colsStmt = $db->query('SHOW COLUMNS FROM dl_users');
    $columns = $colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($columns === []) {
        throw new RuntimeException('Unable to read dl_users columns for account restore.');
    }

    $insertCols = [];
    $insertVals = [];
    $bind = [];
    $i = 0;

    foreach ($columns as $col) {
        $field = (string)($col['Field'] ?? '');
        if ($field === '') {
            continue;
        }

        $nullable = strtolower((string)($col['Null'] ?? 'NO')) === 'yes';
        $value = array_key_exists($field, $row) ? $row[$field] : ($nullable ? null : ($col['Default'] ?? null));

        if ($field === 'role') {
            $value = 'admin';
        } elseif ($field === 'is_active') {
            $value = 1;
        } elseif ($field === 'deleted_at') {
            $value = null;
        } elseif (in_array($field, ['branch_id', 'default_branch_id'], true)) {
            $value = $nullable ? null : 0;
        }

        $param = ':c' . $i;
        $insertCols[] = '`' . str_replace('`', '``', $field) . '`';
        $insertVals[] = $param;
        $bind[$param] = $value;
        $i++;
    }

    if ($insertCols === []) {
        throw new RuntimeException('No columns available to restore preserved admin account.');
    }

    $sql = 'INSERT INTO dl_users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
    $ins = $db->prepare($sql);
    $ins->execute($bind);
}

function dl_tableExists($db, string $table): bool
{
    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    if ($safe === '' || $safe !== $table) {
        return false;
    }

    try {
        $stmt = $db->query("SHOW TABLES LIKE '" . $safe . "'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function dl_deleteAllRowsIfTableExists($db, string $table): int
{
    if (!dl_tableExists($db, $table)) {
        return 0;
    }

    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $stmt = $db->prepare('DELETE FROM ' . $safe);
    $ok = $stmt->execute();
    if ($ok !== true) {
        throw new RuntimeException('Failed deleting table: ' . $safe);
    }

    return (int)$stmt->rowCount();
}

function dl_countRowsIfTableExists($db, string $table): int
{
    if (!dl_tableExists($db, $table)) {
        return 0;
    }

    $safe = preg_replace('/[^a-z0-9_]+/i', '', $table);
    $stmt = $db->query('SELECT COUNT(*) FROM ' . $safe);
    return (int)($stmt->fetchColumn() ?: 0);
}

function dl_runDeploymentDataReset(array $user, bool $dryRun = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $db = $ctx->db();
    $tables = dl_deploymentResetTables($db);
    $preservedAdminRow = dl_preservedAdminRowForReset($db, $user);
    $adminCount = dlActiveAdminCount();
    if ($adminCount < 1) {
        throw new RuntimeException('No active admin account found; reset aborted.');
    }

    $result = [
        'dry_run' => $dryRun,
        'preserved_admin_accounts' => 1,
        'preserved_admin_id' => (int)($preservedAdminRow['id'] ?? 0),
        'preserved_admin_username' => (string)($preservedAdminRow['username'] ?? ''),
        'backup' => null,
        'tables' => [],
        'total_rows' => 0,
    ];

    if ($dryRun) {
        foreach ($tables as $table) {
            $rows = dl_countRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }
        return $result;
    }

    $backupSettings = dl_backupSettings();
    if ($backupSettings['backup_before_reset_enabled']) {
        $result['backup'] = dl_generateDatabaseBackup(
            $user,
            'before_deployment_reset',
            (bool)$backupSettings['backup_include_users']
        );
    }

    $db->beginTransaction();
    $fkChecksDisabled = false;
    try {
        $db->prepare('SET FOREIGN_KEY_CHECKS=0')->execute();
        $fkChecksDisabled = true;

        foreach ($tables as $table) {
            $rows = dl_deleteAllRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }

        dl_restorePreservedAdminRowAfterReset($db, $preservedAdminRow);

        $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
        $fkChecksDisabled = false;
        $db->commit();
    } catch (Throwable $e) {
        if ($fkChecksDisabled) {
            try {
                $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
            } catch (Throwable) {
            }
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    dl_auditLog('deployment_data_reset', null, 'module_settings', 'daily-ledger', null, [
        'performed_by_role' => (string)($user['role'] ?? ''),
        'performed_by_source' => (string)($user['source'] ?? ''),
        'preserved_admin_accounts' => 1,
        'preserved_admin_id' => (int)($preservedAdminRow['id'] ?? 0),
        'preserved_admin_username' => (string)($preservedAdminRow['username'] ?? ''),
        'total_rows' => $result['total_rows'],
        'tables' => $result['tables'],
    ]);

    return $result;
}

/**
 * Tables whose rows are SALES/transaction evidence for the "reset sales data
 * only" feature. Reference/master data is intentionally NOT listed: users,
 * branches, products, prices, price groups, supply rules, production,
 * selling accounts, audit logs, and settings are preserved so a fresh sales
 * period can start without rebuilding the catalog.
 */
function dl_salesResetTables($db): array
{
    $candidates = [
        'dl_daily_ledger',
        'dl_pos_sales',
        'dl_pos_sale_items',
        'dl_pos_payments',
        'dl_pos_sale_events',
        'dl_sales_day_modes',
        'dl_pos_fallback_checkpoints',
        'dl_pos_fallback_checkpoint_items',
        'dl_cashier_withdrawals',
        'dl_deliveries',
        'dl_delivery_items',
        'dl_branch_receivings',
        'dl_branch_receiving_items',
        'dl_variance_flags',
        'dl_delivery_variance_flags',
        'dl_ledger_day_status',
        'dl_ledger_shift_status',
    ];
    $tables = [];
    foreach ($candidates as $table) {
        if (dl_tableExists($db, $table)) {
            $tables[] = $table;
        }
    }
    sort($tables);
    return $tables;
}

/**
 * Reset ONLY sales/transaction data (ledger, POS, deliveries, withdrawals,
 * variance flags, day status). Master data (users, branches, products,
 * prices, settings, audit logs) is untouched, so no admin-account restore is
 * needed. Supports dry-run preview and optional backup-before-reset.
 */
function dl_runSalesDataReset(array $user, bool $dryRun = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $db = $ctx->db();
    $tables = dl_salesResetTables($db);

    $result = [
        'dry_run' => $dryRun,
        'preserved_master_data' => true,
        'backup' => null,
        'tables' => [],
        'total_rows' => 0,
    ];

    if ($dryRun) {
        foreach ($tables as $table) {
            $rows = dl_countRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }
        return $result;
    }

    $backupSettings = dl_backupSettings();
    if ($backupSettings['backup_before_reset_enabled']) {
        $result['backup'] = dl_generateDatabaseBackup(
            $user,
            'before_sales_data_reset',
            (bool)$backupSettings['backup_include_users']
        );
    }

    $db->beginTransaction();
    $fkChecksDisabled = false;
    try {
        $db->prepare('SET FOREIGN_KEY_CHECKS=0')->execute();
        $fkChecksDisabled = true;

        foreach ($tables as $table) {
            $rows = dl_deleteAllRowsIfTableExists($db, $table);
            $result['tables'][] = ['table' => $table, 'rows' => $rows];
            $result['total_rows'] += $rows;
        }

        $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
        $fkChecksDisabled = false;
        $db->commit();
    } catch (Throwable $e) {
        if ($fkChecksDisabled) {
            try {
                $db->prepare('SET FOREIGN_KEY_CHECKS=1')->execute();
            } catch (Throwable) {
            }
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    dl_auditLog('sales_data_reset', null, 'module_settings', 'daily-ledger', null, [
        'performed_by_role' => (string)($user['role'] ?? ''),
        'performed_by_source' => (string)($user['source'] ?? ''),
        'preserved_master_data' => true,
        'total_rows' => $result['total_rows'],
        'tables' => $result['tables'],
    ]);

    return $result;
}

function dl_closeOfDaySettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $settings = dlModuleSettings();
    $cache = [
        'auto_close_enabled' => dl_settingToBool($settings['auto_close_enabled'] ?? false),
        'close_of_day_time' => dl_normalizeCloseOfDayTime($settings['close_of_day_time'] ?? '00:00'),
        'operating_timezone' => dl_normalizeTimezone($settings['operating_timezone'] ?? config('app.timezone', 'Asia/Manila')),
        'operating_region' => dl_normalizeRegion($settings['operating_region'] ?? ''),
    ];
    return $cache;
}

function dl_businessDate(?\DateTimeImmutable $now = null): string
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    
    if (!$settings['auto_close_enabled']) {
        return $now->format('Y-m-d');
    }

    list($hours, $minutes) = explode(':', $settings['close_of_day_time']);
    $hours = (int)$hours;
    
    if ($hours < 12) {
        // Morning cutoff (e.g. 03:00) -> Shift clock backward
        return $now->modify("-{$hours} hours -{$minutes} minutes")->format('Y-m-d');
    } else {
        // Evening cutoff (e.g. 20:00) -> Shift clock forward
        $shiftHours = 24 - $hours;
        // Adjust for minutes as well to be precise (e.g. 20:30 means we add 3 hours 30 mins)
        // Wait, if it's 20:30, and it is 20:29, adding 3h30m gives 23:59. It's the same day.
        // If it's 20:31, adding 3h30m gives 00:01 next day. Correct.
        $shiftMinutes = $minutes > 0 ? (60 - $minutes) : 0;
        $shiftHours = $minutes > 0 ? $shiftHours - 1 : $shiftHours;
        return $now->modify("+{$shiftHours} hours +{$shiftMinutes} minutes")->format('Y-m-d');
    }
}

function dl_maybeAutoCloseBranchDay(int $branchId, ?int $actorId = null, ?\DateTimeImmutable $now = null): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $settings = dl_closeOfDaySettings();
    if (!$settings['auto_close_enabled']) {
        return false;
    }

    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    // The shift before the *current* business date has already ended.
    $currentBusinessDate = dl_businessDate($now);
    $closeDate = (new \DateTimeImmutable($currentBusinessDate))->modify('-1 day')->format('Y-m-d');
    if (dl_getDayStatus($branchId, $closeDate) === 'closed') {
        return false;
    }

    $ctx = module();
    if (!$ctx) {
        return false;
    }

    $closeActorId = ($actorId !== null && $actorId > 0) ? $actorId : null;
    $ownsTxn = !$ctx->db()->inTransaction();
    if ($ownsTxn) {
        $ctx->db()->beginTransaction();
    }
    try {
        $lockedStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $closeDate);
        if ($lockedStatus === 'closed') {
            if ($ownsTxn) {
                $ctx->db()->commit();
            }
            return false;
        }

        // A day that an admin/supervisor deliberately reopened (apiReopenDay
        // sets reopened_by/reopened_at) must STAY open until it is closed
        // manually. Without this exemption the next auto-close pass re-closes
        // the just-reopened day on the very next request, so admin reopen of a
        // previous day appears to fail for both AM and PM. Keying on
        // reopened_at preserves the offline late-ending bridge
        // (dl_reopenDayForLateEnding) which reopens WITHOUT setting reopened_at
        // and relies on the next auto-close pass to re-close.
        $reopenStmt = $ctx->db()->prepare(
            'SELECT reopened_at FROM dl_ledger_day_status
              WHERE branch_id = :bid AND ledger_date = :d LIMIT 1'
        );
        $reopenStmt->execute([':bid' => $branchId, ':d' => $closeDate]);
        $reopenedAt = $reopenStmt->fetchColumn();
        if ($reopenedAt !== false && $reopenedAt !== null && (string)$reopenedAt !== '') {
            if ($ownsTxn) {
                $ctx->db()->commit();
            }
            return false;
        }

        // POS/fallback days close under their own receipt/checkpoint rules.
        // Owner rule (2026-09-04): AM and PM shifts close when the day is over —
        // at the business-day cutoff (midnight) the previous date closes for
        // both shifts regardless of whether the PM ending was finalized. This
        // stops an unfinalized PM shift from keeping the day open and leaking
        // entries into the next business date. A manual day whose PM was not
        // finalized still closes, but is flagged so the owner can reopen and
        // backfill the PM ending counts if needed.
        if (dl_isFullyManualDay($ctx->db(), $branchId, $closeDate)) {
            $pmRow = dl_lockShiftStatusRow($ctx->db(), $branchId, $closeDate, 'PM');
            if ((string)($pmRow['status'] ?? 'open') === 'finalized') {
                // Finalized manual day: full variance sweep + freeze before closing.
                dl_recomputeVariancesForDay($branchId, $closeDate, false);
                dl_freezeVarianceFlags($ctx->db(), $branchId, $closeDate, $closeActorId);
            } else {
                // PM not finalized — close at the cutoff anyway and surface the
                // gap so it is never silently lost.
                $notify = $ctx->db()->prepare(
                    'UPDATE dl_ledger_shift_status SET pending_notified_at = CURRENT_TIMESTAMP
                      WHERE branch_id = :bid AND ledger_date = :d AND shift = \'PM\' AND pending_notified_at IS NULL'
                );
                $notify->execute([':bid' => $branchId, ':d' => $closeDate]);
                dl_auditLog('auto_close_day', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$closeDate}-PM", null, [
                    'status' => 'closed_without_pm_finalize',
                    'source' => 'auto_close_cutoff',
                    'close_of_day_time' => $settings['close_of_day_time'],
                ]);
            }
        }

        $stmt = $ctx->db()->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
             VALUES (:bid, :d, \'closed\', :uid, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = \'closed\', closed_by = VALUES(closed_by), closed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':bid' => $branchId, ':d' => $closeDate, ':uid' => $closeActorId]);

        dl_auditLog('auto_close_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$closeDate}", null, [
            'status' => 'closed',
            'source' => 'cutoff',
            'close_of_day_time' => $settings['close_of_day_time'],
        ]);

        if ($ownsTxn) {
            $ctx->db()->commit();
        }
        return true;
    } catch (\Throwable $e) {
        if ($ownsTxn && $ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        write_log('daily-ledger auto close failed', 'error', [
            'branch_id' => $branchId,
            'ledger_date' => $closeDate,
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}

function dl_maybeAutoCloseBranches(array $branchIds, ?int $actorId = null, ?\DateTimeImmutable $now = null): void
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    $uniqueBranchIds = [];
    foreach ($branchIds as $branchId) {
        $branchId = (int)$branchId;
        if ($branchId > 0) {
            $uniqueBranchIds[$branchId] = true;
        }
    }

    foreach (array_keys($uniqueBranchIds) as $branchId) {
        dl_maybeAutoCloseBranchDay((int)$branchId, $actorId, $now);
    }
}

/**
 * Auto-finalize the PM shift for ONE commissary branch+date once that business
 * date has ended. Shift-only (the day auto-close at the cutoff is unchanged):
 *   - PM already finalized        -> success, no write, no audit
 *   - every active product has a PM ending -> finalize the PM shift (audited
 *     as finalize_production_shift)
 *   - ANY active product missing its PM ending -> never force-finalize; set
 *     pending_notified_at and write exactly ONE auto_close_shift audit row with
 *     status='closed_without_pm_finalize'. The shift stays open for correction.
 * Idempotent: a repeat pass does not duplicate the flag or its audit row.
 *
 * @return array{finalized:bool,flagged:bool,missing:int}
 */
function dl_maybeAutoFinalizeCommissaryPmShift(int $branchId, string $date, ?int $actorId = null): array
{
    $result = ['finalized' => false, 'flagged' => false, 'missing' => 0];
    if ($branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $result;
    }

    // Act only when the business date has already ended, matching the day
    // auto-close condition. The current business date is still live.
    if ($date >= dl_businessDate()) {
        return $result;
    }

    $ctx = module();
    if (!$ctx) {
        return $result;
    }
    $db = $ctx->db();

    // A day an admin deliberately reopened stays open until it is closed manually — the same
    // exemption dl_maybeAutoCloseBranchDay() makes. Without it the next page load re-finalises the
    // shift that apiReopenDay() just reopened, so the correction is impossible and the sheet reads
    // day=open while every cell is locked.
    $reopenStmt = $db->prepare('SELECT reopened_at FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d LIMIT 1');
    $reopenStmt->execute([':bid' => $branchId, ':d' => $date]);
    $reopenedAt = $reopenStmt->fetchColumn();
    if ($reopenedAt !== false && $reopenedAt !== null && (string)$reopenedAt !== '') {
        return $result;   // deliberately reopened: leave the shift exactly as the admin left it
    }

    // Active commissary products whose PM ending was never recorded.
    $missingStmt = $db->prepare(
        'SELECT COUNT(*)
           FROM dl_products p
           INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
           LEFT JOIN dl_commissary_product_ledger cpl
                  ON cpl.product_id = p.id
                 AND cpl.commissary_branch_id = :bid2
                 AND cpl.ledger_date = :d
                 AND cpl.shift = "PM"
          WHERE p.is_active = 1
            AND (cpl.id IS NULL OR cpl.actual_end_qty IS NULL)'
    );
    $missingStmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $date]);
    $missing = (int)$missingStmt->fetchColumn();
    $result['missing'] = $missing;

    $ownsTxn = !$db->inTransaction();
    if ($ownsTxn) {
        $db->beginTransaction();
    }
    try {
        // Shift-status row is the resource this evaluator owns; lock it before
        // reading its status or writing anything.
        $shift = dl_lockShiftStatusRow($db, $branchId, $date, 'PM');

        if ((string)($shift['status'] ?? 'open') === 'finalized') {
            // A finalized shift is a signed-off record. Even an incomplete one
            // stays finalized: this evaluator must never lift the immutability
            // boundary. Return success with no write and no audit.
            $result['finalized'] = true;
            $result['flagged'] = false;
        } elseif ($missing > 0) {
            // Never force-finalize an incomplete shift. Set the notification
            // flag only; the shift keeps whatever status it already has.
            $db->prepare(
                'UPDATE dl_ledger_shift_status
                    SET pending_notified_at = COALESCE(pending_notified_at, CURRENT_TIMESTAMP)
                  WHERE branch_id = :bid AND ledger_date = :d AND shift = "PM"'
            )->execute([':bid' => $branchId, ':d' => $date]);

            $auditStmt = $db->prepare(
                'SELECT COUNT(*) FROM audit_logs
                  WHERE module = "daily-ledger" AND action = "auto_close_shift" AND entity_id = :eid'
            );
            $auditStmt->execute([':eid' => "{$branchId}-{$date}-PM"]);
            if ((int)$auditStmt->fetchColumn() === 0) {
                dl_auditLog('auto_close_shift', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$date}-PM", null, [
                    'status' => 'closed_without_pm_finalize',
                    'source' => 'auto_finalize_pm_commissary',
                    'missing' => $missing,
                ]);
            }
            $result['flagged'] = true;
            $result['finalized'] = false;
        } else {
            if ((string)($shift['status'] ?? 'open') !== 'finalized') {
                $db->prepare(
                    'UPDATE dl_ledger_shift_status
                        SET status = "finalized", finalized_by = :uid, finalized_at = CURRENT_TIMESTAMP
                      WHERE branch_id = :bid AND ledger_date = :d AND shift = "PM"'
                )->execute([
                    ':uid' => ($actorId !== null && $actorId > 0) ? $actorId : null,
                    ':bid' => $branchId,
                    ':d' => $date,
                ]);
                dl_auditLog('finalize_production_shift', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$date}-PM", ['status' => 'open'], ['status' => 'finalized']);
            }
            $result['finalized'] = true;
        }

        if ($ownsTxn) {
            $db->commit();
        }
    } catch (\Throwable $e) {
        if ($ownsTxn && $db->inTransaction()) {
            $db->rollBack();
        }
        write_log('daily-ledger PM auto-finalize failed', 'error', [
            'branch_id' => $branchId,
            'ledger_date' => $date,
            'error' => $e->getMessage(),
        ]);
        return ['finalized' => false, 'flagged' => false, 'missing' => $missing];
    }

    return $result;
}

function dl_operatingClockLabel(): array
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = new \DateTimeImmutable('now', $timezone);

    return [
        'business_date' => dl_businessDate(),
        'close_of_day_time' => $settings['close_of_day_time'],
        'auto_close_enabled' => $settings['auto_close_enabled'],
        'operating_timezone' => $settings['operating_timezone'],
        'operating_region' => $settings['operating_region'],
        // Server clock, rendered in the operating timezone so an operator can see
        // at a glance whether the clock driving shift/day boundaries is correct
        // (a server on UTC reads AM through the local afternoon). The epoch is
        // timezone-free, so the top-bar clock can keep ticking client-side without
        // ever falling back to the viewer's own timezone. The label format matches
        // the client formatter exactly, so taking over for the tick does not
        // visibly change the rendering.
        'server_now_label' => $now->format('D, M j, Y, h:i:s A'),
        'server_now_offset' => $now->format('P'),
        'server_epoch_ms' => (int) round(microtime(true) * 1000),
    ];
}

function dl_getBranchName(int $branchId): string
{
    $ctx = module();
    if (!$ctx) return 'Unknown';
    $stmt = $ctx->db()->prepare('SELECT name FROM dl_branches WHERE id = :id');
    $stmt->execute([':id' => $branchId]);
    return (string)($stmt->fetchColumn() ?: 'Branch #' . $branchId);
}

function dl_getDayStatus(int $branchId, string $date): string
{
    $ctx = module();
    if (!$ctx) return 'open';

    $stmt = $ctx->db()->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d');
    $stmt->execute([':bid' => $branchId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['status'] : 'open';
}

/**
 * Which cashier shift is active right now, resolved in the operating timezone.
 * AM before the configured AM→PM cutoff (dl_amShiftCutoff()), PM at/after.
 * Used as the default shift on the cashier ledger so a PM-hour cashier lands
 * on the PM shift (and sees the AM ending handed off as her beginning)
 * without manually toggling. The explicit AM/PM toggle always overrides.
 */
function dl_currentShift(?\DateTimeImmutable $now = null): string
{
    $settings = dl_closeOfDaySettings();
    $timezone = new \DateTimeZone($settings['operating_timezone']);
    $now = $now ? $now->setTimezone($timezone) : new \DateTimeImmutable('now', $timezone);
    list($hours, $minutes) = array_map('intval', explode(':', dl_amShiftCutoff()));
    $nowMinutes = (int)$now->format('G') * 60 + (int)$now->format('i');
    $cutoffMinutes = $hours * 60 + $minutes;
    return $nowMinutes < $cutoffMinutes ? 'AM' : 'PM';
}

/**
 * AM→PM shift cutoff time (HH:MM) configured in Admin Settings. Invalid or
 * missing values fall back to "14:00". At/after this time the active shift
 * is PM.
 */
function dl_amShiftCutoff(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = trim((string)(dlModuleSettings()['am_shift_cutoff'] ?? '14:00'));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
        $raw = '14:00';
    }
    $cache = $raw;
    return $raw;
}

/**
 * Explicit per-user shift assignment (AM/PM) read from dl_users.shift, or
 * null when the account has no assignment. Cached per request+user id so
 * the ledger hot path only queries once.
 */
function dl_userAssignedShift(array $user): ?string
{
    $id = dl_getActorUserId($user);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $value = null;
    $ctx = module();
    if ($ctx) {
        try {
            $stmt = $ctx->db()->prepare('SELECT shift FROM dl_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $v = $stmt->fetchColumn();
            if ($v === 'AM' || $v === 'PM') {
                $value = (string)$v;
            }
        } catch (\Throwable $e) {
            // Column may be missing on tenants that have not run migration 046.
            $value = null;
        }
    }
    $cache[$id] = $value;
    return $value;
}

/**
 * Effective shift for the current actor.
 *
 * - Assigned cashiers (dl_users.shift set) are locked to their shift:
 *   the AM cashier stays on AM and may keep editing its own ledger even
 *   after the PM shift has started; the PM cashier stays on PM.
 * - Unassigned accounts (admin/supervisor/auditor, or cashiers without
 *   an assignment) follow the time-based active shift (dl_currentShift).
 */
function dl_userShift(array $user): string
{
    $assigned = dl_userAssignedShift($user);
    if ($assigned !== null) {
        return $assigned;
    }
    return dl_currentShift();
}

/**
 * Whether the account is bound to a specific shift (AM or PM) via
 * dl_users.shift. Bound cashiers may only ever view/edit that shift.
 */
function dl_userShiftBound(array $user): bool
{
    return dl_userAssignedShift($user) !== null;
}

/**
 * Resolve and enforce the ledger shift for the current actor.
 *
 * - A shift-bound cashier (dl_users.shift set) is FORCED to their assigned
 *   shift: any `shift` value coming from the request is ignored, so they can
 *   never open or edit the other shift's ledger.
 * - Everyone else (admin/supervisor/unassigned) follows the requested shift
 *   and defaults to the time-based active shift (dl_currentShift), which
 *   stays PM for the rest of the day once the AM cutoff has passed.
 *
 * @return array{shift: string, bound: bool}
 */
function dl_resolveLedgerShift(array $user, array $input): array
{
    $explicit = $input['shift'] ?? null;
    $explicit = in_array($explicit, ['AM', 'PM'], true) ? (string)$explicit : null;

    // An explicit shift from the caller means the operator is looking at that
    // shift (the ledger drives this from the AM/PM tab + date it is showing),
    // so honour it instead of guessing from the request clock. Limited to the
    // roles that correct a ledger across shifts: an assigned CASHIER stays
    // locked to their own shift, which is the accountability control.
    $role = (string)($user['role'] ?? '');
    if ($explicit !== null && in_array($role, ['admin', 'supervisor'], true)) {
        return ['shift' => $explicit, 'bound' => false];
    }

    $boundShift = dl_userAssignedShift($user);
    if ($boundShift !== null) {
        return ['shift' => $boundShift, 'bound' => true];
    }
    $shift = (($explicit ?? dl_currentShift()) === 'PM') ? 'PM' : 'AM';
    return ['shift' => $shift, 'bound' => false];
}


function dl_generateSku(): string
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $stmt = $ctx->db()->query('SELECT MAX(id) FROM dl_products');
    $nextId = ((int)$stmt->fetchColumn()) + 1;
    return 'BBS-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);
}

function dl_getActorUserId(array $user): int
{
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) {
            $userId = 0;
        }
    }
    if ($userId > 0) {
        return $userId;
    }

    $sub = (string)($user['sub'] ?? '');
    if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier|production_in_charge|auditor|viewer):(\d+)$/', $sub, $m)) {
        return (int)$m[1];
    }
    if (is_numeric($sub)) {
        return (int)$sub;
    }

    return 0;
}

function dl_accessibleBranchIds(array $user): array
{
    $ctx = module();
    if (!$ctx) {
        return [];
    }

    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['admin', 'auditor', 'viewer'], true)) {
        $stmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id');
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    if ($role === 'supervisor') {
        $sid = dl_getActorUserId($user);
        if ($sid <= 0) {
            return [];
        }
        $stmt = $ctx->db()->prepare(
            'SELECT b.id
             FROM dl_user_branches ub
             INNER JOIN dl_branches b ON b.id = ub.branch_id
             WHERE ub.user_id = :sid AND b.is_active = 1
             ORDER BY b.id'
        );
        $stmt->execute([':sid' => $sid]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    if ($role === 'production_in_charge') {
        $pid = dl_getActorUserId($user);
        if ($pid <= 0) {
            return [];
        }
        $stmt = $ctx->db()->prepare(
            'SELECT b.id
             FROM dl_user_branches ub
             INNER JOIN dl_branches b ON b.id = ub.branch_id
             WHERE ub.user_id = :pid AND b.is_active = 1
             ORDER BY b.id'
        );
        $stmt->execute([':pid' => $pid]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    }

    $branchId = dl_getUserBranchId();
    return $branchId ? [$branchId] : [];
}

/**
 * Canonical branch authorization — deny-by-default.
 *
 * Returns ['branch_id' => int, 'accessible' => int[]].
 * If an explicit branch_id is provided in input/GET and it is NOT in the
 * actor's accessible set, the response is a structured denial (caller handles
 * 403). Never silently falls back to a different branch when the caller
 * explicitly requested one.
 *
 * - Admins: accessible = all active tenant branches, default = first active.
 * - Supervisors: accessible = assigned active branches via dl_user_branches.
 * - Production in-charge: accessible = assigned active branches.
 * - Cashiers: locked to single assigned branch (accessible = that branch only).
 */
function dl_authorizeBranch(array $user, array $input = []): array
{
    $role = (string)($user['role'] ?? '');

    // --- Build accessible set ---
    if ($role === 'cashier') {
        $accessible = [];
        $branchId = dl_getUserBranchId();
        if ($branchId) {
            $accessible = [$branchId];
        }
        $requestedBranchId = 0;
        if (!empty($input['branch_id'])) {
            $requestedBranchId = (int)$input['branch_id'];
        } elseif (!empty($_GET['branch_id'])) {
            $requestedBranchId = (int)$_GET['branch_id'];
        }
        if ($requestedBranchId > 0 && $requestedBranchId !== (int)$branchId) {
            return ['branch_id' => -1, 'accessible' => $accessible];
        }
        return ['branch_id' => $branchId ?: 0, 'accessible' => $accessible];
    }

    $accessible = dl_accessibleBranchIds($user);
    $defaultBranchId = count($accessible) > 0 ? $accessible[0] : 0;

    // Check for an explicit requested branch
    $requestedBranchId = 0;
    if (!empty($input['branch_id'])) {
        $requestedBranchId = (int)$input['branch_id'];
    } elseif (!empty($_GET['branch_id'])) {
        $requestedBranchId = (int)$_GET['branch_id'];
    }

    if ($requestedBranchId > 0) {
        if (in_array($requestedBranchId, $accessible, true)) {
            return ['branch_id' => $requestedBranchId, 'accessible' => $accessible];
        }
        // Explicit unauthorized branch — deny, do not fall back
        return ['branch_id' => -1, 'accessible' => $accessible];
    }

    return ['branch_id' => $defaultBranchId, 'accessible' => $accessible];
}

/**
 * @deprecated Use dl_authorizeBranch() instead. Kept for backward compat.
 */
function dl_resolveLedgerBranchId(array $user, array $input = []): int
{
    $result = dl_authorizeBranch($user, $input);
    return $result['branch_id'] > 0 ? $result['branch_id'] : 0;
}

function dl_denyBranch(string $message = 'Branch not authorized'): void
{
    http_response_code(403);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/daily-ledger/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        echo $message;
    }
    exit;
}

function dl_generateMovementUuid(): string
{
    try {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    } catch (\Throwable $e) {
        return uniqid('dlm-', true);
    }
}

function dl_computeSalesValue(?int $begBal, ?int $addtl, ?int $withdraw, ?int $balEnd): ?int
{
    // An absent ending means sales is pending — never substitute 0.
    if ($balEnd === null) {
        return null;
    }
    return max(0, ($begBal ?? 0) + ($addtl ?? 0) - ($withdraw ?? 0) - $balEnd);
}

/**
 * Canonical stock-derived sales quantity expression. Returns NULL when the
 * ending was never recorded (pending) so aggregations can exclude it; a
 * recorded zero ending still computes the full invariant.
 */
function dl_ledgerSalesQuantitySql(string $alias = 'dl'): string
{
    $safeAlias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias : 'dl';
    return "CASE WHEN {$safeAlias}.bal_end IS NULL THEN NULL ELSE GREATEST(0, COALESCE({$safeAlias}.beg_bal,0) + COALESCE({$safeAlias}.addtl,0) - COALESCE({$safeAlias}.withdraw,0) - COALESCE({$safeAlias}.bal_end,0)) END";
}

function dl_ledgerSalesAmountSql(string $alias = 'dl', string $priceColumn = 'price_snapshot'): string
{
    $safeAlias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias : 'dl';
    $safePriceColumn = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $priceColumn) ? $priceColumn : 'price_snapshot';
    // NULL quantity propagates: pending rows never contribute an official amount.
    return dl_ledgerSalesQuantitySql($safeAlias) . " * COALESCE({$safeAlias}.{$safePriceColumn},0)";
}

/**
 * Settle one ledger row whose shift may never have been finalized.
 *
 * Returns the rung, the ending to report, and the sales to report — all
 * COMPUTED. Nothing here is ever written back into a counted column: the stored
 * ending stays NULL (R2). The caller supplies $movements from its OWN invariant
 * (cashier: beg_bal + addtl - withdraw; production: beg_qty + produced_qty -
 * dispatched_qty - wastage_qty), so the two sheets never accidentally share a
 * formula (R6).
 *
 * Rung 3 is only reached when $nextBeginningIsIndependent is true. A carried
 * beginning is a COPY of the very ending being estimated, so using it would be
 * estimating a value from itself; the circularity guard refuses it (R4) and the
 * row falls through to rule 2.
 *
 * There is no sixth rung: every NULL-ending case is covered by 3, 4 or 5.
 *
 * @return array{rung:string,ending:?int,sales:?int,official:bool}
 */
function dl_settleUnfinalizedRow(
    ?int $countedEnd,
    bool $shiftFinalized,
    int $movements,
    ?int $nextBeginning,
    bool $nextBeginningIsIndependent
): array {
    // A recorded ending — including a recorded ZERO — is a COUNT, never a
    // missing value. Only a NULL ending reaches the derived rungs below.
    if ($countedEnd !== null) {
        return [
            'rung' => $shiftFinalized ? 'counted' : 'counted-unsigned',
            'ending' => $countedEnd,
            'sales' => max(0, $movements - $countedEnd),
            'official' => $shiftFinalized,
        ];
    }

    // Rule 1: with every movement zero the invariant is max(0, 0 - ending) = 0
    // for any non-negative ending, so this is arithmetic, not an assumption.
    if ($movements === 0) {
        return ['rung' => 'zero-forced', 'ending' => 0, 'sales' => 0, 'official' => false];
    }

    // Rule 3: an INDEPENDENT next beginning is evidence of this missing ending.
    // A carried beginning is a COPY of the very ending being estimated, so using
    // it would be estimating a value from itself; refuse it (R4) and fall
    // through to rule 2.
    if ($nextBeginning !== null && $nextBeginningIsIndependent) {
        return [
            'rung' => 'derived-next-beginning',
            'ending' => $nextBeginning,
            'sales' => max(0, $movements - $nextBeginning),
            'official' => false,
        ];
    }

    // Rule 2: define the ending as the movements, so the shift settles at zero
    // sales. A labelled derivation, never official (C1, R3).
    return ['rung' => 'derived-from-movements', 'ending' => $movements, 'sales' => 0, 'official' => false];
}

/**
 * Resolve the ledger table an ending-provenance operation targets.
 *
 * @return array{table:string,branch_col:string,end_col:string,entity_type:string}
 */
function dl_endingProvenanceTable(bool $production): array
{
    return $production
        ? [
            'table' => 'dl_commissary_product_ledger',
            'branch_col' => 'commissary_branch_id',
            'end_col' => 'actual_end_qty',
            'entity_type' => 'dl_commissary_product_ledger',
        ]
        : [
            'table' => 'dl_daily_ledger',
            'branch_col' => 'branch_id',
            'end_col' => 'bal_end',
            'entity_type' => 'dl_daily_ledger',
        ];
}

/**
 * Read-only counts for the settle / verify / revert control surface on ONE viewed shift.
 *
 * This is presentation only (NO writes, NO settling - R5). Each sheet counts its OWN
 * table, so the cashier sheet and the production sheet can never disagree about which
 * lifecycle rows they are describing.
 *
 * @param ?string $shift AM, PM, or null when no single shift is being viewed
 * @return array{pending:int,unverified:int,verified:int,can_settle:bool,can_verify:bool,date:string,shift:?string,branch_id:int}
 */
function dl_settledEndingSummary($db, int $branchId, string $date, ?string $shift, string $role, bool $production): array
{
    $pending = 0;
    $unverified = 0;
    $verified = 0;
    if ($branchId > 0 && $shift !== null && $shift !== '') {
        $config = dl_endingProvenanceTable($production);
        $stmt = $db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN {$config['end_col']} IS NULL THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN end_source IN ('derived-from-movements','zero-forced') THEN 1 ELSE 0 END), 0) AS unverified,
                COALESCE(SUM(CASE WHEN end_verified_by IS NOT NULL THEN 1 ELSE 0 END), 0) AS verified
               FROM {$config['table']}
              WHERE {$config['branch_col']} = :bid AND ledger_date = :d AND shift = :shift"
        );
        $stmt->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $pending = (int)($row['pending'] ?? 0);
        $unverified = (int)($row['unverified'] ?? 0);
        $verified = (int)($row['verified'] ?? 0);
    }

    return [
        'pending' => $pending,
        'unverified' => $unverified,
        'verified' => $verified,
        'can_settle' => in_array($role, ['admin', 'supervisor', 'production_in_charge'], true),
        'can_verify' => $role === 'admin',
        'date' => $date,
        'shift' => $shift,
        'branch_id' => $branchId,
    ];
}

/**
 * Settle every NULL ending of one shift as a TAGGED, unverified proposal (R3).
 *
 * This SUPERSEDES R2 of the smart-settlement contract ("never write a derived ending
 * into a counted column"). The C1 guarantee is kept by making the write DURABLE and
 * REVERSIBLE: end_source names the ladder rung, the row reads provisional, and an admin
 * can verify (certify) or revert (return to pending) it. A row whose ending is NOT NULL
 * - a count, a verified count, or an earlier settle - is never touched.
 *
 * Authorization is enforced HERE on the resolved $actor array, before any write, so it is
 * assertable in-process without an HTTP round trip (R7).
 *
 * @return array{settled:int}
 */
function dl_settlePendingEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
{
    $role = (string)($actor['role'] ?? '');
    if (!in_array($role, ['admin', 'supervisor', 'production_in_charge'], true)) {
        throw new \RuntimeException('Only an admin, supervisor or production-in-charge may settle pending endings.', 403);
    }
    if (!in_array($branchId, dl_accessibleBranchIds($actor), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.', 403);
    }

    $shift = dl_normalizeShift($shift);
    $finalized = dl_shiftIsFinalized($db, $branchId, $date, $shift);
    $actorId = dl_getActorUserId($actor);
    $config = dl_endingProvenanceTable($production);

    $db->beginTransaction();
    try {
        $settled = 0;
        if ($production) {
            // Production invariant is its OWN: beg_qty + produced_qty - dispatched_qty - wastage_qty.
            $select = $db->prepare(
                'SELECT id, product_id, beg_qty, produced_qty, dispatched_qty, wastage_qty
                   FROM dl_commissary_product_ledger
                  WHERE commissary_branch_id = :bid AND ledger_date = :d AND shift = :shift
                    AND actual_end_qty IS NULL
                  ORDER BY product_id
                  FOR UPDATE'
            );
            $select->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $movements = (int)$row['beg_qty'] + (int)$row['produced_qty'] - (int)$row['dispatched_qty'] - (int)$row['wastage_qty'];
                $decision = dl_settleUnfinalizedRow(null, $finalized, $movements, null, false);
                $rung = (string)($decision['rung'] ?? '');
                if ($rung !== 'derived-from-movements' && $rung !== 'zero-forced') {
                    continue;
                }
                $ending = (int)$decision['ending'];
                $update = $db->prepare(
                    'UPDATE dl_commissary_product_ledger
                        SET actual_end_qty = :end, end_source = :src, end_settled_at = NOW(),
                            updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id AND actual_end_qty IS NULL'
                );
                $update->execute([
                    ':end' => $ending,
                    ':src' => $rung,
                    ':uid' => $actorId > 0 ? $actorId : null,
                    ':id' => (int)$row['id'],
                ]);
                if ($update->rowCount() < 1) {
                    continue;
                }
                dl_auditLog(
                    'settle_derived_ending',
                    $branchId,
                    $config['entity_type'],
                    $branchId . '-' . $date . '-' . $shift . '-' . (int)$row['product_id'],
                    ['actual_end_qty' => null, 'end_source' => null],
                    ['actual_end_qty' => $ending, 'end_source' => $rung, 'rung' => $rung, 'shift' => $shift, 'ledger_date' => $date]
                );
                $settled++;
            }
        } else {
            // Cashier invariant is its OWN: beg_bal + addtl - withdraw.
            $select = $db->prepare(
                'SELECT id, product_id, beg_bal, addtl, withdraw
                   FROM dl_daily_ledger
                  WHERE branch_id = :bid AND ledger_date = :d AND shift = :shift
                    AND bal_end IS NULL
                  ORDER BY product_id
                  FOR UPDATE'
            );
            $select->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $movements = (int)$row['beg_bal'] + (int)$row['addtl'] - (int)$row['withdraw'];
                $decision = dl_settleUnfinalizedRow(null, $finalized, $movements, null, false);
                $rung = (string)($decision['rung'] ?? '');
                if ($rung !== 'derived-from-movements' && $rung !== 'zero-forced') {
                    continue;
                }
                $ending = (int)$decision['ending'];
                $update = $db->prepare(
                    'UPDATE dl_daily_ledger
                        SET bal_end = :end, end_source = :src, end_settled_at = NOW(),
                            updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id AND bal_end IS NULL'
                );
                $update->execute([
                    ':end' => $ending,
                    ':src' => $rung,
                    ':uid' => $actorId > 0 ? $actorId : null,
                    ':id' => (int)$row['id'],
                ]);
                if ($update->rowCount() < 1) {
                    continue;
                }
                // Normal cashier write path owns `sales`; production `remaining_qty` is generated.
                dl_recomputeSales($branchId, (int)$row['product_id'], $date, $actorId, $shift);
                dl_auditLog(
                    'settle_derived_ending',
                    $branchId,
                    $config['entity_type'],
                    $branchId . '-' . $date . '-' . $shift . '-' . (int)$row['product_id'],
                    ['bal_end' => null, 'end_source' => null],
                    ['bal_end' => $ending, 'end_source' => $rung, 'rung' => $rung, 'shift' => $shift, 'ledger_date' => $date]
                );
                $settled++;
            }
        }

        $db->commit();
        return ['settled' => $settled];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Admin-only: certify every unverified derived ending of a shift as a count (R4).
 *
 * The ENDING VALUE IS NOT CHANGED - a verify is a human certifying the number, not a
 * recomputation. Clearing end_source is what makes the row countable/official again.
 *
 * @return array{verified:int}
 */
function dl_verifySettledEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
{
    if ((string)($actor['role'] ?? '') !== 'admin') {
        throw new \RuntimeException('Only an admin may verify settled endings.', 403);
    }
    if (!in_array($branchId, dl_accessibleBranchIds($actor), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.', 403);
    }

    $shift = dl_normalizeShift($shift);
    $actorId = dl_getActorUserId($actor);
    $config = dl_endingProvenanceTable($production);

    $db->beginTransaction();
    try {
        $verified = 0;
        $select = $db->prepare(
            "SELECT id, product_id, {$config['end_col']} AS ending, end_source
               FROM {$config['table']}
              WHERE {$config['branch_col']} = :bid AND ledger_date = :d AND shift = :shift
                AND end_source IN ('derived-from-movements','zero-forced')
                AND end_verified_by IS NULL
              ORDER BY product_id
              FOR UPDATE"
        );
        $select->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ending = $row['ending'] === null ? null : (int)$row['ending'];
            $source = (string)($row['end_source'] ?? '');
            $update = $db->prepare(
                "UPDATE {$config['table']}
                    SET end_source = NULL, end_verified_by = :uid, end_verified_at = NOW()
                  WHERE id = :id AND end_source IS NOT NULL AND end_verified_by IS NULL"
            );
            $update->execute([':uid' => $actorId, ':id' => (int)$row['id']]);
            if ($update->rowCount() < 1) {
                continue;
            }
            dl_auditLog(
                'verify_derived_ending',
                $branchId,
                $config['entity_type'],
                $branchId . '-' . $date . '-' . $shift . '-' . (int)$row['product_id'],
                ['ending' => $ending, 'end_source' => $source],
                ['ending' => $ending, 'end_source' => null, 'verified_by' => $actorId, 'rung' => $source]
            );
            $verified++;
        }

        $db->commit();
        return ['verified' => $verified];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Admin-only: return every UNVERIFIED derived ending of a shift to pending (R5).
 *
 * This is the irreversibility guarantee. A VERIFIED row is a certified count and is NOT
 * reverted here; undoing a certification is a separate, deliberate act (out of scope).
 *
 * @return array{reverted:int}
 */
function dl_revertSettledEndingsForShift($db, int $branchId, string $date, string $shift, array $actor, bool $production): array
{
    if ((string)($actor['role'] ?? '') !== 'admin') {
        throw new \RuntimeException('Only an admin may revert settled endings.', 403);
    }
    if (!in_array($branchId, dl_accessibleBranchIds($actor), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.', 403);
    }

    $shift = dl_normalizeShift($shift);
    $actorId = dl_getActorUserId($actor);
    $config = dl_endingProvenanceTable($production);

    $db->beginTransaction();
    try {
        $reverted = 0;
        $select = $db->prepare(
            "SELECT id, product_id, {$config['end_col']} AS ending, end_source
               FROM {$config['table']}
              WHERE {$config['branch_col']} = :bid AND ledger_date = :d AND shift = :shift
                AND end_source IN ('derived-from-movements','zero-forced')
                AND end_verified_by IS NULL
              ORDER BY product_id
              FOR UPDATE"
        );
        $select->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ending = $row['ending'] === null ? null : (int)$row['ending'];
            $source = (string)($row['end_source'] ?? '');
            $update = $db->prepare(
                "UPDATE {$config['table']}
                    SET {$config['end_col']} = NULL, end_source = NULL, end_settled_at = NULL
                  WHERE id = :id AND end_source IS NOT NULL AND end_verified_by IS NULL"
            );
            $update->execute([':id' => (int)$row['id']]);
            if ($update->rowCount() < 1) {
                continue;
            }
            if (!$production) {
                // Keep `sales` consistent with the now-absent ending.
                dl_recomputeSales($branchId, (int)$row['product_id'], $date, $actorId, $shift);
            }
            dl_auditLog(
                'revert_derived_ending',
                $branchId,
                $config['entity_type'],
                $branchId . '-' . $date . '-' . $shift . '-' . (int)$row['product_id'],
                ['ending' => $ending, 'end_source' => $source],
                ['ending' => null, 'end_source' => null, 'rung' => $source]
            );
            $reverted++;
        }

        $db->commit();
        return ['reverted' => $reverted];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function dl_applyLedgerDelta(int $branchId, int $productId, string $ledgerDate, int $delta, int $actorId, string $column = 'addtl', string $shift = 'AM'): array
{
    if (!in_array($column, ['addtl', 'withdraw'], true)) {
        throw new \RuntimeException('Invalid ledger column: ' . $column);
    }
    $shift = ($shift === 'PM') ? 'PM' : 'AM';

    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    // Finalized shifts are immutable to every domain mutation. Admin/supervisor
    // must explicitly reopen the shift before correcting locked sales. For the
    // PM shift (the only finalizable shift) lock the shift-status row so a
    // concurrent finalize cannot interleave with this mutation — consistent
    // lock order with apiFinalizePmShift (day-status → shift-status).
    if ($shift === 'PM') {
        $shiftLock = dl_lockShiftStatusRow($ctx->db(), $branchId, $ledgerDate, 'PM');
        if ((string)$shiftLock['status'] === 'finalized') {
            throw new \RuntimeException('This shift is finalized and locked. Reopen the shift before editing.', 403);
        }
    } else {
        dl_assertShiftMutable($ctx->db(), $branchId, $ledgerDate, 'AM');
    }

    $select = $ctx->db()->prepare(
        'SELECT id, addtl, withdraw FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1 FOR UPDATE'
    );
    $select->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

    $price = dl_resolveBranchProductPrice($branchId, $productId, $ledgerDate);

    if (!$row) {
        if ($delta < 0) {
            throw new \RuntimeException('Cannot reverse before an output/withdrawal exists for this date.');
        }
        $addtlVal = $column === 'addtl' ? $delta : 0;
        $withdrawVal = $column === 'withdraw' ? $delta : 0;
        // A delta-created row has no counted ending yet — bal_end stays NULL.
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, 0, :addtl, :withdraw, NULL, :uid, :uid2)'
        );
        $ins->execute([
            ':bid' => $branchId,
            ':pid' => $productId,
            ':d' => $ledgerDate,
            ':shift' => $shift,
            ':price' => $price,
            ':addtl' => $addtlVal,
            ':withdraw' => $withdrawVal,
            ':uid' => $actorId > 0 ? $actorId : null,
            ':uid2' => $actorId > 0 ? $actorId : null,
        ]);
        dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId), $shift);
        dl_recomputeVariancesForDay($branchId, $ledgerDate);
        return [$column => $delta];
    }

    $currentVal = (int)($row[$column] ?? 0);
    $newVal = $currentVal + $delta;
    if ($newVal < 0) {
        $label = $column === 'addtl' ? 'additional (output)' : 'withdrawal';
        throw new \RuntimeException('Reverse quantity exceeds available ' . $label . ' stock.');
    }

    $upd = $ctx->db()->prepare(
        "UPDATE dl_daily_ledger
         SET {$column} = :val, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $upd->execute([
        ':val' => $newVal,
        ':uid' => $actorId > 0 ? $actorId : null,
        ':id' => (int)$row['id'],
    ]);

    dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId), $shift);
    dl_recomputeVariancesForDay($branchId, $ledgerDate);
    return [$column => $newVal];
}

/**
 * Suggest the next day-level opening. A physical count resets the baseline;
 * only movement on later dates accrues. With no earlier count this degrades to
 * the original cumulative movement rule. Negative book positions stay visible.
 */
function dl_suggestCommissaryBeginning($db, int $commissaryBranchId, int $productId, string $ledgerDate): int
{
    $countStmt = $db->prepare(
        'SELECT ledger_date, actual_end_qty
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid
            AND ledger_date < :d AND actual_end_qty IS NOT NULL
          ORDER BY ledger_date DESC
          LIMIT 1'
    );
    $countStmt->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate]);
    $count = $countStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $movementSql = 'SELECT COALESCE(SUM(produced_qty - dispatched_qty - wastage_qty), 0)
                      FROM dl_commissary_product_ledger
                     WHERE commissary_branch_id = :cb AND product_id = :pid
                       AND ledger_date < :d';
    $bind = [':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate];
    if ($count) {
        $movementSql .= ' AND ledger_date > :count_date';
        $bind[':count_date'] = (string)$count['ledger_date'];
    }
    $movementStmt = $db->prepare($movementSql);
    $movementStmt->execute($bind);
    $movement = (int)$movementStmt->fetchColumn();

    return ($count ? (int)$count['actual_end_qty'] : 0) + $movement;
}

/**
 * Fetch the exact preceding-shift ending used by the production carry control.
 * PM has one source only: that product's AM count on the same date. AM uses the
 * latest earlier date with a count, preferring that date's PM row over AM (and
 * an historical unshifted row last). There is deliberately no fallback from a
 * missing PM→AM handoff to an older day.
 */
function dl_fetchCommissaryBeginningSuggestions(
    $db,
    int $commissaryBranchId,
    string $ledgerDate,
    ?string $shift = 'AM'
): array {
    if ($commissaryBranchId <= 0) {
        return [];
    }
    $shift = dl_normalizeShift((string)$shift);
    if ($shift === 'PM') {
        $stmt = $db->prepare(
            'SELECT product_id, actual_end_qty
               FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND ledger_date = :d
                AND shift = "AM" AND actual_end_qty IS NOT NULL
              ORDER BY product_id, id DESC'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT product_id, actual_end_qty
               FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND ledger_date < :d
                AND actual_end_qty IS NOT NULL
              ORDER BY product_id, ledger_date DESC,
                       CASE shift WHEN "PM" THEN 2 WHEN "AM" THEN 1 ELSE 0 END DESC,
                       id DESC'
        );
    }
    $stmt->execute([':cb' => $commissaryBranchId, ':d' => $ledgerDate]);
    $suggestions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int)$row['product_id'];
        if (!array_key_exists($productId, $suggestions)) {
            $suggestions[$productId] = (int)$row['actual_end_qty'];
        }
    }
    return $suggestions;
}

/**
 * Carry a sheet in one transaction. Every requested row must still be an
 * unrecorded zero and must equal the server-selected positive preceding ending;
 * one stale/invalid row aborts the entire batch.
 */
function dl_carryCommissaryBeginnings(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) throw new \RuntimeException('Module context unavailable.');
    $db = $ctx->db();
    $date = (string)($input['date'] ?? '');
    $branchId = (int)($input['commissary_branch_id'] ?? 0);
    $shift = dl_normalizeShift((string)($input['shift'] ?? ''));
    $rows = $input['rows'] ?? null;
    $key = trim((string)($input['idempotency_key'] ?? ''));
    $role = (string)($user['role'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $branchId <= 0 || !is_array($rows) || $rows === []
        || $key === '' || strlen($key) > 190) {
        throw new \RuntimeException('Invalid production carry request.');
    }
    if (!in_array($branchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.', 403);
    }
    $day = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $day->execute([':b' => $branchId, ':d' => $date]);
    if ((string)$day->fetchColumn() === 'closed') {
        throw new \RuntimeException('This day is closed. Reopen the day before carrying beginnings.', 403);
    }
    if (dl_shiftIsFinalized($db, $branchId, $date, $shift)) {
        throw new \RuntimeException("The {$shift} shift is finalized. Reopen the shift before carrying beginnings.", 403);
    }
    if (!in_array($role, ['admin', 'supervisor', 'production_in_charge'], true)) {
        throw new \RuntimeException('This date is read-only for your role, so beginnings cannot be carried forward.', 403);
    }

    // The client's idempotency key is already shaped
    // carry-forward-<branch>-<date>-<shift>-<epoch>, so it carries the same
    // branch/date/shift identity the old "{$branchId}-{$date}-{$shift}-{$key}"
    // prefixed onto it. Prefixing again pushed the value past
    // audit_logs.entity_id varchar(50) and the carry's audit row was silently
    // lost. Use the key itself. For an unexpectedly long key we fall back to a
    // deterministic short digest of the whole key rather than truncating it:
    // truncation would drop the epoch and let two carries in one shift collide.
    $batchEntityId = $key;
    if (strlen($batchEntityId) > 50) {
        $batchEntityId = 'cf-' . sha1($key);
    }
    $duplicate = $db->prepare('SELECT 1 FROM audit_logs WHERE module = "daily-ledger" AND action = "carry_commissary_beginnings" AND entity_id = :eid LIMIT 1');
    $duplicate->execute([':eid' => $batchEntityId]);
    if ($duplicate->fetchColumn()) return ['carried' => 0, 'duplicate' => true];

    $source = dl_fetchCommissaryBeginningSuggestions($db, $branchId, $date, $shift);
    $normalized = [];
    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $begQty = filter_var($row['beg_qty'] ?? null, FILTER_VALIDATE_INT);
        if ($productId <= 0 || $begQty === false || (int)$begQty <= 0 || isset($normalized[$productId])
            || !isset($source[$productId]) || (int)$source[$productId] !== (int)$begQty) {
            throw new \RuntimeException('Carry rows no longer match the preceding positive endings; nothing was changed.');
        }
        $normalized[$productId] = (int)$begQty;
    }

    $actorId = dl_getActorUserId($user);
    $db->beginTransaction();
    try {
        $lock = $db->prepare(
            'SELECT beg_qty FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift = :shift
              LIMIT 1 FOR UPDATE'
        );
        $recorded = $db->prepare(
            'SELECT 1 FROM audit_logs WHERE module = "daily-ledger" AND action = "save_commissary_product_beg"
              AND entity_id = :eid LIMIT 1 FOR UPDATE'
        );
        foreach ($normalized as $productId => $begQty) {
            $lock->execute([':cb' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
            $current = $lock->fetchColumn();
            $entityId = "{$branchId}-{$productId}-{$date}-{$shift}";
            $recorded->execute([':eid' => $entityId]);
            if ($recorded->fetchColumn() || ($current !== false && (int)$current !== 0)) {
                throw new \RuntimeException('A beginning was already recorded or is no longer zero; nothing was changed.');
            }
            dl_saveCommissaryBeginningQty($db, $branchId, $productId, $date, $begQty, $actorId, $shift);
            dl_auditLog('save_commissary_product_beg', $branchId, 'dl_commissary_product_ledger', $entityId, null, [
                'beg_qty' => $begQty, 'source' => 'carry_forward', 'idempotency_key' => $key,
            ]);
        }
        dl_auditLog('carry_commissary_beginnings', $branchId, 'dl_commissary_product_ledger', $batchEntityId, null, [
            'date' => $date, 'shift' => $shift, 'rows' => count($normalized), 'idempotency_key' => $key,
        ]);
        $db->commit();
        return ['carried' => count($normalized), 'duplicate' => false];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function dl_ensureCommissaryProductLedgerRow(
    $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    int $actorId,
    ?string $shift = null
): int {
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $suggestedBeg = dl_suggestCommissaryBeginning($db, $commissaryBranchId, $productId, $ledgerDate);
    $stmt = $db->prepare(
        'INSERT IGNORE INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, updated_by)
         VALUES (:cb, :pid, :d, :shift, :beg, 0, 0, 0, :uid)'
    );
    $stmt->execute([
        ':cb' => $commissaryBranchId,
        ':pid' => $productId,
        ':d' => $ledgerDate,
        ':shift' => $shift,
        ':beg' => $suggestedBeg,
        ':uid' => $actorId > 0 ? $actorId : null,
    ]);
    return $stmt->rowCount();
}

function dl_applyCommissaryProductLedgerDelta(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    int $producedDelta,
    int $dispatchedDelta,
    int $actorId,
    int $wastageDelta = 0,
    bool $rejectNegativeBalance = false,
    ?string $shift = null
): array {
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    if ($commissaryBranchId <= 0 || $productId <= 0 || $ledgerDate === '') {
        return ['produced_qty' => 0, 'dispatched_qty' => 0, 'remaining_qty' => 0, 'skipped' => true];
    }

    // Verify the branch is actually a commissary
    $checkStmt = $db->prepare('SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1');
    $checkStmt->execute([':id' => $commissaryBranchId]);
    if (!$checkStmt->fetchColumn()) {
        return ['produced_qty' => 0, 'dispatched_qty' => 0, 'remaining_qty' => 0, 'skipped' => true];
    }

    $select = $db->prepare(
        'SELECT id, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty, calc_variance
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift
          LIMIT 1
          FOR UPDATE'
    );
    $select->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        if ($producedDelta < 0 || $dispatchedDelta < 0 || $wastageDelta < 0) {
            throw new \RuntimeException('Cannot reverse commissary production before any output exists for this date.');
        }
        dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $ledgerDate, $actorId, $shift);
        $select->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
        $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            throw new \RuntimeException('Unable to create commissary product ledger row.');
        }
    }

    $currentProduced = (int)$row['produced_qty'];
    $currentDispatched = (int)$row['dispatched_qty'];
    $currentWastage = (int)$row['wastage_qty'];
    // A correction against a recorded delivery must never be silently clamped:
    // when the caller opts in, a delta that would drive any recorded column
    // below zero is refused so the stored ledger cannot disagree with the figure
    // the operator was shown.
    if ($rejectNegativeBalance
        && ($currentProduced + $producedDelta < 0
            || $currentDispatched + $dispatchedDelta < 0
            || $currentWastage + $wastageDelta < 0)) {
        throw new \RuntimeException('Cannot record a negative ledger balance for this product on this date.', 422);
    }
    $newProduced = max(0, $currentProduced + $producedDelta);
    $newDispatched = max(0, $currentDispatched + $dispatchedDelta);
    $newWastage = max(0, $currentWastage + $wastageDelta);

    $db->prepare(
        'UPDATE dl_commissary_product_ledger
            SET produced_qty = :prod, dispatched_qty = :disp, wastage_qty = :waste, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    )->execute([
        ':prod' => $newProduced,
        ':disp' => $newDispatched,
        ':waste' => $newWastage,
        ':uid' => $actorId > 0 ? $actorId : null,
        ':id' => (int)$row['id'],
    ]);

    return ['beg_qty' => (int)$row['beg_qty'], 'produced_qty' => $newProduced, 'dispatched_qty' => $newDispatched, 'wastage_qty' => $newWastage, 'remaining_qty' => (int)$row['beg_qty'] + $newProduced - $newDispatched - $newWastage, 'skipped' => false];
}

/**
 * In-request buffer for integrity emails.
 *
 * A receipt write must never hold the cashier's HTTP connection open on SMTP:
 * two admin round-trips can outlast the 15s client write abort, so the cashier
 * is told the result is UNKNOWN while the server may already have committed.
 * Mail is therefore queued while the request is being served and flushed only
 * after the response has been finished. No new table, no new dependency, no
 * durable-outbox worker (there is none).
 */
function dl_queueIntegrityEmail(string $to, string $subject, string $html): void
{
    // No client to release on the CLI: send synchronously so maintenance and
    // scripted paths keep delivering mail.
    if (PHP_SAPI === 'cli') {
        if (function_exists('sendEmail')) {
            sendEmail($to, $subject, $html);
        }
        return;
    }

    if (!isset($GLOBALS['dl_integrity_email_queue']) || !is_array($GLOBALS['dl_integrity_email_queue'])) {
        $GLOBALS['dl_integrity_email_queue'] = [];
    }
    $GLOBALS['dl_integrity_email_queue'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];

    // Registration is lazy: only a request that actually raised an email pays
    // for the backstop. It flushes every HTTP path that enqueued, even one that
    // never got (or cannot have) an explicit flush, so a queued mail can never
    // be silently dropped.
    if (empty($GLOBALS['dl_integrity_email_flush_registered'])) {
        $GLOBALS['dl_integrity_email_flush_registered'] = true;
        register_shutdown_function('dl_flushDeferredIntegrityEmailsAfterResponse');
    }
}

/** Send every queued integrity email. Safe to call more than once. */
function dl_flushDeferredIntegrityEmails(): int
{
    $queue = $GLOBALS['dl_integrity_email_queue'] ?? [];
    if (!is_array($queue) || $queue === []) {
        return 0;
    }
    $GLOBALS['dl_integrity_email_queue'] = [];
    $sent = 0;
    foreach ($queue as $mail) {
        if (!function_exists('sendEmail')) {
            continue;
        }
        try {
            if (sendEmail((string)$mail['to'], (string)$mail['subject'], (string)$mail['html'])) {
                $sent++;
            }
        } catch (\Throwable $e) {
            // A mail failure must never resurface as a write failure after the
            // receipt has already committed.
        }
    }
    return $sent;
}

/**
 * Shutdown backstop for deferred integrity emails. The JSON response has already
 * been echoed by the endpoint; this releases the session lock, finishes the HTTP
 * response, and only then performs the SMTP work the UI must not wait on.
 */
function dl_flushDeferredIntegrityEmailsAfterResponse(): void
{
    if (empty($GLOBALS['dl_integrity_email_queue'])) {
        return;
    }
    release_session_lock_if_active();
    finish_response_if_possible();
    dl_flushDeferredIntegrityEmails();
}

/**
 * Emit a JSON response, release the session and client, then flush deferred
 * mail. The proven finish-response shape, local to Daily Ledger so the shared
 * bootstrap helper is untouched.
 */
function dl_respondThenFlushMail(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
        if (function_exists('request_id') && ($rid = request_id())) {
            header('X-Request-Id: ' . $rid);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    release_session_lock_if_active();
    finish_response_if_possible();
    dl_flushDeferredIntegrityEmails();
    exit;
}

/** Record one integrity finding and address it to active admins/supervisors with authority over the branch. */
function dl_raiseIntegrityNotification($db, string $key, string $type, ?int $branchId, ?string $entityType, ?int $entityId, string $title, string $detail = '', bool $email = false): ?int
{
    $insert = $db->prepare(
        'INSERT IGNORE INTO dl_integrity_notifications
            (aggregate_key, finding_type, branch_id, entity_type, entity_id, title, detail)
         VALUES (:k, :t, :b, :et, :eid, :title, :detail)'
    );
    $insert->execute([':k' => $key, ':t' => $type, ':b' => $branchId, ':et' => $entityType, ':eid' => $entityId, ':title' => $title, ':detail' => $detail !== '' ? $detail : null]);
    $created = $insert->rowCount() === 1;
    $idStmt = $db->prepare('SELECT id FROM dl_integrity_notifications WHERE aggregate_key = :k LIMIT 1');
    $idStmt->execute([':k' => $key]);
    $notificationId = (int)($idStmt->fetchColumn() ?: 0);
    if ($notificationId <= 0) return null;

    $recipientSql = "SELECT DISTINCT u.id, u.email
                       FROM dl_users u
                       LEFT JOIN dl_user_branches ub ON ub.user_id = u.id
                      WHERE u.is_active = 1 AND u.deleted_at IS NULL
                        AND (u.role = 'admin' OR (u.role = 'supervisor' AND ub.branch_id = :branch))";
    $recipients = $db->prepare($recipientSql);
    $recipients->execute([':branch' => $branchId ?? 0]);
    $recipientRows = $recipients->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $address = $db->prepare('INSERT IGNORE INTO dl_integrity_notification_recipients (notification_id, user_id) VALUES (:n, :u)');
    foreach ($recipientRows as $recipient) {
        $address->execute([':n' => $notificationId, ':u' => (int)$recipient['id']]);
        if ($created && $email && function_exists('sendEmail')) {
            $to = trim((string)($recipient['email'] ?? ''));
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                // Deferred: queued here, sent after the HTTP response is finished
                // (or inline under CLI) so the write never waits on SMTP.
                dl_queueIntegrityEmail($to, '[Daily Ledger] ' . $title, '<p>' . htmlspecialchars($detail !== '' ? $detail : $title, ENT_QUOTES, 'UTF-8') . '</p>');
            }
        }
    }
    return $notificationId;
}

function dl_commissaryLedgerSnapshot($db, int $branchId, int $productId, string $date): array
{
    $stmt = $db->prepare('SELECT dispatched_qty, remaining_qty, calc_variance FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d LIMIT 1');
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'dispatched_qty' => (int)($row['dispatched_qty'] ?? 0),
        'remaining_qty' => (int)($row['remaining_qty'] ?? 0),
        'calc_variance' => array_key_exists('calc_variance', $row) && $row['calc_variance'] !== null ? (int)$row['calc_variance'] : null,
    ];
}

/**
 * Apply the commissary debit represented by a posted delivery exactly once per item.
 * The unique delivery_item_id is the retry/offline-replay idempotency boundary.
 */
function dl_applyPostedDeliveryCommissaryLedger($db, int $deliveryId, int $actorId): array
{
    $headStmt = $db->prepare('SELECT id, origin_type, origin_id, resolved_origin_id, destination_type, destination_id, delivery_date, status FROM dl_deliveries WHERE id = :id FOR UPDATE');
    $headStmt->execute([':id' => $deliveryId]);
    $head = $headStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$head || (string)$head['status'] !== 'posted' || (string)$head['origin_type'] !== 'commissary' || (string)$head['destination_type'] !== 'branch') {
        return ['status' => 'not_applicable', 'applied' => 0];
    }
    $originId = (int)($head['resolved_origin_id'] ?? $head['origin_id'] ?? 0);
    if ($originId <= 0) {
        dl_raiseIntegrityNotification($db, 'delivery-origin-' . $deliveryId, 'unresolved_origin', (int)$head['destination_id'], 'dl_deliveries', $deliveryId,
            'Dispatch origin must be resolved', 'Delivery #' . $deliveryId . ' is posted but has no attributable commissary; no ledger debit was guessed.', true);
        return ['status' => 'unresolved_origin', 'applied' => 0];
    }

    $items = $db->prepare('SELECT id, product_id, quantity FROM dl_delivery_items WHERE delivery_id = :id ORDER BY id FOR UPDATE');
    $items->execute([':id' => $deliveryId]);
    $insert = $db->prepare(
        'INSERT INTO dl_delivery_ledger_effects
            (delivery_id, delivery_item_id, commissary_branch_id, product_id, ledger_date, quantity, effect_status,
             applied_by, applied_at, before_dispatched_qty, after_dispatched_qty, before_remaining_qty, after_remaining_qty)
         VALUES (:d, :di, :b, :p, :dt, :q, "applied", :u, NOW(), :bd, :ad, :br, :ar)'
    );
    $applied = 0;
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
        $exists = $db->prepare('SELECT effect_status FROM dl_delivery_ledger_effects WHERE delivery_item_id = :id FOR UPDATE');
        $exists->execute([':id' => (int)$item['id']]);
        if ($exists->fetchColumn() !== false) continue;
        $before = dl_commissaryLedgerSnapshot($db, $originId, (int)$item['product_id'], (string)$head['delivery_date']);
        $state = dl_applyCommissaryProductLedgerDelta($db, $originId, (int)$item['product_id'], (string)$head['delivery_date'], 0, (int)$item['quantity'], $actorId);
        if (!empty($state['skipped'])) throw new RuntimeException('Resolved origin is not an active commissary.');
        $after = dl_commissaryLedgerSnapshot($db, $originId, (int)$item['product_id'], (string)$head['delivery_date']);
        $insert->execute([
            ':d' => $deliveryId, ':di' => (int)$item['id'], ':b' => $originId, ':p' => (int)$item['product_id'],
            ':dt' => (string)$head['delivery_date'], ':q' => (int)$item['quantity'], ':u' => $actorId ?: null,
            ':bd' => $before['dispatched_qty'], ':ad' => $after['dispatched_qty'], ':br' => $before['remaining_qty'], ':ar' => $after['remaining_qty'],
        ]);
        dl_auditLog('delivery_ledger_applied', $originId, 'dl_delivery_ledger_effects', (string)$db->lastInsertId(), $before,
            $after + ['delivery_id' => $deliveryId, 'product_id' => (int)$item['product_id'], 'quantity' => (int)$item['quantity']]);
        $applied++;
    }
    return ['status' => 'applied', 'applied' => $applied];
}

/**
 * Keep the durable per-item delivery effect in step with a quantity correction,
 * so a later void reverses the corrected quantity rather than the original. A
 * delivery item with no effect row (never posted through the effect path) is a
 * no-op: the caller has already moved the commissary ledger by the delta.
 */
function dl_resyncDeliveryLedgerEffectOnCorrection($db, int $deliveryItemId, int $newQty): void
{
    if ($deliveryItemId <= 0) {
        return;
    }
    $db->prepare(
        'UPDATE dl_delivery_ledger_effects SET quantity = :qty
          WHERE delivery_item_id = :id AND effect_status = "applied"'
    )->execute([':qty' => $newQty, ':id' => $deliveryItemId]);
}

/** Reverse only durable applied effects; legacy rows without one are explicitly reported. */
function dl_reversePostedDeliveryCommissaryLedger($db, int $deliveryId, int $actorId): array
{
    $effects = $db->prepare('SELECT * FROM dl_delivery_ledger_effects WHERE delivery_id = :id ORDER BY id FOR UPDATE');
    $effects->execute([':id' => $deliveryId]);
    $rows = $effects->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $reversed = 0;
    foreach ($rows as $effect) {
        if ((string)$effect['effect_status'] !== 'applied') continue;
        $before = dl_commissaryLedgerSnapshot($db, (int)$effect['commissary_branch_id'], (int)$effect['product_id'], (string)$effect['ledger_date']);
        dl_applyCommissaryProductLedgerDelta($db, (int)$effect['commissary_branch_id'], (int)$effect['product_id'], (string)$effect['ledger_date'], 0, -((int)$effect['quantity']), $actorId, 0, true);
        $after = dl_commissaryLedgerSnapshot($db, (int)$effect['commissary_branch_id'], (int)$effect['product_id'], (string)$effect['ledger_date']);
        $db->prepare('UPDATE dl_delivery_ledger_effects SET effect_status = "reversed", reversed_by = :u, reversed_at = NOW(), reverse_before_dispatched_qty = :bd, reverse_after_dispatched_qty = :ad, reverse_before_remaining_qty = :br, reverse_after_remaining_qty = :ar WHERE id = :id AND effect_status = "applied"')
            ->execute([':u' => $actorId ?: null, ':bd' => $before['dispatched_qty'], ':ad' => $after['dispatched_qty'], ':br' => $before['remaining_qty'], ':ar' => $after['remaining_qty'], ':id' => (int)$effect['id']]);
        dl_auditLog('delivery_ledger_reversed', (int)$effect['commissary_branch_id'], 'dl_delivery_ledger_effects', (string)$effect['id'], $before,
            $after + ['delivery_id' => $deliveryId, 'product_id' => (int)$effect['product_id'], 'quantity' => (int)$effect['quantity']]);
        $reversed++;
    }
    return ['status' => $rows === [] ? 'legacy_no_effect' : 'reversed', 'reversed' => $reversed];
}

function dl_saveCommissaryBeginningQty(
    $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    int $begQty,
    int $actorId,
    ?string $shift = null
): array {
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    if ($commissaryBranchId <= 0 || $productId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)
        || $begQty < -999999999 || $begQty > 999999999) {
        throw new \RuntimeException('Invalid production beginning data.');
    }
    if ($shift !== null) dl_assertShiftMutable($db, $commissaryBranchId, $ledgerDate, $shift);
    dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $ledgerDate, $actorId, $shift);
    $stmt = $db->prepare(
        'UPDATE dl_commissary_product_ledger
            SET beg_qty = :beg, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift'
    );
    $stmt->bindValue(':beg', $begQty, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $actorId > 0 ? $actorId : null, $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':cb', $commissaryBranchId, PDO::PARAM_INT);
    $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':d', $ledgerDate);
    $stmt->bindValue(':shift', $shift);
    $stmt->execute();

    $read = $db->prepare(
        'SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, remaining_qty,
                actual_end_qty, calc_variance,
                (beg_qty + produced_qty - dispatched_qty - wastage_qty) AS book_balance
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift LIMIT 1'
    );
    $read->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    return $read->fetch(PDO::FETCH_ASSOC) ?: [];
}

function dl_saveCommissaryActualEndQty(
    $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    ?int $actualEndQty,
    int $actorId,
    ?string $shift = null
): array {
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    if ($commissaryBranchId <= 0 || $productId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)) {
        throw new \RuntimeException('Invalid production count data.');
    }
    if ($actualEndQty !== null && ($actualEndQty < 0 || $actualEndQty > 999999999)) {
        throw new \RuntimeException('Production count is out of bounds.');
    }

    // The full D6 sheet includes dead-stock products with no movement row yet.
    // Create the same carried row that movement writes create before storing the count.
    if ($shift !== null) dl_assertShiftMutable($db, $commissaryBranchId, $ledgerDate, $shift);
    dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $ledgerDate, $actorId, $shift);

    $stmt = $db->prepare(
        'UPDATE dl_commissary_product_ledger
            SET actual_end_qty = :actual, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift'
    );
    $stmt->bindValue(':actual', $actualEndQty, $actualEndQty === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':uid', $actorId > 0 ? $actorId : null, $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':cb', $commissaryBranchId, PDO::PARAM_INT);
    $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':d', $ledgerDate);
    $stmt->bindValue(':shift', $shift);
    $stmt->execute();

    $read = $db->prepare(
        'SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, remaining_qty,
                actual_end_qty, calc_variance
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift
          LIMIT 1'
    );
    $read->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new \RuntimeException('Production ledger row not found.');
    }
    return $row;
}

/**
 * S7b: record one auditable change to the day's production ledger.
 *
 * S7b replaces S7 §3b's reverse-movement correction with the owner's simpler
 * model: an edit is a logged change, not a new operation. The appended audit
 * record is the change log; the prior record is never erased. `reason` is
 * stored inside new_data because audit_logs has no reason column.
 */
function dl_auditProductionLedgerChange(
    $db,
    int $commissaryBranchId,
    int $productId,
    string $ledgerDate,
    string $field,
    $before,
    $after,
    ?string $reason,
    string $source,
    ?int $movementId,
    string $submissionId
): void {
    $name = '';
    try {
        $nameStmt = $db->prepare('SELECT name FROM dl_products WHERE id = :pid LIMIT 1');
        $nameStmt->execute([':pid' => $productId]);
        $name = (string)($nameStmt->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        $name = '';
    }
    dl_auditLog(
        'production_ledger_change',
        $commissaryBranchId,
        'dl_commissary_product_ledger',
        "{$commissaryBranchId}-{$productId}-{$ledgerDate}",
        ['field' => $field, 'value' => $before],
        [
            'field' => $field,
            'value' => $after,
            'reason' => $reason,
            'source' => $source,
            'movement_id' => $movementId,
            'submission_id' => $submissionId,
            'commissary_branch_id' => $commissaryBranchId,
            'product_id' => $productId,
            'product_name' => $name,
            'ledger_date' => $ledgerDate,
        ]
    );
}

/**
 * Capture the day's produced total on dl_production_runs, preserving the S5
 * yield-profile capture while ADDTL entry moves to the S7b modal. Additive
 * additions raise the run's yield_qty to the resulting day total.
 */
function dl_upsertProductionRunYield($db, string $ledgerDate, int $productId, int $commissaryBranchId, int $yieldQty, int $actorId, ?string $shift = null): void
{
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $runStmt = $db->prepare(
        'SELECT id, baker_name, primary_input_qty
           FROM dl_production_runs
          WHERE ledger_date = :d AND product_id = :pid AND destination_branch_id = :bid AND shift <=> :shift
          ORDER BY id DESC LIMIT 1 FOR UPDATE'
    );
    $runStmt->execute([':d' => $ledgerDate, ':pid' => $productId, ':bid' => $commissaryBranchId, ':shift' => $shift]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($run) {
        if ($yieldQty === 0
            && trim((string)$run['baker_name']) === ''
            && (float)$run['primary_input_qty'] <= 0) {
            $db->prepare('DELETE FROM dl_production_runs WHERE id = :id')
                ->execute([':id' => (int)$run['id']]);
            return;
        }
        $db->prepare('UPDATE dl_production_runs SET yield_qty = :qty, recorded_by = :uid WHERE id = :id')
            ->execute([':qty' => $yieldQty, ':uid' => $actorId > 0 ? $actorId : null, ':id' => (int)$run['id']]);
        return;
    }

    if ($yieldQty > 0) {
        $db->prepare(
            "INSERT INTO dl_production_runs
                (ledger_date, shift, product_id, baker_name, run_type, primary_input_qty,
                 primary_input_type, yield_qty, destination_branch_id, recorded_by)
             VALUES (:d, :shift, :pid, '', 'regular', 0, 'kilo', :qty, :bid, :uid)"
        )->execute([
            ':d' => $ledgerDate,
            ':shift' => $shift,
            ':pid' => $productId,
            ':qty' => $yieldQty,
            ':bid' => $commissaryBranchId,
            ':uid' => $actorId > 0 ? $actorId : null,
        ]);
    }
}

/**
 * S7b: one addition recorded through the simple modal.
 *
 * Additive by design ("records an addition"): each submission adds quantity to
 * the day's produced_qty. Identity is the submission, mirroring
 * dl_withdrawalSubmissionId() — a replay of the same submission is refused by
 * the movement's client_op_id guard, and two submissions with different ids
 * create two additions. An absent id is minted here and never falls back to a
 * content hash.
 */
function dl_recordProductionAddition(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $db = $ctx->db();
    $date = (string)($input['date'] ?? '');
    $commissaryBranchId = (int)($input['commissary_branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? -1);
    $role = (string)($user['role'] ?? '');
    $shift = $role === 'production_in_charge'
        ? dl_resolveLedgerShift($user, $input)['shift']
        : (isset($input['shift']) ? dl_normalizeShift((string)$input['shift']) : null);
    $reason = trim((string)($input['reason'] ?? ''));
    $submissionId = dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''));
    $actorId = dl_getActorUserId($user);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || $commissaryBranchId <= 0
        || $productId <= 0
        || $quantity <= 0
        || $quantity > 999999999) {
        throw new \RuntimeException('Invalid production addition data.');
    }
    if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.');
    }

    $valid = $db->prepare(
        'SELECT COUNT(*)
           FROM dl_branches b
           INNER JOIN dl_branch_products bp
             ON bp.branch_id = b.id AND bp.product_id = :pid AND bp.is_active = 1
           INNER JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
          WHERE b.id = :bid AND b.is_active = 1 AND b.is_commissary = 1'
    );
    $valid->execute([':pid' => $productId, ':bid' => $commissaryBranchId]);
    if ((int)$valid->fetchColumn() !== 1) {
        throw new \RuntimeException('Product is not active for this commissary.');
    }

    $db->beginTransaction();
    try {
        if ($shift !== null) {
            $shiftStatus = dl_lockShiftStatusRow($db, $commissaryBranchId, $date, $shift);
            if ((string)$shiftStatus['status'] === 'finalized') {
                throw new \RuntimeException('This shift is finalized and locked. Reopen the shift before editing.', 403);
            }
        }
        dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $actorId, $shift);
        $beforeStmt = $db->prepare(
            'SELECT produced_qty FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1 FOR UPDATE'
        );
        $beforeStmt->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $beforeProduced = (int)$beforeStmt->fetchColumn();

        $movement = dl_processProductionMovement($user, 'output', [
            'destination_branch_id' => $commissaryBranchId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'ledger_date' => $date,
            'shift' => $shift,
            'flow_mode' => 'production',
            'client_op_id' => 'sheet-addtl-' . hash('sha256', $submissionId),
            'reason' => $reason !== '' ? $reason : 'Daily Sheet ADDTL addition',
            'submission_id' => $submissionId,
            'before_produced' => $beforeProduced,
            'after_produced' => $beforeProduced + $quantity,
        ]);

        if (!empty($movement['duplicate'])) {
            $db->commit();
            return [
                'row' => dl_readCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $shift),
                'duplicate' => true,
                'submission_id' => $submissionId,
                'movement_id' => (int)($movement['movement_id'] ?? 0),
            ];
        }

        dl_upsertProductionRunYield($db, $date, $productId, $commissaryBranchId, $beforeProduced + $quantity, $actorId, $shift);
        $row = dl_readCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $shift);
        $db->commit();
        return [
            'row' => $row,
            'duplicate' => false,
            'submission_id' => $submissionId,
            'movement_id' => (int)($movement['movement_id'] ?? 0),
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * S7b: apply an edit to a recorded entry and append a new audit record.
 * No reverse movement — the stored field moves before → after and is logged.
 * production.override and a reason are required (S7b §3 invariants).
 */
function dl_changeProductionLedgerField(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $role = (string)($user['role'] ?? '');
    if (!dl_roleHasPermission($role, 'production.override')) {
        throw new \RuntimeException('production.override permission is required.');
    }
    $db = $ctx->db();
    $field = (string)($input['field'] ?? '');
    $date = (string)($input['date'] ?? '');
    $commissaryBranchId = (int)($input['commissary_branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    $submissionId = dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''));
    $actorId = dl_getActorUserId($user);

    $columns = ['beg_qty' => 'beg_qty', 'produced_qty' => 'produced_qty', 'actual_end_qty' => 'actual_end_qty'];
    if (!isset($columns[$field])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || $commissaryBranchId <= 0 || $productId <= 0) {
        throw new \RuntimeException('Invalid production ledger edit data.');
    }
    if ($reason === '') {
        throw new \RuntimeException('A reason is required to edit a recorded entry.');
    }
    if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.');
    }

    $rawValue = $input['value'] ?? null;
    $newValue = ($rawValue === null || $rawValue === '') ? null : filter_var($rawValue, FILTER_VALIDATE_INT);
    if ($newValue === false) {
        throw new \RuntimeException('The corrected value is not a whole number.');
    }
    if ($field !== 'actual_end_qty' && $newValue === null) {
        throw new \RuntimeException('A corrected value is required.');
    }
    if ($newValue !== null && ($newValue < ($field === 'beg_qty' ? -999999999 : 0) || $newValue > 999999999)) {
        throw new \RuntimeException('Corrected value is out of bounds.');
    }

    $db->beginTransaction();
    try {
        dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $actorId);
        $beforeStmt = $db->prepare(
            'SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty
               FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d LIMIT 1 FOR UPDATE'
        );
        $beforeStmt->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $date]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$before) {
            throw new \RuntimeException('Unable to open the production ledger row.');
        }
        $oldValue = $before[$field] === null ? null : (int)$before[$field];

        if ((string)$oldValue !== (string)$newValue) {
            $update = $db->prepare(
                "UPDATE dl_commissary_product_ledger SET {$columns[$field]} = :value,
                        updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                  WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d"
            );
            $update->bindValue(':value', $newValue, $newValue === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $update->bindValue(':uid', $actorId > 0 ? $actorId : null, $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $update->bindValue(':cb', $commissaryBranchId, PDO::PARAM_INT);
            $update->bindValue(':pid', $productId, PDO::PARAM_INT);
            $update->bindValue(':d', $date);
            $update->execute();

            if ($field === 'produced_qty') {
                dl_upsertProductionRunYield($db, $date, $productId, $commissaryBranchId, (int)($newValue ?? 0), $actorId);
            }
            dl_auditProductionLedgerChange(
                $db, $commissaryBranchId, $productId, $date, $field,
                $oldValue, $newValue, $reason, 'edit', null, $submissionId
            );
        }

        $row = dl_readCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date);
        $db->commit();
        return ['row' => $row, 'changed' => (string)$oldValue !== (string)$newValue, 'submission_id' => $submissionId];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * S10: Type vocabulary for a Daily Sheet branch-cell correction. These values and
 * labels are copied from the cashier withdrawal modal
 * (templates/modules/daily-ledger/cashier/modal_patch.disyl) so the sheet does not
 * grow a parallel correction vocabulary. `correction` is the owner's "error in
 * entry"; its label is the contract's exact "Correction — wrong entry".
 */
function dl_dailySheetAdjustmentTypes(): array
{
    return [
        'charge' => 'Charge',
        'pullout' => 'Pullout',
        'used' => 'Used (for a product/order)',
        'correction' => 'Correction — wrong entry',
        'adjustment_add' => 'Add Stock (missed entry / variance)',
        'correction_addtl' => 'Correction — Additional (wrong entry)',
    ];
}

/** Reason codes, verbatim from the cashier modal. `encoder_omission` = owner's "encoder omission". */
function dl_dailySheetAdjustmentReasons(): array
{
    return [
        'manual_adjustment' => 'Manual adjustment',
        'encoder_omission' => 'Encoder omission (nothing lost)',
        'spoilage' => 'Spoilage',
        'staff_meal' => 'Staff meal',
        'sampling' => 'Sampling',
        'testing' => 'Testing',
        'promo' => 'Promo',
        'donation' => 'Donation',
        'damage' => 'Damage',
        'other' => 'Other',
    ];
}

/** Whether a (commissary, product, branch, date) cell already carries a recorded delivery. */
function dl_dailySheetCellHasEntry($db, string $ledgerDate, int $commissaryBranchId, int $productId, int $branchId, ?string $shift = null): bool
{
    if ($commissaryBranchId <= 0 || $productId <= 0 || $branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)) {
        return false;
    }
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
          WHERE d.delivery_date = :d
            AND (:shift_filter IS NULL OR d.production_shift = :shift)
            AND d.origin_type = 'commissary'
            AND d.destination_type = 'branch'
            AND d.destination_id = :bid
            AND d.origin_id = :cid
            AND d.status <> 'voided'
            AND di.product_id = :pid"
    );
    $stmt->execute([':d' => $ledgerDate, ':shift_filter' => $shift, ':shift' => $shift, ':bid' => $branchId, ':cid' => $commissaryBranchId, ':pid' => $productId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** The delivery-backed quantity currently shown in one branch cell. */
function dl_dailySheetCellQuantity($db, string $ledgerDate, int $commissaryBranchId, int $productId, int $branchId, ?string $shift = null): int
{
    if ($commissaryBranchId <= 0 || $productId <= 0 || $branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)) {
        return 0;
    }
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(di.quantity), 0)
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
          WHERE d.delivery_date = :d
            AND (:shift_filter IS NULL OR d.production_shift = :shift)
            AND d.origin_type = 'commissary'
            AND d.destination_type = 'branch'
            AND d.destination_id = :bid
            AND d.origin_id = :cid
            AND d.status <> 'voided'
            AND di.product_id = :pid"
    );
    $stmt->execute([':d' => $ledgerDate, ':shift_filter' => $shift, ':shift' => $shift, ':bid' => $branchId, ':cid' => $commissaryBranchId, ':pid' => $productId]);
    return (int)$stmt->fetchColumn();
}

/**
 * S10: record one Daily Sheet branch-cell entry as a real delivery.
 *
 * A first entry is a plain signed quantity and mints exactly one posted
 * commissary -> branch delivery plus its item. `origin_id` is always written: the
 * sheet's matrix filters on it, and a NULL origin_id blanks every cell whenever a
 * commissary is selected. A later change is an append-only correction: it mints
 * ANOTHER delivery carrying the signed delta, requires Type + Reason Code copied
 * from the cashier modal, and is logged so the sheet's bottom log reads
 * original entry -> correction. Nothing already recorded is updated or deleted.
 *
 * A correction is stored with receipt_required = 0: it is not something a cashier
 * receives, so it never appears in the receive surface and can never block the
 * cashier on an unsatisfiable count range. Where it moves the cell's effective
 * quantity away from what the cashier counted, it raises the same unreviewed
 * delivery variance a paper-DR correction raises.
 */
function dl_recordDailySheetBranchEntry(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $db = $ctx->db();
    $date = (string)($input['date'] ?? $input['ledger_date'] ?? '');
    $shift = isset($input['shift']) ? dl_normalizeShift((string)$input['shift']) : null;

    // The daily-sheet modal posts the dispatch shape (items[]), but accept a flat
    // shape too so the core is testable without wrapping it.
    $item = [];
    if (isset($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $candidate) {
            if (is_array($candidate)) {
                $item = $candidate;
                break;
            }
        }
    }
    $commissaryBranchId = (int)($input['commissary_branch_id'] ?? $item['commissary_branch_id'] ?? 0);
    $branchId = (int)($input['destination_branch_id'] ?? $input['branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? $item['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? $item['quantity'] ?? 0);
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $submissionId = dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''));
    $actorId = dl_getActorUserId($user);
    $actorName = (string)($user['full_name'] ?? $user['name'] ?? $user['username'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || $commissaryBranchId <= 0
        || $branchId <= 0
        || $productId <= 0
        || $quantity === 0
        || $quantity < -999999999
        || $quantity > 999999999) {
        throw new \RuntimeException('A valid date, commissary, branch, product and a non-zero whole quantity are required.');
    }
    if ($commissaryBranchId === $branchId) {
        throw new \RuntimeException('A commissary cannot deliver to itself.');
    }
    if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.');
    }

    $commStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1');
    $commStmt->execute([':id' => $commissaryBranchId]);
    $commissary = $commStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$commissary) {
        throw new \RuntimeException('The selected source is not an active commissary.');
    }
    $destStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1');
    $destStmt->execute([':id' => $branchId]);
    $destination = $destStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$destination) {
        throw new \RuntimeException('The selected destination branch is not active.');
    }

    $validProduct = $db->prepare(
        'SELECT p.id, p.name
           FROM dl_branches b
           INNER JOIN dl_branch_products bp ON bp.branch_id = b.id AND bp.product_id = :pid AND bp.is_active = 1
           INNER JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
          WHERE b.id = :bid AND b.is_active = 1 AND b.is_commissary = 1
          LIMIT 1'
    );
    $validProduct->execute([':pid' => $productId, ':bid' => $commissaryBranchId]);
    $product = $validProduct->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$product) {
        throw new \RuntimeException('Product is not active for this commissary.');
    }

    $hasEntry = dl_dailySheetCellHasEntry($db, $date, $commissaryBranchId, $productId, $branchId, $shift);
    $type = '';
    $reasonCode = '';
    $customReason = '';
    $liableId = null;
    if ($hasEntry) {
        $type = trim((string)($input['type'] ?? $input['withdrawal_type'] ?? ''));
        $reasonCode = trim((string)($input['reason_code'] ?? ''));
        $customReason = trim((string)($input['custom_reason'] ?? ''));
        $types = dl_dailySheetAdjustmentTypes();
        $reasons = dl_dailySheetAdjustmentReasons();
        if ($type === '' || !isset($types[$type])) {
            throw new \RuntimeException('Type is required to change a recorded entry.');
        }
        if ($reasonCode === '' || !isset($reasons[$reasonCode])) {
            throw new \RuntimeException('Reason code is required to change a recorded entry.');
        }
        if ($reasonCode === 'other' && $customReason === '') {
            throw new \RuntimeException('A custom reason is required when Reason is Other.');
        }
        if ($reasonCode !== 'other') {
            $customReason = '';
        }
        // Mirror the cashier liability rules exactly: an encoder omission lost
        // nothing, so nobody is recorded or charged; other Add Stock does name a
        // liable person. Every other type records what it records and charges nobody
        // by default, so its charge-to stays optional.
        if ($reasonCode === 'encoder_omission') {
            $liableId = null;
        } else {
            $liableId = (int)($input['liable_user_id'] ?? 0);
            if ($liableId <= 0) {
                $liableId = null;
            }
            if ($type === 'adjustment_add' && $liableId === null) {
                throw new \RuntimeException('Charge to (liable person) is required for Add Stock that resolves a shortage. Use the Encoder omission reason if nothing was lost.');
            }
        }
    }

    // Submission identity, not content: a replay of the same submission is
    // returned as a duplicate rather than written twice.
    if ($submissionId !== '') {
        // The kernel's audit encoder writes JSON with a space after the colon
        // (`"submission_id": "…"`), so match both the spaced and compact forms
        // rather than binding the serialization format.
        $dupStmt = $db->prepare(
            "SELECT id FROM audit_logs
              WHERE module = 'daily-ledger' AND action = 'production_ledger_change'
                AND branch_id = :cb
                AND (new_data LIKE :needle_tight OR new_data LIKE :needle_spaced)
              ORDER BY id DESC LIMIT 1"
        );
        $dupStmt->execute([
            ':cb' => $commissaryBranchId,
            ':needle_tight' => '%"submission_id":"' . $submissionId . '"%',
            ':needle_spaced' => '%"submission_id": "' . $submissionId . '"%',
        ]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            return ['duplicate' => true, 'submission_id' => $submissionId, 'has_previous_entry' => $hasEntry];
        }
    }

    $priceGroupId = dl_defaultPriceGroupId();

    $db->beginTransaction();
    try {
        // Serialize shift-keyed writes with finalization, exactly as the cashier ledger does.
        if ($shift !== null) {
            $shiftStatus = dl_lockShiftStatusRow($db, $commissaryBranchId, $date, $shift);
            if ((string)$shiftStatus['status'] === 'finalized') {
                throw new \RuntimeException('This shift is finalized and locked. Reopen the shift before editing.', 403);
            }
        }
        // Keep the commissary finished-goods position in step with the delivery, and
        // guarantee the ledger row exists before a negative correction is applied.
        dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $actorId, $shift);
        // Read the already-recorded cell quantity under the transaction and the
        // ledger-row lock, then refuse a correction that would drive the cell
        // below zero. The old behaviour clamped the stored dispatched_qty while
        // the screen showed a negative figure, so the two never agreed.
        $db->prepare(
            'SELECT id FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift = :shift
              FOR UPDATE'
        )->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $before = dl_dailySheetCellQuantity($db, $date, $commissaryBranchId, $productId, $branchId, $shift);
        if ($before + $quantity < 0) {
            throw new \RuntimeException(
                'Cannot reduce the branch cell below zero: this product has ' . $before
                . ' recorded for this date and branch.',
                422
            );
        }
        $after = $before + $quantity;

        $remarks = $hasEntry
            ? '[daily-sheet-entry] correction ' . $type . ':' . $reasonCode
            : '[daily-sheet-entry] new';
        $delStmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, production_shift, status, created_by, posted_by, posted_at, remarks, receipt_required)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, :production_shift, "posted", :created_by, :posted_by, NOW(), :remarks, :receipt_required)'
        );
        $delStmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $commissaryBranchId,
            ':destination_type' => 'branch',
            ':destination_id' => $branchId,
            ':dr_number' => $drNumber !== '' ? $drNumber : null,
            ':delivery_date' => $date,
            ':production_shift' => $shift,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => $remarks,
            // A correction never presents as awaiting receipt. A first (dispatch)
            // entry is explicitly receivable. NULL stays historical/unknown.
            ':receipt_required' => $hasEntry ? 0 : 1,
        ]);
        $deliveryId = (int)$db->lastInsertId();

        $itemStmt = $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        );
        $itemStmt->execute([
            ':delivery_id' => $deliveryId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':unit' => 'pcs',
            ':unit_cost_snapshot' => 0,
            ':price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $date),
            ':price_group_id' => $priceGroupId,
            ':remarks' => $hasEntry ? 'daily_sheet_correction' : 'daily_sheet_entry',
        ]);
        $itemId = (int)$db->lastInsertId();

        // Debit (positive) or credit back (negative) the commissary dispatched qty.
        dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $productId, $date, 0, $quantity, $actorId, 0, true, $shift);

        $reasonDisplay = $hasEntry
            ? ($reasonCode === 'other' ? $customReason : $reasonCode)
            : 'new entry';
        dl_auditLog(
            'production_ledger_change',
            $commissaryBranchId,
            'dl_deliveries',
            "{$commissaryBranchId}-{$productId}-{$date}",
            ['field' => 'branch_qty', 'value' => $before],
            [
                'field' => 'branch_qty',
                'value' => $after,
                'quantity' => $quantity,
                'signed_display' => ($quantity > 0 ? '+' : '') . $quantity,
                'type' => $hasEntry ? $type : null,
                'reason_code' => $hasEntry ? $reasonCode : null,
                'reason' => $reasonDisplay,
                'custom_reason' => $customReason,
                'liable_user_id' => $liableId,
                'liable_name' => $liableId !== null ? (string)($db->query('SELECT COALESCE(full_name, username, \'\') FROM dl_users WHERE id = ' . (int)$liableId)->fetchColumn() ?: '') : '',
                'branch_id' => $branchId,
                'branch_name' => (string)($destination['name'] ?? ''),
                'commissary_branch_id' => $commissaryBranchId,
                'product_id' => $productId,
                'product_name' => (string)($product['name'] ?? ''),
                'ledger_date' => $date,
                'delivery_id' => $deliveryId,
                'item_id' => $itemId,
                'submission_id' => $submissionId,
                'actor_name' => $actorName,
            ],
            $reasonDisplay
        );

        // A correction is not something the cashier receives. Where it moves the
        // cell's effective quantity away from what the cashier physically counted,
        // raise the same sent-vs-received variance a paper-DR correction raises.
        dl_raiseDailySheetCorrectionVariance($db, $commissaryBranchId, $branchId, $productId, $date, $actorId);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'delivery_id' => $deliveryId,
        'item_id' => $itemId,
        'quantity' => $quantity,
        'cell_quantity' => $after,
        'has_previous_entry' => $hasEntry,
        'submission_id' => $submissionId,
        'duplicate' => false,
    ];
}

/** Read the day's ledger row (with computed book balance) for a product. */
function dl_readCommissaryProductLedgerRow($db, int $commissaryBranchId, int $productId, string $ledgerDate, ?string $shift = null): array
{
    $shift = $shift === null ? null : dl_normalizeShift($shift);
    $read = $db->prepare(
        'SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, remaining_qty,
                actual_end_qty, calc_variance,
                (beg_qty + produced_qty - dispatched_qty - wastage_qty) AS book_balance
           FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift
          LIMIT 1'
    );
    $read->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    return $read->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * S7b: the bottom log for one commissary-day.
 *
 * Operations (additions) come from dl_production_movements — reason from
 * override_reason, actor from created_by_id, before/after from source_payload.
 * Field changes (BEG, ADDTL edits, ACTUAL BAL) come from dl_auditLog(). The two
 * are merged by time so the log accumulates rather than overwrites.
 */
function dl_fetchProductionLedgerLog($db, int $commissaryBranchId, string $ledgerDate): array
{
    if ($commissaryBranchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)) {
        return [];
    }
    $log = [];

    $movementStmt = $db->prepare(
        "SELECT pm.id, pm.created_at, pm.movement_type, pm.quantity, pm.override_reason,
                pm.created_by_id, pm.source_payload, pm.product_id, p.name AS product_name,
                COALESCE(u.full_name, u.username, '') AS actor_name
           FROM dl_production_movements pm
           INNER JOIN dl_products p ON p.id = pm.product_id
           LEFT JOIN dl_users u ON u.id = pm.created_by_id
          WHERE pm.destination_branch_id = :cb AND pm.ledger_date = :d
          ORDER BY pm.id ASC"
    );
    $movementStmt->execute([':cb' => $commissaryBranchId, ':d' => $ledgerDate]);
    foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $movement) {
        $payload = json_decode((string)($movement['source_payload'] ?? ''), true) ?: [];
        $before = array_key_exists('before_produced', $payload) ? (int)$payload['before_produced'] : null;
        $after = array_key_exists('after_produced', $payload) ? (int)$payload['after_produced'] : null;
        $createdAt = (string)$movement['created_at'];
        $log[] = [
            'id' => 'movement-' . (int)$movement['id'],
            'when' => $createdAt,
            'who' => (string)$movement['actor_name'],
            'product_id' => (int)$movement['product_id'],
            'product_name' => (string)$movement['product_name'],
            'field' => 'ADDTL',
            'field_key' => 'produced_qty',
            'before' => $before,
            'after' => $after,
            'before_display' => $before === null ? '—' : (string)$before,
            'after_display' => $after === null ? '—' : (string)$after,
            'quantity' => (int)$movement['quantity'],
            'reason' => (string)($movement['override_reason'] ?? ''),
            'reason_code' => '',
            'custom_reason' => '',
            'branch_id' => null,
            'branch_name' => '',
            'adjustment_type' => '',
            'signed_qty' => null,
            'signed_display' => '',
            'liable_name' => '',
            'source' => 'movement',
            'sort' => [(int)strtotime($createdAt), 0, (int)$movement['id']],
        ];
    }

    $auditStmt = $db->prepare(
        "SELECT al.id, al.created_at, al.actor_module_user_id, al.actor_user_id,
                al.old_data, al.new_data, al.entity_id,
                COALESCE(u.full_name, u.username, '') AS actor_name
           FROM audit_logs al
           LEFT JOIN dl_users u ON u.id = COALESCE(al.actor_module_user_id, al.actor_user_id)
          WHERE al.module = 'daily-ledger' AND al.action = 'production_ledger_change'
            AND al.branch_id = :cb AND al.entity_id LIKE :pattern
          ORDER BY al.id ASC"
    );
    $auditStmt->execute([':cb' => $commissaryBranchId, ':pattern' => $commissaryBranchId . '-%-' . $ledgerDate]);
    $fieldLabels = ['beg_qty' => 'BEG', 'produced_qty' => 'ADDTL', 'actual_end_qty' => 'ACTUAL BAL', 'branch_qty' => 'BRANCH'];
    // A Daily Sheet (S10) entry already renders as its own BRANCH row and owns the
    // delivery it wrote; collect those delivery ids so the send/receive pass below
    // does not list the same event twice.
    $coveredDeliveryIds = [];
    foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $change) {
        $old = json_decode((string)($change['old_data'] ?? ''), true) ?: [];
        $new = json_decode((string)($change['new_data'] ?? ''), true) ?: [];
        $fieldKey = (string)($new['field'] ?? '');
        $createdAt = (string)$change['created_at'];
        $isBranchEntry = $fieldKey === 'branch_qty';
        if ($isBranchEntry && !empty($new['delivery_id'])) {
            $coveredDeliveryIds[(int)$new['delivery_id']] = true;
        }
        // The sheet's own audit writes the actor name into new_data because the
        // CLI/integration harness may have no request user; fall back to it so the
        // correction is attributed either way.
        $actorName = (string)$change['actor_name'];
        if ($actorName === '') {
            $actorName = (string)($new['actor_name'] ?? '');
        }
        $log[] = [
            'id' => 'audit-' . (int)$change['id'],
            'when' => $createdAt,
            'who' => $actorName,
            'product_id' => (int)($new['product_id'] ?? 0),
            'product_name' => (string)($new['product_name'] ?? ('#' . (int)($new['product_id'] ?? 0))),
            'field' => $fieldLabels[$fieldKey] ?? strtoupper($fieldKey),
            'field_key' => $fieldKey,
            'before' => $old['value'] ?? null,
            'after' => $new['value'] ?? null,
            'before_display' => ($old['value'] ?? null) === null ? '—' : (string)$old['value'],
            'after_display' => ($new['value'] ?? null) === null ? '—' : (string)$new['value'],
            'quantity' => $isBranchEntry ? (int)($new['quantity'] ?? 0) : null,
            'reason' => (string)($new['reason'] ?? ''),
            'reason_code' => $isBranchEntry ? (string)($new['reason_code'] ?? '') : '',
            'custom_reason' => $isBranchEntry ? (string)($new['custom_reason'] ?? '') : '',
            'branch_id' => $isBranchEntry ? (int)($new['branch_id'] ?? 0) : null,
            'branch_name' => $isBranchEntry ? (string)($new['branch_name'] ?? '') : '',
            'adjustment_type' => $isBranchEntry ? (string)($new['type'] ?? '') : '',
            'signed_qty' => $isBranchEntry ? (int)($new['quantity'] ?? 0) : null,
            'signed_display' => $isBranchEntry ? (string)($new['signed_display'] ?? '') : '',
            'liable_name' => $isBranchEntry ? (string)($new['liable_name'] ?? '') : '',
            'source' => $isBranchEntry ? 'branch_entry' : 'edit',
            'sort' => [(int)strtotime($createdAt), 1, (int)$change['id']],
        ];
    }

    // ── S15: the day's dispatches and receipts ──────────────────────────────
    // audit_logs has create_delivery / create_receiving rows for only a handful of
    // the real events (3 of 118 / 112 on this tenant), so the audit query above can
    // never carry this history and must not be widened for it. The delivery and
    // receiving tables are attributed for every row, so read those directly. Rows
    // already covered by an existing family (the S10 branch entry) are skipped.
    $sentStmt = $db->prepare(
        "SELECT d.id AS delivery_id, d.dr_number, d.destination_id AS branch_id,
                COALESCE(b.name, '') AS branch_name,
                d.created_by, d.posted_by, d.produced_by, d.produced_at, d.remarks,
                d.origin_id, d.resolved_origin_id,
                COALESCE(d.posted_at, d.created_at) AS sent_at,
                COALESCE(u.full_name, u.username, '') AS actor_name,
                COALESCE(pu.full_name, pu.username, '') AS producer_name,
                COALESCE(SUM(di.quantity), 0) AS quantity
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
           LEFT JOIN dl_branches b ON b.id = d.destination_id
           LEFT JOIN dl_users u ON u.id = COALESCE(d.posted_by, d.created_by)
           LEFT JOIN dl_users pu ON pu.id = d.produced_by
          WHERE d.delivery_date = :sent_date
            AND d.origin_type = 'commissary'
            AND d.destination_type = 'branch'
            AND d.status = 'posted'
            AND (COALESCE(d.resolved_origin_id, d.origin_id) = :sent_cb
                 OR (d.origin_id IS NULL AND d.resolved_origin_id IS NULL))
          GROUP BY d.id, d.dr_number, d.destination_id, b.name,
                   d.created_by, d.posted_by, d.produced_by, d.produced_at, d.remarks,
                   d.origin_id, d.resolved_origin_id, d.posted_at, d.created_at,
                   u.full_name, u.username, pu.full_name, pu.username
          ORDER BY d.id ASC"
    );
    $sentStmt->execute([':sent_date' => $ledgerDate, ':sent_cb' => $commissaryBranchId]);
    foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sent) {
        $deliveryId = (int)$sent['delivery_id'];
        if (isset($coveredDeliveryIds[$deliveryId])) {
            continue;
        }
        $sentAt = (string)$sent['sent_at'];
        $sentQty = (int)$sent['quantity'];
        $dr = trim((string)($sent['dr_number'] ?? ''));
        // Delayed producer entry (066): the producer and the production moment are
        // shown when recorded, otherwise each field falls back independently to the
        // encoder / encoding moment. A NULL producer must reproduce the pre-066 log
        // exactly (the encoder suffix is only added once a producer exists).
        $producedBy = $sent['produced_by'] !== null ? (int)$sent['produced_by'] : null;
        $producedAt = $sent['produced_at'] !== null ? (string)$sent['produced_at'] : null;
        $producerName = trim((string)($sent['producer_name'] ?? ''));
        $encoderName = (string)$sent['actor_name'];
        $sentWho = ($producedBy !== null && $producerName !== '') ? $producerName : $encoderName;
        $sentWhen = ($producedAt !== null && $producedAt !== '') ? $producedAt : $sentAt;
        $sentReason = $dr !== '' ? 'DR ' . $dr : '';
        if ($sent['origin_id'] === null && $sent['resolved_origin_id'] === null) {
            $sentReason = trim($sentReason . ($sentReason !== '' ? ' ' : '') . '· origin unresolved');
        }
        if ($producedBy !== null && $encoderName !== '') {
            $sentReason = trim($sentReason . ($sentReason !== '' ? ' ' : '') . '· enc: ' . $encoderName);
        }
        $isPaperCapture = str_contains((string)($sent['remarks'] ?? ''), '[captured-from-paper-dr]');
        if ($isPaperCapture) {
            $sentReason = trim($sentReason . ($sentReason !== '' ? ' ' : '') . '· single paper capture');
        }
        $log[] = [
            'id' => 'sent-' . $deliveryId,
            'when' => $sentWhen,
            'who' => $sentWho,
            'product_id' => 0,
            'product_name' => '',
            'field' => 'SENT',
            'field_key' => 'sent',
            'before' => null,
            'after' => $sentQty,
            'before_display' => '—',
            'after_display' => (string)$sentQty,
            'quantity' => $sentQty,
            'reason' => $sentReason,
            'reason_code' => '',
            'custom_reason' => '',
            'branch_id' => (int)$sent['branch_id'],
            'branch_name' => (string)$sent['branch_name'],
            'adjustment_type' => '',
            'signed_qty' => null,
            'signed_display' => '',
            'liable_name' => '',
            'source' => 'send',
            'sort' => [(int)strtotime($sentWhen), 2, $deliveryId],
        ];
    }

    $receivedStmt = $db->prepare(
        "SELECT br.id AS receiving_id, br.branch_id,
                COALESCE(b.name, '') AS branch_name, br.dr_number,
                br.received_by, br.received_at,
                COALESCE(u.full_name, u.username, '') AS actor_name,
                rd.created_by AS delivery_created_by, rd.remarks AS delivery_remarks,
                br.count_basis,
                COALESCE(SUM(bri.quantity_received), 0) AS quantity
           FROM dl_branch_receivings br
           LEFT JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
           LEFT JOIN dl_branches b ON b.id = br.branch_id
           LEFT JOIN dl_users u ON u.id = br.received_by
           LEFT JOIN dl_deliveries rd ON rd.id = br.delivery_id
          WHERE br.received_ledger_date = :recv_date
            AND br.status = 'posted'
            AND (
                (br.origin_type = 'commissary' AND (br.origin_id = :recv_origin OR br.origin_id IS NULL))
                OR br.branch_id IN (SELECT id FROM dl_branches WHERE assigned_commissary_id = :recv_branch_cb)
                OR br.delivery_id IN (
                    SELECT d2.id FROM dl_deliveries d2
                     WHERE d2.origin_type = 'commissary'
                       AND d2.destination_type = 'branch'
                       AND d2.status = 'posted'
                       AND (d2.origin_id = :recv_deliv_cb OR d2.origin_id IS NULL)
                )
            )
          GROUP BY br.id, br.branch_id, b.name, br.dr_number,
                   br.received_by, br.received_at, u.full_name, u.username,
                   rd.created_by, rd.remarks, br.count_basis
          ORDER BY br.id ASC"
    );
    $receivedStmt->execute([
        ':recv_date' => $ledgerDate,
        ':recv_origin' => $commissaryBranchId,
        ':recv_branch_cb' => $commissaryBranchId,
        ':recv_deliv_cb' => $commissaryBranchId,
    ]);
    foreach ($receivedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $received) {
        $receivingId = (int)$received['receiving_id'];
        $receivedAt = (string)$received['received_at'];
        $receivedQty = (int)$received['quantity'];
        $dr = trim((string)($received['dr_number'] ?? ''));
        $receivedReason = $dr !== '' ? 'DR ' . $dr : '';
        if ((string)($received['count_basis'] ?? '') === 'copied') {
            $receivedReason = trim($receivedReason . ($receivedReason !== '' ? ' ' : '') . '· not independently counted');
        } elseif (($received['count_basis'] ?? null) === null) {
            $receivedReason = trim($receivedReason . ($receivedReason !== '' ? ' ' : '') . '· count basis unresolved');
        }
        $isSinglePaperCapture = str_contains((string)($received['delivery_remarks'] ?? ''), '[captured-from-paper-dr]')
            && (int)($received['delivery_created_by'] ?? 0) > 0
            && (int)($received['delivery_created_by'] ?? 0) === (int)($received['received_by'] ?? 0);
        if ($isSinglePaperCapture) {
            $receivedReason = trim($receivedReason . ($receivedReason !== '' ? ' ' : '') . '· single paper capture · same encoder');
        }
        $log[] = [
            'id' => 'received-' . $receivingId,
            'when' => $receivedAt,
            'who' => (string)$received['actor_name'],
            'product_id' => 0,
            'product_name' => '',
            'field' => 'RECEIVED',
            'field_key' => 'received',
            'before' => null,
            'after' => $receivedQty,
            'before_display' => '—',
            'after_display' => (string)$receivedQty,
            'quantity' => $receivedQty,
            'reason' => $receivedReason,
            'reason_code' => '',
            'custom_reason' => '',
            'branch_id' => (int)$received['branch_id'],
            'branch_name' => (string)$received['branch_name'],
            'adjustment_type' => '',
            'signed_qty' => null,
            'signed_display' => '',
            'liable_name' => '',
            'source' => 'receiving',
            'sort' => [(int)strtotime($receivedAt), 3, $receivingId],
        ];
    }

    // Compare [event timestamp, source rank, source id] lexicographically. Time
    // therefore dominates regardless of the unrelated id ranges used by each
    // source, while equal-second rows retain a deterministic reading order.
    usort($log, static function (array $a, array $b): int {
        return $a['sort'] <=> $b['sort'];
    });
    return $log;
}

function dl_processProductionMovement(array $user, string $movementType, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $allowedTypes = ['withdrawal', 'output', 'reverse'];
    if (!in_array($movementType, $allowedTypes, true)) {
        throw new \RuntimeException('Invalid movement type.');
    }

    $role = (string)($user['role'] ?? '');
    $actorId = dl_getActorUserId($user);
    if ($movementType === 'reverse' && !dl_roleHasPermission($role, 'production.override')) {
        throw new \RuntimeException('production.override permission is required.');
    }
    $flowMode = (string)($input['flow_mode'] ?? 'production');
    if (!in_array($flowMode, ['legacy', 'production'], true)) {
        $flowMode = 'production';
    }

    $clientOpId = trim((string)($input['client_op_id'] ?? ''));
    if ($clientOpId !== '') {
        $dupStmt = $ctx->db()->prepare('SELECT id, movement_uuid FROM dl_production_movements WHERE client_op_id = :coid LIMIT 1');
        $dupStmt->execute([':coid' => $clientOpId]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            return [
                'movement_id' => (int)$dup['id'],
                'movement_uuid' => (string)$dup['movement_uuid'],
                'duplicate' => true,
            ];
        }
    }

    $destinationBranchId = (int)($input['destination_branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);
    $ledgerDate = (string)($input['ledger_date'] ?? dl_businessDate());
    $productionShift = isset($input['shift']) ? dl_normalizeShift((string)$input['shift']) : null;
    $reason = trim((string)($input['reason'] ?? $input['override_reason'] ?? ''));
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    if ($drNumber !== '') {
        $drNumber = substr($drNumber, 0, 120);
    }

    if ($destinationBranchId <= 0 || $productId <= 0 || $quantity <= 0 || $ledgerDate === '') {
        throw new \RuntimeException('destination_branch_id, product_id, quantity, and ledger_date are required.');
    }

    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
        throw new \RuntimeException('Destination branch is not allowed for this user.');
    }

    $branchStmt = $ctx->db()->prepare('SELECT id, is_active FROM dl_branches WHERE id = :id LIMIT 1');
    $branchStmt->execute([':id' => $destinationBranchId]);
    $destinationBranch = $branchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$destinationBranch || (int)($destinationBranch['is_active'] ?? 0) !== 1) {
        throw new \RuntimeException('Destination branch no longer exists or is inactive. Refresh the page and choose a current branch.');
    }

    dl_maybeAutoCloseBranchDay($destinationBranchId, $actorId);

    $dayStatus = dl_getDayStatus($destinationBranchId, $ledgerDate);
    if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'production.override')) {
        throw new \RuntimeException('Day is closed for this branch.');
    }

    $referenceMovementId = null;
    $delta = $quantity;
    $formalDeliveryEnabled = dl_isFormalDeliveryEnabled();
    if ($formalDeliveryEnabled && $movementType === 'withdrawal' && $flowMode === 'production') {
        if ($drNumber === '') {
            throw new \RuntimeException('Delivery Receipt number is required for production withdrawal when formal delivery workflow is enabled.');
        }

        $deliveryStmt = $ctx->db()->prepare(
            'SELECT d.id
               FROM dl_deliveries d
               INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
              WHERE d.destination_type = :destination_type
                AND d.destination_id = :destination_id
                AND d.dr_number = :dr_number
                AND d.status <> "voided"
                AND di.product_id = :product_id
              ORDER BY d.id DESC
              LIMIT 1'
        );
        $deliveryStmt->execute([
            ':destination_type' => 'branch',
            ':destination_id' => $destinationBranchId,
            ':dr_number' => $drNumber,
            ':product_id' => $productId,
        ]);
        if (!$deliveryStmt->fetchColumn()) {
            throw new \RuntimeException('Production withdrawal requires a matching branch delivery for the same DR before the downstream step can be encoded.');
        }
    }

    // Resolve commissary for output movements — credit commissary finished-goods ledger
    $commissaryBranchId = null;
    $isCommissaryDirectOutput = false;
    if ($movementType === 'output') {
        $commCheckStmt = $ctx->db()->prepare(
            'SELECT id, is_commissary, default_supply_mode, assigned_commissary_id
               FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $commCheckStmt->execute([':id' => $destinationBranchId]);
        $destBranch = $commCheckStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($destBranch && (int)($destBranch['is_commissary'] ?? 0) === 1) {
            $commissaryBranchId = $destinationBranchId;
            $isCommissaryDirectOutput = true;
        } else {
            $supply = dl_resolveProductSupplySource($destinationBranchId, $productId);
            if ($supply['source'] === 'commissary' && $supply['source_id'] !== null) {
                $commissaryBranchId = (int)$supply['source_id'];
            }
        }
    }

    $shouldAutoCreateFormalDelivery = $movementType === 'output'
        && $referenceMovementId === null
        && $formalDeliveryEnabled
        && $drNumber !== ''
        && !$isCommissaryDirectOutput;
    if ($movementType === 'reverse') {
        if ($reason === '') {
            throw new \RuntimeException('Reverse requires an override reason.');
        }
        $refId = (int)($input['reference_movement_id'] ?? 0);
        $refUuid = trim((string)($input['reference_movement_uuid'] ?? ''));

        if ($refId <= 0 && $refUuid === '') {
            throw new \RuntimeException('reference_movement_id or reference_movement_uuid is required for reverse.');
        }

        if ($refId > 0) {
            $refStmt = $ctx->db()->prepare(
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, shift, flow_mode, movement_type, dr_number
                 FROM dl_production_movements
                 WHERE id = :id AND movement_type IN ('withdrawal','output')
                 LIMIT 1"
            );
            $refStmt->execute([':id' => $refId]);
        } else {
            $refStmt = $ctx->db()->prepare(
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, shift, flow_mode, movement_type, dr_number
                 FROM dl_production_movements
                 WHERE movement_uuid = :uuid AND movement_type IN ('withdrawal','output')
                 LIMIT 1"
            );
            $refStmt->execute([':uuid' => $refUuid]);
        }
        $ref = $refStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            throw new \RuntimeException('Reference movement not found.');
        }

        $referenceMovementId = (int)$ref['id'];
        $referenceMovementType = (string)$ref['movement_type'];
        $destinationBranchId = (int)$ref['destination_branch_id'];
        $productId = (int)$ref['product_id'];
        $quantity = (int)$ref['quantity'];
        $ledgerDate = (string)$ref['ledger_date'];
        $productionShift = $ref['shift'] !== null ? dl_normalizeShift((string)$ref['shift']) : null;
        $flowMode = (string)$ref['flow_mode'];
        if ($drNumber === '') {
            $drNumber = trim((string)($ref['dr_number'] ?? ''));
        }

        if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
            throw new \RuntimeException('You cannot reverse a movement outside your branch scope.');
        }

        $reverseExists = $ctx->db()->prepare("SELECT id FROM dl_production_movements WHERE reference_movement_id = :rid AND movement_type = 'reverse' LIMIT 1");
        $reverseExists->execute([':rid' => $referenceMovementId]);
        if ($reverseExists->fetchColumn()) {
            throw new \RuntimeException('Reference movement is already reversed.');
        }

        if ($referenceMovementType === 'output') {
            $directStmt = $ctx->db()->prepare(
                'SELECT is_commissary FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $directStmt->execute([':id' => $destinationBranchId]);
            if ((int)$directStmt->fetchColumn() === 1) {
                $commissaryBranchId = $destinationBranchId;
                $isCommissaryDirectOutput = true;
            }

            if ($drNumber !== '') {
                $deliveryStmt = $ctx->db()->prepare(
                    'SELECT d.id
                       FROM dl_deliveries d
                       INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                      WHERE d.destination_type = "branch" AND d.destination_id = :bid
                        AND d.dr_number = :dr AND di.product_id = :pid
                        AND d.status <> "voided"
                      ORDER BY d.id DESC LIMIT 1'
                );
                $deliveryStmt->execute([':bid' => $destinationBranchId, ':dr' => $drNumber, ':pid' => $productId]);
                $deliveryId = (int)($deliveryStmt->fetchColumn() ?: 0);
                if ($deliveryId > 0 && dl_deliveryHasActiveReceivings($ctx->db(), $deliveryId)) {
                    throw new \RuntimeException('Delivery has already been received and cannot be corrected here. Reverse the receiving first.');
                }
            }
        }

        $delta = -$quantity;
    }

    // Route each movement type to the correct ledger column:
    // output (delivered to branch) → addtl, withdrawal (pulled from branch) → withdraw
    if ($movementType === 'reverse') {
        $ledgerColumn = $referenceMovementType === 'withdrawal' ? 'withdraw' : 'addtl';
    } else {
        $ledgerColumn = $movementType === 'withdrawal' ? 'withdraw' : 'addtl';
    }

    $ownsTransaction = !$ctx->db()->inTransaction();
    if ($ownsTransaction) {
        $ctx->db()->beginTransaction();
    }
    try {
        // Credit, or reverse, commissary finished-goods output. A direct
        // commissary reverse stays in this ledger and never touches deliveries.
        $commissaryLedgerState = null;
        if (($movementType === 'output' || ($movementType === 'reverse' && $isCommissaryDirectOutput))
            && $commissaryBranchId !== null) {
            $commissaryLedgerState = dl_applyCommissaryProductLedgerDelta(
                $ctx->db(),
                $commissaryBranchId,
                $productId,
                $ledgerDate,
                $delta,  // produced_qty += quantity
                0,       // dispatched_qty tracked separately via delivery
                $actorId,
                0,
                false,
                $productionShift
            );

            if (empty($commissaryLedgerState['skipped'])) {
                dl_auditLog(
                    'commissary_production',
                    $commissaryBranchId,
                    'dl_commissary_product_ledger',
                    "{$commissaryBranchId}-{$productId}-{$ledgerDate}",
                    null,
                    [
                        'commissary_branch_id' => $commissaryBranchId,
                        'product_id' => $productId,
                        'ledger_date' => $ledgerDate,
                        'produced_qty' => $commissaryLedgerState['produced_qty'],
                        'dispatched_qty' => $commissaryLedgerState['dispatched_qty'],
                        'remaining_qty' => $commissaryLedgerState['remaining_qty'],
                        'movement_id' => null, // will be set after insert
                    ]
                );
            }
        }

        if ($shouldAutoCreateFormalDelivery || $isCommissaryDirectOutput) {
            // Do NOT update addtl directly — commissary production is tracked
            // in dl_commissary_product_ledger. Branch receives addtl only when
            // it accepts a delivery via Receive Stock.
            $ledgerState = [$ledgerColumn => 0];
        } else {
            $ledgerState = dl_applyLedgerDelta($destinationBranchId, $productId, $ledgerDate, $delta, $actorId, $ledgerColumn);
        }

        $movementUuid = dl_generateMovementUuid();
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_production_movements (
                movement_uuid, client_op_id, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, shift, quantity, dr_number,
                override_reason, reference_movement_id, source_payload,
                created_by_id, created_by_role
             ) VALUES (
                :uuid, :coid, :mtype, :fmode,
                     :bid, :pid, :ldate, :shift, :qty, :dr,
                :reason, :refid, :payload,
                :uid, :role
             )'
        );
        $ins->execute([
            ':uuid' => $movementUuid,
            ':coid' => $clientOpId !== '' ? $clientOpId : null,
            ':mtype' => $movementType,
            ':fmode' => $flowMode,
            ':bid' => $destinationBranchId,
            ':pid' => $productId,
            ':ldate' => $ledgerDate,
            ':shift' => $productionShift,
            ':qty' => $quantity,
            ':dr' => $drNumber !== '' ? $drNumber : null,
            ':reason' => $reason !== '' ? $reason : null,
            ':refid' => $referenceMovementId,
            ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES),
            ':uid' => $actorId > 0 ? $actorId : null,
            ':role' => $role !== '' ? $role : 'unknown',
        ]);
        $movementId = (int)$ctx->db()->lastInsertId();

        $autoDeliveryId = null;
        if ($shouldAutoCreateFormalDelivery) {
            $autoDeliveryId = dl_upsertCommissaryOutputDeliveryItem(
                $ctx->db(),
                $destinationBranchId,
                $productId,
                $ledgerDate,
                $quantity,
                $drNumber,
                $actorId,
                $movementId,
                $commissaryBranchId
            );
        }

        dl_auditLog(
            'production_' . $movementType,
            $destinationBranchId,
            'dl_production_movements',
            (string)$movementId,
            null,
            [
                'movement_uuid' => $movementUuid,
                'flow_mode' => $flowMode,
                'destination_branch_id' => $destinationBranchId,
                'product_id' => $productId,
                'ledger_date' => $ledgerDate,
                'quantity' => $quantity,
                'dr_number' => $drNumber,
                'reference_movement_id' => $referenceMovementId,
                'reason' => $reason,
                'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            ],
            $reason !== '' ? $reason : null
        );

        if ($ownsTransaction) {
            $ctx->db()->commit();
        }

        return [
            'movement_id' => $movementId,
            'movement_uuid' => $movementUuid,
            'movement_type' => $movementType,
            'flow_mode' => $flowMode,
            'destination_branch_id' => $destinationBranchId,
            'product_id' => $productId,
            'ledger_date' => $ledgerDate,
            'quantity' => $quantity,
            'dr_number' => $drNumber,
            'delivery_id' => $autoDeliveryId,
            'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            'ledger_column' => $ledgerColumn,
            'duplicate' => false,
        ];
    } catch (\Throwable $e) {
        if ($ownsTransaction && $ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        throw $e;
    }
}

function dl_upsertCommissaryOutputDeliveryItem(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    int $branchId,
    int $productId,
    string $deliveryDate,
    int $quantity,
    string $drNumber,
    int $actorId,
    int $movementId,
    ?int $commissaryBranchId = null,
    bool $allowEmptyDr = false
): int {
    // S10: a Daily Sheet entry is a delivery with no paper DR. Only that explicit
    // caller may omit the DR; every formal production-output delivery still requires
    // one. The sheet path does not use this function's reuse logic (each correction
    // is its own delivery), so this flag exists solely to keep the guard honest for
    // the callers that do carry a sheet marker.
    if ($branchId <= 0 || $productId <= 0 || $quantity <= 0 || (trim($drNumber) === '' && !$allowEmptyDr)) {
        throw new \RuntimeException('Formal production output delivery requires branch, product, quantity, and DR number.');
    }

    $existingPaper = dl_findPaperCapturedCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);
    if ($existingPaper) {
        $deliveryId = (int)$existingPaper['id'];
        if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
            throw new \RuntimeException('Matching paper DR delivery already has a receiving. Encode the source in Usage/Commissary instead of creating another delivery from Production Output.');
        }

        $itemStmt = $db->prepare(
            'SELECT id, quantity FROM dl_delivery_items WHERE delivery_id = :delivery_id AND product_id = :product_id LIMIT 1'
        );
        $itemStmt->execute([':delivery_id' => $deliveryId, ':product_id' => $productId]);
        $existingItem = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingItem) {
            $newQty = (int)$existingItem['quantity'] + $quantity;
            $db->prepare('UPDATE dl_delivery_items SET quantity = :quantity WHERE id = :id')
                ->execute([':quantity' => $newQty, ':id' => (int)$existingItem['id']]);
        } else {
            $priceGroupId = dl_defaultPriceGroupId();
            $db->prepare(
                'INSERT INTO dl_delivery_items
                    (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                 VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
            )->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':unit' => 'pcs',
                ':unit_cost_snapshot' => 0,
                ':price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $deliveryDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => 'production_output_movement:' . $movementId,
            ]);
        }

        // Debit commissary dispatched_qty for the added quantity
        if ($commissaryBranchId !== null) {
            dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $productId, $deliveryDate, 0, $quantity, $actorId);
        }

        dl_auditLog('update_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'source' => 'production_output',
            'movement_id' => $movementId,
            'product_id' => $productId,
            'quantity_added' => $quantity,
            'commissary_branch_id' => $commissaryBranchId,
        ]);

        return $deliveryId;
    }

    $existingAuto = dl_findAutoCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);
    $priceGroupId = dl_defaultPriceGroupId();
    if ($existingAuto) {
        $deliveryId = (int)$existingAuto['id'];
        if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
            throw new \RuntimeException('Delivery already has a receiving. Void the receiving first before changing production output for this DR.');
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $stmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $commissaryBranchId,
            ':destination_type' => 'branch',
            ':destination_id' => $branchId,
            ':dr_number' => $drNumber !== '' ? $drNumber : null,
            ':delivery_date' => $deliveryDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => dl_autoCommissaryDeliveryRemark(),
        ]);
        $deliveryId = (int)$db->lastInsertId();

        dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'production_output',
            'movement_id' => $movementId,
            'commissary_branch_id' => $commissaryBranchId,
        ]);
    }

    // Debit commissary dispatched_qty for the new delivery
    if ($commissaryBranchId !== null) {
        dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $productId, $deliveryDate, 0, $quantity, $actorId);
    }

    $itemStmt = $db->prepare(
        'SELECT id, quantity FROM dl_delivery_items WHERE delivery_id = :delivery_id AND product_id = :product_id LIMIT 1'
    );
    $itemStmt->execute([':delivery_id' => $deliveryId, ':product_id' => $productId]);
    $existingItem = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existingItem) {
        $newQty = (int)$existingItem['quantity'] + $quantity;
        $db->prepare('UPDATE dl_delivery_items SET quantity = :quantity WHERE id = :id')
            ->execute([':quantity' => $newQty, ':id' => (int)$existingItem['id']]);
    } else {
        $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        )->execute([
            ':delivery_id' => $deliveryId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':unit' => 'pcs',
            ':unit_cost_snapshot' => 0,
            ':price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $deliveryDate),
            ':price_group_id' => $priceGroupId,
            ':remarks' => 'production_output_movement:' . $movementId,
        ]);
    }

    return $deliveryId;
}

function dl_recomputeSales(int $branchId, int $productId, string $date, int $userId, string $shift = 'AM'): void
{
    try {
        $ctx = module();
        if (!$ctx) return;
        $shift = ($shift === 'PM') ? 'PM' : 'AM';

        $stmt = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        );
        $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $sales = dl_computeSalesValue(
            $row['beg_bal'] !== null ? (int)$row['beg_bal'] : null,
            $row['addtl'] !== null ? (int)$row['addtl'] : null,
            $row['withdraw'] !== null ? (int)$row['withdraw'] : null,
            $row['bal_end'] !== null ? (int)$row['bal_end'] : null
        );

        $ctx->db()->prepare(
            'UPDATE dl_daily_ledger SET sales = :sales, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        )->execute([':sales' => $sales, ':uid' => $userId, ':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

// ─── Shift lifecycle helpers ─────────────────────────────────────────

function dl_normalizeShift(string $shift): string
{
    return ($shift === 'PM') ? 'PM' : 'AM';
}

function dl_getShiftStatus($db, int $branchId, string $date, string $shift): ?array
{
    $shift = dl_normalizeShift($shift);
    $stmt = $db->prepare(
        'SELECT * FROM dl_ledger_shift_status
         WHERE branch_id = :bid AND ledger_date = :d AND shift = :shift LIMIT 1'
    );
    $stmt->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function dl_shiftIsFinalized($db, int $branchId, string $date, string $shift): bool
{
    $row = dl_getShiftStatus($db, $branchId, $date, $shift);
    return $row !== null && (string)$row['status'] === 'finalized';
}

/**
 * True when an admin's DELIBERATE reopen authorises a non-override actor to correct an
 * already-recorded production entry (S7b 3).
 *
 * The reopen is the authorisation, exactly as reopening lifts the lock in the cashier
 * ledger. It holds only while the day is still OPEN and the shift is not FINALIZED, so a
 * day that was reopened and then closed again (Close Day never clears reopened_at) stays
 * protected, and a finalized shift stays immutable.
 */
function dl_deliberateReopenUnlocksEntryEdit($db, int $branchId, string $date, ?string $shift): bool
{
    if ($shift === null) {
        return false;
    }
    if (dl_getDayStatus($branchId, $date) !== 'open') {
        return false;
    }
    $stmt = $db->prepare('SELECT reopened_at FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d LIMIT 1');
    $stmt->execute([':bid' => $branchId, ':d' => $date]);
    $reopenedAt = $stmt->fetchColumn();
    if ($reopenedAt === false || $reopenedAt === null || (string)$reopenedAt === '') {
        return false;
    }
    return !dl_shiftIsFinalized($db, $branchId, $date, $shift);
}

/** Lock (and create-if-absent) the shift-status row inside the caller's txn. */
function dl_lockShiftStatusRow($db, int $branchId, string $date, string $shift): array
{
    $shift = dl_normalizeShift($shift);
    $ensure = $db->prepare(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
         VALUES (:bid, :d, :shift, "open")
         ON DUPLICATE KEY UPDATE branch_id = branch_id'
    );
    $ensure->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);

    $lock = $db->prepare(
        'SELECT * FROM dl_ledger_shift_status
         WHERE branch_id = :bid AND ledger_date = :d AND shift = :shift
         LIMIT 1 FOR UPDATE'
    );
    $lock->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
    $row = $lock->fetch(PDO::FETCH_ASSOC);
    return is_array($row)
        ? $row
        : ['branch_id' => $branchId, 'ledger_date' => $date, 'shift' => $shift, 'status' => 'open', 'finalized_by' => null, 'finalized_at' => null];
}

/**
 * Active branch products lacking a ledger row for the shift, or whose ending
 * has not been recorded yet. PM finalization reports these before locking.
 *
 * @return array<int,array{product_id:int,name:string,sku:string}>
 */
function dl_shiftMissingEndings($db, int $branchId, string $date, string $shift): array
{
    $shift = dl_normalizeShift($shift);
    $stmt = $db->prepare(
        'SELECT p.id AS product_id, p.name, p.sku
           FROM dl_products p
           INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
           LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
          WHERE p.is_active = 1
            AND (dl.id IS NULL OR dl.bal_end IS NULL)
          ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $date, ':shift' => $shift]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The immediately previous business date when it is still open with an unfinalized PM
 * shift, else null. Ported from the cashier gate (handlers.php:6007-6015) so the
 * production user is told a prior PM day is still pending instead of silently finding a
 * date that refuses to close.
 */
function dl_priorPendingPmDay($db, int $branchId, string $today, string $viewedDate): ?string
{
    $prevDate = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    if ($prevDate === $viewedDate) {
        return null;
    }
    if (dl_getDayStatus($branchId, $prevDate) !== 'open') {
        return null;
    }
    if (dl_shiftIsFinalized($db, $branchId, $prevDate, 'PM')) {
        return null;
    }
    return $prevDate;
}

/** Fully manual day: no decided POS/fallback mode governs the day. */
function dl_isFullyManualDay($db, int $branchId, string $date): bool
{
    if (!dl_isPosEnabled()) {
        return true;
    }
    $mode = dl_pos_dayMode($db, $branchId, $date);
    return $mode['mode'] === 'manual';
}

/**
 * Whether a cashier may edit the given branch/date/shift. The current business
 * date is always editable; additionally the immediately previous date's PM is
 * editable while that day is open and PM is still pending (late-count window
 * after the 22:00 rollover). Everything else is reference-only for cashiers.
 */
function dl_cashierMayEdit(int $branchId, string $date, string $shift, string $today, string $dayStatus): bool
{
    if ($date === $today) {
        return true;
    }
    if ($shift !== 'PM' || $dayStatus === 'closed') {
        return false;
    }
    $prev = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    if ($date !== $prev) {
        return false;
    }
    $ctx = module();
    if (!$ctx) {
        return false;
    }
    return !dl_shiftIsFinalized($ctx->db(), $branchId, $date, 'PM');
}

/** Throw (403) when the shift is finalized — immutability guard for writers. */
function dl_assertShiftMutable($db, int $branchId, string $date, string $shift): void
{
    if (dl_shiftIsFinalized($db, $branchId, $date, $shift)) {
        throw new \RuntimeException('This shift is finalized and locked. Reopen the shift before editing.', 403);
    }
}

// ─── Deterministic variance recompute ────────────────────────────────

/**
 * Pure, idempotent, day-level variance recompute. Derives every applicable
 * variance kind from recorded ledger values only:
 *   - overnight (AM):    AM.beg_bal(D) − ending(D−1)   [recorded ending: PM row preferred, AM fallback]
 *   - handoff (PM):      PM.beg_bal(D) − AM.bal_end(D) [both recorded]
 *   - ending (per shift): bal_end − (beg+addtl−withdraw) when positive
 *   - sales (per shift):  (beg+addtl−withdraw) − bal_end when negative (raw)
 * A missing ending creates no numeric variance (pending context only).
 * Unreviewed flags for the day are regenerated; reviewed zero-resolved flags
 * are retained with variance=0 and an auto-clear note.
 */
function dl_recomputeVariancesForDay(int $branchId, string $date, bool $touchNextDay = true): void
{
    $ctx = module();
    if (!$ctx) return;
    $db = $ctx->db();

    try {
        $ledgerStmt = $db->prepare(
            'SELECT product_id, shift, beg_bal, addtl, withdraw, bal_end
               FROM dl_daily_ledger
              WHERE branch_id = :bid AND ledger_date = :d'
        );
        $ledgerStmt->execute([':bid' => $branchId, ':d' => $date]);
        $rows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Recorded prior ending per product (latest earlier date, PM preferred).
        //
        // This used to pull EVERY earlier date for the branch and let PHP keep the first
        // row per product: 5,700 to 7,400 rows reduced to 174, on a function that runs
        // after every adjustment save, batch save and close. That was the ~2.1s the
        // withdrawal POST took, and it is why an operator saw nothing after a confirmed
        // write and keyed the amount a second time.
        //
        // The join below selects only the latest earlier date that carries an ending, per
        // product; PHP still applies the same PM-over-AM preference, so the resulting map
        // is unchanged - verified identical on six dates, 340-348 rows instead of up to
        // 7,433. Separate placeholders because PDO may not reuse a named parameter.
        $prevStmt = $db->prepare(
            'SELECT dl.product_id, dl.shift, dl.bal_end
               FROM dl_daily_ledger dl
               INNER JOIN (
                   SELECT product_id, MAX(ledger_date) AS max_date
                     FROM dl_daily_ledger
                    WHERE branch_id = :bid1 AND ledger_date < :d1 AND bal_end IS NOT NULL
                    GROUP BY product_id
               ) latest ON latest.product_id = dl.product_id AND latest.max_date = dl.ledger_date
              WHERE dl.branch_id = :bid2 AND dl.bal_end IS NOT NULL
              ORDER BY dl.product_id, CASE dl.shift WHEN \'PM\' THEN 1 ELSE 0 END DESC'
        );
        $prevStmt->execute([':bid1' => $branchId, ':d1' => $date, ':bid2' => $branchId]);
        $prevEnd = [];
        foreach ($prevStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pid = (int)$r['product_id'];
            if (!isset($prevEnd[$pid])) {
                $prevEnd[$pid] = (int)$r['bal_end'];
            }
        }

        // Recompute owns the derived day kinds only: clear their unreviewed
        // flags, keep reviewed history. kind='delivery' is raised by a
        // production correction and is owned by the admin decision, so a ledger
        // recompute must never silently delete it.
        $db->prepare(
            "DELETE FROM dl_variance_flags
              WHERE branch_id = :bid AND ledger_date = :d AND resolution_status = 'unreviewed'
                AND kind <> 'delivery'"
        )->execute([':bid' => $branchId, ':d' => $date]);

        $byProduct = [];
        foreach ($rows as $r) {
            $byProduct[(int)$r['product_id']][] = $r;
        }

        foreach ($byProduct as $pid => $productRows) {
            $am = null;
            $pm = null;
            foreach ($productRows as $r) {
                if ((string)$r['shift'] === 'PM') {
                    $pm = $r;
                } else {
                    $am = $r;
                }
            }

            // overnight (AM) — AM beg vs prior recorded ending.
            if ($am !== null && isset($prevEnd[$pid])) {
                $beg = $am['beg_bal'] !== null ? (int)$am['beg_bal'] : 0;
                dl_upsertVarianceFlag($db, $branchId, $pid, $date, 'overnight', 'AM', $beg - $prevEnd[$pid], $prevEnd[$pid], $beg, $beg, $prevEnd[$pid], (int)$am['addtl'], (int)$am['withdraw']);
            }

            // handoff (PM) — PM beg vs AM ending, both recorded.
            if ($pm !== null && $am !== null && $am['bal_end'] !== null) {
                $pmBeg = $pm['beg_bal'] !== null ? (int)$pm['beg_bal'] : 0;
                dl_upsertVarianceFlag($db, $branchId, $pid, $date, 'handoff', 'PM', $pmBeg - (int)$am['bal_end'], (int)$am['bal_end'], $pmBeg, $pmBeg, (int)$am['bal_end'], (int)$pm['addtl'], (int)$pm['withdraw']);
            }

            foreach ([['row' => $am, 'shift' => 'AM'], ['row' => $pm, 'shift' => 'PM']] as $pair) {
                $row = $pair['row'];
                if ($row === null || $row['bal_end'] === null) {
                    continue; // pending ending → context only, no numeric variance.
                }
                $beg = $row['beg_bal'] !== null ? (int)$row['beg_bal'] : 0;
                $add = $row['addtl'] !== null ? (int)$row['addtl'] : 0;
                $wdr = $row['withdraw'] !== null ? (int)$row['withdraw'] : 0;
                $end = (int)$row['bal_end'];
                $expected = $beg + $add - $wdr;
                $over = $end - $expected;       // ending above supply
                $rawSales = $expected - $end;   // negative raw sales
                if ($over > 0) {
                    dl_upsertVarianceFlag($db, $branchId, $pid, $date, 'ending', $pair['shift'], $over, $expected, $end, $beg, null, (int)$row['addtl'], (int)$row['withdraw']);
                    dl_upsertVarianceFlag($db, $branchId, $pid, $date, 'sales', $pair['shift'], $rawSales, $expected, $end, $beg, null, (int)$row['addtl'], (int)$row['withdraw']);
                }
            }
        }

        if ($touchNextDay) {
            $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            dl_recomputeVariancesForDay($branchId, $next, false);
        }
    } catch (\Throwable $e) {
        write_log('daily-ledger variance recompute failed', 'error', [
            'branch_id' => $branchId,
            'ledger_date' => $date,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Upsert one variance flag by (branch, product, date, kind, shift).
 * Snapshot columns carry the flagged shift's raw ledger inputs so the view can
 * show the source numbers: current_beg_bal = shift beginning, addtl / withdraw
 * = shift additional / withdrawals, recorded_end_bal = shift bal_end. prev_bal_end
 * carries the reference balance feeding each comparison (prior day ending for
 * overnight, AM ending for handoff) and is null for ending/sales.
 * Zero variance auto-clears unreviewed flags; reviewed flags are retained at
 * zero with an auditable auto-clear note (reviewer/status metadata preserved).
 */
function dl_upsertVarianceFlag($db, int $branchId, int $productId, string $date, string $kind, ?string $shift, int $variance, ?int $expectedEnd, ?int $recordedEnd, ?int $currentBeg = null, ?int $prevEnd = null, ?int $addtl = null, ?int $withdraw = null): void
{
    $kind = in_array($kind, ['overnight', 'handoff', 'ending', 'sales'], true) ? $kind : 'overnight';
    $shift = ($shift === 'AM' || $shift === 'PM') ? $shift : null;

    if ($variance === 0) {
        $sel = $db->prepare(
            'SELECT id, resolution_status FROM dl_variance_flags
              WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d
                AND kind = :kind AND shift <=> :shift LIMIT 1'
        );
        $sel->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':kind' => $kind, ':shift' => $shift]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            return;
        }
        if ((string)$existing['resolution_status'] === 'unreviewed') {
            $db->prepare('DELETE FROM dl_variance_flags WHERE id = :id')->execute([':id' => (int)$existing['id']]);
        } else {
            $db->prepare(
                'UPDATE dl_variance_flags
                    SET variance = 0, expected_end_bal = :exp, recorded_end_bal = :rec,
                        auto_clear_note = "auto-cleared by recompute"
                  WHERE id = :id'
            )->execute([':exp' => $expectedEnd, ':rec' => $recordedEnd, ':id' => (int)$existing['id']]);
        }
        return;
    }

    $db->prepare(
        'INSERT INTO dl_variance_flags
            (branch_id, product_id, ledger_date, kind, shift, variance, prev_bal_end, current_beg_bal, addtl, withdraw, expected_end_bal, recorded_end_bal)
         VALUES (:bid, :pid, :d, :kind, :shift, :var, :prev, :cur, :addtl, :withdraw, :exp, :rec)
         ON DUPLICATE KEY UPDATE
            variance = VALUES(variance),
            prev_bal_end = VALUES(prev_bal_end),
            current_beg_bal = VALUES(current_beg_bal),
            addtl = VALUES(addtl),
            withdraw = VALUES(withdraw),
            expected_end_bal = VALUES(expected_end_bal),
            recorded_end_bal = VALUES(recorded_end_bal)'
    )->execute([
        ':bid' => $branchId,
        ':pid' => $productId,
        ':d' => $date,
        ':kind' => $kind,
        ':shift' => $shift,
        ':var' => $variance,
        ':prev' => $prevEnd !== null ? $prevEnd : ($kind === 'overnight' ? $expectedEnd : null),
        ':cur' => $currentBeg !== null ? $currentBeg : ($kind === 'overnight' ? $recordedEnd : null),
        ':addtl' => $addtl,
        ':withdraw' => $withdraw,
        ':exp' => $expectedEnd,
        ':rec' => $recordedEnd,
    ]);
    $flagStmt = $db->prepare('SELECT id FROM dl_variance_flags WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND kind = :k AND shift <=> :s LIMIT 1');
    $flagStmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':k' => $kind, ':s' => $shift]);
    $flagId = (int)($flagStmt->fetchColumn() ?: 0);
    if ($flagId > 0) {
        dl_raiseIntegrityNotification($db, 'variance-' . $flagId, 'variance', $branchId, 'dl_variance_flags', $flagId,
            'Inventory variance surfaced', 'Variance flag #' . $flagId . ' requires investigation before correction.');
    }
}

/**
 * Raise (or refresh) the sent-vs-received variance for one delivery line.
 *
 * A production correction must never edit the cashier's count. It raises this
 * flag instead: kind='delivery', state 'unreviewed', so it enters the existing
 * unreviewed -> investigated -> corrected resolve flow rather than a second
 * lifecycle. The cashier's counted value is stored here as evidence and is not
 * changed by this function. Only an explicit admin decision may correct it.
 *
 * @return int|null the variance flag id, or null when it could not be located
 */
function dl_raiseDeliveryVariance(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $deliveryId, int $receivingId, int $productId, int $sentQty, int $receivedQty, int $actorId = 0): ?int
{
    if ($deliveryId <= 0 || $productId <= 0) {
        return null;
    }

    $branchId = 0;
    $ledgerDate = '';
    $countedBy = null;
    if ($receivingId > 0) {
        $head = $db->prepare('SELECT branch_id, received_ledger_date, received_by FROM dl_branch_receivings WHERE id = :id');
        $head->execute([':id' => $receivingId]);
        $row = $head->fetch(PDO::FETCH_ASSOC) ?: [];
        $branchId = (int)($row['branch_id'] ?? 0);
        $ledgerDate = (string)($row['received_ledger_date'] ?? '');
        $countedBy = ($row['received_by'] ?? null) !== null ? (int)$row['received_by'] : null;
    }
    if ($branchId <= 0 || $ledgerDate === '') {
        $head = $db->prepare('SELECT destination_id, delivery_date FROM dl_deliveries WHERE id = :id');
        $head->execute([':id' => $deliveryId]);
        $row = $head->fetch(PDO::FETCH_ASSOC) ?: [];
        $branchId = (int)($row['destination_id'] ?? 0);
        $ledgerDate = (string)($row['delivery_date'] ?? '');
    }
    if ($branchId <= 0 || $ledgerDate === '') {
        return null;
    }

    $variance = $receivedQty - $sentQty;
    $sel = $db->prepare("SELECT id, resolution_status, resolution_choice, original_counted_qty, counted_by
                           FROM dl_variance_flags
                          WHERE kind = 'delivery' AND delivery_id = :delivery AND product_id = :product
                          LIMIT 1");
    $sel->execute([':delivery' => $deliveryId, ':product' => $productId]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC) ?: null;

    // A delivery variance is a cross-shift event: the goods were produced in one
    // shift and received into another, so there is no single shift it belongs to.
    // Both writes below keep shift = NULL on purpose; the two known shifts are
    // read from the delivery and the receiving when the admin view renders the
    // row. Never re-attribute this flag to one shift: doing so hides it from the
    // other shift's filter, which is exactly the visibility defect being fixed.
    if (is_array($existing)) {
        $flagId = (int)$existing['id'];
        $wasCorrected = (string)($existing['resolution_status'] ?? '') === 'corrected';
        $db->prepare(
            "UPDATE dl_variance_flags
                SET branch_id = :bid, product_id = :pid, ledger_date = :d, shift = NULL,
                    delivery_id = :delivery, receiving_id = :rcv, sent_qty = :sent, received_qty = :received,
                    original_counted_qty = COALESCE(original_counted_qty, :orig),
                    counted_by = COALESCE(counted_by, :cby),
                    expected_end_bal = :sent2, recorded_end_bal = :received2, variance = :var,
                    resolution_status = 'unreviewed', is_reviewed = 0,
                    resolution_choice = NULL, reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
              WHERE id = :id"
        )->execute([
            ':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate,
            ':delivery' => $deliveryId, ':rcv' => $receivingId > 0 ? $receivingId : null,
            ':sent' => $sentQty, ':received' => $receivedQty, ':orig' => $receivedQty, ':cby' => $countedBy,
            ':sent2' => $sentQty, ':received2' => $receivedQty, ':var' => $variance,
            ':id' => $flagId,
        ]);
        if ($wasCorrected) {
            dl_auditLog('delivery_variance_reopened', $branchId, 'dl_variance_flags', (string)$flagId, [
                'resolution_status' => 'corrected',
                'resolution_choice' => $existing['resolution_choice'],
                'original_counted_qty' => $existing['original_counted_qty'],
            ], [
                'resolution_status' => 'unreviewed',
                'sent_qty' => $sentQty,
                'received_qty' => $receivedQty,
                'variance' => $variance,
            ], 'a later production correction changed the sent quantity');
        }
    } else {
        // HAZARD: dl_variance_flags.kind is NOT NULL with DEFAULT 'overnight'.
        // This INSERT must always name kind = 'delivery' explicitly; drop that
        // literal and the row silently becomes an overnight variance and pollutes
        // a shift-scoped count. dl_upsertVarianceFlag() must NOT be used for
        // delivery flags either: it coerces every unknown kind to 'overnight'.
        // shift stays NULL because this is a cross-shift event (see above).
        $db->prepare(
            "INSERT INTO dl_variance_flags
                (branch_id, product_id, ledger_date, kind, shift, delivery_id, receiving_id, sent_qty, received_qty,
                 original_counted_qty, counted_by, expected_end_bal, recorded_end_bal, variance, resolution_status, is_reviewed)
             VALUES (:bid, :pid, :d, 'delivery', NULL, :delivery, :rcv, :sent, :received,
                     :orig, :cby, :sent2, :received2, :var, 'unreviewed', 0)"
        )->execute([
            ':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate,
            ':delivery' => $deliveryId, ':rcv' => $receivingId > 0 ? $receivingId : null,
            ':sent' => $sentQty, ':received' => $receivedQty, ':orig' => $receivedQty, ':cby' => $countedBy,
            ':sent2' => $sentQty, ':received2' => $receivedQty, ':var' => $variance,
        ]);
        $flagId = (int)$db->lastInsertId();
    }

    if ($flagId <= 0) {
        return null;
    }

    dl_raiseIntegrityNotification(
        $db,
        'delivery-variance-' . $flagId,
        'variance',
        $branchId,
        'dl_variance_flags',
        $flagId,
        'Delivery sent/received variance surfaced',
        'Delivery #' . $deliveryId . ' product #' . $productId . ': cashier counted ' . $receivedQty
            . ', production records sent ' . $sentQty
            . '. An admin must decide whether to accept production or keep the count as evidence.'
    );

    return $flagId;
}

/**
 * After an append-only Daily Sheet correction, compare the cell's effective
 * (corrected) quantity against what the cashier(s) actually counted. Any
 * difference is surfaced through the same delivery-variance flow a paper-DR
 * correction uses, anchored on the cell's first delivery that carries a
 * posted receiving. The cashier's count is read, never written.
 */
function dl_raiseDailySheetCorrectionVariance($db, int $commissaryBranchId, int $branchId, int $productId, string $ledgerDate, int $actorId = 0): ?int
{
    if ($commissaryBranchId <= 0 || $branchId <= 0 || $productId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ledgerDate)) {
        return null;
    }

    $cellStmt = $db->prepare(
        "SELECT d.id AS delivery_id, COALESCE(SUM(di.quantity), 0) AS sent_qty
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
          WHERE d.delivery_date = :d
            AND d.origin_type = 'commissary'
            AND d.destination_type = 'branch'
            AND d.destination_id = :bid
            AND d.origin_id = :cid
            AND d.status <> 'voided'
            AND di.product_id = :pid
          GROUP BY d.id
          ORDER BY d.id ASC"
    );
    $cellStmt->execute([':d' => $ledgerDate, ':bid' => $branchId, ':cid' => $commissaryBranchId, ':pid' => $productId]);
    $cellRows = $cellStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($cellRows === []) {
        return null;
    }
    $effective = 0;
    foreach ($cellRows as $cellRow) {
        $effective += (int)$cellRow['sent_qty'];
    }

    // The physical count: sum posted receivings for the cell's deliveries. The
    // earliest receiving is the anchor the admin decision corrects.
    $recvStmt = $db->prepare(
        "SELECT br.id AS receiving_id, br.delivery_id, bri.quantity_received
           FROM dl_branch_receivings br
           INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
           INNER JOIN dl_deliveries d ON d.id = br.delivery_id
          WHERE d.delivery_date = :d
            AND d.origin_type = 'commissary'
            AND d.destination_type = 'branch'
            AND d.destination_id = :bid
            AND d.origin_id = :cid
            AND d.status <> 'voided'
            AND bri.product_id = :pid
            AND br.status = 'posted'
          ORDER BY br.id ASC"
    );
    $recvStmt->execute([':d' => $ledgerDate, ':bid' => $branchId, ':cid' => $commissaryBranchId, ':pid' => $productId]);
    $recvRows = $recvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($recvRows === []) {
        return null;
    }
    $counted = 0;
    foreach ($recvRows as $recvRow) {
        $counted += (int)$recvRow['quantity_received'];
    }
    if ($counted === $effective) {
        return null;
    }

    $anchor = $recvRows[0];
    return dl_raiseDeliveryVariance(
        $db,
        (int)$anchor['delivery_id'],
        (int)$anchor['receiving_id'],
        $productId,
        $effective,
        $counted,
        $actorId
    );
}

/** Freeze the variance snapshot for a manual day close (explicit metadata). */
function dl_freezeVarianceFlags($db, int $branchId, string $date, ?int $actorId): void
{
    $db->prepare(
        'UPDATE dl_variance_flags SET frozen_at = COALESCE(frozen_at, CURRENT_TIMESTAMP)
          WHERE branch_id = :bid AND ledger_date = :d AND frozen_at IS NULL'
    )->execute([':bid' => $branchId, ':d' => $date]);
}

/**
 * Self-healing variance refresh for the admin variance page.
 *
 * Recomputes derived variance flags for the viewed date on OPEN days so the
 * page always reflects the current ledger. This surfaces anomalies in data that
 * entered outside the recompute-on-save path — rows encoded before the variance
 * enhancement was deployed, imports, offline syncs — exactly the historical
 * backfill gap where "beginning lesser than ending" rows silently go unflagged.
 *
 * Closed days are skipped: their frozen variance snapshot is authoritative and
 * must not be mutated by a page view. Only the viewed day is touched (no
 * next-day chaining), so viewing one day never rewrites another day's flags.
 */
function dl_refreshVariancesForDateView(string $date, array $accessibleBranchIds): void
{
    if ($date === '') {
        return;
    }
    $ctx = module();
    if (!$ctx) {
        return;
    }
    $db = $ctx->db();

    $dayBranchIds = $db->prepare(
        'SELECT DISTINCT branch_id FROM dl_daily_ledger WHERE ledger_date = :d'
    );
    $dayBranchIds->execute([':d' => $date]);
    $dayStatusStmt = $db->prepare(
        'SELECT status FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d'
    );

    foreach ($dayBranchIds->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawBranchId) {
        $branchId = (int)$rawBranchId;
        if (!in_array($branchId, $accessibleBranchIds, true)) {
            continue;
        }
        $dayStatusStmt->execute([':bid' => $branchId, ':d' => $date]);
        if ((string)$dayStatusStmt->fetchColumn() === 'closed') {
            continue;
        }
        dl_recomputeVariancesForDay($branchId, $date, false);
    }
}

// ─── Cashier Handlers ──────────────────────────────────────────────────

function dlCookieName(): string
{
    return 'daily_ledger_token';
}

function dlSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(dlCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
}

function dlClearAuthCookie(): void
{
    setcookie(dlCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
}

function dlUserFromRequest(): ?array
{
    $token = null;
    $cookieToken = kernelCookie(dlCookieName());

    // Prefer Authorization: Bearer <jwt> for module API calls
    $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authHeader === '') {
        $authHeader = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    if ($authHeader === '' && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) {
                if (is_string($k) && is_string($v) && strtolower($k) === 'authorization') {
                    $authHeader = $v;
                    break;
                }
            }
        }
    }
    $headerToken = '';
    if ($authHeader !== '' && preg_match('/Bearer\s+(.+)$/i', $authHeader, $m)) {
        $headerToken = trim((string)($m[1] ?? ''));
    }

    // Build the candidate token list: the Authorization header first, then the
    // module cookie. When the header carries a stale/expired token (e.g. the
    // mobile client holds an access token past its TTL while the browser cookie
    // is still valid), a hard failure on the header would lock the user out —
    // so each candidate is verified in order until one succeeds.
    $candidates = [];
    if ($headerToken !== '') {
        $candidates[] = $headerToken;
    }
    if (is_string($cookieToken) && $cookieToken !== '' && $cookieToken !== $headerToken) {
        $candidates[] = $cookieToken;
    }
    if ($candidates === []) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            $authHeaderPresent = false;
            $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if ($authHeader !== '') {
                $authHeaderPresent = true;
            }
            if (!$authHeaderPresent && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeaderPresent = true;
            }
            $cookiePresent = is_string($cookieToken) && $cookieToken !== '';
            write_log('daily-ledger api auth missing token', 'error', [
                'path' => $path,
                'http_authorization' => $authHeaderPresent,
                'redirect_http_authorization' => !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
                'cookie_present' => $cookiePresent,
                'has_getallheaders' => function_exists('getallheaders'),
            ]);
        }
        return null;
    }

    foreach ($candidates as $candidate) {
        try {
            $payload = app()->jwt()->verify($candidate);
            if (!is_array($payload)) {
                continue;
            }
            if (($payload['source'] ?? '') !== 'daily-ledger') {
                $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                if (str_starts_with($path, '/daily-ledger/api/')) {
                    write_log('daily-ledger api auth wrong source', 'error', [
                        'path' => $path,
                        'source' => $payload['source'] ?? null,
                        'role' => $payload['role'] ?? null,
                        'sub' => $payload['sub'] ?? null,
                    ]);
                }
                return null;
            }
            return $payload;
        } catch (Throwable $e) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/daily-ledger/api/')) {
                write_log('daily-ledger api auth exception', 'error', [
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
            continue;
        }
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/daily-ledger/api/')) {
        write_log('daily-ledger api auth invalid jwt', 'error', [
            'path' => $path,
            'token_len' => strlen($headerToken !== '' ? $headerToken : (string)$cookieToken),
            'auth_header_present' => ($headerToken !== ''),
            'cookie_present' => (is_string($cookieToken) && $cookieToken !== ''),
        ]);
    }
    return null;
}

function dlRequireAuth(array $roles = ['cashier', 'supervisor', 'admin']): array
{
    $u = dlUserFromRequest();
    if (!$u) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    return $u;
}

function dlAuthenticatedHomeRedirect(): ?string
{
    $user = dlUserFromRequest();
    if (!is_array($user)) {
        return null;
    }

    $role = (string)($user['role'] ?? '');
    if ($role === 'cashier') {
        return '/daily-ledger/ledger';
    }

    if ($role === 'production_in_charge') {
        return '/daily-ledger/admin/commissary';
    }

    if ($role === 'viewer') {
        return '/daily-ledger/admin/overview';
    }

    return '/daily-ledger/admin/dashboard';
}

function dlPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function dlForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'daily_ledger_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function dlForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = dlForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = dlForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}

function dlForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        dlForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        dlForgotPasswordRateLimitSnapshot('identity', $identity),
    ];

    foreach ($entries as $entry) {
        app()->cache()->set(
            'security_rate_limits',
            (string)$entry['key'],
            ['count' => ((int)($entry['count'] ?? 0)) + 1],
            (int)$policy['forgot_rate_limit_window_seconds']
        );
    }
}

function dlResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $key = 'daily_ledger_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    return is_array($cached) && (int)($cached['count'] ?? 0) >= (int)$policy['reset_rate_limit_ip_max'];
}

function dlResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $key = 'daily_ledger_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    $count = is_array($cached) ? max(0, (int)($cached['count'] ?? 0)) : 0;
    app()->cache()->set('security_rate_limits', $key, ['count' => $count + 1], (int)$policy['reset_rate_limit_window_seconds']);
}

function dlResetTokenIsValid(string $token): bool
{
    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    try {
        $stmt = dlCtx()->db()->prepare(
            'SELECT id
             FROM dl_password_resets
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => dlPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function pageDailyLedgerLogin(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    echo dlRender('modules/daily-ledger/pages/login.disyl', dlLoginPageContext());
}

function pageDailyLedgerForgotPassword(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    echo app()->render('pages/forgot-password.disyl', dlLoginPageContext([
        'page_title' => dlAppName() . ' Forgot Password',
        'forgot_password_endpoint' => dlGetBaseUrl() . '/api/v1/auth/forgot-password',
        'login_page_url' => dlGetBaseUrl() . '/login',
    ]));
}

function pageDailyLedgerResetPassword(): void
{
    $redirect = dlAuthenticatedHomeRedirect();
    if (is_string($redirect) && $redirect !== '') {
        dlRedirect($redirect);
    }

    $token = trim((string)($_GET['token'] ?? ''));

    echo app()->render('pages/reset-password.disyl', dlLoginPageContext([
        'page_title' => dlAppName() . ' Reset Password',
        'reset_password_endpoint' => dlGetBaseUrl() . '/api/v1/auth/reset-password',
        'login_page_url' => dlGetBaseUrl() . '/login',
        'reset_token' => $token,
        'token_valid' => dlResetTokenIsValid($token),
    ]));
}

function dailyLedgerAuthLogin(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = dlInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($username === '' || $password === '') {
        write_log('daily-ledger auth login validation failed', 'info', [
            'username_present' => ($username !== ''),
            'password_present' => ($password !== ''),
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email and password are required.']);
        return;
    }

    $auth = null;
    try {
        $auth = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@daily-ledger:' . $username,
            'password' => $password,
        ], ['mode' => 'pipeline']);
    } catch (Throwable $e) {
        write_log('daily-ledger auth login exception', 'error', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'username' => $username,
            'message' => $e->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Login failed.']);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'daily-ledger')) {
        write_log('daily-ledger auth login invalid credentials', 'info', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'username' => $username,
            'auth_is_array' => is_array($auth),
            'auth_source' => is_array($auth) ? ($auth['source'] ?? null) : null,
            'auth_user_present' => (is_array($auth) && is_array($auth['user'] ?? null)),
        ]);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or email/password combination.']);
        return;
    }

    $u = $auth['user'];
    $role = (string)($u['role'] ?? '');
    $sub = (string)($u['sub'] ?? '');
    // The auth provider may not populate a numeric `id` (it sets sub as
    // "role:id"). The kernel audit records actor_module_user_id from payload id,
    // so a 0 here makes every activity entry anonymous. Derive it from sub.
    $payloadId = (int)($u['id'] ?? 0);
    if ($payloadId <= 0 && $sub !== '' && preg_match('/:(\d+)$/', $sub, $idMatch)) {
        $payloadId = (int)$idMatch[1];
    }
    $payload = [
        'sub' => $sub !== '' ? $sub : ($role . ':0'),
        'id' => $payloadId,
        'username' => (string)($u['username'] ?? $username),
        'name' => (string)($u['full_name'] ?? $username),
        'role' => $role,
        'source' => 'daily-ledger',
    ];

    // Cashier-entered full name: cashier usernames are branch-shift labels
    // (e.g. "Branch-AM"), so the real name is typed at login. Persist it on
    // the user profile (survives logout/login) and surface it in the session
    // payload so the top nav can show the full name beside the username.
    // API/mobile clients may omit it — the field is only saved when provided.
    $enteredFullName = trim((string)($input['full_name'] ?? ''));
    if ($enteredFullName !== '') {
        $payload['name'] = $enteredFullName;
        $payload['full_name'] = $enteredFullName;
        if ($payloadId > 0) {
            // Two rules, selected by the explicit role list in
            // dl_sharedBranchAccountRoles() (helpers.php):
            //
            //   * SHARED branch account (cashier): the account belongs to the
            //     branch, so the name typed at login tracks its CURRENT holder.
            //     A different name updates the profile on every login — the
            //     owner-approved Option B. History stays safe because each audit
            //     row carries its own per-event {actor_name, actor_username} stamp.
            //   * PERSONAL account (anything NOT in the shared list, e.g. admin,
            //     viewer, auditor, supervisor): never overwrite an established name. The typed name may
            //     only CAPTURE an empty profile; a refused overwrite is logged with
            //     both values. This is the original defect and must not be reopened.
            $sharedAccount = in_array($role, dl_sharedBranchAccountRoles(), true);
            try {
                if ($sharedAccount) {
                    $storedStmt = dlCtx()->db()->prepare(
                        'SELECT full_name FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
                    );
                    $storedStmt->execute([':id' => $payloadId]);
                    $storedName = $storedStmt->fetchColumn();
                    if ($storedName === false) {
                        write_log('daily-ledger auth full_name not persisted (user row missing)', 'warning', [
                            'user_id' => $payloadId,
                            'username' => $username,
                            'role' => $role,
                            'entered_full_name' => $enteredFullName,
                        ]);
                    } else {
                        $previousFullName = trim((string)$storedName);
                        $newFullName = mb_substr($enteredFullName, 0, 100);
                        if ($previousFullName !== $newFullName) {
                            $persist = dlCtx()->db()->prepare(
                                'UPDATE dl_users SET full_name = :fn
                                  WHERE id = :id AND deleted_at IS NULL'
                            );
                            $persist->execute([
                                ':fn' => $newFullName,
                                ':id' => $payloadId,
                            ]);
                            if ($persist->rowCount() > 0) {
                                // Information, not noise: a shared branch account is
                                // expected to change hands, so record the account and
                                // the previous/next holder. This is a normal event.
                                write_log('daily-ledger auth full_name updated for shared account', 'info', [
                                    'user_id' => $payloadId,
                                    'username' => $username,
                                    'role' => $role,
                                    'previous_full_name' => $previousFullName,
                                    'new_full_name' => $newFullName,
                                ]);
                            }
                        }
                    }
                } else {
                    // The name typed at login may only CAPTURE a name that is not yet set.
                    // It must never overwrite an established profile name: anyone who can
                    // log in as a user could otherwise permanently rewrite that user's
                    // name, and every historical audit row resolved from it. Cashier
                    // usernames are shift labels (e.g. Cashier-KatipunanAM), so the first
                    // non-empty login still fills an empty profile.
                    $persist = dlCtx()->db()->prepare(
                        'UPDATE dl_users SET full_name = :fn
                          WHERE id = :id AND deleted_at IS NULL
                            AND (full_name IS NULL OR full_name = \'\')'
                    );
                    $persist->execute([
                        ':fn' => mb_substr($enteredFullName, 0, 100),
                        ':id' => $payloadId,
                    ]);
                    if ($persist->rowCount() > 0) {
                        write_log('daily-ledger auth full_name persisted', 'info', [
                            'user_id' => $payloadId,
                            'username' => $username,
                            'role' => $role,
                        ]);
                    } else {
                        // The profile already carried a name. Do not mutate it; make
                        // the attempted overwrite visible with both values and the id.
                        $storedStmt = dlCtx()->db()->prepare(
                            'SELECT full_name FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
                        );
                        $storedStmt->execute([':id' => $payloadId]);
                        $storedName = $storedStmt->fetchColumn();
                        if ($storedName === false) {
                            write_log('daily-ledger auth full_name not persisted (user row missing)', 'warning', [
                                'user_id' => $payloadId,
                                'username' => $username,
                                'entered_full_name' => $enteredFullName,
                            ]);
                        } elseif (trim((string)$storedName) !== $enteredFullName) {
                            write_log('daily-ledger auth full_name overwrite refused', 'warning', [
                                'user_id' => $payloadId,
                                'username' => $username,
                                'stored_full_name' => (string)$storedName,
                                'entered_full_name' => $enteredFullName,
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                write_log('daily-ledger auth full_name persist failed', 'warning', [
                    'user_id' => $payloadId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    } else {
        $payload['full_name'] = (string)($u['full_name'] ?? $payload['name']);
    }

    $tokens = dl_generateAuthTokens($payload);
    dlSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    // D4: record each successful login as its own audit event. There is no session
    // for this request yet, so install the just-authenticated payload before
    // auditing: ModuleContext::audit() stamps actor_name/actor_username from the
    // session user it sees. The branch is best-effort context for shared logins.
    $loginBranchId = null;
    try {
        $loginBranchStmt = dlCtx()->db()->prepare(
            'SELECT branch_id FROM dl_user_branches WHERE user_id = :uid ORDER BY branch_id LIMIT 1'
        );
        $loginBranchStmt->execute([':uid' => $payloadId]);
        $loginBranch = $loginBranchStmt->fetchColumn();
        if ($loginBranch !== false && $loginBranch !== null && (int)$loginBranch > 0) {
            $loginBranchId = (int)$loginBranch;
        }
    } catch (Throwable $e) {
        // Branch is optional context; never block a login on it.
    }
    $loginCtx = module('daily-ledger');
    if ($loginCtx) {
        try {
            app()->setUser($payload);
            $loginCtx->audit('login', $loginBranchId, 'dl_users', (string)$payloadId, null, [
                'username' => $payload['username'],
                'full_name' => $payload['full_name'],
                'role' => $role,
            ], 'login');
        } catch (Throwable $e) {
            write_log('daily-ledger auth login audit failed', 'warning', [
                'user_id' => $payloadId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // Credentials are verified, so refund the login budget this request spent.
    // The limiter counts every attempt including successful ones, and a branch
    // signs in several cashiers from one egress IP, so without this a shift change
    // could only ever admit AUTH_LOGIN_RATE_LIMIT_MAX people per window. Failed
    // attempts still accumulate, so the brute-force bound is unchanged.
    if (function_exists('kernelResetLoginRateLimit')) {
        kernelResetLoginRateLimit('daily-ledger');
    }

    if ($role === 'cashier') {
        $redirect = '/daily-ledger/ledger';
    } elseif ($role === 'production_in_charge') {
        $redirect = '/daily-ledger/admin/commissary';
    } elseif ($role === 'viewer') {
        $redirect = '/daily-ledger/admin/overview';
    } else {
        $redirect = '/daily-ledger/admin/dashboard';
    }
    echo json_encode([
        'ok' => true,
        'redirect' => $redirect,
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function dailyLedgerForgotPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $input = dlInput();
    $identity = trim((string)($input['identity'] ?? ''));
    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email is required.']);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (dlForgotPasswordRateLimitExceeded($requestIp, $identity)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['forgot_rate_limit_message']]);
        return;
    }

    dlForgotPasswordRateLimitRecord($requestIp, $identity);

    try {
        $user = dlFindActiveUserByIdentity($identity);
        if (is_array($user)) {
            $email = strtolower(trim((string)($user['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = dlPasswordResetTokenHash($rawToken);

                $clear = dlCtx()->db()->prepare(
                    'UPDATE dl_password_resets
                     SET used_at = NOW()
                     WHERE user_id = :user_id
                       AND used_at IS NULL'
                );
                $clear->execute([':user_id' => (int)$user['id']]);

                $insert = dlCtx()->db()->prepare(
                    'INSERT INTO dl_password_resets (user_id, token_hash, requester_ip, expires_at, created_at)
                     VALUES (:user_id, :token_hash, :requester_ip, DATE_ADD(NOW(), INTERVAL ' . $ttlMinutes . ' MINUTE), NOW())'
                );
                $insert->execute([
                    ':user_id' => (int)$user['id'],
                    ':token_hash' => $tokenHash,
                    ':requester_ip' => $requestIp,
                ]);

                if (function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
                    $displayName = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
                    $resetUrl = dlExternalBaseUrl() . '/daily-ledger/reset-password?token=' . urlencode($rawToken);
                    $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your Daily Ledger password.</p>'
                        . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                    $body = buildEmailTemplate('Reset Your Daily Ledger Password', $content, 'Reset Password', $resetUrl);
                    $sent = sendEmail($email, 'Daily Ledger Password Reset', $body);
                    if (!$sent) {
                        write_log('daily-ledger forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                    }
                }
            }
        }

        echo json_encode(['ok' => true, 'message' => (string)$policy['forgot_success_message']]);
    } catch (Throwable $e) {
        write_log('daily-ledger forgot-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process request right now.']);
    }
}

function dailyLedgerResetPassword(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $policy = kernel_password_reset_policy();
    $input = dlInput();
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
        return;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        return;
    }

    if ($password !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
        return;
    }

    $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (dlResetPasswordRateLimitExceeded($requestIp)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => (string)$policy['reset_rate_limit_message']]);
        return;
    }

    dlResetPasswordRateLimitRecord($requestIp);

    try {
        $stmt = dlCtx()->db()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id
             FROM dl_password_resets pr
             INNER JOIN dl_users du ON du.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND du.is_active = 1
               AND du.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => dlPasswordResetTokenHash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)$policy['invalid_token_message']]);
            return;
        }

        $updateUser = dlCtx()->db()->prepare(
            'UPDATE dl_users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $updateUser->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':user_id' => (int)$row['user_id'],
        ]);

        $updateReset = dlCtx()->db()->prepare(
            'UPDATE dl_password_resets
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $updateReset->execute([':user_id' => (int)$row['user_id']]);

        echo json_encode([
            'ok' => true,
            'message' => (string)$policy['reset_success_message'],
            'redirect' => '/daily-ledger/login',
        ]);
    } catch (Throwable $e) {
        write_log('daily-ledger reset-password failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to reset password right now.']);
    }
}

function dailyLedgerAuthRefresh(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = dlInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'refresh_token is required.']);
        return;
    }

    $payload = dl_verifyRefreshToken($refreshToken);
    if (!is_array($payload)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid refresh token.']);
        return;
    }

    dl_revokeRefreshToken($refreshToken);
    $tokens = dl_generateAuthTokens($payload);
    dlSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    echo json_encode([
        'ok' => true,
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function dailyLedgerLogout(): void
{
    dlClearAuthCookie();
    dlRedirect('/daily-ledger/login');
}

/**
 * Adopt the preceding ending as this shift's beginning, for products nobody has touched.
 *
 * A shift that has not started should not ask anyone to type 174 numbers, so the sheet
 * opens filled. The safety is entirely in the SCOPE: only rows that do not exist yet are
 * created. A beginning somebody already recorded - a count, a deliberate 0, or a row a
 * delivery/withdrawal created - is left exactly as it is; those are what the per-row
 * "Use previous ending" link and the carry control are for. Treating "stored 0" as
 * "not recorded" is precisely what made the earlier auto-adopt unsafe to keep.
 *
 * Only the current business date is eligible, so browsing an older open day never writes.
 *
 * Overriding an adopted number is an ordinary cell edit: audited as row_update, and
 * measured against this same reference by dl_recomputeVariancesForDay (kind `overnight`
 * for AM against the preceding ending, `handoff` for PM against the AM ending). So a
 * cashier who disagrees with the carried figure stays in control, and the difference is
 * recorded as a variance rather than lost.
 *
 * The reference is the same one the sheet displays: AM takes the preceding date's PM
 * ending (falling back to that date's AM ending), PM takes its own morning's ending.
 *
 * @return int number of beginnings adopted
 */
function dl_autoCarryBeginnings(\Ikabud\Kernel\Contracts\ModuleDB $db, int $branchId, string $ledgerDate, string $shift, int $actorId): int
{
    if ($branchId <= 0 || $ledgerDate !== dl_businessDate()) {
        return 0;
    }
    $shift = $shift === 'PM' ? 'PM' : 'AM';
    $prevDate = (new \DateTimeImmutable($ledgerDate))->modify('-1 day')->format('Y-m-d');

    // `cur.id IS NULL` is the whole safety rule: the row must not exist. One placeholder per
    // binding because PDO does not reliably reuse a named parameter.
    $stmt = $db->prepare(
        "SELECT p.id AS product_id,
                CASE
                    WHEN :isPm = 1 THEN am.bal_end
                    WHEN prev_pm.bal_end IS NOT NULL THEN prev_pm.bal_end
                    ELSE prev_am.bal_end
                END AS carry_from
           FROM dl_products p
           INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
           LEFT JOIN dl_daily_ledger cur
                  ON cur.product_id = p.id AND cur.branch_id = :bid2 AND cur.ledger_date = :d AND cur.shift = :shift
           LEFT JOIN dl_daily_ledger am
                  ON am.product_id = p.id AND am.branch_id = :bid3 AND am.ledger_date = :d2 AND am.shift = 'AM'
           LEFT JOIN dl_daily_ledger prev_pm
                  ON prev_pm.product_id = p.id AND prev_pm.branch_id = :bid4 AND prev_pm.ledger_date = :dprev AND prev_pm.shift = 'PM'
           LEFT JOIN dl_daily_ledger prev_am
                  ON prev_am.product_id = p.id AND prev_am.branch_id = :bid5 AND prev_am.ledger_date = :dprev2 AND prev_am.shift = 'AM'
          WHERE p.is_active = 1
            AND cur.id IS NULL"
    );
    $stmt->execute([
        ':isPm' => $shift === 'PM' ? 1 : 0,
        ':bid' => $branchId, ':bid2' => $branchId, ':bid3' => $branchId, ':bid4' => $branchId, ':bid5' => $branchId,
        ':d' => $ledgerDate, ':d2' => $ledgerDate, ':dprev' => $prevDate, ':dprev2' => $prevDate,
        ':shift' => $shift,
    ]);

    $insert = $db->prepare(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
         VALUES (:bid, :pid, :d, :shift, :price, :beg, 0, 0, NULL, :uid, :uid2)'
    );

    $adopted = 0;
    $products = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $from = $row['carry_from'] ?? null;
        // A recorded 0 is not a reference to carry from - the explicit controls decide there.
        if ($productId <= 0 || $from === null || (int)$from <= 0) {
            continue;
        }
        try {
            $insert->execute([
                ':bid' => $branchId,
                ':pid' => $productId,
                ':d' => $ledgerDate,
                ':shift' => $shift,
                ':price' => dl_resolveBranchProductPrice($branchId, $productId, $ledgerDate),
                ':beg' => (int)$from,
                ':uid' => $actorId,
                ':uid2' => $actorId,
            ]);
        } catch (\PDOException $e) {
            // Another request created the row between the SELECT and here; their value wins.
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
            continue;
        }
        $adopted++;
        $products[] = $productId;
    }

    if ($adopted > 0) {
        dl_auditLog(
            'auto_carry',
            $branchId,
            'dl_daily_ledger',
            "{$branchId}-{$ledgerDate}-{$shift}",
            null,
            ['adopted' => $adopted, 'products' => array_slice($products, 0, 50)],
            $shift === 'PM' ? 'Adopted the AM ending as the PM beginning' : 'Adopted the preceding ending as the AM beginning'
        );
    }

    return $adopted;
}

/**
 * @return array<int,array<string,mixed>>
 */
function dl_fetchCashierLedgerRows(\Ikabud\Kernel\Contracts\ModuleDB $db, int $branchId, string $ledgerDate, string $shift): array
{
    $salesExpr = dl_ledgerSalesQuantitySql('dl');
    $stmt = $db->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order, p.pcs_per_pack,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, dl.bal_end AS bal_end,
                ' . $salesExpr . ' AS sales, dl.price_snapshot,
                COALESCE(am.bal_end, 0) AS am_bal_end,
                CASE
                    WHEN prev_pm.bal_end IS NOT NULL THEN prev_pm.bal_end
                    WHEN prev_am.bal_end IS NOT NULL THEN prev_am.bal_end
                    ELSE NULL
                END AS prev_bal_end,
                CASE WHEN prev_pm.id IS NOT NULL AND prev_pm.bal_end IS NULL THEN 1 ELSE 0 END AS prev_pm_pending
           FROM dl_products p
           INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
           LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
           LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
           LEFT JOIN dl_daily_ledger prev_pm ON prev_pm.product_id = p.id AND prev_pm.branch_id = :bidprevpm AND prev_pm.ledger_date = :dprevpm AND prev_pm.shift = \'PM\'
           LEFT JOIN dl_daily_ledger prev_am ON prev_am.product_id = p.id AND prev_am.branch_id = :bidprevam AND prev_am.ledger_date = :dprevam AND prev_am.shift = \'AM\'
          WHERE p.is_active = 1
          ORDER BY p.sort_order, p.name'
    );
    $prevDate = (new \DateTimeImmutable($ledgerDate))->modify('-1 day')->format('Y-m-d');
    $stmt->execute([
        ':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate, ':shift' => $shift,
        ':bidam' => $branchId, ':dam' => $ledgerDate,
        ':bidprevpm' => $branchId, ':dprevpm' => $prevDate,
        ':bidprevam' => $branchId, ':dprevam' => $prevDate,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        // Explicit flags avoid ambiguous nullable comparisons in the template.
        $row['has_ending'] = $row['bal_end'] !== null;
        $row['has_previous_ending'] = $row['prev_bal_end'] !== null;
    }
    unset($row);
    return dl_applyLedgerDisplayPrices($rows, $branchId, $ledgerDate);
}

function handleCashierLedger(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['cashier', 'supervisor', 'admin'], true)) {
        $ctx->redirect('/');
    }

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        dl_denyBranch('Branch not authorized');
        return;
    }
    $branchId   = $authResult['branch_id'];
    $today      = dl_businessDate();
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : $today;
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift      = $shiftResolved['shift'];
    $shiftBound = $shiftResolved['bound'];
    $branchName = $branchId ? dl_getBranchName($branchId) : 'No Branch';

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus  = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';
    $referenceOnly = ($role === 'cashier' && !dl_cashierMayEdit($branchId, $ledgerDate, $shift, $today, $dayStatus));

    // Shift lifecycle for the visible ledger.
    $shiftRow = $branchId ? dl_getShiftStatus($ctx->db(), (int)$branchId, $ledgerDate, $shift) : null;
    $shiftStatus = $shiftRow ? (string)$shiftRow['status'] : 'open';

    // A prior pending PM day the cashier may recover after the 22:00 rollover.
    $priorPendingDay = null;
    if ($role === 'cashier' && $branchId) {
        $prevDate = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        if ($prevDate !== $ledgerDate
            && dl_getDayStatus($branchId, $prevDate) === 'open'
            && !dl_shiftIsFinalized($ctx->db(), $branchId, $prevDate, 'PM')) {
            $priorPendingDay = ['date' => $prevDate];
        }
    }

    // Branch selector: only accessible branches for the current actor
    $branches = [];
    if ($branchId > 0) {
        $accessible = $authResult['accessible'];
        if (count($accessible) > 0) {
            $placeholders = implode(',', array_fill(0, count($accessible), '?'));
            $stmt = $ctx->db()->prepare(
                "SELECT id, code, name FROM dl_branches WHERE id IN ({$placeholders}) AND is_active = 1 ORDER BY name"
            );
            $stmt->execute($accessible);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $canLedgerOverride = dl_roleHasPermission($role, 'ledger.override');
    // All active branches for the dispatch/receive destination-origin dropdowns.
    // Branch-to-branch transfers are fleet-wide by design (the server accepts any
    // active branch as destination/origin), so these pickers must NOT be scoped
    // to the actor's accessible set — a cashier is locked to a single branch and
    // would otherwise see an empty destination list and be unable to send stock.
    $allBranches = $ctx->db()->query("SELECT id, code, name, is_commissary FROM dl_branches WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Pending incoming deliveries (count of distinct DR groups for this branch)
    // Includes both informal transfers (dl_cashier_withdrawals) and formal DRs (dl_deliveries)
    $incomingCount = 0;
    if ($branchId) {
        $incStmt = $ctx->db()->prepare(
            "SELECT COUNT(DISTINCT COALESCE(dr_number, CONCAT('o:', branch_id, ':', ledger_date)))
             FROM dl_cashier_withdrawals
             WHERE target_branch_id = :bid
               AND withdrawal_type = 'delivery'
               AND received_at IS NULL"
        );
        $incStmt->execute([':bid' => $branchId]);
        $incomingCount = (int)$incStmt->fetchColumn();

        if (dl_isFormalDeliveryEnabled()) {
            $formalIncStmt = $ctx->db()->prepare(
                "SELECT COUNT(*)
                 FROM dl_deliveries d
                 WHERE d.destination_type = 'branch'
                   AND d.destination_id = :bid
                   AND d.status = 'posted'
                   AND COALESCE(d.receipt_required, 1) = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM dl_branch_receivings br
                       WHERE br.delivery_id = d.id AND br.status <> 'voided'
                   )"
            );
            $formalIncStmt->execute([':bid' => $branchId]);
            $incomingCount += (int)$formalIncStmt->fetchColumn();
        }
    }

    $clockLabel = dl_operatingClockLabel();
    $actorId = dl_getActorUserId($user);
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $commissaryBranchId = null;
    $commissaryBranchName = null;
    if ($branchId) {
        $commStmt = $ctx->db()->prepare('SELECT assigned_commissary_id FROM dl_branches WHERE id = :id LIMIT 1');
        $commStmt->execute([':id' => $branchId]);
        $commRow = $commStmt->fetch(PDO::FETCH_ASSOC);
        if ($commRow && !empty($commRow['assigned_commissary_id'])) {
            $commissaryBranchId = (int)$commRow['assigned_commissary_id'];
            $commNameStmt = $ctx->db()->prepare('SELECT name FROM dl_branches WHERE id = :id LIMIT 1');
            $commNameStmt->execute([':id' => $commissaryBranchId]);
            $commissaryBranchName = $commNameStmt->fetchColumn() ?: null;
        }
    }

    // Liable persons for the charge-to dropdown: this branch's cashiers first,
    // then the branch-independent roles.
    $liablePersons = dl_liablePersonsForBranch($ctx->db(), $branchId);

    // POS context: feature flag, cashier sell access, and the branch-day sales mode.
    $posEnabled = dl_isPosEnabled();
    $posMode = ($branchId && $posEnabled) ? dl_pos_dayMode($ctx->db(), $branchId, $ledgerDate) : ['mode' => 'manual', 'row' => null, 'decided' => false];

    // A shift nobody has started opens filled with the preceding ending rather than asking
    // for 174 numbers. Only rows that do not exist are created, so a beginning somebody
    // already recorded - including a deliberate 0 - is never replaced by opening the sheet.
    if ($branchId && $dayStatus === 'open' && !$referenceOnly && $shiftStatus !== 'finalized') {
        dl_autoCarryBeginnings($ctx->db(), (int)$branchId, (string)$ledgerDate, (string)$shift, $actorId);
    }

    $ledgerRows = $branchId ? dl_fetchCashierLedgerRows($ctx->db(), (int)$branchId, $ledgerDate, $shift) : [];
    $settledSummary = dl_settledEndingSummary($ctx->db(), (int)$branchId, (string)$ledgerDate, (string)$shift, $role, false);
    echo dlRender('modules/daily-ledger/cashier/ledger.disyl', [
        'page_title'  => 'Daily Ledger',
        'user_name'   => $userName,
        'user_role'   => $role,
        'dl_user_id'  => $actorId > 0 ? $actorId : '',
        'tenant_scope' => $tenantScope,
        'current_page'=> 'ledger',
        'base_url' => dlGetBaseUrl(),
        'dl_token'    => (string)kernelCookie(dlCookieName(), ''),
        'branch_id'   => $branchId,
        'branch_name' => $branchName,
        'ledger_date' => $ledgerDate,
        'shift'       => $shift,
        'shift_locked' => $shiftBound,
        'today'       => $today,
        'day_status'  => $dayStatus,
        'branches'    => $branches,
        'is_cashier'  => ($role === 'cashier'),
        'reference_only' => $referenceOnly,
        'can_ledger_override' => $canLedgerOverride,
        'pos_enabled' => $posEnabled,
        'can_pos_sell' => $posEnabled && dl_pos_userCan($user, 'pos.sell'),
        'can_edit_delivery' => (function () use ($user) {
            $r = (string)($user['role'] ?? '');
            return in_array($r, ['supervisor', 'admin'], true) || dl_roleHasPermission($r, 'delivery.edit');
        })(),
        'sales_mode' => (string)$posMode['mode'],
        'sales_mode_decided' => (bool)$posMode['decided'],
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        // Top-bar server clock (rendered by partials/server-clock.disyl) so the
        // operator can confirm the business timezone the shifts follow.
        'server_now_label' => $clockLabel['server_now_label'],
        'server_now_offset' => $clockLabel['server_now_offset'],
        'server_epoch_ms' => $clockLabel['server_epoch_ms'],
        'all_branches' => $allBranches,
        'incoming_count' => $incomingCount,
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'commissary_branch_id' => $commissaryBranchId,
        'commissary_branch_name' => $commissaryBranchName,
        'liable_persons' => $liablePersons,
        // Delayed producer entry (066): optional "Produced by" choices for the
        // paper-DR receive modal. Server-side so the template never hard-codes it.
        'producer_options' => dl_productionProducerOptions($ctx->db()),
        'liable_persons_json' => json_encode($liablePersons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        'rows' => $ledgerRows,
        'settled_summary' => $settledSummary,
        'shift_status' => $shiftStatus,
        'pm_pending' => ($shift === 'PM' && $shiftStatus !== 'finalized'),
        'prior_pending_day' => $priorPendingDay,
    ]);
}

function handleCashierRows(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $role = (string)($user['role'] ?? '');
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : dl_businessDate();
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $referenceOnly = ($role === 'cashier' && !dl_cashierMayEdit($branchId, $ledgerDate, $shift, dl_businessDate(), $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open'));

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        echo '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-light);">No branch assigned</td></tr>';
        return;
    }

    $rows = dl_fetchCashierLedgerRows($ctx->db(), (int)$branchId, $ledgerDate, $shift);

    // The rows partial locks its cells off the viewed shift's own lifecycle, so the
    // HTMX swap must receive the same shift_status the full page rendered with.
    // Without this the tbody swap silently re-enabled cells the page had locked.
    $shiftRow = dl_getShiftStatus($ctx->db(), (int)$branchId, $ledgerDate, $shift);
    $shiftStatus = $shiftRow ? (string)$shiftRow['status'] : 'open';

    echo dlRender('modules/daily-ledger/cashier/partials/ledger-rows.disyl', [
        'rows'        => $rows,
        'branch_id'   => $branchId,
        'ledger_date' => $ledgerDate,
        'shift'       => $shift,
        'day_status'  => $dayStatus,
        'shift_status' => $shiftStatus,
        'reference_only' => $referenceOnly,
    ]);
}

// ─── Cashier API ───────────────────────────────────────────────────────

function apiGetLedgerRows(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());
    $shiftSource = $input;
    if (!empty($_GET['shift'])) {
        $shiftSource['shift'] = (string)$_GET['shift'];
    }
    $shiftResolved = dl_resolveLedgerShift($user, $shiftSource);
    $shift = $shiftResolved['shift'];

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        $ctx->json(['ok' => true, 'rows' => [], 'day_status' => $dayStatus]);
        return;
    }

    $salesExpr = dl_ledgerSalesQuantitySql('dl');
    $stmt = $ctx->db()->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, dl.bal_end AS bal_end,
                ' . $salesExpr . ' AS sales, dl.price_snapshot,
                COALESCE(am.bal_end, 0) AS am_bal_end,
                CASE
                    WHEN prev_pm.bal_end IS NOT NULL THEN prev_pm.bal_end
                    WHEN prev_am.bal_end IS NOT NULL THEN prev_am.bal_end
                    ELSE NULL
                END AS prev_bal_end,
                CASE WHEN prev_pm.id IS NOT NULL AND prev_pm.bal_end IS NULL THEN 1 ELSE 0 END AS prev_pm_pending
         FROM dl_products p
         INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
         LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
         LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
         LEFT JOIN dl_daily_ledger prev_pm ON prev_pm.product_id = p.id AND prev_pm.branch_id = :bidprevpm AND prev_pm.ledger_date = :dprevpm AND prev_pm.shift = \'PM\'
         LEFT JOIN dl_daily_ledger prev_am ON prev_am.product_id = p.id AND prev_am.branch_id = :bidprevam AND prev_am.ledger_date = :dprevam AND prev_am.shift = \'AM\'
         WHERE p.is_active = 1
         ORDER BY p.sort_order, p.name'
    );
    $prevDate = (new \DateTimeImmutable($ledgerDate))->modify('-1 day')->format('Y-m-d');
    $stmt->execute([
        ':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate, ':shift' => $shift,
        ':bidam' => $branchId, ':dam' => $ledgerDate,
        ':bidprevpm' => $branchId, ':dprevpm' => $prevDate,
        ':bidprevam' => $branchId, ':dprevam' => $prevDate,
    ]);
    $ctx->json([
        'ok' => true,
        'rows' => dl_applyLedgerDisplayPrices($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $branchId, $ledgerDate),
        'day_status' => $dayStatus,
        'shift' => $shift,
        'shift_locked' => $shiftResolved['bound'],
    ]);
}

function apiGetLedgerDayStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $ctx->json([
        'ok' => true,
        'day_status' => $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open',
    ]);
}


function apiGetCashierWithdrawals(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser();
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$productId || !$branchId) {
        $ctx->json(['ok' => false, 'error' => 'Missing product or branch']);
        return;
    }
    
    $stmt = $ctx->db()->prepare('SELECT id, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, unit, pack_qty, liable_user_id, encoded_by, created_at FROM dl_cashier_withdrawals WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d');
    $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ctx->json(['ok' => true, 'withdrawals' => $rows]);
}

/**
 * GET /daily-ledger/api/v1/cashier/ledger/withdrawals/today
 *
 * Lists the current business date's cashier adjustments for the branch so a
 * cashier can correct their own entries (Issue: slow-internet retry
 * corrections). Cashiers see only rows they encoded; supervisors/admins see
 * all rows in the branch (branch access is enforced by dl_authorizeBranch).
 */
function apiTodayCashierWithdrawals(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = (int)$authResult['branch_id'];
    $date = isset($_GET['date']) ? (string)$_GET['date'] : dl_businessDate();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid date.'], 422);
        return;
    }
    $shift = isset($_GET['shift']) ? strtoupper(trim((string)$_GET['shift'])) : '';
    if ($shift !== '' && !in_array($shift, ['AM', 'PM'], true)) {
        $shift = '';
    }
    if ($branchId <= 0) {
        $ctx->json(['ok' => true, 'withdrawals' => []]);
        return;
    }

    $actorId = dl_getActorUserId($user);
    $sql = 'SELECT cw.id, cw.product_id, p.name AS product_name, cw.withdrawal_type, cw.reason_code,
                   cw.custom_reason, cw.dr_number, cw.target_branch_id, cw.quantity, cw.unit, cw.pack_qty,
                   cw.liable_user_id, cw.encoded_by, cw.shift, cw.created_at, cw.received_at,
                   COALESCE(NULLIF(cw.liable_user_name, \'\'), NULLIF(lu.full_name, \'\'), lu.username, \'\') AS liable_user_name
              FROM dl_cashier_withdrawals cw
              INNER JOIN dl_products p ON p.id = cw.product_id
              LEFT JOIN dl_users lu ON lu.id = cw.liable_user_id
             WHERE cw.branch_id = :bid AND cw.ledger_date = :d';
    $bind = [':bid' => $branchId, ':d' => $date];
    if ($role === 'cashier') {
        $sql .= ' AND cw.encoded_by = :uid';
        $bind[':uid'] = $actorId;
    }
    if ($shift !== '') {
        // Legacy rows (shift NULL) are shown in either shift (best-effort).
        $sql .= ' AND (cw.shift = :shift OR cw.shift IS NULL)';
        $bind[':shift'] = $shift;
    }
    $sql .= ' ORDER BY cw.created_at DESC, cw.id DESC LIMIT 200';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $editableDate = $date === dl_businessDate() || $role === 'admin' || dl_isKernelAdmin($user);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['product_id'] = (int)$r['product_id'];
        $r['quantity'] = (int)$r['quantity'];
        $r['pack_qty'] = $r['pack_qty'] !== null ? (int)$r['pack_qty'] : null;
        $r['target_branch_id'] = $r['target_branch_id'] !== null ? (int)$r['target_branch_id'] : null;
        $r['liable_user_id'] = $r['liable_user_id'] !== null ? (int)$r['liable_user_id'] : null;
        $r['encoded_by'] = $r['encoded_by'] !== null ? (int)$r['encoded_by'] : null;
        $r['shift'] = $r['shift'] !== null ? (string)$r['shift'] : null;
        $r['editable'] = !in_array((string)$r['withdrawal_type'], ['charge', 'pullout', 'adjustment_add', 'used', 'correction'], true)
            ? false
            : ($editableDate && empty($r['received_at']) && ($r['target_branch_id'] ?? null) === null);
    }
    unset($r);
    $ctx->json(['ok' => true, 'date' => $date, 'withdrawals' => $rows]);
}

/**
 * Classify a branch pullout / return reason for commissary ledger purposes.
 *
 * Owner ruling: only SPOILAGE and DAMAGE are wastage. Goods consumed at the
 * branch (staff meal, sampling, testing, promo, donation) are NOT wastage, and
 * must not credit stock either -- they never came back, and the original
 * dispatch already removed them from the commissary. So this is deliberately a
 * three-way decision, not saleable/unsaleable.
 *
 * Extracted from apiSaveCashierWithdrawals so this financial rule is directly
 * testable instead of buried in the withdrawal handler.
 *
 * @return string 'wastage' | 'returned_saleable' | 'consumed_no_delta'
 */
function dl_classifyPulloutReturnReason(?string $reasonCode): string
{
    $reason = strtolower(trim((string)$reasonCode));
    if (in_array($reason, ['spoilage', 'damage'], true)) {
        return 'wastage';
    }
    if (in_array($reason, ['staff_meal', 'sampling', 'testing', 'promo', 'donation'], true)) {
        return 'consumed_no_delta';
    }
    return 'returned_saleable';
}

function apiSaveCashierWithdrawals(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser();
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cached = dl_loadIdempotentResponse('cashier_withdrawal', $idempotencyKey);
        if ($cached !== null) {
            $ctx->json($cached);
            return;
        }
    }
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    if ($branchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Unable to resolve branch. Verify your branch assignment.'], 422);
        return;
    }
    $date = $input['date'] ?? date('Y-m-d');
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $header = (array)($input['header'] ?? []);
    $lines = (array)($input['lines'] ?? []);

    if (!$branchId) {
        $ctx->json(['ok' => false, 'error' => 'Missing branch'], 422);
        return;
    }

    $type = (string)($header['withdrawal_type'] ?? 'charge');
    if (!in_array($type, ['charge', 'pullout', 'adjustment_add', 'used', 'correction'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid withdrawal type'], 422);
        return;
    }
    $drNumber = isset($header['dr_number']) && $header['dr_number'] !== '' ? (string)$header['dr_number'] : null;
    $targetBranchId = !empty($header['target_branch_id']) ? (int)$header['target_branch_id'] : null;
    $reasonCode = isset($header['reason_code']) && $header['reason_code'] !== '' ? (string)$header['reason_code'] : null;
    $customReason = isset($header['custom_reason']) ? trim((string)$header['custom_reason']) : '';
    $liableUserId = !empty($header['liable_user_id']) ? (int)$header['liable_user_id'] : null;
    $allowedReasons = dl_allowedWithdrawalReasons();
    if ($reasonCode !== null && !in_array($reasonCode, $allowedReasons, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid reason_code'], 422);
        return;
    }
    if (in_array($type, ['charge','pullout','adjustment_add','used','correction'], true) && $reasonCode === null) {
        $reasonCode = 'manual_adjustment';
    }
    if ($reasonCode === 'other' && $customReason === '') {
        $ctx->json(['ok' => false, 'error' => 'A custom reason is required when reason is Other.'], 422);
        return;
    }
    if ($customReason !== '' && mb_strlen($customReason) > 255) {
        $ctx->json(['ok' => false, 'error' => 'Custom reason must be 255 characters or fewer.'], 422);
        return;
    }
    if ($reasonCode !== 'other') {
        $customReason = '';
    }
    // Add Stock resolves a shortage, so it has to name who is charged. An encoder
    // omission is not a shortage — the entry was simply missed — so no one is
    // charged and liable_user_id stays NULL.
    if ($type === 'adjustment_add' && $liableUserId === null && dl_adjustmentAddNeedsLiable($reasonCode)) {
        $ctx->json(['ok' => false, 'error' => 'adjustment_add requires a liable_user_id (charge to person). Choose the Encoder omission reason if nothing was lost.'], 422);
        return;
    }

    // Filter to valid product+qty pairs, resolving pcs|box units to the
    // piece-equivalent the ledger counts (quantity stays pieces). Correction
    // entries may use a minus prefix (negative qty) to REDUCE an over-recorded
    // amount; negatives are piece-based reductions and never box units.
    $validLines = [];
    foreach ($lines as $l) {
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['quantity']) ? (int)$l['quantity'] : 0;
        if ($pid <= 0 || $qty === 0) {
            continue;
        }
        try {
            $resolved = dl_resolveWithdrawalLineForType($ctx->db(), $pid, $qty, $l['unit'] ?? 'pcs', $type);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 422) {
                $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
                return;
            }
            throw $e;
        }
        if ($resolved['quantity'] === 0) {
            continue;
        }
        $validLines[] = [
            'product_id' => $pid,
            'quantity' => $resolved['quantity'],
            'unit' => $resolved['unit'],
            'pack_qty' => $resolved['pack_qty'],
        ];
    }
    if (count($validLines) === 0) {
        $ctx->json(['ok' => false, 'error' => 'Add at least one product with a quantity greater than 0.'], 422);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $dayStatus = dl_getDayStatus($branchId, $date);
    if ($role === 'cashier' && !dl_cashierMayEdit($branchId, $date, $shift, dl_businessDate(), $dayStatus)) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($dayStatus === 'closed' && $role === 'cashier') {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }

    $userId = dl_getActorUserId($user);
    $totals = [];

    $ctx->db()->beginTransaction();
    try {
        dl_assertShiftMutable($ctx->db(), $branchId, $date, $shift);
        // Snapshot the charged person's name as it reads now (migration 060), so a
        // later rename of that account cannot rewrite who this charge was for.
        $liableUserName = dl_userDisplayNameById($ctx->db(), $liableUserId);
        $stmtIns = $ctx->db()->prepare(
            'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, shift, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, unit, pack_qty, encoded_by, liable_user_id, liable_user_name, dedup_hash)
             VALUES (:bid, :pid, :d, :shift, :typ, :rc, :crc, :dr, :tbid, :qty, :unit, :pack_qty, :uid, :luid, :luid_name, :dedup)'
        );
        // Both accumulator columns are read under the row lock. Cashier rows are
        // only one source of ledger movement (dispatches also move withdraw), so
        // neither accumulator may ever be rebuilt from the cashier-row SUM.
        $stmtCheck = $ctx->db()->prepare(
            'SELECT id, addtl, withdraw FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );
        // adjustment_add moves addtl (positive adds stock back to the branch, negative
        // takes back an amount recorded too high); charge/pullout increase withdraw
        $isAddtl = ($type === 'adjustment_add');
        if ($isAddtl) {
            // addtl accumulates from multiple sources — use increment, not replace
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET addtl = addtl + :qty, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :qty, :uid_enc, :uid_upd)'
            );
        } else {
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET withdraw = withdraw + :qty, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, withdraw, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :qty, :uid_enc, :uid_upd)'
            );
        }

        foreach ($validLines as $line) {
            $pid = $line['product_id'];
            $qty = $line['quantity'];
            $unit = $line['unit'];
            $packQty = $line['pack_qty'];

            $dedupHash = dl_withdrawalDedupHash(
                $branchId, $pid, $date, $type,
                $reasonCode,
                $customReason !== '' ? $customReason : null,
                $drNumber,
                $targetBranchId,
                $qty,
                $liableUserId,
                $unit,
                $shift,
                // The SUBMISSION's identity, minted when the caller sent no key. A replay of
                // this request is still refused; a new submission of the same content is
                // recorded. Never fall back to content identity - see
                // dl_withdrawalSubmissionId().
                dl_withdrawalSubmissionId($idempotencyKey)
            );

            try {
                $stmtIns->execute([
                    ':bid' => $branchId,
                    ':pid' => $pid,
                    ':d' => $date,
                    ':shift' => $shift,
                    ':typ' => $type,
                    ':rc' => $reasonCode,
                    ':crc' => $customReason !== '' ? $customReason : null,
                    ':dr' => $drNumber,
                    ':tbid' => $targetBranchId,
                    ':qty' => $qty,
                    ':unit' => $unit,
                    ':pack_qty' => $packQty,
                    ':uid' => $userId,
                    ':luid' => $liableUserId,
                    ':luid_name' => $liableUserName,
                    ':dedup' => $dedupHash,
                ]);
            } catch (\PDOException $e) {
                if (dl_isDuplicateKeyError($e)) {
                    // DB-level dedup guard: identical line already recorded.
                    // Treat the whole submission as a replay — nothing to apply.
                    throw new DlDuplicateWithdrawalException();
                }
                throw $e;
            }

            if ($isAddtl) {
                // adjustment_add moves addtl by qty: positive adds stock back to the
                // branch, negative takes back an amount that was recorded too high.
                // addtl accumulates from several sources (formal receive, informal
                // receive, adjustment_add), so this is a DELTA, never a replace.
                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                $ledgerRowForAddtl = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if ($ledgerRowForAddtl) {
                    // Floor at zero: the ledger row is the evidence of what was
                    // recorded, so it must never go negative. Reject rather than clamp
                    // — a silent clamp would store a row whose quantity disagrees with
                    // the addtl it moved, and the operator would believe it landed.
                    $nextAddtl = (int)$ledgerRowForAddtl['addtl'] + $qty;
                    if ($nextAddtl < 0) {
                        throw new RuntimeException(
                            'Cannot reduce additional stock below zero: this product has '
                            . (int)$ledgerRowForAddtl['addtl'] . ' recorded for this date and shift.',
                            422
                        );
                    }
                    $stmtUpd->execute([
                        ':qty' => $qty,
                        ':uid' => $userId,
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                    ]);
                } else {
                    if ($qty < 0) {
                        throw new RuntimeException('There is no additional stock recorded for this product, date and shift to reduce.', 422);
                    }
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                        ':prc' => $price,
                        ':qty' => $qty,
                        ':uid_enc' => $userId,
                        ':uid_upd' => $userId,
                    ]);
                }
                // The DELTA, not the resulting balance. This value is both the API
                // response and the audit line, and "-4" reads unambiguously as "moved
                // back by 4", where the resulting "6" could be taken for the delta.
                // `result` is added alongside it so the client can show the new balance
                // at once without guessing at it: the value comes from the write itself.
                $totals[] = ['product_id' => $pid, 'addtl' => $qty, 'result' => $nextAddtl ?? $qty, 'field' => 'addtl'];
            } else {
                // Withdraw accumulates from cashier rows AND dispatches. Apply only
                // this row's delta; replacing it with a cashier SUM erases dispatches.
                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                $ledgerRowForWithdraw = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if ($ledgerRowForWithdraw) {
                    $newTotal = (int)$ledgerRowForWithdraw['withdraw'] + $qty;
                    if ($newTotal < 0) {
                        throw new RuntimeException('Cannot reduce withdrawals below zero: this product has ' . (int)$ledgerRowForWithdraw['withdraw'] . ' recorded for this date and shift.', 422);
                    }
                    $stmtUpd->execute([
                        ':qty' => $qty,
                        ':uid' => $userId,
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                    ]);
                } else {
                    if ($qty < 0) {
                        throw new RuntimeException('There are no withdrawals recorded for this product, date and shift to reduce.', 422);
                    }
                    $newTotal = $qty;
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([
                        ':bid' => $branchId,
                        ':pid' => $pid,
                        ':d' => $date,
                        ':shift' => $shift,
                        ':prc' => $price,
                        ':qty' => $qty,
                        ':uid_enc' => $userId,
                        ':uid_upd' => $userId,
                    ]);
                }
                $totals[] = ['product_id' => $pid, 'total' => $newTotal, 'result' => $newTotal, 'field' => 'withdraw'];
            }

            // Both branches move the sales invariant: sales = beg_bal + addtl -
            // withdraw - bal_end, so charge/pullout (withdraw) and Add Stock (addtl)
            // each shift it. This handler used to recompute only the variance flags,
            // so `sales` kept the value it held when the ending was set - computed
            // while withdraw was still 0 - and then silently disagreed with the three
            // columns printed beside it. Reports stayed correct the whole time because
            // they derive sales themselves, which is exactly what made the stale cell
            // read as a display glitch instead of a stored-value bug.
            dl_recomputeSales($branchId, $pid, $date, $userId, $shift);
        }

        // ── Pullout return to commissary ──────────────────────────────
        // When a branch pullout is recorded with a target commissary,
        // create a return delivery and auto-receive so the commissary
        // ledger reflects the returned goods.
        // If the branch IS the commissary (self-managed production),
        // skip the delivery — no physical movement, just credit the ledger.
        $returnDeliveryId = null;
        $returnReceivingId = null;
        if ($type === 'pullout' && $targetBranchId !== null && dl_isFormalDeliveryEnabled()) {
            $commissaryCheck = $ctx->db()->prepare(
                'SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
            );
            $commissaryCheck->execute([':id' => $targetBranchId]);
            $commissary = $commissaryCheck->fetch(PDO::FETCH_ASSOC);
            if ($commissary) {
                $actorId = dl_getActorUserId($user);
                $effectiveUserId = $actorId > 0 ? $actorId : null;
                $returnDr = '[pullout-return-' . $date . '-' . $branchId . '-' . date('His') . ']';

                // Classify BEFORE any physical return is minted. Consumed goods
                // (staff meal, sampling, testing, promo, donation) never came
                // back, so they must not create a return delivery at all. Wastage
                // is physical but not saleable; saleable stock returns to stock.
                $classification = dl_classifyPulloutReturnReason($reasonCode);
                $isWastage = $classification === 'wastage';
                $isSaleableReturn = $classification === 'returned_saleable';
                $createsPhysicalReturn = $isWastage || $isSaleableReturn;

                // Skip self-delivery when branch IS the commissary (self-managed production)
                if (!$createsPhysicalReturn || $branchId === $targetBranchId) {
                    // Consumed goods: no physical movement. Self-managed production:
                    // goods stay at the production site. Wastage/saleable still
                    // credit the commissary product ledger below.
                } else {
                    $priceGroupId = dl_defaultPriceGroupId();

                    $delIns = $ctx->db()->prepare(
                        'INSERT INTO dl_deliveries
                            (origin_type, origin_id, destination_type, destination_id, dr_number,
                             delivery_date, status, created_by, posted_by, posted_at, remarks)
                         VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :uid1, :uid2, NOW(), :remarks)'
                    );
                    $delIns->execute([
                        ':ot' => 'branch',
                        ':oid' => $branchId,
                        ':dt' => 'branch',
                        ':did' => $targetBranchId,
                        ':dr' => $returnDr,
                        ':dd' => $date,
                        ':uid1' => $effectiveUserId,
                        ':uid2' => $effectiveUserId,
                        // A wastage return is physical but already recorded as
                        // wastage_qty; the marker keeps it out of the saleable
                        // returned_qty the Summary credits.
                        ':remarks' => $isWastage ? '[cashier-pullout-return:wastage]' : '[cashier-pullout-return]',
                    ]);
                    $returnDeliveryId = (int)$ctx->db()->lastInsertId();

                    // Add delivery items
                    $itemIns = $ctx->db()->prepare(
                        'INSERT INTO dl_delivery_items
                            (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                         VALUES (:did, :pid, :qty, :unit, :cost, :price, :pg, :remarks)'
                    );
                    foreach ($validLines as $line) {
                        $itemIns->execute([
                            ':did' => $returnDeliveryId,
                            ':pid' => $line['product_id'],
                            ':qty' => $line['quantity'],
                            ':unit' => 'pcs',
                            ':cost' => 0,
                            ':price' => dl_resolveProductPrice((int)$line['product_id'], $priceGroupId, $date),
                            ':pg' => $priceGroupId,
                            ':remarks' => 'pullout_return:' . $branchId,
                        ]);
                    }

                    // Auto-receive for commissary
                    $returnReceivingId = dl_acceptFormalDelivery(
                        $ctx->db(), $targetBranchId, $returnDeliveryId, $actorId, $date, null, $shift
                    );
                    // Auto-receiving copies the sent quantity; no person counted it.
                    dl_markReceivingCountBasis($ctx->db(), $returnReceivingId, 'copied');
                }

                // Credit commissary product ledger. THREE-WAY split (owner ruling):
                // - returned_saleable -> produced_qty, because the goods physically
                //   came back and can be re-dispatched.
                // - wastage (spoilage, damage) -> wastage_qty, the only ledger
                //   column meaning goods are gone for good.
                // - consumed_no_delta (staff_meal, sampling, testing, promo,
                //   donation) -> NEITHER. Not wastage, and crediting produced_qty
                //   would invent stock. The dispatch already removed them.
                foreach ($validLines as $line) {
                    $pid = (int)$line['product_id'];
                    $qty = (int)$line['quantity'];
                    if ($isWastage) {
                        dl_applyCommissaryProductLedgerDelta(
                            $ctx->db(), $targetBranchId, $pid, $date,
                            0, 0, $actorId, $qty  // wastage_qty += qty
                        );
                    } elseif ($isSaleableReturn) {
                        dl_applyCommissaryProductLedgerDelta(
                            $ctx->db(), $targetBranchId, $pid, $date,
                            $qty, 0, $actorId, 0  // produced_qty += qty
                        );
                    }
                    // else: consumed at the branch -- no commissary ledger delta.
                }

                dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)($returnDeliveryId ?? 0), null, [
                    'dr_number' => $returnDr ?? '[self-managed-no-delivery]',
                    'status' => $returnDeliveryId ? 'posted' : 'ledger-only',
                    'source' => 'cashier_pullout_return',
                    'destination_commissary_id' => $targetBranchId,
                    'saleable' => $isSaleableReturn,
                    'classification' => $isWastage
                        ? 'wastage'
                        : ($isSaleableReturn ? 'returned_saleable' : 'consumed_no_delta'),
                    'items' => count($validLines),
                ]);
            }
        }

        $ctx->db()->commit();

        // These are post-commit side effects. In particular, never publish a
        // successful audit before the data transaction is durable: audit uses the
        // kernel connection and may not share a stale module context's transaction.
        dl_auditLog('withdrawal', $branchId, 'dl_cashier_withdrawals', "{$date}-{$shift}", null, [
            'withdrawal_type' => $type,
            'reason_code' => $reasonCode,
            'custom_reason' => $customReason !== '' ? $customReason : null,
            'dr_number' => $drNumber,
            'liable_user_id' => $liableUserId,
            'liable_user_name' => $liableUserName,
            'lines' => $totals,
        ]);
        dl_recomputeVariancesForDay($branchId, $date);

        $response = ['ok' => true, 'totals' => $totals];
        if ($returnDeliveryId !== null) {
            $response['delivery_id'] = $returnDeliveryId;
            $response['receiving_id'] = $returnReceivingId;
        }
        if ($idempotencyKey !== '') {
            dl_storeIdempotentResponse('cashier_withdrawal', $idempotencyKey, $response, 86400);
        }
        $ctx->json($response);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof DlDuplicateWithdrawalException) {
            // Idempotent replay: identical withdrawal already recorded, so the
            // transaction (including any ledger delta) was rolled back whole.
            $ctx->json(['ok' => true, 'duplicate' => true, 'message' => 'Identical withdrawal already recorded — no changes made.']);
            return;
        }
        if ($e instanceof RuntimeException && $e->getCode() === 422) {
            // Validation raised inside the transaction — e.g. an Add Stock reduction
            // that would drive addtl below zero. Surfaced verbatim: falling through to
            // the generic handler would report "Database error", which tells the
            // operator nothing about what to change and looks like a system fault.
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $ctx->log('apiSaveCashierWithdrawals error: ' . $e->getMessage(), 'error');
        if ($e instanceof RuntimeException && $e->getCode() === 403) {
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 403);
            return;
        }
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * POST /daily-ledger/api/v1/cashier/ledger/withdrawals/edit
 *
 * Lets a cashier correct a withdrawal they encoded — or a supervisor/admin
 * correct any withdrawal in an accessible branch — when a slow connection made
 * a retry leave a wrong quantity/type/reason. Every change is audit-logged
 * ('withdrawal_updated', old -> new).
 *
 * Window: ledger_date == current business date, day open, shift mutable.
 * Blocked: rows already received (received_at), legacy 'delivery' rows, and
 * pullout rows that returned goods to a commissary (target_branch_id) — those
 * chain to a posted return delivery/receiving and are corrected by an admin.
 */
function apiUpdateCashierWithdrawal(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    $input = (array)json_decode(file_get_contents('php://input'), true);

    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = (int)$authResult['branch_id'];
    if ($branchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Unable to resolve branch. Verify your branch assignment.'], 422);
        return;
    }

    $withdrawalId = (int)($input['withdrawal_id'] ?? 0);
    if ($withdrawalId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'withdrawal_id is required.'], 422);
        return;
    }

    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $date = (string)($input['date'] ?? dl_businessDate());
    $header = (array)($input['header'] ?? []);
    $lines = (array)($input['lines'] ?? []);

    $type = (string)($header['withdrawal_type'] ?? '');
    if (!in_array($type, ['charge', 'pullout', 'adjustment_add', 'used', 'correction'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid withdrawal type'], 422);
        return;
    }
    $drNumber = isset($header['dr_number']) && $header['dr_number'] !== '' ? (string)$header['dr_number'] : null;
    $targetBranchId = !empty($header['target_branch_id']) ? (int)$header['target_branch_id'] : null;
    $reasonCode = isset($header['reason_code']) && $header['reason_code'] !== '' ? (string)$header['reason_code'] : null;
    $customReason = isset($header['custom_reason']) ? trim((string)$header['custom_reason']) : '';
    $liableUserId = !empty($header['liable_user_id']) ? (int)$header['liable_user_id'] : null;
    $allowedReasons = dl_allowedWithdrawalReasons();
    if ($reasonCode !== null && !in_array($reasonCode, $allowedReasons, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid reason_code'], 422);
        return;
    }
    if ($type === 'adjustment_add' && $liableUserId === null && dl_adjustmentAddNeedsLiable($reasonCode)) {
        $ctx->json(['ok' => false, 'error' => 'adjustment_add requires a liable_user_id (charge to person). Choose the Encoder omission reason if nothing was lost.'], 422);
        return;
    }
    if ($reasonCode === 'other' && $customReason === '') {
        $ctx->json(['ok' => false, 'error' => 'A custom reason is required when reason is Other.'], 422);
        return;
    }
    if ($reasonCode !== 'other') {
        $customReason = '';
    }
    if ($type === 'pullout' && $targetBranchId !== null && $targetBranchId > 0) {
        $ctx->json(['ok' => false, 'error' => 'Pullouts that return goods to a commissary cannot be edited here. Ask a supervisor/admin to correct them.'], 422);
        return;
    }

    // A withdrawal row represents one product; editing accepts one product line.
    // Correction rows may carry a negative qty (minus prefix = reduce).
    $line = null;
    foreach ($lines as $l) {
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['quantity']) ? (int)$l['quantity'] : 0;
        if ($pid <= 0 || $qty === 0) {
            continue;
        }
        if ($line !== null) {
            $ctx->json(['ok' => false, 'error' => 'Editing supports exactly one product line per withdrawal row.'], 422);
            return;
        }
        try {
            $resolved = dl_resolveWithdrawalLineForType($ctx->db(), $pid, $qty, $l['unit'] ?? 'pcs', $type);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 422) {
                $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
                return;
            }
            throw $e;
        }
        if ($resolved['quantity'] !== 0) {
            $line = [
                'product_id' => $pid,
                'quantity' => $resolved['quantity'],
                'unit' => $resolved['unit'],
                'pack_qty' => $resolved['pack_qty'],
            ];
        }
    }
    if ($line === null) {
        $ctx->json(['ok' => false, 'error' => 'A product with a quantity greater than 0 is required.'], 422);
        return;
    }

    $businessDate = dl_businessDate();
    $isAdminUser = $role === 'admin' || dl_isKernelAdmin($user);
    if ($date !== $businessDate && !$isAdminUser) {
        $ctx->json(['ok' => false, 'error' => 'Only the current business date can be edited by this role.'], 403);
        return;
    }

    $db = $ctx->db();
    $actorId = dl_getActorUserId($user);

    $db->beginTransaction();
    try {
        // Load + lock the target row.
        $load = $db->prepare('SELECT * FROM dl_cashier_withdrawals WHERE id = :id FOR UPDATE');
        $load->execute([':id' => $withdrawalId]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Withdrawal not found', 404);
        }
        if ((int)$row['branch_id'] !== $branchId) {
            throw new RuntimeException('Withdrawal does not belong to this branch.', 403);
        }
        $rowActor = (int)($row['encoded_by'] ?? 0);
        if ($role === 'cashier' && ($rowActor <= 0 || $rowActor !== $actorId)) {
            throw new RuntimeException('You can only edit withdrawals you encoded.', 403);
        }
        if ((string)$row['ledger_date'] !== $date) {
            throw new RuntimeException('Withdrawal ledger date does not match the selected date.', 422);
        }
        $rowType = (string)($row['withdrawal_type'] ?? '');
        if (!in_array($rowType, ['charge', 'pullout', 'adjustment_add', 'used', 'correction'], true)) {
            throw new RuntimeException('This withdrawal type cannot be edited.', 422);
        }
        if ($row['received_at'] !== null && $row['received_at'] !== '') {
            throw new RuntimeException('Received delivery rows cannot be edited.', 422);
        }
        $rowTarget = (int)($row['target_branch_id'] ?? 0);
        if ($rowType === 'pullout' && $rowTarget > 0) {
            throw new RuntimeException('Pullouts that already returned goods to a commissary cannot be edited here. Ask a supervisor/admin to correct them.', 422);
        }
        $pid = (int)$row['product_id'];
        if ($line['product_id'] !== $pid) {
            throw new RuntimeException('Changing the product of an existing withdrawal is not supported. Void the row and re-enter.', 422);
        }
        $oldQty = (int)$row['quantity'];
        $newQty = $line['quantity'];
        $newUnit = $line['unit'];
        $newPackQty = $line['pack_qty'];
        // The ledger row a withdrawal fed is shift-scoped. Prefer the shift the
        // row was encoded under; legacy rows (shift NULL) fall back to the
        // actor's currently resolved shift (best-effort).
        $rowShift = ($row['shift'] !== null && $row['shift'] !== '') ? (string)$row['shift'] : $shift;

        // Authoritative day-open + shift-mutable re-check under lock.
        $lockStatus = dl_lockDayStatusRow($db, $branchId, $date);
        if ($lockStatus === 'closed' && !$isAdminUser && !dl_roleHasPermission($role, 'ledger.override')) {
            throw new RuntimeException('Day is closed', 403);
        }
        dl_assertShiftMutable($db, $branchId, $date, $rowShift);

        // CONTENT-only fingerprint, deliberately keyless: this check asks "does ANOTHER row
        // already hold this content", so the content IS the identity here. Passing a
        // submission key would make the comparison meaningless.
        $newDedup = dl_withdrawalDedupHash(
            $branchId, $pid, $date, $type,
            $reasonCode,
            $customReason !== '' ? $customReason : null,
            $drNumber,
            $targetBranchId,
            $newQty,
            $liableUserId,
            $newUnit,
            $rowShift
        );
        // If the recomputed fingerprint matches a DIFFERENT existing row the
        // table-unique dedup index would reject the UPDATE — surface it as a
        // clear 409 instead of a bare DB error.
        $dup = $db->prepare('SELECT id FROM dl_cashier_withdrawals WHERE dedup_hash = :h AND id <> :id LIMIT 1');
        $dup->execute([':h' => $newDedup, ':id' => $withdrawalId]);
        if ($dup->fetchColumn()) {
            $db->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Another identical adjustment already exists for this product, date and quantity. Remove it instead of duplicating.'], 409);
            return;
        }

        $oldRowForAudit = $row;

        $db->prepare(
            'UPDATE dl_cashier_withdrawals
                SET withdrawal_type = :typ, reason_code = :rc, custom_reason = :crc,
                    dr_number = :dr, target_branch_id = :tbid, quantity = :qty,
                    unit = :unit, pack_qty = :pack_qty, liable_user_id = :luid,
                    liable_user_name = :luid_name,
                    shift = :shift, dedup_hash = :dedup, updated_at = NOW()
              WHERE id = :id'
        )->execute([
            ':typ' => $type,
            ':rc' => $reasonCode,
            ':crc' => $customReason !== '' ? $customReason : null,
            ':dr' => $drNumber,
            ':tbid' => $targetBranchId !== null && $targetBranchId > 0 ? $targetBranchId : null,
            ':qty' => $newQty,
            ':unit' => $newUnit,
            ':pack_qty' => $newPackQty,
            ':luid' => $liableUserId,
            // Re-snapshot on edit: the operator is naming the person right now.
            ':luid_name' => dl_userDisplayNameById($db, $liableUserId),
            ':shift' => $rowShift,
            ':dedup' => $newDedup,
            ':id' => $withdrawalId,
        ]);

        // ── Recompute the shift-scoped daily-ledger row ─────────────
        // Both columns accumulate from multiple sources. Apply old→new deltas;
        // rebuilding withdraw from cashier rows would erase dispatch quantities.
        $oldIsAddtl = ($rowType === 'adjustment_add');
        $newIsAddtl = ($type === 'adjustment_add');

        $ledgerCheck = $db->prepare(
            'SELECT id, addtl, withdraw FROM dl_daily_ledger
              WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift
              FOR UPDATE'
        );
        $ledgerCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $rowShift]);
        $ledgerRow = $ledgerCheck->fetch(PDO::FETCH_ASSOC);

        $hasLedgerRow = is_array($ledgerRow);
        $curAddtl = $hasLedgerRow ? (int)$ledgerRow['addtl'] : 0;
        $curWithdraw = $hasLedgerRow ? (int)$ledgerRow['withdraw'] : 0;
        $nextAddtl = $curAddtl - ($oldIsAddtl ? $oldQty : 0) + ($newIsAddtl ? $newQty : 0);
        $nextWithdraw = $curWithdraw - (!$oldIsAddtl ? $oldQty : 0) + (!$newIsAddtl ? $newQty : 0);

        if ($oldIsAddtl || $newIsAddtl) {
            if ($nextAddtl < 0) {
                // Rejected, not clamped. Clamping to zero would store the edited row
                // while leaving addtl short of what the row claims, so the ledger and
                // the recorded evidence would silently disagree — the operator would
                // see "saved" on a correction that did not fully apply.
                $db->rollBack();
                $ctx->json([
                    'ok' => false,
                    'error' => 'This change would take additional stock below zero (recorded: ' . $curAddtl
                        . '). Reduce the quantity, or correct the earlier entry instead.',
                ], 422);
                return;
            }
        }
        if ($nextWithdraw < 0) {
            $db->rollBack();
            $ctx->json([
                'ok' => false,
                'error' => 'This change would take withdrawals below zero (recorded: ' . $curWithdraw
                    . '). Reduce the quantity, or correct the earlier entry instead.',
            ], 422);
            return;
        }

        if ($hasLedgerRow) {
            $db->prepare('UPDATE dl_daily_ledger SET addtl = :a, withdraw = :w, updated_by = :u WHERE id = :id')
                ->execute([':a' => $nextAddtl, ':w' => $nextWithdraw, ':u' => $actorId, ':id' => (int)$ledgerRow['id']]);
        } else {
            $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
            $db->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, withdraw, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :addtl, :withdraw, :u_enc, :u_upd)'
            )->execute([
                ':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $rowShift,
                ':prc' => $price, ':addtl' => $nextAddtl, ':withdraw' => $nextWithdraw,
                ':u_enc' => $actorId, ':u_upd' => $actorId,
            ]);
        }

        // Editing a withdrawal rewrites addtl and/or withdraw, so the stored sales
        // value has to follow it for the same reason as the create path above.
        dl_recomputeSales($branchId, $pid, $date, $actorId, $rowShift);

        $db->commit();

        dl_auditLog('withdrawal_updated', $branchId, 'dl_cashier_withdrawals', (string)$withdrawalId, $oldRowForAudit, [
            'withdrawal_type' => $type,
            'reason_code' => $reasonCode,
            'custom_reason' => $customReason !== '' ? $customReason : null,
            'dr_number' => $drNumber,
            'target_branch_id' => $targetBranchId,
            'quantity' => $newQty,
            'unit' => $newUnit,
            'pack_qty' => $newPackQty,
            'liable_user_id' => $liableUserId,
            'liable_user_name' => dl_userDisplayNameById($db, $liableUserId),
            'shift' => $rowShift,
            'context' => 'cashier_retry_correction',
        ]);
        dl_recomputeVariancesForDay($branchId, $date);

        $ctx->json([
            'ok' => true, 'withdrawal_id' => $withdrawalId, 'quantity' => $newQty, 'unit' => $newUnit,
            'totals' => [
                ['product_id' => $pid, 'field' => 'addtl', 'result' => $nextAddtl],
                ['product_id' => $pid, 'field' => 'withdraw', 'result' => $nextWithdraw],
            ],
        ]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $code = $e instanceof RuntimeException ? $e->getCode() : 0;
        $status = in_array($code, [403, 404, 409, 422], true) ? $code : 400;
        $ctx->log('apiUpdateCashierWithdrawal error: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $status);
    }
}

function apiCreateCashierDispatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser();
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled for branch deliveries.'], 403);
        return;
    }

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $originBranchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $deliveryDate = (string)($input['delivery_date'] ?? dl_businessDate());
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $destType = (string)($input['destination_type'] ?? 'branch');
    $destId = (int)($input['destination_id'] ?? $input['target_branch_id'] ?? 0);
    $items = dl_normalizeDeliveryItems((array)($input['items'] ?? []));
    $role = (string)($user['role'] ?? '');
    $actorId = dl_getActorUserId($user);

    if ($originBranchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing source branch.'], 422);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid delivery date.'], 422);
        return;
    }
    if ($drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);
        return;
    }
    if ($destType !== 'branch') {
        $ctx->json(['ok' => false, 'error' => 'Invalid destination type.'], 422);
        return;
    }
    if ($destId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'A destination is required.'], 422);
        return;
    }
    $destBranchStmt = $ctx->db()->prepare('SELECT id, is_active FROM dl_branches WHERE id = :id LIMIT 1');
    $destBranchStmt->execute([':id' => $destId]);
    $destBranch = $destBranchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$destBranch || (int)($destBranch['is_active'] ?? 0) !== 1) {
        $ctx->json(['ok' => false, 'error' => 'Destination branch no longer exists or is inactive. Refresh the page and choose a current branch.'], 422);
        return;
    }
    if ($destType === 'branch' && $destId === $originBranchId) {
        $ctx->json(['ok' => false, 'error' => 'A different destination branch is required.'], 422);
        return;
    }
    if ($items === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required.'], 422);
        return;
    }

    $dayStatus = dl_getDayStatus($originBranchId, $deliveryDate);
    if ($role === 'cashier' && $deliveryDate !== dl_businessDate()) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'ledger.override')) {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }

    $dupStmt = $ctx->db()->prepare(
        'SELECT id, status
           FROM dl_deliveries
          WHERE origin_type = :origin_type
            AND origin_id = :origin_id
            AND destination_type = :destination_type
            AND destination_id = :destination_id
            AND dr_number = :dr_number
            AND status <> "voided"
          ORDER BY id DESC
          LIMIT 1'
    );
    $dupStmt->execute([
        ':origin_type' => 'branch',
        ':origin_id' => $originBranchId,
        ':destination_type' => $destType,
        ':destination_id' => $destId,
        ':dr_number' => $drNumber,
    ]);
    $dup = $dupStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($dup) {
        $ctx->json(['ok' => false, 'error' => 'This paper DR already exists in the system. Use Receive Stock on the destination branch.'], 422);
        return;
    }

    $priceGroupId = dl_defaultPriceGroupId();

    $ctx->db()->beginTransaction();
    try {
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $ins->execute([
            ':ot' => 'branch',
            ':oid' => $originBranchId,
            ':dt' => $destType,
            ':did' => $destId,
            ':dr' => $drNumber,
            ':dd' => $deliveryDate,
            ':created_by' => $actorId ?: null,
            ':posted_by' => $actorId ?: null,
            ':remarks' => dl_cashierDispatchRemark(),
        ]);
        $deliveryId = (int)$ctx->db()->lastInsertId();

        $itemStmt = $ctx->db()->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $item['product_id'],
                ':quantity' => $item['quantity'],
                ':unit' => $item['unit'],
                ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
                ':price_snapshot' => dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, $deliveryDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => $item['remarks'],
            ]);
            dl_applyLedgerDelta($originBranchId, (int)$item['product_id'], $deliveryDate, (int)$item['quantity'], $actorId, 'withdraw', $shift);
        }

        $ctx->db()->commit();
        dl_auditLog('create_delivery', $originBranchId, 'dl_deliveries', (string)$deliveryId, null, [
            'destination_type' => $destType,
            'destination_id' => $destId,
            'items' => count($items),
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'cashier_dispatch',
        ]);
        $ctx->json(['ok' => true, 'delivery_id' => $deliveryId]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiGetIncomingDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    if (!$branchId) { $ctx->json(['ok' => true, 'deliveries' => []]); return; }

    $drFilter = isset($_GET['dr_number']) ? trim((string)$_GET['dr_number']) : '';

    $sql = 'SELECT cw.id, cw.dr_number, cw.ledger_date, cw.shift AS production_shift, cw.quantity, cw.branch_id AS origin_branch_id,
                   ob.name AS origin_branch_name, cw.product_id, p.name AS product_name
            FROM dl_cashier_withdrawals cw
            INNER JOIN dl_branches ob ON ob.id = cw.branch_id
            INNER JOIN dl_products p ON p.id = cw.product_id
            WHERE cw.target_branch_id = :bid
              AND cw.withdrawal_type = \'delivery\'
              AND cw.received_at IS NULL'
        . ($drFilter !== '' ? ' AND cw.dr_number = :dr_filter' : '')
        . ' ORDER BY cw.ledger_date DESC, cw.dr_number, cw.id';
    $stmt = $ctx->db()->prepare($sql);
    $bind = [':bid' => $branchId];
    if ($drFilter !== '') $bind[':dr_filter'] = $drFilter;
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Group by DR# (or by origin branch + date if DR# is null)
    $groups = [];
    foreach ($rows as $r) {
        $key = ($r['dr_number'] !== null && $r['dr_number'] !== '')
            ? 'dr:' . $r['dr_number'] . ':' . $r['origin_branch_id'] . ':' . ($r['production_shift'] ?? '')
            : 'orig:' . $r['origin_branch_id'] . ':' . $r['ledger_date'] . ':' . ($r['production_shift'] ?? '');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'group_key' => $key,
                'dr_number' => $r['dr_number'],
                'origin_branch_id' => (int)$r['origin_branch_id'],
                'origin_branch_name' => $r['origin_branch_name'],
                'ledger_date' => $r['ledger_date'],
                'production_shift' => in_array(($r['production_shift'] ?? null), ['AM', 'PM'], true) ? $r['production_shift'] : null,
                'items' => [],
                'ids' => [],
                'delivery_ids' => [],
            ];
        }
        $groups[$key]['items'][] = [
            'id' => (int)$r['id'],
            'product_id' => (int)$r['product_id'],
            'product_name' => $r['product_name'],
            'quantity' => (int)$r['quantity'],
        ];
        $groups[$key]['ids'][] = (int)$r['id'];
    }

    if (dl_isFormalDeliveryEnabled()) {
        $formalSql = 'SELECT d.id AS delivery_id, d.dr_number, d.delivery_date, d.production_shift,
                             d.origin_id AS origin_branch_id,
                             COALESCE(ob.name, cb.name, d.origin_type) AS origin_branch_name,
                             di.id AS delivery_item_id,
                             di.product_id, p.name AS product_name, di.quantity
                      FROM dl_deliveries d
                      INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                      INNER JOIN dl_products p ON p.id = di.product_id
                      LEFT JOIN dl_branches ob ON ob.id = d.origin_id AND d.origin_type = "branch"
                      LEFT JOIN dl_branches cb ON cb.id = d.origin_id AND d.origin_type = "commissary"
                      WHERE d.destination_type = "branch"
                        AND d.destination_id = :bid
                        AND d.status = "posted"
                        AND COALESCE(d.receipt_required, 1) = 1
                        AND NOT EXISTS (
                            SELECT 1 FROM dl_branch_receivings br
                            WHERE br.delivery_id = d.id AND br.status <> "voided"
                        )'
            . ($drFilter !== '' ? ' AND d.dr_number = :dr_filter' : '')
            . ' ORDER BY d.delivery_date DESC, d.dr_number, d.id';
        $formalBind = [':bid' => $branchId];
        if ($drFilter !== '') $formalBind[':dr_filter'] = $drFilter;
        $formalStmt = $ctx->db()->prepare($formalSql);
        $formalStmt->execute($formalBind);
        foreach ($formalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = 'delivery:' . (int)$row['delivery_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'group_key' => $key,
                    'dr_number' => $row['dr_number'],
                    'origin_branch_id' => $row['origin_branch_id'] !== null ? (int)$row['origin_branch_id'] : 0,
                    'origin_branch_name' => $row['origin_branch_name'],
                    'ledger_date' => $row['delivery_date'],
                    'production_shift' => in_array(($row['production_shift'] ?? null), ['AM', 'PM'], true) ? $row['production_shift'] : null,
                    'items' => [],
                    'ids' => [],
                    'delivery_ids' => [(int)$row['delivery_id']],
                ];
            }
            $groups[$key]['items'][] = [
                'id' => (int)$row['delivery_item_id'],
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'quantity' => (int)$row['quantity'],
            ];
        }
    }

    $deliveries = array_values($groups);
    usort($deliveries, static function (array $left, array $right): int {
        $dateCmp = strcmp((string)($right['ledger_date'] ?? ''), (string)($left['ledger_date'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        return strcmp((string)($left['group_key'] ?? ''), (string)($right['group_key'] ?? ''));
    });

    $ctx->json(['ok' => true, 'deliveries' => $deliveries]);
}

/**
 * Require a deliberate non-negative whole-number count for every receipt line.
 * Explicit zero and a count above the electronic dispatch are valid because the
 * paper DR is authoritative; absent, empty, fractional, or negative values are not.
 *
 * @param array<int,int> $sentByKey
 * @return array<int,int>
 */
function dl_requireReceiptCounts(array $sentByKey, mixed $provided): array
{
    if (!is_array($provided)) {
        throw new \RuntimeException('A counted Received value is required for every line.');
    }

    $counts = [];
    foreach ($sentByKey as $key => $sent) {
        if (!array_key_exists($key, $provided)) {
            throw new \RuntimeException('A counted Received value is required for every line.');
        }
        $raw = $provided[$key];
        if ((!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1))
            || (int)$raw < 0) {
            throw new \RuntimeException('Each Received value must be a non-negative whole number.');
        }
        $counts[(int)$key] = (int)$raw;
    }
    return $counts;
}

function dl_markReceivingCountBasis($db, int $receivingId, string $basis): void
{
    if (!in_array($basis, ['copied', 'independently_counted'], true)) {
        throw new \InvalidArgumentException('Invalid receipt count basis.');
    }
    $beforeStmt = $db->prepare('SELECT branch_id, count_basis FROM dl_branch_receivings WHERE id = :id');
    $beforeStmt->execute([':id' => $receivingId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $db->prepare('UPDATE dl_branch_receivings SET count_basis = :basis WHERE id = :id')
        ->execute([':basis' => $basis, ':id' => $receivingId]);
    if (($before['count_basis'] ?? null) !== $basis) {
        dl_auditLog('classify_receiving_count_basis', (int)($before['branch_id'] ?? 0) ?: null,
            'dl_branch_receivings', (string)$receivingId,
            ['count_basis' => $before['count_basis'] ?? null], ['count_basis' => $basis]);
    }
}

/**
 * Receive formal lines by delivery-item id. The shared legacy boundary accepts
 * product-keyed counts, so duplicate product lines are corrected inside the same
 * transaction and their ledger deltas are adjusted before commit.
 *
 * @param array<int,int> $countsByDeliveryItem
 */
function dl_acceptFormalDeliveryByItem($db, int $branchId, int $deliveryId, int $userId, string $receiveDate, array $countsByDeliveryItem, string $shift): int
{
    $stmt = $db->prepare('SELECT id, product_id, quantity FROM dl_delivery_items WHERE delivery_id = :delivery ORDER BY id');
    $stmt->execute([':delivery' => $deliveryId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sentByItem = [];
    foreach ($lines as $line) {
        $sentByItem[(int)$line['id']] = (int)$line['quantity'];
    }
    $counts = dl_requireReceiptCounts($sentByItem, $countsByDeliveryItem);

    // Seed values only exist until the item-linked corrections below. The
    // minimum is valid for every duplicate line of the same product.
    $seedByProduct = [];
    foreach ($lines as $line) {
        $pid = (int)$line['product_id'];
        $itemId = (int)$line['id'];
        $seedByProduct[$pid] = isset($seedByProduct[$pid])
            ? min($seedByProduct[$pid], $counts[$itemId])
            : $counts[$itemId];
    }
    $receivingId = dl_acceptFormalDelivery($db, $branchId, $deliveryId, $userId, $receiveDate, $seedByProduct, $shift);

    $update = $db->prepare(
        'UPDATE dl_branch_receiving_items SET quantity_received = :quantity
          WHERE receiving_id = :receiving AND delivery_item_id = :item'
    );
    foreach ($lines as $line) {
        $itemId = (int)$line['id'];
        $pid = (int)$line['product_id'];
        $wanted = $counts[$itemId];
        $seed = $seedByProduct[$pid];
        if ($wanted !== $seed) {
            $update->execute([':quantity' => $wanted, ':receiving' => $receivingId, ':item' => $itemId]);
            dl_applyLedgerDelta($branchId, $pid, $receiveDate, $wanted - $seed, $userId, 'addtl', $shift);
        }
    }
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE receiving_id = :receiving')
        ->execute([':receiving' => $receivingId]);
    dl_recordReceivingVariances($receivingId);
    dl_markReceivingCountBasis($db, $receivingId, 'independently_counted');
    return $receivingId;
}

function apiReceiveDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $productionShift = strtoupper(trim((string)($input['production_shift'] ?? '')));
    if ($productionShift !== '' && !in_array($productionShift, ['AM', 'PM'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Production shift must be AM or PM.'], 422);
        return;
    }
    $ids = array_values(array_filter(array_map('intval', (array)($input['withdrawal_ids'] ?? []))));
    $deliveryIds = array_values(array_filter(array_map('intval', (array)($input['delivery_ids'] ?? []))));

    if (!$branchId || (count($ids) === 0 && count($deliveryIds) === 0)) {
        $ctx->json(['ok' => false, 'error' => 'Missing fields']);
        return;
    }

    $userId = dl_getActorUserId($user);
    $receiveDate = dl_businessDate();

    if (count($deliveryIds) > 0) {
        // Counts are keyed by delivery item, not product. Duplicate product
        // lines are separate physical lines and must retain separate counts.
        $hasCorrections = array_key_exists('partial_qtys', $input);
        $partialQtysMap = $hasCorrections && is_array($input['partial_qtys'])
            ? $input['partial_qtys']
            : [];
        $ctx->db()->beginTransaction();
        try {
            if ($hasCorrections && !is_array($input['partial_qtys'])) {
                throw new \RuntimeException('Corrected Received quantities must be supplied for every line.');
            }
            $receivedCount = 0;
            foreach ($deliveryIds as $deliveryId) {
                // The paper DR is authoritative. A receiver may correct the shift
                // prefilled from the dispatch before accepting the delivery.
                if ($productionShift !== '') {
                    $ctx->db()->prepare('UPDATE dl_deliveries SET production_shift = :shift WHERE id = :id')
                        ->execute([':shift' => $productionShift, ':id' => $deliveryId]);
                }
                if ($hasCorrections) {
                    $providedCounts = $partialQtysMap[$deliveryId] ?? $partialQtysMap[(string)$deliveryId] ?? null;
                    if (!is_array($providedCounts)) {
                        throw new \RuntimeException('A counted Received value is required for every line.');
                    }
                    $rcvId = dl_acceptFormalDeliveryByItem(
                        $ctx->db(), $branchId, $deliveryId, $userId, $receiveDate, $providedCounts, $shift
                    );
                } else {
                    // An untouched one-click confirmation deliberately copies the
                    // dispatched quantities. dl_acceptFormalDelivery records the
                    // honest `copied` basis and raises the uncounted notification.
                    $rcvId = dl_acceptFormalDelivery(
                        $ctx->db(), $branchId, $deliveryId, $userId, $receiveDate, null, $shift
                    );
                }
                if ($rcvId > 0) {
                    $receivedCount++;
                }
            }
            $ctx->db()->commit();
            dl_respondThenFlushMail([
                'ok' => true,
                'received_count' => $receivedCount,
                'receive_date' => $receiveDate,
                'received_shift' => $shift,
                'production_shift' => $productionShift !== '' ? $productionShift : null,
            ]);
            return;
        } catch (\Throwable $e) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
            return;
        }
    }

    // Optional per-item correction for informal transfers:
    // { withdrawal_id => received_qty }. Omission confirms the sent quantities.
    $hasInformalCorrections = array_key_exists('informal_partial_qtys', $input);
    $informalPartialQtys = $hasInformalCorrections && is_array($input['informal_partial_qtys'])
        ? $input['informal_partial_qtys']
        : [];

    // Make sure all ids are deliveries targeting this branch and not yet received.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $check = $ctx->db()->prepare(
        "SELECT id, product_id, quantity FROM dl_cashier_withdrawals
         WHERE id IN ($placeholders) AND target_branch_id = ? AND withdrawal_type = 'delivery' AND received_at IS NULL"
    );
    $check->execute(array_merge($ids, [$branchId]));
    $rows = $check->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($rows) === 0 || count($rows) !== count($ids)) {
        $ctx->json(['ok' => false, 'error' => 'Nothing to receive']);
        return;
    }

    $sentByWithdrawal = [];
    foreach ($rows as $r) {
        $sentByWithdrawal[(int)$r['id']] = (int)$r['quantity'];
    }
    try {
        if ($hasInformalCorrections && !is_array($input['informal_partial_qtys'])) {
            throw new \RuntimeException('Corrected Received quantities must be supplied for every line.');
        }
        $rowReceivedQtys = $hasInformalCorrections
            ? dl_requireReceiptCounts($sentByWithdrawal, $informalPartialQtys)
            : $sentByWithdrawal;
    } catch (\RuntimeException $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    $ctx->db()->beginTransaction();
    try {
        // Sum received pcs per product (using actual received qty, not sent qty)
        $perProduct = [];
        foreach ($rows as $r) {
            $pid = (int)$r['product_id'];
            $perProduct[$pid] = ($perProduct[$pid] ?? 0) + $rowReceivedQtys[(int)$r['id']];
        }

        // Mark each row received, storing the actual received_qty
        $markIndiv = $ctx->db()->prepare(
            "UPDATE dl_cashier_withdrawals
             SET received_at = NOW(), received_by = ?, received_ledger_date = ?, received_shift = ?, received_qty = ?
             WHERE id = ?"
        );
        $foundIds = [];
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            $sentQty = (int)$r['quantity'];
            $receivedQty = $rowReceivedQtys[$rid];
            $markIndiv->execute([$userId, $receiveDate, $shift, $receivedQty, $rid]);
            $foundIds[] = $rid;
            if ($receivedQty !== $sentQty) {
                dl_raiseIntegrityNotification(
                    $ctx->db(),
                    'informal-receipt-mismatch-' . $rid,
                    'receipt_mismatch',
                    $branchId,
                    'dl_cashier_withdrawals',
                    $rid,
                    'Branch transfer receipt differs from dispatch',
                    'Withdrawal #' . $rid . ' was sent as ' . $sentQty . ' and received as ' . $receivedQty . '.',
                    false
                );
            }
        }

        // Apply to dl_daily_ledger.addtl for receive date (shift-scoped: each
        // shift receives into its own row).
        $stmtCheck = $ctx->db()->prepare(
            'SELECT id, addtl FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );
        $stmtUpd = $ctx->db()->prepare(
            'UPDATE dl_daily_ledger SET addtl = :addtl, updated_by = :uid
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
        );
        $stmtInit = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :prc, :addtl, :uid_enc, :uid_upd)'
        );

        foreach ($perProduct as $pid => $qty) {
            $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $newAddtl = (int)$existing['addtl'] + (int)$qty;
                $stmtUpd->execute([':addtl' => $newAddtl, ':uid' => $userId, ':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift]);
            } else {
                $price = dl_resolveBranchProductPrice($branchId, (int)$pid, $receiveDate);
                $stmtInit->execute([
                    ':bid' => $branchId, ':pid' => $pid, ':d' => $receiveDate, ':shift' => $shift,
                    ':prc' => $price, ':addtl' => (int)$qty,
                    ':uid_enc' => $userId, ':uid_upd' => $userId,
                ]);
            }
        }

        $ctx->db()->commit();
        dl_respondThenFlushMail([
            'ok' => true,
            'received_count' => count($foundIds),
            'receive_date' => $receiveDate,
            'received_shift' => $shift,
            'production_shift' => $productionShift !== '' ? $productionShift : null,
        ]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiReceiveDelivery error: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error']);
    }
}

/**
 * Delayed producer entry (066): normalize an optional produced-at value.
 *
 * Accepts a date (YYYY-MM-DD) or a date-time (YYYY-MM-DD HH:MM[:SS], with a
 * T separator tolerated because datetime-local posts one). Returns the value in
 * the DATETIME shape MySQL stores, or null when the value is empty or malformed.
 */
function dl_normalizeProducedAt(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)) {
        return null;
    }
    if (strlen($value) === 10) {
        $value .= ' 00:00:00';
    } elseif (strlen($value) === 16) {
        $value .= ':00';
    }
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $value) {
        return null;
    }
    return $value;
}

/**
 * Delayed producer entry (066): the people who may be recorded as the producer
 * of a paper DR — production-in-charge first, then the branch-independent
 * oversight roles. This is server-side so the template never hard-codes a list.
 *
 * @return array<int, array{id:int, name:string, role:string, label:string}>
 */
function dl_productionProducerOptions($db): array
{
    $stmt = $db->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.full_name, ''), u.username, CONCAT('User #', u.id)) AS name,
                u.role
           FROM dl_users u
          WHERE u.is_active = 1
            AND u.deleted_at IS NULL
            AND u.role IN ('production_in_charge', 'supervisor', 'admin')
          ORDER BY (u.role = 'production_in_charge') DESC, name ASC"
    );
    $stmt->execute();

    $options = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $name = (string)$row['name'];
        $role = (string)$row['role'];
        $options[] = [
            'id' => (int)$row['id'],
            'name' => $name,
            'role' => $role,
            'label' => $name . ' (' . $role . ')',
        ];
    }
    return $options;
}

function apiReceivePaperDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cached = dl_loadIdempotentResponse('receive_paper_dr', $idempotencyKey);
        if ($cached !== null) {
            $ctx->json($cached);
            return;
        }
    }
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $destinationBranchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    // New clients must send the paper-DR choice. For pre-071 queued/client
    // payloads where the key does not exist at all, retain compatibility by
    // recording the server-resolved shift rather than creating another NULL.
    $productionShift = array_key_exists('production_shift', $input)
        ? strtoupper(trim((string)$input['production_shift']))
        : $shift;
    $originType = (string)($input['origin_type'] ?? 'commissary');
    $originId = isset($input['origin_id']) && $input['origin_id'] !== '' ? (int)$input['origin_id'] : null;
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $autoDr = (int)(bool)($input['auto_dr'] ?? false);
    if ($autoDr) {
        // Server-authoritative: an auto-DR receive never reuses a typed paper DR.
        $drNumber = '';
    }
    $deliveryDate = (string)($input['delivery_date'] ?? dl_businessDate());
    $receiveDate = (string)($input['receive_date'] ?? dl_businessDate());
    $items = dl_normalizeDeliveryItems((array)($input['items'] ?? []));
    $actorId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');
    $isAdminUser = $role === 'admin' || dl_isKernelAdmin($user);

    if (!in_array($productionShift, ['AM', 'PM'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Production shift from the paper DR is required.'], 422);
        return;
    }
    if ($destinationBranchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing destination branch.'], 422);
        return;
    }
    if (!in_array($originType, ['branch', 'commissary'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid origin type.'], 422);
        return;
    }
    if (($originType === 'branch' && (($originId ?? 0) <= 0 || $originId === $destinationBranchId))
        || ($originType === 'commissary' && (($originId ?? 0) > 0 && $originId === $destinationBranchId))) {
        $ctx->json(['ok' => false, 'error' => 'A different source branch is required.'], 422);
        return;
    }
    if ($drNumber === '') {
        if ($autoDr) {
            // Production receive with no paper DR: the server mints the auto DR
            // below. Most tenants receive produced goods from a generic external
            // commissary (origin_id null) that is not modelled as a branch. When a
            // specific commissary branch IS selected, it must be an active
            // production site.
            if ($originType !== 'commissary') {
                $ctx->json(['ok' => false, 'error' => 'Auto DR is only available when receiving from a production (commissary) branch.'], 422);
                return;
            }
            if (($originId ?? 0) > 0) {
                if ($originId === $destinationBranchId) {
                    $ctx->json(['ok' => false, 'error' => 'A different production (commissary) source branch is required.'], 422);
                    return;
                }
                if (!dl_branchIsProductionSite($ctx->db(), (int)$originId)) {
                    $ctx->json(['ok' => false, 'error' => 'The selected source branch is not an active production (commissary) site.'], 422);
                    return;
                }
            }
        } else {
            $ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);
            return;
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiveDate)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid date.'], 422);
        return;
    }
    if ($items === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required.'], 422);
        return;
    }

    // Delayed producer entry (066): both fields are optional. An absent or empty
    // value means "not recorded" and leaves the columns NULL; a supplied value is
    // validated here and refused with 422 rather than silently ignored.
    $producedBy = null;
    $producedByRaw = $input['produced_by'] ?? null;
    if ($producedByRaw !== null && !is_scalar($producedByRaw)) {
        $ctx->json(['ok' => false, 'error' => 'Produced by must be a valid user id.'], 422);
        return;
    }
    if ($producedByRaw !== null && trim((string)$producedByRaw) !== '') {
        if (!is_numeric($producedByRaw) || (int)$producedByRaw <= 0) {
            $ctx->json(['ok' => false, 'error' => 'Produced by must be a valid user id.'], 422);
            return;
        }
        $producedBy = (int)$producedByRaw;
        $producerStmt = $ctx->db()->prepare(
            'SELECT id FROM dl_users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $producerStmt->execute([':id' => $producedBy]);
        if (!$producerStmt->fetchColumn()) {
            $ctx->json(['ok' => false, 'error' => 'Produced by must be an existing, active user.'], 422);
            return;
        }
    }
    $producedAt = null;
    $producedAtRaw = $input['produced_at'] ?? null;
    if ($producedAtRaw !== null && !is_scalar($producedAtRaw)) {
        $ctx->json(['ok' => false, 'error' => 'Produced at must be a valid date or date-time.'], 422);
        return;
    }
    if ($producedAtRaw !== null && trim((string)$producedAtRaw) !== '') {
        $producedAt = dl_normalizeProducedAt((string)$producedAtRaw);
        if ($producedAt === null) {
            $ctx->json(['ok' => false, 'error' => 'Produced at must be a valid date or date-time.'], 422);
            return;
        }
    }

    $businessDate = dl_businessDate();
    if ($role === 'cashier' && $receiveDate !== $businessDate) {
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($originType === 'branch' && !$isAdminUser && $deliveryDate !== $businessDate) {
        $ctx->json(['ok' => false, 'error' => 'Admin required for late branch paper DR capture'], 403);
        return;
    }
    if ($autoDr && $role === 'cashier' && ($deliveryDate !== $businessDate || $receiveDate !== $businessDate)) {
        $ctx->json(['ok' => false, 'error' => 'Cashiers can only auto-DR receive production stock on the current business date.'], 403);
        return;
    }

    $receiveDayStatus = dl_getDayStatus($destinationBranchId, $receiveDate);
    if ($receiveDayStatus === 'closed' && !dl_roleHasPermission($role, 'ledger.override')) {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }
    if ($originType === 'branch' && $originId !== null) {
        $originDayStatus = dl_getDayStatus((int)$originId, $deliveryDate);
        if ($originDayStatus === 'closed' && !$isAdminUser) {
            $ctx->json(['ok' => false, 'error' => 'Admin required for closed source-branch paper DR capture'], 403);
            return;
        }
    }

    $existing = null;
    if (!$autoDr) {
        // Date-scoped deliberately: a paper capture is a document for one
        // delivery_date. Matching the DR alone would let a capture update a
        // different day's dispatch (and now write a producer onto it), which is
        // an evidence-integrity hazard. See dl_findPaperCapturedCommissaryDelivery
        // for the date+remark-scoped helper this mirrors (date scoping only here).
        $findStmt = $ctx->db()->prepare(
            'SELECT id, status, produced_by, produced_at, production_shift
               FROM dl_deliveries
              WHERE destination_type = :destination_type
                AND destination_id = :destination_id
                AND delivery_date = :delivery_date
                AND dr_number = :dr_number
                AND status <> "voided"
              ORDER BY id DESC
              LIMIT 1'
        );
        $findStmt->execute([
            ':destination_type' => 'branch',
            ':destination_id' => $destinationBranchId,
            ':delivery_date' => $deliveryDate,
            ':dr_number' => $drNumber,
        ]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $ctx->db()->beginTransaction();
    try {
        // Auto-DR production receive: mint the label while holding the
        // destination day-status lock so per-branch/date sequence numbers
        // cannot collide under concurrency.
        if ($autoDr) {
            $lockStatus = dl_lockDayStatusRow($ctx->db(), $destinationBranchId, $receiveDate);
            if ($lockStatus === 'closed' && !$isAdminUser && !dl_roleHasPermission($role, 'ledger.override')) {
                throw new \RuntimeException('Day is closed');
            }
            $drNumber = dl_mintAutoDrNumber($ctx->db(), $destinationBranchId, $deliveryDate);
        }

        if ($existing && dl_deliveryHasActiveReceivings($ctx->db(), (int)$existing['id'])) {
            throw new \RuntimeException('This paper DR was already received.');
        }

        $deliveryId = $existing ? (int)$existing['id'] : 0;
        if (!$existing) {
            $priceGroupId = dl_defaultPriceGroupId();
            $ins = $ctx->db()->prepare(
                'INSERT INTO dl_deliveries
                    (origin_type, origin_id, destination_type, destination_id, dr_number,
                     delivery_date, production_shift, status, created_by, posted_by, posted_at, remarks, provenance_status,
                     produced_by, produced_at)
                 VALUES (:ot, :oid, :dt, :did, :dr, :dd, :production_shift, "posted", :created_by, :posted_by, NOW(), :remarks, :provenance_status,
                         :produced_by, :produced_at)'
            );
            $ins->execute([
                ':ot' => $originType,
                ':oid' => $originId,
                ':dt' => 'branch',
                ':did' => $destinationBranchId,
                ':dr' => $drNumber,
                ':dd' => $deliveryDate,
                ':production_shift' => $productionShift,
                ':created_by' => $actorId ?: null,
                ':posted_by' => $actorId ?: null,
                ':remarks' => $autoDr ? '[auto-dr-production]' : dl_paperDrCaptureRemark(),
                ':provenance_status' => $autoDr ? 'none' : 'paper_dr_pending',
                ':produced_by' => $producedBy,
                ':produced_at' => $producedAt,
            ]);
            $deliveryId = (int)$ctx->db()->lastInsertId();

            $itemStmt = $ctx->db()->prepare(
                'INSERT INTO dl_delivery_items
                    (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                 VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':delivery_id' => $deliveryId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit' => $item['unit'],
                    ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
                    ':price_snapshot' => dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, $deliveryDate),
                    ':price_group_id' => $priceGroupId,
                    ':remarks' => $item['remarks'],
                ]);
                if ($originType === 'branch' && $originId !== null) {
                    dl_applyLedgerDelta((int)$originId, (int)$item['product_id'], $deliveryDate, (int)$item['quantity'], $actorId, 'withdraw', $productionShift);
                }
            }

            dl_auditLog('create_delivery', $originType === 'branch' ? (int)$originId : null, 'dl_deliveries', (string)$deliveryId, null, [
                'destination_type' => 'branch',
                'destination_id' => $destinationBranchId,
                'items' => count($items),
                'dr_number' => $drNumber,
                'status' => 'posted',
                'source' => $autoDr ? 'auto_dr_production' : 'captured_from_paper_dr',
                'auto_dr' => $autoDr ? 1 : 0,
                'produced_by' => $producedBy,
                'produced_at' => $producedAt,
                'production_shift' => $productionShift,
                'received_shift' => $shift,
            ]);
        } else {
            // The capture already exists. Delayed producer entry (066): update a
            // supplied producer, but never null out one that was recorded earlier,
            // so a replay that omits the fields cannot erase the paper-sheet data.
            $set = ['production_shift = :production_shift'];
            $params = [':id' => $deliveryId, ':production_shift' => $productionShift];
            if ((string)$existing['status'] === 'draft') {
                $set[] = 'status = "posted"';
                $set[] = 'posted_by = :u';
                $set[] = 'posted_at = NOW()';
                $params[':u'] = $actorId ?: null;
            }
            if ($producedBy !== null) {
                $set[] = 'produced_by = :produced_by';
                $params[':produced_by'] = $producedBy;
            }
            if ($producedAt !== null) {
                $set[] = 'produced_at = :produced_at';
                $params[':produced_at'] = $producedAt;
            }
            if ($set !== []) {
                $ctx->db()->prepare('UPDATE dl_deliveries SET ' . implode(', ', $set) . ' WHERE id = :id')
                    ->execute($params);
            }
            $oldProducedBy = $existing['produced_by'] !== null ? (int)$existing['produced_by'] : null;
            $oldProducedAt = $existing['produced_at'] !== null ? (string)$existing['produced_at'] : null;
            $producerChanged = ($producedBy !== null && $producedBy !== $oldProducedBy)
                || ($producedAt !== null && $producedAt !== $oldProducedAt);
            if ($producerChanged) {
                dl_auditLog('update_delivery', $originType === 'branch' ? (int)$originId : null, 'dl_deliveries', (string)$deliveryId, [
                    'produced_by' => $oldProducedBy,
                    'produced_at' => $oldProducedAt,
                ], [
                    'produced_by' => $producedBy ?? $oldProducedBy,
                    'produced_at' => $producedAt ?? $oldProducedAt,
                    'source' => 'delayed_producer_entry',
                    'dr_number' => $drNumber,
                ], 'producer recorded from paper DR');
            }
        }

        $ledgerEffect = dl_applyPostedDeliveryCommissaryLedger($ctx->db(), $deliveryId, $actorId);
        $receivingId = dl_acceptFormalDelivery($ctx->db(), $destinationBranchId, $deliveryId, $actorId, $receiveDate, null, $shift);
        // Paper capture and auto-DR both copy SENT into RECEIVED. Preserve the
        // quantity, but label its evidentiary basis honestly.
        dl_markReceivingCountBasis($ctx->db(), $receivingId, 'copied');
        $ctx->db()->commit();
        $response = [
            'ok' => true,
            'delivery_id' => $deliveryId,
            'receiving_id' => $receivingId,
            'ledger_effect' => $ledgerEffect,
            'production_shift' => $productionShift,
            'received_shift' => $shift,
        ];
        if ($idempotencyKey !== '') {
            dl_storeIdempotentResponse('receive_paper_dr', $idempotencyKey, $response, 86400);
        }
        dl_respondThenFlushMail($response);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiSaveLedgerField(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    header('Content-Type: application/json');

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input     = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId  = $authResult['branch_id'];
    $productId = (int)($input['product_id'] ?? 0);
    $field     = (string)($input['field'] ?? '');
    $rawValue  = $input['value'] ?? null;
    $date      = (string)($input['date'] ?? dl_businessDate());
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift     = $shiftResolved['shift'];
    $userId    = dl_getActorUserId($user);
    if ($userId <= 0) {
        write_log('daily-ledger save auth required', 'error', [
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'user' => [
                'id' => $user['id'] ?? null,
                'sub' => $user['sub'] ?? null,
                'role' => $user['role'] ?? null,
                'source' => $user['source'] ?? null,
                'username' => $user['username'] ?? null,
            ],
            'auth_header_present' => (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])),
            'cookie_present' => (is_string(kernelCookie(dlCookieName())) && kernelCookie(dlCookieName()) !== ''),
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    // Validate field name and value — sales is derived, never client-writable
    $fieldMap = [
        'beg_bal' => 'beg_bal',
        'addtl' => 'addtl',
        'withdraw' => 'withdraw',
        'bal_end' => 'bal_end',
    ];
    $column = dl_allowedColumn($field, $fieldMap);
    if ($column === null || !$branchId || !$productId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }
    // bal_end accepts null (ending not yet counted). Every other field is an int.
    if ($column === 'bal_end' && ($rawValue === null || $rawValue === '')) {
        $value = null;
    } elseif ($column === 'bal_end' && !is_numeric($rawValue)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Value must be a number', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Value must be a number'], 422);
        return;
    } else {
        $value = (int)$rawValue;
    }
    if ($value !== null && ($value < 0 || $value > 999999999)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Value out of bounds', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Value out of bounds'], 422);
        return;
    }

    // Production-lock guard: cashier cannot overwrite addtl/withdraw set by a production movement
    if ($role === 'cashier' && in_array($field, ['addtl', 'withdraw'], true)) {
        $movementType = $field === 'addtl' ? 'output' : 'withdrawal';
        $lockStmt = $ctx->db()->prepare(
            'SELECT COUNT(*) FROM dl_production_movements pm
             WHERE pm.destination_branch_id = :bid
               AND pm.product_id = :pid
               AND pm.ledger_date = :d
               AND pm.movement_type = :mtype
               AND NOT EXISTS (
                   SELECT 1 FROM dl_production_movements r
                   WHERE r.reference_movement_id = pm.id AND r.movement_type = :rev
               )'
        );
        $lockStmt->execute([
            ':bid'   => $branchId,
            ':pid'   => $productId,
            ':d'     => $date,
            ':mtype' => $movementType,
            ':rev'   => 'reverse',
        ]);
        if ((int)$lockStmt->fetchColumn() > 0) {
            write_log('daily-ledger cashier override blocked', 'warning', [
                'branch_id'       => $branchId,
                'product_id'      => $productId,
                'date'            => $date,
                'field'           => $field,
                'attempted_value' => $value,
                'user_sub'        => (string)($user['sub'] ?? ''),
            ]);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Set by production — cannot override', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Set by production — cannot override'], 403);
            return;
        }
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $date) : 'open';
    if ($role === 'cashier' && !dl_cashierMayEdit($branchId, $date, $shift, dl_businessDate(), $dayStatus)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    try {
        $ctx->db()->beginTransaction();
        $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
        if ($dayStatus === 'closed' && $role === 'cashier') {
            throw new RuntimeException('Day is closed');
        }
        dl_assertShiftMutable($ctx->db(), $branchId, $date, $shift);

        $currentPrice = dl_resolveBranchProductPrice($branchId, $productId, $date);
        // Deliberately NOT "FOR UPDATE". This read only captures the audit "before"
        // value; the INSERT ... ON DUPLICATE KEY UPDATE below already takes the row
        // lock it needs. Under REPEATABLE READ a locking read of a row that does not
        // exist yet takes a next-key lock on the gap it would occupy. At the start of
        // a business day every row is missing and the new date is the newest in the
        // table, so concurrent saves across branches all gap-lock the same index
        // supremum and then each request an insert-intention lock inside it - which
        // deadlocks (InnoDB 1213, reproduced at 10 concurrent saves over 10 branches).
        $oldStmt = $ctx->db()->prepare(
            "SELECT {$column} AS current_value FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1"
        );
        $oldStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $oldVal = $oldStmt->fetchColumn();

        $stmt = $ctx->db()->prepare(
            "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, {$column}, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :val, :uid, :uid2)
             ON DUPLICATE KEY UPDATE {$column} = :val2, updated_by = :uid3, updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':bid'   => $branchId,
            ':pid'   => $productId,
            ':d'     => $date,
            ':shift' => $shift,
            ':price' => $currentPrice,
            ':val'   => $value,
            ':uid'   => $userId,
            ':uid2'  => $userId,
            ':val2'  => $value,
            ':uid3'  => $userId,
        ]);

        // Auto-recompute sales = beg_bal + addtl - withdraw - bal_end (server-side)
        if ($field !== 'sales') {
            dl_recomputeSales($branchId, $productId, $date, $userId, $shift);
        }
        dl_recomputeVariancesForDay($branchId, $date);

        // Audit log (silent)
        $oldAudit = $oldVal !== false ? ($oldVal !== null ? (int)$oldVal : null) : null;
        dl_auditLog(
            'field_update',
            $branchId,
            'dl_daily_ledger',
            "{$branchId}-{$productId}-{$date}-{$shift}",
            [$field => $oldAudit],
            [$field => $value]
        );

        $ctx->db()->commit();

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'field' => $field, 'value' => $value]);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof RuntimeException && $e->getMessage() === 'Day is closed') {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
            return;
        }
        if ($e instanceof RuntimeException && $e->getCode() === 403) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 403);
            return;
        }
        $ctx->log('apiSaveLedgerField failed: ' . $e->getMessage(), 'error', [
            'branch_id'  => $branchId,
            'product_id' => $productId,
            'field'      => $field,
            'date'       => $date,
            'user_id'    => $userId,
            'role'       => $role,
            'sub'        => (string)($user['sub'] ?? ''),
            'input'      => $input,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Save failed', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiSaveLedgerBatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $input = $ctx->input();
    $date = (string)($input['date'] ?? dl_businessDate());
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $rows = $input['rows'] ?? null;
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));

    if ($idempotencyKey !== '') {
        $cachedResponse = dl_loadIdempotentResponse('ledger_batch', $idempotencyKey);
        if (is_array($cachedResponse)) {
            $ctx->json($cachedResponse);
            return;
        }
    }

    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    if (!$branchId || !is_array($rows) || count($rows) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    $isReadOnly = ($role === 'cashier' && !dl_cashierMayEdit($branchId, $date, $shift, dl_businessDate(), dl_getDayStatus($branchId, $date)));
    if ($isReadOnly) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    // Validate payload and normalize
    $normalized = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $productId = (int)($r['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $beg = (int)($r['beg_bal'] ?? 0);
        $add = (int)($r['addtl'] ?? 0);
        $with = (int)($r['withdraw'] ?? 0);
        // bal_end is written only when explicitly present; an absent key must
        // preserve the existing ending (partial/handoff payloads never stamp 0).
        $hasEnd = array_key_exists('bal_end', $r);
        $end = $hasEnd && $r['bal_end'] !== null && $r['bal_end'] !== '' ? (int)$r['bal_end'] : null;

        // The same rule the ending already follows must hold for the counted columns.
        // The AM/PM handoff payload carries only beg_bal, so defaulting addtl and
        // withdraw to 0 and then writing them erases real quantities - which is exactly
        // why the carry-forward adopt routines could never safely be called.
        $hasAdd = array_key_exists('addtl', $r);
        $hasWith = array_key_exists('withdraw', $r);
        $hasBeg = array_key_exists('beg_bal', $r);

        if ($beg < 0 || $add < 0 || $with < 0 || $beg > 999999999 || $add > 999999999 || $with > 999999999
            || ($end !== null && ($end < 0 || $end > 999999999))) {
            $ctx->json(['ok' => false, 'error' => 'Values are out of bounds'], 422);
            return;
        }

        $normalized[] = [
            'product_id' => $productId,
            'beg_bal' => $beg,
            'addtl' => $add,
            'withdraw' => $with,
            'bal_end' => $end,
            'has_bal_end' => $hasEnd,
            'has_addtl' => $hasAdd,
            'has_withdraw' => $hasWith,
            'has_beg_bal' => $hasBeg,
        ];
    }

    if (count($normalized) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid rows', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid rows'], 422);
        return;
    }

    try {
        $dayStatus = dl_getDayStatus($branchId, $date);
        if (!$isReadOnly) {
            // For cashier: identify production-locked columns per product before entering the transaction
            $productionLocks = [];
            if ($role === 'cashier') {
                $lockStmt = $ctx->db()->prepare(
                    'SELECT pm.product_id, pm.movement_type
                     FROM dl_production_movements pm
                     WHERE pm.destination_branch_id = :bid
                       AND pm.ledger_date = :d
                       AND pm.movement_type IN (\'output\', \'withdrawal\')
                       AND NOT EXISTS (
                           SELECT 1 FROM dl_production_movements r
                           WHERE r.reference_movement_id = pm.id AND r.movement_type = \'reverse\'
                       )
                     GROUP BY pm.product_id, pm.movement_type'
                );
                $lockStmt->execute([':bid' => $branchId, ':d' => $date]);
                foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lockRow) {
                    $lpid = (int)$lockRow['product_id'];
                    $col  = $lockRow['movement_type'] === 'output' ? 'addtl' : 'withdraw';
                    $productionLocks[$lpid][$col] = true;
                }
            }

            $ctx->db()->beginTransaction();
            $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
            if ($role === 'cashier' && $dayStatus === 'closed') {
                throw new RuntimeException('Day is closed');
            }
            dl_assertShiftMutable($ctx->db(), $branchId, $date, $shift);

        $selectOld = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );

        // Two upsert variants: bal_end written only when explicitly present. addtl and
        // withdraw are guarded the same way - an absent key preserves the stored value
        // instead of stamping 0, so a handoff payload that carries only beg_bal cannot
        // erase quantities recorded by another path.
        $upsertWithEnd = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :beg, :addtl, :withdraw, :end, :uid, :uid2)
             ON DUPLICATE KEY UPDATE
                beg_bal = IF(:has_beg_bal, VALUES(beg_bal), beg_bal),
                addtl = IF(:has_addtl, VALUES(addtl), addtl),
                withdraw = IF(:has_withdraw, VALUES(withdraw), withdraw),
                bal_end = VALUES(bal_end),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $upsertWithoutEnd = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :beg, :addtl, :withdraw, :uid, :uid2)
             ON DUPLICATE KEY UPDATE
                beg_bal = IF(:has_beg_bal, VALUES(beg_bal), beg_bal),
                addtl = IF(:has_addtl, VALUES(addtl), addtl),
                withdraw = IF(:has_withdraw, VALUES(withdraw), withdraw),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($normalized as $r) {
            $pid = (int)$r['product_id'];

            $selectOld->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
            $old = $selectOld->fetch(PDO::FETCH_ASSOC) ?: null;

            $currentPrice = dl_resolveBranchProductPrice($branchId, $pid, $date);

            // Preserve production-set values: cashier cannot override addtl/withdraw locked by a movement
            $addtlVal    = (int)$r['addtl'];
            $withdrawVal = (int)$r['withdraw'];
            if ($role === 'cashier') {
                $locks = $productionLocks[$pid] ?? [];
                if (!empty($locks['addtl'])) {
                    $preserved = (int)($old['addtl'] ?? 0);
                    if ($addtlVal !== $preserved) {
                        write_log('daily-ledger cashier batch addtl override blocked', 'warning', [
                            'branch_id'       => $branchId,
                            'product_id'      => $pid,
                            'date'            => $date,
                            'attempted_value' => $addtlVal,
                            'preserved_value' => $preserved,
                            'user_sub'        => (string)($user['sub'] ?? ''),
                        ]);
                    }
                    $addtlVal = $preserved;
                }
                if (!empty($locks['withdraw'])) {
                    $preserved = (int)($old['withdraw'] ?? 0);
                    if ($withdrawVal !== $preserved) {
                        write_log('daily-ledger cashier batch withdraw override blocked', 'warning', [
                            'branch_id'       => $branchId,
                            'product_id'      => $pid,
                            'date'            => $date,
                            'attempted_value' => $withdrawVal,
                            'preserved_value' => $preserved,
                            'user_sub'        => (string)($user['sub'] ?? ''),
                        ]);
                    }
                    $withdrawVal = $preserved;
                }
            }

            if (!empty($r['has_bal_end'])) {
                $upsertWithEnd->execute([
                    ':bid'      => $branchId,
                    ':pid'      => $pid,
                    ':d'        => $date,
                    ':shift'    => $shift,
                    ':price'    => $currentPrice,
                    ':beg'      => (int)$r['beg_bal'],
                    ':addtl'    => $addtlVal,
                    ':withdraw' => $withdrawVal,
                    ':end'      => $r['bal_end'],
                    ':has_addtl' => !empty($r['has_addtl']) ? 1 : 0,
                    ':has_withdraw' => !empty($r['has_withdraw']) ? 1 : 0,
                    ':has_beg_bal' => !empty($r['has_beg_bal']) ? 1 : 0,
                    ':uid'      => $userId,
                    ':uid2'     => $userId,
                ]);
            } else {
                $upsertWithoutEnd->execute([
                    ':bid'      => $branchId,
                    ':pid'      => $pid,
                    ':d'        => $date,
                    ':shift'    => $shift,
                    ':price'    => $currentPrice,
                    ':beg'      => (int)$r['beg_bal'],
                    ':addtl'    => $addtlVal,
                    ':withdraw' => $withdrawVal,
                    ':has_addtl' => !empty($r['has_addtl']) ? 1 : 0,
                    ':has_withdraw' => !empty($r['has_withdraw']) ? 1 : 0,
                    ':has_beg_bal' => !empty($r['has_beg_bal']) ? 1 : 0,
                    ':uid'      => $userId,
                    ':uid2'     => $userId,
                ]);
            }

            // Always recompute sales from the invariant.
            dl_recomputeSales($branchId, $pid, $date, $userId, $shift);

            // Audit as a single event per product row
            dl_auditLog(
                'row_update',
                $branchId,
                'dl_daily_ledger',
                "{$branchId}-{$pid}-{$date}-{$shift}",
                $old,
                [
                    'beg_bal'  => !empty($r['has_beg_bal']) ? (int)$r['beg_bal'] : (int)($old['beg_bal'] ?? 0),
                    'addtl'    => !empty($r['has_addtl']) ? $addtlVal : (int)($old['addtl'] ?? 0),
                    'withdraw' => !empty($r['has_withdraw']) ? $withdrawVal : (int)($old['withdraw'] ?? 0),
                    'bal_end'  => !empty($r['has_bal_end']) ? $r['bal_end'] : ($old['bal_end'] ?? null),
                ]
            );
        }

        dl_recomputeVariancesForDay($branchId, $date);

        $ctx->db()->commit();
        } // end if (!$isReadOnly)

        // Return updated rows as fresh read
        $salesExpr = dl_ledgerSalesQuantitySql('dl');
        $stmt = $ctx->db()->prepare(
            'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                    COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                    COALESCE(dl.withdraw, 0) AS withdraw, dl.bal_end AS bal_end,
                    ' . $salesExpr . ' AS sales, dl.price_snapshot,
                    COALESCE(am.bal_end, 0) AS am_bal_end,
                    CASE
                        WHEN prev_pm.bal_end IS NOT NULL THEN prev_pm.bal_end
                        WHEN prev_am.bal_end IS NOT NULL THEN prev_am.bal_end
                        ELSE NULL
                    END AS prev_bal_end,
                    CASE WHEN prev_pm.id IS NOT NULL AND prev_pm.bal_end IS NULL THEN 1 ELSE 0 END AS prev_pm_pending
             FROM dl_products p
             INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
             LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d AND dl.shift = :shift
             LEFT JOIN dl_daily_ledger am ON am.product_id = p.id AND am.branch_id = :bidam AND am.ledger_date = :dam AND am.shift = \'AM\'
             LEFT JOIN dl_daily_ledger prev_pm ON prev_pm.product_id = p.id AND prev_pm.branch_id = :bidprevpm AND prev_pm.ledger_date = :dprevpm AND prev_pm.shift = \'PM\'
             LEFT JOIN dl_daily_ledger prev_am ON prev_am.product_id = p.id AND prev_am.branch_id = :bidprevam AND prev_am.ledger_date = :dprevam AND prev_am.shift = \'AM\'
             WHERE p.is_active = 1
             ORDER BY p.sort_order, p.name'
        );
        $prevDate = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $stmt->execute([
            ':bid' => $branchId, ':bid2' => $branchId, ':d' => $date, ':shift' => $shift,
            ':bidam' => $branchId, ':dam' => $date,
            ':bidprevpm' => $branchId, ':dprevpm' => $prevDate,
            ':bidprevam' => $branchId, ':dprevam' => $prevDate,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $response = [
            'ok' => true,
            'branch_id' => $branchId,
            'date' => $date,
            'rows' => dl_applyLedgerDisplayPrices($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $branchId, $date),
            'day_status' => $dayStatus,
        ];
        dl_storeIdempotentResponse('ledger_batch', $idempotencyKey, $response);
        $ctx->json($response);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }

        if ($e instanceof RuntimeException && $e->getMessage() === 'Day is closed') {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
            return;
        }
        if ($e instanceof RuntimeException && $e->getCode() === 403) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 403);
            return;
        }

        $ctx->log('apiSaveLedgerBatch failed: ' . $e->getMessage(), 'error', [
            'branch_id' => $branchId,
            'date' => $date,
            'user_id' => $userId,
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Save failed', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiProductionDestinations(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (count($allowedBranchIds) === 0) {
        $ctx->json(['ok' => true, 'destinations' => []]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $stmt = $ctx->db()->prepare(
        "SELECT id, code, name
         FROM dl_branches
         WHERE is_active = 1 AND id IN ({$placeholders})
         ORDER BY name"
    );
    $stmt->execute($allowedBranchIds);

    $ctx->json(['ok' => true, 'destinations' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiProductionProducts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $category = trim((string)($input['category'] ?? ''));

    $sql = 'SELECT id, sku, name, current_price, product_category, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label
            FROM dl_products
            WHERE is_active = 1';
    $bind = [];
    if ($category !== '' && in_array($category, ['bread', 'cake', 'other'], true)) {
        $sql .= ' AND product_category = :category';
        $bind[':category'] = $category;
    }
    $sql .= ' ORDER BY sort_order, name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);

    $ctx->json([
        'ok' => true,
        'products' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

function apiCommissaryMaterials(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);

    $stmt = $ctx->db()->query(
        'SELECT id, name, unit_of_measure, category, sort_order
         FROM dl_raw_materials
         WHERE is_active = 1
         ORDER BY sort_order, name'
    );

    $ctx->json([
        'ok' => true,
        'materials' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

function apiProductionMovements(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : date('Y-m-d', strtotime($today . ' -7 days'));
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $movementType = trim((string)($input['movement_type'] ?? ''));

    $allowedBranchIds = dl_accessibleBranchIds($user);
    dl_maybeAutoCloseBranches($allowedBranchIds, dl_getActorUserId($user));
    if (count($allowedBranchIds) === 0) {
        $ctx->json(['ok' => true, 'rows' => []]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $sql =
        "SELECT pm.id, pm.movement_uuid, pm.client_op_id, pm.movement_type, pm.flow_mode,
                pm.destination_branch_id, b.code AS destination_code, b.name AS destination_name,
                pm.product_id, p.name AS product_name, p.sku,
            pm.ledger_date, pm.quantity, pm.dr_number, pm.override_reason,
                pm.reference_movement_id, pm.created_by_id, pm.created_by_role, pm.created_at
         FROM dl_production_movements pm
         INNER JOIN dl_branches b ON b.id = pm.destination_branch_id
         INNER JOIN dl_products p ON p.id = pm.product_id
         WHERE pm.destination_branch_id IN ({$placeholders})
           AND pm.ledger_date BETWEEN ? AND ?";
    $bind = $allowedBranchIds;
    $bind[] = $dateFrom;
    $bind[] = $dateTo;

    if ($movementType !== '' && in_array($movementType, ['withdrawal', 'output', 'reverse'], true)) {
        $sql .= ' AND pm.movement_type = ?';
        $bind[] = $movementType;
    }
    $sql .= ' ORDER BY pm.created_at DESC LIMIT 500';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);

    $ctx->json(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiProductionWithdrawal(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'withdrawal', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionOutput(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    if (!dl_isFeatureEnabled('production_output_enabled')) {
        $ctx->json(['ok' => false, 'error' => 'Production output feature is disabled. Ask Kernel Admin to enable it.'], 403);
        return;
    }
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'output', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionReverse(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $role = (string)($user['role'] ?? '');
    if (!dl_roleHasPermission($role, 'production.override')) {
        $ctx->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return;
    }
    $input = $ctx->input();

    try {
        $result = dl_processProductionMovement($user, 'reverse', $input);
        $ctx->json(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiProductionSyncBatch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $outputEnabled = dl_isFeatureEnabled('production_output_enabled');
    $input = $ctx->input();
    $operations = $input['operations'] ?? [];
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $cachedResponse = dl_loadIdempotentResponse('production_sync_batch', $idempotencyKey);
        if (is_array($cachedResponse)) {
            $ctx->json($cachedResponse);
            return;
        }
    }
    if (!is_array($operations) || count($operations) === 0) {
        $ctx->json(['ok' => false, 'error' => 'operations[] is required'], 422);
        return;
    }

    $results = [];
    foreach ($operations as $idx => $op) {
        if (!is_array($op)) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Invalid operation payload'];
            continue;
        }
        $type = (string)($op['type'] ?? '');
        if (!in_array($type, ['withdrawal', 'output', 'reverse'], true)) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Invalid type'];
            continue;
        }

        if ($type === 'output' && !$outputEnabled) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => 'Production output feature is disabled. Ask Kernel Admin to enable it.'];
            continue;
        }

        try {
            $results[] = ['index' => $idx, 'ok' => true, 'result' => dl_processProductionMovement($user, $type, $op)];
        } catch (\Throwable $e) {
            $results[] = ['index' => $idx, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $okCount = 0;
    foreach ($results as $r) {
        if (!empty($r['ok'])) {
            $okCount++;
        }
    }

    $response = [
        'ok' => true,
        'summary' => [
            'total' => count($results),
            'succeeded' => $okCount,
            'failed' => count($results) - $okCount,
        ],
        'results' => $results,
    ];
    dl_storeIdempotentResponse('production_sync_batch', $idempotencyKey, $response);
    $ctx->json($response);
}

function apiFinalizePmShift(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['cashier', 'supervisor', 'admin'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return;
    }

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $date = (string)($input['date'] ?? dl_businessDate());
    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
        return;
    }
    if (!$branchId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid branch or date'], 422);
        return;
    }

    // Cashiers may finalize PM only for the current date or the immediately
    // previous pending PM day (late-count window). Supervisors/admins may
    // finalize any open day's PM on their authorized branch.
    if ($role === 'cashier') {
        $today = dl_businessDate();
        $prev = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        if ($date !== $today && $date !== $prev) {
            $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
            return;
        }
    }

    try {
        $ctx->db()->beginTransaction();

        $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
        if ($dayStatus === 'closed') {
            $ctx->db()->rollBack();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.'], 422);
            return;
        }

        $pmStatus = dl_lockShiftStatusRow($ctx->db(), $branchId, $date, 'PM');
        if ((string)$pmStatus['status'] === 'finalized') {
            // Idempotent: an unchanged already-finalized PM shift is a success.
            $ctx->db()->commit();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'PM shift already finalized', 'type' => 'success']]));
            $ctx->json(['ok' => true, 'already_finalized' => true, 'finalized' => true]);
            return;
        }

        // Validate every currently active branch product has a recorded ending.
        $missing = dl_shiftMissingEndings($ctx->db(), $branchId, $date, 'PM');
        if (count($missing) > 0) {
            $ctx->db()->rollBack();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Complete all PM ending counts first.', 'type' => 'error']]));
            $ctx->json([
                'ok' => false,
                'code' => 'PM_ENDING_MISSING',
                'error' => count($missing) . ' active product(s) are missing a PM ending count.',
                'missing_products' => array_map(static function (array $m): array {
                    return ['product_id' => (int)$m['product_id'], 'name' => (string)$m['name'], 'sku' => (string)($m['sku'] ?? '')];
                }, $missing),
            ], 422);
            return;
        }

        // Recompute every PM sales value inside the lock, then the day variances.
        $updStmt = $ctx->db()->prepare(
            'SELECT product_id FROM dl_daily_ledger
              WHERE branch_id = :bid AND ledger_date = :d AND shift = \'PM\''
        );
        $updStmt->execute([':bid' => $branchId, ':d' => $date]);
        foreach ($updStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            dl_recomputeSales($branchId, (int)$r['product_id'], $date, $userId, 'PM');
        }
        dl_recomputeVariancesForDay($branchId, $date, false);

        $finalize = $ctx->db()->prepare(
            'UPDATE dl_ledger_shift_status
                SET status = \'finalized\', finalized_by = :uid, finalized_at = CURRENT_TIMESTAMP
              WHERE branch_id = :bid AND ledger_date = :d AND shift = \'PM\''
        );
        $finalize->execute([':uid' => $userId, ':bid' => $branchId, ':d' => $date]);

        dl_auditLog('finalize_shift', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$date}-PM", ['status' => 'open'], [
            'status' => 'finalized',
            'shift' => 'PM',
            'finalized_by' => $userId,
        ]);

        $ctx->db()->commit();

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'PM shift finalized', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'finalized' => true]);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }
        write_log('daily-ledger finalize pm failed', 'error', [
            'branch_id' => $branchId,
            'ledger_date' => $date,
            'error' => $e->getMessage(),
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to finalize PM shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to finalize PM shift'], 500);
    }
}

function apiCloseDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin', 'production_in_charge']);

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $date     = (string)($input['date'] ?? dl_businessDate());
    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
        return;
    }

    if (!$branchId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'No branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'No branch'], 422);
        return;
    }

    // Cashiers and production-in-charge may close only the current business
    // date; a past date stays an admin/supervisor action. Admin may close any
    // date. (The reference-date rule is server-side; the button is a hint.)
    if (in_array((string)($user['role'] ?? ''), ['cashier', 'production_in_charge'], true) && $date !== dl_businessDate()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    try {
        $ctx->db()->beginTransaction();

        // Serialize on the day-status row first (creates the open row if absent).
        $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
        if ($dayStatus === 'closed') {
            $ctx->db()->commit();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day already closed', 'type' => 'success']]));
            $ctx->json(['ok' => true, 'day_status' => 'closed']);
            return;
        }

        // POS days have extra close requirements (open carts, variance ack).
        $posBlock = dl_pos_dayClosePrecheck($ctx->db(), $branchId, $date, $input);
        if (is_array($posBlock)) {
            $ctx->db()->rollBack();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => (string)$posBlock['error'], 'type' => 'error']]));
            $ctx->json($posBlock, 422);
            return;
        }

        // Fully manual days require the PM shift to be finalized first.
        if (dl_isFullyManualDay($ctx->db(), $branchId, $date)) {
            $pmStatus = dl_lockShiftStatusRow($ctx->db(), $branchId, $date, 'PM');
            if ((string)$pmStatus['status'] !== 'finalized') {
                $ctx->db()->rollBack();
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Close the PM shift before closing the day.', 'type' => 'error']]));
                $ctx->json([
                    'ok' => false,
                    'code' => 'PM_ENDING_PENDING',
                    'error' => 'The PM shift has not been finalized. Complete the PM ending counts and close the PM shift before closing the day.',
                ], 422);
                return;
            }
            // Complete manual-day variance sweep + freeze before locking.
            dl_recomputeVariancesForDay($branchId, $date, false);
            dl_freezeVarianceFlags($ctx->db(), $branchId, $date, $userId);
        }

        $stmt = $ctx->db()->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
             VALUES (:bid, :d, \'closed\', :uid, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = \'closed\', closed_by = :uid2, closed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':bid' => $branchId, ':d' => $date, ':uid' => $userId, ':uid2' => $userId]);

        dl_pos_markModeClosed($ctx->db(), $branchId, $date, $userId);

        dl_auditLog('close_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", null, ['status' => 'closed']);

        $ctx->db()->commit();

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day closed', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'day_status' => 'closed']);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }
        if ($e instanceof RuntimeException && $e->getCode() === 403) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 403);
            return;
        }
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to close day', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to close day'], 500);
    }
}

function apiReopenDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);
    $role = (string)($user['role'] ?? '');
    if (!dl_roleHasPermission($role, 'ledger.override')) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Permission denied', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return;
    }

    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $date     = (string)($input['date'] ?? '');
    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required', 'code' => 'session_expired'], 401);
        return;
    }

    if (!$branchId || !$date) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Missing branch or date', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Missing branch_id or date'], 422);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_ledger_day_status SET status = \'open\', reopened_by = :uid, reopened_at = CURRENT_TIMESTAMP
             WHERE branch_id = :bid AND ledger_date = :d'
        );
        $stmt->execute([':uid' => $userId, ':bid' => $branchId, ':d' => $date]);

        // Reopening a day deliberately reopens both shift lifecycles so locked
        // finalized sales can be corrected under an audited override.
        $shiftStmt = $ctx->db()->prepare(
            'UPDATE dl_ledger_shift_status
                SET status = \'open\', finalized_by = NULL, finalized_at = NULL, pending_notified_at = NULL
              WHERE branch_id = :bid AND ledger_date = :d'
        );
        $shiftStmt->execute([':bid' => $branchId, ':d' => $date]);
        if ($shiftStmt->rowCount() > 0) {
            dl_auditLog('reopen_shift', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$date}", ['status' => 'finalized'], ['status' => 'open']);
        }

        // An audited reopen returns the day's derived variance snapshot to a
        // mutable state; otherwise a reported frozen-day reprice could never be rerun.
        $ctx->db()->prepare(
            'UPDATE dl_variance_flags SET frozen_at = NULL
              WHERE branch_id = :bid AND ledger_date = :d'
        )->execute([':bid' => $branchId, ':d' => $date]);

        dl_recomputeVariancesForDay($branchId, $date);

        dl_auditLog('reopen_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", ['status' => 'closed'], ['status' => 'open']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day reopened', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'day_status' => 'open']);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to reopen', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to reopen'], 500);
    }
}

// ─── Admin Page Handlers ───────────────────────────────────────────────

function handleAdminDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);
    $role = (string)($user['role'] ?? '');
    $input = $ctx->input();

    $today    = dl_businessDate();
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0]; // Ensure empty result
    }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];
    dl_maybeAutoCloseBranches(array_column($branches, 'id'), dl_getActorUserId($user));

    $salesFilterDateFrom = $today;
    $salesFilterDateTo = $today;
    if (!empty($input['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_from'])) {
        $salesFilterDateFrom = (string)$input['date_from'];
    }
    if (!empty($input['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_to'])) {
        $salesFilterDateTo = (string)$input['date_to'];
    }
    if ($salesFilterDateFrom > $salesFilterDateTo) {
        [$salesFilterDateFrom, $salesFilterDateTo] = [$salesFilterDateTo, $salesFilterDateFrom];
    }

    $salesFilterBranchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 0;
    if ($salesFilterBranchId > 0 && !in_array($salesFilterBranchId, $accessibleBranchIds, true) && $role !== 'admin') {
        $salesFilterBranchId = 0;
    }
    $salesFilterPeriodLabel = $salesFilterDateFrom === $salesFilterDateTo
        ? $salesFilterDateFrom
        : $salesFilterDateFrom . ' to ' . $salesFilterDateTo;

    $salesScopeBranches = $branches;
    if ($salesFilterBranchId > 0) {
        $salesScopeBranches = array_values(array_filter($branches, static function (array $branch) use ($salesFilterBranchId): bool {
            return (int)($branch['id'] ?? 0) === $salesFilterBranchId;
        }));
    }

    // Today's sales per branch — computed: sales = beg_bal + addtl - withdraw - bal_end.
    // Official vs provisional: derived from the one shared predicate (dl_provisionalSqlExpr),
    // which mirrors dl_rowIsProvisional() so this aggregate cannot drift from the row-level
    // authority. Do NOT hand-write the rule here.
    $provisionalExpr = dl_provisionalSqlExpr('dl', 'ss');
    $qtyExpr = 'GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end)';
    $salesStmt = $ctx->db()->prepare(
        'SELECT dl.branch_id, b.name AS branch_name,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE ' . $qtyExpr . ' END), 0) AS total_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE ' . $qtyExpr . ' * dl.price_snapshot END), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN ' . $qtyExpr . ' ELSE 0 END), 0) AS provisional_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN ' . $qtyExpr . ' * dl.price_snapshot ELSE 0 END), 0) AS provisional_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         LEFT JOIN dl_ledger_shift_status ss ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
         WHERE dl.ledger_date = ? AND dl.branch_id IN (' . $branchPlaceholders . ')
         GROUP BY dl.branch_id
         ORDER BY b.name'
    );
    $salesStmt->execute(array_merge([$today], $accessibleBranchIds));
    $todaySales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $filteredSalesSql =
        'SELECT dl.branch_id, b.name AS branch_name,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE ' . $qtyExpr . ' END), 0) AS total_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE ' . $qtyExpr . ' * dl.price_snapshot END), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN ' . $qtyExpr . ' ELSE 0 END), 0) AS provisional_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN ' . $qtyExpr . ' * dl.price_snapshot ELSE 0 END), 0) AS provisional_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         LEFT JOIN dl_ledger_shift_status ss ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
         WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (' . $branchPlaceholders . ')';
    $filteredSalesBind = array_merge([$salesFilterDateFrom, $salesFilterDateTo], $accessibleBranchIds);
    if ($salesFilterBranchId > 0) {
        $filteredSalesSql .= ' AND dl.branch_id = ?';
        $filteredSalesBind[] = $salesFilterBranchId;
    }
    $filteredSalesSql .= ' GROUP BY dl.branch_id ORDER BY b.name';
    $filteredSalesStmt = $ctx->db()->prepare($filteredSalesSql);
    $filteredSalesStmt->execute($filteredSalesBind);
    $filteredSalesRows = $filteredSalesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Day status per branch — filtered to accessible branches
    $statusStmt = $ctx->db()->prepare(
        "SELECT branch_id, status FROM dl_ledger_day_status WHERE ledger_date = ? AND branch_id IN ({$branchPlaceholders})"
    );
    $statusStmt->execute(array_merge([$salesFilterDateTo], $accessibleBranchIds));
    $dayStatuses = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $dayStatuses[(int)$s['branch_id']] = $s['status'];
    }

    // Unreviewed variance count
    $varStmt = $ctx->db()->query('SELECT COUNT(*) FROM dl_variance_flags WHERE resolution_status = "unreviewed"');
    $unreviewedVariances = (int)$varStmt->fetchColumn();

    // Recent encoder activity (last 20) — human-readable + branch-scoped for non-admins.
    $hasActorModuleUserId = dlAuditLogHasColumn('actor_module_user_id');
    $hasActorSource = dlAuditLogHasColumn('actor_source');
    $hasMetadataColumn = dlAuditLogHasColumn('metadata_json');
    $hasUsersTable = dl_tableExists($ctx->db(), 'users');
    $activitySql = 'SELECT a.action, a.created_at, a.branch_id,
                           b.name AS branch_name,
                           ' . ($hasActorSource ? 'a.actor_source' : 'NULL') . ' AS actor_source,
                           a.actor_user_id,
                           ' . ($hasActorModuleUserId ? 'a.actor_module_user_id' : 'NULL') . ' AS actor_module_user_id,
                           ' . ($hasUsersTable ? 'ku.full_name AS kernel_actor_name' : 'NULL AS kernel_actor_name') . ',
                           ' . ($hasActorModuleUserId ? 'du.full_name' : 'NULL') . ' AS module_actor_name,
                           ' . ($hasMetadataColumn ? 'a.metadata_json' : 'NULL') . ' AS metadata_json
                    FROM audit_logs a
                    LEFT JOIN dl_branches b ON b.id = a.branch_id
                    ' . ($hasUsersTable ? 'LEFT JOIN users ku ON ku.id = a.actor_user_id' : '') . '
                    ' . ($hasActorModuleUserId ? 'LEFT JOIN dl_users du ON du.id = a.actor_module_user_id' : 'LEFT JOIN dl_users du ON 1 = 0') . '
                    WHERE a.module = \'daily-ledger\'';
    $activityBind = [];
    if ($role !== 'admin') {
        $activityBranchPlaceholders = [];
        foreach (array_values($accessibleBranchIds) as $index => $accessibleBranchId) {
            $placeholder = ':dash_branch_' . $index;
            $activityBranchPlaceholders[] = $placeholder;
            $activityBind[$placeholder] = (int)$accessibleBranchId;
        }
        $activitySql .= ' AND (a.branch_id IS NULL OR a.branch_id IN (' . implode(',', $activityBranchPlaceholders) . '))';
    }
    $activitySql .= ' ORDER BY a.created_at DESC LIMIT 20';
    $activityStmt = $ctx->db()->prepare($activitySql);
    $activityStmt->execute($activityBind);
    $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dashboardActionLabels = [
        'field_update' => 'Updated ledger field',
        'row_update' => 'Updated ledger row',
        'close_day' => 'Closed the day',
        'reopen_day' => 'Reopened the day',
        'create_product' => 'Added product',
        'update_product' => 'Updated product',
        'create_user' => 'Created user',
        'update_user' => 'Updated user',
        'delete_user' => 'Deleted user',
        'restore_user' => 'Restored user',
        'production_output' => 'Recorded production output',
        'production_withdrawal' => 'Recorded production withdrawal',
        'withdrawal' => 'Recorded withdrawal',
        'withdrawal_updated' => 'Updated withdrawal',
        'login' => 'Signed in',
        'create_delivery' => 'Created delivery',
        'delivery_posted' => 'Posted delivery',
        'delivery_voided' => 'Voided delivery',
        'create_receiving' => 'Created receiving',
        'receiving_posted' => 'Posted receiving',
        'receiving_voided' => 'Voided receiving',
        'review_delivery_provenance' => 'Reviewed paper DR',
        'variance_status' => 'Updated variance status',
        'create_commissary_run' => 'Created commissary run',
        'update_commissary_run' => 'Updated commissary run',
        'delete_commissary_run' => 'Deleted commissary run',
        'save_commissary_material' => 'Saved material count',
    ];
    foreach ($recentActivity as &$activityRow) {
        // Prefer the per-event name stamped at write time; the profile join is the
        // fallback for rows written before the stamp existed.
        $eventMetadata = [];
        if (isset($activityRow['metadata_json']) && is_string($activityRow['metadata_json']) && trim($activityRow['metadata_json']) !== '') {
            $decodedMetadata = json_decode($activityRow['metadata_json'], true);
            if (is_array($decodedMetadata)) {
                $eventMetadata = $decodedMetadata;
            }
        }
        $actorName = trim((string)($eventMetadata['actor_name'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string)($activityRow['module_actor_name'] ?? ''));
        }
        if ($actorName === '') {
            $actorName = trim((string)($activityRow['kernel_actor_name'] ?? ''));
        }
        if ($actorName === '') {
            $source = strtolower(trim((string)($activityRow['actor_source'] ?? '')));
            if ($source === 'daily-ledger') {
                $actorName = 'Daily Ledger';
            } elseif ($source === 'kernel') {
                $actorName = 'Kernel User';
            } else {
                $actorName = 'System';
            }
        }
        $action = (string)($activityRow['action'] ?? '');
        $activityRow['actor_name'] = $actorName;
        $activityRow['activity_label'] = $dashboardActionLabels[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
    unset($activityRow);

    // Join branches + sales + day-statuses into card data.
    // Pass raw numeric values — let DiSyL handle formatting (currency, number_format).
    $salesByBranch = [];
    foreach ($filteredSalesRows as $ts) {
        $salesByBranch[(int)$ts['branch_id']] = $ts;
    }

    $scopeUnits = 0;
    $scopeAmount = 0.0;
    $scopeProvisionalUnits = 0;
    $scopeProvisionalAmount = 0.0;
    $branchCards = [];
    foreach ($salesScopeBranches as $br) {
        $bid = (int)$br['id'];
        $ts = $salesByBranch[$bid] ?? null;
        $units  = $ts ? (int)$ts['total_units'] : 0;
        $amount = $ts ? (float)$ts['total_amount'] : 0.0;
        $pUnits  = $ts ? (int)($ts['provisional_units'] ?? 0) : 0;
        $pAmount = $ts ? (float)($ts['provisional_amount'] ?? 0) : 0.0;
        $status = $dayStatuses[$bid] ?? 'none';
        $scopeUnits  += $units;
        $scopeAmount += $amount;
        $scopeProvisionalUnits  += $pUnits;
        $scopeProvisionalAmount += $pAmount;
        $branchCards[] = [
            'branch_id' => $bid,
            'name'   => $br['name'],
            'units'  => $units,
            'amount' => $amount,
            'provisional_units' => $pUnits,
            'provisional_amount' => $pAmount,
            'status' => $status,
        ];
    }

    // Devices with unsynced offline work — admin visibility so a cashier's
    // captured-but-unsynced ending is never silently stuck. Branch-scoped.
    $unsyncedDevices = [];
    if (in_array($role, ['admin', 'supervisor', 'auditor'], true)) {
        try {
            $unsyncedDevices = dl_offlineUnsyncedDevices($user, 20);
        } catch (\Throwable $e) {
            // Column may be missing until migration 053 runs; degrade to empty.
            write_log('daily-ledger unsynced-devices query failed', 'warning', ['message' => $e->getMessage()]);
            $unsyncedDevices = [];
        }
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/dashboard.disyl', [
        'page_title'            => 'Dashboard',
        'user_name'             => $userName,
        'user_role'             => $role,
        'current_page'          => 'dashboard',
        'base_url' => dlGetBaseUrl(),
        'dl_token'              => (string)kernelCookie(dlCookieName(), ''),
        'today'                 => $today,
        'branches'              => $branches,
        'branch_cards'          => $branchCards,
        'sales_filter_date_from' => $salesFilterDateFrom,
        'sales_filter_date_to'   => $salesFilterDateTo,
        'sales_filter_branch_id' => $salesFilterBranchId,
        'sales_filter_period_label' => $salesFilterPeriodLabel,
        'branch_sales_units'    => $scopeUnits,
        'branch_sales_amount'   => $scopeAmount,
        'branch_provisional_units' => $scopeProvisionalUnits,
        'branch_provisional_amount' => $scopeProvisionalAmount,
        'unreviewed_variances'  => $unreviewedVariances,
        'recent_activity'       => $recentActivity,
        'unsynced_devices'      => $unsyncedDevices,
        'total_units_today'     => array_reduce($todaySales, static fn(int $carry, array $row): int => $carry + (int)($row['total_units'] ?? 0), 0),
        'total_amount_today'    => array_reduce($todaySales, static fn(float $carry, array $row): float => $carry + (float)($row['total_amount'] ?? 0), 0.0),
        'provisional_units_today' => array_reduce($todaySales, static fn(int $carry, array $row): int => $carry + (int)($row['provisional_units'] ?? 0), 0),
        'provisional_amount_today' => array_reduce($todaySales, static fn(float $carry, array $row): float => $carry + (float)($row['provisional_amount'] ?? 0), 0.0),
        'business_date_label'   => $clockLabel['business_date'],
        'close_of_day_time'     => $clockLabel['close_of_day_time'],
        'auto_close_enabled'    => $clockLabel['auto_close_enabled'],
        'operating_timezone'    => $clockLabel['operating_timezone'],
        'operating_region'      => $clockLabel['operating_region'],
    ]);
}

function handleAdminOverview(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    // Read-only business overview: sales data + top saleable products.
    // Accessible to admins, supervisors, auditors, and viewers (business owners).
    $user = dlCurrentUser(['admin', 'supervisor', 'auditor', 'viewer']);
    $role = (string)($user['role'] ?? '');
    $input = $ctx->input();

    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_from'])
        ? (string)$input['date_from'] : $today;
    $dateTo = !empty($input['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['date_to'])
        ? (string)$input['date_to'] : $today;
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : 0;
    // Ranking controls for the product tables. Pareto itself stays amount-ordered
    // because cumulative accumulation is only meaningful strongest-first.
    $sortBy = dl_overviewNormalizeSortBy($input['sort_by'] ?? 'amount');
    $sortDir = dl_overviewNormalizeSortDir($input['sort_dir'] ?? 'desc');
    // Chart-only controls: how many series to plot, and whether series with no
    // sales are plotted at all (empty branches previously rendered as long grey
    // tracks that carried no information).
    $chartLimit = dl_overviewNormalizeChartLimit($input['chart_limit'] ?? DL_OVERVIEW_CHART_LIMIT_DEFAULT);
    $chartShowEmpty = !empty($input['chart_show_empty']);
    // Pending ledger rows are excluded by default so only completed entries are
    // counted; the checkbox lets the viewer include them.
    $pendingRowsMode = dl_overviewPendingRowsMode($input['pending_rows'] ?? null);

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0];
    }

    // Branch selection narrows the displayed scope. An explicitly requested
    // branch that is not accessible yields an empty scope instead of widening
    // the queries back to every accessible branch.
    $scopedBranchIds = $accessibleBranchIds;
    if ($branchId > 0) {
        $scopedBranchIds = in_array($branchId, $accessibleBranchIds, true) ? [$branchId] : [];
    }

    // The filter dropdown always lists every authorized active branch so a user
    // can switch directly between them. $scopedBranchIds (not $branches) drives
    // the cards, queries, and analytics for the selected scope.
    $branches = [];
    if ($accessibleBranchIds !== []) {
        $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
        $branchStmt = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
        $branchStmt->execute($accessibleBranchIds);
        $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $scopedBranches = [];
    if ($scopedBranchIds !== []) {
        foreach ($branches as $br) {
            if (in_array((int)$br['id'], $scopedBranchIds, true)) {
                $scopedBranches[] = $br;
            }
        }
    }

    $filters = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $branchId,
        'product_id' => 0,
        'shift' => '',
        'accessible_branch_ids' => $accessibleBranchIds,
        'pending_rows_mode' => $pendingRowsMode,
    ];

    // Overall product totals feed both the ranked tables and the Pareto
    // accumulation. Products with no completed sales are already excluded by the
    // query itself.
    $productTotals = dl_overviewProductTotals($ctx->db(), $filters);
    $pareto = dl_overviewPareto($productTotals, DL_OVERVIEW_PARETO_THRESHOLD);
    $topProducts = dl_overviewTopProducts(dl_overviewSortProducts($productTotals, $sortBy, $sortDir), DL_OVERVIEW_TOP_PRODUCTS_LIMIT);
    foreach ($topProducts as &$tp) {
        $tp['share'] = $pareto['total_amount'] > 0
            ? round(((float)($tp['amount'] ?? 0)) / $pareto['total_amount'] * 100, 1)
            : 0.0;
    }
    unset($tp);
    $topProducts = dl_overviewBarPercentages($topProducts, 'amount');
    // Pareto contributors are charted on the canonical amount order (which is
    // still descending) with their cumulative share carried per row.
    $pareto['contributors'] = dl_overviewBarPercentages($pareto['contributors'], 'amount');
    $branchTopProducts = dl_overviewBranchProductTotals($ctx->db(), $filters, DL_OVERVIEW_PER_BRANCH_PRODUCTS_LIMIT, $sortBy, $sortDir);

    // Sales per branch for the period.
    $grandUnits = 0;
    $grandAmount = 0.0;
    $dayStatuses = [];
    $branchSalesMap = [];
    if ($scopedBranchIds !== []) {
        $branchPlaceholders = implode(',', array_fill(0, count($scopedBranchIds), '?'));
        $salesSql =
            'SELECT dl.branch_id, b.name AS branch_name,
                    COALESCE(SUM(' . dl_ledgerSalesQuantitySql('dl') . '), 0) AS total_units,
                    COALESCE(SUM(' . dl_ledgerSalesAmountSql('dl') . '), 0) AS total_amount,
                    COUNT(DISTINCT dl.product_id) AS product_count
             FROM dl_daily_ledger dl
             INNER JOIN dl_branches b ON b.id = dl.branch_id
             WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (' . $branchPlaceholders . ')';
        $salesBind = array_merge([$dateFrom, $dateTo], $scopedBranchIds);
        if ($pendingRowsMode === 'exclude') {
            $salesSql .= dl_overviewPendingPredicate('dl');
        }
        $salesSql .= ' GROUP BY dl.branch_id, b.name ORDER BY b.name';
        $salesStmt = $ctx->db()->prepare($salesSql);
        $salesStmt->execute($salesBind);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $branchSalesMap[(int)$row['branch_id']] = $row;
        }

        // Day status per branch for the end date.
        $statusStmt = $ctx->db()->prepare("SELECT branch_id, status FROM dl_ledger_day_status WHERE ledger_date = ? AND branch_id IN ({$branchPlaceholders})");
        $statusStmt->execute(array_merge([$dateTo], $scopedBranchIds));
        foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $dayStatuses[(int)$s['branch_id']] = $s['status'];
        }
    }

    $cards = [];
    foreach ($scopedBranches as $br) {
        $bid = (int)$br['id'];
        $s = $branchSalesMap[$bid] ?? null;
        $units = $s ? (int)$s['total_units'] : 0;
        $amount = $s ? (float)$s['total_amount'] : 0.0;
        $grandUnits += $units;
        $grandAmount += $amount;
        $cards[] = [
            'branch_id' => $bid,
            'name' => $br['name'],
            'units' => $units,
            'amount' => $amount,
            'status' => $dayStatuses[$bid] ?? 'none',
        ];
    }

    // Configurable net sales deduction. 0% (or a non-numeric setting) is
    // reported as "not configured" rather than echoing gross as a net value.
    $settings = dlModuleSettings();
    $netSetting = (string)($settings['net_sales_deduction_percent'] ?? '0');
    $netSales = dl_overviewNetSales($grandAmount, $netSetting);
    foreach ($cards as &$card) {
        $card['net_amount'] = dl_overviewNetSales((float)$card['amount'], $netSetting)['net'];
        $card['net_configured'] = $netSales['configured'];
    }
    unset($card);
    // Branch bars are scaled against the strongest branch in scope, and the plot
    // is ranked strongest-first so a shortened chart still shows the leaders.
    $cards = dl_overviewBarPercentages($cards, 'amount');
    $branchChartSource = dl_overviewSortProducts(array_map(static function (array $card): array {
        $card['id'] = (int)$card['branch_id'];
        return $card;
    }, $cards), 'amount', 'desc');
    $branchChartSellable = array_values(array_filter($branchChartSource, static fn(array $card): bool => (float)$card['amount'] > 0.0));
    $branchChart = dl_overviewChartSeries($branchChartSource, $chartLimit, $chartShowEmpty, 'amount');
    $branchChart = dl_overviewValueShares(dl_overviewBarPercentages($branchChart, 'amount'), 'amount');
    $branchChartEmpty = max(0, count($branchChartSource) - count($branchChartSellable));
    $branchChartPool = $chartShowEmpty ? count($branchChartSource) : count($branchChartSellable);

    // Production forecast anchored at the selected date_to (target = next day).
    $forecastWindow = dl_overviewForecastWindow($input['forecast_window'] ?? null);
    $forecastPeriod = dl_overviewNormalizeForecastPeriod((string)($input['forecast_period'] ?? 'daily'));
    $forecastRows = dl_overviewForecastRows($ctx->db(), $filters, $dateTo, $forecastWindow);
    $forecast = dl_overviewForecastSummary($forecastRows, $forecastPeriod);
    // Forecast bars are scaled against the largest projected product volume. The
    // table keeps every product; the chart plots the strongest $chartLimit so it
    // stays readable when a period has many products.
    $forecast['products'] = dl_overviewBarPercentages($forecast['products'], 'projected_units');
    $forecastChart = dl_overviewValueShares(
        dl_overviewBarPercentages(dl_overviewChartSeries($forecast['products'], $chartLimit, false, 'projected_units'), 'projected_units'),
        'projected_units'
    );
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo) ?: new DateTimeImmutable($dateTo);
    $forecastHistoryFrom = $anchor->modify('+' . (1 - $forecastWindow) . ' days')->format('Y-m-d');
    $forecastTargetDate = $anchor->modify('+1 day')->format('Y-m-d');

    $periodLabel = $dateFrom === $dateTo ? $dateFrom : $dateFrom . ' to ' . $dateTo;
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/overview.disyl', [
        'page_title' => 'Business Overview',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'overview',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $branchId,
        'branches' => $branches,
        'scoped_branch_count' => count($scopedBranches),
        'branch_cards' => $cards,
        'top_products' => $topProducts,
        // Denominator for the 80/20 statement: products with sales value only.
        'product_scope_count' => $pareto['scope_count'],
        'sort_by' => $sortBy,
        'sort_dir' => $sortDir,
        'sort_label' => dl_overviewSortLabel($sortBy, $sortDir),
        'chart_limit' => $chartLimit,
        'chart_show_empty' => $chartShowEmpty,
        'exclude_pending' => $pendingRowsMode === 'exclude',
        // The form carries this marker, so the cue can tell a submitted filter
        // from a plain page load where the same defaults are in force.
        'filters_applied' => !empty($input['filters_applied']),
        'branch_chart' => $branchChart,
        'branch_chart_pool' => $branchChartPool,
        'branch_chart_empty' => $branchChartEmpty,
        'forecast_chart' => $forecastChart,
        'branch_top_products' => $branchTopProducts,
        'pareto' => $pareto,
        'net_sales' => $netSales,
        'net_sales_configured' => $netSales['configured'],
        'grand_units' => $grandUnits,
        'grand_amount' => $grandAmount,
        'period_label' => $periodLabel,
        'forecast' => $forecast,
        'forecast_window' => $forecastWindow,
        'forecast_period' => $forecastPeriod,
        'forecast_period_days' => $forecast['period_days'],
        'forecast_history_from' => $forecastHistoryFrom,
        'forecast_history_to' => $dateTo,
        'forecast_target_date' => $forecastTargetDate,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
    ]);
}

function handleAdminReports(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlRequireAuth(['admin', 'supervisor', 'auditor', 'viewer']);
    $role = (string)($user['role'] ?? '');
    $packs = array_values(array_filter(
        \Ikabud\Kernel\Services\ReportManager::moduleReportPacks(),
        static fn(array $pack): bool => (string)($pack['module'] ?? '') === 'daily-ledger'
    ));
    $tenantScope = dl_reportTenantScope();
    $archives = array_values(array_filter(
        \Ikabud\Kernel\Services\ReportManager::listArchived(),
        static fn(array $item): bool => dl_reportArchiveVisibleToTenant($item, $tenantScope)
    ));
    echo dlRender('modules/daily-ledger/admin/reports.disyl', [
        'page_title' => 'Reports',
        'user_name' => (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User'),
        'user_role' => $role,
        'current_page' => 'reports',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'landing' => true,
        'report_packs' => $packs,
        'archives' => array_slice($archives, 0, 20),
        'report_type' => '',
        'report_title' => 'Reports',
        'report_rows' => [],
        'totals' => [],
        'filters' => [],
        'branches' => [],
        'products' => [],
        'columns' => [],
    ]);
}

function dl_handleAdminReport(string $type): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlRequireAuth(['admin', 'supervisor', 'auditor', 'viewer']);
    $definitions = dl_reportDefinitions();
    if (!isset($definitions[$type])) { http_response_code(404); echo 'Report not found'; return; }
    $reportInput = $ctx->input();
    if ($type === 'month-end' && empty($reportInput['date_from']) && empty($reportInput['date_to'])) {
        $reportInput['date_from'] = (new DateTimeImmutable(dl_businessDate()))->modify('first day of this month')->format('Y-m-d');
        $reportInput['date_to'] = dl_businessDate();
    }
    $filters = dl_reportFilters($reportInput, $user);
    $data = dl_reportDataForType($ctx->db(), $type, $filters);
    echo dlRender('modules/daily-ledger/admin/reports.disyl', [
        'page_title' => $definitions[$type]['title'],
        'user_name' => (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User'),
        'user_role' => (string)($user['role'] ?? ''),
        'current_page' => 'reports',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'landing' => false,
        'report_packs' => [],
        'archives' => [],
        'report_type' => $type,
        'report_title' => $definitions[$type]['title'],
        'report_rows' => $data['rows'],
        'totals' => $data['totals'],
        'data_quality' => $data['data_quality'] ?? null,
        'data_quality_label' => (string)($data['data_quality']['label'] ?? ''),
        'filters' => $filters,
        'branches' => dl_reportFilterBranches($ctx->db(), $filters),
        'products' => dl_reportFilterProducts($ctx->db(), $filters),
        'columns' => $definitions[$type]['columns'],
    ]);
}

function handleAdminReportSales(array $params = []): void { dl_handleAdminReport('sales'); }
function handleAdminReportVariances(array $params = []): void { dl_handleAdminReport('variances'); }
function handleAdminReportBranchSummary(array $params = []): void { dl_handleAdminReport('branch-summary'); }
function handleAdminReportMonthEnd(array $params = []): void { dl_handleAdminReport('month-end'); }
function handleAdminReportCategorySales(array $params = []): void { dl_handleAdminReport('category-sales'); }
function handleAdminReportDataIntegrity(array $params = []): void { dl_handleAdminReport('data-integrity'); }

function dl_handleAdminReportExport(string $type): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlRequireAuth(['admin', 'supervisor', 'auditor', 'viewer']);
    $input = $ctx->input();
    $format = strtolower(trim((string)($input['format'] ?? 'pdf')));
    if (!in_array($format, ['pdf', 'csv'], true)) { http_response_code(422); echo 'Unsupported format'; return; }
    try {
        $filters = dl_reportFilters($input, $user);
        $data = dl_reportDataForType($ctx->db(), $type, $filters);
        $branchLabel = 'all';
        foreach (dl_reportFilterBranches($ctx->db(), $filters) as $branch) {
            if ((int)$branch['id'] === (int)$filters['branch_id']) { $branchLabel = (string)$branch['code']; break; }
        }
        $export = dl_generateGovernedReport($type, $format, $data, $filters, $user, $branchLabel);
        if (!is_array($export)) { throw new DlReportUserException('Unable to generate report.'); }
        if (!empty($export['queued'])) {
            http_response_code(202);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)$export['message'];
            return;
        }
        header('Content-Type: ' . $export['mime']);
        header('Content-Disposition: attachment; filename="' . basename((string)$export['filename']) . '"');
        header('Content-Length: ' . (int)$export['size']);
        readfile((string)$export['path']);
        @unlink((string)$export['path']);
    } catch (Throwable $e) {
        write_log('daily-ledger report export failed: ' . $e->getMessage(), 'error', ['type' => $type, 'format' => $format]);
        http_response_code(422);
        echo $e instanceof DlReportUserException ? $e->getMessage() : 'Unable to generate report.';
    }
}

function handleAdminReportSalesExport(array $params = []): void { dl_handleAdminReportExport('sales'); }
function handleAdminReportVariancesExport(array $params = []): void { dl_handleAdminReportExport('variances'); }
function handleAdminReportBranchSummaryExport(array $params = []): void { dl_handleAdminReportExport('branch-summary'); }
function handleAdminReportMonthEndExport(array $params = []): void { dl_handleAdminReportExport('month-end'); }
function handleAdminReportCategorySalesExport(array $params = []): void { dl_handleAdminReportExport('category-sales'); }
function handleAdminReportDataIntegrityExport(array $params = []): void { dl_handleAdminReportExport('data-integrity'); }

function handleAdminForecast(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlRequireAuth(['admin', 'supervisor', 'auditor']);
    $input = $ctx->input();
    $filters = dl_reportFilters($input, $user);
    $targetDate = dl_reportValidDate((string)($input['target_date'] ?? ''))
        ?: (new DateTimeImmutable(dl_businessDate()))->modify('+1 day')->format('Y-m-d');
    $window = max(3, min(90, (int)($input['window'] ?? 14)));
    $rows = dl_forecastRows($ctx->db(), $filters, $targetDate, $window);
    echo dlRender('modules/daily-ledger/admin/forecast.disyl', [
        'page_title' => 'Production Forecast',
        'user_name' => (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User'),
        'user_role' => (string)($user['role'] ?? ''),
        'current_page' => 'forecast',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'target_date' => $targetDate,
        'window' => $window,
        'rows' => $rows,
        'filters' => $filters,
        'branches' => dl_reportFilterBranches($ctx->db(), $filters),
        'products' => dl_reportFilterProducts($ctx->db(), $filters),
    ]);
}

function handleAdminSales(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlRequireAuth(['admin', 'supervisor', 'auditor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $today;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $actionFilter = trim((string)($input['action_filter'] ?? ''));
    $search   = trim((string)($input['q'] ?? ''));
    $shiftFilter = strtoupper(trim((string)($input['shift'] ?? '')));
    if (!in_array($shiftFilter, ['AM', 'PM'], true)) {
        $shiftFilter = '';
    }

    $actionFilterMap = [
        'output' => ['production_output'],
        // The withdrawal audit action actually written is 'withdrawal'
        // (handlers-offline.php and apiCreateWithdrawal), plus 'withdrawal_updated'
        // for edits. 'production_withdrawal' is retained last for any legacy rows.
        'withdrawal' => ['withdrawal', 'withdrawal_updated', 'production_withdrawal'],
        'delivery' => [
            'create_delivery',
            'delivery_created',
            'update_delivery',
            'delivery_posted',
            'delivery_voided',
            'create_receiving',
            'receiving_created',
            'receiving_posted',
            'receiving_voided',
            'review_delivery_provenance',
        ],
        'product' => ['create_product', 'update_product'],
        'user' => ['create_user', 'update_user', 'delete_user', 'restore_user'],
        'commissary' => ['create_commissary_run', 'update_commissary_run', 'delete_commissary_run', 'save_commissary_material'],
        'ledger' => ['field_update', 'row_update', 'close_day', 'reopen_day'],
        'variance' => ['variance_status'],
    ];
    if ($actionFilter !== '' && !isset($actionFilterMap[$actionFilter])) {
        $actionFilter = '';
    }
    $actionFilterOptions = [
        ['value' => '', 'label' => 'All Activities'],
        ['value' => 'output', 'label' => 'Output'],
        ['value' => 'withdrawal', 'label' => 'Withdrawal'],
        ['value' => 'delivery', 'label' => 'Delivery'],
        ['value' => 'ledger', 'label' => 'Ledger'],
        ['value' => 'commissary', 'label' => 'Commissary'],
        ['value' => 'product', 'label' => 'Product'],
        ['value' => 'user', 'label' => 'User'],
        ['value' => 'variance', 'label' => 'Variance'],
    ];

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Sales data with computed sales and amount (admin can see these).
    // shift_status marks unfinalized manual PM rows as provisional.
    $salesExpr = dl_ledgerSalesQuantitySql('dl');
    $amountExpr = dl_ledgerSalesAmountSql('dl');
    // A row is provisional when dl_rowIsProvisional() says so. The rule lives in ONE
    // place (dl_provisionalSqlExpr, the SQL twin of dl_rowIsProvisional()); the aggregate
    // totals and the rendered rows therefore cannot disagree about it.
    $provisionalExpr = dl_provisionalSqlExpr('dl', 'ss');

    $where = 'dl.branch_id IN (' . $branchPlaceholders . ') AND dl.ledger_date BETWEEN ? AND ?';
    $bind = array_merge($accessibleBranchIds, [$dateFrom, $dateTo]);

    if ($branchId) {
        $where .= ' AND dl.branch_id = ?';
        $bind[] = $branchId;
    }
    if ($search !== '') {
        $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ?)';
        $like = "%{$search}%";
        $bind[] = $like;
        $bind[] = $like;
        $bind[] = $like;
    }
    if ($shiftFilter !== '') {
        $where .= ' AND dl.shift = ?';
        $bind[] = $shiftFilter;
    }

    $salesFromSql = 'FROM dl_daily_ledger dl
             INNER JOIN dl_products p ON p.id = dl.product_id
             INNER JOIN dl_branches b ON b.id = dl.branch_id
             LEFT JOIN dl_ledger_shift_status ss ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
            WHERE ' . $where;

    // Grand totals — official vs provisional — over every matching row, not over
    // the capped slice rendered below.
    $totalsStmt = $ctx->db()->prepare(
        'SELECT COUNT(*) AS row_count,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE (' . $salesExpr . ') END), 0) AS official_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN 0 ELSE (' . $amountExpr . ') END), 0) AS official_amount,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN (' . $salesExpr . ') ELSE 0 END), 0) AS provisional_units,
                COALESCE(SUM(CASE WHEN ' . $provisionalExpr . ' THEN (' . $amountExpr . ') ELSE 0 END), 0) AS provisional_amount '
        . $salesFromSql
    );
    $totalsStmt->execute($bind);
    $salesTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $salesTotalMatching = (int)($salesTotals['row_count'] ?? 0);
    $grandUnits = (int)($salesTotals['official_units'] ?? 0);
    $grandAmount = (float)($salesTotals['official_amount'] ?? 0);
    $provisionalUnits = (int)($salesTotals['provisional_units'] ?? 0);
    $provisionalAmount = (float)($salesTotals['provisional_amount'] ?? 0);

    // dl.end_source is REQUIRED by C1 in dl_rowIsProvisional() (reached via
    // dl_salesRowStatusLabel()): without it the row badge is blind to a derived, unverified
    // ending. Do not drop this column from the SELECT.
    $listStmt = $ctx->db()->prepare(
        'SELECT dl.ledger_date, dl.shift, p.name AS product_name, p.sku, b.name AS branch_name,
                   dl.beg_bal, dl.addtl, dl.withdraw, dl.bal_end, dl.end_source,
                   ' . $salesExpr . ' AS sales,
                   dl.price_snapshot,
                   (' . $amountExpr . ') AS amount,
                   ss.status AS shift_status '
        . $salesFromSql . ' ORDER BY dl.ledger_date DESC, b.name, p.name LIMIT ' . DL_SALES_PAGE_ROW_LIMIT
    );
    $listStmt->execute($bind);
    $salesRows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Label every rendered row with the SAME canonical predicate that buckets the totals
    // (dl_rowIsProvisional()), so the badge and the footer cannot disagree. Do not
    // re-derive the rule here or in the template — that duplication is the defect.
    $pendingDates = [];
    foreach ($salesRows as &$salesRow) {
        $salesRow['status_label'] = dl_salesRowStatusLabel($salesRow);
        if ($salesRow['status_label'] !== 'official') {
            $pendingDates[(string)$salesRow['ledger_date']] = true;
        }
    }
    unset($salesRow);
    $pendingDates = array_keys($pendingDates);
    sort($pendingDates);

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $stmtAll = $ctx->db()->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $stmtAll->execute($accessibleBranchIds);
    $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clockLabel = dl_operatingClockLabel();

    // POS reconciliation: per-branch sales mode + POS-vs-calculated summary
    // for the filtered range. Never additive — labels the official source.
    $posEnabled = dl_isPosEnabled();
    $posBranchModes = [];
    $posReconciliation = null;
    if ($posEnabled && $dateFrom === $dateTo) {
        foreach ($branches as $b) {
            $bid = (int)($b['id'] ?? 0);
            if ($bid > 0) {
                $posMode = dl_pos_dayMode($ctx->db(), $bid, $dateFrom);
                $posBranchModes[$bid] = $posMode['mode'];
            }
        }
        if ($branchId > 0) {
            $posReconciliation = dl_pos_salesSummary($ctx->db(), $branchId, $dateFrom);
        }
    }

    // The table below always shows stock-derived rows; when a single branch-day
    // reconciliation exists, label the OFFICIAL source (which may be POS/fallback).
    $salesSourceLabel = 'Stock-derived (manual ledger)';
    if (is_array($posReconciliation)) {
        $salesSourceLabel = match ($posReconciliation['sales_source'] ?? '') {
            'pos' => 'POS (completed sales, net of refunds)',
            'fallback' => 'POS before checkpoint + stock-derived after',
            default => 'Stock-derived (manual ledger)',
        };
    }

    echo dlRender('modules/daily-ledger/admin/sales.disyl', [
        'page_title'   => 'Sales Summary',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'sales',
        'base_url' => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'branch_id'    => $branchId,
        'branches'     => $branches,
        'sales_rows'   => $salesRows,
        'sales_total_matching' => $salesTotalMatching,
        'sales_shown'          => count($salesRows),
        'sales_row_limit'      => DL_SALES_PAGE_ROW_LIMIT,
        'pending_dates'        => $pendingDates,
        'grand_units'  => $grandUnits,
        'grand_amount' => $grandAmount,
        'provisional_units' => $provisionalUnits,
        'provisional_amount' => $provisionalAmount,
        'search'       => $search,
        'filter_shift' => $shiftFilter,
        'pos_enabled'  => $posEnabled,
        'pos_branch_modes' => $posBranchModes,
        'pos_reconciliation' => $posReconciliation,
        'sales_source_label' => $salesSourceLabel,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'all_branches' => $allBranches,
    ]);
}

function handleAdminProductionOutputRedirect(array $params = []): void
{
    dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    dlRedirect('/daily-ledger/admin/commissary');
}

function handleAdminSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $canManageFeatureActivation = dl_canManageFeatureActivation($user);
    $permissions = dl_rolePermissions();
    $closeOfDaySettings = dl_closeOfDaySettings();
    $featureSettings = dl_featureSettings();
    $backupSettings = dl_backupSettings();
    $resetSafeguardSettings = dl_resetSafeguardSettings();

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/settings.disyl', [
        'page_title' => 'Daily Ledger Settings',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'settings',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'perm_supervisor_ledger_override' => in_array('ledger.override', $permissions['supervisor'] ?? [], true),
        'perm_supervisor_production_override' => in_array('production.override', $permissions['supervisor'] ?? [], true),
        'perm_supervisor_delivery_edit' => in_array('delivery.edit', $permissions['supervisor'] ?? [], true),
        'perm_cashier_delivery_edit' => in_array('delivery.edit', $permissions['cashier'] ?? [], true),
        'perm_prod_ledger_override' => in_array('ledger.override', $permissions['production_in_charge'] ?? [], true),
        'perm_prod_production_override' => in_array('production.override', $permissions['production_in_charge'] ?? [], true),
        'auto_close_enabled' => $closeOfDaySettings['auto_close_enabled'],
        'close_of_day_time' => $closeOfDaySettings['close_of_day_time'],
        'am_shift_cutoff' => dl_amShiftCutoff(),
        'operating_timezone' => $closeOfDaySettings['operating_timezone'],
        'operating_region' => $closeOfDaySettings['operating_region'],
        'operating_timezone_choices' => dl_operatingTimezoneChoices($closeOfDaySettings['operating_timezone']),
        'operating_region_choices' => dl_operatingRegionChoices($closeOfDaySettings['operating_region']),
        'can_manage_feature_activation' => $canManageFeatureActivation,
        'production_output_enabled' => $featureSettings['production_output_enabled'],
        'formal_delivery_workflow_enabled' => $featureSettings['formal_delivery_workflow_enabled'],
        'price_groups_enabled' => $featureSettings['price_groups_enabled'],
        'pos_enabled' => $featureSettings['pos_enabled'],
        'pos_sort_by_sales' => $featureSettings['pos_sort_by_sales'],
        'app_name' => trim((string)(dlModuleSettings()['app_name'] ?? 'Daily Ledger')),
        'logo_url' => dlLogoUrl(),
        'favicon_url' => dlFaviconUrl(),
        'backup_before_reset_enabled' => $backupSettings['backup_before_reset_enabled'],
        'backup_include_users' => $backupSettings['backup_include_users'],
        'backup_retention_days' => $backupSettings['backup_retention_days'],
        'reset_second_phrase_enabled' => $resetSafeguardSettings['reset_second_phrase_enabled'],
        'reset_second_phrase' => $resetSafeguardSettings['reset_second_phrase'],
        'max_offline_days' => dl_offlineMaxDays(),
    ]);
}

function handleAdminBackupDownload(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    dlCurrentUser(['admin']);

    // Standard secure download via the shared kernel service (validates the
    // {slug}-db-backup-YYYYMMDD-HHMMSS.sql filename, guards path traversal).
    \Ikabud\Kernel\Services\ModuleBackupService::download($ctx, 'dl_', (string) ($_GET['file'] ?? ''));
}

function apiUploadBrandingAsset(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $user = dlCurrentUser(['admin']);

    $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
    $file = kernelUploadedFile('asset_file');
    if (!is_array($file)) {
        $ctx->json(['ok' => false, 'error' => 'Upload a branding image first.'], 422);
        return;
    }

    try {
        $upload = dlUploadBrandAsset($assetType, $file);
    } catch (InvalidArgumentException $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log('daily-ledger branding upload failed', 'error', [
            'asset_type' => $assetType,
            'message' => $e->getMessage(),
        ]);
        $ctx->json(['ok' => false, 'error' => 'Failed to upload branding asset.'], 500);
        return;
    }

    $settingKey = $assetType === 'favicon' ? 'favicon_url' : 'logo_url';
    if (!dlPersistModuleSettings([$settingKey => $upload['asset_url']])) {
        $ctx->json(['ok' => false, 'error' => 'Branding asset uploaded but could not be persisted to settings.'], 500);
        return;
    }

    dl_auditLog('upload_branding_asset', null, 'module_settings', 'daily-ledger', null, [
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'uploaded_by_role' => (string)($user['role'] ?? ''),
    ]);

    $ctx->json([
        'ok' => true,
        'asset_type' => $assetType,
        'asset_url' => $upload['asset_url'],
        'message' => ucfirst($assetType) . ' uploaded.',
    ]);
}

function apiSaveRolePermissions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);
    $canManageFeatureActivation = dl_canManageFeatureActivation($user);
    $isKernelAdmin = dl_isKernelAdmin($user);
    $input = $ctx->input();

    $toBool = static function ($v): bool {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    };

    if ($toBool($input['db_backup_generate'] ?? false)) {
        $includeUsers = array_key_exists('backup_include_users', $input)
            ? $toBool($input['backup_include_users'])
            : null;
        try {
            $backupResult = dl_generateDatabaseBackup($user, 'manual_settings_backup', $includeUsers);
        } catch (Throwable $e) {
            write_log('daily-ledger database backup failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Database backup failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => 'Daily Ledger database backup created.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'backup' => $backupResult,
        ]);
        return;
    }

    if ($toBool($input['deployment_reset'] ?? false)) {
        $confirmPhrase = trim((string)($input['deployment_reset_confirm'] ?? ''));
        if ($confirmPhrase !== 'RESET DAILY LEDGER DATA') {
            $ctx->json([
                'ok' => false,
                'error' => 'Type RESET DAILY LEDGER DATA to continue.',
            ], 422);
            return;
        }

        $dryRun = $toBool($input['deployment_reset_dry_run'] ?? false);
        $resetSafeguardSettings = dl_resetSafeguardSettings();
        if (!$dryRun && $resetSafeguardSettings['reset_second_phrase_enabled']) {
            $secondConfirm = trim((string)($input['deployment_reset_second_confirm'] ?? ''));
            if ($secondConfirm !== (string)$resetSafeguardSettings['reset_second_phrase']) {
                $ctx->json([
                    'ok' => false,
                    'error' => 'Type the second safeguard phrase exactly before running full reset.',
                ], 422);
                return;
            }
        }

        try {
            $resetResult = dl_runDeploymentDataReset($user, $dryRun);
        } catch (Throwable $e) {
            write_log('daily-ledger deployment reset failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
                'dry_run' => $dryRun,
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Deployment reset failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => $dryRun
                ? 'Reset preview ready. Review row counts before execution.'
                : 'Full reset completed. Only the currently logged-in admin account was preserved.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'deployment_reset' => $resetResult,
        ]);
        return;
    }

    if ($toBool($input['sales_data_reset'] ?? false)) {
        $confirmPhrase = trim((string)($input['sales_data_reset_confirm'] ?? ''));
        if ($confirmPhrase !== 'RESET SALES DATA') {
            $ctx->json([
                'ok' => false,
                'error' => 'Type RESET SALES DATA to continue.',
            ], 422);
            return;
        }

        $dryRun = $toBool($input['sales_data_reset_dry_run'] ?? false);

        try {
            $resetResult = dl_runSalesDataReset($user, $dryRun);
        } catch (Throwable $e) {
            write_log('daily-ledger sales data reset failed', 'error', [
                'message' => $e->getMessage(),
                'actor_role' => (string)($user['role'] ?? ''),
                'actor_source' => (string)($user['source'] ?? ''),
                'dry_run' => $dryRun,
            ]);
            $ctx->json([
                'ok' => false,
                'error' => 'Sales data reset failed: ' . $e->getMessage(),
            ], 500);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => [
            'message' => $dryRun
                ? 'Sales data reset preview ready. Review row counts before execution.'
                : 'Sales data reset completed. Master data (users, branches, products, settings) was preserved.',
            'type' => 'success',
        ]]));
        $ctx->json([
            'ok' => true,
            'sales_data_reset' => $resetResult,
        ]);
        return;
    }

    // Seed each role with its default grants so an unrelated settings save never
    // silently strips permissions. dl_rolePermissions() REPLACES a role's stored
    // permissions (it does not merge), so any grant omitted here (e.g. the POS
    // permissions, which have no settings checkboxes) is lost on save. POS grants
    // mirror dl_defaultRolePermissions(); delivery.edit for supervisor/cashier is
    // still controlled by the checkboxes below.
    $permissions = [
        'admin' => ['ledger.override', 'production.override', 'pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report', 'delivery.edit'],
        'supervisor' => ['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report'],
        'production_in_charge' => [],
        'cashier' => ['pos.sell'],
        'auditor' => [],
        'viewer' => [],
    ];

    if ($toBool($input['supervisor_ledger_override'] ?? false)) {
        $permissions['supervisor'][] = 'ledger.override';
    }
    if ($toBool($input['supervisor_production_override'] ?? false)) {
        $permissions['supervisor'][] = 'production.override';
    }
    if ($toBool($input['supervisor_delivery_edit'] ?? false)) {
        $permissions['supervisor'][] = 'delivery.edit';
    }
    if ($toBool($input['cashier_delivery_edit'] ?? false)) {
        $permissions['cashier'][] = 'delivery.edit';
    }
    if ($toBool($input['prod_ledger_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'ledger.override';
    }
    if ($toBool($input['prod_production_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'production.override';
    }

    $autoCloseEnabled = $toBool($input['auto_close_enabled'] ?? false);
    $closeOfDayTime = dl_normalizeCloseOfDayTime($input['close_of_day_time'] ?? '00:00');
    // AM→PM shift cutoff: valid HH:MM required; falls back to 14:00 otherwise.
    $amShiftCutoff = dl_normalizeCloseOfDayTime($input['am_shift_cutoff'] ?? '14:00');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim((string)($input['am_shift_cutoff'] ?? '')))) {
        $amShiftCutoff = '14:00';
    }
    $operatingTimezone = dl_normalizeTimezone($input['operating_timezone'] ?? config('app.timezone', 'Asia/Manila'));
    $operatingRegion = dl_normalizeRegion($input['operating_region'] ?? '');
    $featureSettings = dl_featureSettings();
    $backupSettings = dl_backupSettings();
    $resetSafeguardSettings = dl_resetSafeguardSettings();
    $productionOutputEnabled = $featureSettings['production_output_enabled'];
    $formalDeliveryEnabled = $featureSettings['formal_delivery_workflow_enabled'];
    $priceGroupsEnabled = $featureSettings['price_groups_enabled'];
    $posEnabled = $featureSettings['pos_enabled'];
    $posSortBySales = $featureSettings['pos_sort_by_sales'];
    $backupBeforeResetEnabled = $backupSettings['backup_before_reset_enabled'];
    $backupIncludeUsers = $backupSettings['backup_include_users'];
    $backupRetentionDays = $backupSettings['backup_retention_days'];
    $resetSecondPhraseEnabled = $resetSafeguardSettings['reset_second_phrase_enabled'];

    if (array_key_exists('production_output_enabled', $input)) {
        if (!$canManageFeatureActivation) {
            $ctx->json([
                'ok' => false,
                'error' => 'Only Admin or Superadmin can change feature activation.',
            ], 403);
            return;
        }
        $productionOutputEnabled = $toBool($input['production_output_enabled']);
    }
    foreach ([
        'formal_delivery_workflow_enabled' => &$formalDeliveryEnabled,
        'price_groups_enabled' => &$priceGroupsEnabled,
        'pos_enabled' => &$posEnabled,
        'pos_sort_by_sales' => &$posSortBySales,
    ] as $key => &$ref) {
        if (array_key_exists($key, $input)) {
            if (!$canManageFeatureActivation) {
                $ctx->json([
                    'ok' => false,
                    'error' => 'Only Admin or Superadmin can change feature activation.',
                ], 403);
                return;
            }
            $ref = $toBool($input[$key]);
        }
    }
    unset($ref);

    if (array_key_exists('backup_before_reset_enabled', $input)) {
        $backupBeforeResetEnabled = $toBool($input['backup_before_reset_enabled']);
    }
    if (array_key_exists('backup_include_users', $input)) {
        $backupIncludeUsers = $toBool($input['backup_include_users']);
    }
    if (array_key_exists('backup_retention_days', $input)) {
        $backupRetentionDays = (int)$input['backup_retention_days'];
    }
    if (array_key_exists('reset_second_phrase_enabled', $input)) {
        $resetSecondPhraseEnabled = $toBool($input['reset_second_phrase_enabled']);
    }
    if ($backupRetentionDays < 1 || $backupRetentionDays > 90) {
        $ctx->json([
            'ok' => false,
            'error' => 'Backup retention days must be between 1 and 90.',
        ], 422);
        return;
    }

    // Max offline days: bounds offline enrollment expiry (1..90).
    if (array_key_exists('max_offline_days', $input)) {
        $maxOfflineDays = (int)$input['max_offline_days'];
        if ($maxOfflineDays < 1 || $maxOfflineDays > 90) {
            $ctx->json([
                'ok' => false,
                'error' => 'Max offline days must be between 1 and 90.',
            ], 422);
            return;
        }
    } else {
        $maxOfflineDays = dl_offlineMaxDays();
    }

    if ($autoCloseEnabled && !dl_isAllowedAutoCloseTime($closeOfDayTime)) {
        $ctx->json([
            'ok' => false,
            'error' => 'Auto close cutoff must be a valid time (00:00 - 23:59).',
        ], 422);
        return;
    }

    $appNameInput = trim((string)($input['app_name'] ?? ''));
    $appName = $appNameInput !== '' ? mb_substr($appNameInput, 0, 80) : 'Daily Ledger';
    try {
        $logoUrl = dlNormalizeBrandAssetUrl($input['logo_url'] ?? '', 'Logo URL');
        $faviconUrl = dlNormalizeBrandAssetUrl($input['favicon_url'] ?? '', 'Favicon URL');
    } catch (InvalidArgumentException $e) {
        $ctx->json([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 422);
        return;
    }

    $settingsToSave = [
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled ? '1' : '0',
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled ? '1' : '0',
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled ? '1' : '0',
        'price_groups_enabled' => $priceGroupsEnabled ? '1' : '0',
        'pos_enabled' => $posEnabled ? '1' : '0',
        'pos_sort_by_sales' => $posSortBySales ? '1' : '0',
        'backup_before_reset_enabled' => $backupBeforeResetEnabled ? '1' : '0',
        'backup_include_users' => $backupIncludeUsers ? '1' : '0',
        'backup_retention_days' => (string)$backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled ? '1' : '0',
        'max_offline_days' => (string)$maxOfflineDays,
    ];

    if (!dlPersistModuleSettings($settingsToSave)) {
        $ctx->json([
            'ok' => false,
            'error' => 'Failed to persist Daily Ledger settings.',
        ], 500);
        return;
    }

    dl_auditLog('update_role_permissions', null, 'module_settings', 'daily-ledger', null, [
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'production_output_enabled' => $productionOutputEnabled,
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled,
        'price_groups_enabled' => $priceGroupsEnabled,
        'pos_enabled' => $posEnabled,
        'pos_sort_by_sales' => $posSortBySales,
        'backup_before_reset_enabled' => $backupBeforeResetEnabled,
        'backup_include_users' => $backupIncludeUsers,
        'backup_retention_days' => $backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled,
        'is_kernel_admin' => $isKernelAdmin,
        'updated_by_role' => (string)($user['role'] ?? ''),
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings updated', 'type' => 'success']]));
    $ctx->json([
        'ok' => true,
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => $faviconUrl,
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'backup_before_reset_enabled' => $backupBeforeResetEnabled,
        'backup_include_users' => $backupIncludeUsers,
        'backup_retention_days' => $backupRetentionDays,
        'reset_second_phrase_enabled' => $resetSecondPhraseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'am_shift_cutoff' => $amShiftCutoff,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled,
        'formal_delivery_workflow_enabled' => $formalDeliveryEnabled,
        'price_groups_enabled' => $priceGroupsEnabled,
        'pos_enabled' => $posEnabled,
        'pos_sort_by_sales' => $posSortBySales,
    ]);
}

/**
 * Resolve the variance dashboard's date range from request input.
 *
 * Accepts date_from/date_to; falls back to the legacy single-day `date` (which
 * scopes both bounds so older links keep working); swaps reversed bounds and
 * ignores malformed dates. Empty strings mean "no bound".
 *
 * @return array{0:string,1:string} [from, to]
 */
function dl_varianceDateRange(array $input): array
{
    $from = (string)(dl_reportValidDate(trim((string)($input['date_from'] ?? ''))) ?? '');
    $to = (string)(dl_reportValidDate(trim((string)($input['date_to'] ?? ''))) ?? '');
    if ($from === '' && $to === '') {
        $legacy = (string)(dl_reportValidDate(trim((string)($input['date'] ?? ''))) ?? '');
        $from = $legacy;
        $to = $legacy;
    }
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to];
}

/**
 * Classify one shift's reconciliation against the ledger's derived sales.
 *
 * Pure so the rule can be tested directly rather than through a rendered page: this is the
 * business decision (checked vs not, matched vs mismatch) that an operator is expected to
 * act on, and it must not be buried in a template.
 *
 * $hasReconRow distinguishes "nobody has looked at this shift" from "looked and matched".
 * A shift with no reconciliation row is NOT a zero-variance shift, and it is not an error
 * either - it is simply unchecked, and reporting it as matched would hide it.
 *
 * A variance is only computed against a figure that was actually entered; a NULL paper or
 * cash yields a NULL variance rather than a difference from 0, because 0 is a real result
 * (a shift that sold nothing) and must not be fabricated.
 *
 * @return array{paper_variance: ?float, cash_variance: ?float, is_checked: bool, is_mismatch: bool, state: string}
 */
function dl_reconciliationState(bool $hasReconRow, float $ledgerSales, ?float $paper, ?float $cash): array
{
    $paperVariance = $paper === null ? null : round($paper - $ledgerSales, 2);
    $cashVariance = $cash === null ? null : round($cash - $ledgerSales, 2);

    $isChecked = $hasReconRow && ($paper !== null || $cash !== null);
    $isMismatch = false;
    if ($isChecked) {
        $isMismatch = ($paperVariance !== null && abs($paperVariance) > DL_RECON_MATCH_TOLERANCE)
                   || ($cashVariance !== null && abs($cashVariance) > DL_RECON_MATCH_TOLERANCE);
    }

    return [
        'paper_variance' => $paperVariance,
        'cash_variance' => $cashVariance,
        'is_checked' => $isChecked,
        'is_mismatch' => $isMismatch,
        'state' => !$isChecked ? 'unchecked' : ($isMismatch ? 'mismatch' : 'matched'),
    ];
}

/**
 * Shift reconciliation — the admin's external check on a shift.
 *
 * The ledger's sales is derived from counts, so it is only ever an INTERNAL check: it
 * proves the arithmetic is self-consistent and nothing was double-counted, but it cannot
 * say whether the money was collected. Stock that leaves the shelf without a recorded
 * `withdraw` reads as a sale (ledger overstates, cash comes up short), and `withdraw` is
 * also the catch-all for spoilage, so a legitimate write-off and a disappearance are
 * indistinguishable in the data. Only the paper sheet and the cash handed over at
 * remittance can settle that, and this page is where the admin records the comparison.
 *
 * Ledger sales is computed live from `dl_daily_ledger` and never read from a stored copy,
 * so correcting a count on the day immediately changes what the paper and cash are
 * compared against (the reason 061 exists).
 */
function handleAdminReconciliation(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);
    $role = (string)($user['role'] ?? '');
    $canManage = in_array($role, ['admin', 'supervisor'], true);

    $input = $ctx->input();
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    [$dateFrom, $dateTo] = dl_varianceDateRange($input);
    if ($dateFrom === '') { $dateFrom = date('Y-m-d', strtotime('-13 days')); }
    if ($dateTo === '') { $dateTo = dl_businessDate(); }
    if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

    // "Only what needs attention" — unchecked shifts and any shift whose entered figures
    // disagree with the ledger. A shift nothing was recorded for is NOT the same as a
    // shift that was checked and matched, so the default view must not hide it.
    $only = strtolower(trim((string)($input['only'] ?? '')));
    $onlyFilter = in_array($only, ['attention', 'checked', 'unchecked'], true) ? $only : '';

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branchStmt = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branchStmt->execute($accessibleBranchIds);
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($branchId !== null && !in_array($branchId, $accessibleBranchIds, true)) {
        $branchId = null;
    }

    // One row per (branch, date, shift) that exists in the ledger. The reconciliation
    // row is LEFT JOINed: its absence means "nobody has checked this shift yet".
    $sql = 'SELECT dl.branch_id, b.name AS branch_name, b.code AS branch_code,
                   dl.ledger_date, dl.shift,
                   COUNT(*) AS row_count,
                   SUM(dl.bal_end IS NULL) AS pending_rows,
                   ROUND(SUM(CASE WHEN dl.bal_end IS NULL THEN 0 ELSE GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) * COALESCE(dl.price_snapshot,0) END), 2) AS ledger_sales,
                   SUM(CASE WHEN dl.shift = \'PM\' AND COALESCE(ss.status, \'\') <> \'finalized\' THEN 1 ELSE 0 END) AS pm_unfinalized_rows,
                   r.id AS recon_id, r.paper_sales, r.cash_remitted, r.review_note,
                   r.recorded_at, COALESCE(u.full_name, \'\') AS recorded_by_name
              FROM dl_daily_ledger dl
              INNER JOIN dl_branches b ON b.id = dl.branch_id
              LEFT JOIN dl_ledger_shift_status ss
                     ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
                    AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
              LEFT JOIN dl_shift_reconciliation r
                     ON r.branch_id = dl.branch_id AND r.ledger_date = dl.ledger_date
                    AND r.shift = dl.shift COLLATE utf8mb4_unicode_ci
              LEFT JOIN dl_users u ON u.id = r.recorded_by
             WHERE dl.branch_id IN (' . $branchPlaceholders . ')
               AND dl.ledger_date BETWEEN ? AND ?';
    $bind = array_merge($accessibleBranchIds, [$dateFrom, $dateTo]);
    if ($branchId !== null) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = $branchId;
    }
    $sql .= ' GROUP BY dl.branch_id, b.name, b.code, dl.ledger_date, dl.shift,
                      r.id, r.paper_sales, r.cash_remitted, r.review_note, r.recorded_at, u.full_name
              ORDER BY dl.ledger_date DESC, b.name, dl.shift';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $stats = [
        'total' => 0, 'checked' => 0, 'unchecked' => 0, 'matched' => 0, 'mismatch' => 0,
        'cash_variance_total' => 0.0, 'paper_variance_total' => 0.0,
    ];
    foreach ($rawRows as $raw) {
        $ledgerSales = (float)($raw['ledger_sales'] ?? 0);
        $paper = $raw['paper_sales'] === null ? null : (float)$raw['paper_sales'];
        $cash = $raw['cash_remitted'] === null ? null : (float)$raw['cash_remitted'];

        $state = dl_reconciliationState($raw['recon_id'] !== null, $ledgerSales, $paper, $cash);
        $checked = $state['is_checked'];
        $mismatch = $state['is_mismatch'];

        $stats['total']++;
        if ($checked) { $stats['checked']++; } else { $stats['unchecked']++; }
        if ($checked && $mismatch) { $stats['mismatch']++; }
        if ($checked && !$mismatch) { $stats['matched']++; }
        if ($checked) {
            $stats['cash_variance_total'] += (float)($state['cash_variance'] ?? 0);
            $stats['paper_variance_total'] += (float)($state['paper_variance'] ?? 0);
        }

        $raw['ledger_sales'] = $ledgerSales;
        $raw['paper_sales'] = $paper;
        $raw['cash_remitted'] = $cash;
        $raw['paper_variance'] = $state['paper_variance'];
        $raw['cash_variance'] = $state['cash_variance'];
        // Explicit presence flags: the template must not test the amount itself, because
        // a recorded 0.00 is a real result ("sold nothing") and is falsy.
        $raw['has_paper'] = $paper !== null;
        $raw['has_cash'] = $cash !== null;
        $raw['has_note'] = trim((string)($raw['review_note'] ?? '')) !== '';
        // Precomputed so the read-only cell needs no nested {if} and no filter chain: a
        // plain string the template can print and escape once.
        $raw['note_display'] = $raw['has_note'] ? (string)$raw['review_note'] : '—';
        $raw['is_checked'] = $checked;
        $raw['is_mismatch'] = $mismatch;
        $raw['state'] = $state['state'];
        $raw['has_pending_ending'] = ((int)($raw['pending_rows'] ?? 0)) > 0;
        $raw['is_provisional'] = ((int)($raw['pm_unfinalized_rows'] ?? 0)) > 0;

        if ($onlyFilter === 'attention' && $raw['state'] === 'matched') { continue; }
        if ($onlyFilter === 'checked' && !$checked) { continue; }
        if ($onlyFilter === 'unchecked' && $checked) { continue; }

        $rows[] = $raw;
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/reconciliation.disyl', [
        'page_title'   => 'Cash & Paper Check',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'reconciliation',
        'base_url'     => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'branch_id'    => $branchId,
        'branches'     => $branches,
        'only_filter'  => $onlyFilter,
        'rows'         => $rows,
        'row_count'    => count($rows),
        'stats'        => $stats,
        'can_manage'   => $canManage,
        'tolerance'    => DL_RECON_MATCH_TOLERANCE,
        'business_date' => dl_businessDate(),
    ]);
}

function handleAdminVariances(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);
    $role = (string)($user['role'] ?? '');
    $isSupervisor = ($role === 'supervisor');

    $input = $ctx->input();
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $statusFilter = (string)($input['status'] ?? '');
    $kindFilter = (string)($input['kind'] ?? '');
    $shiftFilter = strtoupper(trim((string)($input['shift'] ?? '')));
    if (!in_array($kindFilter, ['overnight', 'handoff', 'ending', 'sales', 'delivery'], true)) { $kindFilter = ''; }
    if (!in_array($shiftFilter, ['AM', 'PM'], true)) { $shiftFilter = ''; }
    // Date range (From/To). A legacy single-day ?date= still works and scopes
    // both bounds, so bookmarks and the report deep-links keep behaving.
    [$dateFrom, $dateTo] = dl_varianceDateRange($input);
    $search   = trim((string)($input['q'] ?? ''));
    $viewMode = $input['view'] ?? ($isSupervisor ? 'grouped' : 'list');

    // For supervisors, restrict to their assigned branches if no explicit filter
    $supervisorBranchIds = [];
    if ($isSupervisor) {
        $userId = dl_getActorUserId($user);
        $sbStmt = $ctx->db()->prepare(
            'SELECT branch_id FROM dl_user_branches WHERE user_id = :uid'
        );
        $sbStmt->execute([':uid' => $userId]);
        $supervisorBranchIds = array_map('intval', $sbStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Self-healing: refresh variances for the viewed day on open days so the
    // page surfaces anomalies even when rows entered before the variance
    // enhancement (imports, pre-deployment data) never triggered recompute.
    // Only a single-day view is cheap enough to heal; a wider range is a review
    // view, so opening a month must not trigger a month of recomputes. With no
    // date filter at all the previous behaviour is kept and today is healed.
    $refreshDate = ($dateFrom !== '' || $dateTo !== '')
        ? (($dateFrom !== '' && $dateFrom === $dateTo) ? $dateFrom : '')
        : dl_businessDate();
    dl_refreshVariancesForDateView($refreshDate, $accessibleBranchIds);

    // Build the variance filter in two layers.
    //
    // $whereScope carries every filter except status. It drives the dashboard
    // figures, because status is a *facet*: the Unreviewed/Investigated/Corrected
    // buttons must keep showing each other's real totals. Counting them through
    // the active status filter made the inactive buttons collapse to zero.
    //
    // $whereFiltered adds the status filter on top and drives the rendered list,
    // so the active button's own count still equals the rows it lists.
    $whereScope = '1=1';
    $bind = [];

    // An explicit date range scopes the list and every figure above it. The
    // default view stays the newest flags across all dates.
    if ($dateFrom !== '' && $dateTo !== '') {
        $whereScope .= ' AND vf.ledger_date BETWEEN :dfrom AND :dto';
        $bind[':dfrom'] = $dateFrom;
        $bind[':dto'] = $dateTo;
    } elseif ($dateFrom !== '') {
        $whereScope .= ' AND vf.ledger_date >= :dfrom';
        $bind[':dfrom'] = $dateFrom;
    } elseif ($dateTo !== '') {
        $whereScope .= ' AND vf.ledger_date <= :dto';
        $bind[':dto'] = $dateTo;
    }

    if ($branchId) {
        $whereScope .= ' AND vf.branch_id = :bid';
        $bind[':bid'] = $branchId;
    } elseif ($isSupervisor && !empty($supervisorBranchIds)) {
        // Auto-scope to supervisor branches when no explicit filter
        $placeholders = [];
        foreach ($supervisorBranchIds as $i => $sbId) {
            $key = ":sb_{$i}";
            $placeholders[] = $key;
            $bind[$key] = $sbId;
        }
        $whereScope .= ' AND vf.branch_id IN (' . implode(',', $placeholders) . ')';
    }

    if ($kindFilter !== '') {
        $whereScope .= ' AND vf.kind = :kind';
        $bind[':kind'] = $kindFilter;
    }
    if ($shiftFilter !== '') {
        // Cross-shift visibility: a delivery variance is stored with shift = NULL
        // on purpose (the goods were produced in one shift and received in
        // another). Matching only the requested shift would hide an unreviewed
        // discrepancy from BOTH the AM and the PM view, so an explicit filter
        // must also surface shift-agnostic rows. Shift-scoped kinds
        // (overnight/handoff/ending/sales) still match only their own shift,
        // because those rows never carry a NULL shift.
        $whereScope .= ' AND (vf.shift = :shift OR vf.shift IS NULL)';
        $bind[':shift'] = $shiftFilter;
    }
    if ($search !== '') {
        $whereScope .= ' AND (p.name LIKE :q OR p.sku LIKE :q2 OR b.name LIKE :q3 OR b.code LIKE :q4)';
        $bind[':q'] = "%{$search}%";
        $bind[':q2'] = "%{$search}%";
        $bind[':q3'] = "%{$search}%";
        $bind[':q4'] = "%{$search}%";
    }

    $whereFiltered = $whereScope;
    $bindFiltered = $bind;
    $statusIsValid = in_array($statusFilter, ['unreviewed', 'investigated', 'corrected'], true);
    if ($statusFilter !== '' && $statusIsValid) {
        $whereFiltered .= ' AND vf.resolution_status = :st';
        $bindFiltered[':st'] = $statusFilter;
    }

    // Base FROM shared by the aggregates and the rendered list. The two
    // delivery-shift joins are keyed on primary keys (dl_deliveries.id,
    // dl_branch_receivings.id) and are therefore strictly 1:1, so they cannot
    // multiply a variance row or move an aggregate count. They let a delivery
    // variance show which shift produced/dispatched the goods
    // (d.production_shift) and which shift received them (r.received_shift)
    // while the flag itself deliberately keeps shift = NULL (cross-shift event)
    // and is never bucketed.
    $varianceFromSql = 'FROM dl_variance_flags vf
            INNER JOIN dl_products p ON p.id = vf.product_id
            INNER JOIN dl_branches b ON b.id = vf.branch_id
            LEFT JOIN dl_users reviewer ON reviewer.id = vf.reviewed_by
            LEFT JOIN dl_deliveries d ON d.id = vf.delivery_id
            LEFT JOIN dl_branch_receivings r ON r.id = vf.receiving_id
            WHERE ';
    $scopeFromSql = $varianceFromSql . $whereScope;
    $filteredFromSql = $varianceFromSql . $whereFiltered;

    // Full-set aggregates over the scope (status excluded) — these drive the
    // dashboard figures and every filter button's count.
    $aggStmt = $ctx->db()->prepare(
        'SELECT vf.resolution_status, vf.kind, COUNT(*) AS flag_count, COALESCE(SUM(vf.variance), 0) AS net_variance '
        . $scopeFromSql . ' GROUP BY vf.resolution_status, vf.kind'
    );
    $aggStmt->execute($bind);
    $aggRows = $aggStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $branchAggStmt = $ctx->db()->prepare(
        'SELECT vf.branch_id, b.name AS branch_name, b.code AS branch_code, vf.resolution_status,
                COUNT(*) AS flag_count, COALESCE(SUM(vf.variance), 0) AS net_variance '
        . $scopeFromSql . ' GROUP BY vf.branch_id, b.name, b.code, vf.resolution_status'
    );
    $branchAggStmt->execute($bind);
    $branchAgg = $branchAggStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // The rendered slice: status-filtered, capped, newest first.
    $listStmt = $ctx->db()->prepare(
        'SELECT vf.*, p.name AS product_name, p.sku AS product_sku, b.name AS branch_name, b.code AS branch_code,
                COALESCE(reviewer.full_name, \'Unknown\') AS reviewer_name,
                d.production_shift AS delivery_production_shift,
                r.received_shift AS delivery_received_shift '
        . $filteredFromSql . ' ORDER BY vf.ledger_date DESC, b.name, p.name LIMIT ' . DL_VARIANCE_PAGE_ROW_LIMIT
    );
    $listStmt->execute($bindFiltered);
    $variances = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Annotate the delivery sent-vs-received rows so the resolve surface can
    // present the explicit two-way admin choice rather than a status flip.
    $deliveryChoiceLabels = [
        'accept_production' => 'Accepted production',
        'keep_as_evidence' => 'Kept as evidence',
    ];
    foreach ($variances as &$varianceRow) {
        $isDeliveryVariance = (string)($varianceRow['kind'] ?? '') === 'delivery';
        $varianceRow['is_delivery'] = $isDeliveryVariance;
        $varianceRow['choice_label'] = $deliveryChoiceLabels[(string)($varianceRow['resolution_choice'] ?? '')] ?? '';
        $varianceRow['decision_ready'] = $isDeliveryVariance
            && (string)($varianceRow['resolution_status'] ?? '') === 'investigated';
    }
    unset($varianceRow);

    // ── Aggregate stats ──────────────────────────────────────────────
    // Counted in SQL over every matching flag: the rendered list below is capped,
    // so counting it here would understate the dashboard.
    $statsTotal = 0;
    $statsUnreviewed = 0;
    $statsInvestigated = 0;
    $statsCorrected = 0;
    $statsByKind = [
        'overnight' => ['count' => 0, 'net' => 0],
        'handoff' => ['count' => 0, 'net' => 0],
        'ending' => ['count' => 0, 'net' => 0],
        'sales' => ['count' => 0, 'net' => 0],
        'delivery' => ['count' => 0, 'net' => 0],
    ];
    foreach ($aggRows as $agg) {
        $aggCount = (int)($agg['flag_count'] ?? 0);
        $statsTotal += $aggCount;
        $aggStatus = (string)($agg['resolution_status'] ?? '');
        if ($aggStatus === 'unreviewed') { $statsUnreviewed += $aggCount; }
        elseif ($aggStatus === 'investigated') { $statsInvestigated += $aggCount; }
        elseif ($aggStatus === 'corrected') { $statsCorrected += $aggCount; }
        $aggKind = (string)($agg['kind'] ?? 'overnight');
        if (isset($statsByKind[$aggKind])) {
            $statsByKind[$aggKind]['count'] += $aggCount;
            $statsByKind[$aggKind]['net'] += (int)($agg['net_variance'] ?? 0);
        }
    }
    // Net is only meaningful within a single variance kind — never across kinds.
    $statsTotalVariance = $kindFilter !== '' && isset($statsByKind[$kindFilter])
        ? $statsByKind[$kindFilter]['net']
        : null;

    // How many rows the rendered list actually matches (status applied), so the
    // truncation notice stays truthful while the buttons keep scope totals.
    $filteredTotal = match ($statusFilter) {
        'unreviewed' => $statsUnreviewed,
        'investigated' => $statsInvestigated,
        'corrected' => $statsCorrected,
        default => $statsTotal,
    };

    // ── Per-branch breakdown ─────────────────────────────────────────
    // Branch counts describe every matching flag; only the expandable item lists
    // come from the capped page slice.
    $branchSummary = [];
    foreach ($branchAgg as $agg) {
        $bid = (int)($agg['branch_id'] ?? 0);
        if (!isset($branchSummary[$bid])) {
            $branchSummary[$bid] = [
                'branch_id'   => $bid,
                'branch_name' => (string)($agg['branch_name'] ?? 'Unknown'),
                'branch_code'  => (string)($agg['branch_code'] ?? ''),
                'total'       => 0,
                'unreviewed'  => 0,
                'investigated'=> 0,
                'corrected'   => 0,
                'net_variance'=> 0,
                'items'       => [],
            ];
        }
        $aggCount = (int)($agg['flag_count'] ?? 0);
        $branchSummary[$bid]['total'] += $aggCount;
        $branchSummary[$bid]['net_variance'] += (int)($agg['net_variance'] ?? 0);
        $aggStatus = (string)($agg['resolution_status'] ?? '');
        if (isset($branchSummary[$bid][$aggStatus])) {
            $branchSummary[$bid][$aggStatus] += $aggCount;
        }
    }
    foreach ($variances as $v) {
        $bid = (int)($v['branch_id'] ?? 0);
        if (!isset($branchSummary[$bid])) {
            $branchSummary[$bid] = [
                'branch_id'   => $bid,
                'branch_name' => (string)($v['branch_name'] ?? 'Unknown'),
                'branch_code'  => (string)($v['branch_code'] ?? ''),
                'total'       => 0,
                'unreviewed'  => 0,
                'investigated'=> 0,
                'corrected'   => 0,
                'net_variance'=> 0,
                'items'       => [],
            ];
        }
        $branchSummary[$bid]['items'][] = $v;
    }
    // Sort branches by unreviewed count desc, then name
    uasort($branchSummary, function($a, $b) {
        if ($a['unreviewed'] !== $b['unreviewed']) {
            return $b['unreviewed'] - $a['unreviewed'];
        }
        return strcmp($a['branch_name'], $b['branch_name']);
    });
    $branchSummary = array_values($branchSummary);

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $notificationRows = [];
    $notificationUserId = dl_getActorUserId($user);
    if ($notificationUserId > 0) {
        $notificationStmt = $ctx->db()->prepare(
            'SELECT n.id, n.title, n.detail, n.finding_count, n.raised_at, r.notified_at, r.seen_at
               FROM dl_integrity_notification_recipients r
               JOIN dl_integrity_notifications n ON n.id = r.notification_id
              WHERE r.user_id = :u
              ORDER BY n.raised_at DESC LIMIT 20'
        );
        $notificationStmt->execute([':u' => $notificationUserId]);
        $notificationRows = $notificationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ctx->db()->prepare('UPDATE dl_integrity_notification_recipients SET seen_at = COALESCE(seen_at, NOW()) WHERE user_id = :u')
            ->execute([':u' => $notificationUserId]);
        foreach ($notificationRows as &$notificationRow) {
            if ($notificationRow['seen_at'] === null) $notificationRow['seen_at'] = date('Y-m-d H:i:s');
        }
        unset($notificationRow);
    }
    // Every filter that changes the result set, so the status pills, the stat
    // cards and the search form keep the date range and branch instead of
    // silently dropping them.
    $filterParams = [];
    if ($branchId) { $filterParams['branch_id'] = (string)$branchId; }
    if ($search !== '') { $filterParams['q'] = $search; }
    if ($kindFilter !== '') { $filterParams['kind'] = $kindFilter; }
    if ($shiftFilter !== '') { $filterParams['shift'] = $shiftFilter; }
    if ($dateFrom !== '') { $filterParams['date_from'] = $dateFrom; }
    if ($dateTo !== '') { $filterParams['date_to'] = $dateTo; }
    $filterQuery = $filterParams === [] ? '' : '&' . http_build_query($filterParams);
    echo dlRender('modules/daily-ledger/admin/variances.disyl', [
        'page_title'    => 'Variance Dashboard',
        'user_name'     => $userName,
        'user_role'     => $role,
        'current_page'  => 'variances',
        'base_url'      => dlGetBaseUrl(),
        'dl_token'      => (string)kernelCookie(dlCookieName(), ''),
        'date'          => $dateTo !== '' ? $dateTo : ($dateFrom !== '' ? $dateFrom : dl_businessDate()),
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
        // The Sales Summary hand-off carries the range the operator is looking at.
        'sales_link_from' => $dateFrom !== '' ? $dateFrom : dl_businessDate(),
        'sales_link_to'   => $dateTo !== '' ? $dateTo : dl_businessDate(),
        'filter_query'  => $filterQuery,
        'branch_id'     => $branchId,
        'status_filter' => $statusFilter,
        'kind_filter' => $kindFilter,
        'shift_filter' => $shiftFilter,
        'branches'      => $branches,
        'variances'     => $variances,
        'search'        => $search,
        'view_mode'     => $viewMode,
        'is_supervisor' => $isSupervisor,
        'supervisor_branch_ids' => $supervisorBranchIds,
        'can_manage_variances' => in_array($role, ['admin', 'supervisor'], true),
        'can_decide_delivery' => $role === 'admin',
        'integrity_notifications' => $notificationRows,
        // Aggregate stats
        'stats_total'        => $statsTotal,
        'stats_unreviewed'   => $statsUnreviewed,
        'stats_investigated' => $statsInvestigated,
        'stats_corrected'    => $statsCorrected,
        'stats_by_kind'      => $statsByKind,
        'stats_net_variance' => $statsTotalVariance,
        // Page slice vs full set, so the page can say what it is not showing
        'variances_total_matching' => $filteredTotal,
        'variances_scope_total'    => $statsTotal,
        'variances_shown'          => count($variances),
        'variances_row_limit'      => DL_VARIANCE_PAGE_ROW_LIMIT,
        // Branch breakdown
        'branch_summary' => $branchSummary,
    ]);
}

/**
 * Admin evidence trace — the linked lookup used for arbitration.
 *
 * Given a DR (literal, case-insensitive substring) or a day+branch, returns
 * every linked record across the sources of truth, grouped and labelled with
 * what each source is authoritative for. Absent records stay absent in the
 * returned shape (recorded=false / empty lists) so the template renders the
 * words "not recorded" — never 0, and never a blank cell that reads as zero.
 *
 * The variance group is computed from the two literal sides (sent items and
 * receiving items linked through delivery_item_id). It deliberately does NOT
 * fall back to the sent quantity when the received quantity is NULL: that
 * shortcut reports a missing receiving as a full match and destroys the
 * evidence this page exists to expose. A sent item with no linked receiving is
 * an OPEN item.
 *
 * Read-only. No new tables, no MySQL 8 constructs.
 *
 * @param mixed $db
 * @param array{dr?:string,date_from?:string,date_to?:string,branch_id?:int,product_id?:int,user?:string,user_id?:int} $filters
 * @param int[] $accessibleBranchIds
 * @return array{mode:string,documents:array<int,array<string,mixed>>,count:int,truncated:bool,user_filter:array<string,mixed>}
 */
function dl_buildAdminTraceData($db, array $filters = [], array $accessibleBranchIds = [], string $viewerRole = 'admin'): array
{
    $dr = trim((string)($filters['dr'] ?? ''));
    $hasDr = $dr !== '';
    $drLower = function_exists('mb_strtolower') ? mb_strtolower($dr, 'UTF-8') : strtolower($dr);
    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    if (!$hasDr) {
        // Day view: default to a single business day (today) when no range given.
        if ($dateFrom === '' && $dateTo === '') {
            $dateFrom = $dateTo = dl_businessDate();
        } elseif ($dateFrom === '') {
            $dateFrom = $dateTo;
        } elseif ($dateTo === '') {
            $dateTo = $dateFrom;
        }
    }

    $branchId = (int)($filters['branch_id'] ?? 0);
    $productId = (int)($filters['product_id'] ?? 0);
    $userQuery = trim((string)($filters['user'] ?? ''));
    $legacyUserId = (int)($filters['user_id'] ?? 0);
    $matchedUsers = [];
    if ($userQuery !== '') {
        $userNeedle = function_exists('mb_strtolower') ? mb_strtolower($userQuery, 'UTF-8') : strtolower($userQuery);
        $userStmt = $db->prepare(
            'SELECT id, full_name, username, role
               FROM dl_users
              WHERE LOCATE(:trace_user_name, LOWER(COALESCE(full_name, ""))) > 0
                 OR LOCATE(:trace_user_login, LOWER(COALESCE(username, ""))) > 0
              ORDER BY full_name, username, id'
        );
        $userStmt->execute([
            ':trace_user_name' => $userNeedle,
            ':trace_user_login' => $userNeedle,
        ]);
        $matchedUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($legacyUserId > 0) {
        // Backward compatibility for bookmarks/integrations that still send an id.
        $userStmt = $db->prepare('SELECT id, full_name, username, role FROM dl_users WHERE id = :trace_user_id');
        $userStmt->execute([':trace_user_id' => $legacyUserId]);
        $legacyUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($legacyUser) {
            $matchedUsers = [$legacyUser];
        }
    }
    $matchedUserIds = array_values(array_unique(array_map('intval', array_column($matchedUsers, 'id'))));
    $userFilterActive = $userQuery !== '' || $legacyUserId > 0;

    $where = ['d.status = "posted"'];
    $bind = [];
    if ($hasDr) {
        // The paper formats vary (17395, SEPT 28 2026, Sep24,2026, Sep232026)
        // so match the literal text the user typed, case-insensitively, and do
        // not parse or normalise it.
        $where[] = 'LOWER(d.dr_number) LIKE :trace_dr';
        $bind[':trace_dr'] = '%' . $drLower . '%';
    }
    if ($dateFrom !== '') {
        $where[] = 'd.delivery_date >= :trace_date_from';
        $bind[':trace_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'd.delivery_date <= :trace_date_to';
        $bind[':trace_date_to'] = $dateTo;
    }
    if ($productId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM dl_delivery_items ditem WHERE ditem.delivery_id = d.id AND ditem.product_id = :trace_product)';
        $bind[':trace_product'] = $productId;
    }
    if ($userFilterActive) {
        if ($matchedUserIds === []) {
            // A named person that does not resolve must never broaden to every
            // document: that would present a false answer about that person.
            $where[] = '1 = 0';
        } else {
            // Keep the historical scope: producer, dispatch creator, or poster.
            // Receiving actors are intentionally not included.
            $matchedUserIdsSql = implode(',', $matchedUserIds);
            $where[] = '(d.produced_by IN (' . $matchedUserIdsSql . ')'
                . ' OR d.created_by IN (' . $matchedUserIdsSql . ')'
                . ' OR d.posted_by IN (' . $matchedUserIdsSql . '))';
        }
    }
    if ($branchId > 0) {
        $where[] = 'd.destination_id = :trace_branch';
        $bind[':trace_branch'] = $branchId;
    } elseif (count($accessibleBranchIds) > 0) {
        // A production in-charge is assigned to the COMMISSARY they produce at,
        // which is the origin of a dispatch, not its destination. Scoping on the
        // destination alone hid every document from them and rendered
        // "No documents matched" for a dispatch they sent themselves - a false
        // negative on the exact question this page exists to answer. So a
        // document is visible when the branch is on either side of it.
        $placeholders = [];
        $originPlaceholders = [];
        foreach (array_values($accessibleBranchIds) as $index => $accessibleBranchId) {
            $key = ':trace_acc_' . $index;
            $originKey = ':trace_acc_org_' . $index;
            $placeholders[] = $key;
            $originPlaceholders[] = $originKey;
            $bind[$key] = (int)$accessibleBranchId;
            $bind[$originKey] = (int)$accessibleBranchId;
        }

        $arms = [
            'd.destination_id IN (' . implode(',', $placeholders) . ')',
            'COALESCE(d.resolved_origin_id, d.origin_id) IN (' . implode(',', $originPlaceholders) . ')',
        ];

        // Historical paper captures can have no origin_id. Attribute those via
        // the relationship the destination already carries: it must be supplied
        // by one of this user's accessible commissaries. This is deliberately
        // not an origin_type-only grant and requires no historical backfill.
        $accessibleIdsSql = implode(',', array_map('intval', $accessibleBranchIds));
        $commissaryIds = array_map('intval', $db->query(
            "SELECT id FROM dl_branches WHERE id IN ($accessibleIdsSql) AND is_commissary = 1"
        )->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($commissaryIds !== []) {
            $arms[] = "(d.origin_id IS NULL AND d.resolved_origin_id IS NULL AND d.origin_type = 'commissary'"
                . ' AND dst.assigned_commissary_id IN (' . implode(',', $commissaryIds) . '))';
        }

        $where[] = '(' . implode(' OR ', $arms) . ')';
    } else {
        $where[] = '1 = 0';
    }

    $limit = $hasDr ? 50 : 100;
    $sql = 'SELECT d.id, d.origin_type, d.origin_id, d.resolved_origin_id, d.destination_type, d.destination_id,
                   d.dr_number, d.delivery_date, d.status, d.created_by, d.posted_by, d.posted_at,
                   d.remarks, d.provenance_status, d.provenance_reviewed_at, d.provenance_review_note,
                   d.produced_by, d.produced_at, d.production_shift, d.created_at,
                   EXISTS (
                       SELECT 1 FROM dl_branch_receivings br_status
                        WHERE br_status.delivery_id = d.id AND br_status.status = "posted"
                   ) AS has_receipt,
                   (SELECT br_actor.received_by FROM dl_branch_receivings br_actor
                     WHERE br_actor.delivery_id = d.id AND br_actor.status = "posted"
                     ORDER BY br_actor.id DESC LIMIT 1) AS receipt_received_by,
                   (SELECT br_time.received_at FROM dl_branch_receivings br_time
                     WHERE br_time.delivery_id = d.id AND br_time.status = "posted"
                     ORDER BY br_time.id DESC LIMIT 1) AS receipt_received_at,
                   org.name AS origin_branch_name, org.code AS origin_branch_code,
                   dst.name AS destination_branch_name, dst.code AS destination_branch_code
              FROM dl_deliveries d
              LEFT JOIN dl_branches org ON org.id = COALESCE(d.resolved_origin_id, d.origin_id)
              LEFT JOIN dl_branches dst ON dst.id = d.destination_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY d.delivery_date DESC, d.id DESC
             LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $truncated = count($rows) >= $limit;

    // Lazy, batched user and product label resolution keeps the page to a
    // small fixed number of catalog queries regardless of how many documents
    // a broad DR substring matches.
    $userNames = null;
    $resolveUserName = static function (int $id) use ($db, &$userNames): string {
        if ($id <= 0) {
            return '';
        }
        if ($userNames === null) {
            $userNames = [];
            foreach ($db->query('SELECT id, full_name, username FROM dl_users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
                $label = trim((string)($u['full_name'] ?? ''));
                if ($label === '') {
                    $label = trim((string)($u['username'] ?? ''));
                }
                if ($label === '') {
                    $label = 'User #' . (int)$u['id'];
                }
                $userNames[(int)$u['id']] = $label;
            }
        }
        return $userNames[$id] ?? ('User #' . $id);
    };

    $link = static function (string $path, array $params): string {
        $params = array_filter($params, static function ($value): bool {
            return $value !== null && $value !== '';
        });
        return '/daily-ledger/' . ltrim($path, '/') . ($params === [] ? '' : '?' . http_build_query($params));
    };

    $originLabel = static function (array $row): string {
        if ($row['origin_id'] === null && $row['resolved_origin_id'] === null) {
            return 'origin unresolved';
        }
        $name = trim((string)($row['origin_branch_name'] ?? ''));
        if ($name !== '') {
            $code = trim((string)($row['origin_branch_code'] ?? ''));
            return $code !== '' ? $name . ' (' . $code . ')' : $name;
        }
        $type = ucwords(str_replace('_', ' ', (string)($row['origin_type'] ?? '')));
        return $type !== '' ? $type : 'Unknown origin';
    };
    $destinationLabel = static function (array $row): string {
        $name = trim((string)($row['destination_branch_name'] ?? ''));
        if ($name !== '') {
            $code = trim((string)($row['destination_branch_code'] ?? ''));
            return $code !== '' ? $name . ' (' . $code . ')' : $name;
        }
        $type = ucwords(str_replace('_', ' ', (string)($row['destination_type'] ?? '')));
        return $type !== '' ? $type : 'Unknown destination';
    };

    $documents = [];
    foreach ($rows as $row) {
        $deliveryId = (int)($row['id'] ?? 0);
        $drNumber = trim((string)($row['dr_number'] ?? ''));
        $deliveryDate = (string)($row['delivery_date'] ?? '');
        $destinationId = (int)($row['destination_id'] ?? 0);
        $originId = (int)($row['resolved_origin_id'] ?? $row['origin_id'] ?? 0);
        $originType = (string)($row['origin_type'] ?? '');
        $storedStatus = (string)($row['status'] ?? '');
        $hasReceipt = !empty($row['has_receipt']);
        $status = $hasReceipt ? 'received' : $storedStatus;
        $statusMeta = dlDeliveryStatusMeta($status);
        $receiptActor = $hasReceipt
            ? $resolveUserName((int)($row['receipt_received_by'] ?? 0))
            : '';
        $receiptTime = $hasReceipt ? (string)($row['receipt_received_at'] ?? '') : '';
        $provenanceMeta = dlDeliveryProvenanceStatusMeta((string)($row['provenance_status'] ?? ''));
        $encoderName = $resolveUserName((int)($row['created_by'] ?? 0));

        $destinationAccessible = in_array($destinationId, $accessibleBranchIds, true);
        $traceLink = $link('admin/trace', [
            'dr' => $drNumber,
            'date_from' => $deliveryDate,
            'date_to' => $deliveryDate,
            // Origin-scoped production users can see their dispatch without
            // being assigned to its destination. Do not send them to a trace
            // URL that the handler will correctly reject as an unauthorized
            // explicit branch filter.
            'branch_id' => $destinationAccessible ? $destinationId : '',
        ]);

        $base = [
            'id' => $deliveryId,
            'dr_number' => $drNumber,
            'dr_display' => $drNumber !== '' ? $drNumber : 'NO DR',
            'delivery_date' => $deliveryDate,
            'status' => $status,
            'stored_status' => $storedStatus,
            'status_label' => (string)($statusMeta['label'] ?? ucfirst($status)),
            'status_actor' => $receiptActor,
            'status_time' => $receiptTime,
            'status_detail' => $hasReceipt
                ? trim(($receiptActor !== '' ? 'by ' . $receiptActor : '') . ($receiptTime !== '' ? ' at ' . $receiptTime : ''))
                : '',
            'origin_label' => $originLabel($row),
            'origin_unresolved' => $row['origin_id'] === null && $row['resolved_origin_id'] === null,
            'origin_admin_resolved' => $row['origin_id'] === null && $row['resolved_origin_id'] !== null,
            'origin_editable' => $row['origin_id'] === null,
            'destination_id' => $destinationId,
            'destination_label' => $destinationLabel($row),
            'provenance_status' => (string)($row['provenance_status'] ?? ''),
            'provenance_status_label' => (string)($provenanceMeta['label'] ?? ''),
            'provenance_review_note' => trim((string)($row['provenance_review_note'] ?? '')),
            'created_by_label' => $encoderName,
            'production_shift' => (string)($row['production_shift'] ?? ''),
            'posted_by_label' => $resolveUserName((int)($row['posted_by'] ?? 0)),
            'posted_at' => (string)($row['posted_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'trace_link' => $traceLink,
        ];

        if (!$hasDr) {
            // Day view: header rows only, to drill into. Building the full
            // chain for every document would be a page-sized N+1 on a busy day.
            $documents[] = $base;
            continue;
        }

        // ── Dispatch items ────────────────────────────────────────────
        $itemStmt = $db->prepare(
            'SELECT di.id, di.product_id, di.quantity, di.unit, di.remarks, p.name AS product_name, p.sku
               FROM dl_delivery_items di
               LEFT JOIN dl_products p ON p.id = di.product_id
              WHERE di.delivery_id = :trace_delivery
              ORDER BY di.id'
        );
        $itemStmt->execute([':trace_delivery' => $deliveryId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $productIds = [];
        $dispatchItems = [];
        foreach ($items as $item) {
            $productIds[(int)$item['product_id']] = true;
            $dispatchItems[] = [
                'id' => (int)$item['id'],
                'product' => (string)($item['product_name'] ?? ('Product #' . (int)$item['product_id'])),
                'sku' => (string)($item['sku'] ?? ''),
                'quantity' => (int)($item['quantity'] ?? 0),
                'unit' => (string)($item['unit'] ?? 'pcs'),
            ];
        }
        $productIds = array_keys($productIds);

        // ── Receivings (RECEIVED) ─────────────────────────────────────
        $rcvStmt = $db->prepare(
            'SELECT br.id, br.delivery_id, br.status, br.dr_number, br.received_by, br.received_at,
                    br.received_ledger_date, br.received_shift, br.posted_by, br.posted_at, br.remarks, br.created_at,
                    br.count_basis, br.count_resolved_by, br.count_resolved_at
               FROM dl_branch_receivings br
              WHERE br.delivery_id = :trace_delivery AND br.status = "posted"
              ORDER BY br.id DESC'
        );
        $rcvStmt->execute([':trace_delivery' => $deliveryId]);
        $receivings = $rcvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $receivedByItem = [];
        foreach ($receivings as &$receiving) {
            $rcvItemStmt = $db->prepare(
                'SELECT bri.id, bri.delivery_item_id, bri.product_id, bri.quantity_received, bri.unit, p.name AS product_name
                   FROM dl_branch_receiving_items bri
                   LEFT JOIN dl_products p ON p.id = bri.product_id
                  WHERE bri.receiving_id = :trace_receiving
                  ORDER BY bri.id'
            );
            $rcvItemStmt->execute([':trace_receiving' => (int)$receiving['id']]);
            $receivingItems = $rcvItemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($receivingItems as $receivingItem) {
                $deliveryItemId = (int)($receivingItem['delivery_item_id'] ?? 0);
                if ($deliveryItemId > 0) {
                    $receivedByItem[$deliveryItemId] = ($receivedByItem[$deliveryItemId] ?? 0) + (int)($receivingItem['quantity_received'] ?? 0);
                }
            }
            $receiving['received_by_label'] = $resolveUserName((int)($receiving['received_by'] ?? 0));
            $receiving['posted_by_label'] = $resolveUserName((int)($receiving['posted_by'] ?? 0));
            $receiving['count_basis_label'] = (string)($receiving['count_basis'] ?? '') === 'copied'
                ? 'not independently counted'
                : ((string)($receiving['count_basis'] ?? '') === 'independently_counted'
                    ? 'independently counted'
                    : 'count basis unresolved');
            $receiving['count_resolved_by_label'] = $resolveUserName((int)($receiving['count_resolved_by'] ?? 0));
            $receiving['items'] = array_map(static function (array $receivingItem): array {
                return [
                    'id' => (int)($receivingItem['id'] ?? 0),
                    'delivery_item_id' => (int)($receivingItem['delivery_item_id'] ?? 0),
                    'product' => (string)($receivingItem['product_name'] ?? ('Product #' . (int)$receivingItem['product_id'])),
                    'quantity' => (int)($receivingItem['quantity_received'] ?? 0),
                    'unit' => (string)($receivingItem['unit'] ?? 'pcs'),
                    'linked' => (int)($receivingItem['delivery_item_id'] ?? 0) > 0,
                ];
            }, $receivingItems);
        }
        unset($receiving);

        // ── Variance: literal sent vs literal received ────────────────
        // NULL is deliberately historical/unknown, not silently counted.
        $receiptCountBasis = '';
        foreach ($receivings as $receiving) {
            $basis = (string)($receiving['count_basis'] ?? '');
            if ($basis === 'copied') {
                $receiptCountBasis = 'copied';
                break;
            }
            if ($basis === 'independently_counted') {
                $receiptCountBasis = 'independently_counted';
            }
        }
        $varianceItems = [];
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $sent = (int)($item['quantity'] ?? 0);
            $productName = (string)($item['product_name'] ?? ('Product #' . (int)$item['product_id']));
            if (!array_key_exists($itemId, $receivedByItem)) {
                // No linked receiving item: OPEN, never a zero-difference match.
                $varianceItems[] = [
                    'product' => $productName,
                    'sent' => $sent,
                    'received_display' => 'not recorded',
                    'delta_display' => 'open',
                    'state' => 'open',
                ];
                continue;
            }
            $received = (int)$receivedByItem[$itemId];
            if ($receiptCountBasis !== 'independently_counted') {
                $label = $receiptCountBasis === 'copied'
                    ? 'not independently counted'
                    : 'count basis unresolved';
                $varianceItems[] = [
                    'product' => $productName,
                    'sent' => $sent,
                    'received_display' => $label,
                    'delta_display' => $label,
                    'state' => 'uncounted',
                ];
                continue;
            }
            if ($received !== $sent) {
                $delta = $received - $sent;
                $varianceItems[] = [
                    'product' => $productName,
                    'sent' => $sent,
                    'received_display' => (string)$received,
                    'delta_display' => ($delta > 0 ? '+' : '') . $delta,
                    'state' => $delta < 0 ? 'short' : 'over',
                ];
            }
        }

        // ── Production (who produced it) ──────────────────────────────
        $producedBy = $row['produced_by'] !== null ? (int)$row['produced_by'] : 0;
        $producedAt = trim((string)($row['produced_at'] ?? ''));
        $producerName = $resolveUserName($producedBy);
        $runs = [];
        $movements = [];
        if ($productIds !== []) {
            $runPlaceholders = [];
            $runBind = [
                ':trace_run_date' => $deliveryDate,
                ':trace_run_destination' => $destinationId,
            ];
            foreach (array_values($productIds) as $index => $pid) {
                $key = ':trace_run_product_' . $index;
                $runPlaceholders[] = $key;
                $runBind[$key] = (int)$pid;
            }
            $runStmt = $db->prepare(
                'SELECT pr.id, pr.product_id, pr.baker_name, pr.yield_qty, pr.run_type, pr.dr_number, pr.ledger_date,
                        p.name AS product_name
                   FROM dl_production_runs pr
                   LEFT JOIN dl_products p ON p.id = pr.product_id
                  WHERE pr.ledger_date = :trace_run_date
                    AND pr.destination_branch_id = :trace_run_destination
                    AND pr.product_id IN (' . implode(',', $runPlaceholders) . ')
                  ORDER BY pr.id'
            );
            $runStmt->execute($runBind);
            foreach ($runStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $run) {
                $runs[] = [
                    // Production runs have no delivery FK. Even after document
                    // scoping they are candidates, not independently linked evidence.
                    'product' => '[candidate] ' . (string)($run['product_name'] ?? ('Product #' . (int)$run['product_id'])),
                    'evidence_state' => 'candidate',
                    'baker' => trim((string)($run['baker_name'] ?? '')),
                    'yield' => (int)($run['yield_qty'] ?? 0),
                    'run_type' => (string)($run['run_type'] ?? ''),
                    'dr_number' => trim((string)($run['dr_number'] ?? '')),
                ];
            }
        }
        if ($drNumber !== '' && $productIds !== []) {
            $movementStmt = $db->prepare(
                'SELECT pm.id, pm.movement_type, pm.product_id, pm.quantity, pm.ledger_date, pm.dr_number,
                        p.name AS product_name
                   FROM dl_production_movements pm
                   LEFT JOIN dl_products p ON p.id = pm.product_id
                  WHERE pm.dr_number = :trace_dr
                    AND pm.destination_branch_id = :trace_movement_destination
                    AND pm.ledger_date = :trace_movement_date
                    AND pm.product_id IN (' . implode(',', $runPlaceholders) . ')
                  ORDER BY pm.id'
            );
            $movementBind = $runBind;
            unset($movementBind[':trace_run_date'], $movementBind[':trace_run_destination']);
            $movementBind[':trace_dr'] = $drNumber;
            $movementBind[':trace_movement_destination'] = $destinationId;
            $movementBind[':trace_movement_date'] = $deliveryDate;
            $movementStmt->execute($movementBind);
            foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $movement) {
                $movements[] = [
                    'product' => (string)($movement['product_name'] ?? ('Product #' . (int)$movement['product_id'])),
                    'movement_type' => (string)($movement['movement_type'] ?? ''),
                    'quantity' => (int)($movement['quantity'] ?? 0),
                    'ledger_date' => (string)($movement['ledger_date'] ?? ''),
                ];
            }
        }

        // ── Cashier withdrawals for the same DR ───────────────────────
        $cashierRows = [];
        if ($drNumber !== '' && $productIds !== []) {
            $cashierStmt = $db->prepare(
                'SELECT cw.id, cw.ledger_date, cw.withdrawal_type, cw.reason_code, cw.custom_reason,
                        cw.quantity, cw.unit, cw.dr_number, cw.created_at,
                        p.name AS product_name, b.name AS branch_name,
                        COALESCE(NULLIF(u.full_name, ""), NULLIF(u.username, ""), "Unknown") AS encoded_by_name
                   FROM dl_cashier_withdrawals cw
                   LEFT JOIN dl_products p ON p.id = cw.product_id
                   LEFT JOIN dl_branches b ON b.id = cw.branch_id
                   LEFT JOIN dl_users u ON u.id = cw.encoded_by
                  WHERE cw.dr_number = :trace_dr
                    AND cw.branch_id = :trace_cashier_branch
                    AND cw.ledger_date = :trace_cashier_date
                    AND cw.product_id IN (' . implode(',', $runPlaceholders) . ')
                  ORDER BY cw.id DESC
                  LIMIT 100'
            );
            $cashierBind = $runBind;
            unset($cashierBind[':trace_run_date'], $cashierBind[':trace_run_destination']);
            $cashierBind[':trace_dr'] = $drNumber;
            $cashierBind[':trace_cashier_branch'] = $destinationId;
            $cashierBind[':trace_cashier_date'] = $deliveryDate;
            $cashierStmt->execute($cashierBind);
            foreach ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cashier) {
                $typeMeta = dlWithdrawalTypeMeta((string)($cashier['withdrawal_type'] ?? ''));
                $cashierRows[] = [
                    'id' => (int)($cashier['id'] ?? 0),
                    'ledger_date' => (string)($cashier['ledger_date'] ?? ''),
                    'type_label' => (string)($typeMeta['label'] ?? $cashier['withdrawal_type'] ?? ''),
                    'product' => (string)($cashier['product_name'] ?? ''),
                    'quantity' => (int)($cashier['quantity'] ?? 0),
                    'unit' => (string)($cashier['unit'] ?? 'pcs'),
                    'branch' => (string)($cashier['branch_name'] ?? ''),
                    'encoded_by' => (string)($cashier['encoded_by_name'] ?? ''),
                    'reason' => trim((string)($cashier['custom_reason'] ?? '')) !== ''
                        ? trim((string)$cashier['custom_reason'])
                        : dlHumanizeToken((string)($cashier['reason_code'] ?? '')),
                ];
            }
        }

        // ── Ledger-cell corrections (field_update) for same date+product ──
        $corrections = [];
        if ($destinationId > 0 && $deliveryDate !== '') {
            $hasActorModuleUserId = false;
            try {
                $colStmt = $db->query('SHOW COLUMNS FROM audit_logs LIKE "actor_module_user_id"');
                $hasActorModuleUserId = $colStmt instanceof \PDOStatement && $colStmt->fetchColumn() !== false;
            } catch (\Throwable $e) {
                $hasActorModuleUserId = false;
            }
            $correctionSql = 'SELECT a.id, a.entity_id, a.old_data, a.new_data, a.created_at, a.action, a.actor_user_id'
                . ($hasActorModuleUserId ? ', a.actor_module_user_id' : ', NULL AS actor_module_user_id')
                . ' FROM audit_logs a'
                . ' WHERE a.module = "daily-ledger" AND a.action = "field_update"'
                . ' AND a.branch_id = :trace_corr_branch'
                . ' AND a.entity_id LIKE :trace_corr_prefix'
                . ' ORDER BY a.id DESC LIMIT 100';
            $correctionStmt = $db->prepare($correctionSql);
            $correctionStmt->execute([
                ':trace_corr_branch' => $destinationId,
                ':trace_corr_prefix' => $destinationId . '-%',
            ]);
            foreach ($correctionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $correction) {
                $entityId = (string)($correction['entity_id'] ?? '');
                // entity_id shape: {branch}-{product}-{YYYY-MM-DD}-{shift}. The
                // date itself contains dashes, so parse it as a whole rather
                // than splitting naively on '-'.
                if (!preg_match('/^(\d+)-(\d+)-(\d{4}-\d{2}-\d{2})-(AM|PM)$/', $entityId, $entityParts)) {
                    continue;
                }
                $corrProductId = (int)$entityParts[2];
                $corrDate = (string)$entityParts[3];
                $corrShift = (string)$entityParts[4];
                if ($corrDate !== $deliveryDate || !in_array($corrProductId, $productIds, true)) {
                    continue;
                }
                $old = json_decode((string)($correction['old_data'] ?? ''), true);
                $new = json_decode((string)($correction['new_data'] ?? ''), true);
                $fieldName = '';
                $fromValue = '';
                $toValue = '';
                if (is_array($new)) {
                    foreach ($new as $key => $value) {
                        $fieldName = (string)$key;
                        $toValue = is_scalar($value) ? (string)$value : json_encode($value);
                        break;
                    }
                }
                if ($fieldName === '' && is_array($old)) {
                    foreach ($old as $key => $value) {
                        $fieldName = (string)$key;
                        break;
                    }
                }
                if (is_array($old) && $fieldName !== '' && array_key_exists($fieldName, $old)) {
                    $fromValue = is_scalar($old[$fieldName]) ? (string)$old[$fieldName] : json_encode($old[$fieldName]);
                }
                $actorId = (int)($correction['actor_module_user_id'] ?? 0);
                if ($actorId <= 0) {
                    $actorId = (int)($correction['actor_user_id'] ?? 0);
                }
                $corrections[] = [
                    'when' => (string)($correction['created_at'] ?? ''),
                    'product_id' => $corrProductId,
                    'shift' => $corrShift,
                    'field' => $fieldName !== '' ? dlHumanizeToken($fieldName) : 'field',
                    'from' => $fromValue,
                    'to' => $toValue,
                    'actor' => $resolveUserName($actorId),
                ];
            }
        }

        // ── Ledger: what the sheet ultimately recorded ────────────────
        $ledgerRows = [];
        if ($productIds !== []) {
            $ledgerPlaceholders = [];
            $ledgerBind = [':trace_led_branch' => $destinationId, ':trace_led_date' => $deliveryDate];
            foreach (array_values($productIds) as $index => $pid) {
                $key = ':trace_led_product_' . $index;
                $ledgerPlaceholders[] = $key;
                $ledgerBind[$key] = (int)$pid;
            }
            $ledgerIn = implode(',', $ledgerPlaceholders);
            $dailyStmt = $db->prepare(
                'SELECT dl.id, dl.product_id, dl.ledger_date, dl.shift, dl.beg_bal, dl.addtl, dl.withdraw,
                        dl.bal_end, dl.sales, p.name AS product_name
                   FROM dl_daily_ledger dl
                   LEFT JOIN dl_products p ON p.id = dl.product_id
                  WHERE dl.branch_id = :trace_led_branch
                    AND dl.ledger_date = :trace_led_date
                    AND dl.product_id IN (' . $ledgerIn . ')
                  ORDER BY dl.product_id, dl.shift'
            );
            $dailyStmt->execute($ledgerBind);
            foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ledger) {
                $ledgerRows[] = [
                    'source' => 'Daily Ledger',
                    'product' => (string)($ledger['product_name'] ?? ('Product #' . (int)$ledger['product_id'])),
                    'shift' => (string)($ledger['shift'] ?? ''),
                    'figures' => 'beg ' . (int)($ledger['beg_bal'] ?? 0)
                        . ' · addtl ' . (int)($ledger['addtl'] ?? 0)
                        . ' · withdraw ' . (int)($ledger['withdraw'] ?? 0)
                        . ' · end ' . ($ledger['bal_end'] === null ? 'not recorded' : (string)(int)$ledger['bal_end'])
                        . ' · sales ' . ($ledger['sales'] === null ? 'not recorded' : (string)(int)$ledger['sales']),
                ];
            }

            if ($originType === 'commissary' && $originId > 0) {
                $cplBind = [':trace_cpl_branch' => $originId, ':trace_cpl_date' => $deliveryDate];
                $cplPlaceholders = [];
                foreach (array_values($productIds) as $index => $pid) {
                    $key = ':trace_cpl_product_' . $index;
                    $cplPlaceholders[] = $key;
                    $cplBind[$key] = (int)$pid;
                }
                $cplStmt = $db->prepare(
                    'SELECT cpl.id, cpl.product_id, cpl.ledger_date, cpl.beg_qty, cpl.produced_qty, cpl.dispatched_qty,
                            cpl.wastage_qty, cpl.remaining_qty, cpl.actual_end_qty, cpl.calc_variance, p.name AS product_name
                       FROM dl_commissary_product_ledger cpl
                       LEFT JOIN dl_products p ON p.id = cpl.product_id
                      WHERE cpl.commissary_branch_id = :trace_cpl_branch
                        AND cpl.ledger_date = :trace_cpl_date
                        AND cpl.product_id IN (' . implode(',', $cplPlaceholders) . ')
                      ORDER BY cpl.product_id'
                );
                $cplStmt->execute($cplBind);
                foreach ($cplStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cpl) {
                    $ledgerRows[] = [
                        'source' => 'Commissary Product Ledger',
                        'product' => (string)($cpl['product_name'] ?? ('Product #' . (int)$cpl['product_id'])),
                        'shift' => '',
                        'figures' => 'beg ' . (int)($cpl['beg_qty'] ?? 0)
                            . ' · produced ' . (int)($cpl['produced_qty'] ?? 0)
                            . ' · dispatched ' . (int)($cpl['dispatched_qty'] ?? 0)
                            . ' · wastage ' . (int)($cpl['wastage_qty'] ?? 0)
                            . ' · remaining ' . ($cpl['remaining_qty'] === null ? 'not recorded' : (string)(int)$cpl['remaining_qty']),
                    ];
                }
            }
        }

        // Keep every rendered link openable by the trace viewer. Owning pages
        // retain their existing gates; denied roles fall back to this read-only
        // trace document rather than receiving a redirect or forbidden response.
        $roleCanOpen = static function (string $target, string $role): bool {
            $gates = [
                'deliveries' => ['admin', 'supervisor', 'production_in_charge'],
                'production' => ['admin', 'supervisor', 'production_in_charge'],
                'variances' => ['admin', 'supervisor', 'auditor'],
                'withdrawals' => ['admin', 'supervisor'],
                'ledger' => ['admin', 'supervisor', 'cashier'],
            ];
            return in_array($role, $gates[$target] ?? [], true);
        };

        $deliveriesLink = $link('admin/deliveries', [
            'branch_id' => $destinationId,
            'date_from' => $deliveryDate,
            'date_to' => $deliveryDate,
        ]);
        $withdrawalsLink = $link('admin/withdrawals', [
            'q' => $drNumber,
            'branch_id' => $destinationId,
            'date_from' => $deliveryDate,
            'date_to' => $deliveryDate,
        ]);
        $varianceLink = $link('admin/variances', [
            'branch_id' => $destinationId,
            'date_from' => $deliveryDate,
            'date_to' => $deliveryDate,
            'q' => $varianceItems !== [] ? (string)$varianceItems[0]['product'] : '',
        ]);
        $sheetLink = $link('ledger', [
            'branch_id' => $destinationId,
            'date' => $deliveryDate,
        ]);
        $productionLink = $link('admin/commissary', [
            'date' => $deliveryDate,
            'branch_id' => $destinationId,
            'commissary_id' => $originType === 'commissary' ? $originId : 0,
        ]);
        if (!$roleCanOpen('deliveries', $viewerRole) || !$destinationAccessible) {
            $deliveriesLink = $traceLink;
        }
        if (!$roleCanOpen('withdrawals', $viewerRole)) {
            $withdrawalsLink = $traceLink;
        }
        if (!$roleCanOpen('variances', $viewerRole)) {
            $varianceLink = $traceLink;
        }
        if (!$roleCanOpen('ledger', $viewerRole)) {
            $sheetLink = $traceLink;
        }
        if (!$roleCanOpen('production', $viewerRole)) {
            $productionLink = $traceLink;
        }

        $sameEncoderReceiving = false;
        $isPaperCapture = str_contains((string)($row['remarks'] ?? ''), '[captured-from-paper-dr]');
        $dispatchActorId = (int)($row['created_by'] ?? 0);
        foreach ($receivings as $receiving) {
            if ($isPaperCapture && $dispatchActorId > 0 && (int)($receiving['received_by'] ?? 0) === $dispatchActorId) {
                $sameEncoderReceiving = true;
                break;
            }
        }
        $singleWitnessNotice = $sameEncoderReceiving === true
            ? ' Single paper capture: dispatch and receiving were encoded by the same user; these are not independent witnesses.'
            : '';

        $documents[] = array_merge($base, [
            'production' => [
                'authoritative' => 'Who produced the goods and when: the producer recorded on the dispatch, same-date production runs, and any DR-linked movements.',
                'recorded' => ($producedBy > 0 || $producedAt !== '' || $runs !== [] || $movements !== []),
                'producer_name' => $producerName,
                'produced_at' => $producedAt,
                'shift' => (string)($row['production_shift'] ?? ''),
                'encoder_name' => $encoderName,
                'runs' => $runs,
                'movements' => $movements,
                'link_url' => $productionLink,
            ],
            'dispatch' => [
                'authoritative' => 'What the source recorded as sent: DR, delivery date, destination, status and per-item sent quantities.' . $singleWitnessNotice,
                'items' => $dispatchItems,
                'posted_by' => $resolveUserName((int)($row['posted_by'] ?? 0)),
                'posted_at' => (string)($row['posted_at'] ?? ''),
                'link_url' => $deliveriesLink,
            ],
            'receiving' => [
                'authoritative' => 'What physically arrived at the destination branch and who recorded it. No row here means the receiving was never recorded.' . $singleWitnessNotice,
                'recorded' => $receivings !== [],
                'rows' => $receivings,
                'link_url' => $deliveriesLink,
            ],
            'variance' => [
                'authoritative' => 'Derived difference between SENT and RECEIVED when independently counted. Missing, copied, or historically ambiguous counts are not matches.',
                'items' => $varianceItems,
                'link_url' => $varianceLink,
            ],
            'cashier' => [
                'authoritative' => 'Cashier-side adjustments for this DR and the ledger-cell corrections for the same date+product.',
                'rows' => $cashierRows,
                'corrections' => $corrections,
                'link_url' => $withdrawalsLink,
            ],
            'ledger' => [
                'authoritative' => 'What the Daily Sheet ultimately recorded for the same date+branch+product.',
                'rows' => $ledgerRows,
                'link_url' => $sheetLink,
            ],
        ]);
    }

    return [
        'mode' => $hasDr ? 'dr' : 'day',
        'documents' => $documents,
        'count' => count($documents),
        'truncated' => $truncated,
        'user_filter' => [
            'active' => $userFilterActive,
            'query' => $userQuery,
            'legacy_user_id' => $userQuery === '' ? $legacyUserId : 0,
            'matches' => array_map(static function (array $matchedUser): array {
                return [
                    'id' => (int)$matchedUser['id'],
                    'full_name' => trim((string)($matchedUser['full_name'] ?? '')),
                    'username' => trim((string)($matchedUser['username'] ?? '')),
                    'role' => trim((string)($matchedUser['role'] ?? '')),
                ];
            }, $matchedUsers),
            'no_match' => $userFilterActive && $matchedUserIds === [],
        ],
    ];
}

function handleAdminTrace(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    // Roles match the Daily Sheet page (handleAdminCommissary): the production
    // in-charge works the sheet, sees a flagged variance there, and must be able
    // to open the trace that explains it. Auditor is kept for read-only review.
    $user = dlCurrentUser(['admin', 'supervisor', 'auditor', 'production_in_charge']);
    $input = $ctx->input();

    $filters = [
        'dr' => trim((string)($input['dr'] ?? '')),
        'date_from' => trim((string)($input['date_from'] ?? '')),
        'date_to' => trim((string)($input['date_to'] ?? '')),
        'branch_id' => (int)($input['branch_id'] ?? 0),
        'product_id' => (int)($input['product_id'] ?? 0),
        'user' => trim((string)($input['user'] ?? '')),
        'user_id' => (int)($input['user_id'] ?? 0),
        'variance_id' => (int)($input['variance_id'] ?? 0),
    ];

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if ($filters['branch_id'] > 0 && !in_array($filters['branch_id'], $accessibleBranchIds, true)) {
        http_response_code(403);
        echo 'Branch not authorized';
        return;
    }

    // Default the shown date range the same way the builder does, so the form
    // reflects exactly what is being viewed.
    $dateFrom = $filters['date_from'];
    $dateTo = $filters['date_to'];
    if ($filters['dr'] === '') {
        if ($dateFrom === '' && $dateTo === '') {
            $dateFrom = $dateTo = dl_businessDate();
        } elseif ($dateFrom === '') {
            $dateFrom = $dateTo;
        } elseif ($dateTo === '') {
            $dateTo = $dateFrom;
        }
    }

    $role = (string)($user['role'] ?? '');
    $trace = dl_buildAdminTraceData($ctx->db(), $filters, $accessibleBranchIds, $role);

    $pickerBranchIds = $accessibleBranchIds === [] ? [0] : $accessibleBranchIds;
    $branchPlaceholders = implode(',', array_fill(0, count($pickerBranchIds), '?'));
    $branchStmt = $ctx->db()->prepare(
        "SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name"
    );
    $branchStmt->execute($pickerBranchIds);
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $products = $ctx->db()->query('SELECT id, sku, name FROM dl_products WHERE is_active = 1 ORDER BY name LIMIT 500')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $commissaries = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    echo dlRender('modules/daily-ledger/admin/trace.disyl', [
        'page_title' => 'Production Delivery Audit',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'trace',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'dr' => $filters['dr'],
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $filters['branch_id'],
        'product_id' => $filters['product_id'],
        'user' => $filters['user'],
        'variance_id' => $filters['variance_id'],
        'branches' => $branches,
        'products' => $products,
        'commissaries' => $commissaries,
        'can_resolve_integrity' => $role === 'admin',
        'trace' => $trace,
    ]);
}

function apiDeliveryIntegrityLabels(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser(['admin', 'supervisor', 'auditor', 'production_in_charge']);
    $rows = $ctx->db()->query(
        'SELECT d.id, d.origin_id, d.resolved_origin_id, b.name AS resolved_origin_name, b.code AS resolved_origin_code
           FROM dl_deliveries d
           LEFT JOIN dl_branches b ON b.id = d.resolved_origin_id
          WHERE d.origin_id IS NULL'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ctx->json(['ok' => true, 'deliveries' => $rows]);
}

function apiResolveDeliveryOrigin(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    $originId = (int)($input['origin_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    if ($deliveryId <= 0 || $originId <= 0 || $note === '') {
        $ctx->json(['ok' => false, 'error' => 'Delivery, real origin, and evidence note are required.'], 422);
        return;
    }
    $originStmt = $ctx->db()->prepare('SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1');
    $originStmt->execute([':id' => $originId]);
    if (!$originStmt->fetchColumn()) {
        $ctx->json(['ok' => false, 'error' => 'Origin must be an active commissary.'], 422);
        return;
    }
    $beforeStmt = $ctx->db()->prepare('SELECT origin_id, resolved_origin_id, provenance_status, provenance_review_note FROM dl_deliveries WHERE id = :id');
    $beforeStmt->execute([':id' => $deliveryId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before || $before['origin_id'] !== null) {
        $ctx->json(['ok' => false, 'error' => 'Only a document with unresolved original origin can be resolved here.'], 422);
        return;
    }
    $actorId = dl_getActorUserId($user);
    $ctx->db()->beginTransaction();
    $ctx->db()->prepare(
        'UPDATE dl_deliveries
            SET resolved_origin_id = :origin,
                provenance_reviewed_by = :actor, provenance_reviewed_at = NOW(),
                provenance_review_note = CONCAT_WS("\n", NULLIF(provenance_review_note, ""), CONCAT("Origin resolution: ", :note))
          WHERE id = :id AND origin_id IS NULL'
    )->execute([':origin' => $originId, ':actor' => $actorId ?: null, ':note' => $note, ':id' => $deliveryId]);
    $ledgerEffect = dl_applyPostedDeliveryCommissaryLedger($ctx->db(), $deliveryId, $actorId);
    $ctx->db()->commit();
    dl_auditLog('resolve_delivery_origin', null, 'dl_deliveries', (string)$deliveryId, [
        'resolved_origin_id' => $before['resolved_origin_id'],
        'provenance_status' => $before['provenance_status'],
        'provenance_review_note' => $before['provenance_review_note'],
    ], [
        'resolved_origin_id' => $originId,
        'provenance_status' => $before['provenance_status'],
        'provenance_review_note' => 'Origin resolution: ' . $note,
        'resolved_by' => $actorId,
    ], $note);
    $ctx->json(['ok' => true, 'delivery_id' => $deliveryId, 'resolved_origin_id' => $originId, 'ledger_effect' => $ledgerEffect]);
}

function apiResolveReceivingCount(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $receivingId = (int)($input['receiving_id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    $provided = $input['items'] ?? null;
    if ($receivingId <= 0 || $note === '' || !is_array($provided)) {
        $ctx->json(['ok' => false, 'error' => 'Receiving, all actual counts, and evidence note are required.'], 422);
        return;
    }
    $counts = [];
    foreach ($provided as $itemId => $raw) {
        if ((!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw))) || (int)$raw < 0) {
            $ctx->json(['ok' => false, 'error' => 'Actual counts must be non-negative whole numbers.'], 422);
            return;
        }
        $counts[(int)$itemId] = (int)$raw;
    }
    $actorId = dl_getActorUserId($user);
    $ctx->db()->beginTransaction();
    try {
        $headStmt = $ctx->db()->prepare('SELECT * FROM dl_branch_receivings WHERE id = :id AND status = "posted" FOR UPDATE');
        $headStmt->execute([':id' => $receivingId]);
        $head = $headStmt->fetch(PDO::FETCH_ASSOC);
        if (!$head) { throw new \RuntimeException('Posted receiving not found.'); }
        $itemStmt = $ctx->db()->prepare('SELECT id, delivery_item_id, product_id, quantity_received FROM dl_branch_receiving_items WHERE receiving_id = :id ORDER BY id');
        $itemStmt->execute([':id' => $receivingId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $oldCounts = [];
        $newCounts = [];
        $update = $ctx->db()->prepare('UPDATE dl_branch_receiving_items SET quantity_received = :quantity WHERE id = :id AND receiving_id = :receiving');
        foreach ($items as $item) {
            $deliveryItemId = (int)($item['delivery_item_id'] ?? 0);
            if ($deliveryItemId <= 0 || !array_key_exists($deliveryItemId, $counts)) {
                throw new \RuntimeException('An actual count is required for every delivery item.');
            }
            $old = (int)$item['quantity_received'];
            $new = $counts[$deliveryItemId];
            $oldCounts[$deliveryItemId] = $old;
            $newCounts[$deliveryItemId] = $new;
            if ($old !== $new) {
                $update->execute([':quantity' => $new, ':id' => (int)$item['id'], ':receiving' => $receivingId]);
                dl_applyLedgerDelta(
                    (int)$head['branch_id'], (int)$item['product_id'], (string)$head['received_ledger_date'],
                    $new - $old, $actorId, 'addtl', (string)($head['received_shift'] ?? 'AM')
                );
            }
        }
        $ctx->db()->prepare(
            'UPDATE dl_branch_receivings
                SET count_basis = "independently_counted", count_resolved_by = :actor, count_resolved_at = NOW()
              WHERE id = :id'
        )->execute([':actor' => $actorId ?: null, ':id' => $receivingId]);
        $ctx->db()->prepare('DELETE FROM dl_delivery_variance_flags WHERE receiving_id = :id')->execute([':id' => $receivingId]);
        dl_recordReceivingVariances($receivingId);
        $ctx->db()->commit();
        dl_auditLog('resolve_receiving_count', (int)$head['branch_id'], 'dl_branch_receivings', (string)$receivingId, [
            'count_basis' => $head['count_basis'], 'items' => $oldCounts,
        ], [
            'count_basis' => 'independently_counted', 'items' => $newCounts,
            'resolved_by' => $actorId,
        ], $note);
        $ctx->json(['ok' => true, 'receiving_id' => $receivingId]);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) { $ctx->db()->rollBack(); }
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function handleAdminActivity(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'auditor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    // Default to the last 7 days (today inclusive) instead of a single day.
    // Defaulting to one day made every action filter look broken when that action
    // simply did not occur today. The active range is echoed in the view so an
    // operator can see exactly what is being filtered and widen it when needed.
    $defaultFrom = (new \DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $defaultFrom;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $defaultFrom;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = $today;
    }
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $actionFilter = trim((string)($input['action_filter'] ?? ''));
    $search   = trim((string)($input['q'] ?? ''));
    $drNumber = trim((string)($input['dr_number'] ?? ''));

    $actionFilterMap = [
        'output' => ['production_output'],
        // The withdrawal audit action actually written is 'withdrawal'
        // (handlers-offline.php and apiCreateWithdrawal), plus 'withdrawal_updated'
        // for edits. 'production_withdrawal' is retained last for any legacy rows.
        'withdrawal' => ['withdrawal', 'withdrawal_updated', 'production_withdrawal'],
        'product' => ['create_product', 'update_product'],
        'user' => ['create_user', 'update_user', 'delete_user', 'restore_user'],
        'commissary' => ['create_commissary_run', 'update_commissary_run', 'delete_commissary_run', 'save_commissary_material'],
        'ledger' => ['field_update', 'row_update', 'close_day', 'reopen_day'],
        'variance' => ['variance_status'],
    ];
    if ($actionFilter !== '' && !isset($actionFilterMap[$actionFilter])) {
        $actionFilter = '';
    }
    $actionFilterOptions = [
        ['value' => '', 'label' => 'All Activities'],
        ['value' => 'output', 'label' => 'Output'],
        ['value' => 'withdrawal', 'label' => 'Withdrawal'],
        ['value' => 'ledger', 'label' => 'Ledger'],
        ['value' => 'commissary', 'label' => 'Commissary'],
        ['value' => 'product', 'label' => 'Product'],
        ['value' => 'user', 'label' => 'User'],
        ['value' => 'variance', 'label' => 'Variance'],
    ];

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $productLookup = [];
    foreach ($ctx->db()->query('SELECT id, name FROM dl_products')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $productRow) {
        $productLookup[(int)$productRow['id']] = (string)$productRow['name'];
    }

    $materialLookup = [];
    foreach ($ctx->db()->query('SELECT id, name FROM dl_raw_materials')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $materialRow) {
        $materialLookup[(int)$materialRow['id']] = (string)$materialRow['name'];
    }

    $branchLookup = [];
    foreach ($branches as $branchRow) {
        $branchLookup[(int)$branchRow['id']] = (string)$branchRow['name'];
    }

    // NOTE: Only select stable columns (id, full_name, username). The optional
    // `email` column exists only on newer migrations (dl_users:035, users:020)
    // and is NOT present on older/shared-host databases — selecting it there
    // throws SQLSTATE[42S22]. Labels degrade gracefully without email.
    $moduleUserLookup = [];
    foreach ($ctx->db()->query('SELECT id, full_name, username FROM dl_users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $moduleUserRow) {
        $moduleUserLookup[(int)$moduleUserRow['id']] = [
            'full_name' => (string)($moduleUserRow['full_name'] ?? ''),
            'username' => (string)($moduleUserRow['username'] ?? ''),
            'email' => '',
        ];
    }

    $kernelUserLookup = [];
    // The shared kernel `users` table exists only when the connected DB is the
    // base/single-tenant DB. Auth-owned module tenants keep their users in
    // dl_users and have no `users` table — guard so the activity view never 500s.
    if (dl_tableExists($ctx->db(), 'users')) {
        foreach ($ctx->db()->query('SELECT id, full_name, username FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $kernelUserRow) {
            $kernelUserLookup[(int)$kernelUserRow['id']] = [
                'full_name' => (string)($kernelUserRow['full_name'] ?? ''),
                'username' => (string)($kernelUserRow['username'] ?? ''),
                'email' => '',
            ];
        }
    }

    $formatUserLabel = static function (array $userRow, int $userId): string {
        $fullName = trim((string)($userRow['full_name'] ?? ''));
        $username = trim((string)($userRow['username'] ?? ''));
        $email = trim((string)($userRow['email'] ?? ''));
        if ($fullName !== '') {
            return $username !== ''
                ? $fullName . ' (@' . $username . ', User #' . $userId . ')'
                : $fullName . ' (User #' . $userId . ')';
        }
        if ($username !== '') {
            return '@' . $username . ' (User #' . $userId . ')';
        }
        if ($email !== '') {
            return $email . ' (User #' . $userId . ')';
        }
        return 'User #' . $userId;
    };

    $resolveUserById = static function (int $userId, string $preferredSource = 'daily-ledger') use ($moduleUserLookup, $kernelUserLookup, $formatUserLabel): string {
        if ($userId <= 0) {
            return '';
        }

        $source = strtolower(trim($preferredSource));
        if ($source === 'kernel') {
            if (isset($kernelUserLookup[$userId])) {
                return $formatUserLabel($kernelUserLookup[$userId], $userId);
            }
            if (isset($moduleUserLookup[$userId])) {
                return $formatUserLabel($moduleUserLookup[$userId], $userId);
            }
            return 'Kernel User #' . $userId;
        }

        if (isset($moduleUserLookup[$userId])) {
            return $formatUserLabel($moduleUserLookup[$userId], $userId);
        }
        if (isset($kernelUserLookup[$userId])) {
            return $formatUserLabel($kernelUserLookup[$userId], $userId);
        }
        return 'User #' . $userId;
    };

    // Resolve just the actor's username for the Actor column (@handle).
    $resolveActorUsername = static function (int $moduleUserId, int $kernelUserId, string $actorSource) use ($moduleUserLookup, $kernelUserLookup): string {
        $pick = static function (array $lookup, int $id): string {
            if ($id <= 0 || !isset($lookup[$id])) {
                return '';
            }
            return trim((string)($lookup[$id]['username'] ?? ''));
        };

        if ($moduleUserId > 0) {
            $u = $pick($moduleUserLookup, $moduleUserId);
            if ($u !== '') {
                return $u;
            }
        }

        if ($kernelUserId > 0) {
            $u = $pick($kernelUserLookup, $kernelUserId);
            if ($u === '') {
                $u = $pick($moduleUserLookup, $kernelUserId);
            }
            return $u;
        }

        return '';
    };

    $resolveUserFromPayload = static function (array $newPayload, array $oldPayload): string {
        $sourcePayload = $newPayload !== [] ? $newPayload : $oldPayload;
        if ($sourcePayload === []) {
            return '';
        }

        $fullName = trim((string)($sourcePayload['full_name'] ?? $sourcePayload['name'] ?? ''));
        $username = trim((string)($sourcePayload['username'] ?? ''));
        $email = trim((string)($sourcePayload['email'] ?? ''));
        $id = 0;
        if (isset($sourcePayload['id']) && is_numeric($sourcePayload['id'])) {
            $id = (int)$sourcePayload['id'];
        } elseif (isset($sourcePayload['user_id']) && is_numeric($sourcePayload['user_id'])) {
            $id = (int)$sourcePayload['user_id'];
        }

        $suffix = $id > 0 ? ' (User #' . $id . ')' : '';
        if ($fullName !== '') {
            if ($username !== '') {
                return $fullName . ' (@' . $username . ')' . $suffix;
            }
            return $fullName . $suffix;
        }
        if ($username !== '') {
            return '@' . $username . $suffix;
        }
        if ($email !== '') {
            return $email . $suffix;
        }

        return $id > 0 ? 'User #' . $id : '';
    };

    $hasActorModuleUserId = dlAuditLogHasColumn('actor_module_user_id');
    $hasActorSource = dlAuditLogHasColumn('actor_source');
    $hasMetadataColumn = dlAuditLogHasColumn('metadata_json');
    $hasUsersTable = dl_tableExists($ctx->db(), 'users');

    $selectFrom = 'SELECT a.action, a.created_at, a.old_data, a.new_data,
                   a.entity_type, a.entity_id, '
        . ($hasActorSource ? 'a.actor_source' : 'NULL') . ' AS actor_source,
                   a.actor_user_id, '
        . ($hasActorModuleUserId ? 'a.actor_module_user_id' : 'NULL') . ' AS actor_module_user_id,
                   b.name AS branch_name,
                   ' . ($hasUsersTable ? 'ku.full_name AS kernel_actor_name' : 'NULL AS kernel_actor_name') . ',
                   ' . ($hasActorModuleUserId ? 'du.full_name' : 'NULL') . ' AS module_actor_name,
                   ' . ($hasMetadataColumn ? 'a.metadata_json' : 'NULL') . ' AS metadata_json
            FROM audit_logs a
            LEFT JOIN dl_branches b ON b.id = a.branch_id
            ' . ($hasUsersTable ? 'LEFT JOIN users ku ON ku.id = a.actor_user_id' : '') . '
            ' . ($hasActorModuleUserId ? 'LEFT JOIN dl_users du ON du.id = a.actor_module_user_id' : 'LEFT JOIN dl_users du ON 1 = 0');
    $where = "a.module = 'daily-ledger'
              AND DATE(a.created_at) BETWEEN :df AND :dt";
    $bind = [':df' => $dateFrom, ':dt' => $dateTo];

    if ($branchId) {
        $where .= ' AND a.branch_id = :bid';
        $bind[':bid'] = $branchId;
    }
    $filterActions = [];
    if ($actionFilter !== '') {
        $filterActions = array_values(array_filter($actionFilterMap[$actionFilter] ?? [], static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        }));
    }
    if ($filterActions !== []) {
        $placeholders = [];
        foreach ($filterActions as $index => $filterAction) {
            $placeholder = ':af' . $index;
            $placeholders[] = $placeholder;
            $bind[$placeholder] = $filterAction;
        }
        $where .= ' AND a.action IN (' . implode(', ', $placeholders) . ')';
    }
    if ($search !== '') {
        $where .= ' AND (a.action LIKE :q OR b.name LIKE :q2)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%";
    }
    if ($drNumber !== '') {
        $where .= ' AND (a.new_data LIKE :drq OR a.old_data LIKE :drq2)';
        $bind[':drq'] = "%{$drNumber}%";
        $bind[':drq2'] = "%{$drNumber}%";
    }

    // Page the activity table. The previous LIMIT 500 rendered ~3.1 KB of markup
    // per row (~1.25 MB document). 200 rows is scannable and still honest: the
    // whole matching set is counted with the same predicate so the view can state
    // exactly how many older events were omitted. The composite (module,
    // created_at) index from migration 073 serves both this predicate and the
    // ORDER BY ... LIMIT ordering (no filesort).
    $activityLimit = 200;
    // The count only needs the branch join when the free-text search matches on the
    // branch name; otherwise a PK join over every matching row is pure overhead.
    $countBranchJoin = ($search !== '') ? ' LEFT JOIN dl_branches b ON b.id = a.branch_id' : '';
    $countStmt = $ctx->db()->prepare('SELECT COUNT(*) FROM audit_logs a' . $countBranchJoin . ' WHERE ' . $where);
    $countStmt->execute($bind);
    $totalMatching = (int)$countStmt->fetchColumn();

    $sql = $selectFrom . ' WHERE ' . $where . ' ORDER BY a.created_at DESC LIMIT ' . $activityLimit;

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $activityRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $omitted = max(0, $totalMatching - count($activityRows));

    $fieldLabels = [
        'full_name' => 'Full Name',
        'username' => 'Username',
        'product_id' => 'Product',
        'material_id' => 'Material',
        'raw_material_id' => 'Material',
        'destination_branch_id' => 'Destination Branch',
        'branch_id' => 'Branch',
        'dr_number' => 'DR Number',
        'ledger_date' => 'Ledger Date',
        'flow_mode' => 'Flow',
        'review_action' => 'Check Action',
        'reviewed_by_role' => 'Checked By Role',
        'provenance_status' => 'Paper DR Check Status',
        'provenance_review_note' => 'Check Note',
        'yield_qty' => 'Yield',
        'kilo_qty' => 'Kilo',
        'egg_qty' => 'Egg',
        'primary_input_qty' => 'Primary Input',
        'primary_input_type' => 'Primary Input Type',
        'resulting_addtl' => 'Resulting Stock',
        'is_active' => 'Active',
        'sort_order' => 'Sort Order',
        'output_pieces_per_batch' => 'Pieces Per Batch',
        'output_unit_label' => 'Output Unit',
        'batch_input_qty' => 'Batch Kilo Qty',
        'batch_egg_qty' => 'Batch Egg Qty',
        // Withdrawals resolve this id to the person's name, so label it the way
        // the Stock Adjustment form asks for it.
        'liable_user_id' => 'Charged To',
    ];
    $skipKeys = ['movement_uuid', 'reference_movement_id', 'client_op_id', 'source_payload', 'role_permissions'];
    $priorityKeys = ['name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id', 'destination_branch_id', 'branch_id', 'dr_number', 'ledger_date', 'quantity', 'yield_qty', 'kilo_qty', 'egg_qty', 'flow_mode', 'status', 'role', 'reason', 'resulting_addtl'];

    $formatFieldLabel = static function (string $key) use ($fieldLabels): string {
        return $fieldLabels[$key] ?? ucwords(str_replace('_', ' ', $key));
    };

    $formatRolePermissions = static function ($value): string {
        if (!is_array($value) || $value === []) {
            return 'None';
        }
        $parts = [];
        foreach ($value as $roleName => $caps) {
            $label = ucwords(str_replace('_', ' ', (string)$roleName));
            $capList = is_array($caps) && count($caps) > 0
                ? implode(', ', array_map(static fn($c) => str_replace('.', ': ', (string)$c), $caps))
                : 'no permissions';
            $parts[] = $label . ' — ' . $capList;
        }
        return implode('; ', $parts);
    };

    $formatValue = static function (string $key, $value) use ($productLookup, $materialLookup, $branchLookup): string {
        if (is_array($value)) {
            // Format items arrays with product names
            if ($key === 'items' || (array_keys($value) === range(0, count($value) - 1) && count($value) > 0 && isset($value[0]['product_id']))) {
                $parts = [];
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        $parts[] = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        continue;
                    }
                    $pid = (int)($item['product_id'] ?? 0);
                    $pname = $pid > 0 ? ($productLookup[$pid] ?? 'Product #' . $pid) : '?';
                    $qty = $item['quantity'] ?? $item['qty'] ?? '?';
                    $parts[] = $pname . ' ×' . $qty;
                }
                return implode('; ', $parts);
            }
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($value === null) {
            return 'None';
        }

        if (in_array($key, ['product_id'], true)) {
            $id = (int)$value;
            $name = $productLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ('#' . $id);
        }
        if (in_array($key, ['material_id', 'raw_material_id'], true)) {
            $id = (int)$value;
            $name = $materialLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ('#' . $id);
        }
        if (in_array($key, ['destination_branch_id', 'branch_id'], true)) {
            $id = (int)$value;
            $name = $branchLookup[$id] ?? '';
            return $name !== '' ? $name . ' (#' . $id . ')' : ($id > 0 ? ('#' . $id) : 'Commissary');
        }
        if ($key === 'is_active') {
            return (int)$value === 1 ? 'Yes' : 'No';
        }
        if ($key === 'provenance_status') {
            return match (trim((string)$value)) {
                'paper_dr_pending' => 'Needs Check',
                'accepted' => 'Verified',
                'discrepant' => 'Discrepancy',
                default => trim((string)$value) === '' ? 'None' : ucwords(str_replace('_', ' ', trim((string)$value))),
            };
        }
        if ($key === 'review_action') {
            return match (trim((string)$value)) {
                'accepted' => 'Verified',
                'discrepant' => 'Flagged Discrepancy',
                'reopen' => 'Reopened Check',
                default => trim((string)$value) === '' ? 'None' : ucwords(str_replace('_', ' ', trim((string)$value))),
            };
        }
        if (in_array($key, ['role', 'flow_mode', 'status', 'primary_input_type', 'reviewed_by_role'], true)) {
            $text = trim((string)$value);
            return $text === '' ? 'None' : ucwords(str_replace('_', ' ', $text));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            if ((float)(int)$number === $number) {
                return (string)(int)$number;
            }
            return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
        }

        $text = trim((string)$value);
        return $text === '' ? 'None' : $text;
    };

    $decodeJson = static function ($payload): array {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    };

    $formatRelativeTime = static function (string $createdAt): string {
        try {
            $timezone = new \DateTimeZone((string)config('app.timezone', 'UTC'));
            $now = new \DateTimeImmutable('now', $timezone);
            $then = new \DateTimeImmutable($createdAt, $timezone);
            $delta = $now->getTimestamp() - $then->getTimestamp();
            if ($delta < 5) {
                return 'just now';
            }
            if ($delta < 60) {
                return $delta . ' seconds ago';
            }
            $minutes = (int) floor($delta / 60);
            if ($minutes < 60) {
                return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
            }
            $hours = (int) floor($delta / 3600);
            if ($hours < 24) {
                return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
            }
            $days = (int) floor($delta / 86400);
            if ($days < 30) {
                return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    };

    $buildDetailItems = static function (array $payload) use ($priorityKeys, $skipKeys, $formatFieldLabel, $formatValue, $formatRolePermissions): array {
        $items = [];
        $seen = [];

        // Expand role_permissions into per-role detail rows before the generic loop
        if (isset($payload['role_permissions']) && is_array($payload['role_permissions'])) {
            foreach ($payload['role_permissions'] as $roleName => $caps) {
                $label = ucwords(str_replace('_', ' ', (string)$roleName));
                $capList = is_array($caps) && count($caps) > 0
                    ? implode(', ', array_map(static fn($c) => str_replace('.', ': ', (string)$c), $caps))
                    : 'no permissions';
                $items[] = ['label' => $label, 'value' => $capList];
            }
            $seen['role_permissions'] = true;
        }

        foreach ($priorityKeys as $key) {
            if (!array_key_exists($key, $payload) || in_array($key, $skipKeys, true)) {
                continue;
            }
            $formatted = $formatValue($key, $payload[$key]);
            if ($formatted === 'None') {
                continue;
            }
            $items[] = ['label' => $formatFieldLabel($key), 'value' => $formatted];
            $seen[$key] = true;
        }

        foreach ($payload as $key => $value) {
            if (isset($seen[$key]) || in_array($key, $skipKeys, true)) {
                continue;
            }
            $formatted = $formatValue((string)$key, $value);
            if ($formatted === 'None') {
                continue;
            }
            $items[] = ['label' => $formatFieldLabel((string)$key), 'value' => $formatted];
        }

        return array_slice($items, 0, 8);
    };

    $buildChangeItems = static function (array $oldPayload, array $newPayload) use ($priorityKeys, $skipKeys, $formatFieldLabel, $formatValue, $formatRolePermissions): array {
        if ($oldPayload === []) {
            return [];
        }

        $keys = array_values(array_unique(array_merge(array_keys($oldPayload), array_keys($newPayload))));
        usort($keys, static function (string $left, string $right) use ($priorityKeys): int {
            $leftPos = array_search($left, $priorityKeys, true);
            $rightPos = array_search($right, $priorityKeys, true);
            $leftRank = $leftPos === false ? 999 : $leftPos;
            $rightRank = $rightPos === false ? 999 : $rightPos;
            if ($leftRank === $rightRank) {
                return strcmp($left, $right);
            }
            return $leftRank <=> $rightRank;
        });

        $items = [];
        foreach ($keys as $key) {
            if (in_array($key, $skipKeys, true)) {
                // role_permissions skipped from generic loop — handle separately so from/to is readable
                if ($key === 'role_permissions') {
                    $oldEncoded = json_encode($oldPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $newEncoded = json_encode($newPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($oldEncoded !== $newEncoded) {
                        $items[] = [
                            'label' => 'Role Permissions',
                            'from' => array_key_exists($key, $oldPayload) ? $formatRolePermissions($oldPayload[$key]) : 'None',
                            'to'   => array_key_exists($key, $newPayload) ? $formatRolePermissions($newPayload[$key]) : 'None',
                        ];
                    }
                }
                continue;
            }
            $oldEncoded = json_encode($oldPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $newEncoded = json_encode($newPayload[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($oldEncoded === $newEncoded) {
                continue;
            }
            $items[] = [
                'label' => $formatFieldLabel($key),
                'from' => array_key_exists($key, $oldPayload) ? $formatValue($key, $oldPayload[$key]) : 'None',
                'to' => array_key_exists($key, $newPayload) ? $formatValue($key, $newPayload[$key]) : 'None',
            ];
        }

        return array_slice($items, 0, 8);
    };

    $entityLabels = [
        'product' => 'product',
        'user' => 'user',
        'branch' => 'branch',
        'dl_deliveries' => 'delivery',
        'dl_branch_receivings' => 'receiving',
        'dl_production_movements' => 'production movement',
        'dl_production_runs' => 'production run',
        'dl_commissary_ledger' => 'commissary material',
        'dl_commissary_product_ledger' => 'commissary product inventory',
        'dl_ledger_day_status' => 'day status',
        'module_settings' => 'settings',
    ];

    $actionMeta = static function (string $action, ?string $entityType) use ($entityLabels): array {
        $entityLabel = $entityLabels[$entityType ?? ''] ?? str_replace('_', ' ', (string)$entityType);
        $summary = ucwords(str_replace('_', ' ', $action));
        $badgeLabel = 'Activity';
        $badgeClasses = 'bg-slate-100 text-slate-800 ring-slate-300';

        switch ($action) {
            case 'production_output':
                $summary = 'Recorded production output';
                $badgeLabel = 'Output';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'production_withdrawal':
                $summary = 'Recorded production withdrawal';
                $badgeLabel = 'Withdrawal';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'withdrawal':
                $summary = 'Recorded withdrawal';
                $badgeLabel = 'Withdrawal';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'withdrawal_updated':
                $summary = 'Updated withdrawal';
                $badgeLabel = 'Withdrawal';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            case 'login':
                $summary = 'Signed in';
                $badgeLabel = 'Login';
                $badgeClasses = 'bg-purple-50 text-purple-800 ring-purple-200';
                break;
            case 'field_update':
                $summary = 'Updated ledger field';
                $badgeLabel = 'Field';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'row_update':
                $summary = 'Updated ledger row';
                $badgeLabel = 'Row';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'close_day':
                $summary = 'Closed the day';
                $badgeLabel = 'Close';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'reopen_day':
                $summary = 'Reopened the day';
                $badgeLabel = 'Reopen';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            case 'create_commissary_run':
                $summary = 'Created commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'update_commissary_run':
                $summary = 'Updated commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'delete_commissary_run':
                $summary = 'Deleted commissary run';
                $badgeLabel = 'Commissary';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'save_commissary_material':
                $summary = 'Saved commissary material count';
                $badgeLabel = 'Material';
                $badgeClasses = 'bg-sky-50 text-sky-800 ring-sky-200';
                break;
            case 'commissary_production':
                $summary = 'Recorded commissary production';
                $badgeLabel = 'Production';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            case 'commissary_dispatch':
                $summary = 'Dispatched from commissary to branch';
                $badgeLabel = 'Dispatch';
                $badgeClasses = 'bg-violet-50 text-violet-800 ring-violet-200';
                break;
            case 'create_delivery':
            case 'delivery_created':
                $summary = 'Created delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'update_delivery':
                $summary = 'Updated delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'delivery_posted':
                $summary = 'Posted delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'delivery_voided':
                $summary = 'Voided delivery';
                $badgeLabel = 'Delivery';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'create_receiving':
            case 'receiving_created':
                $summary = 'Created receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'receiving_posted':
                $summary = 'Posted receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                break;
            case 'receiving_voided':
                $summary = 'Voided receiving';
                $badgeLabel = 'Receiving';
                $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                break;
            case 'review_delivery_provenance':
                $summary = 'Updated paper DR check';
                $badgeLabel = 'Paper DR';
                $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                break;
            case 'variance_status':
                $summary = 'Updated variance status';
                $badgeLabel = 'Variance';
                $badgeClasses = 'bg-amber-50 text-amber-800 ring-amber-200';
                break;
            default:
                if (str_starts_with($action, 'create_')) {
                    $summary = 'Created ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Create';
                    $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                } elseif (str_starts_with($action, 'update_')) {
                    $summary = 'Updated ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Update';
                    $badgeClasses = 'bg-indigo-50 text-indigo-800 ring-indigo-200';
                } elseif (str_starts_with($action, 'delete_')) {
                    $summary = 'Deleted ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Delete';
                    $badgeClasses = 'bg-rose-50 text-rose-800 ring-rose-200';
                } elseif (str_starts_with($action, 'restore_')) {
                    $summary = 'Restored ' . ($entityLabel !== '' ? $entityLabel : 'record');
                    $badgeLabel = 'Restore';
                    $badgeClasses = 'bg-emerald-50 text-emerald-800 ring-emerald-200';
                }
                break;
        }

        return ['summary' => $summary, 'badge_label' => $badgeLabel, 'badge_classes' => $badgeClasses];
    };

    $pickTarget = static function (array $newPayload, array $oldPayload) use ($formatValue): string {
        $source = $newPayload !== [] ? $newPayload : $oldPayload;
        foreach (['name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $formatted = $formatValue($key, $source[$key]);
            if ($formatted !== 'None') {
                return $formatted;
            }
        }
        return '';
    };

    // Build a human-readable label for the edited record (Source / Record column):
    // users resolve to username/full name, other entities to their identifying
    // data (DR number, product/material name, branch name, etc.).
    $resolveRecordLabel = static function (string $entityType, int $entityId, array $newPayload, array $oldPayload) use ($formatValue, $resolveUserById, $resolveUserFromPayload): string {
        $lowerEntity = strtolower(trim($entityType));
        $isUserEntity = $lowerEntity !== '' && (str_contains($lowerEntity, 'user') || in_array($lowerEntity, ['users', 'user', 'dl_users'], true));
        if ($isUserEntity) {
            $userLabel = $resolveUserFromPayload($newPayload, $oldPayload);
            if ($userLabel !== '') {
                return $userLabel;
            }
            if ($entityId > 0) {
                return $resolveUserById($entityId, 'daily-ledger');
            }
        }

        $sourcePayload = $newPayload !== [] ? $newPayload : $oldPayload;
        foreach (['dr_number', 'name', 'full_name', 'username', 'product_id', 'material_id', 'raw_material_id', 'destination_branch_id', 'branch_id'] as $key) {
            if (!array_key_exists($key, $sourcePayload)) {
                continue;
            }
            $formatted = $formatValue($key, $sourcePayload[$key]);
            if ($formatted !== '' && $formatted !== 'None') {
                return $formatted;
            }
        }

        if ($entityId > 0) {
            $label = $lowerEntity !== '' ? ucwords(str_replace(['dl_', '_'], ['', ' '], $lowerEntity)) : 'Record';
            return $label . ' #' . $entityId;
        }

        return '';
    };

    $buildActivityEntry = static function (array $row, array $oldPayload, array $newPayload, array $overrides = []) use ($actionMeta, $pickTarget, $buildDetailItems, $buildChangeItems, $formatRelativeTime, $resolveActorUsername, $resolveRecordLabel, $resolveUserById): array {
        $meta = $actionMeta((string)$row['action'], $row['entity_type'] ?? null);
        $target = $pickTarget($newPayload, $oldPayload);
        $recordLabel = $resolveRecordLabel((string)($row['entity_type'] ?? ''), (int)($row['entity_id'] ?? 0), $newPayload, $oldPayload);
        $summary = $meta['summary'];
        if ($target !== '') {
            $summary .= ' - ' . $target;
        } elseif ($recordLabel !== '') {
            // Fall back to the resolved record (user name, DR number, product,
            // etc.) so the action is never a generic "Updated user #N".
            $summary .= ' - ' . $recordLabel;
        }

        $actorSource = strtolower(trim((string)($row['actor_source'] ?? '')));
        $actorModuleUserId = (int)($row['actor_module_user_id'] ?? 0);
        $actorKernelUserId = (int)($row['actor_user_id'] ?? 0);
        $actorIdentity = '';
        if ($actorModuleUserId > 0) {
            $actorIdentity = $resolveUserById($actorModuleUserId, 'daily-ledger');
        } elseif ($actorKernelUserId > 0) {
            $actorIdentity = $resolveUserById($actorKernelUserId, $actorSource !== '' ? $actorSource : 'kernel');
        }

        // The name recorded with the event (metadata_json) wins: it is the identity
        // actually used at write time. Fall back to the profile join only for
        // pre-existing rows that carry no per-event name.
        $eventMetadata = [];
        if (isset($row['metadata_json']) && is_string($row['metadata_json']) && trim($row['metadata_json']) !== '') {
            $decodedMetadata = json_decode($row['metadata_json'], true);
            if (is_array($decodedMetadata)) {
                $eventMetadata = $decodedMetadata;
            }
        }
        $actorName = trim((string)($eventMetadata['actor_name'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string)($row['module_actor_name'] ?? ''));
        }
        if ($actorName === '') {
            $actorName = trim((string)($row['kernel_actor_name'] ?? ''));
        }
        if ($actorName === '' && $actorIdentity !== '') {
            $actorName = $actorIdentity;
        }
        if ($actorName === '') {
            if ($actorSource === 'daily-ledger') {
                $actorName = 'Daily Ledger';
            } elseif ($actorSource === 'kernel') {
                $actorName = 'Kernel User';
            } else {
                $actorName = 'System';
            }
        }
        $actorUsername = trim((string)($eventMetadata['actor_username'] ?? ''));
        if ($actorUsername === '') {
            $actorUsername = $resolveActorUsername($actorModuleUserId, $actorKernelUserId, $actorSource);
        }

        $detailSource = $newPayload !== [] ? $newPayload : $oldPayload;
        // A withdrawal records the person the stock is charged to. Prefer the name
        // captured when the charge was written (migration 060) so a later rename of
        // that account cannot re-label history; fall back to the live user row for
        // entries recorded before the snapshot existed.
        $liableSnapshot = trim((string)($detailSource['liable_user_name'] ?? ''));
        if ($liableSnapshot !== '') {
            $detailSource['liable_user_id'] = $liableSnapshot;
        } elseif (isset($detailSource['liable_user_id']) && (int)$detailSource['liable_user_id'] > 0) {
            $liableName = $resolveUserById((int)$detailSource['liable_user_id'], 'daily-ledger');
            if ($liableName !== '') {
                $detailSource['liable_user_id'] = $liableName;
            }
        }
        $entry = [
            'action' => (string)$row['action'],
            'actor_name' => $actorName,
            'actor_username' => $actorUsername,
            'actor_identity_label' => $actorIdentity,
            'actor_source_label' => ucwords(str_replace('-', ' ', (string)($row['actor_source'] ?? 'system'))),
            'created_at' => (string)$row['created_at'],
            'relative_time' => $formatRelativeTime((string)$row['created_at']),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'entity_type' => (string)($row['entity_type'] ?? ''),
            'entity_id' => (string)($row['entity_id'] ?? ''),
            'record_label' => $recordLabel,
            'summary' => $summary,
            'badge_label' => $meta['badge_label'],
            'badge_classes' => $meta['badge_classes'],
            'detail_items' => $buildDetailItems($detailSource),
            'change_items' => $buildChangeItems($oldPayload, $newPayload),
            'grouped_items' => [],
            'is_grouped' => false,
        ];

        foreach ($overrides as $key => $value) {
            $entry[$key] = $value;
        }

        return $entry;
    };

    $summarizeDetailItems = static function (array $items): string {
        if ($items === []) {
            return 'None';
        }
        $parts = [];
        foreach (array_slice($items, 0, 4) as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $value = trim((string)($item['value'] ?? ''));
            if ($label === '' || $value === '' || $value === 'None') {
                continue;
            }
            $parts[] = $label . ': ' . $value;
        }
        return $parts !== [] ? implode(' | ', $parts) : 'None';
    };

    $summarizeChangeItems = static function (array $items): string {
        if ($items === []) {
            return 'None';
        }
        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $from = trim((string)($item['from'] ?? ''));
            $to = trim((string)($item['to'] ?? ''));
            if ($label === '') {
                continue;
            }
            $parts[] = $label . ': ' . $from . ' -> ' . $to;
        }
        return $parts !== [] ? implode(' | ', $parts) : 'None';
    };

    $humanizeEntityType = static function (string $entityType): string {
        $text = trim($entityType);
        if ($text === '') {
            return 'Activity';
        }
        return ucwords(str_replace(['dl_', '_'], ['', ' '], $text));
    };

    $activities = [];
    $productionGroup = null;
    $flushProductionGroup = static function (?array $group) use (&$activities, $buildActivityEntry, $buildDetailItems): void {
        if ($group === null) {
            return;
        }

        if (count($group['rows']) <= 1) {
            $single = $group['rows'][0] ?? null;
            if ($single !== null) {
                $activities[] = $buildActivityEntry($single['row'], $single['old_payload'], $single['new_payload']);
            }
            return;
        }

        $first = $group['rows'][0];
        $summaryPayload = $group['summary_payload'];
        $summaryPayload['products'] = count($group['rows']);
        $activities[] = $buildActivityEntry($first['row'], [], $summaryPayload, [
            'summary' => $group['summary_text'],
            'entity_id' => '',
            'is_grouped' => true,
            'detail_items' => $buildDetailItems($summaryPayload),
            'change_items' => [],
            'grouped_items' => $group['grouped_items'],
        ]);
    };

    foreach ($activityRows as $row) {
        $oldPayload = $decodeJson($row['old_data'] ?? null);
        $newPayload = $decodeJson($row['new_data'] ?? null);
        $isGroupedProductionAction = in_array((string)$row['action'], ['production_output', 'production_withdrawal'], true)
            && $newPayload !== []
            && isset($newPayload['product_id']);

        if ($isGroupedProductionAction) {
            $groupKey = implode('|', [
                (string)$row['action'],
                (string)($row['branch_name'] ?? ''),
                (string)$row['created_at'],
                (string)($newPayload['dr_number'] ?? ''),
                (string)($newPayload['ledger_date'] ?? ''),
            ]);

            if ($productionGroup !== null && $productionGroup['key'] !== $groupKey) {
                $flushProductionGroup($productionGroup);
                $productionGroup = null;
            }

            if ($productionGroup === null) {
                $summaryPayload = [
                    'ledger_date' => $newPayload['ledger_date'] ?? null,
                    'destination_branch_id' => $newPayload['destination_branch_id'] ?? null,
                    'dr_number' => $newPayload['dr_number'] ?? null,
                    'flow_mode' => $newPayload['flow_mode'] ?? null,
                    'quantity' => 0,
                    'products' => 0,
                ];
                if (isset($newPayload['reason']) && trim((string)$newPayload['reason']) !== '') {
                    $summaryPayload['reason'] = $newPayload['reason'];
                }
                $productionGroup = [
                    'key' => $groupKey,
                    'rows' => [],
                    'summary_payload' => $summaryPayload,
                    'summary_text' => '',
                    'grouped_items' => [],
                ];
            }

            $productionGroup['rows'][] = [
                'row' => $row,
                'old_payload' => $oldPayload,
                'new_payload' => $newPayload,
            ];
            $productionGroup['summary_payload']['quantity'] += (float)($newPayload['quantity'] ?? 0);
            $productionGroup['summary_payload']['products'] += 1;
            $productionGroup['grouped_items'][] = [
                'product' => $formatValue('product_id', $newPayload['product_id']),
                'quantity' => $formatValue('quantity', $newPayload['quantity'] ?? 0),
            ];
            $productionGroup['summary_text'] = ((string)$row['action'] === 'production_withdrawal'
                ? 'Recorded production withdrawal'
                : 'Recorded production output') . ' - ' . count($productionGroup['rows']) . ' products';
            continue;
        }

        if ($productionGroup !== null) {
            $flushProductionGroup($productionGroup);
            $productionGroup = null;
        }

        $activities[] = $buildActivityEntry($row, $oldPayload, $newPayload);
    }
    $flushProductionGroup($productionGroup);

    foreach ($activities as &$activity) {
        $activity['entity_type_label'] = $humanizeEntityType((string)($activity['entity_type'] ?? ''));
        $activity['detail_summary'] = $summarizeDetailItems(is_array($activity['detail_items'] ?? null) ? $activity['detail_items'] : []);
        $activity['change_summary'] = $summarizeChangeItems(is_array($activity['change_items'] ?? null) ? $activity['change_items'] : []);
        $resolvedRecordLabel = trim((string)($activity['record_label'] ?? ''));
        if ($resolvedRecordLabel === '') {
            $resolvedRecordLabel = (string)$activity['entity_type_label'];
            if (!empty($activity['entity_id'])) {
                $resolvedRecordLabel .= ' #' . (string)$activity['entity_id'];
            }
        }
        $activity['record_label'] = $resolvedRecordLabel;
        $activity['grouped_summary'] = 'None';
        if (!empty($activity['grouped_items']) && is_array($activity['grouped_items'])) {
            $parts = [];
            foreach (array_slice($activity['grouped_items'], 0, 3) as $groupedItem) {
                $product = trim((string)($groupedItem['product'] ?? ''));
                $quantity = trim((string)($groupedItem['quantity'] ?? ''));
                if ($product === '' || $quantity === '') {
                    continue;
                }
                $parts[] = $product . ' x' . $quantity;
            }
            if ($parts !== []) {
                $activity['grouped_summary'] = implode(' | ', $parts);
                if (count($activity['grouped_items']) > 3) {
                    $activity['grouped_summary'] .= ' | +' . (count($activity['grouped_items']) - 3) . ' more';
                }
            }
        }
    }
    unset($activity);

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/activity.disyl', [
        'page_title' => 'Encoder Activity',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'activity',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'activities' => $activities,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'activity_limit' => $activityLimit,
        'total_matching' => $totalMatching,
        'omitted' => $omitted,
        'branch_id' => $branchId,
        'action_filter' => $actionFilter,
        'action_filter_options' => $actionFilterOptions,
        'branches' => $branches,
        'search' => $search,
        'dr_number' => $drNumber,
    ]);
}

// ─── Admin: Products ───────────────────────────────────────────────────

function handleAdminProducts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    dl_promoteCurrentPrices();
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $today = dl_businessDate();
    $effectivePrice = dl_effectivePriceSql('p', ':product_price_at');

    $sql = 'SELECT p.*, ' . $effectivePrice . ' AS current_price,
                   (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.product_id = p.id AND bp.is_active = 1) AS branch_count,
                   (SELECT DATE(ph.effective_at) FROM dl_product_price_history ph
                     WHERE ph.product_id = p.id AND ph.effective_at < DATE_ADD(:product_label_at, INTERVAL 1 DAY)
                     ORDER BY ph.effective_at DESC, ph.id DESC LIMIT 1) AS current_price_effective_from
            FROM dl_products p WHERE 1=1';
    $bind = [':product_price_at' => $today, ':product_label_at' => $today];
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q2)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%";
    }
    $sql .= ' ORDER BY p.sort_order, p.name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/products.disyl', [
        'page_title' => 'Products',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'products',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'products' => $products,
        'branches' => $branches,
        'search' => $search,
        'today' => $today,
    ]);
}

function apiUpdateVarianceStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);

    $input = $ctx->input();
    $varianceId = (int)($input['variance_id'] ?? 0);
    $status = (string)($input['status'] ?? '');

    if ($varianceId <= 0 || !in_array($status, ['unreviewed', 'investigated', 'corrected'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid variance/status', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid variance_id or status'], 422);
        return;
    }

    // Review note. A key that is present but empty clears the note; an ABSENT key
    // leaves the stored note alone, so a status change from a surface with no note
    // box cannot wipe a note written elsewhere. Same present/absent contract the
    // ledger batch save uses for its optional columns.
    $hasNote = is_array($input) && array_key_exists('review_note', $input);
    $note = $hasNote ? trim((string)$input['review_note']) : '';
    if ($hasNote && mb_strlen($note) > DL_VARIANCE_NOTE_MAX) {
        $note = mb_substr($note, 0, DL_VARIANCE_NOTE_MAX);
    }

    $reviewerId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $reviewerId = (int)$user['id'];
        if ($reviewerId <= 0) $reviewerId = 0;
    }
    if ($reviewerId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $reviewerId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $reviewerId = (int)$sub;
        }
    }

    // reviewed_by stores the actor id.
    // Prefer daily-ledger user ids when the request is coming from the daily-ledger auth source.
    // If the actor is kernel admin (opt-in allowed), store kernel users.id.
    $reviewedBy = null;
    if ($reviewerId > 0) {
        if (($user['source'] ?? '') === 'daily-ledger') {
            $st = $ctx->db()->prepare(
                'SELECT id FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
            );
            $st->execute([':id' => $reviewerId]);
            $exists = (int)($st->fetchColumn() ?: 0);
            if ($exists > 0) {
                $reviewedBy = $reviewerId;
            }
        } elseif (($user['source'] ?? '') === 'kernel') {
            $reviewedBy = $reviewerId;
        }
    }

    try {
        // Existence is checked separately because UPDATE rowCount() reports CHANGED
        // rows, not matched ones: re-submitting the same status inside the same
        // second changed nothing, so the old rowCount() check answered "Variance not
        // found" for a row that plainly exists. Saving a note twice hits this.
        $existenceCheck = $ctx->db()->prepare('SELECT id FROM dl_variance_flags WHERE id = :id LIMIT 1');
        $existenceCheck->execute([':id' => $varianceId]);
        if ((int)($existenceCheck->fetchColumn() ?: 0) <= 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Variance not found'], 404);
            return;
        }
        $ctx->db()->beginTransaction();
        $existsStmt = $ctx->db()->prepare('SELECT id, branch_id, kind, resolution_status, review_note, reviewed_by, reviewed_at FROM dl_variance_flags WHERE id = :id LIMIT 1 FOR UPDATE');
        $existsStmt->execute([':id' => $varianceId]);
        $before = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            $ctx->db()->rollBack();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Variance not found'], 404);
            return;
        }
        // A delivery sent-vs-received variance is settled only by the explicit
        // admin acceptance decision (accept production OR keep as evidence).
        // This generic endpoint must not be a second door to that outcome, so a
        // supervisor (or anyone) cannot mark it corrected from here.
        if ((string)($before['kind'] ?? '') === 'delivery' && $status === 'corrected') {
            $ctx->db()->rollBack();
            $ctx->json([
                'ok' => false,
                'error' => 'A delivery sent/received variance is resolved only by an admin decision (accept production or keep as evidence).',
                'status' => 409,
            ], 409);
            return;
        }
        $oldStatus = (string)$before['resolution_status'];
        $allowedTransition = $status === $oldStatus
            || ($oldStatus === 'unreviewed' && $status === 'investigated')
            || ($oldStatus === 'investigated' && $status === 'corrected')
            || ($oldStatus === 'corrected' && $status === 'investigated');
        if (!$allowedTransition) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'A surfaced finding must be investigated before it can be corrected.', 'status' => 409], 409);
            return;
        }
        $effectiveNote = $hasNote ? $note : trim((string)($before['review_note'] ?? ''));
        if ($status === 'corrected' && $effectiveNote === '') {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'A resolution note is required before correction.'], 422);
            return;
        }

        $stmt = $ctx->db()->prepare(
            'UPDATE dl_variance_flags
             SET resolution_status = :st,
                 reviewed_by = :rb,
                 reviewed_at = CURRENT_TIMESTAMP,
                 is_reviewed = CASE WHEN :st2 = \'unreviewed\' THEN 0 ELSE 1 END,
                 review_note = IF(:has_note, :note, review_note)
             WHERE id = :id'
        );
        $stmt->execute([
            ':st' => $status,
            ':st2' => $status,
            ':rb' => $reviewedBy,
            ':has_note' => $hasNote ? 1 : 0,
            ':note' => $hasNote ? $note : null,
            ':id' => $varianceId,
        ]);
        dl_auditLog('variance_status', (int)$before['branch_id'], 'dl_variance_flags', (string)$varianceId, [
            'resolution_status' => $oldStatus,
            'review_note' => $before['review_note'],
            'reviewed_by' => $before['reviewed_by'],
            'reviewed_at' => $before['reviewed_at'],
        ], [
            'resolution_status' => $status,
            'review_note' => $effectiveNote,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], $status === 'investigated' && $oldStatus === 'corrected' ? 'reopened' : null);
        $ctx->db()->commit();

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance updated', 'type' => 'success']]));
        $ctx->json([
            'ok' => true,
            'resolution_status' => $status,
            // null means "unchanged", so the caller can tell a save from a no-op.
            'review_note' => $hasNote ? $note : null,
        ]);
        return;
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) { $ctx->db()->rollBack(); }
        write_log('daily-ledger apiUpdateVarianceStatus failed', 'error', [
            'error' => $e->getMessage(),
            'variance_id' => $varianceId,
            'status' => $status,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Server error', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Server error'], 500);
        return;
    }
}

/**
 * POST /daily-ledger/api/v1/admin/variances/decide-delivery
 *
 * The admin-only acceptance decision for a production correction that changed a
 * dispatch after the cashier counted it. It is a CHOICE, not a status flip:
 *
 *   accept_production -> correct the cashier's counted quantity to the figure
 *                        production recorded as sent. The original counted value
 *                        and who counted it are preserved as evidence.
 *   keep_as_evidence  -> leave the cashier's count exactly as it is and close
 *                        the variance on that basis.
 *
 * Both record actor, timestamp and note. Both are editable afterwards: an admin
 * reopens the flag through the generic endpoint (corrected -> investigated) and
 * decides again. Every prior revision is retained in audit_logs.
 *
 * Authority: admin only. A supervisor cannot reach this outcome through the
 * generic variance endpoint either (that route is closed for kind='delivery').
 */
function apiResolveDeliveryVariance(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin']);

    $input = $ctx->input();
    $varianceId = (int)($input['variance_id'] ?? 0);
    $choice = trim((string)($input['choice'] ?? $input['decision'] ?? ''));
    $note = is_array($input) ? trim((string)($input['review_note'] ?? '')) : '';

    if ($varianceId <= 0 || !in_array($choice, ['accept_production', 'keep_as_evidence'], true)) {
        $ctx->json(['ok' => false, 'error' => 'A choice of accept_production or keep_as_evidence is required.'], 422);
        return;
    }
    if ($note === '') {
        $ctx->json(['ok' => false, 'error' => 'A resolution note is required for the acceptance decision.'], 422);
        return;
    }
    if (mb_strlen($note) > DL_VARIANCE_NOTE_MAX) {
        $note = mb_substr($note, 0, DL_VARIANCE_NOTE_MAX);
    }

    $reviewedBy = null;
    $actorId = dl_getActorUserId($user);
    if ($actorId > 0 && ($user['source'] ?? '') === 'daily-ledger') {
        $st = $ctx->db()->prepare('SELECT id FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $st->execute([':id' => $actorId]);
        if ((int)($st->fetchColumn() ?: 0) > 0) {
            $reviewedBy = $actorId;
        }
    } elseif ($actorId > 0) {
        $reviewedBy = $actorId;
    }

    try {
        $ctx->db()->beginTransaction();
        $stmt = $ctx->db()->prepare('SELECT * FROM dl_variance_flags WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $varianceId]);
        $flag = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$flag) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Variance not found'], 404);
            return;
        }
        if ((string)($flag['kind'] ?? '') !== 'delivery') {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'This is not a delivery sent/received variance.'], 409);
            return;
        }
        if ((string)($flag['resolution_status'] ?? '') === 'unreviewed') {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'The finding must be investigated before it can be resolved.', 'status' => 409], 409);
            return;
        }

        $sentQty = (int)($flag['sent_qty'] ?? 0);
        $originalCounted = (int)($flag['original_counted_qty'] ?? $flag['received_qty'] ?? 0);

        // The choice IS the resolution. Apply the chosen outcome to the
        // cashier's counted line: the production figure when accepted, or the
        // preserved original count when kept as evidence. Re-deciding therefore
        // moves the count both ways, and the original always survives on the
        // flag and in the audit entry.
        $targetCount = $choice === 'accept_production' ? $sentQty : $originalCounted;
        $itemStmt = $ctx->db()->prepare(
            'SELECT id, quantity_received FROM dl_branch_receiving_items
              WHERE receiving_id = :r AND product_id = :p ORDER BY id ASC'
        );
        $itemStmt->execute([':r' => (int)($flag['receiving_id'] ?? 0), ':p' => (int)($flag['product_id'] ?? 0)]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items === []) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'The cashier\'s counted line no longer exists.'], 409);
            return;
        }
        $countedBefore = 0;
        foreach ($items as $item) {
            $countedBefore += (int)$item['quantity_received'];
        }
        // The single positive line becomes the chosen total; any duplicate lines
        // are zeroed so the total equals the decision. The original value is
        // preserved on the flag and in the audit entry before it is changed.
        $first = true;
        foreach ($items as $item) {
            $newValue = $first ? $targetCount : 0;
            $oldValue = (int)$item['quantity_received'];
            if ($oldValue !== $newValue) {
                $ctx->db()->prepare('UPDATE dl_branch_receiving_items SET quantity_received = :q WHERE id = :id')
                    ->execute([':q' => $newValue, ':id' => (int)$item['id']]);
                dl_applyLedgerDelta((int)$flag['branch_id'], (int)$flag['product_id'], (string)$flag['ledger_date'], $newValue - $oldValue, $actorId, 'addtl');
            }
            $first = false;
        }
        $countedAfter = $targetCount;

        $ctx->db()->prepare(
            'UPDATE dl_variance_flags
                SET resolution_status = \'corrected\',
                    resolution_choice = :choice,
                    original_counted_qty = COALESCE(original_counted_qty, :orig),
                    is_reviewed = 1,
                    reviewed_by = :rb,
                    reviewed_at = CURRENT_TIMESTAMP,
                    review_note = :note
              WHERE id = :id'
        )->execute([
            ':choice' => $choice,
            ':orig' => $countedBefore,
            ':rb' => $reviewedBy,
            ':note' => $note,
            ':id' => $varianceId,
        ]);

        dl_auditLog('delivery_variance_resolution', (int)$flag['branch_id'], 'dl_variance_flags', (string)$varianceId, [
            'resolution_status' => (string)$flag['resolution_status'],
            'resolution_choice' => $flag['resolution_choice'],
            'review_note' => $flag['review_note'],
            'reviewed_by' => $flag['reviewed_by'],
            'reviewed_at' => $flag['reviewed_at'],
            'cashier_counted_qty' => $flag['original_counted_qty'],
        ], [
            'resolution_status' => 'corrected',
            'resolution_choice' => $choice,
            'review_note' => $note,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'production_sent_qty' => $sentQty,
            'cashier_counted_qty_before' => $countedBefore,
            'cashier_counted_qty_after' => $countedAfter,
        ], $choice);
        $ctx->db()->commit();

        $ctx->json([
            'ok' => true,
            'resolution_status' => 'corrected',
            'resolution_choice' => $choice,
            'cashier_counted_qty' => $countedAfter,
        ]);
        return;
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) { $ctx->db()->rollBack(); }
        write_log('daily-ledger apiResolveDeliveryVariance failed', 'error', [
            'error' => $e->getMessage(),
            'variance_id' => $varianceId,
            'choice' => $choice,
        ]);
        $ctx->json(['ok' => false, 'error' => 'Server error'], 500);
        return;
    }
}

/**
 * Save the external check for one shift: the paper sheet total, the cash actually
 * remitted, and the reviewer's note.
 *
 * Present-vs-absent contract on every field, the same as the ledger batch save and the
 * variance note: a key that is PRESENT sets the value (empty string = "not recorded"
 * = NULL), an ABSENT key leaves the stored value alone. A caller that only has the cash
 * to hand must not silently wipe a paper total someone else entered.
 *
 * An entered figure must be a non-negative number. A bad value is REJECTED rather than
 * coerced to 0, because 0 is a meaningful result (a shift that genuinely sold nothing)
 * and must never be invented from a typo.
 */
function apiSaveReconciliation(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);
    $input = $ctx->input();

    $authResult = dl_authorizeBranch($user, is_array($input) ? $input : []);
    if (($authResult['branch_id'] ?? -1) < 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Branch not authorized', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = (int)($authResult['branch_id'] ?? 0);
    $date = trim((string)($input['ledger_date'] ?? ''));
    $shift = strtoupper(trim((string)($input['shift'] ?? '')));

    if ($branchId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !in_array($shift, ['AM', 'PM'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid branch/date/shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid branch, date or shift'], 422);
        return;
    }

    $hasPaper = is_array($input) && array_key_exists('paper_sales', $input);
    $hasCash = is_array($input) && array_key_exists('cash_remitted', $input);
    $hasNote = is_array($input) && array_key_exists('review_note', $input);

    // Normalise the two money fields. '' / null means "not recorded".
    $paper = null;
    $cash = null;
    foreach (['paper_sales' => &$paper, 'cash_remitted' => &$cash] as $key => $target) {
        $raw = $input[$key] ?? null;
        if ($raw === null || (is_scalar($raw) && trim((string)$raw) === '')) {
            $target = null;
            continue;
        }
        if (!is_scalar($raw) || !is_numeric($raw)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Amounts must be numbers', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $key . ' must be a number'], 422);
            return;
        }
        $value = round((float)$raw, 2);
        // decimal(12,2) ceiling, and a negative remittance is not a thing.
        if ($value < 0 || $value > 9999999999.99) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Amounts must be between 0 and 9,999,999,999.99', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $key . ' is out of range'], 422);
            return;
        }
        $target = $value;
    }
    unset($target);

    $note = $hasNote ? trim((string)$input['review_note']) : '';
    if ($hasNote && mb_strlen($note) > DL_RECON_NOTE_MAX) {
        $note = mb_substr($note, 0, DL_RECON_NOTE_MAX);
    }

    // recorded_by follows the same storage policy as the variance reviewer: a
    // daily-ledger id only when it is a real, non-deleted dl_users row; a kernel id when
    // the actor is a kernel admin (which the module explicitly allows here).
    $recordedBy = null;
    $actorId = dl_getActorUserId(is_array($user) ? $user : []);
    if ($actorId > 0) {
        $source = (string)($user['source'] ?? '');
        if ($source === 'daily-ledger') {
            $chk = $ctx->db()->prepare('SELECT id FROM dl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':id' => $actorId]);
            if ((int)($chk->fetchColumn() ?: 0) > 0) { $recordedBy = $actorId; }
        } elseif ($source === 'kernel') {
            $recordedBy = $actorId;
        }
    }

    try {
        $stmt = $ctx->db()->prepare(
            'INSERT INTO dl_shift_reconciliation
                (branch_id, ledger_date, shift, paper_sales, cash_remitted, review_note, recorded_by, recorded_at)
             VALUES (:bid, :d, :shift, :paper, :cash, :note, :uid, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                paper_sales   = IF(:has_paper, VALUES(paper_sales), paper_sales),
                cash_remitted = IF(:has_cash,  VALUES(cash_remitted), cash_remitted),
                review_note   = IF(:has_note,  VALUES(review_note), review_note),
                recorded_by   = VALUES(recorded_by),
                recorded_at   = VALUES(recorded_at)'
        );
        $stmt->execute([
            ':bid' => $branchId,
            ':d' => $date,
            ':shift' => $shift,
            ':paper' => $hasPaper ? $paper : null,
            ':cash' => $hasCash ? $cash : null,
            ':note' => $hasNote ? $note : null,
            ':uid' => $recordedBy,
            ':has_paper' => $hasPaper ? 1 : 0,
            ':has_cash' => $hasCash ? 1 : 0,
            ':has_note' => $hasNote ? 1 : 0,
        ]);

        dl_auditLog(
            'reconciliation_saved',
            $branchId,
            'dl_shift_reconciliation',
            "{$branchId}-{$date}-{$shift}",
            null,
            [
                'paper_sales' => $hasPaper ? $paper : 'unchanged',
                'cash_remitted' => $hasCash ? $cash : 'unchanged',
                'note' => $hasNote ? ($note === '' ? '(cleared)' : 'set') : 'unchanged',
            ]
        );

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Check saved', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
        return;
    } catch (\Throwable $e) {
        write_log('daily-ledger apiSaveReconciliation failed', 'error', [
            'error' => $e->getMessage(),
            'branch_id' => $branchId,
            'ledger_date' => $date,
            'shift' => $shift,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Server error', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Server error'], 500);
        return;
    }
}

function apiCreateProduct(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input = $ctx->input();
    $name  = trim((string)($input['name'] ?? ''));
    $category  = strtolower(trim((string)($input['product_category'] ?? 'bread')));
    if (!in_array($category, ['bread', 'cake', 'other'])) $category = 'bread';
    $price = (float)($input['price'] ?? 0);
    $sort  = (int)($input['sort_order'] ?? 0);
    $outputPiecesPerBatch = dl_normalizePiecesPerBatch($input['output_pieces_per_batch'] ?? null);
    $outputUnitLabel = dl_normalizeOutputUnitLabel($input['output_unit_label'] ?? 'pcs');
    $batchInputQty = isset($input['batch_input_qty']) && $input['batch_input_qty'] !== '' && $input['batch_input_qty'] !== null
        ? round((float)$input['batch_input_qty'], 3) : null;
    if ($batchInputQty !== null && $batchInputQty <= 0) $batchInputQty = null;
    $batchEggQty = isset($input['batch_egg_qty']) && $input['batch_egg_qty'] !== '' && $input['batch_egg_qty'] !== null
        ? round((float)$input['batch_egg_qty'], 3) : null;
    if ($batchEggQty !== null && $batchEggQty <= 0) $batchEggQty = null;
    $pcsPerPack = isset($input['pcs_per_pack']) && $input['pcs_per_pack'] !== '' && $input['pcs_per_pack'] !== null
        ? (int)$input['pcs_per_pack'] : null;
    if ($pcsPerPack !== null && $pcsPerPack <= 0) $pcsPerPack = null;

    if ($name === '' || $price <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Name and price are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Name and price are required'], 422);
        return;
    }

    $sku = dl_generateSku();
    $userId = dl_getActorUserId($user);

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_products (sku, name, product_category, current_price, sort_order, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label, pcs_per_pack) VALUES (:sku, :name, :cat, :price, :sort, :oppb, :biq, :beq, :unit, :ppp)'
        )->execute([':sku' => $sku, ':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sort, ':oppb' => $outputPiecesPerBatch, ':biq' => $batchInputQty, ':beq' => $batchEggQty, ':unit' => $outputUnitLabel, ':ppp' => $pcsPerPack]);

        $productId = (int)$ctx->db()->lastInsertId();

        // Record price history
        $ctx->db()->prepare(
            'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
        )->execute([':pid' => $productId, ':price' => $price, ':uid' => $kernelActorUserId]);

        // Assign to all active branches by default
        $branches = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($branches !== []) {
            $values = [];
            $params = [];
            foreach ($branches as $index => $br) {
                // Unique named placeholders per row: PDO native prepared
                // statements cannot reuse a named marker more than once.
                $values[] = "(:bid_{$index}, :pid_{$index})";
                $params[":bid_{$index}"] = (int)$br['id'];
                $params[":pid_{$index}"] = $productId;
            }
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES ' . implode(', ', $values)
            )->execute($params);
        }

        dl_auditLog('create_product', null, 'product', (string)$productId, null, [
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
            'pcs_per_pack' => $pcsPerPack,
        ]);

        app()->cache()->clearByTags('daily-ledger', ['dl_products']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product created', 'type' => 'success']]));
        $ctx->json([
            'ok' => true,
            'product_id' => $productId,
            'sku' => $sku,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
            'pcs_per_pack' => $pcsPerPack,
        ]);
    } catch (\Throwable $e) {
        write_log('apiCreateProduct error: ' . $e->getMessage(), 'error', ['trace' => substr((string)$e->getTraceAsString(), 0, 800)]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create product', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to create product'], 500);
    }
}

function apiUpdateProduct(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input     = $ctx->input();
    $productId = (int)($input['product_id'] ?? 0);
    $name      = trim((string)($input['name'] ?? ''));
    $category  = strtolower(trim((string)($input['product_category'] ?? 'bread')));
    if (!in_array($category, ['bread', 'cake', 'other'])) $category = 'bread';
    $price     = (float)($input['price'] ?? 0);
    $effectiveFrom = dl_normalizeEffectiveFrom(
        array_key_exists('effective_from', $input) ? $input['effective_from'] : null,
        dl_businessDate()
    );
    $sort      = (int)($input['sort_order'] ?? 0);
    $isActive  = (int)($input['is_active'] ?? 1);
    $outputPiecesPerBatch = dl_normalizePiecesPerBatch($input['output_pieces_per_batch'] ?? null);
    $outputUnitLabel = dl_normalizeOutputUnitLabel($input['output_unit_label'] ?? 'pcs');
    $batchInputQty = isset($input['batch_input_qty']) && $input['batch_input_qty'] !== '' && $input['batch_input_qty'] !== null
        ? round((float)$input['batch_input_qty'], 3) : null;
    if ($batchInputQty !== null && $batchInputQty <= 0) $batchInputQty = null;
    $batchEggQty = isset($input['batch_egg_qty']) && $input['batch_egg_qty'] !== '' && $input['batch_egg_qty'] !== null
        ? round((float)$input['batch_egg_qty'], 3) : null;
    if ($batchEggQty !== null && $batchEggQty <= 0) $batchEggQty = null;
    $pcsPerPack = isset($input['pcs_per_pack']) && $input['pcs_per_pack'] !== '' && $input['pcs_per_pack'] !== null
        ? (int)$input['pcs_per_pack'] : null;
    if ($pcsPerPack !== null && $pcsPerPack <= 0) $pcsPerPack = null;
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) {
            $userId = 0;
        }
    }
    if ($userId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $userId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $userId = (int)$sub;
        }
    }

    if (!$productId || $name === '' || $effectiveFrom === null) {
        $error = $effectiveFrom === null ? 'effective_from must be a valid YYYY-MM-DD date' : 'Invalid input';
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $error, 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => $error], 422);
        return;
    }

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        $ctx->db()->beginTransaction();

        // Lock the product so history insertion, current-price sync and repricing are atomic.
        $oldStmt = $ctx->db()->prepare('SELECT name, current_price, sort_order, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label, pcs_per_pack FROM dl_products WHERE id = :id FOR UPDATE');
        $oldStmt->execute([':id' => $productId]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            $ctx->db()->rollBack();
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Product not found'], 404);
            return;
        }

        $reprice = ['updated_rows' => 0, 'unchanged_rows' => 0, 'skipped_rows' => 0, 'skipped_days' => []];
        $priceChanged = abs(dl_resolveBaseProductPrice($productId, $effectiveFrom) - $price) >= 0.00001;
        if ($priceChanged) {
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by, effective_at)
                 VALUES (:pid, :price, :uid, :effective_at)'
            )->execute([
                ':pid' => $productId,
                ':price' => $price,
                ':uid' => $kernelActorUserId,
                ':effective_at' => $effectiveFrom . ' 00:00:00',
            ]);
            $reprice = dl_repriceProductLedgerRows($ctx->db(), $productId, $effectiveFrom);
        }

        // Future-dated changes must not leak into undated consumers before their start date.
        $currentPrice = dl_resolveBaseProductPrice($productId, dl_businessDate());
        $ctx->db()->prepare(
            'UPDATE dl_products SET name = :name, product_category = :cat, current_price = :price, sort_order = :sort, is_active = :active, output_pieces_per_batch = :oppb, batch_input_qty = :biq, batch_egg_qty = :beq, output_unit_label = :unit, pcs_per_pack = :ppp WHERE id = :id'
        )->execute([':name' => $name, ':cat' => $category, ':price' => $currentPrice, ':sort' => $sort, ':active' => $isActive, ':oppb' => $outputPiecesPerBatch, ':biq' => $batchInputQty, ':beq' => $batchEggQty, ':unit' => $outputUnitLabel, ':ppp' => $pcsPerPack, ':id' => $productId]);

        dl_auditLog('update_product', null, 'product', (string)$productId, $old, [
            'name' => $name,
            'price' => $price,
            'current_price' => $currentPrice,
            'effective_from' => $effectiveFrom,
            'reprice' => $reprice,
            'sort_order' => $sort,
            'is_active' => $isActive,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
            'pcs_per_pack' => $pcsPerPack,
        ]);

        $ctx->db()->commit();
        app()->cache()->clearByTags('daily-ledger', ['dl_products']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product updated', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'effective_from' => $effectiveFrom, 'current_price' => $currentPrice, 'reprice' => $reprice]);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }
        write_log('daily-ledger apiUpdateProduct failed', 'error', [
            'message' => $e->getMessage(),
            'product_id' => $productId,
            'name' => $name,
            'price' => $price,
            'sort_order' => $sort,
            'is_active' => $isActive,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
            'user_id' => $userId,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update product', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update product'], 500);
    }
}

function apiRepriceProduct(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin']);
    $input = $ctx->input();
    $productId = (int)($input['product_id'] ?? 0);
    $effectiveFrom = dl_normalizeEffectiveFrom($input['effective_from'] ?? null, dl_businessDate());
    if ($productId <= 0 || $effectiveFrom === null) {
        $ctx->json(['ok' => false, 'error' => 'product_id and a valid YYYY-MM-DD effective_from are required'], 422);
        return;
    }

    try {
        $ctx->db()->beginTransaction();
        $productStmt = $ctx->db()->prepare('SELECT id FROM dl_products WHERE id = :id FOR UPDATE');
        $productStmt->execute([':id' => $productId]);
        if (!$productStmt->fetchColumn()) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Product not found'], 404);
            return;
        }

        $reprice = dl_repriceProductLedgerRows($ctx->db(), $productId, $effectiveFrom);
        dl_auditLog('reprice_product', null, 'product', (string)$productId, null, [
            'effective_from' => $effectiveFrom,
            'reprice' => $reprice,
        ]);
        $ctx->db()->commit();
        $ctx->json(['ok' => true, 'product_id' => $productId, 'effective_from' => $effectiveFrom, 'reprice' => $reprice]);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
        }
        write_log('daily-ledger apiRepriceProduct failed', 'error', [
            'message' => $e->getMessage(),
            'product_id' => $productId,
            'effective_from' => $effectiveFrom,
        ]);
        $ctx->json(['ok' => false, 'error' => 'Failed to reprice product ledger rows'], 500);
    }
}

// ─── Admin: Branches ───────────────────────────────────────────────────

function handleAdminBranches(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $selectedPriceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : 0;

    $sql = 'SELECT b.*, pg.name AS price_group_name,
                ac.code AS assigned_commissary_code,
                ac.name AS assigned_commissary_name,
                (SELECT COUNT(*) FROM dl_user_branches ub INNER JOIN dl_users u ON u.id = ub.user_id WHERE ub.branch_id = b.id AND u.role = \'cashier\' AND u.is_active = 1 AND u.deleted_at IS NULL) AS user_count,
                (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.branch_id = b.id AND bp.is_active = 1) AS product_count
            FROM dl_branches b
            LEFT JOIN dl_price_groups pg ON pg.id = b.price_group_id
            LEFT JOIN dl_branches ac ON ac.id = b.assigned_commissary_id
            WHERE 1=1';
    $bind = [];
    if ($selectedPriceGroupId > 0) {
        $sql .= ' AND b.price_group_id = :price_group_id';
        $bind[':price_group_id'] = $selectedPriceGroupId;
    }
    if ($search !== '') {
        $sql .= ' AND (b.name LIKE :q OR b.code LIKE :q2 OR b.address LIKE :q3)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%"; $bind[':q3'] = "%{$search}%";
    }
    // Use the same order the Daily Sheet prints in, so the sequence configured
    // here is immediately visible here instead of only on the sheet.
    $sql .= ' ORDER BY (b.sort_order = 0) ASC, b.sort_order ASC, b.name ASC';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Commissary candidates for the supply-mode picker (any active branch flagged as commissary).
    $commStmt = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name');
    $commissaries = $commStmt ? ($commStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $priceGroupsStmt = $ctx->db()->query('SELECT id, name, type, is_default FROM dl_price_groups WHERE is_active = 1 ORDER BY is_default DESC, name');
    $priceGroups = $priceGroupsStmt ? ($priceGroupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $selectedPriceGroupName = null;
    foreach ($priceGroups as $priceGroup) {
        if ((int)($priceGroup['id'] ?? 0) === $selectedPriceGroupId) {
            $selectedPriceGroupName = (string)($priceGroup['name'] ?? '');
            break;
        }
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/branches.disyl', [
        'page_title' => 'Branches',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'branches',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'branches' => $branches,
        'commissaries' => $commissaries,
        'price_groups' => $priceGroups,
        'search' => $search,
        'selected_price_group_id' => $selectedPriceGroupId,
        'selected_price_group_name' => $selectedPriceGroupName,
    ]);
}

function apiCreateBranch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input   = $ctx->input();
    $code    = strtoupper(trim((string)($input['code'] ?? '')));
    $name    = trim((string)($input['name'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $area    = trim((string)($input['area'] ?? ''));
    $supplyMode = (string)($input['default_supply_mode'] ?? 'self_managed');
    if (!in_array($supplyMode, ['commissary_supplied','self_managed','hybrid'], true)) {
        $supplyMode = 'self_managed';
    }
    $assignedCommissaryId = isset($input['assigned_commissary_id']) && $input['assigned_commissary_id'] !== ''
        ? (int)$input['assigned_commissary_id'] : null;
    $priceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : null;
    $isCommissary = !empty($input['is_commissary']) ? 1 : 0;

    if ($code === '' || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Code and name are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Code and name are required'], 422);
        return;
    }

    if ($assignedCommissaryId !== null) {
        $commStmt = $ctx->db()->prepare(
            'SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
        );
        $commStmt->execute([':id' => $assignedCommissaryId]);
        if (!$commStmt->fetchColumn()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assigned commissary must be an active branch marked as a commissary', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Assigned commissary must be an active branch marked as a commissary'], 422);
            return;
        }
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_branches (code, name, address, area, default_supply_mode, assigned_commissary_id, price_group_id, is_commissary)
             VALUES (:code, :name, :addr, :area, :mode, :ac, :pg, :ic)'
        )->execute([
            ':code' => $code, ':name' => $name, ':addr' => $address,
            ':area' => $area !== '' ? $area : null,
            ':mode' => $supplyMode, ':ac' => $assignedCommissaryId, ':pg' => $priceGroupId, ':ic' => $isCommissary,
        ]);

        $branchId = (int)$ctx->db()->lastInsertId();

        // Assign all active products to new branch
        $pStmt = $ctx->db()->query('SELECT id FROM dl_products WHERE is_active = 1');
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
            )->execute([':bid' => $branchId, ':pid' => (int)$p['id']]);
        }

        dl_auditLog('create_branch', $branchId, 'branch', (string)$branchId, null, [
            'code' => $code, 'name' => $name, 'area' => $area,
            'default_supply_mode' => $supplyMode,
            'assigned_commissary_id' => $assignedCommissaryId,
            'price_group_id' => $priceGroupId,
            'is_commissary' => $isCommissary,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Branch created', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'branch_id' => $branchId]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to create branch'], 500);
    }
}

function apiUpdateBranch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $branchId = (int)($input['branch_id'] ?? 0);
    $name     = trim((string)($input['name'] ?? ''));
    $address  = trim((string)($input['address'] ?? ''));
    $area     = trim((string)($input['area'] ?? ''));
    $isActive = (int)($input['is_active'] ?? 1);
    $sortOrder = (int)($input['sort_order'] ?? 0);
    $supplyMode = (string)($input['default_supply_mode'] ?? '');
    $assignedCommissaryId = isset($input['assigned_commissary_id']) && $input['assigned_commissary_id'] !== ''
        ? (int)$input['assigned_commissary_id'] : null;
    $priceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : null;
    $isCommissary = array_key_exists('is_commissary', $input)
        ? (!empty($input['is_commissary']) ? 1 : 0)
        : null;

    if (!$branchId || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    if ($assignedCommissaryId !== null && $assignedCommissaryId === $branchId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'A branch cannot be assigned to itself as a commissary', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'A branch cannot be assigned to itself as a commissary'], 422);
        return;
    }

    if ($assignedCommissaryId !== null) {
        $commStmt = $ctx->db()->prepare(
            'SELECT id FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
        );
        $commStmt->execute([':id' => $assignedCommissaryId]);
        if (!$commStmt->fetchColumn()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assigned commissary must be an active branch marked as a commissary', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Assigned commissary must be an active branch marked as a commissary'], 422);
            return;
        }
    }

    try {
        $beforeStmt = $ctx->db()->prepare('SELECT default_supply_mode, assigned_commissary_id, price_group_id, is_commissary FROM dl_branches WHERE id = :id');
        $beforeStmt->execute([':id' => $branchId]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sets = ['name = :name', 'address = :addr', 'area = :area', 'is_active = :active', 'sort_order = :sort'];
        $bind = [':name' => $name, ':addr' => $address, ':area' => $area !== '' ? $area : null, ':active' => $isActive, ':sort' => $sortOrder, ':id' => $branchId];
        if (in_array($supplyMode, ['commissary_supplied','self_managed','hybrid'], true)) {
            $sets[] = 'default_supply_mode = :mode';
            $bind[':mode'] = $supplyMode;
        }
        if ($assignedCommissaryId !== null || array_key_exists('assigned_commissary_id', $input)) {
            $sets[] = 'assigned_commissary_id = :ac';
            $bind[':ac'] = $assignedCommissaryId;
        }
        if ($priceGroupId !== null || array_key_exists('price_group_id', $input)) {
            $sets[] = 'price_group_id = :pg';
            $bind[':pg'] = $priceGroupId;
        }
        if ($isCommissary !== null) {
            $sets[] = 'is_commissary = :ic';
            $bind[':ic'] = $isCommissary;
        }
        $ctx->db()->prepare('UPDATE dl_branches SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($bind);

        $afterStmt = $ctx->db()->prepare('SELECT default_supply_mode, assigned_commissary_id, price_group_id, is_commissary FROM dl_branches WHERE id = :id');
        $afterStmt->execute([':id' => $branchId]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (($before['default_supply_mode'] ?? null) !== ($after['default_supply_mode'] ?? null)
            || ($before['assigned_commissary_id'] ?? null) !== ($after['assigned_commissary_id'] ?? null)
            || ($before['price_group_id'] ?? null) !== ($after['price_group_id'] ?? null)) {
            dl_auditLog('branch_supply_mode_changed', $branchId, 'dl_branches', (string)$branchId, $before, $after);
        }
        dl_auditLog('update_branch', $branchId, 'branch', (string)$branchId);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Branch updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update branch'], 500);
    }
}

// ─── Admin: Users ──────────────────────────────────────────────────────

function handleAdminUsers(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin']);
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $tab = strtolower(trim((string)($input['tab'] ?? 'active')));
    if (!in_array($tab, ['active', 'inactive', 'deleted'], true)) {
        $tab = 'active';
    }

    $statusSql = match ($tab) {
        'inactive' => ' AND u.deleted_at IS NULL AND u.is_active = 0',
        'deleted' => ' AND u.deleted_at IS NOT NULL',
        default => ' AND u.deleted_at IS NULL AND u.is_active = 1',
    };

    $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
    $userEmailSelect = $usersHaveEmail ? 'u.email, ' : '';
    $sql = "SELECT u.id, u.username, {$userEmailSelect}u.full_name, u.role, u.shift,
                   u.is_active, u.deleted_at,
                   CASE WHEN u.role = 'cashier'
                        THEN (SELECT MIN(ub.branch_id) FROM dl_user_branches ub WHERE ub.user_id = u.id)
                        ELSE NULL END AS branch_id,
                   (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ')
                      FROM dl_user_branches ub
                      INNER JOIN dl_branches b ON b.id = ub.branch_id
                      WHERE ub.user_id = u.id) AS branch_names,
                   (SELECT GROUP_CONCAT(ub.branch_id ORDER BY ub.branch_id SEPARATOR ',')
                      FROM dl_user_branches ub
                      WHERE ub.user_id = u.id) AS branch_ids_csv
            FROM dl_users u
            WHERE 1=1" . $statusSql;
    $bind = [];
    if ($search !== '') {
        $emailSearch = $usersHaveEmail ? ' OR u.email LIKE :q2' : '';
        $sql .= ' AND (u.username LIKE :q' . $emailSearch . ' OR u.full_name LIKE :q3 OR u.role LIKE :q4)';
        $bind[':q'] = "%{$search}%";
        if ($usersHaveEmail) {
            $bind[':q2'] = "%{$search}%";
        }
        $bind[':q3'] = "%{$search}%";
        $bind[':q4'] = "%{$search}%";
    }
    $sql .= ' ORDER BY u.full_name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($users as &$userRow) {
        $userRow['email'] = (string)($userRow['email'] ?? '');
        $userRow['branch_names'] = (string)($userRow['branch_names'] ?? '');
        $userRow['branch_ids_csv'] = (string)($userRow['branch_ids_csv'] ?? '');
        $userRow['branch_id'] = (int)($userRow['branch_id'] ?? 0);
        $userRow['shift'] = (string)($userRow['shift'] ?? '');
    }
    unset($userRow);

    // Per-tab counts for the tab badges (single query over dl_users).
    $countRow = $ctx->db()->query(
        "SELECT
            SUM(CASE WHEN deleted_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN deleted_at IS NULL AND is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_count
         FROM dl_users"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = [
        'active_count' => (int)($countRow['active_count'] ?? 0),
        'inactive_count' => (int)($countRow['inactive_count'] ?? 0),
        'deleted_count' => (int)($countRow['deleted_count'] ?? 0),
    ];

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/users.disyl', [
        'page_title' => 'Users',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'users',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'users' => $users,
        'tab' => $tab,
        'active_count' => (int)($counts['active_count'] ?? 0),
        'inactive_count' => (int)($counts['inactive_count'] ?? 0),
        'deleted_count' => (int)($counts['deleted_count'] ?? 0),
        'branches' => $branches,
        'search' => $search,
    ]);
}

function apiCreateUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $username = trim((string)($input['username'] ?? ''));
    $email    = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role     = (string)($input['role'] ?? 'cashier');
    $branchId = (int)($input['branch_id'] ?? 0);
    $shift    = (string)($input['shift'] ?? '');

    if ($username === '' || $password === '' || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'All fields required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'All fields required'], 422);
        return;
    }

    if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
        return;
    }

    if ($shift !== '' && !in_array($shift, ['AM', 'PM'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid shift'], 422);
        return;
    }

    // Convenience: when creating a cashier without an explicit shift, auto-bind
    // AM/PM from a username ending in "am"/"pm" (e.g. cashier-miputakAM).
    if ($shift === '' && $role === 'cashier' && preg_match('/^(am|pm)$/i', substr($username, -2))) {
        $shift = strtoupper(substr($username, -2));
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Valid email required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Valid email required'], 422);
        return;
    }

    $identityConflict = dlUserIdentityConflict(0, $username, $email !== '' ? $email : null);
    if ($identityConflict !== null) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $identityConflict, 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => $identityConflict], 409);
        return;
    }

    $branchIds = $input['branch_ids'] ?? [];
    if (!is_array($branchIds)) {
        $branchIds = [];
    }
    $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static function ($v) {
        return $v > 0;
    })));

    // Cashiers must be assigned to a branch
    if ($role === 'cashier' && $branchId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Cashiers must be assigned to a branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Cashiers must be assigned to a branch'], 422);
        return;
    }

    if (in_array($role, ['supervisor', 'production_in_charge'], true) && count($branchIds) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'At least one branch is required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'At least one branch is required'], 422);
        return;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Optional `email` column may be missing on shared-host DBs that have
        // not run migration 035. Only write it when the column exists.
        $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
        $emailColumn = $usersHaveEmail ? ', email' : '';
        $emailPlaceholder = $usersHaveEmail ? ', :e' : '';
        $createBind = [':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role, ':s' => $shift !== '' ? $shift : null];
        if ($usersHaveEmail) {
            $createBind[':e'] = $email !== '' ? $email : null;
        }
        $ctx->db()->prepare(
            'INSERT INTO dl_users (username' . $emailColumn . ', password_hash, full_name, role, shift, is_active)
             VALUES (:u' . $emailPlaceholder . ', :p, :n, :r, :s, 1)'
        )->execute($createBind);
        $newUserId = (int)$ctx->db()->lastInsertId();

        if ($role === 'cashier') {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
            )->execute([':uid' => $newUserId, ':bid' => $branchId]);
        } elseif (in_array($role, ['supervisor', 'production_in_charge'], true)) {
            foreach ($branchIds as $bid) {
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
                )->execute([':uid' => $newUserId, ':bid' => $bid]);
            }
        }

        dl_auditLog('create_user', null, 'user', (string)$newUserId, null, [
            'id' => $newUserId,
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email !== '' ? $email : null,
            'role' => $role,
            'shift' => $shift !== '' ? $shift : null,
            'branch_id' => $role === 'cashier' ? $branchId : null,
            'branch_ids' => in_array($role, ['supervisor', 'production_in_charge'], true) ? $branchIds : null,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User created', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'user_id' => $newUserId]);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Username or email already exists', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Username or email already exists'], 409);
        } else {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create user', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Failed to create user'], 500);
        }
    }
}

function dlUserIdentityConflict(int $excludeUserId, string $username, ?string $email): ?string
{
    $ctx = module();
    if (!$ctx) {
        return 'Module context unavailable';
    }

    $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
    $emailClause = $usersHaveEmail
        ? ' OR (:check_email <> "" AND email IS NOT NULL AND email = :check_email2)'
        : '';
    $stmt = $ctx->db()->prepare(
        'SELECT id
         FROM dl_users
         WHERE id <> :exclude_id
                     AND ((:check_username <> "" AND username = :check_username2)'
                     . $emailClause . ')
         LIMIT 1'
    );
    $params = [
        ':exclude_id' => max(0, $excludeUserId),
        ':check_username' => $username,
        ':check_username2' => $username,
    ];
    if ($usersHaveEmail) {
        $params[':check_email'] = $email ?? '';
        $params[':check_email2'] = $email ?? '';
    }
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ? 'Username or email conflicts with another account.' : null;
}

function apiUpdateUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['admin']);

    $input    = $ctx->input();
    $editId   = (int)($input['user_id'] ?? 0);
    $fullName = trim((string)($input['full_name'] ?? ''));
    $email    = strtolower(trim((string)($input['email'] ?? '')));
    $role     = (string)($input['role'] ?? '');
    $isActive = (int)($input['is_active'] ?? 1);
    $password = (string)($input['password'] ?? '');
    $branchId = (int)($input['branch_id'] ?? 0);
    $shift    = (string)($input['shift'] ?? '');

    if (!$editId || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    if ($shift !== '' && !in_array($shift, ['AM', 'PM'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid shift', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid shift'], 422);
        return;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Valid email required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Valid email required'], 422);
        return;
    }

    try {
        if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
            return;
        }

        $st = $ctx->db()->prepare('SELECT role, deleted_at, username, full_name, email, is_active FROM dl_users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $editId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if (!is_array($existing)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'User not found'], 404);
            return;
        }

        if (!empty($existing['deleted_at'])) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Restore the user before editing', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'User is deleted; restore first'], 409);
            return;
        }

        $identityConflict = dlUserIdentityConflict($editId, '', $email !== '' ? $email : null);
        if ($identityConflict !== null) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $identityConflict, 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => $identityConflict], 409);
            return;
        }

        $currentRole = (string)($existing['role'] ?? '');
        if ($role !== $currentRole) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Role changes require new account', 'type' => 'error']]));
            $ctx->json([
                'ok' => false,
                'error' => 'Role changes create a new account instead. Create the new account, then deactivate the old one.',
            ], 422);
            return;
        }

        $branchIds = $input['branch_ids'] ?? [];
        if (!is_array($branchIds)) {
            $branchIds = [];
        }
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static function ($v) {
            return $v > 0;
        })));

        if ($role === 'cashier' && $branchId <= 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Cashiers must be assigned to a branch', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Cashiers must be assigned to a branch'], 422);
            return;
        }

        if (in_array($role, ['supervisor', 'production_in_charge'], true) && count($branchIds) === 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'At least one branch is required', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'At least one branch is required'], 422);
            return;
        }

        // Optional `email` column may be missing on shared-host DBs that have
        // not run migration 035. Only write it when the column exists.
        $usersHaveEmail = dlTableHasColumn('dl_users', 'email');
        $sql = 'UPDATE dl_users SET full_name = :name, is_active = :active';
        $bind = [':name' => $fullName, ':active' => $isActive, ':id' => $editId];
        // Only write `shift` when the payload carries it, so callers that
        // predate the per-user shift feature cannot accidentally clear one.
        if (array_key_exists('shift', $input)) {
            $sql .= ', shift = :shift';
            $bind[':shift'] = $shift !== '' ? $shift : null;
        }
        if ($usersHaveEmail) {
            $sql .= ', email = :email';
            $bind[':email'] = $email !== '' ? $email : null;
        }
        if ($password !== '') {
            $sql .= ', password_hash = :pass';
            $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id';
        $ctx->db()->prepare($sql)->execute($bind);

        if (in_array($role, ['cashier', 'supervisor', 'production_in_charge'], true)) {
            // Reset branch assignments
            $ctx->db()->prepare('DELETE FROM dl_user_branches WHERE user_id = :uid')->execute([':uid' => $editId]);

            $assignments = $role === 'cashier' ? [$branchId] : $branchIds;
            foreach ($assignments as $bid) {
                $bid = (int)$bid;
                if ($bid <= 0) continue;
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)'
                )->execute([':uid' => $editId, ':bid' => $bid]);
            }
        }

        dl_auditLog('update_user', null, 'user', (string)$editId, [
            'id' => $editId,
            'username' => (string)($existing['username'] ?? ''),
            'full_name' => (string)($existing['full_name'] ?? ''),
            'email' => $existing['email'] ?? null,
            'role' => (string)($existing['role'] ?? ''),
            'is_active' => (int)($existing['is_active'] ?? 0),
        ], [
            'id' => $editId,
            'username' => (string)($existing['username'] ?? ''),
            'full_name' => $fullName,
            'email' => $email !== '' ? $email : null,
            'role' => $role,
            'shift' => $shift !== '' ? $shift : null,
            'is_active' => $isActive,
            'branch_id' => $role === 'cashier' ? $branchId : null,
            'branch_ids' => in_array($role, ['supervisor', 'production_in_charge'], true) ? $branchIds : null,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update user', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Failed to update user'], 500);
    }
}

/**
 * Soft-delete a daily-ledger user account. Sets deleted_at and is_active=0
 * across the four role tables. Self-delete is refused. All FK references
 * (encoded_by, updated_by, audit logs, price history) are preserved
 * because the row is not removed.
 */
function apiDeleteUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user   = dlCurrentUser(['admin']);
    $input  = $ctx->input();
    $userId = (int)($input['user_id'] ?? 0);
    $role   = (string)($input['role'] ?? '');

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // Prevent self-delete: dlCurrentUser sub is "<role>:<id>" for module logins.
    $sub = (string)($user['sub'] ?? '');
    if ($sub === $role . ':' . $userId) {
        $ctx->json(['ok' => false, 'error' => 'You cannot delete your own account'], 403);
        return;
    }

    if ($role === 'admin') {
        $adminCount = dlActiveAdminCount();
        if ($adminCount <= 1) {
            $ctx->json(['ok' => false, 'error' => 'Cannot delete the last active admin account'], 422);
            return;
        }
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_users SET deleted_at = CURRENT_TIMESTAMP, is_active = 0
             WHERE id = :id AND role = :role AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $userId, ':role' => $role]);
        if ($stmt->rowCount() === 0) {
            $ctx->json(['ok' => false, 'error' => 'User not found or already deleted'], 404);
            return;
        }

        $userInfoStmt = $ctx->db()->prepare('SELECT username, full_name FROM dl_users WHERE id = :id LIMIT 1');
        $userInfoStmt->execute([':id' => $userId]);
        $userInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        dl_auditLog('delete_user', null, 'user', (string)$userId, null, [
            'id' => $userId,
            'username' => (string)($userInfo['username'] ?? ''),
            'full_name' => (string)($userInfo['full_name'] ?? ''),
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User deleted', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed to delete user'], 500);
    }
}

/**
 * Restore a soft-deleted daily-ledger user account. The account is restored
 * as inactive so the admin must explicitly re-activate it before logins
 * are permitted again.
 */
function apiRestoreUser(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    dlCurrentUser(['admin']);
    $input  = $ctx->input();
    $userId = (int)($input['user_id'] ?? 0);
    $role   = (string)($input['role'] ?? '');

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_users SET deleted_at = NULL
             WHERE id = :id AND role = :role AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':id' => $userId, ':role' => $role]);
        if ($stmt->rowCount() === 0) {
            $ctx->json(['ok' => false, 'error' => 'User not found or not deleted'], 404);
            return;
        }

        $userInfoStmt = $ctx->db()->prepare('SELECT username, full_name FROM dl_users WHERE id = :id LIMIT 1');
        $userInfoStmt->execute([':id' => $userId]);
        $userInfo = $userInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        dl_auditLog('restore_user', null, 'user', (string)$userId, null, [
            'id' => $userId,
            'username' => (string)($userInfo['username'] ?? ''),
            'full_name' => (string)($userInfo['full_name'] ?? ''),
            'role' => $role,
        ]);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User restored (inactive)', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed to restore user'], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — CSV Import / Export Handlers
// ─────────────────────────────────────────────────────────────────────────

function handleProductsCsvExport(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    
    $user = dlCurrentUser(['admin']);

    $stmt = $ctx->db()->query(
        "SELECT p.*, COUNT(bp.branch_id) as branch_count
         FROM dl_products p
         LEFT JOIN dl_branch_products bp ON p.id = bp.product_id
         GROUP BY p.id
         ORDER BY p.name ASC"
    );
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $headers = [
        'SKU',
        'Name',
        'Category',
        'Price',
        'Sort Order',
        'Active',
        'Output Pieces Per Batch',
        'Output Unit Label',
        'Batch Kilo Qty',
        'Batch Egg Qty',
    ];
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [
            'SKU'                    => (string)($item['sku'] ?? ''),
            'Name'                   => (string)($item['name'] ?? ''),
            'Category'               => ucfirst((string)($item['product_category'] ?? 'bread')),
            'Price'                  => (string)($item['current_price'] ?? '0.00'),
            'Sort Order'             => (string)($item['sort_order'] ?? '0'),
            'Active'                 => ((int)($item['is_active'] ?? 1) === 1) ? '1' : '0',
            'Output Pieces Per Batch'=> (string)($item['output_pieces_per_batch'] ?? '0'),
            'Output Unit Label'      => (string)($item['output_unit_label'] ?? 'pcs'),
            'Batch Kilo Qty'         => isset($item['batch_input_qty']) && (float)$item['batch_input_qty'] > 0 ? (string)$item['batch_input_qty'] : '',
            'Batch Egg Qty'          => isset($item['batch_egg_qty']) && (float)$item['batch_egg_qty'] > 0 ? (string)$item['batch_egg_qty'] : '',
        ];
    }

    dlCsvResponse('products-' . date('Y-m-d-His') . '.csv', $headers, $rows);
}

/**
 * Parse + normalize a single product CSV row (keys already header-normalized).
 *
 * Returns a flat field map ready for the insert/update statements, or throws
 * RuntimeException when a required cell is missing/malformed. The import loop
 * catches that and skips the row (counted), so one bad cell never aborts the
 * whole upload or leaves partial rows committed.
 */
function dl_normalizeProductCsvRow(array $normalizedRow): array
{
    $name = trim((string)($normalizedRow['name'] ?? ''));
    $price = dlCsvNullableFloat($normalizedRow['price'] ?? null);
    if ($name === '' || $price === null || $price < 0) {
        throw new \RuntimeException('Missing or invalid product name/price.');
    }

    $sku = trim((string)($normalizedRow['sku'] ?? ''));
    $sortOrder = dlCsvNullableInt($normalizedRow['sort_order'] ?? null) ?? 0;
    $isActive = isset($normalizedRow['active']) && in_array(strtolower(trim((string)$normalizedRow['active'])), ['0', 'false', 'no']) ? 0 : 1;
    $category = 'bread';
    if (isset($normalizedRow['category'])) {
        $val = strtolower(trim((string)$normalizedRow['category']));
        if (in_array($val, ['bread', 'cake', 'other'], true)) {
            $category = $val;
        }
    }

    $oppbSource = $normalizedRow['output_pieces_per_batch'] ?? ($normalizedRow['output_pieces'] ?? null);
    $oulSource = $normalizedRow['output_unit_label'] ?? ($normalizedRow['output_unit'] ?? 'pcs');
    $batchKiloSource = $normalizedRow['batch_kilo_qty'] ?? ($normalizedRow['batch_input_qty'] ?? ($normalizedRow['kilo_per_batch'] ?? null));
    $batchEggSource = $normalizedRow['batch_egg_qty'] ?? ($normalizedRow['eggs_per_batch'] ?? ($normalizedRow['egg_per_batch'] ?? null));

    $oppb = dl_normalizePiecesPerBatch(dlCsvNullableInt($oppbSource) ?? 0);
    $oul = dl_normalizeOutputUnitLabel($oulSource ?? 'pcs');
    $batchInputQty = dlCsvNullableFloat($batchKiloSource);
    if ($batchInputQty !== null) {
        $batchInputQty = round($batchInputQty, 3);
        if ($batchInputQty <= 0) {
            $batchInputQty = null;
        }
    }
    $batchEggQty = dlCsvNullableFloat($batchEggSource);
    if ($batchEggQty !== null) {
        $batchEggQty = round($batchEggQty, 3);
        if ($batchEggQty <= 0) {
            $batchEggQty = null;
        }
    }

    return [
        'name' => $name,
        'price' => $price,
        'sku' => $sku,
        'sort_order' => $sortOrder,
        'is_active' => $isActive,
        'category' => $category,
        'oppb' => $oppb,
        'oul' => $oul,
        'batch_input_qty' => $batchInputQty,
        'batch_egg_qty' => $batchEggQty,
    ];
}

function apiProductsImportCsv(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }
    
    $user = dlCurrentUser(['admin']);
    
    $userId = 0;
    if (isset($user['id']) && is_numeric($user['id'])) {
        $userId = (int)$user['id'];
        if ($userId <= 0) $userId = 0;
    }
    if ($userId <= 0) {
        $sub = (string)($user['sub'] ?? '');
        if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier):(\d+)$/', $sub, $m)) {
            $userId = (int)$m[1];
        } elseif (is_numeric($sub)) {
            $userId = (int)$sub;
        }
    }
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }
    $importEffectiveAt = dl_businessDate() . ' 00:00:00';

    $upload = dlImportReadUploadedCsv('csv_file');
    if (empty($upload['ok'])) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => (string)($upload['error'] ?? 'CSV upload failed.'), 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => (string)($upload['error'] ?? 'CSV upload failed.')], 422);
        return;
    }

    try {
        $rows = dlCsvRowsFromString((string)$upload['raw']);
        if ($rows === []) {
             header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'CSV file is empty.', 'type' => 'error']]));
             $ctx->json(['ok' => false, 'error' => 'CSV file is empty.'], 422);
             return;
        }

        $firstRow = $rows[0] ?? [];
        $normalizedKeys = array_map('dlCsvNormalizeHeader', array_keys($firstRow));
        if (!in_array('name', $normalizedKeys, true) || !in_array('price', $normalizedKeys, true)) {
             header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Missing required columns: Name, Price', 'type' => 'error']]));
             $ctx->json(['ok' => false, 'error' => 'Missing required columns: Name, Price'], 422);
             return;
        }
        
        $updated = 0;
        $created = 0;
        $skipped = 0;
        $skippedReasons = [];
        
        foreach ($rows as $rowIndex => $row) {
            // A malformed cell must skip only its own row (counted in $skipped),
            // never abort the whole import and leave partial rows committed.
            try {
            $normalizedRow = [];
            foreach ($row as $k => $v) {
                $normalizedRow[dlCsvNormalizeHeader((string)$k)] = $v;
            }
            
            $parsed = dl_normalizeProductCsvRow($normalizedRow);
            $name = $parsed['name'];
            $price = $parsed['price'];
            $sku = $parsed['sku'];
            $sortOrder = $parsed['sort_order'];
            $isActive = $parsed['is_active'];
            $category = $parsed['category'];
            $oppb = $parsed['oppb'];
            $oul = $parsed['oul'];
            $batchInputQty = $parsed['batch_input_qty'];
            $batchEggQty = $parsed['batch_egg_qty'];
            
            if ($sku !== '') {
                $stmt = $ctx->db()->prepare('SELECT id, current_price FROM dl_products WHERE sku = :sku');
                $stmt->execute([':sku' => $sku]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $pid = (int)$existing['id'];
                    $oldPrice = (float)$existing['current_price'];
                    
                    $ctx->db()->prepare(
                        'UPDATE dl_products
                         SET name = :name,
                             product_category = :cat,
                             current_price = :price,
                             sort_order = :sort,
                             is_active = :act,
                             output_pieces_per_batch = :oppb,
                             batch_input_qty = :biq,
                             batch_egg_qty = :beq,
                             output_unit_label = :oul
                         WHERE id = :id'
                    )->execute([
                        ':name' => $name,
                        ':cat' => $category,
                        ':price' => $price,
                        ':sort' => $sortOrder,
                        ':act' => $isActive,
                        ':oppb' => $oppb,
                        ':biq' => $batchInputQty,
                        ':beq' => $batchEggQty,
                        ':oul' => $oul,
                        ':id' => $pid,
                    ]);
                    
                    if (abs($oldPrice - $price) > 0.001) {
                        $ctx->db()->prepare(
                            'INSERT INTO dl_product_price_history (product_id, price, changed_by, effective_at) VALUES (:pid, :price, :uid, :effective_at)'
                        )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId, ':effective_at' => $importEffectiveAt]);
                    }
                    
                    // Assign active branches if not present
                    $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
                    foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
                        $ctx->db()->prepare(
                            'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
                        )->execute([':bid' => (int)$br['id'], ':pid' => $pid]);
                    }
                    
                    dl_auditLog('update_product', null, 'product', (string)$pid, null, [
                        'csv_import' => true,
                        'sku' => $sku,
                        'name' => $name,
                        'category' => $category,
                        'price' => $price,
                        'is_active' => $isActive,
                        'output_pieces_per_batch' => $oppb,
                        'output_unit_label' => $oul,
                        'batch_input_qty' => $batchInputQty,
                        'batch_egg_qty' => $batchEggQty,
                    ]);
                    $updated++;
                    continue;
                }
            }
            
            // Create New
            if ($sku === '') $sku = dl_generateSku();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_products
                    (sku, name, product_category, current_price, sort_order, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label)
                 VALUES
                    (:sku, :name, :cat, :price, :sort, :act, :oppb, :biq, :beq, :oul)'
            )->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':cat' => $category,
                ':price' => $price,
                ':sort' => $sortOrder,
                ':act' => $isActive,
                ':oppb' => $oppb,
                ':biq' => $batchInputQty,
                ':beq' => $batchEggQty,
                ':oul' => $oul,
            ]);
            $pid = (int)$ctx->db()->lastInsertId();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by, effective_at) VALUES (:pid, :price, :uid, :effective_at)'
            )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId, ':effective_at' => $importEffectiveAt]);
            
            $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
            foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
                )->execute([':bid' => (int)$br['id'], ':pid' => $pid]);
            }
            
            dl_auditLog('create_product', null, 'product', (string)$pid, null, [
                'csv_import' => true,
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'price' => $price,
                'is_active' => $isActive,
                'output_pieces_per_batch' => $oppb,
                'output_unit_label' => $oul,
                'batch_input_qty' => $batchInputQty,
                'batch_egg_qty' => $batchEggQty,
            ]);
            $created++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedReasons[] = 'row ' . ($rowIndex + 2) . ': ' . $e->getMessage();
                write_log('dl products import row skipped (row ' . ($rowIndex + 2) . '): ' . $e->getMessage(), 'warning', ['module' => 'daily-ledger']);
            }
        }
        
        $msg = "Imported: $created created, $updated updated, $skipped skipped.";
        if ($skipped > 0 && $skippedReasons !== []) {
            $sample = array_slice(array_unique($skippedReasons), 0, 3);
            $msg .= ' Skipped: ' . implode('; ', $sample);
            if ($skipped > count($sample)) {
                $msg .= ' (+' . ($skipped - count($sample)) . ' more)';
            }
        }
        app()->cache()->clearByTags('daily-ledger', ['dl_products']);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $msg, 'type' => 'success'], 'reloadProducts' => true]));
        $ctx->json(['ok' => true, 'message' => $msg]);
    } catch (\Throwable $e) {
        write_log('dl products import error: ' . $e->getMessage(), 'error', ['module' => 'daily-ledger']);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Import failed: ' . $e->getMessage(), 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Import failed'], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — Commissary / Production Runs
// ─────────────────────────────────────────────────────────────────────────

function dl_buildUsagePageData(\Ikabud\Kernel\Contracts\DatabaseContract $db, array $user, string $rawDate, int $requestedBranchId = 0): array
{
    $productsStmt = $db->query("SELECT id, name, product_category, is_active, output_pieces_per_batch, batch_input_qty, batch_egg_qty, output_unit_label FROM dl_products ORDER BY product_category ASC, sort_order ASC, name ASC");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load raw materials
    $materialsStmt = $db->query("SELECT * FROM dl_raw_materials WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    $materials = $materialsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load commissary ledger for the date
    $ledgerStmt = $db->prepare("SELECT * FROM dl_commissary_ledger WHERE ledger_date = :date");
    $ledgerStmt->execute([':date' => $rawDate]);
    $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $ledgerMap = [];
    foreach ($ledgerRows as $r) {
        $ledgerMap[(int)$r['raw_material_id']] = $r;
    }

    $branchesStmt = $db->query("SELECT id, name FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availableBranchIds = array_map('intval', array_column($branches, 'id'));

    $selectedBranchId = $requestedBranchId;
    if ($selectedBranchId > 0 && !in_array($selectedBranchId, $availableBranchIds, true)) {
        $selectedBranchId = 0;
    }

    if ($selectedBranchId <= 0) {
        $defaultBranchStmt = $db->prepare(
            "SELECT destination_branch_id
             FROM dl_production_runs
             WHERE ledger_date = :date AND destination_branch_id IS NOT NULL
             ORDER BY id ASC
             LIMIT 1"
        );
        $defaultBranchStmt->execute([':date' => $rawDate]);
        $defaultBranchId = (int)($defaultBranchStmt->fetchColumn() ?: 0);
        $paperBranchId = 0;
        if ($defaultBranchId <= 0 || !in_array($defaultBranchId, $availableBranchIds, true)) {
            $paperDefaultStmt = $db->prepare(
                "SELECT destination_id
                 FROM dl_deliveries
                 WHERE delivery_date = :date
                   AND origin_type = 'commissary'
                   AND destination_type = 'branch'
                   AND remarks = :remarks
                   AND status <> 'voided'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $paperDefaultStmt->execute([':date' => $rawDate, ':remarks' => dl_paperDrCaptureRemark()]);
            $paperBranchId = (int)($paperDefaultStmt->fetchColumn() ?: 0);
        }
        if ($defaultBranchId > 0 && in_array($defaultBranchId, $availableBranchIds, true)) {
            $selectedBranchId = $defaultBranchId;
        } elseif ($paperBranchId > 0 && in_array($paperBranchId, $availableBranchIds, true)) {
            $selectedBranchId = $paperBranchId;
        } elseif ($availableBranchIds !== []) {
            $selectedBranchId = $availableBranchIds[0];
        }
    }

    // Load production runs for the selected branch and date.
    if ($selectedBranchId > 0) {
        $runsStmt = $db->prepare(
            "SELECT pr.*, p.name as product_name
             FROM dl_production_runs pr
             JOIN dl_products p ON pr.product_id = p.id
             WHERE pr.ledger_date = :date AND pr.destination_branch_id = :branch"
        );
        $runsStmt->execute([':date' => $rawDate, ':branch' => $selectedBranchId]);
    } else {
        $runsStmt = $db->prepare(
            "SELECT pr.*, p.name as product_name
             FROM dl_production_runs pr
             JOIN dl_products p ON pr.product_id = p.id
             WHERE pr.ledger_date = :date AND pr.destination_branch_id IS NULL"
        );
        $runsStmt->execute([':date' => $rawDate]);
    }
    $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $paperCaptureItems = [];
    if ($selectedBranchId > 0) {
        $paperStmt = $db->prepare(
            "SELECT d.id AS delivery_id, d.dr_number, d.delivery_date, d.created_at,
                    di.product_id, di.quantity,
                    COALESCE(u.username, '') AS created_by_name
             FROM dl_deliveries d
             INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
             LEFT JOIN dl_users u ON u.id = d.created_by
             WHERE d.delivery_date = :date
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.destination_id = :branch
               AND d.remarks = :remarks
               AND d.status <> 'voided'
             ORDER BY d.id DESC, di.id ASC"
        );
        $paperStmt->execute([
            ':date' => $rawDate,
            ':branch' => $selectedBranchId,
            ':remarks' => dl_paperDrCaptureRemark(),
        ]);
        $paperCaptureItems = $paperStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $paperCaptureMap = [];
    $paperDrNumber = '';
    foreach ($paperCaptureItems as $paperItem) {
        $productId = (int)($paperItem['product_id'] ?? 0);
        $quantity = (int)($paperItem['quantity'] ?? 0);
        if ($productId > 0 && $quantity > 0) {
            $paperCaptureMap[$productId] = ($paperCaptureMap[$productId] ?? 0) + $quantity;
        }
        if ($paperDrNumber === '' && trim((string)($paperItem['dr_number'] ?? '')) !== '') {
            $paperDrNumber = trim((string)$paperItem['dr_number']);
        }
    }

    // Map selected-branch runs by product_id so we can associate them 1:1 on the spreadsheet
    $runMap = [];
    foreach ($runs as $r) {
        $runMap[(int)$r['product_id']] = $r;
    }

    // Combine products + runs state
    $productRowsBread = [];
    $productRowsCake = [];
    foreach ($products as $p) {
        if (!$p['is_active']) continue;
        $pid = (int)$p['id'];
        $r = $runMap[$pid] ?? null;
        
        $iqty = $r && $r['primary_input_qty'] > 0 ? (float)$r['primary_input_qty'] : '';
        if ($iqty !== '') {
            $iqty = rtrim(rtrim(sprintf('%.3f', $iqty), '0'), '.');
        }
        $kilo_qty = ($r && ($r['primary_input_type'] ?? '') === 'kilo') ? $iqty : '';
        $egg_qty  = ($r && ($r['primary_input_type'] ?? '') === 'egg')  ? $iqty : '';

        $rowData = [
            'product_id'             => $pid,
            'name'                   => $p['name'],
            'baker_name'             => $r ? (string)$r['baker_name'] : '',
            'kilo_qty'               => $kilo_qty,
            'egg_qty'                => $egg_qty,
            'yield_qty'              => $r && $r['yield_qty'] > 0 ? (int)$r['yield_qty'] : '',
            'category'               => $p['product_category'],
            'output_pieces_per_batch'=> (int)($p['output_pieces_per_batch'] ?? 0),
            'batch_input_qty'        => isset($p['batch_input_qty']) && $p['batch_input_qty'] > 0 ? (float)$p['batch_input_qty'] : 0,
            'batch_egg_qty'          => isset($p['batch_egg_qty']) && $p['batch_egg_qty'] > 0 ? (float)$p['batch_egg_qty'] : 0,
            'output_unit_label'      => (string)($p['output_unit_label'] ?? 'pcs'),
        ];
        
        if ($p['product_category'] === 'cake') {
            $productRowsCake[] = $rowData;
        } else {
            $productRowsBread[] = $rowData;
        }
    }

    // Combine material + ledger state
    $commissaryRows = [];
    foreach ($materials as $m) {
        $mid = (int)$m['id'];
        $l = $ledgerMap[$mid] ?? [];
                        $commissaryRows[] = [
            'material_id'      => $mid,
            'name'             => (string)$m['name'],
            'unit'             => (string)$m['unit_of_measure'],
            'category'         => (string)$m['category'],
            'beg_bal'          => (float)($l['beg_bal'] ?? 0),
            'delivery_qty'     => (float)($l['delivery_qty'] ?? 0),
            'used_qty'         => (float)($l['used_qty'] ?? 0),
            'actual_end_bal'   => (float)($l['actual_end_bal'] ?? 0),
            'calc_variance'    => (float)($l['calc_variance'] ?? 0),
        ];
    }
    $globalBaker = '';
    $globalBranch = $selectedBranchId;
    $globalDrNumber = '';
    foreach ($runs as $r) {
        if ($r['baker_name'] !== '') {
            $globalBaker = (string)$r['baker_name'];
        }
        if ($globalDrNumber === '' && trim((string)($r['dr_number'] ?? '')) !== '') {
            $globalDrNumber = trim((string)$r['dr_number']);
        }
    }
    if ($globalDrNumber === '' && $paperDrNumber !== '') {
        $globalDrNumber = $paperDrNumber;
    }

    // Load net production output movements for the date (scoped to accessible branches).
    // Used on the commissary page to auto-populate yield fields when a branch is selected.
    // "Net" = output movements that have not been reversed.
    $accessibleBranchIds = dl_accessibleBranchIds($user);
    $outputByBranch = [];
    if (count($accessibleBranchIds) > 0) {
        $bPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
        $outStmt = $db->prepare(
            "SELECT pm.destination_branch_id, pm.product_id, SUM(pm.quantity) AS net_qty
             FROM dl_production_movements pm
             WHERE pm.ledger_date = ?
               AND pm.movement_type = 'output'
               AND pm.destination_branch_id IN ({$bPlaceholders})
               AND NOT EXISTS (
                   SELECT 1 FROM dl_production_movements r
                   WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
               )
             GROUP BY pm.destination_branch_id, pm.product_id
             HAVING net_qty > 0"
        );
        $outStmt->execute(array_merge([$rawDate], $accessibleBranchIds));
        foreach ($outStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $bid = (int)$row['destination_branch_id'];
            $pid = (int)$row['product_id'];
            if (!isset($outputByBranch[$bid])) $outputByBranch[$bid] = [];
            $outputByBranch[$bid][$pid] = (int)$row['net_qty'];
        }
    }

    $paperPrefillStmt = null;
    if (count($accessibleBranchIds) > 0) {
        $paperPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
        $paperPrefillStmt = $db->prepare(
            "SELECT d.destination_id AS branch_id, di.product_id, SUM(di.quantity) AS net_qty
             FROM dl_deliveries d
             INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
             WHERE d.delivery_date = ?
               AND d.origin_type = 'commissary'
               AND d.destination_type = 'branch'
               AND d.destination_id IN ({$paperPlaceholders})
               AND d.remarks = ?
               AND d.status <> 'voided'
             GROUP BY d.destination_id, di.product_id
             HAVING net_qty > 0"
        );
        $paperPrefillStmt->execute(array_merge([$rawDate], $accessibleBranchIds, [dl_paperDrCaptureRemark()]));
        foreach ($paperPrefillStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $bid = (int)$row['branch_id'];
            $pid = (int)$row['product_id'];
            if (!isset($outputByBranch[$bid])) {
                $outputByBranch[$bid] = [];
            }
            $existingQty = (int)($outputByBranch[$bid][$pid] ?? 0);
            $paperQty = (int)$row['net_qty'];
            if ($paperQty > $existingQty) {
                $outputByBranch[$bid][$pid] = $paperQty;
            }
        }
    }

    // Same-location internal-release eligibility for the selected destination
    // branch (presentation-only metadata). The server re-validates every save.
    // Batch-resolved so the product grid render does not issue N×3 queries.
    $sameLocationRelease = ['branch_id' => 0, 'branch' => null, 'eligible' => false, 'products' => []];
    if ($selectedBranchId > 0) {
        $activeProductIds = [];
        foreach ($products as $p) {
            if (!empty($p['is_active'])) {
                $activeProductIds[] = (int)$p['id'];
            }
        }
        $sameLocationRelease = dl_buildSameLocationEligibilityMap($db, $selectedBranchId, $activeProductIds);
    }

    return [
        'date' => $rawDate,
        'products' => $products,
        'branches' => $branches,
        'global_baker_name' => $globalBaker,
        'global_branch_id' => $globalBranch,
        'global_dr_number' => $globalDrNumber,
        'product_rows_bread' => $productRowsBread,
        'product_rows_cake' => $productRowsCake,
        'materials' => $commissaryRows,
        'output_by_branch' => $outputByBranch,
        'paper_capture_product_map' => $paperCaptureMap,
        'paper_capture_dr_number' => $paperDrNumber,
        'same_location_release' => $sameLocationRelease,
        // The Usage page needs the formal-delivery flag to render the correct
        // DR label (required vs internal-release). dlRender injects
        // layout-level feature flags, but not this raw flag.
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'feature_formal_delivery' => dl_isFormalDeliveryEnabled(),
    ];
}

function handleAdminUsage(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();
    $rawDate = (string)($input['date'] ?? '');
    if ($rawDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        $rawDate = date('Y-m-d');
    }
    $requestedBranchId = (int)($input['branch_id'] ?? 0);

    $pageData = dl_buildUsagePageData($ctx->db(), $user, $rawDate, $requestedBranchId);

    echo dlRender('modules/daily-ledger/admin/usage.disyl', [
        'page_title' => 'Commissary Usage',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'current_page' => 'usage',
        'user' => $user,
        'user_name' => $user['full_name'] ?? $user['username'] ?? 'User',
        'user_role' => $user['role'] ?? 'unknown',
    ] + $pageData);
}

function handleAdminCommissary(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $role = (string)($user['role'] ?? '');
    $isProductionUser = $role === 'production_in_charge';
    $canViewProductionManagement = in_array($role, ['admin', 'supervisor'], true);
    // Day controls are role-gated here; the R7 production today-only rule is
    // enforced server-side by apiCloseDay(), never by hiding the button alone.
    $canCloseDay = in_array($role, ['admin', 'supervisor', 'production_in_charge'], true);
    $canReopenDay = dl_roleHasPermission($role, 'ledger.override');
    $db = $ctx->db();
    $input = $ctx->input();
    // A real calendar date only. An invalid date falls back to the current
    // business date for everyone; a future date falls back only on the
    // production view (the picker's max is today). Management keeps its
    // existing ability to inspect a future-dated sheet. The HTML max="{today}"
    // is a hint; this is the enforcement.
    $today = dl_businessDate();
    $rawDate = (string)($input['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)
        || !checkdate((int)substr($rawDate, 5, 2), (int)substr($rawDate, 8, 2), (int)substr($rawDate, 0, 4))
        || ($isProductionUser && $rawDate > $today)) {
        $rawDate = $today;
    }
    // Production operators follow the same shift contract as cashiers: an
    // assigned user is locked to that shift, while an unassigned user may
    // select AM or PM and defaults to the operating clock. Management retains
    // its all-day/explicit-shift filter, including pre-shift historical rows.
    $productionShift = $isProductionUser ? dl_resolveLedgerShift($user, $input) : null;
    $shiftLocked = (bool)($productionShift['bound'] ?? false);
    $shift = $isProductionUser
        ? (string)$productionShift['shift']
        : (isset($input['shift']) && (string)$input['shift'] !== ''
            ? dl_normalizeShift((string)$input['shift'])
            : null);

    $requestedBranchId = (int)($input['branch_id'] ?? 0);
    $requestedCommissaryId = (int)($input['commissary_id'] ?? 0);
    $commissariesStmt = $db->query("SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name ASC");
    $commissaries = $commissariesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availableCommissaryIds = array_map('intval', array_column($commissaries, 'id'));
    // Default to All (0). Historical deliveries carry origin_id = NULL, so a
    // default commissary filter blanked every branch cell; only an explicitly
    // selected, available commissary narrows the sheet.
    $selectedCommissaryId = in_array($requestedCommissaryId, $availableCommissaryIds, true)
        ? $requestedCommissaryId
        : 0;

    // Branches: when commissary selected, show only branches assigned to it OR with DR data from it
    if ($selectedCommissaryId > 0) {
        $branchesStmt = $db->prepare(
            "SELECT DISTINCT b.id, b.name, b.is_commissary
               FROM dl_branches b
               LEFT JOIN dl_deliveries d ON d.destination_id = b.id
                 AND d.destination_type = 'branch'
                 AND d.origin_id = :cid
                 AND d.origin_type = 'commissary'
                 AND d.status = 'posted'
                 AND d.delivery_date = :date
              WHERE b.is_active = 1
                AND (b.assigned_commissary_id = :cid2 OR d.id IS NOT NULL)
              ORDER BY b.name ASC"
        );
        $branchesStmt->execute([':cid' => $selectedCommissaryId, ':cid2' => $selectedCommissaryId, ':date' => $rawDate]);
        $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $branchesStmt = $db->query("SELECT id, name, is_commissary FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
        $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $availableBranchIds = array_map('intval', array_column($branches, 'id'));
    $selectedBranchId = $requestedBranchId > 0 && in_array($requestedBranchId, $availableBranchIds, true)
        ? $requestedBranchId
        : 0;

    // ── Tab 1: Inventory (commissary product ledger) ──
    $inventorySql = "SELECT cpl.commissary_branch_id,
                COALESCE(b.name, 'Commissary') AS commissary_name,
                cpl.product_id,
                p.name AS product_name,
                p.sku,
                cpl.beg_qty,
                cpl.produced_qty,
                cpl.dispatched_qty,
                cpl.wastage_qty,
                cpl.remaining_qty,
                cpl.actual_end_qty,
                cpl.calc_variance,
                (cpl.beg_qty + cpl.produced_qty - cpl.dispatched_qty - cpl.wastage_qty) AS book_balance,
                COALESCE(cum.cumulative_remaining, cpl.remaining_qty) AS cumulative_remaining,
                cpl.updated_at
           FROM dl_commissary_product_ledger cpl
           INNER JOIN dl_products p ON p.id = cpl.product_id
           LEFT JOIN dl_branches b ON b.id = cpl.commissary_branch_id
           LEFT JOIN (
               SELECT commissary_branch_id, product_id, SUM(remaining_qty) AS cumulative_remaining
                 FROM dl_commissary_product_ledger
                GROUP BY commissary_branch_id, product_id
           ) cum ON cum.commissary_branch_id = cpl.commissary_branch_id AND cum.product_id = cpl.product_id
          WHERE cpl.ledger_date = :date
            AND (cpl.produced_qty > 0 OR cpl.dispatched_qty > 0 OR cpl.wastage_qty > 0)";
    $inventoryBind = [':date' => $rawDate];
    if ($selectedCommissaryId > 0) {
        $inventorySql .= ' AND cpl.commissary_branch_id = :cid';
        $inventoryBind[':cid'] = $selectedCommissaryId;
    }
    $inventorySql .= " ORDER BY COALESCE(b.name, 'Commissary') ASC, p.name ASC";
    $inventoryStmt = $db->prepare($inventorySql);
    $inventoryStmt->execute($inventoryBind);
    $inventoryRows = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Cumulative stock (all dates) for dispatch dropdown ──
    $cumulativeStockStmt = $db->prepare(
        "SELECT cpl.commissary_branch_id,
                COALESCE(b.name, 'Commissary') AS commissary_name,
                cpl.product_id,
                p.name AS product_name,
                p.sku,
                SUM(cpl.produced_qty) AS total_produced,
                SUM(cpl.dispatched_qty) AS total_dispatched,
                SUM(cpl.remaining_qty) AS cumulative_remaining
           FROM dl_commissary_product_ledger cpl
           INNER JOIN dl_products p ON p.id = cpl.product_id AND p.is_active = 1
           LEFT JOIN dl_branches b ON b.id = cpl.commissary_branch_id
          GROUP BY cpl.commissary_branch_id, cpl.product_id, b.name, p.name, p.sku
         HAVING cumulative_remaining > 0
          ORDER BY p.name ASC"
    );
    $cumulativeStockStmt->execute();
    $cumulativeStock = $cumulativeStockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 2: Deliveries to branches ──
    $deliverySql = "SELECT d.id AS delivery_id,
                           d.delivery_date,
                           d.dr_number,
                           d.destination_id AS branch_id,
                           b.name AS branch_name,
                           di.product_id,
                           p.name AS product_name,
                           p.sku,
                           di.quantity,
                           d.status,
                           d.remarks,
                           d.created_at,
                           COALESCE(u.full_name, u.username, '') AS created_by_name
                    FROM dl_deliveries d
                    INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                    INNER JOIN dl_branches b ON b.id = d.destination_id
                    INNER JOIN dl_products p ON p.id = di.product_id
                    LEFT JOIN dl_users u ON u.id = d.created_by
                    WHERE d.origin_type = 'commissary'
                      AND d.destination_type = 'branch'
                      AND d.delivery_date = :date";
    $deliveryBind = [':date' => $rawDate];
    if ($selectedBranchId > 0) {
        $deliverySql .= ' AND d.destination_id = :branch';
        $deliveryBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $deliverySql .= ' AND d.origin_id = :cid';
        $deliveryBind[':cid'] = $selectedCommissaryId;
    }
    $deliverySql .= ' ORDER BY d.delivery_date DESC, b.name ASC, p.name ASC, d.id DESC';
    $deliveryStmt = $db->prepare($deliverySql);
    $deliveryStmt->execute($deliveryBind);
    $deliveryRows = $deliveryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 3: Pullouts / Returns to commissary ──
    $pulloutSql = "SELECT d.id AS delivery_id,
                          d.delivery_date,
                          d.dr_number,
                          d.origin_id AS from_branch_id,
                          COALESCE(ob.name, 'Branch') AS from_branch_name,
                          d.destination_id AS commissary_branch_id,
                          COALESCE(cb.name, 'Commissary') AS commissary_branch_name,
                          di.product_id,
                          p.name AS product_name,
                          p.sku,
                          di.quantity,
                          d.status,
                          d.remarks,
                          d.created_at
                   FROM dl_deliveries d
                   INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                   INNER JOIN dl_products p ON p.id = di.product_id
                   LEFT JOIN dl_branches ob ON ob.id = d.origin_id
                   INNER JOIN dl_branches cb ON cb.id = d.destination_id AND cb.is_commissary = 1
                   WHERE d.destination_type = 'branch'
                     AND d.origin_type = 'branch'
                     AND d.status = 'posted'
                     AND d.delivery_date = :date";
    $pulloutBind = [':date' => $rawDate];
    if ($selectedBranchId > 0) {
        $pulloutSql .= ' AND d.origin_id = :branch';
        $pulloutBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $pulloutSql .= ' AND d.destination_id = :cid';
        $pulloutBind[':cid'] = $selectedCommissaryId;
    }
    $pulloutSql .= ' ORDER BY d.delivery_date DESC, ob.name ASC, p.name ASC';
    $pulloutStmt = $db->prepare($pulloutSql);
    $pulloutStmt->execute($pulloutBind);
    $pulloutRows = $pulloutStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 4: Summary ──
    $summarySql = "SELECT p.id AS product_id,
                p.name AS product_name,
                p.sku,
                COALESCE(inv.beg_qty, 0) AS beg_qty,
                COALESCE(inv.produced_qty, 0) AS produced_qty,
                COALESCE(inv.dispatched_qty, 0) AS dispatched_qty,
                COALESCE(inv.wastage_qty, 0) AS wastage_qty,
                COALESCE(inv.remaining_qty, 0) AS remaining_qty,
                COALESCE(ret.returned_qty, 0) AS returned_qty,
                (COALESCE(inv.remaining_qty, 0) + COALESCE(ret.returned_qty, 0)) AS net_available
           FROM dl_products p
           LEFT JOIN (
               SELECT product_id,
                      SUM(beg_qty) AS beg_qty,
                      SUM(produced_qty) AS produced_qty,
                      SUM(dispatched_qty) AS dispatched_qty,
                      SUM(wastage_qty) AS wastage_qty,
                      SUM(remaining_qty) AS remaining_qty
                 FROM dl_commissary_product_ledger
                WHERE ledger_date = :date1";
    $summaryBind = [':date1' => $rawDate];
    if ($selectedCommissaryId > 0) {
        $summarySql .= ' AND commissary_branch_id = :cid';
        $summaryBind[':cid'] = $selectedCommissaryId;
    }
    $summarySql .= "
                GROUP BY product_id
           ) inv ON inv.product_id = p.id
           LEFT JOIN (
               SELECT di.product_id, SUM(di.quantity) AS returned_qty
                 FROM dl_deliveries d
                 INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
                 INNER JOIN dl_branches cb ON cb.id = d.destination_id AND cb.is_commissary = 1
                WHERE d.destination_type = 'branch'
                  AND d.origin_type = 'branch'
                  AND d.status = 'posted'
                  AND d.delivery_date = :date2
                  AND d.remarks NOT LIKE '%[cashier-pullout-return:wastage]%'";
    $summaryBind[':date2'] = $rawDate;
    if ($selectedBranchId > 0) {
        $summarySql .= ' AND d.origin_id = :branch';
        $summaryBind[':branch'] = $selectedBranchId;
    }
    if ($selectedCommissaryId > 0) {
        $summarySql .= ' AND d.destination_id = :cid2';
        $summaryBind[':cid2'] = $selectedCommissaryId;
    }
    $summarySql .= "
                GROUP BY di.product_id
           ) ret ON ret.product_id = p.id
          WHERE COALESCE(inv.produced_qty, 0) > 0
             OR COALESCE(inv.dispatched_qty, 0) > 0
             OR COALESCE(ret.returned_qty, 0) > 0
          ORDER BY p.name ASC";
    $summaryStmt = $db->prepare($summarySql);
    $summaryStmt->execute($summaryBind);
    $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Tab 5: Daily Sheet ──
    // The product set is exactly the cashier set for the selected production
    // branch (or the first active commissary when the all filter is used).
    $sheetSourceBranchId = $selectedCommissaryId;
    if ($sheetSourceBranchId <= 0 && $commissaries !== []) {
        $sheetSourceBranchId = (int)$commissaries[0]['id'];
    }
    $sheetSourceBranchName = '';
    foreach ($commissaries as $commissaryRow) {
        if ((int)($commissaryRow['id'] ?? 0) === $sheetSourceBranchId) {
            $sheetSourceBranchName = (string)($commissaryRow['name'] ?? '');
            break;
        }
    }
    // Branch column order = the order the admin set on each branch (lowest
    // number prints leftmost, matching the paper form). Unnumbered branches
    // (sort_order = 0) print AFTER all numbered ones so a newly added branch
    // can never jump to the front. With every branch still at 0 the leading
    // flag ties for every row, so the whole clause collapses to plain
    // alphabetical order -- i.e. exactly the pre-order behaviour.
    $sheetBranchesStmt = $db->query(
        'SELECT id, code, name FROM dl_branches WHERE is_active = 1
          ORDER BY (sort_order = 0) ASC, sort_order ASC, name ASC'
    );
    $sheetBranches = $sheetBranchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sheetProducts = dl_fetchProductionSheetProducts(
        $db,
        $selectedCommissaryId > 0 ? $sheetSourceBranchId : 0
    );

    $sheetDispatch = dl_fetchProductionSheetDispatchMatrix($db, $rawDate, $selectedCommissaryId, $shift);
    $sheetDispatchEntries = dl_fetchProductionSheetDispatchEntryFlags($db, $rawDate, $selectedCommissaryId, $shift);
    $sheetReceiving = dl_fetchProductionSheetReceivingMatrix($db, $rawDate, $selectedCommissaryId, $shift);

    $sheetLedgerSql = "SELECT product_id,
                              COUNT(*) AS ledger_row_count,
                              SUM(beg_qty) AS beg_qty,
                              SUM(produced_qty) AS addtl_qty,
                              SUM(wastage_qty) AS wastage_qty,
                              CASE
                                  WHEN COUNT(*) = SUM(actual_end_qty IS NOT NULL) THEN SUM(actual_end_qty)
                                  ELSE NULL
                              END AS actual_end_qty,
                              CASE
                                  WHEN COUNT(*) = SUM(actual_end_qty IS NOT NULL) THEN SUM(calc_variance)
                                  ELSE NULL
                              END AS calc_variance
                         FROM dl_commissary_product_ledger
                        WHERE ledger_date = :sheet_ledger_date";
    $sheetLedgerBind = [':sheet_ledger_date' => $rawDate];
    if ($shift !== null) {
        $sheetLedgerSql .= ' AND shift = :sheet_shift';
        $sheetLedgerBind[':sheet_shift'] = $shift;
    }
    if ($selectedCommissaryId > 0) {
        $sheetLedgerSql .= ' AND commissary_branch_id = :sheet_ledger_cid';
        $sheetLedgerBind[':sheet_ledger_cid'] = $selectedCommissaryId;
    }
    $sheetLedgerSql .= ' GROUP BY product_id';
    $sheetLedgerStmt = $db->prepare($sheetLedgerSql);
    $sheetLedgerStmt->execute($sheetLedgerBind);
    $sheetLedger = [];
    foreach ($sheetLedgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ledgerRow) {
        $sheetLedger[(int)$ledgerRow['product_id']] = $ledgerRow;
    }
    $sheetBegSuggestions = $shift === null
        ? []
        : dl_fetchCommissaryBeginningSuggestions($db, $sheetSourceBranchId, $rawDate, $shift);

    $recordedBegProducts = [];
    $begAudit = $db->prepare(
        'SELECT entity_id FROM audit_logs
          WHERE module = "daily-ledger" AND action = "save_commissary_product_beg"
            AND entity_id LIKE :entity_pattern'
    );
    $begEntitySuffix = $shift === null ? '%' : '-' . $shift;
    $begAudit->execute([':entity_pattern' => $sheetSourceBranchId . '-%-' . $rawDate . $begEntitySuffix]);
    foreach ($begAudit->fetchAll(PDO::FETCH_COLUMN) ?: [] as $entityId) {
        $shiftSuffixPattern = $shift === null ? '(?:-(?:AM|PM))?' : '-' . $shift;
        if (preg_match('/^' . preg_quote((string)$sheetSourceBranchId, '/') . '-(\d+)-' . preg_quote($rawDate, '/') . $shiftSuffixPattern . '$/', (string)$entityId, $match)) {
            $recordedBegProducts[(int)$match[1]] = true;
        }
    }

    $activeAddtlMovements = [];
    $movementSql = 'SELECT pm.id, pm.product_id
           FROM dl_production_movements pm
          WHERE pm.destination_branch_id = :bid AND pm.ledger_date = :d';
    $movementBind = [':bid' => $sheetSourceBranchId, ':d' => $rawDate];
    if ($shift !== null) {
        $movementSql .= ' AND pm.shift = :shift';
        $movementBind[':shift'] = $shift;
    }
    $movementSql .= ' AND pm.movement_type = "output"
            AND NOT EXISTS (
                SELECT 1 FROM dl_production_movements rev
                 WHERE rev.reference_movement_id = pm.id AND rev.movement_type = "reverse"
            )
          ORDER BY pm.id DESC';
    $movementStmt = $db->prepare($movementSql);
    $movementStmt->execute($movementBind);
    foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $movement) {
        $pid = (int)$movement['product_id'];
        if (!isset($activeAddtlMovements[$pid])) {
            $activeAddtlMovements[$pid] = (int)$movement['id'];
        }
    }

    $dailySheetRows = [];
    foreach ($sheetProducts as $product) {
        $productId = (int)$product['id'];
        $ledgerRow = $sheetLedger[$productId] ?? [];
        $branchCells = [];
        $total = 0;
        foreach ($sheetBranches as $sheetBranch) {
            $quantity = (int)($sheetDispatch[$productId][(int)$sheetBranch['id']] ?? 0);
            $total += $quantity;
            $branchId = (int)$sheetBranch['id'];
            // S12: the received side is read from the delivery/receiving ITEMS, never
            // from the exception-only dl_delivery_variance_flags and never through a
            // COALESCE that would call an unreceived delivery a full receipt. A cell
            // with no receiving row is pending, and its difference is null -- not 0.
            $receiving = $sheetReceiving[$productId][$branchId] ?? null;
            $receivedQty = $receiving['received'] ?? null;
            $receivedPending = $receiving !== null && !empty($receiving['pending']);
            $notIndependentlyCounted = $receiving !== null && !empty($receiving['not_independently_counted']);
            $countBasisUnresolved = $receiving !== null && !empty($receiving['count_basis_unresolved']);
            $deliveryDiff = (!$receivedPending && $receivedQty !== null)
                ? (($notIndependentlyCounted || $countBasisUnresolved) ? null : ($receivedQty - $quantity))
                : null;
            $drNumbers = $receiving['dr_numbers'] ?? [];
            $branchCells[] = [
                'branch_id' => $branchId,
                'branch_name' => (string)($sheetBranch['name'] ?? ''),
                'quantity' => $quantity,
                'has_entry' => isset($sheetDispatchEntries[$productId][$branchId]),
                'has_delivery' => $receiving !== null,
                'received_qty' => $receivedQty,
                'received_pending' => $receivedPending,
                'not_independently_counted' => $notIndependentlyCounted,
                'count_basis_unresolved' => $countBasisUnresolved,
                'origin_unresolved' => $receiving !== null && !empty($receiving['origin_unresolved']),
                'delivery_diff' => $deliveryDiff,
                'delivery_diff_display' => $deliveryDiff === null || $deliveryDiff === 0
                    ? ''
                    : (($deliveryDiff > 0 ? '+' : '') . $deliveryDiff),
                'delivery_short' => $deliveryDiff !== null && $deliveryDiff < 0,
                'delivery_over' => $deliveryDiff !== null && $deliveryDiff > 0,
                'dr_numbers' => $drNumbers,
                'dr_display' => implode(', ', $drNumbers),
            ];
        }
        $begSuggestion = (int)($sheetBegSuggestions[$productId] ?? 0);
        $begIsRecorded = isset($recordedBegProducts[$productId]);
        // An absent row is an unrecorded zero. Keep the preceding ending in its
        // own data attribute; rendering must never apply or persist the carry.
        $begQty = !empty($ledgerRow['ledger_row_count']) ? (int)($ledgerRow['beg_qty'] ?? 0) : 0;
        $addtlQty = (int)($ledgerRow['addtl_qty'] ?? 0);
        $wastageQty = (int)($ledgerRow['wastage_qty'] ?? 0);
        $actualEndQty = array_key_exists('actual_end_qty', $ledgerRow) && $ledgerRow['actual_end_qty'] !== null
            ? (int)$ledgerRow['actual_end_qty'] : null;
        $dailySheetRows[] = [
            'product_id' => $productId,
            'sheet_label' => dl_productionSheetLabel($product),
            'sku' => (string)($product['sku'] ?? ''),
            'beg_qty' => $begQty,
            'beg_suggestion' => $begSuggestion,
            'beg_is_recorded' => $begIsRecorded,
            'addtl_qty' => $addtlQty,
            'addtl_movement_id' => (int)($activeAddtlMovements[$productId] ?? 0),
            'output_pieces_per_batch' => (int)($product['output_pieces_per_batch'] ?? 0),
            'output_unit_label' => (string)($product['output_unit_label'] ?? 'pcs'),
            'batch_count' => (int)($product['output_pieces_per_batch'] ?? 0) > 0 && $addtlQty > 0
                ? round($addtlQty / (int)$product['output_pieces_per_batch'], 2)
                : null,
            'branch_cells' => $branchCells,
            'total_qty' => $total,
            // Wastage is genuinely gone, so it must not be expected on the shelf.
            // Subtracting it makes this suggestion share ONE basis with the DB's
            // calc_variance (migration 064). Omitting it made the suggestion
            // over-read by the wastage amount while the variance stayed correct,
            // which read as a contradiction on screen.
            'book_balance' => $begQty + $addtlQty - $total - $wastageQty,
            'wastage_qty' => $wastageQty,
            'actual_end_qty' => $actualEndQty,
            // The DiSyL conditional `!== null` is unreliable, so the displayed
            // value is resolved in PHP: the count when present, else the suggestion.
            'actual_input_value' => $actualEndQty !== null ? $actualEndQty : ($begQty + $addtlQty - $total - $wastageQty),
            'calc_variance' => $actualEndQty === null || !array_key_exists('calc_variance', $ledgerRow)
                ? null : (int)$ledgerRow['calc_variance'],
            // Explicit flag keeps interpreted and compiled DiSyL null checks identical.
            'calc_variance_present' => $actualEndQty !== null && array_key_exists('calc_variance', $ledgerRow),
        ];
    }

    // Historical NULL-shift rows remain explicitly unshifted; they are never
    // assigned to AM or PM. The selected shift only reads shift-keyed rows.
    $legacySheetStmt = $db->prepare('SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = :cb AND ledger_date = :d AND shift IS NULL');
    $legacySheetStmt->execute([':cb' => $sheetSourceBranchId, ':d' => $rawDate]);
    $historicalUnshiftedCount = (int)$legacySheetStmt->fetchColumn();

    // Request-triggered PM auto-close for the viewed commissary+date. There is
    // no cron: if nobody opens the sheet, nothing auto-finalizes. Idempotent.
    // Only run it for a branch the actor may access, so a GET never mutates a
    // branch outside the actor's scope.
    if ($sheetSourceBranchId > 0 && in_array($sheetSourceBranchId, dl_accessibleBranchIds($user), true)) {
        dl_maybeAutoFinalizeCommissaryPmShift($sheetSourceBranchId, $rawDate, dl_getActorUserId($user));
    }

    // A PM day the auto-close flagged as closed-without-finalize. This is a
    // NOTIFICATION only (R3): it exposes the existing flag (R1) as data (R4)
    // and never guards, refuses or locks any write. Read pending_notified_at
    // from the viewed date+commissary's PM shift row, reusing dl_getShiftStatus().
    // R6: the read MUST happen AFTER the request-triggered auto-close above,
    // because that evaluator runs ON RENDER and can FLAG this very day. Reading
    // before it would show no warning on the render that flags the day, so the
    // admin would only see the banner after a reload. A finalized shift is a
    // completed day, not a flagged one, even if a stale flag remains.
    $pmFlag = null;
    if ($sheetSourceBranchId > 0) {
        $pmShiftRow = dl_getShiftStatus($db, $sheetSourceBranchId, $rawDate, 'PM');
        if ($pmShiftRow !== null
            && ($pmShiftRow['pending_notified_at'] ?? null) !== null
            && (string)($pmShiftRow['status'] ?? '') !== 'finalized') {
            $pmFlag = [
                'date' => $rawDate,
                'at' => (string)$pmShiftRow['pending_notified_at'],
            ];
        }
    }

    $shiftRow = $shift === null ? null : dl_getShiftStatus($db, $sheetSourceBranchId, $rawDate, $shift);
    $shiftStatus = $shift === null ? 'unshifted' : ($shiftRow ? (string)$shiftRow['status'] : 'open');

    // A prior pending PM day the sheet's own operators can still recover. Gated on
    // the sheet's roles, not the cashier's $role === 'cashier'.
    $priorPendingDay = null;
    if (in_array($role, ['admin', 'supervisor', 'production_in_charge'], true) && $sheetSourceBranchId > 0) {
        $priorDate = dl_priorPendingPmDay($db, (int)$sheetSourceBranchId, $today, $rawDate);
        if ($priorDate !== null) {
            $priorPendingDay = ['date' => $priorDate];
        }
    }

    // The management log is intentionally not exposed to production_in_charge.
    $productionLog = ($user['role'] ?? '') === 'admin'
        ? dl_fetchProductionLedgerLog($db, $sheetSourceBranchId, $rawDate)
        : [];

    // Aggregate totals
    $totals = [
        'total_produced' => 0,
        'total_dispatched' => 0,
        'total_wastage' => 0,
        'total_returned' => 0,
        'total_remaining' => 0,
        'total_deliveries' => count($deliveryRows),
        'total_pullouts' => count($pulloutRows),
    ];
    foreach ($inventoryRows as $row) {
        $totals['total_produced'] += (int)($row['produced_qty'] ?? 0);
        $totals['total_dispatched'] += (int)($row['dispatched_qty'] ?? 0);
        $totals['total_wastage'] += (int)($row['wastage_qty'] ?? 0);
        $totals['total_remaining'] += (int)($row['remaining_qty'] ?? 0);
    }
    foreach ($pulloutRows as $row) {
        // Wastage returns are physically moved but already counted as
        // wastage_qty; they are not saleable returned stock.
        if (str_contains((string)($row['remarks'] ?? ''), '[cashier-pullout-return:wastage]')) {
            continue;
        }
        $totals['total_returned'] += (int)($row['quantity'] ?? 0);
    }

    // Branch-level dispatch summary for deliveries tab
    $branchDispatchSummary = [];
    foreach ($deliveryRows as $row) {
        $bid = (int)($row['branch_id'] ?? 0);
        if (!isset($branchDispatchSummary[$bid])) {
            $branchDispatchSummary[$bid] = [
                'branch_id' => $bid,
                'branch_name' => (string)($row['branch_name'] ?? ''),
                'total_qty' => 0,
                'delivery_count' => 0,
                'dr_numbers' => [],
            ];
        }
        $branchDispatchSummary[$bid]['total_qty'] += (int)($row['quantity'] ?? 0);
        $dr = trim((string)($row['dr_number'] ?? ''));
        if ($dr !== '' && !in_array($dr, $branchDispatchSummary[$bid]['dr_numbers'], true)) {
            $branchDispatchSummary[$bid]['dr_numbers'][] = $dr;
        }
    }
    // Count unique delivery IDs per branch
    foreach ($branchDispatchSummary as $bid => &$bs) {
        $uniqueDeliveryIds = [];
        foreach ($deliveryRows as $row) {
            if ((int)($row['branch_id'] ?? 0) === $bid) {
                $uniqueDeliveryIds[(int)($row['delivery_id'] ?? 0)] = true;
            }
        }
        $bs['delivery_count'] = count($uniqueDeliveryIds);
    }
    unset($bs);

    echo dlRender('modules/daily-ledger/admin/commissary.disyl', [
        'page_title' => 'Commissary',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'current_page' => 'commissary',
        'user' => $user,
        'user_name' => $user['full_name'] ?? $user['username'] ?? 'User',
        'user_role' => $user['role'] ?? 'unknown',
        'date' => $rawDate,
        'today' => $today,
        'shift' => $shift,
        'settled_summary' => dl_settledEndingSummary($db, (int)$sheetSourceBranchId, (string)$rawDate, $shift, $role, true),
        'shift_locked' => $shiftLocked,
        'close_of_day_time' => dl_operatingClockLabel()['close_of_day_time'],
        'shift_status' => $shiftStatus,
        'prior_pending_day' => $priorPendingDay,
        'pm_flag' => $pmFlag,
        'day_status' => $sheetSourceBranchId > 0 ? dl_getDayStatus($sheetSourceBranchId, $rawDate) : 'open',
        'production_reference_only' => !in_array($role, ['admin', 'supervisor', 'production_in_charge'], true) || $shift === null,
        'historical_unshifted_count' => $historicalUnshiftedCount,
        'can_view_production_management' => $canViewProductionManagement,
        'can_close_day' => $canCloseDay,
        'can_reopen_day' => $canReopenDay,
        'can_view_production_variance' => $role === 'admin',
        'branches' => $branches,
        'commissaries' => $commissaries,
        'branch_id' => $selectedBranchId,
        'commissary_id' => $selectedCommissaryId,
        'inventory_rows' => $inventoryRows,
        'delivery_rows' => $deliveryRows,
        'pullout_rows' => $pulloutRows,
        'summary_rows' => $summaryRows,
        'daily_sheet_rows' => $dailySheetRows,
        'sheet_branches' => $sheetBranches,
        'sheet_source_branch_id' => $sheetSourceBranchId,
        'sheet_source_branch_name' => $sheetSourceBranchName,
        'production_log' => $productionLog,
        'production_can_override' => dl_roleHasPermission((string)($user['role'] ?? ''), 'production.override'),
        'unresolved_sheet_labels' => dl_unresolvedProductionSheetLabels(),
        'branch_dispatch_summary' => array_values($branchDispatchSummary),
        'cumulative_stock' => $cumulativeStock,
        'totals' => $totals,
    ]);
}

/**
 * Save the Production Output ledger's absolute ADDTL value while retaining the
 * existing production-run yield capture. This is stock-only production: branch
 * dispatch remains read-only and no delivery or branch daily-ledger row is written.
 */
function dl_saveProductionOutputLedgerCell(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $db = $ctx->db();
    $date = (string)($input['date'] ?? '');
    $commissaryBranchId = (int)($input['commissary_branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $producedQty = (int)($input['yield_qty'] ?? -1);
    $actorId = dl_getActorUserId($user);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || $commissaryBranchId <= 0
        || $productId <= 0
        || $producedQty < 0
        || $producedQty > 999999999) {
        throw new \RuntimeException('Invalid production ledger data.');
    }
    if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Commissary is not allowed for this user.');
    }
    $valid = $db->prepare(
        'SELECT COUNT(*)
           FROM dl_branches b
           INNER JOIN dl_branch_products bp
             ON bp.branch_id = b.id AND bp.product_id = :pid AND bp.is_active = 1
           INNER JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
          WHERE b.id = :bid AND b.is_active = 1 AND b.is_commissary = 1'
    );
    $valid->execute([':pid' => $productId, ':bid' => $commissaryBranchId]);
    if ((int)$valid->fetchColumn() !== 1) {
        throw new \RuntimeException('Product is not active for this commissary.');
    }

    $db->beginTransaction();
    try {
        $currentStmt = $db->prepare(
            'SELECT produced_qty
               FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :bid AND product_id = :pid AND ledger_date = :d
              LIMIT 1 FOR UPDATE'
        );
        $currentStmt->execute([':bid' => $commissaryBranchId, ':pid' => $productId, ':d' => $date]);
        $currentRaw = $currentStmt->fetchColumn();
        $currentProduced = $currentRaw === false ? 0 : (int)$currentRaw;
        if ($currentProduced > 0 && $currentProduced !== $producedQty) {
            throw new \RuntimeException('Recorded ADDTL must be changed through Correct entry.');
        }

        $runStmt = $db->prepare(
            'SELECT id, baker_name, primary_input_qty
               FROM dl_production_runs
              WHERE ledger_date = :d AND product_id = :pid AND destination_branch_id = :bid
              ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $runStmt->execute([':d' => $date, ':pid' => $productId, ':bid' => $commissaryBranchId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($run) {
            if ($producedQty === 0
                && trim((string)$run['baker_name']) === ''
                && (float)$run['primary_input_qty'] <= 0) {
                $db->prepare('DELETE FROM dl_production_runs WHERE id = :id')
                    ->execute([':id' => (int)$run['id']]);
            } else {
                $db->prepare(
                    'UPDATE dl_production_runs
                        SET yield_qty = :qty, recorded_by = :uid
                      WHERE id = :id'
                )->execute([
                    ':qty' => $producedQty,
                    ':uid' => $actorId > 0 ? $actorId : null,
                    ':id' => (int)$run['id'],
                ]);
            }
        } elseif ($producedQty > 0) {
            $db->prepare(
                "INSERT INTO dl_production_runs
                    (ledger_date, product_id, baker_name, run_type, primary_input_qty,
                     primary_input_type, yield_qty, destination_branch_id, recorded_by)
                 VALUES (:d, :pid, '', 'regular', 0, 'kilo', :qty, :bid, :uid)"
            )->execute([
                ':d' => $date,
                ':pid' => $productId,
                ':qty' => $producedQty,
                ':bid' => $commissaryBranchId,
                ':uid' => $actorId > 0 ? $actorId : null,
            ]);
        }

        if ($currentProduced === 0 && $producedQty > 0) {
            $submissionId = dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''));
            dl_processProductionMovement($user, 'output', [
                'destination_branch_id' => $commissaryBranchId,
                'product_id' => $productId,
                'quantity' => $producedQty,
                'ledger_date' => $date,
                'flow_mode' => 'production',
                'client_op_id' => 'sheet-addtl-' . hash('sha256', $submissionId),
                'reason' => 'Daily Sheet ADDTL recording',
                'submission_id' => $submissionId,
            ]);
        } elseif ($producedQty > 0) {
            dl_ensureCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $actorId);
        }

        $read = $db->prepare(
            'SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, remaining_qty,
                    actual_end_qty, calc_variance,
                    (beg_qty + produced_qty - dispatched_qty - wastage_qty) AS book_balance
               FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = :bid AND product_id = :pid AND ledger_date = :d
              LIMIT 1'
        );
        $read->execute([':bid' => $commissaryBranchId, ':pid' => $productId, ':d' => $date]);
        $row = $read->fetch(PDO::FETCH_ASSOC) ?: [
            'beg_qty' => 0,
            'produced_qty' => 0,
            'dispatched_qty' => 0,
            'wastage_qty' => 0,
            'remaining_qty' => 0,
            'actual_end_qty' => null,
            'calc_variance' => null,
            'book_balance' => 0,
        ];
        $db->commit();
        return $row;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Save a production run (commissary usage row).
 *
 * Extracted from apiSaveProductionRun() so the full transactional behavior can
 * be exercised directly in integration tests. Handles:
 *   - create / update / delete of dl_production_runs
 *   - the commissary → production-movement bridge (non-formal mode)
 *   - same-location internal releases when formal delivery is enabled (DR-less
 *     self-managed commissary/storefront output)
 *   - formal cross-location delivery synchronization when formal mode is on
 *
 * Returns a structured result; throws RuntimeException for controlled
 * validation failures and any other Throwable is a database error.
 */
function dl_saveProductionRun(array $user, array $input): array
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }
    $db = $ctx->db();

    $date          = (string)($input['date'] ?? '');
    $productId     = (int)($input['product_id'] ?? 0);
    $bakerName     = trim((string)($input['baker_name'] ?? ''));
    $type          = 'regular'; // default
    $kiloQty   = (float)($input['kilo_qty'] ?? 0);
    $eggQty    = (float)($input['egg_qty'] ?? 0);
    // Egg takes precedence when kilo is absent; kilo is the default
    if ($eggQty > 0 && $kiloQty <= 0) {
        $inputQty  = $eggQty;
        $inputType = 'egg';
    } else {
        $inputQty  = $kiloQty;
        $inputType = 'kilo';
    }
    $yieldQty      = (int)($input['yield_qty'] ?? 0);
    $destBranchId  = (int)($input['destination_branch_id'] ?? 0);
    $drNumber      = trim((string)($input['dr_number'] ?? ''));
    if ($drNumber !== '') {
        $drNumber = substr($drNumber, 0, 120);
    }

    $actorId = dl_getActorUserId($user);

    $db->beginTransaction();
    try {

        if ($destBranchId > 0) {
            $stmt = $db->prepare(
                "SELECT id, commissary_movement_id, destination_branch_id, dr_number
                 FROM dl_production_runs
                 WHERE ledger_date = :d AND product_id = :p AND destination_branch_id = :dest
                 LIMIT 1"
            );
            $stmt->execute([':d' => $date, ':p' => $productId, ':dest' => $destBranchId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, commissary_movement_id, destination_branch_id, dr_number
                 FROM dl_production_runs
                 WHERE ledger_date = :d AND product_id = :p AND destination_branch_id IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':d' => $date, ':p' => $productId]);
        }
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $previousDestBranchId = $existing ? (int)($existing['destination_branch_id'] ?? 0) : 0;
        $previousDrNumber = $existing ? trim((string)($existing['dr_number'] ?? '')) : '';
        $formalDeliveryEnabled = dl_isFormalDeliveryEnabled();

        // ─── Same-location internal-release eligibility ──────────────────────
        // Derived ONLY from authoritative branch + product-supply configuration
        // (never from a browser-supplied boolean, name, or address). A DR may be
        // omitted only when the destination is an active commissary that produces
        // the product locally (self-managed / self-referencing / local override).
        $sameLocationDecision = null;
        if ($destBranchId > 0 && $productId > 0) {
            $sameLocationDecision = dl_resolveSameLocationEligibility($destBranchId, $productId);
        }
        $isSameLocationEligible = $sameLocationDecision !== null
            && !empty($sameLocationDecision['same_location']);
        $isSameLocationRelease = $formalDeliveryEnabled
            && $destBranchId > 0
            && $yieldQty > 0
            && $isSameLocationEligible;

        // Same-location releases must respect the same branch-authorization,
        // active-branch, and closed-day gates as production output. They must
        // never create a formal delivery from a branch to itself.
        if ($formalDeliveryEnabled && $destBranchId > 0 && $isSameLocationEligible) {
            $role = (string)($user['role'] ?? '');
            $allowedBranchIds = dl_accessibleBranchIds($user);
            if (!in_array($destBranchId, $allowedBranchIds, true)) {
                throw new RuntimeException('Destination branch is not allowed for this user.');
            }
            dl_maybeAutoCloseBranchDay($destBranchId, $actorId);
            $dayStatus = dl_getDayStatus($destBranchId, $date);
            if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'production.override')) {
                throw new RuntimeException('Day is closed for this branch.');
            }
        }

        if ($formalDeliveryEnabled && $destBranchId > 0 && $yieldQty > 0 && $drNumber === '' && !$isSameLocationEligible) {
            throw new RuntimeException('Delivery Receipt number is required for branch-directed commissary output.');
        }
        if ($formalDeliveryEnabled && $destBranchId > 0 && $yieldQty > 0 && $isSameLocationEligible && $drNumber !== '') {
            throw new RuntimeException('This branch is a co-located commissary — use Internal release (same location) and leave the DR blank instead of creating a delivery to itself.');
        }

        if ($bakerName === '' && $yieldQty <= 0 && $inputQty <= 0) {
            if ($existing) {
                $db->prepare("DELETE FROM dl_production_runs WHERE id = ?")->execute([$existing['id']]);
            }
        } elseif ($existing) {
            $stmt = $db->prepare(
                "UPDATE dl_production_runs
                 SET baker_name = :baker, primary_input_qty = :iqty, primary_input_type = :itype, yield_qty = :yqty, dr_number = :dr, destination_branch_id = :dest, recorded_by = :actor
                 WHERE id = :id"
            );
            $stmt->execute([
                ':baker' => $bakerName,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dr'    => $drNumber !== '' ? $drNumber : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
                ':id'    => $existing['id'],
            ]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO dl_production_runs (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type, yield_qty, dr_number, destination_branch_id, recorded_by)
                 VALUES (:date, :pid, :baker, :type, :iqty, :itype, :yqty, :dr, :dest, :actor)"
            );
            $stmt->execute([
                ':date'  => $date,
                ':pid'   => $productId,
                ':baker' => $bakerName,
                ':type'  => $type,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dr'    => $drNumber !== '' ? $drNumber : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
            ]);
        }

        // Determine the saved run id (needed to update commissary_movement_id)
        $runId = $existing ? (int)$existing['id'] : (int)$db->lastInsertId();

        // ─── Commissary → production-movement bridge ───────────────────────────
        // When a destination branch and a yield qty are set, auto-create/update a
        // dl_production_movements 'output' record so the branch ledger (addtl) is
        // kept in sync without the production_in_charge having to encode it twice.
        //
        // Logic:
        //  - priorBridgeId: the movement this run created on the LAST save (if any)
        //  - If that movement was manually reversed (by supervisor/admin) we leave it
        //    alone and re-bridge from scratch for the new values.
        //  - If it was NOT reversed but values changed: reverse it and re-apply.
        //  - If values are identical to last save: no-op.
        //  - Deletion of the run: reverse any outstanding bridge movement.

        $priorBridgeId = $existing ? ((int)($existing['commissary_movement_id'] ?? 0) ?: null) : null;
        $isRunDeleted  = ($bakerName === '' && $yieldQty <= 0 && $inputQty <= 0);
        $newBridgeId   = null;
        $role          = (string)($user['role'] ?? '');

        // The ledger bridge (production movement + storefront addtl) is used when
        // formal delivery is disabled OR when this save is an eligible same-location
        // internal release (which must never route through dl_deliveries).
        $useLedgerBridge = !$formalDeliveryEnabled || $isSameLocationRelease;

        // Local closure: undo a prior bridge movement. Internal releases also
        // reverse the commissary produced/dispatched ledger so the same pieces are
        // not left available for a second dispatch.
        $reverseBridge = function (int $refBridgeId, array $prior, bool $priorInternal, string $overrideReason) use ($db, $productId, $actorId, $role): void {
            dl_applyLedgerDelta((int)$prior['branch'], $productId, (string)$prior['date'], -((int)$prior['qty']), $actorId, 'addtl');
            if ($priorInternal) {
                dl_applyCommissaryProductLedgerDelta($db, (int)$prior['branch'], $productId, (string)$prior['date'], -((int)$prior['qty']), -((int)$prior['qty']), $actorId);
            }
            $revUuid = dl_generateMovementUuid();
            $db->prepare(
                "INSERT INTO dl_production_movements
                    (movement_uuid, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, quantity, dr_number,
                     override_reason, reference_movement_id, source_payload,
                     created_by_id, created_by_role)
                 VALUES
                    (:uuid, 'reverse', 'commissary',
                     :bid, :pid, :ldate, :qty, :dr,
                     :reason, :refid, :payload,
                     :uid, :role)"
            )->execute([
                ':uuid'    => $revUuid,
                ':bid'     => (int)$prior['branch'],
                ':pid'     => $productId,
                ':ldate'   => (string)$prior['date'],
                ':qty'     => (int)$prior['qty'],
                ':dr'      => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
                ':reason'  => $overrideReason,
                ':refid'   => $refBridgeId,
                ':payload' => json_encode([
                    'commissary_bridge' => true,
                    'auto_reverse' => true,
                    'same_location_internal_release' => $priorInternal,
                    'dr_number' => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
                ], JSON_UNESCAPED_SLASHES),
                ':uid'     => $actorId > 0 ? $actorId : null,
                ':role'    => $role !== '' ? $role : 'unknown',
            ]);
            dl_auditLog('reverse_commissary_run', (int)$prior['branch'] ?: null, 'dl_production_movements', (string)$revUuid, null, [
                'source_branch_id' => (int)$prior['branch'],
                'product_id' => $productId,
                'ledger_date' => (string)$prior['date'],
                'old_quantity' => (int)$prior['qty'],
                'new_quantity' => 0,
                'same_location_internal_release' => $priorInternal,
                'reference_movement_id' => $refBridgeId,
                'dr_number' => $priorInternal ? null : (($prior['dr'] ?? '') !== '' ? (string)$prior['dr'] : null),
            ]);
        };

        if ($priorBridgeId !== null) {
            // Was the prior bridge movement already manually reversed?
            $revChk = $db->prepare(
                "SELECT id FROM dl_production_movements WHERE reference_movement_id = :rid AND movement_type = 'reverse' LIMIT 1"
            );
            $revChk->execute([':rid' => $priorBridgeId]);
            $alreadyReversed = (bool)$revChk->fetchColumn();

            if (!$alreadyReversed) {
                $priorMoveStmt = $db->prepare(
                    'SELECT destination_branch_id, quantity, ledger_date, dr_number, source_payload FROM dl_production_movements WHERE id = :id LIMIT 1'
                );
                $priorMoveStmt->execute([':id' => $priorBridgeId]);
                $priorMove = $priorMoveStmt->fetch(PDO::FETCH_ASSOC);

                if ($priorMove) {
                    $priorPayload = json_decode((string)($priorMove['source_payload'] ?? '{}'), true);
                    $priorInternal = !empty($priorPayload['same_location_internal_release']);
                    $prior = [
                        'branch' => (int)$priorMove['destination_branch_id'],
                        'qty'    => (int)$priorMove['quantity'],
                        'date'   => (string)$priorMove['ledger_date'],
                        'dr'     => trim((string)($priorMove['dr_number'] ?? '')),
                    ];

                    $sameQuantities = !$isRunDeleted
                        && $prior['branch'] === $destBranchId
                        && $prior['qty']    === $yieldQty
                        && $prior['date']   === $date
                        && $destBranchId > 0
                        && $yieldQty    > 0;

                    if ($formalDeliveryEnabled && !$isSameLocationRelease) {
                        // Current save is a formal cross-location output. Undo
                        // whatever the prior bridge created (a legacy non-formal
                        // bridge or a prior same-location internal release) before
                        // the formal delivery is (re)synchronized below.
                        $reverseBridge($priorBridgeId, $prior, $priorInternal, $priorInternal ? 'commissary-internal-release-reverse' : 'commissary-formal-delivery');
                        $newBridgeId = null;
                    } elseif ($isSameLocationRelease) {
                        // Current save is a same-location internal release.
                        if ($sameQuantities && $priorInternal && $prior['dr'] === '') {
                            // Identical to the previous internal release — idempotent no-op.
                            $newBridgeId = $priorBridgeId;
                        } else {
                            $reverseBridge($priorBridgeId, $prior, $priorInternal, $priorInternal ? 'commissary-internal-release-reverse' : 'commissary-bridge-update');
                            $newBridgeId = null;
                        }
                    } elseif ($sameQuantities && $prior['dr'] !== $drNumber) {
                        $db->prepare(
                            'UPDATE dl_production_movements SET dr_number = :dr, source_payload = :payload WHERE id = :id'
                        )->execute([
                            ':dr' => $drNumber !== '' ? $drNumber : null,
                            ':payload' => json_encode(['commissary_bridge' => true, 'dr_number' => $drNumber !== '' ? $drNumber : null], JSON_UNESCAPED_SLASHES),
                            ':id' => $priorBridgeId,
                        ]);
                        $newBridgeId = $priorBridgeId;
                    } elseif (!$sameQuantities) {
                        $reverseBridge($priorBridgeId, $prior, false, 'commissary-bridge-update');
                        $newBridgeId = null;
                    } else {
                        // Identical values — keep the existing bridge movement
                        $newBridgeId = $priorBridgeId;
                    }
                }
            }
            // If already manually reversed: fall through and re-bridge below if applicable
        }

        // Create a new bridge movement when the run has a destination + yield.
        // Applies for non-formal mode AND same-location internal releases.
        if ($useLedgerBridge && $newBridgeId === null && !$isRunDeleted && $destBranchId > 0 && $yieldQty > 0) {
            $ledgerState = dl_applyLedgerDelta($destBranchId, $productId, $date, $yieldQty, $actorId, 'addtl');

            if ($isSameLocationRelease) {
                // Record produced + dispatched so the same pieces are not left
                // available for a second dispatch from the commissary.
                dl_applyCommissaryProductLedgerDelta($db, $destBranchId, $productId, $date, $yieldQty, $yieldQty, $actorId);
            }

            $newMoveUuid = dl_generateMovementUuid();
            $newPayload = $isSameLocationRelease
                ? json_encode(['commissary_bridge' => true, 'same_location_internal_release' => true, 'dr_number' => null, 'source_branch_id' => $destBranchId], JSON_UNESCAPED_SLASHES)
                : json_encode(['commissary_bridge' => true, 'dr_number' => $drNumber !== '' ? $drNumber : null], JSON_UNESCAPED_SLASHES);
            $db->prepare(
                "INSERT INTO dl_production_movements
                    (movement_uuid, movement_type, flow_mode,
                     destination_branch_id, product_id, ledger_date, quantity, dr_number,
                     source_payload, created_by_id, created_by_role)
                 VALUES
                    (:uuid, 'output', 'commissary',
                     :bid, :pid, :ldate, :qty, :dr,
                     :payload, :uid, :role)"
            )->execute([
                ':uuid'    => $newMoveUuid,
                ':bid'     => $destBranchId,
                ':pid'     => $productId,
                ':ldate'   => $date,
                ':qty'     => $yieldQty,
                ':dr'      => $isSameLocationRelease ? null : ($drNumber !== '' ? $drNumber : null),
                ':payload' => $newPayload,
                ':uid'     => $actorId > 0 ? $actorId : null,
                ':role'    => $role !== '' ? $role : 'unknown',
            ]);
            $newBridgeId = (int)$db->lastInsertId();
        }

        // Persist the bridge movement id back onto the run (NULL when run deleted / keep-in-commissary)
        if ($formalDeliveryEnabled) {
            $syncKeys = [];
            if ($previousDestBranchId > 0 && $previousDrNumber !== '') {
                $syncKeys[] = $previousDestBranchId . '|' . $date . '|' . $previousDrNumber;
            }
            if (!$isRunDeleted && $destBranchId > 0 && $drNumber !== '' && !$isSameLocationRelease) {
                $syncKeys[] = $destBranchId . '|' . $date . '|' . $drNumber;
            }
            foreach (array_values(array_unique($syncKeys)) as $syncKey) {
                [$syncBranchId, $syncDate, $syncDrNumber] = explode('|', $syncKey, 3);
                dl_syncAutoCommissaryDeliveryFromRuns($db, $syncDate, (int)$syncBranchId, $syncDrNumber, $actorId);
            }
            // Same-location internal releases keep their bridge movement (never a
            // self-delivery); formal cross-location output never keeps a bridge.
            if (!$isSameLocationRelease) {
                $newBridgeId = null;
            }
        }

        if ($runId > 0 && !$isRunDeleted) {
            $db->prepare("UPDATE dl_production_runs SET commissary_movement_id = :mid WHERE id = :id")
               ->execute([':mid' => $newBridgeId, ':id' => $runId]);
        }

        $db->commit();

        // Post-commit audit is best-effort: a failure here must never turn an
        // already-committed save into a 500 response (false failure). The
        // transaction is already durable; only observability is lost.
        $resultingAddtl = null;
        try {
            // Resulting storefront addtl for the affected branch/date (audit evidence).
            // Aggregate across shift-period rows (day-level addtl = AM + PM).
            $auditAddtlBranch = $destBranchId > 0 ? $destBranchId : ($previousDestBranchId > 0 ? $previousDestBranchId : 0);
            if ($auditAddtlBranch > 0 && $productId > 0 && $date !== '') {
                $lStmt = $db->prepare('SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d');
                $lStmt->execute([':b' => $auditAddtlBranch, ':p' => $productId, ':d' => $date]);
                $lVal = $lStmt->fetchColumn();
                $resultingAddtl = $lVal === false ? null : (int)$lVal;
            }

            $auditAction = $isRunDeleted ? 'delete_commissary_run' : ($existing ? 'update_commissary_run' : 'create_commissary_run');
            dl_auditLog($auditAction, $destBranchId > 0 ? $destBranchId : null, 'dl_production_runs', "{$date}-{$productId}-" . ($destBranchId > 0 ? $destBranchId : 'commissary'), null, [
                'date'        => $date,
                'product_id'  => $productId,
                'baker_name'  => $bakerName,
                'input_qty'   => $inputQty,
                'input_type'  => $inputType,
                'yield_qty'   => $yieldQty,
                'dr_number'   => $drNumber,
                'dest_branch' => $destBranchId,
                'source_branch_id' => $sameLocationDecision !== null ? ($sameLocationDecision['source_branch_id'] ?? null) : null,
                'same_location_internal_release' => $isSameLocationRelease,
                'movement_id' => $isRunDeleted ? null : $newBridgeId,
                'resulting_addtl' => $resultingAddtl,
            ]);
        } catch (Throwable $e) {
            write_log('apiSaveProductionRun post-commit audit error: ' . $e->getMessage(), 'warning');
        }

        return [
            'ok' => true,
            'run_id' => $runId,
            'movement_id' => $isRunDeleted ? null : $newBridgeId,
            'same_location_internal_release' => $isSameLocationRelease,
            'resulting_addtl' => $resultingAddtl,
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function apiFinalizeProductionPmShift(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'production_in_charge']);
    $input = $ctx->input();
    $branchId = (int)($input['commissary_branch_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    if ($branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !in_array($branchId, dl_accessibleBranchIds($user), true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid or unauthorized commissary/date.'], 422);
        return;
    }
    $db = $ctx->db();
    $db->beginTransaction();
    try {
        $status = dl_lockShiftStatusRow($db, $branchId, $date, 'PM');
        if ((string)$status['status'] !== 'finalized') {
            $missing = $db->prepare(
                'SELECT p.id AS product_id, p.name, p.sku FROM dl_products p
                 INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
                 LEFT JOIN dl_commissary_product_ledger cpl ON cpl.product_id = p.id AND cpl.commissary_branch_id = :bid2 AND cpl.ledger_date = :d AND cpl.shift = "PM"
                 WHERE p.is_active = 1 AND (cpl.id IS NULL OR cpl.actual_end_qty IS NULL)
                 ORDER BY p.sort_order, p.name'
            );
            $missing->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $date]);
            $missingProducts = $missing->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($missingProducts !== []) {
                $db->rollBack();
                $ctx->json([
                    'ok' => false,
                    'code' => 'PM_ENDING_MISSING',
                    'error' => count($missingProducts) . ' active product(s) are missing a PM ending count.',
                    'missing_products' => array_map(static function (array $m): array {
                        return ['product_id' => (int)$m['product_id'], 'name' => (string)$m['name'], 'sku' => (string)($m['sku'] ?? '')];
                    }, $missingProducts),
                ], 422);
                return;
            }
            $db->prepare('UPDATE dl_ledger_shift_status SET status = "finalized", finalized_by = :uid, finalized_at = CURRENT_TIMESTAMP WHERE branch_id = :bid AND ledger_date = :d AND shift = "PM"')
                ->execute([':uid' => dl_getActorUserId($user) ?: null, ':bid' => $branchId, ':d' => $date]);
            dl_auditLog('finalize_production_shift', $branchId, 'dl_ledger_shift_status', "{$branchId}-{$date}-PM", ['status' => 'open'], ['status' => 'finalized']);
        }
        $db->commit();
        $ctx->json(['ok' => true, 'finalized' => true]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
    }
}

/**
 * Shared parser for the three explicit ending-settlement endpoints (R9).
 *
 * @return array{branch_id:int,date:string,shift:string,production:bool}
 */
function dl_endingSettlementRequest(array $user, array $input): array
{
    $production = !array_key_exists('production', $input)
        ? true
        : (bool)filter_var($input['production'], FILTER_VALIDATE_BOOLEAN);
    $branchId = (int)($input['commissary_branch_id'] ?? $input['branch_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    if ($branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !in_array($branchId, dl_accessibleBranchIds($user), true)) {
        throw new \RuntimeException('Invalid or unauthorized branch/date.');
    }
    return [
        'branch_id' => $branchId,
        'date' => $date,
        'shift' => dl_normalizeShift((string)($input['shift'] ?? 'AM')),
        'production' => $production,
    ];
}

function apiSettleCommissaryEndings(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    try {
        $request = dl_endingSettlementRequest($user, $ctx->input());
        $result = dl_settlePendingEndingsForShift(
            $ctx->db(), $request['branch_id'], $request['date'], $request['shift'], $user, $request['production']
        );
        $ctx->json(['ok' => true] + $result);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
    }
}

function apiVerifyCommissaryEndings(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin']);
    try {
        $request = dl_endingSettlementRequest($user, $ctx->input());
        $result = dl_verifySettledEndingsForShift(
            $ctx->db(), $request['branch_id'], $request['date'], $request['shift'], $user, $request['production']
        );
        $ctx->json(['ok' => true] + $result);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
    }
}

function apiRevertCommissaryEndings(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin']);
    try {
        $request = dl_endingSettlementRequest($user, $ctx->input());
        $result = dl_revertSettledEndingsForShift(
            $ctx->db(), $request['branch_id'], $request['date'], $request['shift'], $user, $request['production']
        );
        $ctx->json(['ok' => true] + $result);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
    }
}

function apiSaveProductionRun(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    if (($input['entity'] ?? '') === 'production_addition') {
        try {
            $result = dl_recordProductionAddition($user, $input);
            $ctx->json(['ok' => true] + $result);
        } catch (RuntimeException $e) {
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
        } catch (Throwable $e) {
            write_log('apiSaveProductionRun addition error: ' . $e->getMessage(), 'error');
            $ctx->json(['ok' => false, 'error' => 'Database error executing transaction'], 500);
        }
        return;
    }

    if (($input['entity'] ?? '') === 'production_output_ledger') {
        try {
            $row = dl_saveProductionOutputLedgerCell($user, $input);
            $ctx->json(['ok' => true, 'row' => $row]);
        } catch (RuntimeException $e) {
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            write_log('apiSaveProductionRun ledger error: ' . $e->getMessage(), 'error');
            $ctx->json(['ok' => false, 'error' => 'Database error executing transaction'], 500);
        }
        return;
    }

    $date = (string)($input['date'] ?? '');
    $productId = (int)($input['product_id'] ?? 0);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $productId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing or invalid date or product'], 400);
        return;
    }

    try {
        dl_saveProductionRun($user, $input);
        $ctx->json(['ok' => true]);
    } catch (RuntimeException $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        return;
    } catch (Throwable $e) {
        write_log("apiSaveProductionRun error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error executing transaction'], 500);
    }
}

function apiCommissaryDispatch(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    // S10: the Daily Sheet's branch cells and the ADDTL modal both post through
    // the existing dispatch endpoint. A sheet entry is a real delivery but is NOT a
    // formal paper dispatch: it carries no DR, it may be a signed correction, and
    // it is logged in the sheet's own bottom log. Delegating here keeps the formal
    // dispatch path (and its DR guard) byte-for-byte intact for every other caller.
    $isSheetEntry = !empty($input['sheet_entry']) || (string)($input['source'] ?? '') === 'daily_sheet';
    if ($isSheetEntry) {
        try {
            $result = dl_recordDailySheetBranchEntry($user, $input);
            $ctx->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            write_log('apiCommissaryDispatch sheet entry error: ' . $e->getMessage(), 'error');
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
        }
        return;
    }

    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal delivery workflow is not enabled. Enable it in Settings first.'], 422);
        return;
    }

    $db = $ctx->db();
    $items = $input['items'] ?? [];
    $destinationBranchId = (int)($input['destination_branch_id'] ?? 0);
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $ledgerDate = (string)($input['ledger_date'] ?? dl_businessDate());
    $actorId = dl_getActorUserId($user);

    if (!is_array($items) || count($items) === 0) {
        $ctx->json(['ok' => false, 'error' => 'items[] array is required with at least one item.'], 422);
        return;
    }
    // A formal dispatch still requires a DR. The `!$isSheetEntry` clause documents
    // the sole exception (a sheet entry, handled above) instead of leaving the rule
    // implicit; it never weakens the paper-dispatch contract.
    if ($destinationBranchId <= 0 || ($drNumber === '' && !$isSheetEntry)) {
        $ctx->json(['ok' => false, 'error' => 'destination_branch_id and dr_number are required.'], 422);
        return;
    }

    // Normalize and validate items
    $cleanItems = [];
    foreach ($items as $i) {
        if (!is_array($i)) continue;
        $cid = (int)($i['commissary_branch_id'] ?? 0);
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['quantity'] ?? 0);
        if ($cid <= 0 || $pid <= 0 || $qty <= 0) continue;
        if ($cid === $destinationBranchId) {
            $ctx->json(['ok' => false, 'error' => 'Cannot dispatch from a commissary to itself.'], 422);
            return;
        }
        $key = $cid . ':' . $pid;
        if (isset($cleanItems[$key])) {
            $cleanItems[$key]['quantity'] += $qty;
        } else {
            $cleanItems[$key] = ['commissary_branch_id' => $cid, 'product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (count($cleanItems) === 0) {
        $ctx->json(['ok' => false, 'error' => 'No valid items with commissary_branch_id, product_id, and quantity > 0.'], 422);
        return;
    }

    try {
        $db->beginTransaction();

        // Verify destination branch
        $destStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1');
        $destStmt->execute([':id' => $destinationBranchId]);
        $destBranch = $destStmt->fetch(PDO::FETCH_ASSOC);
        if (!$destBranch) {
            throw new RuntimeException('Destination branch is not active.');
        }

        // Verify DR not already used
        $existingDelivery = dl_findAutoCommissaryDelivery($db, $destinationBranchId, $ledgerDate, $drNumber);
        if ($existingDelivery) {
            throw new RuntimeException('A delivery with DR "' . $drNumber . '" already exists for this branch on ' . $ledgerDate . '.');
        }
        $existingPaper = dl_findPaperCapturedCommissaryDelivery($db, $destinationBranchId, $ledgerDate, $drNumber);
        if ($existingPaper) {
            throw new RuntimeException('A paper DR capture with DR "' . $drNumber . '" already exists for this branch on ' . $ledgerDate . '.');
        }

        // Validate cumulative stock for each item
        $commissaryNames = [];
        foreach ($cleanItems as $item) {
            $cid = $item['commissary_branch_id'];
            $pid = $item['product_id'];
            $qty = $item['quantity'];

            // Verify commissary
            if (!isset($commissaryNames[$cid])) {
                $commStmt = $db->prepare('SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1');
                $commStmt->execute([':id' => $cid]);
                $comm = $commStmt->fetch(PDO::FETCH_ASSOC);
                if (!$comm) {
                    throw new RuntimeException('Branch #' . $cid . ' is not an active commissary.');
                }
                $commissaryNames[$cid] = $comm['name'];
            }

            // Check cumulative stock
            $cumulativeStmt = $db->prepare(
                'SELECT SUM(produced_qty - dispatched_qty) AS cumulative_remaining
                   FROM dl_commissary_product_ledger
                  WHERE commissary_branch_id = :cb AND product_id = :pid
                  HAVING cumulative_remaining > 0'
            );
            $cumulativeStmt->execute([':cb' => $cid, ':pid' => $pid]);
            $cumulativeRemaining = (int)($cumulativeStmt->fetchColumn() ?: 0);
            if ($cumulativeRemaining < $qty) {
                throw new RuntimeException('Insufficient commissary stock for product #' . $pid . '. Available: ' . $cumulativeRemaining . ', requested: ' . $qty . '.');
            }
        }

        // A delivery has one origin. Refuse mixed-commissary items rather than
        // attributing some debits to a guessed primary origin.
        $originIds = array_values(array_unique(array_map(static fn(array $item): int => (int)$item['commissary_branch_id'], $cleanItems)));
        if (count($originIds) !== 1) {
            throw new RuntimeException('Create a separate dispatch for each commissary origin.');
        }
        $primaryCommissaryId = $originIds[0];
        $delStmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $delStmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $primaryCommissaryId,
            ':destination_type' => 'branch',
            ':destination_id' => $destinationBranchId,
            ':dr_number' => $drNumber,
            ':delivery_date' => $ledgerDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => '[commissary-dispatch]',
        ]);
        $deliveryId = (int)$db->lastInsertId();

        // Add delivery items + update commissary ledger for each item
        $priceGroupId = dl_defaultPriceGroupId();
        $resultItems = [];
        $itemStmt = $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
        );

        foreach ($cleanItems as $item) {
            $cid = $item['commissary_branch_id'];
            $pid = $item['product_id'];
            $qty = $item['quantity'];

            $itemStmt->execute([
                ':delivery_id' => $deliveryId,
                ':product_id' => $pid,
                ':quantity' => $qty,
                ':unit' => 'pcs',
                ':unit_cost_snapshot' => 0,
                ':price_snapshot' => dl_resolveProductPrice($pid, $priceGroupId, $ledgerDate),
                ':price_group_id' => $priceGroupId,
                ':remarks' => 'commissary_dispatch',
            ]);

            $resultItems[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'commissary_branch_id' => $cid,
            ];
        }

        $ledgerEffect = dl_applyPostedDeliveryCommissaryLedger($db, $deliveryId, $actorId);
        foreach ($resultItems as &$resultItem) {
            $snapshot = dl_commissaryLedgerSnapshot($db, $primaryCommissaryId, (int)$resultItem['product_id'], $ledgerDate);
            $resultItem['remaining_qty'] = $snapshot['remaining_qty'];
        }
        unset($resultItem);

        dl_auditLog('commissary_dispatch', $primaryCommissaryId, 'dl_deliveries', (string)$deliveryId, null, [
            'commissary_branch_id' => $primaryCommissaryId,
            'destination_branch_id' => $destinationBranchId,
            'dr_number' => $drNumber,
            'ledger_date' => $ledgerDate,
            'destination_name' => $destBranch['name'],
            'item_count' => count($resultItems),
            'total_quantity' => array_sum(array_column($resultItems, 'quantity')),
            'items' => $resultItems,
        ]);

        $db->commit();

        // Verify all items were actually persisted
        $verifyStmt = $db->prepare('SELECT COUNT(*) FROM dl_delivery_items WHERE delivery_id = :did');
        $verifyStmt->execute([':did' => $deliveryId]);
        $actualItemCount = (int)$verifyStmt->fetchColumn();

        $ctx->json([
            'ok' => true,
            'delivery_id' => $deliveryId,
            'dr_number' => $drNumber,
            'destination_branch_id' => $destinationBranchId,
            'expected_items' => count($resultItems),
            'actual_items' => $actualItemCount,
            'items' => $resultItems,
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        write_log("apiCommissaryDispatch error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

function apiCarryCommissaryBeginnings(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge', 'auditor']);
    try {
        $result = dl_carryCommissaryBeginnings($user, $ctx->input());
        $ctx->json(['ok' => true] + $result);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
    }
}

function apiSaveCommissaryMaterial(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $db = $ctx->db();

    $input = $ctx->input();
    if (($input['entity'] ?? '') === 'production_ledger_edit') {
        try {
            $result = dl_changeProductionLedgerField($user, $input);
            $ctx->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'permission is required') ? 403 : 422;
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], $status);
        }
        return;
    }

    if (($input['entity'] ?? '') === 'product_beg') {
        $date = (string)($input['date'] ?? '');
        $commissaryBranchId = (int)($input['commissary_branch_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $shift = isset($input['shift']) && (string)$input['shift'] !== ''
            ? dl_normalizeShift((string)$input['shift'])
            : null;
        $begQty = filter_var($input['beg_qty'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($input['reason'] ?? ''));
        try {
            if ($begQty === false) {
                throw new \RuntimeException('A beginning value is required.');
            }
            if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
                throw new \RuntimeException('Commissary is not allowed for this user.');
            }
            $entityId = "{$commissaryBranchId}-{$productId}-{$date}-{$shift}";
            $recorded = $db->prepare(
                'SELECT id FROM audit_logs WHERE module = "daily-ledger"
                  AND action = "save_commissary_product_beg" AND entity_id = :eid LIMIT 1'
            );
            $recorded->execute([':eid' => $entityId]);
            $alreadyRecorded = (bool)$recorded->fetchColumn();
            // S7b: a recorded beginning is editable by user or admin, but it is an
            // edit of an entry, so production.override is required (S7b §3).
            if ($alreadyRecorded && !dl_roleHasPermission((string)($user['role'] ?? ''), 'production.override')
                && !dl_deliberateReopenUnlocksEntryEdit($db, $commissaryBranchId, $date, $shift)) {
                throw new \RuntimeException('production.override permission is required to change a recorded beginning.');
            }
            $beforeRow = dl_readCommissaryProductLedgerRow($db, $commissaryBranchId, $productId, $date, $shift);
            $beforeBeg = array_key_exists('beg_qty', $beforeRow) ? (int)$beforeRow['beg_qty'] : null;
            $row = dl_saveCommissaryBeginningQty(
                $db, $commissaryBranchId, $productId, $date, (int)$begQty, dl_getActorUserId($user), $shift
            );
            dl_auditLog('save_commissary_product_beg', $commissaryBranchId, 'dl_commissary_product_ledger', $entityId, null, [
                'beg_qty' => (int)$begQty,
            ]);
            dl_auditProductionLedgerChange(
                $db, $commissaryBranchId, $productId, $date, 'beg_qty',
                $alreadyRecorded ? $beforeBeg : null, (int)$begQty,
                $reason !== '' ? $reason : null, 'beg', null,
                dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''))
            );
            $ctx->json(['ok' => true, 'row' => $row]);
        } catch (Throwable $e) {
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return;
    }

    if (($input['entity'] ?? '') === 'product_count') {
        $date = (string)($input['date'] ?? '');
        $commissaryBranchId = (int)($input['commissary_branch_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $shift = isset($input['shift']) && (string)$input['shift'] !== ''
            ? dl_normalizeShift((string)$input['shift'])
            : null;
        $rawValue = $input['actual_end_qty'] ?? null;
        $actualEndQty = ($rawValue === null || $rawValue === '') ? null : (int)$rawValue;
        $reason = trim((string)($input['reason'] ?? ''));
        try {
            if (!in_array($commissaryBranchId, dl_accessibleBranchIds($user), true)) {
                throw new \RuntimeException('Commissary is not allowed for this user.');
            }
            $existingStmt = $db->prepare(
                'SELECT actual_end_qty FROM dl_commissary_product_ledger
                  WHERE commissary_branch_id = :cb AND product_id = :pid AND ledger_date = :d AND shift <=> :shift LIMIT 1'
            );
            $existingStmt->execute([':cb' => $commissaryBranchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
            $existing = $existingStmt->fetchColumn();
            $beforeCount = ($existing === false || $existing === null) ? null : (int)$existing;
            if ($beforeCount !== null && (int)$existing !== $actualEndQty
                && !dl_roleHasPermission((string)($user['role'] ?? ''), 'production.override')
                && !dl_deliberateReopenUnlocksEntryEdit($db, $commissaryBranchId, $date, $shift)) {
                throw new \RuntimeException('production.override permission is required to change a recorded count.');
            }
            $row = dl_saveCommissaryActualEndQty(
                $db,
                $commissaryBranchId,
                $productId,
                $date,
                $actualEndQty,
                dl_getActorUserId($user),
                $shift
            );
            dl_auditLog('save_commissary_product_count', $commissaryBranchId, 'dl_commissary_product_ledger', "{$commissaryBranchId}-{$productId}-{$date}-{$shift}", null, [
                'actual_end_qty' => $actualEndQty,
            ]);
            dl_auditProductionLedgerChange(
                $db, $commissaryBranchId, $productId, $date, 'actual_end_qty',
                $beforeCount, $actualEndQty, $reason !== '' ? $reason : null, 'count', null,
                dl_withdrawalSubmissionId((string)($input['submission_id'] ?? ''))
            );
            $ctx->json(['ok' => true, 'row' => $row]);
        } catch (Throwable $e) {
            $ctx->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return;
    }

    $date       = (string)($input['date'] ?? '');
    $materialId = (int)($input['material_id'] ?? 0);
    $field      = (string)($input['field'] ?? '');
    $val        = (float)($input['value'] ?? 0);
    $fieldMap = [
        'beg_bal' => 'beg_bal',
        'delivery_qty' => 'delivery_qty',
        'used_qty' => 'used_qty',
        'actual_end_bal' => 'actual_end_bal',
    ];
    $column = dl_allowedColumn($field, $fieldMap);

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $materialId <= 0 || $column === null) {
        $ctx->json(['ok' => false, 'error' => 'Invalid data'], 400);
        return;
    }
    if ($val < 0 || $val > 999999.999) {
        $ctx->json(['ok' => false, 'error' => 'Value out of bounds'], 422);
        return;
    }

    $actorId = dl_getActorUserId($user);

    try {
        $stmt = $db->prepare(
            "INSERT INTO dl_commissary_ledger (ledger_date, raw_material_id, {$column}, recorded_by)
             VALUES (:date, :mid, :val, :actor)
             ON DUPLICATE KEY UPDATE {$column} = :val, recorded_by = :actor"
        );
        $stmt->execute([
            ':date' => $date,
            ':mid'  => $materialId,
            ':val'  => $val,
            ':actor'=> $actorId > 0 ? $actorId : null,
        ]);

        // Read back the full row so the UI can update variance inline (no page reload needed)
        $rowStmt = $db->prepare(
            "SELECT beg_bal, delivery_qty, used_qty, actual_end_bal, calc_variance
             FROM dl_commissary_ledger
             WHERE ledger_date = :date AND raw_material_id = :mid
             LIMIT 1"
        );
        $rowStmt->execute([':date' => $date, ':mid' => $materialId]);
        $updatedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        dl_auditLog('save_commissary_material', null, 'dl_commissary_ledger', "{$date}-{$materialId}", null, [
            'date'        => $date,
            'material_id' => $materialId,
            'field'       => $field,
            'value'       => $val,
        ]);

        $ctx->json(['ok' => true, 'row' => $updatedRow]);
    } catch (Throwable $e) {
        write_log("apiSaveCommissaryMaterial error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Save failed'], 500);
    }
}

function apiDailyLedgerMe(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin', 'production_in_charge']);
    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (count($allowedBranchIds) === 0) { $allowedBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
    $stmtAll = $ctx->db()->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $stmtAll->execute($allowedBranchIds);
    $allBranches = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clockLabel = dl_operatingClockLabel();
    $branches = [];

    if (count($allowedBranchIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));
        $stmt = $ctx->db()->prepare(
            "SELECT id, code, name
             FROM dl_branches
             WHERE is_active = 1 AND id IN ({$placeholders})
             ORDER BY name"
        );
        $stmt->execute($allowedBranchIds);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $ctx->json([
        'ok' => true,
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'full_name' => (string)($user['full_name'] ?? $user['name'] ?? ''),
            'role' => (string)($user['role'] ?? '')
        ],
        'branches' => $branches,
        'clock' => [
            'business_date' => $clockLabel['business_date'],
            'close_of_day_time' => $clockLabel['close_of_day_time'],
            'operating_timezone' => $clockLabel['operating_timezone'],
            'operating_region' => $clockLabel['operating_region'],
        ],
        'all_branches' => $allBranches,
    ]);
}

function handleBranchSummaryRedirect(): void
{
    $ctx = module();
    if ($ctx) {
        $ctx->redirect(dlGetBaseUrl() . '/admin/sales');
    }
}

function handleAdminWithdrawals(): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor']);
    $db = $ctx->db();
    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : (!empty($input['date']) ? (string)$input['date'] : $today);
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : (!empty($input['date']) ? (string)$input['date'] : $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = $today;
    }
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $branchId = (int)($input['branch_id'] ?? 0);
    $commissaryId = (int)($input['commissary_id'] ?? 0);
    $search = trim((string)($input['q'] ?? ''));

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) {
        $accessibleBranchIds = [0];
    }
    if ((string)($user['role'] ?? '') !== 'admin' && $branchId > 0 && !in_array($branchId, $accessibleBranchIds, true)) {
        $branchId = 0;
    }

    // Branch dropdowns below use positional placeholders; the main query must
    // use NAMED placeholders only — PDO native prepares reject mixing
    // positional `?` with `:named` (HY093).
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branchNamed = [];
    $executeBind = [];
    foreach (array_values($accessibleBranchIds) as $index => $accessibleBranchId) {
        $key = ':branch_' . $index;
        $branchNamed[] = $key;
        $executeBind[$key] = (int)$accessibleBranchId;
    }
    $branchNamedSql = implode(',', $branchNamed);

    $sql = 'SELECT cw.id, cw.ledger_date, cw.created_at, cw.withdrawal_type, cw.reason_code,
                   cw.custom_reason, cw.quantity, cw.dr_number, cw.liable_user_id,
                   p.name AS product_name,
                   b.name AS branch_name,
                   b.area AS branch_area,
                   COALESCE(cb.name, \'—\') AS commissary_name,
                   COALESCE(
                       NULLIF(u.full_name, ""),
                       (SELECT NULLIF(uc.full_name, "")
                          FROM dl_user_branches ub
                          JOIN dl_users uc ON uc.id = ub.user_id AND uc.role = "cashier"
                         WHERE ub.branch_id = cw.branch_id
                         LIMIT 1),
                       NULLIF(u.username, ""),
                       "Unknown"
                   ) AS cashier_name,
                   COALESCE(NULLIF(cw.liable_user_name, ""), NULLIF(lu.full_name, lu.username), lu.username) AS liable_user_name
              FROM dl_cashier_withdrawals cw
              JOIN dl_products p ON p.id = cw.product_id
              JOIN dl_branches b ON b.id = cw.branch_id
              LEFT JOIN dl_branches cb ON cb.id = b.assigned_commissary_id AND cb.is_commissary = 1
              LEFT JOIN dl_users u ON u.id = cw.encoded_by AND cw.encoded_by > 0
              LEFT JOIN dl_users lu ON lu.id = cw.liable_user_id
             WHERE cw.branch_id IN (' . $branchNamedSql . ')
               AND cw.ledger_date BETWEEN :date_from AND :date_to';
    $executeBind[':date_from'] = $dateFrom;
    $executeBind[':date_to'] = $dateTo;
    if ($branchId > 0) {
        $sql .= ' AND cw.branch_id = :bid';
        $executeBind[':bid'] = $branchId;
    }
    if ($commissaryId > 0) {
        $sql .= ' AND b.assigned_commissary_id = :cid';
        $executeBind[':cid'] = $commissaryId;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR b.name LIKE :q_branch OR COALESCE(cb.name, \'\') LIKE :q_commissary OR COALESCE(lu.full_name, \'\') LIKE :q_liable OR COALESCE(u.full_name, u.username, \'\') LIKE :q_cashier OR COALESCE(cw.reason_code, \'\') LIKE :q_reason OR COALESCE(cw.custom_reason, \'\') LIKE :q_custom_reason OR COALESCE(cw.dr_number, \'\') LIKE :q_dr)';
        $like = '%' . $search . '%';
        $executeBind[':q'] = $like;
        $executeBind[':q_branch'] = $like;
        $executeBind[':q_commissary'] = $like;
        $executeBind[':q_liable'] = $like;
        $executeBind[':q_cashier'] = $like;
        $executeBind[':q_reason'] = $like;
        $executeBind[':q_custom_reason'] = $like;
        $executeBind[':q_dr'] = $like;
    }
    $sql .= ' ORDER BY cw.created_at DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    $stmt->execute($executeBind);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Hide rows where cashier couldn't be resolved; format time for display
    $rows = [];
    $totalQuantity = 0;
    $typeCounts = [
        'charge' => 0,
        'pullout' => 0,
        'adjustment_add' => 0,
    ];
    foreach ($allRows as $row) {
        if (($row['cashier_name'] ?? 'Unknown') !== 'Unknown') {
            $row['created_time'] = !empty($row['created_at']) ? date('H:i', strtotime($row['created_at'])) : '';
            $type = (string)($row['withdrawal_type'] ?? '');
            $typeMeta = dlWithdrawalTypeMeta($type);
            $row['withdrawal_type_label'] = $typeMeta['label'];
            $row['withdrawal_type_badge_classes'] = $typeMeta['badge_classes'];
            $row['reason_code_label'] = trim((string)($row['reason_code'] ?? '')) !== ''
                ? dlHumanizeToken((string)$row['reason_code'])
                : '';
            $row['custom_reason'] = trim((string)($row['custom_reason'] ?? ''));
            $rows[] = $row;
            $totalQuantity += (int)($row['quantity'] ?? 0);
            if (isset($typeCounts[$type])) {
                $typeCounts[$type]++;
            }
        }
    }

    $branchesStmt = $db->prepare("SELECT id, name FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branchesStmt->execute($accessibleBranchIds);
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $commissarySql = "SELECT DISTINCT cb.id, cb.name, cb.area
                        FROM dl_branches cb
                        INNER JOIN dl_branches b ON b.assigned_commissary_id = cb.id
                       WHERE cb.is_commissary = 1 AND cb.is_active = 1 AND b.id IN ({$branchPlaceholders})
                       ORDER BY COALESCE(cb.area, ''), cb.name";
    $commissaryStmt = $db->prepare($commissarySql);
    $commissaryStmt->execute($accessibleBranchIds);
    $commissaries = $commissaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/withdrawals.disyl', [
        'page_title' => 'Stock Adjustments',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'stock-adjustments',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'withdrawals' => $rows,
        'branches' => $branches,
        'commissaries' => $commissaries,
        'branch_id' => $branchId,
        'commissary_id' => $commissaryId,
        'date' => $dateTo,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'search' => $search,
        'total_rows' => count($rows),
        'total_quantity' => $totalQuantity,
        'type_charge_count' => $typeCounts['charge'],
        'type_pullout_count' => $typeCounts['pullout'],
        'type_adjustment_add_count' => $typeCounts['adjustment_add'],
    ]);
}

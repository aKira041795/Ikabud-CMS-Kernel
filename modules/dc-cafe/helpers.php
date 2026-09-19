<?php
/**
 * DC Cafe POS Module — Helpers
 *
 * Scoped context helpers following the example-notes convention.
 * Prefix: dc (for DC Cafe).
 *
 * @see modules/example-notes/helpers.php
 */

declare(strict_types=1);

/**
 * Returns the scoped ModuleContext for dc-cafe.
 */
function dcCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('dc-cafe');
    if (!$ctx) {
        throw new \RuntimeException('DC Cafe module context unavailable');
    }
    return $ctx;
}

/**
 * Returns the scoped ModuleDB for dc-cafe.
 */
function dcDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return dcCtx()->db();
}

/**
 * Returns the decoded request input (JSON or form).
 */
function dcInput(?string $key = null, mixed $default = null): mixed
{
    return dcCtx()->input($key, $default);
}

/**
 * Renders a DiSyL template from this module's template directory.
 */
function dcRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/dc-cafe/')
        ? $template
        : 'modules/dc-cafe/' . ltrim($template, '/');

    return dcCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

/**
 * Returns JSON response and exits.
 */
function dcJsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Returns error JSON response and exits.
 */
function dcJsonError(string $message, int $status = 400): void
{
    dcJsonResponse(['ok' => false, 'error' => $message], $status);
}

/**
 * Base URL for DC Cafe routes.
 */
function dcBaseUrl(): string
{
    return '/dc-cafe';
}

/**
 * Resolve the effective catalog store for a requested branch.
 *
 * DC Cafe currently seeds menu products into a primary store only. Branches
 * without store-local product rows should still see and sell the shared menu.
 */
function dcCatalogStoreId(int $requestedStoreId): int
{
    $db = dcDb();

    if ($requestedStoreId > 0) {
        $row = $db->query(
            "SELECT COUNT(*) AS cnt
             FROM dc_products
             WHERE store_id = ? AND is_active = 1",
            [$requestedStoreId]
        )->fetch(\PDO::FETCH_ASSOC);
        if ((int) ($row['cnt'] ?? 0) > 0) {
            return $requestedStoreId;
        }
    }

    $fallback = $db->query(
        "SELECT store_id
         FROM dc_products
         WHERE is_active = 1
         GROUP BY store_id
         ORDER BY COUNT(*) DESC, store_id ASC
         LIMIT 1"
    )->fetch(\PDO::FETCH_ASSOC);

    return (int) ($fallback['store_id'] ?? $requestedStoreId ?: 1);
}

// ── Module Settings ──────────────────────────────────────────────
//
// Preferences are declared in module.json `settings_fields` (single source of
// truth for defaults) and stored per tenant via saveTenantModuleSettings().
// Always read through dcSettings() so a tenant that has never saved a
// preference still gets the declared default.

/**
 * Declared preference fields for this module.
 *
 * @return array<int, array<string, mixed>>
 */
function dcSettingsFields(): array
{
    static $fields = null;
    if ($fields !== null) {
        return $fields;
    }

    // Read this module's own manifest directly. discoverModules() returns an
    // APCu-cached copy for up to 300s, so an edited default would not take
    // effect until that cache expired. The file next to us is authoritative and
    // this is static-cached for the request anyway.
    $path = __DIR__ . '/module.json';
    $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $declared = is_array($manifest) && is_array($manifest['settings_fields'] ?? null)
        ? $manifest['settings_fields']
        : [];

    $fields = [];
    foreach ($declared as $field) {
        if (is_array($field) && trim((string) ($field['key'] ?? '')) !== '') {
            $fields[] = $field;
        }
    }

    return $fields;
}

/**
 * Manifest defaults, used when a tenant has never saved a preference.
 *
 * @return array<string, mixed>
 */
function dcSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    foreach (dcSettingsFields() as $field) {
        $key = (string) $field['key'];
        if (array_key_exists('default', $field)) {
            $defaults[$key] = $field['default'];
        }
    }

    return $defaults;
}

/**
 * Effective preferences: declared defaults overlaid with the tenant's values.
 *
 * @return array<string, mixed>
 */
function dcSettings(): array
{
    // Outside a module request there is no stored settings source, and the
    // declared defaults are the correct answer.
    $stored = function_exists('getModuleSettings') ? getModuleSettings('dc-cafe') : [];
    if (!is_array($stored)) {
        $stored = [];
    }

    return array_merge(dcSettingsDefaults(), $stored);
}

/**
 * Read a boolean preference. Declared defaults are strings ('1'/'0').
 */
function dcSettingBool(string $key): bool
{
    $value = dcSettings()[$key] ?? null;
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Read a numeric preference with a fallback for unusable stored values.
 */
function dcSettingNumber(string $key, float $fallback = 0.0): float
{
    $value = dcSettings()[$key] ?? null;
    if (!is_numeric($value)) {
        return $fallback;
    }

    $number = (float) $value;

    return is_finite($number) ? $number : $fallback;
}

/**
 * How the till behaves when stock is short.
 *
 *   strict          refuse the sale
 *   warn (default)  allow it, let the balance go negative, flag the shortfall
 *   allow_negative  allow it silently
 *
 * `warn` is the default on purpose: goods are routinely on the rack before the
 * delivery is entered into the system, and a cashier must be able to sell what
 * the customer can see.
 */
function dcStockPolicy(): string
{
    $policy = (string) (dcSettings()['pos_stock_policy'] ?? 'warn');

    return in_array($policy, ['strict', 'warn', 'allow_negative'], true) ? $policy : 'warn';
}

/**
 * Whether the selected policy refuses a sale when stock is short.
 */
function dcStockPolicyBlocksSale(): bool
{
    return dcStockPolicy() === 'strict';
}

/**
 * Whether a shortfall should be recorded for follow-up (Warn mode).
 */
function dcStockPolicyRecordsShortfall(): bool
{
    return dcStockPolicy() === 'warn';
}

// ── Discount Ceiling ─────────────────────────────────────────────
// An administrator caps what a cashier may give away on a single sale. The
// ceiling is a percentage of the subtotal, so it constrains a peso-amount
// discount as well as a rated one. Per-type rates pre-fill the till; the
// ceiling is what actually bounds it.

/**
 * The configured ceiling, clamped to a sane range.
 */
function dcMaxDiscountPct(): float
{
    return max(0.0, min(100.0, dcSettingNumber('pos_max_discount_pct', 20.0)));
}

/**
 * The effective discount as a percentage of the subtotal.
 */
function dcDiscountPctOf(float $subtotal, float $discountAmount): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }
    return ($discountAmount / $subtotal) * 100.0;
}

/**
 * Why a discount is not allowed, or null when it is within the ceiling.
 *
 * Returned rather than thrown so the order flow and the config endpoint can
 * both describe the same rule to the till.
 */
function dcDiscountCeilingError(float $subtotal, float $discountAmount): ?string
{
    if ($discountAmount <= 0 || $subtotal <= 0) {
        return null;
    }
    $ceiling = dcMaxDiscountPct();
    $applied = dcDiscountPctOf($subtotal, $discountAmount);
    // A penny of float drift should not reject a discount that is exactly at the cap.
    if ($applied > $ceiling + 0.01) {
        return sprintf(
            'A %s%% discount exceeds the %s%% limit set for this branch',
            rtrim(rtrim(number_format($applied, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($ceiling, 2, '.', ''), '0'), '.')
        );
    }
    return null;
}

// ── Audit ────────────────────────────────────────────────────────

/**
 * Record an action against the shared audit log.
 *
 * Defined here rather than in handlers.php on purpose: the split handler files
 * (handlers-orders, handlers-products, ...) are loaded directly by some entry
 * points without handlers.php, so anything they call must live in helpers.php,
 * which the kernel always loads.
 */
function dc_auditLog(string $action, ?string $entityType = null, ?string $entityId = null, $oldData = null, $newData = null, ?string $reason = null): void
{
    $ctx = module('dc-cafe');
    if (!$ctx) return;
    try {
        $ctx->audit($action, null, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (\Throwable $e) {
        // Non-fatal: a missing audit row must not fail the operation.
    }
}

// ── Void Approval ────────────────────────────────────────────────
// A cashier may start a void, but an administrator or supervisor has to approve
// it with their own PIN. The PIN identifies a person, so the audit records who
// approved rather than merely that a shared password was used.

/**
 * Whether voiding is permitted at all for this branch.
 *
 * On by default, so an existing branch keeps the behaviour it had. Turning it
 * off makes a completed sale final — the till hides the control and the server
 * refuses the request, so it is a real policy and not a cosmetic one.
 */
function dcVoidEnabled(): bool
{
    return dcSettingBool('pos_void_enabled');
}

/**
 * Whether the till may park an order instead of taking payment now.
 *
 * Off by default. With a queue a cashier can hold one customer's items while
 * the next is served, then bring the sale back to finish it. A parked order
 * holds no stock and is not counted as a sale — it only becomes one when it is
 * finalized. The control is hidden from the till AND the server refuses the
 * request, so this is a policy rather than a cosmetic switch.
 */
function dcOrderQueueEnabled(): bool
{
    return dcSettingBool('pos_order_queue_enabled');
}

/**
 * Whether dropping a parked order is limited to an administrator or supervisor.
 *
 * Off by default: a parked order is the cashier's own mis-park and nothing has
 * been sold, so they may clear it themselves. A branch can require a supervisor
 * instead, in which case the till hides the control and the server refuses.
 */
function dcDiscardRequiresSupervisor(): bool
{
    return dcSettingBool('pos_discard_supervisor_only');
}

/**
 * Whether this role may discard a parked order, which is what the till needs to
 * know in order to decide whether to offer the control at all.
 */
function dcCanDiscardParkedOrder(string $role): bool
{
    return !dcDiscardRequiresSupervisor() || in_array($role, ['admin', 'supervisor'], true);
}

/** Longest run of failed approvals before the till is locked out. */
const DC_VOID_MAX_FAILURES = 5;/** How long a failed attempt is remembered, in minutes. */
const DC_VOID_FAILURE_WINDOW_MIN = 15;

/**
 * Whether any administrator or supervisor has a void PIN configured.
 *
 * Used to fail closed with an actionable message instead of silently refusing
 * every void on a branch that has never been set up.
 */
function dcHasVoidApprovers(): bool
{
    try {
        $count = dcDb()->query(
            "SELECT COUNT(*) FROM dc_users
             WHERE void_pin_hash IS NOT NULL AND void_pin_hash <> ''
               AND is_active = 1 AND deleted_at IS NULL
               AND role IN ('admin', 'supervisor')"
        )->fetchColumn();
    } catch (\Throwable $e) {
        write_log('dc_cafe.void.approver_check_failed', 'warning', ['message' => $e->getMessage()]);
        return false;
    }
    return ((int) $count) > 0;
}

/**
 * The administrator or supervisor whose PIN matches, or null.
 *
 * bcrypt comparisons are slow, so every active approver is tried deliberately —
 * that is what stops a correct PIN being identified by response timing.
 */
function dcVerifyVoidPin(string $pin): ?array
{
    $pin = trim($pin);
    if ($pin === '' || mb_strlen($pin) > 32) {
        return null;
    }

    try {
        $approvers = dcDb()->query(
            "SELECT user_id, username, full_name, role, void_pin_hash
             FROM dc_users
             WHERE void_pin_hash IS NOT NULL AND void_pin_hash <> ''
               AND is_active = 1 AND deleted_at IS NULL
               AND role IN ('admin', 'supervisor')
             ORDER BY user_id"
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        write_log('dc_cafe.void.pin_lookup_failed', 'warning', ['message' => $e->getMessage()]);
        return null;
    }

    $matched = null;
    foreach ($approvers as $approver) {
        if (password_verify($pin, (string) $approver['void_pin_hash'])) {
            $matched = $approver;
        }
    }
    return $matched;
}

/**
 * Record an approval attempt so repeated guesses can be throttled.
 */
function dcRecordVoidAttempt(int $storeId, int $userId, bool $succeeded): void
{
    try {
        dcDb()->query(
            'INSERT INTO dc_void_attempts (store_id, user_id, succeeded) VALUES (?, ?, ?)',
            [$storeId, $userId, $succeeded ? 1 : 0]
        );
    } catch (\Throwable $e) {
        // Losing an audit row must not block a legitimate void.
        write_log('dc_cafe.void.attempt_log_failed', 'warning', ['message' => $e->getMessage()]);
    }
}

/**
 * Recent failures for this branch, used to refuse further guesses.
 */
function dcVoidFailureCount(int $storeId): int
{
    try {
        return (int) dcDb()->query(
            'SELECT COUNT(*) FROM dc_void_attempts
             WHERE store_id = ? AND succeeded = 0
               AND attempted_at >= (NOW() - INTERVAL ' . (int) DC_VOID_FAILURE_WINDOW_MIN . ' MINUTE)',
            [$storeId]
        )->fetchColumn();
    } catch (\Throwable $e) {
        // Fail closed: if the counter cannot be read, treat the till as locked.
        write_log('dc_cafe.void.throttle_read_failed', 'warning', ['message' => $e->getMessage()]);
        return DC_VOID_MAX_FAILURES;
    }
}

/**
 * Why a void is currently blocked by the throttle, or null when it may proceed.
 *
 * A successful approval clears the branch's failure history so a supervisor who
 * mistyped their PIN is not punished for the rest of the window.
 */
function dcVoidThrottleError(int $storeId): ?string
{
    if (dcVoidFailureCount($storeId) < DC_VOID_MAX_FAILURES) {
        return null;
    }
    return 'Too many failed approvals. Wait ' . DC_VOID_FAILURE_WINDOW_MIN
        . ' minutes before trying again.';
}

/**
 * Clear the failure history for a branch after a successful approval.
 */
function dcClearVoidFailures(int $storeId): void
{
    try {
        dcDb()->query('DELETE FROM dc_void_attempts WHERE store_id = ? AND succeeded = 0', [$storeId]);
    } catch (\Throwable $e) {
        write_log('dc_cafe.void.throttle_clear_failed', 'warning', ['message' => $e->getMessage()]);
    }
}

// ── Discount Types ───────────────────────────────────────────────
// Configurable per branch. The rate stored here is the single source of truth
// for a discount type — the till pre-fills from it and the cashier may still
// override the amount, bounded by the ceiling above.

/**
 * Active discount types, ordered for display.
 *
 * @return array<int, array{discount_type_id:int, code:string, name:string, default_pct:float, is_active:bool, sort_order:int}>
 */
function dcDiscountTypes(bool $activeOnly = true): array
{
    try {
        $sql = 'SELECT discount_type_id, code, name, default_pct, is_active, sort_order
                FROM dc_discount_types';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $rows = dcDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Table not migrated yet, or a transient DB problem: the till falls back
        // to its built-in list rather than failing to load.
        write_log('dc_cafe.discount_types.unavailable', 'warning', ['message' => $e->getMessage()]);
        return [];
    }

    $types = [];
    foreach ($rows as $row) {
        $types[] = [
            'discount_type_id' => (int) $row['discount_type_id'],
            'code'             => (string) $row['code'],
            'name'             => (string) $row['name'],
            'default_pct'      => (float) $row['default_pct'],
            'is_active'        => (bool) $row['is_active'],
            'sort_order'       => (int) $row['sort_order'],
        ];
    }
    return $types;
}

/**
 * Rate for a discount type identified by its code, or null when unknown.
 */
function dcDiscountTypeRate(string $code): ?float
{
    foreach (dcDiscountTypes() as $type) {
        if ($type['code'] === $code) {
            return $type['default_pct'];
        }
    }
    return null;
}

// ── Order Activity Trail ─────────────────────────────────────────
// The order is the unit of record for everything that happens at the till, so
// its audit rows are surfaced on the order itself. This is read-only history:
// nothing here may alter an order.

/**
 * Audit rows recorded against one order, newest first.
 *
 * @return array<int, array{action:string, label:string, detail:string, at:string, tone:string}>
 */
function dcOrderActivity(int $orderId, int $limit = 50): array
{
    if ($orderId <= 0) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    try {
        $rows = dcDb()->query(
            "SELECT action, new_data, created_at
             FROM audit_logs
             WHERE module = 'dc-cafe' AND entity_type = 'dc_orders' AND entity_id = ?
             ORDER BY id DESC
             LIMIT " . $limit,
            [(string) $orderId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // A missing trail must not break the order page.
        write_log('dc_cafe.order_activity.unavailable', 'warning', ['message' => $e->getMessage()]);
        return [];
    }

    $activity = [];
    foreach ($rows as $row) {
        $new = json_decode((string) ($row['new_data'] ?? ''), true);
        $activity[] = dcOrderActivityEntry((string) $row['action'], is_array($new) ? $new : [], (string) $row['created_at']);
    }
    return $activity;
}

/**
 * Turn one audit row into something a supervisor can read at a glance.
 *
 * @param array<string, mixed> $new
 * @return array{action:string, label:string, detail:string, at:string, tone:string}
 */
function dcOrderActivityEntry(string $action, array $new, string $at): array
{
    $entry = ['action' => $action, 'label' => $action, 'detail' => '', 'at' => $at, 'tone' => 'neutral'];

    switch ($action) {
        case 'order.created':
            $entry['label'] = 'Sale recorded';
            $entry['tone'] = 'ok';
            $items = isset($new['items']) ? (int) $new['items'] : 0;
            $entry['detail'] = 'Total ' . number_format((float) ($new['total'] ?? 0), 2)
                . ', ' . $items . ' line item' . ($items === 1 ? '' : 's');
            break;

        case 'order.voided':
            $entry['label'] = 'Sale voided';
            $entry['tone'] = 'danger';
            $by = (string) ($new['approved_by_username'] ?? '');
            $role = (string) ($new['approved_by_role'] ?? '');
            $entry['detail'] = $by !== ''
                ? 'Approved by ' . $by . ($role !== '' ? ' (' . $role . ')' : '')
                : 'Approved with a supervisor PIN';
            break;

        case 'stock.shortfall':
            $entry['label'] = 'Sold below recorded stock';
            $entry['tone'] = 'warn';
            $entry['detail'] = 'Stock was short at the time of sale; receiving was not caught up.';
            break;

        case 'order.create_failed':
            $entry['label'] = 'Sale failed';
            $entry['tone'] = 'danger';
            $entry['detail'] = (string) ($new['error'] ?? 'The sale could not be completed.');
            break;
    }

    return $entry;
}

// ── Audit Trail (all activities) ─────────────────────────────────
// The Audit view is the running log of everything that happens in the app, not
// just sales and not just this module. Rows are read-only history: nothing here
// may change state.

/** How many audit rows a page shows. */
const DC_AUDIT_PAGE_SIZE = 50;

/**
 * Audited actions, newest first, with the acting user resolved.
 *
 * @param array{action?:string, entity_type?:string, q?:string} $filters
 * @return array<int, array<string, mixed>>
 */
function dcAuditTrail(array $filters = [], int $limit = DC_AUDIT_PAGE_SIZE, int $offset = 0): array
{
    [$where, $params] = dcAuditTrailWhere($filters);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);

    try {
        $rows = dcDb()->query(
            "SELECT al.id, al.module, al.action, al.entity_type, al.entity_id, al.actor_user_id,
                    al.actor_module_user_id, al.actor_source, al.old_data, al.new_data, al.created_at,
                    u.full_name AS actor_name, u.username AS actor_username, u.role AS actor_role
             FROM audit_logs al
             LEFT JOIN dc_users u
               ON u.user_id = al.actor_module_user_id AND al.actor_source = 'dc-cafe'
             WHERE " . implode(' AND ', $where) . "
             ORDER BY al.id DESC
             LIMIT " . $limit . " OFFSET " . $offset,
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // A missing trail must not break the page.
        write_log('dc_cafe.audit_trail.unavailable', 'warning', ['message' => $e->getMessage()]);
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $new = json_decode((string) ($row['new_data'] ?? ''), true);
        $old = json_decode((string) ($row['old_data'] ?? ''), true);
        $out[] = dcAuditTrailEntry($row, is_array($new) ? $new : [], is_array($old) ? $old : []);
    }
    return $out;
}

/**
 * Total rows matching the same filters, so the view can page honestly.
 */
function dcAuditTrailCount(array $filters = []): int
{
    [$where, $params] = dcAuditTrailWhere($filters);
    try {
        return (int) dcDb()->query(
            "SELECT COUNT(*)
             FROM audit_logs al
             LEFT JOIN dc_users u
               ON u.user_id = al.actor_module_user_id AND al.actor_source = 'dc-cafe'
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    } catch (\Throwable $e) {
        write_log('dc_cafe.audit_trail.count_failed', 'warning', ['message' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Shared WHERE clause so the page and its total can never disagree.
 *
 * @return array{0: array<int,string>, 1: array<int,mixed>}
 */
function dcAuditTrailWhere(array $filters): array
{
    // Deliberately not scoped to one module: this is the app-wide trail. The
    // module filter is optional and only narrows it.
    $where = ['1 = 1'];
    $params = [];

    $module = trim((string) ($filters['module'] ?? ''));
    if ($module !== '') {
        $where[] = 'al.module = ?';
        $params[] = $module;
    }
    $action = trim((string) ($filters['action'] ?? ''));
    if ($action !== '') {
        $where[] = 'al.action = ?';
        $params[] = $action;
    }
    $entity = trim((string) ($filters['entity_type'] ?? ''));
    if ($entity !== '') {
        $where[] = 'al.entity_type = ?';
        $params[] = $entity;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(al.action LIKE ? OR al.entity_id LIKE ? OR al.module LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    return [$where, $params];
}

/**
 * Turn one audit row into something readable, including who did it.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $new
 * @param array<string, mixed> $old
 * @return array<string, mixed>
 */
function dcAuditTrailEntry(array $row, array $new, array $old = []): array
{
    $action = (string) ($row['action'] ?? '');
    $name = trim((string) ($row['actor_name'] ?? ''));
    $username = trim((string) ($row['actor_username'] ?? ''));
    $role = trim((string) ($row['actor_role'] ?? ''));
    $source = trim((string) ($row['actor_source'] ?? ''));

    // The actor column is the point of this view: name the person when the row
    // belongs to a module whose users we can resolve, then the account, then a
    // labelled id. Never a bare id, and never another module's name.
    $rowModule = trim((string) ($row['module'] ?? ''));
    if ($name !== '' || $username !== '') {
        $actorLabel = $name !== '' ? $name : $username;
        $actorDetail = $username !== '' && $name !== '' ? $username : '';
    } elseif ((int) ($row['actor_module_user_id'] ?? 0) > 0) {
        // actor_module_user_id is scoped to the ACTING module's user table, so
        // for another module this is an id we cannot safely name. Always carry
        // the module so an id is never mistaken for one of our own users.
        $actorLabel = ($rowModule !== '' ? $rowModule : 'Module')
            . ' user #' . (int) $row['actor_module_user_id'];
        $actorDetail = $source !== '' ? $source : '';
    } elseif ((int) ($row['actor_user_id'] ?? 0) > 0) {
        $actorLabel = 'Kernel user #' . (int) $row['actor_user_id'];
        $actorDetail = $source !== '' ? $source : 'kernel';
    } else {
        $actorLabel = 'System';
        $actorDetail = $source !== '' ? $source : 'automated';
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'module' => $rowModule,
        'action' => $action,
        'label' => dcAuditActionLabel($action),
        'tone' => dcAuditActionTone($action),
        'detail' => dcAuditDetail($action, $new, $old),
        'entity_type' => (string) ($row['entity_type'] ?? ''),
        'entity_id' => (string) ($row['entity_id'] ?? ''),
        'actor_label' => $actorLabel,
        'actor_detail' => $actorDetail,
        'actor_role' => $role,
        'at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * Human names for audited actions.
 *
 * The single source for both the Activity column and the filter options, so a
 * new action is named in one place and the two can never disagree.
 *
 * @return array<string, string>
 */
function dcAuditActionLabels(): array
{
    return [
        'order.created' => 'Sale recorded',
        'order.voided' => 'Sale voided',
        'order.create_failed' => 'Sale failed',
        // Order queue: a parked order is held, not sold, and may be dropped.
        'order.parked' => 'Order parked (pending)',
        'order.parked_discarded' => 'Parked order discarded',
        'stock.shortfall' => 'Sold below recorded stock',
        'stock.received' => 'Stock received',
        'stock.adjusted' => 'Stock corrected',
        'product.reorder_level_changed' => 'Reorder level changed',
        'inventory_progress.saved' => 'Inventory count saved',
        'inventory.reset' => 'Inventory reset',
        'session.started' => 'Shift started',
        'session.ended' => 'Shift ended',
        'settings.preferences_saved' => 'Preferences saved',
        'discount_type.saved' => 'Discount type saved',
        'user.void_pin_set' => 'Void PIN set',
        'user.void_pin_cleared' => 'Void PIN removed',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.activated' => 'User activated',
        'user.deactivated' => 'User deactivated',
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Sign-in failed',
        // Password recovery: the request, the completion, and a request naming an
        // account that does not exist.
        'auth.password_reset_requested' => 'Password reset requested',
        'auth.password_reset' => 'Password reset completed',
        'auth.password_reset_unknown' => 'Password reset for unknown account',
        'softserve.base_saved' => 'Soft-serve base saved',
        'softserve.sauce_saved' => 'Soft-serve sauce saved',
        'softserve.topping_saved' => 'Soft-serve topping saved',
        'softserve.addon_saved' => 'Soft-serve addon saved',
        'backup.generated' => 'Backup generated',
        'ledger_group.create' => 'Ledger group created',
        'ledger_group.remap' => 'Ledger group remapped',
        // Catalog records created or edited from Settings. These decide what the
        // till can sell and at what cost, so they belong in the trail beside the
        // sales themselves.
        'customer.created' => 'Customer added',
        'supplier.created' => 'Supplier added',
        'supplier.updated' => 'Supplier edited',
        'ingredient.created' => 'Ingredient added',
        'ingredient.updated' => 'Ingredient edited',
        'payment_method.created' => 'Payment method added',
        'payment_method.updated' => 'Payment method edited',
        'store.updated' => 'Store edited',
        // Recorded by the kernel safety net when a state-changing request finished
        // without the module describing what it did. Presence only, no detail.
        'request.mutated' => 'Change recorded (no detail)',
    ];
}

/**
 * Human name for an audited action, falling back to a readable form of the key
 * so a newly added action shows up immediately instead of vanishing.
 */
function dcAuditActionLabel(string $action): string
{
    $known = dcAuditActionLabels();
    if (isset($known[$action])) {
        return $known[$action];
    }
    return ucfirst(str_replace(['.', '_'], ' ', $action));
}

/**
 * Colour hint for scanning: losses and failures stand out from routine work.
 */
function dcAuditActionTone(string $action): string
{
    if (in_array($action, ['order.voided', 'order.create_failed', 'auth.login_failed', 'inventory.reset', 'auth.password_reset_unknown'], true)) {
        return 'danger';
    }
    if (in_array($action, ['stock.shortfall', 'user.deactivated', 'user.void_pin_cleared', 'auth.password_reset'], true)) {
        return 'warn';
    }
    // A parked order is unfinished business, and a discarded park is business that
    // never finished at all. Both deserve to stand out from a completed sale.
    if (in_array($action, ['order.parked', 'order.parked_discarded'], true)) {
        return 'warn';
    }
    // An undescribed change is a coverage gap, so it reads as caution rather
    // than routine activity — it is there to prompt a specific audit row.
    if ($action === 'request.mutated') {
        return 'warn';
    }
    if (in_array($action, ['order.created', 'stock.received', 'auth.login', 'user.created'], true)) {
        return 'ok';
    }
    return 'neutral';
}

/**
 * One-line summary of what changed, best-effort from the recorded payload.
 *
 * @param array<string, mixed> $new
 * @param array<string, mixed> $old
 */
function dcAuditDetail(string $action, array $new, array $old = []): string
{
    switch ($action) {
        case 'order.created':
            $items = (int) ($new['items'] ?? 0);
            $detail = 'Total ' . number_format((float) ($new['total'] ?? 0), 2)
                . ', ' . $items . ' line item' . ($items === 1 ? '' : 's');
            // A sale that was held first reads as one continuous transaction.
            return !empty($new['finalized_from_parked']) ? $detail . ' (was parked)' : $detail;

        case 'order.parked':
            $items = (int) ($new['items'] ?? 0);
            return 'Held for later, total ' . number_format((float) ($new['total'] ?? 0), 2)
                . ', ' . $items . ' line item' . ($items === 1 ? '' : 's');

        case 'order.parked_discarded':
            $items = (int) ($new['items'] ?? 0);
            return 'Dropped, total ' . number_format((float) ($new['total'] ?? 0), 2)
                . ', ' . $items . ' line item' . ($items === 1 ? '' : 's');

        case 'auth.password_reset_requested':
            $who = (string) ($new['username'] ?? '');
            return $who !== '' ? 'Reset link issued for ' . $who : 'Reset link issued';

        case 'auth.password_reset':
            $ip = (string) ($new['ip'] ?? '');
            return $ip !== '' ? 'Password changed from ' . $ip : 'Password changed';

        case 'auth.password_reset_unknown':
            $identity = (string) ($new['identity'] ?? '');
            return $identity !== '' ? 'No account matched "' . $identity . '"' : 'No account matched';

        case 'customer.created':
        case 'supplier.created':
        case 'ingredient.created':
        case 'payment_method.created':
            $added = (string) ($new['name'] ?? '');
            return $added !== '' ? 'Added ' . $added : 'Added';

        case 'supplier.updated':
        case 'ingredient.updated':
        case 'payment_method.updated':
        case 'store.updated':
            $changed = is_array($new['changes'] ?? null) ? $new['changes'] : [];
            $named = (string) ($new['name'] ?? '');
            $parts = [];
            if ($named !== '') { $parts[] = $named; }
            if ($changed !== []) { $parts[] = 'changed: ' . implode(', ', $changed); }
            return $parts === [] ? '' : implode(' — ', $parts);

        case 'softserve.base_saved':
        case 'softserve.sauce_saved':
        case 'softserve.topping_saved':
        case 'softserve.addon_saved':
            $saved = (string) ($new['name'] ?? '');
            $verb = !empty($new['created']) ? 'Added' : 'Updated';
            return $saved !== '' ? $verb . ' ' . $saved : $verb;

        case 'order.voided':
            $by = (string) ($new['approved_by_username'] ?? '');
            $role = (string) ($new['approved_by_role'] ?? '');
            if ($by === '') {
                return 'Approved with a supervisor PIN';
            }
            return 'Approved by ' . $by . ($role !== '' ? ' (' . $role . ')' : '');

        case 'order.create_failed':
            return (string) ($new['error'] ?? 'The sale could not be completed.');

        case 'stock.shortfall':
            $items = is_array($new['items'] ?? null) ? $new['items'] : [];
            return $items === []
                ? 'Stock was short at the time of sale'
                : count($items) . ' item(s) short at the time of sale';

        case 'stock.received':
            $items = is_array($new['items'] ?? null) ? $new['items'] : [];
            $units = 0.0;
            foreach ($items as $item) {
                $units += (float) ($item['quantity'] ?? 0);
            }
            return (int) ($new['products'] ?? count($items)) . ' product(s), '
                . rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.') . ' unit(s)';

        case 'stock.adjusted':
            $delta = (float) ($new['delta'] ?? 0);
            return 'On hand ' . $new['on_hand_qty'] . ' (' . ($delta >= 0 ? '+' : '') . $delta . ')';

        case 'request.mutated':
            $method = strtoupper((string) ($new['method'] ?? ''));
            $path = (string) ($new['path'] ?? '');
            $parts = array_filter([$method, $path]);
            return $parts === []
                ? 'A change was made without a specific audit record'
                : implode(' ', $parts) . ' — no specific audit record was written';

        case 'product.reorder_level_changed':
            return 'Reorder level ' . $new['reorder_level'];

        case 'session.started':
        case 'session.ended':
            return 'Store ' . ($new['store_id'] ?? '—') . ', shift ' . ($new['shift_type'] ?? '—');

        case 'settings.preferences_saved':
            // The changed keys are recorded as the prior state on this action.
            $keys = is_array($old['keys'] ?? null) ? $old['keys'] : (is_array($new['keys'] ?? null) ? $new['keys'] : []);
            return $keys === [] ? '' : 'Changed: ' . implode(', ', $keys);

        case 'discount_type.saved':
            $pct = $new['default_pct'] ?? null;
            return (string) ($new['name'] ?? '') . ($pct !== null ? ' at ' . $pct . '%' : '');

        case 'user.void_pin_set':
        case 'user.void_pin_cleared':
            return (string) ($new['username'] ?? '');

        case 'inventory_progress.saved':
            $rows = (int) ($new['item_count'] ?? 0);
            return $rows > 0 ? $rows . ' row' . ($rows === 1 ? '' : 's') . ' counted' : '';

        case 'ledger_group.create':
        case 'ledger_group.remap':
            return (string) ($new['name'] ?? $new['ledger_group'] ?? '');
    }

    // Unknown action: show the first scalar values rather than nothing.
    $parts = [];
    foreach ($new as $key => $value) {
        if (is_scalar($value) && $parts === []) {
            $parts[] = $key . ': ' . $value;
        }
    }
    return implode(', ', $parts);
}

/**
 * Actions present in the log, as value/label pairs for the filter.
 *
 * The stored action is the value so filtering still matches, and the label is
 * the same human name the Activity column shows. Sorted by label because that
 * is what the reader is scanning.
 *
 * @return array<int, array{value:string, label:string}>
 */
function dcAuditActionOptions(): array
{
    try {
        $actions = array_column(dcDb()->query(
            "SELECT DISTINCT action FROM audit_logs ORDER BY action"
        )->fetchAll(\PDO::FETCH_ASSOC), 'action');
    } catch (\Throwable $e) {
        return [];
    }

    $options = [];
    foreach ($actions as $action) {
        $action = (string) $action;
        if ($action === '') {
            continue;
        }
        $options[] = ['value' => $action, 'label' => dcAuditActionLabel($action)];
    }
    usort($options, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $options;
}

/**
 * Distinct modules present in the log, for the module filter.
 *
 * @return array<int, string>
 */
function dcAuditModuleOptions(): array
{
    try {
        return array_column(dcDb()->query(
            "SELECT DISTINCT module FROM audit_logs WHERE module <> '' ORDER BY module"
        )->fetchAll(\PDO::FETCH_ASSOC), 'module');
    } catch (\Throwable $e) {
        return [];
    }
}

// ── Capability Handler Map ───────────────────────────────────────

function dc_cafe_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1'   => 'dc_cap_kernel_auth_authenticate_1',
        'entity.list.dc_product@1'     => 'dc_cap_entity_list_product_1',
        'entity.list.dc_product_stock@1' => 'dc_cap_entity_list_product_stock_1',
        'entity.get.dc_product@1'      => 'dc_cap_entity_get_product_1',
        'entity.list.dc_order@1'       => 'dc_cap_entity_list_order_1',
        'entity.get.dc_order@1'        => 'dc_cap_entity_get_order_1',
        'entity.list.dc_customer@1'    => 'dc_cap_entity_list_customer_1',
        'entity.get.dc_customer@1'     => 'dc_cap_entity_get_customer_1',
        'entity.list.dc_inventory@1'   => 'dc_cap_entity_list_inventory_1',
    ];
}

// Load entity view capability implementations at module registration time
// so is_callable() checks in module-routes.php can find them.
require_once __DIR__ . '/helpers/entity-views.php';

// Box (variable product) composition helpers.
require_once __DIR__ . '/helpers/boxes.php';

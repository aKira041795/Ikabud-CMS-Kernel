<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

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

    // Cashier: locked to assigned branch (single row in dl_user_branches)
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

    return $u;
}

function dl_allPermissionActions(): array
{
    return [
        'ledger.override',
        'production.override',
    ];
}

function dl_defaultRolePermissions(): array
{
    return [
        'admin' => ['ledger.override', 'production.override'],
        'supervisor' => [],
        'production_in_charge' => [],
        'cashier' => [],
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

function dlModuleSettings(): array
{
    return array_merge(dlSettingsDefaults(), getModuleSettings('daily-ledger'));
}

function dl_rolePermissions(): array
{
    $defaults = dl_defaultRolePermissions();
    $settings = getModuleSettings('daily-ledger');
    $raw = $settings['role_permissions'] ?? null;

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    if (!is_array($raw)) {
        return $defaults;
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

    return $result;
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

function dl_featureSettings(): array
{
    $settings = dlModuleSettings();

    return [
        'production_output_enabled' => dl_settingToBool($settings['production_output_enabled'] ?? false),
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
    try {
        $stmt = $db->query('SELECT id, name, sku, current_price, output_pieces_per_batch, output_unit_label FROM dl_products WHERE is_active = 1 ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $stmt = $db->query('SELECT id, name, sku, current_price FROM dl_products WHERE is_active = 1 ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($rows as &$row) {
        if (!array_key_exists('output_pieces_per_batch', $row)) {
            $row['output_pieces_per_batch'] = null;
        }
        if (!array_key_exists('output_unit_label', $row)) {
            $row['output_unit_label'] = 'pcs';
        }
    }
    unset($row);

    return $rows;
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

function dl_closeOfDaySettings(): array
{
    $settings = getModuleSettings('daily-ledger');

    return [
        'auto_close_enabled' => dl_settingToBool($settings['auto_close_enabled'] ?? false),
        'close_of_day_time' => dl_normalizeCloseOfDayTime($settings['close_of_day_time'] ?? '00:00'),
        'operating_timezone' => dl_normalizeTimezone($settings['operating_timezone'] ?? config('app.timezone', 'Asia/Manila')),
        'operating_region' => dl_normalizeRegion($settings['operating_region'] ?? ''),
    ];
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

    return true;
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

function dl_operatingClockLabel(): array
{
    $settings = dl_closeOfDaySettings();

    return [
        'business_date' => dl_businessDate(),
        'close_of_day_time' => $settings['close_of_day_time'],
        'auto_close_enabled' => $settings['auto_close_enabled'],
        'operating_timezone' => $settings['operating_timezone'],
        'operating_region' => $settings['operating_region'],
    ];
}

function dl_getBranchName(int $branchId): string
{
    $ctx = module();
    if (!$ctx) return 'Unknown';

    $stmt = $ctx->db()->prepare('SELECT name FROM dl_branches WHERE id = :id');
    $stmt->execute([':id' => $branchId]);
    return (string)($stmt->fetchColumn() ?: 'Unknown');
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
    if ($sub !== '' && preg_match('/^(?:admin|supervisor|cashier|production_in_charge):(\d+)$/', $sub, $m)) {
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
    if ($role === 'admin') {
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

function dl_applyLedgerDelta(int $branchId, int $productId, string $ledgerDate, int $delta, int $actorId, string $column = 'addtl'): array
{
    if (!in_array($column, ['addtl', 'withdraw'], true)) {
        throw new \RuntimeException('Invalid ledger column: ' . $column);
    }

    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    $select = $ctx->db()->prepare(
        'SELECT id, addtl, withdraw FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d LIMIT 1 FOR UPDATE'
    );
    $select->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

    $priceStmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :pid');
    $priceStmt->execute([':pid' => $productId]);
    $price = (float)($priceStmt->fetchColumn() ?: 0.0);

    if (!$row) {
        if ($delta < 0) {
            throw new \RuntimeException('Cannot reverse before an output/withdrawal exists for this date.');
        }
        $addtlVal = $column === 'addtl' ? $delta : 0;
        $withdrawVal = $column === 'withdraw' ? $delta : 0;
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :price, 0, :addtl, :withdraw, 0, :uid, :uid2)'
        );
        $ins->execute([
            ':bid' => $branchId,
            ':pid' => $productId,
            ':d' => $ledgerDate,
            ':price' => $price,
            ':addtl' => $addtlVal,
            ':withdraw' => $withdrawVal,
            ':uid' => $actorId > 0 ? $actorId : null,
            ':uid2' => $actorId > 0 ? $actorId : null,
        ]);
        dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId));
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

    dl_recomputeSales($branchId, $productId, $ledgerDate, max(0, $actorId));
    return [$column => $newVal];
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
    $reason = trim((string)($input['reason'] ?? ''));

    if ($destinationBranchId <= 0 || $productId <= 0 || $quantity <= 0 || $ledgerDate === '') {
        throw new \RuntimeException('destination_branch_id, product_id, quantity, and ledger_date are required.');
    }

    $allowedBranchIds = dl_accessibleBranchIds($user);
    if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
        throw new \RuntimeException('Destination branch is not allowed for this user.');
    }

    dl_maybeAutoCloseBranchDay($destinationBranchId, $actorId);

    $dayStatus = dl_getDayStatus($destinationBranchId, $ledgerDate);
    if ($dayStatus === 'closed' && !dl_roleHasPermission($role, 'production.override')) {
        throw new \RuntimeException('Day is closed for this branch.');
    }

    $referenceMovementId = null;
    $delta = $quantity;
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
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, flow_mode, movement_type
                 FROM dl_production_movements
                 WHERE id = :id AND movement_type IN ('withdrawal','output')
                 LIMIT 1"
            );
            $refStmt->execute([':id' => $refId]);
        } else {
            $refStmt = $ctx->db()->prepare(
                "SELECT id, destination_branch_id, product_id, quantity, ledger_date, flow_mode, movement_type
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
        $flowMode = (string)$ref['flow_mode'];

        if (!in_array($destinationBranchId, $allowedBranchIds, true)) {
            throw new \RuntimeException('You cannot reverse a movement outside your branch scope.');
        }

        $reverseExists = $ctx->db()->prepare("SELECT id FROM dl_production_movements WHERE reference_movement_id = :rid AND movement_type = 'reverse' LIMIT 1");
        $reverseExists->execute([':rid' => $referenceMovementId]);
        if ($reverseExists->fetchColumn()) {
            throw new \RuntimeException('Reference movement is already reversed.');
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

    $ctx->db()->beginTransaction();
    try {
        $ledgerState = dl_applyLedgerDelta($destinationBranchId, $productId, $ledgerDate, $delta, $actorId, $ledgerColumn);

        $movementUuid = dl_generateMovementUuid();
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_production_movements (
                movement_uuid, client_op_id, movement_type, flow_mode,
                destination_branch_id, product_id, ledger_date, quantity,
                override_reason, reference_movement_id, source_payload,
                created_by_id, created_by_role
             ) VALUES (
                :uuid, :coid, :mtype, :fmode,
                :bid, :pid, :ldate, :qty,
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
            ':qty' => $quantity,
            ':reason' => $reason !== '' ? $reason : null,
            ':refid' => $referenceMovementId,
            ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES),
            ':uid' => $actorId > 0 ? $actorId : null,
            ':role' => $role !== '' ? $role : 'unknown',
        ]);
        $movementId = (int)$ctx->db()->lastInsertId();

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
                'reference_movement_id' => $referenceMovementId,
                'reason' => $reason,
                'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            ],
            $reason !== '' ? $reason : null
        );

        $ctx->db()->commit();

        return [
            'movement_id' => $movementId,
            'movement_uuid' => $movementUuid,
            'movement_type' => $movementType,
            'flow_mode' => $flowMode,
            'destination_branch_id' => $destinationBranchId,
            'product_id' => $productId,
            'ledger_date' => $ledgerDate,
            'quantity' => $quantity,
            'resulting_' . $ledgerColumn => (int)($ledgerState[$ledgerColumn] ?? 0),
            'ledger_column' => $ledgerColumn,
            'duplicate' => false,
        ];
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        throw $e;
    }
}

function dl_recomputeSales(int $branchId, int $productId, string $date, int $userId): void
{
    try {
        $ctx = module();
        if (!$ctx) return;

        $stmt = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d'
        );
        $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $sales = max(0, (int)$row['beg_bal'] + (int)$row['addtl'] - (int)$row['withdraw'] - (int)$row['bal_end']);

        $ctx->db()->prepare(
            'UPDATE dl_daily_ledger SET sales = :sales, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d'
        )->execute([':sales' => $sales, ':uid' => $userId, ':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

function dl_computeVarianceSilently(int $branchId, int $productId, string $date, int $begBal): void
{
    $ctx = module();
    if (!$ctx) return;

    // Find previous day's bal_end for same branch+product
    $stmt = $ctx->db()->prepare(
        'SELECT bal_end FROM dl_daily_ledger
         WHERE branch_id = :bid AND product_id = :pid AND ledger_date < :d
         ORDER BY ledger_date DESC LIMIT 1'
    );
    $stmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prev) return; // No previous day — nothing to compare

    $prevBalEnd = (int)$prev['bal_end'];
    $variance   = $begBal - $prevBalEnd;

    if ($variance === 0) {
        // No variance — remove any existing flag
        $ctx->db()->prepare(
            'DELETE FROM dl_variance_flags WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d'
        )->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
        return;
    }

    // Upsert variance flag
    $ctx->db()->prepare(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, prev_bal_end, current_beg_bal, variance)
         VALUES (:bid, :pid, :d, :prev, :beg, :var)
         ON DUPLICATE KEY UPDATE prev_bal_end = VALUES(prev_bal_end), current_beg_bal = VALUES(current_beg_bal), variance = VALUES(variance)'
    )->execute([
        ':bid' => $branchId,
        ':pid' => $productId, ':d' => $date,
        ':prev' => $prevBalEnd, ':beg' => $begBal, ':var' => $variance,
    ]);
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
    if ($authHeader !== '' && preg_match('/Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim((string)($m[1] ?? ''));
    }
    // Fallback to module cookie for browser page requests
    if ($token === null || $token === '') {
        if (is_string($cookieToken) && $cookieToken !== '') {
            $token = $cookieToken;
        }
    }
    if (!is_string($token) || $token === '') {
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
    try {
        $payload = app()->jwt()->verify($token);
        if (!is_array($payload)) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/daily-ledger/api/')) {
                write_log('daily-ledger api auth invalid jwt', 'error', [
                    'path' => $path,
                    'token_len' => strlen($token),
                    'auth_header_present' => ($authHeader !== ''),
                    'cookie_present' => (is_string($cookieToken) && $cookieToken !== ''),
                ]);
            }
            return null;
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
        return null;
    }
}

function dlRequireAuth(array $roles = ['cashier', 'supervisor', 'admin']): array
{
    $u = dlUserFromRequest();
    if (!$u) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/daily-ledger/api/')) {
            dlJson(['ok' => false, 'error' => 'Auth required'], 401);
            exit;
        }
        dlRedirect('/daily-ledger/login');
    }
    return $u;
}

function pageDailyLedgerLogin(): void
{
    if (dlUserFromRequest()) {
        $role = (string)(dlUserFromRequest()['role'] ?? '');
        if ($role === 'cashier') {
            $redir = '/daily-ledger/ledger';
        } elseif ($role === 'production_in_charge') {
            $redir = '/daily-ledger/admin/production';
        } else {
            $redir = '/daily-ledger/admin/dashboard';
        }
        dlRedirect($redir);
    }
    echo dlRender('modules/daily-ledger/pages/login.disyl', [
        'page_title' => 'Daily Ledger Sign In',
    ]);
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
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
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
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        return;
    }

    $u = $auth['user'];
    $role = (string)($u['role'] ?? '');
    $sub = (string)($u['sub'] ?? '');
    $payload = [
        'sub' => $sub !== '' ? $sub : ($role . ':0'),
        'id' => (int)($u['id'] ?? 0),
        'username' => (string)($u['username'] ?? $username),
        'name' => (string)($u['full_name'] ?? $username),
        'role' => $role,
        'source' => 'daily-ledger',
    ];
    $tokens = dl_generateAuthTokens($payload);
    dlSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    if ($role === 'cashier') {
        $redirect = '/daily-ledger/ledger';
    } elseif ($role === 'production_in_charge') {
        $redirect = '/daily-ledger/admin/production';
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

    $branchId   = dl_getUserBranchId();
    $branchName = $branchId ? dl_getBranchName($branchId) : 'No Branch';
    $today      = dl_businessDate();
    $input = $ctx->input();
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : $today;
    $referenceOnly = ($role === 'cashier' && $ledgerDate !== $today);

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus  = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    // For supervisor/admin: get list of all branches for switcher
    $branches = [];
    if (in_array($role, ['admin', 'supervisor'], true)) {
        $stmt = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name');
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $canLedgerOverride = dl_roleHasPermission($role, 'ledger.override');
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/cashier/ledger.disyl', [
        'page_title'  => 'Daily Ledger',
        'user_name'   => $userName,
        'user_role'   => $role,
        'current_page'=> 'ledger',
        'base_url' => dlGetBaseUrl(),
        'dl_token'    => (string)kernelCookie(dlCookieName(), ''),
        'branch_id'   => $branchId,
        'branch_name' => $branchName,
        'ledger_date' => $ledgerDate,
        'today'       => $today,
        'day_status'  => $dayStatus,
        'branches'    => $branches,
        'is_cashier'  => ($role === 'cashier'),
        'reference_only' => $referenceOnly,
        'can_ledger_override' => $canLedgerOverride,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
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
    $role = (string)($user['role'] ?? '');
    $branchId   = dl_getUserBranchId();
    $input = $ctx->input();
    $ledgerDate = !empty($input['date']) ? (string)$input['date'] : dl_businessDate();
    $referenceOnly = ($role === 'cashier' && $ledgerDate !== dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus  = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        echo '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-light);">No branch assigned</td></tr>';
        return;
    }

    // Get all active products for this branch with their ledger data
    $stmt = $ctx->db()->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales, dl.price_snapshot
         FROM dl_products p
         INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
         LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d
         WHERE p.is_active = 1
         ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo dlRender('modules/daily-ledger/cashier/partials/ledger-rows.disyl', [
        'rows'        => $rows,
        'branch_id'   => $branchId,
        'ledger_date' => $ledgerDate,
        'day_status'  => $dayStatus,
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
    $role = (string)($user['role'] ?? '');
    $branchId = dl_getUserBranchId();
    if (in_array($role, ['admin', 'supervisor'], true)) {
        if (!empty($_GET['branch_id'])) {
            $branchId = (int)$_GET['branch_id'];
        } elseif (!empty($input['branch_id'])) {
            $branchId = (int)$input['branch_id'];
        }
    }
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $dayStatus = $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open';

    if (!$branchId) {
        $ctx->json(['ok' => true, 'rows' => [], 'day_status' => $dayStatus]);
        return;
    }

    $stmt = $ctx->db()->prepare(
        'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales
         FROM dl_products p
         INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
         LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d
         WHERE p.is_active = 1
         ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $ledgerDate]);
    $ctx->json([
        'ok' => true,
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'day_status' => $dayStatus,
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
    $role = (string)($user['role'] ?? '');
    $branchId = dl_getUserBranchId();
    if (in_array($role, ['admin', 'supervisor'], true)) {
        if (!empty($_GET['branch_id'])) {
            $branchId = (int)$_GET['branch_id'];
        } elseif (!empty($input['branch_id'])) {
            $branchId = (int)$input['branch_id'];
        }
    }
    $ledgerDate = !empty($_GET['date']) ? (string)$_GET['date'] : (!empty($input['date']) ? (string)$input['date'] : dl_businessDate());

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));
    }

    $ctx->json([
        'ok' => true,
        'day_status' => $branchId ? dl_getDayStatus($branchId, $ledgerDate) : 'open',
    ]);
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
    $branchId  = dl_getUserBranchId();
    $productId = (int)($input['product_id'] ?? 0);
    $field     = (string)($input['field'] ?? '');
    $value     = (int)($input['value'] ?? 0);
    $date      = (string)($input['date'] ?? dl_businessDate());
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
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['admin', 'supervisor'], true) && !empty($input['branch_id'])) {
        $branchId = (int)$input['branch_id'];
    }

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    // Validate field name and value
    $allowed = ['beg_bal', 'addtl', 'withdraw', 'bal_end', 'sales'];
    if (!in_array($field, $allowed, true) || !$branchId || !$productId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }
    if ($value < 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Value cannot be negative', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Value cannot be negative'], 422);
        return;
    }

    // Check day status — soft lock: cashier can't edit closed days
    $dayStatus = dl_getDayStatus($branchId, $date);
    if ($role === 'cashier' && $date !== dl_businessDate()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }
    if ($dayStatus === 'closed' && $role === 'cashier') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day is closed', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403);
        return;
    }

    // Get current price snapshot
    $priceStmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :pid');
    $priceStmt->execute([':pid' => $productId]);
    $currentPrice = (float)($priceStmt->fetchColumn() ?: 0);

    // Upsert the ledger row.
    // Note: ON DUPLICATE KEY requires a UNIQUE index on (branch_id, product_id, ledger_date).
    $sql = "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, price_snapshot, {$field}, encoded_by, updated_by)
            VALUES (:bid, :pid, :d, :price, :val, :uid, :uid2)
            ON DUPLICATE KEY UPDATE {$field} = :val2, updated_by = :uid3, updated_at = CURRENT_TIMESTAMP";

    try {
        // Get old value for audit
        $oldStmt = $ctx->db()->prepare(
            "SELECT {$field} FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d"
        );
        $oldStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
        $oldVal = $oldStmt->fetchColumn();

        $stmt = $ctx->db()->prepare($sql);
        try {
            $stmt->execute([
                ':bid'   => $branchId,
                ':pid'   => $productId,
                ':d'     => $date,
                ':price' => $currentPrice,
                ':val'   => $value,
                ':uid'   => $userId,
                ':uid2'  => $userId,
                ':val2'  => $value,
                ':uid3'  => $userId,
            ]);
        } catch (\Throwable $e2) {
            // Fallback for environments missing the expected UNIQUE index.
            $ctx->db()->beginTransaction();
            try {
                $existsStmt = $ctx->db()->prepare(
                    'SELECT id FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d LIMIT 1'
                );
                $existsStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
                $existingId = (int)($existsStmt->fetchColumn() ?: 0);

                if ($existingId > 0) {
                    $updateStmt = $ctx->db()->prepare(
                        "UPDATE dl_daily_ledger
                         SET {$field} = :val, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id"
                    );
                    $updateStmt->execute([':val' => $value, ':uid' => $userId, ':id' => $existingId]);
                } else {
                    $insertStmt = $ctx->db()->prepare(
                        "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, price_snapshot, {$field}, encoded_by, updated_by)
                         VALUES (:bid, :pid, :d, :price, :val, :uid, :uid2)"
                    );
                    $insertStmt->execute([
                        ':bid' => $branchId,
                        ':pid' => $productId,
                        ':d' => $date,
                        ':price' => $currentPrice,
                        ':val' => $value,
                        ':uid' => $userId,
                        ':uid2' => $userId,
                    ]);
                }
                $ctx->db()->commit();
            } catch (\Throwable $e3) {
                $ctx->db()->rollBack();
                throw $e3;
            }
        }

        // Silent variance computation when beg_bal changes
        if ($field === 'beg_bal') {
            dl_computeVarianceSilently($branchId, $productId, $date, $value);
        }

        // Auto-recompute sales = beg_bal + addtl - withdraw - bal_end (server-side)
        if ($field !== 'sales') {
            dl_recomputeSales($branchId, $productId, $date, $userId);
        }

        // Audit log (silent)
        dl_auditLog(
            'field_update',
            $branchId,
            'dl_daily_ledger',
            "{$branchId}-{$productId}-{$date}",
            [$field => $oldVal !== false ? (int)$oldVal : null],
            [$field => $value]
        );

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'field' => $field, 'value' => $value]);
    } catch (\Throwable $e) {
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
    $rows = $input['rows'] ?? null;

    $userId = dl_getActorUserId($user);
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $branchId = dl_getUserBranchId();
    if (in_array($role, ['admin', 'supervisor'], true)) {
        if (!empty($_GET['branch_id'])) {
            $branchId = (int)$_GET['branch_id'];
        } elseif (!empty($input['branch_id'])) {
            $branchId = (int)$input['branch_id'];
        }
    }

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    if (!$branchId || !is_array($rows) || count($rows) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // Check day status — cashier cannot edit closed days
    $dayStatus = dl_getDayStatus($branchId, $date);
    $isReadOnly = ($role === 'cashier' && ($date > dl_businessDate() || $dayStatus === 'closed'));

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
        $end = (int)($r['bal_end'] ?? 0);

        if ($beg < 0 || $add < 0 || $with < 0 || $end < 0) {
            $ctx->json(['ok' => false, 'error' => 'Values cannot be negative'], 422);
            return;
        }

        $normalized[] = [
            'product_id' => $productId,
            'beg_bal' => $beg,
            'addtl' => $add,
            'withdraw' => $with,
            'bal_end' => $end,
        ];
    }

    if (count($normalized) === 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid rows', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid rows'], 422);
        return;
    }

    try {
        if (!$isReadOnly) {
            $ctx->db()->beginTransaction();

        $priceStmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :pid');
        $selectOld = $ctx->db()->prepare(
            'SELECT beg_bal, addtl, withdraw, bal_end FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d FOR UPDATE'
        );

        $upsert = $ctx->db()->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :price, :beg, :addtl, :withdraw, :end, :uid, :uid2)
             ON DUPLICATE KEY UPDATE
                beg_bal = VALUES(beg_bal),
                addtl = VALUES(addtl),
                withdraw = VALUES(withdraw),
                bal_end = VALUES(bal_end),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($normalized as $r) {
            $pid = (int)$r['product_id'];

            $selectOld->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date]);
            $old = $selectOld->fetch(PDO::FETCH_ASSOC) ?: null;

            $priceStmt->execute([':pid' => $pid]);
            $currentPrice = (float)($priceStmt->fetchColumn() ?: 0);

            $upsert->execute([
                ':bid' => $branchId,
                ':pid' => $pid,
                ':d' => $date,
                ':price' => $currentPrice,
                ':beg' => (int)$r['beg_bal'],
                ':addtl' => (int)$r['addtl'],
                ':withdraw' => (int)$r['withdraw'],
                ':end' => (int)$r['bal_end'],
                ':uid' => $userId,
                ':uid2' => $userId,
            ]);

            // Beg-bal changes trigger variance check
            if ($old && array_key_exists('beg_bal', $old) && (int)$old['beg_bal'] !== (int)$r['beg_bal']) {
                dl_computeVarianceSilently($branchId, $pid, $date, (int)$r['beg_bal']);
            }

            // Always recompute sales from invariant
            dl_recomputeSales($branchId, $pid, $date, $userId);

            // Audit as a single event per product row
            dl_auditLog(
                'row_update',
                $branchId,
                'dl_daily_ledger',
                "{$branchId}-{$pid}-{$date}",
                $old,
                [
                    'beg_bal' => (int)$r['beg_bal'],
                    'addtl' => (int)$r['addtl'],
                    'withdraw' => (int)$r['withdraw'],
                    'bal_end' => (int)$r['bal_end'],
                ]
            );
        }

        $ctx->db()->commit();
        } // end if (!$isReadOnly)

        // Return updated rows as fresh read
        $stmt = $ctx->db()->prepare(
            'SELECT p.id AS product_id, p.name, p.current_price, p.sort_order,
                    COALESCE(dl.beg_bal, 0) AS beg_bal, COALESCE(dl.addtl, 0) AS addtl,
                    COALESCE(dl.withdraw, 0) AS withdraw, COALESCE(dl.bal_end, 0) AS bal_end,
                    GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) AS sales
             FROM dl_products p
             INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
             LEFT JOIN dl_daily_ledger dl ON dl.product_id = p.id AND dl.branch_id = :bid2 AND dl.ledger_date = :d
             WHERE p.is_active = 1
             ORDER BY p.sort_order, p.name'
        );
        $stmt->execute([':bid' => $branchId, ':bid2' => $branchId, ':d' => $date]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Saved', 'type' => 'success']]));
        $ctx->json([
            'ok' => true,
            'branch_id' => $branchId,
            'date' => $date,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'day_status' => $dayStatus,
        ]);
    } catch (\Throwable $e) {
        try {
            if ($ctx->db()->inTransaction()) {
                $ctx->db()->rollBack();
            }
        } catch (\Throwable $ignored) {
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

    $sql = 'SELECT id, sku, name, current_price, product_category, output_pieces_per_batch, output_unit_label
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
                pm.ledger_date, pm.quantity, pm.override_reason,
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

    $ctx->json([
        'ok' => true,
        'summary' => [
            'total' => count($results),
            'succeeded' => $okCount,
            'failed' => count($results) - $okCount,
        ],
        'results' => $results,
    ]);
}

function apiCloseDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);

    $branchId = dl_getUserBranchId();
    $input = $ctx->input();
    $date     = (string)($input['date'] ?? dl_businessDate());
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
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
        return;
    }

    if (!$branchId) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'No branch', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'No branch'], 422);
        return;
    }

    if ((string)($user['role'] ?? '') === 'cashier' && $date !== dl_businessDate()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Reference only', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Reference only'], 403);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
             VALUES (:bid, :d, \'closed\', :uid, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = \'closed\', closed_by = :uid2, closed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':bid' => $branchId, ':d' => $date, ':uid' => $userId, ':uid2' => $userId]);

        dl_auditLog('close_day', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", null, ['status' => 'closed']);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Day closed', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'day_status' => 'closed']);
    } catch (\Throwable $e) {
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
    $branchId = (int)($input['branch_id'] ?? 0);
    $date     = (string)($input['date'] ?? '');
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
    if ($userId <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Auth required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Auth required'], 401);
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

    $user = dlCurrentUser(['admin', 'supervisor']);

    $today    = dl_businessDate();
    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    dl_maybeAutoCloseBranches(array_column($branches, 'id'), dl_getActorUserId($user));

    // Today's sales per branch — computed: sales = beg_bal + addtl - withdraw - bal_end
    $salesStmt = $ctx->db()->prepare(
        'SELECT dl.branch_id, b.name AS branch_name,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end)) AS total_units,
                SUM(GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end) * dl.price_snapshot) AS total_amount,
                COUNT(DISTINCT dl.product_id) AS product_count
         FROM dl_daily_ledger dl
         INNER JOIN dl_branches b ON b.id = dl.branch_id
         WHERE dl.ledger_date = :d
         GROUP BY dl.branch_id
         ORDER BY b.name'
    );
    $salesStmt->execute([':d' => $today]);
    $todaySales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Day status per branch
    $statusStmt = $ctx->db()->prepare(
        'SELECT branch_id, status FROM dl_ledger_day_status WHERE ledger_date = :d'
    );
    $statusStmt->execute([':d' => $today]);
    $dayStatuses = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        $dayStatuses[(int)$s['branch_id']] = $s['status'];
    }

    // Unreviewed variance count
    $varStmt = $ctx->db()->query('SELECT COUNT(*) FROM dl_variance_flags WHERE is_reviewed = 0');
    $unreviewedVariances = (int)$varStmt->fetchColumn();

    // Recent encoder activity (last 20)
    $activityStmt = $ctx->db()->prepare(
        'SELECT a.action, a.created_at, b.name AS branch_name,
                a.old_data, a.new_data
         FROM audit_logs a
         LEFT JOIN dl_branches b ON b.id = a.branch_id
         WHERE a.module = \'daily-ledger\'
         ORDER BY a.created_at DESC LIMIT 20'
    );
    $activityStmt->execute();
    $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Join branches + sales + day-statuses into card data.
    // Pass raw numeric values — let DiSyL handle formatting (currency, number_format).
    $salesByBranch = [];
    foreach ($todaySales as $ts) {
        $salesByBranch[(int)$ts['branch_id']] = $ts;
    }

    $totalUnitsToday = 0;
    $totalAmountToday = 0.0;
    $branchCards = [];
    foreach ($branches as $br) {
        $bid = (int)$br['id'];
        $ts = $salesByBranch[$bid] ?? null;
        $units  = $ts ? (int)$ts['total_units'] : 0;
        $amount = $ts ? (float)$ts['total_amount'] : 0.0;
        $status = $dayStatuses[$bid] ?? 'none';
        $totalUnitsToday  += $units;
        $totalAmountToday += $amount;
        $branchCards[] = [
            'name'   => $br['name'],
            'units'  => $units,
            'amount' => $amount,
            'status' => $status,
        ];
    }

    $role = (string)($user['role'] ?? '');
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
        'unreviewed_variances'  => $unreviewedVariances,
        'recent_activity'       => $recentActivity,
        'total_units_today'     => $totalUnitsToday,
        'total_amount_today'    => $totalAmountToday,
        'business_date_label'   => $clockLabel['business_date'],
        'close_of_day_time'     => $clockLabel['close_of_day_time'],
        'auto_close_enabled'    => $clockLabel['auto_close_enabled'],
        'operating_timezone'    => $clockLabel['operating_timezone'],
        'operating_region'      => $clockLabel['operating_region'],
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

    $user = dlRequireAuth(['admin', 'supervisor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $today;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $search   = trim((string)($input['q'] ?? ''));

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Sales data with computed sales and amount (admin can see these)
    $sql = 'SELECT dl.ledger_date, p.name AS product_name, p.sku, b.name AS branch_name,
                   dl.beg_bal, dl.addtl, dl.withdraw, dl.bal_end,
                   GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end) AS sales,
                   dl.price_snapshot,
                   (GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end) * dl.price_snapshot) AS amount
            FROM dl_daily_ledger dl
            INNER JOIN dl_products p ON p.id = dl.product_id
            INNER JOIN dl_branches b ON b.id = dl.branch_id
            WHERE dl.ledger_date BETWEEN :df AND :dt';
    $bind = [':df' => $dateFrom, ':dt' => $dateTo];

    if ($branchId) {
        $sql .= ' AND dl.branch_id = :bid';
        $bind[':bid'] = $branchId;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q2 OR b.name LIKE :q3)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%"; $bind[':q3'] = "%{$search}%";
    }
    $sql .= ' ORDER BY dl.ledger_date DESC, b.name, p.name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Grand totals
    $grandUnits  = 0;
    $grandAmount = 0.0;
    foreach ($salesRows as $r) {
        $grandUnits  += (int)$r['sales'];
        $grandAmount += (float)$r['amount'];
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $clockLabel = dl_operatingClockLabel();
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
        'grand_units'  => $grandUnits,
        'grand_amount' => $grandAmount,
        'search'       => $search,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
    ]);
}

function handleAdminProduction(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    $today = dl_businessDate();
    $ledgerDate = !empty($input['ledger_date']) ? (string)$input['ledger_date'] : $today;
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : date('Y-m-d', strtotime($today . ' -7 days'));
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : $today;

    $allowedBranchIds = dl_accessibleBranchIds($user);
    dl_maybeAutoCloseBranches($allowedBranchIds, dl_getActorUserId($user));
    $branches = [];
    $products = [];
    $movementRows = [];

    if (count($allowedBranchIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));

        $branchStmt = $ctx->db()->prepare(
            "SELECT id, code, name, COALESCE(area, '') AS area
             FROM dl_branches
             WHERE is_active = 1 AND id IN ({$placeholders})
             ORDER BY area, name"
        );
        $branchStmt->execute($allowedBranchIds);
        $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $products = dl_fetchActiveProductsForProduction($ctx->db());

        $moveSql =
            "SELECT pm.id, pm.destination_branch_id, pm.product_id,
                    pm.movement_type, pm.flow_mode, pm.ledger_date, pm.quantity,
                    pm.override_reason, pm.created_at,
                    b.name AS destination_name, b.code AS destination_code,
                    COALESCE(b.area, '') AS destination_area,
                    p.name AS product_name, p.sku,
                    pm.created_by_role,
                    EXISTS(
                        SELECT 1
                        FROM dl_production_movements r
                        WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
                    ) AS has_reverse
             FROM dl_production_movements pm
             INNER JOIN dl_branches b ON b.id = pm.destination_branch_id
             INNER JOIN dl_products p ON p.id = pm.product_id
             WHERE pm.destination_branch_id IN ({$placeholders})
               AND pm.ledger_date BETWEEN ? AND ?
               AND (pm.movement_type = 'withdrawal'
                    OR (pm.movement_type = 'reverse' AND pm.reference_movement_id IN (
                        SELECT id FROM dl_production_movements WHERE movement_type = 'withdrawal'
                    )))
             ORDER BY pm.created_at DESC
             LIMIT 200";
        $bind = $allowedBranchIds;
        $bind[] = $dateFrom;
        $bind[] = $dateTo;
        $moveStmt = $ctx->db()->prepare($moveSql);
        $moveStmt->execute($bind);
        $movementRows = $moveStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $areas = [];
    foreach ($branches as $b) {
        $a = trim((string)($b['area'] ?? ''));
        if ($a !== '' && !in_array($a, $areas, true)) {
            $areas[] = $a;
        }
    }
    sort($areas);

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $canProductionOverride = dl_roleHasPermission($role, 'production.override');
    $featureSettings = dl_featureSettings();
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/production.disyl', [
        'page_title' => 'Production Withdrawal',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'production',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'ledger_date' => $ledgerDate,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branches' => $branches,
        'products' => $products,
        'movement_rows' => $movementRows,
        'can_production_override' => $canProductionOverride,
        'can_production_output' => $featureSettings['production_output_enabled'],
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'areas' => $areas,
    ]);
}

function handleAdminProductionOutput(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    $today = dl_businessDate();
    $ledgerDate = !empty($input['ledger_date']) ? (string)$input['ledger_date'] : $today;
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : date('Y-m-d', strtotime($today . ' -7 days'));
    $dateTo = !empty($input['date_to']) ? (string)$input['date_to'] : $today;

    $allowedBranchIds = dl_accessibleBranchIds($user);
    dl_maybeAutoCloseBranches($allowedBranchIds, dl_getActorUserId($user));
    $branches = [];
    $products = [];
    $movementRows = [];

    if (count($allowedBranchIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($allowedBranchIds), '?'));

        $branchStmt = $ctx->db()->prepare(
            "SELECT id, code, name, COALESCE(area, '') AS area
             FROM dl_branches
             WHERE is_active = 1 AND id IN ({$placeholders})
             ORDER BY area, name"
        );
        $branchStmt->execute($allowedBranchIds);
        $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $products = dl_fetchActiveProductsForProduction($ctx->db());

        $moveSql =
            "SELECT pm.id, pm.destination_branch_id, pm.product_id,
                    pm.movement_type, pm.flow_mode, pm.ledger_date, pm.quantity,
                    pm.override_reason, pm.created_at,
                    b.name AS destination_name, b.code AS destination_code,
                    COALESCE(b.area, '') AS destination_area,
                    p.name AS product_name, p.sku,
                    pm.created_by_role,
                    EXISTS(
                        SELECT 1
                        FROM dl_production_movements r
                        WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
                    ) AS has_reverse
             FROM dl_production_movements pm
             INNER JOIN dl_branches b ON b.id = pm.destination_branch_id
             INNER JOIN dl_products p ON p.id = pm.product_id
             WHERE pm.destination_branch_id IN ({$placeholders})
               AND pm.ledger_date BETWEEN ? AND ?
               AND (pm.movement_type = 'output'
                    OR (pm.movement_type = 'reverse' AND pm.reference_movement_id IN (
                        SELECT id FROM dl_production_movements WHERE movement_type = 'output'
                    )))
             ORDER BY pm.created_at DESC
             LIMIT 200";
        $bind = $allowedBranchIds;
        $bind[] = $dateFrom;
        $bind[] = $dateTo;
        $moveStmt = $ctx->db()->prepare($moveSql);
        $moveStmt->execute($bind);
        $movementRows = $moveStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $areas = [];
    foreach ($branches as $b) {
        $a = trim((string)($b['area'] ?? ''));
        if ($a !== '' && !in_array($a, $areas, true)) {
            $areas[] = $a;
        }
    }
    sort($areas);

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $featureSettings = dl_featureSettings();
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/production-output.disyl', [
        'page_title' => 'Production Output',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'production_output',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'ledger_date' => $ledgerDate,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branches' => $branches,
        'products' => $products,
        'movement_rows' => $movementRows,
        'can_production_output' => $featureSettings['production_output_enabled'],
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
        'areas' => $areas,
    ]);
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
    $isKernelAdmin = dl_isKernelAdmin($user);
    $permissions = dl_rolePermissions();
    $closeOfDaySettings = dl_closeOfDaySettings();
    $featureSettings = dl_featureSettings();

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
        'perm_prod_ledger_override' => in_array('ledger.override', $permissions['production_in_charge'] ?? [], true),
        'perm_prod_production_override' => in_array('production.override', $permissions['production_in_charge'] ?? [], true),
        'auto_close_enabled' => $closeOfDaySettings['auto_close_enabled'],
        'close_of_day_time' => $closeOfDaySettings['close_of_day_time'],
        'operating_timezone' => $closeOfDaySettings['operating_timezone'],
        'operating_region' => $closeOfDaySettings['operating_region'],
        'operating_timezone_choices' => dl_operatingTimezoneChoices($closeOfDaySettings['operating_timezone']),
        'operating_region_choices' => dl_operatingRegionChoices($closeOfDaySettings['operating_region']),
        'is_kernel_admin' => $isKernelAdmin,
        'production_output_enabled' => $featureSettings['production_output_enabled'],
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
    $isKernelAdmin = dl_isKernelAdmin($user);
    $input = $ctx->input();

    $toBool = static function ($v): bool {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    };

    $permissions = [
        'admin' => ['ledger.override', 'production.override'],
        'supervisor' => [],
        'production_in_charge' => [],
        'cashier' => [],
    ];

    if ($toBool($input['supervisor_ledger_override'] ?? false)) {
        $permissions['supervisor'][] = 'ledger.override';
    }
    if ($toBool($input['supervisor_production_override'] ?? false)) {
        $permissions['supervisor'][] = 'production.override';
    }
    if ($toBool($input['prod_ledger_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'ledger.override';
    }
    if ($toBool($input['prod_production_override'] ?? false)) {
        $permissions['production_in_charge'][] = 'production.override';
    }

    $autoCloseEnabled = $toBool($input['auto_close_enabled'] ?? false);
    $closeOfDayTime = dl_normalizeCloseOfDayTime($input['close_of_day_time'] ?? '00:00');
    $operatingTimezone = dl_normalizeTimezone($input['operating_timezone'] ?? config('app.timezone', 'Asia/Manila'));
    $operatingRegion = dl_normalizeRegion($input['operating_region'] ?? '');
    $featureSettings = dl_featureSettings();
    $productionOutputEnabled = $featureSettings['production_output_enabled'];

    if (array_key_exists('production_output_enabled', $input)) {
        if (!$isKernelAdmin) {
            $ctx->json([
                'ok' => false,
                'error' => 'Only Kernel Admin or Superadmin can change feature activation.',
            ], 403);
            return;
        }
        $productionOutputEnabled = $toBool($input['production_output_enabled']);
    }

    if ($autoCloseEnabled && !dl_isAllowedAutoCloseTime($closeOfDayTime)) {
        $ctx->json([
            'ok' => false,
            'error' => 'Auto close cutoff must be a valid time (00:00 - 23:59).',
        ], 422);
        return;
    }

    saveModuleSettings('daily-ledger', [
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled ? '1' : '0',
        'close_of_day_time' => $closeOfDayTime,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled ? '1' : '0',
    ]);

    dl_auditLog('update_role_permissions', null, 'module_settings', 'daily-ledger', null, [
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled,
        'is_kernel_admin' => $isKernelAdmin,
        'updated_by_role' => (string)($user['role'] ?? ''),
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings updated', 'type' => 'success']]));
    $ctx->json([
        'ok' => true,
        'role_permissions' => $permissions,
        'auto_close_enabled' => $autoCloseEnabled,
        'close_of_day_time' => $closeOfDayTime,
        'operating_timezone' => $operatingTimezone,
        'operating_region' => $operatingRegion,
        'production_output_enabled' => $productionOutputEnabled,
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

    $user = dlCurrentUser(['admin', 'supervisor']);

    $input = $ctx->input();
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $statusFilter = $input['status'] ?? null;
    $search   = trim((string)($input['q'] ?? ''));

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sql = 'SELECT vf.*, p.name AS product_name, b.name AS branch_name,
                   COALESCE(reviewer.full_name, \'Unknown\') AS reviewer_name
            FROM dl_variance_flags vf
            INNER JOIN dl_products p ON p.id = vf.product_id
            INNER JOIN dl_branches b ON b.id = vf.branch_id
            LEFT JOIN dl_users reviewer ON reviewer.id = vf.reviewed_by
            WHERE 1=1';
    $bind = [];

    if ($branchId) {
        $sql .= ' AND vf.branch_id = :bid';
        $bind[':bid'] = $branchId;
    }
    if ($statusFilter && in_array($statusFilter, ['unreviewed', 'investigated', 'corrected'], true)) {
        $sql .= ' AND vf.resolution_status = :st';
        $bind[':st'] = $statusFilter;
    }
    if ($search !== '') {
        $sql .= ' AND (p.name LIKE :q OR b.name LIKE :q2)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%";
    }

    $sql .= ' ORDER BY vf.ledger_date DESC, b.name, p.name';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $variances = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    echo dlRender('modules/daily-ledger/admin/variances.disyl', [
        'page_title'    => 'Variance Flags',
        'user_name'     => $userName,
        'user_role'     => $role,
        'current_page'  => 'variances',
        'base_url' => dlGetBaseUrl(),
        'dl_token'      => (string)kernelCookie(dlCookieName(), ''),
        'branch_id'     => $branchId,
        'status_filter' => $statusFilter,
        'branches'      => $branches,
        'variances'     => $variances,
        'search'        => $search,
    ]);
}

function handleAdminActivity(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor']);

    $input = $ctx->input();
    $today = dl_businessDate();
    $dateFrom = !empty($input['date_from']) ? (string)$input['date_from'] : $today;
    $dateTo   = !empty($input['date_to']) ? (string)$input['date_to'] : $today;
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $search   = trim((string)($input['q'] ?? ''));

    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sql = 'SELECT a.action, a.created_at, a.old_data, a.new_data,
                   b.name AS branch_name
            FROM audit_logs a
            LEFT JOIN dl_branches b ON b.id = a.branch_id
            WHERE a.module = \'daily-ledger\'
              AND DATE(a.created_at) BETWEEN :df AND :dt';
    $bind = [':df' => $dateFrom, ':dt' => $dateTo];

    if ($branchId) {
        $sql .= ' AND a.branch_id = :bid';
        $bind[':bid'] = $branchId;
    }
    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE :q OR a.action LIKE :q2 OR b.name LIKE :q3)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%"; $bind[':q3'] = "%{$search}%";
    }

    $sql .= ' ORDER BY a.created_at DESC LIMIT 500';

    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        'branch_id' => $branchId,
        'branches' => $branches,
        'search' => $search,
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
    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));

    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.product_id = p.id AND bp.is_active = 1) AS branch_count
            FROM dl_products p WHERE 1=1';
    $bind = [];
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
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_variance_flags
             SET resolution_status = :st,
                 reviewed_by = :rb,
                 reviewed_at = CURRENT_TIMESTAMP,
                 is_reviewed = CASE WHEN :st2 = \'unreviewed\' THEN 0 ELSE 1 END
             WHERE id = :id'
        );
        $stmt->execute([
            ':st' => $status,
            ':st2' => $status,
            ':rb' => $reviewedBy,
            ':id' => $varianceId,
        ]);

        if ($stmt->rowCount() <= 0) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Variance not found'], 404);
            return;
        }

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Variance updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
        return;
    } catch (\Throwable $e) {
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

    if ($name === '' || $price <= 0) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Name and price are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Name and price are required'], 422);
        return;
    }

    $sku = dl_generateSku();
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

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_products (sku, name, product_category, current_price, sort_order, output_pieces_per_batch, output_unit_label) VALUES (:sku, :name, :cat, :price, :sort, :oppb, :unit)'
        )->execute([':sku' => $sku, ':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sort, ':oppb' => $outputPiecesPerBatch, ':unit' => $outputUnitLabel]);

        $productId = (int)$ctx->db()->lastInsertId();

        // Record price history
        $ctx->db()->prepare(
            'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
        )->execute([':pid' => $productId, ':price' => $price, ':uid' => $kernelActorUserId]);

        // Assign to all active branches by default
        $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
        foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
            )->execute([':bid' => (int)$br['id'], ':pid' => $productId]);
        }

        dl_auditLog('create_product', null, 'product', (string)$productId, null, [
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product created', 'type' => 'success']]));
        $ctx->json([
            'ok' => true,
            'product_id' => $productId,
            'sku' => $sku,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);
    } catch (\Throwable $e) {
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
    $sort      = (int)($input['sort_order'] ?? 0);
    $isActive  = (int)($input['is_active'] ?? 1);
    $outputPiecesPerBatch = dl_normalizePiecesPerBatch($input['output_pieces_per_batch'] ?? null);
    $outputUnitLabel = dl_normalizeOutputUnitLabel($input['output_unit_label'] ?? 'pcs');
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

    if (!$productId || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // dl_product_price_history.changed_by has an FK to kernel users.id.
    // Daily-ledger JWTs intentionally use id=0; use NULL when we don't have a kernel actor id.
    $kernelActorUserId = null;
    if (($user['source'] ?? '') === 'kernel' && isset($user['id']) && is_numeric($user['id']) && (int)$user['id'] > 0) {
        $kernelActorUserId = (int)$user['id'];
    }

    try {
        // Get old data
        $oldStmt = $ctx->db()->prepare('SELECT name, current_price, sort_order, is_active, output_pieces_per_batch, output_unit_label FROM dl_products WHERE id = :id');
        $oldStmt->execute([':id' => $productId]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product not found', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Product not found'], 404);
            return;
        }

        // If price changed, record in price history (immutable snapshot)
        if ((float)$old['current_price'] !== $price) {
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
            )->execute([':pid' => $productId, ':price' => $price, ':uid' => $kernelActorUserId]);
        }

        $ctx->db()->prepare(
            'UPDATE dl_products SET name = :name, product_category = :cat, current_price = :price, sort_order = :sort, is_active = :active, output_pieces_per_batch = :oppb, output_unit_label = :unit WHERE id = :id'
        )->execute([':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sort, ':active' => $isActive, ':oppb' => $outputPiecesPerBatch, ':unit' => $outputUnitLabel, ':id' => $productId]);

        dl_auditLog('update_product', null, 'product', (string)$productId, $old, [
            'name' => $name,
            'price' => $price,
            'sort_order' => $sort,
            'is_active' => $isActive,
            'output_pieces_per_batch' => $outputPiecesPerBatch,
            'output_unit_label' => $outputUnitLabel,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Product updated', 'type' => 'success']]));
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
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

    $sql = 'SELECT b.*,
                (SELECT COUNT(*) FROM dl_user_branches ub INNER JOIN dl_users u ON u.id = ub.user_id WHERE ub.branch_id = b.id AND u.role = \'cashier\' AND u.is_active = 1 AND u.deleted_at IS NULL) AS user_count,
                (SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.branch_id = b.id AND bp.is_active = 1) AS product_count
            FROM dl_branches b WHERE 1=1';
    $bind = [];
    if ($search !== '') {
        $sql .= ' AND (b.name LIKE :q OR b.code LIKE :q2 OR b.address LIKE :q3)';
        $bind[':q'] = "%{$search}%"; $bind[':q2'] = "%{$search}%"; $bind[':q3'] = "%{$search}%";
    }
    $sql .= ' ORDER BY b.name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        'search' => $search,
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

    if ($code === '' || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Code and name are required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Code and name are required'], 422);
        return;
    }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_branches (code, name, address, area) VALUES (:code, :name, :addr, :area)'
        )->execute([':code' => $code, ':name' => $name, ':addr' => $address, ':area' => $area !== '' ? $area : null]);

        $branchId = (int)$ctx->db()->lastInsertId();

        // Assign all active products to new branch
        $pStmt = $ctx->db()->query('SELECT id FROM dl_products WHERE is_active = 1');
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $ctx->db()->prepare(
                'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
            )->execute([':bid' => $branchId, ':pid' => (int)$p['id']]);
        }

        dl_auditLog('create_branch', $branchId, 'branch', (string)$branchId, null, ['code' => $code, 'name' => $name, 'area' => $area]);

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

    if (!$branchId || $name === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    try {
        $ctx->db()->prepare(
            'UPDATE dl_branches SET name = :name, address = :addr, area = :area, is_active = :active WHERE id = :id'
        )->execute([':name' => $name, ':addr' => $address, ':area' => $area !== '' ? $area : null, ':active' => $isActive, ':id' => $branchId]);

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

    $sql = "SELECT u.id, u.username, u.full_name, u.role,
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
        $sql .= ' AND (u.username LIKE :q OR u.full_name LIKE :q2 OR u.role LIKE :q3)';
        $bind[':q'] = "%{$search}%";
        $bind[':q2'] = "%{$search}%";
        $bind[':q3'] = "%{$search}%";
    }
    $sql .= ' ORDER BY u.full_name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    $password = (string)($input['password'] ?? '');
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role     = (string)($input['role'] ?? 'cashier');
    $branchId = (int)($input['branch_id'] ?? 0);

    if ($username === '' || $password === '' || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'All fields required', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'All fields required'], 422);
        return;
    }

    if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
        return;
    }

    $branchIds = $input['branch_ids'] ?? [];
    if (!is_array($branchIds)) {
        $branchIds = [];
    }
    $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static function ($v) {
        return $v > 0;
    })));

    // Cashiers MUST have a branch; admin/supervisor don't need one
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

        $ctx->db()->prepare(
            'INSERT INTO dl_users (username, password_hash, full_name, role, is_active)
             VALUES (:u, :p, :n, :r, 1)'
        )->execute([':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role]);
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
            'username' => $username,
            'role' => $role,
            'branch_id' => $role === 'cashier' ? $branchId : null,
            'branch_ids' => in_array($role, ['supervisor', 'production_in_charge'], true) ? $branchIds : null,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User created', 'type' => 'success']]));
        $ctx->json(['ok' => true, 'user_id' => $newUserId]);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Username already exists', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Username already exists'], 409);
        } else {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create user', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Failed to create user'], 500);
        }
    }
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
    $role     = (string)($input['role'] ?? '');
    $isActive = (int)($input['is_active'] ?? 1);
    $password = (string)($input['password'] ?? '');
    $branchId = (int)($input['branch_id'] ?? 0);

    if (!$editId || $fullName === '') {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid input', 'type' => 'error']]));
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    try {
        if (!in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
            $ctx->json(['ok' => false, 'error' => 'Invalid role'], 422);
            return;
        }

        $st = $ctx->db()->prepare('SELECT role, deleted_at FROM dl_users WHERE id = :id LIMIT 1');
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

        $sql = 'UPDATE dl_users SET full_name = :name, is_active = :active';
        $bind = [':name' => $fullName, ':active' => $isActive, ':id' => $editId];
        if ($password !== '') {
            $sql .= ', password_hash = :pass';
            $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id = :id';
        $ctx->db()->prepare($sql)->execute($bind);

        if (in_array($role, ['cashier', 'supervisor', 'production_in_charge'], true)) {
            // Reset branch assignments. Cashier has exactly one; the rest can have many.
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

        dl_auditLog('update_user', null, 'user', (string)$editId, null, [
            'role' => $role,
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

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    // Prevent self-delete: dlCurrentUser sub is "<role>:<id>" for module logins.
    $sub = (string)($user['sub'] ?? '');
    if ($sub === $role . ':' . $userId) {
        $ctx->json(['ok' => false, 'error' => 'You cannot delete your own account'], 403);
        return;
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

        dl_auditLog('delete_user', null, 'user', (string)$userId, null, ['role' => $role]);
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

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
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

        dl_auditLog('restore_user', null, 'user', (string)$userId, null, ['role' => $role]);
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

    $headers = ['SKU', 'Name', 'Category', 'Price', 'Sort Order', 'Active', 'Output Pieces', 'Output Unit'];
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [
            'SKU'           => (string)($item['sku'] ?? ''),
            'Name'          => (string)($item['name'] ?? ''),
            'Category'      => ucfirst((string)($item['product_category'] ?? 'bread')),
            'Price'         => (string)($item['current_price'] ?? '0.00'),
            'Sort Order'    => (string)($item['sort_order'] ?? '0'),
            'Active'        => ((int)($item['is_active'] ?? 1) === 1) ? '1' : '0',
            'Output Pieces' => (string)($item['output_pieces_per_batch'] ?? '0'),
            'Output Unit'   => (string)($item['output_unit_label'] ?? 'pcs'),
        ];
    }

    dlCsvResponse('products-' . date('Y-m-d-His') . '.csv', $headers, $rows);
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
        
        foreach ($rows as $rowIndex => $row) {
            $normalizedRow = [];
            foreach ($row as $k => $v) {
                $normalizedRow[dlCsvNormalizeHeader((string)$k)] = $v;
            }
            
            $name = trim((string)($normalizedRow['name'] ?? ''));
            $price = dlCsvNullableFloat($normalizedRow['price'] ?? null);
            if ($name === '' || $price === null || $price < 0) {
                $skipped++;
                continue;
            }
            
            $sku = trim((string)($normalizedRow['sku'] ?? ''));
            $sortOrder = dlCsvNullableInt($normalizedRow['sort_order'] ?? null) ?? 0;
            $isActive = isset($normalizedRow['active']) && in_array(strtolower(trim((string)$normalizedRow['active'])), ['0', 'false', 'no']) ? 0 : 1;
            $oppb = dl_normalizePiecesPerBatch(dlCsvNullableInt($normalizedRow['output_pieces'] ?? null) ?? 0);
            $oul = dl_normalizeOutputUnitLabel($normalizedRow['output_unit'] ?? 'pcs');
            
            if ($sku !== '') {
                $stmt = $ctx->db()->prepare('SELECT id, current_price FROM dl_products WHERE sku = :sku');
                $stmt->execute([':sku' => $sku]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $category = 'bread';
                if (isset($normalizedRow['category'])) {
                    $val = strtolower(trim((string)$normalizedRow['category']));
                    if (in_array($val, ['bread', 'cake', 'other'])) {
                        $category = $val;
                    }
                }
                
                if ($existing) {
                    $pid = (int)$existing['id'];
                    $oldPrice = (float)$existing['current_price'];
                    
                    $ctx->db()->prepare(
                        'UPDATE dl_products SET name = :name, product_category = :cat, current_price = :price, sort_order = :sort, is_active = :act, output_pieces_per_batch = :oppb, output_unit_label = :oul WHERE id = :id'
                    )->execute([
                        ':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $sortOrder, ':act' => $isActive, ':oppb' => $oppb, ':oul' => $oul, ':id' => $pid
                    ]);
                    
                    if (abs($oldPrice - $price) > 0.001) {
                        $ctx->db()->prepare(
                            'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
                        )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId]);
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
                        'sku' => $sku, 'name' => $name, 'price' => $price, 'is_active' => $isActive
                    ]);
                    $updated++;
                    continue;
                }
            }
            
            // Create New
            if ($sku === '') $sku = dl_generateSku();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_products (sku, name, current_price, sort_order, is_active, output_pieces_per_batch, output_unit_label) VALUES (:sku, :name, :price, :sort, :act, :oppb, :oul)'
            )->execute([
                ':sku' => $sku, ':name' => $name, ':price' => $price, ':sort' => $sortOrder, ':act' => $isActive, ':oppb' => $oppb, ':oul' => $oul
            ]);
            $pid = (int)$ctx->db()->lastInsertId();
            
            $ctx->db()->prepare(
                'INSERT INTO dl_product_price_history (product_id, price, changed_by) VALUES (:pid, :price, :uid)'
            )->execute([':pid' => $pid, ':price' => $price, ':uid' => $kernelActorUserId]);
            
            $brStmt = $ctx->db()->query('SELECT id FROM dl_branches WHERE is_active = 1');
            foreach ($brStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $br) {
                $ctx->db()->prepare(
                    'INSERT IGNORE INTO dl_branch_products (branch_id, product_id) VALUES (:bid, :pid)'
                )->execute([':bid' => (int)$br['id'], ':pid' => $pid]);
            }
            
            dl_auditLog('create_product', null, 'product', (string)$pid, null, [
                'csv_import' => true,
                'sku' => $sku, 'name' => $name, 'price' => $price, 'is_active' => $isActive
            ]);
            $created++;
        }
        
        $msg = "Imported: $created created, $updated updated, $skipped skipped.";
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

function handleAdminCommissary(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $db = $ctx->db();

    $input = $ctx->input();
    $rawDate = (string)($input['date'] ?? '');
    if ($rawDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        $rawDate = date('Y-m-d');
    }

    // Load available products for the dropdown
    $productsStmt = $db->query("SELECT id, name, product_category, is_active FROM dl_products ORDER BY product_category ASC, sort_order ASC, name ASC");
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

    // Load production runs for the date
    $runsStmt = $db->prepare(
        "SELECT pr.*, p.name as product_name
         FROM dl_production_runs pr
         JOIN dl_products p ON pr.product_id = p.id
         WHERE pr.ledger_date = :date"
    );
    $runsStmt->execute([':date' => $rawDate]);
    $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Map runs by product_id so we can associate them 1:1 on the spreadsheet
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

        $rowData = [
            'product_id' => $pid,
            'name'       => $p['name'],
            'baker_name' => $r ? (string)$r['baker_name'] : '',
            'input_qty'  => $iqty,
            'input_type' => $r ? (string)$r['primary_input_type'] : 'kilo',
            'yield_qty'  => $r && $r['yield_qty'] > 0 ? (int)$r['yield_qty'] : '',
            'category'   => $p['product_category']
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

    
    $branchesStmt = $db->query("SELECT id, name FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $globalBaker = '';
    $globalBranch = 0;
    foreach ($runs as $r) {
        if ($r['baker_name'] !== '' || (int)$r['destination_branch_id'] > 0) {
            $globalBaker = (string)$r['baker_name'];
            $globalBranch = (int)$r['destination_branch_id'];
            break;
        }
    }

    
    $branchesStmt = $db->query("SELECT id, name FROM dl_branches WHERE is_active = 1 ORDER BY name ASC");
    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $globalBaker = '';
    $globalBranch = 0;
    foreach ($runs as $r) {
        if ($r['baker_name'] !== '' || (int)$r['destination_branch_id'] > 0) {
            $globalBaker = (string)$r['baker_name'];
            $globalBranch = (int)$r['destination_branch_id'];
            break;
        }
    }

    echo dlRender('modules/daily-ledger/admin/commissary.disyl', [
        'global_baker_name' => $globalBaker,
        'global_branch_id'  => $globalBranch,
        'branches'          => $branches,
        'global_baker_name' => $globalBaker,
        'global_branch_id'  => $globalBranch,
        'branches'          => $branches,
        'base_url' => dlGetBaseUrl(),
        'current_page'=> 'commissary',
        'user'        => $user,
        'user_name'   => $user['full_name'] ?? $user['username'] ?? 'User',
        'user_role'   => $user['role'] ?? 'unknown',
        'date'        => $rawDate,
        'products'    => $products,
        'product_rows_bread'=> $productRowsBread,
        'product_rows_cake' => $productRowsCake,
        'materials'   => $commissaryRows,
    ]);}

function apiSaveProductionRun(): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        return;
    }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $db = $ctx->db();

    $input = $ctx->input();
    $date          = (string)($input['date'] ?? '');
    $productId     = (int)($input['product_id'] ?? 0);
    $bakerName     = trim((string)($input['baker_name'] ?? ''));
    $type          = 'regular'; // default
    $inputQty      = (float)($input['input_qty'] ?? 0);
    $inputType     = (string)($input['input_type'] ?? 'kilo');
    $yieldQty      = (int)($input['yield_qty'] ?? 0);
    $destBranchId  = (int)($input['destination_branch_id'] ?? 0);
    $destBranchId  = (int)($input['destination_branch_id'] ?? 0);

    if ($date === '' || $productId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'Missing date or product'], 400);
        return;
    }

    $actorId = (int)($user['id'] ?? 0);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id FROM dl_production_runs WHERE ledger_date = :d AND product_id = :p");
        $stmt->execute([':d' => $date, ':p' => $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bakerName === '' && $yieldQty <= 0 && $inputQty <= 0) {
            if ($existing) {
                $db->prepare("DELETE FROM dl_production_runs WHERE id = ?")->execute([$existing['id']]);
            }
        } elseif ($existing) {
            $stmt = $db->prepare(
                "UPDATE dl_production_runs 
                 SET baker_name = :baker, primary_input_qty = :iqty, primary_input_type = :itype, yield_qty = :yqty, destination_branch_id = :dest, recorded_by = :actor 
                 WHERE id = :id"
            );
            $stmt->execute([
                ':baker' => $bakerName,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
                ':id'    => $existing['id']
            ]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO dl_production_runs (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type, yield_qty, destination_branch_id, recorded_by)
                 VALUES (:date, :pid, :baker, :type, :iqty, :itype, :yqty, :dest, :actor)"
            );
            $stmt->execute([
                ':date'  => $date,
                ':pid'   => $productId,
                ':baker' => $bakerName,
                ':type'  => $type,
                ':iqty'  => $inputQty,
                ':itype' => $inputType,
                ':yqty'  => $yieldQty,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':dest'  => $destBranchId > 0 ? $destBranchId : null,
                ':actor' => $actorId > 0 ? $actorId : null,
            ]);
        }

        $db->commit();
        $ctx->json(['ok' => true]);

    } catch (Throwable $e) {
        $db->rollBack();
        write_log("apiSaveProductionRun error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error executing transaction'], 500);
    }}

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
    $date       = (string)($input['date'] ?? '');
    $materialId = (int)($input['material_id'] ?? 0);
    $field      = (string)($input['field'] ?? '');
    $val        = (float)($input['value'] ?? 0);

    if ($date === '' || $materialId <= 0 || !in_array($field, ['beg_bal', 'delivery_qty', 'used_qty', 'actual_end_bal'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid data'], 400);
        return;
    }
    
    $actorId = (int)($user['id'] ?? 0);

    try {
        $stmt = $db->prepare(
            "INSERT INTO dl_commissary_ledger (ledger_date, raw_material_id, $field, recorded_by)
             VALUES (:date, :mid, :val, :actor)
             ON DUPLICATE KEY UPDATE $field = :val, recorded_by = :actor"
        );
        $stmt->execute([
            ':date' => $date,
            ':mid'  => $materialId,
            ':val'  => $val,
            ':actor'=> $actorId > 0 ? $actorId : null,
        ]);
        
        $ctx->json(['ok' => true]);
    } catch (Throwable $e) {
        write_log("apiSaveCommissaryMaterial error: " . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Saved failed'], 500);
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
    ]);
}

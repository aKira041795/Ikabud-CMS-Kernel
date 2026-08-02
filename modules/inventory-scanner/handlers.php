<?php

declare(strict_types=1);

// ─── Helpers ───────────────────────────────────────────────────────────

function is_auditLog(string $action, ?string $entityType = null, ?string $entityId = null, $oldData = null, $newData = null, ?string $reason = null): void
{
    $ctx = module();
    if (!$ctx) return;
    try {
        $ctx->audit($action, null, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (\Throwable $e) { /* non-fatal */ }
}

function isCookieName(): string
{
    return 'inventory_scanner_token';
}

function isRefreshTokenCacheKey(string $refreshToken): string
{
    return 'refresh_token:' . hash('sha256', $refreshToken);
}

function isRegisterRefreshToken(string $refreshToken, ?int $ttl = null): void
{
    if ($refreshToken === '') return;
    app()->cache()->set('inventory-scanner', isRefreshTokenCacheKey($refreshToken), ['active' => true], $ttl ?? (30 * 86400));
}

function isIsRefreshTokenActive(string $refreshToken): bool
{
    if ($refreshToken === '') return false;
    $cached = app()->cache()->get('inventory-scanner', isRefreshTokenCacheKey($refreshToken));
    if (!is_array($cached)) return true;
    return !empty($cached['active']);
}

function isRevokeRefreshToken(string $refreshToken): void
{
    if ($refreshToken === '') return;
    app()->cache()->set('inventory-scanner', isRefreshTokenCacheKey($refreshToken), ['active' => false], 30 * 86400);
}

function isGenerateAuthTokens(array $payload): array
{
    $accessPayload = $payload;
    unset($accessPayload['token_type']);
    $accessToken = app()->jwt()->generate($accessPayload);

    $refreshPayload = $payload;
    $refreshPayload['token_type'] = 'refresh';
    $refreshJwt = new \Ikabud\Kernel\JWT(config('app.jwt.secret'), 30 * 86400);
    $refreshToken = $refreshJwt->generate($refreshPayload);
    isRegisterRefreshToken($refreshToken, 30 * 86400);

    return [
        'token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => (int)config('app.jwt.expiration', 86400),
        'refresh_expires_in' => 30 * 86400,
    ];
}

function isVerifyRefreshToken(string $refreshToken): ?array
{
    if ($refreshToken === '') return null;
    $refreshJwt = new \Ikabud\Kernel\JWT(config('app.jwt.secret'), 30 * 86400);
    $payload = $refreshJwt->verify($refreshToken);
    if (!is_array($payload)) return null;
    if (($payload['source'] ?? '') !== 'inventory-scanner' || ($payload['token_type'] ?? '') !== 'refresh') return null;
    if (!isIsRefreshTokenActive($refreshToken)) return null;
    unset($payload['token_type']);
    return $payload;
}

function isSetAuthCookie(string $token, int $expiresIn): void
{
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie(isCookieName(), $token, [
        'expires' => time() + $expiresIn,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function isInput(): array
{
    $ctx = module();
    if ($ctx) return $ctx->input();
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

function isJson(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function isRedirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        http_response_code(302);
    }
    echo '<html><body>Redirecting to <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></body></html>';
    exit;
}

function isIsApiRequest(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return str_starts_with($path, '/inventory-scanner/api/');
}

// ─── Auth ──────────────────────────────────────────────────────────────

function isUserFromRequest(): ?array
{
    $token = null;
    $cookieToken = kernelCookie(isCookieName());

    $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authHeader === '') $authHeader = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
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
    if ($token === null || $token === '') {
        if (is_string($cookieToken) && $cookieToken !== '') $token = $cookieToken;
    }
    if (!is_string($token) || $token === '') return null;

    try {
        $payload = app()->jwt()->verify($token);
        if (!is_array($payload)) return null;
        if (($payload['source'] ?? '') !== 'inventory-scanner') return null;
        return $payload;
    } catch (\Throwable $e) {
        return null;
    }
}

function isRequireAuth(array $roles = ['scanner', 'admin']): array
{
    $u = isUserFromRequest();
    if (!$u) {
        if (isIsApiRequest()) {
            isJson(['ok' => false, 'error' => 'Authentication required'], 401);
            exit;
        }
        isRedirect('/inventory-scanner/login');
    }
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        if (isIsApiRequest()) {
            isJson(['ok' => false, 'error' => 'Insufficient permissions'], 403);
            exit;
        }
        isRedirect('/inventory-scanner/login');
    }
    return $u;
}

// ─── Capability Handlers ───────────────────────────────────────────────

function inventory_scanner_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'isAuthAuthenticate',
        'inventory_scanner.scan.lookup@1' => 'isScanLookup',
        'inventory_scanner.scan.save@1' => 'isScanSave',
        'inventory_scanner.products.list@1' => 'isProductsList',
        'inventory_scanner.products.export@1' => 'isProductsExport',
        'inventory_scanner.scan.sync@1' => 'isScanSync',
    ];
}

function is_capability_handlers(): array
{
    return inventory_scanner_capability_handlers();
}

function isAuthAuthenticate($ctx): ?array
{
    $username = trim((string)($ctx['username'] ?? ''));
    $password = (string)($ctx['password'] ?? '');
    if ($username === '' || $password === '') return null;
    $db = module()->db();
    $stmt = $db->prepare(
        'SELECT id, username, email, full_name, password_hash, role
         FROM is_users
         WHERE (username = :u OR email = :u2) AND is_active = 1 AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':u' => $username, ':u2' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!password_verify($password, $row['password_hash'])) return null;
    return [
        'source' => 'inventory-scanner',
        'user' => [
            'id' => (int)$row['id'],
            'sub' => 'scanner:' . $row['id'],
            'username' => $row['username'],
            'email' => $row['email'] ?? '',
            'full_name' => $row['full_name'] ?? '',
            'role' => $row['role'],
        ],
    ];
}

// ─── Auth Routes ───────────────────────────────────────────────────────

function isAuthLogin(): void
{
    isJson([]); // set header early
    $input = isInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        isJson(['ok' => false, 'error' => 'Username and password are required.'], 422);
        return;
    }

    try {
        $auth = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@inventory-scanner:' . $username,
            'password' => $password,
        ], ['mode' => 'pipeline']);
    } catch (\Throwable $e) {
        isJson(['ok' => false, 'error' => 'Login failed.'], 500);
        return;
    }

    if (!is_array($auth) || !is_array($auth['user'] ?? null) || (($auth['source'] ?? '') !== 'inventory-scanner')) {
        isJson(['ok' => false, 'error' => 'Invalid username or password.'], 401);
        return;
    }

    $u = $auth['user'];
    $payload = [
        'sub' => (string)($u['sub'] ?? ''),
        'id' => (int)($u['id'] ?? 0),
        'username' => (string)($u['username'] ?? $username),
        'name' => (string)($u['full_name'] ?? $username),
        'role' => (string)($u['role'] ?? ''),
        'source' => 'inventory-scanner',
    ];
    $tokens = isGenerateAuthTokens($payload);
    isSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    is_auditLog('login', 'user', (string)$payload['id']);

    isJson([
        'ok' => true,
        'redirect' => '/inventory-scanner/scanner',
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function isAuthRefresh(): void
{
    isJson([]);
    $input = isInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        isJson(['ok' => false, 'error' => 'Refresh token is required.'], 422);
        return;
    }

    $payload = isVerifyRefreshToken($refreshToken);
    if (!$payload) {
        isJson(['ok' => false, 'error' => 'Invalid or expired refresh token.'], 401);
        return;
    }

    isRevokeRefreshToken($refreshToken);
    $tokens = isGenerateAuthTokens($payload);
    isSetAuthCookie($tokens['token'], (int)$tokens['expires_in']);

    isJson([
        'ok' => true,
        'token' => $tokens['token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_in' => $tokens['expires_in'],
        'refresh_expires_in' => $tokens['refresh_expires_in'],
    ]);
}

function isAuthLogout(): void
{
    $input = isInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    if ($refreshToken !== '') isRevokeRefreshToken($refreshToken);

    setcookie(isCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    isJson(['ok' => true]);
}

function isApiMe(): void
{
    isJson([]);
    $user = isUserFromRequest();
    if (!$user) {
        isJson(['ok' => false, 'error' => 'Authentication required'], 401);
        return;
    }
    isJson([
        'ok' => true,
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
    ]);
}

// ─── Scan / Product API ────────────────────────────────────────────────

function isScanLookup(): ?array
{
    $barcode = trim((string)($GLOBALS['_is_lookup_barcode'] ?? ''));
    if ($barcode === '') return null;

    $db = module()->db();
    $stmt = $db->prepare(
        'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price, image_url
         FROM is_products
         WHERE barcode = :barcode AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':barcode' => $barcode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isApiScanLookup(): void
{
    isJson([]);
    isRequireAuth();
    $barcode = trim((string)($_GET['barcode'] ?? ''));
    if ($barcode === '') {
        isJson(['ok' => false, 'error' => 'Barcode parameter is required.'], 422);
        return;
    }

    $db = module()->db();
    $stmt = $db->prepare(
        'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price, image_url
         FROM is_products
         WHERE barcode = :barcode AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':barcode' => $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        isJson(['ok' => true, 'found' => false, 'barcode' => $barcode, 'product' => null]);
        return;
    }

    isJson([
        'ok' => true,
        'found' => true,
        'barcode' => $barcode,
        'product' => [
            'id' => (int)$product['id'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'name' => $product['name'],
            'description' => $product['description'],
            'category' => $product['category'],
            'unit' => $product['unit'],
            'quantity' => (float)$product['quantity'],
            'location' => $product['location'],
            'price' => $product['price'] !== null ? (float)$product['price'] : null,
            'image_url' => $product['image_url'],
        ],
    ]);
}

function isScanSave(): ?array
{
    $db = module()->db();
    $item = $GLOBALS['_is_save_item'] ?? [];
    if (empty($item)) return null;

    $sessionId = (int)($item['session_id'] ?? 0);
    $barcode = trim((string)($item['barcode'] ?? ''));
    $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
    $sku = trim((string)($item['sku'] ?? ''));
    $productName = trim((string)($item['product_name'] ?? ''));
    $quantity = (float)($item['quantity'] ?? 1);
    $location = trim((string)($item['location'] ?? ''));
    $status = trim((string)($item['status'] ?? 'scanned'));

    if ($barcode === '' || $sessionId <= 0) return null;

    $stmt = $db->prepare(
        'INSERT INTO is_scan_items (session_id, product_id, barcode_scanned, sku_matched, product_name, quantity, location_scanned, status, scanned_at)
         VALUES (:sid, :pid, :barcode, :sku, :name, :qty, :loc, :status, :scanned)'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':sid' => $sessionId,
        ':pid' => $productId ?: null,
        ':barcode' => $barcode,
        ':sku' => $sku !== '' ? $sku : null,
        ':name' => $productName !== '' ? $productName : null,
        ':qty' => $quantity,
        ':loc' => $location !== '' ? $location : null,
        ':status' => $status,
        ':scanned' => $now,
    ]);

    $itemId = (int)$db->lastInsertId();
    is_auditLog('scan_item_saved', 'scan_item', (string)$itemId);

    return ['id' => $itemId, 'scanned_at' => $now];
}

function isApiScanSave(): void
{
    isJson([]);
    isRequireAuth();
    $input = isInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $barcode = trim((string)($input['barcode'] ?? ''));

    if ($barcode === '') {
        isJson(['ok' => false, 'error' => 'Barcode is required.'], 422);
        return;
    }

    // Auto-create session if not provided
    $db = module()->db();
    if ($sessionId <= 0) {
        $user = isUserFromRequest();
        $sessionType = trim((string)($input['session_type'] ?? 'manual'));
        $deviceId = trim((string)($input['device_id'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        $stmt = $db->prepare(
            'INSERT INTO is_scan_sessions (user_id, session_type, status, notes, device_id, started_at)
             VALUES (:uid, :type, :status, :notes, :device, :started)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':uid' => (int)($user['id'] ?? 0),
            ':type' => $sessionType,
            ':status' => 'open',
            ':notes' => $notes !== '' ? $notes : null,
            ':device' => $deviceId !== '' ? $deviceId : null,
            ':started' => $now,
        ]);
        $sessionId = (int)$db->lastInsertId();
    }

    // Look up product by barcode
    $productStmt = $db->prepare(
        'SELECT id, sku, barcode, name, category, unit, location
         FROM is_products
         WHERE barcode = :barcode AND is_active = 1
         LIMIT 1'
    );
    $productStmt->execute([':barcode' => $barcode]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    $productId = $product ? (int)$product['id'] : null;
    $sku = $product ? $product['sku'] : null;
    $productName = $product ? $product['name'] : null;
    $status = $product ? 'matched' : 'unmatched';
    $quantity = (float)($input['quantity'] ?? 1);
    $location = trim((string)($input['location'] ?? ($product['location'] ?? '')));

    $stmt = $db->prepare(
        'INSERT INTO is_scan_items (session_id, product_id, barcode_scanned, sku_matched, product_name, quantity, location_scanned, status, scanned_at)
         VALUES (:sid, :pid, :barcode, :sku, :name, :qty, :loc, :status, :scanned)'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':sid' => $sessionId,
        ':pid' => $productId,
        ':barcode' => $barcode,
        ':sku' => $sku,
        ':name' => $productName,
        ':qty' => $quantity,
        ':loc' => $location !== '' ? $location : null,
        ':status' => $status,
        ':scanned' => $now,
    ]);

    $itemId = (int)$db->lastInsertId();

    isJson([
        'ok' => true,
        'session_id' => $sessionId,
        'item' => [
            'id' => $itemId,
            'barcode' => $barcode,
            'product_id' => $productId,
            'sku' => $sku,
            'product_name' => $productName,
            'quantity' => $quantity,
            'location' => $location,
            'status' => $status,
            'scanned_at' => $now,
        ],
    ]);
}

function isApiScanBatchSave(): void
{
    isJson([]);
    isRequireAuth();
    $input = isInput();
    $items = $input['items'] ?? [];

    if (!is_array($items) || empty($items)) {
        isJson(['ok' => false, 'error' => 'Items array is required.'], 422);
        return;
    }

    $db = module()->db();
    $user = isUserFromRequest();
    $now = date('Y-m-d H:i:s');

    // Create session for batch
    $sessionType = trim((string)($input['session_type'] ?? 'batch_sync'));
    $deviceId = trim((string)($input['device_id'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    $stmt = $db->prepare(
        'INSERT INTO is_scan_sessions (user_id, session_type, status, notes, device_id, started_at)
         VALUES (:uid, :type, :status, :notes, :device, :started)'
    );
    $stmt->execute([
        ':uid' => (int)($user['id'] ?? 0),
        ':type' => $sessionType,
        ':status' => 'open',
        ':notes' => $notes !== '' ? $notes : null,
        ':device' => $deviceId !== '' ? $deviceId : null,
        ':started' => $now,
    ]);
    $sessionId = (int)$db->lastInsertId();

    $saved = [];
    $insertStmt = $db->prepare(
        'INSERT INTO is_scan_items (session_id, product_id, barcode_scanned, sku_matched, product_name, quantity, location_scanned, status, scanned_at)
         VALUES (:sid, :pid, :barcode, :sku, :name, :qty, :loc, :status, :scanned)'
    );

    foreach ($items as $item) {
        $barcode = trim((string)($item['barcode'] ?? ''));
        if ($barcode === '') continue;

        // Look up product
        $productStmt = $db->prepare(
            'SELECT id, sku, name FROM is_products WHERE barcode = :barcode AND is_active = 1 LIMIT 1'
        );
        $productStmt->execute([':barcode' => $barcode]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        $productId = $product ? (int)$product['id'] : null;
        $sku = $product ? $product['sku'] : null;
        $productName = $product ? $product['name'] : null;
        $status = $product ? 'matched' : 'unmatched';
        $quantity = (float)($item['quantity'] ?? 1);
        $location = trim((string)($item['location'] ?? ''));
        $scannedAt = trim((string)($item['scanned_at'] ?? '')) ?: $now;

        $insertStmt->execute([
            ':sid' => $sessionId,
            ':pid' => $productId,
            ':barcode' => $barcode,
            ':sku' => $sku,
            ':name' => $productName,
            ':qty' => $quantity,
            ':loc' => $location !== '' ? $location : null,
            ':status' => $status,
            ':scanned' => $scannedAt,
        ]);

        $saved[] = [
            'id' => (int)$db->lastInsertId(),
            'barcode' => $barcode,
            'status' => $status,
        ];
    }

    isJson([
        'ok' => true,
        'session_id' => $sessionId,
        'saved_count' => count($saved),
        'items' => $saved,
    ]);
}

// ─── Session Management ────────────────────────────────────────────────

function isApiSessions(): void
{
    isJson([]);
    $user = isRequireAuth();

    $db = module()->db();
    $stmt = $db->prepare(
        'SELECT s.id, s.session_type, s.status, s.notes, s.device_id, s.started_at, s.closed_at,
                COUNT(si.id) as item_count,
                SUM(CASE WHEN si.status = "matched" THEN 1 ELSE 0 END) as matched_count,
                SUM(CASE WHEN si.status = "unmatched" THEN 1 ELSE 0 END) as unmatched_count
         FROM is_scan_sessions s
         LEFT JOIN is_scan_items si ON si.session_id = s.id
         WHERE s.user_id = :uid
         GROUP BY s.id
         ORDER BY s.started_at DESC
         LIMIT 50'
    );
    $stmt->execute([':uid' => (int)($user['id'] ?? 0)]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    isJson(['ok' => true, 'sessions' => $sessions]);
}

function isApiSessionItems(): void
{
    isJson([]);
    isRequireAuth();

    $sessionId = (int)($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        isJson(['ok' => false, 'error' => 'session_id is required.'], 422);
        return;
    }

    $db = module()->db();
    $stmt = $db->prepare(
        'SELECT si.id, si.barcode_scanned, si.sku_matched, si.product_name, si.quantity, si.location_scanned, si.status, si.scanned_at, si.synced_at,
                p.sku as product_sku, p.name as product_name_db, p.category, p.unit
         FROM is_scan_items si
         LEFT JOIN is_products p ON p.id = si.product_id
         WHERE si.session_id = :sid
         ORDER BY si.scanned_at ASC'
    );
    $stmt->execute([':sid' => $sessionId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    isJson(['ok' => true, 'session_id' => $sessionId, 'items' => $items]);
}

function isApiCloseSession(): void
{
    isJson([]);
    isRequireAuth();

    $input = isInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    if ($sessionId <= 0) {
        isJson(['ok' => false, 'error' => 'session_id is required.'], 422);
        return;
    }

    $db = module()->db();
    $stmt = $db->prepare(
        'UPDATE is_scan_sessions SET status = :status, closed_at = :closed WHERE id = :id'
    );
    $stmt->execute([
        ':status' => 'closed',
        ':closed' => date('Y-m-d H:i:s'),
        ':id' => $sessionId,
    ]);

    is_auditLog('session_closed', 'scan_session', (string)$sessionId);
    isJson(['ok' => true]);
}

// ─── Product Management ────────────────────────────────────────────────

function isProductsList(): ?array
{
    $db = module()->db();
    $stmt = $db->query(
        'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price, image_url
         FROM is_products WHERE is_active = 1 ORDER BY name ASC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isApiProducts(): void
{
    isJson([]);
    isRequireAuth();

    $db = module()->db();
    $search = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $location = trim((string)($_GET['location'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $sql = 'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price, image_url FROM is_products WHERE is_active = 1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (barcode = :exact OR sku = :sku OR name LIKE :name)';
        $params[':exact'] = $search;
        $params[':sku'] = $search;
        $params[':name'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $sql .= ' AND category = :cat';
        $params[':cat'] = $category;
    }
    if ($location !== '') {
        $sql .= ' AND location = :loc';
        $params[':loc'] = $location;
    }

    $sql .= ' ORDER BY name ASC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    isJson(['ok' => true, 'products' => $products, 'limit' => $limit, 'offset' => $offset]);
}

function isApiProductSave(): void
{
    isJson([]);
    isRequireAuth(['admin']);

    $input = isInput();
    $id = isset($input['id']) ? (int)$input['id'] : null;
    $sku = trim((string)($input['sku'] ?? ''));
    $barcode = trim((string)($input['barcode'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $unit = trim((string)($input['unit'] ?? 'pcs'));
    $quantity = (float)($input['quantity'] ?? 0);
    $location = trim((string)($input['location'] ?? ''));
    $price = isset($input['price']) ? (float)$input['price'] : null;

    if ($sku === '' || $name === '') {
        isJson(['ok' => false, 'error' => 'SKU and name are required.'], 422);
        return;
    }

    $db = module()->db();

    if ($id && $id > 0) {
        $stmt = $db->prepare(
            'UPDATE is_products SET sku = :sku, barcode = :barcode, name = :name, description = :desc,
             category = :cat, unit = :unit, quantity = :qty, location = :loc, price = :price
             WHERE id = :id'
        );
        $stmt->execute([
            ':sku' => $sku, ':barcode' => ($barcode !== '' ? $barcode : null),
            ':name' => $name, ':desc' => ($description !== '' ? $description : null),
            ':cat' => ($category !== '' ? $category : null), ':unit' => $unit,
            ':qty' => $quantity, ':loc' => ($location !== '' ? $location : null),
            ':price' => $price, ':id' => $id,
        ]);
        is_auditLog('product_updated', 'product', (string)$id);
        isJson(['ok' => true, 'product_id' => $id]);
    } else {
        // Auto-generate SKU if enabled
        if ($sku === '' && module()->setting('auto_generate_sku', true)) {
            $sku = 'SKU-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
        }

        $stmt = $db->prepare(
            'INSERT INTO is_products (sku, barcode, name, description, category, unit, quantity, location, price)
             VALUES (:sku, :barcode, :name, :desc, :cat, :unit, :qty, :loc, :price)'
        );
        $stmt->execute([
            ':sku' => $sku, ':barcode' => ($barcode !== '' ? $barcode : null),
            ':name' => $name, ':desc' => ($description !== '' ? $description : null),
            ':cat' => ($category !== '' ? $category : null), ':unit' => $unit,
            ':qty' => $quantity, ':loc' => ($location !== '' ? $location : null),
            ':price' => $price,
        ]);
        $newId = (int)$db->lastInsertId();
        is_auditLog('product_created', 'product', (string)$newId);
        isJson(['ok' => true, 'product_id' => $newId]);
    }
}

// ─── CSV Export ────────────────────────────────────────────────────────

function isProductsExport(): ?array
{
    $db = module()->db();
    $sessionId = isset($GLOBALS['_is_export_session_id']) ? (int)$GLOBALS['_is_export_session_id'] : null;

    if ($sessionId && $sessionId > 0) {
        $stmt = $db->prepare(
            'SELECT si.barcode_scanned, si.sku_matched, si.product_name, si.quantity, si.location_scanned, si.status, si.scanned_at,
                    p.sku as product_sku, p.name as product_db_name, p.category
             FROM is_scan_items si
             LEFT JOIN is_products p ON p.id = si.product_id
             WHERE si.session_id = :sid
             ORDER BY si.scanned_at ASC'
        );
        $stmt->execute([':sid' => $sessionId]);
    } else {
        $stmt = $db->query(
            'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price FROM is_products WHERE is_active = 1 ORDER BY name ASC'
        );
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isApiExportCsv(): void
{
    isRequireAuth();
    $type = trim((string)($_GET['type'] ?? 'products')); // products | session
    $sessionId = (int)($_GET['session_id'] ?? 0);

    $db = module()->db();

    if ($type === 'session' && $sessionId > 0) {
        $stmt = $db->prepare(
            'SELECT si.barcode_scanned, si.sku_matched, si.product_name, si.quantity, si.location_scanned, si.status, si.scanned_at,
                    p.sku as product_sku, p.name as product_db_name, p.category
             FROM is_scan_items si
             LEFT JOIN is_products p ON p.id = si.product_id
             WHERE si.session_id = :sid
             ORDER BY si.scanned_at ASC'
        );
        $stmt->execute([':sid' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = 'inventory_scan_session_' . $sessionId . '_' . date('Ymd_His') . '.csv';
        $headers = ['Barcode', 'SKU Matched', 'Product Name', 'Qty', 'Location', 'Status', 'Scanned At', 'DB Product', 'Category'];
    } else {
        $stmt = $db->query(
            'SELECT id, sku, barcode, name, description, category, unit, quantity, location, price FROM is_products WHERE is_active = 1 ORDER BY name ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = 'inventory_products_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'SKU', 'Barcode', 'Name', 'Description', 'Category', 'Unit', 'Quantity', 'Location', 'Price'];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers);

    foreach ($rows as $row) {
        fputcsv($fp, array_values($row));
    }

    fclose($fp);
    exit;
}

// ─── Offline Sync ──────────────────────────────────────────────────────

function isScanSync(): ?array
{
    $db = module()->db();
    $items = $GLOBALS['_is_sync_items'] ?? [];
    if (empty($items)) return null;

    $synced = [];
    $insertStmt = $db->prepare(
        'INSERT INTO is_scan_items (session_id, product_id, barcode_scanned, sku_matched, product_name, quantity, location_scanned, status, scanned_at, synced_at)
         VALUES (:sid, :pid, :barcode, :sku, :name, :qty, :loc, :status, :scanned, :synced)'
    );
    $now = date('Y-m-d H:i:s');

    foreach ($items as $item) {
        $barcode = trim((string)($item['barcode'] ?? ''));
        if ($barcode === '') continue;

        $productStmt = $db->prepare('SELECT id, sku, name FROM is_products WHERE barcode = :barcode AND is_active = 1 LIMIT 1');
        $productStmt->execute([':barcode' => $barcode]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        $insertStmt->execute([
            ':sid' => (int)($item['session_id'] ?? 0),
            ':pid' => $product ? (int)$product['id'] : null,
            ':barcode' => $barcode,
            ':sku' => $product ? $product['sku'] : null,
            ':name' => $product ? $product['name'] : null,
            ':qty' => (float)($item['quantity'] ?? 1),
            ':loc' => trim((string)($item['location'] ?? '')) ?: null,
            ':status' => $product ? 'matched' : 'unmatched',
            ':scanned' => trim((string)($item['scanned_at'] ?? '')) ?: $now,
            ':synced' => $now,
        ]);

        $synced[] = [
            'local_id' => $item['local_id'] ?? null,
            'server_id' => (int)$db->lastInsertId(),
            'barcode' => $barcode,
            'status' => $product ? 'matched' : 'unmatched',
        ];
    }

    return ['synced_count' => count($synced), 'items' => $synced];
}

function isApiSync(): void
{
    isJson([]);
    isRequireAuth();

    $input = isInput();
    $items = $input['items'] ?? [];

    if (!is_array($items) || empty($items)) {
        isJson(['ok' => false, 'error' => 'Items array is required for sync.'], 422);
        return;
    }

    $db = module()->db();
    $user = isUserFromRequest();
    $now = date('Y-m-d H:i:s');

    // Create or reuse session
    $sessionId = (int)($input['session_id'] ?? 0);
    if ($sessionId <= 0) {
        $stmt = $db->prepare(
            'INSERT INTO is_scan_sessions (user_id, session_type, status, device_id, started_at)
             VALUES (:uid, :type, :status, :device, :started)'
        );
        $stmt->execute([
            ':uid' => (int)($user['id'] ?? 0),
            ':type' => 'batch_sync',
            ':status' => 'open',
            ':device' => trim((string)($input['device_id'] ?? '')),
            ':started' => $now,
        ]);
        $sessionId = (int)$db->lastInsertId();
    }

    $synced = [];
    $insertStmt = $db->prepare(
        'INSERT INTO is_scan_items (session_id, product_id, barcode_scanned, sku_matched, product_name, quantity, location_scanned, status, scanned_at, synced_at)
         VALUES (:sid, :pid, :barcode, :sku, :name, :qty, :loc, :status, :scanned, :synced)'
    );

    foreach ($items as $item) {
        $barcode = trim((string)($item['barcode'] ?? ''));
        if ($barcode === '') continue;

        $productStmt = $db->prepare('SELECT id, sku, name FROM is_products WHERE barcode = :barcode AND is_active = 1 LIMIT 1');
        $productStmt->execute([':barcode' => $barcode]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        $scannedAt = trim((string)($item['scanned_at'] ?? '')) ?: $now;

        $insertStmt->execute([
            ':sid' => $sessionId,
            ':pid' => $product ? (int)$product['id'] : null,
            ':barcode' => $barcode,
            ':sku' => $product ? $product['sku'] : null,
            ':name' => $product ? $product['name'] : null,
            ':qty' => (float)($item['quantity'] ?? 1),
            ':loc' => trim((string)($item['location'] ?? '')) ?: null,
            ':status' => $product ? 'matched' : 'unmatched',
            ':scanned' => $scannedAt,
            ':synced' => $now,
        ]);

        $synced[] = [
            'local_id' => $item['local_id'] ?? null,
            'server_id' => (int)$db->lastInsertId(),
            'barcode' => $barcode,
            'status' => $product ? 'matched' : 'unmatched',
        ];
    }

    is_auditLog('sync_completed', 'scan_sync', (string)$sessionId, null, ['count' => count($synced)]);

    isJson([
        'ok' => true,
        'session_id' => $sessionId,
        'synced_count' => count($synced),
        'items' => $synced,
    ]);
}

// ─── Category & Location List ──────────────────────────────────────────

function isApiCategories(): void
{
    isJson([]);
    isRequireAuth();

    $db = module()->db();
    $stmt = $db->query(
        'SELECT DISTINCT category FROM is_products WHERE category IS NOT NULL AND category != "" AND is_active = 1 ORDER BY category ASC'
    );
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    isJson(['ok' => true, 'categories' => $categories]);
}

function isApiLocations(): void
{
    isJson([]);
    isRequireAuth();

    $db = module()->db();
    $stmt = $db->query(
        'SELECT DISTINCT location FROM is_products WHERE location IS NOT NULL AND location != "" AND is_active = 1 ORDER BY location ASC'
    );
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    isJson(['ok' => true, 'locations' => $locations]);
}

// ─── Web Pages (minimal) ───────────────────────────────────────────────

function isPageLogin(): void
{
    $user = isUserFromRequest();
    if ($user) {
        isRedirect('/inventory-scanner/scanner');
    }
    \Ikabud\Kernel\DiSyL\TemplateEngine::render('modules/inventory-scanner/pages/login');
}

function isPageScanner(): void
{
    isRequireAuth();
    \Ikabud\Kernel\DiSyL\TemplateEngine::render('modules/inventory-scanner/pages/scanner');
}

function isPageProducts(): void
{
    isRequireAuth();
    \Ikabud\Kernel\DiSyL\TemplateEngine::render('modules/inventory-scanner/pages/products');
}

function isPageHistory(): void
{
    isRequireAuth();
    \Ikabud\Kernel\DiSyL\TemplateEngine::render('modules/inventory-scanner/pages/history');
}

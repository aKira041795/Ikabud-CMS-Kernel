<?php
/**
 * DC Cafe POS Module — Handlers
 *
 * Page handlers + core API handlers.
 * Order/inventory/customer logic split into separate files.
 *
 * @see modules/daily-ledger/handlers.php
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/helpers/entity-views.php';
require_once __DIR__ . '/handlers-orders.php';
require_once __DIR__ . '/handlers-inventory.php';
require_once __DIR__ . '/handlers-customers.php';
require_once __DIR__ . '/handlers-products.php';

// Load DiSyL entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}

// ─── Helpers ───────────────────────────────────────────────────────────

function dc_auditLog(string $action, ?string $entityType = null, ?string $entityId = null, $oldData = null, $newData = null, ?string $reason = null): void
{
    $ctx = module('dc-cafe');
    if (!$ctx) return;
    try {
        $ctx->audit($action, null, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

function dc_storeInput(?string $key = null, mixed $default = null): mixed
{
    $input = dcInput($key, $default);
    if ($input !== null) return $input;

    // Fallback to POST/GET for form submissions
    if ($key !== null) {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
    return $_POST + $_GET;
}

// ─── Auth Handlers ─────────────────────────────────────────────────────

/**
 * GET /dc-cafe/login
 */
function pageDcCafeLogin(array $params = []): void
{
    $ctx = dcCtx();
    $user = $ctx->user();
    if ($user) {
        header('Location: /dc-cafe/pos');
        exit;
    }

    echo dcRender('login.disyl', [
        'page_title' => 'DC Cafe POS — Sign In',
        'login_endpoint' => dcBaseUrl() . '/auth/login',
    ]);
}

/**
 * POST /dc-cafe/auth/login
 *
 * Authenticates via the kernel capability pipeline, generates a JWT,
 * sets the auth cookie, and returns JSON for the Alpine.js login form.
 */
function handleAuthLogin(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $username = (string) (dcInput('username') ?? $_POST['username'] ?? '');
    $password = (string) (dcInput('password') ?? $_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        dcJsonError('Username and password are required');
    }

    // Authenticate through the kernel capability pipeline.
    // The dc-cafe module's dc_cap_kernel_auth_authenticate_1 returns a raw user array.
    // Wrap it in the pipeline format the kernel expects.
    $userRow = dc_cap_kernel_auth_authenticate_1(['username' => $username, 'password' => $password]);
    if (!$userRow || !is_array($userRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    $role = (string) ($userRow['role'] ?? 'cashier');
    $userId = (int) ($userRow['user_id'] ?? 0);
    $sub = $role . ':' . $userId;

    $payload = [
        'sub' => $sub,
        'id' => $userId,
        'user_id' => $userId,
        'username' => (string) ($userRow['username'] ?? $username),
        'name' => (string) ($userRow['name'] ?? $userRow['full_name'] ?? $username),
        'full_name' => (string) ($userRow['name'] ?? $userRow['full_name'] ?? $username),
        'role' => $role,
        'source' => 'dc-cafe',
        'store_id' => $userRow['store_id'] ?? null,
    ];

    $token = app()->jwt()->generate($payload);
    $cookieName = config('app.cookie_name', 'app_token');
    $expiry = time() + (int) config('app.jwt.expiration', 86400);
    setcookie($cookieName, $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
    app()->csrfRotate(true);

    echo json_encode([
        'ok' => true,
        'redirect' => '/dc-cafe/pos',
        'user' => [
            'id' => $userId,
            'username' => (string) ($userRow['username'] ?? ''),
            'name' => (string) ($userRow['name'] ?? $userRow['full_name'] ?? ''),
            'role' => $role,
        ],
    ]);
    exit;
}

/**
 * GET /dc-cafe/logout
 */
function handleLogout(array $params = []): void
{
    $cookieName = config('app.cookie_name', 'app_token');
    setcookie($cookieName, '', [
        'expires' => time() - 86400,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
    header('Location: /dc-cafe/login');
    exit;
}

// ─── Forgot / Reset Password ───────────────────────────────────────────

/**
 * GET /dc-cafe/forgot-password
 */
function pageDcCafeForgotPassword(array $params = []): void
{
    echo dcRender('forgot-password.disyl', [
        'page_title' => 'DC Cafe POS — Forgot Password',
    ]);
}

/**
 * GET /dc-cafe/reset-password
 */
function pageDcCafeResetPassword(array $params = []): void
{
    $token = trim((string) ($_GET['token'] ?? ''));
    $valid = dcResetTokenIsValid($token);

    echo dcRender('reset-password.disyl', [
        'page_title' => 'DC Cafe POS — Reset Password',
        'reset_token' => $token,
        'token_valid' => $valid,
    ]);
}

/**
 * POST /dc-cafe/api/v1/auth/forgot-password
 *
 * Input: { identity: string } — email or username.
 * Always returns success to prevent user enumeration.
 */
function apiDcCafeForgotPassword(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $identity = trim((string) (dcInput('identity') ?? $_POST['identity'] ?? ''));
    if ($identity === '') {
        echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
        exit;
    }

    $db = dcDb();
    $user = $db->query(
        "SELECT user_id, username, email, full_name FROM dc_users WHERE (username = ? OR email = ?) AND is_active = 1 AND deleted_at IS NULL",
        [$identity, $identity]
    )->fetch(\PDO::FETCH_ASSOC);

    // Always return success — don't reveal whether account exists
    if (!$user) {
        echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
        exit;
    }

    $userId = (int) $user['user_id'];
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $db->query(
        "INSERT INTO dc_password_resets (user_id, token_hash, requester_ip, expires_at)
         VALUES (?, ?, ?, ?)",
        [$userId, $tokenHash, $ip !== '' ? $ip : null, $expiresAt]
    );

    // Build reset link using the actual request host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'dccafe.test';
    $resetUrl = $scheme . '://' . $host . '/dc-cafe/reset-password?token=' . $token;

    // Log the reset URL
    write_log('dc-cafe password reset requested', 'info', [
        'user_id' => $userId,
        'username' => $user['username'],
        'reset_url' => $resetUrl,
    ]);

    // Send reset email
    $userEmail = trim((string)($user['email'] ?? ''));
    if ($userEmail !== '' && filter_var($userEmail, FILTER_VALIDATE_EMAIL) && function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
        $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'there'));
        $policy = kernel_password_reset_policy();
        $ttlMinutes = $policy['token_ttl_minutes'] ?? 30;
        $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your DC Cafe POS password.</p>'
            . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
        $body = buildEmailTemplate('Reset Your DC Cafe Password', $content, 'Reset Password', $resetUrl);
        $sent = sendEmail($userEmail, 'DC Cafe Password Reset', $body);
        if (!$sent) {
            write_log('dc-cafe forgot-password email dispatch failed for user_id=' . (string)$userId, 'error');
        }
    }

    echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
    exit;
}

/**
 * POST /dc-cafe/api/v1/auth/reset-password
 *
 * Input: { token: string, password: string }
 */
function apiDcCafeResetPassword(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $token = trim((string) (dcInput('token') ?? $_POST['token'] ?? ''));
    $password = (string) (dcInput('password') ?? $_POST['password'] ?? '');

    if ($token === '' || strlen($password) < 6) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid token or password too short (min 6 characters).']);
        exit;
    }

    $db = dcDb();
    $tokenHash = hash('sha256', $token);

    $resetRow = $db->query(
        "SELECT pr.user_id FROM dc_password_resets pr
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1",
        [$tokenHash]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$resetRow) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired reset token. Please request a new link.']);
        exit;
    }

    $userId = (int) $resetRow['user_id'];
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $db->beginTransaction();
    try {
        $db->query("UPDATE dc_users SET password_hash = ? WHERE user_id = ?", [$passwordHash, $userId]);
        $db->query("UPDATE dc_password_resets SET used_at = NOW() WHERE token_hash = ?", [$tokenHash]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to reset password.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Password has been reset. You may now log in.']);
    exit;
}

/**
 * Validate a password reset token without consuming it.
 */
function dcResetTokenIsValid(string $token): bool
{
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return false;
    }

    try {
        $row = dcDb()->query(
            "SELECT id FROM dc_password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1",
            [hash('sha256', $token)]
        )->fetch(\PDO::FETCH_ASSOC);
        return (bool) $row;
    } catch (\Throwable $e) {
        return false;
    }
}

// ─── Page Handlers ─────────────────────────────────────────────────────

/**
 * GET /dc-cafe/pos — Main POS screen
 */
function pageDcCafePos(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) ($params['store_id'] ?? $user['store_id'] ?? 1);

    // Check active session
    $db = dcDb();
    $activeSession = $db->query(
        "SELECT * FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$activeSession) {
        header('Location: /dc-cafe/pos/session-start');
        exit;
    }

    // Get today's sales for bottom bar
    $todaySales = $db->query(
        "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
         FROM dc_orders
         WHERE store_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'completed'",
        [$storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    $paymentMethods = $db->query(
        "SELECT * FROM dc_payment_methods WHERE is_active = 1 ORDER BY sort_order"
    )->fetchAll(\PDO::FETCH_ASSOC);

    echo dcRender('pos/index.disyl', [
        'page_title' => 'DC Cafe POS',
        'store_id' => $storeId,
        'session' => $activeSession,
        'today_sales_count' => (int) ($todaySales['count'] ?? 0),
        'today_sales_total' => (float) ($todaySales['total'] ?? 0),
        'payment_methods' => $paymentMethods,
        'shift_type' => $activeSession['shift_type'],
    ]);
}

/**
 * GET /dc-cafe/pos/session-start
 */
function pageSessionStart(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) ($user['store_id'] ?? 1);

    // Check if already active
    $activeSession = dcDb()->query(
        "SELECT * FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    if ($activeSession) {
        header('Location: /dc-cafe/pos');
        exit;
    }

    echo dcRender('pos/session-start.disyl', [
        'page_title' => 'Start Shift — DC Cafe POS',
        'store_id' => $storeId,
    ]);
}

/**
 * GET /dc-cafe/pos/session-end
 */
function pageSessionEnd(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) ($user['store_id'] ?? 1);

    $session = dcDb()->query(
        "SELECT * FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$session) {
        header('Location: /dc-cafe/pos/session-start');
        exit;
    }

    // Calculate session sales
    $sales = dcDb()->query(
        "SELECT COUNT(*) AS tx_count, COALESCE(SUM(total_amount), 0) AS total
         FROM dc_orders WHERE session_id = ? AND status = 'completed'",
        [(int) $session['session_id']]
    )->fetch(\PDO::FETCH_ASSOC);

    // Payment breakdown
    $paymentBreakdown = dcDb()->query(
        "SELECT pm.name, pm.code, COALESCE(SUM(o.total_amount), 0) AS total
         FROM dc_orders o
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         WHERE o.session_id = ? AND o.status = 'completed'
         GROUP BY pm.payment_method_id, pm.name, pm.code",
        [(int) $session['session_id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Items bought this session
    $itemsBought = dcDb()->query(
        "SELECT oi.product_id, p.name, SUM(oi.quantity) AS qty
         FROM dc_order_items oi
         JOIN dc_orders o ON o.order_id = oi.order_id
         JOIN dc_products p ON p.product_id = oi.product_id
         WHERE o.session_id = ? AND o.status = 'completed'
         GROUP BY oi.product_id, p.name
         ORDER BY qty DESC",
        [(int) $session['session_id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Total discount this session
    $totalDiscount = dcDb()->query(
        "SELECT COALESCE(SUM(discount_amount), 0) AS total
         FROM dc_orders WHERE session_id = ? AND status = 'completed'",
        [(int) $session['session_id']]
    )->fetch(\PDO::FETCH_ASSOC);

    echo dcRender('pos/session-end.disyl', [
        'page_title' => 'End Shift — DC Cafe POS',
        'session' => $session,
        'sales_count' => (int) ($sales['tx_count'] ?? 0),
        'sales_total' => (float) ($sales['total'] ?? 0),
        'discount_total' => (float) ($totalDiscount['total'] ?? 0),
        'items_bought' => $itemsBought,
        'payment_breakdown' => $paymentBreakdown,
    ]);
}

// ─── Session API ───────────────────────────────────────────────────────

/**
 * POST /dc-cafe/api/v1/sessions/start
 */
function apiStartSession(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) (dcInput('store_id') ?? $user['store_id'] ?? 1);
    $startingCash = (float) (dcInput('starting_cash') ?? 0);
    $shiftType = (string) (dcInput('shift_type') ?? 'morning');
    $isLateReport = (int) (dcInput('is_late_report') ?? 0);

    // Validate shift type
    if (!in_array($shiftType, ['morning', 'afternoon', 'night'], true)) {
        dcJsonError('Invalid shift type. Must be morning, afternoon, or night.');
    }

    // Validate starting cash
    if ($startingCash < 0) {
        dcJsonError('Starting cash cannot be negative.');
    }

    // Validate no active session
    $existing = dcDb()->query(
        "SELECT session_id FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch();

    if ($existing) {
        dcJsonError('You already have an active session at this store');
    }

    $db = dcDb();
    $db->query(
        "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status, is_late_report)
         VALUES (?, ?, ?, ?, NOW(), 'active', ?)",
        [(int) $user['user_id'], $storeId, $startingCash, $shiftType, $isLateReport]
    );

    $sessionId = (int) $db->lastInsertId();

    dc_auditLog('session.started', 'dc_sessions', (string) $sessionId, null, [
        'store_id' => $storeId, 'starting_cash' => $startingCash, 'shift_type' => $shiftType,
    ]);

    dcJsonResponse(['ok' => true, 'session_id' => $sessionId]);
}

/**
 * POST /dc-cafe/api/v1/sessions/end
 */
function apiEndSession(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();

    // Allow store_id from request; fall back to finding any active session for this user
    $storeId = (int) (dcInput('store_id') ?? $user['store_id'] ?? 0);
    $endingCash = (float) (dcInput('ending_cash') ?? 0);

    if ($endingCash < 0) {
        dcJsonError('Ending cash cannot be negative.');
    }

    $session = dcDb()->query(
        "SELECT * FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$session) {
        dcJsonError('No active session found');
    }

    // Calculate expected cash
    $salesTotal = dcDb()->query(
        "SELECT COALESCE(SUM(total_amount), 0) AS total FROM dc_orders
         WHERE session_id = ? AND status = 'completed'",
        [(int) $session['session_id']]
    )->fetch(\PDO::FETCH_ASSOC);

    $expectedCash = (float) $session['starting_cash'] + (float) ($salesTotal['total'] ?? 0);
    $variance = $endingCash - $expectedCash;

    dcDb()->query(
        "UPDATE dc_sessions SET ending_cash = ?, shift_end = NOW(), status = 'closed' WHERE session_id = ?",
        [$endingCash, (int) $session['session_id']]
    );

    dc_auditLog('session.ended', 'dc_sessions', (string) $session['session_id'], [
        'starting_cash' => $session['starting_cash'],
        'expected_cash' => $expectedCash,
        'ending_cash' => $endingCash,
    ], [
        'ending_cash' => $endingCash,
        'expected_cash' => $expectedCash,
        'variance' => $variance,
    ]);

    dcJsonResponse([
        'ok' => true,
        'session_id' => (int) $session['session_id'],
        'expected_cash' => $expectedCash,
        'ending_cash' => $endingCash,
        'variance' => $variance,
    ]);
}

/**
 * GET /dc-cafe/api/v1/sessions/current
 */
function apiGetCurrentSession(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) ($user['store_id'] ?? 1);

    $session = dcDb()->query(
        "SELECT * FROM dc_sessions WHERE user_id = ? AND store_id = ? AND status = 'active'",
        [(int) $user['user_id'], $storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    dcJsonResponse([
        'ok' => true,
        'session' => $session ?: null,
    ]);
}

// ─── Product API ───────────────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/products
 */
function apiGetProducts(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $products = dc_cap_entity_list_product_1($params);
    dcJsonResponse(['ok' => true, 'products' => $products]);
}

/**
 * GET /dc-cafe/api/v1/products/{id}
 */
function apiGetProduct(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $product = dc_cap_entity_get_product_1($params);
    if (!$product) {
        dcJsonError('Product not found', 404);
    }
    dcJsonResponse(['ok' => true, 'product' => $product]);
}

// ─── Payment Methods API ───────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/payment-methods
 */
function apiGetPaymentMethods(array $params = []): void
{
    $methods = dcDb()->query(
        "SELECT payment_method_id, code, name FROM dc_payment_methods WHERE is_active = 1 ORDER BY sort_order"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'payment_methods' => $methods]);
}

// ─── Soft-Serve Options API ────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/soft-serve/bases
 */
function apiGetSoftServeBases(array $params = []): void
{
    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_bases WHERE is_active = 1 ORDER BY base_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'bases' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/sauces
 */
function apiGetSoftServeSauces(array $params = []): void
{
    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_sauces WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'sauces' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/toppings
 */
function apiGetSoftServeToppings(array $params = []): void
{
    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_toppings WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'toppings' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/addons
 */
function apiGetSoftServeAddons(array $params = []): void
{
    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_addons WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'addons' => $rows]);
}

// ─── Dashboard API ─────────────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/dashboard/today
 */
function apiGetTodaySalesData(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $storeId = (int) (dcInput('store_id') ?? 1);

    $db = dcDb();

    // Today's sales KPIs
    $today = $db->query(
        "SELECT COUNT(*) AS tx_count, COALESCE(SUM(total_amount), 0) AS total,
                COALESCE(AVG(total_amount), 0) AS avg_ticket
         FROM dc_orders
         WHERE store_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'completed'",
        [$storeId]
    )->fetch(\PDO::FETCH_ASSOC);

    // Top 5 products today
    $topProducts = $db->query(
        "SELECT p.name, SUM(oi.quantity) AS qty, SUM(oi.total_price) AS total
         FROM dc_order_items oi
         JOIN dc_orders o ON o.order_id = oi.order_id
         JOIN dc_products p ON p.product_id = oi.product_id
         WHERE o.store_id = ? AND DATE(o.transaction_date) = CURDATE() AND o.status = 'completed'
         GROUP BY p.product_id, p.name
         ORDER BY total DESC LIMIT 5",
        [$storeId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Low stock alerts
    $lowStock = $db->query(
        "SELECT ingredient_id, name, current_stock, reorder_level, unit
         FROM dc_ingredients
         WHERE current_stock <= reorder_level AND reorder_level > 0 AND is_active = 1
         ORDER BY (current_stock / reorder_level) ASC LIMIT 10"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Recent orders
    $recentOrders = dcDb()->query(
        "SELECT o.order_id, o.total_amount, o.status, o.transaction_date,
                pm.name AS payment_method, u.full_name AS cashier_name
         FROM dc_orders o
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         JOIN dc_users u ON u.user_id = o.cashier_id
         WHERE o.store_id = ? AND o.status = 'completed'
         ORDER BY o.transaction_date DESC LIMIT 10",
        [$storeId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse([
        'ok' => true,
        'today' => [
            'transactions' => (int) ($today['tx_count'] ?? 0),
            'total' => (float) ($today['total'] ?? 0),
            'avg_ticket' => (float) ($today['avg_ticket'] ?? 0),
        ],
        'top_products' => $topProducts,
        'low_stock' => $lowStock,
        'recent_orders' => $recentOrders,
    ]);
}

// ─── Dashboard Page ────────────────────────────────────────────────────

/**
 * GET /dc-cafe/dashboard
 */
function pageDashboard(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    echo dcRender('dashboard.disyl', [
        'page_title' => 'DC Cafe Dashboard',
    ]);
}

// ─── Order List/Detail Pages ───────────────────────────────────────────

/**
 * GET /dc-cafe/orders
 */
function pageOrderList(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    echo dcRender('orders/list.disyl', [
        'page_title' => 'Order History',
    ]);
}

/**
 * GET /dc-cafe/orders/{id}
 */
function pageOrderDetail(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $orderId = (int) ($params['id'] ?? 0);
    $order = dc_cap_entity_get_order_1(['id' => $orderId]);

    if (!$order) {
        http_response_code(404);
        echo 'Order not found';
        return;
    }

    echo dcRender('orders/detail.disyl', [
        'page_title' => 'Order #' . $orderId,
        'order' => $order,
    ]);
}

// ─── Inventory Pages ───────────────────────────────────────────────────

/**
 * GET /dc-cafe/inventory
 */
function pageInventory(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    echo dcRender('inventory/stock.disyl', [
        'page_title' => 'Stock Levels',
    ]);
}

/**
 * GET /dc-cafe/inventory/ledger — Daily/shift inventory ledger
 * Beginning + Production − Pullouts − Sales = Ending
 */
function pageInventoryLedger(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');
    $user = $ctx->user();

    // Scope the selector before rendering so cashiers never receive another
    // cashier's session metadata. The API repeats this authorization check.
    $sessionSql =
        "SELECT s.session_id, s.shift_start, s.shift_end, s.status,
                s.store_id, u.full_name AS opened_by, st.name AS store_name
         FROM dc_sessions s
         JOIN dc_users u ON u.user_id = s.user_id
         JOIN dc_stores st ON st.store_id = s.store_id
         WHERE s.status IN ('active', 'closed')";
    $sessionParams = [];
    if (($user['role'] ?? '') === 'cashier') {
        $sessionSql .= " AND s.user_id = ?";
        $sessionParams[] = (int) ($user['user_id'] ?? 0);
    }
    $sessionSql .= " ORDER BY s.shift_start DESC LIMIT 30";
    $sessions = dcDb()->query($sessionSql, $sessionParams)->fetchAll(\PDO::FETCH_ASSOC);

    echo dcRender('inventory/ledger.disyl', [
        'page_title' => 'Inventory Ledger',
        'sessions' => $sessions,
        'ledger_read_only' => ($user['role'] ?? '') === 'auditor',
    ]);
}

/**
 * GET /dc-cafe/inventory/receive
 */
function pageReceiveStock(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $suppliers = dcDb()->query(
        "SELECT * FROM dc_suppliers WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $ingredients = dcDb()->query(
        "SELECT * FROM dc_ingredients WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    echo dcRender('inventory/receive.disyl', [
        'page_title' => 'Receive Stock',
        'suppliers' => $suppliers,
        'ingredients' => $ingredients,
    ]);
}

// ─── Customer Pages ────────────────────────────────────────────────────

/**
 * GET /dc-cafe/customers
 */
function pageCustomerList(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    echo dcRender('customers/list.disyl', [
        'page_title' => 'Customers',
    ]);
}

/**
 * GET /dc-cafe/customers/{id}
 */
function pageCustomerDetail(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $customerId = (int) ($params['id'] ?? 0);
    $customer = dc_cap_entity_get_customer_1(['id' => $customerId]);

    if (!$customer) {
        http_response_code(404);
        echo 'Customer not found';
        return;
    }

    echo dcRender('customers/detail.disyl', [
        'page_title' => $customer['name'],
        'customer' => $customer,
    ]);
}

// ─── Customer API ──────────────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/customers
 */
function apiSearchCustomers(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $customers = dc_cap_entity_list_customer_1($params);
    dcJsonResponse(['ok' => true, 'customers' => $customers]);
}

/**
 * GET /dc-cafe/api/v1/customers/{id}
 */
function apiGetCustomer(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $customer = dc_cap_entity_get_customer_1($params);
    if (!$customer) {
        dcJsonError('Customer not found', 404);
    }
    dcJsonResponse(['ok' => true, 'customer' => $customer]);
}

// ─── Order API (list + detail — creation/void in handlers-orders.php) ──

/**
 * GET /dc-cafe/api/v1/orders
 */
function apiListOrders(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $orders = dc_cap_entity_list_order_1($params);
    dcJsonResponse(['ok' => true, 'orders' => $orders]);
}

/**
 * GET /dc-cafe/api/v1/orders/{id}
 */
function apiGetOrder(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $order = dc_cap_entity_get_order_1($params);
    if (!$order) {
        dcJsonError('Order not found', 404);
    }
    dcJsonResponse(['ok' => true, 'order' => $order]);
}

// ─── Voucher Validation ────────────────────────────────────────────────

/**
 * POST /dc-cafe/api/v1/vouchers/validate
 */
function apiValidateVoucher(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $code = (string) (dcInput('code') ?? $_POST['code'] ?? '');
    $orderTotal = (float) (dcInput('order_total') ?? 0);

    if ($code === '') {
        dcJsonError('Voucher code is required');
    }

    $voucher = dcDb()->query(
        "SELECT * FROM dc_vouchers WHERE code = ? AND is_active = 1
         AND valid_from <= NOW() AND valid_until >= NOW()",
        [$code]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$voucher) {
        dcJsonError('Invalid or expired voucher code');
    }

    if ($voucher['max_uses'] !== null && (int) $voucher['used_count'] >= (int) $voucher['max_uses']) {
        dcJsonError('Voucher has reached maximum usage limit');
    }

    if ($voucher['min_order_amount'] !== null && $orderTotal < (float) $voucher['min_order_amount']) {
        dcJsonError('Minimum order amount of ₱' . number_format((float) $voucher['min_order_amount'], 2) . ' required');
    }

    $discount = $voucher['discount_type'] === 'percentage'
        ? $orderTotal * ((float) $voucher['discount_value'] / 100)
        : (float) $voucher['discount_value'];

    $discount = min($discount, $orderTotal);

    dcJsonResponse([
        'ok' => true,
        'voucher' => [
            'id' => (int) $voucher['voucher_id'],
            'code' => $voucher['code'],
            'discount_type' => $voucher['discount_type'],
            'discount_value' => (float) $voucher['discount_value'],
        ],
        'discount_amount' => $discount,
    ]);
}

// ─── Supplier Page ─────────────────────────────────────────────────────

/**
 * GET /dc-cafe/suppliers
 */
function pageSupplierList(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    echo dcRender('suppliers/list.disyl', [
        'page_title' => 'Suppliers',
    ]);
}

// ─── Ingredient Page ────────────────────────────────────────────────────

/**
 * GET /dc-cafe/ingredients
 */
function pageIngredientList(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    echo dcRender('ingredients/list.disyl', [
        'page_title' => 'Ingredients',
    ]);
}

// ─── Settings Page ─────────────────────────────────────────────────────

/**
 * GET /dc-cafe/settings — Settings page
 */
function pageDcCafeSettings(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $db = dcDb();

    $paymentMethods = $db->query(
        "SELECT * FROM dc_payment_methods ORDER BY sort_order"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $stores = $db->query(
        "SELECT * FROM dc_stores ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $users = $db->query(
        "SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.store_id, u.is_active, u.last_login_at,
                s.name AS store_name
         FROM dc_users u
         LEFT JOIN dc_stores s ON s.store_id = u.store_id
         WHERE u.deleted_at IS NULL
         ORDER BY u.full_name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $bases = $db->query(
        "SELECT * FROM dc_soft_serve_bases ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $sauces = $db->query(
        "SELECT * FROM dc_soft_serve_sauces ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $toppings = $db->query(
        "SELECT * FROM dc_soft_serve_toppings ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $addons = $db->query(
        "SELECT * FROM dc_soft_serve_addons ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $ledgerGroups = $db->query(
        "SELECT g.*, COUNT(c.category_id) AS category_count
         FROM dc_ledger_groups g
         LEFT JOIN dc_categories c ON c.ledger_group_id = g.ledger_group_id
         GROUP BY g.ledger_group_id
         ORDER BY g.sort_order, g.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $categories = $db->query(
        "SELECT c.*, COALESCE(NULLIF(c.ledger_group, ''), c.name) AS effective_ledger_group
         FROM dc_categories c
         WHERE c.is_active = 1
         ORDER BY c.sort_order, c.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $settingsDataJson = json_encode([
        'paymentMethods' => $paymentMethods,
        'stores' => $stores,
        'users' => $users,
        'bases' => $bases,
        'sauces' => $sauces,
        'toppings' => $toppings,
        'addons' => $addons,
        'ledgerGroups' => $ledgerGroups,
        'categories' => $categories,
        'currentUserId' => (int) ($ctx->user()['user_id'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo dcRender('settings/index.disyl', [
        'page_title' => 'DC Cafe Settings',
        'settings_data_json' => $settingsDataJson ?: '{}',
    ]);
}

// ─── Payment Method Management API ─────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/payment-methods/list — All payment methods (incl. inactive)
 */
function apiListPaymentMethods(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $methods = dcDb()->query(
        "SELECT * FROM dc_payment_methods ORDER BY sort_order"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'payment_methods' => $methods]);
}

/**
 * POST /dc-cafe/api/v1/payment-methods/create
 */
function apiCreatePaymentMethod(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $code = (string) (dcInput('code') ?? '');
    $name = (string) (dcInput('name') ?? '');
    $sortOrder = (int) (dcInput('sort_order') ?? 0);

    if ($code === '' || $name === '') {
        dcJsonError('Code and name are required');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT payment_method_id FROM dc_payment_methods WHERE code = ?",
        [$code]
    )->fetch();
    if ($existing) {
        dcJsonError('Payment method code already exists');
    }

    $db->query(
        "INSERT INTO dc_payment_methods (code, name, sort_order) VALUES (?, ?, ?)",
        [strtoupper($code), $name, $sortOrder]
    );

    dcJsonResponse([
        'ok' => true,
        'payment_method_id' => (int) $db->lastInsertId(),
    ]);
}

/**
 * PUT /dc-cafe/api/v1/payment-methods/{id}
 */
function apiUpdatePaymentMethod(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid payment method ID', 400);
    }

    $name = dcInput('name');
    $sortOrder = dcInput('sort_order');
    $isActive = dcInput('is_active');

    if ($name === null && $sortOrder === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_payment_methods WHERE payment_method_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Payment method not found', 404);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = (string) $name;
    }
    if ($sortOrder !== null) {
        $sets[] = 'sort_order = ?';
        $vals[] = (int) $sortOrder;
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    if (empty($sets)) {
        dcJsonError('Nothing to update', 400);
    }

    $vals[] = $id;
    $db->query("UPDATE dc_payment_methods SET " . implode(', ', $sets) . " WHERE payment_method_id = ?", $vals);

    dcJsonResponse(['ok' => true]);
}

// ─── Store Management API ──────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/stores
 */
function apiListStores(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $stores = dcDb()->query("SELECT * FROM dc_stores ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'stores' => $stores]);
}

/**
 * GET /dc-cafe/api/v1/stores/{id}
 */
function apiGetStore(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid store ID', 400);
    }

    $store = dcDb()->query("SELECT * FROM dc_stores WHERE store_id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!$store) {
        dcJsonError('Store not found', 404);
    }

    dcJsonResponse(['ok' => true, 'store' => $store]);
}

/**
 * POST /dc-cafe/api/v1/stores/create — Create a new store branch
 */
function apiCreateStore(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = (string) (dcInput('name') ?? '');
    $address = (string) (dcInput('address') ?? '');
    $contactNumber = (string) (dcInput('contact_number') ?? '');
    $latitude = dcInput('latitude');
    $longitude = dcInput('longitude');

    if ($name === '') {
        dcJsonError('Store name is required');
    }

    $db = dcDb();

    // Auto-generate unique code from name
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
    $code = substr($base, 0, 10);
    $suffix = 0;
    while ($db->query("SELECT store_id FROM dc_stores WHERE code = ?", [$code])->fetch()) {
        $suffix++;
        $suffixPart = (string) $suffix;
        $code = substr($base, 0, 10 - strlen($suffixPart)) . $suffixPart;
    }

    $lat = $latitude !== null ? (float) $latitude : null;
    $lng = $longitude !== null ? (float) $longitude : null;

    $db->query(
        "INSERT INTO dc_stores (name, code, address, contact_number, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)",
        [$name, $code, $address ?: null, $contactNumber ?: null, $lat, $lng]
    );

    dcJsonResponse([
        'ok' => true,
        'store_id' => (int) $db->lastInsertId(),
        'code' => $code,
    ]);
}

/**
 * PUT /dc-cafe/api/v1/stores/{id}
 */
function apiUpdateStore(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid store ID', 400);
    }

    $name = dcInput('name');
    $address = dcInput('address');
    $contactNumber = dcInput('contact_number');
    $latitude = dcInput('latitude');
    $longitude = dcInput('longitude');
    $isActive = dcInput('is_active');

    if ($name === null && $address === null && $contactNumber === null
        && $latitude === null && $longitude === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $db = dcDb();
    $existing = $db->query("SELECT * FROM dc_stores WHERE store_id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Store not found', 404);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = (string) $name;
    }
    if ($address !== null) {
        $sets[] = 'address = ?';
        $vals[] = (string) $address;
    }
    if ($contactNumber !== null) {
        $sets[] = 'contact_number = ?';
        $vals[] = (string) $contactNumber;
    }
    if ($latitude !== null) {
        $sets[] = 'latitude = ?';
        $vals[] = (float) $latitude;
    }
    if ($longitude !== null) {
        $sets[] = 'longitude = ?';
        $vals[] = (float) $longitude;
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    if (empty($sets)) {
        dcJsonError('Nothing to update', 400);
    }

    $vals[] = $id;
    $db->query("UPDATE dc_stores SET " . implode(', ', $sets) . " WHERE store_id = ?", $vals);

    dcJsonResponse(['ok' => true]);
}

// ─── User Account Management API ───────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/users
 */
function apiListUsers(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $users = dcDb()->query(
        "SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.store_id, u.is_active, u.last_login_at,
                s.name AS store_name
         FROM dc_users u
         LEFT JOIN dc_stores s ON s.store_id = u.store_id
         WHERE u.deleted_at IS NULL
         ORDER BY u.full_name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'users' => $users]);
}

/**
 * GET /dc-cafe/api/v1/users/{id}
 */
function apiGetUser(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid user ID', 400);
    }

    $user = dcDb()->query(
        "SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.store_id, u.is_active, u.last_login_at,
                s.name AS store_name
         FROM dc_users u
         LEFT JOIN dc_stores s ON s.store_id = u.store_id
         WHERE u.user_id = ? AND u.deleted_at IS NULL",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$user) {
        dcJsonError('User not found', 404);
    }

    dcJsonResponse(['ok' => true, 'user' => $user]);
}

/**
 * POST /dc-cafe/api/v1/users/{id}/toggle-active
 */
function apiToggleUserActive(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid user ID', 400);
    }

    $currentUserId = (int) ($ctx->user()['user_id'] ?? 0);
    if ($id === $currentUserId) {
        dcJsonError('You cannot deactivate your own account', 403);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT user_id, is_active FROM dc_users WHERE user_id = ? AND deleted_at IS NULL",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$existing) {
        dcJsonError('User not found', 404);
    }

    $newActive = empty((int) $existing['is_active']) ? 1 : 0;
    $db->query(
        "UPDATE dc_users SET is_active = ? WHERE user_id = ?",
        [$newActive, $id]
    );

    dcJsonResponse(['ok' => true, 'is_active' => $newActive]);
}

// ─── User Account Management API (continued) ───────────────────────────

/**
 * POST /dc-cafe/api/v1/users/create — Create a new user account
 *
 * Input: { username, password, full_name, email?, role?, store_id? }
 */
function apiCreateUser(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $username = (string) (dcInput('username') ?? '');
    $password = (string) (dcInput('password') ?? '');
    $fullName = (string) (dcInput('full_name') ?? '');
    $email = (string) (dcInput('email') ?? '');
    $role = (string) (dcInput('role') ?? 'cashier');
    $storeId = dcInput('store_id');

    if ($username === '' || $password === '' || $fullName === '') {
        dcJsonError('Username, password, and full name are required');
    }
    if (!in_array($role, ['admin', 'supervisor', 'auditor', 'cashier'], true)) {
        dcJsonError('Invalid role. Must be admin, supervisor, auditor, or cashier');
    }
    if (strlen($password) < 6) {
        dcJsonError('Password must be at least 6 characters');
    }

    $db = dcDb();

    // Check uniqueness
    $existing = $db->query("SELECT user_id FROM dc_users WHERE username = ?", [$username])->fetch();
    if ($existing) {
        dcJsonError('Username already taken');
    }
    if ($email !== '') {
        $existingEmail = $db->query("SELECT user_id FROM dc_users WHERE email = ?", [$email])->fetch();
        if ($existingEmail) {
            dcJsonError('Email already in use');
        }
    }

    $db->query(
        "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [
            $username,
            password_hash($password, PASSWORD_BCRYPT),
            $email ?: null,
            $fullName,
            $role,
            $storeId !== null ? (int) $storeId : null,
        ]
    );

    dcJsonResponse([
        'ok' => true,
        'user_id' => (int) $db->lastInsertId(),
    ]);
}

/**
 * PUT /dc-cafe/api/v1/users/{id} — Update user profile and assignment
 *
 * Input: { full_name?, email?, role?, store_id? }
 */
function apiUpdateUser(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid user ID', 400);
    }

    $fullName = dcInput('full_name');
    $email = dcInput('email');
    $role = dcInput('role');
    $storeId = dcInput('store_id');
    $password = dcInput('password');

    if ($fullName === null && $email === null && $role === null && $storeId === null && $password === null) {
        dcJsonError('Nothing to update', 400);
    }

    if ($role !== null && !in_array((string) $role, ['admin', 'supervisor', 'auditor', 'cashier'], true)) {
        dcJsonError('Invalid role. Must be admin, supervisor, auditor, or cashier');
    }

    if ($password !== null && strlen((string) $password) < 6) {
        dcJsonError('Password must be at least 6 characters');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT user_id FROM dc_users WHERE user_id = ? AND deleted_at IS NULL",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('User not found', 404);
    }

    $sets = [];
    $vals = [];
    if ($fullName !== null) {
        $sets[] = 'full_name = ?';
        $vals[] = (string) $fullName;
    }
    if ($email !== null) {
        $sets[] = 'email = ?';
        $vals[] = (string) $email;
    }
    if ($role !== null) {
        $sets[] = 'role = ?';
        $vals[] = (string) $role;
    }
    if ($storeId !== null) {
        $sets[] = 'store_id = ?';
        $vals[] = (int) $storeId;
    }
    if ($password !== null) {
        $sets[] = 'password_hash = ?';
        $vals[] = password_hash((string) $password, PASSWORD_BCRYPT);
    }

    if (empty($sets)) {
        dcJsonError('Nothing to update', 400);
    }

    $vals[] = $id;
    $db->query("UPDATE dc_users SET " . implode(', ', $sets) . " WHERE user_id = ?", $vals);

    dcJsonResponse(['ok' => true]);
}

// ─── Soft-Serve Base Management API ────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/soft-serve/bases/list — All bases (incl. inactive) for settings
 */
function apiListSoftServeBases(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_bases ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'bases' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/sauces/list — All sauces (incl. inactive) for settings
 */
function apiListSoftServeSauces(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_sauces ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'sauces' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/toppings/list — All toppings (incl. inactive) for settings
 */
function apiListSoftServeToppings(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_toppings ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'toppings' => $rows]);
}

/**
 * GET /dc-cafe/api/v1/soft-serve/addons/list — All addons (incl. inactive) for settings
 */
function apiListSoftServeAddons(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $rows = dcDb()->query(
        "SELECT * FROM dc_soft_serve_addons ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);
    dcJsonResponse(['ok' => true, 'addons' => $rows]);
}

/**
 * POST /dc-cafe/api/v1/soft-serve/bases/create
 */
function apiCreateSoftServeBase(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = (string) (dcInput('name') ?? '');
    if ($name === '') {
        dcJsonError('Name is required');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT base_id FROM dc_soft_serve_bases WHERE name = ?",
        [$name]
    )->fetch();
    if ($existing) {
        dcJsonError('A base with that name already exists');
    }

    $db->query("INSERT INTO dc_soft_serve_bases (name) VALUES (?)", [strtoupper($name)]);
    dcJsonResponse(['ok' => true, 'base_id' => (int) $db->lastInsertId()]);
}

/**
 * PUT /dc-cafe/api/v1/soft-serve/bases/{id}
 */
function apiUpdateSoftServeBase(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid base ID', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_soft_serve_bases WHERE base_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Base not found', 404);
    }

    $name = dcInput('name');
    $isActive = dcInput('is_active');
    if ($name === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = strtoupper((string) $name);
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    $vals[] = $id;
    $db->query("UPDATE dc_soft_serve_bases SET " . implode(', ', $sets) . " WHERE base_id = ?", $vals);
    dcJsonResponse(['ok' => true]);
}

// ─── Soft-Serve Sauce Management API ───────────────────────────────────

/**
 * POST /dc-cafe/api/v1/soft-serve/sauces/create
 */
function apiCreateSoftServeSauce(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = (string) (dcInput('name') ?? '');
    if ($name === '') {
        dcJsonError('Name is required');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT sauce_id FROM dc_soft_serve_sauces WHERE name = ?",
        [$name]
    )->fetch();
    if ($existing) {
        dcJsonError('A sauce with that name already exists');
    }

    $db->query("INSERT INTO dc_soft_serve_sauces (name) VALUES (?)", [strtoupper($name)]);
    dcJsonResponse(['ok' => true, 'sauce_id' => (int) $db->lastInsertId()]);
}

/**
 * PUT /dc-cafe/api/v1/soft-serve/sauces/{id}
 */
function apiUpdateSoftServeSauce(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid sauce ID', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_soft_serve_sauces WHERE sauce_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Sauce not found', 404);
    }

    $name = dcInput('name');
    $isActive = dcInput('is_active');
    if ($name === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = strtoupper((string) $name);
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    $vals[] = $id;
    $db->query("UPDATE dc_soft_serve_sauces SET " . implode(', ', $sets) . " WHERE sauce_id = ?", $vals);
    dcJsonResponse(['ok' => true]);
}

// ─── Soft-Serve Topping Management API ─────────────────────────────────

/**
 * POST /dc-cafe/api/v1/soft-serve/toppings/create
 */
function apiCreateSoftServeTopping(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = (string) (dcInput('name') ?? '');
    if ($name === '') {
        dcJsonError('Name is required');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT topping_id FROM dc_soft_serve_toppings WHERE name = ?",
        [$name]
    )->fetch();
    if ($existing) {
        dcJsonError('A topping with that name already exists');
    }

    $db->query("INSERT INTO dc_soft_serve_toppings (name) VALUES (?)", [strtoupper($name)]);
    dcJsonResponse(['ok' => true, 'topping_id' => (int) $db->lastInsertId()]);
}

/**
 * PUT /dc-cafe/api/v1/soft-serve/toppings/{id}
 */
function apiUpdateSoftServeTopping(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid topping ID', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_soft_serve_toppings WHERE topping_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Topping not found', 404);
    }

    $name = dcInput('name');
    $isActive = dcInput('is_active');
    if ($name === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = strtoupper((string) $name);
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    $vals[] = $id;
    $db->query("UPDATE dc_soft_serve_toppings SET " . implode(', ', $sets) . " WHERE topping_id = ?", $vals);
    dcJsonResponse(['ok' => true]);
}

// ─── Soft-Serve Addon Management API ───────────────────────────────────

/**
 * POST /dc-cafe/api/v1/soft-serve/addons/create
 */
function apiCreateSoftServeAddon(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = (string) (dcInput('name') ?? '');
    $price = (float) (dcInput('price') ?? 0);
    $type = (string) (dcInput('type') ?? 'topping');

    if ($name === '') {
        dcJsonError('Name is required');
    }
    if ($price <= 0) {
        dcJsonError('Price must be greater than zero');
    }
    if (!in_array($type, ['sauce', 'topping', 'spoon'], true)) {
        dcJsonError('Type must be sauce, topping, or spoon');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT addon_id FROM dc_soft_serve_addons WHERE name = ?",
        [$name]
    )->fetch();
    if ($existing) {
        dcJsonError('An addon with that name already exists');
    }

    $db->query(
        "INSERT INTO dc_soft_serve_addons (name, price, type) VALUES (?, ?, ?)",
        [strtoupper($name), $price, $type]
    );
    dcJsonResponse(['ok' => true, 'addon_id' => (int) $db->lastInsertId()]);
}

/**
 * PUT /dc-cafe/api/v1/soft-serve/addons/{id}
 */
function apiUpdateSoftServeAddon(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid addon ID', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_soft_serve_addons WHERE addon_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Addon not found', 404);
    }

    $name = dcInput('name');
    $price = dcInput('price');
    $type = dcInput('type');
    $isActive = dcInput('is_active');

    if ($name === null && $price === null && $type === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $sets = [];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = strtoupper((string) $name);
    }
    if ($price !== null) {
        $sets[] = 'price = ?';
        $vals[] = (float) $price;
    }
    if ($type !== null) {
        if (!in_array((string) $type, ['sauce', 'topping', 'spoon'], true)) {
            dcJsonError('Type must be sauce, topping, or spoon');
        }
        $sets[] = 'type = ?';
        $vals[] = (string) $type;
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    if (empty($sets)) {
        dcJsonError('Nothing to update', 400);
    }

    $vals[] = $id;
    $db->query("UPDATE dc_soft_serve_addons SET " . implode(', ', $sets) . " WHERE addon_id = ?", $vals);
    dcJsonResponse(['ok' => true]);
}

// ─── Ledger Group Management API ───────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/settings/ledger-groups — List all groups w/ category membership
 */
function apiListLedgerGroups(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $db = dcDb();
    $groups = $db->query(
        "SELECT g.*, COUNT(c.category_id) AS category_count
         FROM dc_ledger_groups g
         LEFT JOIN dc_categories c ON c.ledger_group_id = g.ledger_group_id
         GROUP BY g.ledger_group_id
         ORDER BY g.sort_order, g.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $categories = $db->query(
        "SELECT c.category_id, c.name, c.ledger_group_id, c.sort_order,
                COALESCE(NULLIF(c.ledger_group, ''), c.name) AS effective_ledger_group
         FROM dc_categories c
         WHERE c.is_active = 1
         ORDER BY c.sort_order, c.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'groups' => $groups, 'categories' => $categories]);
}

/**
 * POST /dc-cafe/api/v1/settings/ledger-groups/create
 */
function apiCreateLedgerGroup(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $name = trim((string) (dcInput('name') ?? ''));
    if ($name === '') {
        dcJsonError('Group name is required');
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT ledger_group_id FROM dc_ledger_groups WHERE name = ?",
        [$name]
    )->fetch();
    if ($existing) {
        dcJsonError('A group with that name already exists');
    }

    $db->query(
        "INSERT INTO dc_ledger_groups (name) VALUES (?)",
        [$name]
    );

    $newId = (int) $db->lastInsertId();
    dc_auditLog('ledger_group.create', 'dc_ledger_groups', (string) $newId, null, [
        'name' => $name,
    ]);

    dcJsonResponse(['ok' => true, 'ledger_group_id' => $newId]);
}

/**
 * PUT /dc-cafe/api/v1/settings/ledger-groups/{id}
 */
function apiUpdateLedgerGroup(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid group ID', 400);
    }

    $name = dcInput('name');
    $sortOrder = dcInput('sort_order');
    $isActive = dcInput('is_active');
    $clientVersion = dcInput('version');

    if ($name === null && $sortOrder === null && $isActive === null) {
        dcJsonError('Nothing to update', 400);
    }

    $db = dcDb();
    $existing = $db->query(
        "SELECT * FROM dc_ledger_groups WHERE ledger_group_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) {
        dcJsonError('Group not found', 404);
    }

    if ($clientVersion === null) {
        dcJsonError('Group version is required. Refresh and try again.', 428);
    }
    // Optimistic version check
    if ((int) $clientVersion !== (int) $existing['version']) {
        dcJsonError('Group was modified by another user. Refresh and try again.', 409);
    }

    if ($name !== null) {
        $name = trim((string) $name);
        if ($name === '') {
            dcJsonError('Group name is required');
        }
        $dup = $db->query(
            "SELECT ledger_group_id FROM dc_ledger_groups WHERE name = ? AND ledger_group_id != ?",
            [$name, $id]
        )->fetch();
        if ($dup) {
            dcJsonError('Another group with that name already exists');
        }
    }
    if ($isActive !== null && !$isActive) {
        $assigned = (int) $db->query(
            "SELECT COUNT(*) FROM dc_categories WHERE ledger_group_id = ? AND is_active = 1",
            [$id]
        )->fetchColumn();
        if ($assigned > 0) {
            dcJsonError('Reassign active categories before deactivating this group.', 409);
        }
    }

    $sets = ['version = version + 1'];
    $vals = [];
    if ($name !== null) {
        $sets[] = 'name = ?';
        $vals[] = $name;
    }
    if ($sortOrder !== null) {
        $sets[] = 'sort_order = ?';
        $vals[] = (int) $sortOrder;
    }
    if ($isActive !== null) {
        $sets[] = 'is_active = ?';
        $vals[] = $isActive ? 1 : 0;
    }

    $vals[] = $id;
    $vals[] = (int) $clientVersion;
    $updated = $db->query(
        "UPDATE dc_ledger_groups SET " . implode(', ', $sets)
        . " WHERE ledger_group_id = ? AND version = ?",
        $vals
    );
    if ($updated->rowCount() !== 1) {
        dcJsonError('Group was modified by another user. Refresh and try again.', 409);
    }

    $newState = $db->query(
        "SELECT * FROM dc_ledger_groups WHERE ledger_group_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);
    dc_auditLog(
        'ledger_group.update',
        'dc_ledger_groups',
        (string) $id,
        $existing,
        $newState ?: null
    );

    dcJsonResponse(['ok' => true]);
}

/**
 * POST /dc-cafe/api/v1/settings/ledger-groups/remap — Reassign category to a group
 */
function apiRemapLedgerGroupCategory(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $categoryId = (int) (dcInput('category_id') ?? 0);
    $groupId = dcInput('ledger_group_id');

    if ($categoryId <= 0) {
        dcJsonError('Invalid category ID', 400);
    }

    $db = dcDb();
    $category = $db->query(
        "SELECT * FROM dc_categories WHERE category_id = ? AND is_active = 1",
        [$categoryId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$category) {
        dcJsonError('Category not found', 404);
    }

    $newGroupId = ($groupId !== null && $groupId !== '') ? (int) $groupId : null;
    if ($newGroupId !== null) {
        $group = $db->query(
            "SELECT * FROM dc_ledger_groups WHERE ledger_group_id = ? AND is_active = 1",
            [$newGroupId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$group) {
            dcJsonError('Target ledger group not found or inactive', 404);
        }
    }

    $oldGroupId = $category['ledger_group_id'];
    $db->query(
        "UPDATE dc_categories SET ledger_group_id = ? WHERE category_id = ?",
        [$newGroupId, $categoryId]
    );

    dc_auditLog('ledger_group.remap', 'dc_categories', (string) $categoryId, [
        'category_name' => $category['name'],
        'ledger_group_id' => $oldGroupId,
    ], [
        'category_name' => $category['name'],
        'ledger_group_id' => $newGroupId,
    ]);

    dcJsonResponse(['ok' => true]);
}

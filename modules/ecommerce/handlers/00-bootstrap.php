<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Handler Bootstrap (handlers/00-bootstrap.php)
//
// Provides:
//  ecRequireAdmin()       — require CMS admin role (administrator+)
//  ecRequireEditor()      — require editor role (editor+)
//  ecRequireCustomer()    — require logged-in cms user (any role) or redirect
//  ecAdminContext()       — build template context for admin pages
//  ecJsonOk()             — emit JSON success response and exit
//  ecJsonError()          — emit JSON error response and exit
// ─────────────────────────────────────────────────────────────────────────

/**
 * Require CMS administrator or superadmin. Redirects non-admins.
 */
function ecRequireAdmin(): array
{
    // Reuse CMS auth — ecommerce admin lives inside CMS admin chrome
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        echo 'CMS module required for ecommerce admin.';
        exit;
    }
    return cmsRequireRole('administrator');
}

/**
 * Require CMS editor or above.
 */
function ecRequireEditor(): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        exit;
    }
    return cmsRequireRole('editor');
}

/**
 * Require any logged-in CMS user (customer and above).
 * Used for "My Orders", account pages, and digital downloads.
 * 'customer' (level 8) is the minimum; subscriber and all CMS content
 * roles naturally pass as they are level 10+.
 */
function ecRequireCustomer(): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        exit;
    }
    return cmsRequireRole('customer');
}

/**
 * Build base context for ecommerce admin template pages.
 */
function ecAdminContext(array $user, string $currentPage, array $extra = []): array
{
    $ecSettings = ecSettings();
    return array_merge([
        'user'          => $user,
        'current_page'  => $currentPage,
        'page_title'    => 'Ecommerce — ' . ucfirst($currentPage),
        'base_url'      => ecGetBaseUrl(),
        'csrf_token'    => app()->csrfToken(),
        'csrf_field'    => app()->csrfField(),
        'ec_settings'   => $ecSettings,
        'currency'      => (string)($ecSettings['currency'] ?? ''),
        'currency_sym'  => (string)($ecSettings['currency_symbol'] ?? ''),
    ], $extra);
}

/**
 * Require the current user to be either a CMS administrator OR assigned to the given
 * store with one of $minRoles ('owner', 'manager', 'supervisor').
 *
 * Returns the CMS user array (with 'store_role' key appended when access comes from
 * store membership rather than system-admin privilege).
 *
 * Usage: $user = ecRequireStoreAccess($storeId, ['owner', 'manager', 'supervisor']);
 */
function ecRequireStoreAccess(int $storeId, array $minRoles = ['owner', 'manager', 'supervisor']): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        echo 'CMS module required.';
        exit;
    }
    // Require at minimum a logged-in CMS user (any role).
    $user   = cmsRequireRole('customer');
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');

    // Kernel admins and CMS administrators get unrestricted access.
    if ($source === 'kernel' || (function_exists('cmsRoleAtLeast') && cmsRoleAtLeast($role, 'administrator'))) {
        $user['store_role'] = 'administrator';
        return $user;
    }

    // Check store membership in ec_store_users.
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0 && function_exists('ecStoreStorageAvailable') && ecStoreStorageAvailable()) {
        try {
            $row = ecDb()->query(
                'SELECT role FROM ec_store_users WHERE store_id = ? AND user_id = ? LIMIT 1',
                [$storeId, $userId]
            )->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row) && in_array($row['role'], $minRoles, true)) {
                $user['store_role'] = $row['role'];
                return $user;
            }
        } catch (\Throwable $e) {
            // Fall through to 403.
        }
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Denied</title>'
        . '<script src="https://cdn.tailwindcss.com"></script></head>'
        . '<body class="min-h-screen bg-gray-50 flex items-center justify-center"><div class="text-center space-y-4">'
        . '<h1 class="text-xl font-bold text-gray-800">Access Denied</h1>'
        . '<p class="text-slate-500 text-sm">You are not assigned to manage this store.</p>'
        . '<a href="/ecommerce/my-stores" class="inline-block mt-2 px-4 py-2 bg-orange-600 text-white text-sm rounded-lg">My Stores</a>'
        . '</div></body></html>';
    exit;
}

/**
 * Build base context for per-store admin pages (handler 74).
 * Unlike ecAdminContext(), this does not require a system admin — store-level
 * users (owners, managers, supervisors) use this context.
 */
function ecStoreAdminContext(array $user, array $store, string $currentPage, array $extra = []): array
{
    $ecSettings = ecSettings();
    $storeRole  = (string)($user['store_role'] ?? 'administrator');
    $canEdit    = in_array($storeRole, ['owner', 'manager', 'administrator'], true);
    return array_merge([
        'user'         => $user,
        'store'        => $store,
        'store_role'   => $storeRole,
        'can_edit'     => $canEdit,
        'current_page' => $currentPage,
        'page_title'   => ($store['name'] ?? 'Store') . ' — ' . ucfirst($currentPage),
        'base_url'     => ecGetBaseUrl(),
        'csrf_token'   => app()->csrfToken(),
        'csrf_field'   => app()->csrfField(),
        'ec_settings'  => $ecSettings,
        'currency'     => (string)($ecSettings['currency'] ?? ''),
        'currency_sym' => (string)($ecSettings['currency_symbol'] ?? ''),
    ], $extra);
}

/**
 * Emit JSON success response and exit.
 */
function ecJsonOk(array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emit JSON error response and exit.
 */
function ecJsonError(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Auto-register a guest as a customer role account using their billing email.
 *
 * Looks up an existing account by email first; if none exists, creates one
 * with a random password and the 'customer' role. Either way, logs the user
 * in for this request (updates the session) so the order is linked to them.
 *
 * Returns the customer user ID on success, or null if registration is
 * unavailable (e.g. CMS module not loaded).
 *
 * @param string $email      Billing email from checkout
 * @param string $firstName  Billing first name (used as display name)
 * @param string $lastName   Billing last name
 */
function ecAutoRegisterGuestAsCustomer(string $email, string $firstName, string $lastName): ?int
{
    if (!function_exists('cmsDb') || !function_exists('moduleWithContext')) {
        return null;
    }

    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    // Clamp names to safe lengths before any DB write
    $firstName = mb_substr(trim($firstName), 0, 100);
    $lastName  = mb_substr(trim($lastName), 0, 100);

    try {
        return moduleWithContext('cms', static function () use ($email, $firstName, $lastName): ?int {
            $db = cmsDb();

            // Check for existing account with this email
            $existing = $db->query(
                "SELECT id, role FROM cms_users WHERE email = ? LIMIT 1",
                [$email]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $userId = (int)$existing['id'];
            } else {
                // Create a new customer account with a random password
                $displayName = trim($firstName . ' ' . $lastName);
                if ($displayName === '') {
                    $displayName = $email;
                }

                // Generate a unique username from the email local part
                $base     = preg_replace('/[^a-z0-9_]/', '', strtolower((string)explode('@', $email)[0]));
                $base     = $base !== '' ? $base : 'customer';
                $username = $base;
                $suffix   = 2;

                while (true) {
                    $taken = $db->query(
                        "SELECT id FROM cms_users WHERE username = ? LIMIT 1",
                        [$username]
                    )->fetch(\PDO::FETCH_ASSOC);
                    if (!$taken) {
                        break;
                    }
                    $username = $base . $suffix;
                    $suffix++;
                }

                $tempPassword = bin2hex(random_bytes(16)); // never stored in plain text
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $db->execute(
                    "INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
                     VALUES (?, ?, ?, ?, 'customer', 1, NOW())",
                    [$username, $email, $hash, $displayName]
                );
                $userId = (int)$db->lastInsertId();

                write_log('ec.checkout.auto_register', 'info', [
                    'user_id'  => $userId,
                    'email'    => $email,
                    'username' => $username,
                ]);
            }

            // Log the user into the session for this request so the order links to them.
            // Only set session for safe roles — never elevate a non-customer account.
            $safeRoles = ['customer', 'subscriber'];
            $resolvedRole = (string)($existing['role'] ?? 'customer');
            $sessionRole  = in_array($resolvedRole, $safeRoles, true) ? $resolvedRole : 'customer';
            if ($userId > 0 && session_status() !== PHP_SESSION_NONE) {
                $_SESSION['user_id']   = $userId;
                $_SESSION['user_role'] = $sessionRole;
            }

            return $userId > 0 ? $userId : null;
        });

    } catch (\Throwable $e) {
        write_log('ec.checkout.auto_register error: ' . $e->getMessage(), 'error', [
            'email' => $email,
        ]);
        return null;
    }
}

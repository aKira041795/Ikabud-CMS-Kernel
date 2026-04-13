<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Stores (handlers/72-admin-stores.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/admin/stores — list all stores
 */
function ecAdminStores(): void
{
    $user   = ecRequireAdmin();
    $input  = ecInput();
    $search = trim((string)($input['search'] ?? ''));
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 25;
    $offset = ($page - 1) * $limit;

    $result = ecStoreList([
        'search' => $search,
        'limit'  => $limit,
        'offset' => $offset,
    ]);

    $ctx = ecAdminContext($user, 'stores', [
        'stores'      => $result['items'],
        'total'       => (int)$result['total'],
        'total_pages' => max(1, (int)ceil(((int)$result['total']) / $limit)),
        'page'        => $page,
        'search'      => $search,
        'storage_ok'  => ecStoreStorageAvailable(),
        'message'     => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/stores.disyl', $ctx);
}

/**
 * GET  /ecommerce/admin/stores/create  — show create form
 * POST /ecommerce/admin/stores/create  — process create
 */
function ecAdminStoreCreate(): void
{
    $user  = ecRequireAdmin();
    $input = ecInput();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = ecStoreCreate([
            'name'         => $input['name'] ?? '',
            'code'         => $input['code'] ?? '',
            'slug'         => $input['slug'] ?? '',
            'description'  => $input['description'] ?? '',
            'is_active'    => !empty($input['is_active']),
            'is_default'   => !empty($input['is_default']),
            'settings_json' => _ecStoreSettingsFromInput($input),
        ]);

        if ($result['ok']) {
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Store created successfully.'];
            header('Location: /ecommerce/admin/stores/' . $result['id'] . '/edit');
            exit;
        }

        $ctx = ecAdminContext($user, 'stores', [
            'store'   => null,
            'input'   => $input,
            'is_new'  => true,
            'message' => ['type' => 'error', 'text' => $result['error']],
        ]);
        ecRender('modules/ecommerce/admin/store-edit.disyl', $ctx);
        return;
    }

    $ctx = ecAdminContext($user, 'stores', [
        'store'   => null,
        'input'   => [],
        'is_new'  => true,
        'message' => null,
    ]);
    ecRender('modules/ecommerce/admin/store-edit.disyl', $ctx);
}

/**
 * GET  /ecommerce/admin/stores/{id}/edit  — show edit form
 * POST /ecommerce/admin/stores/{id}/edit  — process update or special action
 */
function ecAdminStoreEdit(array $params = []): void
{
    $user  = ecRequireAdmin();
    $id    = (int)($params['id'] ?? 0);
    $input = ecInput();

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Store not found.'];
        header('Location: /ecommerce/admin/stores');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($input['action'] ?? 'save'));

        if ($action === 'save_inventory_source') {
            $sourceType  = trim((string)($input['inventory_source_type'] ?? 'local'));
            $warehouseId = max(0, (int)($input['inventory_warehouse_id'] ?? 0)) ?: null;
            $result = ecStoreSaveInventorySource($id, $sourceType, $warehouseId);
            $_SESSION['ec_message'] = $result['ok']
                ? ['type' => 'success', 'text' => 'Inventory source saved.']
                : ['type' => 'error',   'text' => $result['error']];
            header('Location: /ecommerce/admin/stores/' . $id . '/edit');
            exit;
        }

        if ($action === 'assign_owner') {
            $ownerUserId = (int)($input['owner_user_id'] ?? 0);
            if ($ownerUserId > 0) {
                ecStoreUserAssign($id, $ownerUserId, 'owner');
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Store owner assigned.'];
            } else {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Invalid user ID.'];
            }
            header('Location: /ecommerce/admin/stores/' . $id . '/edit');
            exit;
        }

        if ($action === 'remove_user') {
            $removeUserId = (int)($input['remove_user_id'] ?? 0);
            if ($removeUserId > 0) {
                ecStoreUserRemove($id, $removeUserId);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'User removed from store.'];
            }
            header('Location: /ecommerce/admin/stores/' . $id . '/edit');
            exit;
        }

        if ($action === 'delete') {
            $del = ecStoreDelete($id);
            if ($del['ok']) {
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Store deleted.'];
            } else {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => $del['error']];
            }
            header('Location: /ecommerce/admin/stores');
            exit;
        }

        if ($action === 'set_default') {
            $def = ecStoreSetDefault($id);
            $_SESSION['ec_message'] = $def['ok']
                ? ['type' => 'success', 'text' => '"' . $store['name'] . '" is now the default store.']
                : ['type' => 'error', 'text' => $def['error']];
            header('Location: /ecommerce/admin/stores/' . $id . '/edit');
            exit;
        }

        // Default: save
        $result = ecStoreUpdate($id, [
            'name'          => $input['name'] ?? '',
            'code'          => $input['code'] ?? '',
            'slug'          => $input['slug'] ?? '',
            'description'   => $input['description'] ?? '',
            'is_active'     => !empty($input['is_active']),
            'is_default'    => !empty($input['is_default']),
            'settings_json' => _ecStoreSettingsFromInput($input),
        ]);

        if ($result['ok']) {
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Store saved.'];
            header('Location: /ecommerce/admin/stores/' . $id . '/edit');
            exit;
        }

        $ctx = ecAdminContext($user, 'stores', [
            'store'   => $store,
            'input'   => $input,
            'is_new'  => false,
            'message' => ['type' => 'error', 'text' => $result['error']],
        ]);
        ecRender('modules/ecommerce/admin/store-edit.disyl', $ctx);
        return;
    }

    // Unpack settings_json into flat setting_* keys for the form
    $inputData = $store;
    $rawSettings = trim((string)($store['settings_json'] ?? ''));
    if ($rawSettings !== '') {
        $decoded = json_decode($rawSettings, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $inputData['setting_' . $k] = $v;
            }
        }
    }

    $ctx = ecAdminContext($user, 'stores', [
        'store'            => $store,
        'input'            => $inputData,
        'is_new'           => false,
        'store_users'      => ecStoreUserList($id),
        'inventory_source' => ecStoreInventorySource($id),
        'message'          => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/store-edit.disyl', $ctx);
}

/**
 * Extracts store settings fields from form input into a JSON-encodable array.
 * Returns null when all fields are empty (preserves existing null).
 */
function _ecStoreSettingsFromInput(array $input): ?string
{
    $settings = [];
    $fields   = ['currency', 'currency_symbol', 'timezone', 'tax_rate', 'checkout_note'];
    foreach ($fields as $f) {
        $v = trim((string)($input['setting_' . $f] ?? ''));
        if ($v !== '') {
            $settings[$f] = $v;
        }
    }
    return $settings !== [] ? json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Customers (handlers/65-admin-customers.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET  /ecommerce/admin/customers        — customer list
 */
function ecAdminCustomers(): void
{
    $user   = ecRequireAdmin();
    $input  = ecInput();
    $search = trim((string)($input['search'] ?? ''));
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 25;

    $result = ecCustomerList([
        'search' => $search,
        'limit'  => $limit,
        'offset' => ($page - 1) * $limit,
    ]);

    $msg = null;
    if (!empty($_SESSION['ec_message'])) {
        $msg = $_SESSION['ec_message'];
        unset($_SESSION['ec_message']);
    }

    $ctx = ecAdminContext($user, 'customers', [
        'customers'   => $result['items'],
        'total'       => $result['total'],
        'total_pages' => (int)ceil($result['total'] / $limit),
        'page'        => $page,
        'search'      => $search,
        'message'     => $msg,
        'page_title'  => 'Ecommerce — Customers',
    ]);

    ecRender('modules/ecommerce/admin/customers.disyl', $ctx);
}

/**
 * GET  /ecommerce/admin/customers/{id}/edit  — edit form
 * POST /ecommerce/admin/customers/{id}/edit  — update or delete
 */
function ecAdminCustomerEdit(array $params = []): void
{
    $user       = ecRequireAdmin();
    $customerId = (int)($params['id'] ?? 0);
    $customer   = ecCustomerGet($customerId);

    if (!$customer) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'Customer not found.']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input  = ecInput();
        $action = $input['action'] ?? 'update';

        if ($action === 'delete') {
            $deleted = ecCustomerDelete($customerId);
            $_SESSION['ec_message'] = $deleted
                ? ['type' => 'success', 'text' => 'Customer deleted.']
                : ['type' => 'error',   'text' => 'Could not delete customer.'];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/admin/customers');
            exit;
        }

        if ($action === 'update') {
            try {
                ecCustomerUpdate($customerId, [
                    'display_name' => $input['display_name'] ?? '',
                    'email'        => $input['email']        ?? '',
                    'is_active'    => isset($input['is_active']) ? 1 : 0,
                ]);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Customer updated.'];
            } catch (\Throwable $e) {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Update failed: ' . $e->getMessage()];
            }
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/admin/customers/' . $customerId . '/edit');
            exit;
        }
    }

    $msg = null;
    if (!empty($_SESSION['ec_message'])) {
        $msg = $_SESSION['ec_message'];
        unset($_SESSION['ec_message']);
    }

    $addresses = ecCustomerAddresses($customerId);
    $orders    = ecCustomerOrders($customerId, 10);

    $ctx = ecAdminContext($user, 'customers', [
        'customer'   => $customer,
        'addresses'  => $addresses,
        'orders'     => $orders['items'],
        'message'    => $msg,
        'page_title' => 'Edit Customer — ' . htmlspecialchars($customer['display_name'] ?: $customer['email']),
    ]);

    ecRender('modules/ecommerce/admin/customer-edit.disyl', $ctx);
}

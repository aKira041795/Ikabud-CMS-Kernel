<?php
/**
 * DC Cafe — Customer Handlers
 *
 * Create customer, add/use loyalty points.
 */

declare(strict_types=1);

/**
 * POST /dc-cafe/api/v1/customers — Create a new customer
 *
 * Input: { name, phone, email? }
 */
function apiCreateCustomer(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $name = (string) (dcInput('name') ?? '');
    $phone = (string) (dcInput('phone') ?? '');
    $email = (string) (dcInput('email') ?? '');

    if ($name === '' || $phone === '') {
        dcJsonError('Name and phone are required');
    }

    $db = dcDb();

    // Check duplicate phone
    $existing = $db->query("SELECT customer_id FROM dc_customers WHERE phone = ?", [$phone])->fetch();
    if ($existing) {
        dcJsonResponse(['ok' => true, 'customer_id' => (int) $existing['customer_id'], 'existing' => true]);
        return;
    }

    $db->query(
        "INSERT INTO dc_customers (name, phone, email) VALUES (?, ?, ?)",
        [$name, $phone, $email ?: null]
    );
    $customerId = (int) $db->lastInsertId();

    dcJsonResponse(['ok' => true, 'customer_id' => $customerId, 'existing' => false]);
}

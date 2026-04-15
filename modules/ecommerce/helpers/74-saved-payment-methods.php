<?php
/**
 * Saved Payment Methods (Tier 3.1)
 *
 * Higher-level CRUD for saved payment methods, backed by ec_saved_payment_methods
 * table and Stripe Customer API.
 */

declare(strict_types=1);

function ecSavedPaymentMethodsAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        ecDb()->query('SELECT 1 FROM ec_saved_payment_methods LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

function ecSavedPaymentMethodList(int $userId): array
{
    if (!ecSavedPaymentMethodsAvailable()) return [];
    $rows = ecDb()->query(
        'SELECT * FROM ec_saved_payment_methods WHERE user_id = ? ORDER BY is_default DESC, created_at DESC',
        [$userId]
    );
    return is_array($rows) ? $rows : [];
}

function ecSavedPaymentMethodGet(int $id, int $userId): ?array
{
    if (!ecSavedPaymentMethodsAvailable()) return null;
    $rows = ecDb()->query(
        'SELECT * FROM ec_saved_payment_methods WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );
    return is_array($rows) && count($rows) > 0 ? $rows[0] : null;
}

function ecSavedPaymentMethodSave(int $userId, array $data): array
{
    if (!ecSavedPaymentMethodsAvailable()) {
        return ['ok' => false, 'error' => 'Saved payment methods table not available'];
    }

    $gateway = trim((string)($data['gateway'] ?? 'stripe'));
    $gatewayCustomerId = trim((string)($data['gateway_customer_id'] ?? ''));
    $gatewayPmId = trim((string)($data['gateway_payment_method_id'] ?? ''));
    if ($gatewayPmId === '') {
        return ['ok' => false, 'error' => 'Payment method ID is required'];
    }

    $cardBrand = trim((string)($data['card_brand'] ?? ''));
    $cardLast4 = trim((string)($data['card_last4'] ?? ''));
    $cardExpMonth = isset($data['card_exp_month']) ? (int)$data['card_exp_month'] : null;
    $cardExpYear = isset($data['card_exp_year']) ? (int)$data['card_exp_year'] : null;
    $isDefault = !empty($data['is_default']);
    $label = trim((string)($data['label'] ?? ''));

    // If setting as default, clear other defaults
    if ($isDefault) {
        ecDb()->execute(
            'UPDATE ec_saved_payment_methods SET is_default = 0 WHERE user_id = ? AND gateway = ?',
            [$userId, $gateway]
        );
    }

    ecDb()->execute(
        'INSERT INTO ec_saved_payment_methods
            (user_id, gateway, gateway_customer_id, gateway_payment_method_id,
             card_brand, card_last4, card_exp_month, card_exp_year,
             is_default, label, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            gateway_customer_id = VALUES(gateway_customer_id),
            card_brand = VALUES(card_brand),
            card_last4 = VALUES(card_last4),
            card_exp_month = VALUES(card_exp_month),
            card_exp_year = VALUES(card_exp_year),
            is_default = VALUES(is_default),
            label = VALUES(label),
            updated_at = NOW()',
        [
            $userId, $gateway, $gatewayCustomerId ?: null, $gatewayPmId,
            $cardBrand ?: null, $cardLast4 ?: null, $cardExpMonth, $cardExpYear,
            $isDefault ? 1 : 0, $label ?: null,
        ]
    );

    return ['ok' => true, 'gateway_payment_method_id' => $gatewayPmId];
}

function ecSavedPaymentMethodDelete(int $id, int $userId): bool
{
    if (!ecSavedPaymentMethodsAvailable()) return false;
    $row = ecSavedPaymentMethodGet($id, $userId);
    if (!$row) return false;

    ecDb()->execute(
        'DELETE FROM ec_saved_payment_methods WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );
    return true;
}

function ecSavedPaymentMethodSetDefault(int $id, int $userId): bool
{
    if (!ecSavedPaymentMethodsAvailable()) return false;
    $row = ecSavedPaymentMethodGet($id, $userId);
    if (!$row) return false;

    $gateway = $row['gateway'] ?? 'stripe';
    ecDb()->execute(
        'UPDATE ec_saved_payment_methods SET is_default = 0 WHERE user_id = ? AND gateway = ?',
        [$userId, $gateway]
    );
    ecDb()->execute(
        'UPDATE ec_saved_payment_methods SET is_default = 1 WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );
    return true;
}

function ecSavedPaymentMethodGetOrCreateStripeCustomer(int $userId, string $email, ?string $name = null): array
{
    if (!ecSavedPaymentMethodsAvailable()) {
        return ['ok' => false, 'error' => 'Saved payment methods table not available'];
    }

    // Check if user already has a Stripe customer
    $existing = ecDb()->query(
        'SELECT gateway_customer_id FROM ec_saved_payment_methods
         WHERE user_id = ? AND gateway = ? AND gateway_customer_id IS NOT NULL LIMIT 1',
        [$userId, 'stripe']
    );

    if (is_array($existing) && count($existing) > 0 && !empty($existing[0]['gateway_customer_id'])) {
        return ['ok' => true, 'customer_id' => $existing[0]['gateway_customer_id']];
    }

    // Create Stripe Customer
    $result = ecStripeCreateCustomer($email, [
        'name' => $name ?? '',
        'metadata' => ['user_id' => (string)$userId],
    ]);

    if (!$result['ok']) {
        return $result;
    }

    $customerId = $result['data']['id'] ?? '';
    return ['ok' => true, 'customer_id' => $customerId];
}

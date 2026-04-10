<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/webhooks';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$migration = __DIR__ . '/../modules/ecommerce/database/migrations/017_ec_outbound_webhooks.sql';
if (is_file($migration)) {
    app()->db()->exec((string)file_get_contents($migration));
}

$pass = 0;
$fail = 0;
$errors = [];
$createdWebhookIds = [];
$requests = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function cleanupEcommerceOutboundWebhookFixtures(array $webhookIds): void
{
    unset($GLOBALS['__ec_outbound_webhook_http_mock']);
    $db = app()->db();
    if ($webhookIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($webhookIds), '?'));
        $db->prepare("DELETE FROM ec_webhook_deliveries WHERE webhook_id IN ({$placeholders})")->execute($webhookIds);
        $db->prepare("DELETE FROM ec_outbound_webhooks WHERE id IN ({$placeholders})")->execute($webhookIds);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE OUTBOUND WEBHOOKS ===\n";

$GLOBALS['__ec_outbound_webhook_http_mock'] = static function (string $method, string $url, string $body, array $headers) use (&$requests): array {
    $requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
    if ($url === 'https://hooks.example.test/orders') {
        return ['ok' => true, 'http_status' => 202, 'response_body' => '{"accepted":true}'];
    }
    return ['ok' => false, 'http_status' => 500, 'response_body' => 'unexpected url'];
};

$activeWebhookId = ecOutboundWebhookSave([
    'name' => 'Order webhook',
    'target_url' => 'https://hooks.example.test/orders',
    'signing_secret' => 'test_secret_key',
    'event_patterns' => "ecommerce.order.*\necommerce.product.created",
    'is_active' => true,
]);
$inactiveWebhookId = ecOutboundWebhookSave([
    'name' => 'Inactive webhook',
    'target_url' => 'https://hooks.example.test/inactive',
    'signing_secret' => 'inactive_secret',
    'event_patterns' => 'ecommerce.order.created',
    'is_active' => false,
]);
$createdWebhookIds = [$activeWebhookId, $inactiveWebhookId];

$results = ecOutboundWebhooksDispatchEvent('ecommerce.order.created', [
    'order_id' => 99,
    'order_number' => 'EC-99',
    'total' => 125.50,
]);

$deliveries = ecOutboundWebhookRecentDeliveries(10);
$delivery = $deliveries[0] ?? [];
$payload = json_decode((string)($requests[0]['body'] ?? ''), true);
$signatureHeader = '';
foreach ((array)($requests[0]['headers'] ?? []) as $header) {
    if (str_starts_with($header, 'X-Ecommerce-Signature: ')) {
        $signatureHeader = substr($header, strlen('X-Ecommerce-Signature: '));
        break;
    }
}
$expectedSignature = 'sha256=' . hash_hmac('sha256', (string)($requests[0]['body'] ?? ''), 'test_secret_key');

$template = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/webhooks.disyl') ?: '';
$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';

t('outbound webhook storage is available', ecOutboundWebhookStorageAvailable());
t('active webhook dispatches matching order event once', count($results) === 1 && count($requests) === 1, json_encode(['results' => $results, 'requests' => $requests]));
t('inactive webhook is skipped during dispatch', !str_contains(json_encode($requests, JSON_UNESCAPED_SLASHES), 'inactive'), json_encode($requests));
t('webhook payload includes event name and payload', (string)($payload['event'] ?? '') === 'ecommerce.order.created' && (int)($payload['payload']['order_id'] ?? 0) === 99, json_encode($payload));
t('webhook signature header uses sha256 hmac', $signatureHeader === $expectedSignature, json_encode(['expected' => $expectedSignature, 'actual' => $signatureHeader]));
t('delivery log records successful response', (string)($delivery['status'] ?? '') === 'delivered' && (int)($delivery['http_status'] ?? 0) === 202, json_encode($delivery));
t('admin webhook template exists', str_contains($template, 'Event Patterns') && str_contains($template, 'Recent Deliveries'));
t('routes expose admin webhooks page', str_contains($routes, '/ecommerce/admin/webhooks'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceOutboundWebhookFixtures($createdWebhookIds);

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

$capturedEmails = [];
function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body', 'options');
    return true;
}

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

app()->events()->fire('ecommerce.order.created', [
    'order_id' => 1,
    'order_number' => 'EC-999',
    'customer_email' => 'test@customer.com',
    'total' => 10.00
]);

print_r($capturedEmails);

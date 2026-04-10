<?php

declare(strict_types=1);

function ecApiAbandonedCartCapture(): void
{
    csrf_verify();

    if (!ecAbandonedCartEnabled()) {
        ecJsonOk(['ignored' => true, 'reason' => 'disabled']);
    }

    $input = ecInput();
    $billing = (array)($input['billing'] ?? []);
    $lead = [
        'guest_email' => $input['guest_email'] ?? ($billing['email'] ?? ''),
        'guest_name' => $input['guest_name'] ?? trim((string)($billing['first_name'] ?? $input['first_name'] ?? '') . ' ' . (string)($billing['last_name'] ?? $input['last_name'] ?? '')),
        'first_name' => $billing['first_name'] ?? ($input['first_name'] ?? ''),
        'last_name' => $billing['last_name'] ?? ($input['last_name'] ?? ''),
    ];
    $record = ecAbandonedCartCaptureLead($lead);
    if (!$record) {
        ecJsonOk(['captured' => false]);
    }

    ecJsonOk([
        'captured' => true,
        'abandoned_cart_id' => (int)($record['id'] ?? 0),
    ]);
}
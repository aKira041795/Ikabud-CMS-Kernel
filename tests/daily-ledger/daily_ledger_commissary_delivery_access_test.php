<?php

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-delivery-access');
$base = $h->basePath();
$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

$h->section('T-B1 distinct delivery authorization');
$h->test(
    'view payload grants commissary delivery creation to production-in-charge',
    str_contains($handlers, "'can_create_commissary_delivery' => in_array(\$role, ['admin', 'supervisor', 'production_in_charge'], true)")
);
$h->test(
    'production management remains admin/supervisor only',
    str_contains($handlers, "\$canViewProductionManagement = in_array(\$role, ['admin', 'supervisor'], true);")
        && !str_contains($handlers, "\$canViewProductionManagement = in_array(\$role, ['admin', 'supervisor', 'production_in_charge'], true);")
);
$h->test(
    'New Delivery uses only the distinct creation gate',
    str_contains($template, '{if can_create_commissary_delivery}<button type="button" class="btn btn-primary btn-sm" onclick="openDispatchEntry()"')
);
$h->test(
    'Reopen Shift remains on the production-management gate',
    str_contains($template, "{if can_view_production_management && shift_status == 'finalized'}")
);

$h->section('B4 deliveries navigation remains aligned with document creation rights');
$deliveries = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$h->test(
    'formal delivery document creation still excludes production-in-charge',
    str_contains($deliveries, "'can_create_delivery_docs' => in_array(\$role, ['admin', 'supervisor'], true)")
);
$h->test(
    'Deliveries nav therefore remains admin/supervisor only',
    str_contains($template, "{if feature_formal_delivery && (user_role == 'admin' || user_role == 'supervisor')}")
);

$h->done();

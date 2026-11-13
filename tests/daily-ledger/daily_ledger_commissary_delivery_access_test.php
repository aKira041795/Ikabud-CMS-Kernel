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

$h->section('B4 deliveries navigation aligns with document creation rights');
$deliveries = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
// DECISION CHANGED 2026-11-09 (owner: "ensure this is accessible by a production user, and not
// only by admins or supervisors"). Tranche 1 deliberately left document creation admin-only and
// pinned that here. handleAdminDeliveries() already authorised production_in_charge, so keeping
// creation admin-only hid the create controls from the role whose job is creating DRs. Both the
// creation right and the nav entry now include the role. These assertions were UPDATED to the new
// decision — not weakened: they still assert an exact allowlist and still exclude cashier/viewer.
$h->test(
    'formal delivery document creation includes production-in-charge',
    str_contains($deliveries, "'can_create_delivery_docs' => in_array(\$role, ['admin', 'supervisor', 'production_in_charge'], true)")
);
$h->test(
    'Deliveries nav includes production-in-charge, aligned with creation rights',
    // The nav now asks the SAME predicate the request gate enforces instead of naming roles inline,
    // so the menu cannot offer a page the server refuses (it did: Deliveries was shown to this role
    // and answered 403). The role must still be granted the page - asserted at the predicate.
    str_contains($template, 'feature_formal_delivery && nav_can_deliveries')
        && str_contains(
            (string)file_get_contents($base . '/modules/daily-ledger/helpers.php'),
            "'/daily-ledger/admin/deliveries' => ['admin', 'supervisor', 'production_in_charge']"
        )
);
$h->test(
    'cashier and viewer still cannot create delivery documents',
    !preg_match("/'can_create_delivery_docs'\s*=>\s*in_array\(\\\$role,\s*\[[^\]]*'(cashier|viewer|auditor)'/", $deliveries)
);

$h->done();

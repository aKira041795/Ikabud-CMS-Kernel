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
    'the Deliveries MENU no longer offers this office page to production-in-charge',
    // SUPERSEDED 2026-11-13 by owner direction from the production in-charge's own sidebar:
    // "Deliveries and Production Delivery audit links are showing ... if it's not the concern of PIC,
    // don't show it". The MENU no longer offers these office pages to that role, while the document
    // CREATION right stays with it (asserted above) because creating DRs happens on the Daily Sheet,
    // not on this page. Creation and navigation are different questions and must not be tied again.
    str_contains($template, 'feature_formal_delivery && nav_can_deliveries')
        && str_contains(
            (string)file_get_contents($base . '/modules/daily-ledger/helpers.php'),
            "'deliveries' => ['admin', 'supervisor'],"
        )
);
$h->test(
    'the page and the menu answer to ONE decision, so a hidden page cannot half-load',
    // Measured 2026-11-13: the page was allowed while its nine api/v1/deliveries* endpoints were not,
    // so it rendered with the refusal text inside its own table and four 403s in the console.
    str_contains(
        (string)file_get_contents($base . '/modules/daily-ledger/helpers.php'),
        "if (isset(\$menuKeys[\$path])) {"
    )
);
$h->test(
    'cashier and viewer still cannot create delivery documents',
    !preg_match("/'can_create_delivery_docs'\s*=>\s*in_array\(\\\$role,\s*\[[^\]]*'(cashier|viewer|auditor)'/", $deliveries)
);

$h->done();

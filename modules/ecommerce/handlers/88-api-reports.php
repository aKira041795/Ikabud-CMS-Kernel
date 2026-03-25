<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Reports (handlers/88-api-reports.php)
// ─────────────────────────────────────────────────────────────────────────

function ecApiReportSales(): void
{
    ecRequireAdmin();
    $input  = ecInput();
    $report = ecReportSales([
        'period'     => $input['period']     ?? 'month',
        'start_date' => $input['start_date'] ?? '',
        'end_date'   => $input['end_date']   ?? '',
    ]);
    ecJsonOk($report);
}

function ecApiReportInventory(): void
{
    ecRequireAdmin();
    ecJsonOk(ecReportInventory());
}

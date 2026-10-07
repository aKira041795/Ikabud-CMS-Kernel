#!/usr/bin/env bash
#
# Acceptance probe for lane b2b-receiving-shift-declaration (contract A1).
#
# PASSES only once dl_deliveries.declared_receiving_shift exists.
# Measured on the UNCHANGED tree 2026-10-07: exits 1 (column absent) — the gate discriminates.
# The lane must run `php ikabud tenant:migrate 207 daily-ledger` for this to go green.

cd /var/www/html/applicationostest || exit 1

php -r '
require "bootstrap.php";
$cols = app()->dbForTenant(207)->query("SHOW COLUMNS FROM dl_deliveries LIKE \"declared_receiving_shift\"")->fetchAll(PDO::FETCH_ASSOC);
if (!$cols) {
    fwrite(STDERR, "declared_receiving_shift is ABSENT from dl_deliveries\n");
    exit(1);
}
foreach ($cols as $c) {
    fwrite(STDOUT, "declared_receiving_shift present: type={$c["Type"]} null={$c["Null"]} default=" . var_export($c["Default"], true) . "\n");
}
exit(0);
'

#!/usr/bin/env bash
#
# Acceptance probe for lane consignee-destinations (contract .ai/consignee-destinations.contract.md).
#
# This asserts the SCHEMA OUTCOME of the migration - the consignee destination model exists and the
# ENUM admits it. The deeper claims (exactly-once credit, and a consignee never leaking into a
# branch-facing surface) belong to the lane's isolation fixture, which the chair falsifies separately;
# a gate that tried to carry those would be too slow to run at dispatch and at landing.
#
# MEASURED on the unchanged tree 2026-10-08 BEFORE the change: none of these exist, so it exits 1.
# The gate discriminates.

cd /var/www/html/applicationostest || exit 1

php -r '
require "bootstrap.php";
$db = app()->dbForTenant(207);
$fail = [];

// 1. the consignee master
try { $db->query("SELECT 1 FROM dl_consignees LIMIT 1"); }
catch (Throwable $e) { $fail[] = "dl_consignees: " . $e->getMessage(); }

// 2. the consignee sheet backing store
try { $db->query("SELECT 1 FROM dl_consignee_ledger LIMIT 1"); }
catch (Throwable $e) { $fail[] = "dl_consignee_ledger: " . $e->getMessage(); }

// 3. the dedicated destination column (the collision-free half of the design)
$hasCol = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM dl_deliveries LIKE \"consignee_id\"")->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = $cols !== [];
    if (!$hasCol) { $fail[] = "dl_deliveries.consignee_id is ABSENT"; }
    elseif (strtoupper((string)$cols[0]["Null"]) !== "YES") { $fail[] = "dl_deliveries.consignee_id must be NULLABLE"; }
} catch (Throwable $e) { $fail[] = "consignee_id: " . $e->getMessage(); }

// 4. destination_type must admit consignee
try {
    $c = $db->query("SHOW COLUMNS FROM dl_deliveries LIKE \"destination_type\"")->fetch(PDO::FETCH_ASSOC);
    $type = (string)($c["Type"] ?? "");
    if (stripos($type, "consignee") === false) { $fail[] = "destination_type does not admit consignee: {$type}"; }
    else { echo "  destination_type: {$type}\n"; }
} catch (Throwable $e) { $fail[] = "destination_type: " . $e->getMessage(); }

// 5. the invariant must be enforced where MySQL can do it: destination_id stays branch-only
if ($hasCol) {
    $fk = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"dl_deliveries\"
                        AND COLUMN_NAME = \"consignee_id\" AND REFERENCED_TABLE_NAME = \"dl_consignees\"")->fetchAll(PDO::FETCH_ASSOC);
    if ($fk === []) { echo "  NOTE consignee_id has no FK to dl_consignees (app-level enforcement only)\n"; }
    else { echo "  consignee_id FK present\n"; }
}

if ($fail !== []) {
    foreach ($fail as $f) { fwrite(STDERR, "FAIL: {$f}\n"); }
    fwrite(STDERR, "FAIL: the consignee destination model is not in place\n");
    exit(1);
}

echo "PASS: dl_consignees, dl_consignee_ledger, dl_deliveries.consignee_id and the consignee ENUM value all exist\n";
exit(0);
'

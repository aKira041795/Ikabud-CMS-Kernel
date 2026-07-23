<?php
/**
 * Process all pending Academic Similarity submissions.
 *
 * Usage:
 *   php scripts/process-academic-similarity-pending.php
 *   php scripts/process-academic-similarity-pending.php --tenant=TENANT_ID
 *   php scripts/process-academic-similarity-pending.php --dry-run
 *
 * Finds all submissions with status='pending' and runs the full pipeline.
 * Can be scoped to one tenant or run across all tenants.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$tenantFilter = '';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantFilter = substr($arg, 9);
    }
}

echo "Academic Similarity — Process Pending Submissions\n";
echo "==================================================\n";
if ($dryRun) echo "  [DRY RUN — no changes will be made]\n";
if ($tenantFilter) echo "  Tenant filter: {$tenantFilter}\n";
echo "\n";

// Discover tenants
$tenants = [];
if ($tenantFilter !== '') {
    $tenants[] = $tenantFilter;
} else {
    try {
        $db = app()->db();
        $stmt = $db->query("SELECT tenant_key FROM kernel_tenants WHERE status = 'active'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tenants[] = $row['tenant_key'];
        }
    } catch (\Throwable $e) {
        echo "  ⚠ Tenant discovery failed: " . $e->getMessage() . "\n";
        // Fallback: try current tenant
        $current = app()->tenant()->current();
        if ($current) {
            $tenants[] = (string)$current;
        }
    }
}

$totalProcessed = 0;
$totalFailed = 0;
$totalSkipped = 0;

foreach ($tenants as $tenantId) {
    echo "\n── Tenant: {$tenantId} ──\n";

    try {
        $db = academic_similarity_db();
    } catch (\Throwable $e) {
        echo "  ❌ Cannot connect to module DB: " . $e->getMessage() . "\n";
        continue;
    }

    // Find pending submissions
    $stmt = $db->prepare(
        "SELECT id, submission_title, status, created_at 
         FROM ac_similarity_submissions 
         WHERE tenant_id = :tid AND status = 'pending' 
         ORDER BY created_at ASC"
    );
    $stmt->execute([':tid' => $tenantId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pending)) {
        echo "  No pending submissions.\n";
        continue;
    }

    echo "  Found " . count($pending) . " pending submission(s).\n";

    foreach ($pending as $sub) {
        $id = (int)$sub['id'];
        $title = mb_substr($sub['submission_title'] ?? 'Untitled', 0, 50);

        echo "  Processing #{$id}: \"{$title}\"... ";

        if ($dryRun) {
            echo "SKIP (dry-run)\n";
            $totalSkipped++;
            continue;
        }

        try {
            $pipeline = new AcademicSimilarityPipelineService($tenantId);
            $result = $pipeline->processSubmission($id);

            if ($result['ok'] ?? false) {
                echo "✅ DONE\n";
                $totalProcessed++;
            } else {
                echo "❌ FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
                $totalFailed++;
            }
        } catch (\Throwable $e) {
            echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
            $totalFailed++;
        }
    }
}

echo "\n==================================================\n";
echo "Summary: {$totalProcessed} processed, {$totalFailed} failed, {$totalSkipped} skipped\n";
exit($totalFailed > 0 ? 1 : 0);

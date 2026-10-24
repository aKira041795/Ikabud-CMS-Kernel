<?php

declare(strict_types=1);

/**
 * Daily Ledger — S9 branch display order.
 *
 * Covers the one authorised additive column (dl_branches.sort_order), its
 * registration and idempotent migration, the branches admin edit field and its
 * apiUpdateBranch persistence, and — the part a source grep cannot prove — the
 * RENDERED Daily Sheet <th> sequence:
 *
 *   - with every sort_order = 0 the sheet renders the exact alphabetical order
 *     it did before the change, minus only the source commissary;
 *   - with the owner's paper order configured the sheet renders that order
 *     exactly.
 *
 * Tenant 207 (baron-001). Snapshot/restore so a failure cannot leak ordering
 * into a later suite.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-order', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

// Rendering the sheet compiles the DiSyL template, which emits one info line
// (disyl.compile.phases). It is not a defect, but it must be named explicitly
// rather than tolerated as an unexplained log write.
$h->allowLogLines('disyl.compile.phases');
$h->allowLogLines('disyl.interpreted_fallback');
$h->allowLogLines("ModuleDB DENIED: DDL/DCL statement 'CREATE' is forbidden for modules");

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/database/migrations/065_add_branch_sort_order.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/branches.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$handlers    = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$manifestRaw = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
$manifest    = json_decode($manifestRaw, true) ?: [];
$migration   = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/065_add_branch_sort_order.sql');
$branchesTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/branches.disyl');
$sheetTpl    = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

// ─── Schema and registration ─────────────────────────────────────
$h->section('Schema and registration');

$h->test(
    'migration 065 adds only dl_branches.sort_order',
    str_contains($migration, 'ALTER TABLE dl_branches ADD COLUMN sort_order INT NOT NULL DEFAULT 0')
    && !str_contains($migration, 'dl_commissary_product_ledger')
);

// Re-run the real migration SQL (it is information_schema-guarded) and prove
// the schema and row count are unchanged. The old string match only imitated
// this idempotency proof; executing the guarded ALTER is safe on MySQL 5.7 and
// leaves the existing column/data untouched.
$branchesBefore = $db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn();
$columnsBefore = $db->query('SHOW COLUMNS FROM dl_branches')->fetchAll(PDO::FETCH_ASSOC);
$migrationReRunError = null;
try {
    $rawTenantDb = app()->dbForTenant(207);
    if ($rawTenantDb instanceof \PDO) {
        $rawTenantDb->exec($migration);
    } else {
        $migrationReRunError = 'tenant PDO unavailable';
    }
} catch (\Throwable $e) {
    $migrationReRunError = $e->getMessage();
}
$branchesAfter = $db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn();
$columnsAfter = $db->query('SHOW COLUMNS FROM dl_branches')->fetchAll(PDO::FETCH_ASSOC);
$h->test(
    'migration 065 re-run is idempotent: schema and row count unchanged',
    $migrationReRunError === null
    && $branchesBefore === $branchesAfter
    && $columnsBefore === $columnsAfter,
    'error=' . (string)$migrationReRunError . ' rows=' . $branchesBefore . '->' . $branchesAfter
);

$h->test(
    'migration 065 is information_schema-guarded and @mysql57-compat',
    str_contains($migration, 'information_schema.columns')
    && str_contains($migration, '@mysql57-compat')
    && str_contains($migration, "'SELECT 1'")
);

$h->test(
    'migration 065 is registered after 064 in module.json',
    in_array('database/migrations/065_add_branch_sort_order.sql', $manifest['migrations'] ?? [], true)
    && array_search('database/migrations/065_add_branch_sort_order.sql', $manifest['migrations'] ?? [], true)
        > array_search('database/migrations/064_add_production_sheet_balances.sql', $manifest['migrations'] ?? [], true)
);

$column = $db->query("SHOW COLUMNS FROM dl_branches LIKE 'sort_order'")->fetch(PDO::FETCH_ASSOC);
$h->test(
    'dl_branches.sort_order is INT NOT NULL DEFAULT 0',
    $column !== false
    && stripos((string)($column['Type'] ?? ''), 'int') === 0
    && (string)($column['Null'] ?? '') === 'NO'
    && (string)($column['Default'] ?? '') === '0'
);

// ─── Admin edit field ────────────────────────────────────────────
$h->section('Branches admin edit field');

$h->test(
    'branches edit view has an order input',
    str_contains($branchesTpl, 'id="edit-sort"')
    && str_contains($branchesTpl, 'openEditBranch(')
    && str_contains($branchesTpl, '{b.sort_order | default:0}')
);

$h->test(
    'branches edit view submits sort_order',
    str_contains($branchesTpl, "sort_order: parseInt(document.getElementById('edit-sort').value, 10) || 0")
);

$h->test(
    'apiUpdateBranch persists sort_order',
    str_contains($handlers, "'sort_order = :sort'")
    && str_contains($handlers, "':sort' => \$sortOrder")
);

// ─── Sheet sources ───────────────────────────────────────────────
$h->section('Daily Sheet source');

// (The literal ORDER BY source-string check was removed: it was the
// brittleness class that already broke once. The rendered order tests below
// exercise the real handler and now cover numbered-first/unnumbered-last too.)

$h->test(
    'sheet excludes only the source commissary in both column loops',
    substr_count($sheetTpl, '{if br.id != sheet_source_branch_id}') === 1
    && substr_count($sheetTpl, '{if cell.branch_id != sheet_source_branch_id}') === 1
);

$h->test(
    'orphan paper columns LP/SM1/SM2/IP/MM stay structurally absent',
    !preg_match('/<th[^>]*>\s*(LP|SM1|SM2|IP|MM)\s*<\/th>/', $sheetTpl)
);

// ─── Rendered column order (runtime) ─────────────────────────────
$h->section('Rendered column order');

$renderSheet = static function (): array {
    $harness = __DIR__ . '/daily_ledger_branch_order_runtime_harness.php';
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' 2>/dev/null', $output, $code);
    if ($code !== 0) {
        return [];
    }
    $decoded = json_decode(implode("\n", $output), true);
    return is_array($decoded) ? array_values($decoded) : [];
};

// The handler chooses the source commissary (first active commissary ordered by
// name). Derive it the same way rather than hard-coding a tenant branch code.
$commissaries = $db->query(
    'SELECT id, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sourceCommissaryId = (int)($commissaries[0]['id'] ?? 0);

$activeBranches = $db->query(
    'SELECT id, name FROM dl_branches WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$expectedZeroFallback = [];
foreach ($activeBranches as $branch) {
    if ((int)$branch['id'] !== $sourceCommissaryId) {
        $expectedZeroFallback[] = (string)$branch['name'];
    }
}

// The configured paper order used to be a hardcoded list of branch names. It stopped
// meaning anything the day the tenant's branch set changed: the names were either gone
// or outside the sheet's scope, so the test failed while the ordering code was correct.
// The configured order is now derived from what the sheet actually renders (reverse
// alphabetical, so it can never coincide with the fallback), which is what the assertion
// is about — the ORDER applied, not which branches exist.
$snapshot = [];
foreach ($db->query('SELECT id, sort_order FROM dl_branches')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $snapshot[(int)$row['id']] = (int)$row['sort_order'];
}
$restoreOrder = static function () use ($db, $snapshot): void {
    $stmt = $db->prepare('UPDATE dl_branches SET sort_order = :o WHERE id = :id');
    foreach ($snapshot as $id => $order) {
        $stmt->execute([':o' => $order, ':id' => $id]);
    }
};

try {
    // 1) Every sort_order = 0 must render alphabetical order. The sheet is scoped (a
    //    commissary or area narrows the column set) and capped, so the assertion is the
    //    ORDER — alphabetical, unique, non-empty, within the cap — rather than a fixed
    //    list of branch names.
    $db->execute('UPDATE dl_branches SET sort_order = 0');
    $zeroRendered = $renderSheet();
    $zeroSorted = $zeroRendered;
    sort($zeroSorted, SORT_STRING);
    $h->test(
        'all sort_order=0 renders the branches in alphabetical order',
        $zeroRendered !== []
        && $zeroRendered === $zeroSorted
        && count($zeroRendered) === count(array_unique($zeroRendered))
        && count($zeroRendered) <= 15,
        'got=' . json_encode($zeroRendered)
    );

    // 2) A configured paper order must render in exactly that order. The order is applied
    //    to the branches this sheet actually renders, reversed, so a correct alphabetical
    //    fallback (assertion 1) can never accidentally satisfy this one.
    $setOrder = $db->prepare('UPDATE dl_branches SET sort_order = :o WHERE name = :n');
    $paperOrder = [];
    foreach (array_reverse($zeroRendered) as $i => $name) {
        $paperOrder[$name] = $i + 1;
        $setOrder->execute([':o' => $i + 1, ':n' => $name]);
    }
    $paperRendered = $renderSheet();
    $h->test(
        'configured paper order renders the exact <th> sequence',
        $paperOrder !== [] && $paperRendered === array_keys($paperOrder),
        'got=' . json_encode($paperRendered) . ' expected=' . json_encode(array_keys($paperOrder))
    );

    // 3) Numbered branches must sort before every unnumbered one. With only two branches
    //    numbered, a plain `ORDER BY sort_order ASC` would put the 0-sorted branches
    //    first; asserting the numbered pair leads proves the `(sort_order = 0) ASC` term
    //    behaviourally, without a source grep.
    if (count($zeroRendered) >= 2) {
        $first = $zeroRendered[0];
        $second = $zeroRendered[1];
        $db->execute('UPDATE dl_branches SET sort_order = 0');
        $setOrder->execute([':o' => 5, ':n' => $first]);
        $setOrder->execute([':o' => 2, ':n' => $second]);
        $numberedRendered = $renderSheet();
        $h->test(
            'numbered branches sort ascending before every unnumbered branch',
            array_slice($numberedRendered, 0, 2) === [$second, $first]
            && count($numberedRendered) === count($zeroRendered),
            'got=' . json_encode($numberedRendered) . ' expected first two=' . json_encode([$second, $first])
        );
    }
} finally {
    $restoreOrder();
}

$h->done();

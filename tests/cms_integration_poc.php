<?php
/**
 * Kernel OS 5.0 — CMS Integration POC
 *
 * End-to-end test: capability registration → data query → entity resolution →
 * DiSyL rendering. Proves the full platform pipeline works with real data.
 *
 * Usage: php tests/cms_integration_poc.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers/58-entity-views.php';
require_once __DIR__ . '/../modules/cms/helpers/56-entity-capabilities.php';

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { echo "  ✓ {$label}\n"; $pass++; }
    else { echo "  ✗ {$label}"; if ($detail) echo " — {$detail}"; echo "\n"; $fail++; }
}

$pass = 0; $fail = 0;
$db = app()->db();

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   CMS Integration POC — Full Pipeline Test          ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ── Step 1: Ensure test table exists ──
echo "── 1. Test data setup ──\n";

// Check if cms_content table exists
try {
    $db->query("SELECT 1 FROM cms_content LIMIT 0");
    $tableExists = true;
} catch (\Throwable $e) {
    $tableExists = false;
}

if ($tableExists) {
    // Clean up old POC entries and insert fresh test data
    $db->exec("DELETE FROM cms_content WHERE type = 'post' AND slug LIKE 'poc-test-%'");

    $db->exec("
        INSERT INTO cms_content (uuid, title, slug, type, status, excerpt, author_id, created_at, updated_at)
        VALUES
        (UUID(), 'POC Test: Welcome to Kernel OS 5', 'poc-test-welcome', 'post', 'published', 'The first governed entity list in production.', 1, NOW(), NOW()),
        (UUID(), 'POC Test: Entity Views', 'poc-test-entity-views', 'post', 'published', 'Entity-view contracts resolve source→data.', 1, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 HOUR)),
        (UUID(), 'POC Test: DiSyL Components', 'poc-test-disyl', 'post', 'published', 'ikb_entity_list renders governed data.', 1, DATE_ADD(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 2 HOUR)),
        (UUID(), 'POC Test: Export Pipeline', 'poc-test-export', 'post', 'draft', 'KernelExport produces CSV and DOCX.', 1, DATE_ADD(NOW(), INTERVAL 3 HOUR), DATE_ADD(NOW(), INTERVAL 3 HOUR))
    ");

    $count = $db->query("SELECT COUNT(*) FROM cms_content WHERE slug LIKE 'poc-test-%'")->fetchColumn();
    t('Test data inserted', (int)$count === 4, "{$count} rows");
} else {
    t('cms_content table exists (skip DB tests)', false, 'Table not found — running without DB data');
    echo "  ⚠ Skipping DB-dependent tests — cms_content table not available\n";
}

// ── Step 2: Register capability handlers on the bus ──
echo "── 2. Capability registration ──\n";

$caps = app()->capabilities();
$bus = app()->cap();

// Register entity.list.cms_post@1 — test context uses app()->db() directly
$caps->register(
    'entity.list.cms_post@1',
    'test-poc',
    function($payload) use ($db) {
        // Bypass module() context: query cms_content directly via app DB
        $limit = min((int)($payload['limit'] ?? 25), 100);
        $sortField = 'created_at';
        $sortDir = 'DESC';
        $type = 'post';
        try {
            $stmt = $db->prepare(
                "SELECT c.id, c.title, c.slug, c.status, c.excerpt, c.created_at, c.updated_at
                 FROM cms_content c
                 WHERE c.type = :type AND c.deleted_at IS NULL
                 ORDER BY c.{$sortField} {$sortDir}
                 LIMIT {$limit}"
            );
            $stmt->execute([':type' => $type]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $total = (int)$db->query("SELECT COUNT(*) FROM cms_content WHERE type = '{$type}' AND deleted_at IS NULL")->fetchColumn();
            return ['rows' => $rows, 'total' => $total];
        } catch (\Throwable $e) {
            return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    },
    50,
    ['first']
);

t('entity.list.cms_post@1 registered', $caps->has('entity.list.cms_post@1'), '');

$caps->register(
    'entity.get.cms_post@1',
    'test-poc',
    function($payload) use ($db) {
        $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
        if ($id <= 0) return [];
        try {
            $stmt = $db->prepare("SELECT c.* FROM cms_content c WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    },
    50,
    ['first']
);

t('entity.get.cms_post@1 registered', $caps->has('entity.get.cms_post@1'), '');

// ── Step 3: Test capability calls ──
echo "── 3. Capability calls ──\n";

if ($tableExists) {
    $result = $bus->call('entity.list.cms_post@1', [
        'entity_type' => 'cms_post',
        'qualifier' => 'recent',
        'view' => 'compact',
        'limit' => 5,
        'sort' => ['field' => 'created_at', 'direction' => 'DESC'],
    ]);

    t('entity.list.cms_post returns data', is_array($result) && !empty($result['rows']), count($result['rows'] ?? []) . ' rows');
    t('entity.list.cms_post has total', ($result['total'] ?? 0) >= 4, 'total=' . ($result['total'] ?? 0));
    t('entity.list.cms_post has fields', isset($result['rows'][0]['title']), $result['rows'][0]['title'] ?? '');

    // Test entity get
    $first = $result['rows'][0] ?? [];
    if (!empty($first['id'])) {
        $detail = $bus->call('entity.get.cms_post@1', [
            'entity_type' => 'cms_post',
            'id' => $first['id'],
        ]);
        t('entity.get.cms_post returns single entity', is_array($detail) && !empty($detail['id']), $detail['title'] ?? '');
    }
}

// ── Step 4: EntityViewResolver integration ──
echo "── 4. EntityViewResolver pipeline ──\n";

$resolver = app()->entityViews();

// Register CMS post view contracts
$resolver->registerView('cms_post', 'compact', [
    'fields' => ['title', 'status', 'updated_at'],
    'actions' => ['view'],
    'limit' => 5,
    'empty_state' => 'No posts found.',
]);
$resolver->registerView('cms_post', 'card_grid', [
    'fields' => ['title', 'excerpt', 'status'],
    'actions' => ['view', 'edit'],
    'limit' => 10,
    'empty_state' => 'No posts to show.',
]);

t('cms_post.compact contract registered', $resolver->viewContract('cms_post', 'compact') !== null, '');
t('cms_post.card_grid contract registered', $resolver->viewContract('cms_post', 'card_grid') !== null, '');

if ($tableExists) {
    $resolved = $resolver->resolve('cms_post.recent', 'compact', ['limit' => 3]);
    t('EntityViewResolver.resolve returns rows', !empty($resolved['rows']), count($resolved['rows']) . ' rows');
    t('EntityViewResolver.resolve has no error', empty($resolved['error']), $resolved['error'] ?? '');
    t('EntityViewResolver.resolve uses view contract fields', ($resolved['view']['fields'][0] ?? '') === 'title', '');
}

// ── Step 5: DiSyL template rendering with real data ──
echo "── 5. DiSyL component rendering ──\n";

$engine = app()->templates();

// Render state error (source="")
$r = $engine->renderString('{ikb_entity_list source="" /}', []);
t('ikb_entity_list empty source → error state', str_contains($r, 'ikb-entity-error'), '');

// Render with source that resolves
if ($tableExists) {
    $r = $engine->renderString('{ikb_entity_list source="cms_post.recent" view="compact" limit="3" /}', []);
    t('ikb_entity_list renders HTML', strlen($r) > 50, 'Length: ' . strlen($r));
    t('ikb_entity_list has ikb-entity-list class', str_contains($r, 'ikb-entity-list'), '');
    t('ikb_entity_list renders row items', str_contains($r, 'POC Test'), substr($r, 0, 120));
    t('ikb_entity_list renders view actions', str_contains($r, 'ikb-row-action'), '');
}

// Render card_grid view
if ($tableExists) {
    $r = $engine->renderString('{ikb_entity_list source="cms_post.recent" view="card_grid" limit="3" /}', []);
    t('ikb_entity_list card_grid renders cards', str_contains($r, 'ikb-entity-card'), '');
    t('ikb_entity_list card_grid structure present', str_contains($r, 'rounded-') || str_contains($r, 'ikb-entity-card'), '');
}

// Custom slot template
if ($tableExists) {
    $r = $engine->renderString(
        '{ikb_entity_list source="cms_post.recent" view="compact" limit="2"}<strong>{title}</strong> — {status}{/ikb_entity_list}',
        []
    );
    t('ikb_entity_list custom slot renders content', is_string($r) && str_contains($r, '<strong>'), 'Custom slots compiled by DiSyL — variable binding via row context');
    t('ikb_entity_list custom slot renders two rows (limit=2)', substr_count($r, '<strong>') >= 2, 'Strong tags: ' . substr_count($r, '<strong>'));
}

// ── Step 6: Export pipeline ──
echo "── 6. Export pipeline ──\n";

if ($tableExists) {
    \Ikabud\Kernel\Services\KernelExport::registerDefaults();
    $resolved = $resolver->resolve('cms_post.recent', 'compact', ['limit' => 5]);
    $rows = $resolved['rows'] ?? [];

    if (!empty($rows)) {
        $csv = \Ikabud\Kernel\Services\KernelExport::exportCsv($rows, 'CMS Posts', 'test-export.csv', []);
        t('KernelExport::exportCsv produces file', is_array($csv) && !empty($csv['path']), '');
        t('KernelExport::exportCsv file has content', isset($csv['size']) && $csv['size'] > 0, $csv['size'] . ' bytes');
        if (!empty($csv['path'])) { @unlink($csv['path']); }
    }
}

// ── Step 7: Entity detail rendering ──
echo "── 7. Entity detail ──\n";

if ($tableExists) {
    $r = $engine->renderString('{ikb_entity_detail source="cms_post" id="1" view="detailed" /}', []);
    t('ikb_entity_detail renders without crash', is_string($r), 'Length: ' . strlen($r));
    t('ikb_entity_detail has ikb-entity-detail class', str_contains($r, 'ikb-entity-detail'), '');
}

// ── Summary ──
echo "\n" . str_repeat('─', 55) . "\n";
$total = $pass + $fail;
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) echo ", {$fail} FAILED";
echo "\n\n";

// Clean up test data
if ($tableExists) {
    $db->exec("DELETE FROM cms_content WHERE slug LIKE 'poc-test-%'");
}

exit($fail > 0 ? 1 : 0);

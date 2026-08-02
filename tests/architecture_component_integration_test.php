<?php
declare(strict_types=1);

/**
 * Integration tests for P1/P2 architectural components:
 *   - ResolvedEntityContext (P1.2)
 *   - Provenance tracking (P1.4)
 *   - Domain-specific merge rules (P1.5)
 *   - Context caching (P2.5)
 *   - CustomizerSchemaBuilder (P2.3)
 *   - Built-in default deprecation (P2.1)
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

use Ikabud\Kernel\EntityContext\ResolvedEntityContext;
use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Ikabud\Kernel\EntityContext\CustomizerSchemaBuilder;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "═══ P1/P2 Architectural Component Tests ═══\n\n";

// ════════════════════════════════════════════════════════════════
// 1. ResolvedEntityContext (P1.2) — Immutability
// ════════════════════════════════════════════════════════════════

echo "── 1. ResolvedEntityContext (P1.2) — Immutability ──\n";

$ctx = new ResolvedEntityContext(
    entityType: 'test_entity',
    view: 'compact',
    fields: ['id', 'name', 'status'],
    actions: ['view', 'edit'],
    limit: 20,
    sort: ['field' => 'name', 'direction' => 'asc'],
    emptyState: 'Nothing here.',
    errorState: 'Failed.',
    exportable: false,
    capability: null,
    provider: 'test-module',
    timeoutMs: 5000,
    sortableFields: ['name' => 'name'],
    fieldContracts: [],
    renderers: ['status' => 'badge:{"active":"Active|green"}'],
    actionUrls: ['view' => '/test/{id}'],
    actionMethods: [],
    actionConfirm: [],
    actionShowIf: [],
    actionLabels: [],
    keyField: 'id',
    provenance: [['provider' => 'test-module', 'timestamp' => '2026-01-01T00:00:00+00:00']],
);

t('entityType accessible', $ctx->entityType === 'test_entity');
t('view accessible', $ctx->view === 'compact');
t('fields accessible', $ctx->fields === ['id', 'name', 'status']);
t('actions accessible', $ctx->actions === ['view', 'edit']);
t('limit accessible', $ctx->limit === 20);
t('sort accessible', $ctx->sort === ['field' => 'name', 'direction' => 'asc']);
t('provider accessible', $ctx->provider === 'test-module');
t('timeoutMs accessible', $ctx->timeoutMs === 5000);
t('provenance accessible', $ctx->provenance !== null && count($ctx->provenance) === 1);
t('renderers accessible', isset($ctx->renderers['status']));

// Immutability: fromContract factory
$contract = [
    'fields' => ['id', 'title'],
    'actions' => ['view'],
    'limit' => 10,
    'sort' => ['field' => 'created_at', 'direction' => 'desc'],
    'provider' => 'my-module',
];
$ctx2 = ResolvedEntityContext::fromContract('my_entity', 'table', $contract);
t('fromContract sets entityType', $ctx2->entityType === 'my_entity');
t('fromContract sets view', $ctx2->view === 'table');
t('fromContract sets fields', $ctx2->fields === ['id', 'title']);
t('fromContract defaults empty_state', $ctx2->emptyState === 'No records found.');
t('fromContract defaults timeout', $ctx2->timeoutMs === 10000);
t('fromContract sets provider', $ctx2->provider === 'my-module');

// toArray
$arr = $ctx2->toArray();
t('toArray has entity_type key', ($arr['entity_type'] ?? '') === 'my_entity');
t('toArray has _provenance key', array_key_exists('_provenance', $arr));

// ════════════════════════════════════════════════════════════════
// 3. Domain-specific merge rules (P1.5)
// ════════════════════════════════════════════════════════════════

echo "\n── 3. Domain-specific Merge Rules (P1.5) ──\n";

$base = ResolvedEntityContext::fromContract('merge_test', 'compact', [
    'fields' => ['id', 'name'],
    'actions' => ['view'],
    'sort' => ['field' => 'created_at', 'direction' => 'desc'],
    'renderers' => ['status' => 'badge:old'],
    'action_urls' => ['view' => '/old/{id}'],
    'limit' => 20,
    'provider' => 'base-module',
]);

$override = ResolvedEntityContext::fromContract('merge_test', 'compact', [
    'fields' => ['name', 'email', 'status'],   // union with base
    'actions' => ['edit', 'view'],              // union with base
    'sort' => ['field' => 'name', 'direction' => 'asc'],  // full override
    'renderers' => ['status' => 'badge:new', 'email' => 'email'],  // per-key merge
    'action_urls' => ['edit' => '/new/{id}'],   // per-key merge
    'limit' => 50,                               // scalar override
    'provider' => 'override-module',
]);

$merged = $base->merge($override);

t('merged fields are union', $merged->fields === ['id', 'name', 'email', 'status']);
t('merged actions are union', $merged->actions === ['view', 'edit']);
t('merged sort is overridden', $merged->sort === ['field' => 'name', 'direction' => 'asc']);
t('merged renderers merge per-key (overridden)', $merged->renderers['status'] === 'badge:new');
t('merged renderers merge per-key (added)', $merged->renderers['email'] === 'email');
t('merged action_urls merge per-key', $merged->actionUrls['view'] === '/old/{id}');
t('merged action_urls merge per-key (added)', $merged->actionUrls['edit'] === '/new/{id}');
t('merged limit overridden', $merged->limit === 50);
t('merged provider is override', $merged->provider === 'override-module');

// ════════════════════════════════════════════════════════════════
// 4. Provenance tracking (P1.4)
// ════════════════════════════════════════════════════════════════

echo "\n── 4. Provenance Tracking (P1.4) ──\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

$resolver->registerView('provenance_test', 'compact', [
    'fields' => ['id', 'name'],
    'limit' => 10,
], 'module-a');

$registeredContracts = $resolver->registeredViewContracts();
t('resolver exposes registered contracts for diagnostics',
    isset($registeredContracts['provenance_test.compact'])
    && ($registeredContracts['provenance_test.compact']['provider'] ?? '') === 'module-a'
);

$contract = $resolver->viewContract('provenance_test', 'compact');
t('provenance recorded on first register',
    isset($contract['_provenance']) && count($contract['_provenance']) === 1
    && $contract['_provenance'][0]['provider'] === 'module-a'
);

// Re-register with different provider
$resolver->registerView('provenance_test', 'compact', [
    'fields' => ['id', 'name', 'email'],
    'limit' => 20,
], 'module-b');

$contract2 = $resolver->viewContract('provenance_test', 'compact');
t('provenance accumulates on re-register',
    isset($contract2['_provenance']) && count($contract2['_provenance']) === 2
);

// Provider order preserved in provenance
t('provenance first entry is module-a', $contract2['_provenance'][0]['provider'] === 'module-a');
t('provenance second entry is module-b', $contract2['_provenance'][1]['provider'] === 'module-b');

// resolvedContext accessor returns ResolvedEntityContext
$ctx = $resolver->resolvedContext('provenance_test', 'compact');
t('resolvedContext returns ResolvedEntityContext', $ctx instanceof ResolvedEntityContext);
t('resolvedContext has correct fields', $ctx !== null && $ctx->fields === ['id', 'name', 'email']);

// ════════════════════════════════════════════════════════════════
// 5. Context caching (P2.5)
// ════════════════════════════════════════════════════════════════

echo "\n── 5. Context Caching (P2.5) ──\n";

$resolver->reset();
$resolver->registerView('cached_entity', 'compact', [
    'fields' => ['id', 'name'],
    'limit' => 15,
]);

// First call — populates cache
$first = $resolver->viewContract('cached_entity', 'compact');
t('first viewContract call returns data', $first !== null && ($first['fields'] ?? []) === ['id', 'name']);

// Second call — serves from cache
$second = $resolver->viewContract('cached_entity', 'compact');
t('second viewContract call returns same data', $second !== null && ($second['fields'] ?? []) === ['id', 'name']);

// Re-registering invalidates cache
$resolver->registerView('cached_entity', 'compact', [
    'fields' => ['id', 'name', 'status'],
    'limit' => 25,
]);
$third = $resolver->viewContract('cached_entity', 'compact');
t('re-register invalidates cache', $third !== null && ($third['fields'] ?? []) === ['id', 'name', 'status']);

// reset clears cache
$resolver->reset();
$afterReset = $resolver->viewContract('cached_entity', 'compact');
t('reset clears cache but data still resolvable', $afterReset !== null);

// ════════════════════════════════════════════════════════════════
// 6. CustomizerSchemaBuilder (P2.3)
// ════════════════════════════════════════════════════════════════

echo "\n── 6. CustomizerSchemaBuilder (P2.3) ──\n";

$builder = new CustomizerSchemaBuilder();

// Empty input
$result = $builder->build([]);
t('empty build returns structured result', isset($result['entity_type'], $result['sections'], $result['resolved_capabilities']));
t('empty entity_type is empty string', $result['entity_type'] === '');
t('empty sections is []', $result['sections'] === []);
t('empty resolved_capabilities is []', $result['resolved_capabilities'] === []);

// With capabilities and customizer sections
$input = [
    'entity_type' => 'test_entity',
    'binding' => ['base' => 'test.profile'],
    'capabilities' => [
        'test.cap.display@1' => [
            'customizer' => [
                'section' => ['id' => 'display', 'label' => 'Display Settings', 'priority' => 20],
                'fields' => [
                    ['name' => 'theme', 'label' => 'Theme', 'type' => 'select', 'options' => ['light', 'dark'], 'priority' => 10],
                    ['name' => 'font_size', 'label' => 'Font Size', 'type' => 'number', 'min' => 8, 'max' => 72, 'priority' => 5],
                ],
            ],
        ],
    ],
];

$result2 = $builder->build($input);
t('build returns entity_type', $result2['entity_type'] === 'test_entity');
t('build returns binding', ($result2['context_profile']['base'] ?? '') === 'test.profile');
t('build returns capabilities list', in_array('test.cap.display@1', $result2['resolved_capabilities']));
t('build returns 1 section', count($result2['sections']) === 1);

$section = $result2['sections'][0] ?? [];
t('section has id', ($section['id'] ?? '') === 'display');
t('section has label', ($section['label'] ?? '') === 'Display Settings');
t('section has priority', ($section['priority'] ?? 0) === 20);
t('section has 2 fields', count($section['fields'] ?? []) === 2);

// Fields are sorted by priority descending (higher priority first)
$fields = $section['fields'] ?? [];
t('field[0] is theme (priority 10)', ($fields[0]['name'] ?? '') === 'theme');
t('field[1] is font_size (priority 5)', ($fields[1]['name'] ?? '') === 'font_size');

// Field metadata preserved
t('field has label', ($fields[0]['label'] ?? '') === 'Theme');
t('field has type', ($fields[0]['type'] ?? '') === 'select');
t('field has options', ($fields[0]['options'] ?? []) === ['light', 'dark']);
t('field has min/max', ($fields[1]['min'] ?? null) === 8 && ($fields[1]['max'] ?? null) === 72);

// Base sections
$result3 = $builder->build($input, [
    ['id' => 'general', 'label' => 'General', 'priority' => 30],
]);
t('base sections appear in output', count($result3['sections']) === 2);
$sectionIds = array_map(fn($s) => $s['id'], $result3['sections']);
t('display section present', in_array('display', $sectionIds));
t('general section present', in_array('general', $sectionIds));

// Empty capability section
$result4 = $builder->build([
    'capabilities' => [
        'test.cap.no_customizer@1' => ['some' => 'data'],
    ],
]);
t('capability without customizer produces no sections', $result4['sections'] === []);

// ════════════════════════════════════════════════════════════════
// 7. ViewResolver domain-specific merge via registerView (P1.5)
// ════════════════════════════════════════════════════════════════

echo "\n── 7. registerView Domain Merge (P1.5) ──\n";

$resolver->reset();

// Register with partial contract — defaults fill the rest
$resolver->registerView('domain_merge', 'table', [
    'fields' => ['id', 'name'],
    'actions' => ['view'],
    'sortable_fields' => ['name' => 'name'],
], 'test-module');

$c = $resolver->viewContract('domain_merge', 'table');
t('fields from contract', ($c['fields'] ?? []) === ['id', 'name']);
t('actions from contract', ($c['actions'] ?? []) === ['view']);
t('sort defaults when not provided', ($c['sort']['field'] ?? '') === 'created_at');
t('limit defaults when not provided', ($c['limit'] ?? 0) === 25);
t('empty_state defaults', ($c['empty_state'] ?? '') === 'No records found.');
t('sortable_fields from contract', ($c['sortable_fields'] ?? []) === ['name' => 'name']);
t('provider from contract', ($c['provider'] ?? '') === 'test-module');
t('timeout_ms defaults', ($c['timeout_ms'] ?? 0) === 10000);

// Register again with override — domain merge merges with defaults, not previous entry
// (Re-registration is last-wins for the entry; the merge logic combines contract + defaults)
$resolver->registerView('domain_merge', 'table', [
    'fields' => ['email', 'status'],   // replaces previous
    'actions' => ['edit'],             // replaces previous
    'empty_state' => 'No matching records.',
    'timeout_ms' => 15000,
], 'test-module-v2');

$c2 = $resolver->viewContract('domain_merge', 'table');
t('fields replaced on re-register', $c2['fields'] === ['email', 'status']);
t('actions replaced on re-register', $c2['actions'] === ['edit']);
t('empty_state overridden', $c2['empty_state'] === 'No matching records.');
t('timeout_ms overridden', $c2['timeout_ms'] === 15000);
t('provider updated', $c2['provider'] === 'test-module-v2');

// ════════════════════════════════════════════════════════════════
// 8. Built-in defaults deprecation (P2.1) — legacy fallback
// ════════════════════════════════════════════════════════════════

echo "\n── 8. Built-in Defaults Fallback (P2.1) ──\n";

// Entity with no module registration should fall back to built-in defaults
$legacy = $resolver->viewContract('orders', 'compact');
t('orders entity falls back to built-in', $legacy !== null && ($legacy['fields'] ?? []) === ['id', 'status', 'total', 'created_at']);
t('built-in fallback has provider kernel.builtin', ($legacy['provider'] ?? '') === 'kernel.builtin');
t('built-in fallback has provenance', isset($legacy['_provenance']));

// Unknown entity returns generic built-in fallback
$unknown = $resolver->viewContract('nonexistent_entity_xyz', 'compact');
t('unknown entity gets generic fallback', $unknown !== null);
t('unknown entity fallback has wildcard fields', ($unknown['fields'] ?? '') === '*');
t('unknown entity fallback has generic empty_state', str_contains($unknown['empty_state'] ?? '', 'No records'));

// ════════════════════════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════════════════════════

echo "\n═══ Results: {$pass} passed, {$fail} failed ═══\n";
exit($fail > 0 ? 1 : 0);

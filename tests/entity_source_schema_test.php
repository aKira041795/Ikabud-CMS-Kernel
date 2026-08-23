<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function clearEntitySourceSchemaLogs(): void
{
    file_put_contents(STORAGE_PATH . '/logs/app.log', '');
    file_put_contents(STORAGE_PATH . '/logs/error.log', '');
}

function appLogContains(string $needle): bool
{
    $log = @file_get_contents(STORAGE_PATH . '/logs/app.log');
    return is_string($log) && str_contains($log, $needle);
}

function loadEcommerceEntityViews(): void
{
    require BASE_PATH . '/modules/ecommerce/helpers/43-entity-views.php';
}

echo "── Entity Source Schema Test ──\n\n";

$resolver = app()->entityViews();
$renderer = new DefaultEntityRenderer();
clearEntitySourceSchemaLogs();
$resolver->reset();
loadEcommerceEntityViews();

$products = $resolver->viewContract('products', 'compact');
$productsContext = $resolver->resolvedContext('products', 'compact');
t('owner match resolves ecommerce products contract', ($products['provider'] ?? '') === 'ecommerce', json_encode($products));
t('owner match exposes source_schema in array contract', ($products['source_schema']['owner'] ?? '') === 'ecommerce', json_encode($products['source_schema'] ?? null));
t('owner match exposes source_schema in resolved context', ($productsContext?->sourceSchema['entity'] ?? '') === 'products');

$rendered = $renderer->renderList([
    ['id' => 7, 'name' => 'Schema Product', 'price' => 12.5, 'image' => '/x.png'],
], $products, ['source' => 'products.recent', 'view' => 'compact'], []);
t('POC 4 products view still renders', str_contains($rendered, 'Schema Product') && str_contains($rendered, 'add_to_cart'));

$ownerMismatchRejected = false;
try {
    $resolver->registerView('schema_owner_mismatch', 'compact', [
        'fields' => ['id'],
        'source_schema' => [
            'entity' => 'schema_owner_mismatch',
            'owner' => 'wms',
            'fields' => ['id' => 'int'],
        ],
    ], 'ecommerce');
} catch (\InvalidArgumentException $e) {
    $ownerMismatchRejected = str_contains($e->getMessage(), 'owner mismatch');
}
t('owner mismatch registration is rejected', $ownerMismatchRejected);
t('owner mismatch log emitted', appLogContains('entity.source_schema.owner_mismatch'));

$unknownFieldRejected = false;
try {
    $resolver->registerView('schema_unknown_field', 'compact', [
        'fields' => ['id', 'ghost_field'],
        'source_schema' => [
            'entity' => 'schema_unknown_field',
            'fields' => ['id' => 'int'],
        ],
    ], 'ecommerce');
} catch (\InvalidArgumentException $e) {
    $unknownFieldRejected = str_contains($e->getMessage(), 'ghost_field');
}
t('unknown field registration is rejected', $unknownFieldRejected);
t('unknown field log emitted', appLogContains('entity.source_schema.unknown_field'));

$invalidTypeRejected = false;
try {
    $resolver->registerView('schema_invalid_type', 'compact', [
        'fields' => ['id'],
        'source_schema' => [
            'entity' => 'schema_invalid_type',
            'fields' => ['id' => 'uuid'],
        ],
    ], 'ecommerce');
} catch (\InvalidArgumentException $e) {
    $invalidTypeRejected = str_contains($e->getMessage(), 'invalid type');
}
t('invalid source schema type is rejected', $invalidTypeRejected);
t('invalid type log emitted', appLogContains('entity.source_schema.invalid_type'));

$resolver->registerView('schema_cross_module', 'compact', [
    'fields' => ['id'],
    'source_schema' => [
        'entity' => 'schema_cross_module',
        'owner' => 'wms',
        'fields' => ['id' => 'int'],
    ],
    'cross_module_approved' => [
        'note' => 'approved for test',
        'reason' => 'reads_tables ADR-003',
    ],
], 'ecommerce');
$approved = $resolver->viewContract('schema_cross_module', 'compact');
$approvedProv = $approved['_provenance'][0] ?? [];
t('cross_module_approved permits owner mismatch', ($approved['source_schema']['owner'] ?? '') === 'wms', json_encode($approved));
t('cross_module_approved is recorded in provenance', ($approvedProv['cross_module_approved']['reason'] ?? '') === 'reads_tables ADR-003', json_encode($approvedProv));
t('cross_module_approved log emitted', appLogContains('entity.source_schema.cross_module_approved'));

$resolver->registerView('*', 'compact', [
    'fields' => '*',
    'source_schema' => [
        'entity' => '*',
        'owner' => 'kernel',
        'fields' => [],
    ],
], 'kernel');
$kernelStructural = $resolver->viewContract('*', 'compact');
t('kernel structural-only schema is allowed', ($kernelStructural['source_schema']['owner'] ?? '') === 'kernel', json_encode($kernelStructural));
t('kernel structural-only schema names no business fields', ($kernelStructural['source_schema']['fields'] ?? null) === [] && ($kernelStructural['fields'] ?? null) === '*', json_encode($kernelStructural));

$resolver->registerView('legacy_entity', 'compact', [
    'fields' => ['id', 'name'],
    'actions' => ['view'],
], 'legacy_mod');
$legacy = $resolver->viewContract('legacy_entity', 'compact');
t('legacy registration without source_schema still works', ($legacy['provider'] ?? '') === 'legacy_mod' && ($legacy['fields'] ?? null) === ['id', 'name'], json_encode($legacy));
t('legacy registration without source_schema stays unchanged', array_key_exists('source_schema', $legacy) && $legacy['source_schema'] === null, json_encode($legacy));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";

exit($fail > 0 ? 1 : 0);

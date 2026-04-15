<?php
/**
 * Tier 4 Feature Completeness Tests
 *
 * Covers: workflow transition guards (4.7), module graph output formats (4.5),
 * content revision diffing (4.8), OpenAPI spec generator (4.3),
 * tenant provisioner service (4.4), locale resolver / i18n (4.2),
 * marketplace + POS schema foundations (4.1/4.9), DiSyL fuzz reference (4.6).
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../kernel/WorkflowRuntime.php';
require_once __DIR__ . '/../kernel/Services/OpenApiGenerator.php';
require_once __DIR__ . '/../kernel/Services/TenantProvisioner.php';
require_once __DIR__ . '/../kernel/Services/LocaleResolver.php';

use Ikabud\Kernel\WorkflowRuntime;
use Ikabud\Kernel\Services\OpenApiGenerator;
use Ikabud\Kernel\Services\TenantProvisioner;
use Ikabud\Kernel\Services\LocaleResolver;

$passed = 0;
$failed = 0;
function t4assert(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS: $msg\n"; }
    else       { $failed++; echo "  FAIL: $msg\n"; }
}

// Silence HTML output from module loading
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    if ($errno === E_WARNING || $errno === E_NOTICE || $errno === E_DEPRECATED) return true;
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// -----------------------------------------------------------------
// 1. Workflow Transition Guards (4.7)
// -----------------------------------------------------------------
echo "=== 1. Workflow Transition Guards ===\n";

$rt = app()->workflow();

// Build a definition with guarded transitions
$transitionsJson = json_encode([
    ['from' => 'draft', 'action' => 'submit', 'to' => 'review', 'roles' => []],
    ['from' => 'draft', 'action' => 'fast_publish', 'to' => 'published', 'roles' => [],
     'guard' => ['field' => 'is_admin', 'operator' => 'eq', 'value' => true]],
    ['from' => 'review', 'action' => 'approve', 'to' => 'published', 'roles' => ['editor'],
     'guard' => ['field' => 'word_count', 'operator' => 'gte', 'value' => 100]],
    ['from' => 'review', 'action' => 'reject', 'to' => 'draft', 'roles' => ['editor']],
    ['from' => 'published', 'action' => 'archive', 'to' => 'archived', 'roles' => [],
     'guard' => ['field' => 'age_days', 'operator' => 'gt', 'value' => 30]],
]);
$definition = ['transitions_json' => $transitionsJson];

// 1a. No guard context — unguarded transitions pass, guarded transitions fail
$actions = $rt->allowedActions($definition, 'draft', null, []);
$actionNames = array_column($actions, 'action');
t4assert(in_array('submit', $actionNames), 'Unguarded submit action is allowed');
t4assert(!in_array('fast_publish', $actionNames), 'Guarded fast_publish blocked without context');

// 1b. Guard context satisfies condition
$actions = $rt->allowedActions($definition, 'draft', null, ['is_admin' => true]);
$actionNames = array_column($actions, 'action');
t4assert(in_array('submit', $actionNames), 'Unguarded submit still allowed with context');
t4assert(in_array('fast_publish', $actionNames), 'Guarded fast_publish allowed when is_admin=true');

// 1c. Guard context does NOT satisfy condition
$actions = $rt->allowedActions($definition, 'draft', null, ['is_admin' => false]);
$actionNames = array_column($actions, 'action');
t4assert(!in_array('fast_publish', $actionNames), 'Guarded fast_publish blocked when is_admin=false');

// 1d. Numeric guard — gte
$actions = $rt->allowedActions($definition, 'review', 'editor', ['word_count' => 150]);
$actionNames = array_column($actions, 'action');
t4assert(in_array('approve', $actionNames), 'Approve allowed when word_count >= 100');
t4assert(in_array('reject', $actionNames), 'Unguarded reject always allowed');

$actions = $rt->allowedActions($definition, 'review', 'editor', ['word_count' => 50]);
$actionNames = array_column($actions, 'action');
t4assert(!in_array('approve', $actionNames), 'Approve blocked when word_count < 100');

// 1e. Numeric guard — gt
$actions = $rt->allowedActions($definition, 'published', null, ['age_days' => 31]);
t4assert(count($actions) === 1 && $actions[0]['action'] === 'archive', 'Archive allowed when age_days > 30');
$actions = $rt->allowedActions($definition, 'published', null, ['age_days' => 30]);
t4assert(count($actions) === 0, 'Archive blocked when age_days == 30 (gt, not gte)');

// 1f. Declarative operators: in, not_in, neq, empty, not_empty
$opDef = ['transitions_json' => json_encode([
    ['from' => 's', 'action' => 'a1', 'to' => 't', 'roles' => [],
     'guard' => ['field' => 'status', 'operator' => 'in', 'value' => ['active', 'trial']]],
    ['from' => 's', 'action' => 'a2', 'to' => 't', 'roles' => [],
     'guard' => ['field' => 'status', 'operator' => 'not_in', 'value' => ['banned', 'deleted']]],
    ['from' => 's', 'action' => 'a3', 'to' => 't', 'roles' => [],
     'guard' => ['field' => 'note', 'operator' => 'not_empty']],
    ['from' => 's', 'action' => 'a4', 'to' => 't', 'roles' => [],
     'guard' => ['field' => 'note', 'operator' => 'empty']],
    ['from' => 's', 'action' => 'a5', 'to' => 't', 'roles' => [],
     'guard' => ['field' => 'type', 'operator' => 'neq', 'value' => 'internal']],
])];

$ctx = ['status' => 'active', 'note' => 'hello', 'type' => 'public'];
$actions = $rt->allowedActions($opDef, 's', null, $ctx);
$names = array_column($actions, 'action');
t4assert(in_array('a1', $names), 'IN operator: active in [active,trial]');
t4assert(in_array('a2', $names), 'NOT_IN operator: active not in [banned,deleted]');
t4assert(in_array('a3', $names), 'NOT_EMPTY operator: note is not empty');
t4assert(!in_array('a4', $names), 'EMPTY operator: note is not empty → blocked');
t4assert(in_array('a5', $names), 'NEQ operator: public != internal');

// 1g. Callable guard
$callableDef = ['transitions_json' => json_encode([
    ['from' => 's', 'action' => 'go', 'to' => 't', 'roles' => []],
])];
// Inject callable guard directly into decoded transitions — test the method via reflection
$refClass = new ReflectionClass($rt);
$method = $refClass->getMethod('evaluateGuard');
$method->setAccessible(true);
$result = $method->invoke($rt, ['guard' => fn($ctx) => ($ctx['ok'] ?? false) === true], ['ok' => true]);
t4assert($result === true, 'Callable guard returns true when ctx[ok]=true');
$result = $method->invoke($rt, ['guard' => fn($ctx) => ($ctx['ok'] ?? false) === true], ['ok' => false]);
t4assert($result === false, 'Callable guard returns false when ctx[ok]=false');

// 1h. Guard that throws — fail closed
$result = $method->invoke($rt, ['guard' => fn($ctx) => throw new \RuntimeException('boom')], []);
t4assert($result === false, 'Throwing guard fails closed');

// 1i. No guard key — always passes
$result = $method->invoke($rt, [], []);
t4assert($result === true, 'No guard key → always allowed');

echo "\n";

// -----------------------------------------------------------------
// 2. Module Graph Output Formats (4.5)
// -----------------------------------------------------------------
echo "=== 2. Module Graph Output Formats ===\n";

$ikabudPath = __DIR__ . '/../ikabud';
$ikabudContent = file_get_contents($ikabudPath);
t4assert(str_contains($ikabudContent, "'mermaid'"), 'ikabud has mermaid format support');
t4assert(str_contains($ikabudContent, "'dot'"), 'ikabud has DOT format support');
t4assert(str_contains($ikabudContent, "'json'"), 'ikabud has JSON format support');
t4assert(str_contains($ikabudContent, "graph TD"), 'Mermaid output uses graph TD');
t4assert(str_contains($ikabudContent, "digraph modules"), 'DOT output uses digraph');
t4assert(str_contains($ikabudContent, "JSON_PRETTY_PRINT"), 'JSON output is pretty-printed');
t4assert(str_contains($ikabudContent, "getFlagValue('--format=')"), 'Uses --format= flag');

echo "\n";

// -----------------------------------------------------------------
// 3. Content Revision Diffing (4.8)
// -----------------------------------------------------------------
echo "=== 3. Content Revision Diffing ===\n";

// Load CMS helpers
ob_start();
foreach (glob(__DIR__ . '/../modules/cms/helpers/*.php') as $f) {
    require_once $f;
}
ob_end_clean();

// 3a. Functions exist
t4assert(function_exists('cmsBuilderRevisionList'), 'cmsBuilderRevisionList exists');
t4assert(function_exists('cmsBuilderRevisionGet'), 'cmsBuilderRevisionGet exists');
t4assert(function_exists('cmsBuilderRevisionDiff'), 'cmsBuilderRevisionDiff exists');
t4assert(function_exists('cmsBuilderRevisionRestore'), 'cmsBuilderRevisionRestore exists');
t4assert(function_exists('_cmsBuilderFlattenNodes'), '_cmsBuilderFlattenNodes exists');
t4assert(function_exists('_cmsBuilderDiffAssoc'), '_cmsBuilderDiffAssoc exists');

// 3b. Flatten nodes
$tree = [
    'id' => 'root',
    'type' => 'container',
    'props' => ['class' => 'main'],
    'styles' => [],
    'children' => [
        ['id' => 'child1', 'type' => 'text', 'props' => ['content' => 'hello'], 'styles' => ['color' => 'red'], 'children' => []],
        ['id' => 'child2', 'type' => 'image', 'props' => ['src' => '/img.jpg'], 'styles' => [], 'children' => []],
    ],
];
$flat = _cmsBuilderFlattenNodes($tree);
t4assert(count($flat) === 3, 'Flatten produces 3 nodes from tree');
t4assert(isset($flat['root']) && $flat['root']['type'] === 'container', 'Root node flattened correctly');
t4assert(isset($flat['child1']) && $flat['child1']['type'] === 'text', 'Child1 flattened correctly');

// 3c. Diff assoc
$diffs = _cmsBuilderDiffAssoc(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3, 'c' => 4]);
t4assert(count($diffs) === 2, 'DiffAssoc finds 2 changes (b changed, c added)');
$keys = array_column($diffs, 'key');
t4assert(in_array('b', $keys), 'DiffAssoc detects changed key');
t4assert(in_array('c', $keys), 'DiffAssoc detects added key');

// 3d. Full revision diff
$docA = [
    'id' => 'root', 'type' => 'container', 'props' => [], 'styles' => [],
    'children' => [
        ['id' => 'n1', 'type' => 'text', 'props' => ['content' => 'old'], 'styles' => ['color' => 'red'], 'children' => []],
        ['id' => 'n2', 'type' => 'image', 'props' => ['src' => '/a.jpg'], 'styles' => [], 'children' => []],
    ],
];
$docB = [
    'id' => 'root', 'type' => 'container', 'props' => [], 'styles' => [],
    'children' => [
        ['id' => 'n1', 'type' => 'text', 'props' => ['content' => 'new'], 'styles' => ['color' => 'blue'], 'children' => []],
        ['id' => 'n3', 'type' => 'button', 'props' => ['label' => 'Click'], 'styles' => [], 'children' => []],
    ],
];

$diff = cmsBuilderRevisionDiff($docA, $docB);
t4assert(is_array($diff), 'Diff returns array');
t4assert($diff['total_changes'] > 0, 'Diff detects changes');
t4assert($diff['added'] === 1, 'Diff detects 1 added node (n3)');
t4assert($diff['removed'] === 1, 'Diff detects 1 removed node (n2)');
t4assert($diff['modified'] >= 1, 'Diff detects at least 1 modified node (n1)');

// Check the modification details for n1
$n1change = null;
foreach ($diff['changes'] as $c) {
    if (($c['node_id'] ?? '') === 'n1' && ($c['type'] ?? '') === 'modified') {
        $n1change = $c;
        break;
    }
}
t4assert($n1change !== null, 'n1 modification found in changes');
t4assert(!empty($n1change['prop_changes']), 'n1 has prop changes (content old→new)');
t4assert(!empty($n1change['style_changes']), 'n1 has style changes (color red→blue)');

// 3e. Identical docs produce no changes
$same = cmsBuilderRevisionDiff($docA, $docA);
t4assert($same['total_changes'] === 0, 'Identical docs produce zero changes');

echo "\n";

// -----------------------------------------------------------------
// 4. OpenAPI Spec Generator (4.3)
// -----------------------------------------------------------------
echo "=== 4. OpenAPI Spec Generator ===\n";

$routes = [
    'GET' => [
        '/api/v1/health' => 'apiHealth',
        '/api/v1/admin/users' => 'apiAdminUsers',
        '/api/v1/me' => 'apiMe',
        '/login' => 'pageLogin',
    ],
    'POST' => [
        '/api/v1/auth/login' => 'authLogin',
        '/api/v1/admin/modules/install' => 'apiInstallModule',
    ],
    'DELETE' => [
        '/api/v1/admin/items/{id}' => 'apiDeleteItem',
    ],
];

$gen = new OpenApiGenerator($routes, 'Test API', '2.0.0');
$spec = $gen->generate();

t4assert($spec['openapi'] === '3.0.3', 'Spec version is 3.0.3');
t4assert($spec['info']['title'] === 'Test API', 'Title matches');
t4assert($spec['info']['version'] === '2.0.0', 'Version matches');
t4assert(isset($spec['paths']), 'Paths present');
t4assert(count($spec['paths']) === 7, 'All 7 route paths in spec');
t4assert(isset($spec['paths']['/api/v1/health']['get']), 'GET /health mapped');
t4assert(isset($spec['paths']['/api/v1/auth/login']['post']), 'POST /auth/login mapped');
t4assert(isset($spec['paths']['/api/v1/admin/items/{id}']['delete']), 'DELETE /items/{id} mapped');

// Tags
$tagNames = array_column($spec['tags'], 'name');
t4assert(in_array('admin', $tagNames), 'admin tag present');
t4assert(in_array('auth', $tagNames), 'auth tag present');
t4assert(in_array('public', $tagNames), 'public tag present');

// Security
$healthOp = $spec['paths']['/api/v1/health']['get'];
t4assert($healthOp['operationId'] === 'apiHealth', 'operationId matches handler');

$loginOp = $spec['paths']['/api/v1/auth/login']['post'];
t4assert(array_key_exists('security', $loginOp) && $loginOp['security'] === [], 'Auth login has no security requirement');

$adminOp = $spec['paths']['/api/v1/admin/users']['get'];
t4assert(isset($adminOp['security']), 'Admin route has security');

// Request body for POST
t4assert(isset($loginOp['requestBody']), 'POST has requestBody');

// Path parameters
$deleteOp = $spec['paths']['/api/v1/admin/items/{id}']['delete'];
t4assert(isset($deleteOp['parameters']), 'DELETE with {id} has parameters');
$paramNames = array_column($deleteOp['parameters'], 'name');
t4assert(in_array('id', $paramNames), 'Path param "id" extracted');

// JSON output
$json = $gen->toJson();
t4assert(is_string($json) && strlen($json) > 100, 'toJson produces valid output');
$decoded = json_decode($json, true);
t4assert(is_array($decoded) && $decoded['openapi'] === '3.0.3', 'JSON decodes back correctly');

// Module route tags
$modRoutes = [
    'GET' => ['/api/v1/cms/content' => 'cms:cmsApiContentList'],
    'POST' => [],
];
$modGen = new OpenApiGenerator($modRoutes);
$modSpec = $modGen->generate();
$modTags = array_column($modSpec['tags'], 'name');
t4assert(in_array('cms', $modTags), 'Module handler creates module-specific tag');
$cmsOp = $modSpec['paths']['/api/v1/cms/content']['get'];
t4assert($cmsOp['operationId'] === 'cms_cmsApiContentList', 'Module operationId uses underscore separator');

// Security schemes
t4assert(isset($spec['components']['securitySchemes']['bearerAuth']), 'Bearer auth scheme defined');
t4assert(isset($spec['components']['securitySchemes']['cookieAuth']), 'Cookie auth scheme defined');

echo "\n";

// -----------------------------------------------------------------
// 5. Tenant Provisioner Service (4.4)
// -----------------------------------------------------------------
echo "=== 5. Tenant Provisioner Service ===\n";

t4assert(class_exists(TenantProvisioner::class), 'TenantProvisioner class exists');

$refClass = new ReflectionClass(TenantProvisioner::class);
t4assert($refClass->hasMethod('provision'), 'provision() method exists');
t4assert($refClass->hasMethod('validate'), 'validate() method exists');
t4assert($refClass->hasMethod('getLog'), 'getLog() method exists');
t4assert($refClass->hasMethod('getErrors'), 'getErrors() method exists');

// Check provision method signature
$provisionMethod = $refClass->getMethod('provision');
$params = $provisionMethod->getParameters();
t4assert($params[0]->getName() === 'tenantId', 'provision() first param is tenantId');
t4assert($params[1]->getName() === 'options', 'provision() second param is options');
t4assert($params[1]->isOptional(), 'options param is optional');

// Check validate returns expected structure
$validateMethod = $refClass->getMethod('validate');
$params = $validateMethod->getParameters();
t4assert($params[0]->getName() === 'tenantId', 'validate() takes tenantId');

echo "\n";

// -----------------------------------------------------------------
// 6. Locale Resolver / i18n Foundation (4.2)
// -----------------------------------------------------------------
echo "=== 6. Locale Resolver (i18n) ===\n";

t4assert(class_exists(LocaleResolver::class), 'LocaleResolver class exists');

$resolver = new LocaleResolver();

// Default locale
$locale = $resolver->resolve();
t4assert($locale === 'en', 'Default locale is en');

// Override locale
$resolver->setLocale('fr');
t4assert($resolver->resolve() === 'fr', 'setLocale overrides resolution');

// Set default
$resolver2 = new LocaleResolver();
$resolver2->setDefault('es');
// Without any request globals, falls through to default
$_GET = [];
$_COOKIE = [];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
t4assert($resolver2->resolve() === 'es', 'setDefault changes fallback locale');

// Trans with fallback to key
$resolver3 = new LocaleResolver();
$result = $resolver3->trans('messages.welcome');
t4assert($result === 'messages.welcome', 'trans() falls back to key when DB unavailable');

// Trans with replacements (key fallback)
$result = $resolver3->trans('greet.:name', ['name' => 'Bob']);
// Key fallback doesn't have placeholders matched in the key itself, so the key is returned as-is
t4assert(is_string($result), 'trans() with replacements returns string');

// i18n migration exists
$migrationFile = __DIR__ . '/../migrations/008_kernel_i18n_foundation.sql';
t4assert(file_exists($migrationFile), 'i18n migration file exists');
$migrationSql = file_get_contents($migrationFile);
t4assert(str_contains($migrationSql, 'kernel_locales'), 'Migration has kernel_locales table');
t4assert(str_contains($migrationSql, 'kernel_translations'), 'Migration has kernel_translations table');
t4assert(str_contains($migrationSql, 'locale'), 'Migration has locale column');
t4assert(str_contains($migrationSql, 'namespace'), 'Translation has namespace column');
t4assert(str_contains($migrationSql, 'item_key'), 'Translation has item_key column');

echo "\n";

// -----------------------------------------------------------------
// 7. Marketplace Foundation Schema (4.1)
// -----------------------------------------------------------------
echo "=== 7. Marketplace Foundation (4.1) ===\n";

$mpFile = __DIR__ . '/../modules/ecommerce/database/migrations/040_ec_marketplace_foundation.sql';
t4assert(file_exists($mpFile), 'Marketplace migration file exists');
$mpSql = file_get_contents($mpFile);
t4assert(str_contains($mpSql, 'ec_marketplace_vendors'), 'Has vendors table');
t4assert(str_contains($mpSql, 'ec_marketplace_payouts'), 'Has payouts table');
t4assert(str_contains($mpSql, 'ec_marketplace_product_vendors'), 'Has product-vendor mapping');
t4assert(str_contains($mpSql, 'commission_rate'), 'Vendors have commission_rate');
t4assert(str_contains($mpSql, 'payout_method'), 'Vendors have payout_method');
t4assert(str_contains($mpSql, 'vendor_slug'), 'Vendors have slug for public URLs');

// module.json updated
$modJson = json_decode(file_get_contents(__DIR__ . '/../modules/ecommerce/module.json'), true);
t4assert(in_array('ec_marketplace_vendors', $modJson['owns_tables'] ?? []), 'module.json owns ec_marketplace_vendors');
t4assert(in_array('ec_marketplace_payouts', $modJson['owns_tables'] ?? []), 'module.json owns ec_marketplace_payouts');
t4assert(in_array('ec_marketplace_product_vendors', $modJson['owns_tables'] ?? []), 'module.json owns ec_marketplace_product_vendors');

echo "\n";

// -----------------------------------------------------------------
// 8. POS Expansion Schema (4.9)
// -----------------------------------------------------------------
echo "=== 8. POS Expansion (4.9) ===\n";

$posFile = __DIR__ . '/../modules/ecommerce/database/migrations/041_ec_pos_expansion.sql';
t4assert(file_exists($posFile), 'POS expansion migration file exists');
$posSql = file_get_contents($posFile);
t4assert(str_contains($posSql, 'ec_pos_terminals'), 'Has terminals table');
t4assert(str_contains($posSql, 'ec_pos_cash_drawers'), 'Has cash drawers table');
t4assert(str_contains($posSql, 'ec_pos_payments'), 'Has POS payments table');
t4assert(str_contains($posSql, 'terminal_type'), 'Terminals have type (register/kiosk/mobile/tablet)');
t4assert(str_contains($posSql, 'hardware_config'), 'Terminals have hardware_config JSON');
t4assert(str_contains($posSql, 'split-tender') || str_contains($posSql, 'payment_type'), 'POS payments support multiple types');

// module.json updated
t4assert(in_array('ec_pos_terminals', $modJson['owns_tables'] ?? []), 'module.json owns ec_pos_terminals');
t4assert(in_array('ec_pos_cash_drawers', $modJson['owns_tables'] ?? []), 'module.json owns ec_pos_cash_drawers');
t4assert(in_array('ec_pos_payments', $modJson['owns_tables'] ?? []), 'module.json owns ec_pos_payments');

echo "\n";

// -----------------------------------------------------------------
// 9. DiSyL Fuzz Test Reference (4.6)
// -----------------------------------------------------------------
echo "=== 9. DiSyL Fuzz Test (4.6) ===\n";

$fuzzFile = __DIR__ . '/disyl_security_fuzz_test.php';
t4assert(file_exists($fuzzFile), 'Fuzz test file exists');
$fuzzContent = file_get_contents($fuzzFile);
t4assert(str_contains($fuzzContent, 'XSS payloads'), 'Fuzz covers XSS payloads');
t4assert(str_contains($fuzzContent, 'Expression injection'), 'Fuzz covers expression injection');
t4assert(str_contains($fuzzContent, 'Control structure abuse'), 'Fuzz covers control structure abuse');
t4assert(str_contains($fuzzContent, 'Unicode'), 'Fuzz covers unicode attacks');
t4assert(str_contains($fuzzContent, 'DoS'), 'Fuzz covers DoS resilience');
t4assert(str_contains($fuzzContent, 'Filter injection'), 'Fuzz covers filter injection');
t4assert(str_contains($fuzzContent, 'Prototype'), 'Fuzz covers prototype pollution');

echo "\n";

// -----------------------------------------------------------------
// 10. Migration + Service File Presence
// -----------------------------------------------------------------
echo "=== 10. File Presence ===\n";

t4assert(file_exists(__DIR__ . '/../kernel/Services/OpenApiGenerator.php'), 'OpenApiGenerator.php exists');
t4assert(file_exists(__DIR__ . '/../kernel/Services/TenantProvisioner.php'), 'TenantProvisioner.php exists');
t4assert(file_exists(__DIR__ . '/../kernel/Services/LocaleResolver.php'), 'LocaleResolver.php exists');
t4assert(file_exists(__DIR__ . '/../migrations/008_kernel_i18n_foundation.sql'), 'i18n migration exists');
t4assert(file_exists(__DIR__ . '/../modules/ecommerce/database/migrations/040_ec_marketplace_foundation.sql'), 'Marketplace migration exists');
t4assert(file_exists(__DIR__ . '/../modules/ecommerce/database/migrations/041_ec_pos_expansion.sql'), 'POS expansion migration exists');

// ikabud CLI commands
t4assert(str_contains($ikabudContent, "openapi:generate"), 'ikabud has openapi:generate command');
t4assert(str_contains($ikabudContent, "OpenApiGenerator"), 'ikabud uses OpenApiGenerator class');

echo "\n";

// -----------------------------------------------------------------
echo "==================================================\n";
echo "Tier 4 Feature Completeness Tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);

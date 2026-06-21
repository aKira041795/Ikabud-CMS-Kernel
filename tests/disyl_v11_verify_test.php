<?php
declare(strict_types=1);

/**
 * Quick verification test for DiSyL v11 Intermediate Roadmap implementations.
 *
 * Tests TemplateEngine + Grammar + CSRF helpers directly.
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$basePath = dirname(__DIR__);

// Minimal bootstrap — just the autoloader + constants + Ikabud autoloader
require_once $basePath . '/vendor/autoload.php';
require_once $basePath . '/src/helpers/security.php';

// Define minimal constants (normally in bootstrap.php)
define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');
define('STORAGE_PATH', $basePath . '/storage');

// Register Ikabud\Kernel autoloader
spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Grammar;

$pass = 0;
$fail = 0;

function vt(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// ── 1.1 Script/style passthrough ──

$e = new TemplateEngine('/tmp', '/tmp/cache');
$e->enableStrictMode(true);
$out = $e->renderString('<script>if (x > 0) { console.log("hi"); }</script>', []);
vt('script body preserved', str_contains($out, 'console.log("hi")'));

$out = $e->renderString('<style>.foo { color: red; }</style>', []);
vt('style body preserved', str_contains($out, 'color: red'));

$e2 = new TemplateEngine('/tmp', '/tmp/cache');
$e2->enableStrictMode(true);
$e2->renderString('<script>var obj = {a: 1, b: 2};</script>', []);
vt('JS obj literal no strict errors', empty($e2->getErrors()), 'got ' . count($e2->getErrors()));

$e3 = new TemplateEngine('/tmp', '/tmp/cache');
$e3->enableStrictMode(true);
$out = $e3->renderString('<h1>{greeting}</h1><script>var x = {value: 42};</script>', ['greeting' => 'Hello']);
vt('vars outside script resolve', str_contains($out, 'Hello'));
vt('JS curlies in script preserved', str_contains($out, '{value: 42}'));

// ── 1.2 JWT-derived CSRF token ──

$testVal = 'test-jwt-' . bin2hex(random_bytes(8));
// Config not available in test context — function falls back to 'change-me-in-env'
$expected = hash_hmac('sha256', 'csrf|' . hash('sha256', $testVal), 'change-me-in-env');
$_COOKIE['attendance_wage_token'] = $testVal;
vt('csrfTokenFromJwt correct hash', hash_equals($expected, csrfTokenFromJwt('attendance_wage_token')));
vt('csrfTokenFromJwt hides raw JWT', csrfTokenFromJwt('attendance_wage_token') !== $testVal);
vt('csrfTokenFromJwt not same as old derivation', csrfTokenFromJwt('attendance_wage_token') !== hash_hmac('sha256', $testVal, 'csrf'));
unset($_COOKIE['attendance_wage_token']);
echo "  (csrfTokenFromJwt fallback requires app() — tested via attendance_wage_smoke_test)\n";

// ── 2.2 {ikb_component} Alpine bridge ──
$e8 = new TemplateEngine('/tmp', '/tmp/cache');
$e8->enableStrictMode(true);
$employee = ['name' => 'Noah Omamalin', 'position' => 'Baker'];
$out = $e8->renderString('{ikb_component name="employee-profile" data="employee"}{name} — {position}{/ikb_component}', ['employee' => $employee]);
vt('ikb_component renders', str_contains($out, 'ikbComponent('), 'got: ' . substr($out, 0, 120));
vt('ikb_component includes x-data', str_contains($out, 'x-data='));
vt('ikb_component includes children', str_contains($out, 'Noah Omamalin'));
vt('ikb_component includes position', str_contains($out, 'Baker'));
vt('ikb_component has data-ikb-component attr', str_contains($out, 'data-ikb-component="employee-profile"'));

$e9 = new TemplateEngine('/tmp', '/tmp/cache');
$e9->enableStrictMode(true);
$out2 = $e9->renderString('{ikb_component name="empty" data="noData"}empty{/ikb_component}', []);
vt('ikb_component with missing data renders', str_contains($out2, 'ikbComponent('), 'out: ' . substr($out2, 0, 100));

$e10 = new TemplateEngine('/tmp', '/tmp/cache');
$e10->enableStrictMode(true);
$nested = ['user' => ['name' => 'Alice', 'role' => 'admin']];
$out3 = $e10->renderString('{ikb_component name="nested" data="user"}{name}{/ikb_component}', ['user' => $nested['user']]);
vt('ikb_component data attr resolved', str_contains($out3, '&quot;name&quot;:&quot;Alice&quot;'), 'out: ' . substr($out3, 0, 180));

// ── 2.3 DiSyL entity view configs ──

$e11 = new TemplateEngine('/tmp', '/tmp/cache');
$e11->enableStrictMode(true);
// Render a view config — should produce no output but register the view
$viewConfig = '{ikb_entity_view name="test_entity" view="table"}
    {field name="first_name" type="string" renderer="text"}
    {field name="last_name"  type="string" renderer="text"}
    {action name="view" url="/test/{id}" method="GET" label="View"}
    {action name="edit" url="/test/{id}" method="POST" label="Edit" confirm="Edit this?"}
{/ikb_entity_view}';
$output = $e11->renderString($viewConfig, []);
vt('ikb_entity_view produces no output', $output === '', 'got: ' . substr($output, 0, 100));

// Verify the view was registered with EntityViewResolver
$resolverClass = 'Ikabud\\Kernel\\EntityContext\\EntityViewResolver';
if (class_exists($resolverClass)) {
    $resolver = $resolverClass::getInstance();
    $contract = $resolver->viewContract('test_entity', 'table');
    vt('entity view contract registered', is_array($contract), 'got: ' . gettype($contract));
    vt('entity view has fields', isset($contract['fields']) && is_array($contract['fields']));
    vt('entity view fields count', count($contract['fields'] ?? []) === 2, 'got: ' . (count($contract['fields'] ?? [])));
    vt('entity view has actions', !empty($contract['actions']), 'got: ' . implode(',', $contract['actions'] ?? ['none']));
vt('entity view has action_urls', isset($contract['action_urls']['view']) && str_contains($contract['action_urls']['view'], '{id}'));
vt('entity view has action_labels', isset($contract['action_labels']['edit']) && $contract['action_labels']['edit'] === 'Edit');
} else {
    echo "  (EntityViewResolver not available — skipping contract assertions)\n";
}

// Test the static loader
$viewsDir = BASE_PATH . '/modules/attendance-wage/helpers/views';
if (is_dir($viewsDir)) {
    $count = TemplateEngine::loadViewConfigs($viewsDir);
    vt('loadViewConfigs loads files', $count > 0, 'loaded: ' . $count);
    vt('views dir has employee_profile', is_file($viewsDir . '/employee_profile.disyl'));
} else {
    echo "  (views dir not found — skipping loader test)\n";
}

// ── 3.2 Compiled component manifest ──

// Clear any previous manifests
\Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::clear();
\Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::setStorageDir('/tmp/disyl-manifests');

$e14 = new TemplateEngine('/tmp', '/tmp/cache');
$e14->enableStrictMode(true);
$e14->renderString('{@var string $name}Hello {name}!', ['name' => 'World']);
// Manually trigger manifest (normally done via compile with currentTemplatePath)
$manifest = \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::build(
    'test_template',
    '<div>Hello World</div>',
    ['name' => 'World', 'greeting' => 'Hi']
);
vt('manifest is array', is_array($manifest));
vt('manifest has template key', isset($manifest['template']) && $manifest['template'] === 'test_template');
vt('manifest has variables.used', isset($manifest['variables']['used']) && in_array('name', $manifest['variables']['used']));
vt('manifest has components', isset($manifest['components']));
vt('manifest has source_hash', isset($manifest['source_hash']));
vt('manifest has bridges', isset($manifest['bridges']));
vt('manifest has assets', isset($manifest['assets']['scripts'], $manifest['assets']['styles']));
vt('manifest has dependencies', array_key_exists('dependencies', $manifest) && array_key_exists('extends', $manifest['dependencies']) && array_key_exists('includes', $manifest['dependencies']));
vt('manifest has bytes', isset($manifest['bytes']) && $manifest['bytes'] > 0);
vt('manifest has compiled_at', isset($manifest['compiled_at']));

// Test manifest with component references
$e15 = new TemplateEngine('/tmp', '/tmp/cache');
$e15->enableStrictMode(true);
$e15->renderString('{ikb_component name="test" data="items"}{name}{/ikb_component}', ['items' => ['name' => 'test']]);
$manifest2 = \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::build(
    'component_template',
    '<div data-ikb-component="test" x-data="ikbComponent({})">test</div>',
    ['items' => []]
);
vt('manifest detects component', in_array('test', $manifest2['components'] ?? []));

// Test find by variable
$found = \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::findByVariable('name');
vt('findByVariable works', count($found) >= 1);

// Test find by component
$found2 = \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::findByComponent('test');
vt('findByComponent works', count($found2) >= 1);

// Test all() returns manifests
$all = \Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::all();
vt('all() returns manifests', count($all) >= 2);

// Clean up
\Ikabud\Kernel\DiSyL\Compiler\TemplateManifest::clear();

// ── 3.1 State manager ({state} tag) ──

$e12 = new TemplateEngine('/tmp', '/tmp/cache');
$e12->enableStrictMode(true);
$stateConfig = '{state name="kiosk" class="kiosk-wrapper"}
    {variable name="step" type="int" default="0"}
    {variable name="searchQuery" type="string" default=""}
    {variable name="isOpen" type="bool" default="false"}
    <div>
        <span x-text="step"></span>
        <input x-model="searchQuery">
    </div>
{/state}';
$stateOut = $e12->renderString($stateConfig, []);
vt('state renders with x-data', str_contains($stateOut, 'x-data='), 'out: ' . substr($stateOut, 0, 100));
vt('state has data-state attr', str_contains($stateOut, 'data-state="kiosk"'));
vt('state includes step default', str_contains($stateOut, '&quot;step&quot;:0'));
vt('state includes searchQuery default', str_contains($stateOut, '&quot;searchQuery&quot;:&quot;&quot;'));
vt('state includes bool default', str_contains($stateOut, '&quot;isOpen&quot;:false'));
vt('state includes children', str_contains($stateOut, 'x-text="step"'));
vt('state wrapper class included', str_contains($stateOut, 'kiosk-wrapper'));
vt('state uses ikbComponent', str_contains($stateOut, 'ikbComponent('));
vt('state strips variable tags', !str_contains($stateOut, '{variable'));

// Test state without variables (just container)
$e13 = new TemplateEngine('/tmp', '/tmp/cache');
$e13->enableStrictMode(true);
$simpleState = '{state name="app"}{/state}';
$simpleOut = $e13->renderString($simpleState, []);
vt('state empty renders', str_contains($simpleOut, 'data-state="app"'));

// ikb-components.js asset exists
vt('ikb-components.js exists', is_file(BASE_PATH . '/public/assets/js/ikb-components.js'));
$jsContent = file_get_contents(BASE_PATH . '/public/assets/js/ikb-components.js');
vt('ikb-components.js defines ikbComponent', str_contains($jsContent, 'window.ikbComponent'));
vt('ikb-components.js has init lifecycle', str_contains($jsContent, 'init()'));
vt('ikb-components.js has dispatch', str_contains($jsContent, 'dispatch('));
vt('ikb-components.js has submit', str_contains($jsContent, 'async submit'));

// ── 2.1 {@var} declarations ──

$e4 = new TemplateEngine('/tmp', '/tmp/cache');
$e4->enableStrictMode(true);
$e4->renderString('{@var ?string $title}{title}', []);
vt('{@var ?string} null no warning', empty($e4->getErrors()), 'got ' . count($e4->getErrors()));

$e5 = new TemplateEngine('/tmp', '/tmp/cache');
$e5->enableStrictMode(true);
$out = $e5->renderString('{@var string $name}Hello {name}!', ['name' => 'Alice']);
vt('{@var} with value outputs', str_contains($out, 'Hello Alice!'), 'got: ' . $out);
vt('{@var} with value no errors', empty($e5->getErrors()), 'got ' . count($e5->getErrors()));

$e6 = new TemplateEngine('/tmp', '/tmp/cache');
$e6->enableStrictMode(true);
$e6->renderString('{undeclared_var}', []);
vt('undeclared var still warns', count($e6->getErrors()) >= 1, 'got ' . count($e6->getErrors()));

$e7 = new TemplateEngine('/tmp', '/tmp/cache');
$e7->enableStrictMode(true);
$out = $e7->renderString('{@var string $a}{@var int $b}{a} {b}', ['a' => 'x', 'b' => 42]);
vt('multiple {@var} works', str_contains($out, 'x 42'));
vt('multiple {@var} no errors', empty($e7->getErrors()), 'got ' . count($e7->getErrors()));

vt('Grammar::KEYWORD_VAR', Grammar::KEYWORD_VAR === '@var');
vt('Grammar var valid type', Grammar::validateVarDeclaration('string', 'name'));
vt('Grammar var nullable', Grammar::validateVarDeclaration('?int', 'count'));
vt('Grammar var generic', Grammar::validateVarDeclaration('array<string,mixed>', 'data'));
vt('Grammar var invalid name', !Grammar::validateVarDeclaration('string', '123bad'));

// ── Summary ──
$total = $pass + $fail;
echo str_repeat('=', 50) . "\n";
echo "Result: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

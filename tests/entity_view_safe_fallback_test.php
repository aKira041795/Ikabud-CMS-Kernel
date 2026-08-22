<?php
declare(strict_types=1);

/**
 * P0 safe-fallback enforcement test (ARK audit, 2026-08-22).
 *
 * Verifies that wildcard '*' entity rendering is fail-closed:
 *   - '*' never renders arbitrary row keys (tenant_id, cost, notes, tokens, provider
 *     metadata) — only the centrally governed SAFE_FALLBACK_FIELDS allowlist.
 *   - Explicit visible_fields is presence-sensitive: [] renders nothing; a declared
 *     list is honored (intersected with data).
 *   - Detail rendering applies the same safe-fallback gate.
 *
 * Unit-style: no bootstrap; instantiates DefaultEntityRenderer directly.
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');
define('STORAGE_PATH', $basePath . '/storage');

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "── EntityView Safe-Fallback Test (P0) ──\n\n";

$renderer = new DefaultEntityRenderer();

$rows = [[
    'id' => 11,
    'title' => 'Public Post',
    'name' => 'Alpha',
    'status' => 'published',
    'price' => '9.99',
    'tenant_id' => 4242,
    'cost' => '1.00',
    'internal_notes' => 'TOPSECRETNOTE',
    'secret_token' => 'abc123token',
    'provider_meta' => '{"internal":true}',
]];

$baseAttrs = ['source' => 'test_entity.all', 'view' => 'compact'];

// 1. Wildcard '*' renders ONLY the centrally governed safe fields.
//    Table view exposes all safe fields present in the data.
$html = $renderer->renderList($rows, ['fields' => '*'], ['source' => 'test_entity.all', 'view' => 'table'], []);
t("table '*' renders safe column 'Title'", str_contains($html, 'Title'));
t("table '*' renders safe column 'Status'", str_contains($html, 'Status'));
t("table '*' renders safe column 'Price'", str_contains($html, 'Price'));
t("table '*' renders safe value 'Public Post'", str_contains($html, 'Public Post'));
t("table '*' renders safe price value '9.99'", str_contains($html, '9.99'));
t("table '*' does NOT render 'tenant_id'", !str_contains($html, 'tenant_id'));
t("table '*' does NOT render internal note value", !str_contains($html, 'TOPSECRETNOTE'));
t("table '*' does NOT render secret token", !str_contains($html, 'abc123token'));
t("table '*' does NOT render cost value '1.00'", !str_contains($html, '1.00'));

// Compact '*' renders the primary id + title but still hides internals.
$htmlCompact = $renderer->renderList($rows, ['fields' => '*'], $baseAttrs, []);
t("compact '*' renders title value", str_contains($htmlCompact, 'Public Post'));
t("compact '*' does NOT render internal note", !str_contains($htmlCompact, 'TOPSECRETNOTE'));

// 2. Explicit visible_fields: [] renders no fields.
$htmlEmpty = $renderer->renderList($rows, ['fields' => '*', 'visible_fields' => []], $baseAttrs, []);
t("explicit visible_fields [] renders no title value", !str_contains($htmlEmpty, 'Public Post'));

// 3. Explicit visible_fields list is honored (intersected with data).
$htmlList = $renderer->renderList(
    $rows,
    ['fields' => '*', 'visible_fields' => ['title', 'tenant_id']],
    $baseAttrs,
    []
);
t("explicit visible_fields ['title','tenant_id'] renders title", str_contains($htmlList, 'Public Post'));
t("explicit visible_fields renders declared 'tenant_id' value", str_contains($htmlList, '4242'));

// 4. Detail '*' renders only safe fields.
$detail = $renderer->renderDetail($rows[0], ['fields' => '*'], [], []);
t("detail '*' renders safe field 'Public Post'", str_contains($detail, 'Public Post'));
t("detail '*' does NOT render internal note", !str_contains($detail, 'TOPSECRETNOTE'));
t("detail '*' does NOT render tenant_id", !str_contains($detail, 'tenant_id'));

// 5. Detail explicit visible_fields honored.
$detailList = $renderer->renderDetail($rows[0], ['fields' => '*', 'visible_fields' => ['title']], [], []);
t("detail visible_fields ['title'] renders title", str_contains($detailList, 'Public Post'));
t("detail visible_fields ['title'] hides status value", !str_contains($detailList, 'published'));

// 6. Safe-fallback allowlist is centrally governed.
t(
    "SAFE_FALLBACK_FIELDS constant defined",
    defined('Ikabud\\Kernel\\EntityContext\\DefaultEntityRenderer::SAFE_FALLBACK_FIELDS')
);

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

exit($fail > 0 ? 1 : 0);

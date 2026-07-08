<?php
declare(strict_types=1);

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

echo "── DefaultEntityRenderer POST Row Action Test ──\n\n";

$renderer = new DefaultEntityRenderer();

$rows = [
    [
        'id' => 7,
        'name' => 'Alpha',
        'status' => 'draft',
        'quantity' => 3,
        'meta' => ['skip' => true],
    ],
    [
        'id' => 8,
        'name' => 'Beta',
        'status' => 'published',
        'quantity' => 5,
        'meta' => ['skip' => true],
    ],
];

$view = [
    'fields' => ['name', 'status'],
    'view' => 'table',
    'actions' => ['archive'],
    'action_methods' => ['archive' => 'post'],
    'action_labels' => ['archive' => 'Archive Row'],
    'action_confirm' => ['archive' => 'Archive this row?'],
    'renderers' => [
        'name' => 'string',
        'status' => 'string',
    ],
];

$html = $renderer->renderList($rows, $view, ['source' => 'test_entity.all', 'view' => 'table']);

t('renderList returns HTML', $html !== '');
t('renders table view', str_contains($html, '<table class="w-full text-sm">'));
t('renders POST form', str_contains($html, '<form method="post"'));
t('renders one POST form per row', substr_count($html, '<form method="post"') === 2, 'count=' . substr_count($html, '<form method="post"'));
t('renders hidden id input for first row', str_contains($html, '<input type="hidden" name="id" value="7">'));
t('renders hidden id input for second row', str_contains($html, '<input type="hidden" name="id" value="8">'));
t('renders hidden scalar row input: name', str_contains($html, '<input type="hidden" name="name" value="Alpha">'));
t('renders hidden scalar row input: status', str_contains($html, '<input type="hidden" name="status" value="draft">'));
t('renders hidden scalar row input: quantity', str_contains($html, '<input type="hidden" name="quantity" value="3">'));
t('renders hidden scalar row inputs for second row context', str_contains($html, '<input type="hidden" name="name" value="Beta">') && str_contains($html, '<input type="hidden" name="quantity" value="5">'));
t('does not render non-scalar row data as hidden input', !str_contains($html, 'name="meta"'));
t('renders submit button label', str_contains($html, '<button type="submit"') && str_contains($html, 'Archive Row</button>'));
t('renders POST confirmation handler', str_contains($html, 'onsubmit="return confirm(') && str_contains($html, 'Archive this row?'));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
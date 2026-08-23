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

use Ikabud\Kernel\EntityContext\EntityViewResolver;

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

echo "── Module-Owned Products Entity View Test ──\n\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

$genericProducts = $resolver->viewContract('products', 'compact');
echo 'Evidence A provider=' . ($genericProducts['provider'] ?? '') . ' fields=' . json_encode($genericProducts['fields'] ?? null) . ' actions=' . json_encode($genericProducts['actions'] ?? null) . "\n";
t('kernel neutrality: products compact fields are generic *', ($genericProducts['fields'] ?? null) === '*', json_encode($genericProducts));
t('kernel neutrality: provider is kernel.generic', ($genericProducts['provider'] ?? '') === 'kernel.generic', json_encode($genericProducts));
t('kernel neutrality: no add_to_cart leak', !in_array('add_to_cart', $genericProducts['actions'] ?? [], true), json_encode($genericProducts['actions'] ?? []));
t('kernel neutrality: no business field list leak', ($genericProducts['fields'] ?? null) !== ['id', 'name', 'price', 'image']);

$resolver->registerView('products', 'default', [
    'fields' => ['id', 'name', 'price', 'image'],
    'actions' => ['view', 'add_to_cart'],
    'limit' => 20,
    'empty_state' => 'No products found.',
], 'ecommerce');

$ownedProducts = $resolver->viewContract('products', 'compact');
echo 'Evidence B provider=' . ($ownedProducts['provider'] ?? '') . ' fields=' . json_encode($ownedProducts['fields'] ?? null) . ' actions=' . json_encode($ownedProducts['actions'] ?? null) . "\n";
t('owner enabled: compact resolves module-owned business fields', ($ownedProducts['fields'] ?? null) === ['id', 'name', 'price', 'image'], json_encode($ownedProducts));
t('owner enabled: compact resolves add_to_cart action', in_array('add_to_cart', $ownedProducts['actions'] ?? [], true), json_encode($ownedProducts['actions'] ?? []));
t('owner enabled: provider is ecommerce', ($ownedProducts['provider'] ?? '') === 'ecommerce', json_encode($ownedProducts));
t('owner enabled: limit preserved', (int)($ownedProducts['limit'] ?? 0) === 20, json_encode($ownedProducts));
t('owner enabled: empty_state preserved', ($ownedProducts['empty_state'] ?? '') === 'No products found.', json_encode($ownedProducts));

$resolver->reset();
$genericAgain = $resolver->viewContract('products', 'compact');
echo 'Evidence C provider=' . ($genericAgain['provider'] ?? '') . ' fields=' . json_encode($genericAgain['fields'] ?? null) . ' actions=' . json_encode($genericAgain['actions'] ?? null) . "\n";
t('owner absent again: products compact fields are generic *', ($genericAgain['fields'] ?? null) === '*', json_encode($genericAgain));
t('owner absent again: provider is kernel.generic', ($genericAgain['provider'] ?? '') === 'kernel.generic', json_encode($genericAgain));
t('owner absent again: no add_to_cart leak', !in_array('add_to_cart', $genericAgain['actions'] ?? [], true), json_encode($genericAgain['actions'] ?? []));

$unknown = $resolver->viewContract('unknown_module.entity', 'card');
t('unknown entity behavior unchanged: fields=*', ($unknown['fields'] ?? null) === '*', json_encode($unknown));
t('unknown entity behavior unchanged: provider=kernel.generic', ($unknown['provider'] ?? '') === 'kernel.generic', json_encode($unknown));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

exit($fail > 0 ? 1 : 0);

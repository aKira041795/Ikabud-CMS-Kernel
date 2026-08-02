<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
require_once $basePath . '/src/helpers/security.php';
define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');
define('STORAGE_PATH', $basePath . '/storage');
spl_autoload_register(function($c) {
    $p = 'Ikabud\\Kernel\\';
    if (strncmp($c, $p, strlen($p)) !== 0) return;
    $f = KERNEL_PATH . '/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
    if (file_exists($f)) require_once $f;
});
use Ikabud\Kernel\DiSyL\TemplateEngine;
$engine = new TemplateEngine('/tmp', STORAGE_PATH . '/cache');
$engine->enableStrictMode(false);
$template = file_get_contents(BASE_PATH . '/templates/modules/guidance/pages/login.disyl');
$output = $engine->renderString($template, [
    'app_name' => 'Guidance',
    'base_url' => '/guidance',
    'cookie_name' => 'guidance_token',
    'csrf_token' => 'test',
    'page_title' => 'Login',
]);
if (strpos($output, '{@var') !== false) {
    echo "FAILED: {@var} leaks into output\n";
    echo substr($output, 0, 200) . "\n";
    exit(1);
} else {
    echo "OK: No {@var} in output\n";
    exit(0);
}

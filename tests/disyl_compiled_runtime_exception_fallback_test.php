<?php
/**
 * Compiled renders must fall back on ordinary runtime failures, but still
 * abort on the hard output-size limit.
 *
 * Why this guard exists: TemplateEngine catches RuntimeException and rethrows
 * EVERY one with the comment "re-throw size limit errors". The exception's own
 * docblock says only TemplateOutputTooLargeException may abort; an unwritable
 * compiled cache, a read-only release directory or a full disk must fall back
 * to the interpreted pipeline so a caching problem cannot turn a renderable
 * page into a 500.
 *
 * A third assertion proves the hard limit still wins: a 6 MB render must throw
 * TemplateOutputTooLargeException rather than falling back.
 *
 * Run: php tests/disyl_compiled_runtime_exception_fallback_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\Compiler\CompiledTemplate;
use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;
use Ikabud\Kernel\DiSyL\Exceptions\TemplateOutputTooLargeException;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? rrmdir($child) : @unlink($child);
    }
    @rmdir($path);
}

$tmp = sys_get_temp_dir() . '/disyl-runtime-fallback-' . getmypid() . '-' . bin2hex(random_bytes(4));
$templates = $tmp . '/templates';
$cache = $tmp . '/cache';
@mkdir($templates, 0755, true);
@mkdir($cache, 0755, true);

file_put_contents($templates . '/hello.disyl', 'Hello, {name}!');
file_put_contents($templates . '/large.disyl', '{big|raw}');
file_put_contents($templates . '/unbounded.disyl', '{while 1}{/while}');

try {
    $engine = new TemplateEngine($templates, $cache, false);
    $engine->enableCompiledMode(true);

    $throwing = new class($cache . '/compiled') extends TemplateCache {
        public function get(string $templatePath): CompiledTemplate
        {
            throw new RuntimeException('simulated cache write failure');
        }
    };

    $property = new ReflectionProperty(TemplateEngine::class, 'compiledCache');
    $property->setValue($engine, $throwing);

    $caught = null;
    $html = '';
    try {
        $html = $engine->render('hello', ['name' => 'world']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    t('ordinary compiled RuntimeException falls back to interpreted',
        $caught === null && $html === 'Hello, world!',
        $caught ? get_class($caught) . ': ' . $caught->getMessage() : $html);
    t('fallback output is rendered exactly once', substr_count($html, 'Hello') === 1, $html);
    t('fallback reason is visible in engine errors',
        str_contains(implode("\n", $engine->getErrors()), 'Compiled render failed'),
        implode(' | ', $engine->getErrors()));

    $largeEngine = new TemplateEngine($templates, $cache, false);
    $largeEngine->enableCompiledMode(true);
    $caught = null;
    try {
        $largeEngine->render('large', ['big' => str_repeat('x', 6 * 1024 * 1024)]);
    } catch (Throwable $e) {
        $caught = $e;
    }
    t('oversized output still aborts with TemplateOutputTooLargeException',
        $caught instanceof TemplateOutputTooLargeException,
        $caught ? get_class($caught) : 'no exception');

    $loopEngine = new TemplateEngine($templates, $cache, false);
    $loopEngine->enableCompiledMode(true);
    $caught = null;
    try {
        $loopEngine->render('unbounded', []);
    } catch (Throwable $e) {
        $caught = $e;
    }
    t('loop-iteration limit still aborts instead of falling back',
        $caught instanceof RuntimeException && str_contains(strtolower($caught->getMessage()), 'max iterations'),
        $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'no exception (fallback swallowed the hard limit)');
} finally {
    rrmdir($tmp);
}

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);

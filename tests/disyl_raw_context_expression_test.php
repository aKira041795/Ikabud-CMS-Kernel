<?php
declare(strict_types=1);

/**
 * Regression: JavaScript/CSS brace blocks are literal host-language syntax,
 * not DiSyL expressions. Simple {known} interpolation remains supported.
 */

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = sys_get_temp_dir() . '/disyl-raw-context-expression-templates-' . getmypid();
$cacheRoot = sys_get_temp_dir() . '/disyl-raw-context-expression-cache-' . getmypid();
$logPath = STORAGE_PATH . '/logs/app.log';

@mkdir($templateDir, 0777, true);
@mkdir($cacheRoot, 0777, true);

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

$passed = 0;
$failed = 0;

function checkRawContext(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
        return;
    }

    $failed++;
    echo "  ✗ {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

/** @return array{output:string, fallback:bool, error:string} */
function renderRawContextCase(
    string $templateDir,
    string $cacheRoot,
    string $logPath,
    string $name,
    string $source,
    array $context = []
): array {
    file_put_contents($templateDir . '/' . $name . '.disyl', $source);
    $mark = @filesize($logPath) ?: 0;
    $engine = new TemplateEngine($templateDir, $cacheRoot . '/' . $name, true);
    $engine->enableCompiledMode(true);

    $output = '';
    $error = '';
    try {
        $output = $engine->render($name . '.disyl', $context);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $newLog = '';
    $handle = @fopen($logPath, 'r');
    if ($handle !== false) {
        fseek($handle, $mark);
        $newLog = (string) stream_get_contents($handle);
        fclose($handle);
    }

    return [
        'output' => $output,
        'fallback' => str_contains($newLog, 'disyl.compile.fallback'),
        'error' => $error,
    ];
}

try {
    echo "=== DiSyL raw script/style expression isolation ===\n";

    // Reduced directly from templates/modules/harpp/runners.disyl.
    $css = '<style>.x{flex-direction:column}</style>';
    $cssResult = renderRawContextCase($templateDir, $cacheRoot, $logPath, 'css-property', $css);
    checkRawContext('CSS property is preserved', $cssResult['output'] === $css, json_encode($cssResult));
    checkRawContext('CSS property stays on compiled path', !$cssResult['fallback'] && $cssResult['error'] === '', json_encode($cssResult));

    // Minimal truncation trigger reduced from modules/harpp/runners.disyl:
    // nested CSS whose inner rule begins with an ID selector. `{#x` must not
    // be treated as an unterminated DiSyL hash comment that consumes `TAIL`.
    $nestedCss = '<style>@media(x){#x{a:b}}</style>TAIL';
    $nestedCssResult = renderRawContextCase($templateDir, $cacheRoot, $logPath, 'nested-css-id', $nestedCss);
    checkRawContext('nested CSS ID rule and following document survive', $nestedCssResult['output'] === $nestedCss, json_encode($nestedCssResult));
    checkRawContext('nested CSS ID rule stays on compiled path', !$nestedCssResult['fallback'] && $nestedCssResult['error'] === '', json_encode($nestedCssResult));

    // A real, terminated DiSyL hash comment remains a comment in raw output.
    $rawComment = '<style>.x{a:b}{# remove me #}.y{c:d}</style>TAIL';
    $rawCommentExpected = '<style>.x{a:b}.y{c:d}</style>TAIL';
    $rawCommentResult = renderRawContextCase($templateDir, $cacheRoot, $logPath, 'raw-hash-comment', $rawComment);
    checkRawContext('terminated raw hash comment is still removed', $rawCommentResult['output'] === $rawCommentExpected, json_encode($rawCommentResult));
    checkRawContext('terminated raw hash comment stays on compiled path', !$rawCommentResult['fallback'] && $rawCommentResult['error'] === '', json_encode($rawCommentResult));

    // Reduced from theme-customizer.disyl's Alpine @input handlers.
    $attribute = '<input @input="if(ok){let c=x;value=\'rgba(\'+r+\')\';}">';
    $attributeResult = renderRawContextCase($templateDir, $cacheRoot, $logPath, 'js-attribute', $attribute);
    checkRawContext('JS attribute block is preserved', $attributeResult['output'] === $attribute, json_encode($attributeResult));
    checkRawContext('JS attribute stays on compiled path', !$attributeResult['fallback'] && $attributeResult['error'] === '', json_encode($attributeResult));

    $interpolation = '<script>const value = "{known}";</script><style>.x::after{content:"{known}"}</style>';
    $expectedInterpolation = '<script>const value = "A\\u0027B";</script><style>.x::after{content:"A\\u0027B"}</style>';
    $interpolationResult = renderRawContextCase(
        $templateDir,
        $cacheRoot,
        $logPath,
        'known-interpolation',
        $interpolation,
        ['known' => "A'B"]
    );
    checkRawContext('known script/style interpolation still resolves safely', $interpolationResult['output'] === $expectedInterpolation, json_encode($interpolationResult));
    checkRawContext('interpolation stays on compiled path', !$interpolationResult['fallback'] && $interpolationResult['error'] === '', json_encode($interpolationResult));

    $nullish = '<script>const count = {missing ?? 0};</script>';
    $nullishExpected = '<script>const count = 0;</script>';
    $nullishResult = renderRawContextCase($templateDir, $cacheRoot, $logPath, 'nullish-interpolation', $nullish);
    checkRawContext('raw-context null-coalescing interpolation still resolves', $nullishResult['output'] === $nullishExpected, json_encode($nullishResult));
    checkRawContext('null-coalescing stays on compiled path', !$nullishResult['fallback'] && $nullishResult['error'] === '', json_encode($nullishResult));
} finally {
    foreach ((array) glob($templateDir . '/*') as $path) {
        @unlink($path);
    }
    @rmdir($templateDir);
    if (is_dir($cacheRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($cacheRoot);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

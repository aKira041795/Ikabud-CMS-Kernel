<?php
declare(strict_types=1);

/**
 * Differential corpus: does the fix change RENDERED OUTPUT for any template?
 *
 * Why this exists. The acceptance test for the DiSyL fix is probe-fallback-sweep.php, which reports which
 * PIPELINE each template took. That says nothing about whether the HTML changed. A fix that correctly
 * routes 7 templates onto the compiled path while silently altering what a different template renders
 * would pass the sweep perfectly.
 *
 * The specific risk in this fix: inside a <style>/<script> body the rule is now
 *
 *     /^[a-zA-Z_][\w.]*(?:\s*\|\s*[^}]+)?$/      -> expression, else literal text
 *
 * so only a bare (optionally dotted) identifier, with optional filters, is still interpolated. Anything
 * else - including `{w + 10}`, a `{set}` block, or a nested expression - becomes literal text. If any
 * template relied on an interpolated EXPRESSION inside a style/script body, its output changes. Tests
 * and conformance gates check constructs; only a differential over the real corpus checks the templates.
 *
 * Method: render every .disyl with a fixed context and print `sha256<TAB>template`. Run it on the
 * unmodified tree and on the fixed tree, then diff the two lists. Any line that changes is a template
 * whose output moved, and it must be explained before the fix is accepted.
 *
 *   php .../probe-output-diff.php > /tmp/before.txt
 *   # apply fix
 *   php .../probe-output-diff.php > /tmp/after.txt
 *   diff /tmp/before.txt /tmp/after.txt
 *
 * The context is deliberately minimal and identical for both arms; a template that throws is recorded
 * as THREW rather than skipped, so a change in throwing behaviour also shows up as a diff.
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ikabud-output-diff';

$_ENV['APP_TIMING_LOGS'] = 'false'; // not needed here; keep the log quiet for speed

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
        $files[] = str_replace($templateDir . '/', '', $f->getPathname());
    }
}
sort($files);

$context = ['page_title' => '__output_diff__'];

foreach ($files as $template) {
    $engine = new TemplateEngine($templateDir, $cacheDir, true);
    $engine->enableCompiledMode(true);

    try {
        $html = $engine->render($template, $context);
        $tag = hash('sha256', $html);
    } catch (Throwable $e) {
        $tag = 'THREW:' . substr(sha1($e->getMessage()), 0, 12);
    }

    // Template name first so the diff is readable and sortable.
    echo $template . "\t" . $tag . PHP_EOL;
}

<?php
declare(strict_types=1);

/**
 * Render ONE template and write the HTML to stdout, for differential comparison between two tree states.
 *
 *   php probe-render-one.php modules/cms/admin/menus.disyl > /tmp/x-before.html
 *
 * Read-only with respect to the repo. Used with the fix stashed and applied in turn, so a real before/after
 * HTML diff can be inspected rather than inferred from a hash.
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$template = $argv[1] ?? '';
if ($template === '') {
    fwrite(STDERR, "usage: php probe-render-one.php <template-relative-path>\n");
    exit(2);
}

$templateDir = defined('TEMPLATES_PATH') ? TEMPLATES_PATH : __DIR__ . '/../../../templates';
$_ENV['APP_TIMING_LOGS'] = 'false';

$engine = new TemplateEngine($templateDir, rtrim(sys_get_temp_dir(), '/') . '/ikabud-output-diff', true);
$engine->enableCompiledMode(true);

try {
    echo $engine->render($template, ['page_title' => '__output_diff__']);
} catch (Throwable $e) {
    fwrite(STDERR, 'THREW: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

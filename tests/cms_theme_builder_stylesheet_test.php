<?php
/**
 * Every CMS theme public layout must load the CMS structural stylesheet.
 *
 * Why this guard exists: cms-public.css is the CMS-owned contract for builder
 * node geometry, prose typography, lightbox and UI primitives. ARK and
 * native-default load it; four bundled themes rendered builder content without
 * it, so an authored page looked correct in preview and broken in public.
 *
 * The check requires a REAL <link rel="stylesheet"> before </head>, exactly
 * once. A comment that mentions cms_public_css_url does not satisfy it, and a
 * duplicate link is a failure, not a pass.
 *
 * Run: php tests/cms_theme_builder_stylesheet_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
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

$files = array_merge(
    glob($root . '/storage/cms-themes/*/layouts/public.disyl') ?: [],
    glob($root . '/storage/cms-themes/*/modules/*/layouts/public.disyl') ?: []
);
sort($files);
t('theme public layouts were found', $files !== [], 'no layouts under storage/cms-themes');

foreach ($files as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = (string)file_get_contents($file);
    $headEnd = strpos($source, '</head>');

    if (!preg_match_all('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*cms_public_css_url[^>]*>/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
        t("{$relative} loads the CMS stylesheet", false, 'no real <link rel="stylesheet"> found');
        continue;
    }

    $links = $matches[0];
    t("{$relative} loads the CMS stylesheet exactly once", count($links) === 1,
        count($links) . ' matching link tags');

    $offset = (int)$links[0][1];
    $before = substr($source, 0, $offset);
    $htmlCommentOpen = strrpos($before, '<!--');
    $htmlCommentClose = strrpos($before, '-->');
    $disylCommentOpen = strrpos($before, '{#');
    $disylCommentClose = strrpos($before, '#}');
    $insideHtmlComment = $htmlCommentOpen !== false
        && ($htmlCommentClose === false || $htmlCommentOpen > $htmlCommentClose);
    $insideDisylComment = $disylCommentOpen !== false
        && ($disylCommentClose === false || $disylCommentOpen > $disylCommentClose);

    t("{$relative} link is real markup, not a comment",
        !$insideHtmlComment && !$insideDisylComment);
    t("{$relative} link is inside <head>",
        $headEnd !== false && $offset < $headEnd,
        $headEnd === false ? 'missing </head>' : 'link offset ' . $offset . ', head ends ' . $headEnd);
}

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);

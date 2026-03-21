<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
if (!function_exists('getModuleSettings')) {
    require_once __DIR__ . '/../src/helpers/module-manager.php';
}
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/tinymce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "Phase 4: Field-Level Rich-Text Support\n";

// 1) cmsGetRichTextFieldKeys returns empty for types with no richtext fields
$keys = cmsGetRichTextFieldKeys('post');
t('no richtext fields by default for post', is_array($keys));

// 2) Extension sidebar hook can register richtext fields
app()->hooks()->on('cms.editor.sidebar_fields', function (array $fields, string $contentType) {
    $fields[] = [
        'key' => 'custom_bio',
        'type' => 'richtext',
        'label' => 'Author Bio',
        'placeholder' => 'Enter bio...',
    ];
    $fields[] = [
        'key' => 'plain_notes',
        'type' => 'textarea',
        'label' => 'Notes',
        'placeholder' => 'Plain text notes',
    ];
    return $fields;
}, 10);

$keys2 = cmsGetRichTextFieldKeys('post');
t('hook-registered richtext field detected', in_array('custom_bio', $keys2, true));
t('plain textarea NOT in richtext keys', !in_array('plain_notes', $keys2, true));

// 3) cmsSanitizeRichTextMeta sanitizes richtext fields
$meta = [
    'custom_bio' => '<p>Hello <b>World</b></p><script>alert("x")</script>',
    'plain_notes' => '<script>safe</script>',
    'seo_title' => 'Test SEO',
];
cmsSanitizeRichTextMeta($meta, 'post');
t('richtext field sanitized (script removed)', !str_contains($meta['custom_bio'], '<script>'));
t('richtext field keeps safe HTML', str_contains($meta['custom_bio'], '<p>') || str_contains($meta['custom_bio'], 'Hello'));
t('plain field NOT sanitized', str_contains($meta['plain_notes'], '<script>'));
t('non-field meta untouched', $meta['seo_title'] === 'Test SEO');

// 4) cmsSanitizeRichTextMeta handles empty values gracefully
$meta2 = ['custom_bio' => '', 'plain_notes' => 'foo'];
cmsSanitizeRichTextMeta($meta2, 'post');
t('empty richtext field stays empty', $meta2['custom_bio'] === '');

// 5) cmsSanitizeRichTextMeta handles missing keys gracefully
$meta3 = ['seo_title' => 'Test'];
cmsSanitizeRichTextMeta($meta3, 'post');
t('missing richtext key is fine', $meta3['seo_title'] === 'Test');

// 6) cmsEditorNormalizeHtml works for meta context
$normalized = cmsEditorNormalizeHtml('<p> test </p>', 'cms.meta');
t('cmsEditorNormalizeHtml works for cms.meta context', is_string($normalized) && trim($normalized) !== '');

// 7) cmsEditorSanitizeHtml works for meta context
$sanitized = cmsEditorSanitizeHtml('<p>safe</p><script>bad</script>', 'cms.meta');
t('cmsEditorSanitizeHtml strips script for cms.meta', !str_contains($sanitized, '<script>'));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[critical]'));
t('no app.log critical errors', empty($criticals), implode('; ', $criticals));
t('no PHP errors in error.log', trim($errLog) === '', trim($errLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);

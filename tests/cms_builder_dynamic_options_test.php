<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/builder-preview';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER DYNAMIC OPTIONS ===\n";

$baseNode = [
    'id' => 'dynamic-slideshow-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => [
            [
                'id' => 'slide-1',
                'image' => 'https://example.test/slide-1.jpg',
                'title' => 'Slide One',
                'description' => 'Slide description',
            ],
        ],
        'height' => '320px',
        'customId' => 'hero-slider',
        'customClasses' => 'hero-shell  feature-block',
        'customAttributes' => "data-aos=\"fade-up\"\naria-label=\"Homepage hero\"\nonclick=\"alert(1)\"",
        'visibility' => 'desktop',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
];

$rendered = cmsBuilderRenderNode($baseNode, []);
t('dynamic attributes render custom id and classes', str_contains($rendered, 'id="hero-slider"') && str_contains($rendered, 'hero-shell feature-block'), $rendered);
t('dynamic attributes render safe custom attributes', str_contains($rendered, 'data-aos="fade-up"') && str_contains($rendered, 'aria-label="Homepage hero"'), $rendered);
t('dynamic attributes strip unsafe event handlers', !str_contains($rendered, 'onclick='), $rendered);
t('visibility maps desktop option to runtime class', str_contains($rendered, 'cms-builder-visible--desktop-only'), $rendered);

$desktopTabletHtml = cmsBuilderRenderNode([
    'id' => 'desktop-tablet-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'visibility' => 'desktop-tablet',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);
t('visibility maps desktop-tablet option to runtime class', str_contains($desktopTabletHtml, 'cms-builder-visible--desktop-tablet'), $desktopTabletHtml);

$hiddenHtml = cmsBuilderRenderNode([
    'id' => 'hidden-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'visibility' => 'hidden',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);
t('hidden visibility skips rendering', $hiddenHtml === '', $hiddenHtml);

$loggedOutHtml = cmsBuilderRenderNode([
    'id' => 'logged-out-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'conditionalField' => 'user_logged_out',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);
t('logged-out conditional renders without an authenticated user', str_contains($loggedOutHtml, 'cms-builder-node--slideshow'), $loggedOutHtml);

$loggedInHtml = cmsBuilderRenderNode([
    'id' => 'logged-in-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'conditionalField' => 'user_logged_in',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);
t('logged-in conditional skips rendering without an authenticated user', $loggedInHtml === '', $loggedInHtml);

$customConditionHtml = cmsBuilderRenderNode([
    'id' => 'custom-condition-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'conditionalField' => 'custom',
        'customConditionField' => 'page.title',
        'conditionOperator' => 'equals',
        'conditionValue' => 'Landing Page',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], ['title' => 'Landing Page']);
t('custom condition can read page context fields', str_contains($customConditionHtml, 'cms-builder-node--slideshow'), $customConditionHtml);

$containsConditionHtml = cmsBuilderRenderNode([
    'id' => 'contains-condition-test',
    'type' => 'slideshow',
    'props' => [
        'slides' => $baseNode['props']['slides'],
        'conditionalField' => 'custom',
        'customConditionField' => 'meta.audience',
        'conditionOperator' => 'contains',
        'conditionValue' => 'vip',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], ['meta' => ['audience' => 'vip members']]);
t('custom condition supports nested context values', str_contains($containsConditionHtml, 'cms-builder-node--slideshow'), $containsConditionHtml);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);
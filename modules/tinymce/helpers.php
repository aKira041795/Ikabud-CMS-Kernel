<?php

declare(strict_types=1);

function tinymceDefaultPlugins(): array
{
    return [
        'advlist',
        'autolink',
        'lists',
        'link',
        'image',
        'charmap',
        'preview',
        'anchor',
        'searchreplace',
        'visualblocks',
        'code',
        'fullscreen',
        'insertdatetime',
        'media',
        'table',
        'wordcount',
    ];
}

function tinymceDefaultToolbar(): string
{
    return 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | code fullscreen';
}

function tinymceNormalizeHtml(string $html): string
{
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    // WordPress imports and TinyMCE round-trips commonly preserve spacer paragraphs
    // like <p>&nbsp;</p> or <p><br></p>; strip those so blank lines do not grow on edit.
    $withoutSpacerParagraphs = preg_replace('~<p>(?:\s|&nbsp;|&#160;|<br\s*/?>)*</p>~iu', '', $html);
    if (is_string($withoutSpacerParagraphs)) {
        $html = $withoutSpacerParagraphs;
    }

    $collapsedBreaks = preg_replace("/\n{3,}/", "\n\n", $html);
    if (is_string($collapsedBreaks)) {
        $html = $collapsedBreaks;
    }

    return trim((string)$html);
}

function tinymceSanitizeHtml(string $html): string
{
    $html = tinymceNormalizeHtml($html);
    return strip_tags($html, '<p><br><strong><b><em><i><u><a><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6><img><figure><figcaption><table><thead><tbody><tr><th><td><hr>');
}

function tinymceAssetsGet(array $payload = []): array
{
    return [
        'ok' => true,
        'data' => [
            'version' => '6',
            'js_urls' => [
                'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js',
            ],
            'css_urls' => [],
        ],
    ];
}

function tinymceConfigGet(array $payload = []): array
{
    $context = trim((string)($payload['context'] ?? ''));
    $readonly = !empty($payload['readonly']);
    $ctx = module('tinymce');

    $config = [
        'selector' => '[data-tinymce-editor]',
        'menubar' => true,
        'branding' => false,
        'height' => $context === 'guidance.session' ? 420 : 520,
        'plugins' => tinymceDefaultPlugins(),
        'toolbar' => tinymceDefaultToolbar(),
        'readonly' => $readonly,
    ];

    if ($context === 'guidance.session') {
        $config['toolbar'] = 'undo redo | bold italic underline | bullist numlist | link | blockquote | code';
    }

    if ($ctx) {
        $ctx->fireEvent('tinymce.editor.loaded', [
            'context' => $context,
            'profile' => trim((string)($payload['profile'] ?? 'default')),
        ]);
    }

    return ['ok' => true, 'data' => $config];
}

function tinymce_cap_assets_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return tinymceAssetsGet(is_array($payload) ? $payload : []);
}

function tinymce_cap_config_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return tinymceConfigGet(is_array($payload) ? $payload : []);
}

function tinymce_cap_html_normalize_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    return [
        'ok' => true,
        'html' => tinymceNormalizeHtml((string)($payload['html'] ?? '')),
    ];
}

function tinymce_cap_html_sanitize_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    return [
        'ok' => true,
        'html' => tinymceSanitizeHtml((string)($payload['html'] ?? '')),
    ];
}

function tinymce_capability_handlers(): array
{
    return [
        'tinymce.assets.get@1' => 'tinymce_cap_assets_get_1',
        'tinymce.config.get@1' => 'tinymce_cap_config_get_1',
        'tinymce.html.normalize@1' => 'tinymce_cap_html_normalize_1',
        'tinymce.html.sanitize@1' => 'tinymce_cap_html_sanitize_1',
    ];
}

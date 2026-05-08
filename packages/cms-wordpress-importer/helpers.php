<?php

declare(strict_types=1);

function wordpressImporterTemplateKey(string $relativePath): string
{
    return 'packages/cms-wordpress-importer/' . ltrim($relativePath, '/');
}

function wordpressImporterPrepareRenderContext(string $relativePath, array $context = []): array
{
    return kernelPrepareRenderContext(wordpressImporterTemplateKey($relativePath), $context);
}

function wordpressImporterNormalizeAdminRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title'           => 'WordPress Import',
        'bridge_available'     => false,
        'bridge_enabled'       => false,
        'bridge_settings_url'  => '',
        'bridge_companion_url' => '',
    ], ['page_title'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('wordpress-importer.admin.import', [
    'template' => wordpressImporterTemplateKey('templates/admin/wordpress-importer.disyl'),
    'priority' => 20,
    'normalize' => 'wordpressImporterNormalizeAdminRenderContext',
    'log_event' => 'wordpress-importer.render_context.contract_mismatch',
]);

app()->hooks()->on('cms.admin.nav_items', static function (array $items): array {
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $items[] = [
        'label' => 'WordPress Import',
        'url' => $baseUrl . '/cms/admin/wordpress-import',
        'icon' => 'W',
        'active_key' => 'wordpress_importer',
    ];
    return $items;
});
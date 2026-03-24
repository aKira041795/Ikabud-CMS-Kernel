<?php

declare(strict_types=1);

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
#!/usr/bin/env php
<?php
/**
 * Test Manifest Loading
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\ModularManifestLoader;

echo "=== Manifest Loading Test ===\n\n";

// Initialize with Joomla
echo "Initializing ModularManifestLoader with 'joomla'...\n";
ModularManifestLoader::init('full', 'joomla');

echo "\nChecking for filters...\n";

// Try to get a filter
$filters = ['esc_html', 'esc_url', 'esc_attr', 'wp_trim_words', 'strip_tags', 'truncate'];

foreach ($filters as $filterName) {
    $filter = ModularManifestLoader::getFilter($filterName);
    if ($filter) {
        echo "✅ {$filterName}: Found\n";
        print_r($filter);
    } else {
        echo "❌ {$filterName}: NOT FOUND\n";
    }
}

echo "\nAll loaded filters:\n";
$allFilters = ModularManifestLoader::getFilters();
echo "Total filters loaded: " . count($allFilters) . "\n";
foreach (array_keys($allFilters) as $name) {
    echo "  - {$name}\n";
}

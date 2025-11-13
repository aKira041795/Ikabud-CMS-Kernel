<?php
require 'vendor/autoload.php';
require 'kernel/DiSyL/Grammar.php';
require 'kernel/DiSyL/ComponentRegistry.php';

use IkabudKernel\Core\DiSyL\Grammar;
use IkabudKernel\Core\DiSyL\ComponentRegistry;

echo "=== Grammar Tests ===" . PHP_EOL;
$grammar = new Grammar();

// Test validation
$schema = ['type' => Grammar::TYPE_STRING, 'enum' => ['hero', 'content', 'footer']];
echo 'Validate "hero": ' . ($grammar->validate('hero', $schema) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'Validate "invalid": ' . (!$grammar->validate('invalid', $schema) ? 'PASS' : 'FAIL') . PHP_EOL;

// Test normalization
$schema = ['type' => Grammar::TYPE_STRING, 'default' => 'default-value'];
echo 'Normalize null: ' . $grammar->normalize(null, $schema) . PHP_EOL;

// Test min/max
$schema = ['type' => Grammar::TYPE_INTEGER, 'min' => 1, 'max' => 10];
echo 'Validate 5 (1-10): ' . ($grammar->validate(5, $schema) ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'Validate 15 (1-10): ' . (!$grammar->validate(15, $schema) ? 'PASS' : 'FAIL') . PHP_EOL;

echo PHP_EOL;
echo "=== Component Registry Tests ===" . PHP_EOL;

// Test core components
$components = ComponentRegistry::all();
echo 'Total components: ' . count($components) . PHP_EOL;

// Test ikb_section
$section = ComponentRegistry::get('ikb_section');
echo 'ikb_section category: ' . $section['category'] . PHP_EOL;
echo 'ikb_section attributes: ' . count($section['attributes']) . PHP_EOL;
echo 'ikb_section type default: ' . $section['attributes']['type']['default'] . PHP_EOL;

// Test ikb_query
$query = ComponentRegistry::get('ikb_query');
echo 'ikb_query category: ' . $query['category'] . PHP_EOL;
echo 'ikb_query limit default: ' . $query['attributes']['limit']['default'] . PHP_EOL;
echo 'ikb_query limit min: ' . $query['attributes']['limit']['min'] . PHP_EOL;
echo 'ikb_query limit max: ' . $query['attributes']['limit']['max'] . PHP_EOL;

// Test categories
$structural = ComponentRegistry::getByCategory(ComponentRegistry::CATEGORY_STRUCTURAL);
echo PHP_EOL . 'Structural components: ' . count($structural) . PHP_EOL;

$data = ComponentRegistry::getByCategory(ComponentRegistry::CATEGORY_DATA);
echo 'Data components: ' . count($data) . PHP_EOL;

$ui = ComponentRegistry::getByCategory(ComponentRegistry::CATEGORY_UI);
echo 'UI components: ' . count($ui) . PHP_EOL;

$control = ComponentRegistry::getByCategory(ComponentRegistry::CATEGORY_CONTROL);
echo 'Control components: ' . count($control) . PHP_EOL;

echo PHP_EOL;
echo "=== All Core Components ===" . PHP_EOL;
foreach ($components as $name => $component) {
    echo sprintf('- %-20s (%s)' . PHP_EOL, $name, $component['category']);
}

echo PHP_EOL;
echo "=== Validation Example ===" . PHP_EOL;
$attrs = ['type' => 'hero', 'title' => 'Welcome', 'padding' => 'large'];
$schemas = ComponentRegistry::getAttributeSchemas('ikb_section');
$errors = $grammar->validateAttributes($attrs, $schemas);
echo 'Validation errors: ' . (empty($errors) ? 'None (PASS)' : implode(', ', $errors)) . PHP_EOL;

$normalized = $grammar->normalizeAttributes($attrs, $schemas);
echo 'Normalized bg: ' . $normalized['bg'] . ' (default applied)' . PHP_EOL;

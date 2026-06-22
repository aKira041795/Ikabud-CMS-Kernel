<?php
declare(strict_types=1);

/**
 * Integration tests: Guidance entity view pipeline.
 *
 * Verifies the full flow: view config registration → contract lookup →
 * entity list rendering.
 *
 * @see tests/guidance_entity_view_test.php (canonical)
 */

require_once dirname(__DIR__, 2) . '/unit/DiSyL/EntityRendering/bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\EntityContext\EntityViewResolver;

echo "── Guidance Cases Entity View ──\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

$viewsDir = BASE_PATH . '/modules/guidance/helpers/views';
test_ok('views dir exists', is_dir($viewsDir));

$count = TemplateEngine::loadViewConfigs($viewsDir);
test_ok('loadViewConfigs > 0', $count > 0, 'loaded: ' . $count);

// Check contracts were registered
$table = $resolver->viewContract('guidance_case', 'table');
test_ok('guidance_case table contract exists', $table !== null);
test_ok('fields defined', !empty($table['fields']));
test_ok('actions defined', !empty($table['actions']));

$compact = $resolver->viewContract('guidance_case', 'compact');
test_ok('guidance_case compact contract exists', $compact !== null);

$detailed = $resolver->viewContract('guidance_case', 'detailed');
test_ok('guidance_case detailed contract exists', $detailed !== null);

// Source parsing
$parsed = $resolver->parseSource('guidance_case.all');
test_ok('parses entity type', $parsed['entity_type'] === 'guidance_case');
test_ok('parses qualifier', $parsed['qualifier'] === 'all');

// Builtin defaults
$fallback = $resolver->viewContract('guidance_case', 'nonexistent_view');
test_ok('fallback to builtin defaults', $fallback !== null);

// Sortable fields
$sortable = $resolver->getSortableFields('guidance_case', 'table');
test_ok('sortable fields registered', is_array($sortable));

// Sort validation
$validated = $resolver->validateSort('guidance_case', 'table', 'student_name', 'asc');
test_ok('validates sort field', $validated['field'] === 'student_name');

$resolver->reset();
test_summary('Guidance Cases Entity View');

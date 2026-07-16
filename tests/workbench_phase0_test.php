<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('workbench-phase0');
$root = dirname(__DIR__);

$h->section('Versioned contracts');
$schemaDir = $root . '/kernel/Workbench/Schemas';
$requiredSchemas = [
    'evidence-observation.v1.schema.json',
    'process-graph.v1.schema.json',
    'issue.v1.schema.json',
    'ai-diagnosis.v1.schema.json',
    'run-manifest.v1.schema.json',
];
foreach ($requiredSchemas as $schemaFile) {
    $decoded = json_decode((string)file_get_contents($schemaDir . '/' . $schemaFile), true);
    $h->test($schemaFile . ' is valid JSON', is_array($decoded) && json_last_error() === JSON_ERROR_NONE);
}

$evidenceSchema = json_decode((string)file_get_contents($schemaDir . '/evidence-observation.v1.schema.json'), true);
$outcomes = $evidenceSchema['properties']['outcome']['enum'] ?? [];
$h->test('outcomes distinguish missing from failure', in_array('failed', $outcomes, true) && in_array('unobserved', $outcomes, true) && in_array('probe_error', $outcomes, true));

$h->section('Golden baseline');
$golden = json_decode((string)file_get_contents(__DIR__ . '/ai/golden/comprehension-cases.v1.json'), true);
$h->test('golden dataset has at least 30 cases', count($golden['cases'] ?? []) >= 30);
$ids = array_column($golden['cases'] ?? [], 'id');
$h->test('golden case IDs are unique', count($ids) === count(array_unique($ids)));
$goldenOutcomes = array_unique(array_column($golden['cases'] ?? [], 'outcome'));
$h->test('golden dataset includes censored outcomes', in_array('unobserved', $goldenOutcomes, true) && in_array('not_applicable', $goldenOutcomes, true) && in_array('probe_error', $goldenOutcomes, true));

$h->section('Credential security');
require_once $root . '/modules/ai/helpers.php';
$sensitiveKeys = aiSensitiveKeyNames();
$providerKeys = ['openai', 'groq', 'gemini', 'mistral', 'cerebras', 'openrouter'];
foreach ($providerKeys as $provider) {
    $h->test($provider . ' key is classified sensitive', in_array($provider . '_api_key', $sensitiveKeys, true));
}

$h->section('Governance');
$policy = (string)file_get_contents($root . '/docs/workbench/ark-workbench-data-governance.md');
$h->test('retention policy is documented', str_contains($policy, 'Raw run artifacts: 30 days'));
$h->test('AI mutation boundary is documented', str_contains($policy, 'cannot execute code'));

$h->done();

<?php

declare(strict_types=1);

function palWorkbenchScenarioDescribe(array $payload): array
{
    $file = __DIR__ . '/domain-contracts.v1.json';
    return [
        'ok' => true,
        'module' => 'project-audit-ledger',
        'capabilities' => ['workbench.scenario.describe@1','workbench.scenario.seed@1','workbench.scenario.verify@1','workbench.scenario.cleanup@1'],
        'domain_contract_version' => '1.0.0',
        'data_classes' => json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
    ];
}

function palWorkbenchScenarioSeed(array $payload): array
{
    $scenario = (array)($payload['scenario'] ?? []);
    $runId = (string)($payload['run_id'] ?? '');
    if (($payload['module'] ?? '') !== 'project-audit-ledger' || !preg_match('/^[a-zA-Z0-9._-]+$/', $runId)) {
        return ['ok' => false, 'error' => 'invalid_scope'];
    }
    $errors = palWorkbenchValidateScenarioData($scenario);
    if ($errors !== []) return ['ok' => false, 'error' => 'domain_contract_failed', 'errors' => $errors];
    $root = dirname(__DIR__, 3) . '/storage/private/workbench/module-scenarios/project-audit-ledger/' . $runId;
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) return ['ok' => false, 'error' => 'sandbox_create_failed'];
    $file = $root . '/seed.json';
    $document = ['module' => 'project-audit-ledger', 'run_id' => $runId, 'scenario_id' => $scenario['scenario_id'] ?? '', 'data' => $scenario['data'] ?? [], 'domain_contract_version' => '1.0.0'];
    file_put_contents($file, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    return ['ok' => true, 'provider' => 'project-audit-ledger', 'namespace' => $runId, 'file' => $file, 'entity_count' => array_sum(array_map(fn($v) => array_is_list((array)$v) ? count($v) : 1, (array)($scenario['data']['entities'] ?? []))), 'fingerprint' => hash_file('sha256', $file), 'prepared_at' => gmdate(DATE_ATOM)];
}

function palWorkbenchScenarioVerify(array $payload): array
{
    $receipt = (array)($payload['receipt'] ?? []);
    $file = (string)($receipt['file'] ?? '');
    $allowed = dirname(__DIR__, 3) . '/storage/private/workbench/module-scenarios/project-audit-ledger/';
    if (!str_starts_with($file, $allowed)) return ['valid' => false, 'drift' => 'invalid_namespace'];
    $actual = is_file($file) ? hash_file('sha256', $file) : null;
    $expected = $receipt['fingerprint'] ?? null;
    return ['valid' => $actual !== null && hash_equals((string)$expected, (string)$actual), 'drift' => $actual === null ? 'seed_missing' : ($actual === $expected ? 'none' : 'seed_changed'), 'expected_fingerprint' => $expected, 'actual_fingerprint' => $actual, 'verified_at' => gmdate(DATE_ATOM)];
}

function palWorkbenchScenarioCleanup(array $payload): array
{
    $receipt = (array)($payload['receipt'] ?? []);
    $file = (string)($receipt['file'] ?? '');
    $allowed = dirname(__DIR__, 3) . '/storage/private/workbench/module-scenarios/project-audit-ledger/';
    if (!str_starts_with($file, $allowed)) return ['clean' => false, 'error' => 'invalid_namespace'];
    $removed = is_file($file) ? unlink($file) : false;
    $dir = dirname($file);
    if (is_dir($dir) && count(scandir($dir) ?: []) === 2) @rmdir($dir);
    return ['clean' => !is_file($file), 'removed' => $removed, 'cleaned_at' => gmdate(DATE_ATOM)];
}

function palWorkbenchValidateScenarioData(array $scenario): array
{
    $errors = [];
    $entities = (array)($scenario['data']['entities'] ?? []);
    foreach (array_keys($entities) as $entityType) {
        if (!in_array($entityType, ['pal_expense','pal_receivable'], true)) $errors[] = "unsupported_entity.{$entityType}";
    }
    foreach ((array)($entities['pal_expense'] ?? []) as $i => $expense) {
        $scope = $expense['expense_scope'] ?? null;
        if (!in_array($scope, ['operating','project'], true)) $errors[] = "pal_expense.{$i}.expense_scope";
        if ($scope === 'operating' && !empty($expense['project_id'])) $errors[] = "pal_expense.{$i}.project_prohibited";
        if ($scope === 'project' && empty($expense['project_id'])) $errors[] = "pal_expense.{$i}.project_required";
    }
    foreach ((array)($entities['pal_receivable'] ?? []) as $i => $receivable) {
        foreach (['sales_id','project_id','client_id','amount','due_date'] as $field) if (empty($receivable[$field])) $errors[] = "pal_receivable.{$i}.{$field}";
    }
    return $errors;
}

<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Scenario;

use RuntimeException;

final class ScenarioContract
{
    private const FRONTS = ['design', 'logic', 'semantics'];
    private const CHECKS = ['route_available', 'text_present', 'control_present', 'field_present', 'workflow_state', 'question'];

    public function validate(array $scenario): array
    {
        $errors = [];
        foreach (['scenario_id', 'module', 'title', 'fronts', 'directions', 'questions', 'data'] as $key) {
            if (!array_key_exists($key, $scenario)) $errors[] = "missing:{$key}";
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', (string)($scenario['scenario_id'] ?? ''))) $errors[] = 'invalid:scenario_id';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', (string)($scenario['module'] ?? ''))) $errors[] = 'invalid:module';
        if (trim((string)($scenario['title'] ?? '')) === '') $errors[] = 'invalid:title';
        foreach ((array)($scenario['fronts'] ?? []) as $front) if (!in_array($front, self::FRONTS, true)) $errors[] = "invalid:front:{$front}";
        foreach ((array)($scenario['directions'] ?? []) as $i => $direction) {
            if (!is_array($direction) || !in_array($direction['check'] ?? '', self::CHECKS, true)) $errors[] = "invalid:direction:{$i}";
            if (trim((string)($direction['statement'] ?? '')) === '') $errors[] = "missing:direction_statement:{$i}";
            if (isset($direction['route']) && !str_starts_with((string)$direction['route'], '/')) $errors[] = "invalid:route:{$i}";
        }
        foreach ((array)($scenario['questions'] ?? []) as $i => $question) if (trim((string)$question) === '') $errors[] = "invalid:question:{$i}";
        if (!is_array($scenario['data'] ?? null)) $errors[] = 'invalid:data';

        // Validate fixture declaration fields if present
        $fixtureData = (array)($scenario['data']['fixture'] ?? $scenario['fixture'] ?? []);
        if ($fixtureData !== []) {
            $fixtureDecl = new ScenarioFixtureDeclaration($fixtureData + ['module' => (string)($scenario['module'] ?? '')]);
            $fixtureValidation = $fixtureDecl->validate();
            foreach ($fixtureValidation['errors'] as $fe) $errors[] = "fixture:{$fe}";
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }
}

final class ScenarioCompiler
{
    public function compile(array $input): array
    {
        $module = strtolower(trim((string)($input['module'] ?? '')));
        $title = trim((string)($input['title'] ?? 'Human-guided investigation'));
        $directions = [];
        foreach ((array)($input['directions'] ?? []) as $i => $direction) {
            if (is_string($direction)) $direction = ['statement' => $direction, 'check' => 'question'];
            $directions[] = [
                'direction_id' => (string)($direction['direction_id'] ?? 'direction-' . ($i + 1)),
                'front' => (string)($direction['front'] ?? 'logic'),
                'statement' => trim((string)($direction['statement'] ?? '')),
                'check' => (string)($direction['check'] ?? 'question'),
                'route' => $direction['route'] ?? null,
                'selector' => $direction['selector'] ?? null,
                'expected' => $direction['expected'] ?? null,
            ];
        }
        $questions = array_values(array_filter(array_map('trim', (array)($input['questions'] ?? []))));
        $fronts = array_values(array_unique((array)($input['fronts'] ?? array_column($directions, 'front'))));
        if ($fronts === []) $fronts = ['design', 'logic', 'semantics'];
        $identity = $module . '|' . $title . '|' . json_encode([$directions, $questions, $input['data'] ?? []]);
        return [
            'schema' => 'ark.scenario.v1',
            'scenario_id' => (string)($input['scenario_id'] ?? 'scenario-' . substr(hash('sha256', $identity), 0, 12)),
            'version' => (int)($input['version'] ?? 1),
            'module' => $module,
            'title' => $title,
            'purpose' => trim((string)($input['purpose'] ?? '')),
            'fronts' => $fronts,
            'directions' => $directions,
            'questions' => $questions,
            'data' => (array)($input['data'] ?? ['entities' => [], 'relationships' => []]),
            'cleanup' => ['required' => true, 'preserve_evidence' => true],
            'created_at' => (string)($input['created_at'] ?? gmdate(DATE_ATOM)),
            'created_by' => (string)($input['created_by'] ?? 'human-tester'),
        ];
    }
}

interface ScenarioDataProvider
{
    public function prepare(array $scenario, string $runId): array;
    public function verify(array $scenario, array $receipt): array;
    public function cleanup(array $scenario, array $receipt): array;
}

/** Kernel-side adapter; module ownership remains behind capability IDs. */
final class CapabilityScenarioDataProvider implements ScenarioDataProvider
{
    /** @param callable(string,array,array):array $caller */
    public function __construct(private $caller, private readonly string $module) {}

    public function describe(): array
    {
        return $this->call('workbench.scenario.describe@1', ['module' => $this->module]);
    }

    public function prepare(array $scenario, string $runId): array
    {
        return $this->call('workbench.scenario.seed@1', ['module' => $this->module, 'scenario' => $scenario, 'run_id' => $runId]);
    }

    public function verify(array $scenario, array $receipt): array
    {
        return $this->call('workbench.scenario.verify@1', ['module' => $this->module, 'scenario' => $scenario, 'receipt' => $receipt]);
    }

    public function cleanup(array $scenario, array $receipt): array
    {
        return $this->call('workbench.scenario.cleanup@1', ['module' => $this->module, 'scenario' => $scenario, 'receipt' => $receipt]);
    }

    private function call(string $capability, array $payload): array
    {
        $result = ($this->caller)($capability, $payload, ['caller_module' => 'kernel.workbench']);
        if (!is_array($result) || ($result['ok'] ?? true) === false) throw new RuntimeException('Scenario capability rejected: ' . $capability);
        return $result;
    }
}

final class JsonSandboxDataProvider implements ScenarioDataProvider
{
    public function __construct(private readonly string $root) {}

    public function prepare(array $scenario, string $runId): array
    {
        $dir = $this->path($runId);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Unable to create scenario sandbox');
        $entities = [];
        foreach ((array)($scenario['data']['entities'] ?? []) as $type => $records) {
            $records = array_is_list((array)$records) ? $records : [$records];
            foreach ($records as $i => $record) {
                $record = (array)$record;
                $record['_ark_id'] = $record['_ark_id'] ?? $runId . ':' . $type . ':' . ($i + 1);
                $record['_ark_scenario'] = $scenario['scenario_id'];
                $entities[] = ['type' => (string)$type, 'record' => $record];
            }
        }
        $payload = ['scenario_id' => $scenario['scenario_id'], 'run_id' => $runId, 'entities' => $entities, 'relationships' => (array)($scenario['data']['relationships'] ?? [])];
        $file = $dir . '/seed.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        return ['provider' => 'json-sandbox', 'namespace' => $runId, 'file' => $file, 'entity_count' => count($entities), 'fingerprint' => hash_file('sha256', $file), 'prepared_at' => gmdate(DATE_ATOM)];
    }

    public function verify(array $scenario, array $receipt): array
    {
        $file = (string)($receipt['file'] ?? '');
        $actual = is_file($file) ? hash_file('sha256', $file) : null;
        $expected = $receipt['fingerprint'] ?? null;
        return ['valid' => $actual !== null && hash_equals((string)$expected, (string)$actual), 'drift' => $actual === null ? 'seed_missing' : ($actual === $expected ? 'none' : 'seed_changed'), 'expected_fingerprint' => $expected, 'actual_fingerprint' => $actual, 'verified_at' => gmdate(DATE_ATOM)];
    }

    public function cleanup(array $scenario, array $receipt): array
    {
        $file = (string)($receipt['file'] ?? '');
        $removed = $file !== '' && is_file($file) ? unlink($file) : false;
        $dir = $file !== '' ? dirname($file) : '';
        if ($dir !== '' && is_dir($dir) && count(scandir($dir) ?: []) === 2) @rmdir($dir);
        return ['clean' => !is_file($file), 'removed' => $removed, 'cleaned_at' => gmdate(DATE_ATOM)];
    }

    private function path(string $runId): string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $runId)) throw new RuntimeException('Invalid run ID');
        return rtrim($this->root, '/') . '/' . $runId;
    }
}

final class ScenarioEngine
{
    public function __construct(private readonly ScenarioDataProvider $provider, private readonly ?ScenarioContract $contract = null) {}

    public function prepare(array $scenario, string $runId): array
    {
        $validation = ($this->contract ?? new ScenarioContract())->validate($scenario);
        if (!$validation['valid']) throw new RuntimeException('Invalid scenario: ' . implode(', ', $validation['errors']));
        $receipt = $this->provider->prepare($scenario, $runId);
        $preconditions = $this->provider->verify($scenario, $receipt);
        if (!$preconditions['valid']) $this->provider->cleanup($scenario, $receipt);
        return ['schema' => 'ark.scenario-run.v1', 'run_id' => $runId, 'scenario' => $scenario, 'seed_receipt' => $receipt, 'preconditions' => $preconditions, 'status' => $preconditions['valid'] ? 'ready' : 'blocked'];
    }

    public function finalize(array $run): array
    {
        $run['postconditions'] = $this->provider->verify($run['scenario'], $run['seed_receipt']);
        $run['cleanup_result'] = $this->provider->cleanup($run['scenario'], $run['seed_receipt']);
        $run['status'] = $run['cleanup_result']['clean'] ? 'completed' : 'cleanup-failed';
        $run['finished_at'] = gmdate(DATE_ATOM);
        return $run;
    }
}

final class ScenarioStore
{
    public function __construct(private readonly string $root) {}

    public function save(array $scenario): string
    {
        if (!(new ScenarioContract())->validate($scenario)['valid']) throw new RuntimeException('Refusing invalid scenario');
        $module = $scenario['module'];
        $dir = rtrim($this->root, '/') . '/' . $module;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Unable to create scenario store');
        $file = $dir . '/' . $scenario['scenario_id'] . '.json';
        $tmp = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $file);
        return $file;
    }

    public function load(string $module, string $id): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $module) || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id)) throw new RuntimeException('Invalid scenario reference');
        $file = rtrim($this->root, '/') . '/' . $module . '/' . $id . '.json';
        if (!is_file($file)) throw new RuntimeException('Scenario not found');
        $value = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }
}

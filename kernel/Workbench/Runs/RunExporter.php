<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Runs;

final class RunExporter
{
    /**
     * ARK JSON export — includes full run data with canonical provenance.
     *
     * @param array<string,mixed> $run Run data including 'provenance' key from RunProvenance::build()
     * @param array<string,mixed>|null $task Optional task projection; when provided the export
     *        also carries Phase 3 task + release-decision provenance.
     */
    public function ark(array $run, ?array $task = null): string
    {
        $export = [
            'schema' => 'ark.workbench-run-export.v1',
            'run' => $run,
            'provenance' => $run['provenance'] ?? null,
        ];
        if ($task !== null) {
            $export['task'] = self::taskBlock($task);
        }
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * JUnit XML export — includes provenance as test suite properties.
     *
     * @param array<string,mixed> $run Run data including 'provenance' key
     * @param array<string,mixed>|null $task Optional task + release-decision provenance.
     */
    public function junit(array $run, ?array $task = null): string
    {
        $issues = (array) ($run['issues'] ?? []);
        $xml = new \SimpleXMLElement('<testsuite/>');
        $xml['name'] = 'ARK Workbench ' . ($run['module'] ?? 'module');
        $xml['tests'] = (string) max(1, count($issues));
        $xml['failures'] = (string) count($issues);

        // Embed provenance as properties
        $props = $xml->addChild('properties');
        $provenance = (array) ($run['provenance'] ?? []);
        foreach (['run_id', 'completion_status', 'module_id', 'git_sha', 'module_version', 'redaction_status'] as $key) {
            if (isset($provenance[$key])) {
                $prop = $props->addChild('property');
                $prop['name'] = $key;
                $prop['value'] = (string) $provenance[$key];
            }
        }
        // Keep the full canonical block available to consumers that need more
        // than the flat CI properties (fixture identity, AI policy, artifacts).
        $provenanceJson = json_encode($provenance, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $prop = $props->addChild('property');
        $prop['name'] = 'ark_workbench_provenance_json';
        $prop['value'] = $provenanceJson;

        // Phase 3: task + release-decision provenance as properties.
        if ($task !== null) {
            $taskBlock = self::taskBlock($task);
            $release = (array) ($taskBlock['release'] ?? []);
            $addProp = static function (string $name, string $value) use ($props): void {
                $prop = $props->addChild('property');
                $prop['name'] = $name;
                $prop['value'] = $value;
            };
            $addProp('task_id', (string) ($taskBlock['task_id'] ?? ''));
            $addProp('contract_revision', (string) ($taskBlock['contract_revision'] ?? ''));
            $addProp('release_decision', (string) ($release['decision'] ?? ''));
            $addProp('task_provenance_json', json_encode($taskBlock, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        if ($issues === []) {
            $xml->addChild('testcase')['name'] = 'contract-gates';
        }
        foreach ($issues as $issue) {
            $case = $xml->addChild('testcase');
            $case['name'] = (string) ($issue['fingerprint'] ?? 'issue');
            $failure = $case->addChild('failure', htmlspecialchars((string) ($issue['message'] ?? 'Workbench issue')));
            $failure['type'] = (string) ($issue['category'] ?? 'workbench');
        }
        return (string) $xml->asXML();
    }

    /**
     * SARIF 2.1.0 JSON export — includes provenance in run properties.
     *
     * @param array<string,mixed> $run Run data including 'provenance' key
     * @param array<string,mixed>|null $task Optional task + release-decision provenance.
     */
    public function sarif(array $run, ?array $task = null): string
    {
        $results = array_map(
            static fn(array $issue): array => [
                'ruleId' => (string) ($issue['category'] ?? 'workbench'),
                'level' => in_array($issue['severity'] ?? '', ['critical', 'major'], true) ? 'error' : 'warning',
                'message' => ['text' => (string) ($issue['message'] ?? 'Workbench issue')],
                'properties' => [
                    'fingerprint' => $issue['fingerprint'] ?? null,
                    'evidence' => $issue['evidence_links'] ?? [],
                ],
            ],
            (array) ($run['issues'] ?? [])
        );

        $sarifRun = [
            'tool' => ['driver' => ['name' => 'ARK Workbench']],
            'results' => $results,
            'properties' => [],
        ];

        // Attach provenance to SARIF run properties
        $provenance = (array) ($run['provenance'] ?? []);
        foreach (['run_id', 'completion_status', 'git_sha', 'module_id', 'module_version'] as $key) {
            if (isset($provenance[$key])) {
                $sarifRun['properties'][$key] = $provenance[$key];
            }
        }
        $sarifRun['properties']['ark_workbench_provenance'] = $provenance;

        // Phase 3: task + release-decision provenance.
        if ($task !== null) {
            $sarifRun['properties']['ark_workbench_task'] = self::taskBlock($task);
        }

        return json_encode(
            [
                'version' => '2.1.0',
                '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
                'runs' => [$sarifRun],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Phase 3: normalized task + release-decision provenance block attached to
     * ARK/JUnit/SARIF exports when a task projection is supplied.
     *
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private static function taskBlock(array $task): array
    {
        $release = (array) ($task['release'] ?? []);
        $conditions = [];
        foreach ((array) ($release['conditions'] ?? []) as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $conditions[] = [
                'id' => (string) ($condition['id'] ?? ''),
                'owner' => (string) ($condition['owner'] ?? ''),
                'resolved' => ($condition['resolved'] ?? false) === true,
            ];
        }

        return [
            'task_id' => (string) ($task['task_id'] ?? ''),
            'contract_revision' => (string) ($task['contract_revision'] ?? ''),
            'state' => (string) ($task['state'] ?? ''),
            'release' => [
                'decision' => (string) ($release['decision'] ?? ''),
                'verified_gate' => ($release['verified_gate'] ?? false) === true,
                'conditions' => $conditions,
            ],
        ];
    }
}

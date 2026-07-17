<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Runs;

final class RunExporter
{
    /**
     * ARK JSON export — includes full run data with canonical provenance.
     *
     * @param array<string,mixed> $run Run data including 'provenance' key from RunProvenance::build()
     */
    public function ark(array $run): string
    {
        $export = [
            'schema' => 'ark.workbench-run-export.v1',
            'run' => $run,
            'provenance' => $run['provenance'] ?? null,
        ];
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * JUnit XML export — includes provenance as test suite properties.
     *
     * @param array<string,mixed> $run Run data including 'provenance' key
     */
    public function junit(array $run): string
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
     */
    public function sarif(array $run): string
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

        return json_encode(
            [
                'version' => '2.1.0',
                '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
                'runs' => [$sarifRun],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }
}

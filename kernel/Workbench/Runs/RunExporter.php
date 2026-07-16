<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Runs;

final class RunExporter
{
    public function ark(array $run): string { return json_encode(['schema' => 'ark.workbench-run-export.v1', 'run' => $run], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
    public function junit(array $run): string
    {
        $issues = (array) ($run['issues'] ?? []); $xml = new \SimpleXMLElement('<testsuite/>'); $xml['name'] = 'ARK Workbench ' . ($run['module'] ?? 'module'); $xml['tests'] = (string) max(1, count($issues)); $xml['failures'] = (string) count($issues);
        if ($issues === []) $xml->addChild('testcase')['name'] = 'contract-gates';
        foreach ($issues as $issue) { $case = $xml->addChild('testcase'); $case['name'] = (string) ($issue['fingerprint'] ?? 'issue'); $failure = $case->addChild('failure', htmlspecialchars((string) ($issue['message'] ?? 'Workbench issue'))); $failure['type'] = (string) ($issue['category'] ?? 'workbench'); }
        return (string) $xml->asXML();
    }
    public function sarif(array $run): string
    {
        $results = array_map(static fn(array $issue): array => ['ruleId' => (string) ($issue['category'] ?? 'workbench'), 'level' => in_array($issue['severity'] ?? '', ['critical', 'major'], true) ? 'error' : 'warning', 'message' => ['text' => (string) ($issue['message'] ?? 'Workbench issue')], 'properties' => ['fingerprint' => $issue['fingerprint'] ?? null, 'evidence' => $issue['evidence_links'] ?? []]], (array) ($run['issues'] ?? []));
        return json_encode(['version' => '2.1.0', '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json', 'runs' => [['tool' => ['driver' => ['name' => 'ARK Workbench']], 'results' => $results]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}

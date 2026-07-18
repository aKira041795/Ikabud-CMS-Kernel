<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Extensions/ExtensionContracts.php';

require_once __DIR__ . '/../kernel/Workbench/Extensions/ExtensionRegistry.php';
use Ikabud\Kernel\Workbench\Extensions\{EvidenceCollectorExtension, GateExtension, ExtensionRegistry};

final class ExampleCollector implements EvidenceCollectorExtension { public function id(): string { return 'example.collector'; } public function collect(array $context): array { return [['source' => 'example', 'outcome' => 'passed', 'authoritative_digest' => $context['digest']]]; } }
final class ExampleGate implements GateExtension { public function id(): string { return 'example.gate'; } public function evaluate(array $evidence): array { return ['passed' => $evidence !== [], 'reason' => 'evidence required', 'evidence_links' => ['example:1']]; } }
$h = new TestHarness('workbench-competitive-phase7'); $registry = new ExtensionRegistry();
$registry->register(new ExampleCollector()); $registry->register(new ExampleGate());
$h->assertSame(['example.collector', 'example.gate'], $registry->ids(), 'extension SDK inventory is deterministic');
$evidence = $registry->runCollector('example.collector', ['digest' => 'truth'], 'truth');
$h->assertSame('passed', $evidence[0]['outcome'], 'collector preserves explicit outcomes');
$h->test('extension gate is schema-checked', $registry->runGate('example.gate', $evidence)['passed']);
$h->test('reusable GitHub workflow exists', is_file(__DIR__ . '/../.github/workflows/ark-workbench.yml'));
$workflow = (string) file_get_contents(__DIR__ . '/../.github/workflows/ark-workbench.yml');
$h->test('CI publishes evidence artifacts', str_contains($workflow, 'actions/upload-artifact@v4'));
$h->test('reproducible container runner exists', is_file(__DIR__ . '/../docker/workbench/Dockerfile'));
$docs = (string) file_get_contents(__DIR__ . '/../docs/workbench/extension-sdk-and-ci.md');
$h->test('adoption snippet stays under ten project lines', str_contains($docs, 'fewer than ten project lines'));
$dockerfile = (string) file_get_contents(__DIR__ . '/../docker/workbench/Dockerfile');
$h->test(
    'container scope is explicitly contract and benchmark only',
    str_contains($dockerfile, 'contract-and-benchmark-only')
        && str_contains($docs, 'does not claim to execute browser or hybrid E2E suites')
);
$ciScript = (string) file_get_contents(__DIR__ . '/../scripts/workbench-ci.php');
$h->test(
    'trust-critical CI entry point exposes auditable sections',
    str_contains($ciScript, 'Contract doctor')
        && str_contains($ciScript, 'Competitive benchmark')
        && str_contains($ciScript, 'Durable summary')
        && str_contains($ciScript, 'Exit gate')
);
$h->assertThrows(RuntimeException::class, function (): void { $bad = new class implements EvidenceCollectorExtension { public function id(): string { return 'bad.collector'; } public function collect(array $context): array { return [['source' => 'bad', 'outcome' => 'passed', 'authoritative_digest' => 'forged']]; } }; $r = new ExtensionRegistry(); $r->register($bad); $r->runCollector('bad.collector', ['digest' => 'truth'], 'truth'); }, 'extensions cannot replace authoritative truth');
$h->done();

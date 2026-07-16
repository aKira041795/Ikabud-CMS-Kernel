<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunRepository.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunIntelligence.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunExporter.php';

use Ikabud\Kernel\Workbench\Runs\RunExporter;
use Ikabud\Kernel\Workbench\Runs\RunIntelligence;
use Ikabud\Kernel\Workbench\Runs\RunRepository;

if (($argv[1] ?? '') === '--concurrent-writer') {
    $repository = new RunRepository((string) $argv[2]);
    usleep(random_int(1_000, 80_000));
    $repository->save([
        'run_id' => (string) $argv[3],
        'module' => 'guidance',
        'outcome' => 'passed',
    ]);
    exit(0);
}

$h = new TestHarness('workbench-competitive-phase6');
$root = sys_get_temp_dir() . '/ark-runs-' . bin2hex(random_bytes(4));
$repo = new RunRepository($root);
$base = [
    'module' => 'guidance',
    'commit' => 'abc',
    'tenant' => 'alpha',
    'role' => 'admin',
    'browser' => 'chromium',
    'environment' => 'ci',
    'contract_digest' => 'one',
];

$repo->save(array_merge($base, [
    'run_id' => 'run-1',
    'recorded_at' => '2026-01-01T00:00:00Z',
    'outcome' => 'failed',
    'issues' => [[
        'fingerprint' => 'nav-1',
        'category' => 'navigation',
        'severity' => 'critical',
        'message' => '404',
        'evidence_links' => ['trace:1'],
    ]],
]));
$repo->save(array_merge($base, [
    'run_id' => 'run-2',
    'recorded_at' => '2026-07-16T00:00:00Z',
    'outcome' => 'failed',
    'contract_digest' => 'two',
    'issues' => [[
        'fingerprint' => 'auth-1',
        'category' => 'authorization',
        'severity' => 'major',
        'message' => 'denied',
        'evidence_links' => ['trace:2'],
    ]],
]));

$h->assertCount(2, $repo->query(['module' => 'guidance']), 'history is indexed and filterable');
$comparison = $repo->compare('run-1', 'run-2');
$h->assertSame(['auth-1'], $comparison['new'], 'run comparison finds new defects');
$h->assertSame(['nav-1'], $comparison['resolved'], 'run comparison finds resolved defects');
$h->test('contract drift is visible', $comparison['contract_changed']);
$h->assertSame(
    1,
    $repo->expireRawArtifacts(new DateTimeImmutable('2026-06-01T00:00:00Z')),
    'raw retention expires old artifact'
);
$h->assertCount(2, $repo->query(), 'historical summaries survive raw expiry');

$workers = [];
for ($i = 0; $i < 12; $i++) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--concurrent-writer', $root, 'concurrent-' . $i],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($process)) {
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
}
$workerFailures = [];
foreach ($workers as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        $workerFailures[] = trim((string) $stdout . "\n" . (string) $stderr);
    }
}
$concurrentIds = array_column(
    array_filter(
        $repo->query(['module' => 'guidance']),
        static fn(array $row): bool => str_starts_with((string) $row['run_id'], 'concurrent-')
    ),
    'run_id'
);
$h->test('concurrent writers complete without process failures', $workerFailures === []);
$h->assertCount(12, $concurrentIds, 'index lock prevents lost concurrent run summaries');
$h->test('run repository exposes a dedicated index lock', is_file($root . '/index.lock'));

$intel = new RunIntelligence();
$h->assertSame(
    'flaky',
    $intel->classifyFlake(['passed', 'failed', 'passed'])['classification'],
    'flakes require mixed observed outcomes'
);
$h->assertCount(
    1,
    $intel->cluster([['fingerprint' => 'x'], ['fingerprint' => 'x']]),
    'recurrences cluster by fingerprint'
);
$timeline = $intel->timeline([
    ['at' => '2026-01-01T00:00:02Z', 'sequence' => 2],
    ['at' => '2026-01-01T00:00:01Z', 'sequence' => 1],
]);
$h->assertSame(1, $timeline[0]['sequence'], 'causal timeline is ordered');
$h->test(
    'diagnosis requires contract evidence and remediation',
    $intel->diagnosisIsTraceable([
        'failed_contract' => 'navigation',
        'evidence_links' => ['http:404'],
        'remediation' => 'register route',
    ])
);

$exporter = new RunExporter();
$run = $repo->get('run-2');
$h->test(
    'JUnit export is standards-compatible XML',
    str_contains($exporter->junit($run), '<testsuite')
);
$sarif = json_decode($exporter->sarif($run), true);
$h->assertSame('2.1.0', $sarif['version'], 'SARIF export uses 2.1.0');
$ark = json_decode($exporter->ark($run), true);
$h->assertSame('ark.workbench-run-export.v1', $ark['schema'], 'ARK export is versioned');

$h->done();

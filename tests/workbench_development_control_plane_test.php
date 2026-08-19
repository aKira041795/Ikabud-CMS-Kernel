<?php
declare(strict_types=1);
/**
 * Workbench Development Control Plane — Phase 1 (Observe) contract tests.
 *
 * Covers: schema/import validation, idempotent/revised contracts, every allowed
 * and denied lifecycle transition, release prerequisites (negative paths first),
 * redaction, task/run correlation, exact/prefix scope matching, traversal
 * rejection, corrupt artifacts, atomic-write failure, and concurrent writers.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentLifecycle.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskContract.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskRepository.php';
require_once __DIR__ . '/../kernel/Workbench/Development/GitEvidenceResolver.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentVerificationArtifact.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentArtifactIngestor.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunExporter.php';

use Ikabud\Kernel\Workbench\Development\DevelopmentArtifactIngestor;
use Ikabud\Kernel\Workbench\Development\DevelopmentLifecycle;
use Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract;
use Ikabud\Kernel\Workbench\Development\DevelopmentTaskRepository;
use Ikabud\Kernel\Workbench\Development\DevelopmentVerificationArtifact;
use Ikabud\Kernel\Workbench\Development\GitEvidenceResolver;
use Ikabud\Kernel\Workbench\Runs\RunExporter;

// Self-invocation mode: a concurrent writer appends transitions to a shared task.
if (in_array('--concurrent-writer', $argv ?? [], true)) {
    $root = (string) ($argv[array_search('--concurrent-writer', $argv ?? [], true) + 1] ?? '');
    $taskId = (string) ($argv[array_search('--concurrent-writer', $argv ?? [], true) + 2] ?? '');
    $cycles = (int) ($argv[array_search('--concurrent-writer', $argv ?? [], true) + 3] ?? 5);
    $repo = new DevelopmentTaskRepository($root);
    $cycle = [
        DevelopmentLifecycle::REVIEWING,
        DevelopmentLifecycle::CHANGES_REQUIRED,
        DevelopmentLifecycle::IMPLEMENTING,
        DevelopmentLifecycle::READY_FOR_REVIEW,
    ];
    $success = 0;
    for ($i = 0; $i < $cycles; $i++) {
        foreach ($cycle as $to) {
            $r = $repo->transition($taskId, $to, ['reason' => 'concurrent-writer']);
            if (($r['ok'] ?? false) === true) {
                $success++;
            }
        }
    }
    echo $success;
    exit(0);
}

$h = new TestHarness('workbench-development-control-plane');
$root = sys_get_temp_dir() . '/devcp-' . bin2hex(random_bytes(4));
$evidenceKey = 'development-control-plane-test-attestation-key-2026';
putenv('WORKBENCH_EVIDENCE_HMAC_KEY=' . $evidenceKey);
putenv('WORKBENCH_EVIDENCE_KEY_ID=test-key-v1');
$_ENV['WORKBENCH_EVIDENCE_HMAC_KEY'] = $evidenceKey;
$_ENV['WORKBENCH_EVIDENCE_KEY_ID'] = 'test-key-v1';

$actor = ['role' => 'codex', 'model' => 'test-model', 'harness' => 'cli', 'context_governor' => 'lean-ctx'];

// Throwaway git repo. The base commit tracks every path that will be touched;
// baselinePaths are already modified in the working tree at creation (pre-existing
// dirt that must remain baseline), while taskPaths are committed clean and only
// become dirty via $dirtyTask AFTER import — modelling the correct workflow
// (import before implementation, so the baseline can be separated from
// task-attributable changes). Returns [dir, resolver, head].
$makeGit = static function (array $baselinePaths, array $taskPaths): array {
    $dir = sys_get_temp_dir() . '/devcp-git-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $run = static function (array $args) use ($dir): void {
        $cmd = array_merge(['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test'], $args);
        $pipes = [];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($p)) {
            throw new RuntimeException('git is unavailable for tests');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0) {
            throw new RuntimeException('git command failed: ' . implode(' ', $cmd));
        }
    };
    $run(['init', '-q']);
    foreach (array_unique(array_merge($baselinePaths, $taskPaths)) as $path) {
        $full = $dir . '/' . $path;
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, "base\n");
    }
    $run(['add', '-A']);
    $run(['commit', '-q', '-m', 'base']);
    foreach ($baselinePaths as $path) {
        file_put_contents($dir . '/' . $path, $path . " baseline content\n");
    }
    $resolver = new GitEvidenceResolver($dir);

    return [$dir, $resolver, (string) $resolver->resolveHead()];
};

/** Make task-attributable files dirty in a repo working tree (post-import). */
$dirtyTask = static function (string $gitDir, array $taskPaths): void {
    foreach ($taskPaths as $path) {
        file_put_contents($gitDir . '/' . $path, $path . " content\n");
    }
};

/** Run a Git command in a throwaway repository and fail loudly on errors. */
$runGit = static function (string $gitDir, array $args): void {
    $pipes = [];
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $gitDir);
    if (!is_resource($proc)) {
        throw new RuntimeException('git is unavailable for tests');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('git command failed: ' . trim((string) $stderr));
    }
};

// Happy path: the repo is CLEAN at import (baseline captured empty), then
// src/a.php is made dirty after all imports so it is task-attributable.
[$gitDir, $gitResolver, $gitHead] = $makeGit([], ['src/a.php']);
$gitBase = str_repeat('a', 40); // informational only; not verified
$h->test('test git resolver resolves a HEAD', preg_match('/^[0-9a-f]{40}$/', $gitHead) === 1);
$h->test('clean happy-path repo has no changed paths at import (empty baseline)',
    $gitResolver->resolveChangedPaths() === []);

// Architecture source builder: each scenario needs a distinct objective so the
// source hash differs and idempotent re-import does not return the same task.
$mdFn = static function (string $tag): string {
    return <<<MD
# Current Task
## Objective
Implement the Development Task Ledger {$tag}.
## Existing behavior
Workbench is run-centric.
## Architectural constraints
- Preserve kernel boundaries.
## Files likely affected
- src/a.php
- src/lib/
## Implementation steps
- step one
## Acceptance criteria
- tasks are durable
## Required tests
- unit
- integration
## Risks
- low
## Forbidden changes
- Do not touch config/secret.php
MD;
};

/**
 * Write a schema-valid, task-bound release-gate artifact under a storage root
 * and return its sha256 content hash (which the envelope must provide).
 * Phase 3: $conditions are included when provided (decision: condition).
 */
function writeDevGate(string $storageRoot, array $task, string $gitSha, string $name = 'release-gate.json', string $decision = 'approved', ?array $checks = null, ?array $conditions = null): string
{
    $checks = $checks ?? [
        ['name' => 'unit', 'status' => 'PASS'],
        ['name' => 'integration', 'status' => 'PASS'],
        ['name' => 'playwright', 'status' => 'PASS'],
    ];
    $dir = rtrim($storageRoot, '/') . '/gates';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $gate = [
        'schema' => 'ark.workbench-development-release-gate.v1',
        'task_id' => $task['task_id'],
        'contract_revision' => $task['contract_revision'],
        'git_sha' => $gitSha,
        'decision' => $decision,
        'checks' => $checks,
        'created_at' => gmdate(DATE_ATOM),
    ];
    if ($conditions !== null) {
        $gate['conditions'] = $conditions;
    }
    $json = json_encode($gate, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($dir . '/' . $name, $json);

    return hash('sha256', $json);
}


$md = $mdFn('');

$h->section('Architecture import');
$repo = new DevelopmentTaskRepository($root);
$ing = new DevelopmentArtifactIngestor($repo, $gitResolver, $root);

// Artifact-backed, fully task-bound verification layers (P1-2). Each layer
// references a real result JSON that reproduces the immutable contract revision,
// the resolved Git HEAD, the current working-tree fingerprint, a runner identity,
// and the layer it certifies. The ingestor computes the content hash itself and
// validates every binding. $bindOverride is used for synthetic/nonexistent tasks
// (e.g. the lifecycle context).
$makeVerif = static function (string $taskId, ?array $bindOverride = null) use ($root, $repo, $gitResolver): array {
    if ($bindOverride === null) {
        $task = $repo->getTask($taskId);
        $bind = [
            'contract_revision' => (string) ($task['contract_revision'] ?? ''),
            'head' => (string) ($gitResolver->resolveHead() ?? ''),
            'fingerprint' => (string) ($gitResolver->workingTreeFingerprint() ?? ''),
        ];
    } else {
        $bind = $bindOverride;
    }
    $layers = [];
    foreach (['unit', 'integration', 'playwright'] as $name) {
        $rel = 'evidence/' . $taskId . '/test_results/' . $name . '.json';
        $abs = rtrim($root, '/') . '/' . $rel;
        @mkdir(dirname($abs), 0775, true);
        $artifact = [
            'schema' => 'ark.workbench-test-result.v1',
            'task_id' => $taskId,
            'contract_revision' => (string) ($bind['contract_revision'] ?? ''),
            'git_head' => (string) ($bind['head'] ?? ''),
            'fingerprint' => (string) ($bind['fingerprint'] ?? ''),
            'runner' => 'test-runner',
            'layer' => $name,
            'suite' => $name,
            'signature_algorithm' => DevelopmentVerificationArtifact::SIGNATURE_ALGORITHM,
            'attestation_key_id' => DevelopmentVerificationArtifact::trustedKeyId(),
            'summary' => ['passed' => 10, 'failed' => 0, 'skipped' => 0, 'total' => 10, 'exit_code' => 0],
        ];
        $artifact['signature'] = DevelopmentVerificationArtifact::sign($artifact);
        file_put_contents($abs, json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $layers[] = ['name' => $name, 'status' => 'PASS', 'path' => $rel];
    }

    return $layers;
};

/** Drive a task through implement (full task-bound layer set + git evidence) + passing review. */
$driveToReview = static function (string $tid) use ($ing, $actor, $gitBase, $gitHead, $makeVerif): void {
    $ing->ingestStageResult($tid, [
        'stage' => 'implement', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
        'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
        'verification' => $makeVerif($tid),
    ], []);
    $ing->ingestStageResult($tid, [
        'stage' => 'review', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    ], []);
};

/** Import an architecture under a distinct scenario source path. */
$importTask = static function (string $mdSource, string $tag) use ($ing, $actor): string {
    return (string) ($ing->importArchitecture(
        $mdSource,
        $actor,
        ['source_path' => '.ai/scenario-' . $tag . '.md']
    )['task_id'] ?? '');
};

$res = $ing->importArchitecture($md, $actor, []);
$h->test('valid import creates a durable task', ($res['ok'] ?? false) === true && ($res['created'] ?? false) === true);
$h->test('task starts READY_FOR_IMPLEMENTATION', ($res['task']['state'] ?? '') === DevelopmentLifecycle::READY_FOR_IMPLEMENTATION);
$h->test('immutable revision id recorded', preg_match('/^[a-f0-9]{16}$/', (string) ($res['revision'] ?? '')) === 1);
$h->test('source hash is stored on the task', ($res['task']['source']['hash'] ?? '') === hash('sha256', $md));
$h->test('allowed scope parsed (file + directory)', count($res['task']['approved_scope']['allowed'] ?? []) === 2);
$h->test('forbidden scope parsed', count($res['task']['approved_scope']['forbidden'] ?? []) === 1);
    $dirScope = array_values(array_filter(
        $res['task']['approved_scope']['allowed'] ?? [],
        static fn(array $e): bool => ($e['kind'] ?? '') === 'directory'
    ));
    $h->test('trailing-slash directory is classified as directory scope',
        count($dirScope) === 1 && ($dirScope[0]['path'] ?? '') === 'src/lib');
    $h->test('file under imported directory scope is approved',
        ($ing->classifyScope(['allowed' => $res['task']['approved_scope']['allowed'] ?? []], ['src/lib/util.php'])['approved'] ?? []) === ['src/lib/util.php' => true]);
$taskId = (string) ($res['task_id'] ?? '');

$res2 = $ing->importArchitecture($md, $actor, []);
$h->test('unchanged re-import is idempotent', ($res2['idempotent'] ?? false) === true && ($res2['task_id'] ?? '') === $taskId);

$h->test('missing required heading fails closed', (static function () use ($ing, $actor): bool {
    try {
        $ing->importArchitecture("# Current Task\n## Objective\nOnly objective\n", $actor, []);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());

$h->test('traversal in allowed scope fails closed', (static function () use ($ing, $actor, $md): bool {
    try {
        $ing->importArchitecture(str_replace('- src/a.php', '- ../outside.php', $md), $actor, []);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());

$h->test('invalid task id rejected', (static function () use ($repo): bool {
    try {
        $repo->getTask('../secret');
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());

$h->test('dot task ids are rejected', (static function () use ($repo): bool {
    try {
        $repo->getTask('..');
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());

$h->section('Scope matching');
$h->test('exact file inside allowed scope is approved',
    ($ing->classifyScope(['allowed' => [['path' => 'src/a.php', 'kind' => 'file']], 'forbidden' => []], ['src/a.php'])['approved'] ?? []) === ['src/a.php' => true]);
$h->test('directory prefix inside allowed scope is approved',
    ($ing->classifyScope(['allowed' => [['path' => 'src/lib', 'kind' => 'directory']], 'forbidden' => []], ['src/lib/util.php'])['approved'] ?? []) === ['src/lib/util.php' => true]);
$h->test('path outside scope is unexpected',
    ($ing->classifyScope(['allowed' => [['path' => 'src/a.php', 'kind' => 'file']], 'forbidden' => []], ['modules/x/y.php'])['unexpected'] ?? []) === ['modules/x/y.php' => true]);
$h->test('forbidden path is an unexpected violation',
    ($ing->classifyScope(['allowed' => [['path' => 'src/a.php', 'kind' => 'file']], 'forbidden' => [['path' => 'config', 'kind' => 'directory']]], ['config/secret.php'])['unexpected'] ?? []) === ['config/secret.php' => true]);
$h->test('traversal changed path is unexpected, not fatal',
    ($ing->classifyScope(['allowed' => [['path' => 'src/a.php', 'kind' => 'file']], 'forbidden' => []], ['../escape.php'])['unexpected'] ?? []) === ['../escape.php' => true]);

$h->section('Lifecycle transitions');
$ctxGateHash = writeDevGate($root, ['task_id' => 'ctx-task', 'contract_revision' => str_repeat('a', 16)], $gitHead, 'transition-gate.json');
// The lifecycle context is a synthetic (nonexistent) task, so its verification
// artifacts are written with an explicit binding that matches the context.
$ctxFp = (string) ($gitResolver->workingTreeFingerprint() ?? ''); // repo is clean here
$ctxLayers = [];
foreach ($makeVerif('ctx-task', ['contract_revision' => str_repeat('a', 16), 'head' => $gitHead, 'fingerprint' => $ctxFp]) as $ctxLayer) {
    $ctxAbs = $root . '/' . $ctxLayer['path'];
    $ctxLayers[] = [
        'name' => $ctxLayer['name'],
        'status' => 'PASS',
        'path' => $ctxAbs,
        'hash' => hash_file('sha256', $ctxAbs),
    ];
}
$releaseCtx = [
    'state' => DevelopmentLifecycle::REVIEW_PASSED,
    'task_id' => 'ctx-task',
    'contract_revision' => str_repeat('a', 16),
    'git' => [
        'head' => $gitHead,
        'changed_paths' => [],
        'baseline_changed_paths' => [],
        'fingerprint' => $ctxFp,
    ],
    'release' => [
        'gate_artifact' => realpath($root . '/gates/transition-gate.json'),
        'gate_hash' => $ctxGateHash,
        'decision' => 'approved',
        'blockers' => [],
        'verified_gate' => true,
    ],
    'review' => ['status' => 'passed', 'findings' => []],
    'actual_scope' => [],
    'verification' => ['layers' => $ctxLayers],
];
$allowed = DevelopmentLifecycle::allowedTransitions();
$allOk = true;
$allowedCount = 0;
foreach ($allowed as $from => $tos) {
    foreach (array_keys($tos) as $to) {
        $allowedCount++;
        $ctx = $to === DevelopmentLifecycle::READY_FOR_RELEASE ? $releaseCtx : [];
        if (!(DevelopmentLifecycle::transition($from, $to, $ctx, $gitResolver)['ok'] ?? false)) {
            $allOk = false;
        }
    }
}
$h->test('every allow-listed transition validates', $allOk === true && $allowedCount > 0);
$h->test('unknown target state fails closed', DevelopmentLifecycle::canTransition(DevelopmentLifecycle::READY_FOR_IMPLEMENTATION, 'NOPE') === false);
$h->test('CHANGES_REQUIRED returns only to implementation',
    DevelopmentLifecycle::canTransition(DevelopmentLifecycle::CHANGES_REQUIRED, DevelopmentLifecycle::IMPLEMENTING) === true
    && DevelopmentLifecycle::canTransition(DevelopmentLifecycle::CHANGES_REQUIRED, DevelopmentLifecycle::REVIEW_PASSED) === false
    && DevelopmentLifecycle::canTransition(DevelopmentLifecycle::CHANGES_REQUIRED, DevelopmentLifecycle::RELEASE_GATE) === false);
$h->test('READY_FOR_RELEASE cannot be reached directly from REQUESTED',
    DevelopmentLifecycle::canTransition(DevelopmentLifecycle::REQUESTED, DevelopmentLifecycle::READY_FOR_RELEASE) === false);

$h->section('Architecture revision');
$mdChanged = $mdFn('revised');
$rRev = $repo->reviseArchitecture($taskId, DevelopmentTaskContract::parseCurrentTaskMarkdown($mdChanged), $actor);
$h->test('changed architecture creates a new immutable revision', ($rRev['ok'] ?? false) === true && ($rRev['revision'] ?? '') !== ($res['revision'] ?? ''));
$h->test('revision event is appended', count($repo->timeline($taskId)) === 2);
$rSame = $repo->reviseArchitecture($taskId, DevelopmentTaskContract::parseCurrentTaskMarkdown($mdChanged), $actor);
$h->test('unchanged revision re-applied is idempotent', ($rSame['ok'] ?? false) === true && ($rSame['idempotent'] ?? false) === true);

$scopeChangedMd = str_replace('- src/lib/', "- src/lib/\n- src/new-dir/", $mdChanged);
$rScope = $repo->reviseArchitecture($taskId, DevelopmentTaskContract::parseCurrentTaskMarkdown($scopeChangedMd), $actor);
$scopeDirs = array_column($repo->getTask($taskId)['approved_scope']['allowed'] ?? [], 'path');
$h->test('scope-changing revision updates the enforced approved scope',
    ($rScope['ok'] ?? false) === true && in_array('src/new-dir', $scopeDirs, true));

// Make the happy-path task change (src/a.php) dirty NOW, after all imports, so
// it is a task-attributable change rather than baseline (P1-3).
$dirtyTask($gitDir, ['src/a.php']);

$taskAct = $importTask($md, 'act');
$ing->ingestStageResult($taskAct, [
    'stage' => 'implement', 'task_id' => $taskAct, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS']],
], []);
$rAct = $repo->reviseArchitecture($taskAct, DevelopmentTaskContract::parseCurrentTaskMarkdown($mdChanged), $actor);
$h->test('revision during active implementation is rejected', ($rAct['ok'] ?? false) === false);

$h->section('Stage ingestion and release gating');
$ing->ingestStageResult($taskId, [
    'stage' => 'implement', 'task_id' => $taskId, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskId),
], []);
$t = $repo->getTask($taskId);
$h->test('implement passed -> READY_FOR_REVIEW', $t['state'] === DevelopmentLifecycle::READY_FOR_REVIEW);
$approved = array_filter($t['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'approved');
$h->test('approved changed path is recorded separately', count($approved) === 1);
$persistedByName = [];
foreach (($t['verification']['layers'] ?? []) as $layer) {
    $persistedByName[(string) ($layer['name'] ?? '')] = $layer;
}
$unitAbs = $root . '/evidence/' . $taskId . '/test_results/unit.json';
$h->test('persisted verification layers are artifact-backed with computed hashes',
    ($persistedByName['unit']['verified'] ?? false) === true
    && ($persistedByName['unit']['hash'] ?? '') === hash_file('sha256', $unitAbs)
    && ($persistedByName['unit']['path'] ?? '') === realpath($unitAbs));

$ing->ingestStageResult($taskId, [
    'stage' => 'review', 'task_id' => $taskId, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$h->test('review passed -> REVIEW_PASSED', $repo->getTask($taskId)['state'] === DevelopmentLifecycle::REVIEW_PASSED);

$rNoGate = $ing->ingestStageResult($taskId, [
    'stage' => 'release-gate', 'task_id' => $taskId, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$h->test('release without gate artifact is blocked', ($rNoGate['ok'] ?? false) === false && $repo->getTask($taskId)['state'] === DevelopmentLifecycle::REVIEW_PASSED);
$h->test('release blocker names the missing verified gate artifact', in_array('No verified release-gate artifact is recorded', $rNoGate['blockers'] ?? [], true));

$gateHash = writeDevGate($root, $repo->getTask($taskId), $gitHead);
$rGate = $ing->ingestStageResult($taskId, [
    'stage' => 'release-gate', 'task_id' => $taskId, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/release-gate.json', 'hash' => $gateHash, 'decision' => 'approved'],
], []);
$h->test('valid verified release gate -> READY_FOR_RELEASE', ($rGate['ok'] ?? false) === true && $repo->getTask($taskId)['state'] === DevelopmentLifecycle::READY_FOR_RELEASE);
$h->test('release gate records a verified hash-checked artifact',
    ($repo->getTask($taskId)['release']['verified_gate'] ?? false) === true
    && ($repo->getTask($taskId)['release']['gate_hash'] ?? '') !== '');

// Negative: implementation/review prose and generic passed runs cannot unlock release.
$taskN = $importTask($mdFn('prose'), 'prose');
$ing->ingestStageResult($taskN, [
    'stage' => 'implement', 'task_id' => $taskN, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS']],
], []);
$ing->ingestStageResult($taskN, [
    'stage' => 'review', 'task_id' => $taskN, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$rN = $ing->ingestStageResult($taskN, [
    'stage' => 'release-gate', 'task_id' => $taskN, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$h->test('review prose without gate cannot unlock release', ($rN['ok'] ?? false) === false);
$h->test('task stays non-releasable after prose-only pass', $repo->getTask($taskN)['state'] !== DevelopmentLifecycle::READY_FOR_RELEASE);

// Negative: unexpected scope moves the task to REVIEW_REQUIRED and blocks release.
$taskU = $importTask($mdFn('scope'), 'scope');
[$uDir, $uGit, $uHead] = $makeGit([], ['modules/unrelated/other.php']);
$dirtyTask($uDir, ['modules/unrelated/other.php']);
$uIng = new DevelopmentArtifactIngestor($repo, $uGit, $root);
$rU = $uIng->ingestStageResult($taskU, [
    'stage' => 'implement', 'task_id' => $taskU, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $uHead, 'changed_paths' => ['modules/unrelated/other.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS']],
], []);
$tU = $repo->getTask($taskU);
$h->test('unexpected path -> REVIEW_REQUIRED', $tU['state'] === DevelopmentLifecycle::REVIEW_REQUIRED);
$unexpected = array_values(array_filter($tU['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'));
$h->test('unexpected path is retained visibly in actual_scope',
    count($unexpected) === 1 && ($unexpected[0]['path'] ?? '') === 'modules/unrelated/other.php');

// Negative: missing verification evidence blocks release.
$taskV = $importTask($mdFn('verify'), 'verify');
$ing->ingestStageResult($taskV, [
    'stage' => 'implement', 'task_id' => $taskV, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
], []);
$ing->ingestStageResult($taskV, [
    'stage' => 'review', 'task_id' => $taskV, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$rV = $ing->ingestStageResult($taskV, [
    'stage' => 'release-gate', 'task_id' => $taskV, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'storage/workbench/development/release-gate.json', 'decision' => 'approved'],
], []);
$h->test('missing mandatory verification blocks release', ($rV['ok'] ?? false) === false);
$h->test('release blocker names missing verification', (static function () use ($rV): bool {
    foreach (($rV['blockers'] ?? []) as $b) {
        if (str_contains((string) $b, 'Mandatory verification')) {
            return true;
        }
    }
    return false;
})());

// Malformed envelope fails closed.
$rBad = $ing->ingestStageResult($taskId, ['stage' => 'nope', 'task_id' => $taskId, 'result' => 'passed', 'actor' => $actor], []);
$h->test('malformed stage envelope fails closed with errors', ($rBad['ok'] ?? false) === false && !empty($rBad['errors']));

$h->section('Redaction');
$red = $ing->redact([
    'api_key' => 'sk-1234567890abcdef',
    'password' => 'hunter2',
    'evidence' => [['ref' => 'a', 'hash' => 'h', 'summary' => 'token sk-abcdefghijklmnop used']],
    'safe' => 'plain text',
]);
$h->test('api_key value is redacted', ($red['api_key'] ?? '') === '[REDACTED]');
$h->test('password value is redacted', ($red['password'] ?? '') === '[REDACTED]');
$h->test('inline secret in nested text is redacted', str_contains((string) ($red['evidence'][0]['summary'] ?? ''), 'sk-abcdefghijklmnop') === false);
$h->test('non-secret values are preserved', ($red['safe'] ?? '') === 'plain text');

$h->section('Task/run correlation');
$taskC = $importTask($mdFn('correlate'), 'correlate');
$ing->ingestStageResult($taskC, [
    'stage' => 'implement', 'task_id' => $taskC, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'run_ids' => ['20260101000000-abc123'],
    'evidence' => [['kind' => 'playwright', 'ref' => 'test_results/browser/wb.spec.json', 'hash' => 'deadbeef']],
], []);
$events = $repo->timeline($taskC);
$lastEvent = $events[count($events) - 1] ?? [];
$h->test('run id linked in event evidence', in_array('run:20260101000000-abc123', $lastEvent['evidence'] ?? [], true));
$h->test('content-hashed artifact reference retained', in_array('test_results/browser/wb.spec.json#deadbeef', $lastEvent['evidence'] ?? [], true));

$h->section('Timeline integrity');
$events = $repo->timeline($taskId);
$seqs = array_map(static fn(array $e): int => (int) ($e['sequence'] ?? 0), $events);
$h->test('events are monotonic, append-only, and sequence-complete', $seqs === range(1, count($events)));
$h->test('event records actor identity', (($events[0]['actor']['role'] ?? '') === 'codex' && ($events[0]['actor']['harness'] ?? '') === 'cli'));
$h->test('event records prior and new state', ($events[count($events) - 1]['prior_state'] ?? '') === DevelopmentLifecycle::REVIEW_PASSED && ($events[count($events) - 1]['new_state'] ?? '') === DevelopmentLifecycle::READY_FOR_RELEASE);

$h->section('Corrupt artifacts and write failures');
$corruptRoot = $root . '-corrupt';
$crepo = new DevelopmentTaskRepository($corruptRoot);
$cres = $crepo->createTask(DevelopmentTaskContract::parseCurrentTaskMarkdown($md), $actor, []);
$cid = (string) ($cres['task_id'] ?? '');
$h->test('corrupt task JSON fails closed', (static function () use ($crepo, $cid, $corruptRoot): bool {
    file_put_contents($corruptRoot . '/' . $cid . '/task.json', '{not json');
    try {
        $crepo->getTask($cid);
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

$h->test('failed write is never reported as persisted', (static function () use ($root, $md, $actor): bool {
    $wroot = $root . '-wfail';
    $wrepo = new DevelopmentTaskRepository($wroot);
    $wres = $wrepo->createTask(DevelopmentTaskContract::parseCurrentTaskMarkdown($md), $actor, []);
    $wid = (string) ($wres['task_id'] ?? '');
    $stateBefore = $wrepo->getTask($wid)['state'];
    $eventsBefore = count($wrepo->timeline($wid));
    // Make the task directory read-only so the next write must fail.
    @chmod($wroot . '/' . $wid, 0555);
    $failed = false;
    try {
        $wrepo->transition($wid, DevelopmentLifecycle::IMPLEMENTING, ['reason' => 'write-fail']);
    } catch (\Throwable $e) {
        $failed = true;
    }
    @chmod($wroot . '/' . $wid, 0775);
    return $failed === true
        && $wrepo->getTask($wid)['state'] === $stateBefore
        && count($wrepo->timeline($wid)) === $eventsBefore;
})());

$h->section('Concurrent writers');
$concurrentRoot = $root . '-concurrent';
$crepo2 = new DevelopmentTaskRepository($concurrentRoot);
$cres2 = $crepo2->createTask(DevelopmentTaskContract::parseCurrentTaskMarkdown($mdFn('concurrent')), $actor, []);
$cTaskId = (string) ($cres2['task_id'] ?? '');
// Seed to READY_FOR_REVIEW so every cycle hop is an allow-listed transition.
$crepo2->transition($cTaskId, DevelopmentLifecycle::READY_FOR_REVIEW, ['reason' => 'seed']);
$writers = 4;
$cycles = 5;
$procs = [];
// Spawn ALL writers first so they contend on the per-task lock concurrently.
for ($i = 0; $i < $writers; $i++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --concurrent-writer ' . escapeshellarg($concurrentRoot) . ' ' . escapeshellarg($cTaskId) . ' ' . (string) $cycles;
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $procs[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
}
$totalSuccess = 0;
foreach ($procs as $entry) {
    $out = stream_get_contents($entry['stdout']);
    fclose($entry['stdout']);
    stream_get_contents($entry['stderr']);
    fclose($entry['stderr']);
    proc_close($entry['proc']);
    $totalSuccess += (int) trim((string) $out);
}
$finalTask = $crepo2->getTask($cTaskId);
// Baseline = 1 (import event) + 1 (seed transition to READY_FOR_REVIEW).
// Every reported success must persist exactly one event and bump the sequence.
$expectedSequence = 2 + $totalSuccess;
$h->test('concurrent writers lose no events', count($crepo2->timeline($cTaskId)) === $expectedSequence);
$h->test('concurrent writers keep a consistent final sequence', (int) ($finalTask['sequence'] ?? 0) === $expectedSequence);
$h->test('concurrent writers leave a consistent final state', in_array($finalTask['state'], DevelopmentLifecycle::STATES, true));

$h->section('Review finding resolution');
$taskF = $importTask($mdFn('finding'), 'finding');
$ing->ingestStageResult($taskF, [
    'stage' => 'implement', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS', 'hash' => str_repeat('a', 64)]],
], []);
$ing->ingestStageResult($taskF, [
    'stage' => 'review', 'task_id' => $taskF, 'result' => 'changes_required',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [['severity' => 'P1', 'summary' => 'Scope drift in src/lib']],
], []);
$h->test('review changes_required records an open finding',
    $repo->getTask($taskF)['state'] === DevelopmentLifecycle::CHANGES_REQUIRED
    && count($repo->getTask($taskF)['review']['findings'] ?? []) === 1);
$ing->ingestStageResult($taskF, [
    'stage' => 'implement', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS', 'hash' => str_repeat('a', 64)]],
], []);
$ing->ingestStageResult($taskF, [
    'stage' => 'implement', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
], []);
$ing->ingestStageResult($taskF, [
    'stage' => 'review', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$taskFGateHash = writeDevGate($root, $repo->getTask($taskF), $gitHead, 'taskf-gate.json');
$rF = $ing->ingestStageResult($taskF, [
    'stage' => 'release-gate', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'verification' => $makeVerif($taskF),
    'release_gate' => ['artifact' => 'gates/taskf-gate.json', 'hash' => $taskFGateHash, 'decision' => 'approved'],
], []);
$h->test('findings resolved on passing review unblock release',
    ($rF['ok'] ?? false) === true && $repo->getTask($taskF)['state'] === DevelopmentLifecycle::READY_FOR_RELEASE);

$h->section('Release gate verification');
// Fabricated gate artifact (file does not exist) is rejected.
$t1 = $importTask($mdFn('fabricated'), 'fabricated');
$driveToReview($t1);
$r1 = $ing->ingestStageResult($t1, [
    'stage' => 'release-gate', 'task_id' => $t1, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/no-such-gate.json', 'hash' => str_repeat('d', 64), 'decision' => 'approved'],
], []);
$h->test('fabricated gate artifact is rejected', ($r1['ok'] ?? false) === false
    && in_array('Release-gate artifact not found: gates/no-such-gate.json', $r1['blockers'] ?? [], true));

// Gate artifact with a failing check is rejected.
$t2 = $importTask($mdFn('gatefail'), 'gatefail');
$driveToReview($t2);
$t2Hash = writeDevGate($root, $repo->getTask($t2), $gitHead, 'fail-gate.json', 'approved', [
    ['name' => 'unit', 'status' => 'PASS'],
    ['name' => 'integration', 'status' => 'FAIL'],
    ['name' => 'playwright', 'status' => 'PASS'],
]);
$r2 = $ing->ingestStageResult($t2, [
    'stage' => 'release-gate', 'task_id' => $t2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/fail-gate.json', 'hash' => $t2Hash, 'decision' => 'approved'],
], []);
$h->test('gate with a failing deterministic check is rejected', ($r2['ok'] ?? false) === false
    && in_array("Gate check 'integration' is FAIL", $r2['blockers'] ?? [], true));

// Gate artifact with a mismatched recorded hash is rejected.
$t3 = $importTask($mdFn('gatehash'), 'gatehash');
$driveToReview($t3);
writeDevGate($root, $repo->getTask($t3), $gitHead, 'hash-gate.json');
$r3 = $ing->ingestStageResult($t3, [
    'stage' => 'release-gate', 'task_id' => $t3, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => [
        'artifact' => 'gates/hash-gate.json',
        'decision' => 'approved',
        'hash' => '0000000000000000000000000000000000000000000000000000000000000000',
    ],
], []);
$h->test('gate with mismatched recorded hash is rejected', ($r3['ok'] ?? false) === false
    && in_array('Release-gate artifact hash is missing or does not match', $r3['blockers'] ?? [], true));

// One arbitrary PASS layer cannot unlock release (required set is enforced).
$t4 = $importTask($mdFn('onepass'), 'onepass');
$ing->ingestStageResult($t4, [
    'stage' => 'implement', 'task_id' => $t4, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [['name' => 'unit', 'status' => 'PASS', 'hash' => str_repeat('a', 64)]],
], []);
$ing->ingestStageResult($t4, [
    'stage' => 'review', 'task_id' => $t4, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$t4Hash = writeDevGate($root, $repo->getTask($t4), $gitHead, 'one-pass-gate.json');
$r4 = $ing->ingestStageResult($t4, [
    'stage' => 'release-gate', 'task_id' => $t4, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/one-pass-gate.json', 'hash' => $t4Hash, 'decision' => 'approved'],
], []);
$h->test('one arbitrary PASS layer cannot unlock release', ($r4['ok'] ?? false) === false
    && (in_array("Required verification 'integration' is missing", $r4['blockers'] ?? [], true)
        || in_array("Required verification 'playwright' is missing", $r4['blockers'] ?? [], true)));

// A SKIPPED required layer blocks release.
$t5 = $importTask($mdFn('skipped'), 'skipped');
$ing->ingestStageResult($t5, [
    'stage' => 'implement', 'task_id' => $t5, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [
        ['name' => 'unit', 'status' => 'PASS', 'hash' => str_repeat('a', 64)],
        ['name' => 'integration', 'status' => 'PASS', 'hash' => str_repeat('b', 64)],
        ['name' => 'playwright', 'status' => 'SKIPPED', 'hash' => str_repeat('c', 64)],
    ],
], []);
$ing->ingestStageResult($t5, [
    'stage' => 'review', 'task_id' => $t5, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$t5Hash = writeDevGate($root, $repo->getTask($t5), $gitHead, 'skipped-gate.json');
$r5 = $ing->ingestStageResult($t5, [
    'stage' => 'release-gate', 'task_id' => $t5, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/skipped-gate.json', 'hash' => $t5Hash, 'decision' => 'approved'],
], []);
$h->test('skipped required verification layer blocks release', ($r5['ok'] ?? false) === false
    && in_array("Required verification 'playwright' is SKIPPED (must be executed and PASS)", $r5['blockers'] ?? [], true));

$h->section('Release gate hardening (P1)');
// Empty-check artifact cannot unlock release.
$t6 = $importTask($mdFn('emptygate'), 'emptygate');
$driveToReview($t6);
$t6Hash = writeDevGate($root, $repo->getTask($t6), $gitHead, 'empty-gate.json', 'approved', []);
$r6 = $ing->ingestStageResult($t6, [
    'stage' => 'release-gate', 'task_id' => $t6, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/empty-gate.json', 'hash' => $t6Hash, 'decision' => 'approved'],
], []);
$h->test('empty-check gate artifact is rejected', ($r6['ok'] ?? false) === false
    && in_array('Release-gate artifact declares no checks', $r6['blockers'] ?? [], true));

// Gate bound to a different task is rejected.
$t7 = $importTask($mdFn('wrongtask'), 'wrongtask');
$driveToReview($t7);
$t7Hash = writeDevGate($root, $repo->getTask($taskId), $gitHead, 'wrong-task-gate.json');
$r7 = $ing->ingestStageResult($t7, [
    'stage' => 'release-gate', 'task_id' => $t7, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/wrong-task-gate.json', 'hash' => $t7Hash, 'decision' => 'approved'],
], []);
$h->test('gate bound to a different task is rejected', ($r7['ok'] ?? false) === false
    && in_array('Release-gate artifact is not bound to this task', $r7['blockers'] ?? [], true));

// Gate bound to a stale contract revision is rejected.
$t8 = $importTask($mdFn('stale'), 'stale');
$driveToReview($t8);
$t8Task = $repo->getTask($t8);
$t8Task['contract_revision'] = str_repeat('f', 16);
$t8Hash = writeDevGate($root, $t8Task, $gitHead, 'stale-rev-gate.json');
$r8 = $ing->ingestStageResult($t8, [
    'stage' => 'release-gate', 'task_id' => $t8, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/stale-rev-gate.json', 'hash' => $t8Hash, 'decision' => 'approved'],
], []);
$h->test('gate bound to a stale contract revision is rejected', ($r8['ok'] ?? false) === false
    && in_array('Release-gate artifact is not bound to the current contract revision', $r8['blockers'] ?? [], true));

// Gate bound to a mismatched git SHA is rejected.
$t9 = $importTask($mdFn('gitmismatch'), 'gitmismatch');
$driveToReview($t9);
$t9Hash = writeDevGate($root, $repo->getTask($t9), str_repeat('c', 40), 'wrong-git-gate.json');
$r9 = $ing->ingestStageResult($t9, [
    'stage' => 'release-gate', 'task_id' => $t9, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/wrong-git-gate.json', 'hash' => $t9Hash, 'decision' => 'approved'],
], []);
$h->test('gate with a mismatched git SHA is rejected', ($r9['ok'] ?? false) === false
    && in_array('Release-gate artifact git SHA is missing or does not match the implementation head', $r9['blockers'] ?? [], true));

// Absolute and traversal artifact paths are rejected.
$t10 = $importTask($mdFn('abs'), 'abs');
$driveToReview($t10);
$r10 = $ing->ingestStageResult($t10, [
    'stage' => 'release-gate', 'task_id' => $t10, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => '/etc/passwd', 'hash' => str_repeat('d', 64), 'decision' => 'approved'],
], []);
$h->test('absolute gate artifact path is rejected', ($r10['ok'] ?? false) === false
    && in_array('Release-gate artifact path must be relative', $r10['blockers'] ?? [], true));
$t11 = $importTask($mdFn('trav'), 'trav');
$driveToReview($t11);
$r11 = $ing->ingestStageResult($t11, [
    'stage' => 'release-gate', 'task_id' => $t11, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => '../escape.json', 'hash' => str_repeat('d', 64), 'decision' => 'approved'],
], []);
$h->test('traversal gate artifact path is rejected', ($r11['ok'] ?? false) === false
    && in_array('Release-gate artifact path may not traverse directories', $r11['blockers'] ?? [], true));

// Missing envelope hash is rejected.
$t12 = $importTask($mdFn('nohash'), 'nohash');
$driveToReview($t12);
writeDevGate($root, $repo->getTask($t12), $gitHead, 'no-hash-gate.json');
$r12 = $ing->ingestStageResult($t12, [
    'stage' => 'release-gate', 'task_id' => $t12, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/no-hash-gate.json', 'decision' => 'approved'],
], []);
$h->test('gate envelope without a content hash is rejected', ($r12['ok'] ?? false) === false
    && in_array('Release-gate artifact hash is missing or does not match', $r12['blockers'] ?? [], true));

// NOT_REQUIRED mandatory layer is rejected.
$t13 = $importTask($mdFn('notreq'), 'notreq');
$ing->ingestStageResult($t13, [
    'stage' => 'implement', 'task_id' => $t13, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [
        ['name' => 'unit', 'status' => 'NOT_REQUIRED', 'hash' => str_repeat('a', 64)],
        ['name' => 'integration', 'status' => 'PASS', 'hash' => str_repeat('b', 64)],
        ['name' => 'playwright', 'status' => 'PASS', 'hash' => str_repeat('c', 64)],
    ],
], []);
$ing->ingestStageResult($t13, [
    'stage' => 'review', 'task_id' => $t13, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$t13Hash = writeDevGate($root, $repo->getTask($t13), $gitHead, 'notreq-gate.json');
$r13 = $ing->ingestStageResult($t13, [
    'stage' => 'release-gate', 'task_id' => $t13, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/notreq-gate.json', 'hash' => $t13Hash, 'decision' => 'approved'],
], []);
$h->test('NOT_REQUIRED mandatory layer is rejected', ($r13['ok'] ?? false) === false
    && in_array("Required verification 'unit' is NOT_REQUIRED (must be executed and PASS)", $r13['blockers'] ?? [], true));

// Required layer without hashed evidence is rejected.
$t14 = $importTask($mdFn('nohashev'), 'nohashev');
$ing->ingestStageResult($t14, [
    'stage' => 'implement', 'task_id' => $t14, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [
        ['name' => 'unit', 'status' => 'PASS'],
        ['name' => 'integration', 'status' => 'PASS', 'hash' => str_repeat('b', 64)],
        ['name' => 'playwright', 'status' => 'PASS', 'hash' => str_repeat('c', 64)],
    ],
], []);
$ing->ingestStageResult($t14, [
    'stage' => 'review', 'task_id' => $t14, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$t14Hash = writeDevGate($root, $repo->getTask($t14), $gitHead, 'nohashev-gate.json');
$r14 = $ing->ingestStageResult($t14, [
    'stage' => 'release-gate', 'task_id' => $t14, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/nohashev-gate.json', 'hash' => $t14Hash, 'decision' => 'approved'],
], []);
$h->test('required layer without hashed evidence artifact is rejected', ($r14['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $r14['blockers'] ?? [], true));

// Implement without git evidence fails closed.
$t15 = $importTask($mdFn('nogit'), 'nogit');
$r15 = $ing->ingestStageResult($t15, [
    'stage' => 'implement', 'task_id' => $t15, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'verification' => $makeVerif($t15),
], []);
$h->test('implement without git evidence fails closed', ($r15['ok'] ?? false) === false
    && str_contains((string) ($r15['reason'] ?? ''), 'git'));

// A task that never records an implement stage (review-first detour) cannot be
// released: the gate may carry a git SHA but the task has no recorded head.
$t16 = $importTask($mdFn('nogithead'), 'nogithead');
$ing->ingestStageResult($t16, [
    'stage' => 'review', 'task_id' => $t16, 'result' => 'review_required',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$ing->ingestStageResult($t16, [
    'stage' => 'review', 'task_id' => $t16, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$t16Task = $repo->getTask($t16);
$h->test('review-first detour reaches REVIEW_PASSED without git evidence',
    ($t16Task['state'] ?? '') === DevelopmentLifecycle::REVIEW_PASSED
    && (string) ($t16Task['git']['head'] ?? '') === '');
$t16Hash = writeDevGate($root, $t16Task, $gitHead, 'no-head-gate.json');
$r16 = $ing->ingestStageResult($t16, [
    'stage' => 'release-gate', 'task_id' => $t16, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/no-head-gate.json', 'hash' => $t16Hash, 'decision' => 'approved'],
], []);
$h->test('release-gate on a task with no recorded git head is rejected',
    ($r16['ok'] ?? false) === false
    && in_array('Task has no recorded git head; release-gate cannot be verified', $r16['blockers'] ?? [], true));

// A gate artifact declaring the wrong schema is rejected.
$t17 = $importTask($mdFn('bad_schema'), 'badschema');
$driveToReview($t17);
$dir = rtrim($root, '/') . '/gates';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$t17Task = $repo->getTask($t17);
file_put_contents($dir . '/bad-schema-gate.json', json_encode([
    'schema' => 'ark.some-other-schema.v1',
    'task_id' => $t17Task['task_id'],
    'contract_revision' => $t17Task['contract_revision'],
    'git_sha' => $gitHead,
    'decision' => 'approved',
    'checks' => [
        ['name' => 'unit', 'status' => 'PASS'],
        ['name' => 'integration', 'status' => 'PASS'],
        ['name' => 'playwright', 'status' => 'PASS'],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$r17 = $ing->ingestStageResult($t17, [
    'stage' => 'release-gate', 'task_id' => $t17, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => [
        'artifact' => 'gates/bad-schema-gate.json',
        'hash' => hash('sha256', (string) file_get_contents($dir . '/bad-schema-gate.json')),
        'decision' => 'approved',
    ],
], []);
$h->test('gate artifact with an invalid schema is rejected', ($r17['ok'] ?? false) === false
    && in_array('Release-gate artifact schema is missing or invalid', $r17['blockers'] ?? [], true));

// A gate whose artifact marks a required layer NOT_REQUIRED is rejected.
$t18 = $importTask($mdFn('gate_notreq'), 'gatenotreq');
$driveToReview($t18);
$t18Hash = writeDevGate($root, $repo->getTask($t18), $gitHead, 'gate-notreq.json', 'approved', [
    ['name' => 'unit', 'status' => 'NOT_REQUIRED'],
    ['name' => 'integration', 'status' => 'PASS'],
    ['name' => 'playwright', 'status' => 'PASS'],
]);
$r18 = $ing->ingestStageResult($t18, [
    'stage' => 'release-gate', 'task_id' => $t18, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/gate-notreq.json', 'hash' => $t18Hash, 'decision' => 'approved'],
], []);
$h->test('gate artifact marking a mandatory layer NOT_REQUIRED is rejected',
    ($r18['ok'] ?? false) === false
    && in_array("Gate mandatory check 'unit' is not PASS", $r18['blockers'] ?? [], true));

// The recorded gate artifact is re-verified from disk: tampering after ingest
// blocks a subsequent release re-verification.
$t19 = $importTask($mdFn('tamper'), 'tamper');
$driveToReview($t19);
$t19Hash = writeDevGate($root, $repo->getTask($t19), $gitHead, 'tamper-gate.json');
$r19a = $ing->ingestStageResult($t19, [
    'stage' => 'release-gate', 'task_id' => $t19, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/tamper-gate.json', 'hash' => $t19Hash, 'decision' => 'approved'],
], []);
$h->test('bound gate reaches READY_FOR_RELEASE', ($r19a['ok'] ?? false) === true
    && ($r19a['state'] ?? '') === DevelopmentLifecycle::READY_FOR_RELEASE);
file_put_contents($root . '/gates/tamper-gate.json', '{"tampered":true}');
$t19Blockers = DevelopmentLifecycle::releaseBlockers($repo->getTask($t19));
$h->test('modifying the gate artifact after ingest blocks release re-verification',
    in_array('Release-gate artifact is missing or its content hash does not match', $t19Blockers, true));

$h->section('Git evidence verification (P1)');
// A fake head cannot satisfy gate binding: it fails closed at ingest.
$taskG1 = $importTask($mdFn('fakehead'), 'fakehead');
$rG1 = $ing->ingestStageResult($taskG1, [
    'stage' => 'implement', 'task_id' => $taskG1, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => str_repeat('c', 40), 'changed_paths' => ['src/a.php']],
], []);
$h->test('fake git head fails closed and never records the task',
    ($rG1['ok'] ?? false) === false
    && str_contains(implode(' ', $rG1['errors'] ?? []), 'does not match repository HEAD'));

// A fabricated in-scope path that was never changed fails closed.
$taskG2 = $importTask($mdFn('fakepath'), 'fakepath');
$rG2 = $ing->ingestStageResult($taskG2, [
    'stage' => 'implement', 'task_id' => $taskG2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/never-changed.php']],
], []);
$h->test('fabricated claimed path fails closed',
    ($rG2['ok'] ?? false) === false
    && str_contains(implode(' ', $rG2['errors'] ?? []), 'is not present in the Git working-tree changes'));

// The core attack: the repo actually changed an out-of-scope file but the
// envelope claims only in-scope paths. Scope review uses the RESOLVED set, so
// the hidden out-of-scope change surfaces as unexpected scope.
$taskG3 = $importTask($mdFn('hidden'), 'hidden');
[$g3Dir, $g3Git, $g3Head] = $makeGit([], ['src/a.php', 'modules/unrelated/other.php']);
$dirtyTask($g3Dir, ['src/a.php', 'modules/unrelated/other.php']);
$g3Ing = new DevelopmentArtifactIngestor($repo, $g3Git, $root);
$rG3 = $g3Ing->ingestStageResult($taskG3, [
    'stage' => 'implement', 'task_id' => $taskG3, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $g3Head, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskG3),
], []);
$tG3 = $repo->getTask($taskG3);
$g3Unexpected = array_values(array_filter(
    $tG3['actual_scope'] ?? [],
    static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'
));
$h->test('hidden out-of-scope git change surfaces as unexpected scope',
    ($tG3['state'] ?? '') === DevelopmentLifecycle::REVIEW_REQUIRED
    && in_array('modules/unrelated/other.php', array_map(
        static fn(array $e): string => (string) ($e['path'] ?? ''),
        $g3Unexpected
    ), true));
$h->test('task stores the Git-resolved head and paths, not the claim',
    ($tG3['git']['head'] ?? '') === $g3Head
    && in_array('modules/unrelated/other.php', $tG3['git']['changed_paths'] ?? [], true)
    && ($tG3['git']['head'] ?? '') !== str_repeat('b', 40));

// A repository-unavailable environment fails closed: unverifiable claims are
// never recorded as evidence.
$taskG4 = $importTask($mdFn('nogit2'), 'nogit2');
$noGitRoot = sys_get_temp_dir() . '/devcp-no-git-' . bin2hex(random_bytes(4));
$g4Ing = new DevelopmentArtifactIngestor($repo, new GitEvidenceResolver($noGitRoot), $root);
$rG4 = $g4Ing->ingestStageResult($taskG4, [
    'stage' => 'implement', 'task_id' => $taskG4, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
], []);
$h->test('implement with an unavailable git repository fails closed',
    ($rG4['ok'] ?? false) === false
    && str_contains(implode(' ', $rG4['errors'] ?? []), 'Git repository is not available'));

$h->section('Verification artifact hashing (P1)');
// Bare caller-supplied hash strings (the repeated a/b/c values) cannot certify
// fabricated unit/integration/playwright passes: without a real artifact they
// are stored unverified and block release.
$taskH1 = $importTask($mdFn('barehash'), 'barehash');
$ing->ingestStageResult($taskH1, [
    'stage' => 'implement', 'task_id' => $taskH1, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [
        ['name' => 'unit', 'status' => 'PASS', 'hash' => str_repeat('a', 64)],
        ['name' => 'integration', 'status' => 'PASS', 'hash' => str_repeat('b', 64)],
        ['name' => 'playwright', 'status' => 'PASS', 'hash' => str_repeat('c', 64)],
    ],
], []);
$ing->ingestStageResult($taskH1, [
    'stage' => 'review', 'task_id' => $taskH1, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$h1GateHash = writeDevGate($root, $repo->getTask($taskH1), $gitHead, 'bare-hash-gate.json');
$rH1 = $ing->ingestStageResult($taskH1, [
    'stage' => 'release-gate', 'task_id' => $taskH1, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/bare-hash-gate.json', 'hash' => $h1GateHash, 'decision' => 'approved'],
], []);
$h->test('bare caller-supplied verification hashes cannot unlock release', ($rH1['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rH1['blockers'] ?? [], true));
$h1Persisted = [];
foreach (($repo->getTask($taskH1)['verification']['layers'] ?? []) as $layer) {
    $h1Persisted[(string) ($layer['name'] ?? '')] = $layer;
}
$h->test('caller-supplied hash strings are never stored as evidence',
    ($h1Persisted['unit']['hash'] ?? '') === ''
    && ($h1Persisted['unit']['verified'] ?? false) === false);

// A layer referencing a missing artifact fails closed; other layers must still
// be valid task-bound results.
$taskH2 = $importTask($mdFn('missingart'), 'missingart');
$h2Valid = $makeVerif($taskH2);
$ing->ingestStageResult($taskH2, [
    'stage' => 'implement', 'task_id' => $taskH2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => [
        ['name' => 'unit', 'status' => 'PASS', 'path' => 'test_results/does-not-exist.json'],
        $h2Valid[1],
        $h2Valid[2],
    ],
], []);
$ing->ingestStageResult($taskH2, [
    'stage' => 'review', 'task_id' => $taskH2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
], []);
$h2GateHash = writeDevGate($root, $repo->getTask($taskH2), $gitHead, 'missing-art-gate.json');
$rH2 = $ing->ingestStageResult($taskH2, [
    'stage' => 'release-gate', 'task_id' => $taskH2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/missing-art-gate.json', 'hash' => $h2GateHash, 'decision' => 'approved'],
], []);
$h->test('verification layer with a missing artifact blocks release', ($rH2['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rH2['blockers'] ?? [], true));

// Tampering with a verification artifact after ingest blocks release
// re-verification (same guarantees as the gate artifact).
$taskH3 = $importTask($mdFn('vparttamper'), 'vparttamper');
$driveToReview($taskH3);
$h3GateHash = writeDevGate($root, $repo->getTask($taskH3), $gitHead, 'vp-tamper-gate.json');
$rH3 = $ing->ingestStageResult($taskH3, [
    'stage' => 'release-gate', 'task_id' => $taskH3, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/vp-tamper-gate.json', 'hash' => $h3GateHash, 'decision' => 'approved'],
], []);
$h->test('artifact-backed verification reaches READY_FOR_RELEASE', ($rH3['ok'] ?? false) === true
    && ($rH3['state'] ?? '') === DevelopmentLifecycle::READY_FOR_RELEASE);
$h3UnitAbs = $root . '/evidence/' . $taskH3 . '/test_results/unit.json';
file_put_contents($h3UnitAbs, "tampered\n");
$h3Blockers = DevelopmentLifecycle::releaseBlockers($repo->getTask($taskH3));
$h->test('tampering a verification artifact after ingest blocks release re-verification',
    in_array("Required verification 'unit' artifact is not a valid task-bound result: artifact content hash does not match", $h3Blockers, true));
$makeVerif($taskH3); // restore the task-bound result artifact

$h->section('Git working-tree stability (P1-1)');
// After a task reaches READY_FOR_RELEASE, later uncommitted changes must be
// caught at release re-verification even though the recorded HEAD SHA matches.
$taskS = $importTask($mdFn('stability'), 'stability');
$driveToReview($taskS);
$sGateHash = writeDevGate($root, $repo->getTask($taskS), $gitHead, 'stability-gate.json');
$rS = $ing->ingestStageResult($taskS, [
    'stage' => 'release-gate', 'task_id' => $taskS, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/stability-gate.json', 'hash' => $sGateHash, 'decision' => 'approved'],
], []);
$h->test('stable working tree reaches READY_FOR_RELEASE', ($rS['ok'] ?? false) === true
    && ($rS['state'] ?? '') === DevelopmentLifecycle::READY_FOR_RELEASE);
// New uncommitted (out-of-scope) file after implementation -> path drift.
@mkdir($gitDir . '/modules/unrelated', 0775, true);
file_put_contents($gitDir . '/modules/unrelated/late.php', "late\n");
$sBlockers = DevelopmentLifecycle::releaseBlockers($repo->getTask($taskS), $gitResolver);
$h->test('later uncommitted out-of-scope file blocks release re-verification',
    in_array('Working-tree changed paths have drifted since implementation was recorded', $sBlockers, true));
unlink($gitDir . '/modules/unrelated/late.php');
@rmdir($gitDir . '/modules/unrelated');
// Content drift of an already-changed file -> fingerprint drift.
file_put_contents($gitDir . '/src/a.php', "src/a.php content DRIFTED\n");
$sBlockers2 = DevelopmentLifecycle::releaseBlockers($repo->getTask($taskS), $gitResolver);
$h->test('content drift of a changed file blocks release re-verification',
    in_array('Working-tree content fingerprint has changed since implementation was recorded', $sBlockers2, true));
file_put_contents($gitDir . '/src/a.php', "src/a.php content\n");
$sBlockers3 = DevelopmentLifecycle::releaseBlockers($repo->getTask($taskS), $gitResolver);
$h->test('restoring the working tree clears release re-verification blockers', $sBlockers3 === []);
// An unavailable git environment (e.g. a web worker that cannot run git) must
// not fabricate drift blockers — it reports unverifiable, never a false block.
$noRepo = sys_get_temp_dir() . '/devcp-no-repo-' . bin2hex(random_bytes(4));
$sBlockers4 = DevelopmentLifecycle::releaseBlockers($repo->getTask($taskS), new GitEvidenceResolver($noRepo));
$h->test('an unavailable git environment does not fabricate drift blockers',
    !in_array('Working-tree changed paths have drifted since implementation was recorded', $sBlockers4, true)
    && !in_array('Working-tree content fingerprint has changed since implementation was recorded', $sBlockers4, true));

$h->section('Task-bound verification artifacts (P1-2)');
/** Drive implement + review + gate and return the release result. */
$tryRelease = static function (string $tid, array $layers, string $gateName) use ($ing, $actor, $gitBase, $gitHead, $root, $repo): array {
    $ing->ingestStageResult($tid, [
        'stage' => 'implement', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
        'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
        'verification' => $layers,
    ], []);
    $ing->ingestStageResult($tid, [
        'stage' => 'review', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    ], []);
    $hash = writeDevGate($root, $repo->getTask($tid), $gitHead, $gateName);

    return $ing->ingestStageResult($tid, [
        'stage' => 'release-gate', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
        'release_gate' => ['artifact' => 'gates/' . $gateName, 'hash' => $hash, 'decision' => 'approved'],
    ], []);
};
$layerReason = static function (string $tid, string $name) use ($repo): string {
    foreach (($repo->getTask($tid)['verification']['layers'] ?? []) as $l) {
        if (($l['name'] ?? '') === $name) {
            return (string) ($l['reason'] ?? '');
        }
    }

    return '';
};
// An arbitrary non-result file cannot certify a PASS.
$taskA = $importTask($mdFn('arbitrary'), 'arbitrary');
$aLayers = $makeVerif($taskA);
file_put_contents($root . '/evidence/' . $taskA . '/test_results/unit.json', "not a result\n");
$rA = $tryRelease($taskA, $aLayers, 'arb-gate.json');
$h->test('arbitrary non-result file cannot certify a verification layer',
    ($rA['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rA['blockers'] ?? [], true)
    && str_contains($layerReason($taskA, 'unit'), 'not a valid JSON result'));
// A valid result bound to another task cannot certify this task.
$taskW = $importTask($mdFn('wrongbind'), 'wrongbind');
// Artifacts bound to another task id; contract revision/head/fingerprint match
// the real task so the ONLY failure is the task binding.
$rW = $tryRelease($taskW, $makeVerif('other-task-id', [
    'contract_revision' => (string) ($repo->getTask($taskW)['contract_revision'] ?? ''),
    'head' => $gitHead,
    'fingerprint' => (string) ($gitResolver->workingTreeFingerprint() ?? ''),
]), 'wrong-bind-gate.json');
$h->test('artifact bound to another task cannot certify a layer',
    ($rW['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rW['blockers'] ?? [], true)
    && str_contains($layerReason($taskW, 'unit'), 'is bound to task other-task-id'));
// A failing result cannot certify a PASS.
$taskF2 = $importTask($mdFn('failresult'), 'failresult');
$fLayers = $makeVerif($taskF2);
$fAbs = $root . '/evidence/' . $taskF2 . '/test_results/unit.json';
file_put_contents($fAbs, json_encode([
    'schema' => 'ark.workbench-test-result.v1',
    'task_id' => $taskF2,
    'summary' => ['passed' => 9, 'failed' => 1, 'total' => 10, 'exit_code' => 1],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$rF2 = $tryRelease($taskF2, $fLayers, 'fail-result-gate.json');
$h->test('a failing test result cannot certify a PASS layer',
    ($rF2['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rF2['blockers'] ?? [], true)
    && str_contains($layerReason($taskF2, 'unit'), 'failed test'));
// A valid result without task binding cannot certify a PASS.
$taskM = $importTask($mdFn('nobind'), 'nobind');
$mLayers = $makeVerif($taskM);
$mAbs = $root . '/evidence/' . $taskM . '/test_results/unit.json';
file_put_contents($mAbs, json_encode([
    'schema' => 'ark.workbench-test-result.v1',
    'summary' => ['passed' => 10, 'failed' => 0, 'total' => 10, 'exit_code' => 0],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$rM = $tryRelease($taskM, $mLayers, 'no-bind-gate.json');
$h->test('an artifact without task binding cannot certify a layer',
    ($rM['ok'] ?? false) === false
    && in_array("Required verification 'unit' has no hashed evidence artifact", $rM['blockers'] ?? [], true)
    && str_contains($layerReason($taskM, 'unit'), 'not bound to a task'));

$h->section('Baseline separation (P1-3)');
// Git-captured baseline: pre-existing dirty .github/AGENTS.md at import is
// baseline, never task scope.
$mdBase = $mdFn('baseline');
[$bDir, $bGit, $bHead] = $makeGit(['.github/AGENTS.md'], ['src/a.php']);
$bIng = new DevelopmentArtifactIngestor($repo, $bGit, $root);
$bRes = $bIng->importArchitecture($mdBase, $actor, ['source_path' => '.ai/scenario-baseline.md']);
$bTid = (string) ($bRes['task_id'] ?? '');
$h->test('git-captured baseline records pre-existing dirty paths',
    in_array('.github/AGENTS.md', $repo->getTask($bTid)['baseline']['changed_paths'] ?? [], true));
$dirtyTask($bDir, ['src/a.php']);
$bR = $bIng->ingestStageResult($bTid, [
    'stage' => 'implement', 'task_id' => $bTid, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $bHead, 'changed_paths' => ['src/a.php', '.github/AGENTS.md']],
    'verification' => $makeVerif($bTid),
], []);
$bAfter = $repo->getTask($bTid);
$bUnexp = array_values(array_filter($bAfter['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'));
$h->test('baseline files are not classified as unexpected task scope',
    ($bAfter['state'] ?? '') === DevelopmentLifecycle::READY_FOR_REVIEW
    && $bUnexp === []
    && !in_array('.github/AGENTS.md', $bAfter['git']['changed_paths'] ?? [], true));
$h->test('baseline files are retained separately on the task',
    in_array('.github/AGENTS.md', $bAfter['git']['baseline_changed_paths'] ?? [], true));
// Declared Baseline heading wins over git capture and covers a directory prefix.
$mdDeclared = $mdFn('declared') . "\n## Baseline\n- .github/\n";
[$dDir, $dGit, $dHead] = $makeGit(['.github/AGENTS.md'], ['src/a.php']);
$dIng = new DevelopmentArtifactIngestor($repo, $dGit, $root);
$dRes = $dIng->importArchitecture($mdDeclared, $actor, ['source_path' => '.ai/scenario-declared.md']);
$dTid = (string) ($dRes['task_id'] ?? '');
$h->test('declared Baseline heading is used for the task baseline',
    in_array('.github', $repo->getTask($dTid)['baseline']['changed_paths'] ?? [], true));
$dirtyTask($dDir, ['src/a.php']);
$dR = $dIng->ingestStageResult($dTid, [
    'stage' => 'implement', 'task_id' => $dTid, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $dHead, 'changed_paths' => ['src/a.php', '.github/AGENTS.md']],
    'verification' => $makeVerif($dTid),
], []);
$dAfter = $repo->getTask($dTid);
$dUnexp = array_values(array_filter($dAfter['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'));
$h->test('declared directory baseline covers dirty files beneath it',
    ($dAfter['state'] ?? '') === DevelopmentLifecycle::READY_FOR_REVIEW
    && $dUnexp === []
    && !in_array('.github/AGENTS.md', $dAfter['git']['changed_paths'] ?? [], true));

$h->section('Release fails closed without git re-verification (P1-1)');
// A task with recorded git evidence cannot reach READY_FOR_RELEASE via a direct
// transition when no resolver is supplied (authoritative strict mode).
$taskFc = $importTask($mdFn('failclosed'), 'failclosed');
$driveToReview($taskFc);
$fcGateHash = writeDevGate($root, $repo->getTask($taskFc), $gitHead, 'failclosed-gate.json');
$fcTask = $repo->getTask($taskFc);
$fcTask['release'] = [
    'gate_artifact' => realpath($root . '/gates/failclosed-gate.json'),
    'gate_hash' => $fcGateHash,
    'decision' => 'approved',
    'blockers' => [],
    'verified_gate' => true,
];
$fcBlockers = DevelopmentLifecycle::releaseBlockers($fcTask, null);
$h->test('authoritative release requires a git resolver',
    in_array('Git re-verification is required at release but no resolver is available', $fcBlockers, true));
$noRepo2 = sys_get_temp_dir() . '/devcp-no-repo2-' . bin2hex(random_bytes(4));
$fcBlockers2 = DevelopmentLifecycle::releaseBlockers($fcTask, new GitEvidenceResolver($noRepo2));
$h->test('authoritative release fails closed when git is unavailable',
    in_array('Git repository is not available to re-verify the working tree', $fcBlockers2, true));
$fcBlockers3 = DevelopmentLifecycle::releaseBlockers($fcTask, new GitEvidenceResolver($noRepo2), false);
$h->test('informational display does not fabricate git-unavailable blockers',
    !in_array('Git repository is not available to re-verify the working tree', $fcBlockers3, true)
    && !in_array('Git re-verification is required at release but no resolver is available', $fcBlockers3, true));
$h->test('verifyStableState strict fails closed on an unavailable repository',
    (new GitEvidenceResolver($noRepo2))->verifyStableState($fcTask, true)['ok'] === false);
$h->test('verifyStableState informational is unverifiable, not a block',
    (new GitEvidenceResolver($noRepo2))->verifyStableState($fcTask, false)['ok'] === true);

// HEAD can remain readable while a corrupt index makes status/fingerprint
// resolution impossible. Strict release revalidation must reject that state.
[$badIdxDir, $badIdxGit, $badIdxHead] = $makeGit([], ['src/a.php']);
$dirtyTask($badIdxDir, ['src/a.php']);
$badIdxTask = ['git' => [
    'head' => $badIdxHead,
    'changed_paths' => ['src/a.php'],
    'baseline_changed_paths' => [],
    'fingerprint' => $badIdxGit->workingTreeFingerprint(),
]];
file_put_contents($badIdxDir . '/.git/index', 'corrupt-index');
$badIdxStable = $badIdxGit->verifyStableState($badIdxTask, true);
$h->test('strict stability verification fails closed when changed paths cannot resolve',
    ($badIdxStable['ok'] ?? true) === false
    && ($badIdxStable['unverifiable'] ?? false) === true
    && in_array('Unable to resolve changed paths to re-verify the working tree', $badIdxStable['errors'] ?? [], true));

// Staging changes the index blob without changing HEAD, paths, or worktree bytes.
[$stageDir, $stageGit, $stageHead] = $makeGit([], ['src/a.php']);
$dirtyTask($stageDir, ['src/a.php']);
$stageTask = ['git' => [
    'head' => $stageHead,
    'changed_paths' => ['src/a.php'],
    'baseline_changed_paths' => [],
    'fingerprint' => $stageGit->workingTreeFingerprint(),
]];
$h->test('checkout fingerprint is initially stable before index-only drift',
    $stageGit->verifyStableState($stageTask, true)['ok'] === true);
$runGit($stageDir, ['add', '--', 'src/a.php']);
$stageDrift = $stageGit->verifyStableState($stageTask, true);
$h->test('staged index-only drift changes the checkout fingerprint',
    ($stageDrift['ok'] ?? true) === false
    && in_array('Working-tree content fingerprint has changed since implementation was recorded', $stageDrift['errors'] ?? [], true));

$h->section('Verification artifact bindings (P1-2)');
$bindCases = [
    'schema' => ['schema' => 'ark.wrong-schema.v1', 'expect' => 'does not declare the result schema'],
    'revision' => ['contract_revision' => str_repeat('f', 16), 'expect' => 'contract_revision'],
    'head' => ['git_head' => str_repeat('c', 40), 'expect' => 'artifact Git HEAD does not match'],
    'fingerprint' => ['fingerprint' => str_repeat('d', 64), 'expect' => 'artifact working-tree fingerprint does not match'],
    'runner' => ['runner' => '', 'expect' => 'does not declare a runner identity'],
    'layer' => ['layer' => 'integration', 'expect' => 'does not certify the unit layer'],
    'summary' => ['summary' => ['passed' => 6, 'failed' => 0, 'skipped' => 0, 'total' => 10, 'exit_code' => 0], 'expect' => 'counts are inconsistent'],
];
foreach ($bindCases as $label => $case) {
    $taskId = $importTask($mdFn('bind-' . $label), 'bind' . $label);
    $layers = $makeVerif($taskId);
    $abs = $root . '/evidence/' . $taskId . '/test_results/unit.json';
    $base = json_decode((string) file_get_contents($abs), true);
    file_put_contents($abs, json_encode(array_merge($base, ['summary' => ['passed' => 10, 'failed' => 0, 'skipped' => 0, 'total' => 10, 'exit_code' => 0]], $case), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $r = $tryRelease($taskId, $layers, 'bind-' . $label . '-gate.json');
    $h->test("artifact with invalid {$label} binding cannot certify a layer",
        ($r['ok'] ?? false) === false
        && str_contains($layerReason($taskId, 'unit'), (string) $case['expect']));
}

// Public task/Git bindings are not a trust anchor. A caller that echoes every
// correct field but cannot produce the configured runner attestation must fail.
$taskUnsigned = $importTask($mdFn('unsigned-echo'), 'unsignedecho');
$unsignedLayers = $makeVerif($taskUnsigned);
$unsignedAbs = $root . '/evidence/' . $taskUnsigned . '/test_results/unit.json';
$unsignedArtifact = json_decode((string) file_get_contents($unsignedAbs), true);
$unsignedArtifact['signature'] = str_repeat('0', 64);
file_put_contents($unsignedAbs, json_encode($unsignedArtifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$unsignedResult = $tryRelease($taskUnsigned, $unsignedLayers, 'unsigned-echo-gate.json');
$h->test('caller-authored result with copied public bindings cannot certify a layer',
    ($unsignedResult['ok'] ?? false) === false
    && str_contains($layerReason($taskUnsigned, 'unit'), 'signature does not match the trusted runner attestation'));

// Missing verifier configuration also fails closed; the artifact signature is
// never accepted merely because it is structurally valid.
$taskNoKey = $importTask($mdFn('missing-attestation-key'), 'missingattestationkey');
$noKeyLayers = $makeVerif($taskNoKey);
unset($_ENV['WORKBENCH_EVIDENCE_HMAC_KEY']);
putenv('WORKBENCH_EVIDENCE_HMAC_KEY');
$noKeyResult = $tryRelease($taskNoKey, $noKeyLayers, 'missing-attestation-key-gate.json');
$h->test('missing trusted attestation key fails closed',
    ($noKeyResult['ok'] ?? false) === false
    && str_contains($layerReason($taskNoKey, 'unit'), 'trusted verification attestation key is not configured'));
putenv('WORKBENCH_EVIDENCE_HMAC_KEY=' . $evidenceKey);
$_ENV['WORKBENCH_EVIDENCE_HMAC_KEY'] = $evidenceKey;

$h->section('Baseline drift detection (P1-3)');
// A baseline file modified between import and implementation is NOT silently
// blessed — it enters task scope and forces review.
[$bdDir, $bdGit, $bdHead] = $makeGit(['.github/AGENTS.md'], ['src/a.php']);
$bdIng = new DevelopmentArtifactIngestor($repo, $bdGit, $root);
$bdRes = $bdIng->importArchitecture($mdFn('baselinedrift'), $actor, ['source_path' => '.ai/scenario-baselinedrift.md']);
$bdTid = (string) ($bdRes['task_id'] ?? '');
$h->test('baseline content is captured at import',
    ($repo->getTask($bdTid)['baseline']['hashes']['.github/AGENTS.md'] ?? '') !== '');
$dirtyTask($bdDir, ['src/a.php']);
// Modify the baseline file AFTER import (the attack the reviewer describes).
file_put_contents($bdDir . '/.github/AGENTS.md', ".github/AGENTS.md baseline content MODIFIED\n");
$bdR = $bdIng->ingestStageResult($bdTid, [
    'stage' => 'implement', 'task_id' => $bdTid, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $bdHead, 'changed_paths' => ['src/a.php', '.github/AGENTS.md']],
    'verification' => $makeVerif($bdTid),
], []);
$bdAfter = $repo->getTask($bdTid);
$bdUnexp = array_values(array_filter($bdAfter['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'));
$h->test('a baseline file modified after import enters task scope',
    ($bdAfter['state'] ?? '') === DevelopmentLifecycle::REVIEW_REQUIRED
    && in_array('.github/AGENTS.md', array_map(static fn(array $e): string => (string) ($e['path'] ?? ''), $bdUnexp), true));
$h->test('baseline drift is recorded on the task',
    in_array('.github/AGENTS.md', $bdAfter['git']['baseline_drifted'] ?? [], true));

// Baseline identity includes the index, not only working-tree bytes. Staging an
// existing baseline edit after import changes release content and must enter scope.
[$biDir, $biGit, $biHead] = $makeGit(['.github/AGENTS.md'], ['src/a.php']);
$biIng = new DevelopmentArtifactIngestor($repo, $biGit, $root);
$biRes = $biIng->importArchitecture($mdFn('baseline-index-drift'), $actor, ['source_path' => '.ai/scenario-baseline-index-drift.md']);
$biTid = (string) ($biRes['task_id'] ?? '');
$runGit($biDir, ['add', '--', '.github/AGENTS.md']);
$dirtyTask($biDir, ['src/a.php']);
$biIng->ingestStageResult($biTid, [
    'stage' => 'implement', 'task_id' => $biTid, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $biHead, 'changed_paths' => ['src/a.php', '.github/AGENTS.md']],
    'verification' => $makeVerif($biTid),
], []);
$biAfter = $repo->getTask($biTid);
$h->test('index-only baseline drift enters task scope',
    ($biAfter['state'] ?? '') === DevelopmentLifecycle::REVIEW_REQUIRED
    && in_array('.github/AGENTS.md', $biAfter['git']['baseline_drifted'] ?? [], true));

// A declared directory is only shorthand for the concrete dirty files captured
// at import. New and formerly-clean descendants are implementation changes.
foreach (['new' => false, 'previously-clean' => true] as $label => $trackedAtImport) {
    $extra = '.github/' . $label . '.md';
    $taskPaths = array_merge(['src/a.php'], $trackedAtImport ? [$extra] : []);
    [$dirBase, $gitBaseResolver, $headBase] = $makeGit(['.github/AGENTS.md'], $taskPaths);
    $ingBase = new DevelopmentArtifactIngestor($repo, $gitBaseResolver, $root);
    $resBase = $ingBase->importArchitecture(
        $mdFn('directory-baseline-' . $label) . "\n## Baseline\n- .github/\n",
        $actor,
        ['source_path' => '.ai/scenario-directory-baseline-' . $label . '.md']
    );
    $tidBase = (string) ($resBase['task_id'] ?? '');
    $dirtyTask($dirBase, ['src/a.php', $extra]);
    $ingBase->ingestStageResult($tidBase, [
        'stage' => 'implement', 'task_id' => $tidBase, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
        'git' => ['base' => $gitBase, 'head' => $headBase, 'changed_paths' => ['src/a.php', '.github/AGENTS.md', $extra]],
        'verification' => $makeVerif($tidBase),
    ], []);
    $afterBase = $repo->getTask($tidBase);
    $unexpectedBase = array_map(
        static fn(array $e): string => (string) ($e['path'] ?? ''),
        array_values(array_filter($afterBase['actual_scope'] ?? [], static fn(array $e): bool => ($e['status'] ?? '') === 'unexpected'))
    );
    $h->test("declared directory baseline does not hide {$label} descendants",
        ($afterBase['state'] ?? '') === DevelopmentLifecycle::REVIEW_REQUIRED
        && in_array($extra, $unexpectedBase, true)
        && !in_array($extra, $afterBase['git']['baseline_changed_paths'] ?? [], true));
}

$h->section('Scope precedence');
$h->test('forbidden scope wins over allowed on overlap',
    ($ing->classifyScope(
        ['allowed' => [['path' => 'src/a.php', 'kind' => 'file']], 'forbidden' => [['path' => 'src', 'kind' => 'directory']]],
        ['src/a.php']
    )['unexpected'] ?? []) === ['src/a.php' => true]);

$h->section('Corrupt index');
$idxRoot = $root . '-idx';
$irepo = new DevelopmentTaskRepository($idxRoot);
$irepo->createTask(DevelopmentTaskContract::parseCurrentTaskMarkdown($mdFn('idx')), $actor, []);
file_put_contents($idxRoot . '/index.json', '{not json');
$h->test('corrupt index fails closed instead of reporting an empty ledger',
    (static function () use ($irepo): bool {
        try {
            $irepo->listTasks();
            return false;
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'corrupt');
        }
    })());

$h->section('Architecture revision via import');
$revRes = $ing->importArchitecture($mdFn('rev'), $actor, ['source_path' => '.ai/rev-task.md']);
$revTask = (string) ($revRes['task_id'] ?? '');
$rev1 = (string) ($revRes['revision'] ?? '');
$revAgain = $ing->importArchitecture($mdFn('rev-changed'), $actor, ['source_path' => '.ai/rev-task.md']);
$h->test('changed architecture re-import revises the same task',
    ($revAgain['task_id'] ?? '') === $revTask && ($revAgain['ok'] ?? false) === true);
$h->test('changed architecture creates a new immutable revision via import',
    ($revAgain['revision'] ?? '') !== $rev1 && count($repo->timeline($revTask)) === 2);
$revSame = $ing->importArchitecture($mdFn('rev-changed'), $actor, ['source_path' => '.ai/rev-task.md']);
$h->test('unchanged re-import via the same source path is idempotent',
    ($revSame['idempotent'] ?? false) === true && ($revSame['task_id'] ?? '') === $revTask);

$h->section('Phase 2: evidence linking and citations');
$taskE = $importTask($mdFn('evidence'), 'ev');
$implE = $ing->ingestStageResult($taskE, [
    'stage' => 'implement', 'task_id' => $taskE, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskE),
    'evidence' => [
        ['kind' => 'observation', 'ref' => 'obs:checkout', 'hash' => 'aa11'],
        ['kind' => 'browser_artifact', 'ref' => 'browser:checkout-01', 'hash' => 'bb22'],
        ['kind' => 'issue_ledger', 'ref' => 'issue:ledger-7', 'hash' => 'cc33'],
    ],
], []);
$taskEPost = $repo->getTask($taskE);
$h->test('implement envelope persists a durable evidence projection',
    ($implE['ok'] ?? false) === true
    && count($taskEPost['evidence'] ?? []) === 3
    && ($taskEPost['evidence'][0]['ref'] ?? '') === 'obs:checkout');
$h->test('evidence citations resolve by id via the repository',
    ($repo->resolveEvidence($taskE, 'browser:checkout-01')['kind'] ?? '') === 'browser_artifact'
    && $repo->resolveEvidence($taskE, 'missing:ref') === null);

$taskR = $importTask($mdFn('evidence-review'), 'evr');
$ing->ingestStageResult($taskR, [
    'stage' => 'implement', 'task_id' => $taskR, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskR),
    'evidence' => [['kind' => 'observation', 'ref' => 'obs:real', 'hash' => 'dd44']],
], []);
$rReviewBad = $ing->ingestStageResult($taskR, [
    'stage' => 'review', 'task_id' => $taskR, 'result' => 'changes_required',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P1', 'summary' => 'cites nothing real', 'evidence_refs' => ['obs:ghost']],
    ],
], []);
$h->test('review finding citing absent evidence is rejected (fail closed)',
    ($rReviewBad['ok'] ?? false) === false
    && in_array('unresolved evidence citation: obs:ghost', $rReviewBad['errors'] ?? [], true));
$rReviewGood = $ing->ingestStageResult($taskR, [
    'stage' => 'review', 'task_id' => $taskR, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P2', 'summary' => 'cites real evidence', 'evidence_refs' => ['obs:real']],
    ],
], []);
$rFindings = $repo->getTask($taskR)['review']['findings'] ?? [];
$h->test('review finding citing present evidence is accepted and persists refs',
    ($rReviewGood['ok'] ?? false) === true
    && ($rFindings[0]['evidence_refs'] ?? []) === ['obs:real']
    && ($rFindings[0]['classification'] ?? 'normal') === 'normal');

$h->section('Phase 3: condition and blocked gate decisions are recorded');
// Each gate scenario uses its own task so the negative decisions are recorded
// on independent, audit-clean tasks rather than stacking states on one task.
$driveToReviewPassed = static function (string $tid, array $evidence = []) use ($ing, $actor, $gitBase, $gitHead, $makeVerif): void {
    $implement = [
        'stage' => 'implement', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
        'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
        'verification' => $makeVerif($tid),
    ];
    if ($evidence !== []) {
        $implement['evidence'] = $evidence;
    }
    $ing->ingestStageResult($tid, $implement, []);
    $ing->ingestStageResult($tid, [
        'stage' => 'review', 'task_id' => $tid, 'result' => 'passed',
        'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    ], []);
};

$taskC = $importTask($mdFn('condition'), 'cond');
$driveToReviewPassed($taskC, [['kind' => 'observation', 'ref' => 'obs:staging', 'hash' => 'ab12']]);
$condGateHash = writeDevGate($root, $repo->getTask($taskC), $gitHead, 'cond-gate.json', 'condition', null, [
    ['id' => 'c1', 'description' => 're-run on staging', 'owner' => 'sre', 'evidence_ref' => 'obs:staging', 'resolved' => false],
]);
$rCond = $ing->ingestStageResult($taskC, [
    'stage' => 'release-gate', 'task_id' => $taskC, 'result' => 'blocked',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/cond-gate.json', 'hash' => $condGateHash, 'decision' => 'condition'],
], []);
$condTask = $repo->getTask($taskC);
$h->test('condition gate is a recorded decision that lands in RELEASE_BLOCKED',
    ($rCond['ok'] ?? false) === true
    && $condTask['state'] === DevelopmentLifecycle::RELEASE_BLOCKED
    && ($condTask['release']['decision'] ?? '') === 'condition'
    && ($condTask['release']['verified_gate'] ?? false) === true
    && ($condTask['release']['conditions'][0]['owner'] ?? '') === 'sre'
    && ($condTask['release']['conditions'][0]['evidence_ref'] ?? '') === 'obs:staging');

$taskC2 = $importTask($mdFn('condition-owner'), 'cond2');
$driveToReviewPassed($taskC2);
$badCondHash = writeDevGate($root, $repo->getTask($taskC2), $gitHead, 'cond-bad.json', 'condition', null, [
    ['id' => 'c2', 'description' => 'missing owner', 'evidence_ref' => 'obs:x'],
]);
$rCondBad = $ing->ingestStageResult($taskC2, [
    'stage' => 'release-gate', 'task_id' => $taskC2, 'result' => 'blocked',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/cond-bad.json', 'hash' => $badCondHash, 'decision' => 'condition'],
], []);
$condBadTask = $repo->getTask($taskC2);
$h->test('condition without an owner is not a verified gate (fail closed)',
    ($rCondBad['ok'] ?? false) === true
    && $condBadTask['state'] === DevelopmentLifecycle::RELEASE_BLOCKED
    && ($condBadTask['release']['verified_gate'] ?? false) === false
    && in_array('Gate condition #0 is missing an owner', $condBadTask['release']['blockers'] ?? [], true));

$taskC3 = $importTask($mdFn('blocked'), 'cond3');
$driveToReviewPassed($taskC3);
$blockGateHash = writeDevGate($root, $repo->getTask($taskC3), $gitHead, 'block-gate.json', 'blocked', [
    ['name' => 'unit', 'status' => 'FAIL'],
]);
$rBlock = $ing->ingestStageResult($taskC3, [
    'stage' => 'release-gate', 'task_id' => $taskC3, 'result' => 'blocked',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/block-gate.json', 'hash' => $blockGateHash, 'decision' => 'blocked'],
], []);
$blockTask = $repo->getTask($taskC3);
$h->test('blocked gate decision is recorded as RELEASE_BLOCKED',
    ($rBlock['ok'] ?? false) === true
    && $blockTask['state'] === DevelopmentLifecycle::RELEASE_BLOCKED
    && ($blockTask['release']['decision'] ?? '') === 'blocked'
    && ($blockTask['release']['verified_gate'] ?? false) === true);

$h->section('Phase 3: flaky/environment-only finding governance');
$taskF = $importTask($mdFn('flaky'), 'flk');
$ing->ingestStageResult($taskF, [
    'stage' => 'implement', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskF),
], []);
$ing->ingestStageResult($taskF, [
    'stage' => 'review', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P1', 'summary' => 'flaky cypress test', 'classification' => 'flaky'],
    ],
], []);
$flakyTask = $repo->getTask($taskF);
$flakyFinding0 = $flakyTask['review']['findings'][0] ?? [];
$h->test('flaky finding classification persists on the task',
    ($flakyFinding0['classification'] ?? '') === 'flaky'
    && array_key_exists('verified_reproduction', $flakyFinding0)
    && $flakyFinding0['verified_reproduction'] === null);
$flakyGateHash = writeDevGate($root, $flakyTask, $gitHead, 'flaky-gate.json');
$rFlaky = $ing->ingestStageResult($taskF, [
    'stage' => 'release-gate', 'task_id' => $taskF, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/flaky-gate.json', 'hash' => $flakyGateHash, 'decision' => 'approved'],
], []);
$h->test('flaky finding without verified reproduction does not block release',
    ($rFlaky['ok'] ?? false) === true
    && $repo->getTask($taskF)['state'] === DevelopmentLifecycle::READY_FOR_RELEASE);

$taskR2 = $importTask($mdFn('flaky-repro'), 'flr');
$ing->ingestStageResult($taskR2, [
    'stage' => 'implement', 'task_id' => $taskR2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskR2),
    'evidence' => [['kind' => 'observation', 'ref' => 'obs:repro', 'hash' => 'ee55']],
], []);
$ing->ingestStageResult($taskR2, [
    'stage' => 'review', 'task_id' => $taskR2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P1', 'summary' => 'flaky reproduced', 'classification' => 'flaky', 'verified_reproduction' => 'obs:repro'],
    ],
], []);
$reproGateHash = writeDevGate($root, $repo->getTask($taskR2), $gitHead, 'repro-gate.json');
$rRepro = $ing->ingestStageResult($taskR2, [
    'stage' => 'release-gate', 'task_id' => $taskR2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/repro-gate.json', 'hash' => $reproGateHash, 'decision' => 'approved'],
], []);
$h->test('flaky finding with verified reproduction blocks release',
    ($rRepro['ok'] ?? false) === false
    && in_array('Unresolved blocking review finding: flaky reproduced', $rRepro['blockers'] ?? [], true));

// P2-4: a condition gate must cite evidence that resolves to the task timeline.
$taskC4 = $importTask($mdFn('condition-evidence'), 'cond4');
$driveToReviewPassed($taskC4);
$badCondEvidenceHash = writeDevGate($root, $repo->getTask($taskC4), $gitHead, 'cond-evidence.json', 'condition', null, [
    ['id' => 'c1', 'description' => 'cites ghost evidence', 'owner' => 'sre', 'evidence_ref' => 'obs:ghost-condition'],
]);
$rCondEvidence = $ing->ingestStageResult($taskC4, [
    'stage' => 'release-gate', 'task_id' => $taskC4, 'result' => 'blocked',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/cond-evidence.json', 'hash' => $badCondEvidenceHash, 'decision' => 'condition'],
], []);
$condEvidenceTask = $repo->getTask($taskC4);
$h->test('condition evidence_ref must resolve to task evidence (fail closed)',
    ($rCondEvidence['ok'] ?? false) === true
    && ($condEvidenceTask['release']['verified_gate'] ?? false) === false
    && in_array('Gate condition #0 evidence_ref does not resolve to task evidence: obs:ghost-condition', $condEvidenceTask['release']['blockers'] ?? [], true));
// P2-5: an unverified gate must not persist a fabricated decision on the ledger.
$h->test('unverified gate does not record a decision on the ledger',
    ($condEvidenceTask['release']['decision'] ?? 'x') === '');

// A normal (unclassified) unresolved P0/P1 still blocks release at the gate.
$taskN2 = $importTask($mdFn('normal-p1'), 'norm');
$ing->ingestStageResult($taskN2, [
    'stage' => 'implement', 'task_id' => $taskN2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskN2),
], []);
$ing->ingestStageResult($taskN2, [
    'stage' => 'review', 'task_id' => $taskN2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P1', 'summary' => 'normal unresolved P1'],
    ],
], []);
$normGateHash = writeDevGate($root, $repo->getTask($taskN2), $gitHead, 'normal-gate.json');
$rNorm = $ing->ingestStageResult($taskN2, [
    'stage' => 'release-gate', 'task_id' => $taskN2, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'release_gate' => ['artifact' => 'gates/normal-gate.json', 'hash' => $normGateHash, 'decision' => 'approved'],
], []);
$h->test('a normal unclassified unresolved P1 still blocks release',
    ($rNorm['ok'] ?? false) === false
    && in_array('Unresolved blocking review finding: normal unresolved P1', $rNorm['blockers'] ?? [], true));

// A fabricated verified_reproduction citation is rejected (fail closed).
$taskN3 = $importTask($mdFn('repro-ghost'), 'repro');
$ing->ingestStageResult($taskN3, [
    'stage' => 'implement', 'task_id' => $taskN3, 'result' => 'passed',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'git' => ['base' => $gitBase, 'head' => $gitHead, 'changed_paths' => ['src/a.php']],
    'verification' => $makeVerif($taskN3),
], []);
$rReproGhost = $ing->ingestStageResult($taskN3, [
    'stage' => 'review', 'task_id' => $taskN3, 'result' => 'changes_required',
    'actor' => $actor, 'recorded_at' => gmdate(DATE_ATOM),
    'unresolved_findings' => [
        ['severity' => 'P1', 'summary' => 'fabricated reproduction', 'classification' => 'flaky', 'verified_reproduction' => 'obs:ghost-repro'],
    ],
], []);
$h->test('fabricated verified_reproduction citation is rejected (fail closed)',
    ($rReproGhost['ok'] ?? false) === false
    && in_array('unresolved evidence citation: obs:ghost-repro', $rReproGhost['errors'] ?? [], true));

// A condition/blocked gate can never auto-unlock to READY_FOR_RELEASE.
$h->test('RELEASE_BLOCKED cannot transition directly to READY_FOR_RELEASE',
    DevelopmentLifecycle::canTransition(DevelopmentLifecycle::RELEASE_BLOCKED, DevelopmentLifecycle::READY_FOR_RELEASE) === false);

$h->section('Phase 3: export provenance');
$expRun = ['module' => 'bakeshop', 'issues' => [['fingerprint' => 'f1', 'message' => 'issue', 'category' => 'db', 'severity' => 'major']]];
$expTask = ['task_id' => 'task-exp-1', 'contract_revision' => str_repeat('b', 16), 'state' => 'READY_FOR_RELEASE', 'release' => ['decision' => 'condition', 'verified_gate' => true, 'conditions' => [['id' => 'c1', 'owner' => 'sre', 'resolved' => false]]]];
$exporter = new RunExporter();
$arkExport = json_decode($exporter->ark($expRun, $expTask), true);
$junitExport = $exporter->junit($expRun, $expTask);
$sarifExport = json_decode($exporter->sarif($expRun, $expTask), true);
$h->test('ARK export carries task + release-decision provenance',
    ($arkExport['task']['task_id'] ?? '') === 'task-exp-1'
    && ($arkExport['task']['release']['decision'] ?? '') === 'condition'
    && ($arkExport['task']['release']['conditions'][0]['owner'] ?? '') === 'sre');
$h->test('JUnit export carries task + release-decision properties',
    str_contains($junitExport, 'task_id') && str_contains($junitExport, 'task-exp-1')
    && str_contains($junitExport, 'release_decision') && str_contains($junitExport, 'condition'));
$h->test('SARIF export carries task + release-decision provenance',
    ($sarifExport['runs'][0]['properties']['ark_workbench_task']['release']['decision'] ?? '') === 'condition');
$arkLegacy = json_decode($exporter->ark($expRun), true);
$h->test('legacy single-argument export is unchanged (no task block)',
    !isset($arkLegacy['task']) && ($arkLegacy['schema'] ?? '') === 'ark.workbench-run-export.v1');

// Cleanup.
foreach (glob($root . '*') ?: [] as $d) {
    if (is_dir($d)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    }
}

$h->done();

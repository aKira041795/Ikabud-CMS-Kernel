<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Development artifact ingestor: the normalized boundary between external
 * agents/harnesses (Codex, DeepSeek, Pi, Git, Playwright, CI) and Workbench.
 * Agent text is a claim, not authoritative evidence. Ingestion validates the
 * envelope, redacts sensitive fields, links content-hashed relative artifacts,
 * verifies caller-supplied Git evidence against the repository, resolves and
 * hashes verification artifacts from disk, classifies approved vs actual Git
 * paths, and performs only valid transitions.
 */
final class DevelopmentArtifactIngestor
{
    private const STAGES = ['architect', 'implement', 'review', 'release-gate'];

    private const RESULTS = [
        'passed', 'failed', 'flaky', 'skipped', 'not_required', 'not_run',
        'blocked', 'review_required', 'changes_required', 'architecture_decision_required',
    ];

    /** @var list<string> Keys whose values are always redacted. */
    private const SECRET_KEYS = [
        'api_key', 'apikey', 'key', 'secret', 'token', 'access_token', 'refresh_token',
        'password', 'passwd', 'authorization', 'cookie', 'csrf', 'csrf_token',
        'session', 'session_id', 'credential', 'credentials', 'request_body',
        'chat_transcript', 'raw_chat', 'transcript', 'auth', 'api-key',
    ];

    private readonly GitEvidenceResolver $git;
    private readonly string $evidenceRoot;

    public function __construct(
        private readonly DevelopmentTaskRepository $repo,
        ?GitEvidenceResolver $git = null,
        ?string $evidenceRoot = null
    ) {
        // Default to the application root (a real repository in production/CLI
        // use). When no Git repository is present, implement-stage results fail
        // closed because they cannot be verified.
        $this->git = $git ?? new GitEvidenceResolver(defined('BASE_PATH') ? BASE_PATH : $repo->root());
        // Verification artifacts are repository-relative paths resolved against
        // the application root by default (where test_results/* live).
        $this->evidenceRoot = $evidenceRoot ?? (defined('BASE_PATH') ? BASE_PATH : $repo->root());
    }

    public function evidenceRoot(): string
    {
        return $this->evidenceRoot;
    }

    /**
     * Import architecture from .ai/current-task.md text into a durable task.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function importArchitecture(string $markdown, array $actor, array $options = []): array
    {
        $contract = DevelopmentTaskContract::parseCurrentTaskMarkdown($markdown);
        $sourcePath = (string) ($options['source_path'] ?? '.ai/current-task.md');
        $explicitTaskId = (string) ($options['task_id'] ?? '');

        // Baseline (pre-existing working-tree changes) separates the approved
        // baseline from task-attributable scope. A declared "Baseline" contract
        // section wins; otherwise capture the current dirty paths at import time
        // (the correct workflow imports before implementation begins).
        $baseline = $this->baselineForContract($contract);

        // Explicit task id: revise it if it exists, otherwise create it.
        if ($explicitTaskId !== '') {
            try {
                $this->repo->getTask($explicitTaskId);

                return $this->repo->reviseArchitecture($explicitTaskId, $contract, $actor);
            } catch (\RuntimeException $e) {
                return $this->repo->createTask($contract, $actor, [
                    'source_path' => $sourcePath,
                    'task_id' => $explicitTaskId,
                    'baseline' => $baseline,
                ]);
            }
        }

        // Same source path (e.g. .ai/current-task.md) with changed architecture:
        // revise the existing task into a new immutable revision rather than
        // failing or silently creating a duplicate task.
        $existing = $this->repo->findBySourcePath($sourcePath);
        if ($existing !== null) {
            return $this->repo->reviseArchitecture($existing, $contract, $actor);
        }

        return $this->repo->createTask($contract, $actor, [
            'source_path' => $sourcePath,
            'baseline' => $baseline,
        ]);
    }

    /**
     * Baseline for a contract: declared baseline_scope wins; otherwise the
     * current working-tree dirt captured at import time. Returns the baseline
     * entries (exact/directory, used for prefix matching), the concrete files
     * they cover, and per-file content hashes so implement can detect any
     * baseline file modified between import and implementation (P1-3) instead
     * of silently blessing it as non-task scope.
     *
     * @param array<string,mixed> $contract
     * @return array{changed_paths:list<string>,covered_paths:list<string>,hashes:array<string,string>}
     */
    private function baselineForContract(array $contract): array
    {
        $declared = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['path'] ?? ''),
            (array) ($contract['baseline_scope'] ?? [])
        )));
        $entries = $declared;
        if ($entries === [] && $this->git->isAvailable()) {
            $resolved = $this->git->resolveChangedPaths();
            if ($resolved !== null) {
                $entries = $resolved;
            }
        }
        $entries = array_values(array_unique(array_map('strval', $entries)));

        // Expand directory entries to the concrete dirty files they cover at
        // import so per-file content drift can be tracked.
        $covered = $entries;
        if ($this->git->isAvailable()) {
            $dirty = $this->git->resolveChangedPaths();
            if ($dirty !== null) {
                $covered = array_values(array_filter(
                    $dirty,
                    static fn(string $p): bool => GitEvidenceResolver::isWithinBaseline($p, $entries)
                ));
            }
        }
        $hashes = $this->git->pathContentHashes($covered) ?? [];

        return ['changed_paths' => $entries, 'covered_paths' => $covered, 'hashes' => $hashes];
    }

    /**
     * Ingest a normalized stage-result envelope and apply the resulting state
     * transition under the task lock. Fails closed on malformed envelopes.
     *
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingestStageResult(string $taskId, array $envelope, array $options = []): array
    {
        $errors = $this->validateEnvelope($envelope);
        if ($errors !== []) {
            return [
                'ok' => false,
                'task_id' => $taskId,
                'state' => null,
                'reason' => 'Malformed stage-result envelope',
                'errors' => $errors,
                'blockers' => [],
            ];
        }

        // Reject unknown tasks without touching storage.
        $task = $this->repo->getTask($taskId);

        $envelope = $this->redact($envelope);
        $stage = (string) $envelope['stage'];
        $result = (string) $envelope['result'];
        $actor = (array) ($envelope['actor'] ?? []);

        // Implement results must carry durable, Git-verifiable evidence. The
        // caller-supplied head and changed paths are claims; they are resolved
        // and verified against the real repository. Task-attributable scope
        // excludes the approved baseline (pre-existing dirt captured at import),
        // and a working-tree fingerprint is stored so release can detect later
        // uncommitted drift even while the recorded HEAD SHA still matches.
        $gitEvidence = null;
        if ($stage === 'implement') {
            $claimedHead = (string) ($envelope['git']['head'] ?? '');
            $claimedPaths = array_values(array_filter(array_map('strval', (array) ($envelope['git']['changed_paths'] ?? []))));
            if ($claimedHead === '' || $claimedPaths === []) {
                return [
                    'ok' => false,
                    'task_id' => $taskId,
                    'state' => $task['state'] ?? null,
                    'reason' => 'implement stage result is missing Git evidence (git.head and git.changed_paths are required)',
                    'blockers' => [],
                    'errors' => ['git.head and git.changed_paths are required on implement results'],
                ];
            }
            $verified = $this->git->verifyImplementEvidence($claimedHead, $claimedPaths);
            if (!$verified['ok']) {
                return [
                    'ok' => false,
                    'task_id' => $taskId,
                    'state' => $task['state'] ?? null,
                    'reason' => 'implement stage result failed Git evidence verification',
                    'blockers' => [],
                    'errors' => $verified['errors'],
                ];
            }
            $baselineHashes = (array) ($task['baseline']['hashes'] ?? []);
            $resolvedPaths = array_values($verified['changed_paths']);

            // P1-3: a baseline file whose CONTENT changed since import is no
            // longer the approved baseline — it must enter task scope so the
            // modification is reviewed, never silently blessed as non-task.
            $currentBaselineHashes = $this->git->pathContentHashes(array_keys($baselineHashes)) ?? [];
            $driftedBaseline = [];
            foreach ($baselineHashes as $file => $importHash) {
                $currentHash = $currentBaselineHashes[$file] ?? '<<unreadable>>';
                if ((string) $importHash !== '' && $currentHash !== (string) $importHash) {
                    $driftedBaseline[$file] = true;
                }
            }

            // Only concrete files captured and hashed at import may remain
            // baseline. A directory declaration must not bless a new or formerly
            // clean descendant that was absent from the import-time snapshot.
            $unchangedBaseline = [];
            foreach ($baselineHashes as $file => $importHash) {
                if (!isset($driftedBaseline[$file]) && (string) $importHash !== '') {
                    $unchangedBaseline[(string) $file] = true;
                }
            }
            $taskChanged = array_values(array_filter(
                $resolvedPaths,
                static fn(string $p): bool => !isset($unchangedBaseline[$p])
            ));
            $gitEvidence = [
                'base' => self::sanitizeBase((string) ($envelope['git']['base'] ?? '')),
                'head' => (string) $verified['head'],
                'changed_paths' => $taskChanged,
                'baseline_changed_paths' => array_keys($unchangedBaseline),
                'baseline_drifted' => array_keys($driftedBaseline),
                'fingerprint' => (string) ($this->git->workingTreeFingerprint() ?? ''),
                'verified_at' => gmdate(DATE_ATOM),
            ];
            // Scope classification uses the task-attributable path set.
            $changedPaths = $taskChanged;
        } else {
            $changedPaths = array_values(array_filter(array_map(
                static fn($p) => is_string($p) ? $p : '',
                (array) ($envelope['git']['changed_paths'] ?? [])
            )));
        }

        $scope = $this->classifyScope((array) ($task['approved_scope'] ?? []), $changedPaths);
        $targetState = $this->mapResultToState((string) ($task['state'] ?? ''), $stage, $result, $scope['unexpected'] !== []);

        // Phase 2: a /review finding may only cite evidence present in the task
        // timeline. Unresolved or fabricated references fail closed instead of
        // being recorded as a durable finding that cites nothing.
        if ($stage === 'review') {
            $missing = $this->unresolvedFindingRefs($task, $envelope);
            if ($missing !== []) {
                return [
                    'ok' => false,
                    'task_id' => $taskId,
                    'state' => $task['state'] ?? null,
                    'reason' => 'review stage result cites evidence not present in the task timeline',
                    'blockers' => [],
                    'errors' => array_map(static fn (string $r): string => 'unresolved evidence citation: ' . $r, $missing),
                ];
            }
        }

        $projection = $this->projectionUpdates($task, $envelope, $scope, $gitEvidence);
        $evidence = $this->evidenceRefs($envelope);

        $reason = sprintf(
            '%s stage result ingested: %s',
            $stage,
            $result
        );

        return $this->repo->transition($taskId, $targetState, [
            'reason' => $reason,
            'actor' => $actor,
            'evidence' => $evidence,
            'scope' => [
                // classifyScope returns path=>true maps; events must store the
                // actual repository-relative path strings.
                'approved_changed' => array_keys($scope['approved']),
                'unexpected_changed' => array_keys($scope['unexpected']),
            ],
            'projection' => $projection,
            // Give release evaluation access to the resolver so it can re-verify
            // the working tree (P1-1) at READY_FOR_RELEASE time.
            'git_resolver' => $this->git,
        ]);
    }

    /**
     * Classify changed paths against approved scope. Supports exact files and
     * directory prefixes; anything else is unexpected. Forbidden matches are
     * unexpected violations.
     *
     * @param array<string,mixed> $approvedScope
     * @param list<string> $changedPaths
     * @return array{approved:array<string,true>,unexpected:array<string,true>}
     */
    public function classifyScope(array $approvedScope, array $changedPaths): array
    {
        $allowed = (array) ($approvedScope['allowed'] ?? []);
        $forbidden = (array) ($approvedScope['forbidden'] ?? []);
        $approved = [];
        $unexpected = [];

        foreach ($changedPaths as $raw) {
            try {
                $path = DevelopmentTaskContract::normalizePath($raw);
            } catch (\InvalidArgumentException) {
                // Traversal or malformed paths cannot be silently ignored.
                $unexpected[$raw] = true;
                continue;
            }

            // Forbidden scope wins: a path covered by both allowed and forbidden
            // must fail closed rather than be approved.
            if (self::withinScope($path, $forbidden)) {
                $unexpected[$path] = true;
                continue;
            }
            if (self::withinScope($path, $allowed)) {
                $approved[$path] = true;
                continue;
            }
            $unexpected[$path] = true;
        }

        return ['approved' => $approved, 'unexpected' => $unexpected];
    }

    /**
     * Deterministically verify a release-gate artifact: it must exist on disk,
     * match the recorded content hash, be valid JSON, declare decision
     * "approved", and include only PASS/NOT_REQUIRED checks. Agent claims are
     * never treated as evidence on their own.
     *
     * @param array<string,mixed> $gate
     * @return array<string,mixed>
     */
    /** Schema a release-gate artifact must declare. */
    public const RELEASE_GATE_SCHEMA = 'ark.workbench-development-release-gate.v1';

    /**
     * Deterministically verify a release-gate artifact. The artifact must:
     *  - live under the ledger gates directory (relative path, no traversal,
     *    no absolute path, no drive roots);
     *  - exist on disk and match the REQUIRED envelope content hash;
     *  - be valid JSON declaring the gate schema, this task id, the current
     *    contract revision, a git SHA matching the implementation head, and
     *    decision "approved";
     *  - declare non-empty checks, all PASS/NOT_REQUIRED, covering the mandatory
     *    layer set. Self-authored or empty-check evidence can never unlock release.
     *
     * @param array<string,mixed> $gate
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    public function verifyReleaseGate(array $gate, string $storageRoot, array $task, ?string $gitHead): array
    {
        $artifact = (string) ($gate['artifact'] ?? '');
        if ($artifact === '') {
            return ['verified' => false, 'errors' => ['Release-gate artifact path is required']];
        }
        if (str_starts_with($artifact, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $artifact) === 1) {
            return ['verified' => false, 'errors' => ['Release-gate artifact path must be relative']];
        }
        if (str_contains($artifact, '\\\\') || preg_match('#(^|/)\\.\\.(/|$)#', $artifact) === 1) {
            return ['verified' => false, 'errors' => ['Release-gate artifact path may not traverse directories']];
        }
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $artifact) !== 1) {
            return ['verified' => false, 'errors' => ['Release-gate artifact path is not a safe relative path']];
        }
        // Confine the artifact to the ledger gates directory.
        if (!str_starts_with($artifact, 'gates/')) {
            return ['verified' => false, 'errors' => ['Release-gate artifact must live under the gates directory']];
        }
        $path = rtrim($storageRoot, '/') . '/' . $artifact;
        if (!is_file($path)) {
            return ['verified' => false, 'errors' => ["Release-gate artifact not found: {$artifact}"]];
        }
        $hash = hash_file('sha256', $path);
        $envelopeHash = (string) ($gate['hash'] ?? '');
        if ($envelopeHash === '' || $envelopeHash !== $hash) {
            return ['verified' => false, 'errors' => ['Release-gate artifact hash is missing or does not match']];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['verified' => false, 'errors' => ['Release-gate artifact is not valid JSON']];
        }
        if ((string) ($decoded['schema'] ?? '') !== self::RELEASE_GATE_SCHEMA) {
            return ['verified' => false, 'errors' => ['Release-gate artifact schema is missing or invalid']];
        }
        if ((string) ($decoded['task_id'] ?? '') !== (string) ($task['task_id'] ?? '')) {
            return ['verified' => false, 'errors' => ['Release-gate artifact is not bound to this task']];
        }
        if ((string) ($decoded['contract_revision'] ?? '') !== (string) ($task['contract_revision'] ?? '')) {
            return ['verified' => false, 'errors' => ['Release-gate artifact is not bound to the current contract revision']];
        }
        // A release approval requires recorded git evidence. A task that never
        // reached the implement stage has no stored head; fail closed instead of
        // accepting an arbitrary (unverifiable) git SHA from the gate.
        if ($gitHead === null || $gitHead === '') {
            return ['verified' => false, 'errors' => ['Task has no recorded git head; release-gate cannot be verified']];
        }
        $decodedSha = (string) ($decoded['git_sha'] ?? '');
        if ($decodedSha === '' || $decodedSha !== $gitHead) {
            return ['verified' => false, 'errors' => ['Release-gate artifact git SHA is missing or does not match the implementation head']];
        }
        // Phase 3: decisions are approve/block/condition. Only an approved gate
        // requires a clean check matrix; a blocked/condition gate records the
        // authoritative negative/conditional decision and must not be forced to
        // fabricate PASS checks.
        $decision = (string) ($decoded['decision'] ?? '');
        if (!in_array($decision, ['approved', 'blocked', 'condition'], true)) {
            return ['verified' => false, 'errors' => ['Release-gate artifact decision is invalid: ' . $decision]];
        }
        $checks = (array) ($decoded['checks'] ?? []);
        if ($checks === []) {
            return ['verified' => false, 'errors' => ['Release-gate artifact declares no checks']];
        }
        $checkErrors = [];
        $byName = [];
        foreach ($checks as $check) {
            $name = (string) ($check['name'] ?? '?');
            $status = (string) ($check['status'] ?? 'NOT_RUN');
            $byName[$name] = $check;
            if ($decision === 'approved' && $status !== 'PASS' && $status !== 'NOT_REQUIRED') {
                $checkErrors[] = "Gate check '{$name}' is {$status}";
            }
        }
        if ($decision === 'approved') {
            // The mandatory layers must be executed (PASS), not waived, in the gate.
            foreach (DevelopmentLifecycle::REQUIRED_VERIFICATION_LAYERS as $required) {
                $check = $byName[$required] ?? null;
                if ($check === null) {
                    $checkErrors[] = "Gate is missing mandatory check '{$required}'";
                    continue;
                }
                if ((string) ($check['status'] ?? '') !== 'PASS') {
                    $checkErrors[] = "Gate mandatory check '{$required}' is not PASS";
                }
            }
        }
        // A condition gate must declare conditions, and every condition must
        // carry an owner and an evidence reference that resolves to evidence
        // present in the task timeline (P2-4: parity with review findings).
        $conditions = array_values((array) ($decoded['conditions'] ?? []));
        if ($decision === 'condition') {
            if ($conditions === []) {
                $checkErrors[] = 'Release-gate decision is condition but no conditions are declared';
            }
            $knownEvidence = $this->knownEvidenceRefs($task, (string) ($task['task_id'] ?? ''));
            foreach ($conditions as $i => $condition) {
                if (!is_array($condition)) {
                    $checkErrors[] = "Gate condition #{$i} is not an object";
                    continue;
                }
                if ((string) ($condition['owner'] ?? '') === '') {
                    $checkErrors[] = "Gate condition #{$i} is missing an owner";
                }
                $ref = (string) ($condition['evidence_ref'] ?? '');
                if ($ref === '') {
                    $checkErrors[] = "Gate condition #{$i} is missing an evidence_ref";
                } elseif (!isset($knownEvidence[$ref])) {
                    $checkErrors[] = "Gate condition #{$i} evidence_ref does not resolve to task evidence: {$ref}";
                }
            }
        }
        if ($checkErrors !== []) {
            return ['verified' => false, 'errors' => $checkErrors];
        }

        return [
            'verified' => true,
            'artifact' => realpath($path) ?: $path,
            'hash' => $hash,
            'decision' => $decision,
            'conditions' => $conditions,
            'task_id' => (string) ($task['task_id'] ?? ''),
            'contract_revision' => (string) ($decoded['contract_revision'] ?? ''),
            'git_sha' => $decodedSha,
            'checks' => $checks,
            'verified_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * Recursively redact sensitive fields and inline secrets from an envelope.
     *
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    public function redact(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (is_array($item)) {
                $out[$key] = $this->redact($item);
                continue;
            }
            if (is_string($item) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_string($item)) {
                $out[$key] = DevelopmentTaskContract::redactScalar($item);
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /** @return list<string> */
    private function validateEnvelope(array $envelope): array
    {
        $errors = [];
        if (!isset($envelope['stage']) || !in_array((string) $envelope['stage'], self::STAGES, true)) {
            $errors[] = 'stage must be one of ' . implode('|', self::STAGES);
        }
        if (!isset($envelope['task_id']) || !is_string($envelope['task_id'])) {
            $errors[] = 'task_id is required';
        }
        if (!isset($envelope['result']) || !in_array((string) $envelope['result'], self::RESULTS, true)) {
            $errors[] = 'result is invalid';
        }
        if (!isset($envelope['actor']) || !is_array($envelope['actor']) || !isset($envelope['actor']['role'])) {
            $errors[] = 'actor.role is required';
        }
        if (empty($envelope['recorded_at'])) {
            $errors[] = 'recorded_at is required';
        }

        return $errors;
    }

    private function mapResultToState(string $current, string $stage, string $result, bool $hasUnexpectedScope): string
    {
        if ($result === 'architecture_decision_required') {
            return DevelopmentLifecycle::ARCHITECTURE_DECISION_REQUIRED;
        }
        if ($hasUnexpectedScope) {
            return DevelopmentLifecycle::REVIEW_REQUIRED;
        }

        return match ($stage) {
            'architect' => match ($result) {
                'passed' => DevelopmentLifecycle::READY_FOR_IMPLEMENTATION,
                'blocked' => DevelopmentLifecycle::BLOCKED,
                default => DevelopmentLifecycle::FAILED,
            },
            'implement' => match ($result) {
                'passed' => in_array($current, [
                    DevelopmentLifecycle::CHANGES_REQUIRED,
                    DevelopmentLifecycle::BLOCKED,
                    DevelopmentLifecycle::REVIEW_REQUIRED,
                ], true)
                    ? DevelopmentLifecycle::IMPLEMENTING
                    : DevelopmentLifecycle::READY_FOR_REVIEW,
                'blocked' => DevelopmentLifecycle::BLOCKED,
                default => DevelopmentLifecycle::FAILED,
            },
            'review' => match ($result) {
                'passed' => DevelopmentLifecycle::REVIEW_PASSED,
                'changes_required' => DevelopmentLifecycle::CHANGES_REQUIRED,
                'review_required' => DevelopmentLifecycle::REVIEW_REQUIRED,
                'blocked' => DevelopmentLifecycle::BLOCKED,
                default => DevelopmentLifecycle::FAILED,
            },
            'release-gate' => match ($result) {
                'passed' => DevelopmentLifecycle::READY_FOR_RELEASE,
                'blocked' => DevelopmentLifecycle::RELEASE_BLOCKED,
                default => DevelopmentLifecycle::FAILED,
            },
            default => DevelopmentLifecycle::FAILED,
        };
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $envelope
     * @param array{approved:array<string,true>,unexpected:array<string,true>} $scope
     * @param array<string,mixed>|null $gitEvidence Resolved/verified implement git evidence.
     * @return array<string,mixed>
     */
    private function projectionUpdates(array $task, array $envelope, array $scope, ?array $gitEvidence = null): array
    {
        $projection = [];

        // Phase 2: durable evidence projection. Every /implement envelope links
        // normalized observations, browser artifacts, and issue-ledger findings
        // as {kind, ref, hash}. Citations resolve against this projection and
        // the append-only timeline by id.
        $knownEvidence = (array) ($task['evidence'] ?? []);
        $evidenceIndex = [];
        foreach ($knownEvidence as $entry) {
            if (!is_array($entry) || empty($entry['ref'])) {
                continue;
            }
            $evidenceIndex[(string) $entry['ref']] = [
                'kind' => (string) ($entry['kind'] ?? 'artifact'),
                'ref' => (string) $entry['ref'],
                'hash' => (string) ($entry['hash'] ?? ''),
            ];
        }
        foreach ((array) ($envelope['evidence'] ?? []) as $entry) {
            if (!is_array($entry) || empty($entry['ref'])) {
                continue;
            }
            $ref = (string) $entry['ref'];
            $evidenceIndex[$ref] = [
                'kind' => (string) ($entry['kind'] ?? 'artifact'),
                'ref' => $ref,
                'hash' => (string) ($entry['hash'] ?? ''),
            ];
        }
        $projection['evidence'] = array_values($evidenceIndex);

        // Implement results carry Git-verified evidence on the task: the head and
        // changed paths were resolved from the repository, never taken verbatim
        // from the envelope.
        if (($envelope['stage'] ?? '') === 'implement') {
            $projection['git'] = $gitEvidence ?? [
                'base' => '',
                'head' => '',
                'changed_paths' => [],
            ];
        }


        // actual_scope merge (dedupe by path). classifyScope returns path=>true
        // maps; the actual path string is the key, so iterate keys.
        $actual = (array) ($task['actual_scope'] ?? []);
        $known = [];
        foreach ($actual as $entry) {
            $known[(string) ($entry['path'] ?? '')] = $entry;
        }
        foreach (array_keys($scope['approved']) as $path) {
            $known[$path] = ['path' => $path, 'status' => 'approved', 'justification' => null, 'source' => (string) ($envelope['stage'] ?? '')];
        }
        foreach (array_keys($scope['unexpected']) as $path) {
            $known[$path] = ['path' => $path, 'status' => 'unexpected', 'justification' => null, 'source' => (string) ($envelope['stage'] ?? '')];
        }
        $projection['actual_scope'] = array_values($known);

        // verification layers merge. Each layer's artifact path is resolved
        // against the evidence root, the content hash is computed here, and the
        // artifact is validated as a legitimate task-bound result — a
        // caller-supplied hash or arbitrary file is never trusted.
        // Missing/unverifiable artifacts are recorded as unverified and block
        // release.
        $layers = (array) ($task['verification']['layers'] ?? []);
        $layerIndex = [];
        foreach ($layers as $layer) {
            $layerIndex[(string) ($layer['name'] ?? '')] = $layer;
        }
        $taskId = (string) ($task['task_id'] ?? '');
        // A verification artifact must bind to the exact implementation evidence:
        // the immutable contract revision, the resolved Git HEAD, and the
        // working-tree fingerprint (fresh from this implement, else recorded).
        $gitForBinding = $gitEvidence ?? (array) ($task['git'] ?? []);
        $binding = [
            'contract_revision' => (string) ($task['contract_revision'] ?? ''),
            'head' => (string) ($gitForBinding['head'] ?? ''),
            'fingerprint' => (string) ($gitForBinding['fingerprint'] ?? ''),
        ];
        foreach ((array) ($envelope['verification'] ?? []) as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $name = (string) ($layer['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $layerBinding = $binding;
            $layerBinding['layer'] = $name;
            $layerIndex[$name] = $this->verificationLayer($layer, $taskId, $layerBinding);
        }
        $projection['verification'] = [
            'status' => self::verificationStatus(array_values($layerIndex)),
            'layers' => array_values($layerIndex),
        ];

        // review updates
        if (($envelope['stage'] ?? '') === 'review') {
            $existingFindings = (array) ($task['review']['findings'] ?? []);
            $findings = $existingFindings;
            // A passing review resolves prior findings so they cannot block release
            // forever; findings from the current envelope remain open.
            if (($envelope['result'] ?? '') === 'passed') {
                foreach ($findings as &$f) {
                    $f['resolved'] = true;
                }
                unset($f);
            }
            foreach ((array) ($envelope['unresolved_findings'] ?? []) as $finding) {
                $findings[] = [
                    'severity' => (string) ($finding['severity'] ?? 'P2'),
                    'summary' => (string) ($finding['summary'] ?? 'Review finding'),
                    // Phase 2: evidence ids this finding cites (validated above).
                    'evidence_refs' => array_values(array_filter(array_map('strval', (array) ($finding['evidence_refs'] ?? [])))),
                    // Phase 3: flaky/environment_only findings do not block
                    // release without a verified reproduction.
                    'classification' => in_array((string) ($finding['classification'] ?? 'normal'), ['flaky', 'environment_only'], true)
                        ? (string) $finding['classification']
                        : 'normal',
                    'verified_reproduction' => (string) ($finding['verified_reproduction'] ?? '') !== ''
                        ? (string) $finding['verified_reproduction']
                        : null,
                    'resolved' => false,
                ];
            }
            $projection['review'] = [
                'status' => (string) ($envelope['result'] === 'passed' ? 'passed' : $envelope['result']),
                'findings' => $findings,
            ];
        }

        // release-gate updates: store the deterministically verified gate only,
        // and require the recorded Git evidence to still describe the working
        // tree (P1-1) — later uncommitted changes cannot unlock release even
        // though the recorded HEAD SHA still matches.
        if (($envelope['stage'] ?? '') === 'release-gate') {
            $gate = (array) ($envelope['release_gate'] ?? []);
            $taskGitHead = (string) ($task['git']['head'] ?? '');
            $verified = $this->verifyReleaseGate($gate, $this->repo->root(), $task, $taskGitHead === '' ? null : $taskGitHead);
            $gitErrors = [];
            if ($verified['verified']) {
                // Authoritative release re-verification: fail closed when the
                // repository cannot be re-verified in this environment.
                $stability = $this->git->verifyStableState($task, true);
                if (!$stability['ok']) {
                    $gitErrors = $stability['errors'];
                }
            }
            // Phase 3: verified_gate means the artifact is structurally genuine
            // and Git-stable. A blocked/condition decision is still a legitimate,
            // recorded gate, but only an approved decision releases.
            $gitStable = $verified['verified'] && $gitErrors === [];
            $releaseOk = $gitStable && ($verified['decision'] ?? '') === 'approved';
            // P2-5: persist only a verified decision. An unverified/fabricated
            // gate must never leave a misleading 'approved' decision on the
            // ledger; its errors surface in blockers and verified_gate=false.
            $decision = $verified['verified'] ? (string) ($verified['decision'] ?? '') : '';
            $projection['release'] = [
                'gate_artifact' => $gitStable ? (string) $verified['artifact'] : (string) ($gate['artifact'] ?? ''),
                'gate_hash' => $gitStable ? (string) $verified['hash'] : '',
                'decision' => $decision,
                // Phase 3: validated conditions (owner + evidence_ref required).
                'conditions' => array_values((array) ($verified['conditions'] ?? [])),
                'blockers' => $verified['verified'] ? ($gitErrors !== [] ? $gitErrors : []) : ($verified['errors'] ?? []),
                'verified_gate' => $gitStable,
                'verified_at' => (string) ($verified['verified_at'] ?? ''),
            ];
        }

        return $projection;
    }

    /** @param list<array<string,mixed>> $layers */
    private static function verificationStatus(array $layers): string
    {
        if ($layers === []) {
            return 'NOT_RUN';
        }
        $statuses = array_map(static fn(array $l): string => (string) ($l['status'] ?? 'NOT_RUN'), $layers);
        if (in_array('FAIL', $statuses, true)) {
            return 'FAIL';
        }
        if (in_array('FLAKY', $statuses, true)) {
            return 'FLAKY';
        }
        if (in_array('SKIPPED', $statuses, true)) {
            return 'SKIPPED';
        }
        if (in_array('NOT_RUN', $statuses, true)) {
            return 'NOT_RUN';
        }
        if (count($statuses) === count(array_filter($statuses, static fn(string $s): bool => $s === 'NOT_REQUIRED'))) {
            return 'NOT_REQUIRED';
        }

        return 'PASS';
    }

    /**
     * Normalize a verification layer into a durable, artifact-backed record.
     *
     * The layer's path is resolved against the evidence root, the content hash
     * is computed from the on-disk artifact, and the artifact is validated as a
     * legitimate task-bound result bound to the exact implementation evidence
     * (contract revision, Git HEAD, working-tree fingerprint, layer) — P1-2. A
     * caller-supplied hash, an arbitrary file, a stale/replayed artifact, or a
     * self-authored result is never certified.
     *
     * @param array<string,mixed> $layer
     * @param string $taskId Task the layer is being recorded against.
     * @param array<string,mixed> $binding Expected contract_revision/head/fingerprint/layer.
     * @return array<string,mixed>
     */
    private function verificationLayer(array $layer, string $taskId, array $binding): array
    {
        $out = [
            'name' => (string) ($layer['name'] ?? ''),
            'status' => (string) ($layer['status'] ?? 'NOT_RUN'),
            'path' => '',
            'hash' => '',
            'verified' => false,
        ];
        $raw = trim((string) ($layer['path'] ?? ''));
        if ($raw === '') {
            $out['reason'] = 'no artifact path recorded';

            return $out;
        }
        try {
            $path = DevelopmentTaskContract::normalizePath($raw);
        } catch (\InvalidArgumentException $e) {
            $out['reason'] = 'invalid artifact path: ' . $e->getMessage();

            return $out;
        }
        $abs = rtrim($this->evidenceRoot, '/') . '/' . $path;
        if (!is_file($abs)) {
            $out['path'] = $abs;
            $out['reason'] = 'artifact not found on disk';

            return $out;
        }
        $hash = hash_file('sha256', $abs);
        $validated = DevelopmentVerificationArtifact::validate($abs, $taskId, $hash, $binding);
        if (!$validated['valid']) {
            $out['path'] = realpath($abs) ?: $abs;
            $out['reason'] = implode('; ', $validated['reasons']);

            return $out;
        }
        $out['path'] = realpath($abs) ?: $abs;
        $out['hash'] = $hash;
        $out['verified'] = true;
        $out['suite'] = (string) ($validated['summary']['suite'] ?? '');

        return $out;
    }

    /** Keep only a hex-looking base ref, or empty. */
    private static function sanitizeBase(string $base): string
    {
        $base = trim($base);

        return preg_match('/^[0-9a-fA-F]{7,64}$/', $base) === 1 ? $base : '';
    }

    /**
     * Phase 2: collect every evidence id a /review finding cites (evidence_refs
     * plus verified_reproduction) and return the ones not present in the task's
     * durable evidence projection or append-only timeline. Unresolved citations
     * fail closed so a finding can never cite evidence that does not exist.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $envelope
     * @return list<string>
     */
    private function unresolvedFindingRefs(array $task, array $envelope): array
    {
        $known = $this->knownEvidenceRefs($task, (string) ($task['task_id'] ?? ''));
        $missing = [];
        foreach ((array) ($envelope['unresolved_findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $refs = array_merge(
                array_values(array_filter(array_map('strval', (array) ($finding['evidence_refs'] ?? [])))),
                [(string) ($finding['verified_reproduction'] ?? '')]
            );
            foreach ($refs as $ref) {
                if ($ref !== '' && !isset($known[$ref])) {
                    $missing[] = $ref;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,bool>
     */
    private function knownEvidenceRefs(array $task, string $taskId): array
    {
        $known = [];
        foreach ((array) ($task['evidence'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['ref'])) {
                $known[(string) $entry['ref']] = true;
                if (!empty($entry['hash'])) {
                    $known[(string) $entry['ref'] . '#' . (string) $entry['hash']] = true;
                }
            }
        }
        foreach ($this->repo->timeline($taskId) as $event) {
            foreach ((array) ($event['evidence'] ?? []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $known[$ref] = true;
                }
            }
        }

        return $known;
    }

    /** @param array<string,mixed> $envelope @return list<string> */
    private function evidenceRefs(array $envelope): array
    {
        $refs = [];
        foreach ((array) ($envelope['evidence'] ?? []) as $evidence) {
            if (!is_array($evidence) || empty($evidence['ref'])) {
                continue;
            }
            $ref = (string) $evidence['ref'];
            $hash = (string) ($evidence['hash'] ?? '');
            $refs[] = $hash !== '' ? $ref . '#' . $hash : $ref;
        }
        foreach ((array) ($envelope['run_ids'] ?? []) as $runId) {
            if (is_string($runId) && $runId !== '') {
                $refs[] = 'run:' . $runId;
            }
        }

        return $refs;
    }

    /**
     * @param list<array{path:string,kind:string}> $entries
     */
    private static function withinScope(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            $kind = (string) ($entry['kind'] ?? 'file');
            if ($kind === 'rule') {
                continue;
            }
            $entryPath = (string) ($entry['path'] ?? '');
            if ($kind === 'file' && $path === $entryPath) {
                return true;
            }
            if ($kind === 'directory' && ($path === $entryPath || str_starts_with($path, $entryPath . '/'))) {
                return true;
            }
        }

        return false;
    }
}

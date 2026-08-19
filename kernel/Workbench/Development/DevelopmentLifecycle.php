<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Development lifecycle state machine for the Workbench Development Control Plane.
 *
 * All transitions are allow-listed and fail closed. READY_FOR_RELEASE requires an
 * explicit release-gate artifact and deterministic mandatory checks; it can never
 * be derived from an implementation/review claim or a generic run marked "passed".
 */
final class DevelopmentLifecycle
{
    public const REQUESTED = 'REQUESTED';
    public const ARCHITECTING = 'ARCHITECTING';
    public const READY_FOR_IMPLEMENTATION = 'READY_FOR_IMPLEMENTATION';
    public const IMPLEMENTING = 'IMPLEMENTING';
    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    public const REVIEWING = 'REVIEWING';
    public const CHANGES_REQUIRED = 'CHANGES_REQUIRED';
    public const REVIEW_PASSED = 'REVIEW_PASSED';
    public const RELEASE_GATE = 'RELEASE_GATE';
    public const READY_FOR_RELEASE = 'READY_FOR_RELEASE';
    public const BLOCKED = 'BLOCKED';
    public const FAILED = 'FAILED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const ARCHITECTURE_DECISION_REQUIRED = 'ARCHITECTURE_DECISION_REQUIRED';
    public const RELEASE_BLOCKED = 'RELEASE_BLOCKED';

    /** Mandatory verification layers a task must record as PASS before release. */
    public const REQUIRED_VERIFICATION_LAYERS = ['unit', 'integration', 'playwright'];

    public const STATES = [
        self::REQUESTED,
        self::ARCHITECTING,
        self::READY_FOR_IMPLEMENTATION,
        self::IMPLEMENTING,
        self::READY_FOR_REVIEW,
        self::REVIEWING,
        self::CHANGES_REQUIRED,
        self::REVIEW_PASSED,
        self::RELEASE_GATE,
        self::READY_FOR_RELEASE,
        self::BLOCKED,
        self::FAILED,
        self::REVIEW_REQUIRED,
        self::ARCHITECTURE_DECISION_REQUIRED,
        self::RELEASE_BLOCKED,
    ];

    /** Allow-listed transitions. CHANGES_REQUIRED returns only to implementation. */
    private const TRANSITIONS = [
        self::REQUESTED => [self::ARCHITECTING => true, self::FAILED => true],
        self::ARCHITECTING => [
            self::READY_FOR_IMPLEMENTATION => true,
            self::FAILED => true,
            self::BLOCKED => true,
            self::ARCHITECTURE_DECISION_REQUIRED => true,
        ],
        self::READY_FOR_IMPLEMENTATION => [
            self::IMPLEMENTING => true,
            self::READY_FOR_REVIEW => true,
            self::REVIEW_REQUIRED => true,
            self::FAILED => true,
            self::BLOCKED => true,
            self::ARCHITECTURE_DECISION_REQUIRED => true,
        ],
        self::IMPLEMENTING => [
            self::READY_FOR_REVIEW => true,
            self::REVIEW_REQUIRED => true,
            self::FAILED => true,
            self::BLOCKED => true,
            self::ARCHITECTURE_DECISION_REQUIRED => true,
        ],
        self::READY_FOR_REVIEW => [
            self::REVIEWING => true,
            self::REVIEW_PASSED => true,
            self::CHANGES_REQUIRED => true,
            self::REVIEW_REQUIRED => true,
            self::FAILED => true,
            self::BLOCKED => true,
        ],
        self::REVIEWING => [
            self::REVIEW_PASSED => true,
            self::CHANGES_REQUIRED => true,
            self::REVIEW_REQUIRED => true,
            self::FAILED => true,
            self::BLOCKED => true,
        ],
        self::CHANGES_REQUIRED => [
            self::IMPLEMENTING => true,
            self::BLOCKED => true,
            self::ARCHITECTURE_DECISION_REQUIRED => true,
            self::FAILED => true,
        ],
        self::REVIEW_PASSED => [
            self::RELEASE_GATE => true,
            self::READY_FOR_RELEASE => true,
            self::BLOCKED => true,
            self::REVIEWING => true,
        ],
        self::RELEASE_GATE => [
            self::READY_FOR_RELEASE => true,
            self::RELEASE_BLOCKED => true,
            self::FAILED => true,
        ],
        self::REVIEW_REQUIRED => [
            self::REVIEWING => true,
            self::REVIEW_PASSED => true,
            self::CHANGES_REQUIRED => true,
            self::IMPLEMENTING => true,
            self::BLOCKED => true,
            self::FAILED => true,
        ],
        self::ARCHITECTURE_DECISION_REQUIRED => [
            self::ARCHITECTING => true,
            self::FAILED => true,
            self::BLOCKED => true,
        ],
        self::BLOCKED => [
            self::IMPLEMENTING => true,
            self::ARCHITECTING => true,
            self::FAILED => true,
        ],
        self::FAILED => [
            self::ARCHITECTING => true,
            self::REQUESTED => true,
        ],
        self::RELEASE_BLOCKED => [
            self::RELEASE_GATE => true,
            self::REVIEWING => true,
            self::REVIEW_REQUIRED => true,
            self::FAILED => true,
        ],
    ];

    public static function isKnownState(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }

    /** @return array<string,array<string,bool>> */
    public static function allowedTransitions(): array
    {
        return self::TRANSITIONS;
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isKnownState($from) || !self::isKnownState($to)) {
            return false;
        }

        return (self::TRANSITIONS[$from][$to] ?? false) === true;
    }

    /**
     * Validate a transition and its release prerequisites.
     *
     * @param array<string,mixed> $context Task projection (used for READY_FOR_RELEASE gating).
     * @param GitEvidenceResolver|null $git Resolver for live working-tree re-verification (P1-1).
     * @return array{ok:bool,allowed:bool,new_state:string,reason:string,blockers:list<string>}
     */
    public static function transition(string $from, string $to, array $context = [], ?GitEvidenceResolver $git = null): array
    {
        $fail = static function (string $reason, array $blockers = []) use ($to): array {
            return [
                'ok' => false,
                'allowed' => false,
                'new_state' => $to,
                'reason' => $reason,
                'blockers' => $blockers,
            ];
        };

        if (!self::isKnownState($from)) {
            return $fail("Unknown state: {$from}");
        }
        if (!self::isKnownState($to)) {
            return $fail("Unknown state: {$to}");
        }
        if (!self::canTransition($from, $to)) {
            return $fail("Transition {$from} -> {$to} is not allow-listed");
        }

        if ($to === self::READY_FOR_RELEASE) {
            $blockers = self::releaseBlockers($context, $git);
            if ($blockers !== []) {
                return [
                    'ok' => false,
                    'allowed' => true,
                    'new_state' => $to,
                    'reason' => 'Release prerequisites are not satisfied',
                    'blockers' => $blockers,
                ];
            }
        }

        return [
            'ok' => true,
            'allowed' => true,
            'new_state' => $to,
            'reason' => '',
            'blockers' => [],
        ];
    }

    /**
     * Deterministic release prerequisites. READY_FOR_RELEASE is unreachable unless:
     *  - an explicit release-gate artifact exists and is approved;
     *  - review passed with no blocking findings;
     *  - no unjustified scope drift remains;
     *  - the recorded Git evidence still describes the current working tree
     *    (HEAD, changed paths, and content fingerprint — P1-1). In strict mode
     *    (the authoritative release decision) a missing resolver or an
     *    unavailable repository FAILS CLOSED: a task with recorded git evidence
     *    can never reach READY_FOR_RELEASE without revalidation;
     *  - all mandatory verification layers are PASS and backed by a valid,
     *    task-bound test result artifact on disk bound to the contract revision,
     *    Git HEAD, and working-tree fingerprint (P1-2) — no FAIL/NOT_RUN/
     *    SKIPPED, no bare caller-supplied hash strings, no arbitrary/stale files.
     *
     * @param array<string,mixed> $task
     * @param GitEvidenceResolver|null $git Resolver for live working-tree re-verification.
     * @param bool $strictGit Fail closed when git cannot be re-verified (authoritative).
     * @return list<string>
     */
    public static function releaseBlockers(array $task, ?GitEvidenceResolver $git = null, bool $strictGit = true): array
    {
        $blockers = [];

        $state = (string) ($task['state'] ?? '');
        if (!in_array($state, [self::REVIEW_PASSED, self::RELEASE_GATE, self::READY_FOR_RELEASE], true)) {
            $blockers[] = 'Task is not in a releasable state (state=' . $state . ')';
        }

        // P1-1: re-resolve the working tree at release time. A recorded HEAD SHA
        // that still matches is not enough — later uncommitted changes must be
        // detected via changed paths and the content fingerprint. Strict mode
        // requires an authoritative revalidation; a missing resolver or an
        // unavailable repository is a blocker, never a silent pass.
        $hasGitEvidence = (string) ($task['git']['head'] ?? '') !== '';
        if ($hasGitEvidence) {
            if ($git === null) {
                if ($strictGit) {
                    $blockers[] = 'Git re-verification is required at release but no resolver is available';
                }
            } else {
                $stability = $git->verifyStableState($task, $strictGit);
                foreach ($stability['errors'] as $error) {
                    $blockers[] = (string) $error;
                }
            }
        }

        $release = (array) ($task['release'] ?? []);
        $gateArtifact = (string) ($release['gate_artifact'] ?? '');
        $gateHash = (string) ($release['gate_hash'] ?? '');
        $verified = ($release['verified_gate'] ?? false) === true;
        // A release requires a real, content-hash-checked gate artifact that was
        // deterministically verified at ingest time. Fabricated claims fail here.
        if (!$verified || $gateArtifact === '' || $gateHash === '') {
            $blockers[] = 'No verified release-gate artifact is recorded';
        } elseif (!is_file($gateArtifact) || hash_file('sha256', $gateArtifact) !== $gateHash) {
            $blockers[] = 'Release-gate artifact is missing or its content hash does not match';
        }
        if ((string) ($release['decision'] ?? '') !== 'approved') {
            $blockers[] = 'Release-gate decision is not approved';
        }
        foreach ((array) ($release['blockers'] ?? []) as $blocker) {
            if ((string) $blocker !== '') {
                $blockers[] = (string) $blocker;
            }
        }

        $review = (array) ($task['review'] ?? []);
        $reviewStatus = (string) ($review['status'] ?? '');
        if ($reviewStatus !== 'passed') {
            $blockers[] = 'Review has not passed';
        }
        foreach ((array) ($review['findings'] ?? []) as $finding) {
            if (($finding['resolved'] ?? false) !== true && in_array((string) ($finding['severity'] ?? ''), ['P0', 'P1'], true)) {
                $blockers[] = 'Unresolved blocking review finding: ' . (string) ($finding['summary'] ?? '');
            }
        }

        foreach ((array) ($task['actual_scope'] ?? []) as $path) {
            if (($path['status'] ?? '') === 'unexpected') {
                $blockers[] = 'Unexpected scope change: ' . (string) ($path['path'] ?? '?');
            }
        }

        $layers = (array) ($task['verification']['layers'] ?? []);
        if ($layers === []) {
            $blockers[] = 'Mandatory verification evidence is missing (no layers recorded)';
        }
        $byName = [];
        foreach ($layers as $layer) {
            $byName[(string) ($layer['name'] ?? '')] = $layer;
        }
        // The full required layer set must be present, executed, and PASS with a
        // disk-verified artifact; one arbitrary PASS layer, a waived NOT_REQUIRED
        // layer, or a bare caller-supplied hash string can never unlock release.
        foreach (self::REQUIRED_VERIFICATION_LAYERS as $required) {
            $layer = $byName[$required] ?? null;
            if ($layer === null) {
                $blockers[] = "Required verification '{$required}' is missing";
                continue;
            }
            $status = (string) ($layer['status'] ?? 'NOT_RUN');
            if ($status !== 'PASS') {
                $blockers[] = "Required verification '{$required}' is {$status} (must be executed and PASS)";
                continue;
            }
            $path = (string) ($layer['path'] ?? '');
            $hash = (string) ($layer['hash'] ?? '');
            if ($path === '' || $hash === '') {
                $blockers[] = "Required verification '{$required}' has no hashed evidence artifact";
                continue;
            }
            // Re-verify the referenced artifact from disk on every release
            // evaluation: it must exist, match its content hash, AND be a valid
            // task-bound test result bound to the contract revision, Git HEAD,
            // and working-tree fingerprint of this task (P1-2). A bare hash
            // string, an arbitrary/stale/replayed artifact, an unbound/failing
            // result, or a missing/tampered file can never certify a pass.
            $artifact = DevelopmentVerificationArtifact::validate(
                $path,
                (string) ($task['task_id'] ?? ''),
                $hash,
                [
                    'contract_revision' => (string) ($task['contract_revision'] ?? ''),
                    'head' => (string) ($task['git']['head'] ?? ''),
                    'fingerprint' => (string) ($task['git']['fingerprint'] ?? ''),
                    'layer' => $required,
                ]
            );
            if (!$artifact['valid']) {
                $blockers[] = "Required verification '{$required}' artifact is not a valid task-bound result: "
                    . implode('; ', $artifact['reasons']);
            }
        }
        // Skipped, flaky (even non-critical), failed, or missing layers are never
        // deterministic gate evidence.
        foreach ($layers as $layer) {
            $status = (string) ($layer['status'] ?? 'NOT_RUN');
            $name = (string) ($layer['name'] ?? '?');
            if (in_array($status, ['FAIL', 'NOT_RUN', 'SKIPPED', 'FLAKY'], true)) {
                $blockers[] = "Verification '{$name}' is {$status}";
            }
        }

        return array_values(array_unique($blockers));
    }
}

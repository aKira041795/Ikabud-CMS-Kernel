<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Resolves authoritative Git evidence (HEAD sha + working-tree changed paths)
 * from a real repository and verifies caller-supplied implement-stage claims
 * against it.
 *
 * Caller-supplied git.head and git.changed_paths are claims, not evidence:
 * fabricated in-scope paths and a fake head can hide actual out-of-scope
 * changes or satisfy release-gate binding. This resolver makes the repository
 * the source of truth, fails closed when Git is unavailable, and never mutates
 * Git state (read-only commands only).
 */
final class GitEvidenceResolver
{
    /** @var string Matches full and abbreviated hex object ids (7-64 hex chars). */
    private const HEX_RE = '/^[0-9a-fA-F]{7,64}$/';

    public function __construct(
        private readonly ?string $gitRoot = null,
        private readonly string $gitBinary = 'git'
    ) {
    }

    public function isAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        if ($this->gitRoot === null || !is_dir($this->gitRoot)) {
            return false;
        }

        return is_dir($this->gitRoot . '/.git') || is_file($this->gitRoot . '/.git');
    }

    public function gitRoot(): ?string
    {
        return $this->gitRoot;
    }

    /**
     * Resolve the current repository HEAD sha, or null when unavailable.
     */
    public function resolveHead(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $r = $this->run(['rev-parse', 'HEAD']);
        if ($r['code'] !== 0) {
            return null;
        }
        $head = trim((string) $r['stdout']);

        return preg_match(self::HEX_RE, $head) === 1 ? $head : null;
    }

    /**
     * Resolve the authoritative working-tree changed paths (staged + unstaged +
     * untracked, excluding ignored files) relative to HEAD. Returns null when
     * Git is unavailable.
     *
     * @return list<string>|null
     */
    public function resolveChangedPaths(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        // core.quotepath=false keeps UTF-8 paths unquoted; --porcelain=v1 gives
        // the stable "XY path" (rename: "XY old -> new") line format; the
        // --diff-filter guard is not needed because status already reflects the
        // working tree, and --untracked-files=all lists every untracked file.
        $r = $this->run(['-c', 'core.quotepath=false', 'status', '--porcelain=v1', '--untracked-files=all']);
        if ($r['code'] !== 0) {
            return null;
        }
        $paths = [];
        foreach (preg_split('/\r?\n/', (string) $r['stdout']) ?: [] as $line) {
            if ($line === '' || strlen($line) < 4) {
                continue;
            }
            // Porcelain v1 layout is "XY path": columns 0-1 are the status and
            // column 2 is exactly one space (e.g. "?? src/a.php" for untracked,
            // " M .github/x.md" for a modified file). The line must NOT be
            // trimmed before extracting the path or a leading status-space is
            // eaten and the path loses its first character.
            $path = trim(substr($line, 3));
            // Renames render as "R  old -> new"; keep the resulting path.
            $arrow = strpos($path, ' -> ');
            if ($arrow !== false) {
                $path = trim(substr($path, $arrow + 4));
            }
            if ($path !== '' && $path !== '.') {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Verify an implement-stage git claim against the real repository.
     *
     * The claimed HEAD must equal the resolved repository HEAD, and every
     * claimed path must actually be present in the working-tree changes. The
     * authoritative changed-path set is the RESOLVED set (not the claim), so a
     * claim that omits real out-of-scope changes cannot hide them.
     *
     * @param string $claimedHead
     * @param list<string> $claimedPaths
     * @return array{ok:bool,head:?string,changed_paths:list<string>,errors:list<string>}
     */
    public function verifyImplementEvidence(string $claimedHead, array $claimedPaths): array
    {
        if (!$this->isAvailable()) {
            return [
                'ok' => false,
                'head' => null,
                'changed_paths' => [],
                'errors' => ['Git repository is not available; implement stage results cannot be verified'],
            ];
        }

        $actualHead = $this->resolveHead();
        if ($actualHead === null || $actualHead === '') {
            return [
                'ok' => false,
                'head' => null,
                'changed_paths' => [],
                'errors' => ['Unable to resolve repository HEAD'],
            ];
        }

        if ($claimedHead === '') {
            return [
                'ok' => false,
                'head' => $actualHead,
                'changed_paths' => [],
                'errors' => ['git.head is required on implement stage results'],
            ];
        }
        if ($claimedHead !== $actualHead) {
            return [
                'ok' => false,
                'head' => $actualHead,
                'changed_paths' => [],
                'errors' => ["git.head {$claimedHead} does not match repository HEAD {$actualHead}"],
            ];
        }

        $actualPaths = $this->resolveChangedPaths();
        if ($actualPaths === null) {
            return [
                'ok' => false,
                'head' => $actualHead,
                'changed_paths' => [],
                'errors' => ['Unable to resolve changed paths from Git'],
            ];
        }
        $actualSet = array_fill_keys($actualPaths, true);
        foreach ($claimedPaths as $path) {
            if (!isset($actualSet[$path])) {
                return [
                    'ok' => false,
                    'head' => $actualHead,
                    'changed_paths' => $actualPaths,
                    'errors' => ["Claimed changed path '{$path}' is not present in the Git working-tree changes"],
                ];
            }
        }

        return [
            'ok' => true,
            'head' => $actualHead,
            'changed_paths' => $actualPaths,
            'errors' => [],
        ];
    }

    /**
     * Deterministic checkout fingerprint: sha256 over every changed path, its
     * working-tree content, and its index entry (sorted). Binding the index entry
     * is essential: `git add` can change the staged release content while leaving
     * HEAD, the dirty path set, and the working-tree bytes unchanged.
     */
    public function workingTreeFingerprint(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $paths = $this->resolveChangedPaths();
        if ($paths === null) {
            return null;
        }
        $parts = [];
        foreach ($paths as $path) {
            $full = $this->gitRoot . '/' . $path;
            if (is_file($full)) {
                $content = file_get_contents($full);
                if ($content === false) {
                    return null;
                }
            } else {
                $content = '<<deleted>>';
            }
            $index = $this->run(['ls-files', '--stage', '--', $path]);
            if ($index['code'] !== 0) {
                return null;
            }
            $parts[] = $path
                . '=worktree:' . hash('sha256', $content)
                . ':index:' . hash('sha256', (string) $index['stdout']);
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * True when a repository-relative path is covered by a baseline entry
     * (exact file or directory prefix). Shared by scope subtraction and
     * working-tree stability re-verification so a declared directory baseline
     * (e.g. ".github/") covers every dirty path beneath it.
     *
     * @param string $path Repository-relative path.
     * @param list<string> $baseline Baseline paths (files and/or directories).
     */
    public static function isWithinBaseline(string $path, array $baseline): bool
    {
        foreach ($baseline as $b) {
            $b = rtrim((string) $b, '/');
            if ($b === '' || $b === '.') {
                continue;
            }
            if ($path === $b || str_starts_with($path, $b . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-path checkout-state hashes for repository-relative paths (used to
     * detect baseline drift between import and implementation). Each hash binds
     * both working-tree bytes and the staged index entry; missing/deleted files
     * use a marker. Returns null when Git or either view cannot be resolved.
     *
     * @param list<string> $paths
     * @return array<string,string>|null
     */
    public function pathContentHashes(array $paths): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $hashes = [];
        foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
            $full = $this->gitRoot . '/' . $path;
            if (is_file($full)) {
                $content = file_get_contents($full);
                if ($content === false) {
                    return null;
                }
            } else {
                $content = '<<deleted>>';
            }
            $index = $this->run(['ls-files', '--stage', '--', $path]);
            if ($index['code'] !== 0) {
                return null;
            }
            $hashes[$path] = hash(
                'sha256',
                'worktree:' . hash('sha256', $content)
                . ':index:' . hash('sha256', (string) $index['stdout'])
            );
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * Re-verify that the recorded Git evidence still describes the current
     * working tree. Guards against P1-1: after an implementation is ingested,
     * later uncommitted changes (new dirty paths, removed dirty paths, or
     * content drift of already-dirty files) must be caught at release time even
     * though the recorded HEAD SHA is unchanged.
     *
     * In strict mode (the authoritative release decision at gate ingest and
     * direct transitions) an unavailable repository or unresolvable HEAD FAILS
     * CLOSED: a task with recorded git evidence can never reach READY_FOR_RELEASE
     * without revalidation. In non-strict mode (informational web display) an
     * unverifiable environment adds no fabricated drift blockers.
     *
     * @param array<string,mixed> $task Task carrying git.head, git.changed_paths,
     *                                  git.baseline_changed_paths, git.fingerprint.
     * @param bool $strict Fail closed when the repository cannot be re-verified.
     * @return array{ok:bool,unverifiable:bool,errors:list<string>}
     */
    public function verifyStableState(array $task, bool $strict = true): array
    {
        $git = (array) ($task['git'] ?? []);
        $head = (string) ($git['head'] ?? '');
        if ($head === '') {
            // No recorded git evidence; callers enforce head presence separately.
            return ['ok' => true, 'unverifiable' => false, 'errors' => []];
        }
        if (!$this->isAvailable()) {
            if ($strict) {
                return ['ok' => false, 'unverifiable' => true, 'errors' => ['Git repository is not available to re-verify the working tree']];
            }

            return ['ok' => true, 'unverifiable' => true, 'errors' => []];
        }

        $currentHead = $this->resolveHead();
        if ($currentHead === null || $currentHead === '') {
            if ($strict) {
                return ['ok' => false, 'unverifiable' => true, 'errors' => ['Unable to resolve repository HEAD to re-verify the working tree']];
            }

            return ['ok' => true, 'unverifiable' => true, 'errors' => []];
        }

        $errors = [];
        if ($currentHead !== $head) {
            $errors[] = 'Git HEAD has moved since implementation was recorded';
        }

        $baseline = array_values(array_unique(array_map('strval', (array) ($git['baseline_changed_paths'] ?? []))));
        $taskChanged = array_values(array_unique(array_map('strval', (array) ($git['changed_paths'] ?? []))));
        $currentPaths = $this->resolveChangedPaths();
        if ($currentPaths === null) {
            if ($strict) {
                return ['ok' => false, 'unverifiable' => true, 'errors' => ['Unable to resolve changed paths to re-verify the working tree']];
            }

            return ['ok' => true, 'unverifiable' => true, 'errors' => []];
        }
        // baseline_changed_paths contains only the concrete, import-time files
        // whose content remained unchanged. Never expand a directory declaration
        // against the current checkout: that would silently bless new descendants.
        $expected = array_values(array_unique(array_merge($baseline, $taskChanged)));
        sort($expected);
        $sortedCurrent = $currentPaths;
        sort($sortedCurrent);
        if ($expected !== $sortedCurrent) {
            $errors[] = 'Working-tree changed paths have drifted since implementation was recorded';
        }

        $fingerprint = (string) ($git['fingerprint'] ?? '');
        if ($fingerprint !== '') {
            $currentFp = $this->workingTreeFingerprint();
            if ($currentFp === null) {
                if ($strict) {
                    return ['ok' => false, 'unverifiable' => true, 'errors' => ['Unable to resolve checkout fingerprint to re-verify the working tree']];
                }

                return ['ok' => true, 'unverifiable' => true, 'errors' => []];
            }
            if ($currentFp !== $fingerprint) {
                $errors[] = 'Working-tree content fingerprint has changed since implementation was recorded';
            }
        }

        return ['ok' => $errors === [], 'unverifiable' => false, 'errors' => $errors];
    }

    /**
     * Run a read-only git command in the repository root.
     *
     * stderr is redirected to a temporary file (not a pipe) so reading stdout to
     * completion can never deadlock on a full stderr pipe — a web worker that
     * cannot run git on the checkout must fail fast, not hang.
     *
     * @param list<string> $args
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function run(array $args): array
    {
        $cmd = array_merge([$this->gitBinary], $args);
        $tmpErr = tempnam(sys_get_temp_dir(), 'devcp-git-err-');
        if ($tmpErr === false) {
            $tmpErr = rtrim($this->gitRoot, '/') . '/.devcp-git-err-' . getmypid();
        }
        $pipes = [];
        $proc = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $tmpErr, 'w'],
        ], $pipes, $this->gitRoot);
        if (!is_resource($proc)) {
            @unlink($tmpErr);

            return ['code' => -1, 'stdout' => '', 'stderr' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        $stderr = is_file($tmpErr) ? (string) file_get_contents($tmpErr) : '';
        @unlink($tmpErr);

        return ['code' => $code, 'stdout' => (string) $stdout, 'stderr' => $stderr];
    }
}

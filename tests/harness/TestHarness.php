<?php

declare(strict_types=1);

/**
 * Shared Test Harness — replicable across any module.
 *
 * Architecture:
 *   - Standalone PHP (no PHPUnit dependency)
 *   - Structured results → stdout (human) + test_results/*.json (machine)
 *   - Log-aware: clears app.log + error.log before, reports after
 *   - Supports pure-logic (no bootstrap) and integration (bootstrap) modes
 *   - Source fingerprinting: every test records md5 of the tested source files
 *   - Self-verification: confirms the code under test matches what was tested
 *
 * INTEGRITY CONTRACT:
 *   Each test run records the md5 hashes of all source files it tests.
 *   Comparing test_results/*.json across runs detects UNNOTICED source changes.
 *   If source changes but test count doesn't, the fingerprint mismatch is visible.
 *
 * Usage:
 *   require_once __DIR__ . '/../harness/TestHarness.php';
 *   $h = new TestHarness('pal-job-order-workflow');
 *   $h->fingerprint('modules/project-audit-ledger/services/JobOrderWorkflow.php');
 *   $h->section('State machine');
 *   $h->test('draft → pending', palJobOrderWorkflow::isAllowed('draft', 'pending'));
 *   $h->done();
 *
 * Future (ARK Workbench infra):
 *   - Contract validation via component contracts JSON
 *   - Rendering tests via TemplateEngine
 *   - Browser fixtures via Playwright projects
 *   - Page-family relationship graph assertions
 */

class TestHarness
{
    private string $suiteName;
    private string $startTime;
    private float $startMicrotime;
    private string $resultsDir;
    private string $resultsFile;
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private int $assertionCount = 0;
    private string $currentSection = '';
    /** @var array<int, array{section: string, label: string, status: string, detail: string, time: float}> */
    private array $results = [];
    /** @var array<string, string[]> Gaps found during testing */
    private array $gaps = [];
    /** @var array<string, string> Source file fingerprints */
    private array $fingerprints = [];
    private bool $bootstrapped = false;
    private float $sectionStart;

    public const MODE_PURE = 'pure';       // No bootstrap needed
    public const MODE_INTEGRATION = 'int';  // Requires bootstrap.php

    private string $host = 'localhost';

    /** Whether done() was reached. Read by the shutdown guard. */
    private bool $completed = false;

    /**
     * Log sizes at construction. app.log belongs to the web user, so this process cannot
     * truncate it — the baseline is what makes "did this run dirty the log?" answerable
     * without owning the file.
     *
     * @var array<string, int|null>
     */
    private array $logBaseline = [];

    /**
     * @param string $suiteName Unique test suite identifier (used for filename)
     * @param string $mode MODE_PURE or MODE_INTEGRATION
     * @param string $host HTTP_HOST for tenant resolution (default: localhost)
     */
    public function __construct(string $suiteName, string $mode = self::MODE_PURE, string $host = 'localhost')
    {
        $this->suiteName = $suiteName;
        $this->host = $host;
        $this->startTime = date('Y-m-d H:i:s');
        $this->startMicrotime = microtime(true);
        $this->resultsDir = dirname(__DIR__, 2) . '/test_results';
        $this->resultsFile = $this->resultsDir . '/' . $suiteName . '.json';
        $this->sectionStart = microtime(true);

        // A suite that dies half way must not exit 0. The kernel's exception handler renders
        // an HTML page and exits cleanly, so a crashed run has reported success: DcCafeHttpTest
        // did exactly that once the module tables it was accidentally reading were removed,
        // and DcCafePosTest has been doing it silently for as long as it has existed.
        register_shutdown_function(function (): void {
            if (!$this->completed) {
                echo "\n  SUITE ABORTED before the end — treat as FAIL\n";
                exit(1);
            }
        });

        if (!is_dir($this->resultsDir)) {
            mkdir($this->resultsDir, 0777, true);
        }

        // Bootstrap if integration mode
        if ($mode === self::MODE_INTEGRATION) {
            $this->bootstrap();
        }

        // Clear logs
        // Clear what can be cleared, and record the rest so the run's own output can be told
        // apart from the web server's. See captureLogBaseline().
        $this->captureLogBaseline();

        echo "\n══════════════════════════════════════\n";
        echo "  {$suiteName}\n";
        echo "  Started: {$this->startTime}\n";
        echo "══════════════════════════════════════\n";

        $this->reportUnresolvedHost();
    }

    /**
     * Make the compiled template cache writable by whoever serves the app.
     *
     * Compiled templates are derived data, so nothing here needs preserving — the only thing
     * that matters is that the web user can rewrite them. Entries this process does not own
     * cannot be chmod'ed and do not need to be: they already belong to the web user, which is
     * why a failure here is silent rather than noisy.
     */
    private function relaxCompiledTemplateCache(): void
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                @chmod($item->getPathname(), $item->isDir() ? 0777 : 0666);
            }
        }
    }

    /**
     * Report when the suite's host belongs to no tenant.
     *
     * A hostname no tenant claims is not an error to the kernel — resolution falls through to
     * the kernel database. So a suite pointed at a stale or mistyped host reads the wrong
     * database and reports success. DcCafeHttpTest did exactly that: 40 requests to
     * `baronbakeshop`, a host that no longer existed, and it passed against the orphaned module
     * tables sitting in the kernel database — requests which were themselves what put the tables
     * there.
     *
     * Reported rather than failed on purpose: a test may use an unregistered host deliberately,
     * to check the fallback itself. Publishing the list makes the accidental ones obvious and
     * leaves the deliberate ones to be declared. Graduating this to a hard failure is a one-line
     * change once they are told apart.
     */
    private function reportUnresolvedHost(): void
    {
        if (!$this->isBootstrapped()) {
            return; // A pure suite has no database, so it has no tenant to be wrong about.
        }

        $host = strtolower(trim($this->host));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            // Not a failure: the harness defaults to localhost, and a suite may not care which
            // tenant it lands on. But it is unverified, and saying so beats implying otherwise.
            $this->gap("Suite did not name a tenant host (using '{$host}'), so which database it read is unverified.");
            return;
        }

        try {
            $stmt = app()->db()->prepare('SELECT COUNT(*) FROM kernel_tenant_domains WHERE domain = ?');
            $stmt->execute([$host]);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }
            $domains = app()->db()->query('SELECT domain FROM kernel_tenant_domains ORDER BY domain')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return; // No control plane reachable — nothing to check against.
        }

        // A failure, not a warning. A warning here was ignored for as long as it existed, and
        // the suites it concerned were reading the kernel database the whole time.
        $this->fail(
            "suite host '{$host}' belongs to no tenant",
            'requests to it fall through to the kernel database. Registered: ' . implode(', ', array_slice($domains, 0, 8))
        );
    }

    // ─── Bootstrap ───────────────────────────────────────────────

    private function bootstrap(): void
    {
        $base = dirname(__DIR__, 2);
        $bootstrap = $base . '/bootstrap.php';
        if (!file_exists($bootstrap)) {
            $this->echoWarn("bootstrap.php not found at {$bootstrap}");
            return;
        }

        // Set server vars for tenant resolution BEFORE bootstrap
        $_SERVER['HTTP_HOST'] = $this->host;
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = $this->host;

        // bootstrap.php creates $config in local scope, but app() references
        // it via global $config. We must declare it global before require.
        global $config;
        $returned = require $bootstrap;
        if ($config === null || !isset($config['database'])) {
            $config = $returned;
        }
        $this->bootstrapped = true;
    }

    /**
     * Truncate the logs this process can, and take a size baseline for the ones it cannot.
     *
     * app.log is owned by the web server and is not writable here. Truncating it failed
     * silently (the error was suppressed), so every log complaint a suite made was really
     * about the web server's traffic — hundreds of kilobytes of it — and the harness had no
     * way to know. A baseline answers the question that was actually being asked: what did
     * THIS run add?
     */
    private function captureLogBaseline(): void
    {
        $storage = dirname(__DIR__, 2) . '/storage/logs';

        foreach (['app.log', 'error.log'] as $name) {
            $path = $storage . '/' . $name;

            // Truncation is used where it works, to keep a run's output small.
            if (!is_file($path) || is_writable($path)) {
                @file_put_contents($path, '');
            }

            $this->logBaseline[$name] = is_file($path) ? (int) filesize($path) : null;
        }
    }

    // ─── Source Integrity ────────────────────────────────────────

    /**
     * Record the md5 fingerprint of a source file being tested.
     * This is included in the JSON output so any source change without
     * a corresponding test update is detectable.
     *
     * @param string $relativePath Path relative to project root, e.g. 'modules/.../services/Foo.php'
     */
    public function fingerprint(string $relativePath): void
    {
        $full = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
        if (!file_exists($full)) {
            $this->fingerprints[$relativePath] = 'FILE_NOT_FOUND';
            echo "  ⚠ fingerprint: {$relativePath} — file not found\n";
            return;
        }
        $hash = md5_file($full);
        $this->fingerprints[$relativePath] = $hash;
        echo "  🧬 {$relativePath} — " . substr($hash, 0, 12) . "...\n";
    }

    // ─── Test Sections ───────────────────────────────────────────

    public function section(string $title): void
    {
        $this->currentSection = $title;
        $this->sectionStart = microtime(true);
        echo "\n── {$title} ──\n";
    }

    // ─── Assertions ──────────────────────────────────────────────

    public function pass(string $label, string $detail = ''): void
    {
        $this->passed++;
        $this->assertionCount++;
        $elapsed = round((microtime(true) - $this->sectionStart) * 1000, 1);
        $this->results[] = [
            'section' => $this->currentSection,
            'label' => $label,
            'status' => 'pass',
            'detail' => $detail,
            'time' => $elapsed,
        ];
        echo "  ✅ {$label}\n";
    }

    public function fail(string $label, string $detail = ''): void
    {
        $this->failed++;
        $this->assertionCount++;
        $elapsed = round((microtime(true) - $this->sectionStart) * 1000, 1);
        $this->results[] = [
            'section' => $this->currentSection,
            'label' => $label,
            'status' => 'fail',
            'detail' => $detail,
            'time' => $elapsed,
        ];
        echo "  ❌ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
        $section = $this->currentSection ?: 'uncategorized';
        $this->gaps[$section][] = $label;
    }

    public function skip(string $label, string $reason = ''): void
    {
        $this->skipped++;
        $this->results[] = [
            'section' => $this->currentSection,
            'label' => $label,
            'status' => 'skip',
            'detail' => $reason,
            'time' => 0,
        ];
        echo "  ⏭ {$label}" . ($reason ? " ({$reason})" : '') . "\n";
    }

    /**
     * Assert a boolean condition. This is the PRIMARY assertion method
     * used by all tests — every call increments the assertion counter
     * and is recorded in the JSON output.
     */
    public function test(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->pass($label, $detail);
        } else {
            $this->fail($label, $detail);
        }
    }

    /**
     * Record a free-form diagnostic detail (e.g. the message from a caught
     * exception inside a try/catch). Does NOT count as an assertion — it is a
     * visibility aid so a caught failure is never silently swallowed.
     */
    public function detail(string $detail): void
    {
        $detail = trim((string)$detail);
        if ($detail === '') {
            return;
        }
        $this->results[] = [
            'section' => $this->currentSection,
            'label' => 'detail',
            'status' => 'detail',
            'detail' => $detail,
            'time' => round((microtime(true) - $this->sectionStart) * 1000, 1),
        ];
        echo "  ℹ detail: {$detail}\n";
    }

    public function assertSame(mixed $expected, mixed $actual, string $label = ''): void
    {
        $label = $label ?: "Expected " . $this->export($expected) . ", got " . $this->export($actual);
        $this->test($label, $expected === $actual, $expected !== $actual ? "got " . $this->export($actual) : '');
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $label = ''): void
    {
        $label = $label ?: "Expected not " . $this->export($expected) . ", got " . $this->export($actual);
        $this->test($label, $expected !== $actual, $expected === $actual ? "unexpectedly equal to " . $this->export($expected) : '');
    }

    public function assertContains(mixed $needle, array $haystack, string $label = ''): void
    {
        $label = $label ?: "Expected array to contain " . $this->export($needle);
        $this->test($label, in_array($needle, $haystack, true), "not found in array");
    }

    public function assertCount(int $expected, array|\Countable $actual, string $label = ''): void
    {
        $count = is_array($actual) ? count($actual) : $actual->count();
        $label = $label ?: "Expected count {$expected}, got {$count}";
        $this->test($label, $count === $expected, "got {$count}");
    }

    public function assertPresent(mixed $value, string $label): void
    {
        $present = $value !== null && $value !== false && $value !== '';
        $this->test($label, $present, $present ? '' : 'value is ' . $this->export($value));
    }

    public function assertThrows(string $exceptionClass, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->fail($label, "Expected {$exceptionClass} was not thrown");
        } catch (\Throwable $e) {
            $isExpected = $e instanceof $exceptionClass;
            $this->test($label, $isExpected, $isExpected ? $e->getMessage() : "Got " . get_class($e) . ": " . $e->getMessage());
        }
    }

    /**
     * Record a documented gap — something that should be tested but
     * requires infrastructure not available in the current test mode.
     */
    public function gap(string $description): void
    {
        $section = $this->currentSection ?: 'uncategorized';
        $this->gaps[$section][] = $description;
        echo "  🔍 GAP: {$description}\n";
    }

    // ─── Finish ──────────────────────────────────────────────────

    public function done(): void
    {
        $this->completed = true;

        $elapsed = round((microtime(true) - $this->startMicrotime) * 1000, 1);

        // Run before the total is computed, so a log failure is counted in the tally and not
        // merely listed while the arithmetic ignores it.
        $this->checkLogs();

        $total = $this->passed + $this->failed;

        echo "\n══════════════════════════════════════\n";
        echo "  RESULTS\n";
        echo "  {$this->passed}/{$total} passed";
        if ($this->skipped > 0) echo ", {$this->skipped} skipped";
        echo "\n";
        echo "  Assertions: {$this->assertionCount}\n";

        if ($this->failed > 0) {
            echo "\n  ❌ FAILURES:\n";
            foreach ($this->results as $r) {
                if ($r['status'] === 'fail') {
                    echo "    • [{$r['section']}] {$r['label']}" . ($r['detail'] ? ": {$r['detail']}" : '') . "\n";
                }
            }
        }

        if (!empty($this->gaps)) {
            echo "\n  🔍 GAPS FOUND:\n";
            foreach ($this->gaps as $section => $items) {
                echo "    [{$section}]\n";
                foreach ($items as $g) echo "      • {$g}\n";
            }
        }

        echo "\n  Suite: {$this->suiteName}\n";
        echo "  Time: " . round($elapsed / 1000, 2) . "s\n";
        echo "══════════════════════════════════════\n\n";

        // Repair again on the way out. The run itself writes cache entries — compiled
        // templates, but also the kernel state cache and workflow seeds — and those are
        // exactly the ones the web user could not overwrite afterwards. Repairing only on
        // the way in undid yesterday's damage and did nothing about the damage this run was
        // about to cause.
        $this->relaxCompiledTemplateCache();

        $this->writeResults($elapsed);

        exit($this->failed > 0 ? 1 : 0);
    }

    // ─── Utilities ───────────────────────────────────────────────

    public function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    public function loadModule(string $path): bool
    {
        $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
        if (!file_exists($full)) {
            $this->echoWarn("Module file not found: {$path}");
            return false;
        }
        require_once $full;
        return true;
    }

    /**
     * Fail the run if it wrote to a log.
     *
     * This used to echo a warning and nothing else, so "the logs are clean" was never an
     * enforced property — a suite could log errors and still pass. It is now a failure, and
     * it measures growth against the baseline rather than the file's total size.
     *
     * A caveat worth stating: traffic to the web server during a suite also grows app.log, and
     * this cannot tell the two apart. On a quiet development machine that is not a problem; on
     * a busy one it may report the web server's activity as the suite's.
     */
    private function checkLogs(): void
    {
        $storage = dirname(__DIR__, 2) . '/storage/logs';

        foreach (['app.log', 'error.log'] as $name) {
            $path = $storage . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $size = (int) filesize($path);
            $baseline = $this->logBaseline[$name] ?? null;

            if ($baseline === null) {
                $this->fail("{$name} was created during the run", $size . ' byte(s)');
                continue;
            }

            $added = $size - $baseline;
            if ($added > 0) {
                $this->fail("{$name} was written during the run", $added . ' byte(s) added');
            }
        }
    }

    private function writeResults(float $elapsed): void
    {
        $data = [
            'suite' => $this->suiteName,
            'started' => $this->startTime,
            'finished' => date('Y-m-d H:i:s'),
            'elapsed_ms' => round($elapsed, 1),
            'summary' => [
                'passed' => $this->passed,
                'failed' => $this->failed,
                'skipped' => $this->skipped,
                'total' => $this->passed + $this->failed,
                'assertions' => $this->assertionCount,
                'exit_code' => $this->failed > 0 ? 1 : 0,
            ],
            'source_fingerprints' => $this->fingerprints,
            'results' => $this->results,
            'gaps' => $this->gaps,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->resultsFile, $json);
        echo "  📄 Results: test_results/{$this->suiteName}.json\n";
    }

    private function export(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_string($value)) return "'{$value}'";
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_array($value)) {
            $keys = array_keys($value);
            $trunc = count($keys) > 10 ? ', ...' : '';
            return '[' . implode(', ', array_slice($keys, 0, 10)) . $trunc . ']';
        }
        if (is_object($value)) {
            return 'object(' . get_class($value) . ')';
        }
        return gettype($value);
    }

    private function echoWarn(string $msg): void
    {
        echo "  ⚠ {$msg}\n";
    }
}

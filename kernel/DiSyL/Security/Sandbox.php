<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Security;

require_once __DIR__ . '/CapabilitySet.php';

/**
 * DiSyL 4.4 — Sandbox runtime.
 *
 * Stack-based capability scope tracking. The TemplateEngine pushes a new
 * scope on entering a {sandbox}/{trusted}/{untrusted} block and pops it on
 * exit. Gated operations consult the current top scope via require().
 *
 * Violations are recorded in a JSON audit log under
 * `storage/cache/disyl-sandbox/violations.json` for offline analysis. In
 * dev mode, the engine renders an inline comment at the violation site;
 * in strict mode (policy='strict'), violations throw SandboxViolation.
 *
 * Out of scope (queued for 4.4.1):
 *   - DB-backed `disyl_sandbox_violations` table
 *   - AST-time annotation (current impl is runtime-only)
 *   - Auto-wrapping of every cmsRender() boundary in `untrusted`
 *   - Per-cache-fragment cap-set hash binding
 *
 * 4.4.1 resource limits (shipped):
 *   - CPU time limit per sandbox block (default 5s)
 *   - Memory growth limit per sandbox block (default 16 MB)
 *   - Limits checked on pop(); violations logged to audit file
 */
final class Sandbox
{
    /** Maximum audit log size before rotation (bytes). */
    private const MAX_AUDIT_BYTES = 10 * 1024 * 1024; // 10 MB

    /** Retention window for rotated audit logs (days). */
    private const AUDIT_RETENTION_DAYS = 7;

    /** @var list<CapabilitySet> */
    private array $stack;

    /** @var bool */
    private bool $strict = false;

    /** @var string */
    private string $auditRoot;

    /** @var array<string,mixed> */
    private array $auditContext = [];

    /** @var bool If true, an `untrusted` block is currently active anywhere on the stack. */
    private bool $untrustedActive = false;

    // ── 4.4.1 resource limits ──────────────────────────────────────

    /** @var list<array{started_at:float, start_memory:int, cpu_limit_s:float, mem_limit_bytes:int}> */
    private array $resourceStack = [];

    /** Default CPU time limit per sandbox block (seconds, 0 = unlimited). */
    private float $defaultCpuLimitS = 5.0;

    /** Default memory growth limit per sandbox block (bytes, 0 = unlimited). */
    private int $defaultMemLimitBytes = 16 * 1024 * 1024; // 16 MB

    public function __construct(?CapabilitySet $initial = null, ?string $auditRoot = null)
    {
        $this->stack = [$initial ?? CapabilitySet::full()];
        $this->auditRoot = $auditRoot
            ?? (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage')
                . '/cache/disyl-sandbox';
        if (!is_dir($this->auditRoot)) @mkdir($this->auditRoot, 0750, true);
    }

    public function setStrict(bool $strict): void { $this->strict = $strict; }

    /** @param array<string,mixed> $ctx */
    public function setAuditContext(array $ctx): void { $this->auditContext = $ctx; }

    public function current(): CapabilitySet { return $this->stack[count($this->stack) - 1]; }

    public function depth(): int { return count($this->stack); }

    /** @param list<string> $deny @param list<string> $allow */
    public function pushSandbox(array $deny, array $allow = [], bool $policyStrict = false, float $cpuLimitS = 0, int $memLimitBytes = 0): void
    {
        $base = $this->current();
        if ($policyStrict) {
            $this->stack[] = CapabilitySet::strict();
        } else {
            $this->stack[] = $base->narrow($deny, $allow);
        }
        // Track resource usage for limits check on pop()
        $this->resourceStack[] = [
            'started_at'      => microtime(true),
            'start_memory'    => memory_get_usage(true),
            'cpu_limit_s'     => $cpuLimitS > 0 ? $cpuLimitS : $this->defaultCpuLimitS,
            'mem_limit_bytes' => $memLimitBytes > 0 ? $memLimitBytes : $this->defaultMemLimitBytes,
        ];
    }

    public function pushTrusted(): bool
    {
        if ($this->untrustedActive) {
            $this->record('SANDBOX_TRUSTED_INSIDE_UNTRUSTED', 'trusted', '');
            // Still push something to keep stack balanced; force strict.
            $this->stack[] = CapabilitySet::strict();
            return false;
        }
        // Trusted = re-elevate to caller's full grant set.
        $this->stack[] = $this->stack[0];
        return true;
    }

    public function pushUntrusted(): void
    {
        $this->untrustedActive = true;
        $this->stack[] = CapabilitySet::strict();
    }

    public function pop(): void
    {
        if (count($this->stack) > 1) {
            array_pop($this->stack);
        }
        // Recompute untrustedActive: true only if any frame on the stack was set
        // explicitly by pushUntrusted. Stack-frame metadata isn't tracked, but
        // since untrusted forces strict and child frames can't widen, an
        // untrusted region remains effectively in force until that frame pops.
        // Conservative recovery: only clear when we return to base.
        if (count($this->stack) === 1) $this->untrustedActive = false;

        // ── 4.4.1: check resource limits on sandbox exit ──────────
        if ($this->resourceStack !== []) {
            $res = array_pop($this->resourceStack);
            $elapsed  = microtime(true) - (float)($res['started_at'] ?? 0);
            $memDelta = memory_get_usage(true) - (int)($res['start_memory'] ?? 0);
            $cpuLimit = (float)($res['cpu_limit_s'] ?? 0);
            $memLimit = (int)($res['mem_limit_bytes'] ?? 0);

            if ($cpuLimit > 0 && $elapsed > $cpuLimit) {
                $this->record('SANDBOX_CPU_LIMIT', 'sandbox', 'cpu', sprintf('%.2fs/%.2fs', $elapsed, $cpuLimit));
            }
            if ($memLimit > 0 && $memDelta > $memLimit) {
                $this->record('SANDBOX_MEM_LIMIT', 'sandbox', 'memory', sprintf('%.1fMB/%.1fMB', $memDelta / 1048576, $memLimit / 1048576));
            }
        }
    }

    /**
     * Configure default resource limits for sandbox blocks.
     *
     * @param float $cpuLimitS  Max CPU seconds per sandbox block (0 = unlimited).
     * @param int   $memBytes   Max memory growth per sandbox block (0 = unlimited).
     */
    public function setResourceLimits(float $cpuLimitS, int $memBytes): void
    {
        $this->defaultCpuLimitS = max(0.0, $cpuLimitS);
        $this->defaultMemLimitBytes = max(0, $memBytes);
    }

    /**
     * Gate an operation. Returns true if allowed; on denial, records and
     * either throws (strict) or returns false (caller renders skip-comment).
     */
    public function require(string $tag, string $op, string $sample = ''): bool
    {
        if ($this->current()->allows($tag)) return true;
        $this->record('SANDBOX_DENIED', $tag, $op, $sample);
        if ($this->strict) {
            throw new SandboxViolation("Sandbox denied: $tag ($op)");
        }
        return false;
    }

    private function record(string $code, string $tag, string $op, string $sample = ''): void
    {
        $row = [
            'code'    => $code,
            'tag'     => $tag,
            'op'      => $op,
            'sample'  => $this->redact(substr($sample, 0, 200)),
            'context' => $this->auditContext,
            'at'      => time(),
        ];
        $f = $this->auditRoot . '/violations.json';
        $this->rotateAuditLogIfNeeded($f);
        $rows = [];
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) $rows = $decoded;
        }
        $rows[] = $row;
        @file_put_contents($f, json_encode($rows), LOCK_EX);
    }

    /**
     * Rotate the violations audit log when it exceeds the size cap.
     * Moves violations.json → violations-YYYYMMDD.json (replacing the file if
     * the same-day rotated file already exists), then prunes logs older than
     * the retention window so disk usage stays bounded.
     */
    private function rotateAuditLogIfNeeded(string $f): void
    {
        if (!is_file($f)) return;
        if (filesize($f) < self::MAX_AUDIT_BYTES) return;

        $rotated = $this->auditRoot . '/violations-' . date('Ymd') . '.json';
        if (is_file($rotated)) {
            @unlink($f); // same-day rotation already exists — drop current buffer
        } else {
            @rename($f, $rotated);
        }
        $this->pruneOldAuditLogs();
    }

    /**
     * Remove rotated audit logs older than the retention window.
     */
    private function pruneOldAuditLogs(): void
    {
        $cutoff = strtotime('-' . self::AUDIT_RETENTION_DAYS . ' days');
        if ($cutoff === false) return;
        foreach (glob($this->auditRoot . '/violations-*.json') ?: [] as $file) {
            if (preg_match('/violations-(\d{8})\.json$/', $file, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false && $ts < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Redact obvious secrets from audit samples (passwords, bearer tokens).
     */
    private function redact(string $s): string
    {
        $s = preg_replace('/("password"\s*:\s*")[^"]*(")/i', '$1***$2', $s) ?? $s;
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1***', $s) ?? $s;
        return $s;
    }

    /** @return list<array<string,mixed>> */
    public function readViolations(): array
    {
        $f = $this->auditRoot . '/violations.json';
        if (!is_file($f)) return [];
        $raw = @file_get_contents($f);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    public function clearViolations(): void
    {
        @unlink($this->auditRoot . '/violations.json');
    }
}

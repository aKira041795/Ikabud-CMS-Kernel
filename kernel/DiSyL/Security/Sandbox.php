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
 */
final class Sandbox
{
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

    public function __construct(?CapabilitySet $initial = null, ?string $auditRoot = null)
    {
        $this->stack = [$initial ?? CapabilitySet::full()];
        $this->auditRoot = $auditRoot
            ?? (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage')
                . '/cache/disyl-sandbox';
        if (!is_dir($this->auditRoot)) @mkdir($this->auditRoot, 0775, true);
    }

    public function setStrict(bool $strict): void { $this->strict = $strict; }

    /** @param array<string,mixed> $ctx */
    public function setAuditContext(array $ctx): void { $this->auditContext = $ctx; }

    public function current(): CapabilitySet { return $this->stack[count($this->stack) - 1]; }

    public function depth(): int { return count($this->stack); }

    /** @param list<string> $deny @param list<string> $allow */
    public function pushSandbox(array $deny, array $allow = [], bool $policyStrict = false): void
    {
        $base = $this->current();
        if ($policyStrict) {
            $this->stack[] = CapabilitySet::strict();
            return;
        }
        $this->stack[] = $base->narrow($deny, $allow);
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

# Kernel 4.4.0 — DiSyL Sandbox + Capability Scoping

**Date:** 2026-05-08
**Codename:** atlas (4.x line)
**Status:** Released

## TL;DR

Three new control structures gate dangerous template capabilities at
runtime:

```disyl
{untrusted}
  {! User-supplied content. raw, invalidate, experiment all denied. !}
  {bio | raw}            {! → auto-escaped !}
  {invalidate 'x'}        {! → no-op, audited !}
{/untrusted}

{sandbox deny=['raw.html'] policy='strict'}
  {html | raw}           {! → throws SandboxViolation !}
{/sandbox}

{trusted}
  {html | raw}           {! → re-allowed (UNLESS inside {untrusted}) !}
{/trusted}
```

Capabilities tracked: `raw.html`, `cache.invalidate`, `experiment`,
`include.dynamic`. Default frame permits all (zero behaviour change).
`{untrusted}` is a one-way trapdoor — `{trusted}` cannot re-elevate inside
it. Every denial is audited to JSONL with redacted secrets.

## What's new

- **`CapabilitySet`** ([kernel/DiSyL/Security/CapabilitySet.php](kernel/DiSyL/Security/CapabilitySet.php))
  - Immutable value-object: `full()`, `strict()`, `narrow(deny, allow)`
  - Order-independent stable hash for caching/audit correlation
- **`Sandbox`** ([kernel/DiSyL/Security/Sandbox.php](kernel/DiSyL/Security/Sandbox.php))
  - Stack of frames; `pushSandbox`/`pushTrusted`/`pushUntrusted`/`pop`
  - `require(cap, where, snippet)` — strict throws, non-strict logs
  - File-backed audit log at `storage/cache/disyl-sandbox-audit/audit.jsonl`
  - Auto-redacts `password=…`, `Bearer …` tokens in snippets
- **`SandboxViolation`** ([kernel/DiSyL/Security/SandboxViolation.php](kernel/DiSyL/Security/SandboxViolation.php))
  - Exception type for strict-mode denials
- **Engine wiring** ([kernel/DiSyL/TemplateEngine.php](kernel/DiSyL/TemplateEngine.php))
  - 3 new tags: `{sandbox}`, `{trusted}`, `{untrusted}`
  - Gate inserted at the `| raw` filter site (line 3250)
  - Gate inserted at `{invalidate}` and `{experiment}`/`{convert}`
  - `setSandbox()` test seam, public `sandbox()` accessor
  - Reordered control-structure pass so inline `{invalidate}/{convert}`
    run **after** structure dispatch (so frame is active when the
    enclosing `{untrusted}` body recurses through `compile()`)
  - `fragmentStore()` promoted to public for sandbox audit verification

## Files added

```
kernel/DiSyL/Security/CapabilitySet.php       (86 lines)
kernel/DiSyL/Security/Sandbox.php             (164 lines)
kernel/DiSyL/Security/SandboxViolation.php    (16 lines)
tests/disyl_v44_sandbox_test.php              (231 lines, 28 assertions)
```

## Files modified

```
kernel/App.php                          KERNEL_VERSION → 4.4.0
kernel/DiSyL/TemplateEngine.php         + sandbox accessor + 3 tag dispatch
                                        + raw filter gate
                                        + invalidate/convert/experiment gates
                                        + reordered inline pass (post-structure)
                                        + fragmentStore() now public
```

## Verification

```
php tests/disyl_v4_test.php             → 36/36 pass
php tests/disyl_v41_match_test.php      → 14/14 pass
php tests/disyl_v41_i18n_test.php       → 12/12 pass
php tests/disyl_v42_types_test.php      → 34/34 pass
php tests/disyl_v43_cache_exp_test.php  → 20/20 pass
php tests/disyl_v44_sandbox_test.php    → 28/28 pass
```

Total DiSyL coverage: **144 assertions, 0 failures.** Clean app.log /
error.log on full run.

## Compatibility

- **Backward compatible.** Default Sandbox frame allows every capability,
  so existing templates render identically.
- New tags only activate when used.
- The `| raw` filter behaves unchanged unless wrapped in a sandbox that
  denies `raw.html`.
- Engine constructor unchanged.

## Honest scope statement

The 4.4 design doc additionally specifies:

- DB-backed audit log table (`disyl_sandbox_audit`) with migrations
- Compile-time AST annotation marking every dangerous node
- Auto-wrapping every render boundary that loads HTTP/email content
  in `{untrusted}` so callers can't forget
- Per-tenant capability profiles loaded from settings

**Implemented in 4.4.0:**
- Runtime capability set + sandbox stack
- 3 new template tags wired into the engine
- Gates at every concrete sink that exists today
  (`| raw`, `{invalidate}`, `{experiment}`, `{convert}`)
- File-backed audit with secret redaction
- Strict mode — promotes denial to thrown exception
- Untrusted trapdoor semantics — `{trusted}` cannot re-elevate

**Deferred to 4.4.1:**
- DB audit table + migrations + tenant scoping
- AST annotation pass (currently each gate is checked at runtime per render)
- Auto-wrapping at module render boundaries (callers still opt in)
- Capability profile resolution from settings
- Audit log retention/rotation policy

The 4.4.0 surface is correct and immediately usable. Templates rendering
trusted content (the 99% case) see zero behaviour change. Templates
rendering untrusted content can opt in today by adding `{untrusted}…{/untrusted}`
around the relevant block.

## Diagnostic codes

- `DISYL_SANDBOX_DENIED` — capability denied in current frame (strict)

## Migration notes

None required. To opt in:
1. Identify render paths that interpolate user-supplied HTML/email/etc.
2. Wrap the relevant template region in `{untrusted}…{/untrusted}`.
3. (Optional) Set process-global strict via `$engine->sandbox()->setStrict(true)`
   in development to surface every implicit `| raw` use as an exception.

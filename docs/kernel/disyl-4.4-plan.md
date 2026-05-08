# DiSyL 4.4 — Sandbox + Capability Scoping

**Target kernel:** 4.4.0
**Risk:** high (security-critical; gets a CVE if wrong)
**Depends on:** 4.2 (type system) and 4.3 (cache for trust-bound fragments)

## Goals

Lexically-scoped capability boundaries inside a template. A `sandbox` block
is denied a configurable set of capabilities (filesystem, network, db writes,
raw-html output, AI, federation, …) **enforced by the engine itself**, not by
the calling module.

This is the single biggest differentiator vs other template engines (Liquid is
sandboxed globally; Jinja's sandbox is class-based; nothing offers a region).

## Non-goals

- Not a general PHP sandbox. Only DiSyL operations are gated; the underlying
  PHP can still do whatever the kernel allows.
- No CPU/memory limits in 4.4 (queued for 4.4.1).

## Surface

### Syntax

```disyl
{[ sandbox deny=['network','db.write','raw.html','ai'] ]}
  {{ user_supplied_template_body | render }}
{[ endsandbox ]}

{[ trusted ]}
  {{ admin.html_widget | raw }}    {{# allowed because outer trusted #}}
{[ endtrusted ]}

{[ untrusted source=request.body ]}
  {{# everything inside is forced through the most restrictive policy #}}
{[ enduntrusted ]}
```

Rules:
- `sandbox` accepts:
  - `deny=[...]` — capability tags to revoke for the block
  - `allow=[...]` — capability tags to *permit* (intersection with caller's grants)
  - `policy='strict'` — shorthand for the most restrictive bundle
- `trusted` blocks elevate the inner content's allow-set to the caller's full
  grants. Forbidden inside an `untrusted` block (parse error).
- `untrusted` blocks force the most restrictive policy regardless of caller
  grants. Required wrapper for any rendered tenant- or end-user-supplied template.
- Nesting: capability sets compose by intersection; you can never widen.

### Capability tags (initial set)

| Tag | Gate |
|---|---|
| `raw.html` | `\| raw` filter, raw `{{!= }}` output |
| `db.read` | DB read filters/functions |
| `db.write` | Any handler-render side-effecting tag |
| `network` | `fetch`, `federated_query`, `remote` (4.6) |
| `filesystem` | `include` of paths outside the template root |
| `ai` | `ai_*` tags (4.6) |
| `experiment` | `experiment`/`variant`/`convert` |
| `cache.invalidate` | `invalidate` tag |
| `script` | Inline `<script>` rendering |

### Engine integration

New `kernel/DiSyL/Security/CapabilitySet.php`:
- Immutable bag with `intersect(array $deny, array $allow): self`.
- Stored in the render context under `_disyl_caps`.

`kernel/DiSyL/Security/Sandbox.php`:
- Walked AST is annotated with the *effective* CapabilitySet at each node.
- Compiler emits runtime checks at the smallest enforcement point.

Every gated tag/filter consults the current CapabilitySet. Denied → throws
`SandboxViolation`, which is caught at the nearest sandbox boundary and
rendered as either:
- silent skip (default)
- inline `<!-- sandbox-denied: TAG -->` comment in dev
- hard 500 in `policy='strict'`

### Audit

Every denial writes a row to `disyl_sandbox_violations` with:
template, line, capability_tag, requested_op, subject (user_id), tenant_id,
request_id, sample of denied input (first 200 bytes, redacted).

### Tests

`tests/disyl_v44_sandbox_test.php`:
1. `raw` filter inside sandbox without `raw.html` → denied + comment
2. Nested sandboxes intersect correctly
3. `trusted` inside `sandbox` → parse error
4. `untrusted` block forces strict regardless of `allow=`
5. Capability denial logs to audit table with redaction
6. Strict mode raises 500
7. Filesystem-bounded include: outside template root denied even if `filesystem` allowed
8. Capability set survives across `include` of sub-template
9. Capability set propagates into 4.3 `cache` body — cached fragment carries the cap-set hash

### Migration

- All existing tenant-rendered templates wrapped in `untrusted` by default at
  the `cmsRender()` boundary. CMS admin templates marked `trusted` explicitly.
- Adds a one-time grant manifest entry: every module declares which capability
  tags its templates need. Guard checks unused / missing grants.

## Acceptance

- All tests pass.
- Pen-test scenario: tenant-supplied template attempting raw HTML, network,
  db write, AI, filesystem traversal — all denied with audit rows.
- No regression on prior suites.
- Sandbox check overhead < 5% on a 100-block render benchmark.

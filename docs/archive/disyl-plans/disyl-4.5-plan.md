# DiSyL 4.5 — Async Runtime ✅ Shipped

**Target kernel:** 4.5.0  
**Status:** Released — see [release notes](../releases/release-notes-2026-05-08-kernel-4.5.md)
**Risk:** highest (new execution model; fiber scheduler)
**Depends on:** 4.4 (sandbox must gate `fetch` before it ships)

## Goals

Declarative concurrent IO inside a render: parallelize independent fetches,
stream skeletons until each resolves, fall back gracefully on error.

PHP has no native promises; this release builds a Fibers-based scheduler.

## Non-goals

- No general async/await across handler boundaries — async is *render-local*.
- No bidirectional streaming. Output is one-direction: server → client.
- No HTTP/2 server push primitives in 4.5.

## Surface

### Syntax

```disyl
{[ parallel ]}
  {[ await let=hero src=fetch('/api/hero') timeout=200 ]}
    <h1>{{ hero.title }}</h1>
  {[ loading ]}
    <h1 class="skeleton"></h1>
  {[ catch let=err ]}
    <h1>Welcome</h1>
  {[ endawait ]}

  {[ await let=stories src=fetch('/api/stories?limit=3') timeout=400 ]}
    {[ for s in stories ]}<article>{{ s.title }}</article>{[ endfor ]}
  {[ loading ]}
    <article class="skeleton"></article>
  {[ endawait ]}
{[ endparallel ]}

{[ suspense fallback=<spinner /> ]}
  {[ await let=detail src=fetch('/api/' + product.id) ]}
    {{ detail.body }}
  {[ endawait ]}
{[ endsuspense ]}
```

Rules:
- `parallel` block runs all immediate `await` children concurrently. Sequential
  awaits run inside a `then` chain.
- `await` requires `let=` and `src=`. Optional `timeout=ms`. Optional `then=EXPR`
  for a transformation applied to the resolved value.
- `loading` and `catch` arms are optional; if absent, loading renders empty and
  errors propagate to the nearest `suspense` boundary.
- `suspense fallback=...` catches loading + error states from descendants and
  renders the fallback until everything inside resolves.

### Streaming protocol

Output written in chunks:
1. Initial chunk: outer HTML with `<template id="disyl-slot-N">FALLBACK</template>` placeholders.
2. As each `await` resolves, append `<template id="disyl-fill-N">RESOLVED</template>` plus a tiny inline replacer script.

Inline replacer is **5 lines of vanilla JS** (no framework dependency), emitted
once per response by the engine.

### Architecture

`kernel/DiSyL/Async/Scheduler.php`:
- One `\Fiber` per `await`.
- Round-robin tick until all fibers resolve or timeout.
- `Scheduler::run(array $tasks): array` returns ordered results.

`kernel/DiSyL/Async/HttpClient.php`:
- Multi-curl backend with selectable handles.
- `fetch(string $url, array $opts): Promise` returns a Promise with `then`/`catch`.

`kernel/DiSyL/Async/Promise.php`:
- Minimal Promise A+ subset; no public chaining surface beyond `then`/`catch`.

### Sandbox interaction

`fetch` requires the `network` capability. `parallel` and `await` themselves
require no capability beyond what their `src` needs. `suspense` is always allowed.

### Cache interaction

`await src=fetch(...)` results are cacheable via the 4.3 cache layer when
wrapped in `{[ cache ... ]}`; cache key must include the awaited args.

### Determinism

Bucketing for experiments (4.3) and AI seeds (4.6) must remain deterministic
across SSR. Fibers do not break determinism because the scheduler always
returns results in source order regardless of resolution order.

### Errors

`DISYL_AWAIT_NO_LET`, `DISYL_AWAIT_NO_SRC`, `DISYL_AWAIT_TIMEOUT`,
`DISYL_PARALLEL_EMPTY`, `DISYL_SUSPENSE_NESTED_LIMIT` (3 levels).

### Tests

`tests/disyl_v45_async_test.php`:
1. Two parallel fetches finish in `max(t1, t2)` not `t1 + t2`
2. Sequential `await` chains in declaration order
3. `loading` renders before resolution
4. `catch` swallows error and renders fallback
5. `suspense` fallback shows until all descendants resolve
6. `timeout` triggers `catch` with timeout error
7. Sandbox without `network` cap → `fetch` denied
8. Stream chunks emitted in correct order
9. 50 concurrent awaits in one render don't exhaust file descriptors
10. Determinism: same input → byte-identical output across runs

## Acceptance

- All tests pass.
- Benchmark: page with 5 independent fetches at 100 ms each renders in ≤ 150 ms
  end-to-end (vs ~500 ms sequential today).
- No regression on prior suites.
- Memory ceiling: each fiber ≤ 256 KB resident; scheduler caps at 64 concurrent
  fibers per render.

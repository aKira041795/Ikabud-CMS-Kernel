# DiSyL Overview

## What DiSyL Is

DiSyL, the Declarative Ikabud Syntax Language, is the kernel's native rendering language and UI runtime.

It started as a server-side template layer, but it is no longer just a templating engine. In the current architecture, DiSyL is the rendering contract that ties together:

- server-rendered pages and layouts
- reusable components and slot composition
- compiled and interpreted render paths
- request-aware reactive client blocks
- progressive hydration islands
- capability-aware and context-aware rendering

DiSyL gives the platform one rendering model across CMS pages, module UIs, entity views, theme layers, and progressively enhanced interactive surfaces.

## What DiSyL Includes Now

DiSyL is best understood as a stack, not a single parser.

### 1. Rendering language

The base syntax handles:

- layouts and inheritance
- blocks and includes
- variables and dot-path access
- filters and expression pipelines
- conditions, loops, and composition

This is the part most people first recognize as "templating."

### 2. Component model

DiSyL supports reusable components with:

- props
- named slots
- scoped styles
- methods, events, and client behavior
- state, computed values, and watchers

That means teams can build UI primitives once and reuse them across modules without introducing a separate component framework for every surface.

### 3. Reactive runtime

DiSyL includes request-aware interactivity primitives for:

- signal-style state
- server-driven partial refresh patterns
- HTMX-friendly response behavior
- secure client blocks for progressive enhancement

This lets the platform stay server-first while still supporting rich interaction.

### 4. Hydration layer

DiSyL can render SSR-first islands that hydrate later based on strategy:

- on page load
- on idle
- when visible
- on interaction
- under media-query conditions

This gives developers a controlled way to add interactivity without turning the whole page into a client app.

### 5. Execution pipeline

DiSyL now spans both:

- interpreted rendering for compatibility and flexible execution
- compiled rendering for performance and production throughput

The important architectural point is that both paths still belong to the same language/runtime model.

### 6. DiSyL 4.x runtime additions (kernel ≥ 4.0)

The 4.x line introduced six tag families that extend the rendering language with cross-cutting platform capabilities. They are part of the same single-pass parser and respect the same escape, cache, and sandbox contracts as the rest of DiSyL.

- **4.0** — single-pass control structure processor; same surface, faster execution.
- **4.1** — `{match}` pattern matching, `{trans}` i18n.
- **4.2** — type system v1 (`scripts/disyl-typecheck.php`); progressive opt-in.
- **4.3** — `{cache}` fragment store with tag invalidation; `{experiment}` deterministic A/B bucketing.
- **4.4** — sandbox + capability scoping: `{sandbox}`/`{trusted}`/`{untrusted}`. Capabilities (`raw.html`, `cache.invalidate`, `experiment`, `network`, `ai`, `federation`) gate every dangerous template sink.
- **4.5** — async runtime: `{parallel}`/`{await}`/`{suspense}` with `{loading}`/`{catch}` arms. Source-order deterministic. Sync execution today; Fibers concurrency in 4.5.1 with no template changes.
- **4.6** — federation (`{federated_query}`/`{remote}`/`{aggregate}`) and pinned AI primitives (`{ai_generate}`/`{ai_query}`/`{ai_complete}`) under a unified Policy (kill switch, allowlist, cost ceiling, max_tokens cap).

184 unit tests cover the 4.x surface end-to-end. See per-release notes under `docs/releases/` for honest-scope statements (what shipped, what's deferred to point releases) and the [DiSyL 4.x Capabilities table in the module guide](module-development-guide.md#disyl-4x-capabilities-kernel--40) for the module-author summary.

## Async Rendering Convention (`{parallel}` / `{await}` / `{suspense}`)

### When to use async in templates

The rule: **use `{parallel}` when a single render needs data from 2+ independent sources, and none depends on another's result.** In all other cases, fetch data in the handler and pass it via `$context`.

```disyl
{[ parallel ]}
  {[ await let=products src=fetch('/api/products?featured=3') timeout=400 ]}
    {[ for p in products ]}<article>{{ p.name }}</article>{[ endfor ]}
  {[ loading ]}<article class="skeleton">Loading products...</article>{[ endawait ]}

  {[ await let=stats src=fetch('/api/stats/dashboard') timeout=200 ]}
    <div class="stats">{{ stats.orders }} orders today</div>
  {[ endawait ]}
{[ endparallel ]}
```

### When this scenario happens (real-world triggers)

Async rendering is for data that **can only be resolved during render**, not before:

| Scenario | Why async at render time? |
|---|---|
| **Entity-view resolution via polyglot service** | Template renders `{ikb_entity_list source="weather.current"}`, EntityViewResolver calls a Python service via ServiceProxy — this happens inside the render pipeline |
| **Cross-module capability composition** | A dashboard page aggregates data from CMS (content stats) + ecommerce (order count) + guidance (case count) — each `{await}` dispatches to a different module's capability |
| **External API enrichment** | Product detail page needs reviews (external service) + stock level (WMS capability) + related products (ecommerce capability) — all independent |
| **Federated queries** | `{federated_query}` composes data from multiple services; the `{parallel}` concurrency is built-in |
| **Builder-composed pages** | A visual builder page with multiple entity widgets — each widget's data source resolves independently during render |

### When NOT to use async

| Situation | Right approach |
|---|---|
| Data available before render | Fetch in handler, pass via `$context` — no `{await}` needed |
| One data source drives the next | Sequential `{await}` outside `{parallel}` (or fetch both in handler) |
| Single data source | Just pass it in `$context` |
| Simple DB query | Handler-level — no template async overhead |

```disyl
{# ❌ WRONG: user.id needed by second call, but parallel blocks don't share state #}
{[ parallel ]}
  {[ await let=user src=fetch('/api/user') ]}...{[ endawait ]}
  {[ await let=orders src=fetch('/api/orders/' + user.id) ]}...{[ endawait ]}
{[ endparallel ]}

{# ✅ RIGHT: fetch user in handler, pass both user + orders in context #}
```

### Streaming protocol (how it renders)

Today (4.5.0 sync scheduler): each `{await}` resolves sequentially in source order. The `{loading}` arm renders while waiting, `{catch}` on error.

When Fibers land (4.5.1): all `{await}` blocks in a `{parallel}` will run concurrently. Templates that use `{parallel}` today will automatically get the speedup — **zero markup changes required.** The output is always source-order deterministic regardless of resolution order.

### Quick reference

| Tag | Purpose |
|---|---|
| `{parallel}` | Wraps multiple `{await}` blocks for concurrent resolution |
| `{await let=X src=... timeout=N}` | Fetches data, binds to `X`, renders body on success |
| `{loading}` | Renders while `{await}` is waiting |
| `{catch let=err}` | Renders on error (timeout, HTTP failure) |
| `{suspense fallback=...}` | Single fallback for an entire section — catches loading/error from all descendants |

## Developer Advantage

### One rendering model across the platform

Developers do not have to stitch together one template engine, one separate component DSL, one separate hydration layer, and one unrelated progressive-enhancement story. DiSyL provides one kernel-native model for all of those concerns.

### Server-first without being stuck in static HTML

DiSyL keeps the performance, operability, and SEO advantages of server rendering, while still giving teams structured interactivity when they need it.

### Safer defaults

DiSyL is designed for controlled rendering in a multi-tenant platform:

- HTML escapes by default
- includes and render paths are constrained
- rendering happens inside the kernel's request and policy model
- module surfaces stay inside the same platform conventions

### Better module ergonomics

Because DiSyL is part of the kernel, modules do not need to invent their own rendering story. Module authors can rely on the same runtime for CMS pages, admin pages, entity displays, shared components, and interactive fragments.

### Lower architectural drift

Without a unified rendering runtime, systems usually drift into multiple UI stacks over time. DiSyL reduces that drift by giving the platform a standard way to render, compose, and enhance UI across modules.

## Why Use DiSyL

Use DiSyL when you want:

- a server-first UI architecture that can still scale to interactive product surfaces
- one rendering language across modules, themes, builder output, and admin UIs
- reusable components without fragmenting into several frontend stacks
- predictable rendering behavior inside a multi-tenant kernel
- progressive enhancement instead of defaulting every feature to a full SPA build
- a runtime that is owned by the platform, not bolted on from unrelated layers

In Ikabud, DiSyL is valuable not only because it renders HTML, but because it gives the kernel a consistent UI contract.

## Practical Scenarios

### CMS pages and marketing sites

Use DiSyL layouts, blocks, widgets, and reusable components to build public-facing pages that stay fast, server-rendered, and easy to theme.

### Admin dashboards and workflow screens

Use DiSyL for forms, tables, detail views, and HTMX-driven updates where full client-side application overhead would add complexity without adding value.

### Multi-tenant branded experiences

Use DiSyL when each tenant needs consistent rendering primitives with different themes, settings, navigation, or module composition, while still staying inside one kernel contract.

### Entity and storefront rendering

Use DiSyL for schema-driven entity views, builder documents, CMS-driven product displays, and other dynamic rendering paths where composition and predictable escaping matter.

### Interactive islands inside server-rendered pages

Use hydration islands when a page is mostly server-rendered but a few areas need richer behavior, such as live filters, contextual controls, or reactive widgets.

### Shared UI primitives across modules

Use the component system when CMS, commerce, guidance, or internal operations modules need the same cards, shells, forms, banners, and display patterns without duplicating markup logic.

## DiSyL Compared To "Just a Template Engine"

Calling DiSyL only a template engine leaves out several parts of the actual system:

- components
- slots
- reactive primitives
- client blocks
- hydration strategies
- compiled execution
- kernel-aware rendering contracts

That older description was accurate earlier in the project. It is incomplete now.

## Where To Read Next

- [ARCHITECTURE.md](ARCHITECTURE.md) for the kernel-level rendering/runtime view
- [disyl-implementation-spec.md](../cms/disyl-implementation-spec.md) for syntax and language behavior
- [disyl-component-system.md](disyl-component-system.md) for single-file components and slots
- [disyl-reactive-system.md](disyl-reactive-system.md) for reactive primitives and HTMX integration
- [disyl-hydration-system.md](disyl-hydration-system.md) for hydration islands and progressive activation
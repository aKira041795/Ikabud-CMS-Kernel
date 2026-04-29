# DiSyL Hydration System

**Subsystem:** `kernel/DiSyL/Hydration/`  
**Status:** Production  
**Last updated:** 2026-04-15

## Overview

The DiSyL Hydration System is the progressive enhancement layer of the broader DiSyL rendering runtime. It enables progressive hydration of server-rendered HTML. Components are rendered as "islands" — self-contained interactive regions — with configurable hydration strategies that control when client-side JavaScript activates. Islands are registered, rendered with SSR markup, and bundled with a client runtime that hydrates them based on their strategy.

## Core Classes

### HydrationStrategy (Enum)

`kernel/DiSyL/Hydration/HydrationStrategy.php`

```php
enum HydrationStrategy: string {
    case LOAD     = 'load';       // Hydrate on page load
    case IDLE     = 'idle';       // Hydrate when browser is idle (requestIdleCallback)
    case VISIBLE  = 'visible';    // Hydrate when island enters viewport (IntersectionObserver)
    case MEDIA    = 'media';      // Hydrate when media query matches
    case NEVER    = 'never';      // Static SSR only, no client JS
    case INTERACT = 'interact';   // Hydrate on first user interaction
    case IMMEDIATE = 'immediate'; // Hydrate immediately (no delay)
}
```

### Island

`kernel/DiSyL/Hydration/Island.php`

A single hydration island instance.

```php
$island = new Island(
    id: 'hero-banner',
    component: 'ui.hero',
    props: ['title' => 'Welcome'],
    strategy: HydrationStrategy::VISIBLE,
    mediaQuery: '(min-width: 768px)',
    fallback: '<div>Loading...</div>'
);
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `string` | Unique island identifier |
| `component` | `string` | Component name (dot notation) |
| `props` | `array` | Props to pass at hydration |
| `strategy` | `HydrationStrategy` | When to hydrate |
| `mediaQuery` | `?string` | CSS media query (for `MEDIA` strategy) |
| `fallback` | `?string` | Placeholder HTML before hydration |

### IslandRegistry

`kernel/DiSyL/Hydration/IslandRegistry.php`

Collects islands during SSR rendering for later manifest generation.

| Method | Purpose |
|--------|---------|
| `register(Island $island): void` | Add island to registry |
| `get(string $id): ?Island` | Retrieve by ID |
| `all(): Island[]` | All registered islands |
| `clear(): void` | Reset for next request |

### IslandManifest

`kernel/DiSyL/Hydration/IslandManifest.php`

Generates the JSON manifest embedded in the HTML page for client-side hydration.

```php
$manifest = new IslandManifest($registry);
echo $manifest->toJson(); // JSON_HEX_TAG-safe output
echo $manifest->toScript(); // <script type="application/json" data-islands>...</script>
```

**Security:** Uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` flags to prevent XSS via `</script>` injection in JSON payloads.

### IslandRenderer

`kernel/DiSyL/Hydration/IslandRenderer.php`

Renders island wrapper HTML with data attributes for client-side discovery.

```php
$renderer = new IslandRenderer($componentRenderer);
echo $renderer->render($island);
```

**Output HTML:**
```html
<div data-island="hero-banner"
     data-component="ui.hero"
     data-hydrate="visible"
     data-props='{"title":"Welcome"}'
     data-media="(min-width: 768px)">
  <!-- SSR content -->
</div>
```

**Security:** All attribute values (`id`, `component`, `strategy`, `mediaQuery`, `props`) are escaped with `htmlspecialchars(ENT_QUOTES)`.

### HydrationRuntime

`kernel/DiSyL/Hydration/HydrationRuntime.php`

Server-side runtime that orchestrates island registration and rendering.

| Method | Purpose |
|--------|---------|
| `registerIsland(string $component, array $props, HydrationStrategy $strategy): Island` | Create and register an island |
| `renderIslands(): string` | Render all registered islands |
| `getManifest(): IslandManifest` | Get manifest for client bundle |

Also exposes `ProgressiveHydration` convenience methods:
- `hydrateOnLoad($component, $props)`
- `hydrateOnIdle($component, $props)`
- `hydrateOnVisible($component, $props)`
- `hydrateOnMedia($component, $props, $mediaQuery)`
- `hydrateOnInteraction($component, $props)`

Each creates an `Island` with an auto-generated ID (`uniqid('island_')`).

### ClientBundleGenerator

`kernel/DiSyL/Hydration/ClientBundleGenerator.php`

Generates the client-side JavaScript that hydrates islands after page load.

```php
$generator = new ClientBundleGenerator($manifest);
echo $generator->generate(); // <script> with hydration runtime
```

**Security:** `modulePath` and `runtimePath` are escaped via `htmlspecialchars()` to prevent injection.

### HydrationData / HydrationContext

Supporting types for serializing hydration state and contextual data.

- `HydrationData`: Wraps serialized props/state for an island
- `HydrationContext`: Carries request-scoped context (locale, auth state) to client

## Client-Side Flow

```
Page loads with SSR HTML + island manifest JSON
       ↓
Client runtime reads manifest
       ↓
For each island, applies strategy:
  - load: immediate hydration
  - idle: requestIdleCallback
  - visible: IntersectionObserver on [data-island]
  - media: matchMedia listener
  - interact: addEventListener (click/focus/mouseover)
  - never: skip
  - immediate: synchronous hydration
       ↓
Imports component module, mounts into [data-island] container
       ↓
Island becomes interactive
```

## Conventions

- Island IDs must be unique per page
- `NEVER` strategy islands produce SSR-only output with no client JS bundle entry
- Manifest JSON is embedded as `<script type="application/json">` (not executable)
- Props are double-encoded: JSON in manifest, HTML-escaped in data attributes

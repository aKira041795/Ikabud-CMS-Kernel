# DiSyL Reactive System

**Subsystem:** `kernel/DiSyL/Reactive/`  
**Status:** Production  
**Last updated:** 2026-04-15

## Overview

The DiSyL Reactive System is the request-aware interactivity layer inside the broader DiSyL rendering runtime. It provides server-driven reactivity through three complementary subsystems:

1. **Signal System** — Fine-grained reactive primitives (`Signal`, `Computed`, `Effect`) for server-side reactive state management
2. **HTMX Integration** — Request/response helpers for HTMX-driven partial page updates
3. **Client Blocks** — Secure inline JavaScript behavior for progressive enhancement

## Signal System

### SignalSystem

`kernel/DiSyL/Reactive/SignalSystem.php`

Contains all reactive primitives in a single file.

#### ReactiveContext (Singleton)

Global reactive tracking context that manages dependency collection and batched updates.

```php
$ctx = ReactiveContext::getInstance();
$ctx->batch(function() use ($signal1, $signal2) {
    $signal1->set(10);
    $signal2->set(20);
    // Effects run once after batch completes
});
```

| Method | Purpose |
|--------|---------|
| `getInstance(): self` | Singleton accessor |
| `track(Signal $signal): void` | Track dependency (called during computed evaluation) |
| `getTracked(): Signal[]` | Get signals tracked since last clear |
| `clearTracked(): void` | Reset tracking |
| `batch(callable $fn): void` | Batch updates, defer effect execution |
| `scheduledEffects` | Queue of effects to run post-batch |

#### Signal

Fine-grained reactive value with subscriber notification.

```php
$count = new Signal(0);
echo $count->get(); // 0 (registers tracking)
$count->set(5);     // notifies subscribers
echo $count->peek(); // 5 (no tracking)
```

| Method | Purpose |
|--------|---------|
| `__construct(mixed $value)` | Initial value |
| `get(): mixed` | Get value + register with ReactiveContext |
| `peek(): mixed` | Get value without tracking |
| `set(mixed $value): void` | Set value, notify subscribers if changed |
| `subscribe(callable $fn): callable` | Subscribe to changes, returns unsubscribe fn |

#### Computed

Derived reactive value that caches until dependencies change.

```php
$doubled = new Computed(fn() => $count->get() * 2);
echo $doubled->get(); // 10 (cached)
$count->set(3);
echo $doubled->get(); // 6 (recomputed)
```

| Method | Purpose |
|--------|---------|
| `__construct(callable $fn)` | Computation function (auto-tracks Signal deps) |
| `get(): mixed` | Get cached value, recompute if dirty |

#### Effect

Side-effect that re-runs when its tracked signals change.

```php
$effect = new Effect(function() use ($count) {
    echo "Count is: " . $count->get();
});
// Prints immediately, re-runs on $count->set()
$effect->dispose(); // stop tracking
```

| Method | Purpose |
|--------|---------|
| `__construct(callable $fn)` | Effect function (runs immediately, re-runs on changes) |
| `dispose(): void` | Unsubscribe from all tracked signals |

## HTMX Integration

### HTMXHeaders (Enum)

`kernel/DiSyL/Reactive/HTMXHeaders.php`

Standard HTMX request/response header names.

```php
HTMXHeaders::HX_REQUEST       // 'HX-Request'
HTMXHeaders::HX_TRIGGER       // 'HX-Trigger'
HTMXHeaders::HX_TARGET        // 'HX-Target'
HTMXHeaders::HX_CURRENT_URL   // 'HX-Current-URL'
HTMXHeaders::HX_PUSH_URL      // 'HX-Push-Url'
HTMXHeaders::HX_REDIRECT      // 'HX-Redirect'
HTMXHeaders::HX_REFRESH       // 'HX-Refresh'
HTMXHeaders::HX_REPLACE_URL   // 'HX-Replace-Url'
HTMXHeaders::HX_RESWAP        // 'HX-Reswap'
HTMXHeaders::HX_RETARGET      // 'HX-Retarget'
HTMXHeaders::HX_TRIGGER_AFTER_SETTLE  // 'HX-Trigger-After-Settle'
HTMXHeaders::HX_TRIGGER_AFTER_SWAP    // 'HX-Trigger-After-Swap'
```

### HTMXRequest

`kernel/DiSyL/Reactive/HTMXRequest.php`

Parses incoming HTMX request headers for server-side decision making.

```php
$req = new HTMXRequest();
if ($req->isHTMX()) {
    $target = $req->target();    // HX-Target value
    $trigger = $req->trigger();  // HX-Trigger value
    $currentUrl = $req->currentUrl();
}
```

### HTMXResponse

`kernel/DiSyL/Reactive/HTMXResponse.php`

Fluent builder for HTMX response headers.

```php
$resp = new HTMXResponse();
$resp->pushUrl('/new-path')
     ->retarget('#content')
     ->reswap('innerHTML')
     ->trigger('itemAdded', ['id' => 42])
     ->triggerAfterSettle('notify')
     ->addOobSwap($oobSwap)
     ->send();
```

| Method | Purpose |
|--------|---------|
| `pushUrl(string $url): self` | Push new URL to browser history |
| `replaceUrl(string $url): self` | Replace current URL |
| `redirect(string $url): self` | Full redirect |
| `refresh(): self` | Full page refresh |
| `retarget(string $selector): self` | Change swap target |
| `reswap(string $strategy): self` | Change swap strategy |
| `trigger(string $event, $detail): self` | Trigger client event |
| `triggerAfterSettle(string $event, $detail): self` | Trigger after DOM settle |
| `triggerAfterSwap(string $event, $detail): self` | Trigger after swap |
| `addOobSwap(OOBSwap $swap): self` | Add out-of-band swap |
| `send(): void` | Emit all response headers |

### HTMXTemplateIntegration

`kernel/DiSyL/Reactive/HTMXTemplateIntegration.php`

Bridges HTMX attributes into DiSyL templates — provides helper functions for generating `hx-get`, `hx-post`, `hx-swap`, `hx-target`, etc. attributes in rendered HTML.

### OOBSwap

`kernel/DiSyL/Reactive/OOBSwap.php`

Out-of-band swap definition for updating multiple DOM targets in a single response.

```php
$swap = new OOBSwap('#notification-count', '3', SwapStrategy::INNER_HTML);
echo $swap->toHtml();
// <div id="notification-count" hx-swap-oob="innerHTML">3</div>
```

**Security:** Target selector and content are escaped via `htmlspecialchars(ENT_QUOTES)`.

### SwapStrategy (Enum)

```php
enum SwapStrategy: string {
    case INNER_HTML   = 'innerHTML';
    case OUTER_HTML   = 'outerHTML';
    case BEFORE_BEGIN = 'beforebegin';
    case AFTER_BEGIN  = 'afterbegin';
    case BEFORE_END   = 'beforeend';
    case AFTER_END    = 'afterend';
    case DELETE       = 'delete';
    case NONE         = 'none';
}
```

### TurboStreamResponse

`kernel/DiSyL/Reactive/TurboStreamResponse.php`

Alternative to HTMX OOB swaps using Turbo Stream format.

```php
$stream = new TurboStreamResponse();
$stream->append('messages', '<div>New message</div>');
$stream->replace('counter', '<span>42</span>');
echo $stream->toHtml();
```

**Security:** All target IDs and content are escaped via `htmlspecialchars(ENT_QUOTES)` to prevent XSS.

## Client Blocks

### ClientBlock

`kernel/DiSyL/Reactive/ClientBlock.php`

Secure inline JavaScript behavior blocks with event whitelist and content sanitization.

```php
$block = new ClientBlock('toggle-menu', 'click', 'this.classList.toggle("open")');
echo $block->render();
// <script data-block="toggle-menu" data-event="click">...</script>
```

**Security measures:**
- Event whitelist: `click`, `submit`, `input`, `change`, `focus`, `blur`, `keydown`, `keyup`, `keypress`, `mouseenter`, `mouseleave`, `mouseover`, `mouseout`, `touchstart`, `touchend`, `scroll`, `resize`, `load`, `DOMContentLoaded`
- ID/event values escaped via `htmlspecialchars()`
- Handler content filtered to strip `<script>` tags

### ClientBlockRegistry

`kernel/DiSyL/Reactive/ClientBlockRegistry.php`

Collects client blocks during rendering for deferred output.

| Method | Purpose |
|--------|---------|
| `register(ClientBlock $block): void` | Add block |
| `renderAll(): string` | Render all blocks as `<script>` tags |
| `clear(): void` | Reset for next request |

### ReactiveState

`kernel/DiSyL/Reactive/ReactiveState.php`

Serializes reactive state for client-side hydration.

```php
$state = new ReactiveState(['count' => 0, 'items' => []]);
echo $state->toJson();   // JSON_HEX_TAG-safe output
echo $state->toScript(); // <script type="application/json" data-reactive-state>
```

**Security:** Uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` encoding flags.

## Conventions

- All HTML output uses `htmlspecialchars(ENT_QUOTES, 'UTF-8')` for XSS prevention
- JSON embedded in HTML uses `JSON_HEX_TAG` family of flags
- Client blocks are limited to whitelisted DOM events
- HTMX request detection relies on `HX-Request` header presence
- Signal subscriptions should be cleaned up via returned unsubscribe callables
- Batch updates via `ReactiveContext::batch()` to minimize redundant effect execution

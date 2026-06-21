# DiSyL Component System

**Subsystem:** `kernel/DiSyL/Component/`  
**Status:** Production  
**Last updated:** 2026-06-11

## Overview

The DiSyL Component System is one part of the broader DiSyL rendering runtime. It implements single-file components with props, slots, reactive state, computed properties, watchers, events, methods, scoped styles, and client-side behavior. Components are defined declaratively via directive-annotated PHP/HTML files and parsed into `ComponentDefinition` objects for rendering.

## Core Classes

### ComponentDefinition

`kernel/DiSyL/Component/ComponentDefinition.php`

Immutable descriptor produced by parsing a single-file component.

| Property | Type | Description |
|----------|------|-------------|
| `name` | `string` | Component name (lowercase, dot-separated) |
| `props` | `array<string, PropDefinition>` | Prop definitions with type, default, required, validator |
| `slots` | `array<string, SlotDefinition>` | Named slots (uses canonical `SlotDefinition` from `SlotSystem.php`) |
| `state` | `array<string, mixed>` | Initial reactive state |
| `computed` | `array<string, string>` | Computed property expressions |
| `watchers` | `array<string, string>` | State watcher expressions |
| `events` | `array<string, EventDefinition>` | Emittable events |
| `methods` | `array<string, callable>` | Component methods |
| `template` | `string` | Template content |
| `style` | `string` | Component styles (optionally scoped) |
| `clientBehavior` | `string` | Client-side JavaScript |

### ComponentInstance

`kernel/DiSyL/Component/ComponentInstance.php`

A live instance of a component definition with resolved props and reactive state.

```php
$instance = new ComponentInstance($definition, ['title' => 'Hello']);
$instance->setState('count', 0);
echo $instance->getState('count');    // 0
echo $instance->getComputed('label'); // cached computed value
```

| Method | Purpose |
|--------|---------|
| `__construct(ComponentDefinition $def, array $props)` | Validates props, initializes state |
| `getState(string $key)` | Get reactive state value |
| `setState(string $key, $value)` | Set state, triggers watchers |
| `getComputed(string $key)` | Get cached computed value — **stub: returns null; actual evaluation happens in the renderer** |
| `getProp(string $key)` | Get resolved prop |
| `emit(string $event, $payload)` | Emit component event |

> **Note:** `getComputed()`, `callMethod()`, and `triggerWatchers()` are currently stubs in `ComponentInstance`. Computed evaluation and watcher execution are handled by the template renderer, not the instance directly.

### ComponentLoader

`kernel/DiSyL/Component/ComponentLoader.php`

Loads component definitions from disk with security and caching.

```php
$loader = new ComponentLoader('/path/to/components');
$def = $loader->load('ui.button'); // loads ui/button.disyl
```

**Security:** Path traversal protection via regex validation — rejects names containing `..`, `/`, `\`, or non-alphanumeric characters beyond dots and hyphens.

**Caching:** Lazy-loaded components are cached in memory for the request lifecycle.

| Method | Purpose |
|--------|---------|
| `load(string $name): ComponentDefinition` | Load/cache component by dotted name |
| `has(string $name): bool` | Check component exists on disk |
| `clear(): void` | Clear in-memory cache |

### ComponentParser

`kernel/DiSyL/Component/ComponentParser.php`

Token-driven parser that converts raw single-file component source into a `ComponentDefinition`.

| Method | Purpose |
|--------|---------|
| `parse(string $source, string $name): ComponentDefinition` | Parse source into definition |

Parses sections: `@props`, `@state`, `@computed`, `@watchers`, `@events`, `@methods`, `@style`, `@client`, and template body.

### SingleFileComponent

`kernel/DiSyL/Component/SingleFileComponent.php`

Alternative parser for directive-style components with scoped style support.

| Feature | Description |
|---------|-------------|
| Directive parsing | `@prop`, `@slot`, `@state`, `@on`, `@computed`, `@watch`, `@method` |
| Scoped styles | Auto-generates `data-scope-{hash}` attributes and rewrites selectors |
| Style extraction | Separates `<style>` from template content |

### SlotSystem / SlotDefinition

`kernel/DiSyL/Component/SlotSystem.php`

Canonical slot definitions shared across the component stack.

```php
$slot = new SlotDefinition('header', false, '<h1>Default</h1>');
echo $slot->name;       // 'header'
echo $slot->required;   // false
echo $slot->default;    // '<h1>Default</h1>'
echo json_encode($slot); // JsonSerializable
```

| Property | Type | Description |
|----------|------|-------------|
| `name` | `string` | Slot name |
| `required` | `bool` | Whether the slot must be filled |
| `default` | `string` | Default content when not filled |

### Slot Template Syntax

Inside a component's `.disyl` template, use the `{slot}` tag to mark injection points:

```disyl
{!-- Self-closing: no default content --}
{slot header}

{!-- Block form: renders default when caller provides nothing --}
{slot footer}
  <p>Default footer</p>
{/slot}
```

`SlotDefinition` objects (PHP layer) declare the contract; `{slot}` tags (template layer) are the rendering counterpart. Names must match between the two.

## Component Lifecycle

```
.disyl file on disk
       ↓
ComponentLoader::load()
       ↓ (path validation)
ComponentParser::parse()  or  SingleFileComponent
       ↓
ComponentDefinition (immutable)
       ↓
ComponentInstance (resolved props, live state)
       ↓
Template rendering (TemplateEngine) + Slot filling (SlotSystem)
       ↓
Scoped style injection + Client behavior attachment
```

---

## Bridge System — Framework-Agnostic Component Output

**Location:** `kernel/DiSyL/Bridge/`  
**Status:** Production (v1.0.0)  
**Last updated:** 2026-06-21

### What Bridges Solve

`{ikb_component}` and `{state}` blocks need to render server-side data into HTML that a client-side framework can use. Different modules use different frontend stacks — Alpine.js (CMS, attendance-wage), HTMX (guidance), or custom JS. The bridge system decouples the DiSyL component data from the framework-specific markup, letting each template choose its target framework per-invocation.

### Architecture

```
{ikb_component name="..." data="..." bridge="..."}
       ↓
TemplateEngine::renderIkbComponent()
       ↓  resolves data from context, serializes to JSON
BridgeManager::resolve('alpine'|'htmx'|'custom')
       ↓
BridgeInterface::renderComponent(name, json, children, attrs)
       ↓
Framework-specific HTML  (e.g. x-data, hx-vals, data-ikb-data)
```

### Bridge Interface

Every bridge implements `Ikabud\Kernel\DiSyL\Bridge\BridgeInterface`:

```php
interface BridgeInterface
{
    public function name(): string;
    public function renderComponent(
        string $componentName, string $json,
        string $children, array $attrs
    ): string;
    public function renderState(
        string $stateName, string $json,
        string $body, array $attrs
    ): string;
}
```

### Built-in Bridges

| Bridge | Class | Output | Best for |
|--------|-------|--------|----------|
| `alpine` (default) | `AlpineBridge.php` | `x-data="ikbComponent({json})"` | CMS, attendance-wage, Alpine-based modules |
| `htmx` | `HtmxBridge.php` | `data-ikb-data` + `hx-vals` (passes through `hx-get`, `hx-post`, `hx-target`, etc.) | Guidance, HTMX-heavy modules |
| `custom` | `CustomBridge.php` | Generic `data-ikb-component` + `data-ikb-data` only | Any custom JS framework, SSR-only fallback |

### Usage in Templates

```disyl
{!-- Alpine (default, backward-compatible) --}
{ikb_component name="profile" data="user"}

{!-- HTMX — emits hx-vals, passes through HTMX attrs --}
{ikb_component name="appointment" data="form" bridge="htmx"
    hx-post="/api/appointments" hx-target="#result"}

{!-- State with custom bridge — generic data attrs only --}
{state name="kiosk" bridge="custom"}
    {variable name="step" type="int" default="0"}
    {variable name="searchQuery" type="string" default=""}
{/state}
```

### Output Comparison

Same data (`{name: "Noah", position: "Baker"}`) rendered through each bridge:

```html
<!-- Alpine bridge -->
<div data-ikb-component="employee" x-data="ikbComponent({&quot;name&quot;:&quot;Noah&quot;})">
  Noah
</div>

<!-- HTMX bridge -->
<div data-ikb-component="employee" data-ikb-data='{&quot;name&quot;:&quot;Noah&quot;}' hx-vals='{&quot;ikb_component&quot;:&quot;employee&quot;,&quot;data&quot;:{&quot;name&quot;:&quot;Noah&quot;}}'>
  Noah
</div>

<!-- Custom bridge -->
<div data-ikb-component="employee" data-ikb-data='{&quot;name&quot;:&quot;Noah&quot;}'>
  Noah
</div>
```

### Creating a Custom Bridge

Any module or package can register a bridge. For example, a Vue bridge:

```php
use Ikabud\Kernel\DiSyL\Bridge\BridgeInterface;
use Ikabud\Kernel\DiSyL\Bridge\BridgeManager;

class VueBridge implements BridgeInterface
{
    public function name(): string { return 'vue'; }

    public function renderComponent(string $name, string $json, string $children, array $attrs): string
    {
        $class = isset($attrs['class']) ? " class=\"{$attrs['class']}\"" : '';
        return "<div data-ikb-component=\"{$name}\" data-vue-component=\"{$name}\" :data='{$json}'{$class}>{$children}</div>";
    }

    public function renderState(string $stateName, string $json, string $body, array $attrs): string
    {
        $class = isset($attrs['class']) ? " class=\"{$attrs['class']}\"" : '';
        return "<div data-state=\"{$stateName}\" data-vue-state=\"{$stateName}\" :data='{$json}'{$class}>{$body}</div>";
    }
}

// Register once at boot time
BridgeManager::register(new VueBridge());
```

No parser changes needed. The bridge is resolved at render time by name.

### Bridge Identifiers (Grammar.php)

```php
Grammar::BRIDGE_ALPINE  // 'alpine'
Grammar::BRIDGE_HTMX    // 'htmx'
Grammar::BRIDGE_CUSTOM  // 'custom'
```

### Impact on Architecture

The bridge system is a **pluggable seam** that keeps DiSyL framework-agnostic at the rendering layer:

- **Modules own their frontend choice** — CMS stays on Alpine, guidance uses HTMX, future modules pick whatever suits
- **Zero parser coupling** — all framework-specific output lives in bridge classes, not in the TemplateEngine
- **Extensible** — adding a new framework is one class + one registration call
- **Backward-compatible** — Alpine is the default, so all existing templates render identically
- **Per-invocation granularity** — a single template can mix bridges if needed

## Conventions

- Component names use dot notation: `ui.button`, `layout.sidebar`
- File extension `.disyl` is auto-appended by the loader
- Props are validated at instance creation; missing required props throw exceptions
- Computed values are cached until dependency state changes
- Slot definitions are JSON-serializable for builder integration

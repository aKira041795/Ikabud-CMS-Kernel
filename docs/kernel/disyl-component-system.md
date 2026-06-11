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

## Conventions

- Component names use dot notation: `ui.button`, `layout.sidebar`
- File extension `.disyl` is auto-appended by the loader
- Props are validated at instance creation; missing required props throw exceptions
- Computed values are cached until dependency state changes
- Slot definitions are JSON-serializable for builder integration

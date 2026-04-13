# Cross-Module Interaction Playbook

> **When do I use Events vs Capabilities vs Triggers vs Hooks?**
> Read this when your module needs to talk to another module.
> For deep-dive reference on each primitive, see [kernel-auto-wiring.md](kernel-auto-wiring.md).

## The Decision Tree

Ask these questions in order:

### 1. Do I need a result right now?

**Yes → Use a Capability.**

```php
// CMS needs the current workflow state before rendering the editor
$state = app()->cap()->call('workflow.state.get@1', ['entity_id' => $contentId]);
```

Capabilities are synchronous request/response. The caller sends a payload,
gets a result. Think of them as **contracts between modules**.

### 2. Did something just happen that others might care about?

**Yes → Fire an Event.**

```php
// After saving a note, announce it
app()->events()->fire('example-notes.note.created', [
    'id'    => $newId,
    'title' => $title,
]);
```

Events are fire-and-forget domain facts. The emitter doesn't know or care
who listens. Think of them as **announcements**.

### 3. Should the reaction be configurable by an admin?

**Yes → Use a Trigger** (event → capability bridge).

Triggers live in the `kernel_event_triggers` table and connect an event to a
capability call. They are:
- visible in the admin UI
- enable/disable-able per tenant
- configurable (payload mapping, priority, timeout)

Example: `cms.content.published` → `search.index.upsert@1`

An admin can disable this without touching code.

### 4. Does my module always need to react, and it's never optional?

**Yes → Register a Listener** (in `helpers.php`).

```php
// Search always re-indexes when CMS content changes
app()->events()->listen('cms.content.updated', function (array $payload) {
    // Re-index the content
}, 10, 'search');
```

Listeners are code-level, always-on reactions. Use them only when the behavior
is a hard guarantee, not a business option.

### 5. Do I need to extend a UI or render pipeline?

**Yes → Use a Hook.**

```php
// Register a widget for the CMS page builder
app()->hooks()->register('cms.builder.widgets', function () {
    return ['my-widget' => [...]];
}, 10, 'my-module');
```

Hooks are kernel→module extension points for UI and rendering.

---

## Quick Reference

| I want to... | Use | Direction |
|--------------|-----|-----------|
| Ask another module to do work now | Capability | Module A → Module B |
| Announce something happened | Event | Module A → anyone listening |
| Let admins configure cross-module automation | Trigger | Event → Capability (configurable) |
| Always react to another module's event | Listener | Module B ← Module A |
| Extend a UI point or template | Hook | Module → kernel render point |

## Anti-Patterns (Don't Do This)

### 1. Don't call another module's functions directly

```php
// ✗ WRONG — direct cross-module call
require_once BASE_PATH . '/modules/cms/helpers.php';
cmsDb()->query('SELECT ...');

// ✓ RIGHT — go through a capability
app()->cap()->call('cms.content.get@1', ['id' => $contentId]);
```

Direct calls bypass permission checks, context isolation, and tenant boundaries.
If the other module changes its internals, your code breaks silently.

### 2. Don't put decisions in event payloads

```php
// ✗ WRONG — event payload contains instructions
app()->events()->fire('order.placed', [
    'order_id' => 42,
    'run_ai_summary' => true,     // This is a decision, not a fact
    'send_notification' => false,  // This is a decision, not a fact
]);

// ✓ RIGHT — event payload is a pure domain fact
app()->events()->fire('order.placed', [
    'order_id'    => 42,
    'customer_id' => 7,
    'total'       => 149.99,
]);
```

Events describe *what happened*. Triggers and listeners decide *what to do about it*.

### 3. Don't use events when you need a response

```php
// ✗ WRONG — firing an event and expecting a result
app()->events()->fire('search.please-index-this', ['content_id' => 5]);
// How do I know if it worked? I don't.

// ✓ RIGHT — use a capability when you need confirmation
$result = app()->cap()->call('search.index.upsert@1', ['content_id' => 5]);
```

### 4. Don't hardcode cross-module automation

```php
// ✗ WRONG — listener with business logic that should be configurable
app()->events()->listen('cms.content.published', function ($payload) {
    app()->cap()->call('ai.text.generate@1', ['content_id' => $payload['id']]);
}, 10, 'ai');

// ✓ RIGHT — use a trigger (admin can enable/disable/configure)
// In kernel_event_triggers:
// event: cms.content.published → capability: ai.text.generate@1
```

If an admin might want to turn it off, it's a trigger, not a listener.

### 5. Don't use app()->db() in modules

```php
// ✗ WRONG — bypasses tenant isolation and table ownership
app()->db()->query('SELECT * FROM en_notes');

// ✓ RIGHT — use the scoped ModuleDB
enDb()->query('SELECT * FROM en_notes');
```

---

## Real Examples from This Codebase

### CMS → Search (Event + Listener)

CMS publishes content. Search re-indexes it. This is always-on, non-optional.

```
cms fires → cms.content.updated
                ↓
search listens → re-indexes content
```

Trace it: `php ikabud event:trace cms.content.updated`

### CMS → Workflow (Capability)

CMS needs the current workflow state before rendering the editor. This is synchronous
and needs a return value.

```
cms calls → workflow.state.get@1 → kernel returns state
cms calls → workflow.transition@1 → kernel transitions state
```

Inspect it: `php ikabud capability:describe workflow.state.get@1`

### Ecommerce → WMS (Event + Integration Bridge)

When an order is paid, the warehouse needs to know. This crosses module boundaries
through the Integration Bridge (a managed Event→Capability router).

```
ecommerce fires → ecommerce.order.paid
                       ↓
bridge routes → wms.fulfillment.create@1
```

Trace it: `php ikabud event:trace ecommerce.order.paid`

### CMS → TinyMCE (Capability)

CMS uses TinyMCE for rich text editing. CMS calls TinyMCE capabilities directly
for assets, config, and sanitization.

```
cms calls → tinymce.assets.get@1
cms calls → tinymce.config.get@1
cms calls → tinymce.html.sanitize@1
```

### Admin-Configurable AI Summary (Trigger)

When content is published, optionally generate an AI summary. This is a business
decision, not a hard guarantee — so it's a trigger, not a listener.

```
trigger: cms.content.published → ai.text.generate@1 (admin can toggle)
```

---

## CLI Discovery Commands

| Command | Purpose |
|---------|---------|
| `php ikabud capability:list` | See all registered capabilities |
| `php ikabud capability:describe <id>` | Deep-dive: providers, consumers, schema |
| `php ikabud capability:call <id> "<json>"` | Call a capability directly |
| `php ikabud event:list` | See all registered event listeners |
| `php ikabud event:trace <name>` | See listeners, emitters, and flow for one event |
| `php ikabud module:graph` | See module dependency edges |
| `php ikabud module:validate <id>` | Full compliance check on a module |

---

## Related Docs

- [Module Quickstart](module-quickstart.md) — build your first module
- [Module Development Guide](module-development-guide.md) — comprehensive reference
- [Kernel Auto-Wiring](kernel-auto-wiring.md) — primitive definitions and deep-dive
- [Integration Bridge](integration-bridge.md) — Event→Capability routing across modules
- [CMS Capability Map](cms-capability-map.md) — real-world capability inventory

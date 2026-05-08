---
description: Decision guide for module developers — when to use a capability vs event vs hook vs trigger vs listener.
---

# Choosing the Right Primitive

Ikabud offers five extension primitives. Picking the right one is the single most important architectural decision when building a module. Wrong picks cause coupling, hidden dependencies, missing audit trails, and untestable code paths.

This guide is the canonical decision flow.

---

## TL;DR Decision Table

| You want to… | Use |
|---|---|
| Call another module's behavior **synchronously** with a typed contract | **Capability** (`CapabilityBus::call()`) |
| **Notify** the system that something happened, others may react | **Event** (`EventBus::fire()`) |
| Let modules **modify a value** the kernel computed | **Hook (filter)** (`Hooks::apply()`) |
| Let modules **react to a kernel lifecycle moment** | **Hook (action)** (`Hooks::do()`) |
| Run a tenant-configurable **automation rule** when an event happens | **Trigger** (declared in module manifest, executed by `TriggerService`) |
| **Listen** to an event in code (no automation, no UI) | **Event listener** (`EventBus::listen()`) |

---

## The Decision Flow

```
Is this module-to-module communication?
├─ YES → Does the caller need a return value or guaranteed completion?
│        ├─ YES → CAPABILITY
│        └─ NO  → EVENT (fire-and-forget) or DEFERRED EVENT
│
└─ NO  → Is the kernel asking modules to participate in something it owns?
         ├─ Modify a value → HOOK (filter)
         ├─ React to a moment → HOOK (action)
         └─ Allow tenant configuration of "when X then Y" → TRIGGER
```

---

## Primitive Profiles

### 1. Capability — `kernel/Capabilities/CapabilityBus.php`

**Use when:** one module needs to invoke another's functionality with a versioned, schema-validated contract.

**Properties:**
- Synchronous, typed input/output, version-locked (`name@1`).
- Caller declares `depends` in `module.json`; provider declares `exposes`.
- Resolution is deterministic (single provider per ID per version).
- Failure modes are explicit (capability missing, version mismatch, schema error).

**Anti-patterns:**
- Do not use a capability when no return value is needed — prefer an event.
- Do not call a capability for purely-informational broadcasts.

**Example:**
```php
$result = $ctx->capabilities()->call('email.send@1', [
    'to' => $user['email'],
    'subject' => 'Welcome',
    'body' => $body,
]);
```

---

### 2. Event — `kernel/EventBus.php`

**Use when:** something happened and zero-or-more modules may want to react.

**Properties:**
- Asynchronous-style (fire-and-forget); can be deferred to end-of-request.
- No return value, no caller knowledge of listeners.
- Audited via `EventBus` instrumentation; appears in observability tools.
- Listed in the module's `module.json` `events` array (declared events).

**Anti-patterns:**
- Do not use events for control flow (caller depends on outcome).
- Do not emit events that cannot be safely ignored.

**Example:**
```php
$ctx->events()->fire('cms.page.published', [
    'page_id' => $page['id'],
    'tenant_id' => $ctx->tenantId(),
]);
```

---

### 3. Hook (filter) — `kernel/Hooks.php`

**Use when:** the kernel computes a value and wants modules to optionally transform it.

**Properties:**
- Synchronous chain; each listener receives prior listener's output.
- Returning `null` or the input unchanged is allowed (means "no change").
- Examples: `kernel.nav_items`, `kernel.gui_context`, `kernel.render_context`.

**Anti-patterns:**
- Do not use a filter for module-to-module communication — use a capability.
- Do not mutate values the kernel did not explicitly publish via a hook.

---

### 4. Hook (action) — `kernel/Hooks.php`

**Use when:** the kernel reaches a lifecycle moment and modules need to do work (no return value).

**Properties:**
- Examples: `kernel.boot`, `kernel.shutdown`.
- Listeners run synchronously in priority order.

**Anti-patterns:**
- Do not perform expensive work in `kernel.boot` listeners.
- Do not assume listener order outside of explicit priority hints.

---

### 5. Trigger — declared in `module.json`, executed by `kernel/TriggerService.php`

**Use when:** a tenant administrator should be able to configure "when event X happens, execute capability Y".

**Properties:**
- The contract is data: an event source + a target capability + a parameter map.
- Tenants can enable/disable/reconfigure triggers via the Integration Catalog UI.
- Failures are surfaced and replayable.

**Anti-patterns:**
- Do not hard-code trigger logic in PHP — make it manifest-declared so tenants control it.
- Do not chain triggers without explicit capability version locks.

---

### 6. Event listener — `EventBus::listen()`

**Use when:** your module needs to react to another module's event in code (not as a tenant-configurable rule).

**Properties:**
- Registered during module bootstrap.
- Cannot be enabled/disabled per tenant (use a trigger for that).

---

## Common Mistakes

| Mistake | Better choice |
|---|---|
| Module A `require`s a file from Module B | Capability |
| Module A reads from Module B's tables directly | Capability or event-driven sync via `EntityAuthority` |
| Hard-coded "when order placed, send email" inside ecommerce | Trigger (so tenant can disable/reroute) |
| Filter that also writes to the database | Split: filter for transformation, event for the side-effect |
| Capability with `void` return | Event |
| Event listener that the caller must wait for | Capability |

---

## See also

- [kernel-stable-contracts.md](kernel-stable-contracts.md) — current stable platform surface
- [integration-bridge.md](integration-bridge.md) — how triggers map events to capabilities
- [kernel-auto-wiring.md](kernel-auto-wiring.md) — how the kernel discovers and registers everything
- [api-reference.md](api-reference.md) — full API reference

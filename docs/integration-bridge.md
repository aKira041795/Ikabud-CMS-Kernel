# Kernel OS Integration Bridge

## Overview

The **Integration Bridge** connects events from a source-of-truth module to actions in a dependent module, without duplicating ownership.

It is a declarative integration layer that allows modules to **react to each other**, rather than **sharing ownership** over data. It acts as the glue that connects an emitted **Event** from one module to an exposed **Capability** in another module, without requiring hardcoded PHP dependencies or direct coupling.

---

## 🔒 What the Bridge Does (and DOES NOT do)

Your Integration Bridge is a deterministic, inspectable, and debuggable router.

**The Bridge Does:**
✔ Connect modules (Event routing)
✔ Translate payloads (Payload mapping & transformation)
✔ Execute capabilities (Stateless invocation)
✔ Remove hardcoded wiring
✔ Log success/failure trails

**The Bridge Does NOT:**
❌ Unify schemas
❌ Sync entities bidirectionally
❌ Decide data ownership
❌ Resolve data/write conflicts
❌ Act as a workflow engine (no multi-step flows, no conditional pathing, no retries)

---

## 🧠 The Correct Mental Model: Authority vs Usage

Think strictly in terms of **authority vs usage**. You pick **ONE** owner for an entity.

| Domain    | Owns Data                   | Uses Data                 |
| --------- | --------------------------- | ------------------------- |
| WMS       | ✅ Products (physical truth) | ❌                         |
| Ecommerce | ❌                           | ✅ Products (catalog view) |

Dual entry (defining the exact same canonical record via multiple module inputs) is a **data federation** problem, not an integration problem. The bridge should strictly stay out of entity syncing logic.

---

## Architectural Rules

1. **Strict Authority**: Modules maintain their own boundaries. Do not use the bridge to build bidirectional state syncs.
2. **Fail-Fast Behavior**: If a capability execution fails, the bridge catches the exception, logs it as `failed`, and stops. It does not retry, queue, or continue into multi-step recovery logic.
3. **No Magic Routing**: Every integration is explicitly defined in the `kernel_integrations` registry.
4. **Version-Safe Dispatch**: A bridge may pin a `version_lock` to the fully resolved capability id (for example `wms.stock.reserve@1`). If alias resolution drifts, the bridge logs an explicit version-lock failure instead of silently calling the wrong provider.

---

## 🚀 Strategic Future: Entity Authority Declaration

To prevent data chaos and prevent the bridge from being misused as a sync federation tool, the Kernel will eventually enforce **Entity Authority** inside a module's `module.json` manifest:

```json
"entities": {
  "products": {
    "authority": true
  }
}
```

The Kernel will enforce that only **one module** can claim authority per entity type. Other modules must consume this entity via capabilities.

---

## Payload Mapping System

The bridge uses a simple, deterministic JSON mapping system to translate an Event's outbound payload into a Capability's expected inbound payload.

* **Syntax**: Supports basic `{{dot.notation}}` string replacements inside JSON string values.
* **Resolution**: If a string *exactly* matches an array or object path (e.g., `"items": "{{order.items}}"`), the mapped variable retains its structured type instead of being flattened into a string.
* **Nested Structures**: Mapping values may themselves be nested JSON objects/arrays, and the mapper resolves placeholders recursively inside those structures.
* **Idempotency**: If the source event payload provides an `idempotency_key`, the bridge passes it through automatically unless the mapping explicitly overrides it.

**What it does NOT support:**
* Expressions, loops, math, or conditional statements.

---

## Database Architecture

1. `kernel_integrations`: The source of truth for declared mappings.
  * `name`, `trigger_event`, `target_capability`, `mapping_json`, `is_active`, `event_source`, `version_lock`
2. `kernel_integration_logs`: The observability trail for fired integrations.
  * `status` (success/failed), `payload_in`, `payload_out`, `error_message`, `request_id`, `correlation_id`, `duration_ms`

The bridge schema is part of the tenant-safe kernel migration set. New tenant databases receive both the base bridge tables and the hardening columns/indexes during request-time kernel migration sync.

### Runtime guardrail

`IntegrationBridge::handle()` is kernel infrastructure even when a module invokes it inline during a request. In practice that means bridge reads and writes to `kernel_integrations` and `kernel_integration_logs` must run under the kernel DB-unguarded scope rather than inheriting the active module's `ModuleDB` restrictions. If that guard is not lifted, real module-driven flows can appear to succeed while the bridge silently skips kernel-table access that still works from standalone kernel or debug entrypoints.

---

## Module Manifest Extensions

Modules that declare dependencies on specific capabilities should now record them in `module.json` under the `capabilities.consumes` array for future discovery and graphing.

```json
"capabilities": {
    "exposes": [...],
    "consumes": []
}
```

---

## Admin UI

The integration registry is visible to Kernel Superadmins at:
**`/kernel/integrations`**

The interface allows administrators to:
1. Create new integrations manually by defining the Trigger Event, Target Capability, and the JSON payload map.
2. Toggle integrations on/off.
3. Pick from known registered Events and Capabilities, with event variable hints and capability version-lock prefill.
4. Promote a simple bridge into a full Kernel Trigger rule while marking the original bridge row as `promoted` for traceability.
5. Review recent execution logs, including request id, correlation id, duration, payloads, and inline capability rejection errors.

The mutating bridge API at `/api/v1/kernel/integrations` now enforces Kernel CSRF validation on `POST` and `DELETE` requests and returns a `request_id` in all JSON responses for operator support and incident correlation.

Bridge draft validation and create now share the same runtime checks. The kernel rejects definitions that reference unknown event variables, violate target capability caller policy for `kernel`, mismatch an explicit `version_lock`, or omit schema-required payload keys that are statically knowable from the mapping.

---

## Example: Ecommerce → WMS (Proper Reactive Flow)

A primary driver for this engine is decoupling Order generation from Inventory control.

**Ownership Model:**
* Ecommerce = owns orders and storefront checkout flow
* WMS = owns stock reservation and release behavior

**Trigger Event:** `ecommerce.order.created` (Ecommerce owns this)  
**Target Capability:** `wms.stock.reserve@1` (WMS reacts)

**Mapping JSON:**
```json
{
  "reference_type": "order",
  "reference_id": "{{order.id}}",
  "items": "{{order.items}}",
  "idempotency_key": "{{idempotency_key}}",
  "actor_user_id": "{{actor_user_id}}"
}
```

**Execution Flow (One-Way Action):**
1. Checkout finishes in Ecommerce, firing `ecommerce.order.created` to `EventBus`.
2. Ecommerce persists an `integration_bridge_snapshot` in order meta so later lifecycle events can reuse the same warehouse and item-routing data.
3. The bridge loads the matching active row from `kernel_integrations`, resolves the mapping, and preserves `order.items` as structured data.
4. `app()->cap()->call()` executes `wms.stock.reserve@1` with request/correlation context plus the event idempotency key.
5. Success or failure is logged to `kernel_integration_logs`, and the bridge emits `integration.result.wms.stock.reserve_v1` for downstream automation.
6. WMS reserves stock, writes `wms_movements`, and honors per-item or derived idempotency keys so replays do not double-reserve.

**Compensating Release Flow:**

When an order is cancelled, Ecommerce emits `ecommerce.order.cancelled` using the stored bridge snapshot instead of reconstructing a lossy item list from order rows.

**Trigger Event:** `ecommerce.order.cancelled`  
**Target Capability:** `wms.stock.release@1`

**Mapping JSON:**
```json
{
  "reference_type": "order",
  "reference_id": "{{order.id}}",
  "items": "{{order.items}}",
  "idempotency_key": "{{idempotency_key}}",
  "actor_user_id": "{{actor_user_id}}"
}
```

This allows the system to release prior reservations deterministically, create a single `unreserved` movement, and survive event replay without over-releasing stock.

---

## Runtime Notes for WMS Consumers

* `wms.stock.reserve@1` and `wms.stock.release@1` accept batch `items` payloads.
* If an item omits `location_id` but provides `warehouse_id`, WMS derives an active location automatically. A tenant can optionally pin this behavior with `bridge.default_location_id` in WMS settings.
* If Ecommerce is using an active `ecommerce.order.created` → `wms.stock.reserve@1` bridge, local Ecommerce stock decrement is skipped so WMS remains the sole reservation authority.
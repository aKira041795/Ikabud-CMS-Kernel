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
2. **Fail-Fast Behavior**: If a capability execution fails, the bridge catches the exception, logs it as `failed`, and stops. It *does not* swallow the error silently if it crashes the runtime, but logs it for visibility. It *does not* retry.
3. **No Magic Routing**: Every integration is explicitly defined in the `kernel_integrations` registry.

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
* **Resolution**: If a string *exactly* matches an array or object path (e.g., `"items": "{{order.items}}"`), the mapped variable will safely retain its structured type (Array/Object) rather than cast to a string.
* **Idempotency**: If the source event payload provides an `idempotency_key`, the mapper natively passes it through to the resolved capability payload.

**What it does NOT support:**
* Expressions, loops, math, or conditional statements.

---

## Database Architecture

1. `kernel_integrations`: The source of truth for declared mappings.
   * `name`, `trigger_event`, `target_capability`, `mapping_json`, `is_active`
2. `kernel_integration_logs`: The observability trail for fired integrations.
   * `status` (success/failed), `payload_in`, `payload_out`, `error_message`

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
3. Review recent execution logs, including expanding payloads and viewing capability rejection errors inline.

---

## Example: Ecommerce → WMS (Proper Reactive Flow)

A primary driver for this engine is decoupling Order generation from Inventory control.

**Ownership Model:**
* Ecommerce = owns products + orders
* WMS = owns stock + fulfillment

**Trigger Event:** `ecommerce.order.created` (Ecommerce owns this)  
**Target Capability:** `wms.stock.reserve@1` (WMS reacts)

**Mapping JSON:**
```json
{
  "reference_type": "order",
  "reference_id": "{{order.id}}",
  "items": "{{order.items}}",
  "idempotency_key": "order_{{order.id}}"
}
```

**Execution Flow (One-Way Action):**
1. Checkout finishes in Ecommerce, firing `ecommerce.order.created` to `EventBus`.
2. Integration Bridge intercepts the wildcard listener.
3. The bridge queries the `kernel_integrations` table and flexibly casts the `$payload['order']['items']` array onto the `wms.stock.reserve@1` schema.
4. `app()->cap()->call()` resolves and executes the targeted WMS capability.
5. Success/Failure is logged to `kernel_integration_logs`.
6. WMS then operates on its stock (reserves inventory, creates `wms_movements`).

**Optional Reverse Bridge:**
If the reservation fails, WMS emits `wms.stock.failed`. The Bridge maps this to an `ecommerce.order.cancel@1` capability to perform the cancellation securely in the Ecommerce module. **This provides bi-directional behavior without tight coupling or data syncing.**
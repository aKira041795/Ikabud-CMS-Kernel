# P6 — Inline Entity Editing RFC

📋 **STATUS: POC (Proof of Concept)** — Kernel OS 6.1.0. The `editable` attribute on `{field}` and `ikbInlineEdit` Alpine component exist as a proof of concept. Full production rollout is deferred. This RFC describes the target architecture. **Last updated: 2026-07-07.**

**Status**: Draft for review  
**Last Updated**: 2026-06-22  
**Author**: Extracted from system architect review — entity-rendering-trait-improvements.md  
**Reviewer**: TBD

---

## Scope

This RFC covers **inline editing** — the ability to edit a cell value directly in an entity list or detail view without navigating to a separate edit page. This is not a rendering feature; it is a **governed mutation workflow**.

---

## Architecture principle

```
View contracts describe.        → {field editable="true" ...}
Registries extend.              → CellRendererRegistry (read-only)
Renderers present.              → DefaultEntityRenderer emits Alpine.js component
Capabilities mutate.            → Module-owned capability validates + persists
Kernel OS governs.              → Authz, audit, CSRF, locking, conflict detection
```

---

## Why not `update_url` in view contracts

The original proposal used arbitrary URLs:

```disyl
{field name="status" renderer="badge"
       editable="true"
       update_url="/api/v1/guidance/cases/{id}/status"}
```

This is **rejected** because it:
- Moves business behavior (validation, authorization) into a view contract
- Bypasses the capability system
- Duplicates URL routing logic across view contracts
- Cannot enforce consistency (field transitions, audit trail)

## Approved approach — capability-driven

```disyl
{field name="status" renderer="badge"
       editable="true"
       update_capability="guidance.case.status.update@1"}
```

The module owns that capability and validates the entire transition — authz, allowed values, business rules, persistence, audit.

---

## Design

### 1. View contract declaration

```php
// In view contract (helpers/views/guidance_case.disyl or builtinDefaults):
{field name="status" type="enum" renderer="badge:{open|Open|blue;closed|Closed|gray}"
       editable="true"
       update_capability="guidance.case.status.update@1"
       allowed_values='["open","closed","in_progress","on_hold"]'}
```

View contract fields:
| Attribute | Required | Description |
|-----------|----------|-------------|
| `editable` | Yes | `"true"` to enable inline editing |
| `update_capability` | Yes | Capability ID that handles the mutation |
| `allowed_values` | For enums | JSON array of allowed values (client-side pre-validation) |
| `editable_if` | No | Expression condition (same grammar as `action_show_if`) |

### 2. Renderer output

When `editable="true"`, `DefaultEntityRenderer::renderCell()` wraps the value in an Alpine.js component instead of static HTML.

For an enum field (`renderer="badge"`):

```html
<td x-data="ikbInlineEdit({
    entityId: 42,
    field: 'status',
    value: 'open',
    displayHtml: '<span class=\"...\">Open</span>',
    capability: 'guidance.case.status.update@1',
    allowedValues: ['open','closed','in_progress','on_hold'],
    version: 7
})">
    <template x-if="!editing">
        <span @click="startEdit" class="cursor-pointer hover:ring-2 hover:ring-brand-300 rounded">
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Open</span>
        </span>
    </template>
    <template x-if="editing">
        <select x-model="newValue" @change="save" @click.stop
                class="text-sm border border-brand-300 rounded px-2 py-1">
            <option value="open" :selected="value=='open'">Open</option>
            <option value="closed" :selected="value=='closed'">Closed</option>
            <option value="in_progress" :selected="value=='in_progress'">In Progress</option>
            <option value="on_hold" :selected="value=='on_hold'">On Hold</option>
        </select>
    </template>
</td>
```

For a text field:

```html
<template x-if="!editing">
    <span @click="startEdit" class="cursor-pointer hover:ring-2 hover:ring-brand-300 rounded">John Doe</span>
</template>
<template x-if="editing">
    <input type="text" x-model="newValue" @blur="save" @keydown.enter="save" @keydown.escape="cancel"
           class="text-sm border border-brand-300 rounded px-2 py-1 w-full">
</template>
```

### 3. Alpine.js component (`ikbInlineEdit`)

Registered globally in the application shell:

```javascript
// public/admin/assets/js/ikb-inline-edit.js
document.addEventListener('alpine:init', () => {
    Alpine.data('ikbInlineEdit', (config) => ({
        editing: false,
        value: config.value,
        newValue: config.value,
        version: config.version,
        saving: false,
        error: null,

        startEdit() {
            this.newValue = this.value;
            this.editing = true;
            this.error = null;
            this.$nextTick(() => {
                const input = this.$el.querySelector('input, select');
                if (input) input.focus();
            });
        },

        cancel() {
            this.editing = false;
            this.newValue = this.value;
            this.error = null;
        },

        async save() {
            if (this.newValue === this.value) {
                this.editing = false;
                return;
            }
            this.saving = true;
            this.error = null;

            try {
                const response = await fetch(`/api/v1/entity/update`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        capability: config.capability,
                        entity_id: config.entityId,
                        field: config.field,
                        value: this.newValue,
                        expected_version: this.version,
                    }),
                });

                const result = await response.json();

                if (!result.ok) {
                    this.error = result.error || 'Update failed';
                    return;
                }

                this.value = result.data.raw_value;
                this.version = result.data.version;
                this.editing = false;

                // Update display HTML from server response
                if (result.data.display_html) {
                    // Replace the display cell content
                }

            } catch (e) {
                this.error = 'Network error';
            } finally {
                this.saving = false;
            }
        },
    }));
});
```

### 4. Capability handler contract

Each `update_capability` handler receives:

```php
// Payload:
[
    'entity_id'       => 42,
    'field'           => 'status',
    'value'           => 'closed',
    'expected_version'=> 7,       // Optional — for optimistic locking
    'auth_user'       => [...],   // Injected by capability bus
]

// Return:
[
    'ok'      => true,
    'data'    => [
        'raw_value'   => 'closed',
        'display_html'=> '<span class="...">Closed</span>',
        'version'     => 8,
        'updated_at'  => '2026-06-22T10:15:00+08:00',
    ],
]
```

Or on error:
```php
[
    'ok'    => false,
    'error' => 'Cannot transition from "closed" to "open".',
    'code'  => 'INVALID_TRANSITION',
]
```

### 5. API endpoint

A single generic endpoint routes to the capability bus:

```
POST /api/v1/entity/update
Content-Type: application/json
X-Requested-With: XMLHttpRequest
```

Body:
```json
{
    "capability": "guidance.case.status.update@1",
    "entity_id": 42,
    "field": "status",
    "value": "closed",
    "expected_version": 7
}
```

Server handler (in `public/index.php` or module route):
```php
// POST /api/v1/entity/update
$input = json_decode(file_get_contents('php://input'), true);
$capabilityId = $input['capability'] ?? '';
$result = app()->cap()->call($capabilityId, [
    'entity_id' => $input['entity_id'] ?? 0,
    'field'     => $input['field'] ?? '',
    'value'     => $input['value'] ?? null,
    'expected_version' => $input['expected_version'] ?? null,
], [
    'caller' => ['module' => 'kernel'],
    'mode'   => 'first',
    'timeout_ms' => 5000,
]);
header('Content-Type: application/json');
echo json_encode($result);
```

---

## Security & constraints

### Authentication
- All mutation requests require an authenticated session (verified via CSRF token or JWT)
- The `X-Requested-With: XMLHttpRequest` header prevents simple CSRF from other origins

### Authorization
- The capability handler is responsible for checking user role/permissions
- `editable_if` can express preconditions (e.g. `status != "closed"`)

### Validation
- `allowed_values` provides client-side pre-validation (UX only — never trust the client)
- The capability handler **must** re-validate all inputs server-side
- Field-level validation rules belong in the capability handler, not the view contract

### Audit
- The capability handler should call `kernel.audit.record@1` for each mutation
- Audit payload: `{module, action: 'inline_update', entity_type, entity_id, old_data, new_data}`

### Optimistic locking
- `expected_version` is optional but recommended
- Capability handler checks: if provided version doesn't match current, return `409 Conflict`
- Version can be a simple integer counter or `updated_at` timestamp
- Prevents one user from silently overwriting another's change

### Rate limiting
- Apply per-user rate limits to `POST /api/v1/entity/update`
- Suggested: 60 requests/minute per user

### Allowed value definitions
- For enum fields, the view contract declares `allowed_values`
- For text fields, the capability handler may impose length/pattern constraints
- Never allow arbitrary SQL or expression injection through field values

---

## Concurrency

### Version conflict flow

```
User A loads page (version 7)
User B loads page (version 7)
User A edits status → closed (version 7→8) ✅
User B edits status → open  (version 7→discard) ❌ 409 Conflict
```

Server response on conflict:
```json
{
    "ok": false,
    "error": "Entity was modified by another user. Reload and try again.",
    "code": "VERSION_CONFLICT",
    "current_version": 8
}
```

The Alpine component shows a toast: "Entity was modified. Please reload."

### Concurrent editing of different fields
If the entity has per-field versioning (recommended), two users can edit different fields simultaneously without conflict.

---

## Accessibility

- Click-to-edit targets must have `role="button"` and `tabindex="0"` with keyboard handler (`Enter` to start edit)
- `<select>` / `<input>` elements receive focus automatically on edit start
- Error messages use `aria-live="polite"` region
- Color is not the only indicator — text labels change (e.g. "Click to edit" tooltip)
- Escape key cancels editing and reverts to previous value
- Saving state is communicated via disabled button + spinner or `aria-busy="true"`

---

## Implementation plan

### Phase 1: Backend capability + endpoint

**Effort**: ~4h

1. Create `POST /api/v1/entity/update` route in `public/index.php` or kernel route
2. Implement CSRF + session auth check in the endpoint
3. Wire endpoint to call `app()->cap()->call()` with the requested capability
4. Return JSON response with `display_html` re-rendered via cell renderer registry

### Phase 2: Alpine.js component

**Effort**: ~3h

1. Create `public/admin/assets/js/ikb-inline-edit.js`
2. Register Alpine data component with `startEdit`, `save`, `cancel` methods
3. Handle text inputs, select dropdowns, and badge click cycles
4. Error display with auto-dismiss
5. Keyboard shortcuts (Enter to save, Escape to cancel)

### Phase 3: Renderer integration

**Effort**: ~3h

1. Add `InlineEditRenderer` that wraps cell output in Alpine component
2. Modify `DefaultEntityRenderer::renderCell()` to check `$fieldContract['editable']`
3. Pass `entity_id`, `update_capability`, `allowed_values`, `version` to the Alpine component
4. Add `editable_if` condition support

### Phase 4: Module adoption (example: guidance case status)

**Effort**: ~2h per module

1. Create `guidance.case.status.update@1` capability handler
2. Validate transition rules (e.g. closed → open is not allowed)
3. Call `kernel.audit.record@1` on each mutation
4. Update `guidance_case` view contract with `editable="true"` + `update_capability`

---

## Open questions

1. **Version source** — Should version be a per-field counter, per-entity counter, or `updated_at` timestamp? Per-field counter is most precise but requires a `gm_case_field_versions` table.

2. **display_html generation** — Should the API endpoint re-render the cell using the server-side renderer (so badges/colors stay in sync), or should the client update the display from the `raw_value`? Server-side is safer but adds latency.

3. **Bulk inline editing** — Should we support selecting multiple rows and editing a field in bulk (e.g. change status of 5 cases at once)? This is a separate UX pattern but shares the same capability contract.

4. **Bridge behavior** — For React islands or HTMX bridges, should inline editing use the same `POST /api/v1/entity/update` endpoint or emit bridge-specific events?

5. **Error recovery** — When `display_html` update fails after a successful save, should the cell fall back to showing `raw_value` in plain text, or should it retry fetching the rendered HTML?

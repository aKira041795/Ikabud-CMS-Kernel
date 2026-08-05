# DiSyL 4.7 — Language Reference

> **Version:** 4.7.0 | **Engine:** TemplateEngine 6.1 | **Parser:** v4 | **Compiler:** v8  
> **Last updated:** 2026-07-05  
> **Note:** Typed `{set}` assignment syntax (`{set name: string = ...}`) documented in the Quick Start is planned for DiSyL 4.8 and is **not yet active** in the 4.7 runtime. Use standard `{set var = expr}` syntax in production templates.

---

## Quick Start

```disyl
{extends "layouts/main.disyl"}

{block body}
    {set title = "Welcome"}
    <h1>{title|upper}</h1>
    <p>Hello, {user.name|default:"Guest"}!</p>

    {ikb_entity_list source="cms_post.recent" view="card_grid" limit="6" /}
{/block}
```

---

## 1. Variables & Expressions

### Output

```
{variable}
{user.name}           — nested property access
{user.getName()}      — function call
{json_encode(data)}   — JSON-encode (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
{json_decode(data).key} — JSON-decode to assoc array + dot-path access
```

### Assignment `{set}`

```
{set x = 42}
{set name = "Alice"}
{set total = price * quantity}
{set active = count > 0}
```

### Typed Assignment (v4.8)

```
{set name: string = "Alice"}
{set count: int = 42}
{set price: float = 9.99}
{set active: bool = true}
{set tags: array = ["a", "b"]}
{set nickname: ?string = null}       — nullable
{set status: "open"|"closed" = "open"}  — literal union
```

### Type Coercion Rules

| Type | Coercion |
|------|----------|
| `string` | Cast to string |
| `int` / `integer` | Cast to int |
| `float` / `number` | Cast to float |
| `bool` / `boolean` | Truthy/falsy |
| `array` | Wrap scalar in `[$value]` |
| `mixed` | No coercion |
| `?type` | Null bypasses coercion |

### Arithmetic

```
{a + b}    {price * quantity}    {(a + b) * c}
{a / b}    {count % 2}           {total - discount}
```

### Ternary

```
{active ? "Yes" : "No"}
{count > 0 ? count : "none"}
{user.role == "admin" ? "Administrator" : user.role}
```

### Debug (✅ v4.7)

```
{debug myVar}     — pretty-prints any value with type info
{debug user}      — arrays as formatted JSON
```

### JSON Function Calls — `json_encode` / `json_decode` (✅ 2026-08-05)

The DiSyL engine now supports JSON serialization and parsing as **function calls**
(in addition to the existing `|json` filter). Registered in
`kernel/DiSyL/v4/FunctionRegistry.php`:

```
{json_encode(data)}         — JSON-encode to a string
{json_decode(data)}         — JSON-decode into an associative array
{json_decode(data).key}     — dot-path access into the decoded array
```

Behavior:

- **`json_encode`** mirrors `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, so
  slashes and non-ASCII characters are not escaped — output stays stable with the
  `|json` filter.
- **Use `{json_encode(data)|raw}` in JS/attribute contexts** (Alpine `x-data`,
  `<script>` blocks, `data-*` attributes) so the JSON string is not HTML-escaped
  into entities.
- **`json_decode`** returns an associative array; dot-path access like
  `{json_decode(payload).user_id}` resolves a nested key directly via
  `kernel/DiSyL/ExpressionEvaluator.php`.

Example:

```disyl
{set payload = '{"user_id":42,"role":"admin"}'}
{json_decode(payload).user_id}        → 42
{json_encode(payload)|raw}            → {"user_id":42,"role":"admin"}
```

### Entity View Field Reflection — `keyof` (✅ v4.7)

Resolves to the field name list of a registered entity view contract at runtime.
Works wherever an expression is expected — direct output, filters, `{for}` loops.

```
{keyof employee_profile}
  → ["first_name","last_name","email","phone","department"]

{keyof employee_profile.detailed}
  → ["first_name","last_name","...","salary","start_date"]

{keyof employee_profile | json}
  → same as default (JSON array)

{keyof employee_profile | join:", "}
  → first_name, last_name, email, phone, department

{for field in keyof employee_profile.detailed}
  <span>{field}</span>
{/for}
```

If the entity type or view is not found, returns `[]` (empty array).
Wildcard field contracts (`fields: '*'`) return `[]` — field list is unknown.

---

## 2. Filters

### Syntax

```
{var|filter}                  — no arguments
{var|filter:arg}              — positional argument
{var|filter:arg1:arg2}        — multiple positional
{var|filter:key="value"}      — named argument (✅ v4.7 — partial)
{var|upper|truncate:10}       — chained
```

### Built-in Filters

| Filter | Arguments | Description |
|--------|-----------|-------------|
| `upper` | — | Uppercase |
| `lower` | — | Lowercase |
| `capitalize` | — | First letter uppercase |
| `title` | — | Title Case (underscores → spaces) |
| `trim` | — | Trim whitespace |
| `truncate` | `length` or `5` | Truncate with `...` |
| `nl2br` | — | Newlines → `<br>` |
| `raw` | — | Skip HTML escaping |
| `esc_html` | — | HTML-escape |
| `esc_attr` | — | Attribute-safe escape |
| `esc_url` | — | URL sanitize |
| `esc_js` | — | JavaScript string escape |
| `json` | — | JSON encode |
| `json_attr` | — | JSON + HTML-escape (for `x-data`) |
| `date` | `format` or `"Y-m-d"` | Format date |
| `default` | fallback value | Default if null/empty |
| `count` | — | Count array elements |
| `join` | separator | Join array |
| `first` | — | First element/char |
| `last` | — | Last element |
| `number_format` | decimals | Format number |
| `keys` | — | Array keys |
| `values` | — | Array values |

### Named Arguments (✅ v4.7 — partial; named arg parsing exists, full test coverage pending)

```
{created_at|date:format="M d, Y"}
{description|truncate:length=100}
```

---

## 3. Control Structures

### `{if}` / `{elseif}` / `{else}`

```disyl
{if user.role == "admin"}
    <span>Admin Panel</span>
{elseif user.role == "editor"}
    <span>Editor Tools</span>
{else}
    <span>Welcome, {user.name}</span>
{/if}
```

### `{for}` / `{empty}`

```disyl
{for item in items}
    <li>{item.name}</li>
{empty}
    <li>No items found.</li>
{/for}
```

### `{foreach}`

```disyl
{foreach users as user}
    <div>{user.name} — {user.email}</div>
{/foreach}

{foreach users as id => user}
    <div>#{id}: {user.name}</div>
{/foreach}
```

### `{match}` / `{when}` / `{else}` (✅ v4.7)

```disyl
{match order.status}
    {when "paid"}<span class="green">Paid</span>{/when}
    {when "pending"}<span class="amber">Pending</span>{/when}
    {when "cancelled", "refunded"}<span class="red">Closed</span>{/when}
    {when "paid" guard order.amount > 1000}<span>High Value!</span>{/when}
    {when _}<span>Unknown</span>{/when}
    {else}<span>No status</span>{/else}
{/match}
```

### `{await}` / `{then}` / `{loading}` / `{catch}` (✅ v4.7)

```disyl
{await userData}
    {then}{value.name} is ready!{/then}
    {loading}<span>Loading user...</span>{/loading}
    {catch let=e}<span class="error">Error: {e}</span>{/catch}
{/await}
```

---

## 4. Templates & Inheritance

### `{extends}`

```disyl
{extends "layouts/admin.disyl"}
```

### `{block}`

```disyl
{block sidebar}
    <nav>Custom sidebar content</nav>
{/block}
```

### `{include}`

```disyl
{include "partials/header.disyl"}
{include "partials/card.disyl" with {"title": "Hello", "body": "World"}}
```

### `{slot}`

```disyl
{slot name="head"}
    <meta name="description" content="...">
{/slot}
```

---

## 5. Components (`ikb_*`)

### Data

| Component | Description |
|-----------|-------------|
| `ikb_entity_list` | Governed entity list from source/view |
| `ikb_entity_detail` | Single entity detail view |
| `ikb_stat_card` | Statistic display card |
| `ikb_timeline` | Timeline list |
| `ikb_audit_log` | Audit log display |
| `ikb_table` | Raw table |
| `ikb_badge` | Badge/pill |

### Layout

| Component | Description |
|-----------|-------------|
| `ikb_section` | Page section |
| `ikb_container` | Centered container |
| `ikb_grid` | CSS grid |
| `ikb_card` | Card component |
| `ikb_panel` | Panel with header |
| `ikb_modal` | Modal dialog |
| `ikb_drawer` | Slide-out drawer |
| `ikb_alert` | Alert banner |
| `ikb_spinner` | Loading spinner |

### Form

| Component | Description |
|-----------|-------------|
| `ikb_form` | Form wrapper with CSRF |
| `ikb_input` | Input field |
| `ikb_textarea` | Textarea |
| `ikb_select` | Select dropdown |

### Interactive

| Component | Description |
|-----------|-------------|
| `ikb_button` | Button |
| `ikb_link` | Link |
| `ikb_export_button` | Governed export |
| `ikb_confirm_action` | Confirm dialog |

### Content

| Component | Description |
|-----------|-------------|
| `ikb_text` | Text block |
| `ikb_image` | Image |
| `ikb_icon` | Icon |

### Report

| Component | Description |
|-----------|-------------|
| `ikb_report` | Report container |
| `ikb_signature_block` | Signature block |

### AI

| Component | Description |
|-----------|-------------|
| `ikb_ai_summary` | AI-generated summary |
| `ikb_ai_assist` | AI writing assistant |

---

## 6. Entity Views

### View Contract Registration

Entity views are declared in DiSyL config files (`.disyl` under `helpers/views/`) using `{ikb_entity_view}`:

```disyl
{ikb_entity_view name="employee_profile" view="table"}
    {field name="first_name" type="string" renderer="text"}
    {field name="last_name"  type="string" renderer="text"}
    {field name="salary_type" type="enum" renderer="badge:{hourly|Daily}"}
    {field name="employment_status" type="enum" renderer="badge:{regular|Regular|green}"}
    {action name="view" url="/admin/wage/employees/{id}/view"}
    {action name="edit" url="/admin/wage/employees/{id}"}
{/ikb_entity_view}
```

Loaded via:
```php
\Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/views');
```

**Config loading errors** (parse failures, missing `name` attributes, invalid renderers) now throw `RuntimeException` with per-file details. Retrieve per-file results via `TemplateEngine::getLastLoadErrors()`. Previously silent parse failures are surfaced as errors in the log.

### Semantic Roles (✅ v4.7)

Fields can declare semantic roles for card_grid layout positioning:

```disyl
{ikb_entity_view name="cms_post" view="card_grid"}
    {field name="title"   type="string" role="title"}
    {field name="excerpt" type="string" role="subtitle"}
    {field name="image"   type="string" role="image"}
    {action name="view" url="{base_url}/cms/blog/{slug}"}
{/ikb_entity_view}
```

Supported roles: `title`, `subtitle`, `image`, `body`, `description`.

### View Contract Validation (✅ v4.7)

At registration time, every `{ikb_entity_view}` is validated for:
- **Duplicate field names** — two `{field name="same"}` declarations
- **Duplicate role values** — two fields with `role="title"`
- **Action URL placeholder mismatches** — `{id}` or `{slug}` in action URLs not matching any declared field

### Unknown Component Suggestion (✅ v4.7)

When a misspelled governed component is used (e.g., `{ikb_botton}` instead of `{ikb_button}`), the engine now suggests the closest match via Levenshtein distance:

```
Unknown component 'ikb_botton' — not registered. Did you mean 'ikb_button'?
```

### `ikb_entity_list`

```disyl
{ikb_entity_list 
    source="employee_profile.all" 
    view="table" 
    use="tailwind"
    limit="25"
    empty="No employees yet."
    actions="view,edit,delete"
    search="true"
    search-placeholder="Filter employees..."
    row-click="/admin/employees/{id}"
    row-click-target="_blank"
    header="#employeeFilters"
    bulk-actions="delete,export"
    bulk-action-url="/admin/employees/bulk"
    action-roles='{"delete":"admin","approve":["admin","supervisor"]}'
    auth-role="admin"
/}
```

| Attribute | Type | Description |
|-----------|------|-------------|
| `source` | string* | Entity source (e.g. `office_location`) |
| `view` | string | `compact`, `table`, `card_grid`, `detailed` |
| `use` | string | CSS framework: `tailwind`, `bootstrap`, `legacy` |
| `limit` | int | Max rows |
| `empty` | string | Empty state message |
| `actions` | string | Comma-separated: `view,edit,delete,approve` |
| `header` | string | HTML or `#blockName` above list |
| `search` | bool | Enable client-side filter (✅ v4.7) |
| `search-placeholder` | string | Search input placeholder (✅ v4.7) |
| `row-click` | string | URL pattern `{id}` substitution (✅ v4.7) |
| `row-click-target` | string | `_blank` etc. (✅ v4.7) |
| `bulk-actions` | string | Comma-separated bulk ops (✅ v4.7) |
| `bulk-action-url` | string | POST endpoint (✅ v4.7) |
| `auth-role` | string | Override role for visibility (✅ v4.7) |
| `action-roles` | JSON | Action → role map (✅ v4.7) |
| `class` | string | Additional CSS classes |
| `filter` | string | Comma-separated `key=value` pairs, `{var.path}` resolved from context |

### `ikb_entity_detail`

```disyl
{ikb_entity_detail 
    source="employee_profile" 
    id="{employeeId}" 
    view="detailed"
    fields="name,email,role,status"
/}
```

### Custom Row Rendering

```disyl
{ikb_entity_list source="employees" view="compact"}
    <div class="custom-row">
        <strong>{this.name}</strong> — {this.role}
    </div>
{/ikb_entity_list}
```

### Cell Renderers

Configured in `EntityViewResolver::builtinDefaults()`:

```
renderers: {
    "status": "badge:{\"active\":\"Active|green\",\"inactive\":\"Inactive|gray\"}",
    "amount": "money:2",
    "created_at": "datetime:date",
    "is_verified": "boolean"
}
```

---

## 7. User-Defined Macros (✅ v4.7)

### Definition

```disyl
{macro input(name, type = "text", label = "")}
    <div class="form-group">
        {if label}<label for="{name}">{label}</label>{/if}
        <input type="{type}" name="{name}" id="{name}"
               class="form-control" />
    </div>
{/macro}
```

### Calling

```disyl
{call input("email", "email", "Email Address")}
{call input("search")}                    — uses defaults
{call input("age", "number", "Age")}
```

### No-Arguments

```disyl
{macro footer()}
    <footer>&copy; 2026 My Company</footer>
{/macro}

{call footer}
```

---

## 8. Raw Blocks & Comments

### Comments

```
{!-- HTML comment: visible in output --}
{* DiSyL comment: stripped from output *}
{# Twig-style: stripped from output #}
```

### Raw Blocks

```
{verbatim}
    {if this}will NOT be processed{/if}
{/verbatim}

{literal}
    <script>const x = {notA_disyl_var};</script>
{/literal}
```

---

## 9. Security

### Auto-Escaping

All `{variable}` output is HTML-escaped by default. Use `|raw` to bypass (logged in strict mode).

### CSRF Protection

POST forms in entity actions auto-inject `_token` via `csrf_token()`.

### Sandbox

```
{sandbox allowed-tags="div,span,p,a"}
    {untrusted}
        User content here — only safe tags render
    {/untrusted}
{/sandbox}
```

### Strict Mode (✅ ON by default — v4.7+)

- Logs undefined variables
- Logs type mismatches in `{set}`
- Logs `|raw` filter usage
- Disable: `DISYL_STRICT_MODE=false` in env

---

## 10. Error Recovery

A malformed control structure does not abort the entire template:

```disyl
Section A — renders fine
{if broken{/if}           ← logs warning, skipped
Section B — still renders
```

---

## 11. Configuration

| Env Var | Default | Description |
|---------|---------|-------------|
| `DISYL_STRICT_MODE` | `true` (✅ v4.7+) | Enable strict warnings |
| `?disyl_nocache=1` | — | Force recompile (dev only) |

---

## 12. Module Wiring Checklist

For a module to expose entity views:

1. `module.json` — declare `entity.list.X@1` and `entity.get.X@1` in `capabilities.exposes`
2. `helpers.php` — map capability IDs to handler functions returning `{rows, total}`
3. `EntityViewResolver::builtinDefaults()` — register field/action/renderer definitions
4. Templates — use `{ikb_entity_list}` / `{ikb_entity_detail}`

---

## See Also

- `docs/kernel/entity-view-adoption-plan.md` — adoption status
- `docs/kernel/kernel-os-disyl-roadmap-status.md` — implementation status
- `docs/kernel/disyl-grammar-v11-planned-types.md` — v11 roadmap
- `kernel/DiSyL/Grammar/Planned.php` — future keywords

# DiSyL in 5 Minutes

DiSyL is the Ikabud kernel's native template engine. It compiles `.disyl` files to PHP for fast server-side rendering, with Alpine.js-friendly HTML output.

## 1. Variables

```disyl
<h1>Welcome, {user.name}!</h1>
<p>Your role: {user.role}</p>
```

Variables come from the render context (passed by PHP handlers). Use dot notation for nested keys: `{order.total}`.

**Filters** transform values with `|`:
```
{title|upper}          →  "HELLO WORLD"
{price|money}          →  "₱1,234.56"
{created_at|datetime}  →  "Jun 20, 2026"
```

## 2. Conditionals

```disyl
{if user.role == 'admin'}
    <a href="/admin">Dashboard</a>
{elseif user.role == 'editor'}
    <a href="/editor">Editor</a>
{else}
    <a href="/login">Log in</a>
{/if}
```

Boolean checks:
```disyl
{if cart.items}            <!-- truthy -->
{if !cart.is_empty}        <!-- negated -->
{if count > 10 && active}  <!-- AND -->
{if flag_a || flag_b}      <!-- OR -->
```

## 3. Loops

```disyl
{foreach products as item}
    <div class="product-card">
        <h3>{item.name}</h3>
        <span>{item.price|money}</span>
    </div>
{empty}
    <p class="empty">No products found.</p>
{/foreach}
```

`{for}` with range:
```disyl
{for i in range(1, 5)}
    <span>{i}</span>
{/for}
```

## 4. Computed Variables with `{set}`

```disyl
{set is_editable = status != 'locked' && role == 'admin'}
{if is_editable}
    <button>Edit</button>
{/if}
```

Supports: `==`, `!=`, `>`, `<`, `>=`, `<=`, `!`, `&&`, `||`.

## 5. Templates & Layouts

**Layout** (`layouts/base.disyl`):
```disyl
<!DOCTYPE html>
<html>
<body>
    {block content}{/block}
</body>
</html>
```

**Page** (`pages/home.disyl`):
```disyl
{extends "layouts/base.disyl"}
{block content}
    <h1>Home Page</h1>
{/block}
```

**Includes** pull in partials:
```disyl
{include "partials/header.disyl"}
```

> ⚠️ **Nested `{block}` tags are not supported.** A `{block}` cannot contain another `{block}`. The linter (`php ikabud disyl:lint`) catches this.

## 6. Entity Lists — The Power Feature

The `{ikb_entity_list}` tag renders database-driven tables/ grids from declarative source+view:

```disyl
{ikb_entity_list source="employee_profile.all" view="table" limit="25"}
```

| Attribute | Purpose |
|-----------|---------|
| `source` | `{entity_type}.{qualifier}` — e.g. `"orders.recent"`, `"employee_profile.all"` |
| `view` | Rendering mode: `"table"`, `"compact"`, `"card_grid"`, `"kanban"` |
| `limit` | Max rows (default 25) |
| `sort` | Sort field |

### The 3-Layer Rule

Entity views resolve through 3 independent layers that **must agree on column names**:

1. **Compact defaults** — `kernel/EntityContext/EntityViewResolver.php`
2. **Registered views** — `modules/*/helpers/entity-views.php`
3. **Handler SQL** — `modules/*/helpers.php` (capability handler)

If your view declares `first_name` but SQL returns `CONCAT_WS(...) AS name`, the column will be empty. The runtime field validator logs mismatches to `storage/logs/app.log`.

### EntityListQuery — Modern Handler Pattern

Instead of hardcoding SQL SELECT, use `EntityListQuery::run()`:

```php
use Ikabud\Kernel\EntityContext\EntityListQuery;

function my_cap_entity_list_orders_1(mixed $payload, ...): array {
    return EntityListQuery::run(
        module('my-module')->db(),
        'orders',
        [
            'id'         => 'order_id',
            'customer'   => 'customer_name',
            'total'      => 'ROUND(total_cents/100, 2)',
            'status'     => 'order_status',
            'created_at' => 'created_at',
        ],
        $payload,
        'tenant_id = :tid',           // base WHERE
        [':tid' => tenantId()]         // bound params
    );
}
```

The column map is a **whitelist** — only declared columns can be queried. The view contract's `fields` drives which columns are selected, so the 3 layers stay in sync by construction.

## 7. Comments

```disyl
{!-- This is a DiSyL comment — stripped at compile time --}
```

## Quick Reference

| Tag | Purpose |
|-----|---------|
| `{var}` | Output variable |
| `{var\|filter}` | Output with filter |
| `{if cond}…{/if}` | Conditional |
| `{foreach arr as item}…{/foreach}` | Loop |
| `{for i in range(1,n)}…{/for}` | Range loop |
| `{set x = expr}` | Computed variable |
| `{extends "layout.disyl"}` | Layout inheritance |
| `{block name}…{/block}` | Block placeholder/override |
| `{include "partial.disyl"}` | Include partial |
| `{ikb_entity_list source="…" view="…"}` | Entity list |
| `{empty}…` | Empty-state for loops |
| `{!-- … --}` | Comment |

## Editor Support

Install the **DiSyL LSP** VS Code extension from `extensions/disyl-lsp/`:
- Syntax highlighting
- Autocomplete (filters, components, block keywords)
- Diagnostics via `php ikabud disyl:lint` on save
- Go-to-definition for `{extends}`, `{include}`, `{component}`
- Bracket matching for `{if}`/`{/if}`, `{block}`/`{/block}`, etc.

Run the linter manually:
```bash
php ikabud disyl:lint                    # all templates
php ikabud disyl:lint path/to/file.disyl  # single file
php ikabud disyl:lint --verbose           # show passing files
```

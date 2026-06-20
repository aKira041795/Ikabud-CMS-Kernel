# DiSyL Grammar Reference v4.7

> **Status**: Extracted from the parser implementation (`kernel/DiSyL/TemplateEngine.php` v4.7.0, `kernel/DiSyL/v4/Parser.php`).
> This document is a **human-readable reference** with examples and explanations.
> For the **formal machine-readable grammar**, see [disyl-grammar-v4.7.ebnf](./disyl-grammar-v4.7.ebnf) — a complete 56-rule EBNF specification suitable for parser generators, linters, and syntax highlighting grammars.

---

## 1. Variable Output

```
{variable_name}
{object.property}
{nested.path.to.value}
```

### Filters (pipe chain)
```
{variable | filter1}
{variable | filter1 | filter2}
{price | number_format:2 | default:'0.00'}
```

### Built-in filters
| Filter | Arguments | Description |
|---|---|---|
| `escape` | — | HTML-escape output (default for all vars in strict mode) |
| `raw` | — | Output without escaping (logged in strict mode) |
| `json` | — | JSON-encode, output raw |
| `default` | fallback | Use fallback when value is null/empty |
| `number_format` | decimals | Format number with specified decimals |
| `upper` | — | Uppercase |
| `lower` | — | Lowercase |
| `trim` | — | Trim whitespace |

---

## 2. Expressions

### Arithmetic
```
{page + 1}
{total - count}
{price * qty}
{x / y}
{x % y}
```

### Ternary
```
{condition ? 'yes' : 'no'}
{user.active ? 'Active' : 'Inactive'}
```

### Conditions (in `{if}` blocks)
```
{page + 1 > total}
{count - 1 == 0}
{status == 'active'}
{role != 'guest'}
{price >= 100}
{items|count > 0}
```

---

## 3. Control Structures

### `{if}` / `{elseif}` / `{else}` / `{/if}`
```
{if condition}
    <p>True branch</p>
{elseif other_condition}
    <p>Else-if branch</p>
{else}
    <p>False branch</p>
{/if}
```

### `{foreach}` / `{/foreach}`
```
{foreach items as item}
    <li>{item.name}</li>
{foreachelse}
    <li>No items found</li>
{/foreach}
```

### `{for}` / `{/for}`
```
{for i = 0; i < 10; i++}
    <span>{i}</span>
{/for}
```

### `{set}`
```
{set name = expression}
{set total = price * qty}
```

### `{verbatim}` / `{/verbatim}`
```
{verbatim}
    Everything here is output literally: {not_parsed}, {if true}, {{curly braces}}
{/verbatim}
```

### `{literal}` / `{/literal}`
```
{literal}
    Same as verbatim but extracted per-compile() call — works inside loops
{/literal}
```

### `{macro}` / `{/macro}`
```
{macro button(text, url)}
    <a href="{url}" class="btn">{text}</a>
{/macro}

{button('Click me', '/go')}
```

---

## 4. Component Tags

### Entity components
```
<ikb_entity_view entity="current" view="product-detail">
    <ikb_entity_pricing />
    <ikb_entity_inventory />
    <ikb_entity_actions />
</ikb_entity_view>
```

```
<ikb_entity_list source="orders.recent" view="compact" limit="10" />
```

### Form components
```
<ikb_form handler="contact.submit" method="POST">
    <ikb_form_field name="email" type="email" required />
    <ikb_form_field name="message" type="textarea" />
</ikb_form>
```

### Navigation components
```
<ikb_nav source="main" />
<ikb_breadcrumb />
```

---

## 5. Special Behaviors

### Script Block Extraction
`<script>` blocks are extracted before control structure processing. Template variables inside `<script>` still resolve:
```
<script>
    const page = {page};
    const total = {total};
    {if debug}
        console.log('Debug mode');
    {/if}
</script>
```

### Compiled Mode (default v4.7+)
Templates are compiled to PHP cache files. On cache miss or parse failure, falls back to interpreted mode. The compiled cache lives in `storage/cache/disyl/`.

### Strict Mode (default v4.8+)
When enabled, logs undefined variables, type mismatches, and `|raw` usage as warnings. Does not break rendering — logs are written to `storage/logs/app.log`.

### Extends
```
{extends 'layouts/base'}
```
Resolves parent templates from `templates/` directory. Cross-request resolution is cached in `storage/cache/disyl-extends/`.

---

## 6. Component Registration

Components are registered via `ComponentRegistry`:
```php
app()->templateEngine()->registerComponent('ikb_entity_list', $handler);
```

The handler receives parsed attributes and child content, and returns rendered HTML.

---

## 7. Grammar Overview (Informal)

```
template    = (text | variable | control_structure | component_tag)*
text        = any character except { and <ikb_
variable    = "{" expr filters? "}"
filters     = "|" IDENT (":" arg)?
expr        = ternary | arithmetic | path
ternary     = condition "?" expr ":" expr
arithmetic  = term (("+" | "-") term)*
term        = factor (("*" | "/" | "%") factor)*
factor      = NUMBER | STRING | path | "(" expr ")"
path        = IDENT ("." IDENT)*
condition   = expr (">" | "<" | ">=" | "<=" | "==" | "!=") expr
control     = if_block | foreach_block | for_block | set_stmt
              | verbatim_block | literal_block | macro_def
component   = "<" IK_PREFIX COMPONENT_NAME attrs ">" content "</" IK_PREFIX COMPONENT_NAME ">"
              | "<" IK_PREFIX COMPONENT_NAME attrs "/>"
```

---

## 8. Error Recovery (v4.7+)

The parser wraps all 9 control structures in `recoverableParse()`:
- On parse failure within a control structure, the raw source block is output as-is
- An error is logged with the template path and line context
- Rendering continues — a broken `{if}` block won't crash the page

---

## 9. Version History

| Version | Changes |
|---|---|
| v4.7.0 | Compiled mode default, per-block error recovery, EntityRenderingTrait extracted |
| v4.0.0 | `{verbatim}`, `{literal}` fix, `<script>` full control structure support, `|json` raw output, `|default` null handling |
| v3.0.0 | Arithmetic expressions, ternary, `{set}`, fixed `{if}` nesting, arithmetic in conditions |
| v2.2.0 | Script-aware compilation, `<script>` block extraction |
| v2.1.0 | Output caching, auto-escape, per-request in-memory cache |

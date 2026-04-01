# DiSyL v4.0.0 — Implementation Specification

**Engine:** `kernel/DiSyL/TemplateEngine.php`  
**Version:** 4.0.0  
**Test suite:** `tests/disyl_engine_test.php` (243 tests, 40 sections)  
**Linter:** `php ikabud disyl:lint [path] [--verbose]`

---

## Table of Contents

1. [Variables](#variables)
2. [Filters](#filters)
3. [Conditions](#conditions)
4. [Loops](#loops)
5. [Set Statements](#set-statements)
6. [Expressions](#expressions)
7. [Comments](#comments)
8. [Verbatim & Literal Blocks](#verbatim--literal-blocks)
9. [Script-Awareness](#script-awareness)
10. [Template Inheritance](#template-inheritance)
11. [Includes](#includes)
12. [Components](#components)
13. [Globals](#globals)
14. [Custom Filters](#custom-filters)
15. [Security](#security)
16. [Error Handling](#error-handling)
17. [Linter Checks](#linter-checks)

---

## Variables

Curly-brace interpolation with dot-path navigation.

```disyl
{name}                     → simple variable
{user.email}               → dot-path (one level)
{user.address.city}        → deeply nested dot-path
{missing_var}              → renders empty string (no error)
```

**Auto-escaping:** All variable output is HTML-escaped by default. Use `| raw` to bypass.

```disyl
{html_content}             → &lt;b&gt;Hello&lt;/b&gt;
{html_content | raw}       → <b>Hello</b>
```

---

## Filters

Pipe-chain syntax: `{value | filter1 | filter2:arg}`. Multiple filters chain left-to-right.

### String Filters

| Filter | Example | Result |
|--------|---------|--------|
| `upper` | `{name \| upper}` | `HELLO` |
| `lower` | `{name \| lower}` | `hello` |
| `capitalize` | `{name \| capitalize \| raw}` | `Hello world` |
| `title` | `{name \| title \| raw}` | `Hello World` |
| `trim` | `{name \| trim}` | `hello` |
| `truncate` | `{text \| truncate:5}` | `hello...` |
| `nl2br` | `{text \| nl2br}` | `line1<br />\nline2` |
| `strip_tags` | `{text \| strip_tags \| raw}` | `hello` |
| `replace:old,new` | `{text \| replace:_,-}` | `hello-world` |
| `split:sep` | `{text \| split:- \| join}` | `a, b, c` |
| `reverse` | `{text \| reverse \| raw}` | `dlrow` |
| `length` | `{text \| length}` | `5` |
| `url_encode` | `{text \| url_encode}` | `hello+world` |
| `base64` | `{text \| base64}` | `aGVsbG8=` |
| `md5` | `{text \| md5}` | `5d41402a...` |

### Numeric Filters

| Filter | Example | Result |
|--------|---------|--------|
| `number_format` | `{val \| number_format}` | `1,234` |
| `number_format:2` | `{val \| number_format:2}` | `1,234.50` |
| `abs` | `{val \| abs}` | `42` |
| `round` | `{val \| round}` | `3` |
| `round:2` | `{val \| round:2}` | `3.14` |
| `floor` | `{val \| floor}` | `3` |
| `ceil` | `{val \| ceil}` | `4` |

### Array Filters

| Filter | Example | Result |
|--------|---------|--------|
| `count` | `{items \| count}` | `3` |
| `join` | `{items \| join}` | `a, b, c` |
| `join:"\|"` | `{items \| join:"\|"}` | `a\|b\|c` |
| `first` | `{items \| first}` | first element |
| `last` | `{items \| last}` | last element |
| `sort` | `{items \| sort \| join}` | sorted, joined |
| `reverse` | `{items \| reverse \| join}` | reversed |
| `unique` | `{items \| unique \| join}` | deduplicated |
| `slice:start,len` | `{items \| slice:1,2 \| join}` | sub-array |
| `keys` | `{obj \| keys \| join}` | object keys |
| `values` | `{obj \| values \| join}` | object values |
| `pluck:field` | `{users \| pluck:name \| join}` | extract field |
| `group_by:field` | `{items \| group_by:category}` | group by key |
| `map:filter` | `{items \| map:upper \| join}` | apply filter to each |
| `flatten` | `{nested \| flatten \| join}` | flatten nested arrays |

### Special Filters

| Filter | Example | Result |
|--------|---------|--------|
| `default:val` | `{missing \| default:"N/A"}` | `N/A` |
| `date:fmt` | `{ts \| date:"Y-m-d"}` | formatted date |
| `json` | `{data \| json \| raw}` | JSON-encoded |
| `raw` | `{html \| raw}` | bypass HTML escaping |
| `e` / `escape` | explicit HTML escaping | escaped output |
| `dump` | `{data \| dump \| raw}` | debug dump |
| `type` | `{val \| type}` | `string`, `integer`, etc. |
| `batch:n` | `{items \| batch:2}` | chunk into groups |
| `merge:key,val` | `{obj \| merge:extra,true}` | merge key into array |
| `striptags` | alias of `strip_tags` | strip HTML |

`default` falls back only when the incoming value is `null`, unresolved, or an empty string. Explicit `false` and `0` are preserved so boolean template flags can safely use fallback expressions like `{if entity_view_context.show_meta | default:1}`.

### Filter Chaining

```disyl
{name | trim | upper | truncate:10}
{items | sort | reverse | first}
{text | split:" " | count}
```

---

## Conditions

### Basic

```disyl
{if show}
  <p>Visible</p>
{/if}

{if show}
  <p>Yes</p>
{else}
  <p>No</p>
{/if}

{if role == "admin"}
  <p>Admin</p>
{elseif role == "editor"}
  <p>Editor</p>
{else}
  <p>User</p>
{/if}
```

### Comparison Operators

```disyl
{if count > 0}         greater than
{if count >= 10}       greater or equal
{if count < 5}         less than
{if count <= 100}      less or equal
{if name == "Alice"}   equality (string)
{if count == 42}       equality (numeric)
{if name != "Bob"}     inequality
```

### Logical Operators

```disyl
{if show and active}       AND
{if show or fallback}      OR
{if not hidden}            NOT / negation
{if !hidden}               also negation
```

### Truthiness

- Truthy: non-empty strings, non-zero numbers, true, non-empty arrays
- Falsy: empty string, `0`, `false`, `null`, empty arrays, undefined variables

### Filters in Conditions

```disyl
{if items | count > 0}           filter result in comparison
{if name | length >= 3}          string length check
{if tags | join:", " == "a, b"}  filter chain in condition
{if name | upper == "ALICE"}     transform then compare
{if items | first == "alpha"}    array filter in condition
```

### Nested Conditions

```disyl
{if outer}
  {if inner}
    <p>Both true</p>
  {else}
    <p>Only outer</p>
  {/if}
{/if}
```

---

## Loops

### For Loops

Numeric range iteration.

```disyl
{for i in 1..3}
  <p>Item {i}</p>
{/for}
```

Loop variables: `{loop.index}` (0-based), `{loop.index1}` (1-based), `{loop.first}`, `{loop.last}`, `{loop.length}`.

```disyl
{for i in 1..items|count}
  <span>{loop.index1}. {i}</span>
{/for}
```

Empty clause (rendered when the iterable resolves to an empty list):

```disyl
{for item in items}
  <li>{item}</li>
{empty}
  <p>No items found.</p>
{/for}
```

### Foreach Loops

Iterate over arrays.

```disyl
{foreach items as item}
  <li>{item}</li>
{/foreach}

{foreach users as user}
  <p>{user.name} — {user.email}</p>
{/foreach}
```

With key access:

```disyl
{foreach items as key => item}
  <dt>{key}</dt>
  <dd>{item}</dd>
{/foreach}
```

Empty clause:

```disyl
{foreach items as item}
  <li>{item}</li>
{empty}
  <p>No items found.</p>
{/foreach}
```

### Each Loops

Alternative iteration syntax with loop variables.

```disyl
{each colors as color}
  <span class="{color}">{loop.index1}. {color}</span>
{/each}

{each users as key => user}
  <p>{key}: {user}</p>
{/each}
```

### Nested Loops

```disyl
{foreach categories as cat}
  <h2>{cat.name}</h2>
  {foreach cat.items as item}
    <li>{item}</li>
  {/foreach}
{/foreach}
```

---

## Set Statements

Assign variables within templates.

```disyl
{set greeting = "Hello"}
{greeting}                          → Hello

{set full = first . " " . last}    → string concatenation
{set total = price * qty}           → arithmetic
{set label = name | upper}          → filter in set
```

---

## Expressions

### Arithmetic

```disyl
{price + tax}              addition
{total - discount}         subtraction
{price * qty}              multiplication
{total / count}            division
{total % 2}               modulo
{(price + tax) * qty}     parenthesized
```

### String Concatenation

```disyl
{first . " " . last}      dot operator
```

### Ternary

```disyl
{active ? "Yes" : "No"}
{count > 0 ? count : "none"}
{name ? name : "Anonymous"}          → truthy check
{user.name ? user.name : "Guest"}    → nested ternary
```

---

## Comments

```disyl
{!-- This is a DiSyL comment --}
{!-- Comments are stripped from output --}
{!-- Multi-line
     comments
     work too --}
```

Comments are completely removed from rendered output.

---

## Verbatim & Literal Blocks

### Verbatim

Prevents DiSyL processing inside the block. Content is output as-is.

```disyl
{verbatim}
  {this_is_not_processed}
  {if this_either}nope{/if}
{/verbatim}
```

Output: `{this_is_not_processed}` (literal curly braces preserved).

### Literal

Alias for verbatim — same behavior.

```disyl
{literal}
  function() { return {x: 1}; }
{/literal}
```

---

## Script-Awareness

DiSyL is script-aware: curly braces inside `<script>` tags are **not** processed as template syntax.

```disyl
<script>
  const data = {x: 1, y: 2};     ← NOT treated as variables
  console.log(data);
</script>
```

DiSyL variables **inside** script tags still work when they follow template syntax:

```disyl
<script>
  const name = "{user_name}";     ← IS processed
</script>
```

---

## Template Inheritance

### Layout (parent)

```disyl
<html>
<head>{block head}default head{/block}</head>
<body>{block content}default content{/block}</body>
</html>
```

### Child template

```disyl
{extends "layout"}

{block head}<title>My Page</title>{/block}
{block content}<h1>Hello</h1>{/block}
```

Unoverridden blocks fall back to the parent's default content.

### Multi-level inheritance

Chains of any depth are supported. A grandchild can extend a parent that itself extends a grandparent:

```disyl
{!-- grandparent: base.disyl --}
<html><body>{block content}default{/block}</body></html>

{!-- parent: layout.disyl --}
{extends "base"}
{block content}<main>{block body}parent body{/block}</main>{/block}

{!-- child: page.disyl --}
{extends "layout"}
{block body}<h1>{title}</h1>{/block}
```

Block resolution: the most-derived child's declaration wins. Uninherited blocks in intermediate parents are preserved without modification.

Circular `{extends}` chains (A extends B extends A) are detected at render time and break gracefully — the engine logs the error and renders what it has so far.

**Limitation:** nesting `{block}` directives inside other `{block}` bodies in the same template file is not supported. Place nested structure in the ancestor template, not in the child overrides.

### HTMX Partial Mode

When `is_htmx` is truthy in context, `{extends}` is skipped and only block contents are emitted — enabling HTMX partial responses without layout chrome.

---

## Includes

```disyl
{include "header"}                           → include header.disyl
{include "partials/nav"}                     → subdirectory
{include "partials/nav" with {label: "Home"}}  → pass context
```

Includes inherit the parent template's context by default. The `with` clause provides additional (or overriding) variables.

---

## Components

Built-in `ikb_*` components render semantic HTML with appropriate classes and attributes.

### Block Components

```disyl
{ikb_card}content{/ikb_card}                    → <div> wrapper
{ikb_card id="my-card"}content{/ikb_card}        → with id attribute
{ikb_text}paragraph{/ikb_text}                   → <p> tag (default)
{ikb_text tag="h1"}Title{/ikb_text}              → custom element
{ikb_button variant="primary"}Click{/ikb_button} → <button> or <a>
{ikb_grid columns="3"}cells{/ikb_grid}           → grid layout
{ikb_alert variant="success"}Done!{/ikb_alert}   → alert box
```

### Self-Closing Components

```disyl
{ikb_spinner size="large" /}
{ikb_icon name="check" /}
```

### Component Attributes

All components accept standard HTML attributes (`id`, `class`, etc.) plus component-specific props (`variant`, `tag`, `columns`, `size`, `name`).

---

## Globals

Global variables are available in every template render. Set via the engine API.

```php
$engine->setGlobals(['site_name' => 'Ikabud']);
```

```disyl
{site_name}                → "Ikabud" (always available)
```

Local context overrides globals when names collide.

---

## Custom Filters

Register custom filters via the engine API.

```php
$engine->registerFilter('shout', function($val) {
    return strtoupper($val) . '!!!';
});
```

```disyl
{name | shout}             → "HELLO!!!"
```

Custom filters chain with built-in filters normally.

---

## Security

### Path traversal protection

Template names passed to `{include}` and `{extends}` are normalized before resolution. Path segments containing `..` that would escape the configured `templateDir` are blocked. The engine logs the attempt and returns an empty string for the include.

Absolute paths from trusted callers (e.g. module render helpers) are permitted.

### URL sanitization

The `esc_url` filter and the `href` attribute of `{ikb_button}` / `{ikb_link}` components reject dangerous URL schemes (`javascript:`, `vbscript:`, `data:`) and return `#` in their place. Safe schemes: `http`, `https`, `mailto`, `tel`, `ftp`, and relative paths.

---

## Error Handling

- Missing variables render as empty string (no exception)
- Missing include files render as empty string
- Invalid filter names render the value unchanged
- Circular `{extends}` references are detected and logged; rendering continues with available content
- Circular `{include}` references are detected after one full cycle and logged

---

## Linter Checks

The `disyl:lint` command performs 8 structural checks on `.disyl` template files:

| # | Check | Severity |
|---|-------|----------|
| 1 | Unmatched control structure tags (`if`, `for`, `foreach`, `each`, `verbatim`, `literal`) | Error |
| 2 | `{else}` / `{elseif}` outside an `{if}` block | Error |
| 3 | Unclosed `ikb_*` component tags | Error |
| 4 | Missing `{include}` file references | Warning |
| 5 | Missing `{extends}` layout references | Warning |
| 6 | Empty control structure bodies | Warning |
| 7 | `{for}` / `{foreach}` tag confusion (wrong syntax for the tag) | Warning |
| 8 | Variable roots not declared in matched render contract | Warning |

The linter strips DiSyL comments (`{!-- --}`) and JS single-line comments before structural analysis to avoid false positives from commented-out code.

**Scan paths:** `templates/`, all `modules/*/templates/`, `modules/*/public/`, `modules/*/views/`, and `modules/*/pages/`.

### Check 8 — contract-aware variable root linting

When a render context contract is registered for the current template (matched by exact path or prefix), the linter statically extracts `required` and `defaults` keys from the contract definition and warns about variable roots used in the template that are not covered. Checks are skipped for:

- kernel base context roots (`user`, `nav_items`, `gui`, `base_url`, `loop`, etc.)
- variables declared inline with `{set ...}`
- loop-bound variable names (`{foreach items as item}`, `{for x in ...}`, etc.)
- contracts that rely solely on a `normalize` callback with no explicit `required`/`defaults` keys (opaque to static analysis)

```bash
php ikabud disyl:lint                            # scan all templates
php ikabud disyl:lint templates/modules/mymod    # scan specific path
php ikabud disyl:lint --verbose                  # show passing files
```

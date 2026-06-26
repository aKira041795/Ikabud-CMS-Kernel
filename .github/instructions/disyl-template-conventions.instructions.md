---
description: "DiSyL template language conventions — entity views, control structures, rendering patterns, and common pitfalls in the Ikabud application."
applyTo: "**/*.disyl"
---
# DiSyL Template Conventions

## Core directive
**If DiSyL does not support a PHP syntax construct that a template needs, improve DiSyL at the engine/root level. Do NOT add template-level workarounds.** See `.github/instructions/disyl-grammar-gaps.instructions.md` for the full PHP syntax support matrix.

## Entity Views
- `{ikb_entity_list}` — renders a list view from an entity view contract
- `{ikb_entity_detail}` — renders a detail view from an entity view contract
- `{ikb_entity_view}` — registers a view contract in `helpers/views/` config files
- View contracts are loaded by `TemplateEngine::loadViewConfigs()`

## Composite Pages
- For dashboards and multi-section detail pages: use a custom DiSyL template
- Handler fetches aggregate data; template embeds `{ikb_entity_list}` calls
- Entity views handle single-source display only — not computed metrics, tabs, charts, or multi-field filter forms

## Control Structures
- Use standard DiSyL control structures (`{if}`, `{for}`, `{foreach}`, `{each}`, `{include}`)
- Use `{ikb_render}` for inline rendering of components
- Avoid HTML-as-source edits — builder source of truth is structured JSON

## Expression Rules
- Use dot-path notation for object access: `{user.name}` — never `{user->name}` or `{user.name()}`
- Use `{var ?? fallback}` for null-coalescing (desugars to `|default:`)
- Parenthesize arithmetic before pipes: `{(a + b) | number_format:2}` — otherwise `b | filter` binds tighter
- String concatenation uses `~` operator: `{a ~ b}`, `{'INV#'~s.id}` (Twig-style, supported in both interpreted and compiled modes)
- Only whitelisted functions work in compiled mode: `range`, `count`, `abs`, `round`, `floor`, `ceil`, `min`, `max`, `length`, `str_*`, `trim`, `substr`, `strlen`, `number_format`, `isset`, `empty`, `is_array`
- `isset()`/`empty()`/`is_array()` are now in the whitelist — `{if isset(source_label)}` works in compiled mode. Both `isset(var)` and `isset($var)` syntaxes are supported (the `$` prefix is stripped automatically).

## Common Pitfalls
- DiSyL curly braces inside Alpine.js `x-data`, `@click`, `x-init` attributes can conflict — use `{verbatim}`/`{literal}` blocks, or escape with `{` → `{` patterns
- For Alpine.js + DiSyL: the engine extracts `<script>` blocks before processing, but inline attribute handlers (`@click="..."`) are NOT extracted — DiSyL sees bare `{}` there
- Compiled template cache may need clearing after template changes: delete `storage/cache/disyl/`
- **If compiled mode is enabled and a template uses `isset()`/`empty()`/`is_array()`, those will silently return null** — compiled code goes through `FunctionRegistry::call()` which has no such entries

## Rendering Context
- `{cmsRender}`, `{cmsAdminContext}` — CMS rendering context providers
- Public rendering must be deterministic — no duplicate/conflicting HTML attributes
- For builder style/props attributes, preserve default-merge semantics from `NodeRenderer.tsx` and `modules/cms/helpers.php`

## When to improve DiSyL vs. fix a template
- Missing operator (`~`, `++`, `+=`, etc.) → **improve DiSyL** (parser + evaluator)
- Missing function (`isset()`, `empty()`, etc.) → **add to `FunctionRegistry`**
- Alpine.js `{}` parse conflict → **improve DiSyL** (script/attribute extraction)
- Wrong syntax (`user->name` instead of `user.name`) → **fix template**
- Bypassing DiSyL with raw PHP → **fix template**

---
name: disyl-engine-first-fix
description: When DiSyL lacks a feature, fix the engine rather than adding template bandaids
applyTo: "**/*.disyl"
---

# DiSyL Engine-First Fix Strategy

## Core principle
When DiSyL lacks a feature or blocks a needed behavior, **fix DiSyL at the engine level** (`kernel/DiSyL/`) rather than working around it in templates or modules. Bandaid workarounds multiply technical debt across every template that hits the same gap.

## When to go to the engine
Ask yourself: "Will I need this in more than one template/module?" If yes, the fix belongs in the engine. Examples:

| Limitation | Bandaid | Engine fix (preferred) |
|---|---|---|
| No `{forelse}` in `{for}` loops | Add `{if not list}` + empty row after `{/for}` in every template | Add `{forelse}` parsing to `kernel/DiSyL/v4/Parser.php` and `ControlNode` |
| Strict-mode undefined variable warnings for common context keys | Pass `page_title` etc. from every handler | Add a default-context merge in the template engine render path |
| No string formatting filter (e.g. `number_format`) | Pre-format values in PHP before passing to template | Add `number_format`, `date_format`, etc. as pipe filters in `TemplateEngine.php` expression evaluator |
| Module DB layer blocks table aliases | Remove aliases from every query | Make the module DB SQL parser recognize `FROM table alias` as valid |

## How to address common DiSyL gaps

### Add new control structures
- File: `kernel/DiSyL/v4/Parser.php`
- Look for `parseBlock()` method that handles `{for}`, `{if}`, etc.
- Add new keyword in the same switch chain, create corresponding node class

### Add new pipe filters
- File: `kernel/DiSyL/TemplateEngine.php`
- Find the expression evaluator that handles `|` pipes (`|default:`, `|raw`, `|substr:`)
- Register new filters in the filter map following existing argument patterns

### Fix module DB SQL alias parsing
- File: `kernel/Services/DatabaseManager.php` (or relevant ModuleDB class)
- The SQL parser checking table permissions needs to strip aliases from `FROM table alias`
- Test with: `FROM table t`, `JOIN table t ON`, subquery aliases

## Templates to follow
- `{if}/{elseif}/{else}`: `v4/Parser.php` `parseBlock()`
- `{for}` + `{forelse}`: `ControlNode` already stores `$elseDoc` — just need Parser support
- Pipe filters: `TemplateEngine.php` `applyModifier()` dispatch pattern

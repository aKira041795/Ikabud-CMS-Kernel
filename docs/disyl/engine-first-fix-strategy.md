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
| Module DB layer blocks table aliases | Remove aliases from every query | Make the module DB SQL parser recognize aliases (`FROM table alias`) as valid references to `table` |

## When a bandaid IS acceptable
- The fix would require a schema/query change that's risky mid-session
- The gap affects only one template and the engine change would be disproportionately complex
- The engine change would break backward compatibility without a migration path

## How to address common DiSyL gaps

### 1. Add new control structures
File: `kernel/DiSyL/v4/Parser.php`
- Look for the `parseBlock()` method that handles `{for}`, `{if}`, etc.
- Add new keyword handling in the same switch/if chain
- Create corresponding `ControlNode` or new node subclass

### 2. Add new filters/pipe modifiers
File: `kernel/DiSyL/TemplateEngine.php`
- Search for the expression evaluator that handles `|` pipes (e.g. `|default:`, `|raw`, `|substr:`)
- Register new filter functions in the filter map
- Follow the existing pattern for argument parsing (e.g. `filter:arg1,arg2`)

### 3. Fix module DB SQL parsing
File: `kernel/Services/DatabaseManager.php` or relevant ModuleDB class
- The SQL parser that checks table permissions needs to handle `FROM table alias` syntax
- Strip alias tokens when extracting table names from SQL
- Test with common patterns: `FROM table t`, `JOIN table t ON`, subquery aliases

## Templates to follow
- `{if}/{elseif}/{else}`: see `v4/Parser.php` `parseBlock()` for the pattern
- `{for}` + `{forelse}`: the `ControlNode` already stores `$elseDoc` — just need Parser support
- Pipe filters: see `TemplateEngine.php` `applyModifier()` for the dispatch pattern

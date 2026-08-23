---
description: "DiSyL grammar/PHP syntax support analysis — what PHP constructs are supported, what gaps exist, and the directive: if DiSyL doesn't support it, fix DiSyL, not the template."
applyTo: "**/*.php **/*.disyl"
---
# DiSyL Grammar — PHP Syntax Support & Gaps

## Core directive
**If DiSyL does not support a PHP syntax construct that a template needs, improve DiSyL at the engine/root level. Do NOT add template-level workarounds.** If DiSyL has gaps rendering from any programming language, update DiSyL, not the template.

**If DiSyL has undefined filters, silent pass-through, or any observed weakness (missing aliases, broken edge cases, poor error messages) — fix DiSyL at the engine level, not the template.** Every template workaround that could have been a DiSyL engine fix is technical debt. When in doubt, fix the engine.

## PHP syntax supported by DiSyL

| Construct | Syntax | Example |
|---|---|---|
| Variable output | `{variable}` `{obj.prop}` `{obj.prop.sub}` | `{user.name}` |
| Dot-path object access | `.` instead of `->` | `{user.profile.email}` |
| Quoted strings | `'` and `"` | `{'hello'}`, `{"hello"}` |
| Numeric literals | int, float | `{42}`, `{3.14}` |
| Boolean/null | `true`, `false`, `null`, `none` | `{if active}` |
| Arithmetic | `+`, `-`, `*`, `/`, `%` | `{price * qty}` |
| Parenthesized sub-expressions | `(expr)` | `{(a + b) * c}` |
| Comparison | `==`, `!=`, `===`, `!==`, `>`, `<`, `>=`, `<=` | `{if count > 0}` |
| Logical | `&&`, `\|\|`, `and`, `or`, `not`, `!` | `{if a && b}` |
| Ternary | `{cond ? a : b}` | `{active ? 'Yes' : 'No'}` |
| Null-coalescing | `??` (desugared to `\|default:`) | `{var ?? fallback}` |
| Filters | `\|filter` `\|filter:arg1,arg2` | `{name \| upper}` |
| Filter: escape | `\|escape:'js'` `\|escape:'html'` `\|escape:'attr'` `\|escape:'url'` | `{text \| escape:'js'}` — delegates to `esc_js`/`esc_html`/`esc_attr`/`esc_url` |
| Filter: json | `\|json` (output raw: `\|json\|raw`) | `{data \| json \| raw}` — passes PHP data to JS via JSON |
| Control: if/elseif/else | `{if}` `{elseif}` `{else}` `{/if}` | — |
| Control: loops | `{for x in list}` `{foreach list as x}` `{each list as x}` | — |
| Control: assignment | `{set var = expr}` | `{set total = price * qty}` |
| Template inheritance | `{extends 'parent'}` `{block name}` `{/block}` | — |
| Macros | `{macro name(args)}` `{call name(args)}` | — |
| Whilisted functions | `range`, `abs`, `round`, `floor`, `ceil`, `min`, `max`, `count`, `length`, `str_pad`, `str_repeat`, `str_replace`, `strtolower`, `strtoupper`, `trim`, `ltrim`, `rtrim`, `substr`, `strlen`, `number_format`, `isset`, `empty`, `is_array`, `eq`, `json_encode`, `json_decode` | — |

## PHP syntax NOT supported (gaps)

### Previously critical gaps — NOW FIXED (2026-06-26)

| Gap | Status | Fix |
|---|---|---|
| `isset()` / `empty()` / `is_array()` not in FunctionRegistry | **FIXED** | Added to `FunctionRegistry::init()`. Also added `$var` prefix stripping in `resolveValue()` so `isset($var)` and `isset(var)` both work. Templates using `$var` syntax (e.g. `weather.disyl`) are now compatible with compiled mode. |
| `~` string concatenation operator | **FIXED** | Added to Parser `parseAddExpr()`, TemplateEngine `evaluateConcat()`/`splitByTilde()`, `isProcessableTemplateExpression()`, and TemplateCompiler `compileBinaryOp()`. Templates using `{a~b}` or `{var\|default:'pref'~suffix}` now work in both interpreted and compiled modes. |
| `===`/`!==` missing from `evaluateComparison()` | **FIXED** | Added strict comparison operators to the legacy comparison path (`evaluateComparison()`). Now `{if x === y}` and `{if x !== y}` work in all condition evaluation paths. |

### Fixed 2026-06-29 — Ternary with filters in condition + `{set}` logical operators

| Gap | Status | Fix |
|---|---|---|
| Ternary `{a ? b : c}` with `\|` (filter) in condition part | **FIXED** | `buildExpressionNode()` in Parser had a `$pipePos < $qPos` guard that rejected ternary when `\|` appeared before `?` in the expression. Removed the guard. Also added `findTernaryColon()` to both Parser and ExpressionEvaluator — skips `:` preceded by word characters (filter argument separators like `default:`) when searching for the ternary `:` separator. |
| `{set var = a or b}` evaluates to null | **FIXED** | `resolveSetValue()` in TemplateEngine fell through to `resolveValueWithFilters()` for logical expressions, which treated them as variable paths. Added `evaluateCondition()` fallback for `or`/`and`/`\|\|`/`&&` patterns before the quoted-string check. `{set x = a or b or c}` now correctly evaluates to boolean `true`/`false`. |

### Remaining gaps — actively used in templates but need external handlers

| Gap | Usage found in | DiSyL limitation |
|---|---|---|
| `eq()` custom helper | `modules/wms/templates/pages/movements.disyl:7` | **NOW REGISTERED** — `eq()` was never in `FunctionRegistry` (always returned null, so `selected` was never applied). Added to `FunctionRegistry::init()` as `eq(a, b) => a == b`. Fixes `<option selected>` in WMS filter dropdowns. |
| `json_encode()` / `json_decode()` function calls | `templates/modules/cms-akira-search-adapter/pages/home.disyl` | **NOW REGISTERED (2026-08-05)** — `json_encode`/`json_decode` were not in `FunctionRegistry`, so `{json_encode(data)}` compiled to empty and broke Alpine `x-for` attributes (logged `[strict] Undefined variable`). Added both to `FunctionRegistry::init()` (`json_encode` mirrors the `|json` filter flags: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`). Also fixed function-call argument resolution in `ExpressionEvaluator` to use `resolveValueWithFilters()` so args can be quoted strings, nested calls, or filter chains. Use `{json_encode(data)|raw}` when emitting into JS/attribute context. |

### Fixed 2026-08-23 — `{math equation="..."}` tag implemented

| Gap | Status | Fix |
|---|---|---|
| `{math equation="..."}` helper | **NOW IMPLEMENTED** | `{math}` was placed into templates but had no parser rule, no component registration, and no evaluator (TD-D1). Now a first-class self-closing tag: `equation` (required), `format="decimals"` (optional, `number_format`), `assign="var"` (optional, context store). Interpreted via `TemplateEngine::processMathTags()`, compiled via v4 `Parser::parseMathTag()` + `TemplateCompiler::compileMath()`. Same expression surface + pipe-binding caveat as `{(...) }`. Missing `equation` emits a visible `<!-- DiSyL math error: ... -->` marker + log in both modes. Tests: `disyl_engine_test.php` section 49 (interpreted + compiled parity). |

### Missing PHP operators

| Operator | Type | Status |
|---|---|---|
| `.` (PHP concat) | String concat in PHP | Conflicts with dot-path property access — can't be added directly. Use `~` instead (Twig-style, now supported) |
| `??` (nullsafe) | Nullsafe property access | **GAP — only `??` (coalesce) is supported** |
| `++` / `--` | Increment/decrement | **SUPPORTED** — postfix `{set x++}`/`{x++}` and C-style `{for}` init/increment (v4 Parser + TemplateCompiler) |
| `+=` / `-=` / `*=` / `/=` | Assignment operators | **SUPPORTED** — compound assignment `{set x += 1}` (v4 Parser + TemplateCompiler) |
| `<=>` | Spaceship | **GAP — not supported** |
| `&`, `^`, `<<`, `>>` | Bitwise operators | **SUPPORTED** — `compileBinaryOp` emits `&`, `^`, `<<`, `>>`. (`\|` remains filter syntax, so bitwise OR is not an operator.) |
| `@` | Error suppression | **GAP — not supported** |
| `instanceof` | Type checking | **GAP — not supported** |
| `...` | Spread operator | **GAP — planned in Grammar\Planned.php** |

### Missing PHP constructs

| Construct | Details |
|---|---|
| C-style `{for}` | **SUPPORTED** — `{for i = 0; i < 10; i++}` parsed by v4 `Parser` (`parseFor` C-style branch) and compiled by `compileCFor` (init/condition/increment + `MAX_LOOP_ITERATIONS` guard) |
| `{while}` | ~~No parser or evaluator code~~ — **FIXED**: parser + interpreted evaluator + compiled codegen are implemented |
| `{break}` / `{continue}` | ~~Not supported~~ — **FIXED (2026-07-05)** in both interpreted and compiled loops (`for`/`foreach`/`each`/`while`) |
| Array literals | ~~No `{['a', 'b']}` syntax~~ — **FIXED (2026-07-05)**: `{['a','b']}`, `{set items = ['a','b']}`, `{for item in ['a','b']}`, and `{['a','b'] \| filter}` all work in both interpreted and compiled modes |
| Array bracket access | No `{arr[0]}` — must use `{arr.0}` dot notation (which only works if the key is numeric-compatible) |
| String interpolation | No `"Hello {name}"` — DiSyL doesn't parse inside string literals |
| Heredoc/Nowdoc | Not applicable in template context |
| PHP `$var` syntax | Variables used without `$` — `{user.name}` not `{$user->name}` |
| `->` object access | Dot notation `obj.prop` only — no `obj->prop` |
| `match` (PHP 8.0) | DiSyL has its own `{match}{when}{/match}` which is different from PHP's `match` |
| `fn` arrow functions | No lambda/closure support in expressions |
| `yield` / generators | Not applicable |
| `include` / `require` | DiSyL has its own `{include}` — different semantics |
| Namespace resolution | No `\` in expressions |
| Type declarations | Only `{@var type $name}` supported, no full type system |
| Nullsafe `?->` | No support |
| Named arguments | No support |
| `enum` (PHP 8.1) | No support |
| `readonly` | No support |
| `#[Attribute]` | No support |

### Weaknesses in existing support

1. **Tight operator binding for `|`**: `{a + b \| filter}` parses `b \| filter` first instead of `a + b` as arithmetic. Fixed only if parenthesized: `{(a + b) \| filter}`.

2. ~~**Unknown filters silently pass through**~~ **FIXED (2026-07-11)**: `FilterRegistry::apply()` now logs a warning via `write_log()` when an unregistered filter name is used. Previously `escape:'js'` (note: correct filter is `esc_js`, not `escape`) would silently return the value unchanged, making JavaScript strings with special characters break. Added `escape` as a meta-filter in `FilterRegistry.php` that delegates to `esc_js`/`esc_html`/`esc_attr`/`esc_url` based on the first argument. Use `{var|escape:'js'}` instead of raw string interpolation in JS contexts. For passing entire PHP data structures to JS, use `{data|json|raw}` which produces proper JSON.

2. ~~**`isset()`/`empty()` only work in interpreted mode**~~ **FIXED (2026-06-26)**: `isset()`, `empty()`, and `is_array()` are now registered in `FunctionRegistry::init()`. Both `isset(var)` and `isset($var)` syntaxes work in compiled mode — the `$` prefix is stripped automatically. This caveat is no longer valid.

3. **Chained ternary without parentheses**: `{a ? b : c ? d : e}` is parsed left-to-right. PHP parses it as `{a ? b : (c ? d : e)}`. Use explicit parentheses: `{a ? b : (c ? d : e)}`.

4. ~~**`{for}` with C-style syntax is documented but unimplemented**~~ **RESOLVED**: The grammar doc mentions `{for i = 0; i < 10; i++}`; v4 `Parser` + `TemplateCompiler::compileCFor()` implement it (init/condition/increment), so C-style `{for}` no longer silently outputs raw text.

5. **Compiled mode does not track `{extends}` ancestors**: `TemplateCache::needsRecompile()` only checks the requested template's mtime. When a layout file is edited, child templates are not recompiled because their cache key only depends on the child's mtime. **FIXED 2026-06-29**: `needsRecompile()` now scans for `{extends}` directives and recursively checks all ancestor template mtimes.

6. **`{set}` does not support logical operators in compiled mode**: The compiled `TemplateCompiler` may not handle `or`/`and` in `{set}` expressions. The interpreted path is fixed (2026-06-29) — if you encounter this in compiled mode, the template falls back to interpreted.

## Remediation priority

1. ~~**URGENT**: Add `isset`, `empty`, `is_array` to `FunctionRegistry` — these are used in active templates and silently broken in compiled mode.~~ ✅ **DONE** (2026-06-26)
2. ~~**URGENT**: Implement `~` (string concatenation) operator in Parser, TemplateEngine arithmetic evaluator, and TemplateCompiler — actively used by project-audit-ledger.~~ ✅ **DONE** (2026-06-26)
3. ~~**HIGH**: Fix `evaluateComparison()` to handle `===`/`!==` (currently only `evaluateCondition()` does).~~ ✅ **DONE** (2026-06-26)
4. ~~**HIGH**: Fix ternary `{a ? b : c}` when filter `|` appears in condition — guard in `buildExpressionNode()` blocked detection. Added `findTernaryColon()` to handle filter-arg `:` vs ternary `:`.~~ ✅ **DONE** (2026-06-29)
5. ~~**HIGH**: Fix `{set var = a or b}` evaluating to null — `resolveSetValue()` lacked logical operator support.~~ ✅ **DONE** (2026-06-29)
6. **MEDIUM**: Fix pipe/filter binding precedence so `{a + b \| filter}` works without parentheses. (Decision 2026-08-23: keep the documented caveat — `{math equation="..."}` inherits it; parenthesized form is the contract.)
7. ~~**MEDIUM**: Implement C-style `{for}` loop syntax matching the documented grammar.~~ ✅ **DONE** (v4 Parser + `compileCFor`)
8. ~~**LOW**: Add increment/decrement (`++`/`--`) support.~~ ✅ **DONE** (postfix + compound assignment + C-style `{for}`)
9. ~~**LOW**: Implement `{while}` loop control structure.~~ ✅ **DONE**

## When to improve DiSyL vs. when to fix a template

| Scenario | Action |
|---|---|
| Template needs a PHP operator DiSyL lacks (`~`, `++`, `+=`, etc.) | **Improve DiSyL** — add parser rule + evaluator |
| Template uses a PHP function not in whitelist (`isset()`, `empty()`, etc.) | **Improve DiSyL** — add to `FunctionRegistry` |
| Template has Alpine.js `{}` conflicting with DiSyL `{}` | **Improve DiSyL** — enhance script/attribute extraction or `{literal}`/`{verbatim}` |
| Template uses wrong syntax (e.g. `{user->name}` instead of `{user.name}`) | **Fix template** — this is a template authoring error |
| Template bypasses DiSyL with raw PHP or ad-hoc HTML | **Fix template** — refactor to use DiSyL constructs |

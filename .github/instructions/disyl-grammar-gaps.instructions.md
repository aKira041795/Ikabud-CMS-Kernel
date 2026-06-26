---
description: "DiSyL grammar/PHP syntax support analysis — what PHP constructs are supported, what gaps exist, and the directive: if DiSyL doesn't support it, fix DiSyL, not the template."
applyTo: "**/*.php **/*.disyl"
---
# DiSyL Grammar — PHP Syntax Support & Gaps

## Core directive
**If DiSyL does not support a PHP syntax construct that a template needs, improve DiSyL at the engine/root level. Do NOT add template-level workarounds.** If DiSyL has gaps rendering from any programming language, update DiSyL, not the template.

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
| Control: if/elseif/else | `{if}` `{elseif}` `{else}` `{/if}` | — |
| Control: loops | `{for x in list}` `{foreach list as x}` `{each list as x}` | — |
| Control: assignment | `{set var = expr}` | `{set total = price * qty}` |
| Template inheritance | `{extends 'parent'}` `{block name}` `{/block}` | — |
| Macros | `{macro name(args)}` `{call name(args)}` | — |
| Whilisted functions | `range`, `abs`, `round`, `floor`, `ceil`, `min`, `max`, `count`, `length`, `str_pad`, `str_repeat`, `str_replace`, `strtolower`, `strtoupper`, `trim`, `ltrim`, `rtrim`, `substr`, `strlen`, `number_format`, `isset`, `empty`, `is_array`, `eq` | — |

## PHP syntax NOT supported (gaps)

### Previously critical gaps — NOW FIXED (2026-06-26)

| Gap | Status | Fix |
|---|---|---|
| `isset()` / `empty()` / `is_array()` not in FunctionRegistry | **FIXED** | Added to `FunctionRegistry::init()`. Also added `$var` prefix stripping in `resolveValue()` so `isset($var)` and `isset(var)` both work. Templates using `$var` syntax (e.g. `weather.disyl`) are now compatible with compiled mode. |
| `~` string concatenation operator | **FIXED** | Added to Parser `parseAddExpr()`, TemplateEngine `evaluateConcat()`/`splitByTilde()`, `isProcessableTemplateExpression()`, and TemplateCompiler `compileBinaryOp()`. Templates using `{a~b}` or `{var\|default:'pref'~suffix}` now work in both interpreted and compiled modes. |
| `===`/`!==` missing from `evaluateComparison()` | **FIXED** | Added strict comparison operators to the legacy comparison path (`evaluateComparison()`). Now `{if x === y}` and `{if x !== y}` work in all condition evaluation paths. |

### Remaining gaps — actively used in templates but need external handlers

| Gap | Usage found in | DiSyL limitation |
|---|---|---|
| `{math equation="..."}` helper | `templates/modules/cms/admin/weather.disyl` | **NEVER IMPLEMENTED** — `{math}` was put into templates but has no parser rule, no component registration, and no evaluator. Renders as empty string or broken output. Use DiSyL expressions directly: `{(current.temperature_c)|round}` instead. |
| `eq()` custom helper | `modules/wms/templates/pages/movements.disyl:7` | **NOW REGISTERED** — `eq()` was never in `FunctionRegistry` (always returned null, so `selected` was never applied). Added to `FunctionRegistry::init()` as `eq(a, b) => a == b`. Fixes `<option selected>` in WMS filter dropdowns. |

### Missing PHP operators

| Operator | Type | Status |
|---|---|---|
| `.` (PHP concat) | String concat in PHP | Conflicts with dot-path property access — can't be added directly. Use `~` instead (Twig-style, now supported) |
| `??` (nullsafe) | Nullsafe property access | **GAP — only `??` (coalesce) is supported** |
| `++` / `--` | Increment/decrement | **GAP — not supported** |
| `+=` / `-=` / `*=` / `/=` | Assignment operators | **GAP — `{set}` only supports `=`** |
| `<=>` | Spaceship | **GAP — not supported** |
| `&`, `\|`, `^`, `<<`, `>>` | Bitwise operators | **GAP — not supported** |
| `@` | Error suppression | **GAP — not supported** |
| `instanceof` | Type checking | **GAP — not supported** |
| `...` | Spread operator | **GAP — planned in Grammar\Planned.php** |

### Missing PHP constructs

| Construct | Details |
|---|---|
| C-style `{for}` | `{for i = 0; i < 10; i++}` mentioned in grammar doc but **no parser code exists** — only `{for x in list}` iteration works |
| `{while}` | No parser or evaluator code |
| Array literals | No `{['a', 'b']}` syntax — only `range()` produces arrays |
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

2. **No comparison with `===` in legacy `evaluateComparison()`**: The legacy interpreted path (`evaluateComparison()`) doesn't match `===`/`!==`. Only `evaluateCondition()` (the newer path) supports them. Some condition paths may silently use `==` instead of `===`.

3. **`isset()`/`empty()` only work in interpreted mode**: These PHP functions are not in `FunctionRegistry`. In compiled mode they return `null` silently. If compiled mode is enabled, templates using `isset()`/`empty()` will silently break.

4. **Chained ternary without parentheses**: `{a ? b : c ? d : e}` is parsed left-to-right. PHP parses it as `{a ? b : (c ? d : e)}`. Use explicit parentheses: `{a ? b : (c ? d : e)}`.

5. **No `~` operator but templates use it**: The `project-audit-ledger` module uses `{some_str~some_var}` for concatenation. This is silently broken — the parser treats `~` as unknown and falls back to raw text output.

6. **`{for}` with C-style syntax is documented but unimplemented**: The grammar doc mentions `{for i = 0; i < 10; i++}` but the parser only supports `{for x in list}` iteration. Any template using C-style `{for}` will silently output raw text.

## Remediation priority

1. ~~**URGENT**: Add `isset`, `empty`, `is_array` to `FunctionRegistry` — these are used in active templates and silently broken in compiled mode.~~ ✅ **DONE** (2026-06-26)
2. ~~**URGENT**: Implement `~` (string concatenation) operator in Parser, TemplateEngine arithmetic evaluator, and TemplateCompiler — actively used by project-audit-ledger.~~ ✅ **DONE** (2026-06-26)
3. ~~**HIGH**: Fix `evaluateComparison()` to handle `===`/`!==` (currently only `evaluateCondition()` does).~~ ✅ **DONE** (2026-06-26)
4. **HIGH**: Fix pipe/filter binding precedence so `{a + b \| filter}` works without parentheses.
5. **MEDIUM**: Implement C-style `{for}` loop syntax matching the documented grammar.
6. **LOW**: Add increment/decrement (`++`/`--`) support.
7. **LOW**: Implement `{while}` loop control structure.

## When to improve DiSyL vs. when to fix a template

| Scenario | Action |
|---|---|
| Template needs a PHP operator DiSyL lacks (`~`, `++`, `+=`, etc.) | **Improve DiSyL** — add parser rule + evaluator |
| Template uses a PHP function not in whitelist (`isset()`, `empty()`, etc.) | **Improve DiSyL** — add to `FunctionRegistry` |
| Template has Alpine.js `{}` conflicting with DiSyL `{}` | **Improve DiSyL** — enhance script/attribute extraction or `{literal}`/`{verbatim}` |
| Template uses wrong syntax (e.g. `{user->name}` instead of `{user.name}`) | **Fix template** — this is a template authoring error |
| Template bypasses DiSyL with raw PHP or ad-hoc HTML | **Fix template** — refactor to use DiSyL constructs |

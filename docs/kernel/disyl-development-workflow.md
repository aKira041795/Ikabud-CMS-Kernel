# DiSyL Development Workflow

> **Canonical development loop for editing `.disyl` templates.**
> Covers the edit → reload → gate → lint → engine-vs-template decision → verify cycle.
> Read this alongside [DiSyL Language Reference](disyl-language-reference.md) and the
> [DiSyL engine-first fix strategy](../disyl/engine-first-fix-strategy.md).

**Last updated:** 2026-08-05

---

## Overview

DiSyL is the template language for Ikabud. The engine lives in `kernel/DiSyL/` (v4 parser, `TemplateEngine`, expression evaluator, function registry) and templates live under `templates/` and `modules/*/templates/`.

This document defines the **canonical development loop** for template work:

- A **numbered end-to-end flow** (Input → Process → Output).
- A **decision gate** for when a template needs a construct DiSyL lacks: fix the **engine** (if reused) or apply a **template-level workaround** (one-off only).
- A Mermaid diagram of the whole loop.

**Entry point:** an existing `.disyl` template you are editing, or a new template you are authoring.
**Exit point:** a lint-clean, warning-free template whose behavior is verified in both `app.log` and `error.log`.

---

## The Development Loop

### 1. Edit the `.disyl` template

Open the template under `templates/` (or a module's `templates/` folder). Keep to supported DiSyL constructs — see
[DiSyL Language Reference](disyl-language-reference.md) and the supported-syntax table in
[`.github/instructions/disyl-grammar-gaps.instructions.md`](../../.github/instructions/disyl-grammar-gaps.instructions.md).

> **If the template needs a construct DiSyL does not support, do NOT reach for a template bandaid yet.** Continue the loop first — the decision gate at **Step 5** tells you when an engine fix is required.

### 2. Reload with `?disyl_nocache=1` (compiled-cache caveats)

With `DISYL_COMPILED_MODE=true` (the production default), reload the page with the query-string escape hatch:

```
https://yourdomain.com/your/route?disyl_nocache=1
```

Compiled-cache caveats you must know:

- **Ancestor `{extends}` layouts matter.** `TemplateCache::needsRecompile()` (in `kernel/DiSyL/Compiler/TemplateCache.php`) now scans `{extends}` chains and recursively checks ancestor template mtimes, but editing a layout does **not** always invalidate every child immediately.
- **`?disyl_nocache=1` forces a full recompile** of that specific template — use it on every test page load after a template change (dev only).
- **APCu persists across graceful PHP-FPM restarts.** If compiled output is stale after a layout edit, clear it properly:
  - Dev: `php ikabud cache:clear` (or `--disyl-only`)
  - Production: `php ikabud cache:clear` **plus a force PHP-FPM restart** (`systemctl restart phpX.Y-fpm`, not graceful) to evict APCu.
  - See [Production Deployment Guide](production-deployment-guide.md) for the production path.

### 3. Gate — check `app.log` for warnings / failures

After reloading, inspect the application log:

```
tail -n 100 storage/logs/app.log
```

Look for:

| Symptom in `app.log` | Meaning |
|---|---|
| `[strict]` warnings (e.g. `Undefined variable`) | Template references a key that was not provided to the render context |
| `render_failure` | Template rendering threw during the request |
| `loadViewConfigs` errors | An `{ikb_entity_view}` contract file failed to parse or validate (`getLastLoadErrors()` reports per-file details; registration now throws instead of failing silently) |
| `Unknown component` / closest-match suggestion | An `ikb_*` component name is misspelled |

**Fix template issues and re-enter at Step 2.** Move on only when the log is clean for your page load.

### 4. Lint — `php ikabud disyl:lint` (canonical)

Lint all templates (or a single path):

```bash
# Entire project (templates/ + module template dirs)
php ikabud disyl:lint

# A single directory or file
php ikabud disyl:lint templates/modules/cms
php ikabud disyl:lint --verbose   # show passing files too
```

> **Canonical CLI:** `php ikabud disyl:lint` is the canonical linter and is the one CI uses.
> There is also a legacy standalone script at the repo root — `php _lint_disyl.php` — which is
> **equivalent** in purpose (same v4 `Parser`), with options `--path <dir>`, `--ci`, and `--fix`.
> Prefer `php ikabud disyl:lint`; treat `_lint_disyl.php` as a compatibility alias.

Exit code `0` = all templates valid; `1` = errors found.

### 5. Decision gate — engine fix vs. template workaround

If your template needs a construct DiSyL lacks (a PHP operator, a whitelisted function, a control structure, a filter), ask:

> **"Will I need this in more than one template?"**

- **Yes → fix the engine.** The fix belongs in `kernel/DiSyL/`, not in the template:
  - `kernel/DiSyL/v4/Parser.php` — new control structures, operators, syntax rules (`parseBlock()`, `buildExpressionNode()`, etc.)
  - `kernel/DiSyL/TemplateEngine.php` — filter/modifier dispatch, `{set}` resolution, render path
  - `kernel/DiSyL/v4/FunctionRegistry.php` — whitelisted functions (`isset()`, `empty()`, `json_encode()`, …)
  - `kernel/DiSyL/ExpressionEvaluator.php` — expression evaluation, ternary/pipe handling
  - Then **add engine-level tests** in `tests/disyl_engine_test.php` (extend the file; run it and the broader DiSyL suite after changes).
  - Directive: **if DiSyL doesn't support it, fix DiSyL, not the template.** See [`.github/instructions/disyl-grammar-gaps.instructions.md`](../../.github/instructions/disyl-grammar-gaps.instructions.md) and [engine-first fix strategy](../disyl/engine-first-fix-strategy.md) for the exact files and patterns.
- **No → template-level workaround.** The gap affects only this one template and an engine change would be disproportionately complex or risky mid-session. Apply a scoped workaround in the template, and note the limitation (see the "when a bandaid IS acceptable" section in the strategy doc).

### 6. Re-lint + re-test, check both logs

- Re-run `php ikabud disyl:lint`.
- Re-run the relevant engine/template tests, e.g. `php tests/disyl_engine_test.php` (and the suite via `composer test` from `ikabud-kernel` if your change touched the engine).
- Reload the page again with `?disyl_nocache=1`.
- **Check BOTH logs** — `storage/logs/app.log` **and** `storage/logs/error.log` — for new strict warnings, render failures, or PHP errors before declaring done.

---

## Decision Gate — Engine vs. Template

| Scenario | Action |
|---|---|
| Template needs a PHP operator DiSyL lacks (`~`, `+=`, `??`, …) | **Fix the engine** — parser rule + evaluator + tests |
| Template uses a PHP function not in the whitelist (`isset()`, `empty()`, `json_encode()`, …) | **Fix the engine** — add to `FunctionRegistry` |
| Template has Alpine.js `{}` conflicting with DiSyL `{}` | **Fix the engine** — script/attribute extraction or `{literal}`/`{verbatim}` |
| Template uses wrong syntax (`{user->name}` instead of `{user.name}`) | **Fix the template** — authoring error |
| Gap affects exactly one template, engine change is disproportionate/risky | **Template workaround** (document it) |

---

## Diagram

```mermaid
flowchart TD
    A[1. Edit the .disyl template] --> B["2. Reload with ?disyl_nocache=1<br/>compiled-cache caveats: {extends} ancestry, APCu"]
    B --> C{3. Gate: check app.log for<br/>strict warnings / render_failure /<br/>loadViewConfigs errors}
    C -- errors --> D[Fix template issues]
    D --> B
    C -- clean --> E[4. Lint: php ikabud disyl:lint<br/>(canonical; _lint_disyl.php equivalent)]
    E --> F{5. Decision gate: template needs<br/>a construct DiSyL lacks?}
    F -- No --> G[6. Re-lint + re-test<br/>check BOTH logs]
    F -- Yes --> H{Will I need this in<br/>more than one template?}
    H -- Yes --> I[Fix the ENGINE in kernel/DiSyL/<br/>v4/Parser.php, TemplateEngine.php,<br/>FunctionRegistry, ExpressionEvaluator]
    I --> J[Add engine-level tests<br/>tests/disyl_engine_test.php]
    J --> G
    H -- No --> K[Template-level workaround<br/>one-off only, document it]
    K --> G
    G --> L[Done — feature verified]
```

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| Template changes don't appear after edit | Compiled cache not invalidated | Reload with `?disyl_nocache=1`; then `php ikabud cache:clear`; in production force-restart PHP-FPM to evict APCu |
| `render_failure` in `app.log` | Template throws at render time | Check the stack in `error.log`, fix, reload |
| `[strict] Undefined variable` | Context key not passed to template | Add the key in the handler, or use `{var \| default:...}` |
| Linter exit code 1 | Syntax / structure error in a `.disyl` file | Fix the reported file/line, re-lint |
| Contract file silently not registered | Old engine behavior | Current engine throws + logs via `getLastLoadErrors()` — check `app.log` for `loadViewConfigs` errors |
| `{a + b \| filter}` gives wrong result | Tight pipe binding — filter applies to `b` only | Parenthesize: `{(a + b) \| filter}` |

---

## References

- [DiSyL Language Reference](disyl-language-reference.md)
- [DiSyL engine-first fix strategy](../disyl/engine-first-fix-strategy.md)
- [`.github/instructions/disyl-grammar-gaps.instructions.md`](../../.github/instructions/disyl-grammar-gaps.instructions.md)
- [Kernel OS / DiSyL roadmap status](kernel-os-disyl-roadmap-status.md)
- [Production Deployment Guide — compiled-cache & APCu section](production-deployment-guide.md)

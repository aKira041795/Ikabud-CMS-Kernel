# Verification of Sol's DiSyL fix — REJECTED, and the brief was at fault too

Date: 2026-10-10. Sol returned `STATUS: PASS` reporting the sweep going `fallback 7 -> 0`. Independently
verified and **not accepted**.

## What Sol's fix does correctly — it is a real improvement

The diff is small and well aimed: 19 insertions in 2 files (`kernel/DiSyL/v4/Parser.php`,
`kernel/DiSyL/Compiler/TemplateCompiler.php`) plus a new test. Two guards in
`Parser::isProcessableTemplateExpression()`:

- inside a raw output context (script/style), only a bare or dotted identifier with optional filters is
  still an expression, so `{flex-direction:column}` stops being arithmetic;
- a brace block starting with a JS statement keyword (`let`, `const`, `return`, ...) is host-language text.

It also bumped `COMPILER_VERSION` 16 -> 17 so stale artifacts are not reused. All of that is right.

**And it fixes real corruption that predates it.** The differential proves the *unfixed* engine was
silently emitting broken JavaScript:

| template | unfixed output | fixed output |
|---|---|---|
| `modules/cms/admin/menus.disyl` | `const node=label:item.label,...` — the `{` was eaten | `const node={label:item.label,...}` |
| `modules/dc-cafe/settings/index.disyl` | `this.api('...', 'PUT', )` — payload argument gone | `this.api('...', 'PUT', {name: this.editBaseName[id]})` |

That second template carries the comment `// ternary (0) is mangled by DiSyL` — a developer documenting
this corruption and working around it.

## Why it is rejected: it converts a slow-but-correct page into a fast-but-broken one

`modules/harpp/runners.disyl` declares three blocks: `head`, `content`, `scripts`. The compiled artifact
Sol's fix now selects (`Template_runners_v17_2e0a2c21.php`) contains **only** `setBlock('head')` and
returns:

```php
{ // setBlock('head')
    $output .= '<style>.runner-card';
    $output .= '{';                       // <- the CSS is now preserved correctly
    $output .= 'display:flex;flex-direction:column;gap:.5rem}...';
    ...
    $output .= 'color:var(--muted);text-align:center;padding:2rem 1rem}@media(min-width:700px)';
    $ctx->setBlockIfAbsent('head', $__blockOutput);
}
return $output;
```

The `content` and `scripts` blocks are absent, and the emitted CSS stops dead at
`@media(min-width:700px)` — the nested `{` that follows it appears to swallow the remainder of the
document, including `{/block}` and everything after.

Rendered result, before vs after:

| | before (interpreted fallback) | after (compiled) |
|---|---|---|
| style body | `...0px){#runner-fleet{grid-template-columns:repeat(2,1fr)!important}}</style>` | `...0px)` |
| page body | `<main class="shell"><section class="panel"><h1>Runner fleet</h1>...</section></main>` | `<main class="shell"></main>` |
| script tag | `<script src="/assets/modules/harpp/runners.js" defer></script>` | *(gone)* |

So the arithmetic error was **masking a second, latent compiled-path defect**: the fallback was
accidentally producing correct output. Removing the trigger exposes the truncation.

## The brief's fault — my error

I made `probe-fallback-sweep.php` reporting `fallback 0` the primary acceptance criterion. That measures
**which pipeline ran**, not **whether the output is right**. Sol satisfied the criterion exactly as
written, and the criterion could not detect the regression. The missing criterion was output equality
against the interpreted path — a differential over the real corpus.

Two probes now close that gap and should be part of the acceptance set permanently:

- `probe-output-diff.php` — renders all 555 templates and prints `template<tab>sha256(output)`. Run on
  both trees and diff. This is what caught the regression.
- `probe-render-one.php` — renders one template to stdout for a readable before/after HTML diff.

**Rule for the next dispatch:** the sweep proves the fix *applies*; the differential proves the fix is
*safe*. Neither alone is sufficient, and only the differential catches a change that makes a page
faster and emptier.

## Required before acceptance

1. `{block content}` and `{block scripts}` must appear in the compiled class for `runners.disyl`.
2. No template's rendered output may differ from the interpreted-pipeline output it produced before,
   except where the difference is the intended repair of corruption (the eaten braces above) — and each
   such difference must be listed and justified individually.
3. The `{#runner-fleet{...}}` CSS must survive compilation.
4. The identified trigger for the truncation must be stated from measurement, not inference.

---

# ROUND 2 — ACCEPTED

Sol's second pass found the **actual** root cause, which was more precise than my own reading. I had said
"the nested `{` after `@media(min-width:700px)` appears to swallow the rest of the document". The real
mechanism:

> `{#runner-fleet{...}}` inside `<style>` was parsed as a DiSyL **`{# ... #}` hash comment**. With no `#}`
> terminator, `parseHashComment()` consumed the remainder of the template — including `{/block}`, and the
> `content` and `scripts` blocks.

Minimal reproduction Sol measured: `<style>@media(x){#x{a:b}}</style>TAIL` — 37 source bytes producing 16
output bytes before, 37 after.

## The change

`{#` is now a comment **only when its `#}` terminator falls inside the same raw body**
(`hasRawHashCommentTerminator()`, using the existing `rawContextRanges`). Null-coalescing was moved ahead
of the raw-context guard and tightened to `^{identifier} ?? …$` so the documented `{sales_count ?? 0}`
interpolation keeps working — Sol cross-referenced `processScriptVariables()` parity explicitly. The
round-1 guards are retained. Cache version 17 -> 18.

## Independently verified — every claim reproduced, not accepted

| check | result |
|---|---|
| **Output differential**, 555 templates, re-run by me against the committed baseline | **exactly 3 differ**, and the hashes match Sol's reported values exactly |
| those 3 diffs inspected by hand | all are the intended repairs: `menus.disyl` `const node={label:...}` brace restored; `users.disyl` `{userId: [{store_id, store_name, role}]}` restored inside a JS comment; `dc-cafe/settings/index.disyl` `{name: this.editBaseName[id]}` payload restored |
| `runners`/`status`/`session-end` | **no longer differ** — compiled output now equals the previously-correct interpreted output |
| `Template_runners_v18_*.php` | all three `setBlockIfAbsent` present: `head` (:43), `content` (:52), `scripts` (:61) |
| fallback sweep, re-run by me | `compiled 479 \| fallback 0 \| interpreted 72 \| THREW 4` |
| new regression test | **9 passed / 3 failed without the fix**, 12 passed / 0 failed with it — it discriminates |
| `tools/disyl-conformance-check.php` | `lane_green=YES`, `promoted=41 partial=0`, `disagreements: none` |
| 5 known pre-existing failures | exit=1 each, unchanged |
| templates modified | **0** |
| logs | `error.log` empty; `app.log` fallbacks 0 |

## What this cost, and the lesson worth keeping

The fix required two rounds, and **the first round's brief was the reason**. I made
`probe-fallback-sweep.php` reporting `fallback 0` the primary acceptance criterion. That measures *which
pipeline ran*, not *whether the output is right* — so it passed while `runners.disyl` silently lost its
content section and its script tag. Sol satisfied the criterion exactly as written.

**The rule, now recorded permanently: the sweep proves the fix *applies*; the differential proves it is
*safe*. Neither alone is sufficient.** Every acceptance set for a compiler change must include
`probe-output-diff.php` against a committed baseline, and every changed template must be listed and
justified individually. `output-before.txt` is that baseline and must not be regenerated after a fix.

A second, quieter value came out of the same instrument: the unfixed engine was **already corrupting
JavaScript** in three templates — eating object-literal braces and an API payload — one of which carried
the comment `// ternary (0) is mangled by DiSyL`, a developer documenting the damage and coding around it.
The fallback had been masking all of it. Fixing the trigger exposed both the corruption and a latent
truncation, and neither would have been visible from the pipeline tally alone.

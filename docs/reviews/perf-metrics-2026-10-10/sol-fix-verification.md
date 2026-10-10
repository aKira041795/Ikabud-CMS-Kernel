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

# ROUND 2 — your fix is good but incomplete; a second defect it exposes

Your change is **correctly aimed and keep it**. Independently verified: it repairs real pre-existing
corruption (the unfixed engine emitted `const node=label:item.label` with the opening brace eaten, and
`this.api('...', 'PUT', )` with the payload argument gone).

It was **rejected as incomplete**, because it converts a slow-but-correct page into a fast-but-broken one.
Full evidence: `docs/reviews/perf-metrics-2026-10-10/sol-fix-verification.md`.

## The defect your fix exposes

`templates/modules/harpp/runners.disyl` declares **three** blocks: `head`, `content`, `scripts`.
Your fix routes it onto the compiled path. The compiled artifact
(`Template_runners_v17_2e0a2c21.php`) contains **only** `setBlock('head')` and then `return $output;`.

So after your change, rendering that template gives:

| | before your fix (interpreted fallback) | after your fix (compiled) |
|---|---|---|
| style body | `...0px){#runner-fleet{grid-template-columns:repeat(2,1fr)!important}}</style>` | `...0px)` |
| page body | `<main class="shell"><section class="panel"><h1>Runner fleet</h1>...</section></main>` | `<main class="shell"></main>` |
| script tag | `<script src="/assets/modules/harpp/runners.js" defer></script>` | *(gone)* |

`content` and `scripts` never reach the parent layout, and the emitted CSS stops dead at
`@media(min-width:700px)`. The nested `{` immediately after it appears to swallow the rest of the
document — including `{/block}` and every later block. **Establish this trigger by measurement; do not
take my reading of it as fact.**

The arithmetic error was **masking** this: the fallback was accidentally rendering the page correctly.
That is why the page gets faster and emptier rather than faster and identical.

## The acceptance criterion I should have given you first time

`probe-fallback-sweep.php` reporting `fallback 0` measures **which pipeline ran**. It says nothing about
whether the output is right, so it passed while the page emptied. The missing criterion is **output
equality against the interpreted path**.

Two probes now exist — use both, and report their output verbatim:

```
# output equality across all 555 templates, before vs after the change
php docs/reviews/perf-metrics-2026-10-10/probe-output-diff.php > /tmp/out-after.txt
diff docs/reviews/perf-metrics-2026-10-10/output-before.txt /tmp/out-after.txt

# readable HTML diff for one template
php docs/reviews/perf-metrics-2026-10-10/probe-render-one.php modules/harpp/runners.disyl
```

`output-before.txt` is the committed baseline (555 templates, rendered on the tree with your changes
stashed). It is the reference arm; do not regenerate it.

## What must hold before this is accepted

1. **Every one of the 555 templates renders byte-identical output** to `output-before.txt`, EXCEPT where
   the difference is the intended repair of corruption (`menus.disyl`, `dc-cafe/settings/index.disyl` and
   any others). **List every changed template individually and justify each one.** A diff you have not
   explained is a rejection.
2. `{block content}` and `{block scripts}` must be present in the compiled class for `runners.disyl`, and
   the `{#runner-fleet{...}}` CSS must survive.
3. `probe-fallback-sweep.php` must still report `fallback 0` with `THREW` not increasing.
4. The identified trigger for the truncation must come from measurement, with the minimal reproduction
   stated.
5. The 5 known pre-existing test failures must be unchanged, and `tools/disyl-conformance-check.php`
   must stay green.

## Same constraints as before

- Engine-level fix only. **No `.disyl` template edits.**
- Do NOT commit and do NOT push; leave changes in the working tree.
- Your existing changes are still in the working tree uncommitted — build on them, do not revert them.
- Check BOTH `storage/logs/app.log` and `storage/logs/error.log`.

A `BLOCKED` with a precise reason beats a plausible fix. Report in the same
STATUS/ROOT CAUSE/TRIGGER/FILES CHANGED/FIX/SWEEP/TESTS/RISKS/UNRESOLVED format, and include the raw
`diff` output for the output-equality check — that diff is the evidence this round turns on.

# DiSyL Editor Tooling — Plan

**Date:** 2026-11-11
**Goal:** a mid-level "vibe coder" — and an AI agent — can author DiSyL templates and entity views
quickly. Two needs, in this order:

1. **Discovery** — know what is allowed at the point of use, without a reference tab.
2. **A fast, machine-readable negative signal** — so a human or an LLM can tell whether what was
   written will work, and self-correct, instead of discovering it in a browser.

## Evidence base (do not re-derive)

- Consultation: `.ai/consult/compare-disyl-lsp-roadmap.md` — ChatGPT and Claude on one grounded
  brief (30 citations). Both independently concluded: **JSON contract first, stay on native VS Code
  providers, do not build an LSP yet**, and make the PHP analyzer canonical while `validator.ts`
  becomes a syntax-only fallback. Two independent rule sets drifting is the named failure mode.
- Measured: `php ikabud disyl:lint` issues carry `['type' => 'error', 'line' => $lineNum]` — **line
  only, no columns, no stable codes, no `--json`**. Messages render as `L7 error: ...`.
- Measured: the linter silently accepts unimplemented constructs. A probe containing `{items[0]}`,
  `{a <=> b}`, `{user?->name}`, `{a . b}`, `{@expr}`, `{instanceof}` and out-of-loop `{break}`
  produced **only** "1 unclosed {if}".
- Measured: 62 `{ikb_entity_list}` tags exist and `source` / `view` names have **no completion**.
  This is the highest-volume authoring task and the names are invisible.
- The extension currently shells out and **regex-parses human output** (`parseLintOutput`,
  cyclomatic complexity 28). Changing a CLI message breaks editor diagnostics silently.

## Slices

### A. `disyl:lint --json` — line-level first
Emit a versioned envelope with stable rule codes and a range. Columns are real work (the analyzer
does not track them today), so ship **line-level** first to kill the regex parser in one increment,
then add columns. Keep the human output byte-compatible: `--json` is additive.

Contract shape (from the consultation):

```json
{
  "schemaVersion": 1,
  "tool": "disyl:lint",
  "status": "invalid",
  "diagnostics": [
    {
      "file": "templates/x.disyl",
      "range": { "start": { "line": 12, "character": 0 }, "end": { "line": 12, "character": 0 } },
      "severity": "error",
      "code": "DISYL_UNCLOSED_BLOCK",
      "message": "Unclosed {if} block (missing {/if})"
    }
  ]
}
```

**Shipped:** `php ikabud disyl:lint [path] --json` now emits the versioned envelope above for
single-file and lint-all runs. All lint checks have stable `DISYL_*` codes, and warnings are included
alongside errors. JSON ranges use 0-based lines; both characters are `0` because the current analyzer
is line-only. Human output remains 1-based and unchanged, and exit status remains error-driven.

### B. `disyl:describe --json` — the introspection contract
One machine-readable surface for grammar, filters, governed components, block keywords and the
`config/disyl-feature-inventory.json` classifications. Highest value per the consultation because it
serves **an AI author and the editor from the same source**, ending the hardcoded lists in
`extension.ts` (`DISYL_FILTERS`, `GOVERNED_COMPONENTS`, `BLOCK_KEYWORDS`).

**Shipped:** `php ikabud disyl:describe [--json]` now reads filter names from
`FilterRegistry::names()`, governed `ikb_*` component names from
`ComponentRenderer::BUILT_IN_GOVERNED_COMPONENTS`, and construct classifications directly from
`config/disyl-feature-inventory.json`. The renderer catalogue is the authority because it tracks
components actually handled by `renderComponent()`; `ComponentRegistry` is only partial Visual
Builder metadata. The JSON contract is versioned and suppresses all human output. Block keywords are deliberately omitted: parser dispatch
is spread across conditional branches and there is no single authoritative enumerable PHP source;
adding another hardcoded list would recreate the drift this contract is intended to remove.

### C. Entity source / view completion
`disyl:list-sources --json` plus view metadata, then completion after `source="` and `view="`, with
`view` filtered by the chosen `source`. This closes the highest-volume authoring gap.

**Shipped:** `php ikabud disyl:list-sources --json` emits a versioned source catalogue whose views
come from `EntityViewResolver::registeredViewContracts()`. The extension consumes that contract for
`source` and `view` values on both `{ikb_entity_list}` and `{ikb_entity_detail}`; when a source is
present on the current tag, view suggestions are restricted to that source. Human CLI output remains
unchanged, and catalogue failures leave these completions empty rather than introducing fallback
names.

### D. Extension consumes the contracts
**Shipped (contract consumption):** the extension parses the versioned `disyl:lint --json` contract
for PHP diagnostics and loads filter/component completion from `disyl:describe --json` once at
activation. CLI catalogue failure leaves completion empty with a warning rather than using stale
hardcoded lists. `validator.ts` remains the instant syntax-only fallback.

Source/view completion is supplied by slice C above.

### E. Semantic reference validation
**Shipped:** `php ikabud disyl:lint` now reports warning-only diagnostics for unknown filters,
governed components, and entity sources. Filter references come from `FilterNode` instances in the
v4 parser AST, so logical OR (`||`) is never interpreted as a filter pipe. Component discovery uses
the renderer's `/\{(ikb_\w+|island|state)[\s}]/` tag shape, its governed catalogue, and its existing
closest-match algorithm. Entity source validation shares the `disyl:list-sources --json` catalogue;
for the `source.view` shorthand, only the segment before the first dot is validated as the source.
The stable codes are `DISYL_UNKNOWN_FILTER`, `DISYL_UNKNOWN_COMPONENT`, and
`DISYL_UNKNOWN_SOURCE`.

### F. Reconcile filter runtime and tooling surfaces
**Shipped:** the interpreted engine and v4 `FilterRegistry` now both register `ucfirst`, `substr`,
`strpos`, `replace`, `date_format`, `time_format`, and `escape_js`. `ucfirst` and `substr` operate on
UTF-8 characters rather than bytes; `strpos` preserves PHP's `false` miss result; `date_format` and
`escape_js` are aliases of `date` and `esc_js`; and argument-less `time_format` defaults to `g:i A`.
The pre-existing drift was reconciled without removing working filters: `json_attr` and `pluralize`
were added to the registry used by describe/lint/completion, while the registry's `escape`
meta-filter was added to the engine and delegates to the existing HTML, attribute, JavaScript, or
URL escaping semantics selected by its first argument. Both surfaces also retain the corpus's legacy
`replace('_', ' ')` call-style spelling as a compatibility alias, while new templates use
`replace:'_':' '`. The `filter` collection filter is also registered on both surfaces and supports
only a simple predicate: `<variable>=><variable>.<dotted_path> <operator> <literal>`, where the
operator is `==`, `!=`, `<`, `>`, `<=`, or `>=` and the literal is a quoted string, number,
`true`, `false`, or `null`. Missing paths do not match, results are reindexed, and unsupported
predicates leave the collection unchanged rather than becoming a general expression evaluator.

## Non-goals (deliberately excluded)

- **No LSP migration.** Revisit only if a second editor needs it, measured CLI latency blocks
  on-type feedback, or cross-file indexing becomes necessary.
- **No semantic tokens, signature help, inlay hints.** The tmLanguage grammar already covers the
  baseline; both models ranked these last.
- **No global variable-existence checking.** Render context is runtime data; the consultation's
  guidance is to express uncertainty (known / unresolved / definitely-invalid), not to guess.
- **No new grammar.** `<=>` and governed `instanceof` measured **zero** DiSyL usage; `instanceof`
  occurrences are all JavaScript. Build them if a template ever needs them, with a parity test.

## Acceptance style

Each slice must fail on the tree before the work starts. Assert **outcomes**, and cover every
**position** a construct or result can occupy. Example:

```bash
# A
php ikabud disyl:lint <file> --json | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit(isset($j["schemaVersion"],$j["diagnostics"][0]["code"],$j["diagnostics"][0]["range"]["start"]["line"])?0:1);'
# A also: human output unchanged
php ikabud disyl:lint <file> | grep -q "error:"
# C: same source/view pair must round-trip
php ikabud disyl:list-sources --json | php -r '...'
```

Rationale for the strictness: a delegated lane once reported `completion: PASS` for nullsafe `?->`
while it was truthy-wrong in **condition** position, because the criterion only covered output
position. See `.github/instructions/ai-development-execution-handoff.instructions.md` and the
`acceptance-position-coverage` memory note.

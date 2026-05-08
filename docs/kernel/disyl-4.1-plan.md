# DiSyL 4.1 — Pattern Matching + i18n

**Target kernel:** 4.1.0
**Risk:** low (additive grammar, no runtime model change)
**Owner:** kernel team

## Goals

1. **Pattern matching** in templates: `{[ match expr ]} {[ when ... ]} … {[ default ]} … {[ endmatch ]}`
2. **First-class i18n**: `{[ trans key ]}default text{[ endtrans ]}` with extractor + ICU plural support

Both replace common today-patterns:
- nested `{[ if ]}`/`{[ elseif ]}` chains for value branching
- ad-hoc `{{ t('key') }}` calls with no extraction guarantee

## Non-goals

- No exhaustiveness over open value sets (only over enums or literal lists)
- No runtime locale negotiation policy beyond a single `request.locale` lookup
- No translation-management UI (catalog format only)

## 1. Pattern matching

> Syntax note: DiSyL uses `{tag args}...{/tag}` delimiters; the v11 design docs and earlier roadmap used `{[ tag ]}` placeholder syntax. Production grammar is the curly form below.

### Syntax

```disyl
{match order.status}
  {when 'paid', 'shipped'}
    <span class="ok">Settled</span>
  {when 'refunded' guard refund.partial}
    <span class="warn">Partial refund</span>
  {when 'refunded'}
    <span class="warn">Refunded</span>
  {default}
    <span>{order.status}</span>
{/match}
```

Rules:
- `match` takes one expression (any DiSyL expression).
- Each `when` accepts a comma-separated list of literal patterns *or* a single identifier expression.
- `guard EXPR` after a `when` adds a boolean predicate; the arm only fires when both pattern and guard match.
- `default` is optional. If absent and no arm matches, the block renders empty (and emits a `disyl.match.unmatched` log entry in strict mode).
- Patterns supported in 4.1: string literal, integer literal, boolean literal, `null`, `_` (wildcard).
- Object/array destructuring is **out** for 4.1 (queued for 4.1.1).

### AST

New `MatchNode` and `WhenArmNode` under [kernel/DiSyL/v4/AST/](../../kernel/DiSyL/v4/AST/). `MatchNode` holds the subject expression + ordered arms + optional default body. `WhenArmNode` holds pattern list, optional guard expression, body.

### Parser changes

- `Parser::parseDisylTag()` recognizes `match`, `when`, `default`, `endmatch`.
- Reuse existing tag-content reader; arms are parsed left-to-right under a new `parseMatch()` recursive descent.
- Guard expressions reuse `buildExpressionNode()`.

### Engine changes

The engine uses a single-pass string processor (`processControlStructuresSinglePass`). Add:
- A new `findMatchingClose(..., 'match')` arm.
- A new `evaluateMatchBody(...)` that:
  1. Resolves the subject via `resolveValue()`.
  2. Walks arms in source order; returns first body whose pattern + guard pass.
  3. Falls back to default if present.
  4. In strict mode, logs `disyl.match.unmatched` with template name + line.

Pattern matching against literals reuses `evaluateCondition` semantics (loose equality for strings/numbers, strict for `null`/`bool`).

### Errors

- Multiple `default` arms → parse error `DISYL_MATCH_DUP_DEFAULT`.
- `when` outside `match` → parse error `DISYL_MATCH_ORPHAN_WHEN`.
- Unterminated `match` → parse error `DISYL_MATCH_UNCLOSED`.

### Tests

`tests/disyl_v41_match_test.php`:
1. Single matching `when` with one pattern
2. Multi-pattern `when`
3. `guard` blocks an otherwise-matching arm
4. `default` falls through
5. No-match + no-default in strict mode logs once
6. Nested `match` inside `for`
7. `match` inside `match`
8. `null` / `false` / `0` patterns disambiguated

## 2. i18n

### Syntax

```disyl
{trans 'cart.empty'}Your cart is empty.{/trans}

{trans 'cart.items' plural=cart.count}
  {when one}1 item
  {when other}{cart.count} items
{/trans}

{trans 'product.title' context='shop_grid'}{product.name}{/trans}
```

Rules:
- `trans` takes a positional string key.
- Optional `plural=EXPR` switches to ICU plural form. Inner `{[ when one ]}` / `{[ when other ]}` / `{[ when zero|two|few|many ]}` arms are catalog source.
- Optional `context='...'` disambiguates same key across surfaces.
- The body between `{[ trans ]}` and `{[ endtrans ]}` is the **default English source**; never translated automatically. Catalog keys are extracted from key+context+plural-arms.
- `{{ var }}` interpolation inside translated strings is preserved through the runtime by a `%(var)s`-style placeholder protocol so translators see stable names.

### Catalog format

```jsonc
// storage/i18n/{locale}.json
{
  "cart.empty": { "value": "Tu carrito está vacío." },
  "cart.items": {
    "plural": {
      "one":   "1 artículo",
      "other": "%(cart.count)s artículos"
    }
  },
  "product.title:shop_grid": { "value": "%(product.name)s" }
}
```

- Catalog files live in `storage/i18n/`, one per locale.
- Per-tenant overrides in `storage/i18n/{tenant_id}/{locale}.json`, merged on top.
- Loaded once per request, cached in APCu under key `disyl.i18n.{tenant}.{locale}.{mtime}`.

### Extractor

`scripts/disyl-i18n-extract.php`:
- Walks `templates/` + every module's `views/`.
- Parses each template, collects `MatchNode`-like `TransNode`s.
- Emits `storage/i18n/_source/messages.pot` (gettext-compatible) and `storage/i18n/en.json` (round-trip baseline).
- Refuses to extract dynamic keys; prints them as warnings.

### Runtime

New `kernel/DiSyL/i18n/Catalog.php`:
- `Catalog::translate(string $key, ?string $context, array $vars, ?string $pluralArm): string`
- Plural arm selected via `Catalog::pluralCategory(string $locale, int|float $n)` — implements CLDR plural rules (subset for the 25 most common locales in 4.1; full rules in 4.1.1).

Engine wires `TransNode` → `Catalog::translate()`, falling back to the inline body text when key is missing.

### Errors

- Dynamic key (`{[ trans some.var ]}`) → parse error `DISYL_TRANS_DYNAMIC_KEY` unless `--allow-dynamic` extractor flag set.
- `plural=` with no `when` arms → parse error `DISYL_TRANS_PLURAL_NO_ARMS`.
- Unknown plural arm name → parse error `DISYL_TRANS_BAD_ARM`.

### Tests

`tests/disyl_v41_i18n_test.php`:
1. Static key falls back to body when catalog missing
2. Static key resolves from catalog
3. Variable interpolation roundtrips correctly
4. Plural: `one` vs `other` selection
5. Context disambiguation (same key, different context, different translation)
6. Tenant override beats global catalog
7. Extractor produces stable keys and reports dynamic-key warnings
8. Locale switch (en → es) flips output for same template

## Migration / docs

- Update [docs/page-builder/page-builder-technical-spec.md](../../docs/page-builder/page-builder-technical-spec.md) with new tag listing.
- Update [docs/kernel/ikabud-roadmap.md](../../docs/kernel/ikabud-roadmap.md) Phase 5 → mark 4.1 shipped.
- Update [docs/releases/](../../docs/releases/) with `release-notes-YYYY-MM-DD-kernel-4.1.md`.

## Acceptance

- All 8 + 8 = 16 new tests pass.
- `php -l` clean across changed files.
- `storage/logs/{app,error}.log` clean after suite.
- No regression in existing `disyl_v4_test.php` (36/36 still PASS).
- Guard manifests still 0/0.

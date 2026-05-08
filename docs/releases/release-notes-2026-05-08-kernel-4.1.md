# Kernel 4.1.0 — Pattern matching + i18n

**Release date:** 2026-05-08
**Codename:** atlas (4.1 minor)
**Predecessor:** 4.0.0 atlas ([release notes](release-notes-2026-05-08-kernel-4.0-atlas.md))
**Type:** Minor (additive). No breaking changes.

## TL;DR

Kernel 4.1 ships the first two batches from the
[DiSyL 4.x roadmap](../kernel/disyl-4.1-plan.md):

1. **Pattern matching** — `{match expr}{when ...}…{default}…{/match}` with
   `guard` predicates and wildcard `_`.
2. **First-class i18n** — `{trans 'key' [plural=…] [context='…']}…{/trans}`
   backed by a JSON catalog with per-tenant overrides and CLDR-subset plural
   rules.

Six follow-on minors are planned and have published design docs:
[4.2 type system](../kernel/disyl-4.2-plan.md),
[4.3 cache + experiments](../kernel/disyl-4.3-plan.md),
[4.4 sandbox](../kernel/disyl-4.4-plan.md),
[4.5 async runtime](../kernel/disyl-4.5-plan.md),
[4.6 federation + AI](../kernel/disyl-4.6-plan.md).

## New features

### Pattern matching (`{match}`)

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

- Patterns: string / int / float / `true` / `false` / `null` / `_` / identifier
  resolved from context.
- `guard EXPR` adds a boolean predicate that must also be truthy.
- `{default}` optional. With no match and no default, renders empty (and logs
  `disyl.match.unmatched` in strict mode).
- Nests inside `{if}`, `{for}`, `{foreach}`, and other `{match}` blocks.

Implementation: [kernel/DiSyL/TemplateEngine.php](../../kernel/DiSyL/TemplateEngine.php)
(`evaluateMatchBody`, `parseMatchArms`, `matchAnyPattern`).

### i18n (`{trans}`)

```disyl
{trans 'cart.empty'}Your cart is empty.{/trans}

{trans 'cart.items' plural=cart.count}
  {when one}1 item
  {when other}{cart.count} items
{/trans}

{trans 'product.title' context='shop_grid'}{product.name}{/trans}
```

Catalog format (`storage/i18n/{locale}.json`, optional per-tenant override at
`storage/i18n/{tenant_id}/{locale}.json`):

```jsonc
{
  "cart.empty":            { "value": "Tu carrito está vacío." },
  "cart.items": {
    "plural": {
      "one":   "1 artículo",
      "other": "%(count)s artículos"
    }
  },
  "product.title:shop_grid": { "value": "%(name)s" }
}
```

Lookup priority: tenant catalog → global catalog → inline body fallback.
Variables interpolated via `%(name)s` from top-level scalar context entries
(plus `count` in plural mode).

Locale resolution: `_locale` / `locale` context key; defaults to `en`.
Tenant resolution: `_tenant_id` / `tenant_id` context key.
Storage root override (tests): `_i18n_root` context key.

CLDR plural rules in 4.1 are a deliberate subset — `one` for `n == 1`,
`other` for everything else. Locales needing `zero/two/few/many` can register
custom resolvers via `Catalog::registerPluralRule(string $locale, callable $resolver)`.

Implementation: [kernel/DiSyL/i18n/Catalog.php](../../kernel/DiSyL/i18n/Catalog.php)
plus `evaluateTransBody` / `parseTransAttributes` / `collectTransVars` in
`TemplateEngine.php`.

## Roadmap docs published

The full DiSyL 4.x arc is now reviewable as six concrete plans:

| Minor | Doc | Status |
|---|---|---|
| 4.1 | [pattern + i18n](../kernel/disyl-4.1-plan.md) | shipped (this release) |
| 4.2 | [type system v1](../kernel/disyl-4.2-plan.md) | designed |
| 4.3 | [cache + experiments](../kernel/disyl-4.3-plan.md) | designed |
| 4.4 | [sandbox + capability scoping](../kernel/disyl-4.4-plan.md) | designed |
| 4.5 | [async runtime](../kernel/disyl-4.5-plan.md) | designed |
| 4.6 | [federation + AI](../kernel/disyl-4.6-plan.md) | designed |

## Verification

```bash
php tests/disyl_v4_test.php                         # 36 PASS / 0 FAIL
php tests/disyl_v41_match_test.php                  # 14 PASS / 0 FAIL  (new)
php tests/disyl_v41_i18n_test.php                   # 12 PASS / 0 FAIL  (new)
php tests/auth_owned_reserved_role_validation_test.php  # 3 PASS / 0 FAIL
php tests/render_context_contracts_test.php         # 58 PASS / 0 FAIL
php tests/sms_module_smoke_test.php                 # 6 PASS / 0 FAIL
php tests/tinymce_module_smoke_test.php             # 6 PASS / 0 FAIL
php tests/gui_settings_module_smoke_test.php        # 40 PASS / 0 FAIL
php scripts/guard-module-manifests.php              # 0 warnings, 0 errors
```

`storage/logs/app.log` and `storage/logs/error.log` are clean after the suite.

## Compatibility

- No breaking changes. Templates that don't use `{match}` or `{trans}` render
  byte-identically.
- New control tags reuse the existing `{tag args}…{/tag}` delimiter
  convention; no new escape rules.
- Catalog files are optional — absent catalogs simply fall back to inline body.

## Files changed

- `kernel/App.php` — version → `4.1.0`
- `kernel/DiSyL/TemplateEngine.php` — `match` + `trans` control types
- `kernel/DiSyL/i18n/Catalog.php` (new) — catalog loader + plural resolver
- `tests/disyl_v41_match_test.php` (new) — 14 assertions
- `tests/disyl_v41_i18n_test.php` (new) — 12 assertions
- `docs/kernel/disyl-4.1-plan.md` (new) — design + acceptance
- `docs/kernel/disyl-4.{2,3,4,5,6}-plan.md` (new) — follow-on minor designs

# ARK Direction — Module-Dev Frontend Toolkit (Refined Scope, 2026-08-22)

The decision-focused blueprint. Combines the executed audit (`.ai/ark-audit-report.md`),
the folding debate (`.ai/current-task.md`, `READY_FOR_AUDIT`), and the final reviewer
refinements. This is the direction ARK should take to be a genuinely capable, coherent
frontend toolkit for module developers.

## North star

> **ARK = the reference theme AND the module-dev frontend toolkit.**
> Module devs get governed building blocks (blocks, components, slots, tokens, fallbacks)
> that are portable, Doctrine-clean, and safe by default — and ARK's documentation is the
> canonical how-to. "Unknown fields are hidden" must be TRUE at runtime, not just in docs.

## Confirmed findings (evidence, from audit)

| # | Severity | Finding |
|---|---|---|
| 1 | **P0** | Safe-fallback doctrine NOT enforced: `EntityViewResolver` defaults `fields:'*'` → `display_fields = array_keys($rows[0])`; `DefaultEntityRenderer` only filters when `visible_fields` is non-empty, and nothing populates it. Internal fields can render. |
| 2 | P1 | Slot vocabulary drift README vs `slots.json` (`header.main`, `hero`, `header.after`, `content`, `notifications` — accepts/multiplicity), yet README claims a stability guarantee. |
| 3 | P1 | Dual identity: README "no module-specific hard deps" vs `entity-view-map.json` (ehr/ecommerce/bakeshop/guidance/attendance/pal contracts) + `block-registry.json` (9 module categories). |
| 4 | P1 | Tailwind CDN (JIT, needs `'unsafe-eval'`) + Alpine CDN loaded unconditionally in `public.disyl`; `component_variants` use Tailwind utilities while the token system is `--color-*`. Two theming systems. |
| 5 | P2 | `public/archive.disyl:30` inline `onchange` violates ARK's own `safety-policy.json` csp_note. |
| — | ✅ | Doctrine-clean confirmed: no SQL/PDO/auth/tenant/session/cookie in ARK; a11y markers present; multi-surface correct (export = reporting-owned). |

## UNVERIFIED (must be resolved in the follow-up audit, not assumed)

- `ThemeManifestValidator` exact coverage of `customizer/regions/capabilities/theme/layout`.
- `SlotRegistry`, `cmsThemeManifestForSlug()` defining file/callers, activation-state owner.
- JWT transport / role model / CSRF API shape at the request seam (test per discovered seam).
- Module capability-handler tenant behavior; test/validator existence + counts (doc-claimed).
- P0 line locations (audit-confirmed, revalidate at checkout).

## Phased roadmap (owners = Kernel Team / Theme Studio / Module Devs)

**P0 — Runtime safe-fallback (closes the central promise).**
`EntityViewResolver` + `DefaultEntityRenderer` fail-closed:
- `visible_fields` is presence-sensitive: absent/malformed metadata → fail closed (render
  nothing or a centrally governed allowlist ONLY if the audit approves it); explicit
  `visible_fields: []` → render no fields.
- Field discovery NEVER derives from any row (incl. `rows[0]`); `'*'` resolves only to
  approved presentation-safe metadata; metadata is intersected with requested fields and
  applied consistently to every row; all values still escaped/typed.
- Reassess globally allowing `id`/`status`/`price` (names alone don't prove safety).
- Regression tests: unknown/internal fields (tenant IDs, cost, notes, tokens) never render.

**P1 — Slot contract.** `slots.json` = single source of truth; align README accepts/
multiplicity; fix slot count (16 vs "17"); document deprecation policy.

**P1 — Identity + module boundaries (category-level, not per-file).**
Classify as (a) portable core (generic blocks/tokens/layouts/fallbacks), (b) module-flavored
showcase (block-registry + entity-view-map + module block-definitions → demote to
`examples/` or module-owned registries), (c) examples. Consumer-reference analysis precedes
any move. Authority hierarchy is an audit outcome, not predeclared.

**P1 — Unify theming.** One system: tokens-only (map `component_variants` → `--ark-*` /
`.ark-*`) OR build-time Tailwind output. Remove unconditional CDN JIT + Alpine CDN from
production layouts; production must work with zero optional assets, no CDN, no inline
handlers.

**P2 — Safety/CSP.** Eliminate `archive.disyl` inline `onchange` via an approved
bundled/nonce-compatible bridge with a no-JS fallback (or zero JS). Context-aware
forbidden-artifact scan (all files + executable-SQL patterns + false-positive ledger).

**Governance follow-up — audit-led.**
Enumerate `ThemeManifestValidator` behavior → define delta. Publish the module-dev toolkit
API (below). Confirm `SlotRegistry`/`cmsThemeManifestForSlug()` ownership. Idempotency +
activation + persistence questions resolved conditionally (only after seams confirmed);
any persistence change gets a separately reviewed, rerunnable, MySQL-5.7-compatible,
rollback-safe plan. Lifecycle/activation tests apply only to audit-confirmed seams.

## The module-dev toolkit API surface (what ARK gives module devs)

| Contract | Convention |
|---|---|
| **Blocks** | Register via `block-registry.json` with a capability contract (`capabilities.supports`); availability-gated presentation only. |
| **Components** | `component_variants` map semantic variants → concrete CSS (token classes or build-time Tailwind), portable across themes. |
| **Slots** | Stable governed IDs (`slots.json`), multiplicity + accepts-types, deprecation policy — modules contribute, ARK composes. |
| **Tokens** | `tokens.json` → `--ark-*` CSS custom properties; dark mode; module devs use tokens, never hardcoded colors. |
| **Fallbacks** | Safe-field allowlist + source-schema presentation-safe metadata; unknown types render, unknown fields hidden. |
| **Security** | Themes never do JWT/session auth, role checks, or authorization; state-changing actions render only Kernel/module-provided governed descriptors with Kernel-issued CSRF; capability availability never substitutes for authorization. |

## Status
- Contract: `.ai/current-task.md` — `READY_FOR_AUDIT`
- Audit report: `.ai/ark-audit-report.md`
- Next action: run the follow-up read-only audit (8 tool calls) to resolve the UNVERIFIED
  seams, then implement P0 → P1 → P2 per this roadmap.

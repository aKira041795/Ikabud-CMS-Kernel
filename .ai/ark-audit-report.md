# ARK Theme Audit Report — Evidence-Based Findings (2026-08-22)

Executed per the debate contract (`.ai/current-task.md`): read-only audit of
`storage/cms-themes/ark` + Kernel grounding. Budget-respecting, evidence-cited.
Status: **AUDIT COMPLETE** (read-only; no files modified).

Verdict on identity: ARK is technically sound and Doctrine-clean on DB/auth/tenant,
but has a **dual identity** (portable reference theme vs module-flavored showcase)
and **one P0 runtime gap** (safe-fallback doctrine unenforced by the Kernel).

---

## P0 — Safe-fallback doctrine NOT enforced by Kernel runtime

- ARK README (`docs/README.md`, "Safe Fallback Rendering Doctrine"):
  *"Unknown entity types are supported — unknown fields are hidden. Fallbacks never
  blindly render array_keys() from an unknown entity."*
- Kernel contradicts this:
  - `kernel/EntityContext/EntityViewResolver.php`:
    - L112 default contract `'fields' => '*'`; L229 "Ultimate fallback: generic
      contract with wildcard fields"; L713 `'fields' => '*'`
    - L369 `display_fields = is_array($displayFields) ? $displayFields :
      array_keys($rows[0])` → **all row keys when `fields` is `'*'`**
    - L696 "Uses '*' for fields so the template can render whatever data is
      available"
  - `kernel/EntityContext/DefaultEntityRenderer.php`:
    - L104 `$fields = $view['fields'] ?? ['*']`
    - L121 `$visibleFields = $view['visible_fields'] ?? []`
    - L124–128 `*` expands to `array_keys($rows[0])`; intersection with
      `visible_fields` happens **only when `visible_fields` is non-empty**
  - `visible_fields` is **never populated** by the resolver, and ARK's
    `entity-view-map.json` has no `visible_fields` keys.
- **Impact**: unknown/internal entity fields (tenant IDs, cost, notes, tokens,
  provider metadata) can be rendered. The doctrine exists in docs but not in the
  enforcement path.
- **Fix direction**: fail-closed — when `fields === '*'` and no `visible_fields`
  and no source schema safe-field metadata, render nothing (or a strict allowlist
  of `id/title/name/label/excerpt/description/url/image/status/price/
  published_at/created_at/author_name` per the ARK doctrine). Populate
  `visible_fields` from the entity source schema when available.

## P1 — Slot vocabulary drift (README vs slots.json)

| Slot | README accepts/multiple | slots.json accepts/multiple |
|---|---|---|
| `header.main` | component, nav / **yes** | [component] / **no** |
| `hero` | component, pattern / **yes** | [component, banner, slideshow] / **no** |
| `header.after` | component, hero / yes | [component, badge, notification] / yes |
| `content` | component, entity-view / no | [component, entity_list, entity_detail] / no |
| `notifications` | component, alert / yes | [component, badge, notification] / yes |

- README claims "These slot IDs will not be renamed or removed without a
  deprecation cycle" (stable public API), but two in-repo sources disagree on
  accepts/multiplicity. No canonical source, no deprecation policy.
- Also: layout comment says "17 governed slots"; `slots.json` + README table = 16.

## P1 — Dual identity / module boundary (needs P0 decision)

ARK README claims: *"not an ecommerce theme specifically", "no module-specific
hard dependencies", "not a business module", "not an exhaustive design showcase"*.
But ARK ships:
- `block-registry.json`: 9 module-flavored categories (ecommerce, healthcare,
  bakeshop, lms, wms, forms, data, content, layout) + `block-definitions/` with 10
  dirs (incl. `module/`)
- `entity-view-map.json`: cross-module presentation contracts for `ehr_patient`,
  `ehr_appointment`, `bakeshop_product`, `ecommerce_product`, `guidance_case`,
  `attendance_record`, `pal_project`, `pal_expense`, `cms_post`, …
- README changelog V3 brags "Ecommerce storefront: product-list/product-detail —
  full product grid"
- Block definitions reference module data (`bakeshop/ledger_row`,
  `healthcare/patient_summary`, `wms/stock_level`, …)

→ ARK is simultaneously a portable reference theme and a module-flavored
showcase. This is the central architecture question: **theme package vs
module-extension showcase**. Needs per-file ownership classification.

## P1 — Tailwind-vs-tokens duplication + CDN in "production" theme

- `theme.manifest.json` `component_variants` use Tailwind utilities
  (`bg-white border border-gray-100`, `bg-indigo-600`, `p-3`, `rounded-xl`) while
  the design-token system is `--color-*` CSS vars (`tokens.json` → `style.css`).
  Two parallel theming systems that can conflict.
- `layouts/public.disyl` loads `https://cdn.tailwindcss.com` (JIT, needs
  `'unsafe-eval'` in CSP) + Alpine CDN **unconditionally**. Manifest declares JS
  as `optional_assets`/zero-required-JS, but the layout always loads two CDN
  scripts; Tailwind CDN is a runtime JS CSS generator → de-facto mandatory JS +
  dev-time tooling in a "production certified" theme.
- ARK CSS is also served via `theme_style_url` (static `style.css`), so Tailwind
  utilities and token CSS coexist inconsistently.

## P2 — Inline handler violates ARK's own CSP note

- `public/archive.disyl:30` uses `onchange="window.location.href=this.value"`
  (inline JS). `safety-policy.json` csp_note: "Themes must not rely on inline
  onclick handlers; use Alpine or approved script blocks."
- Inconsistent with `public/ecommerce/product-list.disyl` which uses Alpine
  `@change`. Fix: convert `archive.disyl` to Alpine.

## P2 — Minor / documentation

- Layout comment "17 slots" vs 16 defined.
- `block-registry.json` categories (9) vs `block-definitions/` dirs (10 — extra
  `module/`).
- README "69+ a11y checks passing" + "a11y audit v3" are documentary claims; not
  re-verified here (would require isolated execution).

## Confirmed strengths (Doctrine-clean)

- **No** SQL/PDO/`db()`/auth/tenant/session/cookie access in ARK
  (grep: 24 hits, all false positives — HTML `<select>`, token names,
  customizer option handling).
- No DB tables, no entity sources, no workflows (README non-goals).
- a11y markers present: skip link, `role=banner`/`contentinfo`, `aria-label`,
  `aria-expanded`, `main tabindex="-1"`, nav landmark.
- 4 fallback views (card/table/detail/compact) declared + present.
- 16 governed slots; multi-surface public/print/email declared + supported.
- Export/PDF correctly NOT a gap (reporting-system-owned per README).
- Capability-gated blocks; full token system incl. dark-mode remap;
  optional `script.js` (Alpine/HTMX bridge) for enhancements.

---

## Direction — make ARK a capable module-dev frontend toolkit

1. **P0 — Enforce safe-fallback in Kernel** (fail-closed `*` without
   `visible_fields`; populate `visible_fields` from source schema). Without this,
   ARK's central promise ("unknown fields are hidden") is a lie at runtime.
2. **P0 — Resolve identity**: pick portable-reference-theme vs showcase.
   Recommend: keep generic blocks + tokens portable; demote module-flavored
   entity maps/block-definitions to `examples/` or move normative contracts into
   module-owned registries.
3. **P1 — Canonicalize slots**: single source of truth (`slots.json`), align
   README, add deprecation policy, fix slot count.
4. **P1 — Unify theming**: tokens-only (map variants to `--ark-*`/`.ark-*`
   classes) or Tailwind-only; replace CDN JIT with build-time Tailwind output;
   reconcile `component_variants` with token CSS.
5. **P2 — Enforce safety-policy**: convert inline handlers to Alpine.
6. **P2 — Module-dev API surface** (the toolkit): explicit conventions for
   blocks (register + capability contract), components (variant → CSS),
   slots (stable IDs), tokens (design tokens), fallbacks (safe-field
   allowlist). Each documented + exemplified; unsupported ideas stay roadmap.
7. **Roadmap**: P0 (safe-fallback, identity) → P1 (slots, theming) → P2
   (a11y/docs/safety).

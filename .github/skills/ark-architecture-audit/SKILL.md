---
name: ark-architecture-audit
description: 'System codebase audit — review Kernel OS, DiSyL, and ARK theme architecture for convention compliance, anti-patterns, and architectural soundness. Use when: auditing themes, reviewing system architecture, checking ARK convention adherence, validating DiSyL/Kernel OS integration, or performing pre-release quality checks.'
argument-hint: 'theme-slug (default: ark)'
---

# ARK Architecture & System Audit

## When to Use
- Pre-release theme quality certification
- Architecture compliance review for Kernel OS + DiSyL + ARK
- Checking if a theme follows ARK conventions and Theme Doctrine
- Investigating DiSyL/Kernel OS integration seams (slot system, entity views, component registry)
- Codebase health check before major upgrades

## Audit Scope
Three layers are inspected in sequence:

| Layer | Focus |
|---|---|
| **Kernel OS** | App bootstrap, capability bus, entity view resolver, slot registry, component registry, event system, workflow engine, security headers, CLI tools |
| **DiSyL** | Grammar support matrix, compiled mode, parser error recovery, expression evaluator, template engine, component rendering, function registry |
| **ARK Theme** | Manifest contract, design tokens, slot vocabulary, entity-view contracts, block library, variants system, multi-surface support, safety policy, fallback rendering, accessibility guarantees, anti-pattern scan |

---

## Procedure

### Phase 1 — Documentation Review (10 min)
1. Load `storage/cms-themes/<slug>/docs/README.md` for theme philosophy and conventions
2. Read `docs/kernel/kernel-os-disyl-roadmap-status.md` for current implementation status
3. Check `docs/kernel/entity-view-adoption-plan.md` if auditing entity views
4. Read `.github/instructions/disyl-grammar-gaps.instructions.md` for known DiSyL limitations
5. Read `.github/instructions/disyl-template-conventions.instructions.md` for template rules

### Phase 2 — Manifest Validation (5 min)
1. Verify `theme.manifest.json` has all required fields per [docs/02-manifest.md](./references/02-manifest.md):
   - `name` matches directory slug
   - `kernel_os_compat` and `disyl_compat` match current versions
   - `supported_surfaces` covers all expected rendering contexts
   - `fallback_views` defines all 4 types: card, table, detail, compact
   - `supported_slots` matches actual `{ikb_slot}` usage in layouts
   - `accessibility` guarantees are declared and implemented
2. Run: `php ikabud theme:validate <slug>` — check for warnings/errors
3. Run: `php ikabud theme:inspect <slug>` — verify slots, variants, fallbacks

### Phase 3 — Anti-Pattern Scan (10 min)
Check for **Theme Doctrine violations** — the theme must NOT:
- Access databases or SQL queries
- Read module tables
- Perform authorization or permission checks
- Execute business workflows or state transitions
- Depend on mandatory JavaScript frameworks
- Reference module-specific hard dependencies
- Perform tenant resolution or context

Check for **template anti-patterns**:
- `{forelse}` — use `{if not list}` after `{/for}` instead (preferred form: `{forelse}` is supported)
- Missing `|default:` fallbacks on all variables
- `{extends}` targeting stale versioned layouts (`.v2`, `.v3` copies identical to `.v1`)
- Raw `$var` PHP syntax where DiSyL dot-path works
- `{math equation="..."}` — **does not exist** in DiSyL, use direct expressions
- Unparenthesized arithmetic before pipes: `{(a + b) | filter}` not `{a + b | filter}`

### Phase 4 — Layout Shell Review (10 min)
1. Check all layout versions under `layouts/`:
   - `public.disyl` — primary shell with all `{ikb_slot}` markers
   - `public-print.disyl` — print-optimized (no JS, minimal CSS, table-based)
   - `public-email.disyl` — email-safe (table-based, inline styles, no external assets)
   - `admin-preview.disyl` — lightweight customizer iframe shell
2. **Slots audit**: Verify all declared `supported_slots` have an `{ikb_slot}` call in the shell
3. **Version dedup check**: If `.v2` / `.v3` copies are identical to `.v1`, flag as stale dead code
4. **Accessibility check**: Skip-to-content link, semantic landmarks (`<main>`, `<aside>`, `<nav>`), `aria-label`, `tabindex`

### Phase 5 — Entity View Contract Review (10 min)
1. Check `entity-view-map.json` for cross-module presentation contracts
2. Check `entity-views/` for fallback templates:
   - `default-card.disyl` — card grid rendering
   - `default-table.disyl` — dynamic table with auto-detected headers
   - `default-detail.disyl` — structured detail view
   - `default-compact.disyl` — minimal inline list
3. Verify `public/entity.view.disyl` uses capability-gated blocks (pricing, inventory, action, progress, lessons)
4. Verify `public/entity.list.disyl` uses `{ikb_entity_list}` governed component
5. **Safe fallback doctrine**: Unknown entity types render known-safe fields only — never `array_keys()` from raw data

### Phase 6 — Block Library Review (10 min)
1. Check `public/blocks/` directory structure matches variant system:
   ```
   blocks/
   ├── pricing/      → default, compact, featured
   ├── inventory/    → default, compact
   ├── action/       → default, inline
   ├── progress/     → default, inline
   ├── lessons/      → lessons
   ├── list-card/    → default, pricing, pricing.featured, inventory, inventory.compact, progress
   ├── meta.block.disyl
   └── media-gallery.block.disyl
   ```
2. Verify `renderer-registry.json` maps each variant to its template path
3. Check variant resolution in entity view templates — variants should be resolved from `entity_presentation.block_variants.*`, not hardcoded

### Phase 7 — Design Token Audit (5 min)
1. Verify `tokens.json` covers all required categories:
   - Color scales (50–900 for primary, secondary, accent, success, warning, danger, info)
   - Semantic colors (primary, surface, text, border, status)
   - Typography (scale xs–6xl, weights, line heights)
   - Spacing (2xs–5xl), radius (none–full), shadows (sm–xl)
   - Motion (duration, easing), z-index layers
   - Layout values (max-width, header height, sidebar width)
   - Component defaults (button, input, badge dimensions)
   - Dark palette variants
2. Verify `style.css` wires tokens through `--ark-*` vars with `var()` fallbacks
3. Check token priority chain: customizer → `tokens.json` → hardcoded fallback

### Phase 8 — Multi-Surface & Safety Policy (5 min)
1. Verify each surface layout exists and is functional:
   - `public` — full shell
   - `print` — minimal, no interactivity
   - `email` — table-based, inline styles, MSO guards
2. Read `safety-policy.json` — verify:
   - `raw_output.allowed_keys` covers all `|raw` variables used in templates
   - `blocked_patterns` is non-empty
   - `allowed_js_bridges` is reasonable
3. Check `component_variants` in manifest for Tailwind class mappings

### Phase 9 — DiSyL Runtime Integration (5 min)
1. Check `{ikb_entity_list}` / `{ikb_entity_detail}` usage follows governed component API
2. Verify `{ikb_slot}` names match the canonical 16-slot vocabulary from slot registry
3. Check that `{extends}` targets resolve correctly — no dangling references to non-existent layouts
4. Run: `php _lint_disyl.php --path storage/cms-themes/<slug>` — validate all templates

### Phase 10 — Test Validation (5 min)
1. Check for theme-specific test files under `tests/` matching `*<slug>*`
2. Run theme tests: `php tests/<slug>_test.php`
3. Check both logs after test run:
   - `storage/logs/app.log` — capability dispatch, slot resolution, DiSyL warnings
   - `storage/logs/error.log` — PHP fatal errors, stack traces

---

## Output Format

Produce a structured audit report with these sections:

```
## Summary
[Pass/Fail/Conditional] — [one-line verdict]

## Manifest & Validation
- [pass/fail] Schema validation
- [pass/fail] CLI validate command
- [pass/fail] CLI inspect command

## Anti-Pattern Scan
- [pass/fail] No DB/SQL in templates
- [pass/fail] No auth/tenant logic
- [pass/fail] All variables have |default: fallbacks
- [pass/fail] No stale versioned layout copies

## Layout Shell
- [pass/fail] All slots present
- [pass/fail] Accessibility features
- [pass/fail] Multi-surface support

## Entity Views
- [pass/fail] Fallback templates exist (4 of 4)
- [pass/fail] Capability-gated blocks in detail view
- [pass/fail] Entity-view-map.json covers expected types

## Block Library
- [pass/fail] Variant structure matches conventions
- [pass/fail] Renderer registry complete
- [pass/fail] Variant resolution from presentation context

## Design Tokens
- [pass/fail] Token coverage (colors, typography, spacing, etc.)
- [pass/fail] Dark palette
- [pass/fail] Token → CSS variable mapping

## Multi-Surface & Safety
- [pass/fail] Print layout
- [pass/fail] Email layout
- [pass/fail] Safety policy covers all raw outputs

## DiSyL Integration
- [pass/fail] All templates lint clean
- [pass/fail] Slot names match canonical vocabulary
- [pass/fail] {extends} targets resolve

## Tests
- [pass/fail] Test file exists and passes
- [pass/fail] Logs clean

## Issues Found
1. [severity] description — file:line

## Recommendations
- [priority] action item
```

## Quality Criteria
- Zero anti-pattern violations (DB access, auth logic, tenant resolution in templates)
- All templates pass DiSyL lint
- Theme validates cleanly via `php ikabud theme:validate <slug>`
- All `{extends}` targets resolve to existing files
- All variables have `|default:` fallbacks
- No stale versioned layout copies (identical `.v2`/`.v3` = dead code)
- Safe fallback doctrine enforced in entity-view fallbacks
- Test file exists and assertions pass
- Both logs (app.log + error.log) clean after test run

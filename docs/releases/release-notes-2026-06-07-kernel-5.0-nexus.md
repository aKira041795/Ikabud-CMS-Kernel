# Kernel OS 5.0 (nexus) — Release Notes

**Date:** June 7, 2026  
**Previous:** 4.6.0 (atlas)  
**Commit:** `083f90d`

---

## Summary

Kernel OS 5.0 is the **governed business platform** release. It ships the defining
`source`/`view` architecture the roadmap describes: write one line of DiSyL to
describe what you want, and the kernel handles permissions, data, styling, and
export — governed by capability contracts.

---

## New Features

### Entity-View Architecture (Phase 3)
- **`EntityViewResolver`** — resolves `source="orders.recent" view="compact"` to
  governed data via the capability bus. Built-in defaults for 6 entity types.
- **13 CMS view contracts** registered across 5 content types (pages, posts,
  products, users, media). Modules define what fields, actions, and views each
  entity supports.
- **`app()->entityViews()`** accessor in App.
- **ContextRegistry Phase 3B** — `registerSchema/Profile/Mode` stores wired.

### Governed DiSyL Components (Phase 2 complete)
31 components with full attribute schemas in ComponentRegistry 1.0:

| New in 5.0 | Description |
|---|---|
| `ikb_entity_list` | Governed entity list — 4 view modes (compact, card_grid, table, detailed) |
| `ikb_entity_detail` | Single entity detail with field-level visibility |
| `ikb_form` | Governed form — capability action binding, CSRF auto-inject |
| `ikb_stat_card` | Dashboard metric — label, value, trend, icon |
| `ikb_timeline` | Chronological event display |
| `ikb_audit_log` | Governed audit trail viewer |
| `ikb_export_button` | Download link → `/api/v1/export` |
| `ikb_confirm_action` | Destructive action guard (Alpine.js confirmation) |
| `ikb_drawer` | Slide-out panel with teleport |
| `ikb_panel` | Semantic container — `tone`, `spacing`, `radius` tokens |
| `ikb_report` | Report document with header, body, and metadata |
| `ikb_signature_block` | Official document signature lines |
| `ikb_ai_summary` | Governed AI summarization with review badge |
| `ikb_ai_assist` | Governed AI drafting with mode control |

### Export Pipeline (Phase 5)
- **`KernelExport`** service — CSV (always available) + DOCX via PHPWord.
  Register custom format handlers per entity type.
- **`/api/v1/export?source=&format=`** route — resolves entity data via
  EntityViewResolver → exports via KernelExport → streams file download.
- Wildcard defaults registered during kernel boot for all entity types.

### AI Governance (Phase 6)
- **Policy engine** — kill switch, model allowlist, cost ceiling, token cap.
- **`OpenAiProvider`** — reads `OPENAI_API_KEY` from env, governed by Policy.
  Falls back to `EchoAiProvider` when no key is configured.
- **`ikb_ai_summary`** + **`ikb_ai_assist`** — declarative AI blocks with
  review requirements and draft-only modes.

### Theme System (Phase 4)
- **`theme.manifest.json`** — formal theme contract (colors, typography, spacing,
  radius, shadows, component variants).
- **`tokens.json`** — CSS custom-property token definitions.
- **`cmsThemeManifestForSlug()`** reads both `theme.manifest.json` and
  `tokens.json` at runtime.

### Service Modules (Phase 8)
- **`"type": "service-module"`** manifest — validated at module load.
- `loadModuleHelpers()` skips PHP helpers for service modules.
- Example: `modules/ai-orchestrator/module.json` with external endpoint,
  health check, retry/backoff, circuit breaker, and signed token auth.

### Module Certification (Phase 9)
- **`php ikabud module:certify [module|--all]`** — 10-point checklist.
- **`GET /api/v1/cms/marketplace/catalog`** — module catalog with cert scores.
- Major production modules pass 9/9 (CMS, bakeshop, guidance, WMS, ecommerce).

### Builder Integration (Phase 7 partial)
- **`cmsRenderWidget_entity_list`** — delegates to `ikb_entity_list` (80 lines
  → 15 lines).
- **`GET /api/v1/cms/builder/components`** — governed component catalog for
  the React builder.

---

## Bug Fixes

| Issue | Fix |
|---|---|
| `capabilities()->call()` nonexistent method | Changed to `cap()->call()` in EntityViewResolver + TemplateEngine (4 instances) |
| `content_type` → `type` column mismatch | Fixed in CMS entity handlers |
| `module()` undefined in CLI context | Added `function_exists` guard in CMS handlers |
| Linter `//` comment stripper ate `{/if}` | Fixed 4 bakeshop templates + 1 guidance template |
| Capability "not found" logged as warning | Demoted to `info` level |
| JWT algorithm validation | Already resolved in 4.6 (header `alg` check) |
| EventBus DB query per fire | Already resolved (IntegrationBridge per-request cache) |

---

## Quality

| Metric | Value |
|---|---|
| Tests | 308 regression + 25 CMS POC = 333 total |
| Linter | 0 errors, 398 templates scanned |
| Load test | 22ms for 100 iterations across 6 critical paths |
| Template adoption | CMS dashboard, content list, guidance dashboard |
| error.log | Clean |

---

## Upgrade Notes

- **Version bump:** `KERNEL_VERSION = '5.0.0'`, `KERNEL_CODENAME = 'nexus'`
- **Entity-view contracts:** Modules should register view contracts via
  `app()->entityViews()->registerView()` during bootstrap.
- **Capability calls:** Use `app()->cap()->call()` (CapabilityBus), not
  `app()->capabilities()->call()` (CapabilityRegistry has no `call` method).
- **CMS handlers:** `entity.list.*` and `entity.get.*` capability handlers
  use `type` column (not `content_type`). Guarded against `module()` being
  undefined in CLI.
- **Templates:** `{ikb_entity_list source="cms.post.recent" view="compact" /}`
  uses curly braces (DiSyL syntax), not angle brackets.
- **Builder:** Rebuilt (`npm run build` from `modules/cms/builder-ui`).

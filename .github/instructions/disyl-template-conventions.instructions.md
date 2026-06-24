---
description: "DiSyL template language conventions — entity views, control structures, rendering patterns, and common pitfalls in the Ikabud application."
applyTo: "**/*.disyl"
---
# DiSyL Template Conventions

## Entity Views
- `{ikb_entity_list}` — renders a list view from an entity view contract
- `{ikb_entity_detail}` — renders a detail view from an entity view contract
- `{ikb_entity_view}` — registers a view contract in `helpers/views/` config files
- View contracts are loaded by `TemplateEngine::loadViewConfigs()`

## Composite Pages
- For dashboards and multi-section detail pages: use a custom DiSyL template
- Handler fetches aggregate data; template embeds `{ikb_entity_list}` calls
- Entity views handle single-source display only — not computed metrics, tabs, charts, or multi-field filter forms

## Control Structures
- Use standard DiSyL control structures (`{if}`, `{for}`, `{while}`, `{include}`)
- Use `{ikb_render}` for inline rendering of components
- Avoid HTML-as-source edits — builder source of truth is structured JSON

## Common Pitfalls
- DiSyL curly braces inside Alpine.js attributes can conflict — use proper escaping
- For Alpine.js + DiSyL: be mindful of attribute parsing boundaries
- Compiled template cache may need clearing after template changes

## Rendering Context
- `{cmsRender}`, `{cmsAdminContext}` — CMS rendering context providers
- Public rendering must be deterministic — no duplicate/conflicting HTML attributes
- For builder style/props attributes, preserve default-merge semantics from `NodeRenderer.tsx` and `modules/cms/helpers.php`

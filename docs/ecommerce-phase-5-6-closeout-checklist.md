# Ecommerce Phase 5 And 6 Closeout Checklist

Use this checklist to close the remaining runtime-hardening and theme-package cleanup work after the storefront convergence slices that completed Phases 1 through 4 and the shared storefront contract adoption.

## Current Snapshot

- [x] Phase 1 runtime mode resolution is active and test-backed.
- [x] Phase 2 product detail routes delegate through the canonical CMS entity-view path.
- [x] Phase 3 shop/category routes render through the canonical entity-list contract.
- [x] Shared storefront payload builders now feed native theme fallbacks, canonical CMS routes, and base ecommerce templates.
- [x] Entity customizer schema/examples are driving the canonical `entity_presentation` workspace.
- [x] Approved storefront list-card block variants are registered and hard-fail on invalid or missing variants.
- [ ] Formal Phase 5 capability-contract closeout remains to be executed.
- [ ] Formal Phase 6 theme-package cleanup and visual signoff remain to be executed.

## Phase 5 Closeout — Variant And Profile Runtime Hardening

### Runtime Contract Checklist

- [x] Approved layout profiles resolve deterministically at runtime.
- [x] Approved storefront block variants are allowlisted and invalid variants fail loudly.
- [x] Entity customizer validation rejects invalid profile and variant values predictably.
- [ ] Audit `cmsEntityCapabilityData()` providers for shape drift and undocumented payload keys.
- [ ] Add explicit negative coverage for invalid or malformed capability-provider responses.
- [ ] Verify canonical entity templates never render capability fragments when the runtime state marks that capability inactive.
- [ ] Verify ecommerce-origin product, course, and service examples all preserve the runtime capability boundary between `context`, `data`, and rendered fragments.
- [ ] Reconfirm no handler/template code silently reconstructs capability behavior that should be sourced from `cmsEntityCapabilityData()`.

### Files To Audit

- [ ] [modules/cms/helpers/56-entity-capabilities.php](modules/cms/helpers/56-entity-capabilities.php)
- [ ] [modules/cms/helpers/40-theme-settings.php](modules/cms/helpers/40-theme-settings.php)
- [ ] [modules/cms/helpers.php](modules/cms/helpers.php)
- [ ] [templates/modules/cms/public/blocks](templates/modules/cms/public/blocks)
- [ ] [storage/cms-themes/entity-commerce-poc/public/blocks](storage/cms-themes/entity-commerce-poc/public/blocks)

### Validation Checklist

- [ ] Run `php tests/cms_theme_test.php`
- [ ] Run `php tests/cms_entity_capability_view_test.php`
- [ ] Run `php tests/ecommerce_storefront_media_test.php`
- [ ] Add or update focused invalid-provider-response coverage if the audit exposes missing tests.
- [ ] Clear and inspect [storage/logs/app.log](storage/logs/app.log)
- [ ] Clear and inspect [storage/logs/error.log](storage/logs/error.log)

### Exit Criteria

- [ ] Runtime layout-profile selection is fully deterministic and documented.
- [ ] Block-variant resolution is fully allowlisted, enforced, and covered by failure-path tests.
- [ ] Capability-contract drift is detected at the data boundary rather than being patched in templates.

## Phase 6 Closeout — Theme Package Cleanup For The POC

### Theme Cleanup Checklist

- [x] POC theme entity view and entity list templates align to canonical CMS contracts.
- [x] Shared shell wrappers are already being used for customized header, footer, and sidebar regions.
- [x] Base ecommerce templates and native storefront templates now consume the shared storefront contract.
- [ ] Audit the POC theme templates for any remaining storefront-app structure that duplicates canonical CMS list or entity contracts.
- [ ] Audit the POC stylesheet for selectors that still assume a separate storefront engine rather than a design-system layer.
- [ ] Verify customizer-generated shell markup is fully styled without relying on runtime-only hacks or route-specific DOM assumptions.
- [ ] Verify the published asset copy under `public/assets` is in sync with the source POC theme assets if any source asset changed.
- [ ] Perform focused visual verification for shop, category, product detail, cart, checkout, my orders, and order detail in both desktop and mobile layouts.
- [ ] Reconfirm native-theme storefront fallbacks remain stable and clearly separate from canonical entity-view mode.

### Files To Audit

- [ ] [storage/cms-themes/entity-commerce-poc/theme.json](storage/cms-themes/entity-commerce-poc/theme.json)
- [ ] [storage/cms-themes/entity-commerce-poc/style.css](storage/cms-themes/entity-commerce-poc/style.css)
- [ ] [storage/cms-themes/entity-commerce-poc/public](storage/cms-themes/entity-commerce-poc/public)
- [ ] [public/assets/cms/themes/entity-commerce-poc](public/assets/cms/themes/entity-commerce-poc)

### Verification Checklist

- [ ] Confirm the POC theme reads as a design-system layer, not a second storefront application.
- [ ] Confirm shell width, spacing, typography, and storefront tokens all come from the shared public theme style contract.
- [ ] Confirm no canonical ecommerce route depends on traditional-only template assumptions.
- [ ] Re-run `php tests/cms_theme_test.php`
- [ ] Re-run `php tests/ecommerce_storefront_media_test.php`
- [ ] Clear and inspect [storage/logs/app.log](storage/logs/app.log)
- [ ] Clear and inspect [storage/logs/error.log](storage/logs/error.log)

## Closeout Notes

- Prefer explicit storefront-vs-legacy branches in Disyl templates when booleans or arrays are involved; `|default` is not safe for falsey storefront state.
- Keep public rendering on the merged theme/entity presentation style contract from `cmsRenderPublicThemeStyle()` instead of reassembling shell and entity CSS ad hoc.
- Keep ecommerce-to-CMS ownership boundaries intact: canonical public rendering and CMS-owned writes still belong to CMS context, even when routes originate in ecommerce.

## Done Means Done

- [ ] Phase 5 exit criteria are satisfied and regression-tested.
- [ ] Phase 6 cleanup and visual verification are complete.
- [ ] Logs remain clean after the final verification pass.
- [ ] No remaining checklist item requires interpretation before implementation.
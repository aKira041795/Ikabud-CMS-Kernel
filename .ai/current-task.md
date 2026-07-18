# Current Task

## Objective

Harden the Bakeshop module release contract by reconciling missing and conflicting process surfaces across manifest metadata, migrations, event declarations, route coverage, documentation, tests, and UI/UX workflow guidance.

This is an architecture and release-readiness pass for the existing Bakeshop module. The implementation should preserve the current module-owned auth shell and tenant-local `bakeshop_*` data model while making the served workflow, documented workflow, and test contracts agree.

## Existing behavior

The Bakeshop module is implemented as a stand-alone bakery operations workspace under `modules/bakeshop` with DiSyL templates under `templates/modules/bakeshop`.

Runtime entry points include:

- Module manifest: `modules/bakeshop/module.json`
- Routes: `modules/bakeshop/routes.php`
- Handler loader: `modules/bakeshop/handlers.php`
- Split handlers: `modules/bakeshop/handlers/*.php`
- Workspace shell: `templates/modules/bakeshop/layouts/app.disyl`
- Main workspace UI: `templates/modules/bakeshop/pages/supervisor.disyl`
- Supporting pages: users, account, settings, history, product ledger, product coverage, and print views
- Documentation: `docs/bakeshop/bakeshop-module.md` and `docs/bakeshop/workspace-user-guide.md`
- Main contract test: `tests/bakeshop_module_test.php`

The intended operator flow is branch setup, ingredients, products and recipes, deliveries, production, then usage/reporting. The shell sidebar also exposes reports for Product Ledger, Coverage Summary, and Print Summary, plus administration and account pages.

Several newer processes already exist in code:

- Delivery coverage days and DR projection
- Suggested reorder reporting
- Product targets
- Product allocations
- Inventory adjustments
- Product ledger and coverage reports
- Print coverage and DR projection
- Product recipe activation and production recipe policy settings
- Username case-sensitivity migration

## Architectural constraints

- `/architect` is plan-only. Do not edit production code as part of this step.
- Preserve module-owned auth, `bakeshop_token`, role permission behavior, and the stand-alone bakeshop admin shell.
- Preserve tenant-local table ownership; do not add tenant IDs to `bakeshop_*` tables.
- Treat `module.json`, `routes.php`, migrations, event names, docs, and focused tests as release contract sources that must not drift.
- Keep fixes additive unless a conflict is demonstrably broken at runtime.
- Do not collapse the Bakeshop module into kernel admin chrome or another module.
- Do not run the full test suite for this task; use focused Bakeshop tests after implementation.

## Files likely affected

- `modules/bakeshop/module.json`
- `modules/bakeshop/routes.php`
- `modules/bakeshop/handlers/10-pages.php`
- `modules/bakeshop/handlers/20-api-products-recipe.php`
- `modules/bakeshop/handlers/25-api-product-targets.php`
- `modules/bakeshop/handlers/45-api-inventory-adjustments.php`
- `modules/bakeshop/handlers/57-api-product-coverage.php`
- `templates/modules/bakeshop/layouts/app.disyl`
- `templates/modules/bakeshop/pages/supervisor.disyl`
- `templates/modules/bakeshop/pages/history.disyl`
- `templates/modules/bakeshop/pages/product-ledger.disyl`
- `templates/modules/bakeshop/pages/product-coverage.disyl`
- `templates/modules/bakeshop/pages/settings.disyl`
- `docs/bakeshop/bakeshop-module.md`
- `docs/bakeshop/workspace-user-guide.md`
- `tests/bakeshop_module_test.php`
- Existing focused tests under `tests/bakeshop_*_test.php`
- New focused route/manifest/history/workflow tests if the existing test file becomes too broad

## Implementation steps

1. Reconcile migration registration.
   - Add `database/migrations/016_bakeshop_username_case_sensitive.sql` to `modules/bakeshop/module.json`.
   - Extend the Bakeshop manifest test so every migration file under `modules/bakeshop/database/migrations` is either declared or explicitly exempted with a reason.
   - Verify the migration list order remains fresh-tenant safe.

2. Reconcile manifest events with emitted events and audit actions.
   - Compare `module.json` `events[]` with event emissions and audit actions in split handlers.
   - Add missing declared events for currently emitted domain events, including at least inventory adjustment creation if it remains an event.
   - Decide whether audit-only actions such as product target create/update/delete, allocation create/delete, adjustment delete, product delete, ingredient delete, recipe delete, delivery delete, and production void/delete should become declared events or stay audit-only.
   - Document the event-vs-audit rule in `docs/bakeshop/bakeshop-module.md`.

3. Align process order across UI copy, focused page intros, docs, and sidebar.
   - Make the canonical setup order consistent everywhere: branches, ingredients, products/recipes, deliveries, production, usage review.
   - Update the Product focused page intro currently saying "Define finished goods first" so it no longer contradicts ingredient-first setup.
   - Resolve naming drift between "Baking Log" in docs and "Production Runs" in the UI. Pick one primary label and use the other only as helper copy if useful.

4. Bring documentation up to current runtime.
   - Update `docs/bakeshop/bakeshop-module.md` migration count, settings-field summary, handler responsibility table, route list, template list, and test coverage table.
   - Add coverage for product ledger, product coverage, allocations, adjustments, DR projection, suggested reorder, delivery coverage days, logo upload, and recipe-policy settings.
   - Update `docs/bakeshop/workspace-user-guide.md` so report surfaces and daily workspace actions match the current sidebar and page labels.

5. Close route and test-contract gaps.
   - Extend route assertions to cover the currently routed but under-tested surfaces: ingredients page, product ledger, coverage, coverage print/csv, DR projection print, suggested reorder API, allocations APIs, adjustments APIs, product targets APIs, logo upload, import templates, batch delete endpoints, and health endpoint.
   - Add a route-vs-sidebar/rendered-link assertion for Bakeshop similar in spirit to module nav source guards: every internal `href` rendered by the bakeshop shell and major pages should resolve to a registered GET route unless explicitly external, print-targeted, or API-only.
   - Add a manifest-vs-files assertion for migrations.

6. Fix Activity History deep-link coverage.
   - Add entity labels and focused return URLs for `bakeshop_branch_product_targets`, `bakeshop_inventory_adjustments`, and `bakeshop_product_allocations`.
   - Add or update UI focus handlers for ledger/coverage/report records only where the destination can actually open a meaningful record context.
   - If an entity cannot support a focused editor, show a stable report/filter URL rather than a dead or generic history link.

7. Audit UI/UX states on the main workspace.
   - Check that every tab has usable loading, empty, filtered-empty, success, error, retry, and post-save refresh states.
   - Check mobile sidebar, sticky topbar, table overflow, long product/ingredient names, wide forms, and print-link filter preservation.
   - Check destructive actions for confirmation text, archive-vs-delete guidance, and post-action focus/announcement.
   - Keep visual changes restrained and consistent with the existing Bakeshop shell.

8. Verify settings policy behavior.
   - Keep the existing rule that deactivated product recipes force production recipe mode to optional.
   - Make the Settings UI explain that dependency clearly near the two controls.
   - Add focused tests proving saved settings, helper behavior, and rendered settings copy stay aligned.

## Acceptance criteria

- Fresh-tenant migration metadata includes every intended Bakeshop migration file, including username case-sensitivity.
- `docs/bakeshop/bakeshop-module.md` accurately describes current migrations, routes, settings, handlers, templates, and tests.
- The workspace guide and UI use one coherent process order and one coherent label set for production/baking work.
- All Bakeshop sidebar/internal page links resolve to registered GET routes or are explicitly allowed exceptions.
- Route tests cover all current Bakeshop page, report, import, and API route surfaces.
- Activity History gives useful labels and destination URLs for product targets, product allocations, and inventory adjustments, or intentionally marks them as audit-only with no broken focus path.
- Settings UI and tests document the recipe-status/production-policy dependency.
- Focused Bakeshop tests pass after implementation.

## Required tests

- `php -l modules/bakeshop/handlers.php`
- `php -l modules/bakeshop/routes.php`
- `php tests/bakeshop_module_test.php`
- `php tests/bakeshop_supervisor_settings_panel_test.php`
- `php tests/bakeshop_display_settings_save_test.php`
- `php tests/bakeshop_product_recipe_toggle_test.php`
- `php tests/bakeshop_supervisor_dr_workflow_render_test.php`
- `php tests/bakeshop_dr_projection_integration_test.php`
- `php tests/bakeshop_dr_projection_print_test.php`
- `php tests/bakeshop_print_summary_test.php`
- Add and run focused tests for migration manifest completeness, route/link coverage, and history deep-link coverage if not added to existing files.

## Risks

- Adding a migration to `module.json` can affect tenants whose migration bookkeeping already diverged from file state; confirm idempotence before deployment.
- Declaring too many audit-only actions as events may create noisy downstream integrations; decide event semantics before expanding `events[]`.
- The main `supervisor.disyl` template is very large, so UI changes should be surgical and tested through rendered HTML assertions.
- History deep links for report-derived records can be misleading if the destination cannot open the exact record.
- Documentation drift has already happened; tests should enforce the highest-risk contracts rather than relying on docs review.

## Forbidden changes

- Do not replace the Bakeshop shell with kernel admin navigation.
- Do not remove module-owned auth or weaken `bakeshopCurrentUser()` authorization gates.
- Do not change tenant data ownership or introduce cross-tenant fields.
- Do not delete existing report surfaces, import flows, ledger/coverage pages, or settings controls to simplify the audit.
- Do not rewrite `templates/modules/bakeshop/pages/supervisor.disyl` wholesale.
- Do not introduce broad visual redesign unrelated to process clarity and broken states.
- Do not run the full repository test suite unless separately requested.

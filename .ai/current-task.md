# Current Task

## Objective

Audit and fix Project Audit Ledger view wiring so every PAL page, form, table, entity list, dashboard widget, approval card, and team-lead view that depends on a newly created Job Order displays true persisted data from the same JO record and its linked lifecycle records.

The source event is JO create:

1. Admin/encoder submits `/admin/project-audit-ledger/projects/create`.
2. `palApiProjectStore()` persists `pal_projects` and `pal_project_items`.
3. The user is redirected to `/admin/project-audit-ledger/projects/{id}`.
4. Every related view that should reflect the new JO must show it through real loaders, not cached, placeholder, stale, or disconnected display data.

The second required trace is mobilization approval:

1. A team lead creates a mobilization request linked to a JO.
2. Admin/supervisor approves it from either the centralized approval queue or the mobilization detail action.
3. Every related view must reflect the approved status, project linkage, amount, approval decision, audit trail, and any dashboard/report totals that should change.

## Existing behavior

JO creation currently flows through:

- `modules/project-audit-ledger/routes.php`
  - `GET /admin/project-audit-ledger/projects/create` -> `palPageProjectForm`
  - `POST /api/v1/project-audit-ledger/projects` -> `palApiProjectStore`
  - `GET /admin/project-audit-ledger/projects/{id}` -> `palPageProjectDetail`
  - `GET /admin/project-audit-ledger/projects` -> `palPageProjectList`
- `modules/project-audit-ledger/handlers/15-projects.php`
  - `palPageProjectList()` renders `projects-list.disyl`.
  - `palPageProjectForm()` loads clients, project types, team leads, materials, optional mockup attachment, then renders `project-form.disyl`.
  - `palApiProjectStore()` creates the JO with `palProjectService::create()`, optionally applies workflow status, creates a project approval for non-admin pending submissions, relinks mockup attachments, audits `pal.project.created`, then returns `redirect = /admin/project-audit-ledger/projects/{id}`.
  - `palPageProjectDetail()` reloads the JO with `palProjectService::get()`, cost/profitability/budget services, expense history, collection history, purchase history, attachments, PO images, and renders `project-detail.disyl`.
- `modules/project-audit-ledger/services/ProjectService.php`
  - `create()` writes `pal_projects`, saves `pal_project_items`, syncs inline client data, fires `pal.project.created`.
  - `list()` reads `pal_projects` for API list consumers.
  - `get()` reads the JO, client, project type, team lead, and line items.
- `modules/project-audit-ledger/helpers.php`
  - `pal_cap_entity_list_project_1()` backs `projects-list.disyl` through `{ikb_entity_list source="pal_project" ...}`.
  - `pal_cap_entity_get_project_1()` is the entity-get capability for JO data.
  - Other entity list/get capabilities join `pal_projects` by `project_id` and must display the JO title/number consistently.
- `modules/project-audit-ledger/presentation/PalDashboardViewModel.php`
  - Dashboard totals, status breakdown, recent projects, financials, pending approvals, and audit-derived recent activity all depend directly or indirectly on JO rows.

Mobilization approval currently flows through:

- `GET /admin/project-audit-ledger/team-lead/mobilization/create` -> `palPageTeamLeadMobilizationForm`
  - Loads selectable projects for the team lead from `pal_projects` where `fabrication_team_lead_id = team_lead_id` and status is in `pending`, `approved`, `started`, `ongoing`.
- `POST /api/v1/project-audit-ledger/tl/mobilization` -> `palApiTeamLeadMobilizationStore`
  - Revalidates AW attendance context when supplied.
  - Inserts `pal_mobilization_requests`.
  - Creates `pal_approvals` with `entity_type = mobilization`.
  - Writes `approval_id` back to the mobilization request.
  - Audits and fires `pal.mobilization.requested`.
- `GET /admin/project-audit-ledger/mobilization` -> `palPageMobilizationList`
  - Renders `{ikb_entity_list source="pal_mobilization" ...}` backed by `pal_cap_entity_list_mobilization_1()`.
- `GET /admin/project-audit-ledger/mobilization/{id}` -> `palPageMobilizationDetail`
  - Loads the request, team lead, linked JO title/number, and attendance group name.
- `GET /admin/project-audit-ledger/approvals` -> `palPageApprovalQueue`
  - Uses `palApprovalService::pendingListEnriched()` and `recentListEnriched()`.
- `POST /api/v1/project-audit-ledger/approvals/{id}/decide` -> `palApiApprovalDecide`
  - Uses centralized `palApprovalService::decide()`.
- Direct mobilization endpoints also exist:
  - `/api/v1/project-audit-ledger/mobilization/{id}/approve`
  - `/api/v1/project-audit-ledger/mobilization/{id}/reject`
  - `/api/v1/project-audit-ledger/mobilization/{id}/disburse`

Known audit targets from inspection:

- `PalDashboardViewModel::projectPipeline()` counts active projects using `status IN ('approved','in_progress')`, while JO/project routes and form logic use statuses such as `started` and `ongoing`; verify dashboard active counts are truthful after JO create/status changes.
- `pal_cap_entity_list_project_1()` lists `project_id` and `title` but not `job_order_number`; views that label records as Job Orders must display the real JO number where expected.
- `pal_cap_entity_list_purchase_1()` does not expose `project_title`, while purchase detail joins the project; purchase list may be disconnected from JO context.
- Mobilization approval through centralized approvals and direct mobilization endpoints must not leave `pal_mobilization_requests`, `pal_approvals`, `approval-queue.disyl`, `mobilization-list.disyl`, `mobilization-detail.disyl`, audit trail, and dashboard pending approvals out of sync.

## Architectural constraints

- PAL owns JO/project, mobilization, approval, sales, collections, expenses, purchases, fabrication, inventory, audit, reports, and dashboard display data.
- Attendance & Wage remains the source of truth for attendance/wage evidence; PAL may consume it through capabilities but must not duplicate AW table ownership.
- Receivables are JO-linked; do not introduce free-standing receivable creation outside the JO/invoice lifecycle.
- A view is correct only if it reads persisted tenant-scoped data through the appropriate service, handler query, or capability. Template-only changes are not enough unless the loader already exposes the correct fields.
- Entity list templates backed by `{ikb_entity_list ...}` must be fixed at their `entity.list.*` capability or entity-view definition when data is missing.
- Admin views, team-lead views, print/email templates, dashboards, reports, and audit trails must agree on identifiers: `pal_projects.id`, `project_id`, `job_order_number`, title, client, team lead, status, contract amount, and linked entity IDs.
- Keep tenant filters on every query and join. Joined project data must not leak across tenants.
- Do not bypass `palCurrentUser()`, `palTeamLeadGuard()`, CSRF enforcement, `palApprovalService`, or the capability bus.
- Do not create duplicate display-only state to make one page look correct.

## Files likely affected

JO create and core project data:

- `modules/project-audit-ledger/routes.php`
- `modules/project-audit-ledger/handlers/15-projects.php`
- `modules/project-audit-ledger/services/ProjectService.php`
- `modules/project-audit-ledger/services/ProjectCostService.php`
- `modules/project-audit-ledger/services/JobOrderWorkflow.php`
- `modules/project-audit-ledger/helpers.php`
- `modules/project-audit-ledger/helpers/views/pal_project.disyl`
- `modules/project-audit-ledger/templates/project-audit-ledger/pages/project-form.disyl`
- `modules/project-audit-ledger/templates/project-audit-ledger/pages/projects-list.disyl`
- `modules/project-audit-ledger/templates/project-audit-ledger/pages/project-detail.disyl`
- `modules/project-audit-ledger/templates/project-audit-ledger/prints/project-print.disyl`
- `modules/project-audit-ledger/templates/project-audit-ledger/_email_job_order.disyl`

Views that must update when JO is created:

- Dashboard:
  - `modules/project-audit-ledger/handlers/10-dashboard.php`
  - `modules/project-audit-ledger/presentation/PalDashboardViewModel.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/dashboard.disyl`
- Approval queue and audit trail:
  - `modules/project-audit-ledger/handlers/55-approvals.php`
  - `modules/project-audit-ledger/services/ApprovalService.php`
  - `modules/project-audit-ledger/handlers/65-audit.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/approval-queue.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/audit-trail.disyl`
- Team lead and mobilization:
  - `modules/project-audit-ledger/handlers/53-team-lead.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-dashboard.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-mobilization-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-mobilization-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/mobilization-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/mobilization-detail.disyl`
  - `modules/project-audit-ledger/helpers/views/pal_mobilization.disyl`
- Cash advances:
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/cash-advance-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/cash-advances-list.disyl`
  - related handlers in `modules/project-audit-ledger/handlers/53-team-lead.php`
- Fabrication:
  - `modules/project-audit-ledger/handlers/45-fabrication.php`
  - `modules/project-audit-ledger/services/FabricationService.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/fabrication-allocations.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/fabrication-dues.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/fabrication-payments.disyl`
- Sales, receivables, and collections:
  - `modules/project-audit-ledger/handlers/50-sales.php`
  - `modules/project-audit-ledger/services/ReceivableService.php`
  - `modules/project-audit-ledger/services/PaymentService.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/sales-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/sales-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/sales-detail.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/collections-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/collection-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/collections-detail.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/prints/invoice-print.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/_email_invoice.disyl`
- Expenses, purchases, material issuance, material returns, BOM, reports:
  - `modules/project-audit-ledger/handlers/25-expenses.php`
  - `modules/project-audit-ledger/handlers/30-purchases.php`
  - `modules/project-audit-ledger/handlers/40-issuance.php`
  - `modules/project-audit-ledger/handlers/60-reports.php`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/expense-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/expenses-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/expense-detail.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/purchase-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/purchases-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/purchase-detail.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/issuance-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/issuance-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/issuance-detail.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/material-return-form.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/material-return-list.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/bill-of-materials.disyl`
  - `modules/project-audit-ledger/templates/project-audit-ledger/pages/reports-center.disyl`

Tests likely affected:

- `tests/pal_service_integration_test.php`
- `tests/pal_mobilization_attendance_capability_test.php`
- `tests/pal_attendance_bridge_test.php`
- `tests/pal_mobilization_fix_verification_test.php`
- Add a focused JO view wiring test if no existing test covers this exact matrix.
- Browser tests under `tests/browser/modules/pal/workflows/`.

## Implementation steps

1. Build a JO dependency matrix before editing.
   - Start at `palApiProjectStore()` and `palProjectService::create()`.
   - Record every persisted field created or updated: `pal_projects`, `pal_project_items`, inline `pal_clients` sync, `pal_attachments`, optional `pal_approvals`, `pal_audit_logs`, and domain event payload.
   - For each field, map all views that display it.
   - Store the matrix in `.ai/current-task.md` under an implementation report section or in a small test fixture comment if the later workflow prefers code evidence.

2. Audit all root views that must update immediately after JO create.
   - Dashboard: totals, status breakdown, recent projects, pending approval count/items, audit recent activity, contract value, fabrication budget.
   - JO list: row appears with true JO number/title/client/status/contract amount/dates.
   - JO detail: overview, items, client, schedule, payment terms, installation/team lead, mockup, financial tabs, related history.
   - JO print and JO email: same persisted JO fields as detail.
   - Approval queue: pending project approval appears only when workflow creates one; admin auto-approved JO must not show a fake pending item.
   - Audit trail: `pal.project.created` appears and links back to the JO detail.

3. Audit forms that should offer the newly created JO as selectable context.
   - Team-lead mobilization form project dropdown.
   - Cash advance form project dropdown.
   - Expense form project selector.
   - Purchase form project selector.
   - Sales/invoice form project selector.
   - Collection form project context where applicable.
   - Material issuance form project selector.
   - Material return form project context where applicable.
   - Fabrication allocation/payment/dues flows.
   - BOM and reports filters if they allow JO/project selection.

4. Audit tables/detail views that should show linked JO data after downstream records are created.
   - Expenses list/detail and JO detail expense history.
   - Purchases list/detail and JO detail purchase history.
   - Sales list/detail, receivables, collections list/detail, invoice print/email, and JO detail collection history.
   - Material issuance list/detail and material return list/detail.
   - Fabrication allocations, weekly dues, and payments.
   - Cash advances and mobilization requests.
   - Approval queue and recent decisions for every JO-linked entity type.
   - Audit trail for every JO-linked action.

5. Fix data loaders at the source.
   - If a template lacks a field, first update the handler/service/capability/query that should expose it.
   - For `{ikb_entity_list ...}` views, update the matching `pal_cap_entity_list_*` function or entity view definition.
   - Ensure list and detail loaders expose `project_id`, `job_order_number`, `project_title`, `client_name`, `team_lead_name`, `status`, amount fields, and created/decision dates where the template needs them.
   - Keep display labels consistent: JO-facing UI should prefer `job_order_number`, with `project_id` as secondary/internal project code when both exist.

6. Trace mobilization request approval as its own display update chain.
   - Create or seed a mobilization request linked to the JO.
   - Confirm the team-lead mobilization list shows the request with the linked JO.
   - Confirm admin mobilization list and detail show the same request, team lead, JO number/title, amount, attendance context, and `approval_id`.
   - Approve through `/api/v1/project-audit-ledger/approvals/{id}/decide`.
   - Confirm `pal_mobilization_requests.status`, `pal_approvals.decision`, approval queue pending/recent lists, mobilization list/detail, audit trail, and dashboard pending approval count all update.
   - Repeat or source-check the direct mobilization approve/reject endpoints and ensure they cannot diverge from centralized approval behavior.

7. Normalize status semantics across views.
   - Enumerate valid JO statuses from schema/workflow/form/service.
   - Fix any dashboard/report/list logic that uses stale status names.
   - Verify newly created admin-approved, draft, and pending JOs land in the correct counts and tables.

8. Add focused automated coverage.
   - Prefer one integration test that creates a JO, then asserts all critical loaders return true data.
   - Add or extend mobilization approval coverage to assert all critical loaders update after approval.
   - Add route/template assertions for any fixed view links.
   - Add browser coverage only for the critical user-facing path if the environment supports it.

## Acceptance criteria

- Creating a JO returns JSON `ok: true` with a redirect to the persisted JO detail route.
- The JO exists in `pal_projects` with correct tenant, JO number, title, client, project type, team lead, status, contract amount, payment, installation, schedule, and notes.
- JO line items exist in `pal_project_items` and render on the JO detail items tab.
- The JO appears on `/admin/project-audit-ledger/projects` with real persisted values, including the correct JO number where the UI labels the row as a Job Order.
- `/admin/project-audit-ledger/projects/{id}` renders persisted data across overview, items, financials, docs, and related history sections.
- `/admin/project-audit-ledger/projects/{id}/print` and JO email output use the same persisted JO identity and financial fields.
- Dashboard project totals, recent projects, status breakdown, contract value, fabrication budget, and pending approvals reflect the new JO according to its actual status.
- If JO creation creates a pending project approval, `/admin/project-audit-ledger/approvals` shows it with linked JO title/number and amount; if admin auto-approves, no fake pending approval appears.
- `/admin/project-audit-ledger/audit-trail` shows `pal.project.created` and links to the JO detail page without 500 errors.
- Every JO-linked form that should allow selecting the JO includes the newly created JO when its status and team-lead assignment make it eligible.
- Creating downstream records linked to the JO causes their list/detail pages and the JO detail related-history sections to show the same persisted linkage.
- Team-lead mobilization request creation linked to the JO appears in team-lead list, admin mobilization list, admin mobilization detail, approval queue, audit trail, and dashboard pending approval count.
- Approving a mobilization request updates `pal_mobilization_requests`, `pal_approvals`, approval pending/recent displays, mobilization admin/team-lead displays, audit trail, and dashboard pending approval count.
- All affected views remain tenant-scoped and do not show records from another tenant.

## Required tests

- Syntax:
  - `php -l` on every changed PHP file.
- Focused PHP/integration:
  - `PAL_TENANT_ID=502 php tests/pal_service_integration_test.php`
  - `PAL_TENANT_ID=502 php tests/pal_mobilization_attendance_capability_test.php`
  - `PAL_TENANT_ID=502 php tests/pal_attendance_bridge_test.php`
  - `php tests/kernel_auth_delegation_test.php` if delegation or team-lead mobilization is touched.
- New or extended JO view wiring test:
  - Create a JO with line items, client, team lead, installation/fabrication data, payment terms, and a status that exercises approval behavior.
  - Assert service/API/entity capability/dashboard/approval/audit loaders all expose the same JO identity and true values.
  - Assert all project selectors that should include the JO do include it under the correct eligibility rules.
- New or extended mobilization approval display test:
  - Create a JO assigned to a team lead.
  - Create a mobilization request linked to that JO.
  - Approve it through centralized approval decision.
  - Assert mobilization list/detail, approval recent decisions, dashboard pending count, and audit trail all reflect the transition.
- Browser coverage when feasible:
  - Create JO through the form.
  - Verify redirect/detail.
  - Visit dashboard, projects list, approval queue, audit trail, team-lead mobilization form, and admin mobilization list/detail.
  - Verify visible data matches the JO and mobilization records.
- Always run:
  - `git diff --check`.

Do not run the full suite unless a later workflow explicitly requests it.

## Risks

- Some views use entity-list capabilities while others use direct SQL; fixing only one loader will leave divergent displays.
- Status names may be inconsistent across workflow, dashboard, selectors, and reports.
- JO number and internal project code can be confused; display rules must be explicit.
- Team-lead project selectors intentionally filter by `fabrication_team_lead_id` and status; do not treat absent rows as a bug until eligibility is verified.
- Mobilization can be approved through more than one endpoint; state can drift if direct endpoints and centralized approval service do not share behavior.
- Browser-visible correctness may be masked by DiSyL compiled template cache; clear cache or use the repo's no-cache path when validating templates.
- Generated browser artifacts under `test_results/` should not be committed unless a release workflow requires them.

## Forbidden changes

- Do not edit production code outside PAL or AW unless a traced dependency proves it is necessary.
- Do not move PAL-owned JO, mobilization, approval, receivable, collection, or audit state into another module.
- Do not create placeholder rows or view-only caches to make tables look updated.
- Do not bypass tenant filters, auth guards, CSRF checks, `palApprovalService`, or capability bus contracts.
- Do not relax AW delegation or attendance validation while tracing mobilization.
- Do not make receivables free-standing; receivables must remain JO/invoice-linked.
- Do not replace focused verification with broad full-suite runs.
- Do not commit generated artifacts, compiled template cache, or unrelated formatting churn.

## Implementation Report

Implemented:

- Added JO number and project context to PAL project, expense, purchase, sales, collections, fabrication due, cash advance, and mobilization entity loaders.
- Tenant-scoped PAL joins that were previously joining clients, project types, team leads, suppliers, categories, sales, or projects by id only.
- Updated JO-linked project selectors across expense, purchase, issuance, material return, sales, collections, quotations, cash advances, BOM, and fabrication payment forms to load and display persisted `job_order_number`.
- Updated dashboard active-project status logic and recent project display so JO rows use real workflow statuses and JO identity.
- Updated table view configs for projects, expenses, purchases, and mobilization so linked project or JO context is visible in list views.
- Updated centralized mobilization approval to stamp `approved_by` and `approved_at` on `pal_mobilization_requests`, matching the direct mobilization approval path.
- Hardened direct mobilization approval sync so a missing pending approval row fails the transaction instead of leaving mobilization and approval displays divergent.
- Fixed audit-trail entity alias resolution for approval audit rows that use `project` or `mobilization` entity types.
- Added `tests/pal_jo_view_wiring_test.php` to lock JO display wiring, tenant-scoped joins, dashboard status logic, selector JO exposure, mobilization approval stamping, direct approval sync failure behavior, and audit alias resolution.

Validation:

- Passed PHP syntax checks for all changed PHP files and the new test file.
- Passed `php tests/pal_jo_view_wiring_test.php`.
- Passed `PAL_TENANT_ID=502 php tests/pal_service_integration_test.php`.
- Passed `PAL_TENANT_ID=502 php tests/pal_mobilization_attendance_capability_test.php`.
- Passed `PAL_TENANT_ID=502 php tests/pal_attendance_bridge_test.php`.
- Passed `php tests/pal_mobilization_fix_verification_test.php`.
- Passed `php tests/kernel_auth_delegation_test.php`.
- Passed `git diff --check`.

Notes:

- Browser validation was not run in this implementation pass; the focused PHP/source checks cover the traced loader and wiring regressions.
- `storage/logs/error.log` still contains earlier exploratory command-line fatals from this session, including missing capability and `TenantResolver::setCurrent()` calls. No new failing test output was observed in the final log tail.
- Existing unrelated dirty/generated files were left untouched.

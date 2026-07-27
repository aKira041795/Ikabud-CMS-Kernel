# PAL UX Contract 1.0

**Status**: Baseline — Phase 1 Freeze
**Date**: 2026-07-12
**Baseline Commit**: `259dba3879167b70fee163747c9a96d03f4da043`
**Template Inventory**: 69 total `.disyl` files (54 pages, 6 components, 2 prints, 5 shell/auth, 2 auth pages)

---

## 1. Page Family Classification

Every PAL page template belongs to exactly one family.

### 1.1 Operational List (22 templates)

Templates that display a filterable, searchable list of entities with row actions.

| # | Template | Entity | Handler |
|---|---|---|---|
| 1 | `projects-list.disyl` | Job Orders | `palPageProjectList()` |
| 2 | `expenses-list.disyl` | Expenses | `palPageExpenseList()` |
| 3 | `purchases-list.disyl` | Purchases | `palPagePurchaseList()` |
| 4 | `inventory-list.disyl` | Inventory | `palPageInventoryList()` |
| 5 | `issuance-list.disyl` | Material Issuances | `palPageIssuanceList()` |
| 6 | `material-return-list.disyl` | Material Returns | `palPageMaterialReturnList()` |
| 7 | `movements-list.disyl` | Stock Movements | `palPageMovementList()` |
| 8 | `sales-list.disyl` | Sales Invoices | `palPageSalesList()` |
| 9 | `collections-list.disyl` | Collections | `palPageCollectionList()` |
| 10 | `quotations-list.disyl` | Quotations | `palPageQuotationList()` |
| 11 | `cash-advances-list.disyl` | Cash Advances | `palPageCashAdvanceList()` |
| 12 | `clients-list.disyl` | Clients | `palPageClientList()` |
| 13 | `suppliers-list.disyl` | Suppliers | `palPageSupplierList()` |
| 14 | `users-list.disyl` | Users | `palPageUserList()` |
| 15 | `mobilization-list.disyl` | Mobilization Requests | `palPageMobilizationList()` |
| 16 | `fabrication-allocations.disyl` | Fabrication Allocations | `palPageFabricationAllocation()` |
| 17 | `fabrication-dues.disyl` | Fabrication Dues | `palPageFabricationDues()` |
| 18 | `team-lead-ca-list.disyl` | TL Cash Advances | `palPageTeamLeadCashAdvances()` |
| 19 | `team-lead-mobilization-list.disyl` | TL Mobilization | `palPageTeamLeadMobilization()` |
| 20 | `team-lead-fabrication.disyl` | TL Fabrications | `palPageTeamLeadFabrication()` |
| 21 | `team-lead-attendance.disyl` | TL Attendance | `palPageTeamLeadAttendance()` |
| 22 | `bill-of-materials.disyl` | Bill of Materials | `palPageBillOfMaterials()` |

### 1.2 Detail Workspace (10 templates)

Templates that display a single entity with header, tabs, and contextual information.

| # | Template | Entity | Handler |
|---|---|---|---|
| 1 | `project-detail.disyl` | Job Order | `palPageProjectDetail()` |
| 2 | `expense-detail.disyl` | Expense | `palPageExpenseDetail()` |
| 3 | `purchase-detail.disyl` | Purchase | `palPagePurchaseDetail()` |
| 4 | `inventory-detail.disyl` | Inventory Item | `palPageInventoryDetail()` |
| 5 | `issuance-detail.disyl` | Material Issuance | `palPageIssuanceDetail()` |
| 6 | `sales-detail.disyl` | Sales Invoice | `palPageSalesDetail()` |
| 7 | `collections-detail.disyl` | Collection | `palPageCollectionDetail()` |
| 8 | `quotation-detail.disyl` | Quotation | `palPageQuotationDetail()` |
| 9 | `client-detail.disyl` | Client | `palPageClientDetail()` |
| 10 | `audit-trail.disyl` | Audit Log | `palPageAuditTrail()` |

### 1.3 Transaction Form (8 templates)

Templates that display a data-entry form for creating or editing a record.

| # | Template | Entity | Handler |
|---|---|---|---|
| 1 | `project-form.disyl` | Job Order | `palPageProjectForm()` |
| 2 | `expense-form.disyl` | Expense | `palPageExpenseForm()` |
| 3 | `purchase-form.disyl` | Purchase | `palPagePurchaseForm()` |
| 4 | `issuance-form.disyl` | Material Issuance | `palPageIssuanceForm()` |
| 5 | `material-return-form.disyl` | Material Return | `palPageMaterialReturnForm()` |
| 6 | `sales-form.disyl` | Sales Invoice | `palPageSalesForm()` |
| 7 | `collection-form.disyl` | Collection | `palPageCollectionForm()` |
| 8 | `quotation-form.disyl` | Quotation | `palPageQuotationForm()` |

### 1.4 Transaction Form — Team Lead (4 templates)

Team-lead-specific form templates, mobile-first.

| # | Template | Entity | Handler |
|---|---|---|---|
| 1 | `cash-advance-form.disyl` | Cash Advance (Admin) | `palPageCashAdvanceForm()` |
| 2 | `team-lead-ca-form.disyl` | Cash Advance (TL) | `palPageTeamLeadCashAdvanceForm()` |
| 3 | `team-lead-mobilization-form.disyl` | Mobilization Request (TL) | `palPageTeamLeadMobilizationForm()` |
| 4 | `fabrication-payment-form.disyl` | Fabrication Payment | `palPageFabricationPaymentForm()` |

### 1.5 Approval Review (2 templates)

| # | Template | Purpose | Handler |
|---|---|---|---|
| 1 | `approval-queue.disyl` | Pending approvals list + decide | `palPageApprovalQueue()` |
| 2 | *(approval decision is inline in queue)* | — | `palApiApprovalDecide()` |

### 1.6 Dashboard (3 templates)

| # | Template | Purpose | Handler |
|---|---|---|---|
| 1 | `dashboard.disyl` | Admin dashboard | `palPageDashboard()` |
| 2 | `team-lead-dashboard.disyl` | Team Lead dashboard | `palPageTeamLeadDashboard()` |
| 3 | `reports-center.disyl` | Reports dashboard | `palPageReportsCenter()` |

### 1.7 Settings/Admin (5 templates)

| # | Template | Purpose | Handler |
|---|---|---|---|
| 1 | `settings-overview.disyl` | Settings overview | `palPageSettingsOverview()` |
| 2 | `settings-categories.disyl` | Category settings | `palPageSettingsCategories()` |
| 3 | `settings-materials.disyl` | Materials settings | `palPageSettingsMaterials()` |
| 4 | `settings-suppliers.disyl` | Supplier settings | `palPageSettingsSuppliers()` |
| 5 | `client-form.disyl` | Client form | `palPageClientForm()` |

### 1.8 Print Templates (2 templates)

| # | Template | Purpose |
|---|---|---|
| 1 | `prints/invoice-print.disyl` | Invoice print layout |
| 2 | `prints/quotation-print.disyl` | Quotation print layout |

### 1.9 Shell & Auth (7 templates)

| Template | Purpose |
|---|---|
| `shell.disyl` | Admin application shell |
| `team-lead-shell.disyl` | Team Lead mobile-first shell |
| `login.disyl` | Admin login page |
| `forgot-password.disyl` | Password reset request |
| `reset-password.disyl` | Password reset form |
| `team-lead-login.disyl` | Team Lead login page |
| `team-lead-otp-verify.disyl` | Team Lead OTP verification |

---

## 2. Reusable Components (6 DiSyL components)

| Component | File | Parameters |
|---|---|---|
| `pal_page_header` | `components/pal_page_header.disyl` | `_ph_title`, `_ph_subtitle?`, `_ph_actions?` |
| `pal_detail_header` | `components/pal_detail_header.disyl` | `_dh_title`, `_dh_subtitle?`, `_dh_status?`, `_dh_amount?`, `_dh_collected?`, `_dh_outstanding?`, `_dh_target_date?`, `_dh_actions?` |
| `pal_summary_card` | `components/pal_summary_card.disyl` | `_sc_label`, `_sc_value`, `_sc_icon?`, `_sc_color?` |
| `pal_money` | `components/pal_money.disyl` | `_m_amount`, `_m_label?`, `_m_class?` |
| `pal_status_badge` | `components/pal_status_badge.disyl` | `_sb_status` |
| `pal_empty_state` | `components/pal_empty_state.disyl` | `_es_icon?`, `_es_title`, `_es_desc?`, `_es_action?` |

---

## 3. Route Map

### 3.1 GET Routes (96)

| Area | Routes |
|---|---|
| Auth | `/project-audit-ledger/login`, `/forgot-password`, `/reset-password`, `/team-lead/login`, `/team-lead/verify` |
| Dashboard | `/admin/project-audit-ledger` |
| Projects | `/admin/project-audit-ledger/projects`, `/create`, `/{id}`, `/{id}/edit`, API endpoints |
| Clients | `/admin/project-audit-ledger/clients`, `/create`, `/{id}`, `/{id}/edit` |
| Suppliers | `/admin/project-audit-ledger/suppliers`, `/create`, `/{id}/edit` |
| Expenses | `/admin/project-audit-ledger/expenses`, `/create`, `/{id}`, `/{id}/edit` |
| Purchases | `/admin/project-audit-ledger/purchases`, `/create`, `/{id}`, `/{id}/edit` |
| Inventory | `/admin/project-audit-ledger/inventory`, `/{id}`, `/movements` |
| Issuances | `/admin/project-audit-ledger/issuances`, `/create`, `/{id}`, `/{id}/edit`, `/returns`, `/returns/create` |
| Sales | `/admin/project-audit-ledger/sales`, `/create`, `/{id}`, `/{id}/edit`, `/{id}/print` |
| Collections | `/admin/project-audit-ledger/collections`, `/create`, `/{id}` |
| Cash Advances | `/admin/project-audit-ledger/cash-advances`, `/create` |
| Quotations | `/admin/project-audit-ledger/quotations`, `/create`, `/{id}`, `/{id}/edit` |
| Approvals | `/admin/project-audit-ledger/approvals` |
| Mobilization | `/admin/project-audit-ledger/mobilization` |
| Fabrication | `/admin/project-audit-ledger/fabrication/allocations`, `/dues/{id}`, `/payment` |
| BOM | `/admin/project-audit-ledger/bom` |
| Reports | `/admin/project-audit-ledger/reports` |
| Audit | `/admin/project-audit-ledger/audit-trail` |
| Settings | `/admin/project-audit-ledger/settings`, `/categories`, `/materials`, `/suppliers` |
| Users | `/admin/project-audit-ledger/users` |
| Team Lead | `/admin/project-audit-ledger/team-lead`, `/fabrication`, `/cash-advances`, `/create`, `/mobilization`, `/create`, `/attendance` |
| Attachments | `/api/v1/project-audit-ledger/attachments/{id}/download` |

### 3.2 POST Routes (120)

API endpoints for all CRUD operations, approvals, status changes, email, settings, autocomplete, quick-create, attachments, and users.

---

## 4. Service Layer (18 services)

| Service | Responsibility |
|---|---|
| `ApprovalService` | Multi-entity approval state machine |
| `AttachmentService` | Upload, store, download, delete attachments |
| `CashAdvanceService` | Cash advance CRUD, approve, settle, void |
| `ExpenseService` | Expense CRUD, submit, approve |
| `FabricationService` | Fabrication allocation, weekly dues, payments |
| `InventoryService` | Material CRUD, stock adjustments, movements |
| `InvoiceTotalCalculator` | Invoice line-item total computation |
| `JobOrderWorkflow` | JO state machine: draft→pending→approved→started→ongoing→completed→closed |
| `MaterialIssuanceService` | Issuance CRUD, submit, return |
| `MaterialReturnService` | Return processing |
| `PaymentService` | Payment allocation, collection |
| `ProjectCompletionCoordinator` | JO completion orchestration |
| `ProjectCostService` | Cost aggregation across expenses, purchases, fabrication |
| `ProjectService` | JO CRUD, status transitions |
| `PurchaseService` | Purchase order CRUD, submit |
| `QuotationService` | Quotation CRUD, convert to JO, status |
| `ReceivableService` | Receivable tracking, payment allocation |
| `SalesService` | Sales invoice CRUD, collections, email |

---

## 5. JavaScript Assets

| File | Purpose |
|---|---|
| `public/assets/pal/pal-core.js` | Shell, sidebar, drawer, toast, autocomplete, quick-create, user mgmt |
| `public/assets/pal/pal-forms.js` | Form submit, validation, resubmission, CSRF handling |
| `public/assets/pal/pal-routes.js` | Route constants for AJAX calls |
| `public/assets/pal/pal-ui.css` | PAL-specific Tailwind extensions |

---

## 6. Entity View Contracts (15)

Registered under `modules/project-audit-ledger/helpers/views/`:

| Entity View | For Entity |
|---|---|
| `pal_project.disyl` | Job Orders |
| `pal_expense.disyl` | Expenses |
| `pal_purchase.disyl` | Purchases |
| `pal_material.disyl` | Inventory Materials |
| `pal_issuance.disyl` | Material Issuances |
| `pal_material_return.disyl` | Material Returns |
| `pal_sale.disyl` | Sales Invoices |
| `pal_collection.disyl` | Collections |
| `pal_quotation.disyl` | Quotations |
| `pal_quotation_item.disyl` | Quotation Line Items |
| `pal_cash_advance.disyl` | Cash Advances |
| `pal_client.disyl` | Clients |
| `pal_supplier.disyl` | Suppliers |
| `pal_mobilization.disyl` | Mobilization Requests |
| `pal_audit_log.disyl` | Audit Logs |

---

## 7. Shell Architecture

### Admin Shell (`shell.disyl`, 194 lines)
- Dark sidebar (slate-900) with collapsible sections
- Mobile sidebar drawer (transform translate)
- Alpine.js for section collapse state
- Tailwind CDN + HTMX + Alpine
- Toast notification container
- References: `pal-core.js`, `pal-forms.js`, `pal-routes.js`, `pal-ui.css`

### Team Lead Shell (`team-lead-shell.disyl`, 86 lines)
- Dark sidebar with hardcoded team lead navigation
- Bottom navigation bar (5 items: Home, Fabrication, Attendance, Cash, More)
- Mobile-first design
- OTP-based authentication
- Same asset dependencies as admin shell

---

## 8. Key Behavioral Contracts

1. **CSRF**: All POST requests include CSRF token; forms refresh token on validation error
2. **Auth**: Role-based access (admin, supervisor, encoder); Team Lead uses email OTP
3. **Status Transitions**: Explicit action buttons, not dropdown editing
4. **Approvals**: Multi-entity queue with approve/reject; rejection requires reason
5. **Attachments**: Upload on detail pages; download via capability-gated API
6. **Money**: All amounts displayed as `₱X,XXX.XX` via `pal_money` component
7. **Tables**: Responsive card-stack behavior below 768px
8. **Toast**: Success/error notifications via accessible toast container

---

## 9. Breakpoint Behavior

| Breakpoint | Shell Behavior | Table Behavior | Form Behavior |
|---|---|---|---|
| Desktop (≥1024px) | Fixed sidebar, full navigation | Standard table with all columns | Multi-column layout |
| Tablet (768-1023px) | Overlay drawer, visible toggle | Horizontal scroll | 2-column layout |
| Phone (<768px) | Overlay drawer, bottom nav (TL) | Card stack (data-label) | Single column |

---

## 10. UI Consistency Contract (Phase 2 — 2026-07-27)

### 10.1 Title Ownership

- **Every page renders exactly one semantic `<h1>`**.
- The shell (`shell.disyl`, `team-lead-shell.disyl`) does **not** render its own `<h1>{page_title}`.
- Page templates use `pal_page_header` (list/dashboard/settings) or `pal_detail_header` (detail pages) to produce the page-level title.
- Auth pages (standalone HTML) and print pages use their own `<h1>` inside their full HTML structure.

### 10.2 Shell Content Wrapper

Both shells wrap `{page_body|raw}` in `<div class="pal-page">`:
- `.pal-page` — max-width 1280px, centered, 1.5rem gap between sections
- `.pal-page--wide` — 100% width for full-bleed pages
- `.pal-page--print` — 900px max for print previews

### 10.3 CSS Architecture

| Layer | Location | Contents |
|---|---|---|
| Workbench profile | `storage/application-profiles/ark-workbench/assets/` | All generic reusable primitives |
| Public mirror | `public/assets/workbench/` | Byte-identical copy of profile assets |
| PAL-specific | `public/assets/pal/pal-ui.css` | PAL domain composition only |

**PAL-specific CSS classes** (in `pal-ui.css`):
- `.pal-page`, `.pal-page--wide`, `.pal-page--print` — page layout wrappers
- `.pal-page-header`, `.pal-page-header__row`, `.pal-page-header__actions` — header composition
- `.pal-metric-grid` — responsive auto-fill metric grid
- `.pal-financial-grid` — 6-column financial health grid
- `.pal-text-positive`, `.pal-text-negative`, `.pal-text-warning`, `.pal-text-muted` — domain tone colors
- `.pal-progress-expenses`, `.pal-progress-fabrication`, `.pal-progress-profit` — progress bar segments
- `.pal-bom-summary`, `.pal-bom-summary__stat`, `.pal-bom-summary__label`, `.pal-bom-summary__value` — BOM footer
- `.pal-modal-overlay`, `.pal-modal`, `.pal-modal__header`, `.pal-modal__title`, `.pal-modal__close`, `.pal-modal__body`, `.pal-modal__footer` — shared dialog
- `.wb-panel__header--stacked`, `.wb-toolbar--flush` — Workbench extension classes

### 10.4 Forbidden Patterns

| Pattern | Replace With |
|---|---|
| `style="color:var(--wb-tone-*)` | `.pal-text-positive`, `.pal-text-negative`, `.pal-text-warning` |
| `text-red-700`, `text-green-700`, `text-orange-700` | `.pal-text-negative`, `.pal-text-positive`, PAL-specific class |
| `bg-gray-800 text-white` on table headers | Workbench `bg-gray-50 border-b` header pattern |
| `fixed inset-0 bg-black/50 z-50` for modals | `.pal-modal-overlay` + `.pal-modal` |
| `wb-panel__header` with `style="flex-direction:column"` | `.wb-panel__header--stacked` |
| Tab bar with `style="margin:0 calc(...)"` | `.wb-toolbar--flush` |
| Hard-coded `<h1>{page_title}` in shells | Page template renders header |
| `<style>` blocks in page templates | Add to `pal-ui.css` or Workbench profile |
| `<table>` without `wb-table` class | Add `wb-table wb-table--sticky` |
| `<table>` without `overflow-x-auto` wrapper | Wrap in `<div class="overflow-x-auto">` |
| `grid grid-cols-2 md:grid-cols-6 gap-6` for financial grid | `.pal-financial-grid` |
| `grid-cols-2 gap-3` / `grid-cols-12` for forms | `wb-form-grid wb-form-grid--2` |

### 10.5 Contract Tests

Enforced by `tests/workbench_profile_contract_test.php` (PAL UI consistency section, 11 checks):

| Check | What it validates |
|---|---|
| Shell title | No `<h1>{page_title}` in admin or TL shells |
| Shell wrapper | `pal-page` container in both shells |
| pal-ui.css | File exists, referenced by helpers, contains PAL rules |
| TL CA list table | Has valid `<table>` tag (was missing) |
| BOM header | No `bg-gray-800 text-white` (uses Workbench classes) |
| Fabrication modal | Uses `pal-modal-overlay` with `role="dialog"` |
| TL Fabrication colors | Uses `pal-text-positive`/`pal-text-negative` instead of raw Tailwind |
| `<style>` blocks | None in shell-delegated page templates (exempt: auth, email, shell, prints) |
| Entity list presets | All use Workbench preset (not Tailwind) |
| Form controls | All standard controls use Workbench primitives |
| Asset parity | SHA-256 match between profile source and public mirror |

### 10.6 Future Work

- Migrate Team Lead shell to use Workbench shell delegation for unified nav consistency
- Add keyboard focus restoration and Escape key handler to fabrication dues modal
- Add Playwright multi-viewport contract tests for each page family
- Migrate remaining inline form grids (`grid-cols-12` line items) to `wb-form-grid`
- Add mobile `data-label` values to entity-list powered tables
- Consolidate all PAL navigation state into shell context
- Migrate `mobilization-detail.disyl` inline `<script>` to `pal-core.js`
- Surface detail pages (issuance-detail, inventory-detail) need `pal_detail_header`
- Add empty/loading/error state components to list page templates

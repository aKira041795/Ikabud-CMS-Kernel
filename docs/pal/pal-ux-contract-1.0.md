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

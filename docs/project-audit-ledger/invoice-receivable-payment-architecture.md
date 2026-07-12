# PAL Invoice–Receivable–Payment Architecture

> **Module**: `project-audit-ledger`  
> **Last audit**: 2026-07-12 (review cycle approved)  
> **HEAD**: see `git log --oneline -1` for current commit

---

## 1. Amount Semantics (Invariant)

All financial calculations in PAL use one canonical relationship:

```
invoice_total = gross_amount
              + installation_charge
              + mobilization_charge
              + other_charges
              - discount_amount
              + tax_amount
```

### 1.1 Column Roles

| Field | Table | Semantics |
|---|---|---|
| `contract_amount` | `pal_projects` | Final agreed customer total. In **items** mode (`jo_type = 'items'`), this value already includes installation, mobilization, and other charges. |
| `gross_amount` | `pal_sales` | Base subtotal before separate charges. For auto-generated invoices from project completion, `gross_amount = contract_amount − charges` to avoid double-counting. |
| `net_amount` | `pal_sales` | DB-generated column — **legacy**. Migration `008_pal_sale_items.sql` redefines it as `gross_amount − discount_amount + tax_amount + installation_charge + mobilization_charge + other_charges`. |
| `invoice_total` | *(computed in PHP)* | Runtime canonical total via `palInvoiceTotalCalculator::total()`. Used for outstanding, display, email, print, receivable creation, and status checks. |

### 1.2 Single Source of Truth

`palInvoiceTotalCalculator` (`services/InvoiceTotalCalculator.php`) provides two static methods:

- **`total(array $saleRow): float`** — matches the DB `net_amount` formula from migration 008. Used everywhere: `SalesService::get()`, `PaymentService::updateSaleCollectionStatus()`, `SalesService::updateSaleCollectionStatus()`, receivable backfill, and email.
- **`grossFromContract(float $contractAmount, array $projectRow): float`** — subtracts charges from `contract_amount` so auto-generated invoices don't double-count. Used by `ProjectCompletionCoordinator::createInvoiceFromProject()`.

---

## 2. Service Boundaries

```
┌──────────────────────────────────────────────────────────┐
│                    SalesService                           │
│  Invoice CRUD, sale items, client snapshot, receivable   │
│  sync on edit                                            │
├──────────────────────────────────────────────────────────┤
│                   PaymentService                          │
│  Record payment (pending), approve (lock + allocate),    │
│  reject. Derives project/client from invoice.            │
├──────────────────────────────────────────────────────────┤
│                 ReceivableService                         │
│  Create from invoice, allocate payments, mark overdue,   │
│  void.                                                   │
├──────────────────────────────────────────────────────────┤
│            ProjectCompletionCoordinator                   │
│  Orchestrates completion: validate, invoice, items,      │
│  receivable, audit, events.                              │
├──────────────────────────────────────────────────────────┤
│              AttachmentService                            │
│  Upload to private storage, delete, reassign.            │
│  Files never stored in public web root.                  │
└──────────────────────────────────────────────────────────┘
```

### 2.1 Key Design Rules

- **Services own domain auditing.** Audits are written inside the service method, not by the caller. Handlers only own request parsing, CSRF, authorization, and HTTP response formatting.
- **Project/Client IDs derived from invoice.** `PaymentService::loadAndValidateSale()` queries the sale to get the real `project_id` and `client_id`. Submitted form data for these fields is ignored.
- **Approved payments block amount edits.** `SalesService::update()` checks for approved allocations before allowing any change to gross, discount, tax, charges, or items. If payments exist, the edit is rejected.
- **Receivable is synced on edit.** When an amount field changes and no approved payments exist, `syncReceivableAmount()` updates the receivable to match the new `invoice_total`.

---

## 3. Payment Lifecycle

```
Invoice created (SalesService::create())
  │
  ▼
Receivable created (ReceivableService::createFromInvoice())
  │  amount = invoice_total
  ▼
Payment recorded (PaymentService::record())
  │  project_id, client_id derived from sale
  │  status = 'pending'
  │  Amount validated (> 0)
  │  Sale validated (not cancelled, not voided, not paid)
  ▼
Payment approved (PaymentService::approve())
  │
  ├─ Lock all receivables (SELECT FOR UPDATE)
  ├─ Calculate total outstanding from locked set
  ├─ Reject overpayment (amount > total outstanding)
  ├─ Allocate to receivables (oldest due date first)
  ├─ Update sale status (paid / partially_paid / overdue)
  └─ Audit: pal.payment.approved
  │
  ▼
Optional: recordAndApprove() — same flow in one transaction
```

### 3.1 Invoice Status Transitions

| Condition | Status |
|---|---|
| Total collected ≥ `invoice_total` | `paid` |
| Total collected > 0 | `partially_paid` |
| Past due date, not paid | `overdue` |
| `updateSaleCollectionStatus()` checks overdue after due date | |

---

## 4. Invoice Total in Presentation Paths

| Path | Source | Notes |
|---|---|---|
| **Sales detail page** | `sale.invoice_total` (fallback: `net_amount`) | Label: "Invoice Total". Down-payment remaining uses `((invoice_total) - (down_payment))` with explicit grouping. |
| **Print invoice** | `sale.invoice_total` (fallback: `net_amount`) | Label: "INVOICE TOTAL". |
| **Email** | `$sale['invoice_total']` (fallback: `net_amount`) | Computed from `SalesService::get()` which calls `InvoiceTotalCalculator::total()`. |
| **Collection form** | SQL computed column: `(gross + installation + mobilization + other - discount + tax) AS invoice_total` | Raw query, not through service. Formula matches calculator. |
| **Collection detail** | SQL computed column: same formula as `sale_invoice_total` | Raw query. |
| **Receivable creation** | `InvoiceTotalCalculator::total()` via `SalesService::create()` | Manual invoice creation. |
| **Receivable backfill** | `InvoiceTotalCalculator::total()` via `palApiCollectionsGenerate()` | Backfill handler. |
| **Project completion** | `InvoiceTotalCalculator::total()` via `ProjectCompletionCoordinator` | Auto-created invoice. |
| **Outstanding display** | `invoice_total − total_collected` via `SalesService::get()` | |

---

## 5. Attachment Security

### 5.1 Storage

All PAL attachments are stored outside the public web root:

```
storage/private/pal/{tenant_id}/{entity_type}/{entity_id}/{random_filename}
```

- Upload path: `STORAGE_PATH . '/private/pal/' . $tenantId . '/' . $entityType . '/' . $entityId`
- Served only through: `/admin/project-audit-ledger/attachments/{id}/download`
- The download handler (`palPageAttachmentDownload`) performs tenant-scoped DB lookup before serving.

### 5.2 URL Exposure

| Surface | Before (public) | After (private) |
|---|---|---|
| Image gallery (PO) | `/$file_path` direct URL | `/admin/.../attachments/{id}/download` |
| Attachment list | `/$file_path` direct URL | `/admin/.../attachments/{id}/download` |
| JSON API list | Returns `file_path` | Returns `download_url`, `file_path` unset |

### 5.3 Allowlist

Defined in `AttachmentService::ALLOWED_MIMES`:

- Images: `image/jpeg`, `image/png`, `image/gif`, `image/webp`
- Documents: `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- Spreadsheets: `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- Data: `text/plain`, `text/csv`
- Archives: `application/zip`
- Max file size: 10 MB

---

## 6. Event Contract

| Event | Payload | Emitted by |
|---|---|---|
| `pal.sale.created` | `{ sale_id, amount (invoice_total), gross_amount }` | `SalesService::create()`, `ProjectCompletionCoordinator` |
| `pal.collection.recorded` | `{ collection_id, sales_id, amount }` | `SalesService::recordCollection()` (deprecated) |
| `pal.payment.recorded` | Audited only (no event) | `PaymentService::record()` |
| `pal.payment.approved` | Audited only (no event) | `PaymentService::approveWithinTransaction()` |
| `pal.payment.rejected` | Audited only (no event) | `PaymentService::reject()` |
| `pal.project.completed` | `{ project_id, auto_invoiced, contract_amount, sale_id }` | `ProjectCompletionCoordinator` |
| `pal.receivable.created` | Audited only (no event) | `ReceivableService::createFromInvoice()` |
| `pal.receivable.payment_allocated` | Audited only (no event) | `ReceivableService::allocatePaymentWithinTransaction()` |

> **Rule**: Events are emitted **after** transaction commit (deferred in `ProjectCompletionCoordinator`; immediate in `SalesService::create()`). Audit entries are written **within** the transaction (safe — app.log is append-only).

---

## 7. Concurrency Guards

| Scenario | Protection |
|---|---|
| Two simultaneous payment approvals | `SELECT ... FOR UPDATE` on all receivables before calculating outstanding — prevents stale-total race. |
| Duplicate invoice number | Unique constraint `uq_pal_sales_number` on `(tenant_id, sales_number)`. `createInvoiceFromProject()` retries with suffix on 23000. |
| Duplicate receivable number | Unique constraint `uq_pal_recv_number` on `(tenant_id, receivable_number)`. |
| Invoice edit after payment | `hasApprovedPayments()` check in `SalesService::update()` — rejects with `InvalidArgumentException`. |
| Overpayment | Rejected in `approveWithinTransaction()` — payment amount compared against locked receivable total. |

---

## 8. Known Future Improvements

- **Business-number sequences**: Invoices, receivables, and payments use `COUNT(*) + 1` for number generation. Unique constraints protect persistence, but concurrent requests can collide. A tenant-scoped sequence service is the proper long-term design.
- **Attachment storage migration**: Existing files under `PUBLIC_PATH/uploads/pal/` must be moved to `STORAGE_PATH/private/pal/` and their `file_path` values updated in `pal_attachments`.

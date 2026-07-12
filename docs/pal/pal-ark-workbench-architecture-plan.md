# PAL → ARK Workbench Architecture Plan

**Status**: Draft for Review
**Date**: 2026-07-12
**Version**: 1.0
**Reviewers**: Senior Architect, PAL Module Lead

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current State Assessment](#2-current-state-assessment)
3. [Target Architecture](#3-target-architecture)
4. [ARK Workbench Definition](#4-ark-workbench-definition)
5. [Component Extraction Catalog](#5-component-extraction-catalog)
6. [PAL View Model Layer](#6-pal-view-model-layer)
7. [Page Family Standardization](#7-page-family-standardization)
8. [Team Lead Experience](#8-team-lead-experience)
9. [Theme Studio Policy Boundaries](#9-theme-studio-policy-boundaries)
10. [Migration Phases](#10-migration-phases)
11. [Conformance Testing Strategy](#11-conformance-testing-strategy)
12. [Risks and Dependencies](#12-risks-and-dependencies)
13. [Success Criteria](#13-success-criteria)

---

## 1. Executive Summary

### 1.1 Directive

**Stability first, extraction second.** Do not redesign PAL. Its current UI becomes the working reference from which we extract a reusable **ARK Workbench** application profile.

### 1.2 PAL's Three Roles

| Role | Description |
|---|---|
| **Production business application** | Reliable project costing, billing, inventory, payments, and audits |
| **Reference UX implementation** | The clearest example of how a Kernel OS operational application should behave |
| **Birthplace of ARK Workbench** | The proven source of reusable application-shell and interaction contracts |

### 1.3 Guiding Principle

> Keep PAL's workflows specific. Keep PAL's view models explicit. Extract proven visual behavior into ARK Workbench. Let Theme Studio configure only safe visual policy. Use PAL to certify ARK's application profile.

---

## 2. Current State Assessment

### 2.1 PAL Module (as of 2026-07-12)

| Metric | Count |
|---|---|
| Service classes | 18 |
| Handler files | 22 (3700+ lines total) |
| Page templates (.disyl) | 54 |
| Reusable components | 6 |
| Print templates | 2 |
| Shells | 2 (admin + team-lead) |
| Entity-view configs | 15 |
| SQL migrations | 19 |
| Capabilities exposed | 62 |
| Database tables | 39 owned + 6 read |
| GET routes | 96 |
| POST routes | 120 |

### 2.2 Current PAL Components

PAL already has 6 reusable DiSyL components under `templates/project-audit-ledger/components/`:

| Component | Purpose | Extraction Candidate |
|---|---|---|
| `pal_page_header` | Page title, subtitle, action slot | Yes → `workbench:page_header` |
| `pal_detail_header` | Entity title, status, amounts, actions | Yes → `workbench:detail_header` |
| `pal_summary_card` | Metric card (label, value, icon, color) | Yes → `workbench:summary_card` |
| `pal_money` | Formatted currency (₱ amount) | Yes → `workbench:money` |
| `pal_status_badge` | Colored status pill | Yes → `workbench:status_badge` |
| `pal_empty_state` | Empty placeholder (icon, title, desc, action) | Yes → `workbench:empty_state` |

### 2.3 Current Architecture State

#### Historical Issues Resolved (post UI/UX Review 2026-07-12)

These problems from the initial review have been addressed in the current PAL commit:

1. **Responsive shells** — both admin and team-lead shells now support mobile drawer + bottom navigation
2. **Extracted PAL assets** — `pal-core.js`, `pal-forms.js`, `pal-routes.js` separated from inline scripts
3. **Workflow action buttons** — status changes now use explicit action buttons, not raw dropdown editing
4. **Shared accessible dialog** — modal pattern with focus management extracted
5. **Responsive table support** — tables use mobile card-stack behavior
6. **Safe autocomplete** — combobox pattern with keyboard navigation
7. **Form resubmission handling** — CSRF token refresh on validation error

#### Remaining Architectural Debt

These are the problems ARK Workbench is designed to resolve:

1. **PAL-specific shell implementation** — shell layout and navigation are tightly coupled to PAL routes and section names
2. **PAL-specific component namespace** — reusable patterns (`pal_page_header`, etc.) carry PAL naming, preventing cross-module reuse
3. **No application-profile registry** — no mechanism for a module to declare visual infrastructure dependencies
4. **No typed view-model layer** — handlers pass raw database arrays to templates
5. **Route and presentation coupling** — hardcoded entity-to-URL maps in `approval-queue.disyl` and `audit-trail.disyl`
6. **No reuse outside PAL** — identical patterns in Attendance, WMS, Guidance are independently implemented
7. **Multiple interaction paradigms** — DiSyL + HTMX + Alpine + vanilla JS coexist without a governed convention

### 2.4 ARK Theme Current State

ARK v3.0.0 (`storage/cms-themes/ark/`) is a **public website theme** — not an application workbench:

| Asset | Purpose |
|---|---|
| `theme.manifest.json` | Theme identity, customizer scope, region templates |
| `tokens.json` | 180+ design tokens (colors, typography, spacing) |
| `safety-policy.json` | Raw output allowlist, blocked patterns |
| `customizer.schema.json` | Header, footer, sidebar, colors, typography sections |
| `renderer-registry.json` | 27 renderer declarations |
| `entity-view-map.json` | 11 entity types → presentation views |
| `ArkCustomizerProvider.php` | Validation + context transformation |
| `block-registry.json` | Page builder block definitions |

ARK currently serves **public-facing CMS sites** (marketing, ecommerce, content). It has no application-shell concept — no sidebar navigation, no bottom nav, no responsive data tables, no approval panels, no detail headers.

### 2.5 Key Gap

There is **no "workbench" concept** anywhere in the codebase. ARK is a content theme. PAL is a standalone application with its own shell. The bridge between them — ARK Workbench — must be created.

This requires minimal kernel-level platform contracts (see §12.3): an `ApplicationProfileProvider` interface, an `ApplicationProfileRegistry`, and an `ApplicationProfileResolver`. These are generic — the kernel must not know PAL or Workbench details.

---

## 3. Target Architecture

### 3.1 Layered Stack

```
┌─────────────────────────────────────────────┐
│              PAL Domain Services             │
│  ProjectService, ExpenseService, etc.        │
├─────────────────────────────────────────────┤
│          PAL View Models & Presenters        │
│  PalInvoiceViewModel, PalMoneyPresenter      │
├─────────────────────────────────────────────┤
│       ARK Workbench Application Profile      │
│  workbench:app_shell, workbench:responsive_table, etc.   │
├─────────────────────────────────────────────┤
│              DiSyL Rendering                 │
│  Templates, components, entity views         │
├─────────────────────────────────────────────┤
│       Surfaces: Desktop, Mobile, Print,      │
│                PDF, Email                    │
└─────────────────────────────────────────────┘
```

### 3.2 Dependency Direction

```
PAL ──depends on──▶ ARK Workbench ──depends on──▶ DiSyL + Kernel OS
PAL ──does NOT depend on──▶ Public CMS Theme (ARK Corporate, ARK Education)
```

PAL declares an application profile, not a public theme dependency:

```json
{
  "application_profile": {
    "id": "ark.workbench",
    "version": "^0.1"
  }
}
```

(Version becomes `^1.0` only when the profile reaches its stable 1.0 contract.)

### 3.3 Profile vs Theme Distinction

| | Public CMS Theme | ARK Workbench Profile |
|---|---|---|
| **Purpose** | Marketing, content, ecommerce storefronts | Operational business applications |
| **Shell** | Header/footer/sidebar regions | App shell, drawer, bottom nav |
| **Surfaces** | Public, print, email | Desktop, mobile, print, PDF, email |
| **Components** | Cards, heroes, stats, sections | Tables, forms, dialogs, approvals, timelines |
| **Customizer scope** | Brand, layout, typography, colors | Brand, density, radius (locked: meanings, emphasis, visibility) |
| **Page builder** | Primary authoring tool | Excluded from operational screens |

### 3.4 Where ARK Workbench Lives

**Location:** `storage/application-profiles/ark-workbench/`

This is a **separate profile domain**, not a subdirectory of `cms-themes`. Putting Workbench inside `cms-themes` would:
- Imply CMS owns application profiles
- Risk theme activation semantics leaking into operational applications
- Prevent a Kernel installation without CMS from using Workbench
- Cause developers to treat it as a selectable public theme

**Rationale for `storage/application-profiles/`:**
- Application profiles are a distinct concept from public CMS themes
- CMS is not required to use Workbench
- Theme Studio discovers profiles through a registry, not filesystem conventions
- Clear separation between "public website theme" and "operational application profile"
- Paves the way for future non-CMS application profiles

The conceptual relationship:
```
ARK Specification
├── Public Theme Profile (storage/cms-themes/ark/)
└── Workbench Application Profile (storage/application-profiles/ark-workbench/)
```

**New kernel-level contracts required:**

```php
interface ApplicationProfileProvider
{
    public function id(): string;
    public function version(): string;
    public function componentNamespaces(): array;
    public function layouts(): array;
    public function assets(): array;
    public function designPolicy(): array;
}
```

And services:
- `ApplicationProfileRegistry` — discovers and registers profiles
- `ApplicationProfileResolver` — resolves active profile for a module
- `ApplicationProfileValidator` — validates profile manifests and contracts

These are generic. Kernel must not know PAL or Workbench details.

### 3.5 ARK Workbench Manifest

```json
{
    "name": "ark-workbench",
    "version": "0.1.0",
    "label": "ARK Workbench",
    "description": "Application workbench profile for Kernel OS operational modules. Derived from PAL reference implementation.",
    "manifest_file": "profile.manifest.json",
    "kernel_os_compat": "6.1.0",
    "disyl_compat": "4.7.0",
    "supported_surfaces": [
        "desktop",
        "mobile",
        "tablet",
        "print",
        "pdf",
        "email"
    ],
    "contracts": {
        "components": "1.0",
        "tokens": "1.0",
        "design_policy": "1.0",
        "shell": "1.0",
        "assets": "1.0"
    },
    "assets": {
        "core": {
            "styles": ["assets/workbench.css"],
            "scripts": ["assets/workbench-core.js"]
        },
        "components": {
            "workbench:dialog": ["assets/workbench-dialog.js"],
            "workbench:combobox": ["assets/workbench-combobox.js"],
            "workbench:responsive_table": ["assets/workbench-table.js"]
        }
    },
    "customizer": {
        "owns": true,
        "schema": "customizer.schema.json",
        "sections": [
            "brand",
            "sidebar",
            "radius",
            "typography",
            "table"
        ]
    },
    "design_policy": {
        "configurable": [
            "brand.primary",
            "font.interface",
            "sidebar.variant",
            "radius"
        ],
        "locked": [
            "status.meaning",
            "danger.emphasis",
            "approval.context",
            "financial.total.visibility"
        ]
    }
}
```

**Contract versioning** is separate from the profile package version. A module declares its dependency with contract-level constraints:

```json
{
    "application_profile": {
        "id": "ark.workbench",
        "version": "^1.0",
        "required_components": {
            "workbench:dialog": "^1.0",
            "workbench:responsive_table": "^1.0"
        }
    }
}
```

**Density and table density** are per-user preferences, not theme settings (see §9). They are removed from the customizer sections. Theme Studio may define tenant defaults (`default_density`, `default_table_density`, `default_sidebar_state`) but individual user preferences are stored separately in `user_preferences`.

---

## 4. ARK Workbench Definition

### 4.1 Component Catalog

Components extracted from PAL's proven patterns, generalized for any operational module:

#### Shell & Navigation

| Component | Source in PAL | Purpose |
|---|---|---|
| `workbench:app_shell` | `shell.disyl` (desktop) | Application chrome: sidebar, header, content area |
| `workbench:mobile_drawer` | `shell.disyl` mobile sidebar | Off-canvas navigation drawer |
| `workbench:bottom_navigation` | `team-lead-shell.disyl` bottom nav | 4-5 item bottom tab bar for mobile |
| `workbench:sidebar_section` | Shell sidebar sections | Collapsible nav section with active state |

#### Page Structure

| Component | Source in PAL | Purpose |
|---|---|---|
| `workbench:page_header` | `pal_page_header` | Title, subtitle, breadcrumb, action slot |
| `workbench:detail_header` | `pal_detail_header` | Entity identity, status, amounts, primary actions |

#### Data Display

| Component | Source in PAL | Purpose |
|---|---|---|
| `workbench:summary_card` | `pal_summary_card` | Single metric: label, value, icon, trend |
| `workbench:money` | `pal_money` | Currency display: alignment, emphasis, negative tone, typography |
| `workbench:status_badge` | `pal_status_badge` | Semantic tone badge (neutral/info/warning/success/danger) |
| `workbench:progress` | (new) | Progress bar with label and percentage |
| `workbench:responsive_table` | (new, from PAL table patterns) | Semantic table → card switch at breakpoint |
| `workbench:empty_state` | `pal_empty_state` | Empty placeholder with CTA |

#### Forms

| Component | Source in PAL | Purpose |
|---|---|---|
| `workbench:form_section` | Job Order form sections | Grouped form fields with header and description |
| `workbench:validation_summary` | (new) | Accessible error summary with focus management |
| `workbench:combobox` | PAL autocomplete patterns | Searchable select with keyboard navigation |

#### Interaction

| Component | Source in PAL | Purpose |
|---|---|---|
| `workbench:dialog` | PAL modal patterns | Accessible modal with focus trap |
| `workbench:activity_timeline` | Audit trail patterns | Chronological event feed |
| `workbench:approval_panel` | Approval queue | Request summary, impact, documents, decide |

**Namespace convention:**

| Prefix | Scope | Example |
|---|---|---|
| `ark:*` | Public/reference ARK theme components | `ark:card_grid`, `ark:hero` |
| `workbench:*` | Operational application components | `workbench:dialog`, `workbench:money` |
| `pal:*` | PAL domain components | `pal:contract_summary`, `pal:job_order_header` |

The profile resolver owns namespace registration. Components are included as:
```
{include "workbench:page_header"}
{include "pal:contract_summary"}
```

### 4.2 Entry Rule

> A component enters ARK Workbench when it is useful to more than one module, **or** when accessibility and security require one governed implementation.

### 4.3 What Stays in PAL

PAL-specific components that contain business vocabulary:

| Component | Reason to Keep in PAL |
|---|---|
| `pal_job_order_header` | PAL-specific JO identity and status |
| `pal_contract_summary` | PAL contract amount, allocation, retention |
| `pal_receivable_status` | PAL receivable aging and status |
| `pal_payment_allocation` | PAL payment-to-invoice allocation |
| `pal_fabrication_due` | PAL fabrication weekly due tracking |
| `pal_inventory_warning` | PAL low-stock/material warning logic |
| `pal_approval_subject` | PAL approval request type and context |

These **compose** ARK Workbench components internally:

```
pal_contract_summary
    ├── workbench:summary_card  (contract amount)
    ├── workbench:money          (formatted values)
    ├── workbench:status_badge   (contract status)
    └── workbench:progress       (completion percentage)
```

### 4.4 Component File Layout

```
storage/application-profiles/ark-workbench/
├── profile.manifest.json        # Application profile manifest
├── design-policy.json           # Configurable vs locked policy
├── tokens.json                  # Workbench design tokens
├── customizer.schema.json       # Customizer controls (brand, sidebar, radius, typography, table)
├── safety-policy.json           # Raw output, blocked patterns
├── assets/
│   ├── workbench.css            # Core stylesheet
│   ├── workbench-core.js        # Shell, drawer, bottom nav
│   ├── workbench-dialog.js      # Focus trap, modal behavior
│   ├── workbench-combobox.js    # Autocomplete keyboard nav
│   ├── workbench-table.js       # Responsive table behavior
│   └── asset-manifest.json      # Asset declarations
├── components/
│   ├── shell/
│   │   ├── app_shell.disyl
│   │   ├── mobile_drawer.disyl
│   │   ├── bottom_navigation.disyl
│   │   └── sidebar_section.disyl
│   ├── page/
│   │   ├── page_header.disyl
│   │   └── detail_header.disyl
│   ├── data/
│   │   ├── summary_card.disyl
│   │   ├── money.disyl
│   │   ├── status_badge.disyl
│   │   ├── progress.disyl
│   │   ├── responsive_table.disyl
│   │   └── empty_state.disyl
│   ├── forms/
│   │   ├── form_section.disyl
│   │   ├── validation_summary.disyl
│   │   └── combobox.disyl
│   └── interaction/
│       ├── dialog.disyl
│       ├── activity_timeline.disyl
│       └── approval_panel.disyl
├── layouts/
│   ├── app-shell.disyl          # Standard app layout
│   └── app-shell-mobile.disyl   # Mobile-first app layout
├── src/
│   └── ArkWorkbenchProvider.php # Customizer provider
└── docs/
    └── component-reference.md   # Usage documentation
```

---

## 5. Component Extraction Catalog

### 5.1 Extraction Mapping

For each PAL component, here is the extraction plan:

#### `pal_page_header` → `workbench:page_header`

**Current PAL implementation:**
```di Syl
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{_ph_title}</h1>
        {if _ph_subtitle|default:''}<p class="text-sm text-gray-600 mt-1">{_ph_subtitle}</p>{/if}
    </div>
    {if _ph_actions|default:''}<div class="flex items-center gap-2">{_ph_actions|raw}</div>{/if}
</div>
```

**Workbench extraction:**
- Add breadcrumb slot (optional breadcrumb trail)
- Add back_url for mobile back navigation
- Replace hardcoded Tailwind with Workbench tokens: `text-2xl` → `var(--wb-heading-xl)`, `text-gray-900` → `var(--wb-text-primary)`

**PAL migration path:** Replace `{include "pal_page_header" ...}` with `{include "workbench:page_header" ...}`. Pass structured actions:

```
{include "workbench:page_header"
    title=vm.title
    subtitle=vm.subtitle
    actions=vm.actions
}
```

Workbench renders buttons from `ActionValue` (label, url, method, variant, confirm) — not raw HTML.

#### `pal_detail_header` → `workbench:detail_header`

**Current PAL implementation:** Title, subtitle, status badge, 4-column metric grid (total, collected, outstanding, target date).

**Workbench extraction:**
- Generalize from 4-column to N-column metric grid
- Each metric becomes named slots
- Status badge uses `workbench:status_badge`
- Add tabs slot for tabbed detail pages

**PAL migration path:** PAL passes project-specific metrics via the generalized slots.

#### `pal_summary_card` → `workbench:summary_card`

**Current PAL implementation:** Icon square, label, value, 5 hardcoded color variants.

**Workbench extraction:**
- Colors become token-driven: `var(--wb-stat-blue)`, `var(--wb-stat-green)`, etc.
- Add trend indicator (up/down/neutral arrow)
- Add href for clickable cards
- Support compact variant

#### `pal_money` → `workbench:money`

**Current PAL implementation:** Hardcoded `₱` prefix, `number_format:2`, red/gray color.

**Critical boundary:** ARK Workbench must NOT decide financial formatting. PAL or a platform-level localization service produces the formatted string.

**Workbench controls only visual presentation:**
- Alignment (left/right/tabular)
- Visual emphasis (primary, secondary, muted)
- Negative tone (danger color)
- Typography (monospace for tabular, proportional for inline)

**PAL supplies pre-formatted value:**
```
{include "workbench:money"
    formatted=vm.total.formatted
    isNegative=vm.total.isNegative
    emphasis=vm.total.emphasis
}
```

**Never use floating-point for authoritative money.** The view model uses integer minor units or decimal strings (see §6.3).

Currency symbol, decimal places, and locale formatting are application/locale data — not visual policy. A theme token must not define the currency symbol for a transaction.

#### `pal_status_badge` → `workbench:status_badge`

**Current PAL implementation:** 11 hardcoded domain statuses (draft, pending, approved, etc.) with Tailwind colors.

**Critical boundary:** ARK must NOT know domain status meanings. Instead, Workbench understands **semantic tones**:

| Tone | Meaning | Visual |
|---|---|---|
| `neutral` | Inactive, draft, informational | Gray |
| `informational` | Note, reference, hint | Blue |
| `warning` | Needs attention, pending, due soon | Yellow/amber |
| `success` | Completed, approved, paid, active | Green |
| `danger` | Rejected, overdue, error, blocked | Red |

**PAL translates domain status → visual tone:**
```
approved → success
pending  → warning
rejected → danger
overdue  → danger
draft    → neutral
started  → success
ongoing  → success
```

**Usage:**
```
{include "workbench:status_badge"
    label=vm.status.label
    tone=vm.status.tone
    variant=vm.status.variant
}
```

- Support variants: `pill` (default), `dot`
- Support size: `sm`, `md`, `lg`
- PAL owns the domain→tone mapping; Workbench owns the presentation
- `isTerminal` belongs to PAL's workflow service, not the visual status presenter

#### `pal_empty_state` → `workbench:empty_state`

**Current PAL implementation:** Centered icon, title, description, optional action.

**Workbench extraction:**
- Add illustration slot for SVG illustrations
- Add size: `sm` for inline empty states (e.g., empty tab), `lg` for full-page
- Add bordered variant

### 5.2 New ARK Workbench Components (No PAL Source)

These components don't exist in PAL yet but are needed for the workbench profile:

#### `workbench:responsive_table`

**Workbench 0.1 scope (conservative):**
- Semantic `<table>` with proper `thead`/`tbody`
- Horizontal overflow on narrow screens (no custom grid)
- `data-label` mobile card mode: each cell gets a `data-label` attribute matching the column header, CSS converts to stacked card layout below breakpoint
- Row actions (single-click, not bulk)
- Pagination slot
- Accessible checkboxes with labels

**Deferred to later versions:**
- Sortable headers (can use server-side sort initially)
- Bulk action bar
- Frozen columns
- Client-side Arrow-key grid navigation

**API:**
```
{include "workbench:responsive_table"
    columns = [...]     // [{key, label, align, width}]
    rows = [...]         // [{id, cells: {key: value}, href}]
    empty = "No items"
    row_actions = [...]  // [{label, href, variant}]
}
```

Native HTML table semantics are valuable. Avoid turning every business list into a custom ARIA grid unless the interaction truly requires it.

#### `workbench:form_section`

**Design:**
- Grouped form fields with section header
- Optional description text
- Optional collapsible toggle
- Validation state per section (icon for sections with errors)
- Responsive: single-column on mobile, multi-column on desktop

#### `workbench:validation_summary`

**Design:**
- Accessible error summary at form top
- `role="alert"` + `aria-live="assertive"`
- Lists each error with link to field
- Focus management: focuses summary on submit with errors
- Count display: "3 errors found"

#### `workbench:combobox`

**Design:**
- Searchable select/autocomplete
- Keyboard navigation (Arrow, Enter, Escape)
- Loading state during fetch
- Empty state when no results
- Grouped options support
- Multiple selection variant

#### `workbench:dialog`

**Design:**
- Accessible modal dialog implemented via `workbench-dialog.js`
- Focus trap (Tab cycles within dialog)
- Escape to close
- Click outside to close (configurable)
- Three variants: `default`, `alert` (destructive), `confirm`
- Title, body, footer action slots

#### `workbench:activity_timeline`

**Design:**
- Vertical timeline with connector line
- Each item: icon, timestamp, actor, action description, optional detail
- Grouped by date
- Load more for pagination
- Variants: `default`, `compact` (for side panels)

#### `workbench:approval_panel`

**Design (generalized beyond financial approvals):**

Workbench provides a generic approval structure. Each module contributes domain-specific sections:

```php
final readonly class ApprovalPanelViewModel implements TemplateViewModel
{
    public function __construct(
        public string $subject,              // What is being approved
        public string $requester,            // Who submitted it
        public string $submittedAt,          // When
        /** @var array<int, ApprovalSection> */
        public array $summarySections,       // Domain-specific context
        /** @var array<int, ApprovalSection> */
        public array $impactSections,        // Domain-specific impact
        /** @var array<int, EvidenceItem> */
        public array $evidence,              // Supporting documents
        /** @var array<int, ActivityItem> */
        public array $decisionHistory,       // Previous decisions
        /** @var array<int, ActionValue> */
        public array $actions,               // Approve/Reject/Return
        public bool $rejectionReasonRequired, // Enforce reason on reject
        public ?string $auditContext,         // Policy/rules reference
    ) {}
}
```

PAL contributes: `Financial Impact`, `Budget Impact`, `Invoice Impact`.
Guidance contributes: `Case Context`, `Risk Context`, `Student History`.
EHR contributes: `Clinical Context`, `Medication Review`, `Result Verification`.

The locked rule is: **Required decision context may not be removed.** It is NOT: "Every approval must show financial impact."

---

## 6. PAL View Model Layer

### 6.1 Directory Structure

```
modules/project-audit-ledger/presentation/
├── PalStatusPresenter.php       # Status → label, color, icon, description
├── PalMoneyPresenter.php        # Money formatting, currency symbol, locale
├── PalJobOrderViewModel.php     # Job Order detail view model
├── PalInvoiceViewModel.php      # Invoice detail view model
├── PalApprovalViewModel.php     # Approval review view model
├── PalInventoryViewModel.php    # Inventory item view model
├── PalTeamLeadViewModel.php     # Team Lead dashboard aggregate
├── PalExpenseViewModel.php      # Expense detail/transaction view model
├── PalPurchaseViewModel.php     # Purchase order view model
├── PalClientViewModel.php       # Client detail view model
├── PalQuotationViewModel.php    # Quotation detail view model
├── PalCollectionViewModel.php   # Collection/payment view model
├── PalCashAdvanceViewModel.php  # Cash advance view model
└── PalDashboardViewModel.php    # (existing — move here, rename)
```

### 6.2 View Model Pattern

Each view model implements `TemplateViewModel` and is a `final readonly class` with typed properties:

```php
interface TemplateViewModel
{
    /** @return array<string,mixed> */
    public function toTemplateContext(): array;
}
```

```php
final readonly class PalInvoiceViewModel implements TemplateViewModel
{
    public function __construct(
        public string $invoiceNumber,
        public string $clientName,
        public MoneyValue $invoiceTotal,
        public MoneyValue $paidAmount,
        public MoneyValue $balance,
        public StatusValue $status,
        /** @var array<int, ActionValue> */
        public array $actions,
        /** @var array<int, LineItemValue> */
        public array $lineItems,
        public ?string $notes,
        public string $createdAt,
        public string $createdBy,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, PalMoneyPresenter $money, PalStatusPresenter $status): self
    {
        return new self(
            invoiceNumber: $row['invoice_number'],
            clientName: $row['client_name'],
            invoiceTotal: $money->fromDecimalString((string)($row['total_amount'] ?? '0'), 'PHP'),
            paidAmount: $money->fromDecimalString((string)($row['paid_amount'] ?? '0'), 'PHP'),
            balance: $money->fromDecimalString((string)($row['balance'] ?? '0'), 'PHP'),
            status: $status->forInvoice($row['status']),
            actions: self::resolveActions($row),
            lineItems: self::resolveLineItems($row),
            notes: $row['notes'] ?? null,
            createdAt: $row['created_at'],
            createdBy: $row['created_by_name'] ?? '—',
        );
    }

    /** @return array<string,mixed> */
    public function toTemplateContext(): array
    {
        return [
            'invoice_number' => $this->invoiceNumber,
            'client_name'   => $this->clientName,
            'total'         => $this->invoiceTotal->toTemplateValue(),
            'paid'          => $this->paidAmount->toTemplateValue(),
            'balance'       => $this->balance->toTemplateValue(),
            'status'        => $this->status->toTemplateValue(),
            'actions'       => array_map(
                static fn (ActionValue $a) => $a->toTemplateValue(),
                $this->actions
            ),
            'line_items'    => array_map(
                static fn (LineItemValue $li) => $li->toTemplateValue(),
                $this->lineItems
            ),
            'notes'         => $this->notes,
            'created_at'    => $this->createdAt,
            'created_by'    => $this->createdBy,
        ];
    }
}
```

**Hard rule: View models contain values and semantic structures. They never contain HTML.**

### 6.3 Value Objects

All value objects implement `TemplateContextValue` for explicit serialization:

```php
interface TemplateContextValue
{
    /** @return array<string,mixed>|string|int|bool|null */
    public function toTemplateValue(): array|string|int|bool|null;
}
```

```php
// MoneyValue — integer minor units (never float), converted from DECIMAL strings
final readonly class MoneyValue implements TemplateContextValue
{
    public function __construct(
        public int $minorUnits,      // 123456 (= ₱1,234.56)
        public string $currency,     // "PHP"
        public string $formatted,    // "₱1,234.56" — produced by PAL/localization
        public bool $isNegative,
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency'    => $this->currency,
            'formatted'   => $this->formatted,
            'is_negative' => $this->isNegative,
        ];
    }
}

// StatusValue — domain status with resolved visual tone
final readonly class StatusValue implements TemplateContextValue
{
    public function __construct(
        public string $key,           // "approved"
        public string $label,         // "Approved"
        public string $tone,          // "success" — semantic tone for workbench:status_badge
        public string $description,   // "Project has been approved and is ready to start"
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'tone'        => $this->tone,
            'description' => $this->description,
        ];
    }
}

// ActionValue — resolved action (authorization already applied)
final readonly class ActionValue implements TemplateContextValue
{
    public function __construct(
        public string $key,           // "invoice.pay"
        public string $label,
        public string $url,           // Fully resolved URL
        public string $method,        // "GET" | "POST"
        public string $variant,       // "primary" | "secondary" | "danger" | "ghost"
        public ?string $confirm,      // Confirmation message
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'key'     => $this->key,
            'label'   => $this->label,
            'url'     => $this->url,
            'method'  => $this->method,
            'variant' => $this->variant,
            'confirm' => $this->confirm,
        ];
    }
}
```

**Safe DECIMAL conversion:** The database may continue using `DECIMAL(15,2)` columns. `PalMoneyPresenter::fromDecimalString()` converts to integer minor units without floating-point:

```php
final readonly class PalMoneyPresenter
{
    public function fromDecimalString(string $amount, string $currency): MoneyValue
    {
        $isNegative = str_starts_with($amount, '-');
        $clean = ltrim($amount, '-');
        $parts = explode('.', $clean);
        $whole = (int)$parts[0];
        $fraction = isset($parts[1]) ? (int)str_pad($parts[1], 2, '0') : 0;
        $minorUnits = ($whole * 100) + $fraction;
        if ($isNegative) {
            $minorUnits = -$minorUnits;
        }
        return new MoneyValue(
            minorUnits: $minorUnits,
            currency: $currency,
            formatted: $this->format($minorUnits, $currency),
            isNegative: $isNegative,
        );
    }
}
```

This avoids inventing `_minor` database columns or requiring schema migrations.

### 6.4 Handler → View Model → Template Flow

**Before (current):**
```
Handler: palPageInvoiceDetail()
  ├── SQL query → raw $row array
  ├── Pre-render HTML fragments (status badge HTML, money HTML)
  └── Pass to template: ['_dh_title' => $row['invoice_number'], ...]
```

**After (target):**
```
Handler: palPageInvoiceDetail()
  ├── InvoiceService::findById($id) → raw row
  ├── PalInvoiceViewModel::fromRow($row, $moneyPresenter, $statusPresenter) → typed model
  └── Render template: ['vm' => $viewModel->toArray()]
```

**Template:**
```
{set vm = invoice_vm}
{include "pal_detail_header"
    _dh_title = vm.invoice_number
    _dh_status = vm.status.key
    _dh_amount = vm.invoice_total.decimal
    _dh_collected = vm.paid_amount.decimal
    _dh_outstanding = vm.balance.decimal
}
```

### 6.5 Benefit Summary

| Before | After |
|---|---|
| Raw DB column names in templates | Typed view model properties via `toTemplateContext()` |
| Handler pre-renders HTML | Handler passes data; template renders |
| Status logic scattered across 20+ files | One `PalStatusPresenter` with centralized domain→tone mapping |
| Money formatting inconsistent | One `PalMoneyPresenter`; integer minor units, never float |
| Template conditions on raw status strings | Template conditions on `vm.status.tone` |
| Impossible to unit test display logic | View models are pure PHP, fully testable |
| View models may contain HTML | Hard rule: view models contain values only, never HTML |
| Roles checked in templates | Authorization resolved before view model; actions list contains only permitted actions |

---

## 7. Page Family Standardization

Every PAL screen should belong to exactly one of these families:

### 7.1 Operational List

**Used by:** Job Orders, Invoices, Expenses, Purchases, Inventory, Sales, Collections, Quotations, Cash Advances, Material Issuances, Material Returns, Clients, Suppliers, Users

**Structure:**
```
┌─────────────────────────────────────┐
│ workbench:page_header (title, subtitle,    │
│   attention filters, create button)  │
├─────────────────────────────────────┤
│ Summary metrics row                  │
│ [workbench:summary_card] [workbench:summary_card]│
├─────────────────────────────────────┤
│ Search bar + saved filters           │
├─────────────────────────────────────┤
│ workbench:responsive_table           │
│ (columns, sort, paginate)            │
├─────────────────────────────────────┤
│ Bulk action bar (if items selected)  │
└─────────────────────────────────────┘
```

**Current PAL pages to standardize:** 22 list pages

### 7.2 Detail Workspace

**Used by:** Job Order detail, Invoice detail, Client detail, Purchase detail, Quotation detail, Expense detail, Issuance detail, Sales detail, Collection detail, Inventory detail

**Structure:**
```
┌─────────────────────────────────────┐
│ workbench:detail_header              │
│ (identity, status, amounts, actions) │
├─────────────────────────────────────┤
│ Tab bar:                             │
│ [Overview] [Items] [Financials]      │
│ [Documents] [Activity]               │
├─────────────────────────────────────┤
│ Tab content — varies by entity       │
│ - Overview: summary cards, timeline  │
│ - Items: line-item table             │
│ - Financials: money breakdown        │
│ - Documents: attachment list         │
│ - Activity: workbench:activity_timeline    │
└─────────────────────────────────────┘
```

**Current PAL pages to standardize:** 10 detail pages

### 7.3 Transaction Form

**Used by:** Payment form, Expense form, Issuance form, Material Return form, Cash Advance form, Collection form, Mobilization form

**Structure:**
```
┌─────────────────────────────────────┐
│ Context summary                      │
│ (what project/client this is for)    │
├─────────────────────────────────────┤
│ workbench:form_section: Transaction Details│
│ (fields for this transaction type)   │
├─────────────────────────────────────┤
│ workbench:form_section: Impact Preview     │
│ (calculated totals, before/after)    │
├─────────────────────────────────────┤
│ workbench:form_section: Documents          │
│ (attachment upload)                  │
├─────────────────────────────────────┤
│ workbench:validation_summary         │
├─────────────────────────────────────┤
│ [Cancel] [Save Draft] [Submit]       │
│ (primary submission action)          │
└─────────────────────────────────────┘
```

**Current PAL pages to standardize:** 8 form pages

### 7.4 Approval Review

**Used by:** Approval queue, individual approval review

**Structure:**
```
┌─────────────────────────────────────┐
│ Request summary                      │
│ (who submitted what, when)           │
├─────────────────────────────────────┤
│ workbench:approval_panel             │
│ - Financial impact (before → after)  │
│ - Supporting documents               │
│ - Previous decisions                 │
│ - Previous vs proposed values        │
├─────────────────────────────────────┤
│ [Approve] [Reject] [Return]          │
│ Rejection reason (if rejecting)      │
├─────────────────────────────────────┤
│ Audit context                        │
│ (policy rules, role permissions)     │
└─────────────────────────────────────┘
```

**Current PAL pages to standardize:** 2 approval pages

### 7.5 Dashboard

**Used by:** Admin dashboard, Team Lead dashboard

**Structure:**
```
┌─────────────────────────────────────┐
│ workbench:page_header                │
├─────────────────────────────────────┤
│ workbench:summary_card row (4-6 metrics)   │
├─────────────────────────────────────┤
│ Charts row (optional)                │
├─────────────────────────────────────┤
│ Pending approvals panel              │
├─────────────────────────────────────┤
│ Recent activity timeline             │
├─────────────────────────────────────┤
│ Low stock alerts                     │
└─────────────────────────────────────┘
```

### 7.6 Page Family → Template Mapping

| Family | Template Pattern | PAL Pages |
|---|---|---|
| Operational List | `pages/lists/{entity}-list.disyl` | 22 |
| Detail Workspace | `pages/details/{entity}-detail.disyl` | 10 |
| Transaction Form | `pages/forms/{entity}-form.disyl` | 8 |
| Approval Review | `pages/approvals/approval-queue.disyl` | 2 |
| Dashboard | `pages/dashboards/{role}-dashboard.disyl` | 3 |
| Settings/Admin | `pages/admin/{section}.disyl` | 5 |
| Auth | `pages/auth/{action}.disyl` | 4 |
| Prints | `prints/{document}-print.disyl` | 2 |

**Total: 54 full pages + 2 prints.** Phase 1 will generate a canonical inventory via `php ikabud ui:inventory project-audit-ledger` to distinguish full pages from partials, components, layouts, and deprecated templates.

---

## 8. Team Lead Experience

### 8.1 Design Goals

The Team Lead portal is PAL's **mobile-first surface** — it must work on a phone at a construction site or fabrication shop.

1. **Answer the key questions immediately:**
   - What project am I working on?
   - What is due today?
   - What materials are available?
   - What requests are pending?
   - What amount has been released?
   - What needs evidence or documentation?

2. **Avoid reproducing the full admin system.** Give team leads only actions required on-site.

3. **This could become a PWA later**, but first make the web workflow excellent.

### 8.2 Current Team Lead Shell Assessment

The current `team-lead-shell.disyl` already has:
- Mobile sidebar drawer (transform translate pattern)
- Bottom navigation bar (5 items: Home, Fabrication, Attendance, Cash, More)
- Toast notification system
- CSRF protection
- Tailwind CDN + HTMX + Alpine

**Gaps:**
- Hardcoded app name in shell (should come from tenant settings)
- Bottom nav items not configurable per tenant
- No offline resilience
- No push notification capability
- No camera capture for evidence upload
- No location tagging for on-site verification

### 8.3 Application Shell Context

`workbench:app_shell` must not directly understand PAL navigation. Instead, PAL supplies a generic shell view model:

```php
final readonly class ApplicationShellViewModel implements TemplateViewModel
{
    public function __construct(
        public string $applicationName,
        public ?string $logoUrl,
        /** @var array<int, NavSection> */
        public array $navigationSections,
        /** @var array<int, NavItem> */
        public array $userActions,
        /** @var array<int, NavItem> */
        public array $mobileNavigation,
        public string $activeRoute,
        /** @var array<int, NotificationValue> */
        public array $notifications,
        public string $contentTemplate,
    ) {}
}

final readonly class NavItem
{
    public function __construct(
        public string $label,
        public string $url,
        public string $iconKey,
        public bool $isActive,
        public ?int $badgeCount,
        // capability already resolved — item is only present if user has access
    ) {}
}
```

Workbench renders the shell. PAL supplies the navigation model. This eliminates the hardcoded "ZAP-ARTS" problem.

### 8.4 Target Team Lead Navigation

```
┌─────────────────────────────────────┐
│ Today                                │
│ - Current project + status           │
│ - Due today (tasks, deadlines)       │
│ - Quick actions (log attendance,     │
│   request cash advance)              │
├─────────────────────────────────────┤
│ My Jobs                              │
│ - Assigned fabrications              │
│ - Progress tracking                  │
│ - Evidence upload                    │
├─────────────────────────────────────┤
│ Requests                             │
│ - Cash advance status                │
│ - Mobilization requests              │
│ - Material requests                  │
├─────────────────────────────────────┤
│ Attendance                           │
│ - Today's log                        │
│ - Team member list                   │
│ - Weekly summary                     │
├─────────────────────────────────────┤
│ More                                 │
│ - Notifications                      │
│ - Profile                            │
│ - Sign Out                           │
└─────────────────────────────────────┘

Bottom nav: [📋 Today] [🔧 My Jobs] [📝 Requests] [👥 Attendance] [☰ More]
```

### 8.5 Team Lead View Model

```php
final readonly class PalTeamLeadViewModel implements TemplateViewModel
{
    public function __construct(
        public readonly ?array $currentProject,    // Active project assignment
        public readonly array $todayTasks,          // Due today
        public readonly array $pendingRequests,      // Cash advances, mobilization
        public readonly array $myFabrications,       // Assigned fabrication work
        public readonly array $recentAttendance,     // Last 7 days
        public readonly array $releasedAmounts,      // Cash advance totals
        public readonly array $requiredEvidence,     // Items needing photos/docs
        public readonly array $notifications,        // Unread alerts
    ) {}
}
```

### 8.6 Future PWA Readiness

Reserve the following for Phase 5+:
- Service worker for offline shell
- IndexedDB for draft form persistence
- Camera API for evidence capture
- Geolocation for site verification
- Push notifications for approval updates

---

## 9. Theme Studio Policy Boundaries

### 9.1 Configurable Properties

Theme Studio may configure these ARK Workbench properties:

| Category | Property | Scope |
|---|---|---|
| **Brand** | `brand.primary` (color) | Per-tenant |
| **Brand** | `brand.logo` (URL) | Per-tenant |
| **Brand** | `brand.app_name` | Per-tenant |
| **Typography** | `font.interface` | Per-tenant |
| **Typography** | `font.size_scale` | Per-tenant |
| **Sidebar** | `sidebar.variant` (`dark` / `light` / `branded`) | Per-tenant |
| **Radius** | `radius` (`none` / `sm` / `md` / `lg`) | Per-tenant |
| **Spacing** | `spacing.scale` | Per-tenant |
| **Table** | `table.zebra_striping` | Per-tenant |
| **Print** | `print.branding` | Per-tenant |
| **Density** | `density.default` (`comfortable` / `compact`) | Per-tenant default |
| **Table** | `table.default_density` | Per-tenant default |
| **Sidebar** | `sidebar.default_state` | Per-tenant default |

**Resolution precedence:**
```
Workbench component defaults
    ↓
Tenant profile defaults (configured via Theme Studio)
    ↓
User preference overrides (stored in user_preferences)
    ↓
Runtime accessibility overrides (e.g., prefers-reduced-motion, high-contrast)
```

> Density, table density, and sidebar state are per-user preferences. Theme Studio defines the tenant default. Individual user overrides are stored separately in `user_preferences` and never overwritten by tenant-wide theme changes.

### 9.2 Locked Properties

Theme Studio **must not** control:

| Category | Locked Property | Rationale |
|---|---|---|
| **Status** | `status.meaning` | "Pending" always means awaiting action; tone is configurable within limits but meaning is not |
| **Status** | `status.color_contract` | Red always signals danger; cannot be repurposed for neutral states |
| **Danger** | `danger.emphasis` | Destructive actions always use warning styling with confirmation |
| **Approval** | `approval.context` | Required decision context may not be removed; content varies by domain (financial, clinical, safeguarding) |
| **Financial** | `financial.total.visibility` | Totals, balances, and amounts must always be visible — never hidden by theme |
| **Actions** | `action.priority` | Primary/submit actions always visually distinct from secondary/cancel |
| **Required** | `required.field_indicator` | Required fields always marked; cannot be hidden by theme |
| **Workflow** | `workflow.state` | Workflow states are business logic, not visual configuration |
| **Terminology** | `business.terminology` | Domain labels belong to the module, not the theme |
| **Access** | `access.rules` | Role-based visibility is capability-enforced, not theme-enforced |

### 9.3 Design Policy Schema

The design policy is published as a machine-readable schema that ARK Workbench enforces at render time:

```json
{
    "version": "1.0",
    "profile": "ark.workbench",
    "configurable": [
        "brand.primary",
        "brand.logo",
        "brand.app_name",
        "font.interface",
        "font.size_scale",
        "density.default",
        "sidebar.variant",
        "sidebar.default_state",
        "table.default_density",
        "table.zebra_striping",
        "radius",
        "spacing.scale",
        "print.branding"
    ],
    "locked": [
        "status.meaning",
        "status.color_contract",
        "danger.emphasis",
        "approval.context",
        "financial.total.visibility",
        "action.priority",
        "required.field_indicator",
        "workflow.state",
        "business.terminology",
        "access.rules"
    ],
    "tone_contract": {
        "neutral": {
            "allowed_families": ["gray", "slate", "zinc", "neutral"],
            "min_contrast_text": 4.5
        },
        "informational": {
            "allowed_families": ["blue", "cyan", "sky"],
            "min_contrast_text": 4.5
        },
        "warning": {
            "allowed_families": ["amber", "yellow", "orange"],
            "min_contrast_text": 4.5
        },
        "success": {
            "allowed_families": ["green", "teal", "emerald"],
            "min_contrast_text": 4.5
        },
        "danger": {
            "allowed_families": ["red", "rose", "crimson"],
            "min_contrast_text": 4.5,
            "must_use_confirmation": true,
            "must_include_text_label": true
        }
    },
    "danger_emphasis": {
        "min_contrast_ratio": 4.5,
        "must_use_confirmation": true
    }
}
```

**Key change from earlier draft:** The policy validates **semantic tones**, not domain statuses. Workbench must never know domain keys like `draft`, `pending`, `approved`, `rejected`, or `overdue`. Those belong to PAL or another domain module. PAL maps `pending → warning`, Guidance maps `escalated → danger`. Workbench validates that `danger` always uses a red-family color with confirmation and a text label.

### 9.4 Application Profile Settings Provider

Theme Studio integration must **not** reintroduce CMS coupling. ARK Workbench uses an independent settings contract:

```php
interface ApplicationProfileSettingsProvider
{
    public function profileId(): string;

    /** @return array Schema for the settings form */
    public function schema(): array;

    /** @return array<string,mixed> Tenant default values */
    public function defaults(): array;

    public function validate(
        ApplicationProfileSettingsSubmission $submission
    ): ApplicationProfileValidationResult;

    /** @return array<string,mixed> Normalized settings for rendering */
    public function normalize(array $settings): array;
}
```

ARK Workbench implements both contracts:

```php
final class ArkWorkbenchProvider implements
    ApplicationProfileProvider,
    ApplicationProfileSettingsProvider
{
    // ApplicationProfileProvider — identity, components, layouts, assets, design policy
    // ApplicationProfileSettingsProvider — settings schema, defaults, validation, normalization
}
```

### 9.5 Persistence

Workbench settings are stored independently of CMS:

| Option | Description |
|---|---|
| **Generic tenant settings capability** | Store under `kernel.tenant.settings` with profile-scoped keys |
| **Dedicated table** | `application_profile_settings (tenant_id, profile_id, key, value)` |

Do **not** store Workbench settings in `cms_theme_customizer`. The runtime must work even when CMS and Theme Studio are disabled.

### 9.6 Locked Properties — UI Behavior

Theme Studio must not merely show locked fields and reject them on save. Prefer:

1. **Do not expose** locked properties in the Theme Studio form at all.
2. **Reject** them if submitted manually (API defense).
3. **Validate again** during profile activation.
4. **Normalize** them during rendering as a final safety layer.

Runtime enforcement should be defensive — not the primary workflow.

---

## 10. Migration Phases

### 10.1 Phase 1: Freeze and Document (1-2 days)

**Goal:** Declare the current PAL UI as a versioned contract. No code changes.

**Deliverables:**
- [ ] `PAL UX Contract 1.0` — document every page, route, component, and behavior
- [ ] `ARK Workbench Candidate 0.1` — component catalog with PAL sources annotated
- [ ] Page family classification for all 54 templates
- [ ] Screenshot baseline at 3 breakpoints (1440px, 768px, 375px)
- [ ] Current accessibility audit (axe-core or Lighthouse)

**Files changed:** None (documentation only)

### 10.2 Phase 2A: Foundation (2-3 days)

**Goal:** Profile infrastructure, shell, and core display components.

**Deliverables:**
- [ ] Create `storage/application-profiles/ark-workbench/` directory structure
- [ ] Implement kernel contracts: `ApplicationProfileProvider`, `ApplicationProfileRegistry`, `ApplicationProfileResolver`, `ApplicationProfileValidator`
- [ ] Create `profile.manifest.json` with contract versioning
- [ ] Create asset package: `workbench.css`, `workbench-core.js`, `asset-manifest.json`
- [ ] Create `tokens.json` (workbench design tokens)
- [ ] Create `design-policy.json` (configurable vs locked)
- [ ] Extract shell patterns:
  - [ ] `workbench:app_shell` (from `shell.disyl`)
  - [ ] `workbench:mobile_drawer` (from both shells)
  - [ ] `workbench:bottom_navigation` (from `team-lead-shell.disyl`)
  - [ ] `workbench:sidebar_section` (from shell navigation)
- [ ] Extract core display components:
  - [ ] `workbench:page_header` (from `pal_page_header`)
  - [ ] `workbench:status_badge` (from `pal_status_badge`, semantic tones)
  - [ ] `workbench:summary_card` (from `pal_summary_card`)
  - [ ] `workbench:money` (from `pal_money`, presentation only)
  - [ ] `workbench:empty_state` (from `pal_empty_state`)
  - [ ] `workbench:dialog` (from PAL modal patterns + `workbench-dialog.js`)
- [ ] Wire PAL shells to use Workbench components
- [ ] **Validate:** Every PAL page renders identically to Phase 1 screenshots
- [ ] Run full test suite; check both logs

### 10.3 Phase 2B: Forms and Data (2-3 days)

**Goal:** Form infrastructure, data tables, and interaction components.

**Deliverables:**
- [ ] Create asset scripts: `workbench-combobox.js`, `workbench-table.js`
- [ ] Create form components:
  - [ ] `workbench:form_section`
  - [ ] `workbench:validation_summary`
- [ ] Create data components:
  - [ ] `workbench:responsive_table` (semantic table + `data-label` card mode)
  - [ ] `workbench:combobox`
- [ ] Create interaction components:
  - [ ] `workbench:activity_timeline`
  - [ ] `workbench:approval_panel`
  - [ ] `workbench:progress`
- [ ] Create `ArkWorkbenchProvider.php`
- [ ] **Validate:** Every PAL page renders identically to Phase 1 screenshots
- [ ] Run full test suite; check both logs

**Risk (Phase 2A/2B):** Template compilation cache may not detect layout changes. Use `?disyl_nocache=1` during development. Restart PHP-FPM to clear APCu.

### 10.4 Phase 3: Introduce View Models (3-5 days)

**Goal:** Create typed view models for the most critical PAL pages. Handlers pass data, templates render.

**Deliverables:**
- [ ] Create `modules/project-audit-ledger/presentation/` directory
- [ ] Implement `TemplateViewModel` interface
- [ ] Implement value objects: `MoneyValue` (int minor units), `StatusValue` (semantic tone), `ActionValue` (auth resolved)
- [ ] Implement presenters: `PalStatusPresenter`, `PalMoneyPresenter`
- [ ] Implement view models (in priority order):
  - [ ] `PalJobOrderViewModel` (highest complexity)
  - [ ] `PalInvoiceViewModel`
  - [ ] `PalApprovalViewModel`
  - [ ] `PalTeamLeadViewModel`
  - [ ] `PalExpenseViewModel`
  - [ ] `PalPurchaseViewModel`
  - [ ] `PalClientViewModel`
  - [ ] `PalQuotationViewModel`
  - [ ] `PalCollectionViewModel`
  - [ ] `PalCashAdvanceViewModel`
  - [ ] `PalInventoryViewModel`
- [ ] Build `ApplicationShellViewModel` populated with PAL navigation (class lives in Workbench `src/`)
- [ ] Move existing `DashboardViewModel` to `presentation/PalDashboardViewModel.php`
- [ ] Update handlers to use view models (one handler per view model)
- [ ] Update templates to consume `vm.*` properties instead of raw arrays
- [ ] Add unit tests for each view model and presenter
- [ ] Add unit tests for `PalStatusPresenter` (verify tone mapping, labels)
- [ ] **Validate:** PAL renders identically. All existing tests pass.

### 10.5 Phase 4: Migrate Page Families (5-8 days)

**Goal:** Standardize all PAL pages into their page families using ARK Workbench components.

**Deliverables:**
- [ ] **Operational Lists** (22 pages):
  - [ ] Standardize on `workbench:page_header` + `workbench:responsive_table` + `workbench:summary_card` metrics
  - [ ] Add search + saved filter patterns
  - [ ] Saved filters: browser `localStorage` for initial implementation; persistent cross-device storage via generic `user_preferences` capability in Phase 6
  - [ ] Migrate one list at a time; validate after each
- [ ] **Detail Workspaces** (10 pages):
  - [ ] Standardize on `workbench:detail_header` + tabbed content
  - [ ] Add `workbench:activity_timeline` to each detail page
  - [ ] Add document/attachment tab pattern
- [ ] **Transaction Forms** (8 pages):
  - [ ] Standardize on `workbench:form_section` + `workbench:validation_summary`
  - [ ] Add impact preview section
  - [ ] Add field-level validation with accessible errors
- [ ] **Approval Review** (2 pages):
  - [ ] Migrate to `workbench:approval_panel`
  - [ ] Add mandatory rejection reason
  - [ ] Add financial impact comparison
- [ ] **Dashboard** (3 pages):
  - [ ] Standardize metric cards + timeline + alerts pattern
- [ ] **Settings/Admin** (6 pages):
  - [ ] Standardize on list + form patterns
- [ ] **Prints** (2 pages):
  - [ ] Ensure print stylesheet works with ARK Workbench tokens
- [ ] **Validate:** Screenshot comparison at all breakpoints. Run full test suite.

### 10.6 Phase 5: Prove Reuse (3-5 days)

**Goal:** Apply ARK Workbench to a second module. This is the real test of extraction quality.

**The second adopter must use the same visual profile with:**
- Different domain
- No PAL services
- No PAL tables
- No PAL templates
- No PAL assets

**Candidate modules:**
- **Attendance & Wages** — similar operational density (payroll periods, employee profiles, attendance records). If any direct PAL table reads exist, replace with capability contracts first.
- **Guidance** — exercises case lists, appointments, statuses, sensitive records, dashboards, and forms across different domain semantics.

**Deliverables:**
- [ ] Apply `workbench:app_shell` to target module admin
- [ ] Apply `workbench:responsive_table` to entity lists
- [ ] Apply `workbench:form_section` to forms
- [ ] Apply `workbench:detail_header` to entity detail pages
- [ ] Apply `workbench:status_badge` with module-specific tone mappings
- [ ] Document any PAL-specific assumptions that leaked into ARK Workbench
- [ ] Fix leaks by generalizing Workbench components
- [ ] **Validate:** Target module renders correctly with zero PAL imports

**Success criterion:** Second module adopts `ark.workbench` profile without copying a single PAL file.

### 10.7 Phase 6: Hardening and Conformance (ongoing)

**Goal:** Add conformance tests, accessibility gates, and CI enforcement.

**Deliverables:**
- [ ] Browser-level conformance tests (Section 11)
- [ ] Automated accessibility checks in CI
- [ ] Design policy validation in CI
- [ ] Visual regression testing (Percy/Chromatic or manual screenshot diff)
- [ ] PWA readiness audit

---

## 11. Conformance Testing Strategy

### 11.1 Test Matrix

| Flow | Desktop (1440px) | Tablet (768px) | Phone (375px) |
|---|---|---|---|
| 1. Create + submit Job Order | ✓ | ✓ | ✓ |
| 2. Approve + start Job Order | ✓ | ✓ | ✓ |
| 3. Complete JO + generate invoice | ✓ | ✓ | — |
| 4. Record + approve payment | ✓ | ✓ | — |
| 5. Upload + download attachment | ✓ | ✓ | ✓ |
| 6. Keyboard-only approval review | ✓ | ✓ | — |
| 7. Team Lead actions (phone) | — | — | ✓ |
| 8. Print invoice + quotation | ✓ | — | — |
| 9. Validation error + resubmit | ✓ | ✓ | ✓ |
| 10. Keyboard nav table + autocomplete | ✓ | ✓ | — |

### 11.2 Accessibility Checks

Automated checks for every ARK Workbench component:

- [ ] `workbench:dialog`: focus trap, Escape close, aria-modal, aria-labelledby
- [ ] `workbench:combobox`: aria-expanded, aria-activedescendant, Arrow key navigation
- [ ] `workbench:responsive_table`: checkboxes have labels, data-label attributes present on mobile
- [ ] `workbench:validation_summary`: role="alert", links to fields, focus on error
- [ ] `workbench:status_badge`: color is not the only differentiator (text label always present)
- [ ] `workbench:money`: negative values distinguished by both color AND minus sign
- [ ] `workbench:app_shell`: skip-to-content link, landmark regions (nav, main)
- [ ] `workbench:bottom_navigation`: touch targets ≥ 44px, current page indicated

### 11.3 Test Implementation

**Plain PHP tests cannot verify browser behavior** (focus trapping, keyboard navigation, mobile layouts, responsive tables, dialogs, viewport behavior). Use PHP for backend contracts, browser automation for UX.

**PHP tests** (existing conventions: bootstrap app, clear logs, assert on concrete behavior):

```
tests/php/workbench/
├── ManifestValidationTest.php
├── ComponentContextTest.php
├── DesignPolicyTest.php
├── TokenSchemaTest.php
└── ArkWorkbenchProviderTest.php

tests/php/pal/
├── viewmodels/
│   ├── PalJobOrderViewModelTest.php
│   ├── PalInvoiceViewModelTest.php
│   ├── PalApprovalViewModelTest.php
│   ├── PalStatusPresenterTest.php
│   └── PalMoneyPresenterTest.php
└── components/
    ├── MoneyTest.php
    ├── StatusBadgeTest.php
    ├── ResponsiveTableTest.php
    └── ApprovalPanelTest.php
```

**Browser tests** (Playwright + axe-core + screenshot comparisons):

```
tests/browser/pal/
├── job-order-workflow.spec.ts       # Flow 1-3
├── payment-approval.spec.ts         # Flow 4
├── attachment-security.spec.ts      # Flow 5
├── keyboard-accessibility.spec.ts   # Flow 6 — Tab, Shift+Tab, Escape
├── team-lead-mobile.spec.ts         # Flow 7 — 375px viewport
├── print-output.spec.ts             # Flow 8
├── validation-error.spec.ts         # Flow 9
└── keyboard-navigation.spec.ts      # Flow 10

tests/browser/workbench/
├── dialog-focus-trap.spec.ts
├── combobox-keyboard.spec.ts
├── responsive-table.spec.ts
├── mobile-drawer.spec.ts
├── bottom-navigation.spec.ts
└── screenshot-baselines/
```

---

## 12. Risks and Dependencies

### 12.1 Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| ARK Workbench components leak PAL assumptions | Medium | High | Phase 5 (prove reuse) explicitly tests this; fix leaks immediately |
| Template compilation cache masks changes | Medium | Medium | Use `?disyl_nocache=1` in dev; restart FPM to clear APCu |
| View model migration breaks handler contracts | Medium | Medium | Phase 3 migrates one page at a time; validate after each |
| Phase 4 (page families) is too large to complete | Medium | Medium | Migrate one page family at a time; ship incrementally |
| Bluehost MySQL 5.7 constraints affect new tables | Low | High | No new tables in this plan; if added, enforce InnoDB + no CTEs/window functions |
| Responsive table component is complex | Low | Low | Workbench 0.1 uses semantic table + data-label card mode only; defer grid features |
| Design policy enforcement breaks Theme Studio workflows | Low | Medium | Policy is additive — it rejects only locked keys, passes everything else through |
| PAL components get out of sync with ARK during extraction | Medium | High | Phase 2 keeps both; PAL components delegate to ARK internally |

### 12.2 Dependencies

| Dependency | Status | Notes |
|---|---|---|
| DiSyL 4.7+ | ✅ Active | Already supports includes, components, conditionals |
| Kernel OS 6.1+ | ✅ Active | Entity view system, capability bus |
| `ApplicationProfileRegistry` | 🔨 New | Kernel service to be created in Phase 2A |
| `ApplicationProfileResolver` | 🔨 New | Kernel service to be created in Phase 2A |
| `ApplicationProfileValidator` | 🔨 New | Kernel service to be created in Phase 2A |
| Generic tenant-settings capability | ✅ Active | For storing profile settings independently of CMS |
| Theme Studio module | ⚪ Optional | Administration UI for profile settings; not a runtime requirement |
| ARK Public Theme v3.0.0 | ⚪ Reference | Design reference for token conventions; not required at runtime |
| Bluehost MySQL 5.7 | ✅ Compatible | Existing queries already MySQL 5.7 safe |

### 12.3 What This Plan Changes at the Kernel Level

This plan **does** require minimal, generic kernel additions:

| Addition | Purpose |
|---|---|
| `ApplicationProfileProvider` interface | Contract for profile identity, component namespaces, layouts, assets, design policy |
| `ApplicationProfileSettingsProvider` interface | Contract for settings schema, defaults, validation, normalization |
| `ApplicationProfileRegistry` | Discovers and registers application profiles |
| `ApplicationProfileResolver` | Resolves the active profile for a requesting module |
| `ApplicationProfileValidator` | Validates profile manifests, contracts, and compatibility |

These are generic. Kernel must not know PAL or Workbench details.

### 12.4 Profile Resolution and Caching

**Resolution precedence:**
```
Module-required profile (declared in module.json)
    ↓
Tenant-selected compatible profile
    ↓
Module default profile
    ↓
Kernel fallback profile
```

**Failure behavior:** If a module declares a required application profile and no compatible profile is available, module activation fails with a clear diagnostic. Silent fallback is not permitted for operational modules. A fallback may be allowed only when the module explicitly declares one.

**Cache layers:**

| Cache | Key Components | Invalidation Trigger |
|---|---|---|
| Profile manifest cache | Profile ID + version | Profile install/upgrade |
| Component registry cache | Profile ID + contract version | Component contract change |
| Token cache | Profile ID + tenant ID | Tenant settings change, profile upgrade |
| Tenant settings cache | Profile ID + tenant ID + settings revision | Theme Studio publish |
| Compiled DiSyL cache | Template path + profile version | Template or layout change |
| Asset manifest cache | Profile ID + version | Asset file change |

Cache keys include profile ID, profile version, contract version, and tenant ID where relevant. Invalidation occurs on profile install/upgrade, tenant settings change, Theme Studio publish, contract change, or asset change. This keeps runtime overhead small and predictable.

### 12.5 What This Plan Does NOT Change

- **Service layer:** 18 PAL services remain unchanged
- **Database schema:** No mandatory migrations. Saved filters and user preferences may use existing generic settings storage or a new lightweight `application_profile_settings` table if needed (see §10.5 Phase 4 notes)
- **Route definitions:** No route changes needed (handler internals change, not routes)
- **Capability contracts:** No capability changes needed
- **Entity view contracts:** 15 entity-view configs remain valid
- **Public CMS Theme:** ARK theme is unaffected by ARK Workbench
- **Page Builder:** No changes to builder; excluded from PAL operational screens by policy

---

## 13. Success Criteria

### 13.1 Phase Gates

| Phase | Gate Criteria |
|---|---|
| **Phase 2A** | All PAL pages render identically to Phase 1 baseline at all 3 breakpoints |
| **Phase 2B** | All PAL pages render identically; new components pass unit tests |
| **Phase 3** | All view model unit tests pass; zero raw DB column names in migrated templates |
| **Phase 4** | Every PAL page classified into exactly one page family; all use ARK Workbench components |
| **Phase 5** | Second module renders with `ark.workbench` profile, zero PAL imports, zero PAL table reads |
| **Phase 6** | All 10 conformance flows pass at required breakpoints (PHP + browser); accessibility checks pass |

### 13.2 Final Success Definition

ARK Workbench is successful when:

1. **PAL is visually stable** — no user-visible regression from current state
2. **PAL's business meaning stays in PAL** — no financial concept leaked into ARK
3. **A second module adopts ARK Workbench** — without copying PAL code
4. **Theme Studio configures only safe properties** — locked properties are enforced at render time
5. **Page builder is excluded from PAL operational screens** — operational forms and approvals remain coded
6. **Conformance tests pass at 3 breakpoints** — desktop, tablet, phone
7. **Accessibility gates pass** — keyboard navigation, screen reader, focus management

---

## Appendix A: File Inventory — What Gets Created

```
docs/pal/
├── pal-ark-workbench-architecture-plan.md    # This document
├── pal-ux-contract-1.0.md                    # Phase 1 deliverable
└── ark-workbench-candidate-0.1.md            # Phase 1 deliverable

storage/application-profiles/ark-workbench/
├── profile.manifest.json
├── design-policy.json
├── tokens.json
├── customizer.schema.json
├── safety-policy.json
├── assets/
│   ├── workbench.css
│   ├── workbench-core.js
│   ├── workbench-dialog.js
│   ├── workbench-combobox.js
│   ├── workbench-table.js
│   └── asset-manifest.json
├── components/
│   ├── shell/
│   │   ├── app_shell.disyl
│   │   ├── mobile_drawer.disyl
│   │   ├── bottom_navigation.disyl
│   │   └── sidebar_section.disyl
│   ├── page/
│   │   ├── page_header.disyl
│   │   └── detail_header.disyl
│   ├── data/
│   │   ├── summary_card.disyl
│   │   ├── money.disyl
│   │   ├── status_badge.disyl
│   │   ├── progress.disyl
│   │   ├── responsive_table.disyl
│   │   └── empty_state.disyl
│   ├── forms/
│   │   ├── form_section.disyl
│   │   ├── validation_summary.disyl
│   │   └── combobox.disyl
│   └── interaction/
│       ├── dialog.disyl
│       ├── activity_timeline.disyl
│       └── approval_panel.disyl
├── layouts/
│   ├── app-shell.disyl
│   └── app-shell-mobile.disyl
├── src/
│   └── ArkWorkbenchProvider.php
└── docs/
    └── component-reference.md

kernel/
├── Contracts/
│   └── ApplicationProfileProvider.php    # New kernel contract
├── Presentation/
│   ├── TemplateViewModel.php             # Interface: toTemplateContext()
│   └── TemplateContextValue.php          # Interface: toTemplateValue()
├── Services/
│   ├── ApplicationProfileRegistry.php    # New kernel service
│   ├── ApplicationProfileResolver.php    # New kernel service
│   └── ApplicationProfileValidator.php   # New kernel service

storage/application-profiles/ark-workbench/src/
├── ArkWorkbenchProvider.php              # Implements ApplicationProfileProvider + SettingsProvider
├── ApplicationShellViewModel.php         # Generic shell context (NavSection, NavItem, NotificationValue)
├── ApprovalPanelViewModel.php            # Generic approval context (ApprovalSection, EvidenceItem)
└── DesignPolicy.php                      # Design policy loader + validator

modules/project-audit-ledger/presentation/
├── PalStatusPresenter.php
├── PalMoneyPresenter.php
├── PalJobOrderViewModel.php
├── PalInvoiceViewModel.php
├── PalApprovalViewModel.php
├── PalInventoryViewModel.php
├── PalTeamLeadViewModel.php
├── PalExpenseViewModel.php
├── PalPurchaseViewModel.php
├── PalClientViewModel.php
├── PalQuotationViewModel.php
├── PalCollectionViewModel.php
├── PalCashAdvanceViewModel.php
└── PalDashboardViewModel.php          # Moved from ViewModels/

tests/php/workbench/
├── ManifestValidationTest.php
├── ComponentContextTest.php
├── DesignPolicyTest.php
├── TokenSchemaTest.php
└── ArkWorkbenchProviderTest.php

tests/php/pal/
├── viewmodels/
│   ├── PalJobOrderViewModelTest.php
│   ├── PalInvoiceViewModelTest.php
│   ├── PalApprovalViewModelTest.php
│   ├── PalStatusPresenterTest.php
│   └── PalMoneyPresenterTest.php
└── components/
    ├── MoneyTest.php
    ├── StatusBadgeTest.php
    ├── ResponsiveTableTest.php
    └── ApprovalPanelTest.php

tests/browser/pal/
├── job-order-workflow.spec.ts
├── payment-approval.spec.ts
├── attachment-security.spec.ts
├── keyboard-accessibility.spec.ts
├── team-lead-mobile.spec.ts
├── print-output.spec.ts
├── validation-error.spec.ts
└── keyboard-navigation.spec.ts

tests/browser/workbench/
├── dialog-focus-trap.spec.ts
├── combobox-keyboard.spec.ts
├── responsive-table.spec.ts
├── mobile-drawer.spec.ts
├── bottom-navigation.spec.ts
└── screenshot-baselines/
```

## Appendix B: File Inventory — What Gets Modified

### Phase 2A
- `modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl` → delegate to `workbench:app_shell`
- `modules/project-audit-ledger/templates/project-audit-ledger/team-lead-shell.disyl` → delegate to `workbench:app_shell`
- `modules/project-audit-ledger/templates/project-audit-ledger/components/*.disyl` → delegate to Workbench equivalents

### Phase 2B
- Remaining PAL templates → adopt `workbench:form_section`, `workbench:responsive_table`, etc.

### Phase 3
- `modules/project-audit-ledger/handlers/10-dashboard.php` → use `PalDashboardViewModel`
- `modules/project-audit-ledger/handlers/15-projects.php` → use `PalJobOrderViewModel`
- `modules/project-audit-ledger/handlers/50-sales.php` → use `PalInvoiceViewModel`
- `modules/project-audit-ledger/handlers/55-approvals.php` → use `PalApprovalViewModel`
- `modules/project-audit-ledger/handlers/53-team-lead.php` → use `PalTeamLeadViewModel`
- (All remaining handlers updated similarly)

### Phase 4
- All 54 page templates → adopt page family structure with ARK Workbench components

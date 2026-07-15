# ARK Workbench Reference Guide

**Version**: 0.1.0
**Date**: 2026-07-12
**Status**: Candidate — derived from PAL reference implementation
**Audience**: Module developers, theme authors, platform architects

---

## Table of Contents

1. [What ARK Workbench Is](#1-what-ark-workbench-is)
2. [Where It Sits in the Stack](#2-where-it-sits-in-the-stack)
3. [The Component Catalog](#3-the-component-catalog)
4. [Component API Contracts](#4-component-api-contracts)
5. [Adopting ARK Workbench](#5-adopting-ark-workbench)
6. [View Model Pattern](#6-view-model-pattern)
7. [Design Policy Reference](#7-design-policy-reference)
8. [Theme Studio Integration](#8-theme-studio-integration)
9. [Testing](#9-testing)
10. [Migration Guide for Existing Modules](#10-migration-guide-for-existing-modules)
11. [Anti-Patterns and Pitfalls](#11-anti-patterns-and-pitfalls)

---

## 1. What ARK Workbench Is

ARK Workbench is a **governed application presentation layer** for Kernel OS operational modules.

### 1.1 The Problem It Solves

Kernel OS governs modules, capabilities, tenancy, policies, and services. DiSyL provides the declarative rendering language. But operational applications can still invent their own:

- Shells and sidebars
- Dialog behavior
- Table interactions
- Mobile navigation
- Form validation display
- Status presentation
- Empty states
- Approval interfaces

ARK Workbench gives the platform a **standard vocabulary** for these patterns.

### 1.2 What It Adds

| Layer | Governs | Example |
|---|---|---|
| **Kernel OS** | Authority and contracts | "This operation is allowed." |
| **ARK Workbench** | Presentation and interaction | "This is how the operation should be presented safely and consistently." |
| **DiSyL** | Declarative rendering | Renders the contracts |
| **Modules (PAL, etc.)** | Business meaning and workflows | Supplies the data and domain rules |

### 1.3 The Core Philosophy

> Workbench governs patterns that are shared, difficult, security-sensitive, accessibility-sensitive, or essential to platform consistency.

It does **not** absorb every small module-specific layout. It is not a mandatory heavy layer where every button requires profile resolution, registry lookup, and nested includes.

### 1.4 Benefits Summary

| Benefit | How |
|---|---|
| **Development speed** | New modules start from tested component vocabulary, not raw markup |
| **Consistency** | Same navigation, dialog, form, status, and table behavior across modules |
| **Reuse** | Fix a dialog focus trap once; every module benefits |
| **Accessibility** | One governed implementation per interaction pattern |
| **Safe theming** | Theme Studio configures branding without breaking operational meaning |
| **Cross-surface** | Same semantic value renders appropriately on desktop, mobile, print, PDF, email |
| **Testability** | Components tested independently; modules test only domain usage |

---

## 2. Where It Sits in the Stack

```
┌──────────────────────────────────────────────┐
│                Kernel OS                      │
│  governs authority, contracts, capabilities   │
├──────────────────────────────────────────────┤
│          Module Domain Services                │
│  PAL, Attendance, Guidance, EHR, WMS          │
│  provide business meaning and workflows       │
├──────────────────────────────────────────────┤
│          Module View Models                    │
│  typed, HTML-free, auth-resolved              │
├──────────────────────────────────────────────┤
│          ARK Workbench                         │
│  defines application presentation contracts   │
├──────────────────────────────────────────────┤
│          DiSyL                                 │
│  renders those contracts                      │
├──────────────────────────────────────────────┤
│  Desktop  │  Mobile  │  Print  │  PDF  │ Email │
└──────────────────────────────────────────────┘
```

### 2.1 Relationship to ARK Public Theme

| | ARK Public Theme | ARK Workbench |
|---|---|---|
| **Location** | `storage/cms-themes/ark/` | `storage/application-profiles/ark-workbench/` |
| **Type** | Public CMS theme | Application profile |
| **Purpose** | Marketing, content, ecommerce storefronts | Operational business applications |
| **Components** | `ark:card_grid`, `ark:hero`, `ark:stats` | `workbench:app_shell`, `workbench:dialog`, `workbench:responsive_table` |
| **Surfaces** | Public, print, email | Desktop, mobile, tablet, print, PDF, email |
| **Page Builder** | Primary authoring tool | Excluded from operational screens |

### 2.2 Namespace Convention

| Prefix | Scope | Example |
|---|---|---|
| `ark:*` | Public/reference ARK theme components | `ark:card_grid`, `ark:hero` |
| `workbench:*` | Operational application components | `workbench:dialog`, `workbench:money` |
| `pal:*` | PAL domain components | `pal:contract_summary`, `pal:job_order_header` |

### 2.3 Profile Declaration

A module declares its Workbench dependency in `module.json`:

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

---

## 3. The Component Catalog

### 3.1 Shell & Navigation

| Component | Purpose | JS Required |
|---|---|---|
| `workbench:app_shell` | Application chrome: sidebar, header, content area | `workbench-core.js` |
| `workbench:mobile_drawer` | Internal `app_shell` subpattern: off-canvas navigation drawer | `workbench-core.js` |
| `workbench:bottom_navigation` | Internal `app_shell` subpattern: 4-5 item bottom tab bar for mobile | `workbench-core.js` |
| `workbench:sidebar_section` | Internal `app_shell` subpattern: collapsible nav section with active state | `workbench-core.js` |

### 3.2 Page Structure

| Component | Purpose |
|---|---|
| `workbench:page_header` | Title, subtitle, breadcrumb, action slot |
| `workbench:detail_header` | Entity identity, status, amounts, primary actions |

### 3.3 Data Display

| Component | Purpose | JS Required |
|---|---|---|
| `workbench:summary_card` | Single metric: label, value, icon, trend | — |
| `workbench:money` | Currency display: alignment, emphasis, negative tone, typography | — |
| `workbench:status_badge` | Semantic tone badge (neutral/info/warning/success/danger) | — |
| `workbench:progress` | Progress bar with label and percentage | — |
| `workbench:responsive_table` | Semantic table → card stack at mobile breakpoint | `workbench-table.js` |
| `workbench:empty_state` | Empty placeholder with CTA | — |

### 3.4 Forms

| Component | Purpose | JS Required |
|---|---|---|
| `workbench:form_section` | Grouped form fields with header and description | — |
| `workbench:validation_summary` | Accessible error summary with focus management | `workbench-core.js` |
| `workbench:combobox` | Searchable select with keyboard navigation | `workbench-combobox.js` |

### 3.5 Interaction

| Component | Purpose | JS Required |
|---|---|---|
| `workbench:dialog` | Accessible modal with focus trap | `workbench-dialog.js` |
| `workbench:activity_timeline` | Chronological event feed | — |
| `workbench:approval_panel` | Request summary, impact, documents, decide | — |

### 3.6 Asset Dependencies

```
workbench-core.js        → app_shell, mobile_drawer, bottom_navigation, validation_summary
workbench-dialog.js      → dialog
workbench-combobox.js    → combobox
workbench-table.js       → responsive_table
```

A page that only uses `workbench:page_header` and `workbench:summary_card` needs **zero JavaScript** from Workbench. Load only what the page actually uses.

---

## 4. Component API Contracts

### 4.1 `workbench:app_shell`

The application shell receives a view model — it does **not** hardcode navigation.

**Context required** (from `ApplicationShellViewModel`):

| Property | Type | Description |
|---|---|---|
| `application_name` | `string` | Displayed in sidebar header |
| `logo_url` | `?string` | Optional organization logo |
| `navigation_sections` | `NavSection[]` | Collapsible sidebar sections |
| `user_actions` | `NavItem[]` | User menu items (profile, logout) |
| `mobile_navigation` | `NavItem[]` | Bottom nav items (max 5) |
| `active_route` | `string` | Currently active route for highlighting |
| `notifications` | `NotificationValue[]` | Unread notifications |
| `content_template` | `string` | Page body template to render |

**`NavSection`**:

| Property | Type | Description |
|---|---|---|
| `label` | `string` | Section header |
| `icon_key` | `string` | Icon identifier |
| `items` | `NavItem[]` | Navigation items in this section |
| `collapsed_default` | `bool` | Start collapsed |

**`NavItem`**:

| Property | Type | Description |
|---|---|---|
| `label` | `string` | Display text |
| `url` | `string` | Fully resolved URL |
| `icon_key` | `string` | Icon identifier |
| `is_active` | `bool` | Current page highlight |
| `badge_count` | `?int` | Optional notification count |

**Usage:**

```
{include "workbench:app_shell" shell=shell_vm}
    {page_body|raw}
{/include}
```

**Accessibility:**
- Skip-to-content link as first focusable element
- Landmark regions: `<nav>` for sidebar, `<main>` for content
- Mobile drawer: focus trap when open, restores focus on close
- Bottom nav: touch targets ≥ 44px

---

### 4.2 `workbench:page_header`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `title` | `string` | Yes | Page heading |
| `subtitle` | `?string` | No | Supporting text |
| `breadcrumb` | `{label, url}[]` | No | Breadcrumb trail |
| `back_url` | `?string` | No | Mobile back navigation link |
| `actions` | `string` | No | Raw HTML for action buttons |

**Usage:**

```
{include "workbench:page_header"
    title=vm.page_title
    subtitle=vm.page_subtitle
    actions=vm.header_actions
}
```

---

### 4.3 `workbench:detail_header`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `title` | `string` | Yes | Entity identifier (e.g., "JO-2024-001") |
| `subtitle` | `?string` | No | Secondary identifier (e.g., client name) |
| `status_badge` | `string` | No | Pre-rendered `workbench:status_badge` include |
| `metrics` | `{label, value, variant}[]` | No | Metric grid items (up to 6) |
| `actions` | `string` | No | Raw HTML for primary actions |
| `tabs` | `{label, url, active}[]` | No | Tab navigation items |

**Usage:**

```
{include "workbench:detail_header"
    title=vm.job_order_number
    subtitle=vm.client_name
    status_badge="
        {include 'workbench:status_badge'
            label=vm.status.label
            tone=vm.status.tone
        }
    "
    metrics=vm.detail_metrics
    actions=vm.primary_actions
    tabs=vm.detail_tabs
}
```

---

### 4.4 `workbench:summary_card`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | Yes | Metric label |
| `value` | `string` | Yes | Display value |
| `icon_key` | `?string` | No | Icon identifier |
| `tone` | `?string` | No | Visual tone: `neutral`, `informational`, `success`, `warning`, `danger` |
| `trend` | `?string` | No | `up`, `down`, `neutral` |
| `href` | `?string` | No | Makes card clickable |
| `variant` | `?string` | No | `default`, `compact` |

---

### 4.5 `workbench:money`

Workbench controls **visual presentation only**. The module supplies the formatted value.

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `formatted` | `string` | Yes | Pre-formatted currency string (e.g., "₱1,234.56") |
| `is_negative` | `bool` | No | Apply danger styling |
| `emphasis` | `string` | No | `primary`, `secondary`, `muted` |
| `align` | `string` | No | `left`, `right`, `tabular` |

**Module responsibility:** PAL or a localization service produces `formatted`. Workbench never performs currency formatting or locale decisions.

**Usage:**

```
{include "workbench:money"
    formatted=vm.total.formatted
    is_negative=vm.total.isNegative
    emphasis="primary"
    align="right"
}
```

---

### 4.6 `workbench:status_badge`

Workbench understands **semantic tones**, not domain statuses. The module maps domain → tone.

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | Yes | Display text |
| `tone` | `string` | Yes | `neutral`, `informational`, `warning`, `success`, `danger` |
| `variant` | `string` | No | `pill` (default), `dot` |
| `size` | `string` | No | `sm`, `md`, `lg` |

**Tone mapping (module responsibility):**

| Domain Status | Tone |
|---|---|
| `approved`, `paid`, `completed`, `active`, `started`, `ongoing` | `success` |
| `pending`, `submitted`, `due_soon` | `warning` |
| `rejected`, `overdue`, `cancelled`, `voided`, `blocked` | `danger` |
| `draft`, `inactive`, `archived` | `neutral` |
| `note`, `info`, `reference` | `informational` |

**Usage:**

```
{include "workbench:status_badge"
    label=vm.status.label
    tone=vm.status.tone
    variant="pill"
    size="md"
}
```

**Accessibility:** Badge always includes visible text. Tone is never communicated through color alone.

---

### 4.7 `workbench:responsive_table`

**Workbench 0.1 scope:** Semantic HTML table with `data-label` mobile card mode.

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `columns` | `{key, label, align, width}[]` | Yes | Column definitions |
| `rows` | `{id, cells: {key: value}, href}[]` | Yes | Row data |
| `empty_message` | `string` | No | Text when no rows (default: "No items found") |
| `row_actions` | `{label, url, variant}[]` | No | Per-row action buttons |
| `pagination` | `string` | No | Pre-rendered pagination HTML |

**Mobile behavior:** Below the breakpoint, each cell receives a `data-label` attribute matching the column header. CSS converts the table to a stacked card layout.

**Usage:**

```
{include "workbench:responsive_table"
    columns=vm.table_columns
    rows=vm.table_rows
    empty_message="No job orders yet"
    row_actions=vm.row_actions
}
```

**Accessibility:**
- `<caption>` for table title
- `<th scope="col">` for headers
- Checkbox cells have `<label>` with readable text
- `data-label` preserves column context on mobile

---

### 4.8 `workbench:empty_state`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `icon_key` | `string` | No | Icon identifier |
| `title` | `string` | Yes | Heading |
| `description` | `?string` | No | Supporting text |
| `action` | `string` | No | Raw HTML for CTA button |
| `size` | `string` | No | `sm` (inline), `lg` (full-page, default) |
| `bordered` | `bool` | No | Show border (default: true) |

---

### 4.9 `workbench:form_section`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `title` | `string` | Yes | Section heading |
| `description` | `?string` | No | Supporting text |
| `collapsible` | `bool` | No | Allow collapse toggle (default: false) |
| `collapsed` | `bool` | No | Start collapsed |
| `has_errors` | `bool` | No | Show error indicator on section |
| `body` | `string` | Yes | Raw HTML for form fields |

**Usage:**

```
{include "workbench:form_section"
    title="Transaction Details"
    description="Enter the payment information"
    has_errors=vm.section_has_errors
    body=vm.transaction_fields_html
}
```

---

### 4.10 `workbench:validation_summary`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `errors` | `{field_id, message}[]` | Yes | Error list |
| `heading` | `string` | No | Custom heading |

**Behavior:**
- Receives focus on form submit with errors
- Each error links to its field via `href="#field_id"`
- `role="alert"` + `aria-live="assertive"`
- Auto-generated heading: "N errors found"

**Usage:**

```
{if vm.has_errors}
    {include "workbench:validation_summary"
        errors=vm.errors
    }
{/if}
```

---

### 4.11 `workbench:combobox`

**Requires:** `workbench-combobox.js`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | Yes | Form field name |
| `options` | `{value, label, group}[]` | Yes | All options |
| `selected_value` | `?string` | No | Pre-selected value |
| `placeholder` | `string` | No | Placeholder text |
| `multiple` | `bool` | No | Allow multi-select |
| `required` | `bool` | No | Required field |
| `disabled` | `bool` | No | Disabled state |
| `empty_message` | `string` | No | Text when no results match |

**Behavior:** Arrow keys navigate, Enter selects, Escape closes. Loading state during async fetch.

---

### 4.12 `workbench:dialog`

**Requires:** `workbench-dialog.js`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | `string` | Yes | Unique dialog ID |
| `title` | `string` | Yes | Dialog heading |
| `body` | `string` | Yes | Content HTML |
| `footer` | `string` | Yes | Action buttons HTML |
| `variant` | `string` | No | `default`, `alert`, `confirm` |
| `close_on_backdrop` | `bool` | No | Close on outside click (default: true) |

**Accessibility:**
- Focus trap: Tab/Shift+Tab cycle within dialog
- Escape closes
- `aria-modal="true"`, `aria-labelledby` pointing to title
- Focus restored to trigger element on close
- Alert variant: destructive action styling
- Confirm variant: requires explicit confirm/cancel

---

### 4.13 `workbench:activity_timeline`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `items` | `ActivityItem[]` | Yes | Timeline entries |
| `variant` | `string` | No | `default`, `compact` |
| `load_more_url` | `?string` | No | URL for next page |

**`ActivityItem`:**

| Property | Type | Description |
|---|---|---|
| `icon_key` | `string` | Icon identifier |
| `timestamp` | `string` | Display timestamp |
| `actor` | `string` | Who performed the action |
| `action` | `string` | What happened |
| `detail` | `?string` | Optional expanded detail |

---

### 4.14 `workbench:approval_panel`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `request_summary` | `string` | Yes | Who submitted what, when |
| `financial_impact` | `string` | Yes | Before/after values HTML |
| `documents` | `{label, url}[]` | No | Supporting documents |
| `previous_decisions` | `ActivityItem[]` | No | Prior approval actions |
| `proposed_changes` | `{field, before, after}[]` | No | Changed values |
| `audit_context` | `string` | No | Policy/rules reference |
| `actions` | `string` | Yes | Approve/Reject/Return buttons HTML |
| `rejection_reason_required` | `bool` | No | Enforce rejection reason (default: true) |

**Business rules enforced by component:**
- Reject action requires a non-empty reason
- Financial impact is always visible — never collapsible or hideable
- Previous decisions shown in chronological order
- Audit context displayed at bottom

---

### 4.15 `workbench:progress`

**Properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `percent` | `int` | Yes | 0–100 |
| `label` | `?string` | No | Text label |
| `tone` | `string` | No | `neutral`, `success`, `warning`, `danger` |
| `size` | `string` | No | `sm`, `md`, `lg` |

---

## 5. Adopting ARK Workbench

### 5.1 Quick Start: New Module

A new operational module begins with:

```
1. Domain service
2. View model (implements TemplateViewModel)
3. Workbench components
4. DiSyL page
```

**Minimal `module.json` declaration:**

```json
{
    "application_profile": {
        "id": "ark.workbench",
        "version": "^1.0"
    }
}
```

**Minimal page template:**

```
{* extends "workbench:layouts/app-shell" *}

{include "workbench:page_header"
    title=vm.page_title
}

{include "workbench:responsive_table"
    columns=vm.table_columns
    rows=vm.table_rows
}

{include "workbench:empty_state"
    title="No records yet"
    description="Create your first record to get started."
    action=vm.create_button
}
```

### 5.2 Incremental Adoption

Modules do not need to adopt every Workbench component at once:

| Phase | What to Adopt |
|---|---|
| **Phase 1** | `workbench:app_shell` only — replaces custom shell |
| **Phase 2** | `workbench:page_header`, `workbench:status_badge`, `workbench:summary_card` |
| **Phase 3** | `workbench:form_section`, `workbench:responsive_table` |
| **Phase 4** | Introduce view models |
| **Phase 5** | `workbench:approval_panel`, `workbench:activity_timeline`, `workbench:dialog` |

Each phase is independently shippable.

### 5.3 Component Dependency Graph

```
workbench:app_shell
├── workbench:mobile_drawer
├── workbench:bottom_navigation
└── workbench:sidebar_section

workbench:detail_header
├── workbench:status_badge (for status slot)
└── workbench:money (for metric slots)

workbench:approval_panel
└── workbench:activity_timeline (for previous decisions)
```

Most components are independent and can be adopted individually.

---

## 6. View Model Pattern

### 6.1 The Contract

Every view model implements `TemplateViewModel`:

```php
interface TemplateViewModel
{
    /** @return array<string,mixed> */
    public function toTemplateContext(): array;
}
```

### 6.2 Rules

1. **View models contain values and semantic structures. They never contain HTML.**
2. **Authorization is resolved before the view model is built.** The actions collection contains only actions the current user may perform.
3. **Money is represented in integer minor units**, never float.
4. **Status is represented as a semantic tone**, not a domain-specific status string.
5. **URLs are fully resolved** by a router before reaching the view model.

### 6.3 Value Objects

```php
// MoneyValue — integer minor units, never float
final readonly class MoneyValue
{
    public function __construct(
        public int $minorUnits,      // 123456 (= ₱1,234.56)
        public string $currency,     // "PHP"
        public string $formatted,    // "₱1,234.56"
        public bool $isNegative,
    ) {}
}

// StatusValue — domain status with resolved visual tone
final readonly class StatusValue
{
    public function __construct(
        public string $key,           // "approved"
        public string $label,         // "Approved"
        public string $tone,          // "success"
        public string $description,   // Human-readable description
    ) {}
}

// ActionValue — auth already resolved
final readonly class ActionValue
{
    public function __construct(
        public string $key,           // "invoice.pay"
        public string $label,
        public string $url,           // Fully resolved
        public string $method,        // "GET" | "POST"
        public string $variant,       // "primary" | "secondary" | "danger" | "ghost"
        public ?string $confirm,
    ) {}
}
```

### 6.4 Handler Pattern

```php
function palPageInvoiceDetail(array $rp = []): void
{
    $user = palRequireRole('admin', 'supervisor', 'encoder');

    $row = (new InvoiceService(module()->db(), tenant()->id()))
        ->findById((int)($rp['id'] ?? 0));

    if (!$row) {
        http_response_code(404);
        renderNotFound();
        return;
    }

    $money = new PalMoneyPresenter(tenant()->locale());
    $status = new PalStatusPresenter();

    $vm = PalInvoiceViewModel::fromRow($row, $money, $status, $user);

    renderTemplate('project-audit-ledger/pages/details/invoice-detail', [
        'vm' => $vm->toTemplateContext(),
    ]);
}
```

---

## 7. Design Policy Reference

### 7.1 Configurable Properties

Theme Studio may configure:

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
| **Density** | `default_density` (`comfortable` / `compact`) | Per-tenant default |
| **Table** | `default_table_density` | Per-tenant default |
| **Sidebar** | `default_sidebar_state` | Per-tenant default |

> Density and sidebar state are per-user preferences. Theme Studio defines the tenant default. Individual users override in `user_preferences`.

### 7.2 Locked Properties

Theme Studio **must not** control:

| Category | Locked Property | Rationale |
|---|---|---|
| **Status** | `status.meaning` | "Pending" always means awaiting action |
| **Status** | `status.color_contract` | Red always signals danger/overdue |
| **Danger** | `danger.emphasis` | Destructive actions always use warning styling + confirmation |
| **Approval** | `approval.context` | Approval panels always show financial impact, documents, audit context |
| **Financial** | `financial.total.visibility` | Totals, balances, amounts always visible |
| **Actions** | `action.priority` | Primary/submit always visually distinct from secondary/cancel |
| **Required** | `required.field_indicator` | Required fields always marked |
| **Workflow** | `workflow.state` | Business logic, not visual configuration |
| **Terminology** | `business.terminology` | "Job Order" means Job Order |
| **Access** | `access.rules` | Role-based visibility is capability-enforced |

### 7.3 Enforcement

1. **Do not expose** locked properties in Theme Studio forms.
2. **Reject** locked properties if submitted via API.
3. **Validate again** during profile activation.
4. **Normalize** during rendering as a final safety layer.

---

## 8. Theme Studio Integration

### 8.1 Discovery

Theme Studio discovers ARK Workbench through `ApplicationProfileRegistry`, not filesystem conventions:

```
ApplicationProfileRegistry::profiles()
→ ["ark.workbench" => ApplicationProfileProvider]
```

### 8.2 What Theme Studio Can Edit

- Brand colors, logo, application name
- Typography (font family, size scale)
- Sidebar appearance (dark/light/branded variant)
- Corner radius
- Spacing scale
- Table zebra striping
- Print branding
- Tenant defaults for density, table density, sidebar state

### 8.3 What Theme Studio Cannot Edit

- Any locked property (see §7.2)
- Status-to-color mappings (these are semantic tones)
- Approval workflows
- Financial display rules
- Required-field indicators
- Business terminology

### 8.4 Customizer Provider

```php
final class ArkWorkbenchProvider implements ThemeCustomizerProvider
{
    public function validate(ThemeCustomizationSubmission $submission): ThemeValidationResult
    {
        $policy = DesignPolicy::load('ark.workbench');

        // Reject locked keys
        foreach ($submission->values as $key => $value) {
            if ($policy->isLocked($key)) {
                return ThemeValidationResult::reject(
                    "{$key} is locked by ARK Workbench design policy"
                );
            }
        }

        // Validate within allowed ranges
        return $policy->validateConstraints($submission->values);
    }

    public function transformContext(ThemeRenderContext $context): ThemeRenderContext
    {
        // Apply token overrides from customizer settings
        return $context->withOverrides($this->resolveTokenOverrides());
    }
}
```

---

## 9. Testing

### 9.1 Test Pyramid

```
Layer 6: Relationship graph      → PHP integration (cross-page context preservation)
Layer 5: Module pages/workflows  → PHP integration (ProjectCompletionTest pattern)
Layer 4: Browser fixture system   → Playwright (WorkbenchFixture + page harnesses)
Layer 3: Component harnesses     → Playwright (ShellHarness, DialogHarness, TableHarness)
Layer 2: Component scenarios     → PHP (TemplateEngine render tests)
Layer 1: Contract tests          → PHP (TestHarness — pure logic + fingerprints)
```

### 9.2 Shared Test Harness (`tests/harness/TestHarness.php`)

Module-agnostic base class. Provides:

| Feature | Method | Purpose |
|---|---|---|
| Sections | `section()` | Group assertions by domain |
| Assertions | `test()`, `assertSame()`, `assertThrows()` | Boolean + comparison assertions |
| Skip | `skip()` | Document intentionally deferred tests |
| Gaps | `gap()` | Document known missing coverage (included in JSON output) |
| Fingerprints | `fingerprint(path)` | Records md5 of source file — detects unnoticed changes |
| Results | `done()` | Writes `test_results/<suite>.json` + aggregated `manifest.json` |
| Integration | `MODE_INTEGRATION` | Loads `bootstrap.php` with tenant host resolution |

**Usage:**

```php
require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('my-module-state-machine');
$h->fingerprint('modules/my-module/services/Workflow.php');

$h->section('Allowed transitions');
$h->test('draft → pending', $workflow::isAllowed('draft', 'pending'));
$h->test('pending → approved', $workflow::isAllowed('pending', 'approved'));

$h->section('Gap analysis');
$h->gap('DB: transition persistence');
$h->gap('DB: audit trail');

$h->done();
```

**Integration mode** (requires tenant host):

```php
$h = new TestHarness('my-module-integration', TestHarness::MODE_INTEGRATION, 'mytenant.test');
// app()->db() now connects to the tenant's database
```

### 9.3 Test Results Output

Every test writes two artifacts:
- **stdout**: Human-readable pass/fail/skip/gap with ✅❌⏭🔍 markers
- **JSON**: Structured `test_results/<suite>.json` with per-assertion detail

**JSON structure:**

```json
{
    "suite": "pal-job-order-workflow",
    "summary": { "passed": 133, "failed": 0, "assertions": 133 },
    "source_fingerprints": {
        "modules/project-audit-ledger/services/JobOrderWorkflow.php": "308285383c5eb5bc"
    },
    "results": [
        { "section": "Exhaustive 8×8 matrix", "label": "draft → pending = ALLOWED",
          "status": "pass", "detail": "", "time": 0.5 }
    ],
    "gaps": {
        "Gap analysis": ["DB: transition persistence"]
    }
}
```

**Aggregated manifest:** `test_results/manifest.json` — combines all suites with pass/fail/assertion/gap totals.

### 9.4 Source Fingerprints

Each test records the md5 hash of every source file it tests. This detects when source code changes without a corresponding test update:

```
🧬 modules/.../JobOrderWorkflow.php — 308285383c5eb5bc...
```

If the hash differs between test runs, the source changed — the test may need updating.

### 9.5 Pure-Logic Test Pattern (highest ROI)

Tests static methods and pure calculations — zero bootstrap, zero DB, zero mocking.

**When:** Service has `static` methods, state machine transitions, or math.
**Where:** `tests/<module>/<feature>_test.php`
**Template:** `tests/pal/pal_job_order_workflow_test.php` (133 assertions, exhaustive 8×8 matrix)

**Key rules:**
1. Cover **every** from→to combination in state machines (N×N matrix)
2. Use `gap()` to document DB-backed guards not covered
3. Fingerprint the source file

### 9.6 Integration Test Pattern

Tests DB-backed business logic through actual service methods.

**Prerequisites:** Tenant host must resolve to a database with module tables.
**Where:** `tests/<module>/<feature>_integration_test.php`
**Template:** `tests/pal/pal_job_order_workflow_integration_test.php` (10 assertions)

**Seed data pattern:**

```php
$testTenantId = 999901; // High ID to avoid conflicts
$cleanup = ['pal_projects', 'pal_clients'];
foreach ($cleanup as $t) { $db->exec("DELETE FROM {$t} WHERE tenant_id = {$testTenantId}"); }

function seedProject(...): int { /* INSERT with unique IDs */ }
```

**Always:** Clean up seed data at the end of the test.

### 9.7 Manifest Contract Test Pattern

Validates `module.json` structure, file existence, and PHP syntax against the filesystem — no DB needed.

**Template:** `tests/project-audit-ledger/pal_manifest_test.php` (60 assertions)

### 9.8 Scaffold Generator

```bash
php scripts/generate-module-test.php <module-id> [--playwright]
```

Auto-generates stub files for any module:
- `tests/<module>/manifest_test.php` — module.json contract
- `tests/<module>/state_machine_test.php` — if state machine detected
- `tests/<module>/integration_test.php` — DB-backed stub
- `tests/browser/modules/<module>/` — Playwright specs (with `--playwright`)

Detects state machines by scanning for status arrays and `forbiddenTransitions` in source code.

### 9.9 Playwright Browser Tests

**Fixture:** `tests/browser/WorkbenchFixture.js`

Provides pre-authenticated page, component harnesses, and integrity tracking:

| Feature | Usage |
|---|---|
| Auto-login | Every test logs in as `paladmin` automatically |
| ShellHarness | `shell.expectVisible()`, `shell.navigateViaSidebar()` |
| DialogHarness | `dialog.open()`, `dialog.confirm()`, `dialog.expectClosed()` |
| TableHarness | `table.rows()`, `table.cellValue()`, `table.expectEmpty()` |
| `integrity.gap()` | Document missing browser coverage |
| `integrity.fingerprint()` | Record template source hashes |
| `integrity.writeResults()` | Write `test_results/browser/<suite>.json` |

**Existing spec files (13, ~77 tests):**

| Suite | Tests | Location |
|---|---|---|
| App shell | 12 | `tests/browser/workbench/app-shell.spec.js` |
| Component conformance | 12 | `tests/browser/workbench/component-conformance.spec.js` |
| Accessibility | 9 | `tests/browser/workbench/accessibility.spec.js` |
| Responsive table | 4 | `tests/browser/workbench/responsive-table.spec.js` |
| PAL Dashboard | 5 | `tests/browser/modules/pal/pages/dashboard.spec.js` |
| PAL Project list | 8 | `tests/browser/modules/pal/pages/project-list.spec.js` |
| PAL Workflow | 12 | `tests/browser/modules/pal/pal-workflow.spec.js` |
| Context preservation | 9 | `tests/browser/modules/pal/relationships/context-preservation.spec.js` |
| Project lifecycle | 6 | `tests/browser/modules/pal/workflows/project-lifecycle.spec.js` |

**Run:**

```bash
npx playwright test tests/browser/workbench/
npx playwright test tests/browser/modules/pal/
```

### 9.10 Component Harnesses

Located in `storage/application-profiles/ark-workbench/testing/harnesses/`:

| Harness | File | Coverage |
|---|---|---|
| `ShellHarness` | `ShellHarness.js` | Sidebar nav, user display, page titles, toast |
| `DialogHarness` | `DialogHarness.js` | Open/close/confirm/cancel, focus trap, backdrop |
| `TableHarness` | `TableHarness.js` | Row counts, cell values, empty state |

Each harness uses `data-wb-component` selectors as stable locators — tests never depend on CSS class names.

### 9.11 Integrity Contract

Every test run produces a machine-readable record of:

1. **What passed and failed** (per-assertion detail)
2. **What was skipped** (intentionally deferred tests)
3. **What gaps remain** (documented missing coverage)
4. **Source file fingerprints** (hashes of tested code)

This means: if source changes but tests don't update, the fingerprint mismatch is visible in the JSON output. No silent regressions.
    }

    public function testRejectsUnknownTone(): void
    {
        $this->expectException(ComponentValidationException::class);

        $this->renderComponent('workbench:status_badge', [
            'label' => 'Test',
            'tone'  => 'nonexistent',
        ]);
    }
}
```

### 9.3 Browser Test Template (Playwright)

```typescript
test('dialog traps focus', async ({ page }) => {
    await page.goto('/admin/project-audit-ledger');
    await page.click('[data-testid="open-dialog"]');

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();

    // Tab should cycle within dialog
    await page.keyboard.press('Tab');
    const focusedId = await page.evaluate(() => document.activeElement?.id);
    expect(focusedId).toMatch(/dialog-/);
});

test('dialog closes on Escape', async ({ page }) => {
    await page.goto('/admin/project-audit-ledger');
    await page.click('[data-testid="open-dialog"]');
    await page.keyboard.press('Escape');

    await expect(page.locator('[role="dialog"]')).not.toBeVisible();
});
```

---

## 10. Migration Guide for Existing Modules

### 10.1 PAL (Reference Implementation)

PAL is the birthplace of ARK Workbench. Its migration is detailed in the architecture plan (`docs/pal/pal-ark-workbench-architecture-plan.md`).

**Current PAL → Workbench mapping:**

| PAL Component | Workbench Component |
|---|---|
| `pal_page_header` | `workbench:page_header` |
| `pal_detail_header` | `workbench:detail_header` |
| `pal_summary_card` | `workbench:summary_card` |
| `pal_money` | `workbench:money` |
| `pal_status_badge` | `workbench:status_badge` |
| `pal_empty_state` | `workbench:empty_state` |
| Shell sidebar/drawer | `workbench:app_shell` + `workbench:mobile_drawer` |
| Team lead bottom nav | `workbench:bottom_navigation` |

### 10.2 Attendance & Wages

**Readiness:** Reads 6 PAL tables. Replace direct table reads with capability contracts before adopting Workbench to ensure clean separation.

**Adoption path:**

1. `workbench:app_shell` — replace admin shell
2. `workbench:responsive_table` — attendance records list
3. `workbench:form_section` — payroll computation form
4. `workbench:detail_header` — employee profile
5. `workbench:status_badge` — payroll period status (map: `open→warning`, `closed→success`)

### 10.3 Guidance

**Readiness:** Clean separation from PAL. Good candidate for Phase 5 proof.

**Adoption path:**

1. `workbench:app_shell` — replace admin shell
2. `workbench:responsive_table` — case list, appointment list
3. `workbench:detail_header` — case detail, appointment detail
4. `workbench:status_badge` — case status (map: `open→warning`, `closed→success`, `cancelled→danger`)
5. `workbench:dialog` — confirmation workflows
6. `workbench:activity_timeline` — case history

### 10.4 WMS (Warehouse Management)

**Adoption path:**

1. `workbench:app_shell`
2. `workbench:responsive_table` — stock list, picklist
3. `workbench:form_section` — stock adjustment, transfer
4. `workbench:status_badge` — batch status, quarantine status
5. `workbench:validation_summary` — receipt validation

### 10.5 EHR

**Adoption path:**

1. `workbench:app_shell`
2. `workbench:detail_header` — patient header
3. `workbench:responsive_table` — appointment list, patient list
4. `workbench:status_badge` — appointment status, triage level
5. `workbench:approval_panel` — clinical approvals

---

## 11. Anti-Patterns and Pitfalls

### 11.1 Do NOT Use Float for Money

```php
// ❌ Wrong
public float $amount;  // Floating-point rounding errors

// ✅ Correct
public int $minorUnits;  // 123456 = ₱1,234.56
public string $formatted;  // "₱1,234.56"
```

### 11.2 Do NOT Put Domain Statuses in Workbench

```di Syl
<!-- ❌ Wrong — domain meaning leaked into presentation -->
{include "workbench:status_badge" status="approved"}

<!-- ✅ Correct — module maps domain → tone -->
{include "workbench:status_badge" label=vm.status.label tone=vm.status.tone}
```

### 11.3 Do NOT Put HTML in View Models

```php
// ❌ Wrong
public string $statusBadgeHtml;  // Pre-rendered HTML

// ✅ Correct
public StatusValue $status;  // Value object, template renders
```

### 11.4 Do NOT Check Roles in Templates

```di Syl
<!-- ❌ Wrong — authorization in template -->
{if user.role == 'admin'}
    <button>Delete</button>
{/if}

<!-- ✅ Correct — auth resolved before view model -->
{for action in vm.actions}
    <a href="{action.url}" class="...">{action.label}</a>
{/for}
```

### 11.5 Do NOT Load All Assets on Every Page

```html
<!-- ❌ Wrong — loads combobox JS on pages without comboboxes -->
<script src="workbench-combobox.js"></script>

<!-- ✅ Correct — asset manifest, load only what the page uses -->
```

The shell should declare which assets a page needs:

```php
final readonly class ApplicationShellViewModel
{
    /** @var string[] */
    public array $requiredAssets;  // ['workbench-dialog.js']
}
```

### 11.6 Do NOT Hardcode Navigation in Shell Templates

```di Syl
<!-- ❌ Wrong — hardcoded PAL navigation in shell -->
<a href="/admin/project-audit-ledger/projects">Job Orders</a>

<!-- ✅ Correct — shell receives navigation from view model -->
{for section in shell.navigation_sections}
    {for item in section.items}
        <a href="{item.url}" class="{if item.is_active}active{/if}">
            {item.label}
        </a>
    {/for}
{/for}
```

### 11.7 Do NOT Use Page Builder for Operational Screens

Job Order forms, payment approvals, inventory issuance, expense entry, audit views — these require tested contracts and predictable behavior. The Page Builder may be used for help pages, onboarding content, and report cover composition, but **never** for transactional or operational interfaces.

---

## Appendix A: Quick Reference — All Component Properties

### Shell

| Component | Key Properties |
|---|---|
| `workbench:app_shell` | `shell` (ApplicationShellViewModel), content block |
| `workbench:mobile_drawer` | `open`, `on_close` |
| `workbench:bottom_navigation` | `items` (NavItem[]), `active_route` |
| `workbench:sidebar_section` | `label`, `icon_key`, `items`, `collapsed_default` |

### Page

| Component | Key Properties |
|---|---|
| `workbench:page_header` | `title`, `subtitle`, `breadcrumb`, `back_url`, `actions` |
| `workbench:detail_header` | `title`, `subtitle`, `status_badge`, `metrics`, `actions`, `tabs` |

### Data

| Component | Key Properties |
|---|---|
| `workbench:summary_card` | `label`, `value`, `icon_key`, `tone`, `trend`, `href`, `variant` |
| `workbench:money` | `formatted`, `is_negative`, `emphasis`, `align` |
| `workbench:status_badge` | `label`, `tone`, `variant`, `size` |
| `workbench:progress` | `percent`, `label`, `tone`, `size` |
| `workbench:responsive_table` | `columns`, `rows`, `empty_message`, `row_actions`, `pagination` |
| `workbench:empty_state` | `icon_key`, `title`, `description`, `action`, `size`, `bordered` |

### Forms

| Component | Key Properties |
|---|---|
| `workbench:form_section` | `title`, `description`, `collapsible`, `collapsed`, `has_errors`, `body` |
| `workbench:validation_summary` | `errors`, `heading` |
| `workbench:combobox` | `name`, `options`, `selected_value`, `placeholder`, `multiple`, `required`, `disabled`, `empty_message` |

### Interaction

| Component | Key Properties |
|---|---|
| `workbench:dialog` | `id`, `title`, `body`, `footer`, `variant`, `close_on_backdrop` |
| `workbench:activity_timeline` | `items`, `variant`, `load_more_url` |
| `workbench:approval_panel` | `request_summary`, `financial_impact`, `documents`, `previous_decisions`, `proposed_changes`, `audit_context`, `actions`, `rejection_reason_required` |

---

## Appendix B: Semantic Tone Reference

| Tone | Meaning | Examples | Visual |
|---|---|---|---|
| `neutral` | Inactive, draft, informational | Draft, Inactive, Archived | Gray |
| `informational` | Note, reference, hint | Info, Note, Reference | Blue |
| `warning` | Needs attention, pending, due soon | Pending, Submitted, Due Soon | Yellow/Amber |
| `success` | Completed, approved, paid, active | Approved, Paid, Active, Completed | Green |
| `danger` | Rejected, overdue, error, blocked | Rejected, Overdue, Cancelled, Voided | Red |

**Rule:** Module maps domain status → semantic tone. Workbench maps semantic tone → visual presentation. The module owns the meaning; Workbench owns the look.

---

## Appendix C: Asset Manifest Format

```json
{
    "version": "1.0",
    "assets": {
        "styles": {
            "workbench.css": {
                "path": "assets/workbench.css",
                "hash": "sha256-...",
                "size": "14KB"
            }
        },
        "scripts": {
            "workbench-core.js": {
                "path": "assets/workbench-core.js",
                "hash": "sha256-...",
                "size": "8KB",
                "provides": ["shell", "drawer", "bottom-nav", "validation-summary"]
            },
            "workbench-dialog.js": {
                "path": "assets/workbench-dialog.js",
                "hash": "sha256-...",
                "size": "4KB",
                "provides": ["dialog"]
            },
            "workbench-combobox.js": {
                "path": "assets/workbench-combobox.js",
                "hash": "sha256-...",
                "size": "6KB",
                "provides": ["combobox"]
            },
            "workbench-table.js": {
                "path": "assets/workbench-table.js",
                "hash": "sha256-...",
                "size": "3KB",
                "provides": ["responsive-table"]
            }
        }
    }
}
```

The `provides` array maps script files to the components they enable. The shell uses this to load only the scripts needed by the current page.

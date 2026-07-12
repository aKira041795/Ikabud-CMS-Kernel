# ARK Workbench Candidate 0.1

**Status**: Candidate — Phase 1 Freeze
**Date**: 2026-07-12
**Source**: Extracted from PAL reference implementation at commit `259dba3`

---

## Component Catalog

Each component below is annotated with its PAL source and extraction plan.

### Shell & Navigation

| Candidate | PAL Source | PAL File | Lines |
|---|---|---|---|
| `workbench:app_shell` | `shell.disyl` sidebar + layout | `templates/.../shell.disyl` | 194 |
| `workbench:mobile_drawer` | `shell.disyl` mobile sidebar toggle + overlay | `shell.disyl` L17-27 | — |
| `workbench:bottom_navigation` | `team-lead-shell.disyl` bottom nav | `team-lead-shell.disyl` L56-72 | 17 |
| `workbench:sidebar_section` | Shell Alpine collapsible sections | `shell.disyl` L33-80 | ~50 |

### Page Structure

| Candidate | PAL Source | PAL File | Lines |
|---|---|---|---|
| `workbench:page_header` | `pal_page_header.disyl` | `components/pal_page_header.disyl` | 10 |
| `workbench:detail_header` | `pal_detail_header.disyl` | `components/pal_detail_header.disyl` | 23 |

### Data Display

| Candidate | PAL Source | PAL File | Lines |
|---|---|---|---|
| `workbench:summary_card` | `pal_summary_card.disyl` | `components/pal_summary_card.disyl` | 18 |
| `workbench:money` | `pal_money.disyl` | `components/pal_money.disyl` | 5 |
| `workbench:status_badge` | `pal_status_badge.disyl` | `components/pal_status_badge.disyl` | 16 |
| `workbench:progress` | _(new)_ | — | — |
| `workbench:responsive_table` | PAL table patterns across all list pages | Multiple list templates | — |
| `workbench:empty_state` | `pal_empty_state.disyl` | `components/pal_empty_state.disyl` | 8 |

### Forms

| Candidate | PAL Source | PAL File |
|---|---|---|
| `workbench:form_section` | Job Order form sections | `pages/project-form.disyl` |
| `workbench:validation_summary` | _(new)_ | — |
| `workbench:combobox` | PAL autocomplete patterns | `pal-core.js` |

### Interaction

| Candidate | PAL Source | PAL File |
|---|---|---|
| `workbench:dialog` | PAL modal/toast patterns | `pal-core.js` |
| `workbench:activity_timeline` | Audit trail | `pages/audit-trail.disyl` |
| `workbench:approval_panel` | Approval queue | `pages/approval-queue.disyl` |

---

## PAL Component → Workbench Extraction Audit

### `pal_page_header` → `workbench:page_header`

**PAL implementation (10 lines):**
```di Syl
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{_ph_title}</h1>
        {if _ph_subtitle|default:''}<p class="text-sm text-gray-600 mt-1">{_ph_subtitle}</p>{/if}
    </div>
    {if _ph_actions|default:''}<div class="flex items-center gap-2">{_ph_actions|raw}</div>{/if}
</div>
```

**Extraction changes:**
- Hardcoded Tailwind classes → Workbench CSS tokens (`var(--wb-heading-xl)`, `var(--wb-text-primary)`, `var(--wb-text-secondary)`)
- Raw HTML `_ph_actions|raw` → structured `actions: ActionValue[]`
- Add `breadcrumb` slot
- Add `back_url` for mobile
- Remove `mb-6` margin (caller controls spacing)

**Used in:** 48 of 54 page templates

---

### `pal_detail_header` → `workbench:detail_header`

**PAL implementation (23 lines):**
- Flex row: title + status badge
- Subtitle line
- 4-column metric grid (total, collected, outstanding, target)
- Raw HTML action slot

**Extraction changes:**
- 4-column hardcoded grid → N-column dynamic metric grid
- Each metric: `{label, value, variant}`
- Status badge uses `workbench:status_badge` with semantic tone
- Raw HTML actions → `actions: ActionValue[]`
- Add `tabs` slot for tab navigation

**Used in:** 10 detail templates

---

### `pal_summary_card` → `workbench:summary_card`

**PAL implementation (18 lines):**
- Icon square (10×10 rounded) with 5 hardcoded Tailwind color variants
- Label (text-sm text-gray-600)
- Value (text-xl font-bold)

**Extraction changes:**
- Colors → token-driven (`var(--wb-stat-blue)`, `var(--wb-stat-green)`, etc.)
- Add `trend` indicator (up/down arrow)
- Add `href` for clickable cards
- Support `compact` variant
- Semantic tone instead of hardcoded color name

**Used in:** 3 dashboard templates, scattered across detail pages

---

### `pal_money` → `workbench:money`

**PAL implementation (5 lines):**
```di Syl
<span class="{_m_class|default:''} {if _m_amount|default:0 < 0}text-red-600{else}text-gray-900{/if}">
    {if _m_label|default:''}<span class="text-xs text-gray-500 mr-1">{_m_label}</span>{/if}
    ₱{_m_amount|default:0|number_format:2}
</span>
```

**Issues for extraction:**
- Hardcoded `₱` symbol
- Hardcoded `number_format:2`
- Inline formatting logic

**Extraction changes:**
- Module supplies pre-formatted `formatted` string
- Workbench controls only: alignment, emphasis (primary/secondary/muted), negative tone
- Currency symbol and decimal places are application/locale data

**Used in:** 30+ templates

---

### `pal_status_badge` → `workbench:status_badge`

**PAL implementation (16 lines):**
- 11 hardcoded domain statuses mapped to Tailwind colors
- `capitalize` filter on status string

**Issues for extraction:**
- Domain statuses (`draft`, `pending`, `approved`, `started`, `ongoing`, `completed`, `paid`, `overdue`, `rejected`, `cancelled`) hardcoded
- PAL-specific status vocabulary

**Extraction changes:**
- Semantic tones only: `neutral`, `informational`, `warning`, `success`, `danger`
- Module maps domain status → tone
- Support `pill` and `dot` variants
- Support `sm`, `md`, `lg` sizes

**Used in:** 40+ templates

---

### `pal_empty_state` → `workbench:empty_state`

**PAL implementation (8 lines):**
- Centered icon (emoji)
- Title (text-lg font-semibold)
- Optional description (text-sm text-gray-600)
- Optional action (raw HTML)

**Extraction changes:**
- Add `illustration` slot for SVGs
- Add `size` variant (`sm` for inline, `lg` for full-page)
- Add `bordered` variant
- Raw HTML action → structured ActionValue

**Used in:** 15+ templates

---

## New Components (No PAL Source)

### `workbench:responsive_table`
**PAL precedent:** Card-stack behavior already present in PAL list templates at mobile breakpoint.
**Extraction:** Formalize as governed component with `data-label` mobile card mode.

### `workbench:form_section`
**PAL precedent:** Job Order form uses grouped sections. Not formalized as reusable component.
**Extraction:** Extract the section grouping pattern.

### `workbench:validation_summary`
**PAL precedent:** No existing error summary pattern. Toast-based feedback only.
**Extraction:** New component for accessible form error handling.

### `workbench:combobox`
**PAL precedent:** Autocomplete behavior in `pal-core.js`.
**Extraction:** Formalize into governed component with keyboard navigation.

### `workbench:dialog`
**PAL precedent:** Modal patterns in `pal-core.js` (toast, confirmation).
**Extraction:** Formalize with focus trap, Escape close, aria-modal.

### `workbench:activity_timeline`
**PAL precedent:** Audit trail page.
**Extraction:** Extract timeline layout pattern.

### `workbench:approval_panel`
**PAL precedent:** Approval queue page.
**Extraction:** Extract approval review structure as governed component.

---

## Shell Extraction Notes

### Admin Shell → `workbench:app_shell`

**What gets extracted:**
- Sidebar layout structure (flex container, aside + main)
- Mobile drawer behavior (transform translate, overlay)
- Alpine.js section collapse pattern
- Toast container (accessible, aria-live)

**What stays in PAL:**
- Specific navigation items (these come from `ApplicationShellViewModel`)
- Hardcoded PAL route URLs
- PAL-specific JS functions (quick-create, user mgmt dialogs)

**Current coupling issues:**
- Navigation is hardcoded in shell template
- Section names ("Job Orders", "Sales & Billing", "Inventory & Procurement") are PAL-specific
- Active state detection uses `{page_content}` matching against PAL route names

### Team Lead Shell → `workbench:bottom_navigation`

**What gets extracted:**
- Bottom nav bar layout (flex justify-around)
- Active state detection pattern
- Mobile-first responsive breakpoint

**What stays in PAL:**
- Specific nav items (Home, Fabrication, Attendance, Cash)
- OTP authentication flow

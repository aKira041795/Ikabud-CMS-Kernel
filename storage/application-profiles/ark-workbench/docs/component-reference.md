# ARK Workbench Component Reference

## Shell Components

### `workbench:app_shell`
Full application chrome: sidebar, header, content area, bottom navigation.
Includes mobile drawer, overlay, and sidebar sections.
- **File**: `components/shell/app_shell.disyl`
- **Layouts**: `layouts/app-shell.disyl`, `layouts/app-shell-mobile.disyl`

## Page Components

### `workbench:page_header`
Page title, subtitle, breadcrumb, structured actions.
- **File**: `components/page/page_header.disyl`

### `workbench:detail_header`
Entity identity, status, metrics grid, tabs.
- **File**: `components/page/detail_header.disyl`

## Data Components

### `workbench:summary_card`
Metric card: label, value, icon, tone, trend.
- **File**: `components/data/summary_card.disyl`

### `workbench:money`
Currency display: alignment, emphasis, negative tone.
- **File**: `components/data/money.disyl`

### `workbench:status_badge`
Semantic tone badge: neutral, informational, warning, success, danger.
- **File**: `components/data/status_badge.disyl`

### `workbench:progress`
Progress bar with label, tone, ARIA.
- **File**: `components/data/progress.disyl`

### `workbench:responsive_table`
Semantic table with data-label mobile card mode.
- **File**: `components/data/responsive_table.disyl`

### `workbench:empty_state`
Empty placeholder with icon, title, action.
- **File**: `components/data/empty_state.disyl`

## Form Components

### `workbench:form_section`
Grouped fields with header, collapsible toggle.
- **File**: `components/forms/form_section.disyl`

### `workbench:validation_summary`
Accessible error list with field links.
- **File**: `components/forms/validation_summary.disyl`

### `workbench:combobox`
Searchable select with keyboard navigation.
- **File**: `components/forms/combobox.disyl`
- **Requires**: `workbench-combobox.js`

## Interaction Components

### `workbench:dialog`
Modal with focus trap, Escape close.
- **File**: `components/interaction/dialog.disyl`
- **Requires**: `workbench-dialog.js`

### `workbench:activity_timeline`
Chronological event feed.
- **File**: `components/interaction/activity_timeline.disyl`

### `workbench:approval_panel`
Approval review: impact, evidence, decisions, actions.
- **File**: `components/interaction/approval_panel.disyl`

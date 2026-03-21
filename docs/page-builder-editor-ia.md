---
description: Page Builder Editor Information Architecture — UI Regions, Workflows, and Interaction Model
---

# Page Builder Editor Information Architecture

This document defines the information architecture and interaction model for the CMS page builder editor.

---

# 1. Design Objective

Create a builder editor that feels intuitive to non-technical content editors while remaining structured enough for long-term maintainability.

The editor should optimize for:

- clarity of structure
- low-friction page assembly
- strong selection/context awareness
- progressive disclosure of advanced controls
- alignment between preview and public output

---

# 2. Primary User Modes

## 2.1 Create Mode

User starts from:

- blank page
- starter template
- duplicate existing page
- convert existing standard page later if supported

## 2.2 Edit Mode

User modifies an existing builder page.

## 2.3 Review/Preview Mode

User validates desktop/tablet/mobile presentation before publish.

---

# 3. Top-Level Screen Layout

Recommended screen regions:

```text
+---------------------------------------------------------------+
| Top Bar: back | page title | save | preview | publish | device |
+----------------------+----------------------------+------------+
| Left Sidebar         | Main Canvas                | Right      |
|                      |                            | Sidebar    |
| - Insert             | - Visual preview           |            |
| - Layers/Navigator   | - Drop zones              | - Content   |
| - Templates          | - Selection outlines      | - Style     |
| - Reusables          | - Inline editing          | - Layout    |
|                      |                            | - Advanced  |
+----------------------+----------------------------+------------+
```

---

# 4. Top Bar Architecture

## Required Controls

- **Back to Pages**
- **Page Title / Editable Label**
- **Save Draft**
- **Preview**
- **Publish / Update**
- **Device Switcher**
  - desktop
  - tablet
  - mobile
- **Undo / Redo**
- **More Actions**
  - revisions
  - duplicate page
  - page settings
  - delete/trash

## Top Bar Design Rule

The top bar should expose global actions only. It should not become a second inspector.

---

# 5. Left Sidebar IA

The left sidebar is the **insertion and navigation area**.

## 5.1 Insert Tab

Purpose:

- add new sections
- add new widgets
- search available items
- browse by category

Sections:

- search field
- quick-start layout blocks
- layout primitives
- basic widgets
- marketing widgets
- dynamic widgets

## 5.2 Layers / Navigator Tab

Purpose:

- expose the document tree
- support fast selection
- allow drag-based reordering in structure view

Each row should show:

- node icon
- node label
- nesting level
- visibility/lock markers if later added

## 5.3 Templates Tab

Purpose:

- browse starter page templates
- filter by page type or category
- apply template to current page with confirmation

## 5.4 Reusable Sections Tab

Purpose:

- browse reusable section library
- insert shared fragments into current page
- search by name/category in later phases

---

# 6. Main Canvas IA

The canvas is the **primary interaction surface**.

## 6.1 Canvas Responsibilities

- display the current page visually
- reveal valid insertion/drop zones
- support hover, selection, move, duplicate, delete
- support inline editing where appropriate
- reflect responsive preview mode

## 6.2 Selection States

Every selectable node should have:

- hover outline
- selected outline
- compact local toolbar
- clear parent/child relationship cues

## 6.3 Empty States

When empty, the canvas should guide the user explicitly:

- add section
- start from template
- insert reusable section

## 6.4 Local Toolbar

On selected node, show lightweight contextual actions:

- edit
- duplicate
- move
- delete
- save as reusable section for eligible nodes

---

# 7. Right Sidebar IA

The right sidebar is the **properties inspector**.

Use progressive disclosure. The default experience should remain simple.

## 7.1 Content Panel

Used for semantic widget settings.

Examples:

- heading text and level
- image selection and alt text
- button label and URL
- FAQ items
- testimonial author and quote

## 7.2 Style Panel

Used for constrained visual choices.

Examples:

- preset/variant
- alignment
- spacing scale
- color token
- typography scale
- border radius token

## 7.3 Layout Panel

Used for container or positioning behavior.

Examples:

- width
- max width
- direction
- justify/align
- gap
- column count
- stacking behavior

## 7.4 Responsive Panel

Used for per-breakpoint overrides.

Examples:

- hide on mobile
- mobile text alignment
- tablet spacing adjustments
- stacked vs inline layout

## 7.5 Advanced Panel

Restricted to safe advanced options.

Examples:

- anchor ID
- accessibility label
- conditional visibility later
- privileged raw HTML settings if allowed

---

# 8. Core Editing Workflows

## 8.1 Create a Builder Page

1. user clicks create page
2. chooses `Builder Page`
3. selects blank canvas or starter template
4. editor opens directly into builder screen

## 8.2 Add a Section

1. user clicks add section on canvas or inserter
2. chooses structure preset or blank section
3. section appears with default container
4. focus moves to inserted section

## 8.3 Add a Widget

1. user selects target container
2. opens insert panel
3. searches/selects widget
4. widget is inserted and selected
5. right inspector opens content controls

## 8.4 Save as Reusable Section

1. user selects eligible section
2. chooses save as reusable
3. enters name
4. system stores fragment
5. reusable section becomes available in library

## 8.5 Publish

1. user clicks publish
2. system validates document
3. validation issues shown inline if present
4. if valid, publish snapshot is stored
5. preview/public cache invalidated

---

# 9. Navigation Model

## Parent/Child Context

The editor should always show where the selected node lives.

Recommended cues:

- breadcrumb path above inspector or canvas
- layers panel synchronized with current selection
- parent selection shortcut

Example:

`Homepage > Hero Section > Inner Container > Heading`

---

# 10. Responsive Editing Model

## Device Modes

- desktop
- tablet
- mobile

## Principle

Device mode should change the preview and available override controls, but not create independent disconnected layouts.

## Responsive Rule Model

- desktop is base
- tablet overrides base where specified
- mobile overrides base/tablet where specified

---

# 11. Error and Validation UX

## Validation Categories

- invalid widget config
- invalid URL/media reference
- unsupported nesting
- missing required prop
- privileged widget not allowed

## UX Rules

- show validation issues inline and in a summary list
- map issues to exact node path when possible
- allow preview validation without publish
- block publish on structural failures

---

# 12. Permissions and Governance UX

## Editor-Level Restrictions

The UI should reflect policy clearly.

Examples:

- hidden widgets for non-privileged users
- disabled advanced panels when not allowed
- warnings when a widget is deprecated or migrated

## Governance Principle

Do not show users dangerous controls they are not allowed to use.

---

# 13. Recommended Initial Sitemap for Builder UI

## Page List

- `CMS > Pages`
- `Create Page`
- `Edit Standard Page`
- `Edit Builder Page`

## Builder Subflows

- builder canvas
- template chooser
- reusable sections library
- revisions drawer/modal
- page settings panel

---

# 14. Editor Success Metrics

The editor information architecture is successful if:

- new editors can create a usable page without training-heavy documentation
- common actions are discoverable in under a minute
- structure is always visible and understandable
- preview and public output match closely
- advanced power is available without overwhelming basic users

---

# 15. Recommended Outcome

The editor should feel like a visual design workspace, but the information architecture must constantly reinforce structure, hierarchy, and safe control. That is the balance required to achieve Elementor-like usability without sacrificing long-term system integrity.

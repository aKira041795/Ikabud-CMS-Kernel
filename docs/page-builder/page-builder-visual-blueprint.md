---
description: Actionable blueprint for a visual-first CMS page builder
---

# Page Builder Visual Blueprint

This document converts the visual-first page builder direction into an implementation-ready blueprint. It is driven by user experience first. The backend handles validation, persistence, rendering, and governance invisibly.

## Objective

Build a page builder that feels visual first, intuitive to non-technical editors, direct-manipulation oriented, safe and reversible, responsive by default, and structurally governed behind the scenes.

## Core Product Promise

A user should be able to add a section visually, drag content where they want it, edit what they see, style it without technical language, preview device modes, and publish confidently — without understanding the document tree.

---

# 1. Editor Shell

## 1.1 Screen Layout

```text
+--------------------------------------------------------------------------------+
| Top Bar                                                                        |
| Back | Page Title | Save State | Undo/Redo | Device | Preview | History | Save |
+---------------------------+--------------------------------+-------------------+
| Left Panel                | Canvas                         | Inspector         |
| Elements                  | Live page surface              | Content           |
| Sections                  | Hover outlines                 | Style             |
| Templates                 | Inline add rails               | Advanced          |
| Navigator                 | Drag/drop zones                | Responsive        |
| Page                      | Quick actions                  |                   |
+---------------------------+--------------------------------+-------------------+
```

## 1.2 Shell Rules

- Canvas is the primary workspace.
- Left panel is for insertion and navigation.
- Right panel edits the selected object.
- Top bar contains global controls only.
- Navigator is secondary, never the main editing surface.

---

# 2. Top Bar

## Controls

- Back to Pages
- Editable page title
- Save state indicator (Saved / Unsaved / Autosaving / Draft / Published)
- Save Draft button
- Preview button
- Publish / Update button
- Undo / Redo
- Device switcher (desktop / tablet / mobile)
- History / Revisions toggle
- Navigator toggle
- More actions (duplicate page, page settings)

## Rules

- Device switcher updates canvas width and responsive inspector context.
- Undo/redo reflects visual actions, not just field edits.
- Top bar behavior is independent of panel tab state.

---

# 3. Left Panel

## Tabs

Elements | Sections | Templates | Navigator | Page

## 3.1 Elements Tab

Search field, category groups, card items with icon + label + description.

Categories: Basic, Media, Layout, Dynamic, Marketing, Advanced.

Core widgets: Heading, Text, Image, Button, Divider, Spacer, Gallery, Quote, Embed, Posts List, Dynamic Field.

## 3.2 Sections Tab

Blank layouts: single column, two columns, three columns, asymmetrical split, full-width hero, boxed section.

Patterns: hero, hero with CTA, feature grid, image/text split, testimonial section, FAQ section, contact strip, latest posts.

## 3.3 Templates Tab

Full-page templates and reusable saved sections with thumbnail, name, category, and source tag (system / shared / personal).

## 3.4 Navigator Tab

Nested tree, expand/collapse, drag reorder, selection sync with canvas, per-node icon, later visibility/lock markers.

## 3.5 Page Tab

Page template, page width mode, container/body class, status info, later SEO.

---

# 4. Canvas

The canvas is the visual page surface. It should feel like editing a real page.

## 4.1 Empty State

Show: Start from Template, Add First Section, Browse Patterns. Must feel inviting.

## 4.2 Hover State

Subtle outline, object label, drag handle, insertion affordance.

## 4.3 Selected State

Strong outline, quick action toolbar, inspector sync, breadcrumb path.

## 4.4 Drag State

Valid drop zones illuminate, invalid zones inert, insertion preview explicit, before/after/inside semantics visually distinct.

## 4.5 Inline Insert Controls

Add Section above/below, Add Widget inside, Add Container inside. Users must be able to build without opening the navigator.

## 4.6 Quick Action Toolbar

Section: edit, drag, duplicate, delete, save as reusable, add above, add below.
Container: edit, drag, duplicate, delete, add widget, add nested container.
Widget: edit, drag, duplicate, delete, copy styles later.

---

# 5. Inspector

## Tabs

Content | Style | Advanced | Responsive

## 5.1 Content Tab

Heading: text, level, alignment, link.
Text: rich content, alignment.
Image: media picker, alt, caption, fit, link.
Button: label, URL, variant, size.

## 5.2 Style Tab

Text color, background, typography scale, spacing presets, border radius, shadow, alignment, width presets.

## 5.3 Advanced Tab

Margin, padding, anchor ID, custom class if allowed, later motion/z-index/visibility rules.

## 5.4 Responsive Tab

Hide on device, alignment override, spacing override, width override, stack behavior override.

## Rules

Show only relevant controls. Use human language. Keep advanced collapsed by default. Show inherited responsive values muted.

---

# 6. UI Object Model

Three primary concepts visible to the user.

## Section

A major page band. Controls: background, outer spacing, content width, preset, responsive visibility.

## Container

An internal layout wrapper. Controls: direction, gap, alignment, width, column/grid behavior, stack behavior on smaller screens.

## Widget

An atomic content element. Examples: heading, text, image, button, divider, spacer.

---

# 7. Interaction Contracts

## Selection

Click object on canvas -> object selected -> inspector updates -> navigator syncs -> breadcrumb updates -> quick toolbar appears.

## Insert

Available through: inline canvas `+` controls, drag from panel, click-to-insert. Rule: user must always know where insertion will occur.

## Drag

Drag handle initiates, target zones appear, result previewed before drop, move completes with confirmation.

## Delete

Immediate, undoable, confirmation only for large structural deletes.

## Duplicate

Adjacent to original, becomes selected, ID regeneration is backend concern.

## Responsive

Device switch changes canvas width, inspector shows responsive controls, inherited values muted, hidden items discoverable in navigator.

---

# 8. Editor State

## Required Domains

selectedObjectId, hoveredObjectId, activeLeftTab, activeInspectorTab, deviceMode, dragState, insertionState, inlineEditState, historyState, saveState, validationState.

## Drag State

draggedId, draggedType, sourceParentId, sourceIndex, targetParentId, targetIndex, dropMode (before/after/inside).

## Save State

dirty, saving, autosavePending, autosaveFailed, lastSavedAt, publishStatus.

## History State

Must capture: insert, move, duplicate, delete, content edit, style edit, template apply, reusable insert.

---

# 9. Frontend / Backend Boundary

## Principle

The frontend editing state does not mirror the persistence schema exactly. Frontend optimizes for interaction. Backend optimizes for storage, validation, rendering, migration.

## Translation Layer

Visual editor state -> canonical document payload.
Canonical document payload -> visual editor state.
Validation issues -> user-facing canvas/inspector locations.

## Backend Owns

Canonical validation, schema migration, widget registry rules, save/publish/revisions, public rendering, reusable templates/sections.

## Frontend Owns

Visual selection, panel state, drag/drop, inline editing, device preview, history UX.

---

# 10. Validation UX

## Types

- missing required content
- invalid URL/media
- unsupported nesting
- unavailable widget
- invalid responsive override
- policy restriction failure

## Rules

- show global summary panel or toast-linked drawer
- map issues to exact canvas objects when possible
- highlight affected object on canvas
- keep messages human-readable
- block publish on structural failures
- allow save draft with non-fatal warnings

---

# 11. Reusable Content

## Reusable Section Flow

Select section -> Save as Reusable -> enter name and scope -> available from Templates tab.

## Template Apply Flow

Browse templates visually -> preview with thumbnail -> confirm replacement if page has content -> apply -> push undo entry.

## Pattern Insert Flow

Choose section pattern -> insert at selected rail -> select inserted section automatically.

---

# 12. Visual Design Rules

## Tone

Calm, premium, light, precise, modern. Not sterile. Not overloaded.

## Rules

- subtle hover outlines
- strong accent for selection
- compact toolbars
- generous whitespace
- restrained shadows
- minimal visual noise
- legible icons and adequate hit targets

## Color Logic

- neutral for chrome
- accent for active selection
- success for saved/published
- warning for unsaved/caution
- danger for destructive actions

---

# 13. Accessibility Baseline

- keyboard reachable top-bar controls
- keyboard-accessible panel tabs
- clear focus states on all interactive elements
- descriptive labels for icon buttons
- adequate hit targets for hover-revealed controls
- state changes not communicated by color alone

---

# 14. Component Map

## Shell Components

- BuilderShell — root layout frame
- BuilderTopBar — global actions bar
- BuilderLeftPanel — mode-tabbed insertion/navigation
- BuilderCanvas — live page editing surface
- BuilderInspector — contextual property editor
- BuilderNavigator — hierarchy tree
- BuilderHistoryDrawer — revision/undo timeline

## Canvas Components

- CanvasPageFrame — page-width container with device preview
- SectionFrame — section outline, hover, selection, inline controls
- ContainerFrame — container outline, layout indicators
- WidgetFrame — widget outline, inline editing shell
- InlineAddRail — `+` insertion controls between/inside objects
- DropIndicator — drag-and-drop placement preview
- QuickActionToolbar — contextual actions floating near selection
- BreadcrumbBar — selection path display

## Panel Components

- ElementsLibrary — searchable widget card grid
- SectionsLibrary — blank layouts and pattern cards
- TemplatesLibrary — page templates and reusable section browser
- NavigatorTree — hierarchy tree with drag reorder
- PageSettingsPanel — page-level configuration
- InspectorContentTab — semantic widget content controls
- InspectorStyleTab — visual styling controls
- InspectorAdvancedTab — spacing, IDs, classes, advanced rules
- InspectorResponsiveTab — per-breakpoint overrides

## Store / Service Modules

- builderEditorStore — root editor state orchestrator
- builderSelectionStore — selected/hovered object tracking
- builderHistoryStore — undo/redo stack management
- builderPersistenceService — save/autosave/publish coordination
- builderTranslationService — visual state <-> canonical document conversion
- builderValidationMapper — backend validation -> UI location mapping

---

# 15. Rollout Phases

## Phase 1 — Visual Shell

Deliver:

- top bar redesign
- left panel tab model (Elements, Sections, Templates, Navigator, Page)
- right inspector shell with Content/Style/Advanced/Responsive tabs
- canvas-first layout with realistic page width
- hover and selected states with outlines
- quick action toolbar on selection
- inline add rails between sections and inside containers

Acceptance:

- users can build basic pages without navigator
- selection and insertion feel visually predictable
- the editor looks and feels like a visual workspace

## Phase 2 — Inspector and Editing UX

Deliver:

- per-widget Content tab controls
- Style tab with typography, color, background, spacing controls
- Advanced tab with margin, padding, anchor, class
- Responsive tab with per-device overrides
- inline text editing for heading and text widgets

Acceptance:

- users can style widgets without technical knowledge
- responsive overrides are discoverable and understandable
- editing feels contextual, not form-heavy

## Phase 3 — Section System and Templates

Deliver:

- section preset layouts (single, two-col, three-col, asymmetric, hero, boxed)
- prebuilt section patterns (hero, CTA, features, testimonial, FAQ, contact, posts)
- reusable section save/load
- visual template browser with thumbnails

Acceptance:

- users can start from attractive defaults
- repeated layouts can be saved and reused
- template browsing feels visual, not list-based

## Phase 4 — Drag-Drop and Navigation Polish

Deliver:

- refined drag-drop with clear before/after/inside indicators
- improved navigator with collapse/expand, rename, visibility
- breadcrumb bar for deep selection context
- history drawer with named visual actions

Acceptance:

- complex pages remain manageable
- drag-drop feels predictable and safe
- users can navigate deep structures without confusion

## Phase 5 — Power Features

Deliver:

- right-click context menus
- copy/paste styles
- keyboard shortcuts surfaced in tooltips
- animation/motion controls
- conditional visibility rules
- deeper dynamic widget library

Acceptance:

- power-user features do not crowd novice workflows
- advanced controls are discoverable but not intrusive

---

# 16. Build Priorities

## Highest Priority

- canvas-first shell
- inline insertion controls
- contextual inspector with tabs
- clean drag/drop semantics
- responsive mode switching

## Medium Priority

- section presets and patterns
- navigator refinement
- templates and reusable patterns
- revision/history UX

## Later Priority

- advanced motion/animation
- richer dynamic widgets
- global reusable synchronization
- conditional visibility rules
- marketplace/extension ecosystem

---

# 17. Success Metrics

The blueprint is successful when:

- a visual user can create a complete page without documentation
- the navigator is optional for common workflows
- users rely on canvas actions more than structure editing
- responsive editing is understandable without training
- save/publish states feel safe and trustworthy
- the interface is described as intuitive and beautiful
- first meaningful page creation takes minutes, not hours
- the builder is competitive with Elementor-class editors in feel

---

# 18. Final Build Principle

The CM Page Builder should present a beautiful, intuitive visual workspace while the backend quietly handles document normalization, validation, responsive inheritance, rendering, publishing, reuse, and performance.

The user thinks in layout, content, spacing, and mood.
The system thinks in nodes, validation, metadata, and rendering.

These two worlds must be cleanly separated.

The beauty of this builder will be that the user never sees the machine — they only see their page coming to life.

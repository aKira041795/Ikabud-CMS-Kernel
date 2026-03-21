# CSS Architecture Plan

## Native Default Theme - Modular CSS Structure

**Version:** 2.1.0  
**Date:** December 2024

---

## Overview

This document outlines the CSS architecture for the Native Default theme. The goal is to maintain separation of concerns, improve maintainability, and enable efficient development as the theme grows.

---

## Directory Structure

```
assets/css/
├── theme.css                 # Main entry point - imports all partials
├── base/
│   ├── _variables.css        # CSS custom properties (design tokens)
│   ├── _reset.css            # Reset & base styles
│   └── _typography.css       # Typography rules
├── layout/
│   ├── _container.css        # Container & grid system
│   ├── _header.css           # Header & navigation
│   ├── _footer.css           # Footer styles
│   └── _sidebar.css          # Sidebar layout
├── components/
│   ├── _buttons.css          # Button styles
│   ├── _forms.css            # Form elements & native forms
│   ├── _cards.css            # Post/content cards
│   ├── _widgets.css          # Widget styles
│   ├── _pagination.css       # Pagination component
│   └── _comments.css         # Comments section
├── pages/
│   ├── _single.css           # Single post/page styles
│   ├── _archive.css          # Archive/blog listing
│   └── _search.css           # Search results page
└── utilities/
    └── _utilities.css        # Utility classes & helpers
```

---

## File Naming Convention

- **Partials** are prefixed with underscore: `_filename.css`
- **Main entry** has no underscore: `theme.css`
- Use **kebab-case** for multi-word names: `_post-cards.css`

---

## Import Order (theme.css)

```css
/* ===========================================
   Native Default Theme - Main Stylesheet
   Version: 2.1.0 (Modular Architecture)
   =========================================== */

/* Base - Foundation styles */
@import 'base/_variables.css';
@import 'base/_reset.css';
@import 'base/_typography.css';

/* Layout - Structural components */
@import 'layout/_container.css';
@import 'layout/_header.css';
@import 'layout/_footer.css';
@import 'layout/_sidebar.css';

/* Components - Reusable UI elements */
@import 'components/_buttons.css';
@import 'components/_forms.css';
@import 'components/_cards.css';
@import 'components/_widgets.css';
@import 'components/_pagination.css';
@import 'components/_comments.css';

/* Pages - Page-specific styles */
@import 'pages/_single.css';
@import 'pages/_archive.css';

/* Utilities - Helper classes (load last) */
@import 'utilities/_utilities.css';
```

---

## CSS Guidelines

### 1. BEM-like Naming
```css
/* Block */
.native-form { }

/* Element */
.native-form__field { }
.native-form__label { }
.native-form__input { }

/* Modifier */
.native-form--compact { }
.native-form__input--error { }
```

### 2. CSS Custom Properties
All design tokens should be defined in `_variables.css`:
```css
:root {
    --color-primary: #1e73be;
    --spacing-md: 1rem;
    --font-body: 'Inter', sans-serif;
}
```

### 3. Component Scoping
Each component should be self-contained:
```css
/* _forms.css */
.native-form { /* container */ }
.native-form__field { /* scoped to form */ }
.native-form__input { /* scoped to form */ }
```

### 4. Responsive Breakpoints
```css
/* Mobile first approach */
.component { /* mobile styles */ }

@media (min-width: 768px) {
    .component { /* tablet styles */ }
}

@media (min-width: 1024px) {
    .component { /* desktop styles */ }
}
```

---

## Build Process (Production)

For production, CSS files are concatenated to reduce HTTP requests.

### Build Script: `build-css.sh`
```bash
#!/bin/bash
# Concatenate all CSS partials into a single production file

CSS_DIR="assets/css"
OUTPUT="assets/css/theme.min.css"

cat \
    "$CSS_DIR/base/_variables.css" \
    "$CSS_DIR/base/_reset.css" \
    "$CSS_DIR/base/_typography.css" \
    "$CSS_DIR/layout/_container.css" \
    "$CSS_DIR/layout/_header.css" \
    "$CSS_DIR/layout/_footer.css" \
    "$CSS_DIR/layout/_sidebar.css" \
    "$CSS_DIR/components/_buttons.css" \
    "$CSS_DIR/components/_forms.css" \
    "$CSS_DIR/components/_cards.css" \
    "$CSS_DIR/components/_widgets.css" \
    "$CSS_DIR/components/_pagination.css" \
    "$CSS_DIR/components/_comments.css" \
    "$CSS_DIR/pages/_single.css" \
    "$CSS_DIR/pages/_archive.css" \
    "$CSS_DIR/utilities/_utilities.css" \
    > "$OUTPUT"

echo "CSS built: $OUTPUT"
```

### Usage
```bash
# Development: Use theme.css with @imports
# Production: Run build script, use theme.min.css

cd /path/to/theme
./build-css.sh
```

---

## Migration Plan

### Phase 1: Create Directory Structure
1. Create `base/`, `layout/`, `components/`, `pages/`, `utilities/` folders
2. Create empty partial files

### Phase 2: Extract Existing Styles
1. Move CSS custom properties → `base/_variables.css`
2. Move reset/base → `base/_reset.css`
3. Move typography → `base/_typography.css`
4. Move header styles → `layout/_header.css`
5. Move footer styles → `layout/_footer.css`
6. Move sidebar styles → `layout/_sidebar.css`
7. Move container/grid → `layout/_container.css`
8. Move button styles → `components/_buttons.css`
9. Move widget styles → `components/_widgets.css`
10. Move pagination → `components/_pagination.css`
11. Move comments → `components/_comments.css`
12. Move single post styles → `pages/_single.css`
13. Move archive styles → `pages/_archive.css`
14. Move utilities → `utilities/_utilities.css`

### Phase 3: Add New Components
1. Create `components/_forms.css` for native form styles
2. Create `components/_cards.css` for post cards

### Phase 4: Update theme.css
1. Replace all styles with @import statements
2. Test in development
3. Create build script for production

---

## File Size Targets

| File | Target Lines | Purpose |
|------|--------------|---------|
| _variables.css | ~120 | Design tokens |
| _reset.css | ~50 | CSS reset |
| _typography.css | ~80 | Typography |
| _container.css | ~60 | Layout grid |
| _header.css | ~400 | Header/nav |
| _footer.css | ~300 | Footer |
| _sidebar.css | ~80 | Sidebar |
| _buttons.css | ~60 | Buttons |
| _forms.css | ~200 | Forms |
| _cards.css | ~150 | Cards |
| _widgets.css | ~120 | Widgets |
| _pagination.css | ~50 | Pagination |
| _comments.css | ~60 | Comments |
| _single.css | ~300 | Single post |
| _archive.css | ~150 | Archive |
| _utilities.css | ~200 | Utilities |

**Total: ~2,400 lines** (same as current, but organized)

---

## Benefits

1. **Maintainability** - Each file is focused and manageable
2. **Team-friendly** - Multiple developers can work simultaneously
3. **Debugging** - Easy to locate and fix issues
4. **Scalability** - Add new components without bloating files
5. **Performance** - Production build concatenates for single request
6. **Documentation** - Self-documenting file structure

---

## Notes

- CSS `@import` is used for development (modern browser support)
- Production uses concatenated file to avoid multiple HTTP requests
- All partials use underscore prefix to indicate they're not standalone
- Utilities load last to ensure they can override component styles

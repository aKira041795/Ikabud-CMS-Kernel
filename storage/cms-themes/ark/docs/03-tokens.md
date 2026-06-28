# ARK Design Token System

## Overview

ARK's visual layer is 100% driven by design tokens in `tokens.json`. Every color, spacing value, radius, shadow, and typography setting flows through CSS custom properties. The customizer overrides these at runtime.

## Token → CSS Variable Mapping

```css
/* tokens.json                         → style.css */
"--color-primary": "#6366f1"           → --ark-primary: var(--color-primary, #6366f1);
"--font-family": "Inter, ..."          → --ark-font-family: var(--font-family, Inter, ...);
"--spacing-md": "1.25rem"              → --ark-spacing-md: var(--spacing-md, 1.25rem);
```

## Token Architecture

```
tokens.json               style.css                 Layout/Templates
───────────               ─────────                 ────────────────
Defines values     →      Wires to --ark-*    →     Consumed via var()
                          vars with fallbacks        in inline styles
                                                      and CSS classes
```

## Adding a Token

1. Add the value to `tokens.json`
2. Add the corresponding `--ark-*` variable in `style.css` with `var()` fallback
3. Use `var(--ark-your-token)` in templates

## Customizer Integration

The customizer sets `--theme-site-max-width`, `--color-primary`, etc. The `--ark-*` vars consume these via `var()`:

```css
--ark-max-width: var(--layout-max-width, var(--theme-site-max-width, 1280px));
```

Priority: customizer value → `tokens.json` default → hardcoded fallback.

## Token Categories

| Category | Prefix | Example |
|---|---|---|
| Colors | `--ark-` | `--ark-primary` |
| Typography | `--ark-font-` | `--ark-font-family` |
| Spacing | `--ark-spacing-` | `--ark-spacing-md` |
| Radius | `--ark-radius-` | `--ark-radius-md` |
| Shadows | `--ark-shadow-` | `--ark-shadow-md` |
| Layout | `--ark-` | `--ark-max-width`, `--ark-header-height` |

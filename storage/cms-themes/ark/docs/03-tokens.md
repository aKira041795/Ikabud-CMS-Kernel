# ARK Design Token System

## Overview

ARK's visual layer is 100% driven by design tokens in `tokens.json`. Every color, spacing value, radius, shadow, motion value, z-index, layout value, and component default flows through CSS custom properties. The customizer overrides these at runtime.

## Token → CSS Variable Mapping

```css
/* tokens.json                              → style.css */
"--color-primary-500": "#6366f1"          → optional scale token for palettes
"--color-primary": "#6366f1"              → --ark-primary: var(--color-primary, #6366f1);
"--duration-fast": "80ms"                 → --ark-duration-fast: var(--duration-fast, 80ms);
"--button-height-md": "40px"              → --ark-button-height-md: var(--button-height-md, 40px);
"--font-family": "Inter, ..."             → --ark-font-family: var(--font-family, Inter, ...);
"--spacing-md": "1.25rem"                 → --ark-spacing-md: var(--spacing-md, 1.25rem);
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
2. If the token needs a semantic alias, add the corresponding `--ark-*` variable in `style.css` with `var()` fallback
3. Prefer `var(--ark-*)` in theme CSS and templates
4. Re-run `php ikabud theme:validate ark`

## Customizer Integration

The customizer sets `--theme-site-max-width`, `--color-primary`, etc. The `--ark-*` vars consume these via `var()`:

```css
--ark-max-width: var(--layout-max-width, var(--theme-site-max-width, 1280px));
```

Priority: customizer value → `tokens.json` default → hardcoded fallback.

## ARK v2 Categories

ARK v2 publishes a larger token surface than the original reference theme baseline. The canonical categories are:

| Category | Examples |
|---|---|
| Color scales | `--color-primary-50` → `--color-primary-900`, `--color-secondary-50` → `--color-secondary-900`, `--color-accent-50` → `--color-accent-900` |
| Semantic colors | `--color-primary`, `--color-surface`, `--color-text`, `--color-border`, `--color-success` |
| Dark palette | `--dark-surface`, `--dark-text`, `--dark-border` |
| Typography | `--font-size-xs` → `--font-size-6xl`, `--font-weight-normal` → `--font-weight-extrabold` |
| Spacing and radius | `--spacing-2xs` → `--spacing-5xl`, `--radius-none` → `--radius-full` |
| Shadows and motion | `--shadow-sm` → `--shadow-xl`, `--duration-fast`, `--easing-standard` |
| Layering and layout | `--z-sticky`, `--z-overlay`, `--layout-max-width`, `--layout-reading-max-width` |
| Component defaults | `--button-height-md`, `--input-focus-ring-width`, `--badge-font-size` |

## Token Categories

| Category | Prefix | Example |
|---|---|---|
| Colors | `--ark-` | `--ark-primary`, `--ark-accent` |
| Typography | `--ark-font-` | `--ark-font-family`, `--ark-font-weight-semibold` |
| Spacing | `--ark-spacing-` | `--ark-spacing-md` |
| Radius | `--ark-radius-` | `--ark-radius-md` |
| Shadows | `--ark-shadow-` | `--ark-shadow-md` |
| Motion | `--ark-duration-`, `--ark-easing-` | `--ark-duration-fast` |
| Layering | `--ark-z-` | `--ark-z-overlay` |
| Layout | `--ark-` | `--ark-max-width`, `--ark-reading-max-width` |
| Components | `--ark-button-`, `--ark-input-`, `--ark-badge-` | `--ark-button-height-md` |

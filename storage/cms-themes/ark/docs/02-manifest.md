# ARK Theme Manifest — Annotated Reference

The `theme.manifest.json` file is the canonical identity document for an Ikabud theme. Every field is defined below.

## Required Fields

| Field | Type | Description |
|---|---|---|
| `name` | string | Machine name (slug). Must match directory name. |
| `version` | string | Semantic version (`1.0.0`). |
| `label` | string | Human-readable display name. |
| `supported_surfaces` | array | Rendering surfaces: `public`, `print`, `email`, `admin`, `export`. |

## Identity Fields

| Field | Type | Description |
|---|---|---|
| `description` | string | Purpose summary shown in admin. |
| `author` | string | Author or organization name. |
| `license` | string | SPDX identifier (`MIT`, `GPL-2.0-only`, etc.). |

## Compatibility

| Field | Type | Description |
|---|---|---|
| `kernel_os_compat` | string | Minimum Kernel OS version (`6.1.0`). |
| `disyl_compat` | string | Minimum DiSyL version (`4.7.0`). |
| `customizer_scope` | string | Scope base: `native` or `ecommerce`. Creates composite `{base}_{slug}` scope. |

## Integration

| Field | Type | Description |
|---|---|---|
| `tokens` | string | Path to `tokens.json` relative to theme root. |
| `shell` | string | Path to primary shell layout. |
| `supported_slots` | array | Governed slot IDs this theme renders. |
| `fallback_views` | object | Entity-view fallback templates (`card`, `table`, `detail`, `compact`). |
| `component_variants` | object | Theme-specific `ikb_*` component variant mappings (Tailwind classes). |

## Design & Accessibility

| Field | Type | Description |
|---|---|---|
| `design_language` | object | Metadata: `type_scale`, `color_system`, `grid`, `icon_set`. |
| `accessibility` | object | Guarantees: `skip_to_content`, `keyboard_navigation`, `contrast_ratio`, etc. |
| `browser_support` | array | Targeted browser versions. |

## Assets

| Field | Type | Description |
|---|---|---|
| `performance_budget` | object | Optional CLI validation budget overrides such as `css_kb` and `js_kb` for required assets. |
| `required_assets` | object | CSS/JS/fonts always loaded. |
| `optional_assets` | object | CSS/JS loaded on demand (bridges). |

## Theme Doctrine Fields

| Field | Type | Description |
|---|---|---|
| `theme.colors` | object | Default color palette (primary, surface, text, border, status). |
| `theme.typography` | object | Default typography (font family, heading weight, body size, line height). |
| `theme.spacing` | object | Spacing scale (none, xs, sm, md, lg, xl, 2xl). |
| `theme.radius` | object | Border radius scale. |
| `theme.shadows` | object | Box shadow scale. |
| `layout` | object | Layout defaults (max width, header, footer, sidebar). |

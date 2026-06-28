# ARK Customizer Integration

## Scope

ARK's customizer scope is `native_ark` (composite: `native` base + `ark` slug). Customizer settings for ARK are stored independently from other themes.

## Customizer Sections

| Section | Controls | Theme Impact |
|---|---|---|
| **Colors** | Primary, surface, text, border, status | `--color-*` CSS custom properties |
| **Typography** | Font family, heading weight, base size, line height | `--font-*` CSS custom properties |
| **Layout** | Container width, header height, sidebar width | `--layout-max-width`, `--header-height` |
| **Header** | Header style, sticky, transparent pages | `header.disyl` rendering |
| **Footer** | Columns, colors, copyright | `footer.disyl` rendering |
| **Entity Detail** | Layout profile, media ratio, spacing | `entity.view.disyl` layout |
| **Entity List** | Card density, excerpt length, filter summary | `entity.list.disyl` layout |
| **Block Variants** | Per-block variant selection | Block include resolution |

## Preview Bridge

The customizer preview iframe (`admin/customizer-preview.disyl`) receives changes via `postMessage`:

```javascript
window.addEventListener('message', function(event) {
    if (event.data.type === 'cms:customizer:update') {
        // Apply CSS variable changes in real-time
        Object.keys(event.data.variables).forEach(function(key) {
            document.documentElement.style.setProperty(key, event.data.variables[key]);
        });
    }
});
```

## Theme Doctrine

> The theme does not **own** the customizer — it provides defaults.

Customizer sections are CMS-owned. ARK documents which controls affect which parts of the theme, but section registration, persistence, and the control UI belong to the CMS module.

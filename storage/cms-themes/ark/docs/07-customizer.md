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

> ARK owns the public region rendering contract through ThemeCustomizerOrchestrator.

Customizer persistence and administrative editing remain CMS-managed, but ARK is now the rendering authority for public shell regions. The canonical runtime path is:

```
CMS persistence
    -> ThemeCustomizerOrchestrator
    -> ThemeRenderContext
    -> ARK region templates
    -> DiSyL output
```

Region HTML is exposed to layouts through the canonical public-context contract:

- `header_region`
- `footer_region`
- `sidebar_region`

Legacy `customized_header`, `customized_footer`, and `customized_sidebar` values remain as temporary compatibility shims only and should not be used for new theme work.

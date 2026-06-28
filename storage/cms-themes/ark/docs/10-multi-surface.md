# ARK Multi-Surface Rendering

## Supported Surfaces

| Surface | Layout | Characteristics |
|---|---|---|
| `public` | `layouts/public.disyl` | Full shell, Alpine.js, Tailwind, interactive |
| `print` | `layouts/public-print.disyl` | No nav/JS/buttons, `@page` rules, A4 |
| `email` | `layouts/public-email.disyl` | Table-based, inline styles, no `<style>`, no JS |

## Surface Detection

Templates can detect the output target:

```disyl
{if output_target == 'print'}
    {# Print-specific markup #}
{elseif output_target == 'email'}
    {# Email-specific markup #}
{else}
    {# Public/default markup #}
{/if}
```

## Print Optimizations

- All interactive elements hidden via CSS (`display:none`)
- `@page { margin: 1.5cm; size: A4; }` for consistent output
- `page-break-after: avoid` on headings
- `orphans: 3; widows: 3` on paragraphs
- Images: `page-break-inside: avoid`
- Print-only header/footer with branding and timestamp

## Email Optimizations

- Table-based layout (Outlook/Gmail compatibility)
- All styles inline (no `<style>` blocks)
- No JavaScript
- No external assets (fonts loaded via `@import` in `<style>` if needed)
- MSO conditional comments for Outlook
- `role="presentation"` on all layout tables
- Preheader text for inbox preview

## Entity Styles Per Surface

The `canonical-entity-styles.disyl` partial includes `@media print` overrides:

```css
@media print {
    .ark-header, .ark-footer, .ark-sidebar-region,
    .ark-topbar, .ark-pagination, .ark-btn,
    .ark-search-form, .ark-skip {
        display: none !important;
    }
    body { font-size: 12pt; color: #000; }
}
```

## Switching Surfaces

The CMS handler sets the layout based on the request context. ARK templates don't need to switch layouts themselves — the handler selects the appropriate layout and the template extends `_cms_active_theme/layouts/{layout}.disyl`.

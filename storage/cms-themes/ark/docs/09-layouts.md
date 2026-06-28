# ARK Layout System

## Layout Files

| Layout | Path | Purpose |
|---|---|---|
| Public | `layouts/public.disyl` | Main shell for all public pages |
| Print | `layouts/public-print.disyl` | Print-optimized (no nav, JS, interactive elements) |
| Email | `layouts/public-email.disyl` | Email-safe (table-based, inline styles, no JS) |
| Admin Preview | `layouts/admin-preview.disyl` | Customizer preview iframe |

## Inheritance Pattern

All page templates extend a layout via `{extends}`:

```disyl
{extends "_cms_active_theme/layouts/public.disyl"}

{block head}
    {# Additional head content #}
{/block}

{block content}
    {# Page content #}
{/block}
```

## Template Blocks

| Block | Purpose |
|---|---|
| `{block head}` | Additional `<head>` content (styles, scripts, meta) |
| `{block header}` | Header override (ARK renders its own by default) |
| `{block content}` | Primary page content — **required** |
| `{block scripts}` | Additional scripts before `</body>` |

## Governed Slots

The layout declares 16 `{ikb_slot}` markers. Modules register contributions against these slot IDs:

```
site.before → header.before → header.main → header.after →
hero → breadcrumbs → content.before → content → content.after →
sidebar.primary → sidebar.secondary →
footer.before → footer.main → footer.after → site.after →
notifications
```

## Partials

Layout includes are extracted to `public/partials/`:

| Partial | Used In |
|---|---|
| `header.disyl` | Layout (optional include) |
| `footer.disyl` | Layout (optional include) |
| `sidebar.disyl` | Layout (sidebar region) |
| `breadcrumb.disyl` | Breadcrumb slot fallback |
| `pagination.disyl` | Entity list, archive, search |
| `search-form.disyl` | Archive, search, entity list toolbar |
| `canonical-entity-styles.disyl` | All entity templates |
| `storefront-styles.disyl` | Ecommerce templates |
| `macros.disyl` | Macro library (import where needed) |

## Responsive Behavior

The public layout uses CSS flexbox with a natural collapse at narrow viewports. No JavaScript-dependent responsive behavior — all breakpoint rules are in `style.css` and `canonical-entity-styles.disyl`.

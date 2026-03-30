# CMS Theme Design & Architecture Guide

**Updated:** March 2026

This guide explains how CMS themes are structured, activated, rendered, and customized.

For a relationship-focused overview of how themes interact with universal entity rendering, see `docs/theme-entity-view-primer.md`.

## 1. Theme philosophy

CMS themes are intentionally lightweight.

They are responsible for the **public presentation layer only**:

- public layouts
- public page/post/archive/search templates
- optional theme CSS/JS assets
- design-system defaults that the theme customizer can activate or adjust

Themes do **not** override:

- admin templates
- CMS business logic
- database schema
- permissions or routing rules

For universal entity pages, themes should also avoid acting as owners of entity-specific template families. The preferred model is one canonical entity-view contract with customizer-driven presentation.

This keeps theming compatible with the CMS module boundary and safer for multi-tenant operation.

---

## 2. Theme storage model

### Source of truth

Themes live in:

`storage/cms-themes/{slug}/`

Examples:

- `storage/cms-themes/native-default/`
- `storage/cms-themes/minimal/`

### Active theme mount point

The active theme is mounted into:

`templates/_cms_active_theme`

This path is managed by symlink creation/removal in the theme helper layer.
Activation and themed render access are serialized with a filesystem lock so requests from different tenants cannot interleave symlink changes during public rendering.

### Public asset copy target

When a theme is installed or upgraded, public assets are copied to:

`public/assets/cms/themes/{slug}/`

Copied asset roots currently include:

- `style.css`
- `script.js`
- `css/`
- `js/`

---

## 3. Theme activation lifecycle

1. a theme ZIP is uploaded and validated
2. the theme is extracted into `storage/cms-themes/{slug}`
3. public assets are copied to `public/assets/cms/themes/{slug}`
4. activating the theme updates CMS settings (`active_theme`) through the module settings API
5. in multi-tenant mode, `active_theme` can be overridden per tenant through `tenant_module_settings`
6. the CMS recreates `templates/_cms_active_theme` as a symlink to the chosen theme directory under a runtime lock

If the active theme is reset to default, the symlink is removed and CMS built-in public templates are used.

### Multi-tenant behavior

- Global fallback settings still exist in `storage/modules.json`
- Tenant-specific theme selection is read from `tenant_module_settings` when a tenant context is resolved
- The symlink path is still shared, but lock-guarded activation and render flow prevents one tenant request from leaking another tenant's active theme during rendering

---

## 4. Template resolution order

Public rendering uses an override-first strategy for general public templates.

For universal entity rendering, this compatibility behavior should not be confused with the preferred long-term design. Entity pages should converge on a canonical entity-view contract whose presentation is controlled by the active theme customizer rather than by per-theme entity template forks.

### Layout resolution

Preferred order:

1. `_cms_active_theme/layouts/public.disyl`
2. `modules/cms/layouts/public.disyl`

### Public template resolution

Common template paths include:

- `public/home.disyl`
- `public/page.disyl`
- `public/single.disyl`
- `public/archive.disyl`
- `public/search.disyl`
- `public/sidebar.disyl`

If an active theme provides the file, the themed version is used. Otherwise the CMS default template is used.

### Content-specific templates

The editor can store a `_template` meta value for content. Runtime resolution then:

1. checks registered content templates
2. resolves the target path if declared
3. falls back to slug-based template lookup
4. falls back again to the default page/post template

---

## 5. Recommended theme directory structure

```text
my-theme/
├── theme.json
├── style.css                # optional
├── script.js                # optional
├── css/                     # optional
├── js/                      # optional
├── layouts/
│   └── public.disyl         # recommended public layout override
└── public/
    ├── home.disyl           # optional
    ├── page.disyl           # optional
    ├── single.disyl         # optional
    ├── archive.disyl        # optional
    ├── search.disyl         # optional
    ├── sidebar.disyl        # optional
    ├── full-width.disyl     # optional custom template
    └── landing.disyl        # optional custom template
```

You only need to include files you intend to override.

For entity pages specifically, prefer supplying design defaults and customizer-compatible styling instead of shipping theme-specific `entity.view` forks as a primary customization strategy.

---

## 6. `theme.json` manifest

### Required and validated fields

```json
{
  "name": "My Theme",
  "version": "1.0.0"
}
```

Validation rules enforced during upload:

- `name` is required and must be a non-empty string
- `version` is optional, but cannot be an empty string when present
- `author` and `description` are optional and must be strings when present
- `templates` and `pageTemplates` are optional and must be arrays when present

### Current extended metadata used by bundled themes

```json
{
  "name": "Native Default",
  "version": "2.0.0",
  "author": "Ikabud Kernel",
  "description": "Modern theme with customization support.",
  "supports": {
    "menus": ["primary", "footer"],
    "features": ["featured-images", "excerpts", "custom-templates"]
  },
  "pageTemplates": [
    { "slug": "default", "name": "Default", "description": "Standard page layout" },
    { "slug": "full-width", "name": "Full Width", "description": "Full width without sidebar" }
  ],
  "assets": {
    "css": ["style.css"],
    "js": ["script.js"]
  }
}
```

### Compatibility behavior

CMS now supports both manifest keys for template declarations:

- `templates` (canonical)
- `pageTemplates` (backward-compatible)

For best forward compatibility, prefer `templates` and use `label` + `path` fields.

Recommended canonical structure for forward compatibility:

```json
{
  "templates": [
    {
      "slug": "landing",
      "label": "Landing Page",
      "types": ["page"],
      "path": "public/landing.disyl"
    }
  ]
}
```

---

## 7. Public assets

### Current behavior

Theme install/upgrade copies a limited set of files into the public asset tree.

### Runtime resolution

Public layouts consume context-provided asset URLs:

- `theme_style_url`
- `theme_script_url`

These are generated by CMS active-theme helpers and automatically resolve to the active theme asset path.

### Recommended authoring approach

- include `style.css` and `script.js`
- verify actual rendered asset URLs after activation

---

## 8. Theme package upload security

Theme ZIP uploads are validated before extraction.

Current checks block:

- path traversal entries (`../`)
- absolute paths and Windows drive-style roots
- null-byte paths
- symlink entries inside ZIP archives

Manifest validation runs after extraction and before install.

---

## 9. Installer audit logging

Theme lifecycle operations write structured installer audit entries to application logs, including success and rejected operations:

- upload/install/upgrade
- activate/deactivate
- delete

See `storage/logs/app.log` for `CMS installer audit` entries.

---

## 10. Customizer relationship to themes

Customizer data is stored in the database, not in the theme directory.

Implemented customizer sections include:

- `colors`
- `header`
- `footer`
- `sidebar`
- `custom_code`
- `entity_presentation`
- `theme`

Target direction for entity presentation controls:

- canonical `entity_presentation` owns approved entity-view and entity-list presentation controls across CMS and ecommerce scopes
- `theme` is now shell-only and owns outer layout geometry such as site width, content width, and shell padding
- canonical list/detail controls migrated out of `theme` include the legacy `blog_*` and `single_*` presentation keys
- the admin `Entities` workspace is rendered from entity-context registry catalog/example payloads, so pricing, inventory, progress, and action controls can appear only when the active schema context supports them
- approved entity layout profiles
- approved block variants
- region emphasis and visibility rules
- token-level presentation adjustments shared across CMS and ecommerce entity pages

### What this means for theme authors

- your theme should coexist with runtime-generated CSS variables and customizer HTML
- your theme should assume the customizer, not the theme directory, is the correct control surface for entity presentation choices
- public handlers and templates should read `entity_presentation_settings` directly; `theme_settings` receives a merged copy only for compatibility
- do not depend on a fixed admin control list or ecommerce-only fields always being visible; the schema-driven `Entities` workspace shows controls according to the selected entity context example/capabilities
- your theme should keep ecommerce and CMS public presentation in the same design language


## 11. Theme authoring recommendations
### Required CSS for customised header components

When the customizer has a saved header configuration the layout renders `{customized_header|raw}`, which emits HTML elements that every theme **must** provide CSS for — otherwise they appear as stray unstyled markup on the page.

| Element / class | Purpose | Minimum required CSS |
|---|---|---|
| `.header-search-overlay` | Full-screen search modal | `position:fixed; opacity:0; visibility:hidden` — shown by adding `.active` |
| `.nav-menu` | Primary nav `<ul>` | `list-style:none; display:flex; gap:…` |
| `.nav-menu-sub` | Dropdown `<ul>` | `position:absolute; opacity:0; visibility:hidden` — shown on hover |
| `.mobile-menu-toggle` | Hamburger button | `display:none` on desktop |
| `.header-cta` / `.header-cta--primary` | CTA button | `display:inline-flex; background; color` |

The `cz-mobile-header` and `cz-header-dropdown` inline `<style>` blocks are injected by the customized header automatically (canvas-nav hide/show, dropdown positioning). Themes do not need to duplicate them.

**Starting point:** copy the _"Customized Header Components"_ section from `storage/cms-themes/minimalist/style.css`.

---

## 11. Theme authoring recommendations
### Do

- keep public layout overrides focused and deterministic
- support CMS menus and content areas cleanly
- design for customizer-generated colors/fonts/layout variables
- design entity pages so customizer-selected presentation rules can apply consistently
- provide optional content templates for page layouts
- test with posts, pages, search, archives, and builder-driven pages

### Do not

- assume admin templates are overrideable
- hardcode tenant-specific URLs
- assume only one menu location exists
- rely on kernel-level modules or tables directly from theme files
- build separate CMS-vs-ecommerce visual systems when both render through shared public contracts
- treat entity-view presentation as something that should primarily be solved by per-theme entity templates

---

## 12. Starter checklist for a new theme

1. create `theme.json`
2. add `layouts/public.disyl`
3. add at least `public/home.disyl`, `public/page.disyl`, and `public/single.disyl`
4. add `style.css`
5. activate the theme from CMS admin
6. verify menu rendering, sidebar behavior, builder pages, archives, search, RSS/sitemap unaffected, and customizer compatibility
7. verify that entity pages can live inside the same design system without requiring a separate theme-owned entity template family

---

## 13. Recommended next improvements to the theme platform

1. add screenshot / preview metadata in `theme.json`
2. add optional schema versioning for future manifest evolution
3. add theme asset manifest/cache-busting support
4. document a starter theme skeleton in the repository

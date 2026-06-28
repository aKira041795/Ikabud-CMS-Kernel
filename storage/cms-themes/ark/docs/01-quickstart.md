# ARK Quickstart — 5-Minute Setup

## Copy ARK as your base theme

```bash
# 1. Copy ARK to a new theme slug
cp -r storage/cms-themes/ark storage/cms-themes/my-theme

# 2. Edit theme.manifest.json — change name, label, description
nano storage/cms-themes/my-theme/theme.manifest.json

# 3. Edit tokens.json — customize colors, fonts, spacing
nano storage/cms-themes/my-theme/tokens.json

# 4. Activate your theme
php ikabud theme:activate my-theme

# 5. Validate
php ikabud theme:validate my-theme
```

## Understand the file structure

| Directory | Purpose |
|---|---|
| `theme.manifest.json` | Theme identity, capabilities, slots, fallbacks |
| `tokens.json` | Design tokens → CSS custom properties |
| `style.css` | Token-driven stylesheet |
| `layouts/` | HTML shell templates (public, print, email) |
| `public/` | Page templates (home, page, archive, 404, etc.) |
| `public/blocks/` | Reusable block variants (pricing, inventory, action, etc.) |
| `public/partials/` | Reusable partials (header, footer, macros, etc.) |
| `entity-views/` | Generic fallback templates for unknown entity types |
| `admin/` | Admin preview and theme info templates |
| `docs/` | This documentation |

## Key concepts

1. **Theme Doctrine**: Theme presents. Modules provide. DiSyL declares. Kernel OS governs.
2. **Tokens drive everything**: All visual values flow from `tokens.json` through CSS custom properties.
3. **Slots are the module integration API**: 16 governed slot IDs let modules inject content.
4. **Block variants**: Same block rendered differently (default, compact, featured) — resolved from customizer.
5. **Safe fallbacks**: Unknown entity types are supported; unknown fields are hidden.

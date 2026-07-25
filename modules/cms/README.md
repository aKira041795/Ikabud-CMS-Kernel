# Content Management System (CMS)

Core CMS module providing pages, posts, custom content types, media library, visual page builder, theme management, and public frontend rendering. Auth-owned module — manages `cms_users` table with `administrator` role.

## Features

- **Content management**: pages, posts, custom content types, categories, tags
- **Visual page builder**: React-based drag-and-drop builder (`builder-ui/`) with server-side rendering
- **Media library**: uploads, galleries, featured images, CDN support
- **Theme system**: customizable themes, slots, presets, token editor
- **Public frontend**: entity-list and entity-detail views via `{ikb_entity_list}` / `{ikb_entity_detail}`
- **SEO**: meta tags, sitemaps, URL management
- **Multi-tenant**: per-tenant content isolation via `tenant_id`
- **AI automation**: content generation, suggested improvements

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers (27): [`handlers.php`](handlers.php)
- Helpers (28): [`helpers.php`](helpers.php)
- Builder UI: [`builder-ui/`](builder-ui/) (Vite + TypeScript React app)
- Builder renderers: [`builder-renderers.php`](builder-renderers.php)

## Dependencies

- `users` — shared `cms_users` table
- `media` — shared `cms_media` table
- `search` — content indexing
- `tinymce` — rich text editor assets

## Documentation

Project-level docs: [`docs/cms/`](../../docs/cms/), [`docs/page-builder/`](../../docs/page-builder/)

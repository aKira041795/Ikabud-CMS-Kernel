# ARK Entity View Templates

## Architecture

ARK provides two primary entity rendering templates:

| Template | Purpose | Uses |
|---|---|---|
| `public/entity.list.disyl` | List/search results | `{ikb_entity_list}`, pagination, filters |
| `public/entity.view.disyl` | Detail/single view | Capability-gated blocks, meta, media |

Both extend `layouts/public.disyl` via `{extends}` and inject content into `{block content}`.

## Entity List (`entity.list.disyl`)

**Route kinds handled:**
- `blog-home`, `archive`, `tag`, `search` — filter pills, category chips, pagination
- Any `entity_type` — generic entity list via `{ikb_entity_list}`
- No context — discovery dashboard with sample sections

**Key patterns:**
- `{ikb_entity_list source="cms_post.recent" view="card_grid" limit="10" /}` — governed data resolution
- Filter summary pills with remove links
- Pagination partial (`{include "partials/pagination.disyl"}`)

## Entity View (`entity.view.disyl`)

**Key patterns:**
- Title → Meta → Featured Image → Body → Capability Blocks
- `{if entity_view_context.show_header|default:1}` — customizer-controlled visibility
- Capability-gated block rendering via `{if capability_data.pricing}` etc.
- Block variant resolution: `entity_presentation.block_variants.pricing|default:'default'`

## Fallback Views

When no module-specific view contract exists, ARK renders generic fallbacks from `entity-views/`:

| View | Template | Use Case |
|---|---|---|
| `card` | `default-card.disyl` | Card grid, auto-detected fields |
| `table` | `default-table.disyl` | Dynamic table, auto-detected headers |
| `detail` | `default-detail.disyl` | Structured detail, safe fields only |
| `compact` | `default-compact.disyl` | Inline list for sidebars |

## Safe Fallback Doctrine

Unknown entity types are **supported**. Unknown fields are **hidden**. Fallbacks render only:
- `id`, `title`, `name`, `label`, `excerpt`, `description`
- `url`, `image`, `featured_image_url`, `thumbnail_url`
- `status`, `price`, `published_at`, `created_at`, `author_name`

Never `array_keys()` from an unknown entity.

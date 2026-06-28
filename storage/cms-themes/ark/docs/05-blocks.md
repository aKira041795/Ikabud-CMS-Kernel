# ARK Block Library Reference

## Block Naming Convention

```
public/blocks/{category}/{category}.block.{variant}.disyl
```

Example: `public/blocks/pricing/pricing.block.compact.disyl`

## Block Categories

### Pricing Blocks
| Block | Path | Description |
|---|---|---|
| Default | `pricing/pricing.block.default.disyl` | Full price with sale comparison, CTA |
| Compact | `pricing/pricing.block.compact.disyl` | Inline price, sale strikethrough |
| Featured | `pricing/pricing.block.featured.disyl` | "Best Value" ribbon, prominent CTA |

**Context required:** `capability_data.pricing` with `price`, `currency`, `compare_at_price`, `description`

### Inventory Blocks
| Block | Path | Description |
|---|---|---|
| Default | `inventory/inventory.block.default.disyl` | Stock badge, quantity, low-stock warning |
| Compact | `inventory/inventory.block.compact.disyl` | Dot indicator only |

**Context required:** `capability_data.inventory` with `in_stock`, `quantity`, `low_stock_threshold`

### Action Blocks
| Block | Path | Description |
|---|---|---|
| Default | `action/action.block.default.disyl` | Full action strip with primary/secondary CTAs |
| Inline | `action/action.block.inline.disyl` | Compact inline buttons, no panel |

**Context required:** `capability_data.actions` — array of `{url, label, primary}`

### Progress Blocks
| Block | Path | Description |
|---|---|---|
| Default | `progress/progress.block.default.disyl` | Full progress bar with label and percentage |
| Inline | `progress/progress.block.inline.disyl` | Dot + percentage text |

**Context required:** `capability_data.progress_tracking` with `percentage`, `label`

### List-Card Blocks
| Block | Path | Description |
|---|---|---|
| Default | `list-card/list-card.block.default.disyl` | Card with image, title, excerpt, meta |
| Pricing | `list-card/list-card.pricing.block.disyl` | Card with integrated price display |
| Pricing Featured | `list-card/list-card.pricing.featured.block.disyl` | Featured ribbon, prominent pricing, CTA |
| Inventory | `list-card/list-card.inventory.block.disyl` | Card with stock badge and quantity |
| Inventory Compact | `list-card/list-card.inventory.compact.block.disyl` | Card with minimal stock dot |
| Progress | `list-card/list-card.progress.block.disyl` | Card with progress bar overlay |

### Other Blocks
| Block | Path | Description |
|---|---|---|
| Meta | `meta.block.disyl` | Author, date, type badge, reading time |
| Media Gallery | `media-gallery.block.disyl` | Featured image + gallery with Alpine.js lightbox |
| Lessons | `lessons/lessons.block.disyl` | Ordered course/lesson index with completion badges |

## Including a Block

```disyl
{include "blocks/pricing/pricing.block.default.disyl"}
```

Blocks resolve relative to the active theme's `public/` directory at runtime. The linter resolves relative to the file containing the include.

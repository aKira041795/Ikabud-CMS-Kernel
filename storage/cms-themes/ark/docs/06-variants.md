# ARK Block Variant System

## Variant Semantics

ARK uses portable semantic variants, not theme-specific visual names:

| Variant | Meaning | Example Components |
|---|---|---|
| `default` | Standard presentation | panel, button, badge, card |
| `compact` | Dense, reduced spacing | pricing, inventory, list-card |
| `featured` | Highlighted, emphasized | pricing, card |
| `minimal` | Reduced visual weight | pricing, list-card |
| `inline` | Horizontal, no wrapping | action, progress |
| `outlined` | Border-only, no fill | button |
| `stacked` | Vertical arrangement | card, list-card |

## Resolution Chain

```
Customizer setting → Theme default → Canonical fallback
```

## Variant Resolution Pattern

In `entity.view.disyl`, each capability block resolves its variant:

```disyl
{if capability_data.pricing}
    {set pricing_variant = entity_presentation.block_variants.pricing|default:'default'}
    {if pricing_variant == 'compact'}
        {include "blocks/pricing/pricing.block.compact.disyl"}
    {elseif pricing_variant == 'featured'}
        {include "blocks/pricing/pricing.block.featured.disyl"}
    {else}
        {include "blocks/pricing/pricing.block.default.disyl"}
    {/if}
{/if}
```

## Adding a New Variant

1. Create the variant file: `blocks/{category}/{category}.block.{variant}.disyl`
2. Add resolution branch in `entity.view.disyl` (or in the consuming template)
3. Add variant to `theme.manifest.json` → `component_variants` if it maps to `ikb_*` classes

## Component Variants in Manifest

The `component_variants` section maps `ikb_*` component attributes to Tailwind CSS classes:

```json
"component_variants": {
    "ikb_panel": {
        "tone": {
            "surface": "bg-white border border-gray-100",
            "muted": "bg-gray-50 border border-gray-100",
            "elevated": "bg-white shadow-md"
        }
    }
}
```

This allows the customizer to swap Tailwind classes without touching templates.

# ARK DiSyL Component Registration

## Overview

ARK registers 5 custom DiSyL components that extend the kernel's governed component vocabulary. Components are registered in PHP via the TemplateEngine's ComponentRegistry and live in `modules/cms/helpers/81-ark-components.php`.

## Registered Components

| Component | Type | Description |
|---|---|---|
| `ark_card_grid` | Container | Responsive card grid layout |
| `ark_hero` | Container | Hero section with background image and overlay |
| `ark_stats` | Container | Statistics/metrics grid row |
| `ark_stat` | Leaf | Single stat item (value + label) |
| `ark_section_heading` | Leaf | Section heading with optional link |

## Usage in Templates

```disyl
{# Card grid #}
{ark_card_grid columns="3" gap="md"}
    {for row in rows}
        {include "blocks/list-card/list-card.block.default.disyl"}
    {/for}
{/ark_card_grid}

{# Hero #}
{ark_hero background="https://example.com/hero.jpg" overlay="dark" height="500px"}
    <h1>Welcome to ARK</h1>
    <p>The reference theme for Kernel OS 6.1+</p>
{/ark_hero}

{# Stats #}
{ark_stats columns="4"}
    {ark_stat value="125K" label="Users" /}
    {ark_stat value="99.9%" label="Uptime" /}
{/ark_stats}

{# Section heading #}
{ark_section_heading title="Latest Articles" link_url="/blog" link_label="View all" /}
```

## Registration Pattern

```php
$engine = app()->templateEngine();

$engine->registerComponent('ark_card_grid', function (array $attrs, string $body): string {
    $columns = max(1, min(6, (int)($attrs['columns'] ?? 3)));
    return sprintf(
        '<div class="ark-card-grid" style="grid-template-columns:repeat(%d,1fr);">%s</div>',
        $columns,
        $body
    );
});
```

## Theme Doctrine

Per ARK doctrine ("Theme presents. Modules provide"), PHP component registration lives in the CMS module, not the theme directory. Themes are presentation-only — they consume components, they don't define PHP logic.

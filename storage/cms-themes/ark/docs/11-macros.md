# ARK DiSyL Macro Library

## Overview

ARK provides a reusable DiSyL macro library in `public/partials/macros.disyl`. Macros are DiSyL's template-level equivalent of PHP helper functions — they encapsulate repeated markup patterns with parameters and defaults.

## Importing Macros

```disyl
{include "partials/macros.disyl"}
```

Include this once at the top of any template that needs macros. The macros become available in the current scope.

## Available Macros

### `ark_button`
```disyl
{ark_button label="Get Started" url="/start" variant="primary" size="md" icon="" /}
```
| Param | Default | Options |
|---|---|---|
| `label` | — (required) | Button text |
| `url` | — (required) | Link target |
| `variant` | `primary` | `primary`, `secondary`, `outline`, `ghost`, `danger` |
| `size` | `md` | `sm`, `md`, `lg` |
| `icon` | `""` | Raw SVG/HTML icon markup |

### `ark_badge`
```disyl
{ark_badge text="Published" variant="default" /}
```
| Param | Default | Options |
|---|---|---|
| `text` | — (required) | Badge label |
| `variant` | `default` | `default`, `primary`, `success`, `warning`, `danger`, `info` |

### `ark_price`
```disyl
{ark_price amount="49.99" currency="$" sale_amount="39.99" suffix="mo" /}
```
| Param | Default | Description |
|---|---|---|
| `amount` | — (required) | Regular price |
| `currency` | `$` | Currency symbol |
| `sale_amount` | `""` | Sale price (shows strikethrough if set) |
| `suffix` | `""` | Unit suffix (e.g., `/mo`) |

### `ark_progress`
```disyl
{ark_progress percent="75" label="Course Progress" color="primary" /}
```
| Param | Default | Description |
|---|---|---|
| `percent` | — (required) | 0–100 |
| `label` | `""` | Optional label above bar |
| `color` | `primary` | Fill color variant |

### `ark_card`
```disyl
{ark_card title="Card Title" url="/post" image="/img.jpg" excerpt="Summary..." price="$49.99" badge="New" meta="Jan 2026" /}
```
| Param | Default | Description |
|---|---|---|
| `title` | — (required) | Card heading |
| `url` | — (required) | Link target |
| `image` | `""` | Thumbnail URL |
| `excerpt` | `""` | Description text |
| `price` | `""` | Price display |
| `badge` | `""` | Badge label |
| `meta` | `""` | Meta line (date, author) |

### `ark_section`
```disyl
{ark_section heading="Section Title" padding="lg" background="white"}
    <p>Section content here.</p>
{/ark_section}
```

### `ark_empty`
```disyl
{ark_empty icon="📄" message="No records found." /}
```

## Writing New Macros

```disyl
{macro my_macro(param1, param2 = 'default')}
    <div class="my-component">{param1} — {param2}</div>
{/macro}
```

Keep macros small, focused, and token-driven (use `var(--ark-*)` for all visual values).

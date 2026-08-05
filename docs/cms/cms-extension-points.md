# CMS Extension Points

The CMS exposes hook-based extension seams through the kernel hooks system. These hooks let other modules extend CMS behavior without editing CMS source.

All hooks below are implemented in the CMS helper layer today.

---

## 1. Builder hooks

### `cms.builder.widgets`

**Signature:** `fn(array $widgets): array`

Registers additional builder widgets for the React page builder.

Expected item shape:

```php
[
    'type' => 'callout',
    'label' => 'Callout',
    'icon' => '📢',
    'category' => 'marketing',
    'kind' => 'widget',
    'default_props' => [
        'text' => '',
        'style' => 'info',
    ],
]
```

### `cms.builder.dynamic_sources`

**Signature:** `fn(array $sources): array`

Registers dynamic data sources for builder widgets.

Use this when a widget needs runtime-bound values such as site metadata, post meta, taxonomy terms, or module-provided data.

### `cms.builder.templates`

**Signature:** `fn(array $templates): array`

Registers additional starter templates for the builder.

These templates supplement database-backed builder templates and can be used to seed landing pages or reusable layouts.

---

## 2. Editor hooks

### `cms.editor.block_types`

**Signature:** `fn(array $blockTypes): array`

Registers additional editor block definitions for CMS editing surfaces that use the block-type registry.

### `cms.editor.sidebar_fields`

**Signature:** `fn(array $fields, string $contentType): array`

Injects additional sidebar fields into the classic content editor.

Example:

```php
app()->hooks()->on('cms.editor.sidebar_fields', function (array $fields, string $type): array {
    if ($type === 'post') {
        $fields[] = [
            'key' => 'reading_time_override',
            'type' => 'number',
            'label' => 'Reading Time Override',
            'placeholder' => '5',
        ];
    }
    return $fields;
}, 10);
```

Supported field types depend on the consuming editor surface. Keep definitions simple and CMS-compatible.

### `cms.content.templates`

**Signature:** `fn(array $templates, string $contentType): array`

Registers public-facing content templates that can be chosen in the editor and resolved at render time.

Expected item shape:

```php
[
    'slug' => 'landing',
    'label' => 'Landing Page',
    'types' => ['page'],
    'path' => '_cms_active_theme/public/landing.disyl',
]
```

---

## 3. Admin hooks

### `cms.admin.nav_items`

**Signature:** `fn(array $items): array`

Adds links to the CMS admin sidebar.

Expected item shape:

```php
[
    'label' => 'Analytics',
    'url' => '/cms/admin/analytics',
    'icon' => '📊',
    'active_key' => 'analytics',
]
```

> **Legacy hook.** Kept for backward compatibility with pre-2026-08 modules. New
> admin surfaces should prefer the manifest-declared contribution model below.

### `cms.sidebar` extension point + `admin_contributions` (dynamic registry)

The `cms.sidebar` extension point is declared by a host product core
(`kind: product-core`, e.g. `cms-akira-core`) in its `extension_points`. Modules
that want to appear in the CMS admin sidebar declare an `admin_contributions`
entry in their own `module.json` instead of registering a hook:

```json
{
    "id": "cms-akira-seo",
    "suite": "cms-akira",
    "kind": "extension",
    "extends": "cms-akira-core",
    "contributes": [
        { "extension_point": "cms.sidebar", "provider": "cms-akira-seo.nav@1" }
    ],
    "admin_contributions": [
        {
            "host": "cms",
            "location": "sidebar",
            "group": "optimization",
            "label": "SEO",
            "icon": "search",
            "route": "/admin/cms-akira-seo",
            "permission": "cms.seo.manage",
            "order": 60
        }
    ]
}
```

Contribution fields:

| Field | Meaning |
|---|---|
| `host` | Host module id the contribution targets (e.g. `cms`) |
| `location` | Surface within the host (e.g. `sidebar`) |
| `group` | Sidebar group/section the item belongs to |
| `label` | Display label |
| `icon` | Icon identifier |
| `route` | Admin route the item links to |
| `permission` | Capability/role gate for visibility |
| `order` | Sort order within the group |

The admin sidebar is rendered **dynamically** from the registry of
`admin_contributions`. The kernel validates that a contribution's host has
declared the matching extension point during install/certification — a module
cannot inject itself into a point the host did not declare.

> **Coexistence (incremental migration):** the legacy `cms.admin.nav_items`
> hook capability and the new `admin_contributions` registry model coexist.
> Existing hook-based modules keep working unchanged; new modules should adopt
> the manifest contribution model, and hook registrations can be migrated
> incrementally.

---

## 4. Public rendering hooks

### `cms.public.head`

**Signature:** `fn(string $headHtml, array $content): string`

Appends or transforms HTML that is inserted into the public page `<head>`.

Typical uses:

- Open Graph tags
- analytics snippets
- additional structured data
- feature-specific preload tags

### `cms.public.render_content`

**Signature:** `fn(string $html, array $content): string`

Transforms rendered content HTML before it is sent to the public template.

Typical uses:

- lightbox classes on images
- paragraph-level content injection
- syntax highlighting wrappers
- typography transforms

### `cms.content.query_args`

**Signature:** `fn(array $args, string $contentType): array`

Adjusts public content query parameters used by CMS list/archive flows.

Typical uses:

- change items per page
- alter sort order
- inject content-type-specific visibility rules

---

## 5. Registration example

```php
app()->hooks()->on('cms.builder.widgets', function (array $widgets): array {
    $widgets[] = [
        'type' => 'callout',
        'label' => 'Callout',
        'icon' => '📢',
        'category' => 'marketing',
        'kind' => 'widget',
        'default_props' => [
            'text' => '',
            'style' => 'info',
        ],
    ];
    return $widgets;
}, 10);
```

---

## 6. Best practices

- Return the same value type you receive
- Keep hook handlers deterministic and side-effect-light
- Prefer hook registration in your module bootstrap/helper load path
- Treat hook payloads as public contracts once released
- Use capabilities, not hooks, when you need synchronous business operations with explicit request/response semantics

---

## 7. Current limitations

- The hook surface is broader than the formal CapabilityBus surface
- Not every admin or public behavior is extensible yet
- Theme template manifest compatibility currently needs clarification (`templates` vs `pageTemplates`)
- Some builder internals remain route/API oriented rather than contract-first

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

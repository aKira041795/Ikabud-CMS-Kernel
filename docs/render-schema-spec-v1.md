# Render Schema Spec v1

## Purpose

This document defines the first implementation-ready render schema and context profile inventory.

It is intentionally narrow. The goal is to put stable names, ownership, and runtime metadata around the render boundaries that already exist today.

This spec does not replace the current render-contract system. It layers named schema and profile metadata onto it so the current runtime and tests can keep working while the platform becomes more explicit.

Related roadmap: `docs/render-schema-context-profiles-plan.md`
Profile companion spec: `docs/context-profiles-spec-v1.md`

---

## Scope

Render Schema v1 covers:

- schema naming
- profile naming
- initial schema inventory
- runtime metadata to add without breaking current contract ids
- the first logging and testing requirements

Render Schema v1 does not yet cover:

- new DiSyL syntax
- full contract-aware linting
- a full admin schema taxonomy
- a complete schema for every historical CMS page template

---

## Key Decision

Current render contract ids stay stable.

That means existing ids such as:

- `ecommerce.public.shell`
- `ecommerce.public.catalog`
- `ecommerce.public.product`

remain the runtime registration keys.

Schema ids are additive metadata, not replacements for those ids.

This avoids breaking:

- current tests
- current module helper registrations
- current log/event expectations

---

## Naming Rules

### Schema ids

Schema ids use:

- `<domain>.<surface>[.<variant>]@<major>`

Examples:

- `kernel.shell@1`
- `cms.public.entity.view@1`
- `cms.public.entity.list@1`
- `ecommerce.public.shell@1`
- `ecommerce.public.catalog@1`
- `ecommerce.public.product@1`

Rules:

- the major version changes only for breaking root-contract changes
- ids must be stable and appear exactly the same in docs, logs, tests, and runtime metadata
- schema ids describe the documented shape, not the PHP function name

### Profile ids

Profile ids use:

- lowercase snake_case experience families

Examples:

- `cms_public`
- `commerce_public`
- `admin`
- `shell_only`

Rules:

- a profile describes the request experience family, not a single template
- a profile resolves before DiSyL executes
- a profile may produce a schema stack instead of one schema id

---

## Runtime Metadata

Render Schema v1 should add metadata to the current runtime without renaming the existing contract registry keys.

### Contract registry additions

`kernelRegisterRenderContextContract()` should accept these optional keys:

```php
[
    'schema_id' => 'ecommerce.public.catalog@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
]
```

Notes:

- `schema_id` is the canonical documented schema name for that contract layer.
- `schema_version` is redundant with `@1`, but useful for filters and later tooling.
- `profile_hint` is optional and only valid when the contract belongs to one profile family.

### Resolved render metadata

After profile resolution and contract matching, the runtime should be able to report:

```php
[
    'render_profile_id' => 'commerce_public',
    'render_schema_stack' => [
        'kernel.shell@1',
        'ecommerce.public.shell@1',
        'ecommerce.public.catalog@1',
    ],
]
```

Rules:

- `render_schema_stack` is ordered from broadest layer to most specific layer.
- the stack is resolved from matched contracts plus any profile-owned shell schema.
- CMS canonical templates that still use `cmsCanonicalRenderContextNormalize()` should emit the same metadata even before they are migrated into the kernel registry.

---

## Initial Profile Inventory

### Implement now

#### `cms_public`

Use for:

- canonical CMS entity pages
- canonical CMS entity lists
- CMS-controlled public pages that render through the public theme shell

Expected schema stack:

- `kernel.shell@1`
- one of:
  - `cms.public.entity.view@1`
  - `cms.public.entity.list@1`
  - later `cms.public.page@1`

Primary producers:

- `modules/cms/helpers/78-public-context.php`
- CMS entity projection helpers
- CMS customizer/theme helpers

#### `commerce_public`

Use for:

- storefront catalog routes
- product detail routes
- cart and checkout routes
- order history and order detail routes

Expected schema stack:

- `kernel.shell@1`
- `ecommerce.public.shell@1`
- one route-specific ecommerce schema

Primary producers:

- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/05-render-contracts.php`

#### `admin`

Use for:

- admin and module dashboard renders that pass through admin shells

Expected schema stack:

- later formalized after admin shell inventory

Primary producers:

- module-specific admin contracts already registered through `kernelRegisterRenderContextContract()`

### Reserve now, implement later

#### `shell_only`

Reserved for very thin renders that only require shell metadata and no route-specific business schema.

#### `guidance_public`

Reserved for a future public guidance surface if it becomes meaningfully different from generic CMS public rendering.

---

## Initial Schema Inventory

### Schemas ready to wire in the first pass

#### `kernel.shell@1`

Status:

- new named schema over an already-existing public shell context

Owned by:

- currently `modules/cms/helpers/78-public-context.php`
- later profile-owned with kernel orchestration

Required roots:

- `page_title`
- `theme_style_url`
- `colors_style`
- `theme_layout_style`
- `custom_css`
- `head_code`
- `body_end_code`
- `active_theme_slug`
- `has_customized_header`
- `customized_header`
- `has_customized_footer`
- `customized_footer`
- `public_render_origin`
- `public_route_kind`
- `public_presentation_mode`

Common optional roots:

- `has_customized_sidebar`
- `customized_sidebar`
- `sidebar_position`
- `sidebar_width`
- `theme_script_url`
- `theme_settings`
- `storefront`

Notes:

- this is the broad public shell layer shared by CMS and ecommerce renders
- it should be included in logs even when the route-specific schema is different

#### `cms.public.entity.view@1`

Current runtime source:

- `cmsCanonicalRenderContextNormalize()` with contract name `entity.view`

Template match:

- any canonical `entity.view.disyl`

Required roots:

- `entity`
- `capabilities`
- `capability_data`
- `entity_context`
- `entity_view_context`
- `entity_presentation`
- `entity_taxonomies`
- `post_html`
- `builder_enabled`
- `builder_page_settings`
- `cart_enabled`
- `cart_action_url`
- `action_sections`
- `public_render_origin`
- `public_route_kind`
- `public_presentation_mode`

Canonical detail spec:

- `docs/entity-view-block-schema.md`

#### `cms.public.entity.list@1`

Current runtime source:

- `cmsCanonicalRenderContextNormalize()` with contract name `entity.list`

Template match:

- any canonical `entity.list.disyl`

Required roots:

- `items`
- `entity_list_context`
- `entity_presentation`
- `pagination`
- `public_render_origin`
- `public_route_kind`
- `public_presentation_mode`

Common optional roots:

- `cms_head`
- `storefront`

#### `ecommerce.public.shell@1`

Current runtime source:

- contract id `ecommerce.public.shell`
- normalizer `ecNormalizePublicShellRenderContext()`

Template match:

- prefix `modules/ecommerce/public/`

Required roots:

- `page_title`
- `storefront`
- `cart_count`
- `ec_settings`
- `public_render_origin`
- `public_route_kind`
- `public_presentation_mode`

Common optional roots:

- `theme_style_url`
- `colors_style`
- `theme_layout_style`
- `custom_css`
- `head_code`
- `active_theme_slug`
- `has_customized_header`
- `customized_header`
- `has_customized_footer`
- `customized_footer`
- `theme_script_url`
- `body_end_code`
- `year`

#### `ecommerce.public.catalog@1`

Current runtime source:

- contract id `ecommerce.public.catalog`
- normalizer `ecNormalizeCatalogRenderContext()`

Template match:

- `modules/ecommerce/public/shop.disyl`

Required roots:

- `products`
- `available_categories`
- `search`
- `category_id`
- `page`
- `total`
- `total_pages`

Common optional roots:

- `categories`
- `current_cat`
- `per_page`
- `all_items_url`
- `search_action_url`
- `visible_count`
- `catalog_category_count`
- `pagination_first_url`
- `pagination_prev_url`
- `pagination_next_url`

#### `ecommerce.public.product@1`

Current runtime source:

- contract id `ecommerce.public.product`
- normalizer `ecNormalizeProductRenderContext()`

Template match:

- `modules/ecommerce/public/product.disyl`

Required roots:

- `product`

#### `ecommerce.public.cart@1`

Current runtime source:

- contract id `ecommerce.public.cart`
- normalizer `ecNormalizeCartRenderContext()`

Template match:

- `modules/ecommerce/public/cart.disyl`

Required roots:

- `cart`
- `shipping_rates`

Common optional roots:

- `message`

#### `ecommerce.public.checkout@1`

Current runtime source:

- contract id `ecommerce.public.checkout`
- normalizer `ecNormalizeCheckoutRenderContext()`

Template match:

- `modules/ecommerce/public/checkout.disyl`

Required roots:

- `cart`
- `shipping_rates`

Common optional roots:

- `payment_label`
- `is_customer`

#### `ecommerce.public.orders@1`

Current runtime source:

- contract id `ecommerce.public.orders`
- normalizer `ecNormalizeOrdersListRenderContext()`

Template match:

- `modules/ecommerce/public/my-orders.disyl`

Required roots:

- `orders`
- `total`
- `page`
- `total_pages`

#### `ecommerce.public.order.detail@1`

Current runtime source:

- contract id `ecommerce.public.order.detail`
- normalizer `ecNormalizeOrderDetailRenderContext()`

Template match:

- `modules/ecommerce/public/order-detail.disyl`

Required roots:

- `order`

#### `ecommerce.public.order.confirmation@1`

Current runtime source:

- contract id `ecommerce.public.order.confirmation`
- normalizer `ecNormalizeOrderConfirmationRenderContext()`

Template match:

- `modules/ecommerce/public/order-confirmation.disyl`

Required roots:

- `order`
- `is_logged_in`

Common optional roots:

- `payment_label`

### Reserved schema names for the next pass

#### `cms.public.page@1`

Reserved, but not fully specified in this pass.

Reason:

- generic CMS public pages still need a template family audit before the root contract can be declared as stable

#### `admin.page@1`

Reserved, but not fully specified in this pass.

Reason:

- current admin contracts are module-specific and need an inventory pass before a single shared admin shell schema is declared

---

## Implementation Rules

### Rule 1

Do not rename current contract ids just to match schema ids.

### Rule 2

CMS canonical entity renders may keep using `cmsCanonicalRenderContextNormalize()` in the short term, but they must emit the same render-profile and render-schema-stack metadata as kernel-registered contracts.

### Rule 3

The runtime must resolve one profile id and a schema stack for every canonical public render.

### Rule 4

Schema ids must be visible in logs without enabling strict mode.

### Rule 5

If a root is used by templates but not declared in the schema spec, that is a schema bug and should be fixed in the schema or producer code, not hidden in template conditionals.

---

## Logging Requirements

Mismatch and trace logs should move toward these fields:

- `template`
- `render_profile_id`
- `render_schema_stack`
- `contract`
- `public_route_kind`
- `public_presentation_mode`
- `public_render_origin`
- `missing_keys`
- `type_mismatches`

The current mismatch event names can stay the same in Render Schema v1.

---

## Test Requirements

Render Schema v1 should add or update coverage for:

1. registry metadata presence for ecommerce contracts
2. schema/profile metadata on canonical CMS entity view and entity list renders
3. stable schema-stack ordering for commerce public routes
4. log payload coverage for render profile and schema stack
5. preservation of current contract ids and current normalization behavior

Likely starting tests:

- `tests/render_context_contracts_test.php`
- `tests/cms_public_entity_contract_test.php`
- `tests/cms_theme_test.php`

---

## File-Level Starting Map

Runtime:

- `bootstrap.php`
- `kernel/App.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/05-render-contracts.php`

Docs:

- `docs/render-schema-context-profiles-plan.md`
- `docs/entity-view-block-schema.md`
- `docs/disyl-implementation-spec.md`
- `docs/module-development-guide.md`

Tests:

- `tests/render_context_contracts_test.php`
- `tests/cms_public_entity_contract_test.php`
- `tests/cms_theme_test.php`

---

## First Implementation Sequence

1. Extend the kernel contract registry with additive schema metadata.
2. Add profile resolution helpers for `cms_public` and `commerce_public`.
3. Emit `render_profile_id` and `render_schema_stack` for CMS canonical entity renders.
4. Annotate ecommerce contracts with schema ids.
5. Add tests that freeze the new names.
6. Update the broader docs once runtime metadata is live.

That is enough to establish the Render Schema v1 foundation without taking on linting or new DiSyL language work yet.

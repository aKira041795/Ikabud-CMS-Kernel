# ARK Capability Bridge

## Purpose

The ARK capability bridge is the theme-side contract that maps cross-module entities to stable visual representations.

It answers:

- which entity views a module publishes for ARK
- which fields each view expects
- which user actions are available in each view
- which ARK block type represents the entity in compact or specialized contexts

The current source of truth is:

- `storage/cms-themes/ark/entity-view-map.json`

## Why It Exists

Without a capability bridge, each module can drift into its own visual vocabulary.

With the bridge in place:

- modules declare entity data and capabilities
- ARK declares the visual contract for presenting those entities
- builders and templates can use stable block names and view names across modules

## Contract Shape

Each entity entry declares one or more named views.

Example:

```json
{
  "ecommerce_product": {
    "compact": {
      "fields": ["name", "price", "image", "stock_status"],
      "actions": ["view", "add_to_cart"],
      "block": "product_card"
    },
    "detail": {
      "fields": ["name", "price", "images", "description", "stock_status"],
      "actions": ["add_to_cart", "wishlist"]
    }
  }
}
```

## Current ARK Domains

The current ARK entity-view map covers:

- `cms_post`
- `ecommerce_product`
- `ehr_patient`
- `ehr_appointment`
- `bakeshop_product`
- `wms_stock`

## Validation Rules

`ThemeManifestValidator` currently checks that each entry:

- has a non-empty entity type name
- declares at least one named view
- provides a non-empty `fields` array
- keeps `actions` as an array when declared
- references a known ARK block when `block` is present

## Builder and Theme Usage

The bridge is not just documentation.

It supports:

- ARK template conventions for module-specific cards and detail views
- future Theme Studio presets that can be derived from known entity view names
- future builders that want module-aware insertions without inventing their own schema

## Authoring Guidance

- Keep field lists presentation-oriented, not persistence-oriented.
- Use stable entity identifiers.
- Reference published ARK block types only.
- Treat changes as contract changes: update `entity-view-map.json`, docs, and tests together.

## Validation Commands

```bash
php ikabud theme:validate ark
php tests/theme_manifest_validation_test.php
php tests/ark_theme_test.php
```

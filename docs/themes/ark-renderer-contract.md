# ARK Renderer Contract

## Purpose

ARK renderer contracts define how a schema-valid ARK block becomes rendered output in Ikabud.

The contract boundary is:

1. `block-definitions/*.json` describe what a block is.
2. `renderer-registry.json` maps the block or variant to a render target.
3. DiSyL templates or governed components render the block.
4. `ThemeManifestValidator` verifies the mapping, template existence, and declared context requirements.

ARK builders do not invent renderer behavior. They select from the renderer contract that the theme publishes.

## Source Files

The active ARK renderer contract is published in:

- `storage/cms-themes/ark/renderer-registry.json`
- `storage/cms-themes/ark/public/blocks/**/*.disyl`
- `kernel/Services/ThemeManifestValidator.php`

## Render Target Types

Each renderer entry must declare exactly one render target.

### Template renderer

Use `template` when the renderer maps to a DiSyL block file.

Example:

```json
{
  "media_gallery": {
    "template": "public/blocks/media-gallery.block.disyl",
    "controls": ["lightbox", "columns"],
    "context_keys": ["entity", "capability_data"]
  }
}
```

### Governed component renderer

Use `renders_as_component` when the renderer maps to a registered DiSyL component.

Example:

```json
{
  "entity_list": {
    "renders_as_component": "ikb_entity_list",
    "controls": ["source", "view", "filter", "limit"],
    "context_keys": ["source", "view", "filter", "limit"]
  }
}
```

## Required Fields

Each renderer entry should publish:

- `template` or `renders_as_component`
- `controls`
- `context_keys`

`ThemeManifestValidator` warns when:

- both render targets are missing
- both render targets are declared together
- the template path is missing
- the component is not registered
- `controls` is empty
- `context_keys` is empty

## Template Context Header

Template-backed renderers must declare a `Context:` header near the top of the block template comment.

Example:

```disyl
{# ARK: Media Gallery Block
    Context: entity, capability_data
    Featured image + gallery thumbnails with lightbox support. #}
```

The validator reads that header and checks that every `context_keys` value declared in `renderer-registry.json` appears in the template header.

## Context Rules

Renderer context must come from approved public render context seams only.

Common ARK renderer context keys are:

- `entity`
- `capability_data`
- `entity_presentation`
- `content`
- `source`
- `view`
- `filter`
- `limit`
- `id`

A renderer should not assume direct database access or ad hoc globals.

## ARK Runtime Flow

```text
Block definition
  -> renderer-registry.json
  -> template or component target
  -> validated public render context
  -> escaped DiSyL output
  -> HTML
```

## Current ARK Coverage

The current ARK registry includes:

- governed component renderers for `entity_list` and `entity_detail`
- template renderers for metadata, media, pricing, inventory, action, progress, lessons, and list-card variants
- validator-backed context header enforcement

## Validation Commands

Use these commands when changing the renderer contract:

```bash
php ikabud theme:validate ark
php tests/ark_renderer_contract_test.php
php tests/ark_theme_test.php
```

## Authoring Guidance

- Prefer one renderer target per entry.
- Keep `context_keys` narrow and explicit.
- Add or update the template `Context:` header whenever registry keys change.
- Treat renderer changes as contract changes: update docs and tests in the same patch.

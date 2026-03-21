# TinyMCE Module Scaffold Plan

## Goal

Add `modules/tinymce/` as a shared editor service that can be consumed by CMS, Guidance, and future modules through capability contracts.

## Scope

The first implementation slice should create:

- `modules/tinymce/module.json`
- `modules/tinymce/helpers.php`
- `modules/tinymce/routes.php`

## Initial capabilities

- `tinymce.assets.get@1`
- `tinymce.config.get@1`
- `tinymce.html.normalize@1`
- `tinymce.html.sanitize@1`

## Recommended first payloads

### `tinymce.assets.get@1`

Input:

- `context` optional

Output:

- `js_urls`
- `css_urls`
- `version`

### `tinymce.config.get@1`

Input:

- `context` such as `cms.content` or `guidance.session`
- `profile` optional
- `readonly` optional

Output:

- `selector`
- `plugins`
- `toolbar`
- `menubar`
- `branding`
- `height`

### `tinymce.html.normalize@1`

Input:

- `html`
- `context` optional

Output:

- `html`

### `tinymce.html.sanitize@1`

Input:

- `html`
- `context` optional

Output:

- `html`

## Design rules

- TinyMCE must not access CMS or Guidance tables directly.
- TinyMCE should not own business workflows.
- TinyMCE should only own editor assets, configuration, and HTML transforms.
- CMS and Guidance should remain the owners of their domain events.

## Next integration steps

1. Update CMS editor pages to request TinyMCE assets/config via capabilities.
2. Update CMS save paths to call normalize/sanitize capabilities.
3. Reuse the same contracts in Guidance editor screens.
4. Add optional TinyMCE events only if there is a real downstream need.

## Acceptance criteria

- Module loads with no routes required.
- Capabilities register on helper load.
- CMS can call all four capabilities.
- Guidance can call all four capabilities once policy is expanded.
- No direct cross-module table coupling is introduced.

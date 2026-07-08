# ARK Safety Policy

## Purpose

ARK safety policy defines what theme templates may render, what patterns are blocked, and which raw-output seams are allowed.

The active policy lives in:

- `storage/cms-themes/ark/safety-policy.json`
- `kernel/Services/ThemeManifestValidator.php`

## Policy Model

The ARK safety contract currently publishes:

- `policy.raw_output.allowed_keys`
- `policy.raw_output.requires_capability`
- `policy.allowed_context_sources`
- `policy.blocked_patterns`
- `policy.allowed_js_bridges`
- `policy.csp_note`

## Raw Output Rules

`|raw` is not generally freeform in ARK templates.

A raw expression must:

1. come from an allowlisted context key in `safety-policy.json`
2. be sourced from a kernel-approved render seam
3. remain compatible with the CSP and DiSyL safety model

Examples of currently allowlisted raw seams include:

- `post_html`
- `content_html`
- `structured_data`
- `colors_style`
- `theme_layout_style`
- `header_region.html`
- `sidebar_region.html`
- `footer_region.html`
- `primary_menu`
- `footer_menu`
- `link.icon`

## Blocked Patterns

The current ARK policy blocks these template behaviors:

- direct database queries
- PHP function invocation from templates
- session access
- cookie writes
- filesystem access

These are validated as theme warnings by `ThemeManifestValidator`.

## CSP and Client-Side Behavior

ARK themes should not rely on inline `onclick` handlers.

Approved client-side bridges are:

- Alpine
- htmx
- project-approved custom script bridges

Inline event attributes are treated as policy violations.

## Allowed Context Sources

Current approved context origins are:

- `kernel`
- `cms`
- `entity_view`
- `customizer`
- `theme`

Anything outside these seams should be treated as a design smell and reviewed before use.

## Validation Behavior

`ThemeManifestValidator` currently checks:

- non-allowlisted `|raw` expressions
- blocked pattern matches in `.disyl` templates
- inline `onclick` handlers
- undeclared slot usage
- unregistered `ikb_*` components

## Validation Commands

```bash
php ikabud theme:validate ark
php tests/ark_safety_test.php
php tests/ark_theme_test.php
```

## Authoring Guidance

- Default to escaped output.
- Use `|raw` only for contract-approved content channels.
- Keep template logic declarative; move imperative behavior into PHP or governed JS.
- Never add direct persistence, session, or filesystem behavior to DiSyL templates.
- When a safety exception is real, update `safety-policy.json`, documentation, and tests in the same patch.

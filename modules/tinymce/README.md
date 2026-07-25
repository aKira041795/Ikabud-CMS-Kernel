# TinyMCE

Shared rich-text editor service module. Provides TinyMCE assets, configuration presets, HTML normalization, and HTML sanitization for downstream modules.

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `tinymce.assets.get@1` | Get TinyMCE editor assets |
| `tinymce.config.get@1` | Get editor configuration for a context |
| `tinymce.html.normalize@1` | Normalize HTML content for consistent storage |
| `tinymce.html.sanitize@1` | Sanitize HTML against XSS and malformed markup |

## Callable by

`cms`, `guidance`, `tinymce` (capability policy-restricted)

## Files

- Manifest: [`module.json`](module.json)

# Content Ingestion

Event-driven, adapter-based ingestion pipeline for importing content from external sources into the CMS. Designed with adapter pattern for extensibility to multiple source types.

## Architecture

- **Event-driven**: triggered by kernel events, not direct handler calls
- **Adapter-based**: each source type (WordPress, etc.) implements a common adapter interface
- **Pipeline**: fetch → transform → import → reconcile

## Current Sources

| Source | Status |
|--------|--------|
| WordPress | Supported (`wordpress-importer`) |

## Files

- Manifest: [`module.json`](module.json)

## Dependencies

- `cms` — target system for imported content

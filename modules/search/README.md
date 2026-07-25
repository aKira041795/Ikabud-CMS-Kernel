# Search Index

Cross-module search index with capability-based query API and event-driven indexing. Co-owns the `kernel_search_index` table (kernel-managed storage, module-controlled indexing logic).

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `search.query@1` | Execute a cross-module search query |
| `search.index.upsert@1` | Update or insert content into the search index |

## Architecture

- **Event-driven**: modules emit events when content changes; search module listens and updates the index
- **Capability-based**: query and index operations are exposed as kernel capabilities for policy-enforced access
- **Cross-module**: results span all modules that participate in indexing

## Files

- Manifest: [`module.json`](module.json)

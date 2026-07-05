# Deploy Cache Invalidation

## Why this matters

Ikabud runtime behavior depends on multiple cache layers:

- DiSyL compiled templates on disk (`storage/cache/disyl/`)
- Runtime file cache (`storage/cache/`)
- APCu in-memory cache

A code deploy that updates templates/layouts/helpers can remain stale if only one layer is cleared.

## Required deploy step

Run this after every deploy:

```bash
php ikabud cache:clear
```

This clears:

- DiSyL compiled templates
- general file cache
- APCu in-memory cache (when enabled)

## Targeted cache clear options

```bash
php ikabud cache:clear --disyl-only
php ikabud cache:clear --apcu-only
```

## Recommended deployment sequence

1. Pull code + install dependencies.
2. Run migrations.
3. Build frontend assets (if applicable).
4. Run `php ikabud cache:clear`.
5. Restart PHP-FPM if your environment requires process-level reset.

## Verification

After cache clear, verify expected template/layout updates through a real request and inspect:

- `storage/logs/app.log`
- `storage/logs/error.log`

If stale output persists, confirm APCu is enabled and that PHP-FPM workers were restarted on the target host.
# Deploy Cache Invalidation

## Why this matters

Ikabud runtime behavior depends on multiple cache layers:

- DiSyL compiled templates on disk (`storage/cache/disyl/`, `storage/cache/compiled/`)
- Runtime file cache (`storage/cache/`)
- **Full-page cache** - `storage/cache/pagecache_t*/` (`.cache_version` + `.flush` markers)
- APCu in-memory cache, which is **per php-fpm worker**

A code deploy that updates templates/layouts/helpers can remain stale if only one layer is cleared.
The page cache matters most: it serves whole rendered HTML, so a stale entry there is a stale page
rather than a stale fragment.

## Required deploy step

Run this after every deploy:

```bash
php ikabud cache:clear
```

This clears:

- DiSyL compiled templates
- general file cache, and the shared module-scan cache
- the page cache for **every** instance, by bumping `.cache_version` and touching `.flush`
- this process's APCu store
- and publishes a new configuration generation

**APCu in php-fpm is not reachable from the CLI.** `apcu_clear_cache()` in a CLI process clears
only that process's own store, which the workers never share. What actually invalidates the
workers is the **configuration generation** the command publishes, which each worker revalidates
against on its next read. That is also why the pre-bootstrap fast path and the kernel page-cache
check compare a file-backed version token instead of trusting APCu.

## If the purge cannot take effect

The page-cache markers live in instance directories created by the **web user**. A purge run as a
different account cannot write them, and that version bump is the only signal that stops a warm
worker serving pre-purge HTML.

The command no longer hides this. An instance whose version cannot be read back is reported as

```
  △ Page cache pagecache_t1: NOT purged — version read back empty
      cause: cannot write storage/cache/pagecache_t1
      fix:   run the purge as the web user, e.g. sudo -u www-data php ikabud cache:clear
```

and the command **exits 1** where it previously exited 0. Treat that as a real deploy result, not
noise: the remedy is to run the purge as the web user. There is no code-side workaround, because
the CLI user cannot chmod a directory it does not own.

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

- the command's **exit code**, and any `△` lines in its output (a non-zero exit means at least one
  layer did not purge - see above)
- `php ikabud cache:stats` - the page-lock count and oldest-lock age, and the age of the
  maintenance marker
- `storage/logs/app.log`
- `storage/logs/error.log`

If stale output persists, confirm APCu is enabled and that PHP-FPM workers were restarted on the target host.
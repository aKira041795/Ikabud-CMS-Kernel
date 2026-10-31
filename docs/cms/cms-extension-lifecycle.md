# CMS Extension Lifecycle — install, configure, update

This is the operating contract for a CMS extension: the ZIP a creator ships, what the
installer checks before it touches the disk, how an operator configures it, and how it
gets updated afterwards.

The model follows **Joomla's update-server contract** (each extension publishes its own
signed feed, so a marketplace is not a prerequisite) with **WordPress's per-extension
update UX** (a visible "update available", one click to apply, an opt-in auto-update
switch, and a rollback). Drupal's Composer-first model is deliberately not used: there is
no Composer build step on the Bluehost target and tenants install from the admin UI.

---

## 1. The package

A package is a `.zip` containing `module.json` at the root, or inside exactly one
top-level folder.

```
my-extension.zip
├── module.json
├── handlers.php
├── routes.php
├── helpers.php
├── migrations/
└── __ikabud_package/templates/     # optional: files destined for templates/modules/<id>/
```

Limits enforced by both installers: 2000 entries, 200 MiB uncompressed (the CMS upload
endpoint additionally caps the uploaded body at 50 MB). Entry names are sanitised before
anything is extracted — absolute paths, drive letters, `..`, null bytes, symlinks and
special files are refused.

### Manifest fields that matter here

| Field | Purpose |
|---|---|
| `id`, `name`, `version` | Identity. `id` must be `[a-z0-9_-]+`. `version` drives the update comparison. |
| `kind`, `extends`, `suite` | Product-suite contract. An `extension`/`adapter` must extend an **installed** host. |
| `contributes`, `admin_contributions` | Admin surfaces the extension adds to its host's declared `extension_points`. |
| `compatibility` | `{kernel, suite}` ranges. Enforced at install and update. |
| `capabilities` | `exposes`/`depends`. Validated before install; unsatisfiable dependencies leave the module installed but disabled. |
| `settings_fields` | The Configure panel is generated from this list. |
| `uninstall` | `disable_safe`, `retain_data_by_default`, `supports_data_export`, `requires_confirmation_to_drop_data`. |
| `update_url` | **New.** HTTPS URL of the creator's update feed. Without it the module is never checked for updates. |
| `update_channel` | **New.** `stable` (default) or a channel the feed also publishes. |

---

## 2. Install

`POST /api/v1/cms/modules/upload` (multipart, field `module`, requires `settings.manage`).

Order of gates — each one fails closed, and none of them is skippable from the UI:

1. suffix, size, `is_uploaded_file`;
2. ZIP signature bytes and archive safety (Zip Slip, symlinks, sizes);
3. **package signature** — `moduleVerifyPackageSignature()` on `module.sig.json`
   (Ed25519). Advisory by default, fatal when `MODULE_SIGNING_REQUIRED=true`;
4. `module.json` schema and capability manifest;
5. `modulePackagePreflightGates()` — capability manifest, product-suite ownership
   (`extends` host present, contribution hosts declared), and `compatibility` ranges.
   Workbench certification is **not** required on this path: a third-party extension does
   not ship a `workbench-contract.json`;
6. copy into `modules/<id>` (or the product-suite container), write `.cms-owned`, register
   the module for this tenant, run its migrations, run its `*_module_setup()` hook.

Installing an id that already exists is an **update**, not an install — see below.

---

## 3. Configure

`PATCH /api/v1/cms/modules/{id}/settings` with `{"settings": {...}}`.

The saved keys are restricted to those declared in `settings_fields`; the panel in
`/cms/admin/modules` renders one control per field (`select` or a typed `input`).

---

## 4. Update

### Who applies it

Three entry points, one implementation (`updateModuleFromZip()`):

| Trigger | Path |
|---|---|
| Operator re-uploads a ZIP for an installed id | `POST /api/v1/cms/modules/upload` |
| Operator clicks **Update** on an advertised version | `POST /api/v1/cms/modules/{id}/update` |
| Cron, for extensions that opted in | `php scripts/check-extension-updates.php --apply` |

### What an update enforces

1. The package passes every gate the installer applies (1–5 above), plus
   `moduleVerifyPackageSignature()` again on the package itself.
2. **Version direction is explicit.** Higher than installed → upgrade. Equal → reinstall
   (reported as `reinstalled: true`, which is how a broken install is repaired). Lower →
   refused with `module_downgrade_blocked` unless the caller passes `allow_downgrade`.
   A refused update leaves the installed tree untouched.
3. The tree is extracted to a staging directory and re-validated there — a package may not
   touch the live directory before it is provably complete.
4. The live directory is renamed to `modules/<id>.bak_<Ymd_His>`; `templates/modules/<id>`
   gets the same treatment when the package ships templates. Any failure after the swap
   restores both and reports `module_update_failed_rolled_back`.
5. The module is re-enabled only if it was enabled before. Cache is flushed either way.

`modulePackageBackupGc($keepDays = 7)` removes stale backups. It refuses every path that
does not end in `.bak_\d{8}_\d{6}`, so an imprecise name can never authorise a recursive
delete of a module or suite directory.

---

## 5. Creator-published update feeds

A manifest may declare:

```json
{ "id": "my-extension", "version": "1.1.0",
  "update_url": "https://example.com/my-extension/update.json" }
```

That URL must serve:

```json
{
  "id": "my-extension",
  "versions": [
    { "version": "1.2.0",
      "package_url": "https://example.com/my-extension-1.2.0.zip",
      "sha256": "<hex digest of the exact zip bytes>",
      "channel": "stable",
      "requires_kernel": ">=6.2",
      "released_at": "2026-10-01",
      "notes": "Fixes ..." }
  ]
}
```

Rules the updater applies:

- **`update_url` must be in the PACKAGE manifest.** An update replaces the module tree,
  so a feed declaration patched onto the installed copy afterwards does not survive the
  next update.
- `https` only. Loopback and private-network hosts are refused, so a package cannot aim the
  updater at the server it runs on. (`APP_EXTENSION_FEEDS_ALLOW_HTTP=1` relaxes the scheme
  *and* the host rules together, for local development only — never set it in production.)
- `sha256` is **mandatory**. A version without a digest is skipped, not trusted — an update
  the installer cannot verify is remote code execution with a version number.
- `requires_kernel` entries that this kernel does not satisfy are skipped.
- The feed's `id` must match the module id.
- The downloaded body is capped at 50 MB and its digest compared with `hash_equals` before
  the path is handed to the updater.

### Auto-update

Off by default, per extension, and only offered when a feed exists:

`POST /api/v1/cms/modules/{id}/auto-update` with `{"enabled": true}`.

Cron:

```
0 */6 * * * php /path/to/scripts/check-extension-updates.php --apply
```

Without `--apply` the script only refreshes state — it changes no installed code. That is
the safe default, and the CMS shows the difference: an operator sees `1.1.0 → 1.2.0` and
decides; only opted-in extensions change without a click.

### Reading the state

Cached state lives in `kernel_update_sync_state` under `extension_update:<module_id>`.
Rendering `/cms/admin/modules` **never** touches the network: the page reads the cached
row, and only the explicit **Check for Updates** action (or the cron script) fetches feeds.

---

## 6. Disable, delete, uninstall

| Action | Endpoint | Behaviour |
|---|---|---|
| Disable | `POST /api/v1/cms/modules/toggle` | Module stops participating for this tenant; data retained. |
| Delete | `POST /api/v1/cms/modules/{id}/delete` | Only when disabled; removes the module from this site (shared code on disk is preserved in a multi-tenant install). Data is never touched. |
| Uninstall | `POST /api/v1/cms/modules/{id}/uninstall` | Removes files too, and honours the manifest's `uninstall` block for data. |

### The uninstall dialog

The screen offers **Uninstall…** for a disabled module, and the options it shows come from
that module's own `uninstall` policy — the UI never offers a choice the server will refuse:

- **Keep the data** is the default. Reinstalling later restores the content.
- **Also delete the data** appears with the number of tables the module owns. When the
  manifest sets `requires_confirmation_to_drop_data`, a second acknowledgement is required
  (unchecked by default — a data drop is not the place to assume permission).
- **Export before deleting** appears only when the module declares `supports_data_export`.
- **Uninstall anyway** appears only when the module declares `disable_safe: false`.

### Multi-tenant installs

Uninstall deletes `modules/<id>` — a directory every tenant shares — and drops module-owned
tables. When one install serves several tenants, neither is one tenant's to remove, so the
endpoint refuses with `uninstall_requires_superadmin_in_multitenant` and the screen does not
offer the action at all. **Delete** remains the tenant-scoped removal path there.

Required always-active modules and kernel modules are refused by all of the above.

---

## 7. Known gaps (not implemented here)

- **No marketplace.** Feeds are decentralised by design; a directory index would be an
  additive discovery layer over the same contract.
- **One code version per install.** `modules/` is shared by every tenant, so the first
  installer of a version sets it for all tenants that activate the module. Per-tenant code
  versions would require per-tenant module roots.
- **No per-tenant data purge.** Because of the point above, dropping a module's tables from a
  tenant session is refused rather than guessed at; a superadmin performs it.

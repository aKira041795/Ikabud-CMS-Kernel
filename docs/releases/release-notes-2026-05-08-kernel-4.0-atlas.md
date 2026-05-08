# Kernel 4.0 "atlas" — Release Notes

**Release date:** 2026-05-08
**Codename:** atlas
**Predecessor:** 3.2 "lattice" ([release notes](release-notes-2026-05-08-kernel-3.2-lattice.md))
**Type:** **Major (BREAKING).** No-runtime-break for compliant modules; removed
two long-deprecated escape hatches.

## TL;DR

Kernel 4.0 finalizes the boundary work begun in 3.x. Kernel core classes are
now `final`, the legacy `_kernel_db_unguarded` request flag is removed,
manifest table ownership splits cleanly into `owns_tables` vs
`co_owns_tables`, and the planned-but-unimplemented DiSyL grammar is moved to
its own namespace so the live grammar surface stays focused.

This release **does not** introduce new runtime features. It is a hardening +
clarity release that locks the architecture in before the workflow runtime
work begins in 4.1.x.

## Breaking changes

| Change | Impact | Migration |
| ------ | ------ | --------- |
| `kernel/App.php`, `kernel/Hooks.php`, `kernel/EventBus.php`, `kernel/JWT.php`, `kernel/Http/SecurityHeaders.php`, `kernel/Contracts/ModuleContext.php`, all `kernel/DiSyL/v4/**` classes are now **`final`**. | Modules cannot subclass kernel internals (no in-tree subclassers existed). | Compose, do not extend. Use the documented capability + hook APIs. |
| `_kernel_db_unguarded` request-context flag **removed** from `kernel/Database/KernelPDO.php`. | Any caller still relying on the flag will fail enforcement instead of silently bypassing. | Use `KernelPDO::kernelEscalationEnter()` / `KernelPDO::kernelEscalationLeave()` (typed counter, kernel-internal callers only). |
| `modules/wordpress-importer/` **removed.** Canonical source is now `packages/cms-wordpress-importer/`. | Tenants that had the bundled module installed must re-install via the package archive. | Run the cms-wordpress-importer package installer; the helper at `modules/cms/helpers/77-import-export.php` already falls back to the package path. |
| `kernel/DiSyL/Grammar.php` **PLANNED keyword constants moved** to `kernel/DiSyL/Grammar/Planned.php`. | Direct use of `Grammar::PATTERN_KEYWORDS` etc. now resolves through `Grammar\Planned`. `Grammar::isUtilityType()` and `Grammar::getAllKeywords()` retained as deprecated shims. | Migrate to `\Ikabud\Kernel\DiSyL\Grammar\Planned::*` directly. |

## New features

### `co_owns_tables` manifest field

Modules can now declare **co-ownership** of a table whose canonical owner is a
different module. This makes shared-table intent explicit and lets the guard
turn accidental ownership collisions into hard errors.

```jsonc
{
  "id": "media",
  "owns_tables": [],
  "co_owns_tables": ["cms_media"],   // canonical owner: cms
  "reads_tables": ["cms_users"]
}
```

See [docs/kernel/co-owned-tables.md](../kernel/co-owned-tables.md) for the
full rules and the current canonical-ownership map.

Migrated in 4.0.0:

| Table | Canonical owner | Co-owners |
| ----- | --------------- | --------- |
| `audit_logs` | `cms` | `daily-ledger`, `wms` |
| `cms_media` | `cms` | `media` |
| `kernel_search_index` | `cms` | `search` |
| `rate_limits` | `ecommerce` | `wms` |

`scripts/guard-module-manifests.php` now reports **0 warnings** on a clean
checkout.

### Kernel flash-message helpers

New global helpers in `bootstrap.php`:

```php
kernel_flash('cms.settings', 'success', 'Saved.');
$msg = kernel_consume_flash('cms.settings'); // ['type'=>'success','text'=>'Saved.']
```

Backed by a single namespaced bag at `$_SESSION['_kernel_flash']`. The
content-ingestion bridge-settings handler is the canonical adopter; ecommerce
and CMS handler migrations are tracked for 4.1.

### Scaffolder enhancements

`scripts/scaffold-module.php` now accepts `--with=capability,event,migration`:

```bash
php scripts/scaffold-module.php my-feature \
    --name="My Feature" \
    --with=capability,event,migration
```

This generates a valid capability expose (`my_feature.example.read@1`), an
event declaration, and a starter migration SQL file alongside the existing
manifest/routes/handlers/smoke-test stubs.

### TenantResolver in-memory cache TTL

The control-host lookup cache in `kernel/TenantResolver.php` now applies the
existing `TENANT_HOST_CACHE_TTL` env (default 30s) to its in-memory layer in
addition to the APCu layer. Stale in-memory entries are evicted on the next
read. A new `memory_expired` counter is exposed via `controlHostCacheMetrics()`.

## Module test stubs

New baseline smoke tests cover three previously-untested modules:

- `tests/sms_module_smoke_test.php`
- `tests/tinymce_module_smoke_test.php`
- `tests/gui_settings_module_smoke_test.php`

Each verifies manifest validation, capability declarations, and discoverability.

## Workflow runtime — published plan

Phase 5 of the kernel roadmap now has a concrete, reviewable plan:
[docs/kernel/workflow-runtime-plan.md](../kernel/workflow-runtime-plan.md).
No runtime code lands in 4.0; M1 (storage + capability stubs) is targeted at
4.1.

## Verification

All shipping smoke tests pass on a clean checkout:

```bash
php tests/disyl_v4_test.php                          # 36 PASS / 0 FAIL
php tests/auth_owned_reserved_role_validation_test.php  # 3 PASS / 0 FAIL
php tests/render_context_contracts_test.php          # 58 PASS / 0 FAIL
php tests/sms_module_smoke_test.php                  # 6 PASS / 0 FAIL
php tests/tinymce_module_smoke_test.php              # 6 PASS / 0 FAIL
php tests/gui_settings_module_smoke_test.php         # 40 PASS / 0 FAIL
php scripts/guard-module-manifests.php               # 0 warnings, 0 errors
```

`storage/logs/app.log` and `storage/logs/error.log` are clean after the suite.

## Upgrade checklist

1. Pull main, verify `App::KERNEL_VERSION === '4.0.0'`.
2. Re-install any tenant that had `modules/wordpress-importer/` enabled, this
   time via `packages/cms-wordpress-importer/`.
3. Audit your modules for two patterns and remediate before deploy:
   - Direct subclassing of any kernel class (very unlikely; not seen in tree).
   - `kernel_request_context_set('_kernel_db_unguarded', ...)` usage. Replace
     with `KernelPDO::kernelEscalationEnter()` / `Leave()`.
4. Re-run `php scripts/guard-module-manifests.php` and reconcile any new
   `[ERROR] ... already declared owned by ...` lines by moving the secondary
   declaration to `co_owns_tables`.

## Contributors

Same authoring team as 3.2 "lattice"; release prepared with assistance from
GitHub Copilot.

# Identity & Role Drift — Backlog

> **Status:** open, not scheduled
> **Raised:** 2026-09-19
> **Scope of the fix so far:** `dc-cafe` only. Everything below is *outside* that module and is recorded here to be addressed deliberately, not as a side effect of unrelated work.
> **Last verified:** 2026-09-19

## How to use this file

These are classes of defect, not one-offs. Each entry names the pattern, gives a reproducible way to find every instance, and states the fix that removes the class. Add new instances under the relevant class; do not fix them piecemeal in unrelated changes.

---

## Class A — the same value declared in three places that drift

A role (or any ENUM-backed value) is declared in:

1. the database — `dc_users.role` is an `ENUM(...)`,
2. the code — an inline array in a handler, `in_array($role, ['admin', 'supervisor'])`,
3. the interface — a hand-written `<option>` list in a template.

Nothing keeps them in step. When a role is added to the ENUM, the code keeps rejecting it and the form keeps not offering it — **silently**. Nothing errors; the value simply cannot be used.

**This was proved in production shape:** `viewer` was added to `dc_users.role` by migration 039, but two dc-cafe handlers validated against a hand-written list and both user dropdowns were hand-written too. An administrator could not create a viewer at all — the app refused a role the database accepts.

**Reference fix (dc-cafe, the pattern to copy):**

- one helper owns the list — `dcAssignableRoles()` in `modules/dc-cafe/helpers/analytics.php`
- both validations ask it
- both `<option>` lists offer what it returns
- a test asserts the role is assignable and that no handler hardcodes the list

### Open instances

Counts are occurrences of a role-argument list (`['admin', 'supervisor'…`), excluding `dc-cafe` and test directories. Not every occurrence is wrong in its context — each is a place where a role list is written out by hand and can drift from the ENUM.

| Module | Occurrences | Notes |
|---|---|---|
| `guidance` | 105 | largest concentration |
| `daily-ledger` | 59 | |
| `project-audit-ledger` | 46 | `palCurrentUser()` takes the roles per call, and already carries a default (`00-bootstrap.php:32`) that callers mostly re-specify |
| `wms` | 34 | |
| `attendance-wage` | 6 | |
| `bakeshop` | 5 | |
| `ticketing` | 3 | |
| **total** | **258** | |

Reproduce:

```bash
grep -rnE "\['admin',[ ]*'supervisor'|\['admin','supervisor'|array\('admin'" \
  --include=*.php modules/ | grep -v "^modules/dc-cafe" | grep -v "/tests/" \
  | cut -d: -f1 | cut -d/ -f2 | sort | uniq -c | sort -rn
```

The same shape applies to ENUM-backed **statuses**, not just roles — e.g. `['draft', 'published', 'private']` in `modules/ecommerce/helpers/30-catalog.php:2158`.

### Why it cannot be fixed in one module

There is no app-wide role registry. The two things that look like one are not:

- `module.json` → `"roles"` is **per-nav-item visibility** (which roles see a menu entry).
- `auth_owned.admin_roles` is the only *declared* role list, and it exists only for auth-owned modules; it is validated by `validateAuthOwnedSpec()` in `src/helpers/module-manager.php`.

### Recommended fix (removes the class)

1. One kernel-level registry. Each module declares its roles **once** in `module.json`, reusing the `auth_owned.admin_roles` shape.
2. Handlers ask the registry. `requireAnyRole()` already exists — it needs its argument to come from somewhere authoritative.
3. Validation **and** UI option lists derive from the same declaration.
4. A guard test per module: every declared role must exist in that module's ENUM, and no handler may hardcode a role list.

Doing this as one sweep is the point — fixing modules one at a time is how the drift was introduced.

---

## Class B — every module's user table is duplicated in the base database

Each module owns its own user table (`dc_users`, `bakeshop_users`, `cms_users`, `dl_users`, `ehr_users`, `gm_users`, `is_users`, `pal_users`, `wms_users`, `ec_store_users`, `attendance_wage_users`, `harpp_users`). There is also a kernel-level `users` table.

**These tables exist in two databases at once**, and they have diverged:

| Database | `dc_users.role` | `dc_users` rows |
|---|---|---|
| base — `applicationostest` | `admin, supervisor, auditor, cashier` — **no `viewer`** | 19 |
| tenant — `dccafe` | `admin, supervisor, auditor, cashier, viewer` | 6 |

The base copies are left over from the earlier single-database layout (`bakeshop_users` 16 rows, `dc_users` 19 rows, and so on). A request for a tenant resolves to that tenant's database, so the **tenant copy is the one in use** — which is why migration 039 looked unapplied when the base copy was inspected.

**Why this matters:**

- Two divergent answers to "who are these users", with nothing enforcing which is authoritative.
- The base copy's ENUM predates migration 039. If tenant resolution ever falls back to the base database, the module authenticates against a copy where `viewer` is not a valid value — the role would be rejected or truncated depending on SQL mode.
- It misleads investigation: querying the base DB suggests a migration was never applied when it was.

Reproduce:

```bash
php -r 'require "bootstrap.php";
foreach (["base" => app()->db(), "tenant" => app()->dbForTenant(583)] as $l => $db) {
  $n = $db->query("SELECT DATABASE()")->fetchColumn();
  echo "$l ($n): dc_users=" . $db->query("SELECT COUNT(*) FROM dc_users")->fetchColumn() . "\n";
  echo "  role enum: " . $db->query("SHOW COLUMNS FROM dc_users LIKE \"role\"")->fetch(PDO::FETCH_ASSOC)["Type"] . "\n";
}' | grep -v SECURITY
```

**Recommended fix:** establish the tenant database as the single source and remove (or hard-fail on) the stale base copies. Which of the two a request should read is a deployment decision, so this is deliberately left unscheduled.

### Confirmed 2026-09-19 — the kernel database is not any tenant's database

Every tenant maps to its own database in `kernel_tenant_db_connections` (`cmsnewtest`, `baronledger`, `guidance`, `juliesmodule`, `ehrtest`, `zapattendance`, `palsystem`, `wmstest`, `aiss`, `dccafe`, `akira`, `moto`, `bakeshop_seed_…`). **No tenant maps to `applicationostest`.** So the kernel database holds only the control plane and the module tables left over from the single-database layout — those tables serve nobody.

Scale: **397 tables and 6,698 rows** in `applicationostest` are declared module-owned tables.

### Two blockers before anything is dropped

1. **`owns_tables` is not a complete inventory.** Classification by manifest found 397 module tables to remove, but the set left behind still contains plainly module-owned tables — `ehr_users`, `ehr_admissions`, `ehr_password_resets`, `bakeshop_ingredient_usage`, `cms_theme_registry`, `cli_tenant_migrate_*`. Only 36 manifests declare `owns_tables`. Removing on this list would leave duplicates behind and keep module tables: it fails the rule in both directions.
2. **The backup path needs the app's connection.** `mysqldump -u root` is refused (`Access denied … using password: NO`) — the credentials live in the app's config. A dump therefore has to be produced through the app's own PDO connection (`SHOW CREATE TABLE` + `INSERT`), not through the CLI client. No removal should happen before that dump exists.

### Direction (owner directive 2026-09-19)

> No duplicate of any module's tables in the kernel database — standalone modules, and extensions/submodules of standalone modules alike. The kernel module handles its own users; every other module owns its own.

Safest sequence, once the inventory is reliable:

1. Dump the kernel database through the app's connection; verify the dump reloads.
2. Complete `owns_tables` in every manifest, **or** classify by exclusion against the kernel's own schema (keep `kernel_*`, `_migrations`, `tenant_*`, control-plane and kernel-owned tables; remove everything else).
3. Remove with `RENAME TABLE tmp_orphan_<name>` first — reversible in one statement — then drop once the app is confirmed healthy.
4. Re-check that `applicationostest` holds no module table, and that every module's tables exist in its tenant's database.

---

## Related note — shared runtime cache and file ownership

Not a module defect, but the same shape of problem and already fixed for the test harness:

A suite compiles templates as whichever user runs it. The web server is a different user and cannot overwrite files it does not own, so a test run left the app unable to recompile and the login page answering **500**. `tests/harness/TestHarness.php` now sets `umask(0000)` and repairs `storage/cache` on the way in *and* on the way out.

The underlying condition remains: CLI runs and the web server share `storage/cache` as different users. Entries written by one are not necessarily writable by the other. A shared group with a setgid directory, or running the suites as the web user, would remove it for good.

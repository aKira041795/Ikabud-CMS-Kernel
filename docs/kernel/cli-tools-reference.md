# CLI Tools Reference

> **CLI:** `php ikabud` (Kernel CLI v6.1.0)
> **Updated:** June 26, 2026

The `php ikabud` CLI provides developer tools for architecture enforcement,
entity inspection, scaffolding, diagnostics, and module management.

---

## Architecture Enforcement

### `architecture:check`

Scans all modules for cross-boundary violations.

```
php ikabud architecture:check
```

**Detects:**
1. **Cross-module table access** — module queries a table owned by another module
2. **Undeclared capability calls** — module references a capability ID not in its `capabilities.depends`
3. **Template entity source misuse** — template uses an entity source not declared in module's view contracts

**Output:**
```
  ╔═ Architecture Check ═╗
  ✗ healthcare reads wms.stock_movements (owned by wms)
  ✗ workflow calls capability 'entity.get.workflow_notification@1' (not declared in depends)
  ✗ ticketing calls capability 'entity.get.ticket@1' (not declared in depends)
  ...
  9 violation(s) found
```

**Exit codes:** `0` = clean, `1` = violations found.

---

### `module:check-boundaries`

Validates a single module's boundary compliance.

```
php ikabud module:check-boundaries <module-id>
php ikabud module:check-boundaries --help
```

---

## Entity Inspection

### `entity:describe <entity>`

Inspects a database entity across all modules — schema, relationships, view contracts, module ownership.

```
php ikabud entity:describe products
```

**Output:**
```
  Entity: products (ecommerce)
  Columns: id, name, slug, price, description, category_id, created_at, updated_at
  Relationships: belongs_to categories (category_id)
  View contracts: ecommerce.product.list, ecommerce.product.detail
  Module: ecommerce (modules/ecommerce)
```

---

### `disyl:inspect <path>`

Analyses a DiSyL template — view contracts used, component usage, template dependencies, control structures.

```
php ikabud disyl:inspect templates/modules/cms/admin/dashboard.disyl
```

**Output:**
```
  File: templates/modules/cms/admin/dashboard.disyl
  View contracts: cms.dashboard
  Components: ikb_stat_card (4), ikb_entity_list (1), ikb_panel (2)
  Includes: modules/cms/admin/header.disyl
  Control structures: for (3), if (2)
```

---

### `capability:trace <id>`

Traces a capability's provider, consumers, auth status, and source references.

```
php ikabud capability:trace guidance.case.create@1
```

**Output:**
```
  CapTrace: guidance.case.create@1

  Provider: guidance (first)
  Consumers: cms, attendance-wage
```

For service-module capabilities:
```
  CapTrace: ai.summarize@1

  Provider: ai-orchestrator (first)
  EP: http://localhost:9001/capability/call
  Auth: OK
  Consumers: cms
  modules/ai-orchestrator/module.json
```

---

### `trigger:trace <trigger>`

Traces an event trigger's emission path and handler resolution.

```
php ikabud trigger:trace order.placed
```

---

## Scaffolding Generators

### `make:entity <name>`

Scaffolds a full entity: migration SQL, capability handlers, view contracts, routes, and handlers.

```
php ikabud make:entity widget
```

**Creates:**
- `migrations/XXX_create_widgets.sql`
- Module capability handler registration
- `entity.list`/`entity.get` view contracts
- Route entries and handler stubs

---

### `make:capability <id>`

Scaffolds a capability handler registration and `module.json` `exposes`/`depends` entries.

```
php ikabud make:capability widget.export@1
```

---

### `make:module <name>`

Scaffolds a new module directory with `module.json`, routes, handlers, and helpers.

```
php ikabud make:module my-feature
```

---

### `make:service-module <name>`

Scaffolds a polyglot service module (non-PHP runtime).

```
php ikabud make:service-module my-service
```

---

### `make:example <name>`

Creates an example module for reference.

```
php ikabud make:example hello-world
```

---

## Diagnostics

### `doctor`

Environment health checker. Validates PHP version, required extensions, database connectivity, and module manifest integrity.

```
php ikabud doctor
```

**Checks:**
- PHP version ≥ 8.1.0
- Required extensions: PDO, PDO_MySQL, mbstring, json, curl, gd, fileinfo, tokenizer, xml, zip
- Database connectivity (base + all configured tenant DBs)
- Module manifest integrity (valid JSON, required keys present)
- Template directory existence
- Log directory writability
- Cache directory writability

**Output:**
```
  ╔═ Ikabud Doctor ═╗

  PHP: 8.2.26 ✓
  Extensions: pdo ✓ pdo_mysql ✓ mbstring ✓ json ✓ curl ✓ gd ✓ fileinfo ✓ tokenizer ✓ xml ✓ zip ✓
  Base DB: connected ✓
  Tenant DBs: 2/2 connected ✓
  Manifests: 44/44 valid ✓
  Templates: 398 files ✓
  Log dir: writable ✓
  Cache dir: writable ✓
```

---

### `module:certify <module|--all>`

Runs the module certification checklist (10-point compliance check).

```
php ikabud module:certify cms
php ikabud module:certify --all
```

---

## Module Management

| Command | Description |
|---|---|
| `module:list` | List all modules, their status, version, and type |
| `module:enable <id>` | Enable a module |
| `module:disable <id>` | Disable a module |
| `module:validate <id>` | Validate module manifest and capabilities |
| `module:pack <id>` | Package a module for distribution |
| `module:install <path>` | Install a module from package |
| `module:remove <id>` | Remove a module |
| `module:publish <id>` | Publish module to marketplace |

---

## Help

```
php ikabud                          # List all commands
php ikabud <command> --help         # Command-specific help
php ikabud <command> -v             # Verbose output
```

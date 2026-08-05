# CMS Akira — Product Suite & Extension Architecture

> **Released:** August 5, 2026
> **Theme:** Product-suite decomposition, additive suite fields (Suite Extension Contract v1), dynamic admin composition, DiSyL JSON function calls.
> **Previous:** [6.1 Intercoherence](release-notes-2026-06-26-kernel-6.1-intercoherence.md)

---

## Executive Summary

This release turns the flat module registry into an explicit **product-suite and
extension model** while keeping every legacy module valid. The CMS is decomposed
into the **CMS Akira** product suite (14 submodules) that renders inside the
CMS admin shell, with an **admin sidebar driven dynamically** from manifest
`admin_contributions`. The kernel gains suite certification checks (C12/C13)
through `validateModuleSuiteContractV1()`, and the DiSyL engine gains
`{json_encode()}` / `{json_decode()}` function-call support.

**Key stats:** 14 Akira submodules · 8 new additive manifest fields · dynamic
admin sidebar registry · product-suite extension ADR accepted 2026-08-04 · 2 new
DiSyL engine function calls.

```
ADR accepted: 2026-08-04 (docs/architecture/product-suite-extension-adr.md)
Additive suite fields: optional, additive (MODULE_MANIFEST_SCHEMA_VERSION stays '1')
Suite certification: C12/C13 via validateModuleSuiteContractV1()
```

---

## 1. CMS Akira Suite Decomposition (14 submodules)

The CMS is decomposed into a product family under `modules/cms-akira/` while
preserving module boundaries and capability-first composition from
`cms-akira-core`.

```
modules/cms-akira/
├── cms-akira-core/            — product core, content orchestration, adapter boundary
├── cms-akira-seo/             — SEO metadata provider
├── cms-akira-ai/              — AI summary/provider layer
├── cms-akira-editor/          — content preparation / editor hooks
├── cms-akira-theme/           — theme resolution provider
├── cms-akira-navigation/      — navigation resolution provider
├── cms-akira-workflow/        — workflow evaluation provider
├── cms-akira-search-adapter/  — search document provider
├── cms-akira-media/           — media resolution and fallback behavior
├── cms-akira-builder/         — visual/builder integration surface
└── cms-akira-profile-minimal/standard/visual/headless — install/enable bundles
```

**Standalone rule:** any Akira module that owns users/auth is a standalone
tenant-entry module (`auth_owned` in `module.json`) and must be selected per
tenant via **Admin > Tenants** — the tenant dropdown is the provisioning gate.

See `modules/cms-akira/README.md` for install/enable order and validation
commands.

---

## 2. Additive Suite Fields (Suite Extension Contract v1)

New **optional, additive** manifest fields — legacy (schema-v1) manifests remain
valid and are treated as `kind: standalone-application`:

| Field | Purpose |
|---|---|
| `suite` | Normalized suite id (e.g. `cms-akira`) |
| `kind` | `product-core`, `extension`, `adapter`, `profile`, `service`, `integration`, `standalone-application` |
| `extends` | Host core id this module extends (extension/adapter) |
| `extension_points` | Point ids the host exposes — declared only on `kind: product-core` |
| `contributes` | `[{extension_point, provider}]` consuming a host's declared points |
| `admin_contributions` | `[{host, location, group, label, icon, route, permission, order}]` — dynamic admin surfaces |
| `compatibility` | `{kernel, suite}` semver ranges |
| `uninstall` | `{disable_safe, retain_data_by_default, supports_data_export, requires_confirmation_to_drop_data}` |

When the new fields are present they are validated **strictly**; fleet-level
checks run in the manifest guard and certification, never at tenant boot for
legacy modules.

---

## 3. Product Suite & Extension Architecture (ADR 2026-08-04, C12/C13)

Accepted **2026-08-04** in
[`docs/architecture/product-suite-extension-adr.md`](../architecture/product-suite-extension-adr.md).

Core concepts:

- **Product Suite** — a named family of modules (`cms-akira`, `pal`).
- **Product Core** (`kind: product-core`) — authoritative host module; declares
  the suite's `extension_points`.
- **Extension / Adapter** — extends a host core (`extends: <core-id>`), consumes
  capabilities and `contributes` surfaces.
- **Profile** — installation bundle (`installs: [...]`) declaring a coherent
  product configuration.
- **Contribution** — manifest-declared admin/UI surface registered against a
  host's declared `extension_points` and rendered dynamically.

The loader stays flat; relationships come from manifests. Hierarchy explains
relationship, capabilities execute behavior, contributions integrate UI, and
certification proves compliance.

**Suite certification (C12/C13):** `validateModuleSuiteContractV1()` in
`src/helpers/manifest-validation.php` (invoked from
`src/helpers/module-manager.php`) validates extends targets exist, contribution
hosts are declared extension points, suite prefix consistency, and
`compatibility` ranges. Run via `php ikabud module:certify <id>`.

---

## 4. Dynamic Admin Sidebar (`admin_contributions`)

The CMS admin sidebar is now **driven dynamically** from `admin_contributions`
entries rather than only from hooks. Each contribution carries:

```
{host, location, group, label, icon, route, permission, order}
```

Example (`cms-akira-seo`):

```json
"admin_contributions": [
    {
        "host": "cms",
        "location": "sidebar",
        "group": "optimization",
        "label": "SEO",
        "icon": "search",
        "route": "/admin/cms-akira-seo",
        "permission": "cms.seo.manage",
        "order": 60
    }
]
```

The kernel validates at install/certification that the contribution `host` has
declared the matching extension point (e.g. `cms.sidebar`) — a module cannot
inject itself into a point the host did not declare.

**Coexistence:** the legacy `cms.admin.nav_items` hook and the new
`admin_contributions` registry model coexist for incremental migration.

---

## 5. CMS Admin-Shell Conversion

Akira suite modules render inside the **CMS admin shell** rather than each
owning a bespoke admin frame. This makes the admin experience consistent across
core and extension modules while contributions control sidebar placement.

---

## 6. DiSyL JSON Function Calls (`json_encode` / `json_decode`)

The DiSyL engine now supports JSON serialization and parsing as **function
calls** (previously only the `|json` filter existed):

| Call | Behavior |
|---|---|
| `{json_encode(data)}` | JSON-encode; mirrors `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` — stable with the `|json` filter |
| `{json_decode(data)}` | JSON-decode into an associative array |
| `{json_decode(data).key}` | Dot-path access into the decoded array |

- Registered in `kernel/DiSyL/v4/FunctionRegistry.php`.
- Dot-path access resolves via `kernel/DiSyL/ExpressionEvaluator.php`.
- Use `{json_encode(data)|raw}` in JS/attribute contexts (Alpine `x-data`,
  `<script>` blocks, `data-*`) so the JSON string is not HTML-escaped.

```disyl
{set payload_json = '{"user_id":42,"role":"admin"}'}
{json_decode(payload_json).user_id}   → 42

{set payload = data}                  ← data is an array/object in the render context
{json_encode(payload)|raw}            → {"user_id":42,"role":"admin"}
```

> `json_encode` expects a **data structure** (array/object), not a JSON string.
> Passing the JSON string `payload_json` to `json_encode` would escape it into
> `"{\"user_id\":42,...}"`. Use separate variables for decode vs. encode.

---

## 7. Validation

| Gate | Status |
|---|---|
| Suite certification C12/C13 (`validateModuleSuiteContractV1`) | ✅ |
| `php ikabud module:certify <suite-module>` | ✅ |
| `php ikabud make:module <name> --suite=<suite-id>` scaffold | ✅ |
| Akira deploy-readiness / provider-boundary / compose / media-resilience tests | ✅ |
| `architecture:check` on suite layout | ✅ |

---

## What This Answers

> **"Can a product family (like CMS) be decomposed into independently governed modules without losing a coherent product identity?"**
>
> Yes — the `cms-akira` suite with `cms-akira-core` as the product core.

> **"How does a module contribute an admin sidebar item without registering a PHP hook?"**
>
> Declare an `admin_contributions` entry in `module.json` — the sidebar renders dynamically from the registry.

> **"Is a suite relationship enforced, or is it just folder naming?"**
>
> Enforced — suite certification (C12/C13) validates extends targets, contribution hosts, prefix consistency, and compatibility.

> **"Can DiSyL templates serialize/parse JSON inline?"**
>
> Yes — `{json_encode(...)}` / `{json_decode(...)}` function calls with dot-path access.

# Module Manifest Schema v1 — Additive Suite Extensions

The runtime schema version remains `1` (`MODULE_MANIFEST_SCHEMA_VERSION = '1'`).
Schema v1 is the canonical contract for `modules/**/module.json`.

The product-suite fields introduced in August 2026 (`suite`, `kind`, `extends`,
`extension_points`, `contributes`, `admin_contributions`, `compatibility`,
`uninstall`) are **optional additive extensions** to schema v1 — the **Suite
Extension Contract v1** — not a new, incompatible manifest version. A legacy
manifest that omits them remains a valid schema-v1 manifest. Some earlier
documents call these the "schema-v2 layer"; the canonical name is **additive
suite fields** and the runtime schema version stays `1`. See
[Additive Suite Extension Contract](#additive-suite-extension-contract-v1)
below.

## Authority and precedence

`php scripts/guard-module-manifests.php --strict` is the authoritative CLI
validation entry point. Runtime manifest loading and `php ikabud
architecture:check` consume the same validator in
`src/helpers/manifest-validation.php`; they must not define independent shape
rules.

Precedence is:

1. schema-v1 fatal diagnostics;
2. certification blockers;
3. advisory compatibility guidance;
4. examples and historical release notes.

Historical examples do not override this schema.

## Severity model

| Severity | Effect |
|---|---|
| `fatal` | The manifest is invalid. Discovery, synchronization, installation, or boot of the declaring module must stop. |
| `cert_blocker` | Development boot may continue, but production certification and release must fail. |
| `advisory` | The declaration is valid; the diagnostic explains a migration or maintainability concern. |

Every diagnostic contains a stable code, schema rule, JSON field, message, and
correction.

## Required identity

- `id`: non-empty kebab-case identifier, maximum 64 characters.
- `name`: non-empty display name.
- `version`: semantic version such as `1.0.0` or `1.0.0-beta.1`.

Established modules whose directory name differs from `id` receive an
advisory. New modules should match them. Existing directories are not renamed
by validation because their paths may be compatibility contracts.

## Routes

`routes` accepts exactly one of:

- `true`: load the conventional `routes.php`, which must exist;
- `false` or `[]`: the module intentionally has no routes;
- a non-empty relative file path: load that route file, which must exist inside
  the module directory;
- absent: legacy-compatible route declaration; new scaffolded modules declare
  `true` explicitly.

Non-empty inline arrays are invalid. Route maps belong in the PHP route file.
Absolute paths, backslashes, drive-prefixed paths, and `..` traversal are
invalid. Runtime route loading consumes this declaration directly; `false` and
`[]` do not fall back to a conventional `routes.php` file.
The previous guard incorrectly converted `true` to `1` and `[]` to `Array`;
schema v1 validates their actual JSON types.

## Capabilities

`capabilities.exposes` and `capabilities.depends` are arrays.

- Each exposed capability is an object containing a versioned `id`.
- Optional `modes` may contain `first`, `pipeline`, or `fanout`.
- Each dependency is a versioned capability-id string.

String-only expose entries are invalid. Duplicate non-pipeline providers are
advisory until provider authority is explicitly resolved.

## Events

`events` is a list of declaration objects. Every entry requires a non-empty
`key`:

```json
"events": [
  {"key": "orders.order.created"}
]
```

String arrays and `{ "emits": [...] }` wrappers are invalid. Malformed event
declarations are fatal for the declaring module because the event registry
cannot synchronize them reliably.

## Table declarations

`owns_tables`, `co_owns_tables`, `reads_tables`, and legacy
`requires_tables`, when present, are arrays of SQL identifiers. Empty arrays
are valid for stateless modules. One module is the canonical owner of each
table; intentional secondary ownership must use `co_owns_tables`.

Schema-v1 migration changed only declarations already represented by runtime
code: it fixed the inventory-scanner capability shape, normalized existing
event names into `{key}` objects, accepted boolean/empty route declarations,
and retained established folder/id mismatches as advisories. It did not widen
table access or module permissions.

---

## Additive Suite Extension Contract v1

Since 2026-08-04, `module.json` may declare an **additive suite extension
contract** on top of schema v1. These fields are optional; a manifest that omits
them is treated as `kind: standalone-application` (or legacy) and stays fully
valid. When present, they are validated strictly by
`validateModuleSuiteContractV1()` / `validateModuleSuiteFleetV1()` in
`src/helpers/manifest-validation.php`, invoked from
`src/helpers/module-manager.php` and `scripts/guard-module-manifests.php`.

> **Naming:** the runtime schema version remains `1`. These are **additive suite
> fields for Manifest Schema v1** — the **Suite Extension Contract v1** — not a
> schema version 2. Earlier documents may informally say "schema-v2"; treat the
> canonical name above as authoritative.

### Fields

| Field | Type | Allowed values / shape | Required when |
|---|---|---|---|
| `suite` | string | Normalized kebab-case suite id (e.g. `cms-akira`, `pal`) | Any suite member |
| `kind` | string | `product-core`, `extension`, `adapter`, `profile`, `service`, `integration`, `standalone-application` | Any suite member |
| `extends` | string | Host module id this module extends | `kind: extension` / `adapter` |
| `extension_points` | string[] | Point ids the host exposes (e.g. `cms.sidebar`) | `kind: product-core` |
| `contributes` | array | `[{extension_point, provider}]` | Extensions/adapters consuming host points |
| `admin_contributions` | array | `[{host, location, group, label, icon, route, permission, order}]` | Modules adding admin surfaces |
| `compatibility` | object | `{kernel, suite}` semver ranges | Optional |
| `uninstall` | object | `{disable_safe, retain_data_by_default, supports_data_export, requires_confirmation_to_drop_data}` | Optional |

### Required combinations

- `kind: product-core` **declares** the suite's `extension_points`. Only a
  product core declares extension points.
- `kind: extension` / `adapter` **must** declare `extends: <core-id>` and
  consume host points via `contributes`.
- `kind: profile` bundles a coherent install set with `installs: [...]`.
- A contribution `host` must be a module that declared the matching extension
  point (e.g. `cms.sidebar`) — a module cannot inject into a point the host did
  not declare.
- A suite member's `id` must start with the suite prefix (e.g. `cms-akira-...`).

### Validation severity

| Field / rule | Severity |
|---|---|
| Unknown `kind` value | `fatal` |
| `kind: extension`/`adapter` without a resolvable `extends` target | `cert_blocker` (fatal at install) |
| Contribution `host` not a declared extension point of the host | `cert_blocker` (fatal at install) |
| Suite prefix mismatch between `id` and `suite` | `cert_blocker` |
| `compatibility` range violated | `cert_blocker` at install/certification |
| Missing optional fields (`compatibility`, `uninstall`) | advisory |
| Profile self-install / undeclared extension point | `fatal` at install |

Certification adds **C12** (suite contract — strict when declared) and **C13**
(admin contribution shape — advisory) checks to
`validateModuleCertification()`; render them with
`php ikabud module:certify <module-id>`.

### Example — extension with an admin contribution

```json
{
    "id": "cms-akira-seo",
    "name": "CMS Akira SEO",
    "suite": "cms-akira",
    "kind": "extension",
    "extends": "cms-akira-core",
    "contributes": [
        { "extension_point": "cms.sidebar", "provider": "cms-akira-seo.nav@1" }
    ],
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
    ],
    "compatibility": { "kernel": "^6.1", "suite": "^1" },
    "uninstall": {
        "disable_safe": true,
        "retain_data_by_default": true,
        "supports_data_export": true,
        "requires_confirmation_to_drop_data": true
    }
}
```

**References:** [Product Suite & Extension ADR](../architecture/product-suite-extension-adr.md), [Product Suite & Extension Architecture Plan](../architecture/product-suite-extension-architecture-plan.md), live example in [`modules/cms-akira/README.md`](../../modules/cms-akira/README.md).

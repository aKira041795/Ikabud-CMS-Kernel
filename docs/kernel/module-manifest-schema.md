# Module Manifest Schema v1

Schema v1 is the canonical contract for `modules/**/module.json`.

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

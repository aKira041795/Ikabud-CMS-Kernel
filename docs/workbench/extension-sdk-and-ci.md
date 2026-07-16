# ARK Workbench extension SDK and CI adoption

## First certified run

```text
php ikabud workbench:init my-module
php ikabud workbench:doctor my-module
php ikabud workbench:run my-module --gate=critical
```

Add `workbench-contract.json` and a convention `WorkbenchComprehensionProvider.php` beside `module.json`. The contract adapter used by Guidance, WMS, and EHR is the reference. No Kernel edit or registry branch is required.

## CI in fewer than ten project lines

```yaml
jobs:
  ark:
    uses: ./.github/workflows/ark-workbench.yml
    with:
      modules: my-module
```

The reusable workflow emits GitHub annotations, publishes evidence artifacts, and fails contract or competitive gates. Other CI systems run `php scripts/workbench-ci.php` with `ARK_MODULES`. `docker/workbench/Dockerfile` is the reproducible runner definition.

## Extension contract

Extensions implement the versioned evidence collector, gate, or exporter interfaces. IDs are unique and stable. Evidence must include provenance and explicit outcome. Extensions may add observations and decisions but cannot replace authoritative evidence or graph digests; invalid outputs are rejected. Existing module comprehension and scenario provider interfaces remain the semantic extension points.

## Version and migration policy

- Schema/interface major changes require a new namespace or schema ID.
- Additive optional fields are minor-compatible.
- Deprecations remain supported for one release line and include a deterministic migration.
- Release notes identify schema, CLI, provider, evidence, and gate changes.
- Troubleshooting starts with `workbench:doctor`, then `workbench:explain <run-id>`.

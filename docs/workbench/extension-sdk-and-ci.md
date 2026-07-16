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

## Runner scopes

`docker/workbench/Dockerfile` is intentionally a contract-and-benchmark runner. It contains PHP and the database extensions needed by `scripts/workbench-ci.php`; it does not claim to execute browser or hybrid E2E suites.

A full contract run with declared browser files requires the application test environment:

- Node.js and npm dependencies installed.
- Playwright browsers and their operating-system dependencies installed.
- The application web server and required tenant databases running.
- Module test credentials supplied through environment variables.
- `WB_RUN_ID`, module identity, and gate propagated by `workbench:run`.

Use the lightweight container for pull-request contract/benchmark governance. Use the application CI environment or `tests/browser/run-workbench.js` for browser/hybrid evidence. These scopes are deliberately separate so the lightweight runner never implies E2E coverage it did not execute.

## Extension contract

Extensions implement the versioned evidence collector, gate, or exporter interfaces. IDs are unique and stable. Evidence must include provenance and explicit outcome. Extensions may add observations and decisions but cannot replace authoritative evidence or graph digests; invalid outputs are rejected. Existing module comprehension and scenario provider interfaces remain the semantic extension points.

## Version and migration policy

- Schema/interface major changes require a new namespace or schema ID.
- Additive optional fields are minor-compatible.
- Deprecations remain supported for one release line and include a deterministic migration.
- Release notes identify schema, CLI, provider, evidence, and gate changes.
- Troubleshooting starts with `workbench:doctor`, then `workbench:explain <run-id>`.

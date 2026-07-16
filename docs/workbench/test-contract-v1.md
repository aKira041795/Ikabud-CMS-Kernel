# ARK Workbench Test Contract v1

Status: normative
Schema: `kernel/Workbench/Schemas/workbench-test-contract.v1.schema.json`
File convention: `<module>/workbench-contract.json`

## Purpose

The contract is the executable agreement between a module and ARK Workbench. It describes ownership, actors, tenancy, pages, workflows, actions, effects, invariants, scenarios, environments, evidence identity, and release gates without module-specific branches in Workbench.

Workbench validates this contract before browser execution. A missing, incompatible, or dishonest contract blocks the run and records a durable explanation.

## Required identity

Every observation must retain `module`, `action`, `step`, `tenant`, `role`, `environment`, and `outcome`. Supported outcomes include `passed`, `failed`, `blocked`, `skipped`, and `censored`; absence of evidence is never silently converted to success.

## Commands

```text
php ikabud workbench:init <module> [--force]
php ikabud workbench:validate <module> [--json]
php ikabud workbench:doctor <module> [--json]
php ikabud workbench:run <module> [--gate=critical|major|off] [--json]
php ikabud workbench:explain <run-id> [--json]
```

`init` deterministically migrates legacy `test-contract.json` when present, otherwise it derives owned routes, capabilities, events, and tables from the module manifest and routes file. `validate` checks schema and implementation claims. `doctor` is the pre-browser gate. `run` records a durable run manifest and refuses to start a browser when preflight fails. `explain` turns that run evidence into a machine-readable cause list and next action.

## Compatibility and deprecation

- v1 readers accept only `contract_version` major 1.
- Additive optional fields are compatible within v1.
- Removing or changing required field meaning requires a new schema major.
- `test-contract.json` is legacy input, not the runtime source of truth.
- Migration is deterministic: the same manifest, routes, and legacy contract produce byte-identical JSON.
- Cross-module navigation must be explicit in `ownership.navigation_dependencies`; undeclared coupling is invalid.

## Release rule

Contract validation runs before browser tests and package/release gates. A failed critical or major invariant prevents certification. Browser-only discovery remains useful evidence, but it is not the first place structural contract defects should be found.

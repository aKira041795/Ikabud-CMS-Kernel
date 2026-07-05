# Architecture Boundary Violations — Backlog

> **Maintained by:** Platform lead  
> **Source tool:** `php ikabud architecture:check`  
> **Last verified:** 2026-07-05

## How to use this file

Run `php ikabud architecture:check` after each significant code change. Any new violation must be added here with an owner and sprint target before merging. Existing violations must not regress.

---

## Current State: ALL CLEAN ✅

A manual investigation on 2026-07-05 confirmed that the **9 violations reported in the 6.1 release notes** (June 26, 2026) are **no longer present** in the current codebase:

| Violation reported in 6.1 | Current status | Notes |
|---|---|---|
| `healthcare` reads `wms.stock_movements` (owned by `wms`) | ✅ Clean | No direct WMS table access found in any healthcare sub-module |
| `workflow` — 5 undeclared capability calls | ✅ Clean | `modules/workflow/` is a legacy compatibility shell — no active `cap()->call()` invocations; `capabilities.depends` matches actual usage |
| `ticketing` — 3 undeclared capability calls | ✅ Clean | `sms.send@1` is the only capability called and it is declared in `module.json` |

The violations were pre-6.0 patterns that were addressed between the release notes writing and 6.1 ship date.

---

## Known Open Violations

*None at 2026-07-05.*

---

## Violation Template (use when adding new items)

```
| Module | File | Line | Violation type | Capability/table | Sprint target | Owner |
```

### Violation types
- **cross-table**: module queries a table owned by another module directly
- **undeclared-cap**: module calls a capability not in its `capabilities.depends`
- **template-source-misuse**: template uses an entity source not declared in view contracts

---

## Remediation Pattern

### For `undeclared-cap` violations
1. Add the capability ID to `modules/<module>/module.json` under `capabilities.depends`
2. Confirm the providing module declares it in `capabilities.exposes`
3. Write a test that calls `CapabilityBus::call()` for the capability and asserts a non-error result
4. Re-run `php ikabud architecture:check` and confirm 0 violations

### For `cross-table` violations
1. Add a `entity.list/get.<source>@1` capability to the owning module
2. Replace the direct table query in the offending module with `app()->cap()->call(...)`
3. Add the new capability to the offending module's `capabilities.depends`
4. Write an integration test asserting the capability resolves data correctly
5. Re-run `php ikabud architecture:check` and confirm 0 violations

---

## CI Gate

`php ikabud architecture:check` is not yet gated in CI. Once this backlog reaches 0 violations (confirmed above), the gate should be enabled in `.github/workflows/ci.yml`:

```yaml
- name: Architecture boundary check
  run: php ikabud architecture:check
```

See TD-CI4 in `docs/reviews/system-review-2026-07-05.md`.

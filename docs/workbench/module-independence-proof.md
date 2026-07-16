# ARK Workbench module-independence proof

Status: verified for PAL, Guidance, WMS, and EHR.

All four modules use `workbench-contract.json`. Guidance, WMS, and EHR expose the same convention-based `WorkbenchComprehensionProvider.php`; the provider is a thin module-owned adapter over the generic `ContractComprehensionProvider`. ARK core contains no Guidance, WMS, or EHR branch.

| Module | Proof focus | Contract evidence |
|---|---|---|
| Project Audit Ledger | navigation and approval workflow | legacy migration plus canonical contract |
| Guidance | page and case-management breadth | owned route/page/action catalog |
| WMS | state-changing and effect-heavy APIs | generated action/effect chains |
| EHR | authority and clinical privacy | critical capability and cross-tenant privacy invariants |

The registry discovers both direct and grouped module directories, so nested `modules/healthcare/ehr` is not a special case. Each contract supplies routes, tables/entities, capabilities, workflows, actions, expected effects, invariants, and scenarios to the updated Comprehension Engine.

Conformance is checked by `tests/workbench_competitive_phase3_test.php`. A new module proves portability by adding only its manifest, routes, contract, and convention provider; Kernel code must remain unchanged.

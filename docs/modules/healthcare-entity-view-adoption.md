# Healthcare Entity-View Adoption

## Scope

Tracks EHR surface migration toward `{ikb_entity_list}` and `{ikb_entity_detail}` contracts.

## Completed (current state)

Core modules now expose entity capability adapters and DiSyL view contracts:

1. Patient Registry
   - capabilities: `entity.list.ehr_patient@1`, `entity.get.ehr_patient@1`
   - view contracts: `ehr_patient` (table/compact/detailed)
2. Encounters
   - capabilities: `entity.list.ehr_encounter@1`, `entity.get.ehr_encounter@1`
   - view contracts: `ehr_encounter` (table/compact/detailed)
3. Scheduling
   - capabilities: `entity.list.ehr_appointment@1`, `entity.get.ehr_appointment@1`
   - view contracts: `ehr_appointment` (table/compact/detailed)

All three modules load view configs through `TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views')` in handlers bootstrap.

## Immediate follow-up targets

Next healthcare modules to map into entity-view contracts:

1. Orders
2. Results
3. Prescriptions
4. Documents
5. Clinical Notes

## Contract guidelines

- Keep list/get capabilities module-owned.
- Return normalized row shapes for list views.
- Prefer explicit action URLs in view contracts (no implicit route guessing).
- Keep cross-entity aggregation outside view contracts (composite template layer).

## Validation checklist per module

1. Capability declaration in `module.json` (`exposes` + policy allow_callers).
2. Capability handler implementation in `helpers.php`.
3. DiSyL view config under `helpers/views/*.disyl`.
4. Handler bootstrap loading of view configs.
5. Integration test asserting list/detail rendering contract behavior.
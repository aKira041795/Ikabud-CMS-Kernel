# EHR — Standalone Migrations

Contains migration files that apply across the EHR suite. The authoritative EHR modules live under [`modules/healthcare/`](../healthcare/).

## Migration

- `001_ehr_username_case_sensitive.sql` — Username case sensitivity fix

## EHR Suite

See [`modules/healthcare/`](../healthcare/) for the full EHR module collection:
- [`ehr-core`](../healthcare/ehr-core/) — Shared contracts and status catalogs
- [`ehr`](../healthcare/ehr/) — Tenant-facing EHR suite shell, auth, branding
- [`patient-registry`](../healthcare/patient-registry/) — Patient identity registry
- [`encounters`](../healthcare/encounters/) — Encounter lifecycle and vitals
- [`clinical-notes`](../healthcare/clinical-notes/) — Encounter-linked clinical notes
- [`scheduling`](../healthcare/scheduling/) — Appointment and queue operations
- [`orders`](../healthcare/orders/) — Clinical orders and order items
- [`results`](../healthcare/results/) — Clinical results
- [`prescriptions`](../healthcare/prescriptions/) — Prescription issuance
- [`documents`](../healthcare/documents/) — Patient document metadata
- [`hospital-adt`](../healthcare/hospital-adt/) — Admission, discharge, transfer
- [`patient-portal`](../healthcare/patient-portal/) — Patient-facing portal
- [`privacy-consent`](../healthcare/privacy-consent/) — Consent and break-glass
- [`interoperability-bridge`](../healthcare/interoperability-bridge/) — FHIR/HL7
- [`billing-bridge`](../healthcare/billing-bridge/) — Derived billing charges
- [`audit`](../healthcare/audit/) — EHR audit search
- [`reporting`](../healthcare/reporting/) — Operational EHR reporting
- [`analytics-cds`](../healthcare/analytics-cds/) — CDS rules engine

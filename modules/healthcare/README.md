# EHR Healthcare Suite

The EHR (Electronic Health Record) suite is a collection of 19 co-operating modules under `modules/healthcare/`. Together they provide patient identity, encounter management, clinical notes, orders, results, prescriptions, scheduling, inpatient ADT, patient portal, privacy consent, interoperability (FHIR/HL7), billing, audit, reporting, and clinical decision support.

## Architecture

```
ehr-core (shared contracts, status catalogs)
  └── ehr (suite shell, auth, branding, admin nav)
        ├── patient-registry   ├── encounters
        ├── scheduling         ├── clinical-notes
        ├── orders             ├── results
        ├── prescriptions      ├── documents
        ├── hospital-adt       ├── patient-portal
        ├── privacy-consent    ├── billing-bridge
        ├── audit              ├── reporting
        ├── analytics-cds      └── interoperability-bridge
```

## Key design principles

- **Patient- and Visit-centric**: designed around persistent patient and encounter context (see `docs/ehr/system-design-and-architecture-plan.md`)
- **Tenant-scoped**: all tables carry `tenant_id`
- **Event-driven**: modules communicate via kernel event bus for cross-module workflows
- **Capability-based**: clinical operations are kernel capabilities with policy-enforced access

## Documentation

- [System design and architecture plan](../../docs/ehr/system-design-and-architecture-plan.md)
- Top-level EHR migrations: [`../ehr/`](../ehr/)

## Sub-modules

| # | Module | Description |
|---|--------|-------------|
| 1 | [`ehr-core`](ehr-core/) | Shared capability contracts, status catalogs, cross-module helpers (no tables) |
| 2 | [`ehr`](ehr/) | Tenant-facing EHR auth, branding, admin shell. Depends on ehr-core, audit, reporting, billing-bridge |
| 3 | [`patient-registry`](patient-registry/) | Canonical patient identity registry. Owns `ehr_patients`, `ehr_patient_identifiers` |
| 4 | [`encounters`](encounters/) | Encounter lifecycle and vitals. Owns `ehr_encounters`, `ehr_vitals` |
| 5 | [`clinical-notes`](clinical-notes/) | Encounter-linked notes with immutable version history, signing, amendments |
| 6 | [`scheduling`](scheduling/) | Appointment and queue operations |
| 7 | [`orders`](orders/) | Encounter-linked clinical orders and order items |
| 8 | [`results`](results/) | Verified and released clinical results linked to order items |
| 9 | [`prescriptions`](prescriptions/) | Prescription issuance and cancellation |
| 10 | [`documents`](documents/) | Patient/encounter document metadata, access restrictions |
| 11 | [`hospital-adt`](hospital-adt/) | Admission, discharge, transfer with wards, beds, inpatient context |
| 12 | [`patient-portal`](patient-portal/) | Patient-facing record access (appointments, results, prescriptions, documents) |
| 13 | [`privacy-consent`](privacy-consent/) | Consent records and break-glass request logging |
| 14 | [`interoperability-bridge`](interoperability-bridge/) | FHIR/HL7/DICOM message translation and logging |
| 15 | [`billing-bridge`](billing-bridge/) | Derived billing charge candidates from EHR events |
| 16 | [`audit`](audit/) | EHR audit search over kernel audit log |
| 17 | [`reporting`](reporting/) | Operational reporting over appointments, encounters, results |
| 18 | [`analytics-cds`](analytics-cds/) | Clinical decision support rules engine |

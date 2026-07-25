# Patient Registry

Canonical patient identity registry for the EHR suite. Manages patient demographics and identifiers.

## Domain

- **Owns tables**: `ehr_patients`, `ehr_patient_identifiers`
- **Patient identity**: name, DOB, contact, address, demographics
- **Patient identifiers**: medical record number (MRN), national ID, external IDs

## Files

- Manifest: [`module.json`](module.json)

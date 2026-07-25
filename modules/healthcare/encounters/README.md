# Encounters

Encounter lifecycle management and vitals services for the EHR domain.

## Domain

- **Owns tables**: `ehr_encounters`, `ehr_vitals`
- **Encounter lifecycle**: check-in, roomed, in-progress, completed, checked-out
- **Vitals**: BP, heart rate, temperature, respiratory rate, SpO2, pain score
- **Encounter types**: outpatient, inpatient, emergency, telemedicine

## Files

- Manifest: [`module.json`](module.json)

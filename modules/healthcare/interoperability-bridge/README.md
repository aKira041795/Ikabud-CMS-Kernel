# Interoperability Bridge

Outbound and inbound interoperability for the EHR suite. Translates clinical data to FHIR-shaped JSON and logs HL7/FHIR/DICOM message exchanges.

## Features

- **FHIR translation**: clinical data → FHIR R4 JSON
- **HL7 logging**: HL7 v2 message capture and audit
- **DICOM tracking**: imaging study references
- **Outbound**: send clinical summaries, lab results, referrals
- **Inbound**: receive external data and map to internal models

## Files

- Manifest: [`module.json`](module.json)

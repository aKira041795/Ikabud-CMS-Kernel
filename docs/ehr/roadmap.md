# EHR Roadmap

**Updated:** May 2026

This roadmap defines the phased build plan for the EHR domain inside Ikabud Kernel OS.

It assumes the EHR is not a single monolith. The target architecture is a clinical core plus governed extension modules that run under kernel routing, capability policy, tenant boundaries, and audit controls.

The first product target is serious outpatient use in clinics, school clinics, and diagnostic centers. Small-hospital features should be designed for later growth, not forced into the first release.

## Guiding principles

- The patient registry is the canonical patient identity source.
- Encounter is the main clinical work unit.
- Clinical notes are legal records and must support draft, sign, lock, and amendment.
- Orders and results are separate lifecycles.
- Verified and released results must never be casually overwritten.
- Prescriptions must always be traceable to patient, encounter, and prescriber.
- Documents require linkage, access control, and audit.
- Billing integrates through events and reference data, not through direct coupling inside charting screens.
- Privacy, consent, break-glass access, and audit are first-class requirements.
- Interoperability must be designed into identifiers, coding fields, and event contracts from the start.

## Target module shape

The EHR domain should be delivered as multiple modules with a small shared core.

Core modules:
- `ehr-core`
- `patient-registry`
- `encounters`
- `privacy-consent`
- `audit`

Clinical workflow modules:
- `clinical-notes`
- `orders`
- `results`
- `prescriptions`
- `documents`
- `scheduling`

Bridge and external-facing modules:
- `billing-bridge`
- `interoperability-bridge`
- `patient-portal`

Design rule:
- if a feature owns a distinct legal record lifecycle, operational workflow, or external integration contract, it should be a separate module
- if a feature defines shared patient, encounter, policy, or audit rules used by many modules, it belongs in the core

## Phase 1: Clinical Core MVP

Outcome:
- a usable outpatient EHR foundation for real clinic operations

Scope:
- canonical patient registry
- duplicate detection and merge review workflow
- outpatient encounter lifecycle
- SOAP and progress notes
- vitals capture
- diagnoses and longitudinal problem list
- allergy registry and safety alerts
- prescriptions
- lab orders with manual result entry
- document and file attachments
- role-aware access control
- healthcare audit trail
- basic appointments and queue states
- printable encounter summaries and signed note output

Primary modules in scope:
- `ehr-core`
- `patient-registry`
- `encounters`
- `clinical-notes`
- `prescriptions`
- `orders`
- `results`
- `documents`
- `scheduling`
- `privacy-consent`
- `audit`

Key deliverables:
- patient identity model with multiple identifiers per patient
- encounter model as the anchor for notes, orders, prescriptions, vitals, and documents
- note lifecycle: draft, signed, locked, amended
- result lifecycle: entered, verified, released
- prescription issue and cancellation tracking
- audit events for view, create, sign, print, export, amend, verify, release
- restricted-record and break-glass policy baseline
- simple operational dashboards for appointments and open encounters

Acceptance criteria:
- no note can exist outside a patient and encounter context
- signed notes cannot be silently edited
- verified results cannot be silently overwritten
- every chart view, print, and export is auditable
- duplicate patient creation is blocked by search and merge review workflows
- clinical actions are role-checked and context-checked

## Phase 2: Orders, Results, Documents, Scheduling Hardening

Outcome:
- stronger day-to-day operational workflows for ambulatory care and diagnostics

Scope:
- richer laboratory workflow states
- radiology or imaging report workflow
- richer medication and refill tracking
- document categorization and restricted access policies
- queue management improvements
- specialty-specific note templates
- department and facility scoping controls

Deliverables:
- worklists for lab and radiology staff
- abnormal result flags and reference range support
- result correction workflow with retained history
- document tags, categories, and access policies
- appointment check-in, waiting, roomed, completed, and no-show states
- specialty note templates for common visit types

Acceptance criteria:
- lab staff can work from an order-driven queue
- result verification and release permissions are separate from result entry
- restricted documents can be hidden unless policy or break-glass allows access
- appointments and queue status are visible to reception and nursing staff without exposing unnecessary chart content

## Phase 3: Billing Bridge and Reporting

Outcome:
- clinical workflows emit usable financial and operational signals without contaminating the chart model

Scope:
- billing reference layer
- charge-candidate event generation
- coding references and reporting support
- administrative and compliance reporting

Modules in scope:
- `billing-bridge`
- reporting surfaces in relevant EHR modules

Deliverables:
- event contracts for consultation completed, order requested, procedure recorded, prescription issued, document released
- billing reference fields on encounters, orders, prescriptions, and documents where needed
- operational reports for appointment flow, encounter volume, turnaround time, and user activity
- compliance reports for print, export, break-glass, and restricted-record access

Acceptance criteria:
- billing logic is not embedded in note, encounter, or result authoring code
- charge candidates can be generated from events without changing clinical record content
- compliance users can query privacy-sensitive activity from audit views

## Phase 4: Patient Portal and Consent Workflows

**Status (May 2026):** Phase 4 MVP scaffold landed. `patient-portal` module is provisioned for the `ehr` entry bundle and ships with own DB tables (`ehr_portal_accounts`, `ehr_portal_login_attempts`), own auth shell at `/portal`, capabilities for account provisioning/deactivation/view, and a portal-side appointments read model that goes through `ehr.appointment.list@1` (no direct clinical-table reads). Released-results, prescriptions, documents, consent capture, and proxy/guardian access still pending.

Outcome:
- controlled patient-facing access to selected records and actions

Scope:
- patient portal release policies
- patient result and document access
- consent capture and revocation workflows
- proxy or guardian access where applicable

Modules in scope:
- `patient-portal`
- `privacy-consent`

Deliverables:
- portal-safe read models for appointments, released results, prescriptions, and selected documents
- consent capture screens and consent document linkage
- patient-facing download and print controls for allowed records
- proxy access and consent expiration rules

Acceptance criteria:
- unreleased or restricted results are not exposed through the portal
- portal access follows the same tenant, patient, and consent rules as staff-facing access
- revoking consent affects future release behavior without deleting historical records

## Phase 5: Hospital Features

Outcome:
- extend the outpatient model into small-hospital workflows without rewriting the foundation

Scope:
- admission, discharge, transfer
- wards, rooms, and beds
- inpatient encounter types
- handoff and inpatient documentation patterns
- longer-running medication and order workflows

Deliverables:
- ADT event model
- inpatient encounter extensions and bed assignment tables
- ward census and transfer workflow
- nursing and physician handoff notes
- discharge summary and discharge instruction workflows

Acceptance criteria:
- inpatient workflows reuse patient identity and encounter governance rather than creating a second record model
- ADT actions are auditable and facility-scoped
- outpatient tenants are not forced to enable hospital-only modules

## Phase 6: Interoperability Bridge

Outcome:
- stable external exchange patterns for healthcare integrations

Scope:
- FHIR resources and API bridge
- HL7 message bridge
- DICOM study linkage and metadata bridge
- external lab, pharmacy, and payer integration patterns
- code-system support for ICD-10, LOINC, SNOMED CT, and future vocabularies

Module in scope:
- `interoperability-bridge`

Deliverables:
- stable internal identifiers and external identifier mapping tables
- canonical event payloads suitable for outbound translation
- import and export adapters for selected workflows
- coding system normalization support on diagnoses, orders, and results

Acceptance criteria:
- interoperability does not require direct database access from external systems
- internal models keep code-system fields even when a tenant starts with mostly free text
- bridges can be enabled incrementally without reshaping the core schema

## Phase 7: Analytics and Clinical Decision Support

Outcome:
- advanced insights and assisted workflows built on a reliable clinical data foundation

Scope:
- analytics feeds
- quality and operational dashboards
- AI-assisted drafting with human sign-off
- clinical decision support rules

Deliverables:
- reporting extracts or warehouse feeds
- longitudinal quality metrics
- note drafting assistance with explicit provenance and user acceptance
- rules engine hooks for safety alerts and care-gap prompts

Acceptance criteria:
- analytics and AI features never bypass note signing, amendment, privacy, or audit rules
- CDS recommendations remain advisory unless explicitly designed otherwise
- model-generated content is attributable and reviewable before finalization

## Core data model milestones

Phase 1 table groups:
- `ehr_patients`
- `ehr_patient_identifiers`
- `ehr_encounters`
- `ehr_vitals`
- `ehr_notes`
- `ehr_note_versions`
- `ehr_diagnoses`
- `ehr_problem_list`
- `ehr_allergies`
- `ehr_prescriptions`
- `ehr_orders`
- `ehr_order_items`
- `ehr_lab_results`
- `ehr_documents`
- `ehr_consents`
- `ehr_audit_events`
- `ehr_access_policies`
- `ehr_appointments`

Later-phase expansions:
- imaging and radiology report tables
- medication catalog and dispense workflow tables
- ADT, wards, rooms, beds, and inpatient care tables
- interoperability mapping and message state tables

Data model rules:
- every clinical table is tenant-scoped
- patient and encounter are the dominant foreign-key anchors
- signed and verified records use explicit lifecycle state fields
- legal record content uses immutable version history where applicable
- external identifier and coding fields are preserved even before full interoperability launches

## Capability and event roadmap

Initial event families:
- `ehr.patient.created`
- `ehr.patient.merged`
- `ehr.encounter.started`
- `ehr.encounter.closed`
- `ehr.note.signed`
- `ehr.note.amended`
- `ehr.order.created`
- `ehr.result.verified`
- `ehr.result.released`
- `ehr.prescription.issued`
- `ehr.document.uploaded`
- `ehr.record.viewed`
- `ehr.record.printed`
- `ehr.record.exported`
- `ehr.break_glass.accessed`
- `ehr.billing.item_requested`

Initial capability families:
- `ehr.patient.create@1`
- `ehr.patient.view@1`
- `ehr.patient.merge@1`
- `ehr.encounter.create@1`
- `ehr.encounter.view@1`
- `ehr.note.create@1`
- `ehr.note.sign@1`
- `ehr.note.amend@1`
- `ehr.order.create@1`
- `ehr.result.enter@1`
- `ehr.result.verify@1`
- `ehr.result.release@1`
- `ehr.prescription.issue@1`
- `ehr.document.upload@1`
- `ehr.document.view@1`
- `ehr.break_glass.request@1`
- `ehr.audit.search@1`

Contract rules:
- capability IDs must stay versioned from the first release
- caller policy should be explicit for cross-module access
- external bridge modules should consume events and call capabilities rather than reading tables directly
- synchronous business requests should use capabilities, not internal HTTP coupling

## Security and compliance milestones

Phase 1 minimums:
- role-based access with tenant scoping
- department or facility scoping where available
- chart, note, document, and result audit events
- note locking and amendment workflows
- break-glass request and audit workflow
- restricted-record flags
- consent record storage
- printable record watermarking or metadata stamping

Later milestones:
- finer patient-assignment access rules
- stronger release-of-information workflows
- data retention policy tooling
- legal hold and archival states
- encrypted backup verification and recovery drills

Non-negotiable rules:
- no silent edits of signed records
- no silent edits of verified and released results
- no unrestricted chart access based only on broad admin status
- no billing-specific mutations inside clinical note storage
- no unlogged print or export path

## Major risks and mistakes to avoid

- building notes without encounters
- allowing departments to create duplicate patient records
- making audit logs too shallow for compliance investigation
- designing access control around role only, without patient and context scoping
- coupling billing logic directly into charting workflows
- overbuilding inpatient features before ambulatory workflows are stable
- delaying interoperability fields and code systems until after schema lock-in
- treating documents as generic uploads without patient, encounter, and policy linkage

## Recommended implementation order

1. Define module boundaries, capability namespaces, and shared enums in `ehr-core`.
2. Build `patient-registry` with duplicate prevention and merge-review workflow.
3. Build `encounters` as the canonical clinical work anchor.
4. Build `clinical-notes` with immutable versioning, signing, locking, and amendment.
5. Add `allergies`, `diagnoses`, `problem list`, and `vitals` surfaces inside the Phase 1 clinical workflow.
6. Build `prescriptions`, then `orders`, then `results`, keeping result verification and release distinct.
7. Add `documents`, `audit`, and `privacy-consent` hardening before wider rollout.
8. Add `scheduling` for appointment and queue operations.
9. Add `billing-bridge` and reporting after core chart integrity is stable.
10. Add `patient-portal` and `interoperability-bridge` after internal record governance is proven.

## Direct recommendation

Start with a real clinical core, not a broad hospital platform.

If the first release does these things well, the product will have a credible foundation:
- one canonical patient registry
- one encounter-centered chart model
- signed and locked notes with amendment history
- orders, results, and prescriptions with separate lifecycles
- privacy, consent, and break-glass controls
- audit strong enough for compliance review

If the first release tries to solve everything from outpatient care to full inpatient operations at once, the likely result is weak workflows, weak audit, and a record model that becomes hard to trust and harder to maintain.

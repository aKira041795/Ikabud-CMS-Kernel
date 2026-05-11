# EHR System Design and Architecture Plan

**Updated:** May 2026
**Status:** Active design document. Source of truth for EHR cohesion, IA, layout system, persistent context, role workspaces, design tokens, and clinical safety UX.
**Scope:** Reviews the EHR as **one product**, not as isolated module pages. All recommendations apply to the whole EHR domain (admin shell + patient portal) unless otherwise scoped.

> Companion to [docs/ehr/roadmap.md](docs/ehr/roadmap.md). The roadmap defines what to build; this plan defines how it should feel and behave as a single system.

---

## A. Executive Verdict

What exists today reads as **a competent admin panel built on top of a healthy module bus** — not as an EHR. It reads "Drupal/CMS for clinics," not "clinical workspace."

Concretely:
- The shell is one of *modules*. The sidebar is grouped (good), but each item still reads like a **management page** ("Patient Registry," "Clinical Notes," "Hospital ADT," "Operations Report") — nouns aimed at the admin, not verbs aimed at the clinician.
- Every module shows the same scaffold: title → stat strip → table → form. There is no concept of a *task in progress*. There is no concept of "I am with patient Maria right now."
- The patient header bar exists in [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) but only when `patient_context` is passed; the rest of the system mostly doesn't carry it. Patient context is a feature on a few pages, not a backbone of the workspace.
- Encounter context is essentially invisible. A clinician cannot answer "what visit am I documenting on right now?" without thinking.
- Implementation leaks into the UI: raw status strings (`scheduled`, `checked-in`, `roomed`, `no-show`), UUIDs in URLs (`?selected=apt-5518e1af8c79b862`), module-named items ("Hospital ADT," "Interoperability," "Billing Signals"), and pages that look like dev consoles for the underlying capability (Audit Trail, Compliance Report).
- The dashboard is a **module launcher with counts** — not a clinical command center.

**Verdict:** a working module console with EHR-shaped content. Not yet an EHR product. It needs (a) a patient/visit spine the whole UI rides on, (b) role-aware worklists, and (c) clinical language. Nothing requires a rewrite.

---

## B. Core UX Diagnosis — Why It Feels Like a Conglomerate

**Root cause:** the system is organized around **what was built** (modules) instead of around **what the user is doing** (the patient and the visit). Each module owns its own page, list, form, status names, and action buttons. The user navigates *between modules* instead of *staying with a patient and switching tools on them*.

Symptoms:

1. **No persistent subject.** Every page resets the user's mental state. There is no "current patient" or "current visit" that follows them.
2. **Module nouns leak into nav.** "Patient Registry," "Clinical Notes," "Hospital ADT," "Interoperability," "Operations Report," "Branding & Access" are *system inventory*. Clinicians think in verbs.
3. **Status taxonomy is engineering, not clinical.** Inline strings (`scheduled`, `checked-in`, `roomed`, `waiting`, `no-show`, `canceled`) appear as raw words.
4. **Every page rebuilds its own grammar.** Tables, stat strips, action buttons, empty states differ per module.
5. **Same "stuff" lives in two pages.** A visit is split across Encounters / Notes / Orders / Results / Prescriptions. There is no unified **chart**.
6. **Dashboard is a directory.** It surfaces modules and counts, not decisions.
7. **Roles share one workspace.** Receptionist, nurse, MD, lab, billing, admin all see the same sidebar.
8. **Patient portal inherits admin density.** Same tables and chrome, less appropriate for patients.

> The cure is one architectural decision: **introduce Patient and Visit as first-class shell concepts**, then let every module read from them instead of reinventing them.

---

## C. Correct Product Mental Model

Stop modeling users as *people who configure a system*. Model them as people doing **the next clinical task**.

| Role | Mental Model | Primary Verb | Worklist |
|---|---|---|---|
| Front Desk | "Who's here, who's late, who needs to be checked in?" | Arrive · Reschedule · Bill | Today's Schedule (queue view) |
| Nurse | "Who's in a room, who needs vitals/intake?" | Vitals · Triage · Room | Active Visits awaiting nurse |
| Physician | "Whose chart do I open next? What's unsigned?" | See Patient · Document · Order · Sign | Roomed/awaiting + unsigned drafts |
| Lab/Diagnostic | "What's pending, what abnormals to report?" | Resolve · Report · Flag | Pending orders · Abnormal results |
| Billing | "What encounters are closed but unbilled?" | Code · Submit · Reconcile | Closed-not-billed |
| Admin | "Who can do what, what's our access posture?" | Configure · Audit · Approve | Settings + access requests |
| Auditor | "Who looked at this chart, was it justified?" | Investigate · Export | Audit search |
| Patient | "When's my next visit, what was my last result?" | View · Reschedule · Message | Next appointment hero |

Each is a **different landing experience**. Today they share one.

---

## D. Recommended Information Architecture

```
TODAY                  (verbs, today-focused)
  • Today              (was Dashboard)
  • Schedule           (was Appointments)
  • Queue              (new: arrived/roomed/waiting)
  • Active Visits      (was Encounters, filtered to in-progress)

PATIENTS
  • Patients           (was Patient Registry)
  • Patient Chart      (deep-link only)

CLINICAL
  • Visits             (was Encounters)
  • Notes              (was Clinical Notes)
  • Orders
  • Results
  • Medications        (was Prescriptions)
  • Documents

GOVERNANCE
  • Privacy & Consent
  • Audit Trail
  • Compliance

OPERATIONS
  • Billing Queue      (was Billing Signals)
  • Clinic Activity    (was Operations Report)
  • Insights           (was Analytics & CDS)

SYSTEM           (admin-only, collapsed by default)
  • External Systems   (was Interoperability)
  • Admissions & Beds  (was Hospital ADT)
  • Users & Roles
  • Settings           (was Branding & Access)
  • Portal Access      (was Patient Portal admin)
```

Implement at [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php) (`ehrSidebarNavGroups()`) — expand from 5 to 6 groups and translate item labels via a shell-side label map. Don't change `nav.label` in 12 module.json files.

**Priority: High.**

---

## E. Unified Layout System

Force every screen into one of **six page archetypes**:

1. **Workboard** — Today, Queue, Active Visits, Billing Queue. Filter bar · grouped rows · per-row primary action · explicit empty state.
2. **List + Side Detail** — Patients, Visits, Notes, Orders, Results, Medications, Documents. Left list · right inspector · URL-driven `?selected=` · no modals.
3. **Patient Chart** — sticky patient header · sub-tabs (Summary · Visits · Notes · Orders · Results · Meds · Documents · Consent · Audit) · main panel.
4. **Form Page** — single column ≤ 720px · grouped fieldsets · sticky footer with primary/secondary actions.
5. **Report Page** — KPI strip · controls (date range, scope) · charts/tables · export top-right.
6. **Settings Page** — left section nav · right form panel · save bar visible only when dirty.

Codify in [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) plus 5 partials (`_workboard.disyl`, `_list_detail.disyl`, `_patient_chart.disyl`, `_form_page.disyl`, `_report.disyl`, `_settings.disyl`). Modules compose, not reinvent.

**Priority: High.**

---

## F. Patient Context Design (Critical)

The header partial at [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) is a starting point. Two problems: (1) it only renders when the page passes `patient_context`; most don't. (2) it lacks safety-critical fields.

Target shape:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ◐ DELA CRUZ, Maria  · F · 47y · MRN 000124                  [✕ End session]│
│  ⚠ Allergies: Penicillin, Sulfa     🔒 Restricted record    🔄 Active visit │
│  Visit: Follow-up · Dr. Reyes · started 09:14 · 38 min ago                  │
│  [Open Chart] [Vitals] [Note] [Order] [Prescribe] [Document] [Close Visit]  │
└─────────────────────────────────────────────────────────────────────────────┘
```

Rules:
- **Always pinned** below page header on every Clinical and Patients page.
- **Allergies are red and never silent** — "No known allergies (review)" is amber, not absent.
- **Restricted/break-glass** state shown as a lock chip; opens a justification dialog before deeper read.
- **MRN, never UUID** in visible text. UUIDs go in `data-` attrs / query strings.
- **One-click context exit.** "End session" clears the patient (audit-logged).
- Identity comes from a single capability `ehr.patient.context@1` so every module gets the same data.

Implementation:
- Promote `patient_context` to a request-scoped `EhrPatientSession` set via `?patient_id=` *and* persisted in session (cleared on End or logout).
- Add `ehrPatientContextHeader($patient_id)` returning the canonical structure (allergies, alerts, restrictions, active visit).
- Render the partial unconditionally on Clinical/Patients pages.
- Emit event `ehr.patient.context.set` so audit captures every chart open.

**Priority: Critical.** Single biggest cohesion lever.

---

## G. Visit / Encounter Context Design (Critical)

When a visit is open, render this strip below the patient header:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Visit V-2026-00489 · Follow-up (Cardiology) · Dr. Reyes · ⏱ 09:14 → ongoing │
│ Progress: ✓ Vitals  ✓ Note (draft)  • Orders  • Results pending  ○ Sign-off │
│ [Resume Note] [Add Order] [Review Results] [Close Visit]                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

Rules:
- **Show step-state** (vitals · note · orders · results · meds · sign-off) so the clinician sees what's missing without leaving the page.
- **"Close Visit" is a guided action**, not a status flip. It enforces a checklist: notes signed? open orders? unaddressed abnormal results? unbilled?
- **Linked artifacts roll up.** Notes/orders/results/meds/documents created during this visit auto-filter (`encounter_id` from context).
- **One active visit per patient.** Two open → reconciliation banner.

Add capability `ehr.encounter.progress@1` → `{vitals_captured, note_signed, open_orders, pending_results, close_ready}`. Render in the visit context partial.

**Priority: Critical.**

---

## H. Dashboard — Clinical Command Center

Stop showing module tiles. Show **decisions**.

Layout (top → bottom):

1. **Greeting + filter chip** — "Good morning, Dr. Reyes · Showing: My patients today" with toggle to "All clinic."
2. **5 KPI tiles** (clinically meaningful):
   - Patients in clinic now (arrived + roomed + with provider)
   - Awaiting provider (roomed, no note started)
   - Unsigned notes (mine)
   - Abnormal results to review (mine, last 7d)
   - Open orders (mine, > 24h)
3. **Today's queue** — one row per appointment in time order, status pill + **next-action** button (Check In / Room / Start Note / Sign / Close).
4. **Active visits** — chips with elapsed time + missing-step badges.
5. **Inbox**:
   - Unsigned notes (mine, oldest first)
   - Pending lab results
   - Pending reschedule requests *(already wired via `ehr.portal.reschedule.pending@1`)*
   - Restricted-access prompts requiring break-glass justification
6. **Quick actions** — `+ New Patient`, `+ Walk-in Visit`, `+ Order`, `+ Note`.
7. **System / Admin** — collapsed disclosure at bottom; hidden for clinician-only roles.

[templates/modules/healthcare/ehr/admin/dashboard.disyl](templates/modules/healthcare/ehr/admin/dashboard.disyl) is fine as a foundation; the change is **content selection**, not chrome.

**Priority: Critical.**

---

## I. Page-by-Page Review

For each: `Issue → Layout → Primary → Secondary → Hierarchy → Missing states → Renames`.

### 1. Dashboard
- **Issue:** module launcher; counts without consequence.
- **Layout:** §H structure.
- **Primary:** "Open next patient in queue."
- **Secondary:** filter (mine/all), refresh.
- **Hierarchy:** KPIs → queue → inbox → quick actions → admin (collapsed).
- **Missing:** loading skeletons, "nothing pending" empty states per inbox section, after-hours mode.
- **Rename:** **Today**.

### 2. Appointments
- **Issue:** half-table-half-form; status soup; no arrival flow.
- **Layout:** workboard. Row = patient + time + provider + status + next-action button. Form moves to slide-in or `/appointments/new`.
- **Primary:** *contextual* (Check In if scheduled, Room if checked-in, Start Note if roomed…). Wired via `action_primary`/`action_more` in [modules/healthcare/scheduling/handlers.php](modules/healthcare/scheduling/handlers.php).
- **Secondary:** Reschedule, Cancel (under "More actions").
- **Missing:** "no appointments today" empty state; cancelled-pile collapse; no-show recovery flow.
- **Rename:** **Schedule**.

### 3. Patient Registry
- **Issue:** spreadsheet; no concept of "open chart"; "Registry" is admin language.
- **Layout:** list+detail. Click row → side panel summary → "Open Chart" CTA.
- **Primary:** Open Chart.
- **Secondary:** Start Visit, Add Document, Edit Demographics.
- **Missing:** duplicate-detection prompt at create, restricted-record indicator.
- **Rename:** **Patients**.

### 4. Encounters / Visits
- **Issue:** read-only list of past visits; no entry into a *living* visit.
- **Layout:** segmented (Active / Today / Recent / All) workboard.
- **Primary:** Resume Visit (active) or Open Visit (read-only).
- **Secondary:** Close, Reopen (audit), Print.
- **Missing:** "no active visits" state; banner if a visit has been open > 24h.
- **Rename:** **Visits**.

### 5. Clinical Notes
- **Issue:** flat list per module, divorced from the visit.
- **Layout:** when entered from a visit, full-screen note editor with patient+visit chrome. From nav, list grouped *Drafts (mine) · Awaiting co-sign · Signed*.
- **Primary:** Sign / Save Draft.
- **Secondary:** Addendum, Print, Share.
- **Missing:** **draft vs signed visual is too quiet today** — make signed = green check + author + timestamp, draft = amber dashed border + "Unsigned — discarded after 14 days."
- **Rename:** **Notes**.

### 6. Orders
- **Issue:** table without lifecycle.
- **Layout:** grouped by status: Pending · In Progress · Resulted · Cancelled. Each order links its result.
- **Primary:** New Order (catalog picker).
- **Secondary:** Cancel, Reorder, Add to Favorites.
- **Missing:** abnormal-result-arrived banner on the originating order; duplicate-order warning at entry.

### 7. Results
- **Issue:** stand alone, not tied to the order or visit.
- **Layout:** triage list — *Critical · Abnormal · Pending review · Reviewed*.
- **Primary:** Acknowledge / Add Note / Notify Patient.
- **Secondary:** Trend, Print.
- **Missing:** **critical results need a hard interrupt** — modal with "I have reviewed this critical result" and audit; never silent.

### 8. Prescriptions
- **Issue:** transactional records; current name.
- **Layout:** medication list per patient — Active · Discontinued · Historical. New Rx is a guided form with allergy + interaction check.
- **Primary:** Prescribe.
- **Secondary:** Renew, Discontinue, Print.
- **Missing:** allergy-clash banner; controlled-substance flow.
- **Rename:** **Medications**.

### 9. Documents
- **Issue:** generic file list.
- **Layout:** filter by document type; thumbnails for images/PDFs.
- **Primary:** Upload.
- **Secondary:** Send to Patient Portal, Restrict, Tag.
- **Missing:** "restricted document" lock state; preview pane.

### 10. Privacy & Consent
- **Issue:** appears as a settings page, not a clinical guardrail.
- **Layout:** per-patient consent panel inside the chart; global page is audit/log only.
- **Primary:** Capture Consent (in chart) / View Activity (global).
- **Missing:** **expired-consent banner** in the patient header.
- **Rename:** **Consent** (in chart) and **Privacy Activity** (global).

### 11. Patient Portal (admin view)
- **Issue:** treated as account-management ("Portal Accounts").
- **Layout:** worklist of accounts + the new reschedule inbox tied to it.
- **Primary:** Provision / Reset.
- **Secondary:** Deactivate, Audit.
- **Rename:** **Portal Access**.

### 12. Audit Trail
- **Issue:** developer console. Raw event names, JSON payloads.
- **Layout:** search-first (patient, user, date) with human-readable event labels ("Dr. Reyes opened chart of Maria Dela Cruz · 09:14"); JSON behind "Show details."
- **Primary:** Export.
- **Missing:** "break-glass justification" filter, "after-hours access" filter.
- **Rename:** keep, reframe as **Access Activity**.

### 13. Hospital ADT
- **Issue:** unclear name to non-hospital staff.
- **Rename:** **Admissions & Beds**.
- **Layout:** bed-board view; row per bed; status (Occupied/Cleaning/Available).

### 14. Reports (Operations / Compliance / Analytics-CDS)
- **Issue:** three pages doing the same thing differently.
- **Layout:** unify under **Insights** with sub-tabs (Operations · Compliance · Clinical).
- **Primary:** Date range + Export.
- **Missing:** saved views, scheduled email export.

### 15. Billing Signals
- **Rename:** **Billing Queue**.
- **Layout:** worklist of closed-not-billed encounters with charge codes ready or missing. Primary = "Send to billing."

### 16. Branding & Access
- **Issue:** mixed concerns.
- **Rename:** **Settings** with sub-pages: *Branding · Login Page · Security · Integrations*.

---

## J. Sidebar & Navigation Review

Current grouping at [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php) (`ehrSidebarNavGroups()`) is reasonable. Issues:

- Description lines under each item add noise for daily users. Show on hover or in onboarding only.
- Active-state styling is loud at full sidebar density. Use a left-rail accent + subtle bg.
- "Governance & Revenue" mashes two domains. Split into **Governance** and **Operations**.
- Add a **global "Find patient" search** at the top of the sidebar (keyboard `/`). Removes ~60% of friction by itself.

Label map (apply shell-side, not via module.json):

| From | To |
|---|---|
| Dashboard | Today |
| Appointments | Schedule |
| Encounters | Visits |
| Patient Registry | Patients |
| Clinical Notes | Notes |
| Prescriptions | Medications |
| Privacy & Consent | Consent |
| Hospital ADT | Admissions & Beds |
| Interoperability | External Systems |
| Operations Report | Clinic Activity |
| Analytics & CDS | Insights |
| Billing Signals | Billing Queue |
| Branding & Access | Settings |
| Patient Portal | Portal Access |

**Priority: High.**

---

## K. Role-Based Workspaces

Today every authenticated EHR user sees the same sidebar. Extend `ehrSidebarNavGroups()` to accept a role and filter/promote items.

| Role | Visible groups | Landing | Hidden |
|---|---|---|---|
| Receptionist | Today, Patients | **Schedule** (queue) | Clinical, Governance, Insights |
| Nurse | Today, Patients, Clinical (Notes/Vitals/Orders) | **Queue** filtered to roomed/awaiting | Billing, External Systems, Settings |
| Physician | Today, Patients, Clinical, Insights (read-only) | **Today** with Mine filter | Settings, Users |
| Lab/Diagnostic | Today (Results worklist), Patients | **Results · Pending** | Schedule, Visits, Notes write |
| Billing | Operations (Billing Queue), Patients (read-only) | **Billing Queue** | Clinical write, Settings |
| Administrator | All groups | **Today** | — |
| Auditor | Governance only | **Audit Trail** search | Everything else |
| Patient | Patient portal shell | **Portal home** | Admin shell entirely |

Deliver as `nav.roles` + a *suppression* map per role. One sidebar with role-aware filtering — not six sidebars.

**Priority: High** (after §F/§G).

---

## L. Patient Portal Review

Foundation is good (shared header, 3px radius, two-column appointments, reschedule form, inbox). Remaining issues:

- **Density still admin-grade.** Patient screens should be larger type (16/20 base), more whitespace, fewer tiles.
- **Next appointment hero** — keep. Add "Add to calendar" (.ics) and "Get directions."
- **Results visibility** — plain-language framing, not raw lab values without context. Show "Reviewed by Dr. X on …" prominently. Hide raw codes.
- **Medications** — cards (name, dose, frequency, prescriber, refills remaining), not table.
- **Documents** — group by type (Lab reports · Imaging · After-visit summary · Forms).
- **Sharing permissions** — single page **Privacy** with toggles in plain English ("Share my records with Dr. Cruz at City Hospital").
- **Patient-friendly language** — strip "encounter," "provider," "MRN," "PRN." Use "visit," "doctor/clinic," "patient number," "as needed."
- **Mobile** — confirm inline-details pattern works at 360px on a real device.
- **Top-level nav** — add a persistent "Help / Contact clinic" anchor.

**Priority: Medium.**

---

## M. Design System Recommendations

Codify in a `_styles.disyl` partial set used by **all** EHR pages, not just patient portal.

**Typography**
- Stack: `Inter, system-ui, sans-serif`. One font.
- Scale: `12 / 13 / 14 / 16 / 18 / 22 / 28`. No others.
- Line height: 1.4 body, 1.2 headings.

**Spacing**
- 4px base. Allowed: `4 / 8 / 12 / 16 / 20 / 24 / 32 / 48`. Forbid 6/10/14.
- Card padding: `16` mobile, `24` desktop.

**Radius**
- One token: `3px` (already enforced).

**Color (semantic, not Tailwind raw)**
- Surface: white / `#f8fafc` / `#0f172a` (sidebar)
- Brand: teal (action)
- Status: scheduled=slate, arrived=teal, waiting=amber, in-room=indigo, completed=emerald, no-show=rose, cancelled=slate-2
- **Critical clinical**: red `#dc2626` reserved for allergies, abnormal results, restricted access. Don't use red for general delete.

**Cards**
- One border (`slate-200`), no shadow at rest, subtle on hover, none on inner cards.
- Title row: 14/600 uppercase track + helper 12/400 muted.

**Buttons** (4 hierarchies, no others)
- Primary: solid teal
- Secondary: white/border slate
- Ghost: text-only teal
- Destructive: solid rose, only for irreversible actions

**Badges/Pills**
- Same height (24px), same horizontal padding, uppercase 11/600.
- Colour paired to status semantic above.

**Tables / Lists**
- Row height 56px, vertical-align middle.
- First column = identity, last = action, middle = data.
- Row hover = `slate-50`. No striping.

**Forms**
- Single column ≤ 720px wide.
- Labels above inputs. Help text below in 12/muted.
- Inputs 40px tall. Focus ring 2px teal.
- Submit row sticky at bottom on long forms.

**Section headers**
- 14/600 uppercase track, 32px top margin, 8px bottom margin, divider line.

**Empty states**
- Icon + one-line "what is this" + one-line "what to do" + primary CTA.
- Never an empty table with column headers and nothing else.

**Warning / Restricted states**
- Restricted: amber banner, lock icon, "Reason required" CTA.
- Critical: red banner with strong emphasis (animate-on-arrive ok, no actual sound).

**Priority: High.**

---

## N. Clinical Safety UX Review

1. **Allergy invisibility.** Patient header today shows MRN/age but not allergies. **Fix immediately:** red allergy chip; "No known allergies" must be explicit, not silence. *Severity: High.*
2. **Active patient ambiguity.** Forms across modules accept `?patient_id=` but don't visually confirm "you are about to write this for X." Wrong-patient note is the classic EHR safety failure. **Fix:** every write form must show patient-context partial *and* a confirm step on submit when there is no active session match. *Severity: Critical.*
3. **Active visit ambiguity.** Same as above for visits. A note without a visit attached should be impossible (or require typed override). *Severity: High.*
4. **Draft vs signed.** Visual difference too quiet. Drafts must be obviously incomplete (amber dashed border, "DRAFT" watermark). Signed must show signer + timestamp. *Severity: High.*
5. **Abnormal result visibility.** Triage-first ordering and colour (Critical/Abnormal/Normal). Critical = hard acknowledgment. *Severity: Critical.*
6. **Restricted document handling.** No visible indicator today. Add lock icon + access-reason gate. *Severity: High.*
7. **Order status clarity.** Pending/in-progress/resulted/cancelled need their own colour and label. *Severity: Medium.*
8. **Audit feedback to user.** When opening a restricted record, user should *see* "Your access to this record is being recorded." Trust + deterrence. *Severity: Medium.*
9. **Cross-patient leakage.** A header that *changes patient when navigating* without the user noticing is the worst possible bug class. The "End session" or explicit chart open should be the only way the active patient changes. **Verify this in QA.**

---

## O. Implementation Roadmap

Ordered by leverage, not by ease. Mirrors the `Phase 8: EHR Cohesion & Workspace Spine` block in [docs/ehr/roadmap.md](docs/ehr/roadmap.md).

**Status:** All seven phases shipped. Reference commits: `5a95d21` (queue worklist), `e66b37d` (safety chips), `089aa6f` (role landing + draft/critical emphasis), `2ef15a7` (portal §L), `9b6ed00` (six layout archetype partials). Validation: 101/101 `php tests/ehr_auth_experience_test.php`.

**Phase 1 — Cohesion fixes (no backend change)** — ✅ done
- Apply label map in shell ("Encounters" → "Visits", etc.)
- Regroup nav (Today / Patients / Clinical / Governance / Operations / System)
- Apply design tokens (typography, radius, button hierarchy, status colours) globally
- Hide module-tier "System" group from non-admin roles
- Add "Find patient" search (`/`) to sidebar header
- Add empty-states to every list page

**Phase 2 — Patient & Visit context** — ✅ done
- Promote `patient_context` to a session-backed `EhrPatientSession` that survives navigation
- Add allergies/restrictions/active-visit to header (cap-bus reads from registry + consent + scheduling)
- Add `ehr.encounter.progress@1` capability and a visit-context strip
- Write forms inherit context; remove most `?patient_id=` URL params

**Phase 3 — Dashboard / worklists** — ✅ done
- Replace dashboard tiles with §H clinical command center
- Reframe Appointments as a queue worklist (largely done in `5a95d21`)
- Reframe Visits as Active/Today/Recent
- Build the result-triage view

**Phase 4 — Patient Chart spine** — ✅ done (`/admin/ehr/patients/{id}/chart`)
- Introduce `/admin/ehr/patients/{id}/chart` with sub-tabs (Summary · Visits · Notes · Orders · Results · Meds · Documents · Consent · Audit)
- Each tab is the existing module list filtered to patient_id
- "Open Chart" from anywhere

**Phase 5 — Role-based workspaces** — ✅ done (`ehrRoleLandingUrl()`, role-filtered nav via `ehrRoleAllowedNavKeys()`)
- Add `nav.roles` filter
- Define landing per role
- Suppress non-relevant groups per role

**Phase 6 — Patient portal refinement** — ✅ done (commit `2ef15a7`: clinic injection, `.ics` calendar, directions, plain-language results, grouped documents)
- Patient-friendly typography scale
- Plain-language results
- Mobile QA
- "Add to calendar" + directions on appointments

**Phase 7 — Design system hardening** — ✅ done (commit `9b6ed00`: `_page_header`, `_kpi_strip`, `_empty_state`, `_workboard`, `_list_detail`, `_form_page`, `_report`, `_settings`, `_patient_chart_tabs` under `templates/modules/healthcare/ehr/partials/`)
- Move every page onto layout archetypes (§E)
- Visual regression suite for header / context bars
- Accessibility audit (focus rings, contrast, keyboard nav)

Phase 1 is reversible and high-payoff — start there. Phase 2 is the architectural keystone.

---

## P. Final Recommended UX Direction

> **Stop shipping modules. Start shipping a workspace.**

The EHR's job is to keep the user **with the patient** while switching tools on the patient. Today the EHR keeps switching the user to a different page about a different concept. That is the entire problem.

Three commitments turn this product around:

1. **One patient, one visit, always visible.** The patient header and (when active) the visit strip become non-negotiable shell furniture. Every write surface inherits them. No write screen exists without them.
2. **Worklists, not tables.** Today / Schedule / Queue / Results / Notes / Billing become "what's next for me?" worklists with one primary action per row. CRUD tables disappear from the front line.
3. **Clinical language, role-aware nav.** Translate every label, hide every screen the role doesn't need, and make the dashboard the place a clinician *starts*, not configures.

No rewrite required:
- a label map in the shell (~1 day)
- an `EhrPatientSession` + `ehr.encounter.progress@1` capability (~3–5 days)
- a real dashboard worklist (~3–5 days)
- a `/patients/{id}/chart` spine (~1–2 weeks)
- discipline to push every new module page into one of the six layout archetypes

Do those, and the same modules that today read as "a conglomerate of options" will read as one EHR.

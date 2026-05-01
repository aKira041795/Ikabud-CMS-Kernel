# Guidance UX / IA Enhancements

This document summarizes the May 2026 Guidance information architecture cleanup. The goal of the change set is to make Guidance feel like one coherent counseling system instead of several loosely connected screens.

The implementation deliberately keeps the existing route and data contracts intact. The work focuses on navigation, labels, and workflow clarity in the shared Guidance shell and the highest-traffic Guidance views.

## Design objectives

- make the module read as one counseling workspace, not separate case, session, and admin mini-apps
- make the sidebar reflect the real mental model of the product
- keep the primary object clear: students are the main entity users work around
- keep scheduled work and completed work distinct in the UI
- reduce hidden admin destinations so setup work is easier to discover
- preserve existing backend contracts and Guidance route structure while improving the front-end experience

## What changed

### 1. Sidebar structure was tightened

The shared Guidance shell now presents a clearer top-level hierarchy:

- Dashboard
- Students
- Sessions
  - Appointments
  - Session Records
- Reports
- Calendar
- Alerts
- Trackers
- Administration
  - Users
  - Colleges
  - Form Settings
  - Email Templates
  - Settings

Profile and Logout remain in the user menu because they are personal account actions, not workspace destinations.

### 2. Admin destinations were moved into visible IA

Before this change, several critical configuration views were only accessible from the avatar menu. That made the system feel split between a "work area" and a hidden "admin area" with no obvious boundary.

The updated IA exposes operational administration directly in the sidebar. This is more consistent with how staff think about the module: Users, Colleges, Forms, Templates, and Settings are part of Guidance administration, not personal profile actions.

### 3. Sessions now behave like a real workflow group

The Sessions cluster now consistently means:

- Appointments: scheduled, pending, and upcoming counseling work
- Session Records: completed sessions and attendance outcomes

The collapsed sidebar also preserves both destinations so the IA does not change when the sidebar narrows.

### 4. Labels now match the actual data on screen

Several visible labels previously contradicted the data being shown. The cleanup corrects those mismatches:

- dashboard "New Session" is now "New Appointment"
- dashboard "Recent Counseling Sessions" is now "Recently Updated Students" because the widget shows student records, not session rows
- sidebar "Resources" is now "Trackers" to match the destination feature
- student detail wording was adjusted so mixed activity is not mislabeled as completed sessions

### 5. Alerts navigation now follows the shell contract

Alerts now uses the same shell-level HTMX navigation pattern as the rest of the primary Guidance views. This makes the module feel consistent during navigation and preserves the shared workspace behavior.

## Why it is set this way

### Students are the primary object

Guidance is fundamentally centered on a student record. Counselors track context, risk, notes, documents, alerts, and appointments around that student. Because of that, Students is kept as the primary entity-level destination.

This does not require changing underlying route names or database terms immediately. It simply ensures the UX speaks in one consistent object language.

### Sessions are a process layer, not a competing object layer

Appointments and Session Records belong together because they represent two stages of the same counseling workflow:

- appointment is the scheduled or pending step
- session record is the completed or outcome step

Keeping them adjacent in one Sessions group makes the module easier to learn and reduces the feeling that counseling work is split across unrelated pages.

### Administration should be visible, not hidden

If setup screens are tucked inside the profile menu, the product feels improvised. Administrative destinations are first-class parts of the system and should be discoverable from the same workspace shell.

The new structure makes it obvious where a user goes to configure staff, colleges, forms, templates, and module settings.

### Labels should describe user intent, not internal implementation accidents

The cleanup prefers labels that answer the user's question "What am I doing here?"

- on the dashboard, users are creating an appointment, not an abstract session
- in the dashboard table, users are reviewing student records, not session rows
- in Trackers, users are managing student trackers, not generic resources

This matters because unclear labels force users to reverse-engineer the product's data model every time they move between pages.

### Navigation should not change meaning when the layout collapses

The collapsed sidebar now preserves access to both Appointments and Session Records. A responsive shell should hide space, not hide product structure.

## Intended UX

### Core counselor UX

The intended counselor journey is:

1. Start on Dashboard for workload awareness and quick actions.
2. Open Students to find or create the student record.
3. Work inside Student Profile as the main case workspace.
4. Use Sessions when managing scheduled appointments or reviewing completed outcomes across all students.
5. Use Alerts for cross-student urgency and follow-up awareness.
6. Use Reports when analyzing trends or exporting management views.

This gives counselors one clear model:

- student pages are where context lives
- session pages are where counseling workflow is managed at scale
- alerts and reports are cross-cutting views

### Student profile UX

The intended student-level experience is:

- Overview shows background, risk, current state, and recent activity
- Appointments shows scheduled or pending counseling work for that student
- Session Records shows completed session outcomes for that student
- Notes, Documents, Alerts, and Activity Log support the surrounding casework

This makes the student profile the main workspace hub without forcing every system-level action to happen there.

### Administrative UX

The intended admin journey is:

1. Use the Administration section in the sidebar.
2. Manage staff in Users.
3. Manage academic structure in Colleges.
4. Manage intake and workflow fields in Form Settings.
5. Manage booking and communication copy in Email Templates.
6. Manage module-wide operating behavior in Settings.

The result is a much clearer split between operational counseling work and system setup work.

## UX outcome expected from this change

Users should now experience Guidance as:

- easier to scan because the sidebar reflects real product groupings
- easier to learn because terminology is more stable
- easier to operate because administration is visible and grouped
- more trustworthy because labels match the data shown on screen
- more consistent because top-level pages use the same workspace navigation behavior

The cleanup does not attempt a full product redesign. It is a structural UX pass that removes the most confusing seams while preserving existing functionality and backend behavior.

## Current implementation scope

This enhancement pass updates the shared shell and the most important Guidance entry points:

- Guidance shell navigation and labels
- dashboard quick actions and dashboard table labeling
- student list create flow consistency
- student detail wording around appointment activity and session records
- appointment and session-record terminology in their respective pages
- tracker naming consistency

## Recommended follow-up work

If Guidance receives another UX pass, the highest-value follow-up items are:

- align remaining "case" terminology behind the scenes with the student-facing language
- define the exact boundary between top-bar notifications, Alerts page, and per-student alerts
- formalize a visual distinction between student context views and administration views
- add an explicit empty-state explanation of the Appointments to Session Records lifecycle

## Files affected in this pass

- [templates/modules/guidance/layouts/app.disyl](../../templates/modules/guidance/layouts/app.disyl)
- [templates/modules/guidance/pages/dashboard.disyl](../../templates/modules/guidance/pages/dashboard.disyl)
- [templates/modules/guidance/pages/cases.disyl](../../templates/modules/guidance/pages/cases.disyl)
- [templates/modules/guidance/pages/case-view.disyl](../../templates/modules/guidance/pages/case-view.disyl)
- [templates/modules/guidance/pages/appointments.disyl](../../templates/modules/guidance/pages/appointments.disyl)
- [templates/modules/guidance/pages/session-records.disyl](../../templates/modules/guidance/pages/session-records.disyl)
- [templates/modules/guidance/pages/trackers.disyl](../../templates/modules/guidance/pages/trackers.disyl)
- [templates/modules/guidance/partials/case-detail-panel.disyl](../../templates/modules/guidance/partials/case-detail-panel.disyl)
- [templates/modules/guidance/partials/recent-cases.disyl](../../templates/modules/guidance/partials/recent-cases.disyl)
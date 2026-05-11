# EHR UI/UX Refactor Implementation Plan

**Status:** Ready to implement (Phase 1–2 scoped below)
**Companion:** [docs/ehr/system-design-and-architecture-plan.md](docs/ehr/system-design-and-architecture-plan.md) (conceptual foundation)
**Roadmap:** [docs/ehr/roadmap.md](docs/ehr/roadmap.md) (Phase 8: EHR Cohesion & Workspace Spine)

---

## I. New Sidebar Structure

### Current State
- [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php) `ehrSidebarNavGroups()` returns 5 groups: Clinical, Patients, Governance & Revenue, Operations, Admin.
- Sidebar rendered via [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl), no role-aware filtering.
- Each item is `{ label, icon, url, description }` from module.json `nav.label`, inherited as-is.
- All users see the same tree; descriptions are verbose for quick-reference users.

### Target State

```
┌─ TODAY
│   Today              (was Dashboard)
│   Schedule           (was Appointments)
│   Queue              (new: arrived/roomed/waiting subset)
│   Active Visits      (was Encounters, filtered to in-progress)
│
├─ PATIENTS
│   Patients           (was Patient Registry)
│   [Patient Chart]    (deep-link only; no sidebar entry)
│
├─ CLINICAL
│   Visits             (was Encounters)
│   Notes              (was Clinical Notes)
│   Orders
│   Results
│   Medications        (was Prescriptions)
│   Documents
│
├─ GOVERNANCE
│   Consent            (was Privacy & Consent)
│   Audit Trail        (renamed Access Activity)
│
├─ OPERATIONS
│   Billing Queue      (was Billing Signals)
│   Clinic Activity    (was Operations Report)
│   Insights           (was Analytics & CDS)
│
└─ SYSTEM [admin-only, collapsed by default]
    External Systems   (was Interoperability)
    Admissions & Beds  (was Hospital ADT)
    Users & Roles      (new admin-only)
    Settings           (was Branding & Access)
    Portal Access      (was Patient Portal)
```

### Label Map
Apply shell-side (do not edit 12 module.json files):

```php
// In modules/healthcare/ehr/helpers.php, new helper:
function ehrNavLabelMap() {
  return [
    'Dashboard' => 'Today',
    'Appointments' => 'Schedule',
    'Encounters' => 'Visits',
    'Patient Registry' => 'Patients',
    'Clinical Notes' => 'Notes',
    'Prescriptions' => 'Medications',
    'Privacy & Consent' => 'Consent',
    'Hospital ADT' => 'Admissions & Beds',
    'Interoperability' => 'External Systems',
    'Operations Report' => 'Clinic Activity',
    'Analytics & CDS' => 'Insights',
    'Billing Signals' => 'Billing Queue',
    'Branding & Access' => 'Settings',
    'Patient Portal' => 'Portal Access',
  ];
}

// Existing ehrSidebarNavGroups() calls this to translate each item.label
function ehrSidebarNavGroups($role = null) {
  $map = ehrNavLabelMap();
  // ... existing logic, but apply $map[$item['label']] ?? $item['label']
  
  // NEW: Return early for non-admin roles, suppressing "System" group
  if ($role && $role !== 'admin') {
    $groups = array_filter($groups, fn($g) => $g['name'] !== 'System');
  }
  
  return $groups;
}
```

### Implementation Steps

1. **Add `ehrNavLabelMap()` helper** (1 file, 15 min)
   - Location: [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php)
   - Apply map in `ehrSidebarNavGroups()` when building item labels
   - Test: load `/admin/ehr/` and verify labels match target above

2. **Regroup nav items into 6 groups** (1 file, 30 min)
   - Modify `ehrSidebarNavGroups()` to move items between groups
   - Surgically reorder based on target IA above
   - Suppress "Governance & Revenue"; split into **Governance** + **Operations**
   - Verify no item is orphaned

3. **Hide "System" group for non-admin roles** (1 file, 15 min)
   - Pass `role()` to `ehrSidebarNavGroups($role)`
   - Filter out groups with `name === 'System'` if role ≠ 'admin'
   - Test: logged in as patient-portal user, verify System hidden; as admin, verify visible

4. **Add global "Find patient" search** to sidebar (1 file, 1–2 hr)
   - Add input at top of sidebar in [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl)
   - Keyboard shortcut `/` focuses input
   - Submit → `/admin/ehr/patients?q=<query>` or XHR autocomplete
   - Link top result to patient chart (to be created in Phase 2)

5. **Remove descriptions; show on hover only** (1 file, 15 min)
   - Update sidebar template to hide `item.description` by default
   - Add tooltip or hover disclosure

### Code Locations
- [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php) — `ehrSidebarNavGroups()`
- [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) — sidebar rendering
- [modules/healthcare/ehr/module.json](modules/healthcare/ehr/module.json) — may add `nav.group_order` or similar metadata

### Validation
- [ ] Sidebar renders with 6 groups (Today, Patients, Clinical, Governance, Operations, System)
- [ ] All 12 module items are present and reachable
- [ ] Labels match target map (Appointments → Schedule, etc.)
- [ ] System group is hidden for nurse/receptionist; visible for admin
- [ ] `/` focuses the Find Patient search
- [ ] "Today" group appears first, "System" last

### Effort: **4–5 hours**

---

## II. New Dashboard Wireframe

### Current State
- [templates/modules/healthcare/ehr/admin/dashboard.disyl](templates/modules/healthcare/ehr/admin/dashboard.disyl) shows 6 tiles (Clinical, Patients, Appointments, etc.) with counts and per-tile CTAs.
- No concept of "decisions" or "next actions."
- All roles see the same dashboard.

### Target State

```
╔═══════════════════════════════════════════════════════════════════════════╗
║ Good morning, Dr. Reyes · Showing: My patients                            ║
║                                              [All clinic ▾] [Refresh]     ║
╠═══════════════════════════════════════════════════════════════════════════╣
║                                                                           ║
║  Patients in clinic: 8    Roomed awaiting: 3    Unsigned notes: 2        ║
║  Abnormal results (7d): 1    Open orders (>24h): 4                       ║
║                                                                           ║
╠═══════════════════════════════════════════════════════════════════════════╣
║ TODAY'S QUEUE                                                             ║
║ ──────────────────────────────────────────────────────────────────────── ║
║ 09:00  ✓ Maria Dela Cruz     · Check-in      Dr. Reyes                  ║
║ 09:15  • James Wong          · [Room ▼] [+]                              ║
║ 09:30  • Sarah Chen           · [Room ▼] [+]                              ║
║ ... (5 more)                                                              ║
║                                                          [See all queue] ║
╠═══════════════════════════════════════════════════════════════════════════╣
║ ACTIVE VISITS                  [+]                                        ║
║ Patient: Maria Dela Cruz (58m open)  [Resume Note]  [Close Visit ▼]      ║
║                                                                           ║
╠═══════════════════════════════════════════════════════════════════════════╣
║ INBOX                                                                     ║
║ ───────────────────────────────────────────────────────────────────────  ║
║ Unsigned notes (mine)           [3]                                       ║
║ Pending results                 [1]                                       ║
║ Pending reschedule requests     [2]                                       ║
║ Restricted record prompts       [0]                                       ║
║                                                                           ║
╠═══════════════════════════════════════════════════════════════════════════╣
║ QUICK ACTIONS                                                             ║
║ [+ New Patient] [+ Walk-in Visit] [+ Order] [+ Note]                    ║
║                                                                           ║
╠═══════════════════════════════════════════════════════════════════════════╣
║ ▼ SYSTEM (admin-only)                                                     ║
║   Users & roles [1] · Settings · External integrations · Audit trail [7] ║
╚═══════════════════════════════════════════════════════════════════════════╝
```

### Structure (HTML/Disyl)

```disyl
<div class="ehr-dashboard">
  <!-- Greeting & filter -->
  <div class="dashboard-header">
    <div>
      <h1>Good morning, {{ user.given_name }}</div>
      <p class="text-sm muted">Showing: <select name="scope">
        <option value="mine">My patients</option>
        <option value="all">All clinic</option>
      </select></p>
    </div>
    <button class="icon" @click="location.reload()">⟳ Refresh</button>
  </div>

  <!-- KPI tiles -->
  <div class="dashboard-kpis">
    <div class="kpi-tile">
      <div class="kpi-label">Patients in clinic</div>
      <div class="kpi-value">{{ kpis.in_clinic }}</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-label">Roomed awaiting</div>
      <div class="kpi-value">{{ kpis.roomed_awaiting }}</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-label">Unsigned notes</div>
      <div class="kpi-value">{{ kpis.unsigned_notes }}</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-label">Abnormal results (7d)</div>
      <div class="kpi-value">{{ kpis.abnormal_results }}</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-label">Open orders (>24h)</div>
      <div class="kpi-value">{{ kpis.open_orders_old }}</div>
    </div>
  </div>

  <!-- Today's queue (workboard) -->
  <section class="dashboard-section">
    <h2>Today's Queue</h2>
    <div class="workboard">
      @foreach ($queue as $appt)
        <div class="workboard-row" data-appt-id="{{ $appt->id }}">
          <div class="time">{{ $appt->start_time->format('H:i') }}</div>
          <div class="status-badge" class="{{ ehrStatusClass($appt->status) }}">
            {{ ehrStatusLabel($appt->status) }}
          </div>
          <div class="patient-name">{{ $appt->patient->full_name }}</div>
          <div class="provider">{{ $appt->provider->short_name }}</div>
          <div class="actions">
            <button class="btn-primary" @click="apptAction('{{ $appt->id }}')">
              {{ $appt->action_primary }}
            </button>
            <button class="btn-ghost" @click="apptMoreActions('{{ $appt->id }}')">
              More ▾
            </button>
          </div>
        </div>
      @endforeach
      @if (count($queue) > 5)
        <a href="/admin/ehr/appointments" class="see-all-link">See all queue</a>
      @endif
      @if (count($queue) === 0)
        <div class="empty-state">
          <div class="empty-icon">📅</div>
          <p>No appointments scheduled</p>
          <button class="btn-primary" @click="location = '/admin/ehr/appointments/new'">
            Schedule appointment
          </button>
        </div>
      @endif
    </div>
  </section>

  <!-- Active visits -->
  <section class="dashboard-section">
    <h2>Active Visits</h2>
    @foreach ($activeVisits as $visit)
      <div class="active-visit-card">
        <div class="visit-header">
          <div class="patient-and-time">
            <strong>{{ $visit->patient->full_name }}</strong>
            <span class="muted">({{ $visit->elapsed_time }}m open)</span>
          </div>
          <div class="visit-actions">
            <button class="btn-secondary" @click="resumeNote('{{ $visit->id }}')">
              Resume Note
            </button>
            <button class="btn-ghost" @click="closeVisitFlow('{{ $visit->id }}')">
              Close Visit ▾
            </button>
          </div>
        </div>
        <div class="visit-progress">
          {{ partial('_visit_progress_strip', ['visit' => $visit]) }}
        </div>
      </div>
    @endforeach
    @if (count($activeVisits) === 0)
      <div class="empty-state-subtle">
        <p>No active visits</p>
      </div>
    @endif
  </section>

  <!-- Inbox -->
  <section class="dashboard-section">
    <h2>Inbox</h2>
    <div class="inbox-grid">
      <div class="inbox-item">
        <div class="inbox-label">Unsigned notes (mine)</div>
        <a href="/admin/ehr/notes?status=draft" class="inbox-count">
          {{ $inbox.unsigned_notes }}
        </a>
      </div>
      <div class="inbox-item">
        <div class="inbox-label">Pending results</div>
        <a href="/admin/ehr/results?status=pending" class="inbox-count">
          {{ $inbox.pending_results }}
        </a>
      </div>
      <div class="inbox-item">
        <div class="inbox-label">Pending reschedule requests</div>
        <a href="/admin/ehr/portal/reschedule-requests" class="inbox-count">
          {{ $inbox.reschedule_requests }}
        </a>
      </div>
      <div class="inbox-item">
        <div class="inbox-label">Restricted record prompts</div>
        <a href="/admin/ehr/audit?filter=break-glass" class="inbox-count">
          {{ $inbox.restricted_access_pending }}
        </a>
      </div>
    </div>
  </section>

  <!-- Quick actions -->
  <section class="dashboard-section">
    <h2>Quick Actions</h2>
    <div class="quick-actions">
      <button class="btn-primary" @click="newPatientFlow()">+ New Patient</button>
      <button class="btn-primary" @click="walkInVisitFlow()">+ Walk-in Visit</button>
      <button class="btn-secondary" @click="newOrderFlow()">+ Order</button>
      <button class="btn-secondary" @click="newNoteFlow()">+ Note</button>
    </div>
  </section>

  <!-- System section (admin-only, collapsed) -->
  @if (role() === 'admin')
    <section class="dashboard-section system-section">
      <details>
        <summary>
          <strong>▼ SYSTEM</strong>
          <span class="muted">(admin-only)</span>
        </summary>
        <div class="system-items">
          <a href="/admin/ehr/users" class="system-link">Users & Roles</a>
          <a href="/admin/ehr/settings" class="system-link">Settings</a>
          <a href="/admin/ehr/integrations" class="system-link">External Integrations</a>
          <a href="/admin/ehr/audit" class="system-link">Audit Trail</a>
        </div>
      </details>
    </section>
  @endif
</div>
```

### Data Contract
New handler at [modules/healthcare/ehr/handlers.php](modules/healthcare/ehr/handlers.php) or similar:

```php
function ehrdashboard_pageState() {
  return [
    'kpis' => [
      'in_clinic' => /* count appointments status=arrived/roomed/with-provider today */,
      'roomed_awaiting' => /* count status=roomed without a note_id started */,
      'unsigned_notes' => /* count notes where signed_at=null AND encounter_id today */,
      'abnormal_results' => /* count results flagged abnormal in last 7d */,
      'open_orders_old' => /* count orders status=open AND created > 24h ago */,
    ],
    'queue' => /* appointments today, ordered by start_time, with action_primary computed */,
    'activeVisits' => /* encounters status=in-progress */,
    'inbox' => [
      'unsigned_notes' => /* count for current user */,
      'pending_results' => /* count for current user's patients */,
      'reschedule_requests' => /* call ehr.portal.reschedule.pending@1 capability */,
      'restricted_access_pending' => /* count audit break-glass events awaiting justification */,
    ],
  ];
}
```

### Implementation Steps

1. **Rename Dashboard → Today** (5 min)
   - Update module.json `nav.label`

2. **Add KPI bar** (30 min)
   - Query functions in handler (in-clinic, roomed-awaiting, unsigned, abnormal, old-open)
   - Render 5 tiles with counts
   - Link each to the relevant worklist

3. **Build Today's Queue workboard** (1 hr)
   - Filter appointments to today, ordered by time
   - Add status badge + patient name + provider
   - Compute `action_primary` (Check In / Room / Start Note / etc.) from [modules/healthcare/scheduling/handlers.php](modules/healthcare/scheduling/handlers.php) existing logic
   - Add "More actions" dropdown (Reschedule, Cancel, etc.)

4. **Build Active Visits section** (30 min)
   - Query encounters `status='in_progress'`
   - Render patient name + elapsed time
   - Show visit-progress strip (to be created in Phase 2)
   - Quick actions: Resume Note, Close Visit

5. **Build Inbox** (30 min)
   - Query counts: unsigned notes (mine), pending results, pending reschedules (via cap-bus), restricted prompts
   - Link each count to the source worklist

6. **Add Quick Actions** (15 min)
   - Wire to new entity flows (patient, visit, order, note)
   - Use modals or slide-in forms

7. **Add System disclosure** (15 min)
   - `@if (role() === 'admin')` guard
   - `<details>` element with links to Users, Settings, Integrations, Audit

### Code Locations
- [templates/modules/healthcare/ehr/admin/dashboard.disyl](templates/modules/healthcare/ehr/admin/dashboard.disyl) — main template (replace or extend)
- [modules/healthcare/ehr/handlers.php](modules/healthcare/ehr/handlers.php) — new `ehrdashboard_pageState()` or similar
- [modules/healthcare/scheduling/handlers.php](modules/healthcare/scheduling/handlers.php) — reuse appointment status/action logic

### Validation
- [ ] Dashboard loads in ≤2s
- [ ] KPI counts update on page refresh
- [ ] Queue lists all appointments for today in time order
- [ ] Each queue row shows patient name, provider, and contextual action button
- [ ] Active visits show elapsed time and dismiss when closed
- [ ] Inbox counts link to source worklists
- [ ] System section visible to admin, hidden to clinicians
- [ ] All quick actions open modals/forms without page reload

### Effort: **6–8 hours**

---

## III. Standard Page Layout Template

### Current State
- Each module page has its own header, filter bar, and table structure.
- No enforced pattern; inconsistent action placement, spacing, empty-state handling.
- Duplicated header logic across [modules/healthcare/ehr/admin/](modules/healthcare/ehr/admin/), [modules/healthcare/scheduling/admin/](modules/healthcare/scheduling/admin/), etc.

### Target State

Codify six layout archetypes as reusable Disyl partials:

1. **Workboard** — filtered list of items, one row per item, contextual action button per row
2. **List + Detail** — two-column: left list + right inspector, URL-driven selection
3. **Patient Chart** — patient header, sticky sub-tabs, main content area
4. **Form Page** — single-column form, sticky footer with submit/cancel
5. **Report Page** — KPI strip, controls, charts/tables, export top-right
6. **Settings Page** — left section nav, right form panel

### Workboard Template

**File:** `templates/modules/healthcare/ehr/layouts/_workboard.disyl`

```disyl
{{!-- 
  Workboard layout: filtered list of actions, one row per item.
  Usage: @include('_workboard', [
    'title' => 'Today\'s Queue',
    'subtitle' => 'My patients',
    'filters' => [...],
    'rows' => [...],
    'emptyState' => [...],
    'primaryAction' => [...],  // button in header
    'rowActions' => function($item) { ... },  // per-row
  ])
--}}

<div class="layout-workboard">
  <!-- Header -->
  <div class="page-header">
    <div class="page-header-main">
      <h1>{{ $title }}</h1>
      @if ($subtitle)
        <p class="text-sm muted">{{ $subtitle }}</p>
      @endif
    </div>
    <div class="page-header-actions">
      @if ($primaryAction)
        <button class="btn-primary" @click="{{ $primaryAction['click'] ?? 'null' }}">
          {{ $primaryAction['label'] }}
        </button>
      @endif
    </div>
  </div>

  <!-- Filter bar -->
  @if (count($filters) > 0)
    <div class="filter-bar">
      @foreach ($filters as $filter)
        <select name="{{ $filter['name'] }}" class="filter-select">
          @foreach ($filter['options'] as $opt)
            <option value="{{ $opt['value'] }}" 
              @selected($opt['value'] === ($query[$filter['name']] ?? null))>
              {{ $opt['label'] }}
            </option>
          @endforeach
        </select>
      @endforeach
      <button class="btn-ghost" @click="location.reload()">⟳ Refresh</button>
    </div>
  @endif

  <!-- Workboard rows -->
  <div class="workboard">
    @forelse ($rows as $item)
      <div class="workboard-row" data-id="{{ $item['id'] }}">
        <!-- Left side: item info -->
        <div class="workboard-content">
          @foreach ($item['fields'] as $field)
            <div class="workboard-field {{ $field['class'] ?? '' }}">
              {{ $field['value'] }}
            </div>
          @endforeach
        </div>

        <!-- Right side: actions -->
        <div class="workboard-actions">
          <button class="btn-primary" 
            @click="{{ $item['action_primary']['click'] ?? 'null' }}">
            {{ $item['action_primary']['label'] ?? 'Open' }}
          </button>
          @if ($item['action_more'])
            <button class="btn-ghost" @click="moreActions('{{ $item['id'] }}')">
              More ▾
            </button>
          @endif
        </div>
      </div>
    @empty
      <div class="empty-state">
        <div class="empty-icon">{{ $emptyState['icon'] ?? '📭' }}</div>
        <p class="empty-title">{{ $emptyState['title'] ?? 'No items' }}</p>
        @if ($emptyState['description'])
          <p class="empty-description">{{ $emptyState['description'] }}</p>
        @endif
        @if ($emptyState['cta'])
          <button class="btn-primary" @click="{{ $emptyState['cta']['click'] ?? 'null' }}">
            {{ $emptyState['cta']['label'] }}
          </button>
        @endif
      </div>
    @endforelse
  </div>
</div>
```

**CSS (Tailwind + custom):**

```css
/* Workboard */
.workboard {
  border-top: 1px solid #e2e8f0;
}

.workboard-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #e2e8f0;
  gap: 1rem;
  min-height: 56px;
  transition: background-color 0.15s;
}

.workboard-row:hover {
  background-color: #f8fafc;
}

.workboard-content {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex: 1;
  min-width: 0;
}

.workboard-field {
  display: flex;
  flex-direction: column;
  justify-content: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.workboard-field.time {
  min-width: 50px;
  font-weight: 600;
  font-size: 14px;
}

.workboard-field.status {
  min-width: 100px;
}

.workboard-field.patient-name {
  flex: 1;
  min-width: 150px;
}

.workboard-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  white-space: nowrap;
}

/* Empty state */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  color: #64748b;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

.empty-title {
  font-size: 1rem;
  font-weight: 600;
  color: #334155;
  margin-bottom: 0.5rem;
}

.empty-description {
  font-size: 0.875rem;
  margin-bottom: 1.5rem;
  max-width: 24rem;
}
```

### List + Detail Template

**File:** `templates/modules/healthcare/ehr/layouts/_list_detail.disyl`

```disyl
{{!-- 
  List + Detail layout: left list, right side panel.
  Usage: @include('_list_detail', [
    'title' => 'Patients',
    'items' => [...],
    'selectedId' => $query['selected'] ?? null,
    'renderDetail' => function($item) { ... },
    'emptyState' => [...],
  ])
--}}

<div class="layout-list-detail">
  <div class="page-header">
    <h1>{{ $title }}</h1>
  </div>

  <div class="list-detail-container">
    <!-- Left: list -->
    <div class="list-panel">
      <div class="list-items">
        @forelse ($items as $item)
          <a href="?selected={{ $item['id'] }}" 
            class="list-item @selected($item['id'] === $selectedId)">
            <div class="list-item-title">{{ $item['title'] }}</div>
            @if ($item['subtitle'])
              <div class="list-item-subtitle">{{ $item['subtitle'] }}</div>
            @endif
          </a>
        @empty
          <div class="list-empty">
            <p>{{ $emptyState['title'] ?? 'No items' }}</p>
          </div>
        @endforelse
      </div>
    </div>

    <!-- Right: detail -->
    <div class="detail-panel">
      @if ($selectedId && $selectedItem = $items->firstWhere('id', $selectedId))
        <div class="detail-content">
          {{ $renderDetail($selectedItem) }}
        </div>
      @else
        <div class="detail-empty">
          <p>Select an item to view details</p>
        </div>
      @endif
    </div>
  </div>
</div>
```

**CSS:**

```css
.list-detail-container {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 0;
  height: calc(100vh - 120px);
}

.list-panel {
  border-right: 1px solid #e2e8f0;
  overflow-y: auto;
  background: #ffffff;
}

.list-items {
  display: flex;
  flex-direction: column;
}

.list-item {
  padding: 1rem;
  border-bottom: 1px solid #f1f5f9;
  transition: background-color 0.15s;
  text-decoration: none;
  color: inherit;
}

.list-item:hover {
  background-color: #f8fafc;
}

.list-item.selected {
  background-color: #ecf0f7;
  border-left: 4px solid #0e7490;
}

.list-item-title {
  font-weight: 500;
  font-size: 14px;
  color: #1e293b;
}

.list-item-subtitle {
  font-size: 12px;
  color: #94a3b8;
  margin-top: 4px;
}

.detail-panel {
  overflow-y: auto;
  background: #ffffff;
}

.detail-content {
  padding: 2rem;
  max-width: 100%;
}

.detail-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
}
```

### Form Page Template

**File:** `templates/modules/healthcare/ehr/layouts/_form_page.disyl`

```disyl
{{!-- 
  Form page: single column form with sticky footer.
  Usage: @include('_form_page', [
    'title' => 'New Patient',
    'subtitle' => 'Enter patient demographics',
    'form' => $form,  // Disyl form helper
    'sections' => [
      ['title' => 'Contact', 'fields' => [...]],
      ['title' => 'Insurance', 'fields' => [...]],
    ],
    'submitLabel' => 'Save',
    'cancelUrl' => '/admin/ehr/patients',
  ])
--}}

<div class="layout-form-page">
  <div class="page-header">
    <h1>{{ $title }}</h1>
    @if ($subtitle)
      <p class="text-sm muted">{{ $subtitle }}</p>
    @endif
  </div>

  <form method="POST" class="form-container">
    @foreach ($sections as $section)
      <fieldset class="form-section">
        <legend>{{ $section['title'] }}</legend>
        @foreach ($section['fields'] as $field)
          <div class="form-group">
            <label for="{{ $field['name'] }}">{{ $field['label'] }}</label>
            @if ($field['type'] === 'text')
              <input type="text" id="{{ $field['name'] }}" name="{{ $field['name'] }}" 
                value="{{ old($field['name'], $field['value'] ?? '') }}" 
                class="form-input @error($field['name']) has-error @enderror" />
            @elseif ($field['type'] === 'textarea')
              <textarea id="{{ $field['name'] }}" name="{{ $field['name'] }}" 
                class="form-textarea @error($field['name']) has-error @enderror">
                {{ old($field['name'], $field['value'] ?? '') }}</textarea>
            @elseif ($field['type'] === 'select')
              <select id="{{ $field['name'] }}" name="{{ $field['name'] }}" 
                class="form-select @error($field['name']) has-error @enderror">
                @foreach ($field['options'] as $opt)
                  <option value="{{ $opt['value'] }}" 
                    @selected(old($field['name']) === $opt['value'] ?? $field['value'] === $opt['value'])>
                    {{ $opt['label'] }}
                  </option>
                @endforeach
              </select>
            @endif
            @error($field['name'])
              <p class="form-error">{{ $message }}</p>
            @enderror
            @if ($field['help'])
              <p class="form-help">{{ $field['help'] }}</p>
            @endif
          </div>
        @endforeach
      </fieldset>
    @endforeach

    <div class="form-footer">
      <a href="{{ $cancelUrl }}" class="btn-secondary">Cancel</a>
      <button type="submit" class="btn-primary">{{ $submitLabel ?? 'Save' }}</button>
    </div>
  </form>
</div>
```

**CSS:**

```css
.layout-form-page {
  max-width: 720px;
  margin: 0 auto;
}

.form-container {
  padding: 2rem 0;
}

.form-section {
  margin-bottom: 2rem;
  border: none;
  padding: 0;
}

.form-section legend {
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
  display: block;
  margin-bottom: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid #e2e8f0;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  font-weight: 500;
  font-size: 14px;
  color: #1e293b;
  margin-bottom: 0.5rem;
}

.form-input, .form-textarea, .form-select {
  display: block;
  width: 100%;
  padding: 0.625rem 0.75rem;
  font-size: 14px;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  transition: border-color 0.15s;
}

.form-input:focus, .form-textarea:focus, .form-select:focus {
  outline: none;
  border-color: #0e7490;
  box-shadow: 0 0 0 2px rgba(14, 116, 144, 0.1);
}

.form-input.has-error, .form-textarea.has-error, .form-select.has-error {
  border-color: #dc2626;
}

.form-error {
  font-size: 12px;
  color: #dc2626;
  margin-top: 0.25rem;
}

.form-help {
  font-size: 12px;
  color: #94a3b8;
  margin-top: 0.5rem;
}

.form-footer {
  display: flex;
  gap: 1rem;
  justify-content: flex-start;
  padding-top: 2rem;
  border-top: 1px solid #e2e8f0;
  position: sticky;
  bottom: 0;
  background: #ffffff;
}
```

### Implementation Steps

1. **Create `_workboard.disyl` partial** (1 hr)
   - Standard row structure: info fields + action buttons
   - Empty state with icon + CTA
   - Reusable across Today, Schedule, Queue, Visits, Results

2. **Create `_list_detail.disyl` partial** (1 hr)
   - Two-column layout with sticky position
   - URL-driven selection via `?selected=<id>`
   - Reusable across Patients, Notes, Orders, Medications, Documents

3. **Create `_form_page.disyl` partial** (45 min)
   - Single-column form, max 720px width
   - Fieldset-based section grouping
   - Sticky footer with save/cancel

4. **Create `_patient_chart.disyl` partial** (Phase 2)
   - Sticky patient header + visit strip
   - Sub-tab navigation
   - Main content area

5. **Create `_report.disyl` partial** (Phase 2)
   - KPI strip, controls, charts

6. **Create `_settings.disyl` partial** (Phase 2)
   - Left section nav, right form

### Code Locations
- New: `templates/modules/healthcare/ehr/layouts/_workboard.disyl`
- New: `templates/modules/healthcare/ehr/layouts/_list_detail.disyl`
- New: `templates/modules/healthcare/ehr/layouts/_form_page.disyl`
- Ref: [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) (main layout wrapper)

### Validation
- [ ] Each partial renders without errors
- [ ] Workboard rows display in a single row with consistent height
- [ ] List+Detail shows list on left (320px fixed) and detail on right (fluid)
- [ ] Form page is centered, max 720px, sticky footer stays at bottom on scroll
- [ ] All three are responsive at 768px (breakpoint defined)
- [ ] Empty states show icon + title + CTA
- [ ] No hardcoded content; all data passed via params

### Effort: **4–5 hours** (all three partials)

---

## IV. Standard List+Detail Layout Template

*Already covered in Section III above.* Deploy as `templates/modules/healthcare/ehr/layouts/_list_detail.disyl` for reuse by:
- Patients page (patient list left, demographics/chart-link right)
- Notes page (note list left, note content right)
- Orders page (order list left, order detail right)
- Results page (result list left, result detail right)
- Medications page (rx list left, rx detail right)
- Documents page (document list left, preview right)

**Adaptation:** Each module passes a `renderDetail` callback to customize the right panel without reimplementing the layout.

---

## V. Patient Context Header Specification

### Current State
- [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) renders a patient header only when `patient_context` is passed (lines ~10–30).
- Header shows MRN, age, sex — but *not* allergies.
- Rarely called; most pages don't pass `patient_context`.
- Allergy absence is silent; no distinction between "no known" and "not reviewed."

### Target State

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ◐ DELA CRUZ, Maria  · F · 47y · MRN 000124                  [✕ End session]│
│  ⚠ ALLERGIES: Penicillin (rash), Sulfa (anaphylaxis)                       │
│  🔒 Restricted record (break-glass access logged)                           │
│  ⏱ Active visit: Follow-up · Dr. Reyes · started 09:14 · 38 min             │
│  [Open Chart] [Vitals] [Note] [Order] [Prescribe] [Document] [Close Visit]  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Backend: `EhrPatientSession` + Capability

**File:** New file `modules/healthcare/patient-registry/session/EhrPatientSession.php`

```php
namespace Ikabud\Modules\HealthCare\PatientRegistry\Session;

class EhrPatientSession {
    public $patient_id;
    public $patient_name;
    public $patient_mrn;
    public $patient_sex;
    public $patient_age;
    public $allergies;  // array of {name, severity_code, reaction}
    public $restrictions;  // array of {type, reason, requires_justification}
    public $active_encounter_id;
    public $active_encounter_type;
    public $active_encounter_provider;
    public $active_encounter_started_at;

    public static function startSession($patient_id, $user_id, $reason = 'routine_access') {
        $patient = /* fetch from registry */;
        $allergies = /* fetch from allergies table */;
        $restrictions = /* fetch from consent table */;
        $activeEncounter = /* fetch active encounter for patient */;

        $session = new self();
        $session->patient_id = $patient_id;
        // ... populate from database

        // Audit: log chart open
        portalAuditRecord('ehr.patient.context.started', [
            'patient_id' => $patient_id,
            'user_id' => $user_id,
            'reason' => $reason,
            'timestamp' => now(),
        ]);

        // Store in session
        session(['ehr_patient_session' => $session]);

        return $session;
    }

    public static function current() {
        return session('ehr_patient_session');
    }

    public static function end($user_id) {
        $session = self::current();
        if ($session) {
            portalAuditRecord('ehr.patient.context.ended', [
                'patient_id' => $session->patient_id,
                'user_id' => $user_id,
                'duration_seconds' => now()->diffInSeconds($session->started_at),
            ]);
            session()->forget('ehr_patient_session');
        }
    }
}
```

**Capability:** Add to `modules/healthcare/patient-registry/module.json`

```json
{
  "capabilities": {
    "exposes": [
      {
        "id": "ehr.patient.context@1",
        "description": "Current patient session context (name, age, allergies, restrictions, active visit)",
        "handler": "patient_registry_cap_ehr_patient_context_1"
      }
    ]
  },
  "policy": {
    "capabilities": [
      {
        "id": "ehr.patient.context@1",
        "allow_callers": ["patient-registry", "scheduling", "clinical-notes", "orders", "results", "prescriptions", "documents", "privacy-consent", "ehr"]
      }
    ]
  }
}
```

**Handler:** In `modules/healthcare/patient-registry/helpers.php`

```php
function patient_registry_cap_ehr_patient_context_1() {
    return EhrPatientSession::current();
}
```

### Frontend: Header Partial

**File:** `templates/modules/healthcare/ehr/partials/_patient_context_header.disyl`

```disyl
{{!-- 
  Sticky patient context header. Rendered unconditionally on Clinical + Patients pages.
  Data from ehr.patient.context@1 capability.
--}}

@if ($session = ehr_patient_context())
  <div class="patient-context-header">
    <!-- Row 1: Name, ID, End session -->
    <div class="header-row identity-row">
      <div class="patient-identity">
        <span class="patient-icon">◐</span>
        <span class="patient-name">{{ strtoupper($session->patient_name) }}</span>
        <span class="patient-demographics">
          · {{ $session->patient_sex }} · {{ $session->patient_age }}y
          · MRN {{ $session->patient_mrn }}
        </span>
      </div>
      <button class="btn-ghost-sm" @click="endSession()">✕ End session</button>
    </div>

    <!-- Row 2: Allergies (red, prominent) -->
    <div class="header-row allergies-row">
      <span class="allergy-icon">⚠</span>
      @if (count($session->allergies) > 0)
        <strong>ALLERGIES:</strong>
        @foreach ($session->allergies as $allergy)
          <span class="allergy-badge severity-{{ $allergy['severity'] }}">
            {{ $allergy['name'] }} ({{ $allergy['reaction'] }})
          </span>
        @endforeach
      @else
        <span class="no-allergies-marker">No known allergies (last reviewed {{ $session->allergy_review_date }})</span>
      @endif
    </div>

    <!-- Row 3: Restrictions (if any) -->
    @if (count($session->restrictions) > 0)
      <div class="header-row restrictions-row">
        <span class="lock-icon">🔒</span>
        <strong>RESTRICTED RECORD</strong>
        @foreach ($session->restrictions as $restriction)
          <span class="restriction-badge">{{ $restriction['reason'] }}</span>
        @endforeach
        <button class="btn-ghost-sm" @click="showBreakGlassJustification()">
          [Justify access]
        </button>
      </div>
    @endif

    <!-- Row 4: Active visit (if open) -->
    @if ($session->active_encounter_id)
      <div class="header-row visit-row">
        <span class="timer-icon">⏱</span>
        <strong>Active visit:</strong> {{ $session->active_encounter_type }}
        · {{ $session->active_encounter_provider }}
        · started {{ $session->active_encounter_started_at->format('H:i') }}
        · {{ $session->active_encounter_started_at->diffInMinutes(now()) }} min
      </div>
    @endif

    <!-- Row 5: Quick actions (conditional on page context) -->
    <div class="header-actions">
      <button class="btn-secondary-sm">📋 Open Chart</button>
      @if (!$session->active_encounter_id)
        <button class="btn-secondary-sm" @click="startVisit()">+ Start Visit</button>
      @else
        <button class="btn-secondary-sm" @click="resumeNote()">✏ Resume Note</button>
        <button class="btn-secondary-sm" @click="newOrder()">+ Order</button>
        <button class="btn-secondary-sm" @click="closeVisitFlow()">✓ Close Visit</button>
      @endif
    </div>
  </div>
@endif
```

**CSS:**

```css
.patient-context-header {
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  border-bottom: 1px solid #e2e8f0;
  padding: 1rem 1.5rem;
  margin-bottom: 1.5rem;
  font-size: 13px;
  line-height: 1.4;
}

.header-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.header-row:last-of-type {
  margin-bottom: 0;
}

.patient-icon {
  font-size: 1.25rem;
}

.patient-name {
  font-weight: 700;
  color: #1e293b;
  letter-spacing: 0.05em;
}

.patient-demographics {
  color: #64748b;
  font-weight: 400;
}

.allergies-row {
  color: #dc2626;
}

.allergy-icon {
  font-weight: bold;
}

.allergy-badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 3px;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  font-weight: 500;
}

.allergy-badge.severity-anaphylaxis {
  background: #991b1b;
  color: #fff;
  font-weight: 600;
}

.no-allergies-marker {
  color: #94a3b8;
  font-style: italic;
}

.restrictions-row {
  color: #f97316;
}

.lock-icon {
  font-weight: bold;
}

.restriction-badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 3px;
  background: #fed7aa;
  border: 1px solid #fdba74;
}

.visit-row {
  color: #0369a1;
}

.timer-icon {
  font-weight: bold;
}

.header-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
  flex-wrap: wrap;
}

.btn-secondary-sm {
  padding: 0.375rem 0.75rem;
  font-size: 12px;
  /* use existing button style */
}
```

### Integration

**File:** [templates/modules/healthcare/ehr/layouts/admin.disyl](templates/modules/healthcare/ehr/layouts/admin.disyl) (or hook into middleware)

```disyl
<!-- At top of all Clinical + Patients pages -->
{{ partial('_patient_context_header') }}

<!-- Rest of page content -->
{{ $content }}
```

### Implementation Steps

1. **Create `EhrPatientSession` class** (1 hr)
   - Methods: `startSession()`, `current()`, `end()`
   - Data: patient id, name, MRN, sex, age, allergies, restrictions, active encounter
   - Audit logging on start/end

2. **Add `ehr.patient.context@1` capability** (30 min)
   - Provider returns `EhrPatientSession::current()`
   - Add to module.json, policy

3. **Create `_patient_context_header.disyl` partial** (1 hr)
   - 5-row layout: identity, allergies, restrictions, visit, actions
   - Allergy severity color-coding
   - Conditional rendering

4. **Add partial to layout** (15 min)
   - Include at top of all Clinical + Patients pages
   - Pass `@patient_context()` helper

5. **Add endpoint to start/end session** (30 min)
   - POST `/api/v1/ehr/patient-session/start/{patient_id}`
   - POST `/api/v1/ehr/patient-session/end`
   - Audit both

### Code Locations
- New: `modules/healthcare/patient-registry/session/EhrPatientSession.php`
- New: `templates/modules/healthcare/ehr/partials/_patient_context_header.disyl`
- Edit: `modules/healthcare/patient-registry/module.json` (add capability)
- Edit: `modules/healthcare/patient-registry/helpers.php` (add capability handler)
- Edit: `templates/modules/healthcare/ehr/layouts/admin.disyl` (include partial)

### Validation
- [ ] Patient context header renders on dashboard, appointments, patients, notes, orders, results
- [ ] Allergies always visible (red, prominent)
- [ ] "No known allergies" is explicit, not absent
- [ ] Restricted record shows lock icon + reason
- [ ] Active visit shows encounter type, provider, elapsed time
- [ ] End session clears context and audit-logs
- [ ] Header is sticky and visible when scrolling
- [ ] No patient context header appears on unauthenticated pages

### Effort: **4–5 hours**

---

## VI. Visit Context Header Specification

### Current State
- No persistent concept of an active visit.
- Encounter data exists but is not exposed as a navigation element.
- User must infer "what visit am I in?" from page content.

### Target State

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Visit V-2026-00489 · Follow-up (Cardiology) · Dr. Reyes · ⏱ 09:14 → ongoing │
│ Progress: ✓ Vitals  ✓ Note (draft)  • Orders  • Results pending  ○ Sign-off │
│ [Resume Note] [Add Order] [Review Results] [Close Visit ▼]                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Backend: `ehr.encounter.progress@1` Capability

**File:** Edit `modules/healthcare/encounters/module.json`

```json
{
  "capabilities": {
    "exposes": [
      {
        "id": "ehr.encounter.progress@1",
        "description": "Current encounter progress state (step completions, close readiness)",
        "handler": "encounters_cap_ehr_encounter_progress_1"
      }
    ]
  },
  "policy": {
    "capabilities": [
      {
        "id": "ehr.encounter.progress@1",
        "allow_callers": ["encounters", "scheduling", "clinical-notes", "orders", "results", "prescriptions", "documents", "ehr"]
      }
    ]
  }
}
```

**Handler:** In `modules/healthcare/encounters/helpers.php`

```php
function encounters_cap_ehr_encounter_progress_1($encounter_id) {
    $encounter = /* fetch from ehr_encounters */;
    
    return [
        'encounter_id' => $encounter->id,
        'encounter_type' => $encounter->type,  // Follow-up, Initial, Hospital admission, etc.
        'provider' => $encounter->provider_name,
        'specialty' => $encounter->specialty,
        'started_at' => $encounter->started_at,
        'status' => $encounter->status,  // in_progress, closed, etc.
        'steps' => [
            'vitals' => [
                'label' => 'Vitals',
                'complete' => !is_null($encounter->vitals_captured_at),
                'timestamp' => $encounter->vitals_captured_at,
            ],
            'note' => [
                'label' => 'Note',
                'complete' => !is_null($encounter->note_id),
                'status' => $encounter->note_signed_at ? 'signed' : 'draft',  // draft, signed
                'timestamp' => $encounter->note_signed_at ?? $encounter->note_started_at,
            ],
            'orders' => [
                'label' => 'Orders',
                'complete' => false,  // always pending as a step
                'count_open' => /* count orders for this encounter status=open */,
            ],
            'results' => [
                'label' => 'Results',
                'complete' => false,
                'count_pending' => /* count results status=pending for open orders */,
            ],
            'signoff' => [
                'label' => 'Sign-off',
                'complete' => false,
                'blockers' => [
                    'unsigned_note' => is_null($encounter->note_signed_at),
                    'open_orders' => /* count */ > 0,
                    'pending_results' => /* count */ > 0,
                ],
            ],
        ],
        'close_ready' => count($blockers) === 0,
    ];
}
```

### Frontend: Visit Progress Strip

**File:** `templates/modules/healthcare/ehr/partials/_visit_progress_strip.disyl`

```disyl
{{!-- 
  Sticky visit progress strip. Rendered below patient context on Clinical pages when visit is active.
  Data from ehr.encounter.progress@1 capability.
--}}

@if ($progress = ehr_encounter_progress($session?->active_encounter_id))
  <div class="visit-progress-strip">
    <!-- Row 1: Visit ID, type, provider, elapsed time -->
    <div class="progress-header">
      <span class="visit-id">Visit {{ $progress['encounter_id'] }}</span>
      <span class="visit-type">{{ $progress['encounter_type'] }} ({{ $progress['specialty'] }})</span>
      <span class="visit-provider">{{ $progress['provider'] }}</span>
      <span class="visit-time">⏱ {{ $progress['started_at']->format('H:i') }} → {{ $progress['status'] }}</span>
    </div>

    <!-- Row 2: Step progress pills -->
    <div class="progress-steps">
      <span class="progress-label">Progress:</span>
      @foreach ($progress['steps'] as $key => $step)
        <div class="progress-pill {{ $step['complete'] ? 'complete' : 'incomplete' }} {{ $step['status'] ?? '' }}">
          @if ($step['complete'])
            <span class="progress-icon">✓</span>
          @elseif ($step['status'] === 'draft')
            <span class="progress-icon">◒</span>
          @else
            <span class="progress-icon">•</span>
          @endif
          <span class="progress-label">{{ $step['label'] }}</span>
          @if ($step['status'])
            <span class="progress-status">({{ $step['status'] }})</span>
          @endif
          @if ($step['count_open'] ?? null)
            <span class="progress-badge">{{ $step['count_open'] }}</span>
          @endif
        </div>
      @endforeach
    </div>

    <!-- Row 3: Quick actions -->
    <div class="progress-actions">
      @if ($progress['steps']['note']['status'] === 'draft')
        <button class="btn-primary-sm" @click="resumeNote('{{ $progress['encounter_id'] }}')">
          ✏ Resume Note
        </button>
      @endif
      @if ($progress['steps']['orders']['count_open'] > 0 || $progress['steps']['orders']['complete'] === false)
        <button class="btn-secondary-sm" @click="newOrder('{{ $progress['encounter_id'] }}')">
          + Order
        </button>
      @endif
      @if ($progress['steps']['results']['count_pending'] > 0)
        <button class="btn-secondary-sm" @click="reviewResults('{{ $progress['encounter_id'] }}')">
          🔍 Review Results
        </button>
      @endif
      <button class="btn-secondary-sm" @click="closeVisitFlow('{{ $progress['encounter_id'] }}')">
        Close Visit {{ $progress['close_ready'] ? '' : '⚠' }} ▼
      </button>
    </div>
  </div>
@endif
```

**CSS:**

```css
.visit-progress-strip {
  background: #ecf0f7;
  border-bottom: 1px solid #cbd5e1;
  padding: 1rem 1.5rem;
  margin-bottom: 1.5rem;
  font-size: 13px;
}

.progress-header {
  display: flex;
  gap: 1rem;
  margin-bottom: 0.75rem;
  font-weight: 500;
  color: #1e293b;
}

.visit-id {
  font-weight: 700;
}

.visit-time {
  margin-left: auto;
  color: #64748b;
}

.progress-steps {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  flex-wrap: wrap;
}

.progress-label {
  font-weight: 600;
  color: #475569;
}

.progress-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.375rem 0.5rem;
  border-radius: 3px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  font-size: 12px;
  font-weight: 500;
}

.progress-pill.complete {
  background: #d1fae5;
  border-color: #6ee7b7;
  color: #047857;
}

.progress-pill.incomplete {
  background: #fef3c7;
  border-color: #fcd34d;
  color: #92400e;
}

.progress-pill.draft {
  background: #fee2e2;
  border-color: #fca5a5;
  color: #991b1b;
}

.progress-icon {
  font-weight: bold;
}

.progress-badge {
  background: #e2e8f0;
  padding: 0 0.25rem;
  border-radius: 2px;
  font-weight: 600;
}

.progress-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.btn-primary-sm, .btn-secondary-sm {
  padding: 0.375rem 0.75rem;
  font-size: 12px;
  white-space: nowrap;
}
```

### Integration

Include in all Clinical pages after patient context header:

```disyl
{{ partial('_visit_progress_strip') }}
```

### Implementation Steps

1. **Add `ehr.encounter.progress@1` capability** (1 hr)
   - Query encounter + related notes/orders/results
   - Compute step progress: vitals ✓, note (draft/signed), orders, results, sign-off
   - Add to module.json, policy

2. **Create `_visit_progress_strip.disyl` partial** (1 hr)
   - 3-row layout: header, step pills, quick actions
   - Color-coded pills (complete=green, draft=red, pending=amber)

3. **Add partial to layout** (15 min)
   - Include after `_patient_context_header.disyl` on Clinical pages

4. **Wire "Close Visit" action** (1 hr)
   - Open guided modal: checklist of blockers (unsigned note, open orders, pending results)
   - Require explicit acknowledgment
   - Mark encounter `status='closed'` on submit
   - Audit log

### Code Locations
- Edit: `modules/healthcare/encounters/module.json` (add capability)
- Edit: `modules/healthcare/encounters/helpers.php` (add capability handler)
- New: `templates/modules/healthcare/ehr/partials/_visit_progress_strip.disyl`
- Edit: All Clinical page templates to include partial

### Validation
- [ ] Visit progress strip appears below patient context on Clinical pages
- [ ] Step pills show correct completion state (✓, •, ○, ◒)
- [ ] Colors match semantic: complete=green, draft=red, pending=amber
- [ ] Quick actions show conditionally (Resume Note if draft, Add Order if no blockers, etc.)
- [ ] Close Visit button shows warning (⚠) if blockers present
- [ ] Close Visit opens guided modal with checklist

### Effort: **4–5 hours**

---

## VII. Status Badge System

### Current State
- Status rendered as raw strings or Tailwind classes scattered across templates.
- No consistency: "scheduled" in Appointments, "pending" in Orders, "abnormal" in Results.
- Colors are ad-hoc (Tailwind utilities).

### Target State

Unified status badge system with semantic colors + labels:

| Status | Entity | Color | Icon | Label |
|---|---|---|---|---|
| scheduled | Appointment | slate | 📅 | Scheduled |
| checked-in | Appointment | teal | ✓ | Checked In |
| roomed | Appointment | indigo | 🚪 | Roomed |
| with-provider | Appointment | indigo | 👤 | With Provider |
| no-show | Appointment | rose | ✕ | No-show |
| cancelled | Appointment | slate-2 | ✕ | Cancelled |
| — | — | — | — | — |
| draft | Note | rose | ◒ | Draft |
| signed | Note | emerald | ✓ | Signed |
| — | — | — | — | — |
| open | Order | teal | 📋 | Open |
| in-progress | Order | indigo | ⟳ | In Progress |
| resulted | Order | emerald | ✓ | Resulted |
| cancelled | Order | slate-2 | ✕ | Cancelled |
| — | — | — | — | — |
| normal | Result | emerald | ✓ | Normal |
| abnormal | Result | rose | ⚠ | Abnormal |
| critical | Result | rose | 🔴 | Critical |
| pending | Result | amber | ⏱ | Pending |

### Implementation: Disyl Partial

**File:** `templates/modules/healthcare/ehr/partials/_status_badge.disyl`

```disyl
{{!-- 
  Semantic status badge. Use instead of hardcoded status.
  
  Usage: {{ partial('_status_badge', ['status' => 'scheduled', 'entity' => 'appointment']) }}
  
  Attributes:
    status: string (e.g., 'scheduled', 'draft', 'open')
    entity: string (e.g., 'appointment', 'note', 'order', 'result')
    size: 'sm' (default) | 'lg'
    class: additional CSS classes
--}}

@php
  $map = [
    'appointment' => [
      'scheduled' => ['color' => 'slate', 'label' => 'Scheduled', 'icon' => '📅'],
      'checked-in' => ['color' => 'teal', 'label' => 'Checked In', 'icon' => '✓'],
      'roomed' => ['color' => 'indigo', 'label' => 'Roomed', 'icon' => '🚪'],
      'with-provider' => ['color' => 'indigo', 'label' => 'With Provider', 'icon' => '👤'],
      'no-show' => ['color' => 'rose', 'label' => 'No-show', 'icon' => '✕'],
      'cancelled' => ['color' => 'slate-2', 'label' => 'Cancelled', 'icon' => '✕'],
    ],
    'note' => [
      'draft' => ['color' => 'rose', 'label' => 'Draft', 'icon' => '◒'],
      'signed' => ['color' => 'emerald', 'label' => 'Signed', 'icon' => '✓'],
    ],
    'order' => [
      'open' => ['color' => 'teal', 'label' => 'Open', 'icon' => '📋'],
      'in-progress' => ['color' => 'indigo', 'label' => 'In Progress', 'icon' => '⟳'],
      'resulted' => ['color' => 'emerald', 'label' => 'Resulted', 'icon' => '✓'],
      'cancelled' => ['color' => 'slate-2', 'label' => 'Cancelled', 'icon' => '✕'],
    ],
    'result' => [
      'normal' => ['color' => 'emerald', 'label' => 'Normal', 'icon' => '✓'],
      'abnormal' => ['color' => 'rose', 'label' => 'Abnormal', 'icon' => '⚠'],
      'critical' => ['color' => 'rose', 'label' => 'Critical', 'icon' => '🔴'],
      'pending' => ['color' => 'amber', 'label' => 'Pending', 'icon' => '⏱'],
    ],
  ];

  $config = $map[$entity][$status] ?? ['color' => 'slate', 'label' => ucfirst($status), 'icon' => '•'];
  $size = $size ?? 'sm';
  $sizeClass = $size === 'lg' ? 'status-badge-lg' : 'status-badge-sm';
  $colorClass = "status-badge-{$config['color']}";
@endphp

<span class="status-badge {{ $sizeClass }} {{ $colorClass }} {{ $class ?? '' }}" 
  data-status="{{ $status }}" data-entity="{{ $entity }}">
  <span class="status-icon">{{ $config['icon'] }}</span>
  <span class="status-label">{{ $config['label'] }}</span>
</span>
```

**CSS:**

```css
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-weight: 500;
  border-radius: 3px;
  white-space: nowrap;
}

.status-badge-sm {
  padding: 0.25rem 0.5rem;
  font-size: 12px;
}

.status-badge-lg {
  padding: 0.5rem 0.75rem;
  font-size: 14px;
}

.status-icon {
  display: inline-block;
  font-weight: bold;
}

/* Color palettes */
.status-badge-slate {
  background: #e2e8f0;
  color: #334155;
}

.status-badge-teal {
  background: #ccfbf1;
  color: #0d9488;
}

.status-badge-indigo {
  background: #e0e7ff;
  color: #4338ca;
}

.status-badge-rose {
  background: #ffe4e6;
  color: #be123c;
}

.status-badge-emerald {
  background: #d1fae5;
  color: #047857;
}

.status-badge-amber {
  background: #fef3c7;
  color: #92400e;
}

.status-badge-slate-2 {
  background: #cbd5e1;
  color: #475569;
}
```

### Usage

Replace all hardcoded status strings:

```disyl
<!-- Before -->
<span class="badge badge-{{ $appointment->status }}">{{ ucfirst($appointment->status) }}</span>

<!-- After -->
{{ partial('_status_badge', ['status' => $appointment->status, 'entity' => 'appointment']) }}
```

### Implementation Steps

1. **Create `_status_badge.disyl` partial** (30 min)
   - Status → config map
   - Size and color variants
   - Icon + label output

2. **Update all pages to use partial** (2–3 hr)
   - Appointments page: replace status with partial
   - Notes page: replace draft/signed
   - Orders page: replace open/in-progress/resulted
   - Results page: replace normal/abnormal/critical/pending
   - (Can be incremental; no breaking change)

### Code Locations
- New: `templates/modules/healthcare/ehr/partials/_status_badge.disyl`
- Edit: All Clinical page templates (search/replace status rendering)

### Validation
- [ ] Status badge renders with correct color and icon
- [ ] sm/lg sizes scale appropriately
- [ ] Badge text matches expected label
- [ ] All 14 status types have configs
- [ ] New statuses can be added without code changes (just update map)

### Effort: **3–4 hours**

---

## VIII. Button / Action Hierarchy

### Current State
- Buttons use Tailwind utilities directly (`btn-primary`, `btn-secondary`) with inconsistent styling.
- No distinction between primary (do the thing), secondary (alternative), ghost (low-priority), and destructive (irreversible).
- Action placement varies per page (top-right, row-end, footer).

### Target State

Four button types, used consistently:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  PRIMARY BUTTON (solid teal, 40px tall)                                    │
│  "Save", "Submit", "Check In", "Start Note", "Order", "Sign"               │
│                                                                             │
│  SECONDARY BUTTON (white border, 40px tall)                                │
│  "Cancel", "Reschedule", "Review", "Export", "More options"                │
│                                                                             │
│  GHOST BUTTON (text-only, teal)                                            │
│  "More ▾", "Delete", "Archive", "Undo", "Learn more"                       │
│                                                                             │
│  DESTRUCTIVE BUTTON (solid red, 40px tall)                                 │
│  "Delete patient", "Discard changes", "Discontinue prescription"           │
│  (Only for irreversible actions; never for "Remove from this visit")       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

Row actions hierarchy:
  [Primary button] [More ▾ button]
    ↑                ↑
    Next action    Alternatives
    (Check In)     (Reschedule, Cancel)

Form footer hierarchy:
  [Cancel button (secondary)] [Save button (primary)]
    ↑                         ↑
    Go back                   Submit

Inline hierarchy:
  {{ partial('_status_badge', [...]) }}  [Primary] [Ghost]
    ↑                                      ↑        ↑
    Status (read-only)                    Action   Disclosure
```

### Disyl Partial: Button Component

**File:** `templates/modules/healthcare/ehr/partials/_button.disyl`

```disyl
{{!-- 
  Semantic button component. Enforces hierarchy + accessibility.
  
  Usage: {{ partial('_button', [
    'type' => 'primary',  // primary | secondary | ghost | destructive
    'label' => 'Save',
    'click' => 'saveForm()',
    'href' => '/path/to/page',  // if link, not button
    'icon' => '✓',
    'size' => 'md',  // sm | md | lg
    'disabled' => false,
    'loading' => false,
    'title' => 'Save changes',  // tooltip
  ]) }}
--}}

@php
  $typeClass = "btn-{$type ?? 'primary'}";
  $sizeClass = "btn-size-{$size ?? 'md'}";
  $isLink = isset($href);
  $tag = $isLink ? 'a' : 'button';
  $attrs = [];
  
  if (!$isLink) {
    $attrs['type'] = 'button';
  }
  if ($disabled ?? false) {
    $attrs['disabled'] = 'disabled';
  }
  if ($loading ?? false) {
    $attrs['aria-busy'] = 'true';
  }
  if ($title ?? null) {
    $attrs['title'] = $title;
  }
  if ($isLink) {
    $attrs['href'] = $href;
    if ($click ?? null) {
      $attrs['@click'] = $click;
    }
  } else {
    if ($click ?? null) {
      $attrs['@click'] = $click;
    }
  }
@endphp

<{{ $tag }}
  class="btn {{ $typeClass }} {{ $sizeClass }} {{ $class ?? '' }}"
  @foreach ($attrs as $key => $val)
    {{ $key }}="{{ $val }}"
  @endforeach
>
  @if ($loading ?? false)
    <span class="btn-loader">⟳</span>
  @else
    @if ($icon ?? null)
      <span class="btn-icon">{{ $icon }}</span>
    @endif
    <span class="btn-label">{{ $label ?? 'Button' }}</span>
  @endif
</{{ $tag }}>
```

**CSS:**

```css
/* Button base */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  font-weight: 500;
  border-radius: 3px;
  transition: all 0.15s;
  cursor: pointer;
  text-decoration: none;
  border: none;
  white-space: nowrap;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-label {
  display: inline;
}

.btn-icon {
  font-weight: bold;
}

/* Sizes */
.btn-size-sm {
  padding: 0.375rem 0.75rem;
  font-size: 12px;
  min-height: 32px;
}

.btn-size-md {
  padding: 0.625rem 1rem;
  font-size: 14px;
  min-height: 40px;
}

.btn-size-lg {
  padding: 0.75rem 1.5rem;
  font-size: 16px;
  min-height: 48px;
}

/* Primary: solid teal */
.btn-primary {
  background: #0d9488;
  color: #ffffff;
}

.btn-primary:hover:not(:disabled) {
  background: #0f766e;
}

.btn-primary:focus-visible {
  outline: 2px solid #0d9488;
  outline-offset: 2px;
}

/* Secondary: white border */
.btn-secondary {
  background: #ffffff;
  border: 1px solid #cbd5e1;
  color: #1e293b;
}

.btn-secondary:hover:not(:disabled) {
  background: #f8fafc;
  border-color: #94a3b8;
}

.btn-secondary:focus-visible {
  outline: 2px solid #cbd5e1;
  outline-offset: 2px;
}

/* Ghost: text-only teal */
.btn-ghost {
  background: transparent;
  color: #0d9488;
  border: none;
  padding: 0.375rem;
}

.btn-ghost:hover:not(:disabled) {
  background: #f0fdfa;
}

.btn-ghost:focus-visible {
  outline: 2px solid #0d9488;
  outline-offset: 2px;
}

/* Destructive: solid red */
.btn-destructive {
  background: #dc2626;
  color: #ffffff;
  border: none;
}

.btn-destructive:hover:not(:disabled) {
  background: #b91c1c;
}

.btn-destructive:focus-visible {
  outline: 2px solid #dc2626;
  outline-offset: 2px;
}

/* Loading state */
.btn[aria-busy="true"] {
  opacity: 0.8;
}

.btn-loader {
  display: inline-block;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
```

### Design Principles

1. **One primary action per row / form.** The most important action is primary.
2. **Alternatives live in "More ▾".** Reschedule, Cancel, Delete are secondary or ghost, accessed via disclosure.
3. **Destructive is rare.** Use only for truly irreversible actions (Delete patient, Discard unsaved changes). "Remove from visit" is secondary, not destructive.
4. **Ghost is disclosure / low-priority.** "More ▾", "Learn more", inline edits.
5. **Accessibility first.** Focus ring always visible. Icons + labels. Disabled state is obvious.

### Implementation Steps

1. **Create `_button.disyl` component** (45 min)
   - Type (primary/secondary/ghost/destructive)
   - Size (sm/md/lg)
   - Icon support
   - Loading state
   - Accessibility (title, aria-busy)

2. **Create global button CSS** (30 min)
   - Color palette (teal, white-border, red)
   - Hover/focus states
   - Animation (spin loader)

3. **Replace hardcoded buttons in templates** (2–3 hr)
   - Appointments page: row actions, header button
   - Patient Registry: primary + more actions
   - Forms: footer submit/cancel
   - (Incremental; no breaking change)

### Code Locations
- New: `templates/modules/healthcare/ehr/partials/_button.disyl`
- Global CSS: `public/css/ehr-buttons.css` or inline in layout

### Validation
- [ ] Primary button renders solid teal, 40px tall
- [ ] Secondary button renders white border, 40px tall
- [ ] Ghost button renders text-only
- [ ] Destructive button renders solid red
- [ ] Hover/focus states are visible
- [ ] Loading state shows spinner
- [ ] Disabled state is obvious (grayed out, no cursor)
- [ ] Size variants (sm/md/lg) render correctly

### Effort: **3–4 hours**

---

## IX. Page-by-Page Change Checklist

### Template

For each page below, follow this checklist to implement the new layout, context headers, and components:

```
Page: [Name]
Current location: [file path]
Target layout: [Workboard | List+Detail | Form | Report | Settings]

[ ] 1. Update page header (title, breadcrumb if needed)
[ ] 2. Replace status badges with _status_badge partial
[ ] 3. Replace buttons with _button component
[ ] 4. Add patient context header (if Clinical/Patients page)
[ ] 5. Add visit progress strip (if Clinical page with active visit)
[ ] 6. Replace list/table with target layout (_workboard or _list_detail)
[ ] 7. Implement empty states (icon + title + CTA)
[ ] 8. Add loading states (skeleton loaders or spinners)
[ ] 9. Update filter bar styling (if needed)
[ ] 10. Test at 768px (tablet), 1024px (desktop), 1440px (full)
[ ] 11. Test keyboard nav (Tab, Enter, Escape)
[ ] 12. Accessibility check: focus rings, color contrast, labels
[ ] 13. Update inline documentation / comments
[ ] 14. Verify no console errors
```

### Pages to Refactor (Priority Order)

#### **Phase 1 — Critical Path (Weeks 1–2)**

1. **Dashboard → Today** (High impact, no backend changes)
   - Location: [templates/modules/healthcare/ehr/admin/dashboard.disyl](templates/modules/healthcare/ehr/admin/dashboard.disyl)
   - Layout: Custom (KPI strip + workboard + inbox)
   - Effort: 6–8 hr
   - Validation: KPIs load, queue shows today's appointments, inbox links to sources

2. **Appointments → Schedule** (High impact, mostly existing structure)
   - Location: [templates/modules/healthcare/scheduling/admin/index.disyl](templates/modules/healthcare/scheduling/admin/index.disyl)
   - Layout: Workboard
   - Changes: Replace table with workboard rows, move form to modal/slide-in
   - Effort: 4–5 hr
   - Validation: Appointments render in time order, row actions work (Check In, Room, etc.)

3. **Sidebar nav regrouping + label map** (No page changes, shell-side)
   - Location: [modules/healthcare/ehr/helpers.php](modules/healthcare/ehr/helpers.php)
   - Effort: 2–3 hr
   - Validation: 6 groups visible, System hidden for non-admin, labels match map

#### **Phase 1b — Cohesion (Weeks 2–3)**

4. **Patient Registry → Patients** (High impact, new chart entry point)
   - Location: [templates/modules/healthcare/patient-registry/admin/index.disyl](templates/modules/healthcare/patient-registry/admin/index.disyl)
   - Layout: List+Detail
   - Changes: Add patient context header, Open Chart CTA, side detail panel
   - Effort: 5–6 hr
   - Validation: Patient context header renders, chart link works, left list / right detail

5. **Dashboard KPI / Inbox build** (Already covered above)
   - Components: KPI tiles, inbox count links, quick actions
   - Effort: 6–8 hr
   - Validation: KPIs update, inbox counts link to worklists

#### **Phase 2 — Clinical Context (Weeks 3–4)**

6. **Encounters → Visits** (Add active/archived segmentation)
   - Location: [templates/modules/healthcare/encounters/admin/list.disyl](templates/modules/healthcare/encounters/admin/list.disyl)
   - Layout: Workboard with Active/Today/Recent segments
   - Changes: Segment by status, add resume/close actions
   - Effort: 3–4 hr

7. **Clinical Notes** (Add patient+visit context, draft/signed styling)
   - Location: [templates/modules/healthcare/clinical-notes/admin/list.disyl](templates/modules/healthcare/clinical-notes/admin/list.disyl)
   - Layout: List+Detail or Workboard (depends on module design)
   - Changes: Patient context header, visit context strip, draft vs signed visual
   - Effort: 4–5 hr

8. **Orders** (Add lifecycle grouping, results link)
   - Location: [templates/modules/healthcare/orders/admin/index.disyl](templates/modules/healthcare/orders/admin/index.disyl)
   - Layout: Workboard grouped by status (Pending · In Progress · Resulted)
   - Effort: 3–4 hr

9. **Results** (Add triage grouping, critical acknowledgment flow)
   - Location: [templates/modules/healthcare/results/admin/index.disyl](templates/modules/healthcare/results/admin/index.disyl)
   - Layout: Triage workboard (Critical · Abnormal · Pending · Reviewed)
   - Changes: Status badge (critical=red, abnormal=rose), hard acknowledgment modal
   - Effort: 4–5 hr

10. **Prescriptions → Medications** (Rename, card layout)
    - Location: [templates/modules/healthcare/prescriptions/admin/list.disyl](templates/modules/healthcare/prescriptions/admin/list.disyl)
    - Layout: Card grid or Workboard
    - Changes: Rename in nav, allergy-clash warning banner
    - Effort: 3–4 hr

#### **Phase 2b — Supporting Pages (Weeks 4–5)**

11. **Documents** (Add file preview, upload)
    - Location: [templates/modules/healthcare/documents/admin/index.disyl](templates/modules/healthcare/documents/admin/index.disyl)
    - Layout: Workboard or card grid
    - Effort: 3–4 hr

12. **Consent** (Add consent capture form in chart, global log)
    - Location: [templates/modules/healthcare/privacy-consent/admin/](templates/modules/healthcare/privacy-consent/admin/)
    - Layout: Form (per-patient capture) + Workboard (global log)
    - Effort: 4–5 hr

13. **Audit Trail → Access Activity** (Improve search/filter, human-readable events)
    - Location: [templates/modules/healthcare/audit/admin/](templates/modules/healthcare/audit/admin/)
    - Layout: Workboard with search + filters
    - Changes: Hide JSON, show human-readable event labels, add break-glass filter
    - Effort: 4–5 hr

#### **Phase 3 — Admin + Patient Portal (Weeks 5–6)**

14. **Billing Queue** (Rename, worklist format)
    - Location: [templates/modules/healthcare/billing/admin/signals.disyl](templates/modules/healthcare/billing/admin/signals.disyl)
    - Layout: Workboard of closed-not-billed encounters
    - Effort: 2–3 hr

15. **Portal Access** (Rename, account worklist + reschedule inbox)
    - Location: [templates/modules/healthcare/patient-portal/admin/](templates/modules/healthcare/patient-portal/admin/)
    - Layout: Workboard
    - Effort: 2–3 hr

16. **Insights** (Unify Operations / Compliance / CDS, report tabs)
    - Location: [templates/modules/healthcare/analytics-cds/admin/](templates/modules/healthcare/analytics-cds/admin/)
    - Layout: Report with tab nav
    - Effort: 3–4 hr

17. **Patient Portal patient-side** (Typography, plain language, mobile)
    - Location: [templates/modules/healthcare/patient-portal/patient/](templates/modules/healthcare/patient-portal/patient/)
    - Changes: Font size, spacing, next appointment hero, results plain-language
    - Effort: 4–6 hr
    - Validation: Test on 360px mobile, 768px tablet, 1024px desktop

### Summary Table

| Page | Phase | Status | Effort | Dependencies |
|---|---|---|---|---|
| Today (Dashboard) | 1 | ✅ done | 6–8h | New dashboard handler |
| Schedule (Appointments) | 1 | ✅ done | 4–5h | Status badge, button hierarchy |
| Sidebar nav | 1 | ✅ done | 2–3h | None (shell) |
| Patients (Registry) | 1b | ✅ done | 5–6h | Patient context header |
| Visits (Encounters) | 2 | ✅ done | 3–4h | Visit context strip + active/today/recent segments |
| Notes (Clinical) | 2 | ✅ done | 4–5h | Status badge precompute |
| Orders | 2 | ✅ done | 3–4h | Status badge + KPI strip |
| Results | 2 | ✅ done | 4–5h | Status badge + triage strip |
| Medications (Rx) | 2 | ✅ done | 3–4h | Rename + status badge |
| Documents | 2b | ✅ done | 3–4h | Workboard sensitivity bucket strip |
| Consent | 2b | ✅ done | 4–5h | Status bucket strip (granted/expired/revoked/break-glass) |
| Access Activity (Audit) | 2b | ✅ done | 4–5h | Search + break-glass filter + human-readable events |
| Billing Queue | 3 | ✅ done | 2–3h | Renamed (workboard pending) |
| Portal Access | 3 | ✅ done | 2–3h | Renamed (workboard pending) |
| Insights (Analytics) | 3 | ✅ done | 3–4h | Tab nav unifies CDS / Clinic activity / Privacy & audit |
| Portal (patient-side) | 3 | ✅ done | 4–6h | Typography baseline, touch targets, focus rings |
| Role-aware nav | X | ✅ done | — | Per-role allow-list + route-level enforcement |
| — | — | — | **59–78h** | — |

*Total effort: ~2–2.5 weeks for one developer working full-time.*

---

## X. Developer Acceptance Criteria

### Cohesion Validation

- [ ] **Unified mental model.** Opening any Clinical page, the user sees a patient header (name, MRN, allergies, restrictions) and a visit context strip (if a visit is active). They never wonder "who am I documenting for?"

- [ ] **One sidebar.** Today, Patients, Clinical, Governance, Operations, System (admin-only). No nested modules or duplicate items. Sidebar is the first place a user looks to find a task.

- [ ] **Persistent labels.** Appointments = Schedule, Encounters = Visits, Prescriptions = Medications, etc. Terminology is role-appropriate: clinicians see "patient," "visit," "note"; admins see "system," "settings."

- [ ] **Role-based UX.** Logged in as receptionist, I see Schedule, Patients, Today, and nothing else. Logged in as MD, I see Clinical + Today + Patients + Insights. Logged in as auditor, I see only Audit Trail. Same sidebar code, different visibility.

- [ ] **Workboard rows are standard.** Every page with a list of items uses the workboard layout: time/status | identity | data | action button. No two pages have different row structures.

- [ ] **Forms are single-column.** Every form page is ≤720px wide, centered, single-column fieldsets, sticky footer with save/cancel.

- [ ] **Empty states are present everywhere.** Every list page that can be empty shows an icon + title + CTA. No blank tables with column headers and no data.

- [ ] **Status is semantic.** Scheduled/checked-in/roomed/no-show (appointments), draft/signed (notes), open/in-progress/resulted (orders), normal/abnormal/critical (results). Colors match across all pages: teal=active, amber=pending, rose=alert, emerald=complete.

- [ ] **Buttons have hierarchy.** One primary action per row / form. "More ▾" discloses alternatives. Destructive is red and reserved for irreversible acts.

- [ ] **Patient context header always shows allergies.** Prominently, in red, never silent. "No known allergies (last reviewed 2026-05-01)" is explicit.

- [ ] **Active visit is always visible.** On Clinical pages, a visit-progress strip shows what's done (✓), what's draft (◒), what's pending (•). Clinician never has to guess where they are in a visit.

- [ ] **Accessibility is enforced.** Every page has focus rings, 4.5:1 color contrast on text, keyboard nav (Tab through all interactive elements), and ARIA labels on dynamic content.

### Performance Validation

- [ ] **Dashboard loads in ≤2s.** KPI counts, queue, and inbox all render without waterfall requests.

- [ ] **Page transitions are snappy.** Clicking a nav item or a row item navigates in ≤500ms; no page reload if not needed.

- [ ] **Modals don't block interaction.** If a modal is open (e.g., "Close Visit" checklist), the background page is dimmed but visible (do not scroll-lock unless on mobile).

### Testing Validation

- [ ] **Lighthouse score ≥90** on Performance, Accessibility, Best Practices for all refactored pages.

- [ ] **Cross-browser tested** on Chrome 120+, Firefox 121+, Safari 17+.

- [ ] **Responsive tested** at 360px (mobile), 768px (tablet), 1024px (desktop), 1440px (full).

- [ ] **Keyboard nav smoke test.** Tab through entire page; all buttons, links, and form fields are reachable. Escape closes modals.

- [ ] **Screen reader tested** (VoiceOver on macOS, NVDA on Windows) on at least one page per layout archetype.

### QA Checklist — Before Merge

- [ ] All 16 page refactors are complete and deployed to staging.

- [ ] Manual click-through of each page; no console errors.

- [ ] Patient context header renders on ≥10 Clinical/Patients pages with real patient data.

- [ ] Visit progress strip shows correct step states (✓, ◒, •, ○) on ≥3 active visits.

- [ ] Status badges are correctly colored across all entity types (appointment, note, order, result).

- [ ] Empty states are present and clickable on all list pages (test by filtering to 0 results).

- [ ] Sidebar groups are hidden/shown correctly for receptionist, nurse, MD, lab, billing, admin, auditor roles.

- [ ] "Find patient" search works and links to patient chart (when chart is ready in Phase 2).

- [ ] Dashboard KPI counts match source data (manual spot-check).

- [ ] Close Visit flow opens modal, shows blockers, and closes encounter on submit.

- [ ] Destructive buttons (Delete patient, Discard changes) show a confirmation dialog before action.

- [ ] All Disyl templates lint clean (`php -l` on generated PHP; no syntax errors).

- [ ] No regression in existing workflows (old links still work, old data is not lost).

### Documentation Validation

- [ ] README updated with new page structure (§IX page list).

- [ ] Each layout archetype has example usage in [docs/ehr/implementation-plan.md](docs/ehr/implementation-plan.md) (already done).

- [ ] Design tokens documented in [docs/ehr/system-design-and-architecture-plan.md](docs/ehr/system-design-and-architecture-plan.md) §M.

- [ ] Capability `ehr.patient.context@1` and `ehr.encounter.progress@1` are documented in module.json with examples.

- [ ] New partials documented with usage examples in comments.

---

## Implementation Phases & Timeline

### Phase 1: Cohesion Fixes (Week 1–2)
- Sidebar nav regrouping + label map
- Dashboard rebuild (Today)
- Appointments workboard (Schedule)
- Status badge system
- Button hierarchy

**Deliverable:** The shell looks and feels unified; user lands on a clinical command center instead of a module launcher.

### Phase 1b: Context Headers (Week 2–3)
- Patient context header (`EhrPatientSession` + capability)
- Visit progress strip (`ehr.encounter.progress@1`)
- Patient Registry (Patients page)

**Deliverable:** Patient and visit are persistent first-class concepts; every Clinical page shows them.

### Phase 2: Clinical Pages (Week 3–5)
- Encounters → Visits
- Clinical Notes (with draft/signed styling)
- Orders (with lifecycle grouping)
- Results (with triage + critical acknowledgment)
- Prescriptions → Medications

**Deliverable:** Clinical workflow is cohesive; user navigates *within* a patient/visit, not *between* module pages.

### Phase 2b: Supporting Pages (Week 4–5)
- Documents, Consent, Access Activity
- Billing Queue, Portal Access

**Deliverable:** All Clinical pages follow the same layout / context / styling rules.

### Phase 3: Polish + Portal (Week 5–6)
- Insights (unify Reports)
- Patient portal typography + mobile
- Accessibility audit
- Responsive design at 360px / 768px / 1440px

**Deliverable:** The EHR is a cohesive product, not a collection of modules. Patient-side is simple and role-appropriate.

---

## Success Metrics

After implementation, measure:

1. **Clinician time to complete a task.** Baseline: 3 minutes to "open a patient and write a note." Target: 2 minutes (30% faster due to removed module-switching friction).

2. **Error rate on wrong-patient access.** Baseline: Track from audit logs. Target: 0 incidents (persistent patient context prevents mis-attribution).

3. **Feature adoption.** Baseline: % of users using each module. Target: >90% of Clinical users interact with ≥4 modules per session (vs. today's ~2).

4. **Page load time.** Baseline: measure per page. Target: all Clinical pages <2s p95 load time.

5. **User satisfaction.** Baseline: NPS / qualitative feedback. Target: "feels like one product" is the majority sentiment.

---

## Notes

- **This plan is incremental.** No existing workflows break. Old links still work. The refactor is a replacement strategy: as pages are touched, they move to the new layout system.
- **Disyl is capable.** All partial logic can be expressed in Disyl without dropping to PHP.
- **Accessibility is a first-class requirement.** Keyboard nav, focus rings, and screen reader compat must pass QA *before* merge.
- **Phase 1 + 1b is a 3-week sprint.** This is the highest-leverage work; ship it first to unblock clinical workflows.
- **Patient and Visit context are architectural keystones.** Once those are in, the rest of the cohesion work is mechanical.

---

## References

- [docs/ehr/system-design-and-architecture-plan.md](docs/ehr/system-design-and-architecture-plan.md) — conceptual foundation
- [docs/ehr/roadmap.md](docs/ehr/roadmap.md) — Phase 8: EHR Cohesion & Workspace Spine
- [.github/copilot-instructions.md](.github/copilot-instructions.md) — mandatory product review stance

---

**Approved for implementation: May 2026**

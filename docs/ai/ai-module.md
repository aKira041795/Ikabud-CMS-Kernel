# AI Module — Design Document

⚠️ **NOTE (2026-07-07):** This document describes a target architecture for AI-driven trigger suggestion. Current implementation status:
- `modules/ai/` — LLM provider helpers (OpenAI, Gemini, Groq, Mistral, Ollama, OpenRouter, Cerebras) ✅
- `modules/ai-orchestrator/` — External service module exposing `ai.summarize@1`, `ai.draft@1`, `ai.complete@1`, `ai.analyze@1` ✅
- `ai.capability.suggest@1` — **NOT YET IMPLEMENTED** (not declared in either module's manifest)
- Kernel event trigger infrastructure (`kernel_event_triggers` table, `kernelEmitEvent()`) — ✅ EXISTS
- AI suggestion loop (AI suggests triggers → admin confirms → kernel enforces) — **PARTIALLY IMPLEMENTED**

**Status**: Shipped — Phase 6 AI-Safe DiSyL Blocks complete (see [kernel-os-disyl-roadmap-status.md](../kernel/kernel-os-disyl-roadmap-status.md#phase-6--ai-safe-disyl-blocks-)). AI Policy engine, `ikb_ai_summary`, `ikb_ai_assist`, CMS AI content automation all delivered.  
**Author**: Cascade (AI pair programmer)  
**Created**: 2026-03-05  
**Last updated**: June 2026  
**Related docs**: `docs/kernel/roadmap.md`, `docs/kernel/module-development-guide.md`

> **Core Principle**: Kernel owns. AI suggests. Kernel enforces. Modules execute.

---

## 1. Problem Statement

Currently, wiring a capability to a module event (a "trigger") requires several manual steps:

1. Add a setting entry in the module's `gm_settings` table (e.g. `sms_notify_guidance_booking_created = 1`)
2. Add a setting key in `guidanceGetAllSettings()` defaults
3. Add toggle + template fields in the module's Settings UI
4. Hardcode the event key in `guidanceNotifySms()` to look up that setting
5. Repeat for every module and every new capability

This is **module-local, not kernel-aware**. There is no central view of what triggers exist, no shared schema, and no way for the system to suggest or validate wiring automatically.

**Goal**: Replace this pattern with a kernel-owned trigger layer, and optionally surface an AI module that can introspect the system and suggest trigger configurations when setting up or extending a module.

---

## 2. Architectural Principle

```
Kernel owns. AI suggests. Kernel enforces. Modules execute.
```

| Layer | Role | Can veto? |
|---|---|---|
| **Kernel** | Owns `kernel_event_triggers` table; enforces whether a trigger fires | Yes — kernel is the single source of truth |
| **AI module** | Reads kernel registry + module manifests; produces suggestions for admin review | No — AI only proposes, never writes directly |
| **Admin** | Reviews and confirms AI suggestions; edits triggers via kernel UI | Yes — human in the loop before anything is applied |
| **Module** | Calls `kernelTriggerEnabled()` / `kernelTriggerTemplate()` at runtime; executes the capability if permitted | No — module defers entirely to kernel decision |

### Invariants

- **Modules never own trigger state.** A module cannot enable or disable its own triggers at runtime — only the kernel (via admin) can.
- **AI never writes to `kernel_event_triggers` directly.** Its capability returns a suggestion payload; the admin confirms before `kernelTriggerSave()` is called.
- **Kernel enforces even without AI.** The AI module is additive — removing it never breaks trigger enforcement.
- **Triggers survive module reinstall.** Because `kernel_event_triggers` is kernel-owned, uninstalling or disabling a module does not destroy its trigger config.

---

## 3. Concept: "Self-Aware" Capability Wiring

```
Today (manual):
  Module dev → hardcodes event key → reads gm_settings → calls capability

Proposed (kernel-aware):
  Kernel owns trigger table
       ↓
  AI suggests wiring for a module (admin reviews)
       ↓
  Admin confirms → Kernel persists to kernel_event_triggers
       ↓
  Module fires: kernelTriggerEnabled('guidance.booking.created', 'sms.send@1')
  Kernel returns: enabled? template? provider override?
  Module calls capability with resolved config
```

Two independent deliverables:

| Deliverable | What it solves | AI required? |
|---|---|---|
| **`kernel_event_triggers` table + helpers** | Central trigger store, replaces per-module settings booleans | No |
| **`modules/ai/` module** | Introspects kernel registry, suggests trigger configs via LLM | Yes |

They are designed to work together but can be built and shipped independently.

---

## 4. Kernel Event Triggers (Step 1 — No AI Needed)

### 4.1 Database Schema

Two tables are created by the kernel migration:

#### `kernel_events` — Event Registry

This table caches the declared events from all module manifests. The kernel loads `events[]` from each enabled module's `module.json` once at install/update and persists them here, enabling runtime introspection, API queries, and AI prompting without reading the filesystem.

```sql
CREATE TABLE IF NOT EXISTS `kernel_events` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`         VARCHAR(100) NOT NULL,          -- module that fires this event
    `event_key`      VARCHAR(255) NOT NULL,          -- e.g. 'guidance.booking.created'
    `description`    TEXT DEFAULT NULL,
    `available_vars` JSON DEFAULT NULL,              -- e.g. ["appointment_id","student_name"]
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_event` (`module`, `event_key`),
    KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Why this matters**: Without this table, the kernel cannot list events, validate triggers, or expose them through an API without parsing the filesystem. The kernel loads `module.json events[]` once and caches them here — modules register events just like they register capabilities.

#### `kernel_event_triggers` — Trigger Registry

```sql
CREATE TABLE IF NOT EXISTS `kernel_event_triggers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`          VARCHAR(100) NOT NULL,          -- owning module id, e.g. 'guidance'
    `event_key`       VARCHAR(255) NOT NULL,          -- e.g. 'guidance.booking.created'
    `capability_id`   VARCHAR(255) NOT NULL,          -- e.g. 'sms.send@1'
    `provider`        VARCHAR(100) DEFAULT NULL,      -- optional: pin to specific provider
    `is_enabled`      TINYINT(1) NOT NULL DEFAULT 1,
    `priority`        INT NOT NULL DEFAULT 100,       -- execution order (lower = earlier)
    `template`        TEXT DEFAULT NULL,              -- optional message template with {vars}
    `max_per_minute`  SMALLINT UNSIGNED DEFAULT NULL, -- rate limit: max fires per minute (NULL = unlimited)
    `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0, -- retry attempts on capability failure
    `timeout_ms`      INT UNSIGNED NOT NULL DEFAULT 5000,  -- max capability execution time
    `meta`            JSON DEFAULT NULL,              -- extensible: extra payload fields, mode override
    `updated_by`      INT DEFAULT NULL,               -- kernel user id who last changed this
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_event_cap` (`module`, `event_key`, `capability_id`),
    KEY `idx_module` (`module`),
    KEY `idx_event_key` (`event_key`),
    KEY `idx_enabled_priority` (`is_enabled`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key design decisions:**
- `UNIQUE (module, event_key, capability_id)` — one row per event+capability pair per module; no duplicates
- `priority` — execution order when multiple capabilities are wired to the same event (e.g. SMS first, audit second, analytics third)
- `template` — stored in the kernel, not per-module settings; any module can use `{var}` substitution
- `max_per_minute` / `retry_count` / `timeout_ms` — first-class rate-limit and resilience fields; protects against event storms and SMS spam
- `meta` JSON — extensible for future: call mode override, payload mapping
- `updated_by` — audit trail for who changed a trigger (links to kernel users table)

### 4.2 Kernel Helper Functions

These live in `kernel/EventTriggers.php`. This file is auto-required by `bootstrap.php` — no module needs to include it.

#### `kernelEmitEvent()` — The Canonical Emission Function

This is the **most important addition**. Modules call this instead of `app()->events()->fire()`. The kernel intercepts the emission, looks up active triggers in `kernel_event_triggers`, and dispatches the appropriate capability calls. **Modules do not need to know triggers exist.**

```php
/**
 * Emit a module event through the kernel trigger system.
 *
 * Flow:
 *   Module → kernelEmitEvent() → Kernel EventBus + Trigger lookup
 *                                       ↓
 *                             Active triggers (sorted by priority)
 *                                       ↓
 *                             Capability calls (sms.send@1, audit, etc.)
 *
 * This is the only emission path modules should use.
 * It fires the kernel EventBus (for module-to-module listeners) AND
 * dispatches all configured capability triggers automatically.
 */
function kernelEmitEvent(string $eventKey, array $payload = [], string $module = ''): void
{
    // 1. Fire the kernel EventBus (module-to-module listeners continue to work)
    app()->events()->fire($eventKey, $payload, $module);

    // 2. Dispatch capability triggers
    try {
        $stmt = app()->db()->prepare(
            "SELECT * FROM kernel_event_triggers
             WHERE event_key = ? AND is_enabled = 1
             ORDER BY priority ASC"
        );
        $stmt->execute([$eventKey]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        write_log("kernelEmitEvent: trigger lookup failed for '{$eventKey}': " . $e->getMessage(), 'error');
        return;
    }

    foreach ($triggers as $trigger) {
        $capId    = (string)($trigger['capability_id'] ?? '');
        $template = $trigger['template'] ?? null;
        $meta     = isset($trigger['meta']) ? (json_decode((string)$trigger['meta'], true) ?? []) : [];

        if ($capId === '') continue;

        // Rate limit check (if configured)
        $maxPerMin = isset($trigger['max_per_minute']) ? (int)$trigger['max_per_minute'] : null;
        if ($maxPerMin !== null && $maxPerMin > 0) {
            // Basic in-memory guard — a proper implementation uses the rate_limits table
            // Placeholder: kernel v2 will wire this to the kernel rate limiter
        }

        // Build capability payload: merge event payload + template + meta overrides
        $capPayload = array_merge($payload, $meta);
        if ($template !== null) {
            $capPayload['_template'] = $template;
        }
        $capPayload['trigger_event']  = $eventKey;
        $capPayload['trigger_ref_id'] = (string)($payload['appointment_id'] ?? $payload['id'] ?? '');

        try {
            app()->cap()->call($capId, $capPayload, ['caller' => $module ?: '_kernel']);
        } catch (Throwable $e) {
            write_log("kernelEmitEvent: capability '{$capId}' failed for event '{$eventKey}': " . $e->getMessage(), 'error', [
                'event'      => $eventKey,
                'capability' => $capId,
                'module'     => $module,
            ]);
            // Continue — one failed trigger never blocks the others
        }
    }
}
```

#### Trigger Query Helpers

```php
/**
 * Check if a trigger is enabled for a given module event + capability pair.
 * Defaults to true (enabled) if the row does not exist yet (opt-out model).
 */
function kernelTriggerEnabled(string $eventKey, string $capabilityId): bool
{
    try {
        $stmt = app()->db()->prepare(
            "SELECT is_enabled FROM kernel_event_triggers
             WHERE event_key = ? AND capability_id = ? LIMIT 1"
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return true; // not configured = enabled by default (opt-out model)
        }
        return (bool)(int)$row;
    } catch (Throwable $e) {
        return true; // fail open: never silently block a trigger on DB error
    }
}

/**
 * Get the template string for a trigger, or null if not configured.
 */
function kernelTriggerTemplate(string $eventKey, string $capabilityId): ?string
{
    try {
        $stmt = app()->db()->prepare(
            "SELECT template FROM kernel_event_triggers
             WHERE event_key = ? AND capability_id = ? AND is_enabled = 1 LIMIT 1"
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $raw = $stmt->fetchColumn();
        return ($raw !== false && $raw !== null && trim((string)$raw) !== '')
            ? (string)$raw
            : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get full trigger config row (enabled, template, meta, provider).
 */
function kernelTriggerConfig(string $eventKey, string $capabilityId): ?array
{
    try {
        $stmt = app()->db()->prepare(
            "SELECT * FROM kernel_event_triggers
             WHERE event_key = ? AND capability_id = ? LIMIT 1"
        );
        $stmt->execute([$eventKey, $capabilityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Upsert a trigger row. Used by admin UI and AI module suggestions.
 */
function kernelTriggerSave(
    string $module,
    string $eventKey,
    string $capabilityId,
    bool $isEnabled,
    ?string $template = null,
    ?array $meta = null,
    ?int $updatedBy = null
): bool {
    try {
        $stmt = app()->db()->prepare(
            "INSERT INTO kernel_event_triggers
                 (module, event_key, capability_id, is_enabled, template, meta, updated_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 is_enabled = VALUES(is_enabled),
                 template   = VALUES(template),
                 meta       = VALUES(meta),
                 updated_by = VALUES(updated_by),
                 updated_at = NOW()"
        );
        $stmt->execute([
            $module,
            $eventKey,
            $capabilityId,
            (int)$isEnabled,
            $template,
            $meta !== null ? json_encode($meta) : null,
            $updatedBy,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
```

### 4.3 How Modules Use It

After this is in place, a module like Guidance replaces its per-module settings calls:

**Before (per-module, `gm_settings`-based):**
```php
function guidanceNotifySms(string $to, string $event, array $data = []): ?array
{
    $enabled = guidanceGetSetting('sms_notify_guidance_booking_created', '1');
    if ($enabled !== '1') { return null; }

    $tpl = guidanceGetSetting('sms_tpl_guidance_booking_created', null);
    // ...
}
```

**After (kernel-aware):**
```php
function guidanceNotifySms(string $to, string $event, array $data = []): ?array
{
    if (!kernelTriggerEnabled($event, 'sms.send@1')) {
        return null;
    }

    $tpl = kernelTriggerTemplate($event, 'sms.send@1');
    // falls back to hardcoded default if $tpl is null
    // ...
}
```

With `kernelEmitEvent()`, this simplifies even further — modules don't call `guidanceNotifySms()` at all. They emit the event and the kernel handles dispatch:

```php
// In guidance handler, after creating a booking:
kernelEmitEvent('guidance.booking.created', [
    'appointment_id' => $appointmentId,
    'student_name'   => $studentName,
    'student_mobile' => $mobile,
], 'guidance');
// No SMS code here. Kernel dispatches sms.send@1 if triggered.
```

This is **complete decoupling** — Guidance no longer needs to know SMS exists. This works identically for `daily-ledger`, `ticketing`, or any future module.

### 4.4 Seed Data (Guidance module migration)

When the kernel tables are created, seed the event registry and existing Guidance SMS triggers:

```sql
-- Register Guidance events in kernel_events
INSERT INTO kernel_events (module, event_key, description, available_vars) VALUES
    ('guidance', 'guidance.booking.created',
     'Fired when a student submits a public booking request.',
     '["appointment_id","student_name","student_email","student_mobile"]'),
    ('guidance', 'guidance.appointment.created',
     'Fired when an admin creates a new appointment for a case.',
     '["appointment_id","date","time","student_name","student_mobile"]')
ON DUPLICATE KEY UPDATE description = VALUES(description), available_vars = VALUES(available_vars);

-- Seed trigger rows (priority 100 = default, rate limit 10/min)
INSERT INTO kernel_event_triggers
    (module, event_key, capability_id, is_enabled, priority, max_per_minute, template, created_at)
VALUES
    ('guidance', 'guidance.booking.created', 'sms.send@1', 1, 100, 10,
     'Guidance: booking request received. Ref #{appointment_id}', NOW()),
    ('guidance', 'guidance.appointment.created', 'sms.send@1', 1, 100, 10,
     'Guidance: appointment scheduled on {date} {time}. Ref #{appointment_id}', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
```

---

## 5. Kernel Admin Triggers UI

A kernel-owned admin page at `/admin/kernel/triggers` (not owned by any specific module) that shows all registered triggers across all modules:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Module Triggers                             [+ Add Trigger]        │
├───────────────┬──────────────────────────────┬──────────────┬───────┤
│ Module        │ Event Key                    │ Capability   │ On/Off│
├───────────────┼──────────────────────────────┼──────────────┼───────┤
│ guidance      │ guidance.booking.created     │ sms.send@1   │  ✓    │
│ guidance      │ guidance.appointment.created │ sms.send@1   │  ✓    │
│ ticketing     │ ticket.created               │ sms.send@1   │  ✗    │
└───────────────┴──────────────────────────────┴──────────────┴───────┘
```

Clicking a row opens an edit panel with:
- **Enabled** toggle
- **Template** text input (with available `{vars}` listed per event)
- **Provider** override (optional, defaults to capability bus selection)
- **Meta** JSON editor (advanced, collapsed by default)

### 5.1 Kernel Admin Routes (new, in core routing)

```
GET  /admin/kernel/triggers              → pageKernelTriggers()
GET  /admin/kernel/api/triggers          → apiKernelGetTriggers()
POST /admin/kernel/api/triggers          → apiKernelCreateTrigger()
PUT  /admin/kernel/api/triggers/{id}     → apiKernelUpdateTrigger()
DELETE /admin/kernel/api/triggers/{id}  → apiKernelDeleteTrigger()
```

These are **kernel routes** (not module routes) — they work even if the `ai` module is disabled.

---

## 6. AI Module (`modules/ai/`)

### 6.1 Purpose

The AI module plugs into the existing kernel and:
1. Exposes `ai.capability.suggest@1` — an LLM-backed capability that takes a module manifest + event list and returns suggested **trigger configurations** and **module/capability recommendations**
2. Adds a "Suggest Triggers" button to the kernel Triggers admin page
3. Adds an "Install Suggestions" panel in the kernel Modules admin page (suggest missing capability modules)
4. Stores provider settings (API key, model, temperature, Ollama URL) in an `ai_settings` table

**Scope of suggestions (v1):**

| Suggestion type | What the AI returns | Where admin sees it |
|---|---|---|
| Trigger wiring | Which capabilities to wire to which events, with templates | Kernel Triggers UI |
| Module recommendations | Which modules to install for detected missing capabilities | Kernel Modules UI |

**Scope of suggestions (future v2):**
- Capability provider selection (prefer Ollama for privacy-sensitive modules)
- Workflow suggestions (`workflow.guidance.notify.student` — see §13)

### 6.2 Module Structure

```
modules/ai/
├── module.json
├── routes.php
├── handlers.php
├── helpers.php          -- ai_cap_ai_capability_suggest_1() provider function
├── database/
│   └── migrations/
│       └── 001_ai_settings.sql
└── (templates in templates/modules/ai/)
    └── pages/
        └── settings.disyl
```

### 6.3 `module.json`

```json
{
    "id": "ai",
    "name": "AI Assistant",
    "version": "1.0.0",
    "description": "AI-powered capability wiring suggestions and self-aware module setup via LLM integration.",
    "author": "Ikabud",
    "requires_tables": ["ai_settings"],
    "migrations": ["database/migrations/001_ai_settings.sql"],
    "capabilities": {
        "exposes": [
            {
                "id": "ai.capability.suggest@1",
                "priority": 50,
                "modes": ["first"],
                "policy": {
                    "default": {
                        "allow_callers": ["kernel", "ai"]
                    }
                }
            }
        ],
        "depends": [
            "kernel.auth.user@1"
        ]
    },
    "nav": [
        {
            "label": "AI Settings",
            "url": "/admin/ai/settings",
            "icon": "sparkles",
            "roles": ["admin"]
        }
    ]
}
```

### 6.4 `ai_settings` Table

```sql
CREATE TABLE IF NOT EXISTS `ai_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO ai_settings (setting_key, setting_value) VALUES
    ('provider', 'openai'),
    ('model', 'gpt-4o-mini'),
    ('api_key', NULL),
    ('enabled', '0')
ON DUPLICATE KEY UPDATE updated_at = NOW();
```

### 6.5 Capability: `ai.capability.suggest@1`

**Contract ID**: `ai.capability.suggest@1`  
**Mode**: `first`  
**Caller**: kernel admin only

**Input payload:**
```json
{
    "module_id": "guidance",
    "manifest": { ... },
    "known_events": [
        {
            "event_key": "guidance.booking.created",
            "description": "Fired when a student submits a public booking request",
            "available_vars": ["appointment_id", "student_name", "student_email"]
        },
        {
            "event_key": "guidance.appointment.created",
            "description": "Fired when an admin creates a new appointment",
            "available_vars": ["appointment_id", "date", "time", "student_name"]
        }
    ],
    "available_capabilities": ["sms.send@1", "email.send@1", "kernel.audit.record@1"]
}
```

**Output:**
```json
{
    "suggestions": [
        {
            "event_key": "guidance.booking.created",
            "capability_id": "sms.send@1",
            "is_enabled": true,
            "priority": 100,
            "max_per_minute": 10,
            "template": "Guidance: booking request received. Ref #{appointment_id}",
            "reasoning": "Student just booked an appointment. SMS is appropriate for immediate confirmation."
        },
        {
            "event_key": "guidance.appointment.created",
            "capability_id": "sms.send@1",
            "is_enabled": true,
            "priority": 100,
            "max_per_minute": 10,
            "template": "Guidance: appointment scheduled on {date} {time}. Ref #{appointment_id}",
            "reasoning": "Admin-created appointment should notify the student via SMS."
        }
    ],
    "module_recommendations": [
        {
            "capability_id": "email.send@1",
            "reasoning": "Guidance module fires events with student_email in available_vars but no email capability is installed."
        }
    ]
}
```

`module_recommendations` is optional — present only when the AI detects declared `available_vars` (e.g. `student_email`) that map to an uninstalled capability.

The admin reviews these suggestions in the Triggers UI and confirms or rejects each before they are applied to `kernel_event_triggers`.

### 6.6 LLM Prompt Strategy

The AI module builds a structured system prompt from the kernel's live data:

```
You are a capability wiring assistant for the Ikabud Kernel OS.
Given a module's events and available capabilities, suggest which
capabilities should be triggered for each event, and write appropriate
message templates where the capability requires one.

Rules:
- Only suggest capabilities that are in the provided available_capabilities list
- Default is_enabled to true only when the event clearly benefits the user
- Keep templates short, include reference IDs for traceability
- Return valid JSON matching the output schema exactly
- Do not hallucinate capability IDs not in the list
```

---

## 7. How the Layers Interact

### 7.1 AI-Assisted Setup Flow

```
Admin installs `ai` module
       ↓
Admin navigates to /admin/kernel/triggers
       ↓
Clicks "Suggest for guidance module"
       ↓
Kernel calls: app()->cap()->call('ai.capability.suggest@1', $payload, ['mode' => 'first'])
       ↓
AI module queries kernel_events + capability registry → builds prompt → calls LLM
       ↓
Kernel UI shows suggestion panel (trigger wiring + module recommendations)
       ↓
Admin reviews and confirms each suggestion individually
       ↓
Kernel calls kernelTriggerSave() → upserts into kernel_event_triggers
```

### 7.2 Runtime Event Emission Flow (via `kernelEmitEvent()`)

```
Guidance handler creates a booking
       ↓
kernelEmitEvent('guidance.booking.created', [...], 'guidance')
       ↓
  ┌────────────────────────────────────────────────────┐
  │ Kernel EventBus fires (module-to-module listeners) │
  └────────────────────────────────────────────────────┘
       ↓
  ┌────────────────────────────────────────────────────┐
  │ Trigger lookup: SELECT * FROM kernel_event_triggers│
  │   WHERE event_key = 'guidance.booking.created'     │
  │   AND is_enabled = 1 ORDER BY priority ASC         │
  └────────────────────────────────────────────────────┘
       ↓
  For each trigger row (in priority order):
    → Check rate limit (max_per_minute)
    → Call app()->cap()->call(capability_id, payload)
    → Log result; continue on failure
```

**Guidance module has zero SMS code.** It emits one event. The kernel dispatches everything.

### 7.3 Fallback — No AI module installed

**Without the AI module installed**, everything works the same — the admin fills trigger rows manually through the kernel Triggers UI. The AI module is **additive, not required**. This is a direct consequence of the core principle: kernel enforces regardless of what AI has suggested or whether AI is present at all.

---

## 8. Migration Plan (from current `gm_settings`)

### Phase A — Kernel infra (no breaking changes to any module)
1. Create `kernel_events` + `kernel_event_triggers` tables (single kernel migration)
2. Add `kernelEmitEvent()` to `kernel/EventTriggers.php` (auto-required via `bootstrap.php`)
3. Add `kernelTriggerEnabled()`, `kernelTriggerTemplate()`, `kernelTriggerConfig()`, `kernelTriggerSave()` helpers
4. Seed `kernel_events` rows for Guidance and seed 2 trigger rows
5. Add `kernelRegisterModuleEvents()` function — called during module load to sync `module.json events[]` into `kernel_events`
6. Wire `kernelRegisterModuleEvents()` into `loadModuleRoutes()` in `module-manager.php`

### Phase B — Migrate Guidance to `kernelEmitEvent()`
1. In Guidance handlers: replace `guidanceNotifySms()` calls with `kernelEmitEvent()`
2. Remove `guidanceNotifySms()` and `guidanceFormatSmsMessage()` from `helpers.php`
3. Remove SMS trigger keys from `guidanceGetAllSettings()` defaults
4. Remove SMS trigger fields from `templates/modules/guidance/pages/settings.disyl`
5. Add `events[]` declaration to `modules/guidance/module.json`
6. Guidance Settings page trigger sub-section now calls `apiKernelGetTriggers(?module=guidance)` (kernel API)

### Phase C — Kernel Triggers admin UI
1. Add `/admin/kernel/triggers` page (kernel route, not a module route)
2. Render event registry + trigger table with enable/disable toggles, template editor, priority sorter
3. Unregistered event warning badge (event in `kernel_events` but no trigger row)

### Phase D — AI module (optional, any time after Phase A)
1. Create `modules/ai/` scaffold
2. Implement `ai.capability.suggest@1` with OpenAI + Ollama providers
3. Wire "Suggest" button into kernel Triggers UI
4. Wire "Module Recommendations" panel into kernel Modules UI
5. Admin provides API key / Ollama URL via `/admin/ai/settings`

---

## 9. `known_events` Registration Pattern

For the AI module to know what events a module fires, each module can declare its events in `module.json` under a new optional `events` key:

```json
{
    "id": "guidance",
    "events": [
        {
            "key": "guidance.booking.created",
            "description": "Fired when a student submits a public booking request.",
            "available_vars": ["appointment_id", "student_name", "student_email", "student_mobile"]
        },
        {
            "key": "guidance.appointment.created",
            "description": "Fired when an admin creates a new appointment for a case.",
            "available_vars": ["appointment_id", "date", "time", "student_name", "student_mobile"]
        }
    ]
}
```

The AI module reads this during suggestion generation. It is **optional** — the admin can also type event keys manually in the Triggers UI.

---

## 10. What Changes in `module.json` (Summary)

No breaking changes to the existing `module.json` spec. One new **optional** field:

| Field | Type | Description |
|---|---|---|
| `events` | object[] | Events this module fires. Each entry: `key`, `description`, `available_vars[]` |

Existing fields (`capabilities.exposes`, `capabilities.depends`, etc.) are unchanged.

The `module-manager.php` `loadModuleRoutes()` function is updated to call `kernelRegisterModuleEvents()` after loading each module — syncing `events[]` into the `kernel_events` table. This is idempotent (upsert) so re-enabling a module is safe.

---

## 11. Open Questions for Review

1. **Trigger table ownership**: ~~Should `kernel_event_triggers` be a true kernel table or owned by the `ai` module?~~ **Resolved**: Kernel-owned. The AI module depends on the trigger table; it does not create it. See §2 Architectural Principle.

2. **Opt-out vs opt-in default**: ~~Is opt-out (missing row = enabled) or opt-in (missing row = disabled) safer?~~ **Resolved**: Opt-out model (`kernelTriggerEnabled()` returns `true` when no row exists), but with a **required seeding process** to keep it safe:
   - Every module that fires events **must** seed its default trigger rows on install (via migration SQL). A missing row is then a bug, not a deliberate off state.
   - The kernel admin Triggers UI flags any event key found in module manifests (`events[]`) that has **no corresponding row** in `kernel_event_triggers` as an unregistered trigger (yellow warning badge), prompting the admin to configure it.
   - This gives the safety of opt-in visibility without silently blocking triggers when a migration hasn't run yet.

3. **Who can edit triggers**: ~~Kernel admin only, or module-level admins too?~~ **Resolved**: Module admins can manage their own module's triggers from the module's **Settings page** (filtered view — they only see rows where `module = their module`). Kernel admins see and edit all modules from `/admin/kernel/triggers`. This means:
   - Module Settings page renders a trigger sub-section by calling `apiKernelGetTriggers(?module=guidance)` — no separate settings keys needed.
   - The kernel API enforces scope: a module admin call with `module = guidance` cannot write rows for any other module.
   - Guidance Settings no longer needs custom SMS toggle fields — the trigger sub-section replaces them entirely (Phase B migration).

4. **LLM provider**: ~~OpenAI only, or Ollama too?~~ **Resolved**: Both supported out of the box in `modules/ai/`. The `ai_settings` table `provider` key accepts `openai` or `ollama`. When `ollama` is selected, an additional `ollama_base_url` setting specifies the local endpoint (e.g. `http://localhost:11434`). The same `ai.capability.suggest@1` contract is used regardless of provider — the provider implementation switches internally. This maps cleanly to the kernel's multi-provider model: two provider functions, same capability ID, `first` mode, `ollama` gets higher priority when configured so it is preferred over OpenAI for privacy-sensitive deployments.

5. **Template variable safety**: ~~Validate `{vars}` against `available_vars`, or soft warning only?~~ **Resolved**: Kernel validates. When saving a trigger row via `kernelTriggerSave()` or the admin API, the kernel:
   - Extracts all `{var}` tokens from the template string using regex
   - Cross-checks against the module's declared `events[].available_vars` for that event key
   - Rejects the save with a `422` and a descriptive error listing unknown variables if any are found
   - Falls back gracefully (allows save) if the module has not declared `events[]` in its manifest yet — treats it as unvalidated rather than blocking
   - The AI module's suggestion output is also validated before the admin can confirm it

---

## 12. Roadmap Placement

This work spans two phases in `docs/kernel/roadmap.md`:

**Phase 4 — Post-v1 Enhancements (existing)**  
Add under "Potential deliverables":
> - `kernel_events` + `kernel_event_triggers` tables + `kernelEmitEvent()` helper
> - Module `events[]` manifest declaration + auto-sync into `kernel_events` on load
> - Kernel admin `/admin/kernel/triggers` UI (enable/disable, template edit, priority sort)
> - Template `{var}` validation against declared `available_vars` on save
> - Unregistered event warning badge in Triggers UI

**New: Phase 5 — AI-Assisted Module Setup**  
> - `modules/ai/` module exposing `ai.capability.suggest@1`
> - LLM-backed trigger wiring suggestions + module recommendations
> - Admin review/confirm flow before anything is applied
> - OpenAI and Ollama providers (same capability contract, provider switches internally)
> - `module_recommendations` output for missing capability detection

**New: Phase 6 — Workflow Engine (strategic)**  
> - `workflow.{name}` capability contracts grouping multi-step capability chains
> - Kernel workflow registry + admin composer UI
> - Modules expose workflows in `module.json` (`workflows[]`)
> - See §13

---

## 13. Strategic Extension: Workflow Capabilities

> *Answering the review's strategic question: "Have you considered allowing modules to expose workflows?"*

This fits the architecture naturally and is the logical Phase 6 evolution.

### Concept

A **workflow** is a named, kernel-owned chain of capability calls triggered by a single event. Instead of wiring capabilities one-by-one in the trigger table, a module can expose a workflow that bundles multiple capabilities:

```json
"workflows": [
    {
        "id": "workflow.guidance.notify.student",
        "description": "Full student notification: SMS + email + audit record",
        "steps": [
            { "capability": "sms.send@1",          "priority": 100 },
            { "capability": "email.send@1",         "priority": 200 },
            { "capability": "kernel.audit.record@1","priority": 300 }
        ]
    }
]
```

A trigger row can then reference a workflow instead of a single capability:

```
kernel_event_triggers:
  event_key:     guidance.booking.created
  capability_id: workflow.guidance.notify.student  ← resolves to 3 capability calls
```

### Why this fits the existing architecture

- Workflows are **registered as capabilities** — same `CapabilityRegistry`, same `CapabilityBus` policy enforcement
- The workflow capability handler internally calls each step, respecting step priority
- Modules **do not need to know** which workflow runs — they just emit the event via `kernelEmitEvent()`
- Admin can swap a single-capability trigger for a workflow trigger in the UI with no module code changes
- AI can suggest workflows just as it suggests single-capability triggers

### Phase 6 Design Principle

Same as the core principle — extended:

```
Kernel owns workflows. AI suggests steps. Admin composes. Modules emit events.
```

This enables platform-level automation:

```
ticket.created → workflow.ticketing.new_ticket_notify
   → sms.send@1      (notify submitter)
   → email.send@1    (notify support team)
   → kernel.audit.record@1  (log event)

ledger.closed → workflow.ledger.daily_close
   → kernel.audit.record@1  (immutable close record)
   → sms.send@1             (supervisor notification)
```

> **Implementation note**: Phase 6 is explicitly deferred until Phase A–D are stable. Do not add workflow complexity to early phases.

---

## 14. AI Provider Expansion (v3)

### Supported Providers

The AI module now supports seven LLM providers sharing a common helper interface:

| Provider | Key | Notes |
|----------|-----|-------|
| OpenAI | `openai` | Default. Requires `OPENAI_API_KEY` / `api_key` setting. |
| Groq | `groq` | OpenAI-compatible, high-speed. Requires `groq_api_key`. |
| Ollama | `ollama` | Local inference server. Requires `ollama_base_url`. |
| Google Gemini | `gemini` | Requires `gemini_api_key`. |
| Cerebras | `cerebras` | Requires `cerebras_api_key`. |
| OpenRouter | `openrouter` | Multi-model gateway. Requires `openrouter_api_key`. |
| Mistral | `mistral` | Requires `mistral_api_key`. |

Provider is selected via the `provider` setting (global or per-tenant). All providers implement the same two entry-point shapes:
- `ai{Provider}SuggestTriggers($ctx)` for `ai.capability.suggest@1`
- `ai{Provider}TextGenerate($messages, $temp, $json, $timeout[, $maxTokens])` for `ai.text.generate@1`

### Settings Resolution Order

Settings are resolved in three layers, with later layers taking precedence:

1. **Global settings** — stored in the module registry (`readModuleRegistry()['ai']['settings']`)
2. **Tenant settings** — per-tenant overrides (`getModuleSettings('ai')`)  
   Empty-string tenant values do not mask valid global defaults.
3. **Runtime overrides** — programmatic overrides via `aiRuntimeOverrides()` / `aiWithRuntimeOverrides()`  
   Empty-string runtime values are also ignored.

Functions:

| Function | Description |
|----------|-------------|
| `aiGlobalSettings()` | Returns raw global AI settings array |
| `aiResolvedSettings()` | Returns merged settings (global → tenant → runtime) |
| `aiRuntimeOverrides(?array $replace)` | Get or set in-request runtime overrides |
| `aiWithRuntimeOverrides(array $overrides, callable $callback)` | Scoped override — restores previous state after callback |

### `ai.text.generate@1` — Extended Input Parameters

Two new optional payload keys were added:

| Key | Type | Description |
|-----|------|-------------|
| `preferred_tier` | `string` | Hint for provider model selection: `free`, `paid`, or `custom`. Empty = no preference. |
| `max_tokens` | `integer` | Hard cap on response tokens. If omitted or 0, the provider default is used. |

The `provider` field in the response is now always the actual resolved provider instead of hardcoded `"openai"`.

---

*Document v3 — new providers, settings cascade, and runtime overrides documented.*

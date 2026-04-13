# Kernel Auto-Wiring

## Purpose

This doc defines how modules should integrate through the kernel without hardcoding cross-module logic.

Use these primitives:

- Capability: do work now
- Event: something happened
- Trigger: when event X happens, call capability Y
- Hook: extend UI/render points
- Listener: code-level always-on event reaction

## Rule of thumb

- Capability = "Do this now"
- Event = "This just happened"
- Trigger = "When that happens, do this automatically"
- Hook = "Let me extend this UI/render point"
- Listener = "I always react in code to this event"

## Auto-wiring flow

When a module is enabled:

1. Read `module.json`
2. Load `helpers.php`
3. Register capabilities
4. Register events into `kernel_events`
5. Load routes
6. Load hooks/listeners
7. Optionally seed default triggers with `kernelTriggerSave(...)`

## Boundary-safe module pattern

Modules should treat `ModuleContext` as the default boundary surface for request-path work.

Preferred handler shape:

```php
function someModuleHandler(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $user = $ctx->requireAnyRole('admin', 'supervisor');
    $input = $ctx->input();
    echo $ctx->render('modules/example/page.disyl', ['user' => $user]);
}
```

Module code should not reach directly for request globals or handler-level `app()` shortcuts when the same behavior is available through `ModuleContext`.

Avoid in module code:

- direct `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`
- handler-level `app()->input()`
- handler-level `app()->user()`
- handler-level `app()->render()`
- handler-level `app()->redirect()`
- handler-level `app()->json()`
- handler-level `app()->isHtmx()`

Use instead:

- `$ctx->input()`
- `$ctx->user()` / `$ctx->requireRole()` / `$ctx->requireAnyRole()`
- `$ctx->render()`
- `$ctx->redirect()`
- `$ctx->json()`
- `$ctx->isHtmx()`

## Kernel-owned helper boundary

When a module needs simple request/file access outside the `ModuleContext` API, use kernel-owned helpers rather than raw globals or raw file I/O.

Current helpers:

- `kernelUploadedFile()`
- `kernelCookie()`
- `kernelReadJsonFile()`
- `kernelEnsureDirectory()`
- `kernelDeletePath()`
- `kernelCopyFile()`
- `kernelWriteFile()`

These helpers exist to keep modules on a reviewable, consistent boundary surface.

## Capability pattern

Use capabilities for synchronous services.

Examples:

- `ai.text.generate@1`
- `workflow.state.get@1`
- `search.index.upsert@1`
- `tinymce.config.get@1`
- `tinymce.html.sanitize@1`

Modules register capability handlers in `helpers.php`.

## Event pattern

Use events for domain notifications.

Examples:

- `cms.content.published`
- `users.created`
- `media.uploaded`
- `guidance.session.completed`
- `tinymce.content.saved`

Modules emit events with `kernelEmitEvent(...)`.

## Trigger pattern

Use triggers for configurable cross-module automation.

Examples:

- `cms.content.published` -> `search.index.upsert@1`
- `cms.content.updated` -> `ai.text.generate@1`
- `guidance.session.completed` -> `guidance.report.generate@1`
- `tinymce.content.saved` -> `audit.record@1`

Triggers live in `kernel_event_triggers`.

Use triggers when integration should be:

- configurable
- enable/disable-able
- visible in admin
- provider/timeout aware

## Listener pattern

Use listeners when behavior is built-in and not configurable.

Examples:

- search listening to CMS publish/update/delete
- cache invalidation listeners

## Hook pattern

Use hooks for UI/render extension points.

Examples already used by CMS:

- `cms.builder.widgets`
- `cms.editor.sidebar_fields`
- `cms.admin.nav_items`
- `cms.public.head`
- `cms.public.render_content`
- `cms.content.query_args`

## TinyMCE integration example

TinyMCE should be a reusable editor module, not a CMS-only addon.

### TinyMCE should expose capabilities

- `tinymce.assets.get@1`
- `tinymce.config.get@1`
- `tinymce.html.normalize@1`
- `tinymce.html.sanitize@1`

### TinyMCE may emit events

- `tinymce.editor.loaded`
- `tinymce.content.changed`
- `tinymce.content.saved`

### CMS should use TinyMCE directly for editor services

CMS calls TinyMCE capabilities for:

- assets
- editor config
- HTML normalization
- HTML sanitation

CMS then emits its own domain events such as:

- `cms.content.created`
- `cms.content.updated`
- `cms.content.published`

### Guidance should use TinyMCE the same way

Guidance calls TinyMCE capabilities for:

- assets
- editor config
- sanitation/normalization

Guidance then emits Guidance domain events such as:

- `guidance.session.saved`
- `guidance.session.completed`
- `guidance.report.saved`

## Best separation of concerns

### Editor module owns

- assets
- config
- sanitize/normalize
- optional editor events

### Business modules own

- content lifecycle
- session lifecycle
- report lifecycle
- publication workflow
- domain events

### Triggers/listeners own automation

- search indexing
- AI summaries
- workflow fanout
- report generation
- notifications

## Suggested default trigger seeds

Optional, idempotent defaults:

- `cms.content.published` -> `search.index.upsert@1`
- `cms.content.updated` -> `ai.text.generate@1`
- `guidance.session.completed` -> `guidance.report.generate@1`

Seed only safe defaults. Keep them editable in admin.

## Recommended architecture

For TinyMCE in this system:

- CMS consumes TinyMCE capabilities directly
- Guidance consumes TinyMCE capabilities directly
- CMS and Guidance emit their own domain events
- AI, Search, Workflow, Reporting, Notifications connect through triggers/listeners

This keeps the editor reusable and keeps business automation outside the editor module.

## Responsibilities by primitive

### Capabilities own service contracts

Capabilities are for synchronous, request-path work.

Use them when a caller needs a result now.

Good examples:

- CMS asks `workflow.state.get@1` before rendering an editor panel
- CMS asks `ai.text.generate@1` from an explicit user action
- Guidance asks `tinymce.html.sanitize@1` before saving notes

### Events own domain facts

Events should describe completed business facts, not instructions.

Good payload keys:

- `content_id`
- `title`
- `slug`
- `type`
- `author_id`

Avoid event payloads that encode decisions like `run_ai_summary` or `send_notification`.

### Triggers own configurable automation

Triggers should be used when the integration must be visible, editable, and optional.

Use triggers when you need:

- enable/disable behavior
- provider selection
- timeout tuning
- payload templating
- ordering via priority

### Listeners own hard guarantees

Use listeners only when a module must always react and the behavior is not a business option.

Good examples:

- cache invalidation
- mandatory search sync

## Data flow walkthrough

A typical cross-module flow should look like this:

1. A handler performs domain work
2. The module emits a domain event with `kernelEmitEvent(...)`
3. The kernel dispatches listeners and configured triggers
4. Triggered capabilities execute in other modules
5. Each downstream module remains isolated behind its own contract

Example:

1. CMS publishes content
2. CMS emits `cms.content.published`
3. Search trigger calls `search.index.upsert@1`
4. AI trigger calls `ai.text.generate@1`
5. Workflow or reporting modules can react without CMS knowing their internals

## Policy and caller boundaries

Capability policy should control which modules may call which services.

Recommended rule:

- direct calls use capability policy
- broad fanout uses events plus triggers

This prevents modules from becoming tightly coupled through unrestricted capability access.

## TinyMCE walkthrough

TinyMCE should be treated as a shared editor service.

### TinyMCE provides

- `tinymce.assets.get@1`
- `tinymce.config.get@1`
- `tinymce.html.normalize@1`
- `tinymce.html.sanitize@1`

### CMS consumes

CMS calls TinyMCE capabilities to render and validate editor content.

CMS then emits:

- `cms.content.created`
- `cms.content.updated`
- `cms.content.published`

### Guidance consumes

Guidance calls the same TinyMCE capabilities for notes, reports, or session content.

Guidance then emits:

- `guidance.session.saved`
- `guidance.session.completed`

### Downstream automation

Keep downstream automations outside TinyMCE itself.

Examples:

- `cms.content.published` -> `search.index.upsert@1`
- `cms.content.updated` -> `ai.text.generate@1`
- `guidance.session.completed` -> `guidance.report.generate@1`

## Default trigger seeding

Modules may seed safe default triggers with `kernelTriggerSave(...)`.

Recommended seeding rules:

- make seeds idempotent
- seed only safe defaults
- keep them editable in admin
- do not bury business rules inside editor modules

## Anti-patterns

Avoid these:

- editor modules writing directly into another module's tables
- CMS embedding AI or editor provider internals in handlers
- using events as command messages instead of domain facts
- putting optional business automation in listeners when it should be a trigger

## Implementation plan

### 1. Add TinyMCE as a module

Create `modules/tinymce/` as a shared editor service.

Minimum contracts:

- `tinymce.assets.get@1`
- `tinymce.config.get@1`
- `tinymce.html.normalize@1`
- `tinymce.html.sanitize@1`

Optional events:

- `tinymce.editor.loaded`
- `tinymce.content.changed`
- `tinymce.content.saved`

Rule: TinyMCE must not contain CMS- or Guidance-specific table logic.

### 2. Wire CMS and Guidance directly to TinyMCE capabilities

CMS and Guidance should both consume TinyMCE through capability calls for:

- assets
- config
- normalize/sanitize

They should continue emitting their own domain events.

### 3. Review existing modules for auto-wiring readiness

Review `ai`, `search`, `workflow`, `users`, and `media` for:

- versioned capability ids
- capability registration in `helpers.php`
- correct manifest declarations
- factual event payloads
- capability policy boundaries
- clear choice between trigger vs listener behavior

### 4. Review notes by module

- `ai`: keep provider routing internal; prefer event + trigger fanout for downstream automation
- `search`: decide which indexing paths are hard listeners vs configurable triggers
- `workflow`: keep state/transition synchronous; emit workflow events for downstream automation when needed
- `users`: keep user ownership and user events inside `users`
- `media`: keep media ownership and media events inside `media`

### 5. Kernel hardening follow-up

Add next:

- manifest validation before registration
- capability registry metadata
- event payload schemas
- dependency version checks
- optional async trigger execution for slow tasks

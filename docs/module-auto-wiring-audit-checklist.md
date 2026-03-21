# Module Auto-Wiring Audit Checklist

Use this checklist when reviewing module readiness for the kernel auto-wiring model.

## Common checklist

- Capability ids are versioned
- Capability handlers register in `helpers.php`
- `module.json` exposes and depends entries are accurate
- Capability policy is explicit where cross-module calls are expected
- Events are declared in the manifest
- Event payloads describe facts, not commands
- Trigger-driven automations are not buried in handlers
- Listener usage is limited to hard technical guarantees
- Tables accessed by the module are declared correctly

## AI

- `ai.text.generate@1` remains the primary synchronous text generation contract
- Provider routing remains internal to AI
- Trigger suggestion and downstream fanout stay outside caller business logic where possible
- Add richer schema metadata later

## Search

- `search.query@1`, `search.index.upsert@1`, `search.index.delete@1` stay stable
- Decide whether indexing is a hard invariant or should be trigger-configurable
- Keep search payloads factual and reusable across modules

## Workflow

- `workflow.state.get@1` and `workflow.transition@1` stay synchronous
- Emit workflow events for downstream automation as needed
- Keep workflow definitions separate from CMS-specific save logic

## Users

- User ownership stays in `users`
- CRUD contracts remain stable and versioned
- Add explicit capability policy if more modules will call into users
- Keep `users.created` and `users.updated` payloads factual

## Media

- Media ownership stays in `media`
- Upload/list/delete contracts remain stable and versioned
- Add explicit capability policy if more modules will call into media
- Keep `media.uploaded` and `media.deleted` payloads factual

## TinyMCE

- TinyMCE stays a shared editor service
- No domain table ownership outside editor concerns
- Assets/config/normalize/sanitize are capability-first
- Business automation remains in CMS/Guidance events plus triggers

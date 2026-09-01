# Architecture/Task Contract — HARPP CMS Assistant

- **Role:** `/architect`
- **Status:** `IMPLEMENTED` (2026-09-01) — Phase 1 (CMS service-token auth) + Phase 2 (client,
  lane, CLI, tests) complete. See `docs/ai/cms-assistant-mode.md` for usage.
- **Owner decisions recorded (2026-09-01):**
  - Auth: **Option A — CMS service token** (new `Authorization: Bearer` support in the CMS API
    auth layer; capability-scoped virtual editor; **never publish**).
  - Model: **deepseek or groq** (execution pool; **never Codex**).
  - Scope: **posts/pages (standard content) AND builder pages** — the assistant can **redesign
    pages** (read + update builder documents), always as **draft**, human publishes.

---

## task

Add a **CMS Assistant** lane to the HARPP harness: the owner sends a content request in a
dedicated `CMS Assistant` conversation, and a headless agent creates/updates **CMS content and
builder pages** through the CMS content/builder APIs using a **scoped service token**, always
landing as **draft → review** for a human to publish.

## objective

- Let the owner say "create a post about X", "update page Y", or "redesign the home page" in the
  messenger and have the harness do it durably — no per-session setup, no Codex.
- **Never auto-publish.** The assistant produces drafts only; publishing stays human (existing
  CMS workflow / approval).

## scope

### allowed

- **CMS side (server, shipped in deploy package):**
  - Migration: `cms_service_tokens` (token hash, name, capability allowlist JSON, created_by,
    is_active, timestamps).
  - Bearer-token auth: when `Authorization: Bearer <token>` is present, resolve the token to a
    **virtual CMS user** (`source='cms'`, `is_service=true`, `service_caps` allowlist) in
    `Cms::ctxUser()`.
  - `cmsUserCan()` honors `service_caps` for service users (authoritative allowlist gate).
  - Default service-token allowlist: `content.list/read/create/edit_any`, `builder.access/save/
    preview/revisions`, `media.list/upload/edit`, `workflow.view/transition`. **Excludes**
    `content.publish`, `builder.publish`, `content.schedule`, `settings.manage`, `users.manage`,
    `import_export.manage`, `content_types.manage`, `customizer.manage`.
  - Admin UI/CLI to mint + revoke tokens (superadmin or CMS admin).
- **Client side (harness, `tools/harpp-bridge/`):**
  - New **CMS Assistant lane** in the wake engine (mirrors the advisor lane): dedicated channel
    (`CMS Assistant`), task contract `wake/task-contract-cms.md`, separate ledger, own model
    (`deepseek/*` or `groq/*`), never Codex.
  - `cms_client` calls the CMS content/builder APIs with the service token (Bearer), forced to
    draft status.
  - CLI: `harpp cmsassistant {set token|set model|set base_url|status|enable|disable}`.
- Reuse existing CMS AI automation (`ai.text.generate@1`) for drafting where useful.

### prohibited

- **Never publish/schedule** via the assistant; no settings/user/media-delete/import-export/customizer
  capabilities.
- No Codex usage for CMS content work.
- No weakening of existing CMS role/capability checks for normal users.
- No cross-module DB access; CMS edits go through CMS APIs only.
- No auto-approving or bypassing the CMS workflow/review step.

## constraints

- **Never auto-publish (hard).** The service token allowlist excludes every publish/schedule cap.
- **Codex separation (hard).** Model allowlist: `deepseek/*` or `groq/*` only.
- **Draft-first (hard).** All content/builder writes are created/updated as `draft`.
- **Durability.** Config/token persist; works from the messenger without Copilot chat.
- **Security.** Token stored hashed server-side; sent only as Bearer over HTTPS; never logged.
  `cms_service_tokens` mint/revoke restricted to CMS admins.
- **Linux-only client** for now (same as advisor lane).

## delegation

- Scaffolding (contract, task-contract, migrations, CLI): **GPT 5.4**.
- `/review`: **GPT 5.4**.
- Implementation execution: **DeepSeek**.

## acceptance

1. A message in the `CMS Assistant` conversation triggers the CMS agent on a `deepseek/*` or
   `groq/*` model (never Codex) and replies over the bridge.
2. A content create/update request lands as a **draft** with correct title/body; the reply
   reports the content id + "awaiting review/publish".
3. A **page redesign** request reads the current builder document and saves an updated **draft**
   (never publish).
4. No publish/schedule capability is granted to the service token (`cmsUserCan('content.publish')`
   false for service user).
5. Ledger records CMS-assistant usage separately; dev/ideation lanes unaffected.

## e2e_acceptance

- Owner texts "create a draft post titled X with body Y" in `CMS Assistant` → a draft post X
  exists (status `draft`) and the owner gets a confirmation with a Next step.
- Owner texts "redesign the About page" → the builder document draft changes; the public page is
  **unchanged** until a human publishes.
- Live CMS shows the drafts in the admin review queue; publishing by the owner works normally.

## verification

- Unit/integration tests: Bearer-token resolution, service_caps allowlist (publish denied),
  draft-forcing on create/update, lane routing (CMS vs dev vs ideation), ledger separation.
- CMS module tests + harness `self-test`; `php -l`, `node --check`, DiSyL lint.
- Live check after deploy: mint a token, create a draft via the agent, confirm no publish.

## risk

- **Builder document schema:** the assistant must edit a structured builder JSON. Mitigation:
  the task contract includes the builder doc contract + node rules; the agent works via the
  builder get/save APIs and must preserve valid structure (validate before save).
- **Service-token scope creep:** the allowlist must stay minimal; any future capability added to
  a token is a deliberate, reviewed change.
- **Content quality:** AI-generated content can be wrong — mitigated by mandatory draft → review
  and the owner's review before publish.
- **Scope creep:** keep this to CMS content + builder pages; no ecommerce/CRM/etc. content.

## recommended_next_state

`/implement` — Phase 1: CMS service-token auth (migration + Bearer hook + allowlist + mint/revoke
CLI). Phase 2: CMS Assistant client lane (contract, routing, model, CLI, ledger). Phase 3: builder
document read/update for page redesign. Phase 4: tests, docs, deploy package. Delegate scaffolding
+ `/review` to GPT 5.4.

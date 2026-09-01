# CMS Assistant — Durable Content-Editing Lane (HARPP)

The **CMS Assistant** is the HARPP content-editing lane: a separate, always-on assistant that
handles owner requests to **create or update CMS content and redesign builder pages** — always as
**drafts** (never published).

It complements the [ChatGPT Advisor](./chatgpt-advisor-mode.md) (ideation, read-only) and the
dev lane (code edits). Unlike those, the CMS Assistant talks to the **CMS** (not ChatGPT/Codex)
using a scoped **service token** and a deepseek/groq model.

## Design goals

- **Durable** — the `harpp watch` daemon is always on (systemd + linger); no per-session Copilot
  setup, no human babysitting.
- **Never Codex** — the CMS lane is allowlisted to `deepseek/*` or `groq/*` only.
- **Never publishes** — every write lands as `status=draft`. Publishing is a human step in the
  CMS admin.
- **Separate ledger** — `cms_usage` is tracked independently of dev (`wake_hour`/`model_routes`)
  and ideation (`ideation_usage`).
- **Fail-safe** — any agent failure keeps the request staged for a bounded retry; nothing is
  silently dropped.

## Architecture

```
Messenger (conversation titled "CMS Assistant")
   └─ harpp watch daemon
        └─ cms lane (harpp_wake.maybe_wake_cms)
             └─ spawns a headless agent (deepseek/groq)
                  └─ task-contract-cms.md (draft-only rules)
                       └─ harpp cms <op> → cms_client.py
                            └─ CMS REST API (Authorization: Bearer <service-token>)
                                 └─ content/builder endpoints (draft-forced)
```

- Lane routing: `tools/harpp-bridge/harpp_wake.py` (`maybe_wake_cms`, `is_cms_item`,
  `cms_config`, `_record_cms_usage`).
- Agent task contract: `tools/harpp-bridge/wake/task-contract-cms.md`.
- CMS API client: `tools/harpp-bridge/cms_client.py` (forces `status=draft`).
- CMS auth: Bearer service token (sha256-hashed) minted by `php ikabud cms:service-token`.
- Contracts: `docs/architecture/cms-assistant-contract.md`.

## Setup (one time, per tenant)

On the **app server** (per tenant), mint a service token:

```bash
php ikabud cms:service-token create <tenant_id|domain> 'CMS Assistant'
```

On this **desktop**:

```bash
harpp cms set base_url https://harpp.ikabudkernel.com
harpp cms set token <raw-token>
harpp cms set model deepseek/deepseek-v4-pro   # or groq/<model>
harpp cms enable
harpp cms status                               # verify
```

Then create a conversation titled **"CMS Assistant"** in the messenger. Send your request there.

## Usage

Send a plain message in the "CMS Assistant" conversation, e.g.:

- "Create a draft post about our new launch"
- "Update post 42: change the title to ..."
- "Redesign page 7: make the hero section wider"
- "Draft a page titled 'About Us' with a contact section"

The assistant replies over the bridge with a summary (content id + status `draft`) and a
`Next step:` line. To make something public, open the CMS admin and publish the draft yourself.

## CLI

```
harpp cms setup                     # interactive guidance
harpp cms status                    # lane config + cms_usage ledger
harpp cms enable|disable            # toggle the lane
harpp cms set <key> <value>         # enabled|conversation_title|model|base_url|token|timeout|cooldown|max_per_hour
harpp cms content-create --title X --body Y [--type post]
harpp cms content-update <id> [--title X] [--body Y]
harpp cms content-get <id>
harpp cms content-list [--q TERM] [--type post] [--status draft] [--limit N]
harpp cms drafts                    # list assistant drafts awaiting human review
harpp cms page-get <id>
harpp cms page-save <id> --document-file <file>
```

The `content-*`/`page-*` operations are what the assistant agent uses; the CLI just proxies to
`cms_client.py`.

## Boundaries (enforced)

- `model` must be `deepseek/*` or `groq/*`; `openai-codex/*` and `openai-ideation/*` are refused.
- Service tokens can never hold `content.publish`, `builder.publish`, or `content.schedule`.
- The client forces `status=draft` on every write; `cmsCanPublish()` is `false` for service users.
- Publishing, scheduling, trashing, deleting, settings, users, menus, redirects, import/export,
  and theme customizer are out of scope.

## Finding content (list/search)

`content-list` searches the CMS API by term, type, status, and author:

```
harpp cms content-list --q "launch" --type post --status draft --limit 10
harpp cms content-list --type page --status draft --limit 25
```

The agent uses this to resolve owner references without an id (e.g. "update the post about
the launch").

## Review loop

After each run the assistant replies with the draft id + status and a `Next step:` line.
To list everything awaiting your review:

```
harpp cms drafts
```

This shows drafts authored by the service user with the CMS admin link to review/publish.

## Ledger

`harpp cms status` shows `cms_usage` (`count`, `last`, `models`, `messages`). This is a separate
ledger from dev and ideation, so CMS-assistant activity never pollutes the dev quota history.

## Known gaps (tracked)

- **Media upload** (`media.upload` is granted to the token, but no client op yet): the upload
  endpoint is POST + multipart (`$_FILES`), which the shared-host ModSecurity blocks and PHP
  doesn't populate for PUT. Closing it needs a JSON/PUT upload route or session-based upload —
  tracked as a low-priority follow-up. Content without a featured image is still creatable.
- **Service token storage**: the raw token sits in `~/.config/harpp/config.json` (mode 0600,
  owner-only). Acceptable for a single-user desktop; if you prefer, set `CMS_TOKEN`/`CMS_BASE_URL`
  env vars instead (`cms_client.py` reads `["cms"].token`/`["cms"].base_url`; env support is a
  small follow-up).
- **ModSecurity**: see `docs/architecture/harpp-cms-modsecurity-allowlist.md` — the PUT aliases
  unblock the assistant; a cPanel/hosting allowlist makes native POST work too.

## Restarting the daemon

After deploying client changes, restart the always-on daemon so it loads the new code:

```bash
systemctl --user restart harpp-watch.service
```

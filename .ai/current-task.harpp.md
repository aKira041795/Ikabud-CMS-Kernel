---
task: HARPP — Harness App (Decision Center + Messenger) tenant module
objective: >
  Connect the local AI harness (VSCode + Pi/Copilot + session-resume reconnect) to the
  operator through a hosted messenger. The harness submits decision-requests and session
  updates via a bridge; HARPP notifies the operator on phone/gadget (PWA + Web Push);
  the operator replies with decisions/instructions in a messenger-like PWA; HARPP records
  decisions as durable ADRs and relays them back so the harness resumes (via session-resume
  saved state). Deployed as a tenant-owned Ikabud module on Bluehost.

scope:
  allowed:
    - New tenant module `modules/harpp/` (id `harpp`) with own tables in the tenant DB.
    - Auth-owned entry module: users + password resets + JWT cookie auth (`harpp_token`),
      roles owner/admin/member, forgot-password flow, per-tenant selection.
    - Domain tables: harpp_users, harpp_password_resets, harpp_conversations,
      harpp_messages, harpp_decisions, harpp_notifications, harpp_push_subscriptions,
      harpp_adrs, harpp_settings.
    - Decision lifecycle state machine: CREATED→PENDING→NOTIFIED→VIEWED→DECIDED→
      ACKNOWLEDGED→APPLIED→CLOSED (+ EXPIRED/SUPERSEDED/CANCELLED), aligned to
      DevelopmentLifecycle states (ARCHITECTURE_DECISION_REQUIRED etc.).
    - Messenger PWA (DiSyL + service worker + Web Push via VAPID): conversation list,
      thread view, compose, unread badges, push notifications.
    - REST JSON API for the PWA + a harness bridge endpoint (REST now, MCP adapter later)
      for decision-requests, updates, polling, and applied-ack.
    - Role-based permission matrix + per-tenant module settings (push toggle, channels).
    - ADR memory (durable, immutable decision records).
    - Capability handlers in helpers.php; entity views for conversation/message/decision.
  prohibited:
    - No kernel core, other-module, or cross-module DB changes.
    - No weakening of existing auth/tenant boundaries.
    - No SMS/email channels in v1 (future); PWA + Web Push only.
    - MySQL 5.7 compatibility maintained (InnoDB utf8mb4; no window fns/CTEs).
    - No secrets in code — VAPID keys/credentials via env.

auth:
  auth_owned:
    users_table: harpp_users
    id_column: id
    role_column: role
    email_column: email
    password_column: password_hash
    name_column: full_name
    active_column: is_active
    admin_roles: [owner, admin]
    default_admin_role: owner
    requires_named_admin_on_provision: true
  auth_cookie: harpp_token
  login: app()->jwt()->generate(); payload includes user_id + store_id.

capabilities:
  exposes:
    - kernel.auth.authenticate@1 (pipeline)
    - harpp.read@1
    - harpp.manage@1
    - harpp.decision.review@1
    - harpp.notify@1
    - harpp.bridge@1
    - entity.list.harpp_conversation@1
    - entity.list.harpp_message@1
    - entity.list.harpp_decision@1
  depends:
    - kernel.audit.record@1
    - kernel.auth.user@1

tables (harpp_ prefix, tenant DB, InnoDB utf8mb4_unicode_ci):
  - harpp_users(id, email, password_hash, full_name, role enum(owner,admin,member), is_active, created_at, updated_at)
  - harpp_password_resets(id, user_id, token, expires_at, used_at, created_at)
  - harpp_conversations(id, title, harness_session_id, status enum(open,closed), created_by, created_at, updated_at)
  - harpp_messages(id, conversation_id, sender_type enum(user,harness,system), sender_user_id, body, payload json, read_at, created_at)
  - harpp_decisions(id, decision_key unique, conversation_id, title, body, lifecycle_state enum, escalation_class, risk_level, options json, payload json, created_at, notified_at, decided_at, decision, decided_by, applied_at, closed_at)
  - harpp_notifications(id, user_id, decision_id, channel enum(push), status enum(pending,sent,delivered,failed), payload json, created_at, sent_at)
  - harpp_push_subscriptions(id, user_id, endpoint, keys json, created_at)
  - harpp_adrs(id, adr_key, title, body, decision_ref, created_at, decided_at, superseded_by)
  - harpp_settings(id, setting_key, setting_value, updated_at)

routes (declarative):
  web: login, messenger (conversations, thread), decision inbox, settings, notifications
  api /api/v1/harpp/*: auth/login, conversations CRUD, messages, decisions (list/get/decide/close), notifications/subscribe, bridge/* (decision-request, update, poll, ack)

phases:
  1. scaffold: module.json + migration 001 (all tables) + user seed + capability map
  2. service + auth: services (conversation/message/decision/notification/adr), JWT login, forgot password, role guards, settings
  3. handlers + routes: REST + web routes + handlers
  4. PWA messenger + push: DiSyL UI + service worker + VAPID web push + notifications
  5. harness bridge: REST bridge + decision lifecycle wiring (+ MCP adapter later)
  6. tests + review + tenant:migrate + Bluehost deploy package

acceptance:
  - Operator logs in via PWA, sees conversations, sends/receives messages.
  - Harness (bridge) submits a decision-request → operator push-notified on phone → opens
    HARPP → decides → decision recorded as ADR → bridge polls, gets decision, relays →
    harness resumes.
  - Roles enforced: member cannot close others' decisions; owner/admin can.
  - php ikabud tenant:migrate <tenant> harpp works; module loads clean (no app/error.log warnings).
  - MySQL 5.7 compatible.

status: READY_FOR_IMPLEMENTATION (phase 1)

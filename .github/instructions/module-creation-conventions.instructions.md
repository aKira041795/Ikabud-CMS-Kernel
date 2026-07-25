---
description: "New module conventions — user seeding, DiSyL syntax, JWT auth, role access, forgot password, capability handler placement. Must-follow rules when creating or reviewing any new module."
applyTo: "**/*.php **/*.disyl **/module.json"
---
# New Module Instructions

## User Seeding
- Role ENUM must include ALL roles upfront. Use `migration 020 → ALTER TABLE` only if missed.
- Seed users: one per role with bcrypt hash, email, and store_id.
- `module.json` `auth_owned.email_column` must be `"email"`.

## DiSyL Syntax
- `{block name}` — NO quotes around block name. `{block "name"}` breaks silently.
- `{ikb_entity_view name="X" view="Y"}` — config uses `name=` not `source=`.
- `{ikb_entity_list source="X" view="Y" /}` — template tag uses `source=` with self-closing ` /`.
- No `$` prefix on variables: `{user.name}` not `{$user.name}`.
- Use `|number_format:2`, `|capitalize`, `|date:"M d, Y"` — not PHP functions.

## Capability Handlers
- MUST be loaded from `helpers.php` (not `handlers.php`).
- Module manager checks `is_callable()` at registration time — handlers.php runs too late.

## JWT Auth
- Login: generate JWT via `app()->jwt()->generate()`, set cookie. DO NOT use `$ctx->setUser()`.
- Payload MUST include `user_id` and `store_id` — handlers access these keys.
- Logout: clear cookie. DO NOT use `$ctx->logout()`.

## Forgot Password
- Table: `<prefix>_password_resets`. MUST be in `module.json` `owns_tables`.
- Reset URL is logged to `storage/logs/app.log` via `write_log()` — no mail server needed in dev.

## Post-Creation
1. Run `php ikabud tenant:migrate <domain> <module>`
2. Check app.log for capability warnings
3. Test login with each seeded role
4. Check app.log for `reset_url` after forgot password
5. Verify `/admin/tenants` push handles the module's user table

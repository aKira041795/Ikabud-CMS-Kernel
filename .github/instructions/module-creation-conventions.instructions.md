---
description: "New module conventions — user seeding, DiSyL syntax, JWT auth, role access, forgot password, capability handler placement. Must-follow rules when creating or reviewing any new module."
applyTo: "**/*.php **/*.disyl **/module.json"
---
# New Module Instructions

## User Seeding
- Role ENUM must include ALL roles upfront. Use `migration 020 → ALTER TABLE` only if missed.
- Seed users: one per role with bcrypt hash, email, and store_id.
- `module.json` `auth_owned.email_column` must be `"email"`.
- `module.json` `auth_owned` MUST declare `id_column` (matches table PK, e.g. `"user_id"`) and `role_column` (e.g. `"role"`). The kernel tenant admin push handlers use these to build SQL — missing `id_column` causes the table to be silently skipped on email/password changes.

## Standalone Entry Modules
- Any new module that owns users/auth is a standalone tenant-entry module, not a shared helper module.
- Such modules MUST declare `auth_owned` in `module.json` and MUST be selected in Admin > Tenants for each tenant that should receive that module bundle.
- If the module is intended to be the default tenant bundle, make that default explicit in the manifest/docs rather than relying on hidden assumptions.
- If the module is not an auth-owning entry module, keep it dependency-only and do not expect it to appear as a tenant dropdown default.
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
- Reset URL is logged to `storage/logs/app.log` via `write_log()` — no mail server needed in dev.- **Build the reset URL using the request host**, not `config('app.url')` — `APP_URL` may differ from the actual domain.
  ✅ `$scheme . '://' . $_SERVER['HTTP_HOST'] . '/reset-password?token=' . $token`
  ❌ `config('app.url') . '/reset-password?token=' . $token`
- **Send a branded email** using `buildEmailTemplate()` + `sendEmail()` — see skill for full pattern. Always check `function_exists()` before calling. The URL is still logged to `app.log` as fallback.
## Post-Creation
1. Run `php ikabud tenant:migrate <domain> <module>`
2. Check app.log for capability warnings
3. Test login with each seeded role
4. Check app.log for `reset_url` after forgot password
5. Verify `/admin/tenants` push handles the module's user table

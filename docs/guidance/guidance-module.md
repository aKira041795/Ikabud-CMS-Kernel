# Guidance Module

The Guidance module is a tenant-scoped counseling workspace for case management, appointments, public booking, notifications, reports, and staff administration. Its runtime source of truth is the consolidated pair [modules/guidance/routes.php](../../modules/guidance/routes.php) and [modules/guidance/handlers.php](../../modules/guidance/handlers.php).

## Auth and reset contract

Guidance is an auth-owned module. Its `auth_owned` manifest block points the kernel at `gm_users`, so tenant provisioning and kernel admin password-push can target module-owned staff accounts without Guidance-specific kernel code.

Guidance now follows the same self-service password reset contract used by the newer auth-owned modules:

- guest pages: `GET /guidance/forgot-password` and `GET /guidance/reset-password`
- canonical browser APIs: `POST /api/v1/guidance/auth/forgot-password` and `POST /api/v1/guidance/auth/reset-password`
- legacy `/guidance/api/auth/*` aliases are retained for backward compatibility
- forgot-password returns generic success to avoid account enumeration
- reset links expire after 60 minutes and the reset page validates the token before rendering the form
- successful resets return `{ok: true, message: ..., redirect: '/guidance/login'}`

Kernel admin password-push remains the trusted recovery path for tenant admins. Self-service forgot/reset is for module users, not a replacement for the kernel recovery surface.

## Main routes

- login: `/guidance/login`, `/guidance/auth/login`
- forgot/reset: `/guidance/forgot-password`, `/guidance/reset-password`
- admin workspace: `/admin/guidance`, `/admin/guidance/cases`, `/admin/guidance/appointments`, `/admin/guidance/reports`, `/admin/guidance/trackers`, `/admin/guidance/users`, `/admin/guidance/settings`
- public booking: `/guidance/book`, `/guidance/book/api/booking`

## Operational notes

- `gm_users`, `gm_password_resets`, and `gm_rate_limits` are Guidance-owned tables and must stay in `owns_tables`.
- Guidance guest templates should target the canonical `/api/v1/guidance/auth/*` endpoints even though the legacy aliases still exist.
- If future auth changes are needed, update [modules/guidance/routes.php](../../modules/guidance/routes.php), [modules/guidance/handlers.php](../../modules/guidance/handlers.php), and the guest templates together.

## Validation

Use the focused regression test:

```bash
php tests/guidance_password_reset_test.php
```

For broader Guidance coverage, also run the existing Guidance tests under [tests](../../tests).
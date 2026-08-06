# Ikabud Installer — WordPress-Parity UX Review & Redesign Plan

> **Review Date:** August 6, 2026
> **Scope:** Fresh-install process (`public/lock.php`, `public/index.php` not-installed guard, `scripts/test-install-http.php`, `docs/kernel/installation.md`)
> **Reviewers:** Senior architect + designer synthesis
> **Reference:** WordPress 6.x installer (`wp-admin/install.php`) — the usability benchmark requested

---

## Executive Summary

The current Ikabud installer is **functionally correct but not WordPress-simple**. It completes a real install (auto-creates the DB, runs migrations + seeds, writes `.env`, seeds admin accounts, locks itself), but it presents **all configuration on one long scrolling form** — including advanced multi-tenant control-plane fields — and it **does not let the user set the domain name** (it is silently derived from the request host). Against the four requested criteria:

| Requirement | Current state | Verdict |
|---|---|---|
| 1. User can set the domain name | ❌ No field — `APP_URL` is auto-derived from `$_SERVER['HTTP_HOST']` + base path | **Gap** |
| 2. User sets DB + password | ✅ Present, but 4 fields + 6 control-plane fields shown by default | Partial — needs decluttering |
| 3. User sets admin username + password | ✅ Present (username, email, name, password, confirm) | Partial — needs decluttering |
| 4. WordPress-like, intuitive, easy | ⚠️ Single long form; no welcome step, no progress indicator, no step-by-step linear flow | **Gap** |

**Verdict: CONDITIONAL PASS with a required UX redesign.** The backend install machinery should be preserved as-is (it is proven and safe); only the **front-end flow and input surface** need to be reshaped to a WordPress-style 4-step wizard, plus one new capability (user-settable domain). One additional defect was found: `scripts/test-install-http.php` is out of sync with `lock.php`'s installed-guard behavior and would fail on an installed system.

---

## 1. Current Process Walkthrough

```mermaid
flowchart TD
    A[Fresh server: no .env, no storage/.installed] --> B[Any request to /]
    B --> C["public/index.php: 302 -> /lock.php<br/>(health endpoint exempt)"]
    C --> D["public/lock.php: single long form"]
    D --> D1[Database: host, port, name, user, pass + Test Connection]
    D --> D2[Multi-tenant: checkbox + host, port, name, user, pass, enc key]
    D --> D3[Admin: username, email, full name, password, confirm]
    D --> E[POST step=install]
    E --> F[Validate input]
    F --> G[Connect MySQL -> auto CREATE DATABASE -> USE]
    G --> H[Run 001_full_schema.sql statement-by-statement]
    H --> I["Bootstrap app -> run _kernel + _control + module migrations & seeds"]
    I --> J[Seed admin into users + dl_admins + cms_users + gm_users + branches]
    J --> K[Derive APP_URL from HTTP_HOST + base path (no user input)]
    K --> L["Write .env atomically (backup prior .env) + JWT secret"]
    L --> M[Write storage/.installed marker]
    M --> N[Success screen: Go to Login]
    N --> O["Post-install: admin deletes public/lock.php"]
```

**Key files:**
- `public/lock.php` — web installer: input collection/validation, `test_db` AJAX, `install` POST, migration/seeding, `.env` write, install lock.
- `public/index.php` (lines 123–135) — not-installed guard: 302 → `/lock.php` when both `storage/.installed` and `.env` are absent; `/api/v1/health` exempt.
- `scripts/test-install-http.php` — HTTP smoke test for the installer endpoint.
- `docs/kernel/installation.md` — install docs (quick deploy + manual).

**What the current installer already does well (preserve these):**
- ✅ Auto-creates the database (no cPanel step required) with a clear success message.
- ✅ "Test Connection" AJAX button with a human-readable result.
- ✅ Auto-derives a sensible default URL from the request host.
- ✅ Generates a fresh `JWT_SECRET`.
- ✅ Idempotent re-runs (skips "already exists"), backs up prior `.env` to `storage/backups/`, atomic temp-file+rename write with `0640` perms.
- ✅ Seeds the admin consistently across `users`, `dl_admins`, `cms_users`, `gm_users`, and creates the default branch.
- ✅ Installed guard + "delete `lock.php`" security reminder.

---

## 2. Findings

Severity rubric: **HIGH** = blocks a core requirement / broken contract; **MEDIUM** = usability or robustness gap; **LOW** = polish / docs.

### 2.1 HIGH — No way to set the domain name (Req 1 not met)
- `public/lock.php` derives `APP_URL` from `$_SERVER['HTTPS']` + `HTTP_HOST` + base path. There is **no form field** for it.
- Consequence: if the user installs over `http://IP:8080` or a temp host, or wants a canonical domain different from the request host (e.g. installing via `www` then serving apex, or a shared-hosting subfolder rewrite), every generated link, cookie name, redirect, and tenant host uses the wrong URL. The current workaround is editing `.env` by hand after install — exactly what the wizard should remove.
- **Fix (design):** add a "Site Address (domain)" field, pre-filled with the detected host, validated, and used to build `APP_URL`.

### 2.2 HIGH — Single long form ≠ WordPress wizard (Req 4 not met)
- All ~15 fields render on one page with no welcome step, no progress indicator, and no linear story.
- Advanced multi-tenant control-plane config (6 fields + encryption key) is shown by default to a user who almost always wants single-tenant.
- **Fix (design):** 4-step linear wizard (Welcome → Database → Site & Admin → Install), with control-plane config collapsed under "Advanced options" — mirroring how WordPress hides table prefix.

### 2.3 MEDIUM — `scripts/test-install-http.php` is out of sync with `lock.php`
- The smoke test asserts that when installed, `lock.php` returns **HTTP 403** with body **"System already installed"**, and it also requests `lock.php?force=1`.
- The actual `lock.php` returns **HTTP 200** with **"The application is already installed and running."** and does not handle `?force=1` at all.
- Result: the smoke test fails on any installed system — the test contradicts the implementation.
- **Fix:** pick one contract. Recommend `lock.php` returns **403** with "System already installed" and honors `?force=1` (only to show a warning, not to allow reinstall), then keep the test aligned. This is a deliberate security posture improvement.

### 2.4 MEDIUM — No install-time requirements/preflight
- The installer fails mid-way if a required extension (e.g. `pdo_mysql`, `zip`, `mbstring`) or writable `storage/` is missing. WordPress-style installers surface a friendly "requirements check" screen before asking for credentials.
- **Fix (design):** Welcome step runs a preflight (PHP version, required extensions, storage writability) and shows pass/fail before DB fields.

### 2.5 LOW — Admin step asks for more than WordPress
- WordPress asks: username, password (with strength meter), email. Ikabud asks username, **email, full name**, password, confirm.
- Full name + email are useful for seeding module admins, so keep them — but move "full name" to an optional/advanced field and let email be validated but not blocking unless needed.
- Password field already has show/hide + match hint; add a strength meter (WP parity).

### 2.6 LOW — Docs describe the current flow but not the domain story
- `docs/kernel/installation.md` documents `lock.php` correctly but says nothing about setting the domain, and will need updating when the wizard lands.

---

## 3. WordPress Reference Mapping

| Aspect | WordPress | Ikabud today | Ikabud target |
|---|---|---|---|
| Entry | `wp-admin/install.php` (also auto-redirect) | `lock.php` via `index.php` 302 | Keep auto-redirect |
| Welcome | Language select + branding | None | Branding + requirements preflight |
| Database step | Name, User, Password, Host (4 fields); prefix hidden | 4 fields + 6 control-plane fields | 4 fields; control-plane under Advanced |
| Site step | Site title, admin username, password, email | No site title; 5 admin fields | Domain + admin username/password (+ email, optional name) |
| Install | One-click, then success | One-click, success | One-click with step progress + success |
| Progress | 4 numbered steps | Single form | 4 numbered steps with progress indicator |
| Post-install | "Success!" → login with entered creds | Success → login | Success → login (same) |

---

## 4. Redesigned Wizard (Implementation-Ready Spec)

Keep the proven backend in `public/lock.php` untouched (validation rules, `test_db` AJAX, `install` POST handler, migration/seeding, `.env` write, lock marker). Re-skin the **front end** into a stateful wizard and add one field.

### Step 1 — Welcome & Requirements
- Branding panel ("Ikabud Kernel APP OS"), short blurb.
- **Requirements preflight** (server-side, rendered at GET): PHP ≥ 8.2, `pdo_mysql`, `mbstring`, `openssl`, `session`, `zip`, `storage/` writable, `public/` writable. Each row green check / red X. All green → "Continue" enabled.
- Optional: language select placeholder (single-language today; omit to avoid fake features).

### Step 2 — Database
- Fields (WordPress order): **Database Name**, **Database User**, **Database Password**, **Database Host** (default `localhost`), **Port** (default `3306`).
- "Test Connection" button (existing AJAX, reused) with inline green/red result.
- Collapsed **"Advanced options"** disclosure:
  - Multi-tenant mode toggle → reveals control-plane host/port/name/user/pass/enc-key fields (current behavior preserved; when toggled off the control DB mirrors primary DB as today).
- Back / Continue.

### Step 3 — Site & Admin
- **Site Address (Domain)** — NEW. Pre-filled with detected `scheme://host` (+ base path when served from a subfolder). Validated (host regex — reuse `installerSanitizeHost`). Used to build `APP_URL`. Helper text: "The URL users will visit this site at."
- **Admin Username** (min 3 chars, `[a-zA-Z0-9_]`).
- **Admin Password** + confirm, with show/hide, match hint, and a **strength meter** (weak/fair/strong).
- **Admin Email** (validated).
- **Admin Full Name** — optional/advanced (still seeded to module admins).
- Back / Continue.

### Step 4 — Install & Success
- "Install Ikabud" primary button → POST `step=install` (existing handler) with all wizard state.
- On success: success panel → "Go to Login" (reuse current links). On error: return to the failing step with the error listed inline (server already returns `$errors`; wizard maps them back to fields).
- Post-install: keep the "Delete `public/lock.php`" security reminder and the "reinstall requires removing `storage/.installed`" note.

### State handling
- Use a single `<form>` with hidden fields persisting prior steps' values (no server-side session needed — matches current stateless POST design and keeps the smoke test compatible).
- JS: step tabs/progress bar, per-step "Continue" validation, `data-*` attributes for test_db + strength meter; degrade gracefully to the full form if JS is off (all steps rendered, only navigation hidden) — important for shared-host/older browsers.

---

## 5. Implementation Plan

| # | Change | File | Notes |
|---|---|---|---|
| 1 | Add domain field + `APP_URL` override | `public/lock.php` | Collect `domain` input; when non-empty validate with `installerSanitizeHost`-style regex and use it (with scheme + base path) instead of `HTTP_HOST` in `$managedEnv['APP_URL']`. |
| 2 | Wizard front end (4 steps + progress) | `public/lock.php` | Keep all existing input `name` attributes; reorder markup into step sections; CSS/JS for tabs, preflight, strength meter; control-plane under Advanced disclosure. |
| 3 | Requirements preflight | `public/lock.php` | Server-side check list rendered in Step 1 (extension + writability). |
| 4 | Align installed-guard contract | `public/lock.php` | Return **403** + "System already installed" when installed; support `?force=1` (warn-only). |
| 5 | Update smoke test | `scripts/test-install-http.php` | Keep assertions matching the 403 + message contract (after #4). |
| 6 | Update docs | `docs/kernel/installation.md` | Document the 4-step wizard + domain field + post-install steps. |
| 7 | Regression test | `tests/` | Add a lock.php-focused integration test: preflight passes on a healthy env; `test_db` AJAX returns valid JSON; installed guard = 403. |

### Validation
- `php -l public/lock.php`
- `php scripts/test-install-http.php` (fresh + installed states)
- Manual browser pass: fresh server → wizard → install → login; verify `APP_URL` honors the entered domain (cookie name + generated links).
- Check both `storage/logs/app.log` and `storage/logs/error.log` after the run.

---

## 6. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Wizard JS disabled (shared hosting, old browsers) | Progressive enhancement: all fields on one page, JS only drives navigation/progress. |
| User enters an invalid/typo'd domain | Pre-fill detected host; validate host regex; warn if the entered host differs from the request host. |
| Domain set to a host not actually served here | Document that the domain must point at this install; keep auto-detected default. |
| Multi-tenant config now hidden | Advanced disclosure persists all current fields/behavior; no loss of capability. |
| Changing installed-guard to 403 | Smoke test updated in lockstep; no app code depends on 200. |
| `APP_URL` change breaks cookie/redirects | Reuse the existing `installerSanitizeHost` + base-path logic; only the host source changes. |

---

## 7. Acceptance Criteria

- [ ] Step 1 shows requirements preflight; blocks Continue on failure.
- [ ] Step 2 collects DB name/user/password/host (+port) and "Test Connection" works.
- [ ] Multi-tenant/control-plane config is behind an Advanced disclosure and still functions when enabled.
- [ ] Step 3 lets the user set the **domain name**; it drives `APP_URL` in the generated `.env`.
- [ ] Step 3 collects admin username + password (with strength meter) and seeds the admin as today.
- [ ] Step 4 installs and shows a success screen; `storage/.installed` written; reinstall guarded.
- [ ] Installed `lock.php` returns 403 "System already installed"; `scripts/test-install-http.php` passes in both fresh and installed states.
- [ ] No new critical/fatal/error entries in `storage/logs/app.log` or `storage/logs/error.log`.

---

## 8. Bottom Line

The install backend is solid and should not be rewritten. The gap is **UX shape and one missing input**: (a) reshape to a 4-step WordPress-style wizard, (b) add a user-settable domain, (c) demote control-plane/multi-tenant to Advanced, (d) add a requirements preflight, and (e) fix the stale smoke-test contract. Estimated effort is small (single-file front-end re-skin + one field + test/doc alignment) and carries no schema or routing risk.

---

## Implementation Status (2026-08-06)

Implemented as reviewed:

- `public/lock.php` — 4-step wizard UI (Requirements → Database → Site & Admin → Install) with progress indicator; user-settable `site_url` (domain) driving `APP_URL` via a new `installerNormalizeSiteUrl()` helper; requirements preflight (`installerRequirementsPreflight()`); advanced control-plane disclosure; password strength meter; and a **403** installed-guard (`?force=1` is warn-only).
- `tests/lock_installer_wizard_test.php` — 39-assertion contract test (installed guard, wizard markers, domain normalizer behavior).
- `scripts/test-install-http.php` — passes as-is now that `lock.php` returns 403 + "System already installed" (previously out of sync with the 200 response).
- `docs/kernel/installation.md` — updated for the wizard + site address field.

Validation: `php -l` clean on both files; `php tests/lock_installer_wizard_test.php` → 39 passed, 0 failed; `php scripts/test-install-http.php` → all checks passed; no new errors in `storage/logs/app.log` / `storage/logs/error.log`.

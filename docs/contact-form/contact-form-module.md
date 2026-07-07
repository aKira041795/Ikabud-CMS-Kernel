# Contact Form Module

**Module ID:** `contact-form`
**Version:** 1.6.0
**Author:** Test Author
**Depends on capabilities:** None
**CMS Integration:** yes — registers 5 CMS hooks for admin nav, editor blocks, builder renderers, public render, and public head injection.

---

## Overview

The Contact Form module provides a lightweight, CMS-integrated form builder with anti-spam protection. It supports:

- Drag-and-drop page builder block integration
- Dynamic multi-field forms with conditional logic
- Honeypot and CAPTCHA spam protection
- Database-backed submission storage with export
- Email notifications on submission
- Workflow integration for submission status tracking

All database tables (`contact_form_submissions`, `contact_forms`, `contact_form_fields`) live in the tenant's database.

---

## Manifest Summary

Source: [modules/contact-form/module.json](../../modules/contact-form/module.json)

| Field | Value |
|---|---|
| `owns_tables` | `contact_form_submissions`, `contact_forms`, `contact_form_fields` |
| `hooks` | `cms.admin.nav_items`, `cms.editor.block_types`, `cms.builder.renderers`, `cms.public.render_content`, `cms.public.head` |
| `routes` | `true` |
| `events` | None declared |
| `migrations` | 7 files |

### Capability

| Capability | Mode | Priority | Purpose |
|---|---|---|---|
| `contact_form.submit@1` | `first` | 100 | Process a contact form submission. Validates input, applies spam protection, stores in DB, and returns the result. |

Registered in [modules/contact-form/helpers.php](../../modules/contact-form/helpers.php) via `contact_form_capability_handlers()`.

---

## Database Architecture

Schema is shipped as 7 migrations under [modules/contact-form/migrations/](../../modules/contact-form/migrations):

| Migration | Purpose |
|---|---|
| `001_contact_form_submissions.sql` | Initial submissions table. |
| `002_contact_forms_and_fields.sql` | Saved forms and field definitions. |
| `003_contact_form_submissions_v130.sql` | Submission schema enhancements. |
| `004_contact_form_editor_and_submission_upgrades.sql` | Editor and submission upgrades. |
| `005_contact_form_submit_label.sql` | Submit button label support. |
| `006_contact_form_field_conditional_logic.sql` | Conditional field logic. |
| `007_contact_form_submission_workflows.sql` | Workflow integration for submission status. |

---

## Routes

Source: [modules/contact-form/routes.php](../../modules/contact-form/routes.php)

All routes are under the CMS admin prefix. There is no separate auth — the module uses CMS authentication.

### Admin Pages (GET)
- `/cms/admin/contact-forms` — form list
- `/cms/admin/contact-forms/create` — new form builder
- `/cms/admin/contact-forms/{id}/edit` — edit form
- `/cms/admin/contact-forms/{id}/preview` — form preview
- `/cms/admin/contact-forms/submissions` — submissions list
- `/cms/admin/contact-forms/submissions/export` — export submissions
- `/cms/admin/contact-forms/submissions/{id}` — submission detail

### API
- `GET /api/v1/contact-form/captcha` — generate CAPTCHA challenge
- `POST /api/v1/contact-form/submit` — public form submission endpoint
- `POST /cms/admin/contact-forms/create`, `/cms/admin/contact-forms/{id}/edit`, `/cms/admin/contact-forms/{id}/delete` — form CRUD
- `POST /cms/admin/contact-forms/{id}/fields/create`, `.../reorder`, `.../{fieldId}/save`, `.../{fieldId}/delete` — field CRUD
- `POST /cms/admin/contact-forms/submissions/{id}/status` — update submission status

---

## CMS Integration Hooks

The module registers 5 CMS hooks:

| Hook | Purpose |
|---|---|
| `cms.admin.nav_items` | Adds "Contact Forms" to the CMS admin sidebar navigation. |
| `cms.editor.block_types` | Registers the contact form as an available block type in the page builder editor. |
| `cms.builder.renderers` | Provides server-side renderer for the contact form builder block. |
| `cms.public.render_content` | Renders the contact form on public pages when embedded via builder. |
| `cms.public.head` | Injects CAPTCHA challenge script/styles into public page `<head>`. |

---

## Settings

| Key | Type | Default | Notes |
|---|---|---|---|
| `recipient_email` | email | `""` | Where submission notifications are sent. Leave blank to disable email. |
| `email_subject` | text | `New Contact Form Submission` | Email subject prefix. Sender's name is appended automatically. |
| `success_message` | text | `Thank you for your message. We will get back to you soon.` | Shown after successful submission. Overridable per form in the builder. |
| `spam_protection` | select | `honeypot` | Options: `honeypot` (recommended), `none`. Honeypot adds a hidden field that bots fill in, triggering silent rejection. |
| `store_submissions` | select | `1` | `Yes` saves to `contact_form_submissions` table; `No` only sends email. |

---

## Anti-Spam Integration

The module integrates with the anti-spam module via `antispamHoneypotTriggered()`:

```php
$honeypotTriggered = function_exists('antispamHoneypotTriggered')
    ? antispamHoneypotTriggered($input, '_hp_name')
    : !empty($input['_hp_name']);
```

When honeypot protection is enabled and the hidden `_hp_name` field is filled (bot behavior), the submission is **silently accepted** — the success message is returned but no data is stored and no email is sent. This prevents bots from knowing they were detected.

For saved forms with CAPTCHA enabled, a math-based CAPTCHA challenge is generated via `/api/v1/contact-form/captcha` and validated on submission.

---

## Submission Flow

```
POST /api/v1/contact-form/submit
  ↓
contact_form_cap_submit_1()             ← capability handler
  ├─ antispamHoneypotTriggered()        ← honeypot check (anti-spam module)
  ├─ contactFormGetFormById()           ← resolve saved form definition
  ├─ contactFormVerifyCaptcha()         ← CAPTCHA validation (if enabled)
  ├─ contactFormPrepareDynamicSubmission() or legacy
  ├─ INSERT INTO contact_form_submissions ← store (if enabled)
  └─ return {ok: true, message: "…"}
```

The handler is exposed as `contact_form.submit@1` on the CapabilityBus at priority 100, so other modules can submit forms programmatically.

---

## File Structure

```
modules/contact-form/
  module.json          — manifest
  routes.php           — route map
  handlers.php         — all handler functions (single file; no handlers/ subdirectory)
  helpers.php          — capability handler, form helpers, DB access, captcha helpers
  migrations/          — 7 SQL migration files
```

All admin form management, field CRUD, submission listing, export, and the public submit endpoint are handled within `handlers.php`.

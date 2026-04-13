# Guidance Module Conversion

This document details the conversion of the standalone guidance app into a freemium module for the Ikabud Kernel platform.

## Freemium Tier Split

### FREE Tier
- **Case Management** — Full CRUD, status workflow, severity, categories, counselor assignment, history
- **Basic Appointments** — Internal scheduling with types, time slots, working hours, conflict detection
- **Public Booking** — Student-facing portal, college/type/date/slot selection (no OTP)
- **Dashboard** — Active cases, today's appointments, basic stats
- **User Management** — CRUD for admin/supervisor/counselor
- **College Management** — CRUD + counselor assignment
- **Basic Settings** — Working hours, timezone, retention years
- **Auth** — Login/logout, JWT, basic password management
- **Profile** — Update own profile

### PRO Tier
- **Counselor Notes** (MSE/4Ps, risk assessment, mood, interventions)
- **Tracker System** (document tracking, CSV import/export, smart column mapping)
- **DOCX Reports** (case summary, appointment list via PHPWord)
- **AI Narrative Reports** (OpenAI integration)
- **Notifications** (in-app bell, email delivery, queue)
- **2FA/OTP** (email OTP for staff and student booking)
- **File Attachments** (upload/download per case)
- **Audit Logging** (all mutations logged)
- **Form Field Customization** (admin-configurable per form)
- **Advanced Analytics** (trends, caseload, overdue follow-ups)
- **Calendar Views** (week/month)
- **Password Reset** (token-based with email)
- **Health Monitoring** (/api/guidance/health)
- **Counselor Availability** (multi-range per day, blocked dates)
- **Appointment Types Management** (custom definitions)
- **Rate Limiting** (DB-backed on auth)

## Conversion Decisions

1. **Full rewrite approach**: All handlers rewritten from standalone's `src/routes/*.php` implementations, not patched incrementally
2. **Freemium tier**: First module to implement explicit Free vs Pro gating using kernel entitlements.
3. **SMS Sub-module**: Standalone SMS capabilities moved to a separate `modules/guidance-sms/` paid sub-module that hooks into guidance events.
4. **Route Gating**: All routes registered regardless of tier; Pro handlers return upgrade prompts/redirects to Free users (instead of 404s).
5. **Handler Organization**: Monolithic `handlers.php` split into concern-based files in `modules/guidance/handlers/` following the ecommerce module pattern.
6. **Schema Strategy**: Single consolidated migration (`001_guidance_schema.sql`) representing the final state of the standalone app's 20 schema iterations.
7. **Offline Sync**: PWA sync endpoints deferred from initial conversion (schema `gm_sync_queue` kept for future use).

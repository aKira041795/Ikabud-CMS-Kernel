# Case Study: Guidance Monitoring System

## Organization

The Guidance Monitoring System serves school counseling offices managing
student cases, appointments, and interventions. It is designed for
multi-institution deployment where each school operates as an isolated tenant.

## The problem

School guidance counselors typically managed cases through a combination of:

- Paper forms and folders
- Spreadsheet trackers shared across counselors
- Ad-hoc email and messaging for referrals
- Manual appointment scheduling
- No centralized record of interventions or outcomes

This approach made it difficult to:

- Track case progress across multiple counselors
- Maintain consistent records across school years
- Generate reports on common issues and interventions
- Ensure privacy and role-appropriate access to sensitive student records
- Scale to multiple schools under a single district administration

## The solution

The Guidance module was built as an auth-owned, tenant-scoped module on the
Kernel OS platform. Each school runs as an isolated tenant with its own
database and staff accounts.

### Key features deployed

- **Case management** — Record, track, and close counseling cases with
  structured intake data, intervention notes, and outcome tracking
- **Appointment scheduling** — Counselors can set available slots; students
  or staff can book through a public booking page
- **Role-based access** — Counselors, department heads, and administrators
  see different views and have different permissions
- **Privacy controls** — Student records are scoped to the tenant. Cross-tenant
  access is blocked by the kernel.
- **Reporting** — Case load summaries, intervention frequency, appointment
  history
- **Self-service password reset** — Counselors can reset their own passwords
  via the standardized forgot/reset flow (see [guidance module docs](../../docs/guidance/guidance-module.md))
- **SMS notifications** — Reminders and alerts via the SMS module
  (optional, paid add-on)
- **Audit trail** — All case updates logged via `kernel.audit.record@1`

### Auth-owned architecture

Guidance owns its user table (`gm_users`), authentication, and login page.
The kernel-admin password-push feature provides a trusted recovery path for
tenant admins. This pattern is the reference implementation for auth-owned
modules in Ikabud.

## Implementation

The Guidance module was developed as an auth-owned module from the start,
following the standardized module structure:

1. **Core case management** — Case creation, intake forms, status tracking,
   and closure workflow
2. **Appointment system** — Public booking page, counselor availability,
   confirmation and reminder flow
3. **Reporting** — Case load, intervention analysis, appointment history
4. **Multi-institution support** — Tenant isolation via kernel tenancy system
5. **SMS and notification integration** — Optional, via the SMS module

The module evolved through **72 commits** over approximately 12 months of
active development. It is tested by **~24 test files** covering case
management, appointment booking, password reset, and role-based access.

## Measurable results

| Metric | Observation |
|---|---|
| Institutions served | 2+ (each as separate tenant) |
| Active users per institution | 10+ (counselors + administrators) |
| Case types tracked | Academic, behavioral, personal, career |
| Appointment types | In-person counseling, parent conference, group session |
| Reports available | Case load summary, intervention frequency, appointment history |
| Password reset flow | Self-service, 30-minute token expiry, rate-limited |

*Specific numerical before/after comparisons are not available as the system
replaced distributed manual processes with no prior centralized data
collection. The metrics above reflect current operational state.*

## Key architectural decisions

1. **Auth-owned from day one.** The module owns its authentication, login page,
   and password reset. This allows schools to use the system without depending
   on a separate identity provider or CMS.
2. **Tenant isolation via kernel.** Each school's data is in a separate
   database. There is no shared table of student records. This was essential
   for data privacy compliance.
3. **Public booking without authentication.** The appointment booking page
   is publicly accessible (with rate limiting). The kernel handles CSRF
   protection for guest routes.
4. **Capability-based integration.** The Guidance module exposes
   `guidance.*@1` capabilities and depends on `kernel.auth.user@1` and
   `kernel.audit.record@1`. It does not import other modules' classes.

## Lessons learned

1. **Auth-owned modules need a standardized password reset contract.** The
   forgot/reset flow was extracted into a reusable pattern that all
   auth-owned modules now follow.
2. **Public booking requires careful CSRF and rate-limit handling.** Guest
   routes cannot rely on session-based CSRF. The kernel's guest CSRF
   protection and rate limiting are essential.
3. **Multi-institution deployment is straightforward with kernel tenancy.**
   Adding a new school is a control-plane operation — create a tenant,
   run migrations, configure the domain.
4. **Case management benefits from structured intake forms.** Unstructured
   notes are flexible but make reporting difficult. The balance between
   structured fields and free-text notes was refined over several iterations.

## Current status

**Active — Controlled pilot.** The Guidance module is deployed in **2+
educational institutions** in a supervised pilot. Ongoing work focuses on
appointment reliability, SMS notification hardening, and expanded reporting.

---

*Data sourced from CI tenant configuration, git history, module documentation,
and operational deployment records. Institution and user counts are
approximate real-world figures.*

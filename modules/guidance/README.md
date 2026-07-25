# Guidance Monitoring

School guidance counseling case management and scheduling system. Freemium model with free and professional tiers. Auth-owned module — manages `gm_users` table with `admin` role.

## Features

- **Case management**: intake, tracking, resolution with full audit trail
- **Appointments**: scheduling, reminders, confirmation workflow, rescheduling
- **Booking**: public-facing appointment booking with availability management
- **Case notes**: structured note-taking per session/case
- **Tracker**: student behavior/sentiment tracking over time
- **AI reports**: optional AI-generated report narratives (Groq-backed)
- **Analytics**: case load, appointment trends, outcome metrics
- **SMS integration**: optional SMS notifications via `guidance-sms` addon

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers (13): [`handlers.php`](handlers.php)
- Migrations (10): [`migrations/`](migrations/)

## Optional addons

- `guidance-sms` — SMS notification hooks
- `sms` — SMS provider integration

## Documentation

Project-level docs: [`docs/guidance/`](../../docs/guidance/)

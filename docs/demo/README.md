# Ikabud Public Demo

This directory contains a scripted walkthrough demonstrating Ikabud as a
coherent platform — not disconnected modules.

## What the demo shows

- Multi-tenant login and role-based access
- A real operational module (Daily Ledger) running end-to-end
- DiSyL-rendered interface
- Reports and exports
- Audit history
- Module enable/disable behavior
- Backup and recovery evidence

## Prerequisites

- A working Ikabud installation (see [Installation Guide](../kernel/installation.md))
- A seeded tenant (see CI tenant seeds or the adopter guide)
- Web browser and terminal access

## Estimated time

- Full walkthrough: 20–30 minutes
- Architecture overview only: 5 minutes

## Files

| File | Description |
|---|---|
| [script.md](script.md) | Step-by-step walkthrough |
| [architecture-explainer.md](architecture-explainer.md) | 2-page architecture summary |

## Audience

This demo is for technical evaluators, pilot adopters, and contributors who
want to see Ikabud working before reading architecture documents.

## Status: Scripted walkthrough

This directory contains a **scripted walkthrough** — a step-by-step guide
for someone with a working Ikabud installation to follow. It is not yet a
live/public demo deployment.

A complete public demo would additionally require:

- A reachable demo URL or packaged local environment (Docker)
- Seeded non-sensitive demonstration data
- Demo credentials or guided anonymous access
- Automatic data reset between sessions
- Screenshots or a short video walkthrough
- Clear notice that demo data is fictional
- A visible version and last-reset date

See the [Adopter Guide](../kernel/adopter-guide.md) for installation and
seed instructions to set up your own evaluation environment.

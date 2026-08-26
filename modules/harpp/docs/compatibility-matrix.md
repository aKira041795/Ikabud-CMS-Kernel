# HARPP Compatibility Matrix

This matrix versions distributions independently. Compatibility means that the listed contracts are understood; it does not prove a live deployment, browser, push, or host.

| Component | Version | Compatible with | Status |
|---|---:|---|---|
| Ikabud module | 2.1.0 | Module migrations 001-008; bridge API v1 compatibility reads/writes; Phase 0, Phase 1 capabilities, and the R-FTP deploy MVP | Implemented in this repository |
| Local bridge/runner | 1.1.0 | `/api/v1/harpp/bridge/*`; decimal server IDs; existing cursor contract | Source-compatible; Phase 2 executor contracts are not implemented |
| MCP bridge server | 1.1.0 | MCP protocol `2024-11-05`; bridge/runner 1.1.x | Source-compatible |
| Standalone PWA/PHP BFF | 1.0.x observed | Bridge API v1 only | Separately distributed at `/var/www/html/harpp`; live/browser verification remains external |

Module 2.0 changes decision mutation semantics: apply-and-close accepts only `ACKNOWLEDGED` or retry-safe `APPLIED`; ordinary delete archives. Existing v1 bridge routes remain compatibility adapters. New authority contracts are capability-first; no `/api/v2` route is claimed in this cycle. Phase 1 scoped reads remain disabled until their flags pass backfill comparison. The standalone distribution never owns hosted domain state and never receives bridge credentials in browser JavaScript.

## Upgrade order

1. Back up the tenant database and deploy module code with new enforcement flags disabled.
2. Apply migration 007 and verify Legacy membership/backfill counts.
3. Run isolated checks and compare compatibility/scoped reads.
4. Start the outbox dispatcher and observe pending, retry, and dead-letter counts.
5. Enable workspace enforcement, participant visibility, per-user receipts, approval policies, and fanout separately in that order.

Rollback disables the affected flag or dispatcher. It does not drop populated additive schema or erase retained evidence.

# Architecture/Task Contract — HARPP UI Phase 1 (Organized Shell + Today Overview)

- **Role:** `/architect` · **Primary reasoning:** Codex
- **Status:** `READY_FOR_IMPLEMENTATION`
- **Origin:** Chaired multi-model debate (Claude Sonnet 4.5 = Proponent, GPT-5.4 = Skeptic).
  Verdict: **gated, target-IA-driven UI program — Phase 1 only.** Labels must not outrun proof:
  this is a bounded shell/IA + overview surface pass on the existing stack, NOT a rewrite.

## task

Make the HARPP control-plane UI feel organized, modern, and UX-oriented by delivering **Phase 1**:
(1) regroup the persistent navigation into role-aware buckets with a shared, mobile-safe nav;
(2) add a **Today / Overview** landing page aggregating pending decisions, active runs, unread
notifications, daemon health, and deploy status on one screen; (3) introduce a small shared
design-token/component vocabulary (nav, cards, pills, status, buttons) reused by the existing
pages; (4) ensure the PWA nav works on a 375px phone (no horizontal overflow).

## objective

- One persistent, organized shell with a clear information architecture.
- An overview surface so the owner sees system state without hopping between 8 pages.
- Consistent component vocabulary across templates (no per-page invention).
- Zero regressions to existing production flows (messenger, decisions, advisor, deploy).

## scope

### allowed
- `templates/modules/harpp/layout.disyl` — the shell: regroup nav into buckets
  (e.g. *Operate*: Messenger/Advisor/Today · *Monitor*: Status/Runners/Notifications ·
  *Admin*: Users/Workspaces/Settings/Deploy), token-based CSS variables, mobile-safe nav
  (wrap/collapse, no 375px horizontal overflow), a **Today** link (first nav item) and make
  the brand link point to it.
- NEW `templates/modules/harpp/overview.disyl` + route `/harpp/overview` +
  handler `harppPageOverview` (via `harppRenderShell('overview','overview')`).
- NEW `modules/harpp/assets/overview.js` (or reuse `status.js` data) for the overview grid.
- Shared CSS token/components block (variables + `.card`, `.pill`, `.btn`, `.status-*`,
  `.nav-*`, `.empty-state`) centralized in the layout; apply consistently to existing
  templates ONLY where it removes duplication (messenger cards, decisions pills, status rows,
  advisor form) — visual-only changes.
- `modules/harpp/routes.php` — add `GET /harpp/overview` → `harpp:harppPageOverview`.
- Small, safe PWA tweaks in `layout.disyl`/`manifest` (theme color, nav behavior) if needed.

### prohibited
- No changes to messenger/decision/deploy/advisor **flow logic** (routes' POST handlers,
  decision transitions, conversation send/archive, artifact logic, deploy request logic).
- No React/stack rewrite; no new build pipeline; no new frontend framework.
- No new dependencies. Keep Tailwind/Alpine CDN + inline `unsafe-inline`/`unsafe-eval` CSP
  untouched (Bluehost constraint — see `.github/copilot-instructions.md`).
- No DB schema changes; no cross-module DB access.
- Do not change `/harpp` messenger route semantics (PWA start_url depends on it).

## constraints

- Shared host (Bluehost): keep the existing CSP; no build step.
- `harppRenderShell($template, $page, $context)` (modules/harpp/handlers.php) is the page
  render contract; every shell page passes `current_page` (nav active state keys on it).
- Templates are DiSyL (interpreted + compiled); keep `{block head/content/scripts}` structure.
- Nav active state: `<nav a class="{if current_page == 'X'}active{/if}">` pattern; the new
  Today page uses `current_page == 'overview'`.
- Roles: owner/admin see Admin items; the nav must be role-aware (existing guards already gate
  the routes).

## acceptance

1. `layout.disyl` nav is regrouped into buckets; **no horizontal overflow at 375px**
   (wrap or collapse; verify with a 375px viewport).
2. `/harpp/overview` renders under the shell with `current_page=overview` and shows on one
   screen: pending decision count, active/queued runs, unread notifications, daemon health,
   and deploy status (reuse the status API surface — `/api/v1/harpp/status` +
   decision/notification counts via existing services).
3. Existing shell pages (messenger, decisions, status, advisor, settings, users, workspaces,
   deploy, notifications, runners) still render with the new shell; only visual changes.
4. A shared token/component block exists in the layout; new/updated templates reference it
   (no new per-page inline redefinitions of nav/card/pill/button).
5. Brand link + first nav item point to `/harpp/overview` (Today).
6. `php -l` clean on all touched PHP; no DiSyL compile/render errors (check app.log
   `render_failure` when rendering).

## e2e_acceptance

- Playwright (repo root `playwright.config.js`): load `/harpp/login`, sign in, verify:
  - nav shows grouped buckets + a Today item; active state matches the current page;
  - `/harpp/overview` shows the operational grid (pending decisions, runs, daemon, unread);
  - `/harpp/messenger`, `/harpp/decisions`, `/harpp/advisor`, `/harpp/status` render and their
    primary controls still work (send message; open a decision; advisor ask button; status
    sections load);
  - at 375px viewport, the nav has no horizontal page overflow.
  If no browser seed/login available, a manual sign-in journey is acceptable with the same
  assertions.

## verification

- `php -l` on `modules/harpp/handlers.php`, `modules/harpp/routes.php`.
- Render smoke: `php ikabud` … or `app()->render('modules/harpp/overview', …)` via CLI with a
  stub user; check both `storage/logs/app.log` and `storage/logs/error.log` for
  `render_failure`/DiSyL errors after each template change (`?disyl_nocache=1` in browser).
- HARPP harness self-test unaffected (no tooling change expected): `python3 harpp self-test`
  (tools/harpp-bridge) must stay green if touched.
- No changes to `modules/harpp/assets/{messenger,decision,deploy,advisor,pwa,status}.js`
  behavior unless purely additive.

## risk

- DiSyL compiled-template cache may serve stale layout (APCu): use `?disyl_nocache=1` and
  flush `_tmp_cache_flush.php?full=1` after layout edits.
- Nav regression breaking messenger/deploy flows: mitigation = visual-only nav changes,
  keep every existing link/destination identical, only regroup + restyle.
- Scope creep ("modernization"): gated — if overview + nav + primitives don't reduce
  orientation friction measurably, stop; do NOT expand to a full redesign in this contract.

## recommended_next_state

`READY_FOR_IMPLEMENTATION` → delegate `/implement` (execution model) → `/review` (GPT-5.5) →
release-gate (php -l, render smoke, Playwright manual journey, git diff scope check).

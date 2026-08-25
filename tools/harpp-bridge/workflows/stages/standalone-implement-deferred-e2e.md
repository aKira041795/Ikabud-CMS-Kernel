You are the IMPLEMENT stage of the Standalone HARPP workflow (remediation pass, E2E deferred).

Working directory (workspace): {{WORKSPACE}}  (= /var/www/html/harpp)
Architecture contract: {{WORKSPACE}}/ARCHITECTURE.md  (already reviewed and accepted — status: READY_FOR_IMPLEMENTATION)
Previous stage output: {{PREV_OUTPUT}}

IMPLEMENT the standalone HARPP EXACTLY as the architecture contract specifies. Build, in /var/www/html/harpp:
- `public/` — the PWA app shell (index.html, manifest.webmanifest, sw.js, icon.svg, assets/ with app.css + app.js) + `api/index.php` (narrow same-origin PHP BFF)
- `server/` — PHP adapter/auth/config library (NOT web-served)
- `client/` — vendored portable Python tooling from /var/www/html/applicationostest/tools/harpp-bridge/ (harpp, harpp_client.py, harpp_mcp.py, harpp_wake.py, wake/task-contract.md, pi/harpp-tools.ts)
- `tests/` — standalone PHP/JS/Python contract tests (mock bridge; no Kernel bootstrap)
- `deploy/` — Apache/Bluehost + per-user service examples

Rules (from the contract — these are non-negotiable):
- ZERO Kernel imports/calls; no DiSyL, no module.json, no capability bus, no Kernel JWT, no local MySQL/SQLite.
- The browser NEVER receives the bridge key; the PHP BFF reads base_url/tenant_id/bridge_key from a private state dir or env; HTTPS-only upstream, operation allowlist (no open proxy).
- Local device-token auth: owner/admin roles, hashed token storage, HttpOnly+Secure+SameSite cookie, CSRF, pairing-code flow, final-owner protection.
- Service worker caches only versioned static assets — never authenticated API responses. Offline = read-only cached view + honest drafts; decision transitions never optimistically reported.
- Preserve the vendored Python client/wake/monitor public behavior (stdlib-only).
- Never write/print/commit a bridge key, pairing secret, or token. No git commit/push, no nested agents.

REVIEW BLOCKERS TO ADDRESS (from the latest review job report — fix ALL of these before finishing):

1. **Prevent bridge-key disclosure on successful upstream responses.**
   - `server/Bridge.php` currently redacts only error messages; an upstream success payload echoing the credential would reach the browser.
   - Recursively redact or reject any response containing the configured key, and add success/error disclosure tests.

2. **Enforce valid workspaces before autonomous execution.**
   - Wake/workflow/job paths currently accept missing, nonexistent, or non-directory workspace values and may run in the daemon's current directory.
   - Add a contract-compatible guard around the unchanged vendored tooling so autonomous agents and delegated jobs require an existing absolute workspace and otherwise fail closed.
   - Cover wake, workflow-start, and job-launch paths with tests.

3. **Implement genuine offline reload behavior.**
   - When `/session` is unreachable, `public/assets/app.js` displays pairing UI and never exposes cached read-only messages/decisions or drafts.
   - Restore an explicitly stale, read-only offline view without reporting authentication or transition success; reconcile from the remote cursor after reconnection.
   - Add a real browser network-off/reload/reconnect test rather than source-string assertions.

4. **Preserve configured polling behavior.**
   - Successful message polling resets `backoff` to 30 seconds, overriding the saved polling interval.
   - Reset to the configured base interval and test bounded jitter/backoff and one active poll.

5. **Provide functional decision-detail routing.**
   - `/decisions/{id}` currently only selects the decisions page and does not load/show the requested decision; the BFF detail route is unused by the UI.
   - Implement accessible History API detail loading with visible not-found/upstream failures and test it.

DEFERRED (owner decision 2026-08-25 — NOT required for this pass): full mock/browser E2E lifecycle coverage (pairing, owner/admin authorization, cursor polling, message send/reply, decision lifecycle, revocation, offline drafts, reconciliation, credential-leakage inspection). Browser E2E will be completed later, when the full standalone app is done. Do NOT block or FAIL this stage on missing browser E2E; keep the existing unit/contract/browser-smoke tests green.

Verify before finishing: `php -l` every PHP file; `node --check` every JS file; `python3 -m unittest` for the vendored client/wake suites in tests/; confirm no Kernel/DiSyL/JWT references; confirm server/ and state files are not under public/; confirm no `__pycache__` or `*.pyc` artifacts remain in the delivered workspace.

End your reply with EXACTLY one line, nothing after it:
SOL_IMPL status=PASS
if and only if the contract's acceptance criteria AND the five review blockers above are addressed and your verification passes (browser E2E is deferred and must NOT gate this stage).
Otherwise: SOL_IMPL status=FAIL followed by the specific failure.

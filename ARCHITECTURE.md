# Architecture / Task Contract

## task
Correct the HARPP decision UI/API flow: make the inbox open on `PENDING`, provide an owner/admin apply-and-close action, enforce CSRF on that browser mutation, and remove newly closed decisions from the active inbox without deleting audit data.

## objective
An operator can find pending work immediately and complete an acknowledged decision in one retry-safe action; the resulting `CLOSED` record remains durable but is no longer shown in the default active queue.

## scope (allowed)
- HARPP decision templates/assets, routes, handlers, and decision service/bridge integration under `modules/harpp` and `templates/modules/harpp`.
- Focused HARPP CLI/static regression tests and module documentation where needed.
- Reuse `HarppDecisionService::applyAndClose`; add a cookie-authenticated endpoint/handler if required.

## constraints
- Preserve the canonical lifecycle and append-only transition/audit/ADR records; “prune” means remove from the active UI/list result, never hard-delete.
- Default the inbox state control and initial request to `PENDING`; explicit filters, including `CLOSED`, must remain available.
- Apply-and-close is owner/admin only, accepts only `ACKNOWLEDGED`, `APPLIED`, or idempotent `CLOSED`, and records `ACKNOWLEDGED → APPLIED → CLOSED` atomically.
- The browser apply endpoint must call `harppRequireCsrf()` before authentication/business logic and use the existing same-origin `Harpp.fetch` CSRF header path. Bridge-key endpoints remain unchanged.
- Maintain tenant scoping, authorization, structured service results, notifications/events/audit behavior, and MySQL compatibility. No schema migration unless proven necessary.

## acceptance
- The decision inbox status select visibly starts at `PENDING`, and its first load requests `state=PENDING`.
- An acknowledged decision exposes a clear “Apply and close” action; success produces `CLOSED`, refreshes/redirects safely, and removes the card from the default pending inbox.
- A partially applied decision can be closed by retry; retrying an already closed decision succeeds without duplicate transitions.
- Missing/invalid CSRF on the session-backed apply mutation fails with 419 and causes no state change; a valid token succeeds.
- Closed decisions remain retrievable and selectable through the explicit `CLOSED` filter with their complete audit trail.

## e2e_acceptance
Sign in as an owner/admin, open the default inbox and observe only pending decisions; advance a fixture to `ACKNOWLEDGED`, invoke “Apply and close,” return to the inbox and confirm it is absent there, then filter `CLOSED` and confirm the same decision and both lifecycle transitions are present. Repeat the apply request and confirm idempotent success. Send the mutation without a valid CSRF token and confirm HTTP 419 with unchanged state.

## verification
- Add focused tests for default filter wiring, route/handler CSRF ordering, role denial, atomic apply-and-close, partial-`APPLIED` recovery, closed retry idempotency, and durable closed retrieval.
- Run the HARPP module test runner and the focused new regression test(s); inspect the changed JS/PHP/template syntax and git diff.

## risk
Primary risks are bypassing CSRF on an API route, accidentally granting members close authority, duplicating transition/audit effects on retry, or interpreting pruning as destructive deletion. Mitigate by routing all state changes through the locked domain service, enforcing CSRF and owner/admin authorization at the user handler, and filtering rather than deleting.

status: READY_FOR_IMPLEMENTATION
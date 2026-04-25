# Daily Ledger Android Integration

## Overview
The Android Daily Ledger app talks directly to the Daily Ledger module routes under the tenant host. The Android source currently lives in `android/daily-ledger`, but that directory is not tracked in this repository, so this document is the tracked source of truth for the backend contract the app depends on.

The current production base URL used by the app is:

* `https://baronledger.zdnorte.net/`

## Backend Contract

The app's Retrofit interface is aligned to these module-owned endpoints:

* **Login:** `POST /daily-ledger/auth/login`
* **Refresh token:** `POST /daily-ledger/auth/refresh`
* **Current user / session bootstrap:** `GET /daily-ledger/api/v1/me`
* **Ledger rows:** `GET /daily-ledger/api/v1/cashier/ledger/rows`
* **Ledger day status:** `GET /daily-ledger/api/v1/cashier/ledger/day-status`
* **Save cashier batch:** `POST /daily-ledger/api/v1/cashier/ledger/save-batch`
* **Close day:** `POST /daily-ledger/api/v1/cashier/ledger/close-day`
* **Reopen day:** `POST /daily-ledger/api/v1/admin/reopen-day`
* **Production products:** `GET /daily-ledger/api/v1/production/products`
* **Production movements:** `GET /daily-ledger/api/v1/production/movements`
* **Production sync batch:** `POST /daily-ledger/api/v1/production/sync-batch`

Backend changes that affect Android behavior must preserve these routes or version them deliberately.

## Offline Model

The app is designed to remain usable during server outages.

* Cashier and production edits are stored locally first and synced later.
* After a successful online login, the user can unlock offline using a device PIN.
* Session bootstrap avoids blocking on `/me` when connectivity probing already shows the server is unreachable.
* Ledger day status falls back to `unknown` instead of staying stuck in a loading state while offline.
* Close-day and reopen-day actions are explicitly blocked offline and return user-facing guidance instead of low-level transport failures.
* Background sync is retried with bounded attempts and exponential backoff rather than looping forever.

## Security Expectations

The app/backend contract assumes the following hard requirements:

* Production traffic is HTTPS only.
* Login, refresh, and bearer-authenticated API traffic must never depend on cleartext endpoints.
* Refresh and primary API clients must use short timeouts so unreachable servers fail fast.
* Android release builds must not log request or response bodies.
* Offline PIN verification must be salted, slow-hashed, and throttled after repeated failures.

## Backend Notes For Future Changes

When modifying Daily Ledger handlers or routes, keep these Android-specific behaviors intact:

* `apiGetLedgerRows()` should continue returning `day_status` with the rows payload.
* `apiGetLedgerDayStatus()` should remain a lightweight status check so the app can refresh lock state without fetching full row data.
* `apiCloseDay()` and `apiReopenDay()` should continue returning the resulting `day_status` in their JSON payloads.
* Admin and supervisor calls may supply `branch_id`; cashier flows remain branch-scoped to the authenticated user.

If the Android app is brought under version control later, keep this document synchronized with `ApiService.kt` and the offline/auth model.

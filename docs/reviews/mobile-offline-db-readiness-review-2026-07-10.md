# Kernel OS + DiSyL + ARK — Mobile App Backend Capability Review
> **Date:** 2026-07-10  
> **Scope:** Kernel OS 6.1 · DiSyL 4.7 · ARK Theme — assessed as a mobile-app backend platform  
> **Reviewer stance:** Systems architect / platform engineer  
> **Output type:** Assessment + gap analysis + prioritized action plan

---

## Executive Verdict

**The stack is mobile-app-capable at an ad-hoc level, but not as a first-class mobile backend platform.** A mobile app can be built today (and one has been — Daily Ledger), but it requires each module to reinvent auth, error handling, API response patterns, and validation. The kernel provides just enough raw primitives (JWT, CORS, JSON helper, Bearer auth) to make mobile work, but lacks the platform-level conventions, middleware, and infrastructure that would make "build a mobile app for this module" a repeatable, low-effort pattern.

| Layer | Mobile Relevance | Verdict |
|---|---|---|
| **Kernel OS** | High — routing, auth, JSON, CORS, rate limiting | ⚠️ Ad-hoc capable, not platform-grade |
| **DiSyL** | Zero — HTML templating only, bypassed for API routes | ❌ Irrelevant to mobile |
| **ARK** | Zero — CSS/HTML theme, bypassed for API routes | ❌ Irrelevant to mobile |



---

## 1. DiSyL: Zero Mobile Relevance (Confirmed)

DiSyL is a purely HTML templating engine. Its entire 7,400-line `TemplateEngine` produces HTML strings. Every subsystem confirms this:

| Subsystem | Purpose | Mobile Relevance |
|---|---|---|
| `Compiler/` | `.disyl` → PHP compilation | None |
| `Bridge/` | Alpine.js, HTMX, React bridges | None (HTML/JS rendering) |
| `Reactive/` | HTMX headers, Turbo Streams, OOB swaps | None (HTML partials) |
| `Hydration/` | Client-side hydration (`disyl-runtime.js`), islands | None |
| `Component/` | Single File Components, slots | None (HTML components) |
| `Async/` | Fiber scheduler, HTTP client for template data | None |

API routes **bypass DiSyL entirely**. When a request hits `/api/v1/...`, the kernel routes to a PHP handler function that returns data structures — DiSyL is never invoked.

**Verdict:** DiSyL is not a factor in mobile app backend capability. It neither helps nor hinders.

---

## 2. ARK Theme: Zero Mobile Relevance (Confirmed)

ARK is a reference theme for browser-rendered HTML pages. Its manifest declares:

- **Surfaces:** `["public", "print", "email"]` — all HTML surfaces
- **Capabilities:** `cms.entity.list`, `cms.entity.detail`, `theme.token.apply`, `theme.elements` — all UI/rendering
- **Assets:** `style.css` + optional `script.js` — CSS/JS only
- **Slots:** header, footer, sidebar, content regions — page layout concepts
- **Design tokens:** Tailwind v3 grid, Inter type scale, OKLCH colors, FontAwesome 6 icons

**Verdict:** ARK is not a factor in mobile app backend capability.

---

## 3. Kernel OS: The Only Layer That Matters for Mobile

### 3.1 What Works ✅

#### Routing — Dual web/API path
```
/api/v1/*    → JSON API route (CSRF bypassed, no session auth assumed)
/*            → Web page route (CSRF enforced, session+cookie auth)
```

The `$isApiRoute` flag in `src/helpers/module-manager.php:1747` cleanly discriminates:
```php
$isApiRoute = str_starts_with($requestUri, '/api/')
    || preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/api/#', $requestUri)
    || preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/refresh$#', $requestUri);
```

This flag is used to skip CSRF enforcement on API routes and return JSON errors for access denials.

#### Auth — Dual cookie + Bearer JWT
`App::user()` checks cookies first, then `Authorization: Bearer <token>`. Both use the same JWT verification pipeline:

```php
// App.php:1385-1415
// 1. Cookie auth (web pages) — sliding refresh supported
// 2. Bearer token (API/mobile clients) — same JWT verification
```

**JWT features (kernel/JWT.php):**
- HS256 (HMAC-SHA256), key rotation via `JWT_SECRET_<ID>` env vars
- Claims: `iss`, `iat`, `nbf`, `exp`, `jti`, `sub`, `token_version`, `tenant_id`
- Multi-tenant cross-validation
- `token_version` for invalidation on password change

**Login endpoint** (`/api/v1/auth/login`) already supports API clients:
```php
// Accept: application/json → returns {ok:true, token, refresh_token, user} in JSON
// Accept: text/html       → sets cookie, redirects
```

**Refresh token flow** (`/api/v1/auth/refresh`): SHA-256 hashed tokens, atomic rotation (revoke + issue), 30-day lifetime. Already used by the Daily Ledger Android app.

#### API Key auth (`kernel/Services/ApiKeyAuth.php`)
A separate parallel auth system for headless/external access:
- `bin2hex(random_bytes(32))` key generation, SHA-256 stored
- Extraction from `Authorization: Bearer`, `X-API-Key`, or `?api_key=` query param
- Scope-based access control (wildcard `*` supported)
- Per-key rate limiting (default 1000 req/min)
- Expiry support (`expires_at`)

#### CORS — Properly configured for mobile
```php
// public/index.php — applies to /api/* routes only
Access-Control-Allow-Origin: <mirrored from CORS_ORIGINS>
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With
Access-Control-Allow-Credentials: true
Access-Control-Max-Age: 86400
OPTIONS → 204 No Content
```

Origins are explicitly allowlisted via `CORS_ORIGINS` env var (comma-separated). Mirrors the origin (never `*`) to support credentials. `Authorization` header is allowed — good for mobile Bearer tokens.

#### JSON helper
```php
app()->json(['ok' => true, 'data' => [...]], 200);
// Sets Content-Type: application/json, echoes JSON_UNESCAPED_UNICODE, exits
```

#### Input parsing (`kernel/Http/Input.php`)
- `php://input` for PUT/PATCH/DELETE
- JSON body when `Content-Type: application/json`
- Null byte stripping, 32-level depth limit, 2MB max

#### Rate limiting infrastructure
- `rate_limits` DB table with sliding window (`INSERT ... ON DUPLICATE KEY UPDATE`)
- Login rate limiting (configurable IP/identity limits)
- API Key per-key rate limiting
- Anti-spam module auto-protects module API routes

#### Database — Production-grade
- Multi-tenant: per-tenant databases, encrypted credentials
- Connection pooling with retry (3 attempts, exponential backoff)
- Automatic tenant scoping via `QueryBuilder`
- ModuleDB table-access enforcement at runtime
- Migration system with rollback support
- Bluehost MySQL 5.7 compatibility enforced

### 3.2 What's Missing ❌ — Platform Gaps

#### GAP-1: No JSON error handler (🔴 CRITICAL)
The global exception handler **always returns HTML**, even for API routes:
```php
// bootstrap.php:1634 — global exception handler
header('Content-Type: text/html; charset=utf-8');  // ← Always HTML!
echo '<!DOCTYPE html>...<h1>Application Error</h1>...';
```

An API route that throws an unhandled exception gets an HTML 500 page with `Content-Type: text/html`. Mobile apps parsing JSON will crash.

**Fix:** Check `$isApiRoute` or `Accept` header in the exception handler, return `{'ok':false,'error':'...'}` JSON for API routes. ~1-2 hours.

#### GAP-2: `requireAuth()` redirects, never returns 401 JSON (🔴 CRITICAL)
```php
// App.php:1455
public function requireAuth(): array {
    $user = $this->user();
    if (!$user) {
        $this->redirect('/login');  // ← Always redirects!
    }
    return $user;
}
```

Every API handler must manually check `isAuthenticated()` and return JSON 401. There's no `requireAuthApi()` alternative.

**Fix:** Add `requireAuthApi(): array` that returns `app()->json(['ok'=>false,'error'=>'Unauthorized'], 401)` instead of redirecting. ~1 hour.

#### GAP-3: No standardized API response envelope (🟡 HIGH)
The `{ok: bool, ...}` pattern is convention only:
- Auth handlers use `{ok: true, token, user}`
- CSRF errors use `{ok: false, error: "..."}`
- Some handlers use raw `echo json_encode($data)`
- Some use `app()->json($data)`

There's no `ApiResponse` class, no RFC 7807 `ProblemDetail` format, no `error_code` convention, no field-level validation error format.

**Fix:** Create `kernel/Http/ApiResponse.php` with `success()`, `error()`, `validationError()`, `paginated()` factory methods. ~2-3 hours.

#### GAP-4: No kernel-level validation framework (🟡 HIGH)
No `Validator` class, no schema validation, no request DTOs. Every handler validates manually. For mobile APIs, consistent validation error formatting is critical.

**Fix:** Add `kernel/Http/Validator.php` with rule-based validation and standardized error output. ~1-2 days.

#### GAP-5: No Accept-header content negotiation at kernel level (🟡 HIGH)
Content type is determined by URL prefix (`/api/v1/`), not by `Accept` header. The `Accept` header is checked only in the login handler — nowhere else. A proper API platform should support:
```
GET /products
Accept: text/html        → DiSyL-rendered page
Accept: application/json → JSON API response
```

**Fix:** Add `kernel/Http/ContentNegotiator.php` that checks `Accept` header and routes to HTML or JSON handler variants. ~3-4 hours.

#### GAP-6: No API versioning infrastructure (🟡 MEDIUM)
All API routes use `/api/v1/` by convention, but:
- No `Accept: application/vnd.ikabud.v2+json` negotiation
- No route group versioning
- No version deprecation headers (`Sunset`, `Deprecation`)
- `OpenApiGenerator` hardcodes version `1.0.0`

**Fix:** Add version-aware route registration and deprecation header injection. ~4-6 hours.

#### GAP-7: No request ID propagation to JSON responses (🟢 LOW)
Web pages get `X-Request-Id` in HTML comments. API responses should include it in response headers or body for client-side error correlation.

**Fix:** Add `X-Request-Id` header to all API responses. ~30 minutes.

#### GAP-8: No pagination standard (🟡 MEDIUM)
No `Paginator` class, no cursor/offset pagination convention, no `Link` header pagination, no `meta.total` envelope convention.

**Fix:** Create `kernel/Http/Paginator.php` with offset + cursor pagination, `Link` headers, and standardized response format. ~4-6 hours.

#### GAP-9: No rate limit headers (🟢 LOW)
API responses lack standard rate limit headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `Retry-After`.

**Fix:** Emit these headers from a kernel-level rate limiter middleware. ~2-3 hours.

#### GAP-10: No OpenAPI spec auto-generation for module routes (🟢 LOW)
`kernel/Services/OpenApiGenerator.php` exists but only scans kernel core routes. Module API endpoints aren't documented.

**Fix:** Extend OpenAPI generator to scan module route maps and handler docblocks. ~4-6 hours.

#### GAP-11: JWT is HS256 only (symmetric) (🟡 MEDIUM)
Mobile apps embed the JWT secret (or derive it from user credentials). HS256 means the same secret signs and verifies — no public/private key separation. A compromised mobile app could extract the signing key.

**Fix:** Add RS256/ES256 support for asymmetric JWT signing, with mobile apps only holding the public key. ~1-2 days.

#### GAP-12: No push notification infrastructure (🟡 MEDIUM)
No FCM/APNs integration, no push queue, no device token storage. Mobile apps must poll for new data.

**Fix:** Add FCM integration + `kernel_push_tokens` table + `kernelPushNotify()` helper. ~2-3 days.

#### GAP-13: Session always started, even for API routes (🟢 LOW)
`session_start()` runs on every request, even pure API calls. The session is unused for API auth but still consumes resources.

**Fix:** Skip `session_start()` when `$isApiRoute` is true. ~1 hour.

---

## 4. Gap Severity Matrix

| # | Gap | Severity | Effort | Blocks Mobile? |
|---|---|---|---|---|
| GAP-1 | JSON error handler | 🔴 Critical | 1-2 hrs | Yes — unhandled exceptions crash mobile parsers |
| GAP-2 | `requireAuth()` → redirect | 🔴 Critical | 1 hr | Yes — every API handler must work around it |
| GAP-3 | API response envelope | 🟡 High | 2-3 hrs | No — convention works, but inconsistency is fragile |
| GAP-4 | Validation framework | 🟡 High | 1-2 days | No — but every module reinvents validation |
| GAP-5 | Accept negotiation | 🟡 High | 3-4 hrs | No — URL-prefix works, but limits flexibility |
| GAP-6 | API versioning | 🟡 Medium | 4-6 hrs | No — but needed before second mobile app |
| GAP-7 | Request ID in JSON | 🟢 Low | 30 min | No — debug convenience |
| GAP-8 | Pagination standard | 🟡 Medium | 4-6 hrs | No — but every list endpoint needs it |
| GAP-9 | Rate limit headers | 🟢 Low | 2-3 hrs | No — mobile UX improvement |
| GAP-10 | OpenAPI for modules | 🟢 Low | 4-6 hrs | No — DX improvement |
| GAP-11 | Asymmetric JWT | 🟡 Medium | 1-2 days | No — security hardening |
| GAP-12 | Push notifications | 🟡 Medium | 2-3 days | No — mobile UX improvement |
| GAP-13 | Session on API routes | 🟢 Low | 1 hr | No — minor perf improvement |
| — | **Critical total** | — | **2-3 hours** | — |
| — | **Critical + High total** | — | **3-4 days** | — |
| — | **Full platform-grade** | — | **2-3 weeks** | — |

---

## 5. Current Mobile App Proof: Daily Ledger

The Daily Ledger Android app proves the stack can support mobile apps today, but it also demonstrates the ad-hoc nature:

| What the app needs | How it's done today | Platform-grade alternative |
|---|---|---|
| Auth | Custom JWT login + refresh in module handler | Would be `app()->requireAuthApi()` |
| Error format | Manual `{ok: false, error: "..."}` checks in every handler | Would be standardized `ApiResponse::error()` |
| Input validation | Manual in each API handler | Would be kernel `Validator` |
| Pagination | Not needed (small datasets) | Would be kernel `Paginator` |
| Rate limiting | Anti-spam module only | Would be kernel rate limiter middleware |
| API docs | Hand-written | Would be auto-generated OpenAPI |
| Offline sync | Custom Room outbox + batch endpoints | Would be kernel sync framework |

---

## 6. Architecture Diagram: Current vs Target

### Current State
```
┌─────────────────────────────────────────────────────────┐
│                    public/index.php                      │
│  ┌──────────────────────┐  ┌──────────────────────────┐ │
│  │   Web Route (/...)    │  │  API Route (/api/v1/...) │ │
│  │   session + CSRF      │  │  no CSRF, CORS headers   │ │
│  │   ↓                   │  │  ↓                       │ │
│  │   DiSyL → ARK → HTML  │  │  PHP handler → json()    │ │
│  └──────────────────────┘  └──────────────────────────┘ │
│                                                         │
│  Auth: cookies → JWT ──── OR ──── Bearer → JWT          │
│  Error: HTML 500 page     │     HTML 500 page ← SAME!   │
│  Auth guard: redirect      │     redirect ← SAME!        │
└─────────────────────────────────────────────────────────┘
```

### Target State (After GAP Fixes)
```
┌─────────────────────────────────────────────────────────┐
│                    public/index.php                      │
│  ┌──────────────────────┐  ┌──────────────────────────┐ │
│  │   Web Route (/...)    │  │  API Route (/api/v1/...) │ │
│  │   session + CSRF      │  │  CORS + Rate-Limit       │ │
│  │   ↓                   │  │  ↓                       │ │
│  │   DiSyL → ARK → HTML  │  │  PHP handler             │ │
│  │   Auth: cookie JWT     │  │  Auth: Bearer JWT        │ │
│  │   Error: HTML 500      │  │  Error: JSON 500         │ │
│  │   Auth guard: redirect  │  │  Auth guard: JSON 401    │ │
│  └──────────────────────┘  │  ↓                       │ │
│                             │  ApiResponse::success()   │ │
│  ┌──────────────────────────┼──────────────────────────┤ │
│  │     Shared Kernel Services                           │ │
│  │  Validator  │  Paginator  │  RateLimiter             │ │
│  │  OpenAPI gen│  ContentNegotiator                     │ │
│  └──────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

---

## 7. Recommendations

### Immediate (Before Next Mobile App — ~2-3 hours)
1. **GAP-1 + GAP-2**: JSON error handler + `requireAuthApi()` — these are the only gaps that actively break mobile clients today
2. **GAP-7**: `X-Request-Id` in API response headers — trivial, high debug value

### Short-Term (Next Sprint — ~3-5 days)
3. **GAP-3**: Standardize API response envelope (`ApiResponse` class)
4. **GAP-4**: Kernel `Validator` with rule-based validation
5. **GAP-5**: `Accept`-header content negotiation
6. **GAP-8**: Standardized `Paginator`

### Medium-Term (6.2 Cycle — ~2-3 weeks)
7. **GAP-6**: API versioning infrastructure
8. **GAP-11**: Asymmetric JWT (RS256/ES256)
9. **GAP-12**: Push notification infrastructure (FCM)
10. **GAP-10**: Module-aware OpenAPI generation
11. **GAP-9**: Standard rate limit headers

### Long-Term (7.x)
12. **GAP-13**: Skip session start for pure API requests
13. Full mobile SDK generation from OpenAPI specs

---

## 8. Bottom Line

| Question | Answer |
|---|---|
| Can you build a mobile app against this stack today? | **Yes.** Daily Ledger proves it. |
| Is it a first-class mobile backend platform? | **No.** It's convention-over-configuration without the conventions. |
| Does DiSyL help or hinder mobile development? | **Neither.** It's bypassed entirely for API routes. |
| Does ARK help or hinder mobile development? | **Neither.** It's a browser CSS theme, irrelevant to APIs. |
| What's the critical missing piece? | **JSON error handling (GAP-1)** + **API-aware auth guards (GAP-2)**. Without these, every unhandled error crashes the mobile client and every auth check requires manual workarounds. |
| How long to make the kernel platform-grade for mobile? | **~2-3 hours** for the critical gaps, **~3-5 days** for critical + high gaps, **~2-3 weeks** for the full medium-term plan. |

---

> **Next review:** After GAP-1 through GAP-4 implementation — re-audit the error handling and auth paths.

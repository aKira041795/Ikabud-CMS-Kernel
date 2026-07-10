# Kernel OS API Reliability and Standardization Plan
> **Date:** 2026-07-10  
> **Source:** `docs/reviews/mobile-offline-db-readiness-review-2026-07-10.md` (13 gaps)  
> **Peer review:** Seasoned developer feedback incorporated 2026-07-10  
> **Goal:** Production-grade JSON API platform with a defined path to offline-capable mobile backend  
> **Phases:** 6 phases · ~4–7 weeks total

---

## Scope Correction

The original review identified 13 gaps. The initial plan addressed them as a "mobile platform" but focused primarily on API reliability and standardization — not offline synchronization, device-aware authentication, or mobile-specific infrastructure.

This revision:

- **Retitles** the work as _API Reliability and Standardization_ (Phases 1–3)
- **Adds** a dedicated Offline Sync phase (Phase 4)
- **Adds** mobile authentication hardening (Phase 3)
- **Restructures** around a central `RequestContext` (Phase 0)
- **Corrects** code-level issues in Validator, Paginator, exception handler, and JWT reasoning
- **Uses realistic effort estimates** (~4–7 weeks, not 8–11 days)

---

## Revised Phase Summary

| Phase | Title | Effort | Deliverable |
|---|---|---|---|
| **P0** | Request Context Foundation | 2–3 days | Central `RequestContext`, route metadata, `isApiRoute` consolidation |
| **P1** | Production Safety | 1–2 days | JSON exception + fatal-error handler, API-aware 401/403, request IDs |
| **P2** | API Contract Foundation | 1–2 weeks | `ApiResponse`, `Validator`, `Paginator`, route metadata, idempotency keys |
| **P3** | Mobile Authentication | 0.5–1 week | Short-lived tokens, rotating refresh, device sessions, token family revocation |
| **P4** | Offline Synchronization | 1.5–2 weeks | Sync cursors, server revisions, tombstones, conflict responses, idempotent batch push/pull |
| **P5** | Delivery & Observability | 1–1.5 weeks | Rate limiting, queued push, OpenAPI, session optimization, audit events |
| **Total** | | **~4–7 weeks** | |

---

## Phase 0: Request Context Foundation (2–3 days)

> **Goal:** Before adding multiple helpers that each re-detect "is this an API route?", establish one central `RequestContext` that exception handling, authorization, rate limiting, content type, and session management all consume.

### Problem

API route detection is duplicated across at least five locations:

| Location | How it detects |
|---|---|
| `bootstrap.php` (exception handler) | N/A — always assumes HTML |
| `kernel/App.php` (`requireAuth`) | N/A — always redirects |
| `src/helpers/module-manager.php` | Inline regex `$isApiRoute` |
| `public/index.php` (CORS) | `str_starts_with($uri_check, '/api/')` |
| `public/index.php` (session_start) | N/A — always starts session |

Adding a sixth detection site (the plan's original `kernel_is_api_request()`) makes this worse. A future tenant-prefix, locale-prefix, or reverse-proxy rewrite would require updating every site.

### Solution: `kernel/Http/RequestContext.php`

A value object resolved once after routing, consumed everywhere:

```php
<?php
// kernel/Http/RequestContext.php

namespace Ikabud\Kernel\Http;

class RequestContext
{
    public readonly string $requestId;
    public readonly string $method;
    public readonly string $path;
    public readonly string $responseFormat; // 'json' | 'html'
    public readonly ?string $apiVersion;    // 'v1', 'v2', null for non-API
    public readonly ?int $tenantId;
    public readonly ?array $authenticatedUser;
    public readonly string $clientIp;
    public readonly bool $isStateless;      // skip session for this request

    /** Resolved route metadata (set after route matching). */
    public readonly ?array $route;

    private function __construct(array $params) { /* assign all */ }

    /** Factory from superglobals — call once early in public/index.php. */
    public static function fromGlobals(): self { ... }

    /** Attach route metadata after route matching. */
    public function withRoute(array $route): self { ... }

    /** Attach authenticated user after auth resolution. */
    public function withUser(?array $user): self { ... }

    // Convenience queries
    public function isApi(): bool { return $this->responseFormat === 'json'; }
    public function isWeb(): bool { return $this->responseFormat === 'html'; }
}
```

### Route metadata

Each route definition carries metadata. The transition is incremental — start with a parallel metadata map and migrate existing routes over time:

```php
// src/http/core-routes.php — current
$routes['GET']['/api/v1/health'] = 'apiHealth';

// src/http/core-routes.php — target (add metadata map)
$routeMeta['GET']['/api/v1/health'] = [
    'format' => 'json',
    'auth' => false,
    'version' => 'v1',
];

$routes['POST']['/api/v1/auth/login'] = 'authLogin';
$routeMeta['POST']['/api/v1/auth/login'] = [
    'format' => 'json',
    'auth' => false,
    'stateless' => true,
    'version' => 'v1',
];
```

For the transition period, routes without metadata fall back to URL-prefix detection (the existing regex). This is the **temporary bridge** — not the final architecture.

### `isApiRoute` consolidation

After `RequestContext` exists:

- `bootstrap.php` exception handler → reads `RequestContext::fromGlobals()->isApi()`
- `kernel/App.php` `requireAuth()` → reads the resolved context
- `module-manager.php` → receives context from the router, not re-detecting
- `public/index.php` CORS → uses context
- `public/index.php` session → uses `$ctx->isStateless`

**Files touched:**
- NEW: `kernel/Http/RequestContext.php`
- `public/index.php` — create context early, pass through routing
- `src/helpers/module-manager.php` — accept context instead of re-detecting
- `src/http/core-routes.php` — add route metadata map (incremental)

**Time:** 2–3 days

---

## Phase 1: Production Safety (1–2 days)

> **Goal:** Unhandled exceptions and auth failures no longer crash mobile clients.

### GAP-1: JSON Exception + Fatal-Error Handler

**Problems with the original proposal:**

1. `http_response_code($isApi ? 500 : 500)` — redundant ternary
2. Only handles exceptions — not fatal errors (parse errors, memory exhaustion, `max_execution_time`)
3. Does not clear partial output before writing JSON
4. Does not handle recursive exceptions inside the handler itself
5. Does not address errors after headers were sent

**Fix — dual handler approach:**

```php
// bootstrap.php

function kernel_is_api_request(): bool {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return str_starts_with($uri, '/api/')
        || (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/api/#', $uri)
        || (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/refresh$#', $uri);
}

// 1. Exception handler (Throwable)
set_exception_handler(function (Throwable $e): void {
    static $handling = false;
    if ($handling) { exit(1); }
    $handling = true;

    try {
        write_log($e->getMessage(), 'critical', [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    } catch (Throwable $logEx) {
        error_log('Exception handler log failed: ' . $logEx->getMessage());
    }

    $isApi = function_exists('kernel_is_api_request') && kernel_is_api_request();

    if ($isApi && !headers_sent()) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        $payload = ['ok' => false, 'error' => 'Internal server error'];
        if (function_exists('request_id')) {
            $payload['request_id'] = request_id();
        }
        if (($_ENV['APP_DEBUG'] ?? '') === 'true') {
            $payload['debug'] = ['type' => get_class($e)];
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // HTML path — unchanged from current implementation
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    // ... existing Tier 1 (DiSyL 500 page) + Tier 2 (bare HTML) ...
});

// 2. Shutdown handler for fatal errors
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) { return; }
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $isApi = function_exists('kernel_is_api_request') && kernel_is_api_request();
    if ($isApi && !headers_sent()) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
});
```

**Note on output buffering:** `ob_end_clean()` in the handler assumes output buffering is active. If the application uses implicit flush, additional guards are needed. The `@` suppresses errors when no buffer exists. Recursive handler death is prevented with a static `$handling` flag.

**Files touched:**
- `bootstrap.php` — add `kernel_is_api_request()`, dual handler (exception + shutdown)

**Time:** 1 day

---

### GAP-2: API-Aware Auth Guards

**Reviewer feedback incorporated:**

Direct-output `json()` inside `requireAuth()` works as a transition step, but the cleaner long-term design throws typed exceptions that the global handler maps to JSON/HTML:

```php
// kernel/Exceptions/AuthenticationException.php (NEW — Phase 2)
class AuthenticationException extends \RuntimeException {}

// kernel/Exceptions/AuthorizationException.php (NEW — Phase 2)
class AuthorizationException extends \RuntimeException {
    public readonly string $requiredRole;
    public function __construct(string $requiredRole) {
        $this->requiredRole = $requiredRole;
        parent::__construct("Requires role: {$requiredRole}");
    }
}
```

The global exception handler maps these:
- `AuthenticationException` → 401 JSON or redirect to `/login`
- `AuthorizationException` → 403 JSON or redirect to `/`

**Transition path:**

Phase 1 ships with the direct-output approach (fastest fix):

```php
// kernel/App.php — transition implementation
public function requireAuth(): array
{
    $user = $this->user();
    if (!$user) {
        if ($this->isApiRequest()) {
            $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $this->redirect('/login');
    }
    return $user;
}

private function isApiRequest(): bool
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return str_starts_with($uri, '/api/')
        || (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/api/#', $uri)
        || (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/refresh$#', $uri);
}
```

Phase 2 migrates to exception-based auth (cleaner, works from CLI/workers/tests too):

```php
// kernel/App.php — target implementation (after Phase 0)
public function requireAuth(): array
{
    $user = $this->user();
    if (!$user) {
        throw new AuthenticationException();
    }
    return $user;
}
```

**Files touched:**
- `kernel/App.php` — modify `requireAuth`, `requireRole`, `requireAnyRole`, add `isApiRequest()`
- `kernel/Contracts/AuthContract.php` — docblock update
- NEW: `kernel/Exceptions/AuthenticationException.php` (Phase 2)
- NEW: `kernel/Exceptions/AuthorizationException.php` (Phase 2)

**Time:** 0.5 day (transition) + 0.5 day (exception migration in Phase 2)

---

### GAP-7: Request ID Propagation

**Reviewer feedback incorporated:**

- Always emit `X-Request-Id` header — safe, non-breaking
- Always include in error bodies — essential for debugging
- Do **not** auto-inject into every success body — hidden breaking change for clients
- Validate inbound `X-Request-Id` before trusting/logging it

```php
// public/index.php — after request_id() is available
$reqId = function_exists('request_id') ? request_id() : null;

// Accept inbound IDs, but sanitize (alphanumeric + hyphens, max 64 chars)
$inboundId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
if ($inboundId !== '' && preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $inboundId)) {
    $reqId = $inboundId;
}

// Emit header for all API routes
if ($reqId && str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api/')) {
    header('X-Request-Id: ' . $reqId);
}
```

`app()->json()` auto-includes `request_id` in error responses (status >= 400), but NOT in success responses:

```php
// kernel/App.php
public function json(array $data, int $status = 200): void
{
    if ($status >= 400 && !isset($data['request_id']) && function_exists('request_id')) {
        $data['request_id'] = request_id();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    if (function_exists('request_id') && ($id = request_id())) {
        header('X-Request-Id: ' . $id);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
```

**Files touched:**
- `public/index.php` — accept/sanitize inbound `X-Request-Id`, emit header
- `kernel/App.php` — include `request_id` in error JSON bodies only

**Time:** 0.5 day

---

## Phase 2: API Contract Foundation (1–2 weeks)

> **Goal:** Standardized response envelope, production-quality validator, pagination (offset + cursor), route metadata, idempotency keys.

### GAP-3: Standardized API Response Envelope

**Reviewer feedback incorporated:**

- Always include `data` (even when `null`) — consistency for generated clients
- Nest errors under an `error` object with `code`, `message`, `details` — extensible
- Avoid `\app()` global where possible — accept an emitter interface

```php
<?php
// kernel/Http/ApiResponse.php

namespace Ikabud\Kernel\Http;

class ApiResponse
{
    /**
     * Success: always includes "data" (nullable) and "meta" (always present).
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): never
    {
        self::emit([
            'ok'   => true,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    /**
     * Error: nested "error" object for extensibility.
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        mixed $details = null
    ): never {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        self::emit(['ok' => false, 'error' => $error], $status);
    }

    /**
     * Validation error: field-level messages in error.details.fields.
     */
    public static function validationError(array $fieldErrors, string $message = 'Validation failed'): never
    {
        self::error('validation_failed', $message, 422, ['fields' => $fieldErrors]);
    }

    /**
     * Paginated success.
     */
    public static function paginated(array $items, Paginator $paginator): never
    {
        self::success($items, 200, [
            'pagination' => $paginator->meta(),
            'links'      => $paginator->links(),
        ]);
    }

    private static function emit(array $body, int $status): never
    {
        \app()->json($body, $status);
    }
}
```

**Response contracts:**

```json
// Success
{"ok":true,"data":{...},"meta":{},"request_id":"..."}

// Error
{"ok":false,"error":{"code":"not_found","message":"Product not found","details":null},"request_id":"..."}

// Validation
{"ok":false,"error":{"code":"validation_failed","message":"Validation failed","details":{"fields":{"email":"Required"}}},"request_id":"..."}

// Paginated
{"ok":true,"data":[{...},{...}],"meta":{"pagination":{...},"links":{...}},"request_id":"..."}
```

**Files touched:**
- NEW: `kernel/Http/ApiResponse.php`

**Time:** 0.5 day

---

### GAP-4: Kernel Validation Framework

**Problems with the original implementation, all corrected below:**

1. **Unknown rules silently pass** — fix: throw `RuntimeException` for unknown rules
2. **Validator methods are `private`** — fix: use `protected` for subclass overrides
3. **Optional fields run validators** — fix: skip absent non-required fields
4. **`ctype_digit` rejects negatives** — fix: use `filter_var(FILTER_VALIDATE_INT)`
5. **`validated()` does no cleaning** — fix: renamed purpose accurately (raw values)
6. **No nested/array validation** — fix: registered as follow-up work

```php
<?php
// kernel/Http/Validator.php

namespace Ikabud\Kernel\Http;

class Validator
{
    private array $errors = [];
    private array $data;
    private array $rules;

    /** Registered custom rules: name => callable(field, value, params): ?string */
    protected static array $customRules = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /** Register a custom rule for all Validator instances. */
    public static function registerRule(string $name, callable $fn): void
    {
        self::$customRules[$name] = $fn;
    }

    /** Run validation without side effects. */
    public function passes(): bool
    {
        $this->errors = [];
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $isRequired = str_contains($ruleString, 'required');

            // Skip validation for absent optional fields
            if (!$isRequired && ($value === null || $value === '')) {
                continue;
            }

            foreach (explode('|', $ruleString) as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = $this->applyRule($field, $value, $rule, $params);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break; // first error per field
                }
            }
        }
        return empty($this->errors);
    }

    /**
     * Validate and return the subset of input keys listed in rules.
     * Emits 422 JSON and exits on failure.
     */
    public function validated(): array
    {
        if (!$this->passes()) {
            ApiResponse::validationError($this->errors);
        }
        $result = [];
        foreach ($this->rules as $field => $_) {
            if (array_key_exists($field, $this->data)) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    public function errors(): array { return $this->errors; }

    // ── Rule resolution ────────────────────────────────────

    private function applyRule(string $field, mixed $value, string $rule, array $params): ?string
    {
        // 1. Built-in method: validate<Rule>()
        $method = 'validate' . ucfirst($rule);
        if (method_exists($this, $method)) {
            return $this->$method($field, $value, $params);
        }

        // 2. Registered custom rule
        if (isset(self::$customRules[$rule])) {
            return call_user_func(self::$customRules[$rule], $field, $value, $params);
        }

        // 3. Subclass method (protected, for extensions)
        if (is_callable([$this, $method])) {
            return $this->$method($field, $value, $params);
        }

        // Unknown rule → configuration error (fail loudly)
        throw new \RuntimeException("Unknown validation rule: {$rule}");
    }

    // ── Built-in rules (protected for subclass override) ────

    protected function validateRequired(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return 'The ' . $field . ' field is required.';
        }
        return null;
    }

    protected function validateNullable(string $field, mixed $value, array $params): ?string
    {
        return null; // Always passes — combine with other rules: 'nullable|string|min:3'
    }

    protected function validateEmail(string $field, mixed $value, array $params): ?string
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'The ' . $field . ' must be a valid email address.';
        }
        return null;
    }

    protected function validateMin(string $field, mixed $value, array $params): ?string
    {
        $min = (int)($params[0] ?? 0);
        if (is_string($value) && mb_strlen($value) < $min) {
            return "The {$field} must be at least {$min} characters.";
        }
        if (is_numeric($value) && (float)$value < $min) {
            return "The {$field} must be at least {$min}.";
        }
        return null;
    }

    protected function validateMax(string $field, mixed $value, array $params): ?string
    {
        $max = (int)($params[0] ?? 0);
        if (is_string($value) && mb_strlen($value) > $max) {
            return "The {$field} must not exceed {$max} characters.";
        }
        if (is_numeric($value) && (float)$value > $max) {
            return "The {$field} must not exceed {$max}.";
        }
        return null;
    }

    protected function validateInt(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') { return null; }
        $filtered = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if ($filtered === null) {
            return "The {$field} must be an integer.";
        }
        return null;
    }

    protected function validateNumeric(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value)) {
            return "The {$field} must be numeric.";
        }
        return null;
    }

    protected function validateString(string $field, mixed $value, array $params): ?string
    {
        if ($value !== null && !is_string($value)) {
            return "The {$field} must be a string.";
        }
        return null;
    }

    protected function validateIn(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!in_array((string)$value, $params, true)) {
            return "The {$field} must be one of: " . implode(', ', $params) . '.';
        }
        return null;
    }

    protected function validateBoolean(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') { return null; }
        $valid = [true, false, 1, 0, '1', '0', 'true', 'false'];
        if (!in_array($value, $valid, true)) {
            return "The {$field} must be a boolean.";
        }
        return null;
    }
}
```

**Key differences from original:**
- Unknown rules → `RuntimeException` (fail loudly, not silently)
- Methods are `protected` (subclass-overridable)
- Optional fields skip validation when absent and not `required`
- `int` validation uses `filter_var(FILTER_VALIDATE_INT)`
- `validated()` renamed purpose accurately (raw values, no casting illusion)
- `registerRule()` for module-specific custom rules
- Added `nullable`, `numeric`, `boolean` rules

**Future: nested paths.** Array-item validation (`rows.*.product_id`, `address.city`) should be added in a follow-up iteration once the base validator is stable.

**Files touched:**
- NEW: `kernel/Http/Validator.php`

**Time:** 3–4 days (including tests and edge cases)

---

### GAP-5: Content Negotiation — DEMOTED

**Reviewer feedback:** Accept-header content negotiation for the same URL returning HTML vs JSON creates cache variation, proxy complexity, and handler ambiguity. Explicit API routes (`/products` vs `/api/v1/products`) are cleaner for Kernel OS.

**Revised approach:**

Keep `ContentNegotiator` as an internal service for consolidating `isApiRoute()` detection only — NOT for HTML vs JSON discrimination at the same URL.

Do **not** implement:
- `GET /products` with `Accept: application/json` → JSON
- `GET /products` with `Accept: text/html` → HTML

Instead, keep the established pattern:
- `GET /products` → HTML page (DiSyL)
- `GET /api/v1/products` → JSON API

If `Accept` is used later (Phase 5+), responses must include `Vary: Accept`.

**Files touched:**
- NEW: `kernel/Http/ContentNegotiator.php` — consolidated `isApiRoute()` only
- `src/helpers/module-manager.php` — delegate to `ContentNegotiator::isApiRoute()`

**Time:** 0.5 day

---

### GAP-8: Standardized Paginator (Offset + Cursor)

**Reviewer feedback incorporated:**

1. `lastPage()` → `max(1, ceil(...))` — empty collection returns page 1, not page 0
2. Current page is clamped to valid range
3. Offset pagination is supplemented with cursor pagination (preferred for mobile sync)
4. SQL binding: `LIMIT`/`OFFSET` must be bound as integers

```php
<?php
// kernel/Http/Paginator.php

namespace Ikabud\Kernel\Http;

class Paginator
{
    private int $total;
    private int $perPage;
    private int $currentPage;
    private string $baseUrl;

    public function __construct(int $total, int $perPage = 20, int $currentPage = 1, string $baseUrl = '')
    {
        $this->total = max(0, $total);
        $this->perPage = max(1, min($perPage, 100));
        $this->baseUrl = $baseUrl ?: ($_SERVER['REQUEST_URI'] ?? '/');

        $lastPage = $this->lastPage();
        $this->currentPage = max(1, min($currentPage, $lastPage));
    }

    public function total(): int { return $this->total; }
    public function perPage(): int { return $this->perPage; }
    public function currentPage(): int { return $this->currentPage; }

    /** Always at least 1, even for empty collections. */
    public function lastPage(): int { return max(1, (int)ceil($this->total / $this->perPage)); }

    public function offset(): int { return ($this->currentPage - 1) * $this->perPage; }
    public function limit(): int { return $this->perPage; }
    public function hasPrev(): bool { return $this->currentPage > 1; }
    public function hasNext(): bool { return $this->currentPage < $this->lastPage(); }

    public function meta(): array
    {
        return [
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage(),
            'per_page'     => $this->perPage,
            'total'        => $this->total,
            'from'         => $this->total === 0 ? 0 : $this->offset() + 1,
            'to'           => $this->total === 0 ? 0 : min($this->offset() + $this->perPage, $this->total),
        ];
    }

    public function links(): array
    {
        $links = ['self' => $this->buildUrl($this->currentPage)];
        if ($this->hasPrev()) { $links['prev'] = $this->buildUrl($this->currentPage - 1); }
        if ($this->hasNext()) { $links['next'] = $this->buildUrl($this->currentPage + 1); }
        $links['first'] = $this->buildUrl(1);
        $links['last'] = $this->buildUrl($this->lastPage());
        return $links;
    }

    public function linkHeader(): string { /* RFC 5988 Link header */ }
    public function emitHeaders(): void { /* Link + X-Pagination-* headers */ }
    private function buildUrl(int $page): string { /* append ?page=N */ }
}
```

### Cursor Paginator (for mobile sync)

Offset pagination causes duplicate/skipped records when data changes between requests. Cursor pagination is stable for mobile sync:

```php
<?php
// kernel/Http/CursorPaginator.php

namespace Ikabud\Kernel\Http;

class CursorPaginator
{
    private bool $hasMore;
    private ?string $nextCursor;
    private ?string $prevCursor;
    private int $limit;

    public function __construct(array $items, int $limit, bool $hasMore, ?string $nextCursor = null, ?string $prevCursor = null)
    {
        $this->limit = $limit;
        $this->hasMore = $hasMore;
        $this->nextCursor = $nextCursor;
        $this->prevCursor = $prevCursor;
    }

    /** Build a cursor from sort fields. Stable sort: updated_at DESC, id DESC. */
    public static function encodeCursor(array $row): string
    {
        return base64_encode(json_encode([
            'id' => (int)($row['id'] ?? 0),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ]));
    }

    public static function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) { return null; }
        $data = json_decode($decoded, true);
        return (is_array($data) && isset($data['id'], $data['updated_at'])) ? $data : null;
    }

    public function meta(): array
    {
        return [
            'per_page' => $this->limit,
            'has_more' => $this->hasMore,
            'next_cursor' => $this->nextCursor,
            'prev_cursor' => $this->prevCursor,
        ];
    }
}
```

**Usage in handlers (cursor):**
```php
$limit = min((int)(app()->input('limit') ?? 50), 100);
$after = app()->input('after');
$where = '';
$params = [$tenantId];

if ($after !== null) {
    $cursor = CursorPaginator::decodeCursor($after);
    if ($cursor) {
        $where = 'AND (updated_at < ? OR (updated_at = ? AND id < ?))';
        array_push($params, $cursor['updated_at'], $cursor['updated_at'], $cursor['id']);
    }
}

$rows = $db->query(
    "SELECT * FROM products WHERE tenant_id = ? {$where} ORDER BY updated_at DESC, id DESC LIMIT ?",
    array_merge($params, [$limit + 1])
)->fetchAll();

$hasMore = count($rows) > $limit;
if ($hasMore) { array_pop($rows); }
$nextCursor = ($hasMore && $rows) ? CursorPaginator::encodeCursor(end($rows)) : null;

$pager = new CursorPaginator($rows, $limit, $hasMore, $nextCursor);
ApiResponse::success($rows, 200, ['cursor' => $pager->meta()]);
```

**Response format:**
```json
{
    "ok": true,
    "data": [{...}, {...}],
    "meta": {
        "cursor": {
            "per_page": 50,
            "has_more": true,
            "next_cursor": "eyJpZCI6NDIsInVwZGF0ZWRfYXQiOiIyMDI2LTA3LTEwVDA4OjMwOjAwWiJ9",
            "prev_cursor": null
        }
    },
    "request_id": "..."
}
```

**Files touched:**
- NEW: `kernel/Http/Paginator.php` — offset pagination
- NEW: `kernel/Http/CursorPaginator.php` — cursor pagination
- `kernel/App.php` — add `paginate()` convenience factory

**Time:** 1.5–2 days (both paginators + tests)

---

### Idempotency Key Support (NEW — added per reviewer feedback)

Idempotency keys are the foundation for safe mobile retries. Without them, a network interruption during a `POST` can cause duplicate writes.

```php
<?php
// kernel/Http/Idempotency.php

namespace Ikabud\Kernel\Http;

class Idempotency
{
    /** Check or store an idempotency key. Returns stored response if already processed. */
    public static function check(string $key, int $tenantId): ?array { ... }

    /** Store the response for a key after successful processing. */
    public static function store(string $key, int $tenantId, array $response): void { ... }

    /** Remove a key after failure (so client can retry). */
    public static function release(string $key, int $tenantId): void { ... }
}
```

**Migration:**
```sql
CREATE TABLE kernel_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key_hash CHAR(64) NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    status ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
    response_json LONGTEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_tenant (idempotency_key_hash, tenant_id),
    INDEX idx_expired (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Client usage:**
```
POST /api/v1/ledger/save-batch
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

**Files touched:**
- NEW: `kernel/Http/Idempotency.php`
- NEW: `migrations/012_kernel_idempotency_keys.sql`

**Time:** 0.5–1 day

---

## Phase 3: Mobile Authentication (0.5–1 week)

> **Goal:** Short-lived access tokens, rotating refresh tokens with reuse detection, device session management.

### GAP-11: JWT Hardening (Corrected Threat Model)

**Original plan's error:** Claimed HS256 is insecure because a decompiled mobile app can extract the signing secret. In a correct architecture, the mobile app **never receives the HS256 secret** — only the server holds it. The real reasons to add asymmetric JWT are:

1. **Multiple services can verify** without being able to issue tokens (zero-trust verification)
2. **Signing authority isolation** — only the auth service holds the private key
3. **Public key distribution** — safe to share with CDNs, proxies, third-party services
4. **Cleaner key rotation** — rotate the public key without client changes

### What to implement now (HS256 remains default):

**1. Token lifecycle hardening:**

```php
// kernel/JWT.php — ensure all standard claims
$payload = [
    'iss' => 'ikabud',
    'sub' => (string)$userId,
    'aud' => $tenantId ? "tenant:{$tenantId}" : 'ikabud',
    'iat' => time(),
    'nbf' => time(),
    'exp' => time() + 900,                 // 15-minute access token
    'jti' => bin2hex(random_bytes(16)),    // Unique token ID
    'token_version' => $user['token_version'] ?? 1,
    'tenant_id' => $tenantId,
];
```

**2. Refresh token rotation with reuse detection:**

When a refresh token is used, it is invalidated and a new one is issued. If a previously-used refresh token is presented (indicating theft), revoke the entire token family.

```php
// kernel/Services/TokenFamily.php (NEW)

class TokenFamily
{
    /**
     * Rotate refresh token. If the presented token was already used,
     * revoke the entire family (potential token theft).
     */
    public static function rotate(string $familyId, string $presentedTokenHash): array { ... }

    /** Revoke all tokens in a family (logout all devices). */
    public static function revokeFamily(string $familyId): void { ... }
}
```

**3. Device/session list:**

```sql
CREATE TABLE kernel_device_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    device_name VARCHAR(255),
    device_id VARCHAR(64) NOT NULL,
    token_family_id CHAR(36) NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME,
    INDEX idx_user (tenant_id, user_id),
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

API: `GET /api/v1/auth/devices` → list active sessions, `DELETE /api/v1/auth/devices/{id}` → revoke one.

**4. Asymmetric JWT (deferred to later in Phase 3):**

When operational need arises (multi-service verification, zero-trust), add RS256/ES256/Ed25519 with `kid`-based key rotation. Recommended: Ed25519 if PHP/OpenSSL supports it (`openssl_sign` with EdDSA), otherwise ES256.

**Files touched:**
- `kernel/JWT.php` — standard claims hardening, short-lived token config
- NEW: `kernel/Services/TokenFamily.php` — refresh token rotation + reuse detection
- NEW: `migrations/013_kernel_device_sessions.sql`
- `src/http/core-routes.php` — device management endpoints
- `src/http/auth-handlers.php` — login/refresh integration

**Time:** 2–4 days

---

## Phase 4: Offline Synchronization (1.5–2 weeks)

> **Goal:** Mobile clients can work offline and synchronize when connectivity returns. This is the phase that actually justifies "mobile platform."

### Sync Contract Design

Two endpoints form the sync contract:

```
GET  /api/v1/sync/{entity}/changes?cursor=<opaque>&limit=100
POST /api/v1/sync/push
```

### Pull Changes (Incremental Sync)

```
GET /api/v1/sync/ledger_entries/changes?cursor=eyJ2IjoyfQ&limit=100
```

**Response:**
```json
{
    "ok": true,
    "data": {
        "changes": [
            {
                "entity": "ledger_entry",
                "id": "123",
                "operation": "updated",
                "revision": 18,
                "updated_at": "2026-07-10T08:30:00Z",
                "payload": {
                    "branch_id": 1,
                    "date": "2026-07-10",
                    "product_id": 42,
                    "addtl": 5
                }
            }
        ],
        "deleted": [
            {
                "entity": "ledger_entry",
                "id": "119",
                "revision": 17,
                "deleted_at": "2026-07-10T07:15:00Z"
            }
        ],
        "next_cursor": "eyJ2IjoxOH0=",
        "has_more": false
    },
    "request_id": "..."
}
```

**Cursor encoding:** The cursor is an opaque base64-encoded value. Internally it encodes the last-seen `revision` number. The client treats it as opaque.

**Server implementation sketch:**
```php
function apiSyncChanges(string $entity, array $params): void
{
    $cursor = CursorPaginator::decodeCursor(app()->input('cursor') ?? '');
    $sinceRevision = $cursor['revision'] ?? 0;
    $limit = min((int)(app()->input('limit') ?? 100), 500);

    $changes = fetchChanges($entity, $sinceRevision, $limit);
    $deleted = fetchDeletes($entity, $sinceRevision, $limit);
    $maxRevision = max(
        empty($changes) ? $sinceRevision : max(array_column($changes, 'revision')),
        empty($deleted) ? $sinceRevision : max(array_column($deleted, 'revision'))
    );

    ApiResponse::success([
        'changes' => $changes,
        'deleted' => $deleted,
        'next_cursor' => CursorPaginator::encodeCursor(['revision' => $maxRevision]),
        'has_more' => count($changes) + count($deleted) >= $limit,
    ]);
}
```

### Push Changes (Idempotent Batch)

```
POST /api/v1/sync/push
Content-Type: application/json
Idempotency-Key: <uuid>

{
    "operations": [
        {
            "client_id": "m1-a7f3",
            "entity": "ledger_entry",
            "operation": "upsert",
            "payload": {
                "branch_id": 1,
                "date": "2026-07-10",
                "product_id": 42,
                "addtl": 5
            },
            "base_revision": 17
        }
    ]
}
```

**Response:**
```json
{
    "ok": true,
    "data": {
        "results": [
            {
                "client_id": "m1-a7f3",
                "status": "accepted",
                "server_id": "123",
                "revision": 18,
                "updated_at": "2026-07-10T08:30:00Z"
            }
        ],
        "conflicts": [
            {
                "client_id": "m2-b9d1",
                "status": "conflict",
                "server_revision": 19,
                "server_payload": { "addtl": 10 },
                "reason": "base_revision_mismatch"
            }
        ]
    },
    "request_id": "..."
}
```

### Infrastructure Required

| Component | Purpose |
|---|---|
| `kernel_entity_revisions` table | Monotonically increasing revision per entity type |
| `kernel_entity_tombstones` table | Deleted record tracking (id, entity, revision, deleted_at) |
| `kernel_entity_changelog` table | Per-entity change log for incremental sync |
| `client_id` resolution | Map client-generated IDs to server IDs after first sync |
| Conflict detection | `base_revision` check — reject if server revision has advanced |
| Expired tombstone cleanup | Cron job to purge tombstones older than N days |

### What Phase 4 Does NOT Cover (deferred to 7.x+)

- Real-time sync via WebSocket/SSE
- Multi-device conflict resolution UI
- Selective sync (entity-type subscriptions)
- Binary/blob sync for attachments

**Files touched:**
- NEW: `kernel/Services/SyncEngine.php`
- NEW: `migrations/014_kernel_sync_infrastructure.sql`
- `modules/kernel/routes.php` — sync endpoint routes
- `modules/kernel/handlers.php` — sync handler implementations

**Time:** 7–10 days (sync contract, changelog, tombstone, conflict detection, tests)

---

## Phase 5: Delivery & Observability (1–1.5 weeks)

### GAP-9: Rate Limiting

**Reviewer feedback:** Querying the database on every API request for rate limiting is a performance concern. Design for pluggable backends.

```php
<?php
// kernel/Http/RateLimiter.php

namespace Ikabud\Kernel\Http;

class RateLimiter
{
    /** @var callable */
    private $storage;

    public function __construct(callable $storage) {
        $this->storage = $storage;
    }

    /**
     * Attempt to consume a rate limit token.
     * Returns [allowed: bool, limit: int, remaining: int, reset: int, retryAfter: ?int]
     */
    public function attempt(string $key, int $maxRequests, int $windowSeconds): array { ... }

    /** Emit standard rate-limit headers (RateLimit-* and legacy X-RateLimit-*). */
    public function emitHeaders(array $result): void { ... }
}
```

**Storage backends:**
1. **Database** (current `rate_limits` table) — default, works everywhere
2. **APCu** — for higher throughput (optional, detected at runtime)
3. **Redis** — future option

**Proxy-aware client IP:**
```php
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$clientIp = trim(explode(',', $clientIp)[0]);
```

**Tenant-specific quotas** and **route-specific limits** configured as a map:
```php
$limits = [
    '/api/v1/auth/login' => ['max' => 10, 'window' => 60],
    '/api/v1/sync/push'  => ['max' => 30, 'window' => 60],
    'default'            => ['max' => 300, 'window' => 60],
];
```

**Files touched:**
- NEW: `kernel/Http/RateLimiter.php`
- `public/index.php` — integrate before route dispatch for API routes

**Time:** 1.5–2 days

---

### GAP-12: Push Notification Queue

**Reviewer feedback:** Push is more than one service class. It requires queueing, retry, dead-letter, token invalidation, and provider response handling.

**Architecture:**
```
domain event → notification policy → queue record → worker → FCM/APNs → delivery result → audit
```

```sql
CREATE TABLE kernel_push_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    data_json JSON,
    status ENUM('pending', 'sending', 'sent', 'failed', 'dead') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    last_error TEXT,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    INDEX idx_pending (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE kernel_push_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(512) NOT NULL,
    platform ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
    is_valid BOOLEAN NOT NULL DEFAULT TRUE,
    invalidated_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    INDEX idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Flow:**
1. Domain event fires (`ecommerce.order.shipped`)
2. Notification policy evaluates → determines push is appropriate
3. `kernelDispatchPush($tenantId, $userId, $title, $body, $data)` → inserts into queue
4. `kernelProcessPushJobs()` cron/worker → picks pending records, sends via FCM HTTP v1 API
5. FCM returns canonical token IDs → update `kernel_push_tokens`
6. FCM returns `NotRegistered` → mark token invalid
7. Transient failures → exponential backoff
8. Max attempts exceeded → dead-letter, log

**Token storage location:** Tokens live in the kernel database (not tenant databases), keyed by `tenant_id` for tenant isolation. `dbForTenant()` is not used — the kernel DB connection is used directly (push tokens span tenant boundaries: a user may be logged into multiple tenants on the same device).

**Files touched:**
- NEW: `migrations/015_kernel_push_queue.sql`
- NEW: `kernel/Services/PushNotification.php` — queue dispatch + FCM delivery
- NEW: `kernel/Services/PushWorker.php` — cron/worker job processor
- `modules/kernel/routes.php` — register/unregister endpoints

**Time:** 4–6 days

---

### GAP-10: OpenAPI Generation

**Reviewer feedback:** Parsing loosely-formatted docblocks is fragile. Prefer route metadata + module-owned OpenAPI fragments.

**Revised approach — explicit route metadata as the source of truth:**

```php
// src/http/core-routes.php — route with OpenAPI metadata
$routeMeta['POST']['/api/v1/auth/login'] = [
    'format' => 'json',
    'auth' => false,
    'version' => 'v1',
    'openapi' => [
        'operationId' => 'authLogin',
        'summary' => 'Authenticate and receive JWT tokens',
        'requestBody' => [
            'required' => true,
            'content' => ['application/json' => [
                'schema' => ['$ref' => '#/components/schemas/LoginRequest'],
            ]],
        ],
        'responses' => [
            200 => ['description' => 'Login successful'],
            401 => ['description' => 'Invalid credentials'],
        ],
    ],
];
```

**Module-owned fragments:** Each module can declare an OpenAPI fragment directory:
```
modules/ecommerce/openapi/
├── schemas/Product.json
├── paths/products-list.json
└── paths/products-detail.json
```

The kernel `OpenApiGenerator` merges:
1. Kernel core route metadata
2. Module route metadata
3. Module OpenAPI fragment files
4. Global component schemas

Docblock parsing is NOT used as the primary source.

**Files touched:**
- `kernel/Services/OpenApiGenerator.php` — extend for module fragments
- `src/http/core-routes.php` — add OpenAPI metadata + `/api/v1/openapi.json` endpoint

**Time:** 2–3 days

---

### GAP-13: Session Optimization

**Reviewer feedback:** This is more dangerous than it appears. Don't skip sessions based on URL pattern alone — use route metadata.

**Revised approach:**

```php
// Route metadata
$routeMeta['POST']['/api/v1/auth/refresh'] = [
    'format' => 'json',
    'stateless' => true,      // ← this route does not need a session
];
```

In `public/index.php`, after route matching:

```php
$route = $matchedRouteMeta ?? null;
$needsSession = !($route['stateless'] ?? false);

if ($needsSession && session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

**Before deploying, audit these subsystems** for session dependency:
- `app()->user()` — uses cookies + Bearer, not `$_SESSION` (safe)
- CSRF — already bypassed for API routes (safe)
- Refresh-token endpoints — use `Authorization` header (safe)
- Tenant resolution — check if `$_SESSION['tenant_id']` is set elsewhere
- Flash messages — not used in API routes (safe)
- Rate limiter identity — uses IP, not session (safe)
- Auth middleware — uses JWT, not session (safe)

**Files touched:**
- `public/index.php` — route-metadata-aware session start
- `src/http/core-routes.php` — add `stateless` flag to route metadata

**Time:** 1 day (audit) + 0.5 day (implementation)

---

## Dependency Graph (Revised)

```
Phase 0: RequestContext
├── Centralizes isApiRoute detection
└── ── consumed by all subsequent phases ──

Phase 1: Production Safety
├── GAP-1 (JSON exception/fatal handler) ── uses RequestContext
├── GAP-2 (API auth guards) ── uses RequestContext
└── GAP-7 (request IDs) ── no dependencies

Phase 2: API Contract
├── GAP-3 (ApiResponse) ── uses request IDs from GAP-7
├── GAP-4 (Validator) ── uses ApiResponse from GAP-3
├── GAP-5 (ContentNegotiator → consolidated isApiRoute only)
├── GAP-8 (Paginator) ── uses ApiResponse from GAP-3
└── Idempotency keys ── no dependencies

Phase 3: Mobile Auth
├── GAP-11 (JWT hardening + token families) ── no dependencies
└── Device sessions ── uses token families

Phase 4: Offline Sync
├── Sync contract (changes + push) ── uses Paginator + Idempotency + CursorPaginator
└── Changelog + tombstones ── uses kernel DB infrastructure

Phase 5: Delivery & Observability
├── GAP-9 (Rate limiting) ── uses RequestContext
├── GAP-12 (Push queue) ── uses kernel job queue
├── GAP-10 (OpenAPI) ── uses route metadata from Phase 0
└── GAP-13 (Session optimization) ── uses route metadata from Phase 0
```

---

## File Manifest (Revised)

| File | Phase | Action |
|---|---|---|
| **NEW** `kernel/Http/RequestContext.php` | P0 | Central request context + route metadata |
| `src/http/core-routes.php` | P0 | Add route metadata map (incremental) |
| `public/index.php` | P0-P5 | Context creation, CORS, session, rate limiter, request-id |
| `src/helpers/module-manager.php` | P0 | Accept context instead of re-detecting |
| `bootstrap.php` | P1 | JSON exception + fatal-error dual handler |
| `kernel/App.php` | P1-P2 | Auth guards, `json()`, `paginate()` |
| `kernel/Contracts/AuthContract.php` | P1 | Docblock update |
| **NEW** `kernel/Exceptions/AuthenticationException.php` | P2 | Typed auth exception |
| **NEW** `kernel/Exceptions/AuthorizationException.php` | P2 | Typed auth exception |
| **NEW** `kernel/Http/ApiResponse.php` | P2 | Standardized response envelope |
| **NEW** `kernel/Http/Validator.php` | P2 | Validation framework (protected methods, unknown rule throws) |
| **NEW** `kernel/Http/ContentNegotiator.php` | P2 | Consolidated `isApiRoute()` |
| **NEW** `kernel/Http/Paginator.php` | P2 | Offset pagination (clamped page, min lastPage=1) |
| **NEW** `kernel/Http/CursorPaginator.php` | P2 | Cursor pagination (mobile sync) |
| **NEW** `kernel/Http/Idempotency.php` | P2 | Idempotency key support |
| **NEW** `migrations/012_kernel_idempotency_keys.sql` | P2 | Idempotency storage |
| `kernel/JWT.php` | P3 | Standard claims, short-lived tokens |
| **NEW** `kernel/Services/TokenFamily.php` | P3 | Refresh rotation + reuse detection |
| **NEW** `migrations/013_kernel_device_sessions.sql` | P3 | Device session management |
| `src/http/auth-handlers.php` | P3 | Login/refresh integration |
| **NEW** `kernel/Services/SyncEngine.php` | P4 | Offline sync contract |
| **NEW** `migrations/014_kernel_sync_infrastructure.sql` | P4 | Revisions, tombstones, changelog |
| **NEW** `kernel/Http/RateLimiter.php` | P5 | Pluggable rate limiter |
| **NEW** `migrations/015_kernel_push_queue.sql` | P5 | Push queue + token storage |
| **NEW** `kernel/Services/PushNotification.php` | P5 | Push queue dispatch |
| **NEW** `kernel/Services/PushWorker.php` | P5 | Push delivery worker |
| `kernel/Services/OpenApiGenerator.php` | P5 | Module fragment merging |

---

## Effort Estimate (Revised)

| Phase | Workstream | Realistic Effort |
|---|---|---|
| **P0** | RequestContext + route metadata | 2–3 days |
| **P1** | Exception/fatal handler + auth guards + request IDs | 1–2 days |
| **P2** | ApiResponse + Validator + ContentNegotiator | 3–5 days |
| **P2** | Paginator + CursorPaginator | 1.5–2 days |
| **P2** | Idempotency keys | 0.5–1 day |
| **P3** | JWT hardening + token families + device sessions | 2–4 days |
| **P4** | Sync contract + changelog + tombstones + conflicts | 7–10 days |
| **P5** | Rate limiter | 1.5–2 days |
| **P5** | Push queue + worker + FCM | 4–6 days |
| **P5** | OpenAPI module integration | 2–3 days |
| **P5** | Session audit + optimization | 1–1.5 days |
| — | Integration + regression testing (all phases) | 3–5 days |
| **Total** | | **~4–7 weeks** |

---

## Revised Priority Order

1. JSON exception and fatal-error responses (GAP-1)
2. Request IDs and structured log attachment (GAP-7)
3. API-aware authentication and authorization (GAP-2)
4. Route metadata and central `RequestContext` (Phase 0)
5. Standard error/success responses (GAP-3)
6. Validation framework (GAP-4)
7. Idempotency keys (NEW)
8. Cursor pagination (GAP-8 mobile path)
9. Refresh-token rotation and device sessions (GAP-11 revised)
10. Offline synchronization contract (Phase 4 — NEW)
11. Rate limiting (GAP-9)
12. OpenAPI generation (GAP-10)
13. Push notification queue (GAP-12)
14. Asymmetric JWT/key rotation (GAP-11 deferred portion)
15. Session optimization (GAP-13)
16. Advanced content negotiation (GAP-5 deferred — only if needed)

---

## Rollout Strategy

- **P0+P1 merge to main immediately** — critical safety fixes, no API surface changes
- **P2 creates new kernel classes** — backward-compatible, incremental adoption
- **P3 adds new auth features** — existing HS256 tokens continue working
- **P4 is a net-new module** — no existing behavior changes
- **P5 adds observability** — no breaking changes
- All existing `echo json_encode(...)` patterns continue to work indefinitely
- Modules adopt `ApiResponse`/`Validator`/`Paginator` at their own pace

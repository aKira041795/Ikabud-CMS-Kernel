---
name: auth-module-setup
description: When creating auth-owned modules that use shared kernel login/auth templates, always use API routes for POST handlers and accept JSON input to avoid CSRF/network errors
applyTo: "**/handlers/05-auth.php"
---

# Auth-Owned Module Setup — Avoiding "Network Error"

## The recurring problem

The shared kernel auth templates (`templates/pages/login.disyl`, `forgot-password.disyl`, `reset-password.disyl`) all use **JavaScript fetch with JSON**, not HTML form POST.

When a new auth-owned module is created and the auth handlers use form POST + redirects, every form submission fails with:

```
Network error. Please try again.
```

## Root cause chain

1. Shared templates send: `fetch(endpoint, {headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...})})`
2. Module manager globally enforces CSRF on all non-API POST routes (see `src/helpers/module-manager.php` line 1778)
3. JS fetch doesn't send CSRF tokens
4. CSRF enforcement returns HTML 419 page — not JSON
5. Template's `response.json()` throws a parse error → catch block → "Network error"

## Three mandatory requirements

| # | Requirement | Why | Wrong approach |
|---|---|---|---|
| 1 | **API route** (`/api/v1/{module-id}/auth/*`) | Bypasses global CSRF enforcement in module-manager.php | Using `/module-id/auth/login` — CSRF blocks it |
| 2 | **JSON input** (`php://input` + `json_decode`) | Template sends `Content-Type: application/json` | Reading `$_POST` — always empty |
| 3 | **JSON response** (`echo json_encode(...)`) | Template calls `response.json()` and checks `payload.ok` | `redirect()` or HTML output — parse error |

## Correct handler template

```php
// PAGE handler — renders the shared template
function palPageForgotPassword(): void
{
    echo app()->render('pages/forgot-password.disyl', palLoginPageContext([
        'page_title' => 'Forgot Password',
        'forgot_password_endpoint' => palBaseUrl() . '/api/v1/{module-id}/auth/forgot-password',
    ]));
}

// POST handler — accepts JSON, returns JSON
function palAuthForgotPassword(): void
{
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    $identity = $input['identity'] ?? '';

    if ($identity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter your username or email.']);
        return;
    }

    // ... business logic ...

    echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
}
```

## Route registration

```php
// routes.php — ALL auth POST endpoints use /api/v1/ prefix
'POST' => [
    '/api/v1/{module-id}/auth/login' => '{module-id}:handlerFunction',
    '/api/v1/{module-id}/auth/forgot-password' => '{module-id}:handlerFunction',
    '/api/v1/{module-id}/auth/reset-password' => '{module-id}:handlerFunction',
    // Also keep non-API login route for backward compatibility with form fallback
    '/{module-id}/auth/login' => '{module-id}:handlerFunction',
],
```

## Template context variables needed

| Template | Variables to set in handler context |
|---|---|
| `pages/login.disyl` | `login_endpoint`, `login_forgot_url`, `login_brand_html`, `gui` (colors, fonts) |
| `pages/forgot-password.disyl` | `forgot_password_endpoint`, `login_page_url` |
| `pages/reset-password.disyl` | `reset_password_endpoint`, `reset_token`, `login_page_url` |

## Login handler note

The login handler must accept BOTH JSON and form-encoded input because:
- The shared login template sends JSON via JS fetch
- The `$isModuleLogin` check in module-manager.php excludes login routes from CSRF
- Form POST fallback is needed for non-JS clients

```php
function palAuthLogin(): void
{
    $input = [];
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) { $input = $parsed; }
    $username = $input['username'] ?? $_POST['username'] ?? '';
    // ...
}
```

## Expected JSON response format

The shared templates expect this structure:

```javascript
// Success
{ "ok": true, "redirect": "/admin/{module-id}" }          // login
{ "ok": true, "message": "Reset link sent." }              // forgot-password
{ "ok": true, "message": "Password reset successful." }    // reset-password

// Error
{ "ok": false, "error": "Description of what went wrong" }
```

## Debug checklist

If "Network error" appears:

1. Check the fetch URL in the rendered page HTML — is it `/api/v1/...`?
2. Check `app.log` — did CSRF enforcement trigger? Look for `"CSRF enforcement triggered"`
3. Check the handler — does it read `php://input` or `$_POST`?
4. Check the response — does the handler return `echo json_encode(...)` or `redirect()`?
5. Check the route — is the POST route registered in `routes.php` under the correct path?

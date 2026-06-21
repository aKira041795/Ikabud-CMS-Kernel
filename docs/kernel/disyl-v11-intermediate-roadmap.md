# DiSyL v11 — Intermediate Roadmap

> **Goal:** Narrow the gap between current DiSyL (v6.0) and the v11 vision (type operators, Islands, reactive, Fibers) through incremental, high-impact intermediate steps.
>
> **Status:** Active — sprint 1 (1.1 + 1.2 + 2.1) and sprint 2 (2.2 + 2.3 + 3.1 + 3.2) implemented. See [`tests/disyl_v11_verify_test.php`](../../tests/disyl_v11_verify_test.php) for automated verification (60 tests).

---

## Overview

The v11 planned features (type operators, island hydration, reactive state, Fibers-based async) each require multi-month engineering. But the **impedance mismatches** we hit daily — CSRF 419s, DiSyL strict mode noise from inline JS, undefined variable warnings — can be eliminated with surgical intermediate steps that also lay the foundation for v11.

**Three tiers of work:**

| Tier | Timeframe | Focus |
|------|-----------|-------|
| **Immediate** | Hours–days | Fix parser/runtime friction points we hit today |
| **Short-term** | Weeks | Incremental v11 bridges that add value standalone |
| **Medium-term** | Months | Foundational blocks that enable v11 features |

---

## Tier 1: Immediate (Hours–Days)

### 1.1 `<script>` / `<style>` Passthrough in DiSyL Parser — ✅ **IMPLEMENTED**

**Problem:** Content inside `<script>` and `<style>` tags is parsed for `{...}` template expressions. This causes hundreds of `disyl.strict` warnings for JavaScript code, corrupts inline scripts (see: the entity list AJAX handler that had to be removed), and makes it impossible to embed JS without triggering errors.

**Solution:** In the DiSyL lexer/tokenizer, when entering a `<script>` or `<style>` tag, switch to raw-text mode until the closing `</script>` or `</style>` tag. No `{...}` expressions are evaluated inside these blocks.

**Location:** `kernel/DiSyL/TemplateEngine.php`

**Changes:**
- Early-return check (line 582): added `<style` alongside `<script` so templates with only style blocks skip processing
- Step 4b (line 653): script extraction no longer calls `compileScriptBody()` — body is raw passthrough. Tag attributes still resolve variables (e.g. `src="{base_url}"`)
- Step 4c (line 668): added `<style>` block extraction as raw passthrough
- Step 12b (line 743): added `<style>` block restoration after all processing

**Impact:**
- Eliminates all `disyl.strict` warnings for JS code
- Enables embedding `<script>` tags in component output without corruption
- No behavioral changes for templates that don't use script/style tags

**Effort:** ~2–4 hours (single lexer path)

### 1.2 JWT-Derived CSRF Token — ✅ **IMPLEMENTED**

**Problem:** The session-based CSRF token (`$_SESSION['_csrf_token']`)  causes 419 errors on shared hosting where the PHP session cookie isn't reliably sent. The entity list POST forms embed the session token, but `attendanceWageGuard()` compares it against a session that may be empty or different.

**Solution:** Derive the CSRF token from the JWT auth cookie instead of the PHP session:

```
csrf_token = hash_hmac('sha256', jwt_cookie_value, 'csrf')
```

- **Entity renderer** reads `$_COOKIE['attendance_wage_token']`, hashes it, embeds as `_token`
- **Guard** reads the same cookie, re-derives the hash, compares to `$_POST['_token']`
- No session dependency — both values come from the same browser cookie

**Location:**
- `kernel/DiSyL/EntityRenderingTrait.php` — token generation (delegates to `entity_csrf_token()`)
- `modules/attendance-wage/handlers/00-bootstrap.php` — token validation in `attendanceWageGuard()`
- `src/helpers/security.php` — `csrfTokenFromJwt()` helper + `csrfEnforceFromJwt()` validator

**Changes:**
- `src/helpers/security.php`: added `csrfTokenFromJwt($cookieName)` — derives token via `hash_hmac('sha256', $cookieValue, 'csrf')` with session-based fallback. Added `csrfEnforceFromJwt($cookieName)` — validates POST `_token` against JWT-derived hash
- `modules/attendance-wage/helpers.php`: `entity_csrf_token()` now returns `csrfTokenFromJwt('attendance_wage_token')` instead of raw cookie value
- `modules/attendance-wage/handlers/00-bootstrap.php`: guard uses `csrfEnforceFromJwt()` when JWT cookie is present, falls back to `app()->csrfEnforce()` when absent

**Impact:**
- Permanent fix for 419 CSRF errors on entity list POST actions
- No session dependency for CSRF
- Works across all environments (shared hosting, load-balanced, serverless)

**Effort:** ~2–3 hours

---

## Tier 2: Short-Term (Weeks)

### 2.1 `{@var}` Declarations — First Line of the Type System — ✅ **IMPLEMENTED**

**Problem:** DiSyL strict mode logs warnings for every undefined variable at runtime (`disyl.strict Undefined variable: page_title`). These are informative but noisy, and there's no way to tell the template engine "this variable is expected but may be null."

**Solution:** Add `{@var}` declarations to DiSyL:

```
{@var string $page_title}
{@var ?string $description}
{@var array<int,array> $employees}
```

- **Compile time:** The declaration registers the variable and its type. If strict mode is on, accessing an undeclared variable is an error — but accessing a declared variable with a null value is not.
- **Runtime:** The `{@var}` tag is a no-op — it produces no output. It only affects the compiler's variable table.
- **Type format:** PHP-style type hints (`string`, `int`, `float`, `bool`, `array`, `?nullable`, `array<K,V>`).

**Implementation:**
1. ✅ Add `{@var}` to the Grammar keyword list — `Grammar.php`: `KEYWORD_VAR` constant, `getKeywords()`, `validateVarDeclaration()`
2. ✅ Add `{@var}` extraction in compile pipeline — `TemplateEngine.php` step 0a (line 599): regex extracts `{@var type $name}`, registers in `$declaredVars`, produces no output
3. ✅ Modify strict mode checks — `TemplateEngine.php` `processVariables()` (lines 4259, 4280): skip undefined variable warnings when the variable root is declared

**Location:** `kernel/DiSyL/Grammar.php`, `kernel/DiSyL/TemplateEngine.php`

**Impact:**
- Eliminates 90% of strict mode log noise
- First working piece of DiSyL type system
- Gradual adoption — templates without `{@var}` work exactly as before (runtime warnings remain)

**Effort:** ~3–5 days

### 2.2 `{ikb_component}` — Server-Rendered Alpine Bridge

**Problem:** Every interactive component currently follows the same pattern: PHP renders data into HTML, Alpine.js `x-data` initializes with a JSON blob. But there's no standard way to do this — each component invents its own HTML structure.

**Solution:** A DiSyL component that standardizes server-rendered Alpine components:

```
{ikb_component name="employee-profile" data="selectedEmployee"}
  <template>
    <div class="...">{{ name }}</div>
    <div class="...">{{ position }}</div>
  </template>
{/ikb_component}
```

Generates:

```html
<div x-data="ikbComponent({&quot;name&quot;:&quot;Noah Omamalin&quot;,&quot;position&quot;:&quot;Baker&quot;})">
  <div class="...">Noah Omamalin</div>
  <div class="...">Baker</div>
</div>
```

With a global `ikbComponent()` Alpine function that:
1. Receives the JSON data
2. Makes it reactive via Alpine's `$data`
3. Provides event methods, computed properties, etc.

**Location:**
- `kernel/DiSyL/ComponentRegistry.php` — register `{ikb_component}`
- `kernel/DiSyL/ComponentRenderers/` — new renderer file
- `public/assets/js/ikb-components.js` — Alpine plugin

**Impact:**
- Standardized Alpine integration — every component uses the same bridge
- The Alpine function `ikbComponent()` can be swapped for a DiSyL client runtime later (Island hydration)
- Eliminates ad-hoc `x-data` strings in templates
- Data is serialized once, in one format, by one component

**Effort:** ~1 week

### 2.3 Component Registry Configuration in DiSyL (Not PHP Arrays)

**Problem:** Entity view contracts are defined as PHP arrays in `helpers/entity-views.php`. There's no way to inspect them from the template, validate field names at compile time, or use them as a schema source for type operators.

**Solution:** Allow defining entity views in DiSyL configuration templates:

```disyl
{ikb_entity_view name="employee_profile" view="table"}
  {field name="first_name"     type="string" renderer="text"}
  {field name="last_name"      type="string" renderer="text"}
  {field name="salary_type"    type="enum"   renderer="badge:{hourly|Daily}"}
  {field name="employment_status" type="enum" renderer="badge:{regular|Regular|green}"}
  {action name="view" url="/admin/wage/employees/{id}/view"}
  {action name="edit" url="/admin/wage/employees/{id}"}
{/ikb_entity_view}
```

- **Compile time:** The DiSyL compiler validates field names, renderer syntax, and action URLs
- **Runtime:** The same data is registered with `EntityViewResolver::registerView()` — identical to the current PHP array format
- **Migration:** The PHP array API continues to work. Templates opt-in by placing `.disyl` config files in `helpers/views/`

**Location:**
- `kernel/DiSyL/ComponentRegistry.php` — new component type
- `kernel/DiSyL/EntityViewResolver.php` — support DiSyL-sourced registration
- `modules/attendance-wage/helpers/views/employee_profile.disyl` — first view config

**Impact:**
- Compile-time validation of view contracts (field names, renderers, actions)
- Self-documenting — the DiSyL file IS the documentation
- Enables `keyof employee_profile` in future type operators
- Can generate TypeScript types for the builder UI from the same source

**Effort:** ~2 weeks

---

## Tier 3: Medium-Term (Months)

### 3.1 State Manager — PHP + Client-State Bridge

**Problem:** Current DiSyL has no notion of persistent state between renders. Each page load is a fresh render. Components like the kiosk widget store state in Alpine.js `x-data`, which is invisible to the server.

**Solution:** A `{state}` tag that declares a state namespace with a server-side source:

```
{state name="kiosk" source="attendance-wage/kiosk-state"}
  {variable name="step" type="int" default="0"}
  {variable name="searchQuery" type="string" default=""}
  {variable name="selectedEmployee" type="?object"}
{/state}
```

- **On page load:** DiSyL renders initialState as JSON → Alpine `x-data` initializes from this
- **On interaction:** Alpine updates local state only (no server round-trip)
- **On navigation/refresh:** State is optionally persisted via `sessionStorage` or a quick API call
- **Server side:** The `source` handler can compute initial state, validate state mutations, and authorize access

**Location:**
- `kernel/DiSyL/ComponentRegistry.php` — register `{state}`
- `modules/attendance-wage/handlers/` — kiosk state provider

**Impact:**
- Bridges the PHP-only present and the reactive future
- The `{state}` declaration is the same format a future `ReactiveState` would use
- Kiosk flow no longer needs `x-data="kioskWidget()"` — DiSyL owns the state contract

**Effort:** ~2–4 weeks

### 3.2 Compiled Component Manifest

**Problem:** Currently, the DiSyL compiler compiles templates to PHP files. But there's no compiled index — the runtime doesn't know what templates exist, what variables they expect, or what components they use without loading them all.

**Solution:** On compile, also emit a JSON manifest:

```json
{
  "template": "modules/attendance-wage/wage/employees/view.disyl",
  "variables": ["id", "first_name", "last_name", "photo_url", "tax_exemption_status", "..."],
  "components": ["ikb_entity_list", "ikb_form"],
  "extends": "modules/attendance-wage/layouts/admin.disyl"
}
```

- **Compile time:** The manifest is built from the parsed template tree
- **Runtime:** The manifest loader knows what templates are available without scanning the filesystem
- **Tooling:** The manifest can generate TypeScript types, validate template references, and power a language server

**Location:**
- `kernel/DiSyL/Compiler/` — new manifest emitter
- `kernel/DiSyL/TemplateCache.php` — store/load manifests alongside compiled PHP

**Impact:**
- `keyof template("view.disyl")` becomes a real expression — the type system knows what variables the template expects
- Language server can autocomplete template variable names
- Builder UI can validate template references without rendering

**Effort:** ~3–4 weeks

---

## Priority Matrix

| # | Step | Effort | Impact | Removes Pain From | Unlocks | Status |
|---|------|--------|--------|-------------------|---------|--------|
| 1.1 | `<script>` passthrough | Hours | ⭐⭐⭐ | JS corruption, strict noise | Clean component output | ✅ Done |
| 1.2 | JWT CSRF token | Hours | ⭐⭐⭐ | 419 errors on approve/pay | No-session auth | ✅ Done |
| 2.1 | `{@var}` declarations | Days | ⭐⭐⭐ | Undefined variable warnings | Type system foundation | ✅ Done |
| 2.2 | `{ikb_component}` bridge | Week | ⭐⭐ | Ad-hoc Alpine patterns | Island architecture | ✅ Done |
| 2.3 | DiSyL entity config | 2 weeks | ⭐⭐⭐ | PHP array configs | `keyof`, schema validation | ✅ Done |
| 3.1 | State manager | 2–4 weeks | ⭐⭐ | Alpine-only state | Reactive state | ✅ Done |
| 3.2 | Compiled manifest | 3–4 weeks | ⭐⭐ | No template introspection | Language server, tooling | ✅ Done |

**Recommended first sprint: 1.1 + 1.2 + 2.1** — These eliminate the three biggest pain points (JS corruption, 419 errors, strict mode noise) in under a week, and each one directly lays groundwork for a v11 feature line.

---

## Migration Path

No intermediate step breaks backward compatibility:

| Step | Old Way | Still Works? | Verification |
|------|---------|-------------|-------------|
| 1.1 | Inline `<script>` with `{...}` | No change — only fixes parsing | ✅ `tests/disyl_v11_verify_test.php` |
| 1.2 | Session CSRF token | Yes — both methods validated | ✅ `tests/disyl_v11_verify_test.php` + smoke tests |
| 2.1 | No `{@var}` declarations | Yes — strict mode falls back to current behavior | ✅ `tests/disyl_v11_verify_test.php` |
| 2.2 | Manual `x-data` | Yes — `{ikb_component}` is additive | ✅ `tests/disyl_v11_verify_test.php` |
| 2.3 | PHP array configs | Yes — both APIs coexist | ✅ `tests/disyl_v11_verify_test.php` |
| 3.1 | Alpine `x-data` only | Yes — `{state}` is additive | ✅ `tests/disyl_v11_verify_test.php` |
| 3.2 | No manifest | Yes — manifests are optional cache | ✅ `tests/disyl_v11_verify_test.php` |

Every intermediate step can be adopted incrementally, template by template, module by module.

# DiSyL v11 — Intermediate Roadmap

> **Goal:** Narrow the gap between current DiSyL (v6.0) and the v11 vision (type operators, Islands, reactive, Fibers) through incremental, high-impact intermediate steps.
>
> **Status:** Intermediate roadmap implementation complete. Active hardening + guidance module POC pilot: bridge capability contracts, binding primitives, JWT CSRF hardening, manifest schema v1, entity view integration. See [`tests/disyl_v11_verify_test.php`](../../tests/disyl_v11_verify_test.php) for v11 features (64 tests) and [`tests/guidance_entity_view_test.php`](../../tests/guidance_entity_view_test.php) for entity view integration (44 tests).
>
> **Architecture highlight:** The **Bridge System** (`kernel/DiSyL/Bridge/`) is a pluggable output abstraction that makes `{ikb_component}` and `{state}` framework-agnostic. Bridges now declare capability contracts (`supports()` + `capabilities()`) so the compiler can catch incompatible template usage at compile time. Binding primitives (`bind`/`model` attributes on `ikb_text`/`ikb_input`) let templates express intent in a framework-neutral way.
>
> **Note on Fibers:** Per architectural review, Fibers are a Kernel runtime optimization, not a DiSyL language feature. DiSyL expresses data requirements declaratively; the Kernel resolves concurrency behind the scenes.

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

## Revised Versioning

Per architectural review, the intermediate work maps to a cleaner release sequence:

| **Current DiSyL** | `6.0` (stable release) | Compiled-mode default, per-block error recovery, entity rendering trait |
| **v7** | Declaration and parser safety | Raw script/style modes, `{@var}`, strict-mode cleanup |
| **v8** | Component Bridge | `ikb_component`, BridgeManager, Alpine/HTMX/Custom output, capability contracts |
| **v9** | Contract Compiler | DiSyL entity-view config, schema registry, compiled manifests v1 |
| **v10** | State and Islands Foundation | State contracts, bridge capability matrix, island descriptors, asset manifest |
| **v11** | Typed Interface Runtime | Type operators, validated component props, island hydration, controlled reactive bindings |

**Release status:** Current stable is DiSyL 6.0. The v7–v10 features described above are implemented on the development branch (`v11-incubation`) and are available in the current codebase. They will be released as DiSyL 7.0 when the contract conformance gate passes — they will not bump through four separate major versions.

Fibers are removed from the DiSyL headline — they belong under **Kernel runtime concurrency and resolver optimization**.

---

## Contract-Hardening Sprint (Completed)

The following items were addressed after the initial implementation, based on architectural review feedback:

| Item | What changed | Status |
|------|-------------|--------|
| Bridge capability contracts | `BridgeInterface::supports()` + `capabilities()` on all bridges | ✅ Done |
| Binding primitives | `bind` attribute on `ikb_text`, `model` on `ikb_input` — bridges translate to framework-specific attrs | ✅ Done |
| JWT CSRF hardening | HKDF-style derivation using app secret (`hash_hmac('sha256', 'csrf\|' . hash('sha256', $cookie), $appSecret)`) | ✅ Done |
| Rich manifest schema | `source_hash`, `compiler_version`, `variables.{used,required,optional}`, `bridges`, `assets`, `dependencies` | ✅ Done |
| Bridge capabilities documented | Each bridge declares feature support (local_state, two_way_binding, server_actions, etc.) | ✅ Done |
| Fibers scope clarified | Fibers moved to Kernel runtime; DiSyL expresses data requirements declaratively | ✅ Done |

---

## Tier 1: Immediate (Hours–Days)

### 1.1 `<script>` / `<style>` Passthrough in DiSyL Parser — ✅ **IMPLEMENTED**

**Problem:** Content inside `<script>` and `<style>` tags is parsed for `{...}` template expressions. This causes hundreds of `disyl.strict` warnings for JavaScript code, corrupts inline scripts (see: the entity list AJAX handler that had to be removed), and makes it impossible to embed JS without triggering errors.

**Solution:** Script and style bodies are protected as raw blocks during compilation using regex extraction, not a parser state machine. When the compiler encounters `<script>` or `<style>`, the body content is extracted and replaced with a placeholder before template-variable processing. After all `{...}` resolution is complete, the original bodies are restored verbatim.

**Location:** `kernel/DiSyL/TemplateEngine.php`

**Changes:**
- Early-return check (line 582): added `<style` alongside `<script` so templates with only style blocks skip processing
- Step 4b (line 653): script extraction no longer calls `compileScriptBody()` — body is raw passthrough. Tag attributes still resolve variables (e.g. `src="{base_url}"`)
- Step 4c (line 668): added `<style>` block extraction as raw passthrough
- Step 12b (line 743): added `<style>` block restoration after all processing

**Note on parser architecture:** The current implementation uses regex extraction, not a true lexer state. A future parser refactor should introduce dedicated `SCRIPT_RAW` / `STYLE_RAW` lexer modes. The behavior is already correct and valuable — it simply should not be mistaken for the final parser architecture.

**Impact:**
- Eliminates all `disyl.strict` warnings for JS code
- Enables embedding `<script>` tags in component output without corruption
- No behavioral changes for templates that don't use script/style tags

**Effort:** ~2–4 hours (single lexer path)

### 1.2 JWT-Derived CSRF Token — ✅ **IMPLEMENTED**

**Problem:** The session-based CSRF token (`$_SESSION['_csrf_token']`)  causes 419 errors on shared hosting where the PHP session cookie isn't reliably sent. The entity list POST forms embed the session token, but `attendanceWageGuard()` compares it against a session that may be empty or different.

**Solution:** Derive the CSRF token from the JWT auth cookie bound to the application secret:

```
$csrfToken = hash_hmac(
    'sha256',
    'csrf|' . hash('sha256', $jwtCookieValue),
    $applicationSecret
);
```

The HKDF-style two-layer derivation (inner hash of cookie + HMAC with app secret) ensures the cookie value is never used directly as a token and that the app secret provides a second factor. When no app secret is configured, the fallback key `'change-me-in-env'` is tolerated (for backward compatibility with test environments) but logs a warning.

- **Entity renderer** reads `$_COOKIE['attendance_wage_token']`, derives token via `csrfTokenFromJwt()`, embeds as `_token`
- **Guard** reads the same cookie, re-derives, compares to `$_POST['_token']`
- No PHP session dependency — both values come from the same browser cookie + app secret

**Location:**
- `kernel/DiSyL/EntityRenderingTrait.php` — token generation (delegates to `entity_csrf_token()`)
- `modules/attendance-wage/handlers/00-bootstrap.php` — token validation in `attendanceWageGuard()`
- `src/helpers/security.php` — `csrfTokenFromJwt()` helper + `csrfEnforceFromJwt()` validator

**Changes:**
- `src/helpers/security.php`: added `csrfTokenFromJwt($cookieName)` — derives token via `hash_hmac('sha256', 'csrf|' . hash('sha256', $cookieValue), $appSecret)` with session-based fallback. Added `csrfEnforceFromJwt($cookieName)` — validates POST `_token` against JWT-derived hash
- `modules/attendance-wage/helpers.php`: `entity_csrf_token()` now returns `csrfTokenFromJwt('attendance_wage_token')` instead of raw cookie value
- `modules/attendance-wage/handlers/00-bootstrap.php`: guard uses `csrfEnforceFromJwt()` when JWT cookie is present, falls back to `app()->csrfEnforce()` when absent

**Impact:**
- Removes PHP session affinity from JWT-authenticated CSRF validation and resolves the observed shared-hosting 419 failures
- Supports stateless and load-balanced deployments where authentication cookies and application secrets are consistently configured
- Cookie expiration, JWT rotation, stale forms, incorrect domain settings, reverse-proxy behavior, and missing secrets can still cause validation failures — this is not a universal fix

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
- Typed declaration metadata used by strict mode and manifests — establishes expected variable shape but does not yet enforce types at runtime
- Gradual adoption — templates without `{@var}` work exactly as before (runtime warnings remain)

**Effort:** ~3–5 days

### 2.2 `{ikb_component}` — Pluggable Component Bridge

**Problem:** Every interactive component currently follows the same pattern: PHP renders data into HTML, Alpine.js `x-data` initializes with a JSON blob. But there's no standard way to do this — each component invents its own HTML structure, and the approach is hardcoded to Alpine.js.

**Solution:** A DiSyL component that standardizes server-rendered components with a **pluggable bridge** system. The `bridge` attribute selects the frontend framework output:

```disyl
{ikb_component name="employee-profile" data="selectedEmployee" bridge="alpine"}
  <div class="...">{name}</div>
  <div class="...">{position}</div>
{/ikb_component}
```

With `bridge="alpine"` (default):

```html
<div data-ikb-component="employee-profile" x-data="ikbComponent({...json...})">
  <div class="...">Noah Omamalin</div>
  <div class="...">Baker</div>
</div>
```

With `bridge="htmx"`:

```html
<div data-ikb-component="employee-profile" data-ikb-data='{...json...}' hx-vals='{"ikb_component":"...","data":{...}}'>
  <div class="...">Noah Omamalin</div>
  <div class="...">Baker</div>
</div>
```

With `bridge="custom"`:

```html
<div data-ikb-component="employee-profile" data-ikb-data='{...json...}'>
  ...
</div>
```

**Bridge architecture:**
- `kernel/DiSyL/Bridge/BridgeInterface.php` — contract for all bridges
- `kernel/DiSyL/Bridge/AlpineBridge.php` — Alpine.js `x-data` output (default)
- `kernel/DiSyL/Bridge/HtmxBridge.php` — HTMX `hx-vals` + `data-*` output
- `kernel/DiSyL/Bridge/CustomBridge.php` — generic `data-ikb-*` attributes only
- `kernel/DiSyL/Bridge/BridgeManager.php` — resolves bridge by name, extensible

Modules choose per-template:
```disyl
{!-- Guidance uses HTMX bridge --}
{ikb_component name="appointment-form" data="formData" bridge="htmx" hx-post="/api/appointments" hx-target="#result"}

{!-- CMS stays on Alpine --}
{ikb_component name="editor-toolbar" data="toolbar" bridge="alpine"}
```

**Location:**
- `kernel/DiSyL/Bridge/` — all bridge classes
- `kernel/DiSyL/TemplateEngine.php` — `renderIkbComponent()` delegates to bridge
- `kernel/DiSyL/Grammar.php` — `BRIDGE_*` constants
- `public/assets/js/ikb-components.js` — Alpine plugin (Alpine bridge only)

**Impact:**
- Framework-agnostic — same DiSyL component works with Alpine, HTMX, or custom JS
- Modules pick the bridge that matches their frontend stack
- Adding a new framework is one bridge class, zero parser changes
- Eliminates ad-hoc `x-data` strings in templates
- Data is serialized once, in one format, by one component

**Effort:** ~1 week (bridge core ~1 day, `{ikb_component}` refactor ~1 day, testing ~1 day)

**Bridge identifiers (Grammar.php):**
- `Grammar::BRIDGE_ALPINE` = `'alpine'` — emits `x-data="ikbComponent(...)"`
- `Grammar::BRIDGE_HTMX` = `'htmx'` — emits `data-ikb-data` + `hx-vals`
- `Grammar::BRIDGE_CUSTOM` = `'custom'` — emits only generic `data-ikb-*` attributes

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

**Solution:** A `{state}` tag that declares a state namespace with a server-side source, using the same pluggable bridge system as `{ikb_component}`:

```disyl
{state name="kiosk" source="attendance-wage/kiosk-state" bridge="alpine"}
  {variable name="step" type="int" default="0"}
  {variable name="searchQuery" type="string" default=""}
  {variable name="selectedEmployee" type="?object"}

  <div class="kiosk-content">
    <ikb_text bind="step" />
    <ikb_input model="searchQuery" />
  </div>
{/state}
```

With `bridge="alpine"` (default) — the Alpine bridge translates `bind` to `x-text` and `model` to `x-model`:

```html
<div data-state="kiosk" x-data="ikbComponent({&quot;step&quot;:0,&quot;searchQuery&quot;:&quot;&quot;})" class="kiosk-wrapper">
  <div class="kiosk-content">
    <span x-text="step"></span>
    <input x-model="searchQuery">
  </div>
</div>
```

With `bridge="htmx"` — the HTMX bridge emits `data-ikb-data` + `hx-vals`:

```html
<div data-state="kiosk" data-ikb-data='{"step":0,"searchQuery":""}' hx-vals='{"ikb_state":"kiosk","data":{"step":0,...}}'>
  ...
</div>
```

With `bridge="custom"` — generic data attributes only:

```html
<div data-state="kiosk" data-ikb-data='{"step":0,"searchQuery":""}'>
  ...
</div>
```

- **On page load:** DiSyL renders initialState as JSON via the selected bridge
- **On interaction:** Client framework manages local state (no server round-trip)
- **On navigation/refresh:** State persistence should be governed — distinguish initial state, local UI state, and business mutations. Plain `sessionStorage` or arbitrary API calls for persistence are not sufficient for governed business state; mutations should go through capability calls.

**Bridge reuse:** `{state}` uses the same `BridgeInterface` as `{ikb_component}`, so all bridges work for both without duplication.

**Location:**
- `kernel/DiSyL/Bridge/` — bridge classes shared with `{ikb_component}`
- `kernel/DiSyL/TemplateEngine.php` — `renderStateDeclaration()` delegates to bridge
- `modules/attendance-wage/handlers/` — kiosk state provider

**Impact:**
- Framework-agnostic state — modules pick Alpine, HTMX, or custom per-state block
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
| 1.3 | JWT CSRF hardening | Hours | ⭐⭐⭐ | Weak derivation key | HKDF-style app-secret binding | ✅ Done |
| 2.1 | `{@var}` declarations | Days | ⭐⭐⭐ | Undefined variable warnings | Type system foundation | ✅ Done |
| 2.2 | `{ikb_component}` + Bridge System | Week | ⭐⭐⭐ | Ad-hoc Alpine patterns, framework lock-in | Pluggable framework bridges, Island architecture | ✅ Done |
| 2.3 | Bridge capability contracts | Days | ⭐⭐⭐ | Silent bridge incompatibility | Compile-time feature checking | ✅ Done |
| 2.4 | Binding primitives | Days | ⭐⭐⭐ | Framework-specific markup in templates | Framework-neutral `<ikb_text bind>` / `<ikb_input model>` | ✅ Done |
| 2.5 | DiSyL entity config | 2 weeks | ⭐⭐⭐ | PHP array configs | `keyof`, schema validation | ✅ Done |
| 2.6 | Guidance module entity view POC | Days | ⭐⭐⭐ | No governed entity list in guidance | Reusable entity views styled with Tailwind | ✅ Done — 2 templates (`?entity=1` toggle) |
| 3.1 | State manager + Bridge System | 2–4 weeks | ⭐⭐⭐ | Alpine-only state, framework lock-in | Framework-agnostic state, Reactive state | ✅ Done |
| 3.2 | Rich compiled manifest | 3–4 weeks | ⭐⭐ | No template introspection | Language server, tooling, dependency graph | ✅ Done |
| 3.3 | Entity-view compiler boundary | 2 weeks | ⭐⭐ | ComponentRegistry overload | ViewContractCompiler, SchemaRegistry | 🔜 Planned |
| 3.4 | Canonical syntax standardization | Ongoing | ⭐⭐ | Inconsistent `{...}` vs `<ikb_...>` | Clear language grammar identity | 🔜 Planned |

**Implementation complete for the intermediate roadmap.** Hardening, adoption, and v11 design remain active.

---

## Migration Path

No intermediate step breaks backward compatibility:

| Step | Old Way | Still Works? | Verification |
|------|---------|-------------|-------------|
| 1.1 | Inline `<script>` with `{...}` | No change — only fixes parsing | ✅ `tests/disyl_v11_verify_test.php` |
| 1.2 | Session CSRF token | Yes — both methods validated | ✅ `tests/disyl_v11_verify_test.php` + smoke tests |
| 2.1 | No `{@var}` declarations | Yes — strict mode falls back to current behavior | ✅ `tests/disyl_v11_verify_test.php` |
| 2.2 | Manual `x-data` | Yes — `{ikb_component}` is additive, Alpine bridge = identical output | ✅ `tests/disyl_v11_verify_test.php` |
| 2.5 | Hand-written `{for case in cases}` tables | Yes — `{ikb_entity_list}` is additive, renders identical HTML via entity-view pipeline | ✅ `tests/guidance_entity_view_test.php` |
| 2.3 | PHP array configs | Yes — both APIs coexist | ✅ `tests/disyl_v11_verify_test.php` |
| 3.1 | Alpine `x-data` only | Yes — `{state}` is additive, bridge defaults to Alpine | ✅ `tests/disyl_v11_verify_test.php` |
| 3.2 | No manifest | Yes — manifests are optional cache | ✅ `tests/disyl_v11_verify_test.php` |

Every intermediate step can be adopted incrementally, template by template, module by module.

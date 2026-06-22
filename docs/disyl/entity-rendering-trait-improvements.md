# EntityRenderingTrait — Extensibility & Scalability Plan

**Status**: Reviewed by senior system architect — revisions applied  
**Last Updated**: 2026-06-22  
**File**: `kernel/DiSyL/EntityRenderingTrait.php` (v1.1.0, ~640 lines)  
**Reviewer**: Senior System Architect

---

## Architecture principle (from review)

```
View contracts describe.
Registries extend.
Renderers present.
Capabilities mutate.
Kernel OS governs.
```

---

## Overall assessment from architect

The gaps are correctly identified, but the solution should **not** center on extending the 640-line trait. The correct direction is extracting entity rendering into composable services:

```
TemplateEngine
    ↓
EntityRendererInterface
    ↓
DefaultEntityRenderer
    ├── CellRendererRegistry
    ├── EntityConditionEvaluator
    ├── EntityQueryState
    ├── PaginationRenderer
    ├── SortHeaderRenderer
    └── EntityStyleResolver
```

`EntityRenderingTrait` becomes a thin compatibility adapter:

```php
private function renderEntityList(...): string
{
    return $this->entityRenderer()->renderList(...);
}
```

Benefits: dependency injection, isolated unit testing, module-specific decorators, cleaner lifecycle, fewer `TemplateEngine` responsibilities, no subclass conflicts.

---

## Identified Gaps

| # | Gap | Symptom |
|---|-----|---------|
| 1 | Cell renderers are hardcoded (`badge`/`money`/`datetime`/`boolean`/`string` only) | Modules needing star ratings, progress bars, inline charts must fall back to custom slots |
| 2 | All 18 methods are `private` — no clean extension mechanism | Any module-specific rendering tweak requires copying the trait |
| 3 | `evaluateRowCondition()` is regex-based (`field == "value"` / `!=` only) | `action_show_if` can't express compound or numeric conditions |
| 4 | Table headers are static — no column sorting | Users can't re-sort without page reload with manual query params |
| 5 | No pagination chrome — `limit`/`offset` passed to handler but no prev/next UI | Large datasets render flat with no page controls |
| 6 | No inline editing — all actions are GET links or POST forms | Simple field changes require navigating to a separate edit page |
| 7 | Style presets are monolithic (Tailwind/Bootstrap/Legacy only) | Can't override styles per-field or per-entity without forking the trait |
| 8 | No output-target awareness — all rendering assumes HTML output | Custom renderers will break in reports, CSV export, PDF generation |

---

## P1 — Registerable Cell Renderers (✅ approved with revisions)

### Verdict
Yes, but use a **dedicated kernel registry service** with a context object and typed result — not a flat `callable` array on the trait.

### Architecture

```php
interface CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult;
}

final class CellRenderContext
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $field,
        public readonly array $row,
        public readonly array $fieldContract,
        public readonly string $view,
        public readonly string $outputTarget,   // 'html', 'csv', 'pdf', 'text'
        public readonly array $options = [],
    ) {}
}

final class CellRenderResult
{
    public function __construct(
        public readonly string $html,
        public readonly string $text,             // plain text fallback
        public readonly mixed $exportValue = null, // for CSV/export
        public readonly ?string $ariaLabel = null,
    ) {}
}
```

### Registry interface

```php
interface CellRendererRegistryInterface
{
    public function register(string $name, CellRendererInterface $renderer, string $provider): void;
    public function has(string $name): bool;
    public function get(string $name): CellRendererInterface;
}
```

### Registration

```php
// kernel boot — built-ins
app()->entityCellRenderers()->register('text',     new TextCellRenderer(),     'kernel');
app()->entityCellRenderers()->register('badge',    new BadgeCellRenderer(),    'kernel');
app()->entityCellRenderers()->register('money',    new MoneyCellRenderer(),    'kernel');
app()->entityCellRenderers()->register('datetime', new DateTimeCellRenderer(), 'kernel');
app()->entityCellRenderers()->register('boolean',  new BooleanCellRenderer(),  'kernel');

// module boot — custom renderers
app()->entityCellRenderers()->register('guidance.rating', new RatingCellRenderer(), 'guidance');
```

### View contract usage

```disyl
{field name="satisfaction" type="int" renderer="guidance.rating"}
```

**Namespaced identifiers** — a global key like `rating` will collide between modules. Use `{module}.{name}`.

### Key changes from original proposal
| Was | Now |
|-----|-----|
| Flat `callable` array on trait | Dedicated registry service (`app()->entityCellRenderers()`) |
| `fn(mixed $value, string $arg): string` | `CellRendererInterface` with `CellRenderContext` + `CellRenderResult` |
| Global renderer keys (`rating`, `progress`) | Namespaced keys (`guidance.rating`, `wms.progress`) |
| Built-in renderers stay in `match` | Built-ins also register through the same registry — one pipeline |
| No XSS protection for custom renderers | `CellRenderResult` separates `html` from `text` for safe escaping |

---

## P2 — Extract Services, Don't Expose Protected Methods (❌ revised approach)

### Verdict from review
**Do not make 8 internal methods `protected`.** This creates a permanent inheritance API that blocks refactoring and couples modules to `TemplateEngine`.

Problems with the subclass approach:
- A module should not extend `TemplateEngine` (the entire compiler + renderer) just to tweak table rows
- Two modules can't use different subclasses in the same request
- Long-running PHP workers face lifecycle ambiguity
- Once overridden, those methods can never be safely refactored

### Recommended approach

**Primary: Strategy service via interface**

```php
interface EntityRendererInterface
{
    public function renderList(EntityListRenderRequest $request): string;
    public function renderDetail(EntityDetailRenderRequest $request): string;
}
```

Modules provide decorators:

```php
final class GuidanceEntityRenderer implements EntityRendererInterface
{
    public function __construct(
        private EntityRendererInterface $inner  // DefaultEntityRenderer
    ) {}
}
```

Or register per-entity renderers:

```php
app()->entityRenderers()->register(
    entity: 'guidance.case',
    view: 'table',
    renderer: new GuidanceCaseTableRenderer()
);
```

**Tactical compromise** (if full extraction is too large for one sprint):

Add only one or two high-level hooks on the trait:

```php
protected function beforeRenderEntityList(array $attrs, array $context): void {}
protected function afterRenderEntityList(string $html, array $attrs): string { return $html; }
```

But **do not** make the internal rendering methods part of the inheritance contract.

---

## P3 — Expression-Based Action Conditions (✅ approved with revisions)

### Verdict
Correct direction, but **compile once — not once per row**.

### Performance issue with original proposal

```php
// Original — parses same expression N times
foreach ($rows as $row) {
    $parser = new Parser();
    $ast = $parser->parseExpression($condition);  // 100 rows × 3 actions = 300 parses
}
```

### Compile at contract load time

```php
// When view contract is loaded / compiled:
$conditionAst = $conditionEvaluator->compile('status == "pending" && priority == "high"');
// Store AST in the view contract cache

// Per row:
$result = $conditionAst->evaluate($rowContext);  // O(1) evaluation
```

Cache key: `entity + view + action + condition_hash`.

### Restricted expression language

`action_show_if` must **not** expose the full DiSyL expression runtime. Allow only:

```
status == "pending"
balance > 0
priority in ["high", "urgent"]
status == "pending" && is_active == true
!is_archived
```

**Disallow**: function calls, service calls, property methods, file access, dynamic includes, arbitrary filters, mutations.

### Error handling — no silent fallback

```php
// Do NOT do this:
catch (\Throwable) {
    return legacyFallback();  // hides malformed expressions forever
}
```

Better approach:
1. Try new parser
2. If expression matches a known legacy pattern → use legacy evaluator + emit deprecation notice
3. Otherwise → report a contract validation error — don't render with broken conditions

### Define null and type semantics

Explicit rules needed for:
```
missing_field == null
"100" > 20
0 == false
status != null
```

Use predictable typed comparisons, not loose PHP `==` behavior.

---

## P4 — Sortable Column Headers (✅ approved with revisions)

### Verdict
Good feature, but the state must be **namespaced** and **allowlisted**.

### Problems with original URL scheme

```
?source=guidance_case.all&view=table&sort=field&dir=asc
```

- `source` and `view` are already defined by the template — don't put them in the URL
- Generic params `page`, `sort`, `dir` collide if a page has two entity lists

### Use a stable list ID

```disyl
{ikb_entity_list
    id="guidance-cases"
    source="guidance_case.all"
    view="table"
    sortable="true"}
```

Namespaced query state:

```
?guidance-cases_sort=status&guidance-cases_dir=asc&guidance-cases_page=2
```

Or compact grouped syntax:
```
?list[guidance-cases][sort]=status&list[guidance-cases][dir]=asc
```

### Sort fields must be allowlisted

```disyl
{field
    name="status"
    renderer="badge"
    sortable="true"
    sort_key="status"}
```

The backend handler must validate the requested field against the contract — **not** blindly pass user input into `ORDER BY`.

### Accessibility

```html
<th aria-sort="ascending">
```

Use descriptive labels, not only arrows. Query params should be the default; progressive enhancement can add HTMX or `pushState` later.

---

## P5 — Pagination Chrome (✅ approved with revisions)

### Verdict
Yes, but pagination state should **not** be read directly from `$_GET` inside the renderer.

### Introduce EntityQueryState

```php
final class EntityQueryState
{
    public function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly ?string $sort,
        public readonly string $direction,
        public readonly array $filters,
    ) {}
}
```

A request-state resolver builds this from the current request:

```
HTTP request → EntityQueryStateResolver → EntityViewResolver → Capability payload → EntityRenderer
```

This makes the renderer testable and usable outside HTTP (CLI, email, reports, queued jobs).

### Add limit caps

```php
default limit: 15
maximum public limit: 50
maximum admin limit: 100
```

Never trust a template or query param to request unlimited rows.

### Design for cursor pagination too

Your current handlers return `total`, which works for numbered pagination. But polyglot/external services may return:

```json
{"rows": [], "next_cursor": "abc123", "has_more": true}
```

Design the result contract to support both:

```php
final class EntityListResult
{
    public array $rows;
    public ?int $total;
    public ?string $nextCursor;
    public bool $hasMore;
}
```

Do **not** make `total` permanently mandatory for all sources.

### Preserve state safely

Use one URL builder that preserves: filters, sort, direction, list ID, and unrelated page parameters. Do not concatenate query strings manually inside the table renderer.

---

## P6 — Inline Editing (✅ deferred, reframed)

### Verdict
Correctly deferred. However, do not frame this as arbitrary `update_url` fields.

Inline editing is **not** a rendering feature — it is a **governed mutation workflow** requiring:

- authorization
- validation
- capability checks
- audit logging
- CSRF
- optimistic locking (version/timestamp)
- conflict handling
- allowed value definitions
- error recovery
- accessibility
- bridge support

### Wrong approach

```disyl
{field name="status" renderer="badge"
       editable="true"
       update_url="/api/v1/guidance/cases/{id}/status"}
```

This moves business behavior into a view contract.

### Better approach — capability-driven

```disyl
{field name="status" renderer="badge"
       editable="true"
       update_capability="guidance.case.status.update@1"}
```

The module owns that capability and validates the transition.

### Concurrency

Include version or timestamp in mutation requests:

```json
{
  "entity_id": 42,
  "field": "status",
  "value": "closed",
  "expected_version": 7
}
```

### Response format

Return both canonical and presentational data:

```json
{
  "ok": true,
  "data": {
    "field": "status",
    "raw_value": "closed",
    "display_html": "<span class=\"...\">Closed</span>",
    "version": 8,
    "updated_at": "2026-06-22T10:15:00+08:00"
  }
}
```

The capability returns raw data; a UI endpoint adds `display_html`. This keeps mutation services free of HTML concerns.

**Treat as a separate RFC** — not part of the rendering trait sprint.

---

## P7 — Per-Entity Style Overrides (✅ approved with minor changes)

### Verdict
Safe and useful, but normalize the identifier and emit data attributes.

### Normalize CSS identifiers

```php
private function normalizeCssIdentifier(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    return trim($value, '-');
}
```

### Emit both classes and data attributes

```html
<div class="ikb-entity-list ikb-entity-guidance-case"
     data-ikb-entity="guidance.case"
     data-ikb-view="table"
     data-ikb-source="guidance-case-all">
```

Cleaner for JS, testing, and CSS targeting.

### Distinguish entity from source

For `guidance_case.all`, `guidance_case.open`, `guidance_case.recent`:

```
entity class: ikb-entity-guidance-case
source class: ikb-source-guidance-case-all
```

### Longer term — token-driven variants

```disyl
{ikb_entity_list
    source="guidance_case.all"
    view="table"
    density="compact"
    tone="admin"}
```

CSS hooks are useful but should not replace a design-token system.

---

## Gap 8 — Output Target Awareness (new, from review)

### Problem
Entity rendering is already connected to reports and exports. A star rating might render differently depending on target:

| Target | Output |
|--------|--------|
| Web HTML | ★★★★☆ |
| CSV | 4 |
| DOCX | 4 out of 5 |
| PDF | ★★★★☆ |
| Screen reader | 4 out of 5 stars |

If ignored now, custom renderers will be difficult to use in reports and exports later.

### Solution
The `CellRenderContext` already includes `outputTarget`. The `CellRenderResult` separates `html`, `text`, `exportValue`, and `ariaLabel`. Renderers can switch on target:

```php
public function render(CellRenderContext $context): CellRenderResult
{
    $value = (int)$context->value;
    return match ($context->outputTarget) {
        'csv', 'export' => new CellRenderResult(
            html: str_repeat('★', $value) . str_repeat('☆', 5 - $value),
            text: (string)$value,
            exportValue: $value,
            ariaLabel: "$value out of 5 stars"
        ),
        default => new CellRenderResult(
            html: str_repeat('★', $value) . str_repeat('☆', 5 - $value),
            text: (string)$value,
            ariaLabel: "$value out of 5 stars"
        ),
    };
}
```

---

## Revised Implementation Roadmap

### Sprint N — Extraction foundation (✅ DONE)

1. Create `CellRendererRegistryInterface` + `CellRendererInterface` + `CellRenderContext` + `CellRenderResult`
2. Register all built-in renderers through the registry
3. Add namespaced module renderer support
4. Add normalized entity/source CSS hooks + data attributes (P7)
5. Add output target awareness (Gap 8)
6. Create dedicated unit test suite
7. `EntityRenderingTrait` delegates to `DefaultEntityRenderer` via adapter
   - Do **not** expose internal methods as `protected`

### Sprint N+1 — Query and condition contracts (✅ DONE)

1. Introduce restricted `EntityConditionEvaluator` with compile-once semantics
2. Compile and cache conditions with view contracts
3. Introduce `EntityQueryState` for request-state abstraction
4. Add sortable field declarations to view contracts (P4)
5. Add namespaced query parameters for multi-list safety
6. Add `aria-sort` and accessibility output
7. Add limit caps

### Sprint N+2 — Pagination (✅ DONE — rolled into N+1)

1. Add total-based pagination to `DefaultEntityRenderer`
2. Preserve sort/filter state via `EntityQueryState`
3. Add limit caps enforcement
4. Design cursor-compatible `EntityListResult` contract
5. Add browser and performance tests

### Remaining items (all done)

| Item | Status |
|------|--------|
| EntityListResult value object (cursor-compatible) | ✅ Done |
| Deprecation logging for legacy condition patterns | ✅ Done |
| Sort field validation against view contract allowlist | ✅ Done |
| Test suite reorganization (unit/integration/security/browser) | ✅ Done |
| Playwright browser tests for sort + pagination | ✅ Done |
| P2 lifecycle hooks (beforeRenderList / afterRenderList) | ✅ Done |

### Still pending (not planned for current sprints)

- **P6 — Inline editing RFC** — separate governance document created at `docs/disyl/inline-editing-rfc.md`
- **Full EntityRenderingTrait removal** — trait still exists as delegation adapter; see migration plan below
- **Cursor-pagination wiring in capability handlers** — `EntityListResult` + `resolveAsResult()` + `renderListFromResult()` exist; no handlers return cursor format yet

## EntityRenderingTrait removal migration plan

### Goal
Remove `EntityRenderingTrait` entirely once all rendering logic lives in `DefaultEntityRenderer`.

### Current state (after Sprint N+N+1)
- `EntityRenderingTrait::renderEntityList()` delegates to `DefaultEntityRenderer::renderList()` when `app()->entityRenderers()` is available
- `EntityRenderingTrait::renderEntityDetail()` delegates to `DefaultEntityRenderer::renderDetail()` similarly
- Old private rendering methods on the trait remain as fallback

### Migration steps

| Step | What | Risk |
|------|------|------|
| 1 | Identify all template tag handlers in `TemplateEngine::renderComponent()` that call trait methods (`renderEntityList`, `renderEntityDetail`, `renderEntityViewConfig`) | None — grep confirms only these 3 entry points |
| 2 | Move `renderEntityViewConfig()` to a standalone service or inline in `DefaultEntityRenderer` | Low — self-contained DiSyL config parser |
| 3 | Replace trait method calls in `renderComponent()` with direct calls to `DefaultEntityRenderer` via `app()->entityRenderers()` | Low — guarded by `method_exists` check |
| 4 | Remove `use EntityRenderingTrait` from `TemplateEngine` | Medium — removes fallback; must verify all paths hit the new service |
| 5 | Delete `kernel/DiSyL/EntityRenderingTrait.php` | Low — file is not referenced after step 4 |

### Breaking change warning
Step 4 is the only breaking change. Any subclass of `TemplateEngine` that references trait methods will break. A grep for `EntityRenderingTrait` across all modules should be run before this step.

### Schedule
- Steps 1-3: Can ship in any release
- Step 4-5: Recommend a minor version bump (e.g. kernel 6.1.0)

### Later RFC — Inline mutations

Separate RFC covering:
- capabilities as mutation gate
- audit trail
- field-level validation
- optimistic locking (version fields)
- conflict resolution
- bridge behavior
- response format
- accessibility

Treat inline editing as a **governed mutation workflow**, not a view-contract feature.

---

## Test Strategy

### Dedicated test suite (not POC tests)

```
tests/unit/DiSyL/EntityRendering/
    CellRendererRegistryTest.php
    ConditionEvaluatorTest.php
    SortHeaderRendererTest.php
    PaginationRendererTest.php
    EntityStyleResolverTest.php

tests/integration/EntityViews/
    GuidanceCasesEntityViewTest.php
    CmsEntityViewTest.php
    PolyglotEntityViewTest.php

tests/security/
    EntityRendererEscapingTest.php
    EntityActionConditionSecurityTest.php

tests/browser/
    guidance-cases-sort.spec.*
    guidance-cases-pagination.spec.*
```

Use snapshot tests for stable HTML structures, but pair with semantic assertions — large snapshots alone are difficult to review.

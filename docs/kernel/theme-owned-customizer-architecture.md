# Theme-Owned Customizer Architecture

> **Status**: Design Document — Revised 2026-06-29  
> **Kernel OS**: `>=6.1.0`  
> **DiSyL**: `>=4.7.0`

## Core Doctrine

```
Kernel OS validates.
CMS persists and orchestrates.
Theme defines and presents.
Theme Studio enhances.
DiSyL renders.
Modules provide business data.
```

### Responsibility Table

| Layer | Responsibility | Database Access |
|---|---|---|
| **Kernel OS** | Contracts, schema validation, provider trust, architecture checks, `ThemeRenderContext` value object | None |
| **CMS** | Settings persistence, tenant/site scope resolution, settings repository, schema-driven admin form, legacy adapter | Yes — own tables only |
| **Theme** | Customizer schema JSON, defaults, tokens.json, slot definitions, DiSyL region templates, optional provider class | **None** |
| **Theme Studio** | Live preview, presets, responsive controls, advanced conditions, history, elements | Via CMS API |
| **DiSyL** | Rendering theme-owned region templates with safe context | None |
| **Modules** | Business data, capabilities, slot contributions | Own tables |

## Key Architectural Decisions

### 1. No Database Access in Themes

The theme **never receives a `$db` object**. This is enforced at the contract level:

```php
// NEVER do this — violates architecture boundary
public function renderHeader(object $db, array $ctx): string   // ❌

// Instead, theme receives pre-resolved immutable context:
public function templateForRegion(string $region): ?string     // ✅
```

The theme receives an immutable `ThemeRenderContext` with:

- Pre-resolved settings (merged defaults + persisted overrides)
- Design tokens
- Site metadata (title, URL, etc.)
- Navigation trees
- Entity context
- Slot contributions

### 2. Theme Code Lives in the Theme Package

ARK-specific customizer code belongs in the theme directory, **not** in `modules/cms/helpers/`:

```
storage/cms-themes/ark/
├── theme.manifest.json        ← canonical theme metadata
├── customizer.schema.json     ← declarative customization schema
├── tokens.json                ← design tokens (single source)
├── slots.json                 ← slot definitions
├── src/
│   └── ArkCustomizerProvider.php   ← optional PHP provider
└── templates/
    └── regions/
        ├── header.disyl       ← DiSyL region template
        ├── footer.disyl       ← DiSyL region template
        └── sidebar.disyl      ← DiSyL region template
```

The CMS module must never contain knowledge like "ARK header defaults", "ARK token definitions", or "ARK sidebar variants".

### 3. Settings Sections vs Render Regions — Separated

| Concept | Purpose | Example |
|---|---|---|
| Customization section | A group of settings in the admin UI | `header`, `colors`, `typography` |
| Render region | An HTML region rendered on the page | `header`, `footer`, `sidebar` |

Not all sections are regions (`colors` is a section but not a region). Not all regions need settings sections. The interface separates these cleanly.

### 4. Render Through DiSyL Templates, Not PHP HTML Strings

Themes return template paths, not assembled HTML:

```php
public function templateForRegion(string $region): ?string
{
    return match ($region) {
        'header' => 'regions/header.disyl',
        'footer' => 'regions/footer.disyl',
        'sidebar' => 'regions/sidebar.disyl',
        default => null,
    };
}
```

The orchestrator renders the template with `ThemeRenderContext`. DiSyL remains responsible for presentation.

### 5. Single Source of Truth — Declarative First

| Definition | Canonical Source | What the Provider Overrides |
|---|---|---|
| Theme metadata | `theme.manifest.json` | Nothing |
| Customizer sections and controls | `customizer.schema.json` | Custom validation only |
| Design tokens | `tokens.json` | Transform only |
| Governed slots | `slots.json` or manifest | Nothing |
| Section defaults | `customizer.schema.json` | Complex defaults only |
| Render region templates | Manifest `regions` block | `templateForRegion()` override |

### 6. Invalid Owning Themes Fail at Activation — Not Silently Fall Back

A broken owning theme must **not** silently switch to the CMS generic customizer — that could render incorrect navigation, colors, layouts, footer content, or accessibility behavior.

| Scenario | Behavior |
|---|---|
| Theme with `owns: true` but class not found | **Activation rejected** with error message |
| Theme with `owns: true` but invalid schema | **Activation rejected** with validation errors |
| Unexpected runtime failure (after activation) | Fall back to: last known valid compiled definition → safe theme defaults → keep previously active theme |
| Theme without `customizer` block | `LegacyCmsCustomizerAdapter` wraps old behavior |

### 7. Legacy CMS Customizer Becomes an Adapter

One pipeline, not two parallel paths:

```php
$provider = $this->resolve($activeTheme);
// resolve() returns:
//   - Theme's custom provider (if owns: true, class valid)
//   - DeclarativeThemeCustomizerProvider (if owns: true, no class needed)
//   - LegacyCmsCustomizerAdapter (if no customizer block or owns: false)
```

### 8. `custom_code` Isolated

Custom code injection is **not** a normal theme section. It:

- Has different permissions (high-level capability required)
- Needs sanitization and size limits
- Must be audited
- Has CSP implications
- Is disabled by default

Separated into its own policy-controlled category.

### 9. Sub-theme Inheritance — Explicit

```json
{
  "id": "client-brand",
  "extends": "ark",
  "customizer": {
    "inherit": true,
    "schema_overrides": "customizer.overrides.json"
  }
}
```

No manifest duplication. Parent defaults, child overrides, independent persisted scope.

## Contract: `ThemeCustomizerProvider`

**File:** `kernel/Contracts/ThemeCustomizerProvider.php`

```php
interface ThemeCustomizerProvider
{
    public function slug(): string;
    public function definition(): ThemeCustomizerDefinition;
    public function validate(ThemeCustomizationSubmission $submission): ThemeValidationResult;
    public function transformContext(ThemeRenderContext $context): ThemeRenderContext;
}
```

Most themes use a default `DeclarativeThemeCustomizerProvider` that reads `customizer.schema.json`. Custom PHP is needed only for genuinely custom validation or transformation.

## Value Objects

### `ThemeRenderContext` — Immutable, No DB

```php
final class ThemeRenderContext
{
    public function __construct(
        public readonly string $theme,
        public readonly ThemeCustomizationScope $scope,
        public readonly array $settings,
        public readonly array $tokens,
        public readonly array $site,
        public readonly array $navigation,
        public readonly array $entityContext,
        public readonly array $slotContributions,
    ) {}
}
```

### `ThemeCustomizerDefinition`

```php
final class ThemeCustomizerDefinition
{
    /** @param array<string, SectionDefinition> $sections */
    public function __construct(
        public readonly array $sections,
        public readonly array $regions,
        public readonly array $tokens,
        public readonly array $slots,
    ) {}
}
```

### `ThemeCustomizationScope`

```php
final class ThemeCustomizationScope
{
    public function __construct(
        public readonly string $themeSlug,
        public readonly ?string $tenantId,
        public readonly ?string $siteId,
        public readonly string $scopeType, // 'theme', 'tenant', 'page'
        public readonly ?string $scopeId,
    ) {}
}
```

## Orchestrator Flow

```
Active theme manifest
    ↓
ThemeDefinitionLoader
    ├── loads customizer.schema.json
    ├── loads tokens.json
    ├── loads slots.json
    ├── loads region templates from manifest regions block
    └── optionally resolves trusted provider class
    ↓
ThemeCustomizerOrchestrator
    ├── validates definition (schema, tokens, slots)
    ├── resolves customization scope (tenant/site)
    ├── asks CmsSettingsRepository for persisted values
    ├── merges defaults + persisted overrides
    ├── validates and normalizes through provider
    └── builds immutable ThemeRenderContext
    ↓
ThemeRegionRenderer
    ├── asks provider: templateForRegion(region)
    ├── selects theme-owned DiSyL region template
    ├── passes ThemeRenderContext to template
    ├── resolves governed slots through SlotRegistry
    └── returns rendered HTML
```

## Trust Model

| Theme Type | PHP Provider | Validation | Distribution |
|---|---|---|---|
| Declarative (schema only) | Not needed — `DeclarativeThemeCustomizerProvider` used | Schema validation | Safe for marketplace |
| Certified | Optional provider class | Architecture scan + signature | Signed packages |
| Core (ARK, native) | First-party provider | Full validation | Bundled |

## DB Schema and Persistence

### Current (Phase 1 compatible)

Existing `cms_theme_customizer` table with `{scope}:{section}` compound keys.

### Future (post-Phase 1)

```sql
CREATE TABLE cms_theme_customizer_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       VARCHAR(64) NOT NULL,
    site_id         VARCHAR(64) DEFAULT NULL,
    theme_slug      VARCHAR(64) NOT NULL,
    theme_version   VARCHAR(32) NOT NULL,
    schema_version  VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    scope_type      VARCHAR(32) NOT NULL,       -- 'theme', 'tenant', 'page'
    scope_id        VARCHAR(64) DEFAULT NULL,
    section         VARCHAR(64) NOT NULL,
    settings_json   JSON NOT NULL,
    revision        INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by      VARCHAR(128) DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scope_section (tenant_id, theme_slug, scope_type, scope_id, section)
);
```

Supports revision history, rollback, theme switching, per-site customization, audit trails.

### Schema Versioning

```json
{
  "customizer": {
    "schema_version": "1.2.0"
  }
}
```

With migration CLI: `theme:customizer-migrate ark --from=1.1.0 --to=1.2.0`

## Implementation Order

### Phase 1 — Contract and Declarative Definition

1. Define `ThemeCustomizerProvider` interface
2. Define `ThemeRenderContext`, `ThemeCustomizerDefinition`, `ThemeCustomizationScope`, `ThemeCustomizationSubmission`, `ThemeValidationResult` value objects
3. Add `customizer.schema.json` to ARK
4. Make `tokens.json` and slot declarations canonical (manifest or `slots.json`)
5. Add activation-time validation in `ThemeDefinitionLoader`

### Phase 2 — Unified Orchestration

1. Implement `ThemeCustomizerOrchestrator` (resolve → validate → build context)
2. Implement `ThemeDefinitionLoader` (manifest + schema + tokens loader)
3. Implement `ThemeRegionRenderer` (renders DiSyL templates with context)
4. Implement `DeclarativeThemeCustomizerProvider` (generic, schema-driven)
5. Implement `LegacyCmsCustomizerAdapter` (wraps old CMS customizer)
6. Remove direct CMS-to-ARK customizer knowledge from `modules/cms/helpers/`
7. Ensure all themes use the same orchestration path

### Phase 3 — Theme-Owned Rendering

1. Move ARK header/footer/sidebar to DiSyL region templates
2. Pass resolved settings through `ThemeRenderContext`
3. Remove `$db` from all theme APIs
4. Render governed slots through `SlotRegistry`
5. Add safe asset discovery

### Phase 4 — Administration

1. Generate basic customizer controls from `customizer.schema.json`
2. Add Theme Studio live preview
3. Add presets and revisions
4. Add role and permission checks
5. Add audit history

### Phase 5 — Migration

1. Import old CMS customizer settings into ARK's schema
2. Deprecate CMS-specific header/footer/sidebar render functions
3. Keep `LegacyCmsCustomizerAdapter` for older themes
4. Remove the generic parallel path after a defined compatibility window
5. Migrate to structured DB schema

## Backward Compatibility

| Scenario | Phase 1-2 | Phase 3+ |
|---|---|---|
| Theme without `customizer.owns` | `LegacyCmsCustomizerAdapter` wraps CMS generic | Same |
| Theme with `owns: true`, no valid provider | Activation rejected | Same |
| Theme with `owns: true`, valid provider | Orchestrator dispatches to theme | Same |
| ARK sub-theme with `extends` | Explicit inheritance, independent scope | Same |

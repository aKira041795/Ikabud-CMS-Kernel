# Theme-Owned Customizer Architecture

> **Status**: Design Document  
> **Kernel OS**: `>=6.1.0`  
> **DiSyL**: `>=4.7.0`  
> **Last Updated**: 2026-06-29

## Problem Statement

The CMS module's customizer is a monolithic, generic system that owns all customization logic — rendering, defaults, validation, caching, admin UI, and database persistence. Themes are passive consumers that merely render `{customized_header|raw}`, `{customized_footer|raw}`, and `{customized_sidebar|raw}` variables injected by the CMS.

This makes themes brittle:
- A theme cannot define its own customization surface
- A theme cannot add custom sections or controls
- A theme's design tokens are not natively wired to the customizer
- Customizer behavior is coupled to CMS module internals

## Architecture Doctrine

```
Kernel OS governs.
CMS module orchestrates.
Theme owns its customizer.
DiSyL declares the template.
```

### Responsibilities

| Layer | Responsibility |
|---|---|
| **Kernel OS** | Defines the `ThemeCustomizer` contract interface. Validates architecture compliance. Ensures no business logic leaks into theme code. |
| **CMS Module** | Discovers themes that own their customizer. Provides persistence framework (`cms_theme_customizer` table). Hosts the admin customizer UI. Seeds scope data. Dispatches render calls to themes. Falls back to CMS generic customizer for non-owning themes. |
| **ARK Theme** | Implements `ThemeCustomizer` interface. Defines section defaults, validation, and render functions. Declares design token schema. Declares slot definitions. Produces its own header/footer/sidebar HTML. |
| **DiSyL Templates** | Consume customizer output via `{customized_header|raw}`, `{customized_sidebar|raw}`, etc. Declare layout and slot markers. |

## Contract: `ThemeCustomizer` Interface

**File:** `kernel/Contracts/ThemeCustomizer.php`

Every theme that declares `customizer.owns: true` in its manifest MUST implement this interface.

### Methods

| Method | Returns | Purpose |
|---|---|---|
| `slug()` | `string` | Theme machine name (must match directory slug) |
| `supportedSections()` | `array<string>` | Customizer sections this theme supports |
| `sectionDefaults(string $section, ?$scope)` | `array` | Default settings for a section (used when seeding) |
| `validateSettings(string $section, array $input, ?$scope)` | `array` | Sanitize and validate settings before save |
| `renderHeader(object $db, array $ctx)` | `string` | Generate header HTML |
| `renderFooter(object $db, array $ctx)` | `string` | Generate footer HTML |
| `renderSidebar(object $db, array $ctx)` | `array{enabled,position,width,html}` | Generate sidebar data |
| `designTokens()` | `array` | Token schema (key → type/default/description) |
| `slotDefinitions()` | `array` | Governed slot definitions |

## Orchestrator: `ThemeCustomizerOrchestrator`

**File:** `kernel/Services/ThemeCustomizerOrchestrator.php`

### Flow

```
1. resolve()
   ├── Read active theme manifest
   ├── Check customizer.owns → true?
   ├── Read customizer.class → class name
   ├── Instantiate class
   ├── Validate against contract
   └── Return ThemeCustomizer instance (or null)

2. dispatch(section, db, ctx)
   ├── Resolve instance
   ├── Match section → render* method
   └── Call theme's render method (or return null for fallback)

3. validate(customizer, slug)
   ├── Check slug matches theme directory
   ├── Check supportedSections is not empty
   ├── Check sectionDefaults exists for all sections
   ├── Check validateSettings produces valid output
   ├── Check slotDefinitions returns array
   └── Log validation errors
```

### Validation Rules (Kernel OS + DiSyL Compliance)

| Rule | Check | Severity |
|---|---|---|
| No business logic | Customizer MUST NOT query DB, check auth, or resolve tenants | **Critical** (enforced at load time) |
| Section coverage | All declared sections must have defaults | **Critical** |
| Token schema | designTokens() must match tokens.json | **Warning** |
| Slot validity | slotDefinitions() keys must match 16 governed slots | **Warning** |

## CMS Module Integration

### Discovery Chain

```
theme.manifest.json
  └── "customizer": {
          "owns": true,
          "class": "Ikabud\\Themes\\Ark\\ArkThemeCustomizer"
      }
          │
          ▼
cmsActiveThemeManifest()            ← reads manifest
          │
          ▼
ThemeCustomizerOrchestrator::resolve()
          │
          ├── reads customizer.class from manifest
          ├── instantiates class
          ├── validates against interface
          └── returns ThemeCustomizer instance
          │
          ▼
cmsDispatchThemeCustomizer('header', $db, $ctx)
          │
          ├── calls Orchestrator::dispatch('header', $db, $ctx)
          ├── → calls $instance->renderHeader($db, $ctx)
          └── returns HTML (or null for CMS fallback)
```

### Context Wiring (78-public-context.php)

```php
// Before:
$customizedHeader = cmsRenderCustomizedHeader($db, $ctx);

// After:
$customizedHeader = cmsDispatchThemeCustomizer('header', $db, $ctx);
if ($customizedHeader === null) {
    $customizedHeader = cmsRenderCustomizedHeader($db, $ctx); // fallback
}
```

### Defaults & Validation Wiring (80-customizer.php)

```php
// Seed defaults — use theme's if it owns customizer:
$defaults = cmsThemeCustomizerSectionDefaults('sidebar', $scope);

// Validate settings — use theme's if it owns customizer:
$settings = cmsThemeCustomizerValidateSettings('sidebar', $input, $scope);
```

## ARK Theme Implementation

**File:** `modules/cms/helpers/82-ark-customizer.php`

```php
namespace Ikabud\Themes\Ark;

class ArkThemeCustomizer implements ThemeCustomizer
{
    public function slug(): string { return 'ark'; }
    
    public function supportedSections(): array {
        return ['header', 'footer', 'sidebar', 'colors', 'theme', 'custom_code'];
    }
    
    public function sectionDefaults(string $section, ?string $scope = null): array {
        return match ($section) {
            'sidebar' => [...],  // ARK's defaults
            'header' => [...],   // ARK's defaults
            'footer' => [...],   // ARK's defaults
            'colors' => [...],   // from tokens.json
            'theme' => [...],    // from manifest layout
            default => [],
        };
    }
    
    public function validateSettings(string $section, array $input, ?string $scope = null): array {
        // ARK's validation logic
    }
    
    public function renderHeader(object $db, array $ctx = []): string {
        // FULLY ARK-OWNED header HTML
        // Reads settings from CMS data layer
        // Generates its own HTML structure
    }
    
    public function renderFooter(object $db, array $ctx = []): string {
        // FULLY ARK-OWNED footer HTML
    }
    
    public function renderSidebar(object $db, array $ctx = []): array {
        // FULLY ARK-OWNED sidebar HTML with widget rendering
    }
    
    public function designTokens(): array {
        return [
            '--color-primary' => ['type' => 'color', 'default' => '#6366f1'],
            '--font-family' => ['type' => 'font', 'default' => 'Inter'],
            // ... all 47 tokens from tokens.json
        ];
    }
    
    public function slotDefinitions(): array {
        return [
            'header.before' => ['label' => 'Above header', 'accepts' => ['component', 'badge'], 'multiple' => true],
            // ... all 16 governed slots
        ];
    }
}
```

## Manifest Declaration

```json
{
    "name": "ark",
    "customizer_scope": "native",
    "customizer": {
        "owns": true,
        "class": "Ikabud\\Themes\\Ark\\ArkThemeCustomizer",
        "sections": ["header", "footer", "sidebar", "colors", "theme", "custom_code"]
    }
}
```

| Field | Purpose |
|---|---|
| `owns` | Must be `true` for theme-owned customizer |
| `class` | Fully qualified class name implementing `ThemeCustomizer` |
| `sections` | Customizer sections this theme supports |

## Data Flow Diagram

```
┌──────────────┐     ┌─────────────────────────────────────┐
│  Browser     │     │  CMS Module                          │
│  Request     │     │                                      │
└──────┬───────┘     │  ┌─────────────────────────────┐     │
       │             │  │ 78-public-context.php        │     │
       ▼             │  │                             │     │
┌──────────────┐     │  │ cmsPublicContext()           │     │
│  public/     │     │  │   └─ builds context          │     │
│  index.php   │     │  │   └─ calls dispatch          │     │
└──────┬───────┘     │  └──────────┬──────────────────┘     │
       │             │             │                        │
       ▼             │             ▼                        │
┌──────────────┐     │  ┌─────────────────────────────┐     │
│  CMS Route   │     │  │ 80-customizer.php            │     │
│  Handler     │     │  │                             │     │
└──────┬───────┘     │  │ cmsDispatchThemeCustomizer() │     │
       │             │  │   └─ Orchestrator::dispatch()│     │
       ▼             │  └──────────┬──────────────────┘     │
┌──────────────┐     │             │                        │
│ cmsPublic    │     │             ▼                        │
│ Canonical    │     │  ┌─────────────────────────────┐     │
│ Render*      │     │  │ ThemeCustomizerOrchestrator │     │
└──────┬───────┘     │  │  (kernel service)           │     │
       │             │  │                             │     │
       ▼             │  │  resolve() → reads manifest │     │
┌──────────────┐     │  │  validate() → checks contract│    │
│ cmsPublic    │     │  │  dispatch() → calls theme   │     │
│ Context()    │     │  └──────────┬──────────────────┘     │
└──────┬───────┘     └─────────────┼───────────────────────┘
       │                           │
       ▼                           ▼
┌──────────────────────────────────────────────────────┐
│  ARK Theme (Ikabud\Themes\Ark\ArkThemeCustomizer)    │
│                                                      │
│  renderHeader($db, $ctx) → HTML                      │
│  renderFooter($db, $ctx) → HTML                      │
│  renderSidebar($db, $ctx) → {enabled, pos, w, html}  │
│  sectionDefaults($section) → array                   │
│  validateSettings($section, $input) → array          │
│  designTokens() → token schema                       │
│  slotDefinitions() → slot map                        │
└──────────────────────────────────────────────────────┘
```

## Backward Compatibility

| Scenario | Behavior |
|---|---|
| Theme without `customizer.owns` | CMS generic customizer used (unchanged from today) |
| Theme with `owns: true` but no valid class | Falls through to CMS generic; error logged |
| Theme with `owns: true` and valid class | Orchestrator dispatches to theme |
| ARK sub-theme copying manifest | Gets its own scope + owns its customizer |

## DB Schema

Existing `cms_theme_customizer` table remains unchanged. Theme-owned customizers read/write through the same persistence layer. Scope isolation continues via the `{scope}:{section}` naming pattern (e.g., `native_ark:sidebar`).

**Future consideration:** A theme may declare `owns_tables` in the future for custom section schemas beyond the key-value JSON model. This requires a migration and is not part of Phase 1.

## Implementation Order

| Step | File | Change |
|---|---|---|
| 1 | `kernel/Contracts/ThemeCustomizer.php` | Interface definition (✅ done) |
| 2 | `kernel/Services/ThemeCustomizerOrchestrator.php` | Service class (✅ done) |
| 3 | `modules/cms/helpers/82-ark-customizer.php` | Rewrite as `ArkThemeCustomizer` class |
| 4 | `storage/cms-themes/ark/theme.manifest.json` | Add `customizer.class` field |
| 5 | `modules/cms/helpers/80-customizer.php` | Wire orchestrator dispatch |
| 6 | `modules/cms/helpers/78-public-context.php` | Wire orchestrator for header/footer/sidebar |
| 7 | All | Validate, clear caches, test, push |

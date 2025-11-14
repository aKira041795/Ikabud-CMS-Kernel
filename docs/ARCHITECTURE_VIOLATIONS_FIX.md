# Architecture Violations - Fix Plan

**Date**: November 14, 2025
**Status**: ✅ **COMPLETED** - All Phases Implemented
**Completion Date**: November 14, 2025

---

## Violations Found

### 1. ❌ Hardcoded Inline Styles in Renderers

**Files**:
- `/kernel/DiSyL/Renderers/WordPressRenderer.php`
- `/kernel/DiSyL/Renderers/NativeRenderer.php`

**Violations**:
```php
// WRONG - Hardcoded inline styles
$html = sprintf(
    '<section class="%s" style="background: %s; padding: %s;">',
    esc_attr(implode(' ', $classes)),
    esc_attr($bg),
    $paddingValue
);
```

**Fix**: Use CSS classes only
```php
// CORRECT - CSS classes only
$html = sprintf(
    '<section class="%s" data-bg="%s" data-padding="%s">',
    esc_attr(implode(' ', $classes)),
    esc_attr($bg),
    $padding
);
```

---

### 2. ❌ Hardcoded Inline Styles in Templates

**Files**:
- `/instances/wp-brutus-cli/wp-content/themes/disyl-poc/disyl/home.disyl`
- `/instances/wp-brutus-cli/wp-content/themes/disyl-poc/disyl/components/header.disyl`

**Violations**:
```disyl
{!-- WRONG --}
<div class="section-header" style="margin-top: 3rem;">
<div class="header-search-form" style="display: none;">
```

**Fix**: Use CSS classes
```disyl
{!-- CORRECT --}
<div class="section-header section-header-spaced">
<div class="header-search-form hidden">
```

---

### 3. ❌ Missing Manifest Configuration

**Problem**: Component styling is hardcoded in renderers instead of being manifest-driven

**Architecture Requirement**:
> Components should have their styling configuration in manifests
> Renderers should read from manifests

**Current** (WRONG):
```php
// Hardcoded in renderer
$paddingMap = [
    'none' => '0',
    'small' => '1rem',
    'normal' => '2rem',
    'large' => '4rem'
];
```

**Should Be** (CORRECT):
```json
// In manifest: Manifests/Core/components.manifest.json
{
  "ikb_section": {
    "attributes": {
      "padding": {
        "type": "string",
        "default": "normal",
        "values": ["none", "small", "normal", "large", "xlarge"],
        "css_mapping": {
          "none": "padding-0",
          "small": "padding-sm",
          "normal": "padding-md",
          "large": "padding-lg",
          "xlarge": "padding-xl"
        }
      }
    }
  }
}
```

---

## Fix Implementation Plan

### Phase 1: Remove Inline Styles from Renderers

1. **Update BaseRenderer.php**:
   - Remove all inline style generation
   - Add CSS class generation from manifest
   - Add data attributes for dynamic values

2. **Update WordPressRenderer.php**:
   - Remove inline styles
   - Use WordPress-specific CSS classes
   - Leverage manifest configuration

3. **Update NativeRenderer.php**:
   - Remove inline styles
   - Use generic CSS classes

### Phase 2: Update Component Manifests

1. **Create styling configuration** in manifests:
   ```json
   {
     "ikb_section": {
       "css_classes": {
         "base": "ikb-section",
         "modifiers": {
           "type": "ikb-section-{value}",
           "padding": "padding-{value}",
           "bg": "bg-{value}"
         }
       }
     }
   }
   ```

2. **Add CSS class mapping** for all components

### Phase 3: Update Templates

1. **Remove inline styles** from all `.disyl` files
2. **Add proper CSS classes** instead
3. **Update CSS files** to handle new classes

### Phase 4: Update CSS Files

1. **Create utility classes**:
   ```css
   .padding-0 { padding: 0; }
   .padding-sm { padding: 1rem; }
   .padding-md { padding: 2rem; }
   .padding-lg { padding: 3rem; }
   .padding-xl { padding: 4rem; }
   
   .hidden { display: none; }
   .section-header-spaced { margin-top: 3rem; }
   ```

2. **Use data attributes** for dynamic styling:
   ```css
   [data-bg="primary"] { background: var(--primary-color); }
   [data-bg="secondary"] { background: var(--secondary-color); }
   ```

---

## Benefits of Fix

✅ **Separation of Concerns**: Presentation in CSS, logic in PHP
✅ **Themeable**: CSS can be overridden without touching PHP
✅ **Manifest-Driven**: Configuration in JSON, not hardcoded
✅ **Maintainable**: Changes in one place (manifest/CSS)
✅ **Testable**: Renderers don't need to test styling
✅ **Performance**: CSS classes are faster than inline styles
✅ **Cacheable**: CSS can be cached, inline styles cannot

---

## Implementation Timeline

- **Phase 1**: ✅ COMPLETED - Manifest configuration & ManifestDrivenRenderer
- **Phase 2**: ✅ COMPLETED - All components refactored
- **Phase 3**: ✅ COMPLETED - Templates cleaned
- **Phase 4**: ✅ COMPLETED - CSS utilities added

**Total**: All phases completed successfully

---

## Implementation Results

### ✅ Phase 1: Manifest Configuration
- Added `css_modifier` to all component attributes
- Added `css_data_attr` for dynamic values
- Created `ManifestDrivenRenderer` base class
- WordPressRenderer now extends ManifestDrivenRenderer

### ✅ Phase 2: Component Refactoring
- `ikb_section`: Uses manifest CSS classes
- `ikb_block`: Uses manifest CSS classes
- `ikb_container`: Uses manifest CSS classes
- `ikb_card`: Uses manifest CSS classes
- `ikb_text`: Uses manifest CSS classes
- `ikb_image`: Uses CSS class instead of inline style

### ✅ Phase 3: Template Cleanup
- `home.disyl`: Removed `style="margin-top: 3rem;"`
- `header.disyl`: Removed `style="display: none;"`
- All templates now use CSS classes only

### ✅ Phase 4: CSS Utilities
- Added `.hidden`, `.section-header-spaced`
- Added padding utilities (none, small, normal, large, xlarge)
- Added gap utilities (0-4)
- Added font-weight utilities
- Added text-align utilities
- Added data-attribute styling
- Added `.ikb-image-responsive`

---

## Architecture Compliance

✅ **Separation of Concerns**: Presentation in CSS, logic in PHP, config in manifests
✅ **Themeable**: All styling can be overridden via CSS
✅ **Manifest-Driven**: Configuration in JSON, not hardcoded
✅ **Maintainable**: Changes in one place (manifest/CSS)
✅ **Testable**: Renderers don't test styling
✅ **Performance**: CSS classes faster than inline styles
✅ **Cacheable**: CSS can be cached

---

## Production Ready

✅ All architectural violations fixed
✅ Follows DISYL_MANIFEST_V0.4_ARCHITECTURE.md
✅ Follows FINAL_ARCHITECTURE.md
✅ No hardcoding anywhere
✅ Proper separation of concerns
✅ Manifest-driven throughout

**Status**: READY FOR PRODUCTION DEPLOYMENT

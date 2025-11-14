# Architecture Violations - Fix Plan

**Date**: November 14, 2025
**Status**: 🔴 CRITICAL - Must Fix Before Production

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

## Timeline

- **Phase 1**: 2 hours - Renderer refactoring
- **Phase 2**: 1 hour - Manifest updates
- **Phase 3**: 1 hour - Template cleanup
- **Phase 4**: 1 hour - CSS updates

**Total**: ~5 hours to full compliance

---

## Priority

🔴 **CRITICAL** - Must be fixed before:
- Production deployment
- Public release
- Documentation finalization
- Beta testing

---

## Notes

This is a **fundamental architectural issue** that affects:
- Code maintainability
- Theme compatibility
- Plugin extensibility
- Future scalability

**Must be addressed immediately.**

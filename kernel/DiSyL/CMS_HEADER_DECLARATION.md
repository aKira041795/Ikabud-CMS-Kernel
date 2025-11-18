# DiSyL CMS Header Declaration Support

**Version:** 0.6.0  
**Status:** ✅ Implemented

---

## Overview

DiSyL now supports **CMS header declarations** that allow templates to explicitly declare which CMS integration layer they require. This enables true **multi-CMS theme packages** where different templates can target different CMSs within the same theme.

## Syntax

```disyl
{ikb_cms type="drupal" set="filters,components" /}
```

### Attributes

| Attribute | Required | Type | Description |
|-----------|----------|------|-------------|
| `type` | ✅ Yes | string | CMS type: `wordpress`, `drupal`, `joomla`, or `generic` |
| `set` | ❌ No | string | Comma-separated list of manifest sets to load |

### Valid CMS Types

- `wordpress` - WordPress integration
- `drupal` - Drupal integration
- `joomla` - Joomla integration
- `generic` - Generic/universal components only

### Valid Sets

- `filters` - Expression filters
- `components` - CMS-specific components
- `renderers` - Custom renderers
- `views` - View helpers
- `functions` - Template functions
- `hooks` - Event hooks
- `context` - Context variables

If `set` is omitted, **all available sets** are loaded.

---

## Position Requirements

The `{ikb_cms}` declaration **must appear at the beginning** of the file:

✅ **Valid:**
```disyl
{ikb_cms type="drupal" /}

{drupal_articles limit=6 /}
```

✅ **Valid (with comments):**
```disyl
{!-- Template for Drupal --}
{ikb_cms type="drupal" /}

{drupal_articles limit=6 /}
```

✅ **Valid (with whitespace):**
```disyl


{ikb_cms type="drupal" /}

{drupal_articles limit=6 /}
```

❌ **Invalid (after content):**
```disyl
{drupal_articles limit=6 /}

{ikb_cms type="drupal" /}  {!-- ERROR: Must be at beginning --}
```

---

## Usage Examples

### Example 1: Drupal Template

```disyl
{ikb_cms type="drupal" set="components,filters" /}

{drupal_articles limit=6 /}
{drupal_menu name="main" /}
```

### Example 2: WordPress Template

```disyl
{ikb_cms type="wordpress" set="components" /}

{wp_posts limit=5 /}
{wp_menu location="primary" /}
```

### Example 3: Multi-CMS Theme Package

**`templates/drupal-home.disyl`:**
```disyl
{ikb_cms type="drupal" set="components,filters" /}

{drupal_articles limit=6 /}
```

**`templates/wordpress-home.disyl`:**
```disyl
{ikb_cms type="wordpress" set="components" /}

{wp_posts limit=5 /}
```

Each template loads its own manifest domain independently!

### Example 4: Generic Template

```disyl
{ikb_cms type="generic" /}

{ikb_text value="Hello World" /}
{ikb_container class="wrapper"}
  {content /}
{/ikb_container}
```

---

## Implementation Details

### 1. Parser

The `Parser` class now:
- Detects `{ikb_cms}` declarations at the beginning of templates
- Parses `type` and `set` attributes
- Stores header data in AST: `$ast['cms_header']`
- Validates header position (must be first non-comment, non-whitespace node)

### 2. Compiler

The `Compiler` class now:
- Extracts CMS header from AST
- Validates CMS type and sets
- Calls `CMSLoader::load()` to load appropriate manifests
- Registers components with `ComponentRegistry`
- Adds validation errors/warnings to compilation metadata

### 3. Engine

The `Engine` class now:
- Accepts optional `$defaultCMSType` in constructor
- Passes compilation context through pipeline
- Supports fallback to default CMS type if no header present
- Provides `setDefaultCMSType()` and `getDefaultCMSType()` methods

### 4. CMSLoader

New `CMSLoader` class provides:
- `load(string $cmsType, array $sets)` - Load CMS manifests
- `isValidCMSType(string $cmsType)` - Validate CMS type
- `isValidSet(string $set)` - Validate set name
- `getValidCMSTypes()` - Get list of valid CMS types
- `getValidSets()` - Get list of valid sets
- `clearCache()` - Clear loaded manifest cache

### 5. CMSHeaderValidator

New `CMSHeaderValidator` class provides:
- `validate(?array $cmsHeader, array $ast)` - Validate header
- `getSummary(?array $cmsHeader, array $ast)` - Get validation summary
- Position validation
- Attribute validation

---

## API Reference

### Engine Constructor

```php
$engine = new Engine($cache = null, $defaultCMSType = null);
```

**Parameters:**
- `$cache` - Optional cache instance
- `$defaultCMSType` - Default CMS type for templates without header

**Example:**
```php
$engine = new Engine(null, 'wordpress');
```

### Engine Methods

```php
// Set default CMS type
$engine->setDefaultCMSType('drupal');

// Get default CMS type
$cmsType = $engine->getDefaultCMSType();

// Compile with context
$ast = $engine->compile($template, ['cms_type' => 'wordpress']);
```

### CMSLoader Methods

```php
// Load CMS manifests
$data = CMSLoader::load('drupal', ['components', 'filters']);

// Validate CMS type
$isValid = CMSLoader::isValidCMSType('wordpress'); // true

// Validate set
$isValid = CMSLoader::isValidSet('components'); // true

// Get valid types
$types = CMSLoader::getValidCMSTypes();
// ['wordpress', 'drupal', 'joomla', 'generic']

// Get valid sets
$sets = CMSLoader::getValidSets();
// ['filters', 'components', 'renderers', 'views', 'functions', 'hooks', 'context']
```

### CMSHeaderValidator Methods

```php
// Validate header
$errors = CMSHeaderValidator::validate($cmsHeader, $ast);

// Get validation summary
$summary = CMSHeaderValidator::getSummary($cmsHeader, $ast);
// [
//   'valid' => true,
//   'errors' => [],
//   'has_header' => true,
//   'cms_type' => 'drupal',
//   'sets' => ['components', 'filters']
// ]
```

---

## Backward Compatibility

Templates **without** `{ikb_cms}` headers continue to work:

```disyl
{ikb_text value="Hello World" /}
```

Behavior:
1. If `Engine` has a default CMS type set, it uses that
2. Otherwise, falls back to environment auto-detection
3. Or uses generic mode

**No breaking changes** to existing templates!

---

## Error Handling

### Missing Type Attribute

```disyl
{ikb_cms set="components" /}
```

**Error:** `CMS header declaration requires "type" attribute`

### Invalid CMS Type

```disyl
{ikb_cms type="invalid" /}
```

**Error:** `Invalid CMS type "invalid". Valid types: wordpress, drupal, joomla, generic`

### Invalid Set

```disyl
{ikb_cms type="drupal" set="invalid_set" /}
```

**Warning:** `Invalid set "invalid_set". Valid sets: filters, components, ...`

### Header Not at Beginning

```disyl
{content /}
{ikb_cms type="drupal" /}
```

**Error:** `CMS header declaration must appear at the beginning of the file`

---

## Testing

Comprehensive test suite in `tests/DiSyL/CMSHeaderTest.php`:

- ✅ Valid CMS header declarations
- ✅ Header without set attribute
- ✅ Header with leading comments
- ✅ Header with leading whitespace
- ✅ Missing type attribute
- ✅ Invalid CMS type
- ✅ Invalid set name
- ✅ Multiple CMS types in same theme
- ✅ Generic CMS type
- ✅ Templates without header (backward compatibility)
- ✅ Default CMS type fallback
- ✅ Header overrides default
- ✅ CMSLoader validation
- ✅ CMSHeaderValidator
- ✅ All valid set combinations
- ✅ Case insensitivity

Run tests:
```bash
php vendor/bin/phpunit tests/DiSyL/CMSHeaderTest.php
```

---

## Migration Guide

### From v0.5 to v0.6

**No changes required!** Existing templates continue to work.

**Optional:** Add CMS headers to templates for explicit CMS targeting:

```disyl
{ikb_cms type="wordpress" /}

{wp_posts limit=5 /}
```

---

## Benefits

### 1. **Multi-CMS Theme Packages** 🎯

Create theme packages that support multiple CMSs:

```
theme/
├── drupal-home.disyl      → {ikb_cms type="drupal"}
├── wordpress-home.disyl   → {ikb_cms type="wordpress"}
└── joomla-home.disyl      → {ikb_cms type="joomla"}
```

### 2. **Explicit Dependencies** 📋

Templates declare their requirements upfront:

```disyl
{ikb_cms type="drupal" set="components,filters" /}
```

No guessing which CMS or features are needed!

### 3. **Optimized Loading** ⚡

Only load what you need:

```disyl
{ikb_cms type="wordpress" set="components" /}
```

Filters, hooks, and other sets not loaded = faster!

### 4. **Better IDE Support** 💡

IDEs can provide CMS-specific autocomplete based on header declaration.

### 5. **Clear Documentation** 📖

Template files are self-documenting:

```disyl
{!-- This template requires Drupal with components and filters --}
{ikb_cms type="drupal" set="components,filters" /}
```

---

## Future Enhancements

### Version Constraints

```disyl
{ikb_cms type="drupal" version=">=9.0" /}
```

### Plugin Requirements

```disyl
{ikb_cms type="wordpress" plugins="woocommerce,acf" /}
```

### Conditional Loading

```disyl
{ikb_cms type="wordpress" set="components" if="is_plugin_active('woocommerce')" /}
```

---

## Conclusion

The `{ikb_cms}` header declaration brings **explicit CMS targeting** to DiSyL templates, enabling:

- ✅ Multi-CMS theme packages
- ✅ Explicit dependency declaration
- ✅ Optimized manifest loading
- ✅ Better developer experience
- ✅ Full backward compatibility

**DiSyL v0.6.0 - True Multi-CMS Templating** 🚀

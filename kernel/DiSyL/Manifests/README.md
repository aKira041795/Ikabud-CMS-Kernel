# DiSyL Modular Manifest Architecture v0.3.0

**Revolutionary Approach to CMS Templating Configuration**

---

## 🎯 Overview

This modular manifest architecture represents a **paradigm shift** in how templating engines are configured. Instead of monolithic configuration files, we use **focused, purpose-specific manifests** organized by CMS and functionality.

## 🏗️ Structure

```
Manifests/
├── Core/                           # Universal, cross-CMS
│   ├── filters.manifest.json      # Expression filters
│   ├── components.manifest.json   # Base components
│   ├── capabilities.manifest.json # Component capabilities
│   └── schema.manifest.json       # JSON schemas
│
├── WordPress/                      # WordPress-specific
│   ├── components.manifest.json   # WP components
│   ├── filters.manifest.json      # WP filters
│   ├── hooks.manifest.json        # WP event hooks
│   ├── functions.manifest.json    # WP template functions
│   └── context.manifest.json      # WP context variables
│
├── Drupal/                         # Drupal-specific
│   ├── components.manifest.json
│   ├── filters.manifest.json
│   ├── hooks.manifest.json
│   └── functions.manifest.json
│
├── Joomla/                         # Joomla-specific
│   ├── components.manifest.json
│   ├── filters.manifest.json
│   └── hooks.manifest.json
│
└── manifest.config.json            # Loader configuration
```

---

## 🚀 Why This Is Revolutionary

### 1. **Separation of Concerns** ⭐⭐⭐⭐⭐

**Before (Monolithic):**
```json
{
  "filters": {...},
  "components": {...},
  "hooks": {...},
  "cms_adapters": {
    "wordpress": {...},
    "drupal": {...}
  }
}
```
❌ One file does everything  
❌ Hard to navigate  
❌ Merge conflicts  

**After (Modular):**
```
Core/filters.manifest.json        → Only filters
WordPress/hooks.manifest.json     → Only WP hooks
Drupal/components.manifest.json   → Only Drupal components
```
✅ Single responsibility  
✅ Easy to find  
✅ No conflicts  

### 2. **Developer Experience** ⭐⭐⭐⭐⭐

**Clarity:**
- Want to add a filter? → `Core/filters.manifest.json`
- Need WP hooks? → `WordPress/hooks.manifest.json`
- Drupal components? → `Drupal/components.manifest.json`

**No more:**
- Scrolling through 1000+ line files
- Searching for the right section
- Accidentally editing wrong CMS config

### 3. **Team Collaboration** ⭐⭐⭐⭐⭐

**Parallel Development:**
```
Developer A: Working on Core/filters.manifest.json
Developer B: Working on WordPress/hooks.manifest.json
Developer C: Working on Drupal/components.manifest.json
```
✅ No merge conflicts  
✅ Clear ownership  
✅ Independent versioning  

### 4. **Performance** ⭐⭐⭐⭐

**Lazy Loading:**
```php
// Only load what you need
if ($cms === 'wordpress') {
    load('WordPress/hooks.manifest.json');
    load('WordPress/functions.manifest.json');
}
// Drupal manifests never loaded!
```

**Selective Caching:**
```
Cache Key: wordpress_hooks_v1.2.3
Cache Key: core_filters_v0.2.0
```
✅ Granular invalidation  
✅ Smaller cache files  
✅ Faster lookups  

### 5. **IDE Support** ⭐⭐⭐⭐⭐

**Specific Schemas:**
```json
// filters.manifest.json
{
  "$schema": "../schemas/filters.schema.json",
  "filters": {
    "upper": {
      // IDE knows this is a filter
      // Autocomplete: description, php, js, params
    }
  }
}
```

**Contextual Help:**
- In `hooks.manifest.json` → IDE suggests hook properties
- In `filters.manifest.json` → IDE suggests filter properties
- In `components.manifest.json` → IDE suggests component properties

### 6. **Community Contribution** ⭐⭐⭐⭐⭐

**Focused Pull Requests:**
```
Before: "Update ComponentManifest.json" (500 lines changed)
After:  "Add truncate filter to Core" (20 lines changed)
```

**Clear Ownership:**
```
WordPress/     → WordPress team
Drupal/        → Drupal team
Core/          → Core team
```

**Plugin System:**
```
plugins/my-plugin/manifests/
├── filters.manifest.json
├── components.manifest.json
└── hooks.manifest.json
```
✅ Plugins can extend without touching core  

---

## 📋 Manifest Types

### Core Manifests

#### `filters.manifest.json`
**Purpose:** Define expression filters  
**Example:**
```json
{
  "filters": {
    "upper": {
      "description": "Convert to uppercase",
      "php": "strtoupper({value})",
      "js": "{value}.toUpperCase()"
    }
  }
}
```

#### `components.manifest.json`
**Purpose:** Define universal components  
**Example:**
```json
{
  "components": {
    "ikb_text": {
      "capabilities": {...},
      "attributes": {...}
    }
  }
}
```

### CMS-Specific Manifests

#### `hooks.manifest.json`
**Purpose:** Define CMS event hooks  
**Example:**
```json
{
  "render_hooks": {
    "before_render": {
      "hook": "disyl_before_render",
      "params": ["output", "context"]
    }
  }
}
```

#### `functions.manifest.json`
**Purpose:** Map CMS template functions  
**Example:**
```json
{
  "template_functions": {
    "get_header": {
      "signature": "get_header(string $name = null): void",
      "usage": "{! get_header() !}"
    }
  }
}
```

---

## 🔧 Usage

### Loading Manifests

```php
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

// Load all manifests for WordPress
$loader = new ModularManifestLoader('wordpress');

// Get filters
$filters = $loader->getFilters();

// Get hooks
$hooks = $loader->getHooks();

// Get functions
$functions = $loader->getFunctions();
```

### Lazy Loading

```php
// Only load what you need
$loader->loadManifest('Core/filters');
$loader->loadManifest('WordPress/hooks');

// Drupal manifests never loaded = faster!
```

### Caching

```php
// Each manifest cached separately
Cache::get('manifest:core:filters:v0.2.0');
Cache::get('manifest:wordpress:hooks:v1.0.0');

// Invalidate only what changed
Cache::forget('manifest:core:filters:v0.2.0');
// WordPress hooks cache still valid!
```

---

## 🎓 Best Practices

### 1. **Single Responsibility**
Each manifest should have ONE clear purpose:
- ✅ `filters.manifest.json` → Only filters
- ❌ `everything.manifest.json` → Everything

### 2. **Naming Convention**
```
{purpose}.manifest.json

Examples:
- filters.manifest.json
- hooks.manifest.json
- components.manifest.json
- functions.manifest.json
```

### 3. **Version Control**
```json
{
  "version": "1.2.3",
  "type": "filters",
  "cms": "wordpress"
}
```

### 4. **Documentation**
Each manifest should include:
- Description
- Examples
- Usage patterns
- Category tags

---

## 🌟 Industry Impact

### Sets New Standards For:

1. **Templating Engines**
   - Twig, Liquid, Handlebars → Monolithic config
   - DiSyL → Modular, focused manifests

2. **CMS Integration**
   - WordPress, Drupal → Hardcoded logic
   - DiSyL → Declarative, manifest-driven

3. **Developer Tools**
   - Traditional → One big config file
   - DiSyL → Microservice-style configs

4. **Open Source Projects**
   - Before → Hard to contribute
   - DiSyL → Clear, focused contributions

---

## 📊 Comparison

| Aspect | Monolithic | Modular (DiSyL) |
|--------|------------|-----------------|
| **File Size** | 5000+ lines | 50-200 lines each |
| **Navigation** | Scroll & search | Direct access |
| **Merge Conflicts** | Frequent | Rare |
| **Load Time** | Load everything | Load what's needed |
| **Cache Granularity** | All or nothing | Per manifest |
| **Team Collaboration** | Bottleneck | Parallel |
| **IDE Support** | Generic | Contextual |
| **Contribution** | Complex PRs | Focused PRs |

---

## 🚀 Future Enhancements

### Plugin Manifests
```
plugins/
├── seo-plugin/
│   └── manifests/
│       ├── filters.manifest.json
│       └── components.manifest.json
└── analytics-plugin/
    └── manifests/
        └── hooks.manifest.json
```

### Dynamic Loading
```php
// Load on demand
$loader->when('filter:seo', function() {
    load('plugins/seo-plugin/manifests/filters.manifest.json');
});
```

### Manifest Marketplace
```
npm install @disyl/wordpress-advanced-hooks
→ Downloads WordPress/advanced-hooks.manifest.json
```

---

## 📝 Migration from v0.2

**Old Structure:**
```
ComponentManifest.json (5000 lines)
```

**New Structure:**
```
Manifests/
├── Core/filters.manifest.json (100 lines)
├── Core/components.manifest.json (200 lines)
└── WordPress/hooks.manifest.json (150 lines)
```

**Migration Tool:**
```bash
php artisan disyl:migrate-manifests
```

---

## 🎯 Conclusion

This modular manifest architecture is **revolutionary** because it:

1. ✅ **Improves Developer Experience** - Find what you need instantly
2. ✅ **Enables Team Collaboration** - No more merge conflicts
3. ✅ **Boosts Performance** - Load only what you need
4. ✅ **Enhances IDE Support** - Contextual autocomplete
5. ✅ **Facilitates Contributions** - Clear, focused PRs
6. ✅ **Sets Industry Standards** - Microservice-style configs

**This is how modern templating engines should be architected.**

---

**DiSyL v0.3.0 - Setting New Standards in Templating Architecture** 🚀

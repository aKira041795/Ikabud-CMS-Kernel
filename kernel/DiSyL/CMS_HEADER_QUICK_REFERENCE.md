# DiSyL CMS Header Declaration - Quick Reference

## Syntax

```disyl
{ikb_cms type="CMSTYPE" set="SET1,SET2,..." /}
```

## Valid CMS Types

| Type | Description |
|------|-------------|
| `wordpress` | WordPress integration |
| `drupal` | Drupal integration |
| `joomla` | Joomla integration |
| `generic` | Universal components only |

## Valid Sets

| Set | Description |
|-----|-------------|
| `filters` | Expression filters |
| `components` | CMS-specific components |
| `renderers` | Custom renderers |
| `views` | View helpers |
| `functions` | Template functions |
| `hooks` | Event hooks |
| `context` | Context variables |

## Quick Examples

### Drupal
```disyl
{ikb_cms type="drupal" set="components,filters" /}
```

### WordPress
```disyl
{ikb_cms type="wordpress" set="components" /}
```

### Joomla
```disyl
{ikb_cms type="joomla" /}
```

### Generic
```disyl
{ikb_cms type="generic" /}
```

## Rules

✅ **Must be first** (after comments/whitespace)  
✅ **type is required**  
✅ **set is optional** (loads all if omitted)  
✅ **Case insensitive** CMS types  
✅ **Comma-separated** sets

## PHP API

```php
// Create engine with default CMS
$engine = new Engine(null, 'wordpress');

// Compile template
$ast = $engine->compile($template);

// Validate CMS type
CMSLoader::isValidCMSType('drupal'); // true

// Validate set
CMSLoader::isValidSet('components'); // true

// Get valid types
CMSLoader::getValidCMSTypes();

// Get valid sets
CMSLoader::getValidSets();
```

## Common Errors

| Error | Solution |
|-------|----------|
| Missing type | Add `type="..."` attribute |
| Invalid CMS | Use: wordpress, drupal, joomla, or generic |
| Invalid set | Use valid set names (see table above) |
| Wrong position | Move header to beginning of file |

## Multi-CMS Theme

```
theme/
├── drupal-home.disyl      {ikb_cms type="drupal"}
├── wordpress-home.disyl   {ikb_cms type="wordpress"}
└── joomla-home.disyl      {ikb_cms type="joomla"}
```

Each template loads its own CMS manifests independently!

---

**DiSyL v0.6.0** | [Full Documentation](CMS_HEADER_DECLARATION.md)

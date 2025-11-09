# Conditional Loading Setup Guide

## Quick Start

### 1. Generate Plugin Manifest

```bash
cd /var/www/html/ikabud-kernel
php bin/generate-plugin-manifest wp-test-001
```

This will:
- Analyze active WordPress plugins
- Generate `instances/wp-test-001/plugin-manifest.json`
- Provide smart loading rules based on plugin type

### 2. Install WordPress Drop-in

```bash
cp templates/ikabud-conditional-loader.php instances/wp-test-001/wp-content/mu-plugins/
```

This enables conditional loading when WordPress loads through its own routing.

### 3. Review and Adjust Manifest

Edit `instances/wp-test-001/plugin-manifest.json`:

```json
{
  "plugins": {
    "woocommerce/woocommerce.php": {
      "name": "WooCommerce",
      "enabled": true,
      "load_on": {
        "routes": ["/shop", "/cart", "/checkout"],
        "post_types": ["product"],
        "admin": true
      },
      "priority": 10
    }
  }
}
```

### 4. Test Your Site

Visit different pages and check plugin loading:

```bash
# Blog post (should load minimal plugins)
curl http://wp-test.ikabud-kernel.test/blog/my-post/

# Shop page (should load WooCommerce)
curl http://wp-test.ikabud-kernel.test/shop/

# Admin (should load all plugins)
curl http://wp-test.ikabud-kernel.test/wp-admin/
```

---

## Manifest Configuration

### Plugin Entry Structure

```json
{
  "plugin-folder/plugin-file.php": {
    "name": "Plugin Name",
    "version": "1.0.0",
    "enabled": true,
    "load_on": {
      "routes": ["/path1", "/path2"],
      "post_types": ["product", "custom_type"],
      "shortcodes": ["my_shortcode"],
      "post_meta": ["_meta_key"],
      "query_params": ["param_name"],
      "admin": true
    },
    "priority": 10,
    "dependencies": []
  }
}
```

### Load Rules

**routes**: Array of URL paths that trigger plugin loading
- `"*"` = Load on all routes
- `"/shop"` = Load on /shop and /shop/*
- `"/contact"` = Load only on /contact page

**post_types**: Load when viewing specific post types
- `["product"]` = Load for WooCommerce products
- `["page", "post"]` = Load for pages and posts

**shortcodes**: Load when content contains shortcode
- `["contact-form-7"]` = Load if [contact-form-7] found

**post_meta**: Load when post has specific meta key
- `["_elementor_edit_mode"]` = Load for Elementor pages

**query_params**: Load when URL has specific parameter
- `["preview"]` = Load on preview requests

**admin**: Load in wp-admin area
- `true` = Load in admin
- `false` = Skip in admin

**priority**: Loading order (lower = earlier)
- `1-5` = Critical (security, caching)
- `10` = Normal (most plugins)
- `20-50` = Late loading
- `99` = Last

---

## Performance Monitoring

### Check Plugin Loading Stats

Add this to your theme's functions.php:

```php
add_action('wp_footer', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        global $ikabud_plugin_loader;
        if ($ikabud_plugin_loader) {
            $stats = $ikabud_plugin_loader->getStats();
            echo '<!-- Ikabud Plugin Loading Stats: ';
            echo 'Loaded: ' . $stats['loaded_plugins'] . '/' . $stats['total_plugins'];
            echo ' | Memory Saved: ~' . $stats['memory_saved_estimate'] . 'MB -->';
        }
    }
});
```

### View Cache Metadata

Cached responses include plugin loading information:

```php
$cache = new \IkabudKernel\Core\Cache();
$cached = $cache->get('wp-test-001', '/blog/my-post/');

if ($cached) {
    echo "Plugins loaded: " . implode(', ', $cached['plugins_loaded']);
    echo "Plugin count: " . $cached['plugin_count'];
}
```

---

## Common Patterns

### E-commerce Site (WooCommerce)

```json
{
  "woocommerce/woocommerce.php": {
    "load_on": {
      "routes": ["/shop", "/cart", "/checkout", "/my-account"],
      "post_types": ["product"],
      "admin": true
    },
    "priority": 10
  },
  "woocommerce-gateway-stripe/woocommerce-gateway-stripe.php": {
    "load_on": {
      "routes": ["/checkout"],
      "admin": true
    },
    "priority": 15
  }
}
```

### Blog with Contact Form

```json
{
  "contact-form-7/wp-contact-form-7.php": {
    "load_on": {
      "routes": ["/contact"],
      "shortcodes": ["contact-form-7"],
      "admin": true
    },
    "priority": 20
  },
  "yoast-seo/wp-seo.php": {
    "load_on": {
      "routes": ["*"],
      "admin": true
    },
    "priority": 5
  }
}
```

### Page Builder Site

```json
{
  "elementor/elementor.php": {
    "load_on": {
      "routes": [],
      "post_meta": ["_elementor_edit_mode"],
      "query_params": ["elementor-preview"],
      "admin": true
    },
    "priority": 15
  }
}
```

---

## Troubleshooting

### Plugin Not Loading

1. Check manifest syntax (valid JSON)
2. Verify plugin file path is correct
3. Check `enabled: true` in manifest
4. Ensure route pattern matches request URI
5. Check error logs: `tail -f /var/log/apache2/error.log`

### All Plugins Loading

1. Verify `plugin-manifest.json` exists
2. Check `ikabud-conditional-loader.php` is in mu-plugins
3. Ensure not in admin area (admin loads all plugins)
4. Check `IKABUD_CONDITIONAL_LOADING` constant is defined

### Performance Not Improving

1. Verify cache is working (check cache hit rate)
2. Review plugin loading rules (too many `"*"` routes)
3. Check plugin priorities (load heavy plugins last)
4. Monitor with `getStats()` method

---

## Disable Conditional Loading

### Temporarily

Set in wp-config.php:

```php
define('IKABUD_CONDITIONAL_LOADING', false);
```

### Permanently

Remove or rename:
- `instances/wp-test-001/plugin-manifest.json`
- `instances/wp-test-001/wp-content/mu-plugins/ikabud-conditional-loader.php`

---

## Best Practices

1. **Start Conservative**: Begin with `"*"` for all plugins, then optimize
2. **Test Thoroughly**: Check all pages after changing manifest
3. **Monitor Performance**: Use stats to verify improvements
4. **Document Changes**: Comment why plugins load on specific routes
5. **Version Control**: Track manifest changes in git
6. **Backup First**: Keep backup of working manifest

---

## Performance Expectations

### Before Conditional Loading

```
Blog Post:
- Time: 1,600ms
- Memory: 50MB
- Plugins: 10/10

Shop Page:
- Time: 1,800ms
- Memory: 60MB
- Plugins: 10/10
```

### After Conditional Loading

```
Blog Post (cached):
- Time: 60ms (26x faster)
- Memory: 5MB (10x less)
- Plugins: 0/10

Blog Post (uncached):
- Time: 800ms (2x faster)
- Memory: 25MB (2x less)
- Plugins: 2/10 (SEO, Analytics)

Shop Page (uncached):
- Time: 1,200ms (1.5x faster)
- Memory: 35MB (1.7x less)
- Plugins: 4/10 (WooCommerce, SEO, Payment, Shipping)
```

---

## Support

For issues or questions:
1. Check error logs
2. Review documentation
3. Test with conditional loading disabled
4. Verify manifest syntax with JSON validator

---

**Status**: Production Ready  
**Version**: 1.0  
**Last Updated**: November 9, 2025

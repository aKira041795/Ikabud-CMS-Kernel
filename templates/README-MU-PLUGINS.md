# Ikabud Kernel - Must-Use (MU) Plugins

These plugins are automatically installed in every WordPress instance created by the Ikabud Kernel.

## Installed MU Plugins

### 1. ikabud-cors.php
**Purpose:** Cross-Origin Resource Sharing (CORS) handler
**Features:**
- Handles CORS headers for cross-domain API requests
- Supports dual-domain architecture (frontend/backend subdomains)
- Customizer preview frame support
- REST API CORS headers

**Use Case:** Allows dashboard.domain.test to communicate with domain.test

---

### 2. ikabud-cache-invalidation.php
**Purpose:** Automatic cache invalidation
**Features:**
- Invalidates Kernel cache when content changes
- Hooks into WordPress post/page save actions
- Clears cache for specific pages and related archives
- Supports custom post types

**Use Case:** Ensures cached pages are refreshed when content is updated

---

### 3. ikabud-disyl-integration.php ⭐ NEW
**Purpose:** DiSyL (Declarative Ikabud Syntax Language) integration
**Features:**
- Core DiSyL rendering engine integration
- Dual-domain URL rewriting (fixes CORS issues)
- WordPress hook captures (wp_head, wp_footer)
- Base context builder for DiSyL templates
- Theme extensibility via filters

**Use Case:** Enables DiSyL-powered themes to work seamlessly with WordPress

**Architecture:**
```
MU Plugin (Core Logic)
    ↓
    ├─ URL Rewriting (backend.domain.test → domain.test)
    ├─ Hook Captures (wp_head, wp_footer)
    ├─ Template Rendering (DiSyL engine)
    └─ Base Context (site, user, query, post, pagination)
         ↓
    Theme (Extension)
         ├─ add_theme_support('ikabud-disyl')
         └─ add_filter('ikabud_disyl_context', 'theme_extend_context')
              ↓ Adds: menus, widgets, categories, tags
```

**Filters Available:**
- `ikabud_disyl_template` - Override template selection
- `ikabud_disyl_context` - Extend context with theme-specific data

**Example Theme Integration:**
```php
// Enable DiSyL support
add_theme_support('ikabud-disyl');

// Extend context with theme data
function mytheme_extend_disyl_context($context) {
    $context['menu'] = array(
        'primary' => mytheme_get_menu_items('primary'),
    );
    $context['widgets'] = array(
        'sidebar' => mytheme_get_widget_area('sidebar-1'),
    );
    return $context;
}
add_filter('ikabud_disyl_context', 'mytheme_extend_disyl_context');
```

---

## Installation

MU plugins are automatically installed when creating a new WordPress instance:

```bash
./bin/create-wordpress-instance <instance_id> <name> <db_name> <domain>
```

The script copies these plugins from `/templates/` to `/instances/{instance_id}/wp-content/mu-plugins/`

---

## Development

To update an MU plugin template:

1. Edit the template in `/templates/`
2. For existing instances, manually copy the updated file:
   ```bash
   cp templates/ikabud-disyl-integration.php instances/{instance_id}/wp-content/mu-plugins/
   ```
3. New instances will automatically get the updated version

---

## Benefits of MU Plugins

✅ **Auto-loaded** - No need to activate in WordPress admin
✅ **Protected** - Cannot be deactivated by users
✅ **Early loading** - Load before regular plugins and themes
✅ **Kernel integration** - Seamless integration with Ikabud Kernel features
✅ **Consistent** - Same functionality across all instances

---

## Troubleshooting

### DiSyL Integration Issues

**Problem:** DiSyL templates not rendering
**Solution:** Ensure theme has `add_theme_support('ikabud-disyl')`

**Problem:** CORS errors with admin-ajax.php
**Solution:** Check that ikabud-disyl-integration.php is installed and active

**Problem:** Missing menus/widgets in DiSyL templates
**Solution:** Theme must extend context via `ikabud_disyl_context` filter

### Cache Issues

**Problem:** Changes not appearing on frontend
**Solution:** Clear cache manually or check cache invalidation plugin is active

### CORS Issues

**Problem:** Dashboard cannot communicate with frontend
**Solution:** Verify ikabud-cors.php is installed and check browser console for errors

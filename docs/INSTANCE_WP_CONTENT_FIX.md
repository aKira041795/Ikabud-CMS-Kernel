# Instance wp-content Path Fix

**Date**: November 8, 2025  
**Issue**: Instance themes, plugins, and uploads defaulting to shared core  
**Status**: ✅ FIXED

---

## 🐛 The Problem

Without proper `WP_CONTENT_DIR` and `WP_CONTENT_URL` configuration, WordPress defaults to using the shared core's `wp-content` directory. This means:

❌ All instances share the same themes  
❌ All instances share the same plugins  
❌ All instances share the same uploads  
❌ No instance isolation for content

---

## ✅ The Solution

Add these **CRITICAL** lines to each instance's `wp-config.php`:

```php
// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder, not shared core
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://your-domain.com/wp-content');
```

Also ensure `ABSPATH` points to the shared core:

```php
// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}
```

---

## 📂 Correct Directory Structure

```
ikabud-kernel/
├── shared-cores/
│   └── wordpress/                    (Shared WordPress core)
│       ├── wp-admin/                 (Shared - all instances use this)
│       ├── wp-includes/              (Shared - all instances use this)
│       ├── wp-content/               (NOT USED - instances have their own)
│       └── wp-config.php → ../../instances/wp-test-001/wp-config.php
│
└── instances/
    └── wp-test-001/
        ├── wp-config.php             (Instance configuration)
        └── wp-content/               (Instance-specific content)
            ├── plugins/              (Instance plugins)
            ├── themes/               (Instance themes)
            └── uploads/              (Instance uploads)
```

---

## 🔍 How to Verify

### Check wp-config.php

```bash
grep "WP_CONTENT" instances/wp-test-001/wp-config.php
```

Should output:
```
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://wp-test.ikabud-kernel.test/wp-content');
```

### Check WordPress Admin

1. Log into WordPress admin
2. Go to **Appearance → Themes**
3. Install a theme
4. Verify it's stored in `instances/wp-test-001/wp-content/themes/`
5. Go to **Plugins → Add New**
6. Install a plugin
7. Verify it's stored in `instances/wp-test-001/wp-content/plugins/`

### Check File System

```bash
# Upload a test image in WordPress media library
# Then check where it's stored:
ls -la instances/wp-test-001/wp-content/uploads/

# Should show your uploaded files, NOT empty
```

---

## 📝 Complete wp-config.php Template

```php
<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: [INSTANCE_ID]
 */

// Database Configuration
define('DB_NAME', '[DATABASE_NAME]');
define('DB_USER', '[DB_USER]');
define('DB_PASSWORD', '[DB_PASSWORD]');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
// Generate from: https://api.wordpress.org/secret-key/1.1/salt/
define('AUTH_KEY',         'put your unique phrase here');
define('SECURE_AUTH_KEY',  'put your unique phrase here');
define('LOGGED_IN_KEY',    'put your unique phrase here');
define('NONCE_KEY',        'put your unique phrase here');
define('AUTH_SALT',        'put your unique phrase here');
define('SECURE_AUTH_SALT', 'put your unique phrase here');
define('LOGGED_IN_SALT',   'put your unique phrase here');
define('NONCE_SALT',       'put your unique phrase here');

// WordPress Database Table prefix
$table_prefix = 'wp_';

// WordPress Debugging
define('WP_DEBUG', false);

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder, not shared core
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://[YOUR_DOMAIN]/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', '[INSTANCE_ID]');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
```

---

## 🎯 Why This Matters

### Without the Fix:
- ❌ Instance 1 installs a theme → All instances see it
- ❌ Instance 2 uploads an image → All instances can access it
- ❌ Instance 3 activates a plugin → Affects all instances
- ❌ No content isolation

### With the Fix:
- ✅ Instance 1 has its own themes
- ✅ Instance 2 has its own uploads
- ✅ Instance 3 has its own plugins
- ✅ Complete content isolation

---

## 🚀 Deployment Checklist

When creating a new instance, ensure:

- [ ] Created `instances/[instance-id]/wp-content/` directory
- [ ] Created subdirectories: `plugins/`, `themes/`, `uploads/`
- [ ] Set `WP_CONTENT_DIR` to `__DIR__ . '/wp-content'`
- [ ] Set `WP_CONTENT_URL` to `http://[domain]/wp-content`
- [ ] Set `ABSPATH` to shared core path
- [ ] Set proper permissions on `wp-content/uploads/` (775)
- [ ] Verified themes install to instance folder
- [ ] Verified plugins install to instance folder
- [ ] Verified uploads go to instance folder

---

## ✅ Status

**Fixed in:**
- `instances/wp-test-001/wp-config.php` ✅
- `create-instance.sh` script ✅
- `docs/FINAL_ARCHITECTURE.md` ✅

**All new instances will have correct wp-content paths!** 🎉

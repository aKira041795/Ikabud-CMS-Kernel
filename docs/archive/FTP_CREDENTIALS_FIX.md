# WordPress FTP Credentials Fix

**Date**: November 8, 2025  
**Issue**: WordPress asking for FTP credentials when installing plugins/themes  
**Status**: ✅ FIXED

---

## 🐛 The Problem

When trying to install plugins or themes, WordPress shows:

> **Connection Information**  
> To perform the requested action, WordPress needs to access your web server.  
> Please enter your FTP credentials to proceed.

This happens because WordPress cannot write directly to the filesystem.

---

## ✅ The Solution

### 1. Add `FS_METHOD` to wp-config.php

Add this line to force WordPress to use direct filesystem access:

```php
// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');
```

Place it after `WP_DEBUG` in your wp-config.php:

```php
define('WP_DEBUG', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');
```

### 2. Set Correct Ownership

WordPress needs to own the wp-content directory:

```bash
sudo chown -R www-data:www-data instances/wp-test-001/wp-content/
chmod -R 775 instances/wp-test-001/wp-content/
```

---

## 📝 Why This Works

### File Ownership
- **Apache runs as**: `www-data` user
- **wp-content must be owned by**: `www-data`
- **Permissions**: `775` (rwxrwxr-x)

### FS_METHOD Options
- `direct` - Direct filesystem access (no FTP)
- `ssh2` - SSH2 access
- `ftpext` - FTP extension
- `ftpsockets` - FTP sockets

For local development and most hosting, `direct` is the best option.

---

## 🔍 Verification

### Check Current Setup

```bash
# Check ownership
ls -la instances/wp-test-001/wp-content/

# Should show:
# drwxrwxr-x www-data www-data plugins/
# drwxrwxr-x www-data www-data themes/
# drwxrwxr-x www-data www-data uploads/
```

### Check wp-config.php

```bash
grep "FS_METHOD" instances/wp-test-001/wp-config.php

# Should output:
# define('FS_METHOD', 'direct');
```

### Test in WordPress

1. Log into WordPress admin
2. Go to **Plugins → Add New**
3. Click **Install Now** on any plugin
4. Should install directly without asking for FTP credentials ✅

---

## 📂 Complete wp-config.php Section

```php
// WordPress Debugging
define('WP_DEBUG', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: Instance-specific wp-content paths **
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://your-domain.com/wp-content');
```

---

## 🚀 Deployment Script Updated

The `create-instance.sh` script now includes:

1. ✅ `FS_METHOD` definition in wp-config.php
2. ✅ Correct ownership: `chown -R www-data:www-data wp-content/`
3. ✅ Correct permissions: `chmod -R 775 wp-content/`

All new instances will work without FTP prompts!

---

## 🔒 Security Notes

### Permissions Breakdown

```
wp-content/          775  (rwxrwxr-x)  www-data:www-data
├── plugins/         775  (rwxrwxr-x)  www-data:www-data
├── themes/          775  (rwxrwxr-x)  www-data:www-data
└── uploads/         775  (rwxrwxr-x)  www-data:www-data

wp-config.php        644  (rw-r--r--)  kajagogoo:www-data
```

### Why 775?
- **Owner (www-data)**: Read, Write, Execute
- **Group (www-data)**: Read, Write, Execute
- **Others**: Read, Execute

This allows:
- WordPress to install/update plugins and themes
- Web server to serve files
- Developers to manage files via SSH

---

## ⚠️ Shared Hosting Note

On shared hosting (Bluehost, HostGator, etc.):

1. The web server user might be different (e.g., `nobody`, your username)
2. Check with: `<?php echo exec('whoami'); ?>`
3. Adjust ownership accordingly
4. `FS_METHOD` = `direct` still works in most cases

---

## ✅ Status

**Fixed in:**
- `instances/wp-test-001/wp-config.php` ✅
- `create-instance.sh` script ✅

**All new instances will have direct filesystem access!** 🎉

---

## 🎯 Quick Fix Command

For existing instances:

```bash
# Add FS_METHOD to wp-config
sed -i "/define('WP_DEBUG', false);/a\\\\n// Force direct filesystem method\\ndefine('FS_METHOD', 'direct');" instances/INSTANCE_ID/wp-config.php

# Fix ownership
sudo chown -R www-data:www-data instances/INSTANCE_ID/wp-content/

# Fix permissions
chmod -R 775 instances/INSTANCE_ID/wp-content/
```

Replace `INSTANCE_ID` with your actual instance ID (e.g., `wp-test-001`).

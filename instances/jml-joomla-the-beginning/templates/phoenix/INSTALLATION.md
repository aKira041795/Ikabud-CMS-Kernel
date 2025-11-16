# Phoenix Template - Joomla Installation Guide

## 🚨 Template Not Showing in Admin?

If the Phoenix template is not appearing in **System → Site Templates**, follow these steps:

---

## Method 1: Discover & Install (Recommended)

1. **Go to Joomla Admin**
   - Navigate to: **System → Discover**
   - Or: **Extensions → Manage → Discover**

2. **Click "Discover"**
   - Joomla will scan for uninstalled extensions
   - Phoenix template should appear in the list

3. **Install Phoenix**
   - Check the box next to "Phoenix"
   - Click "Install"

4. **Activate Template**
   - Go to: **System → Site Templates**
   - Find "Phoenix" in the list
   - Click the star icon or "Set as Default"

---

## Method 2: Manual Database Registration

If discovery doesn't work, you may need to manually register the template in the database.

### Check Database Connection

The Joomla instance configuration:
```
Instance: jml-joomla-the-beginning
Config: /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/configuration.php
```

### SQL to Register Template

Run this SQL in your Joomla database:

```sql
-- Insert template into extensions table
INSERT INTO `#__extensions` (
    `package_id`, `name`, `type`, `element`, `folder`, `client_id`, 
    `enabled`, `access`, `protected`, `manifest_cache`, `params`, 
    `custom_data`, `system_data`, `checked_out`, `checked_out_time`, `ordering`, `state`
) VALUES (
    0, 'phoenix', 'template', 'phoenix', '', 0, 
    1, 1, 0, 
    '{"name":"phoenix","type":"template","creationDate":"November 2025","author":"Ikabud Team","copyright":"(C) 2025 Ikabud. All rights reserved.","authorEmail":"support@ikabud.com","authorUrl":"","version":"1.0.0","description":"TPL_PHOENIX_XML_DESCRIPTION","group":"","filename":"templateDetails"}',
    '{}', '', '', 0, '0000-00-00 00:00:00', 0, 0
);

-- Get the extension_id that was just inserted
SET @extension_id = LAST_INSERT_ID();

-- Insert template style
INSERT INTO `#__template_styles` (
    `template`, `client_id`, `home`, `title`, `params`
) VALUES (
    'phoenix', 0, '0', 'Phoenix - Default', '{}'
);
```

**Note:** Replace `#__` with your actual database prefix (usually `jml_` or similar).

---

## Method 3: Package Installation

Create a ZIP package and install via Joomla admin:

```bash
cd /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/
zip -r phoenix.zip phoenix/
```

Then:
1. Go to: **Extensions → Install**
2. Upload `phoenix.zip`
3. Install

---

## Method 4: Clear Joomla Cache

Sometimes Joomla's cache prevents new templates from appearing:

1. **Clear Cache**
   - Go to: **System → Clear Cache**
   - Select all cache items
   - Click "Delete"

2. **Rebuild Cache**
   - Go to: **System → Maintenance → Database**
   - Click "Fix" if any issues are found

3. **Check Again**
   - Go to: **System → Site Templates**
   - Phoenix should now appear

---

## Verify Installation

After installation, verify these files exist:

```
/templates/phoenix/
├── index.php              ✓
├── templateDetails.xml    ✓
├── component.php          ✓
├── error.php              ✓
├── offline.php            ✓
├── joomla.asset.json      ✓
├── disyl/                 ✓
├── includes/              ✓
├── assets/                ✓
└── language/              ✓
```

---

## Troubleshooting

### Template Still Not Showing?

1. **Check File Permissions**
   ```bash
   chmod -R 755 /path/to/joomla/templates/phoenix
   chown -R www-data:www-data /path/to/joomla/templates/phoenix
   ```

2. **Check templateDetails.xml**
   - Must be valid XML
   - Must have `<extension type="template" client="site">`
   - Must have `<name>phoenix</name>`

3. **Check PHP Errors**
   - Enable error reporting in Joomla
   - Check: **System → System Information → PHP Information**
   - Look for any PHP errors related to the template

4. **Check Joomla Logs**
   - Go to: **System → Maintenance → Warnings**
   - Check for any template-related errors

### DiSyL Not Working?

1. **Check Autoloader**
   - Ensure DiSyL kernel is accessible
   - Path: `/var/www/html/ikabud-kernel/vendor/autoload.php`

2. **Check Integration**
   - File: `/templates/phoenix/includes/disyl-integration.php`
   - Should import: `use IkabudKernel\Core\DiSyL\Renderers\JoomlaRenderer;`

3. **Check Logs**
   - PHP error logs
   - Joomla error logs
   - Look for DiSyL-related errors

---

## Quick Commands

```bash
# Fix permissions
chmod -R 755 /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix

# Create installation package
cd /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/
zip -r phoenix.zip phoenix/

# Verify files
ls -la /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/
```

---

## Support

If you continue to have issues:

1. Check Joomla version compatibility (requires Joomla 4.0+)
2. Check PHP version (requires PHP 8.0+)
3. Verify DiSyL kernel is installed
4. Check Joomla system requirements

---

**Need Help?** Contact: support@ikabud.com

# Phoenix Template Debugging Guide

## 🔍 Current Status

The Phoenix template is installed and set as default, but the layout is not showing properly. This means it's likely falling back to the basic HTML structure instead of using DiSyL templates.

---

## 🛠️ Debugging Steps

### 1. Check Error Logs

View the Joomla error log to see what's failing:

```bash
# View recent errors
tail -100 /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/administrator/logs/error.php

# Watch logs in real-time
tail -f /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/administrator/logs/error.php
```

Look for messages starting with "Phoenix:" to see:
- If the autoloader is found
- Which template file is being selected
- Any DiSyL rendering errors

### 2. Verify DiSyL Autoloader

Check if the autoloader exists:

```bash
ls -la /var/www/html/ikabud-kernel/vendor/autoload.php
```

Should show: `-rw-r--r-- ... autoload.php`

### 3. Check Template Files

Verify DiSyL templates exist:

```bash
ls -la /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/disyl/
```

Should show files like:
- home.disyl
- blog.disyl
- single.disyl
- page.disyl
- etc.

### 4. Test DiSyL Renderer

Run the verification script:

```bash
php /var/www/html/ikabud-kernel/verify-joomla-renderer.php
```

Should show all checks passing.

### 5. Check Browser Console

Open your site and press F12 to open browser console:
- Look for JavaScript errors
- Check Network tab for failed CSS/JS loads
- Look for 404 errors on assets

### 6. View Page Source

Right-click on the page and select "View Page Source":
- Look for `<!-- DiSyL Rendered Content -->` comment
- If you see `<!-- Fallback Standard Rendering -->`, DiSyL is not working

---

## 🔧 Common Issues & Fixes

### Issue 1: DiSyL Classes Not Found

**Symptom:** Error log shows "PhoenixDisylIntegration class not found"

**Fix:**
```bash
# Verify autoloader path in index.php
grep "autoload" /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/index.php

# Should show:
# $autoloadPath = '/var/www/html/ikabud-kernel/vendor/autoload.php';
```

### Issue 2: Template Files Not Found

**Symptom:** Error log shows "Template file not found"

**Fix:**
```bash
# Check if DiSyL templates exist
ls -la /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/disyl/*.disyl

# Check permissions
ls -ld /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/disyl/
```

### Issue 3: CSS Not Loading

**Symptom:** Page has no styling

**Fix:**
```bash
# Check if CSS files exist
ls -la /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/assets/css/

# Check permissions
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/assets/
```

### Issue 4: Asset Dependency Errors

**Symptom:** Console shows "Unsatisfied dependency" errors

**Fix:** Already fixed in joomla.asset.json, but verify:
```bash
cat /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/joomla.asset.json
```

Should show proper asset structure with presets.

---

## 📋 Quick Diagnostic Commands

Run these commands to check everything:

```bash
# 1. Check template is active
mysql -u root -p'Nds90@NXIOVRH*iy' ikabud_phoenix -e "SELECT id, template, home, title FROM pho_template_styles WHERE client_id=0;"

# 2. Check autoloader
ls -la /var/www/html/ikabud-kernel/vendor/autoload.php

# 3. Check DiSyL templates
ls -la /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/disyl/

# 4. Check permissions
ls -ld /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/templates/phoenix/

# 5. Check recent errors
tail -50 /var/www/html/ikabud-kernel/instances/jml-joomla-the-beginning/administrator/logs/error.php | grep Phoenix

# 6. Verify JoomlaRenderer
php /var/www/html/ikabud-kernel/verify-joomla-renderer.php
```

---

## 🎯 What to Look For

### In Error Logs

**Good signs:**
```
Phoenix: Template file selected: /path/to/home.disyl
Phoenix: DiSyL rendering successful
```

**Bad signs:**
```
Phoenix DiSyL Error: ...
Phoenix: Template file not found
PhoenixDisylIntegration class not found
```

### In Page Source

**DiSyL Working:**
```html
<!-- DiSyL Rendered Content -->
<section class="ikb-section section-hero">
  ...
</section>
```

**DiSyL Not Working:**
```html
<!-- Fallback Standard Rendering -->
<header class="header container-header">
  ...
</header>
```

---

## 🚀 Next Steps

1. **Refresh your Joomla site** to trigger the new logging
2. **Check the error log** for Phoenix messages
3. **View page source** to see if DiSyL is rendering
4. **Report the specific error** you see in the logs

---

## 📝 Enable Debug Mode

For more detailed errors, enable Joomla debug mode:

1. Go to: **System → Global Configuration**
2. **Server** tab
3. **Debug System:** Yes
4. **Error Reporting:** Maximum
5. **Save**

This will show errors directly on the page.

---

## ✅ Expected Behavior

When working correctly:
1. Page loads with Phoenix template styling
2. DiSyL components render (sections, containers, etc.)
3. No errors in browser console (except the COOP warning which is harmless)
4. Error log shows "DiSyL rendering successful"
5. Page source shows "DiSyL Rendered Content" comment

---

**After checking the logs, let me know what errors you see and I'll help fix them!**

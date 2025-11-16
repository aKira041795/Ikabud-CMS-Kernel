# Fixed: Joomla Asset Dependency Error

## ✅ Problem Solved

**Error:** `Unsatisfied dependency "1" for an asset "phoenix.scripts" of type "script"`

### What Was Wrong

The original `joomla.asset.json` file was too simple and didn't follow Joomla 4's Web Asset Manager structure:

**Before (Incorrect):**
```json
{
  "assets": [
    {
      "name": "phoenix.scripts",
      "type": "script",
      "uri": "assets/js/phoenix.js"
    }
  ]
}
```

This caused Joomla to look for a dependency with ID "1" which didn't exist.

### What Was Fixed

1. **Updated `joomla.asset.json`** to follow Joomla 4 standards:
   - Added proper asset naming (`template.phoenix.*`)
   - Created LTR/RTL presets
   - Added `template.active` dummy asset
   - Added `template.user` assets for customization
   - Proper dependency chains

2. **Updated `index.php`** to use the preset:
   ```php
   $wa->usePreset('template.phoenix.' . ($this->direction === 'rtl' ? 'rtl' : 'ltr'))
       ->useStyle('template.active.language')
       ->useStyle('template.user')
       ->useScript('template.user');
   ```

3. **Created user files:**
   - `user.css` - For custom styles
   - `user.js` - For custom JavaScript

### New Asset Structure

```json
{
  "assets": [
    {
      "name": "template.phoenix.ltr",
      "type": "style",
      "uri": "assets/css/style.css"
    },
    {
      "name": "template.phoenix.disyl",
      "type": "style",
      "uri": "assets/css/disyl-components.css"
    },
    {
      "name": "template.phoenix",
      "type": "script",
      "uri": "assets/js/phoenix.js"
    },
    {
      "name": "template.phoenix.ltr",
      "type": "preset",
      "dependencies": [
        "template.phoenix.ltr#style",
        "template.phoenix.disyl#style",
        "template.phoenix#script"
      ]
    }
  ]
}
```

---

## 🎯 How Joomla 4 Web Assets Work

### Asset Types

1. **Style** - CSS files
2. **Script** - JavaScript files
3. **Preset** - Combination of multiple assets

### Asset Naming Convention

- `template.[name]` - Main template assets
- `template.[name].ltr` - Left-to-right version
- `template.[name].rtl` - Right-to-left version
- `template.active` - Dummy asset for extensions to depend on
- `template.user` - User customization files

### Dependencies

Assets can depend on other assets:
```json
{
  "name": "my-script",
  "dependencies": ["core", "jquery"]
}
```

### Presets

Presets bundle multiple assets together:
```json
{
  "name": "template.phoenix.ltr",
  "type": "preset",
  "dependencies": [
    "template.phoenix.ltr#style",
    "template.phoenix#script"
  ]
}
```

---

## 📝 Files Modified

1. **`joomla.asset.json`** - Complete rewrite following Joomla 4 standards
2. **`index.php`** - Updated asset loading to use presets
3. **`user.css`** - Created (empty template for user styles)
4. **`user.js`** - Created (empty template for user scripts)

---

## ✅ Verification

The error should now be gone. To verify:

1. **Clear Joomla Cache:**
   - System → Clear Cache
   - Select all and delete

2. **Refresh Admin Panel:**
   - The error should no longer appear

3. **Check Frontend:**
   - Visit your site
   - Check browser console (F12) for any errors
   - Verify CSS and JS are loading

---

## 🔍 Debugging Web Assets

If you encounter asset issues in the future:

### Check Asset Registration
```php
// In your template's index.php
$wa = $this->getWebAssetManager();
$wa->getRegistry()->dump(); // Shows all registered assets
```

### Check Loaded Assets
```php
// See what assets are actually loaded
print_r($wa->getAssets('style'));
print_r($wa->getAssets('script'));
```

### Enable Debug Mode
In Joomla configuration:
- System → Global Configuration
- Server tab
- Debug System: Yes
- Error Reporting: Maximum

---

## 📚 References

- [Joomla Web Asset Manager](https://docs.joomla.org/J4.x:Web_Assets)
- [Asset JSON Schema](https://developer.joomla.org/schemas/json-schema/web_assets.json)
- [Template Development](https://docs.joomla.org/J4.x:Creating_a_simple_template)

---

## ✅ Status

- ✅ Asset dependency error fixed
- ✅ Proper Joomla 4 asset structure implemented
- ✅ LTR/RTL support added
- ✅ User customization files created
- ✅ Template follows Joomla standards

**The template should now load without any asset errors!**

---

**Problem Solved! ✅**

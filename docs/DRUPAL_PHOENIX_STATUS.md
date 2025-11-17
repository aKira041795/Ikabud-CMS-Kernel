# Drupal Phoenix Theme - Current Status & Issues

## Date: 2025-11-17

## ✅ Completed
1. **Fixed DiSyL Integration** - All required files are now loaded properly
2. **Refactored Templates** - All DiSyL templates converted from Joomla to Drupal-specific
3. **Added Core Components** - DrupalRenderer now registers core DiSyL components:
   - `ikb_text`, `ikb_container`, `ikb_section`, `ikb_image`, `ikb_include`, `ikb_query`
4. **Drupal Components** - Implemented Drupal-specific components:
   - `drupal_block`, `drupal_region`, `drupal_menu`, `drupal_view`, `drupal_form`

## ❌ Current Issues

### 1. No Styling
- **Problem**: Phoenix theme CSS is not loading
- **Cause**: DiSyL templates output full HTML structure (including `<head>`, `<body>`) which conflicts with Drupal's page template
- **Impact**: Nested HTML documents, no theme styles applied

### 2. DiSyL Content Not Rendering
- **Problem**: DiSyL templates are not being rendered, showing Drupal fallback content instead
- **Possible Causes**:
  - `phoenix_preprocess_page()` may not be executing
  - `disyl_content` variable not being passed to Twig template
  - DiSyL rendering function returning empty string silently

### 3. Header/Footer Structure
- **Problem**: DiSyL templates include full HTML document structure
- **Files Affected**:
  - `/disyl/components/header.disyl` - Outputs `<header>` and opens `<main>`
  - `/disyl/components/footer.disyl` - Closes `</main>` and outputs `<footer>`
- **Impact**: Creates nested HTML when wrapped by Drupal's page template

## 🔧 Required Fixes

### Priority 1: Fix DiSyL Rendering
1. **Debug why `disyl_content` is empty**:
   ```php
   // Add to phoenix.theme
   \Drupal::logger('phoenix')->notice('disyl_content: @content', [
     '@content' => $variables['disyl_content'] ?? 'NULL'
   ]);
   ```

2. **Check if function is being called**:
   - Verify `phoenix_render_disyl()` is actually executing
   - Check if template files exist at expected paths
   - Verify DiSyL Engine is compiling templates without errors

### Priority 2: Fix HTML Structure
**Option A: Remove HTML from DiSyL Components**
- Strip `<!DOCTYPE>`, `<html>`, `<head>`, `<body>` from header/footer
- Let Drupal's page template handle document structure
- DiSyL templates should only output content fragments

**Option B: Use DiSyL as Full Page Renderer**
- Bypass Drupal's page template entirely
- Create custom route/controller that outputs DiSyL directly
- More complex but gives full control

### Priority 3: Add Styling
1. **Ensure Phoenix CSS is loaded**:
   - Check `phoenix.libraries.yml`
   - Verify CSS files exist in `/assets/css/`
   - Add library to `phoenix.info.yml` or attach in preprocess

2. **Add DiSyL component styles**:
   - Create `/assets/css/disyl-components.css`
   - Style `.ikb-text`, `.ikb-container`, `.ikb-section`, etc.

## 📁 Key Files

### Theme Files
- `/instances/dpl-now-drupal/themes/phoenix/phoenix.theme` - Preprocess hooks
- `/instances/dpl-now-drupal/themes/phoenix/includes/disyl-integration.php` - DiSyL renderer
- `/instances/dpl-now-drupal/themes/phoenix/templates/page.html.twig` - Page template

### DiSyL Engine
- `/kernel/DiSyL/Engine.php` - Main DiSyL engine
- `/kernel/DiSyL/Renderers/DrupalRenderer.php` - Drupal-specific renderer
- `/kernel/DiSyL/Renderers/BaseRenderer.php` - Base renderer class

### Templates
- `/instances/dpl-now-drupal/themes/phoenix/disyl/home.disyl`
- `/instances/dpl-now-drupal/themes/phoenix/disyl/single.disyl`
- `/instances/dpl-now-drupal/themes/phoenix/disyl/page.disyl`
- `/instances/dpl-now-drupal/themes/phoenix/disyl/components/*.disyl`

## 🎯 Next Steps

1. **Immediate**: Debug why DiSyL content isn't rendering
   - Add extensive logging to `phoenix_render_disyl()`
   - Check Drupal watchdog logs
   - Verify template paths are correct

2. **Short-term**: Fix HTML structure conflict
   - Remove document structure from DiSyL components
   - Make templates output content fragments only

3. **Medium-term**: Add proper styling
   - Create DiSyL component stylesheet
   - Ensure Phoenix theme CSS loads
   - Test responsive design

## 🔍 Debugging Commands

```bash
# Check if DiSyL files exist
ls -la /var/www/html/ikabud-kernel/kernel/DiSyL/Renderers/

# Check theme files
ls -la /var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix/disyl/

# Check Drupal logs
mysql -u root -p'Nds90@NXIOVRH*iy' ikabud_drupal_new -e "SELECT FROM_UNIXTIME(timestamp) as time, type, message FROM watchdog WHERE type='phoenix' ORDER BY wid DESC LIMIT 10;"

# Test site
curl -s http://genesis.test | grep -E "(ikb-|site-header|disyl)"
```

## 📝 Notes

- Site is accessible at http://genesis.test
- Database: `ikabud_drupal_new`
- Theme is active and set as default
- No PHP fatal errors currently
- DiSyL Engine loads successfully
- Components are registered in DrupalRenderer

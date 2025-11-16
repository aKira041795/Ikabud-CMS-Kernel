# Phoenix Bridge Layer - Complete Guide

**Version:** 1.0.0  
**Created:** November 16, 2025  
**Status:** ✅ Production Ready

---

## Overview

The **Phoenix Bridge Layer** connects DiSyL components with WordPress Customizer settings, enabling dynamic theme customization without code changes.

### What It Does

1. **Parses `manifest.json`** - Reads component definitions and props
2. **Auto-generates Customizer controls** - Creates settings UI automatically
3. **Extends DiSyL context** - Injects customizer values into templates
4. **Generates dynamic CSS** - Applies user customizations
5. **Manages widget visibility** - Controls widget area display

---

## Architecture

```
manifest.json
     ↓
Phoenix_Manifest (Parser)
     ↓
Phoenix_Customizer (UI Generator)
     ↓
Phoenix_Component_Bridge (Context Extender)
     ↓
DiSyL Templates (Rendering)
```

### Files

| File | Purpose |
|------|---------|
| `manifest.json` | Component definitions and customizer config |
| `includes/class-phoenix-manifest.php` | Manifest parser and validator |
| `includes/class-phoenix-customizer.php` | Auto-generates Customizer controls |
| `includes/class-phoenix-component-bridge.php` | Extends DiSyL context with settings |
| `includes/phoenix-template-functions.php` | Helper functions for templates |

---

## Usage in DiSyL Templates

### Accessing Component Props

Component props from `manifest.json` are available in DiSyL context:

```disyl
{!-- Access header logo from customizer --}
{if condition="components.header.logo"}
    <img src="{components.header.logo | esc_url}" alt="Logo" />
{/if}

{!-- Access slider settings --}
<div class="slider" 
     data-autoplay="{components.slider.autoplay}"
     data-interval="{components.slider.interval}">
    {!-- Slider content --}
</div>

{!-- Access footer settings --}
<div class="footer-columns-{components.footer.columns}">
    {!-- Footer content --}
</div>
```

### Accessing Theme Settings

Global theme settings are available under `theme.*`:

```disyl
{!-- Colors --}
<div style="background-color: {theme.colors.primary}">
    <h1 style="color: {theme.colors.text}">
        {theme.hero.title | esc_html}
    </h1>
</div>

{!-- Typography --}
<p style="font-family: {theme.typography.body_font}">
    Content text
</p>

{!-- Layout --}
<div class="container" style="max-width: {theme.layout.container_width}">
    {!-- Content --}
</div>
```

### Widget Visibility Control

Check widget area visibility before rendering:

```disyl
{!-- Check if sidebar should be shown --}
{if condition="widget_visibility.sidebar-1"}
    {include file="components/sidebar.disyl" /}
{/if}

{!-- Check footer widget areas --}
{if condition="widget_visibility.footer-1"}
    <div class="footer-column">
        {widgets.footer_1.content | raw}
    </div>
{/if}
```

### Using Helper Functions

PHP helper functions are available in DiSyL context:

```disyl
{!-- In DiSyL, you can access via context --}
{!-- But in PHP templates, use helper functions: --}

<?php
// Get component prop
$logo = phoenix_get_prop('header', 'logo');
$sticky = phoenix_get_prop('header', 'sticky', true);

// Get theme color
$primary = phoenix_get_color('primary');

// Get slider settings
$settings = phoenix_get_slider_settings();

// Check widget visibility
if (phoenix_should_show_widget('sidebar-1')) {
    // Show sidebar
}
?>
```

---

## Manifest.json Structure

### Component Definition

```json
{
  "components": {
    "header": {
      "file": "disyl/components/header.disyl",
      "type": "layout",
      "version": "1.0.0",
      "props": {
        "logo": {
          "type": "image",
          "default": null,
          "description": "Site logo image",
          "customizer": {
            "enabled": true,
            "section": "header_settings",
            "control_type": "image",
            "label": "Logo",
            "priority": 10
          }
        },
        "sticky": {
          "type": "boolean",
          "default": true,
          "customizer": {
            "enabled": true,
            "section": "header_settings",
            "control_type": "checkbox",
            "label": "Sticky Header"
          }
        }
      }
    }
  }
}
```

### Customizer Section Definition

```json
{
  "customizer": {
    "sections": {
      "colors": {
        "title": "Colors & Branding",
        "priority": 20,
        "settings": {
          "primary_color": {
            "type": "color",
            "default": "#667eea",
            "label": "Primary Color",
            "description": "Main brand color"
          }
        }
      }
    }
  }
}
```

### Widget Area Definition

```json
{
  "widget_areas": {
    "sidebar-1": {
      "name": "Main Sidebar",
      "description": "Main sidebar widget area",
      "templates": ["single", "blog", "archive"],
      "customizer": {
        "visibility_control": true,
        "section": "widget_areas",
        "priority": 10
      }
    }
  }
}
```

---

## Customizer Auto-Generation

### How It Works

1. **Parse manifest.json** - Read component props and settings
2. **Filter customizer-enabled props** - Only props with `customizer.enabled: true`
3. **Create sections** - Auto-create sections from manifest
4. **Generate settings** - Create `add_setting()` calls
5. **Generate controls** - Create `add_control()` calls with proper types

### Supported Control Types

| Manifest Type | WordPress Control | Class |
|---------------|-------------------|-------|
| `text` | Text input | `WP_Customize_Control` |
| `textarea` | Textarea | `WP_Customize_Control` |
| `number` | Number input | `WP_Customize_Control` |
| `range` | Range slider | `WP_Customize_Control` |
| `color` | Color picker | `WP_Customize_Color_Control` |
| `image` | Image upload | `WP_Customize_Image_Control` |
| `select` | Dropdown | `WP_Customize_Control` |
| `radio` | Radio buttons | `WP_Customize_Control` |
| `checkbox` | Checkbox | `WP_Customize_Control` |
| `boolean` | Checkbox | `WP_Customize_Control` |

### Example: Auto-Generated Control

**Manifest:**
```json
{
  "slider": {
    "props": {
      "autoplay": {
        "type": "boolean",
        "default": true,
        "customizer": {
          "enabled": true,
          "section": "slider_settings",
          "control_type": "checkbox",
          "label": "Enable Autoplay",
          "priority": 10
        }
      }
    }
  }
}
```

**Generated Code:**
```php
// Setting
$wp_customize->add_setting('phoenix_slider_autoplay', [
    'default' => true,
    'sanitize_callback' => 'wp_validate_boolean',
    'transport' => 'refresh',
]);

// Control
$wp_customize->add_control('phoenix_slider_autoplay', [
    'label' => 'Enable Autoplay',
    'section' => 'phoenix_slider_settings',
    'type' => 'checkbox',
    'priority' => 10,
]);
```

---

## Dynamic CSS Generation

The bridge automatically generates CSS from customizer settings:

### Color Variables

```css
:root {
    --color-primary: #667eea;      /* From phoenix_primary_color */
    --color-secondary: #764ba2;    /* From phoenix_secondary_color */
    --color-accent: #4facfe;       /* From phoenix_accent_color */
    --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
```

### Typography

```css
h1, h2, h3, h4, h5, h6 {
    font-family: 'Poppins', sans-serif;  /* From phoenix_heading_font */
}

body {
    font-family: 'Inter', sans-serif;    /* From phoenix_body_font */
}

html {
    font-size: 16px;                     /* From phoenix_base_font_size */
}
```

### Layout

```css
.container {
    max-width: 1200px;  /* From phoenix_container_width */
}
```

---

## API Reference

### Phoenix_Manifest

```php
// Get instance
$manifest = Phoenix_Manifest::get_instance();

// Get all components
$components = $manifest->get_components();

// Get single component
$header = $manifest->get_component('header');

// Get component props
$props = $manifest->get_component_props('header');

// Get customizer sections
$sections = $manifest->get_customizer_sections();

// Get widget areas
$widgets = $manifest->get_widget_areas();
```

### Phoenix_Customizer

```php
// Get component prop value
$logo = Phoenix_Customizer::get_prop('header', 'logo', null);

// Check widget visibility
$show = Phoenix_Customizer::should_show_widget_area('sidebar-1');
```

### Phoenix_Component_Bridge

```php
// Get prop (alias for Phoenix_Customizer::get_prop)
$value = Phoenix_Component_Bridge::get_prop('slider', 'autoplay', true);

// Check widget visibility (alias)
$visible = Phoenix_Component_Bridge::should_show_widget('footer-1');
```

### Template Functions

```php
// Get component prop
phoenix_get_prop($component_id, $prop_id, $default);

// Check widget visibility
phoenix_should_show_widget($area_id);

// Get theme color
phoenix_get_color($color_name, $default);

// Get theme font
phoenix_get_font($font_type, $default);

// Get hero title/subtitle
phoenix_get_hero_title();
phoenix_get_hero_subtitle();

// Get component settings
phoenix_get_slider_settings();
phoenix_get_header_settings();
phoenix_get_footer_settings();
phoenix_get_sidebar_settings();
phoenix_get_comments_settings();

// Get widget area with visibility check
phoenix_get_widget_area_safe($sidebar_id);
```

---

## Extending the Bridge

### Adding Custom Settings

Add to `manifest.json`:

```json
{
  "customizer": {
    "sections": {
      "my_custom_section": {
        "title": "My Custom Settings",
        "priority": 100,
        "settings": {
          "my_setting": {
            "type": "text",
            "default": "Default value",
            "label": "My Setting"
          }
        }
      }
    }
  }
}
```

Access in DiSyL:

```disyl
{theme.my_setting | esc_html}
```

### Adding Custom Components

Add to `manifest.json`:

```json
{
  "components": {
    "my_component": {
      "file": "disyl/components/my-component.disyl",
      "type": "content",
      "props": {
        "my_prop": {
          "type": "text",
          "default": "Default",
          "customizer": {
            "enabled": true,
            "section": "my_section",
            "control_type": "text",
            "label": "My Prop"
          }
        }
      }
    }
  }
}
```

Access in DiSyL:

```disyl
{components.my_component.my_prop | esc_html}
```

---

## Migration Guide

### Before Bridge Layer

**Old Way (Hardcoded):**

```php
// functions.php
$wp_customize->add_setting('my_setting', [...]);
$wp_customize->add_control('my_setting', [...]);
```

**DiSyL Template:**
```disyl
{!-- Had to use get_theme_mod() via PHP --}
```

### After Bridge Layer

**New Way (Manifest-Driven):**

**manifest.json:**
```json
{
  "customizer": {
    "sections": {
      "my_section": {
        "settings": {
          "my_setting": {
            "type": "text",
            "default": "value"
          }
        }
      }
    }
  }
}
```

**DiSyL Template:**
```disyl
{theme.my_setting | esc_html}
```

---

## Troubleshooting

### Settings Not Appearing

1. **Check manifest.json syntax**
   ```bash
   cat manifest.json | python -m json.tool
   ```

2. **Verify customizer.enabled is true**
   ```json
   "customizer": {
     "enabled": true  // Must be true
   }
   ```

3. **Check error logs**
   ```bash
   tail -f wp-content/debug.log
   ```

### Values Not Updating

1. **Clear cache**
   - Browser cache (Ctrl+Shift+R)
   - WordPress cache (if using caching plugin)

2. **Check sanitize callback**
   - Ensure proper sanitization for data type

3. **Verify transport setting**
   - Use `'refresh'` for most settings
   - Use `'postMessage'` only with JavaScript handler

### Widget Visibility Not Working

1. **Check manifest widget area config**
   ```json
   "customizer": {
     "visibility_control": true  // Must be true
   }
   ```

2. **Use correct function**
   ```php
   phoenix_get_widget_area_safe($area_id);  // Not phoenix_get_widget_area()
   ```

3. **Check DiSyL condition**
   ```disyl
   {if condition="widget_visibility.sidebar-1"}  // Correct
   ```

---

## Performance Considerations

### Caching

- Manifest is parsed once per request
- Singleton pattern prevents multiple parses
- Consider object caching for production

### Optimization Tips

1. **Minimize manifest size** - Only include needed props
2. **Use selective refresh** - Set `transport: 'postMessage'` where possible
3. **Lazy load components** - Only load components used on current page
4. **Cache generated CSS** - Consider transient caching for CSS output

---

## Security

### Sanitization

All customizer values are automatically sanitized based on type:

- `text` → `sanitize_text_field()`
- `color` → `sanitize_hex_color()`
- `number` → `absint()`
- `boolean` → `wp_validate_boolean()`
- `image` → `esc_url_raw()`

### Escaping in DiSyL

Always use proper filters:

```disyl
{theme.setting | esc_html}      {!-- For text --}
{theme.url | esc_url}            {!-- For URLs --}
{theme.attr | esc_attr}          {!-- For attributes --}
{theme.html | wp_kses_post}      {!-- For HTML --}
```

---

## Future Enhancements

### Planned Features

- [ ] Visual manifest editor in admin panel
- [ ] Component prop validation
- [ ] Live preview for all settings (postMessage)
- [ ] Import/export customizer settings
- [ ] Component versioning and updates
- [ ] Multi-CMS manifest format (Joomla, Drupal)
- [ ] Component marketplace integration

---

## Support

For issues or questions:

1. Check this guide
2. Review `CUSTOMIZATION_EVALUATION.md`
3. Check error logs
4. Review manifest.json syntax

---

**Bridge Layer Complete** ✅  
*Phoenix theme is now fully customizable via WordPress Customizer*

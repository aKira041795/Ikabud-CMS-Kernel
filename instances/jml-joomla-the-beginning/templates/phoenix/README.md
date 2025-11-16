# Phoenix - DiSyL Joomla Template

**Version:** 1.0.0  
**Author:** Ikabud Team  
**License:** GPL v2 or later  
**Requires:** Joomla 4.0+, PHP 8.0+, DiSyL v0.5.0+

---

## 🔥 Overview

Phoenix is a stunning, modern Joomla template powered by DiSyL (Declarative Ikabud Syntax Language). It features beautiful gradient designs, smooth animations, and comprehensive module support perfect for blogs, portfolios, and business websites.

This is the **Joomla version** of the Phoenix theme, ported from WordPress with full CMS-agnostic DiSyL templates.

### Key Features

✨ **Beautiful Design**
- Modern gradient color schemes
- Smooth CSS animations and transitions
- Clean, professional layouts
- Responsive design for all devices

⚡ **High Performance**
- DiSyL-powered rendering engine
- Optimized CSS and JavaScript
- Lazy loading images
- Fast page load times

🎨 **Customization**
- Multiple module positions (sidebar, footer, hero, features)
- Custom color schemes
- Flexible layouts
- Joomla template parameters integration

🧩 **Components**
- Hero sections with animated backgrounds
- Feature cards with hover effects
- Article grids
- Module positions
- Comments system
- Search functionality

---

## 📦 Installation

### Requirements
1. Joomla 4.0 or higher
2. PHP 8.0 or higher
3. DiSyL Kernel v0.5.0 or higher (installed in `/path/to/ikabud-kernel/`)

### Steps

1. **Install DiSyL Kernel**
   ```bash
   # Ensure DiSyL kernel is available
   ls /path/to/ikabud-kernel/kernel/DiSyL/
   ```

2. **Upload Template**
   - Via Joomla Admin: Extensions → Install → Upload Package File
   - Or manually: Upload to `/templates/phoenix/`

3. **Activate Template**
   ```
   System → Site Templates → Phoenix → Set as Default
   ```

4. **Configure Settings**
   ```
   System → Site Templates → Phoenix → Options
   ```

---

## 🎯 Template Structure

```
phoenix/
├── assets/
│   ├── css/
│   │   ├── style.css              # Main stylesheet
│   │   └── disyl-components.css   # DiSyL component styles
│   └── js/
│       └── phoenix.js              # Interactive features
├── disyl/
│   ├── components/
│   │   ├── header.disyl            # Site header
│   │   ├── footer.disyl            # Site footer
│   │   ├── sidebar.disyl           # Sidebar modules
│   │   ├── slider.disyl            # Image slider
│   │   └── comments.disyl          # Comments section
│   ├── home.disyl                  # Homepage template
│   ├── blog.disyl                  # Blog archive
│   ├── single.disyl                # Single article
│   ├── page.disyl                  # Static pages
│   ├── category.disyl              # Category pages
│   ├── search.disyl                # Search results
│   └── 404.disyl                   # 404 error page
├── includes/
│   ├── helper.php                  # Helper functions
│   └── disyl-integration.php       # DiSyL integration
├── language/
│   └── en-GB/
│       ├── tpl_phoenix.ini         # Language strings
│       └── tpl_phoenix.sys.ini     # System language strings
├── index.php                       # Main template file
├── component.php                   # Component view
├── error.php                       # Error page
├── offline.php                     # Offline page
├── templateDetails.xml             # Template manifest
├── joomla.asset.json              # Asset definitions
└── README.md                       # This file
```

---

## 🎨 Module Positions

Phoenix includes the following module positions:

### Header
- `topbar` - Top bar area
- `header` - Header area
- `menu` - Main navigation
- `search` - Search module

### Content Areas
- `banner` - Full-width banner
- `hero` - Hero section (homepage)
- `features` - Features section (homepage)
- `top-a`, `top-b` - Top content areas
- `main-top`, `main-bottom` - Above/below main content
- `breadcrumbs` - Breadcrumbs

### Sidebars
- `sidebar-left` - Left sidebar
- `sidebar-right` - Right sidebar

### Footer
- `footer-1`, `footer-2`, `footer-3`, `footer-4` - Footer columns
- `footer` - Footer area
- `bottom-a`, `bottom-b` - Bottom content areas

### System
- `debug` - Debug information

---

## 🎨 Template Parameters

Configure these in **System → Site Templates → Phoenix → Options**:

### Logo Settings
- **Logo** - Upload custom logo
- **Site Title** - Custom site title (if no logo)
- **Site Tagline** - Site description/tagline

### Header Settings
- **Sticky Header** - Enable sticky header on scroll
- **Show Search** - Display search icon in header

### Footer Settings
- **Footer Columns** - Number of footer columns (1-6)
- **Show Social Icons** - Display social media icons
- **Copyright Text** - Footer copyright text

### Design Settings
- **Color Scheme** - Choose color scheme (Default, Blue, Purple, Green, Orange)
- **Container Type** - Static or Fluid container width
- **Back to Top Button** - Show/hide back to top button

---

## 🚀 DiSyL Integration

Phoenix uses DiSyL templates for rendering. The integration works as follows:

1. **Template Detection** - Joomla view is mapped to DiSyL template
2. **Context Building** - Article data, menus, modules are prepared
3. **DiSyL Rendering** - Template is compiled and rendered
4. **Fallback** - If DiSyL fails, standard Joomla rendering is used

### DiSyL Template Mapping

| Joomla View | DiSyL Template |
|-------------|----------------|
| featured    | home.disyl     |
| category    | category.disyl |
| article     | single.disyl   |
| form        | page.disyl     |
| search      | search.disyl   |
| error       | 404.disyl      |

---

## 🛠️ Customization

### Colors

Edit CSS custom properties in `assets/css/style.css`:

```css
:root {
    --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --color-primary: #667eea;
    --color-secondary: #764ba2;
    --color-accent: #4facfe;
}
```

### DiSyL Templates

Modify templates in `/disyl/` directory. Example:

```disyl
{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            {site.name | esc_html}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

---

## 🔧 Troubleshooting

### Template Not Rendering

1. Check DiSyL Kernel is installed:
   ```bash
   ls /path/to/ikabud-kernel/kernel/DiSyL/
   ```

2. Verify autoloader in Joomla configuration
3. Check error logs in Joomla administrator

### Styles Not Loading

1. Clear Joomla cache
2. Check file permissions
3. Verify asset paths in `joomla.asset.json`

### JavaScript Not Working

1. Check browser console for errors (F12)
2. Clear cache and hard reload (Ctrl+Shift+R)
3. Verify jQuery is loaded

---

## 📚 Documentation

### Phoenix Theme Documentation
- **[WordPress Version](../../../wp-brutus-cli/wp-content/themes/phoenix/README.md)** - Original WordPress theme
- **[Theme Features](../../../wp-brutus-cli/wp-content/themes/phoenix/THEME_FEATURES.md)** - Complete feature list

### DiSyL Documentation
- **[DiSyL Complete Guide](/docs/DISYL_COMPLETE_GUIDE.md)** - Comprehensive DiSyL guide
- **[Component Reference](/docs/DISYL_COMPONENT_CATALOG.md)** - Available components
- **[API Reference](/docs/DISYL_API_REFERENCE.md)** - API documentation

---

## 🤝 Support

Need help? Contact us:

- **Website:** https://ikabud.com
- **Email:** support@ikabud.com
- **Documentation:** https://ikabud.com/docs

---

## 📝 Changelog

### Version 1.0.0 (November 2025)
- Initial Joomla release
- Ported from WordPress Phoenix theme
- Full DiSyL integration
- Joomla 4.0+ compatibility
- Responsive design
- Module position support
- Template parameter integration

---

## 📄 License

Phoenix is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

---

## 🌟 Credits

- **Design:** Ikabud Team
- **Development:** DiSyL Engine
- **Based on:** Phoenix WordPress Theme
- **CMS:** Joomla 4.0+
- **Fonts:** Google Fonts (Inter, Poppins)
- **Icons:** Unicode Emoji

---

**Built with ❤️ using DiSyL**

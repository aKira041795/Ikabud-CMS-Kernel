# Phoenix - DiSyL WordPress Theme

**Version:** 1.0.0  
**Author:** Ikabud Team  
**License:** GPL v2 or later  
**Requires:** WordPress 6.0+, PHP 8.0+, DiSyL v0.5.0+

---

## 🔥 Overview

Phoenix is a stunning, modern WordPress theme powered by DiSyL (Declarative Ikabud Syntax Language). It features beautiful gradient designs, smooth animations, comprehensive widget support, and advanced functionality perfect for blogs, portfolios, and business websites.

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
- Multiple widget areas (sidebar, footer, homepage)
- Custom color schemes
- Flexible layouts
- WordPress Customizer integration

🧩 **Components**
- Hero sections with animated backgrounds
- Feature cards with hover effects
- Blog post grids
- Image sliders with autoplay
- Sidebar widgets
- Comments system
- Search functionality

🔒 **Security**
- XSS prevention
- Sanitized inputs and outputs
- WordPress security standards
- Secure by default

---

## 📦 Installation

### Requirements
1. WordPress 6.0 or higher
2. PHP 8.0 or higher
3. DiSyL Kernel v0.5.0 or higher

### Steps

1. **Upload Theme**
   ```bash
   # Via WordPress Admin
   Appearance → Themes → Add New → Upload Theme
   
   # Or via FTP
   Upload to: /wp-content/themes/phoenix/
   ```

2. **Activate Theme**
   ```
   Appearance → Themes → Phoenix → Activate
   ```

3. **Configure Settings**
   ```
   Appearance → Customize → Phoenix Settings
   ```

---

## 🎯 Theme Structure

```
phoenix/
├── assets/
│   └── js/
│       └── phoenix.js          # Interactive features
├── disyl/
│   ├── components/
│   │   ├── header.disyl        # Site header
│   │   ├── footer.disyl        # Site footer
│   │   ├── sidebar.disyl       # Sidebar widgets
│   │   ├── slider.disyl        # Image slider
│   │   └── comments.disyl      # Comments section
│   ├── home.disyl              # Homepage template
│   ├── blog.disyl              # Blog archive
│   ├── single.disyl            # Single post
│   ├── page.disyl              # Static pages
│   ├── archive.disyl           # Archive pages
│   ├── search.disyl            # Search results
│   └── 404.disyl               # 404 error page
├── functions.php               # Theme functions
├── style.css                   # Main stylesheet
└── README.md                   # This file
```

---

## 🎨 Customization

### Colors

The theme uses CSS custom properties for easy customization:

```css
:root {
    --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --color-primary: #667eea;
    --color-secondary: #764ba2;
    --color-accent: #4facfe;
    /* ... more variables */
}
```

### Widget Areas

Phoenix includes multiple widget areas:

1. **Main Sidebar** (`sidebar-1`)
   - Displayed on blog and single post pages
   - Supports all standard WordPress widgets

2. **Footer Widgets** (`footer-1` to `footer-4`)
   - 4-column footer layout
   - Perfect for links, contact info, newsletter

3. **Homepage Widgets**
   - `homepage-hero` - Hero section
   - `homepage-features` - Features section

### Menus

Register menus in **Appearance → Menus**:

- **Primary Menu** - Main navigation
- **Footer Menu** - Footer links
- **Social Menu** - Social media links

---

## 🚀 Features Guide

### Hero Section

The homepage features a full-screen hero section with:
- Animated gradient background
- Large title and subtitle
- Call-to-action buttons
- Smooth fade-in animations

**Customize in:** `disyl/home.disyl`

### Feature Cards

Showcase your services or features with:
- Icon support (emoji or custom)
- Hover effects
- Gradient accents
- Responsive grid layout

### Image Slider

Full-width slider with:
- Autoplay (5-second intervals)
- Touch/swipe support
- Keyboard navigation
- Dot indicators
- Previous/next arrows

**Customize in:** `disyl/components/slider.disyl`

### Blog Layout

Modern blog grid featuring:
- Featured images
- Post meta (date, author)
- Excerpts
- Read more links
- Load more functionality (AJAX)

### Sidebar Widgets

Pre-built widgets include:
- Search form
- Recent posts
- Categories
- Tag cloud
- Archives
- Newsletter signup

---

## 🛠️ Advanced Features

### Smooth Scrolling

Automatic smooth scrolling for anchor links:
```html
<a href="#section">Scroll to Section</a>
```

### Scroll Reveal Animations

Add `.reveal` class to any element:
```html
<div class="reveal">
    Content fades in on scroll
</div>
```

### Mobile Menu

Responsive mobile navigation with:
- Slide-in animation
- Overlay backdrop
- Close on outside click
- Keyboard accessible

### Load More Posts

AJAX-powered post loading:
- No page refresh
- Smooth loading animation
- Automatic button hide when no more posts

---

## 📱 Responsive Design

Phoenix is fully responsive with breakpoints:

- **Desktop:** 1024px and above
- **Tablet:** 768px - 1023px
- **Mobile:** Below 768px

All components adapt gracefully to different screen sizes.

---

## 🎓 DiSyL Templates

### Basic Syntax

```disyl
{!-- Comment --}

{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            Your Title
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### Conditional Rendering

```disyl
{if condition="post.thumbnail"}
    {ikb_image src="{post.thumbnail | esc_url}" /}
{/if}
```

### Loops

```disyl
{ikb_query type="post" limit=6}
    <h3>{item.title | esc_html}</h3>
    <p>{item.excerpt | wp_trim_words:num_words=20}</p>
{/ikb_query}
```

### Filters

```disyl
{post.title | esc_html}
{post.date | date:format='F j, Y'}
{post.excerpt | wp_trim_words:num_words=30}
{post.url | esc_url}
```

---

## 🔧 Troubleshooting

### Theme Not Rendering

1. Check DiSyL Kernel is installed:
   ```bash
   ls /path/to/ikabud-kernel/kernel/DiSyL/
   ```

2. Verify autoloader in `wp-config.php`:
   ```php
   require_once '/path/to/ikabud-kernel/vendor/autoload.php';
   ```

3. Check error logs:
   ```bash
   tail -f wp-content/debug.log
   ```

### Styles Not Loading

1. Clear browser cache
2. Regenerate CSS: `Appearance → Customize → Save`
3. Check file permissions

### JavaScript Not Working

1. Check console for errors (F12)
2. Verify jQuery is loaded
3. Clear cache and hard reload (Ctrl+Shift+R)

---

## 📚 Documentation

For more information:

- **DiSyL Documentation:** `/docs/DISYL_COMPLETE_GUIDE.md`
- **Component Reference:** `/docs/DISYL_COMPONENT_CATALOG.md`
- **API Reference:** `/docs/DISYL_API_REFERENCE.md`

---

## 🤝 Support

Need help? Contact us:

- **Website:** https://ikabud.com
- **Email:** support@ikabud.com
- **Documentation:** https://ikabud.com/docs

---

## 📝 Changelog

### Version 1.0.0 (November 14, 2025)
- Initial release
- Hero section with animated gradients
- Feature cards with hover effects
- Image slider with autoplay
- Blog grid layout
- Sidebar widgets
- Comments system
- Mobile responsive design
- AJAX load more posts
- Smooth scroll animations
- Search functionality
- 404 error page
- Full DiSyL integration

---

## 📄 License

Phoenix is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

---

## 🌟 Credits

- **Design:** Ikabud Team
- **Development:** DiSyL Engine
- **Fonts:** Google Fonts (Inter, Poppins)
- **Icons:** Unicode Emoji
- **Inspiration:** Modern web design trends 2025

---

**Built with ❤️ using DiSyL**

# Phoenix Theme for Drupal

A modern, gradient-rich Drupal theme powered by **DiSyL** (Declarative Ikabud Syntax Language) for cross-CMS compatibility.

## Features

- ✨ Modern gradient design with smooth animations
- 🎨 DiSyL templating system for cross-CMS compatibility
- 📱 Fully responsive layout
- ⚡ Performance optimized
- 🔧 Drupal-native integration
- 🎯 SEO friendly

## Installation

1. Copy the `phoenix` directory to `/themes/` in your Drupal installation
2. Enable the theme in Drupal admin: **Appearance** → **Install and set as default**
3. Clear Drupal cache: `drush cr` or via admin UI

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1 or higher
- DiSyL Kernel at `/kernel/DiSyL/`

## Theme Structure

```
phoenix/
├── assets/
│   ├── css/          # Stylesheets
│   ├── js/           # JavaScript files
│   └── images/       # Theme images
├── disyl/            # DiSyL templates
│   ├── home.disyl    # Homepage template
│   ├── single.disyl  # Single node template
│   ├── page.disyl    # Page template
│   └── components/   # Reusable components
├── includes/
│   └── disyl-integration.php  # DiSyL integration layer
├── src/
│   └── TwigExtension/         # Twig extensions
├── templates/        # Twig templates
├── phoenix.info.yml  # Theme metadata
├── phoenix.libraries.yml  # Asset libraries
├── phoenix.theme     # Theme hooks
└── phoenix.services.yml  # Services definition
```

## DiSyL Integration

Phoenix uses DiSyL templates for rendering, providing a CMS-aware templating approach:

### Drupal-Specific Helpers

```disyl
{!-- Render a Drupal region --}
{drupal_region name="sidebar_first" /}

{!-- Render a specific block --}
{drupal_block id="system_branding_block" /}

{!-- Render a menu --}
{drupal_menu name="main" level=1 depth=2 /}

{!-- Render a view --}
{drupal_view id="frontpage" display="page_1" /}

{!-- Render a form --}
{drupal_form id="Drupal\\search\\Form\\SearchBlockForm" /}
```

### Universal DiSyL Components

```disyl
{!-- Section wrapper --}
{ikb_section type="hero" class="hero-section" padding="large"}
    <h1>Welcome</h1>
{/ikb_section}

{!-- Container --}
{ikb_container size="xlarge"}
    Content here
{/ikb_container}

{!-- Text styling --}
{ikb_text size="3xl" weight="bold" class="gradient-text"}
    Large bold text
{/ikb_text}
```

### Filters

```disyl
{site.name | esc_html}
{node.url | esc_url}
{node.title | esc_attr}
{node.created | date:format="long"}
{node.body | truncate:length=150,append="..."}
{node.body | strip_tags}
```

## Regions

The theme provides the following regions:

- **header** - Site header
- **primary_menu** - Primary navigation
- **secondary_menu** - Secondary navigation
- **page_top** - Above content (announcements)
- **hero** - Hero section
- **slider** - Slider section
- **highlighted** - Highlighted content
- **breadcrumb** - Breadcrumb navigation
- **content** - Main content
- **sidebar_first** - Left sidebar
- **sidebar_second** - Right sidebar
- **content_bottom** - Below content
- **footer_first** - Footer column 1
- **footer_second** - Footer column 2
- **footer_third** - Footer column 3
- **footer_fourth** - Footer column 4
- **footer** - Footer bottom
- **page_bottom** - Below everything

## Customization

### Adding Custom DiSyL Templates

1. Create a new `.disyl` file in `disyl/` directory
2. Use Drupal-specific helpers and universal DiSyL components
3. Clear cache to see changes

### Modifying Styles

Edit `assets/css/style.css` for global styles or create custom CSS files and add them to `phoenix.libraries.yml`.

### Adding Custom Twig Templates

Create template files in `templates/` directory following Drupal naming conventions:
- `page--front.html.twig` - Front page
- `page--node--article.html.twig` - Article nodes
- `node--article.html.twig` - Article node display

## DiSyL Philosophy

Phoenix for Drupal is **NOT** a port of a WordPress theme. Instead:

1. **DiSyL templates are CMS-aware** - Uses Drupal-specific helpers (`drupal_region`, `drupal_block`, etc.)
2. **Shared core syntax** - Universal components work across all CMSs
3. **Drupal-native integration** - Respects Drupal's architecture and APIs

> DiSyL provides a unified syntax layer while respecting each CMS's native architecture. It's not about forcing one CMS's patterns onto another, but about using DiSyL as a common templating language that integrates naturally with each platform.

## Support

For issues and questions:
- DiSyL Documentation: `/kernel/DiSyL/README.md`
- Theme Issues: Create an issue in the project repository

## License

Same license as the Ikabud Kernel project.

---

**Built with ❤️ using DiSyL - Write Once, Adapt Everywhere**

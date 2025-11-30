# Theme Builder Guide

**Visual Builder & Code Editor for DiSyL Themes**

**Version:** 1.5.1  
**Last Updated:** November 30, 2025

---

## Table of Contents

1. [Overview](#overview)
2. [Getting Started](#getting-started)
3. [Code Editor](#code-editor)
4. [Visual Builder](#visual-builder)
5. [Theme Management](#theme-management)
6. [Cross-Instance Federation](#cross-instance-federation)
7. [API Reference](#api-reference)
8. [Keyboard Shortcuts](#keyboard-shortcuts)

---

## Overview

The Theme Builder provides two powerful interfaces for creating and editing DiSyL themes:

- **Code Editor** - Monaco-powered editor with syntax highlighting and IntelliSense
- **Visual Builder** - Drag-and-drop component-based theme builder

### Key Features

| Feature | Code Editor | Visual Builder |
|---------|-------------|----------------|
| Syntax Highlighting | ✅ DiSyL, PHP, CSS, JS | N/A |
| IntelliSense/Autocomplete | ✅ Context-aware | ✅ Component palette |
| Cross-Instance Support | ✅ Full | ✅ Full |
| Real-time Preview | ✅ | ✅ |
| File Management | ✅ Full tree | ✅ Template-based |
| Theme Generation | ✅ | ✅ |

---

## Getting Started

### Accessing the Theme Builder

1. Navigate to the Admin Panel (`/login`)
2. Go to **Themes** section
3. Select an instance from the dropdown
4. Choose your editing mode:
   - **Code Editor** - For direct file editing
   - **Visual Builder** - For component-based editing
   - **Upload Theme** - For importing ZIP packages

### Instance Selection

The instance selector is shared across all theme features. Selecting an instance:
- Loads available themes for that instance
- Configures database context for autocomplete
- Sets the target CMS type (WordPress, Joomla, Drupal)

---

## Code Editor

### Monaco Editor Integration

The Code Editor uses Monaco Editor (same as VS Code) with full DiSyL support:

#### Syntax Highlighting

- **DiSyL Tags** - `{ikb_section}`, `{ikb_query}`, etc.
- **Expressions** - `{post.title | esc_html}`
- **Comments** - `{!-- comment --}`
- **Filters** - `| esc_html`, `| truncate(100)`
- **Conditionals** - `{if condition="..."}`, `{else}`, `{/if}`

#### IntelliSense Features

**Component Autocomplete**
```
Type: {ikb_
Suggestions:
  - ikb_section - Layout section component
  - ikb_container - Content container
  - ikb_query - Data query/loop
  - ikb_text - Text component
  - ikb_image - Image component
  - ikb_button - Button component
  - ikb_grid - Grid layout
  - ikb_card - Card component
  ...
```

**Filter Autocomplete**
```
Type: {post.title |
Suggestions:
  - esc_html - Escape HTML entities
  - esc_attr - Escape for attributes
  - esc_url - Escape URLs
  - truncate - Truncate text
  - upper - Uppercase
  - lower - Lowercase
  - date - Format date
  - strip_tags - Remove HTML tags
  ...
```

**Variable Autocomplete**
```
Type: {post.
Suggestions (WordPress):
  - post.ID - Post ID
  - post.title - Post title
  - post.content - Post content
  - post.excerpt - Post excerpt
  - post.thumbnail - Featured image URL
  - post.permalink - Post URL
  - post.author - Author name
  - post.date - Publish date
  ...
```

**Cross-Instance Autocomplete**
```
Type: {ikb_query instance="
Suggestions:
  - wp-main - WordPress (3 themes)
  - joomla-content - Joomla (2 themes)
  - drupal-blog - Drupal (1 theme)
  ...

Type: {ikb_query cms="
Suggestions:
  - wordpress - WordPress CMS (2 instances)
  - joomla - Joomla CMS (1 instance)
  - drupal - Drupal CMS (1 instance)
  ...
```

### Editor Settings

| Setting | Options | Default |
|---------|---------|---------|
| Theme | Light / Dark | Dark |
| Font Size | 12-24px | 14px |
| Minimap | Show / Hide | Show |
| Word Wrap | On / Off | On |
| Tab Size | 2 / 4 | 4 |

### File Tree Navigation

The sidebar displays the theme's file structure:

```
phoenix/
├── disyl/
│   ├── home.disyl
│   ├── single.disyl
│   ├── page.disyl
│   ├── archive.disyl
│   └── components/
│       ├── header.disyl
│       ├── footer.disyl
│       └── sidebar.disyl
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── theme.js
│   └── images/
├── functions.php (WordPress)
├── style.css
└── screenshot.png
```

---

## Visual Builder

### Component Library

#### Layout Components

| Component | Description | Attributes |
|-----------|-------------|------------|
| `ikb_section` | Page section | `type`, `padding`, `background` |
| `ikb_container` | Content wrapper | `size`, `align` |
| `ikb_grid` | Grid layout | `columns`, `gap`, `responsive` |
| `ikb_row` | Flex row | `align`, `justify`, `gap` |
| `ikb_column` | Grid/flex column | `span`, `order` |

#### Content Components

| Component | Description | Attributes |
|-----------|-------------|------------|
| `ikb_text` | Text block | `tag`, `size`, `weight`, `color` |
| `ikb_image` | Image | `src`, `alt`, `responsive` |
| `ikb_button` | Button | `href`, `variant`, `size` |
| `ikb_card` | Card container | `variant`, `padding` |
| `ikb_link` | Hyperlink | `href`, `target` |

#### Data Components

| Component | Description | Attributes |
|-----------|-------------|------------|
| `ikb_query` | Data loop | `type`, `limit`, `orderby`, `instance`, `cms` |
| `ikb_menu` | Navigation menu | `location`, `class` |
| `ikb_widget_area` | Widget zone | `id`, `class` |

#### CMS-Specific Components

**WordPress:**
- `wp_head` - WordPress head hook
- `wp_footer` - WordPress footer hook
- `body_class` - Body classes

**Joomla:**
- `joomla_module` - Module position
- `joomla_component` - Component output
- `joomla_message` - System messages

**Drupal:**
- `drupal_region` - Theme region
- `drupal_block` - Block output

### Drag-and-Drop Interface

1. **Component Palette** - Left sidebar with available components
2. **Canvas** - Center area for building layouts
3. **Properties Panel** - Right sidebar for component settings
4. **Preview** - Real-time preview of changes

### Template Selection

Choose which template to edit:

| Template | Description | Required |
|----------|-------------|----------|
| Homepage | Main landing page | ✅ |
| Single Post | Individual post/article | ✅ |
| Page | Static page | ✅ |
| Archive | Post listings | ✅ |
| Category | Category archive | ❌ |
| Search | Search results | ❌ |
| 404 | Error page | ✅ |
| Header | Site header | ✅ |
| Footer | Site footer | ✅ |
| Sidebar | Sidebar widget area | ❌ |

---

## Theme Management

### Creating a New Theme

1. Select target instance
2. Click **New Theme**
3. Enter theme details:
   - Theme Name
   - Description
   - Author
   - Version
4. Choose starter template (optional)
5. Click **Create**

### Editing Existing Themes

1. Select instance
2. Choose theme from dropdown
3. Select editing mode (Code Editor / Visual Builder)
4. Make changes
5. Save (`Ctrl+S` or Save button)

### Uploading Theme ZIP

1. Select target instance
2. Click **Upload Theme**
3. Drag & drop or browse for ZIP file
4. Review extracted contents
5. Confirm upload

### Exporting Themes

1. Select theme to export
2. Click **Export**
3. Choose format:
   - **ZIP** - Complete theme package
   - **DiSyL Only** - Just template files
4. Download generated file

### Theme Generation

Generate CMS-specific theme files from DiSyL templates:

```
POST /api/theme/generate
{
  "instance_id": "wp-main",
  "theme_name": "phoenix",
  "cms_type": "wordpress"
}
```

Generated files:
- WordPress: `functions.php`, `style.css`, `index.php`, etc.
- Joomla: `templateDetails.xml`, `index.php`, `component.php`, etc.
- Drupal: `*.info.yml`, `*.libraries.yml`, Twig templates, etc.

---

## Cross-Instance Federation

### Overview

Query content from any CMS instance within your templates. This enables:
- WordPress site pulling Joomla articles
- Drupal site displaying WordPress products
- Mixed content from multiple sources

### Code Editor Support

The Code Editor provides full autocomplete support for cross-instance queries:

1. **Instance Suggestions** - When typing `instance="`, shows all available instances
2. **CMS Suggestions** - When typing `cms="`, shows available CMS types
3. **Context Switching** - Autocomplete adapts based on target CMS

### Example Usage

```disyl
{!-- Local WordPress query --}
{ikb_query type="post" limit="5"}
    <h2>{post.title | esc_html}</h2>
{/ikb_query}

{!-- Cross-instance: Joomla articles --}
{ikb_query cms="joomla" instance="joomla-news" type="article" limit="5"}
    <h2>{article.title | esc_html}</h2>
    <p>{article.introtext | truncate(150)}</p>
    <span>Views: {article.hits}</span>
{/ikb_query}

{!-- Cross-instance: Drupal nodes --}
{ikb_query cms="drupal" instance="drupal-blog" type="article" limit="3"}
    <h2>{node.title | esc_html}</h2>
    <p>{node.body | strip_tags | truncate(200)}</p>
{/ikb_query}
```

### Common Fields

These fields work across all CMS types:

| Field | Description |
|-------|-------------|
| `title` | Content title |
| `content` | Full content |
| `excerpt` | Summary text |
| `date` | Publish date |
| `modified` | Last modified |
| `author` | Author name |
| `slug` | URL slug |
| `id` | Content ID |

---

## API Reference

### Filesystem API

**List Theme Files**
```
GET /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files
```

**Read File**
```
GET /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
```

**Write File**
```
PUT /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
Content-Type: application/json
{
  "content": "file contents here"
}
```

**Create File**
```
POST /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files
Content-Type: application/json
{
  "path": "disyl/new-template.disyl",
  "content": "{!-- New template --}"
}
```

**Delete File**
```
DELETE /api/v1/filesystem/instances/{instanceId}/themes/{themeName}/files/{path}
```

### Instance Context API

**Get Database Context**
```
GET /api/v1/filesystem/instances/{instanceId}/context
```

Response:
```json
{
  "success": true,
  "data": {
    "cms_type": "wordpress",
    "variables": {
      "site": ["name", "description", "url", "admin_email"],
      "post": ["ID", "title", "content", "excerpt", "thumbnail", "permalink"],
      "user": ["ID", "login", "email", "display_name", "logged_in"]
    },
    "filters": ["esc_html", "esc_attr", "esc_url", "truncate", "date", ...],
    "operators": ["==", "!=", ">", "<", ">=", "<=", "&&", "||", "!"],
    "cms_specific": {
      "post_types": ["post", "page", "product"],
      "taxonomies": ["category", "post_tag", "product_cat"],
      "menus": ["primary", "footer"],
      "widgets": ["sidebar-1", "footer-1"]
    }
  }
}
```

### Theme Generation API

**Generate Theme**
```
POST /api/theme/generate
Content-Type: application/json
{
  "instance_id": "wp-main",
  "theme_name": "phoenix",
  "cms_type": "wordpress",
  "options": {
    "include_assets": true,
    "minify_css": false,
    "generate_screenshot": true
  }
}
```

---

## Keyboard Shortcuts

### Code Editor

| Shortcut | Action |
|----------|--------|
| `Ctrl+S` | Save file |
| `Ctrl+Z` | Undo |
| `Ctrl+Shift+Z` | Redo |
| `Ctrl+F` | Find |
| `Ctrl+H` | Find & Replace |
| `Ctrl+G` | Go to line |
| `Ctrl+D` | Select word |
| `Ctrl+/` | Toggle comment |
| `Ctrl+Space` | Trigger autocomplete |
| `F1` | Command palette |
| `Alt+Up/Down` | Move line |
| `Ctrl+Shift+K` | Delete line |

### Visual Builder

| Shortcut | Action |
|----------|--------|
| `Ctrl+S` | Save template |
| `Ctrl+Z` | Undo |
| `Ctrl+Shift+Z` | Redo |
| `Delete` | Remove selected component |
| `Ctrl+C` | Copy component |
| `Ctrl+V` | Paste component |
| `Ctrl+D` | Duplicate component |
| `Escape` | Deselect |

---

## Troubleshooting

### Common Issues

**Autocomplete not working**
- Ensure instance is selected
- Check if context API is accessible
- Verify file has `.disyl` extension

**Cross-instance query returns empty**
- Verify target instance exists
- Check database credentials in instance config
- Ensure content is published in source CMS

**Theme generation fails**
- Check write permissions on theme directory
- Verify all required templates exist
- Review error logs for specific issues

### Getting Help

- **Documentation**: `/docs/THEME_BUILDER_GUIDE.md`
- **API Reference**: `/docs/DISYL_API_REFERENCE.md`
- **GitHub Issues**: Report bugs and feature requests

---

**Built with ❤️ for the CMS community**
